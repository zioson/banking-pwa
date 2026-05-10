<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Loans
 *
 * Self-healing: auto-creates tables if missing, auto-adds columns, widens ENUMs.
 */

// PHP 7.x compatibility: str_contains polyfill (introduced in PHP 8.0)
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle !== '' && strpos($haystack, $needle) !== false;
    }
}

require_once __DIR__ . '/../includes/Response.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

if (!isset($_ROUTE) || !is_array($_ROUTE)) {
    $_ROUTE = [
        'resource' => 'loans',
        'id' => null,
        'subResource' => null,
        'subId' => null,
        'method' => strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        'segments' => []
    ];
}

$staff = requireAuth();
$method = $_ROUTE['method'];
$isAutoPay = ($_ROUTE['id'] ?? '') === 'auto-pay' || ($_ROUTE['subResource'] ?? '') === 'auto-pay';
// GET: Any authenticated user can view loans (branch isolation is applied inside GET).
// POST/PUT/DELETE: Requires LOANS module (except auto-pay which is a cron-triggered internal route).
if ($method !== 'GET' && !$isAutoPay) {
    requireModule('LOANS', $staff);
}

$id = $_ROUTE['id'];

/**
 * Safely add a column if it doesn't exist (PostgreSQL compatible)
 */
function loanAddCol(PDO $db, string $table, string $col, string $def): void {
    try {
        $r = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?");
        $r->execute([$table, $col]);
        if (!$r->fetch()) $db->exec('ALTER TABLE "' . $table . '" ADD COLUMN "' . $col . '" ' . $def);
    } catch (PDOException $e) {
        error_log("[Loans Schema] loanAddCol($table, $col) failed: " . $e->getMessage());
    }
}

function loanCanAccessBranch(array $staff, string $branch): bool {
    $branch = strtoupper(trim($branch));
    if ($branch === '') return true;
    if (strtoupper($staff['role'] ?? '') === 'ADMIN') return true;
    $staffBranchesRaw = $staff['branches'] ?? [];
    if (is_string($staffBranchesRaw)) {
        $staffBranchesRaw = [$staffBranchesRaw];
    }
    $staffBranches = array_values(array_unique(array_filter(array_map(function ($b) {
        $v = strtoupper(trim((string)$b));
        if (in_array($v, ['ALL BRANCHES', 'ALL_BRANCHES'], true)) return 'ALL';
        return $v;
    }, is_array($staffBranchesRaw) ? $staffBranchesRaw : []))));
    if (in_array('ALL', $staffBranches, true)) return true;
    return empty($staffBranches) ? false : in_array($branch, $staffBranches, true);
}

function loanRepairCorruptedNumbers(PDO $db): void {
    static $repaired = false;
    if ($repaired) {
        return;
    }

    try {
        $corruptedRows = $db->query(
            "SELECT id, loan_number FROM loans WHERE loan_number ~ 'E\\+|e\\+' AND loan_number LIKE 'LN-%'"
        )->fetchAll(PDO::FETCH_ASSOC);
        if (empty($corruptedRows)) {
            $repaired = true;
            return;
        }

        $yearSeq = [];
        $validRows = $db->query(
            "SELECT loan_number FROM loans WHERE loan_number ~ '^LN-[0-9]{4}-[0-9]{3}$'"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($validRows as $vn) {
            if (preg_match('/^LN-(\d{4})-(\d{3})$/', (string)$vn, $m)) {
                if (!isset($yearSeq[$m[1]]) || (int)$m[2] > $yearSeq[$m[1]]) {
                    $yearSeq[$m[1]] = (int)$m[2];
                }
            }
        }

        foreach ($corruptedRows as $row) {
            if (!preg_match('/^LN-(\d{4})-/', (string)$row['loan_number'], $m)) {
                continue;
            }
            $yr = $m[1];
            if (!isset($yearSeq[$yr])) {
                $yearSeq[$yr] = 0;
            }
            $yearSeq[$yr]++;
            $newNum = 'LN-' . $yr . '-' . str_pad((string)$yearSeq[$yr], 3, '0', STR_PAD_LEFT);
            $oldNum = (string)$row['loan_number'];

            $db->prepare("UPDATE loans SET loan_number = ? WHERE id = ?")->execute([$newNum, (int)$row['id']]);
            try {
                $db->prepare("UPDATE loan_fund_transactions SET description = REPLACE(description, ?, ?) WHERE description LIKE ?")
                   ->execute([$oldNum, $newNum, '%' . $oldNum . '%']);
            } catch (PDOException $e) {}
            try {
                $db->prepare("UPDATE general_ledger SET description = REPLACE(description, ?, ?) WHERE description LIKE ?")
                   ->execute([$oldNum, $newNum, '%' . $oldNum . '%']);
            } catch (PDOException $e) {}
            error_log('[Loans Schema] Repaired corrupted loan number #' . (int)$row['id'] . ': ' . $oldNum . ' -> ' . $newNum);
        }
    } catch (PDOException $e) {
        error_log('[Loans Schema] Corrupted loan number repair failed: ' . $e->getMessage());
    }

    $repaired = true;
}

function loanRepairScheduleStatuses(PDO $db): void {
    static $ran = false;
    if ($ran) return;
    try {
        $db->exec(
            "UPDATE loan_schedule
             SET status = 'UPCOMING'
             WHERE status = 'DUE'
               AND (paid IS NULL OR paid <= 0.01)
               AND due_date > CURRENT_DATE"
        );
    } catch (PDOException $e) {
        error_log('[Loans Schema] Schedule status repair (DUE→UPCOMING) failed: ' . $e->getMessage());
    }
    try {
        $db->exec(
            "UPDATE loan_schedule
             SET status = 'DUE'
             WHERE status = 'UPCOMING'
               AND (paid IS NULL OR paid <= 0.01)
               AND due_date <= CURRENT_DATE"
        );
    } catch (PDOException $e) {
        error_log('[Loans Schema] Schedule status repair (UPCOMING→DUE) failed: ' . $e->getMessage());
    }
    $ran = true;
}

function loanRepairLegacyLoanStatuses(PDO $db): void {
    static $ran = false;
    if ($ran) return;
    try {
        $db->exec("UPDATE loans SET status = 'ACTIVE' WHERE status = 'DISBURSED'");
    } catch (PDOException $e) {
        error_log('[Loans Schema] Loan status repair (DISBURSED→ACTIVE) failed: ' . $e->getMessage());
    }
    $ran = true;
}

function loanEnsureTransactionsCompat(PDO $db): void {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS transactions (
            id              SERIAL PRIMARY KEY,
            ref             VARCHAR(50)  NOT NULL DEFAULT '',
            type            VARCHAR(50)  NOT NULL DEFAULT '',
            direction       VARCHAR(20)  NOT NULL DEFAULT '',
            account         VARCHAR(50)  NOT NULL DEFAULT '',
            branch          VARCHAR(255) NOT NULL DEFAULT '',
            amount          DECIMAL(20,2) NOT NULL DEFAULT 0,
            status          VARCHAR(30)  NOT NULL DEFAULT 'PENDING',
            category        VARCHAR(100) DEFAULT '',
            module          VARCHAR(100) DEFAULT '',
            description     TEXT         DEFAULT NULL,
            memo            TEXT         DEFAULT NULL,
            created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (ref)
        )");
        try {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_txn_branch ON transactions (branch)");
        } catch (PDOException $e) {}
        try {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_txn_status ON transactions (status)");
        } catch (PDOException $e) {}
        try {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_txn_account ON transactions (account)");
        } catch (PDOException $e) {}
        try {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_txn_created ON transactions (created_at)");
        } catch (PDOException $e) {}
    } catch (PDOException $e) {
        error_log('[Loans Schema] CREATE TABLE transactions failed: ' . $e->getMessage());
    }

    foreach ([
        'ref' => "VARCHAR(50) NOT NULL DEFAULT ''",
        'type' => "VARCHAR(50) NOT NULL DEFAULT ''",
        'direction' => "VARCHAR(20) NOT NULL DEFAULT ''",
        'account' => "VARCHAR(50) NOT NULL DEFAULT ''",
        'branch' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'amount' => "DECIMAL(20,2) NOT NULL DEFAULT 0",
        'status' => "VARCHAR(30) NOT NULL DEFAULT 'PENDING'",
        'category' => "VARCHAR(100) DEFAULT ''",
        'module' => "VARCHAR(100) DEFAULT ''",
        'description' => "TEXT DEFAULT NULL",
        'memo' => "TEXT DEFAULT NULL",
        'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'timestamp' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"
    ] as $col => $def) {
        try { loanAddCol($db, 'transactions', $col, $def); } catch (Exception $_) {}
    }
}

function loanInsertTransaction(PDO $db, array $row): void {
    $cols = [];
    try {
        $colRows = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'transactions' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN, 0);
        $cols = array_flip($colRows);
    } catch (PDOException $e) {
        $cols = [];
    }

    $all = [
        'ref'           => $row['ref'] ?? '',
        'type'          => $row['type'] ?? '',
        'status'        => $row['status'] ?? 'POSTED',
        'branch'        => $row['branch'] ?? '',
        'account'       => $row['account'] ?? '',
        'account_type'  => $row['account_type'] ?? '',
        'customer_name' => $row['customer_name'] ?? '',
        'description'   => $row['description'] ?? '',
        'category'      => $row['category'] ?? '',
        'direction'     => $row['direction'] ?? '',
        'amount'        => $row['amount'] ?? 0,
        'fee'           => $row['fee'] ?? 0,
        'fee_pct'       => $row['fee_pct'] ?? 0,
        'net_amount'    => $row['net_amount'] ?? null,
        'fee_mode'      => $row['fee_mode'] ?? '',
        'total_tax'     => $row['total_tax'] ?? 0,
        'memo'          => $row['memo'] ?? '',
        'module'        => $row['module'] ?? '',
        'operator_id'   => $row['operator_id'] ?? null,
        'operator_name' => $row['operator_name'] ?? '',
        'parent_id'     => $row['parent_id'] ?? null,
    ];

    $insertCols = [];
    $placeholders = [];
    $params = [];
    foreach ($all as $k => $v) {
        if (!empty($cols) && !isset($cols[$k])) continue;
        $insertCols[] = "\"$k\"";
        $placeholders[] = ':' . $k;
        $params[$k] = $v;
    }
    if (empty($insertCols)) {
        throw new PDOException('transactions table is missing required columns.');
    }

    $now = date('Y-m-d H:i:s');
    if (empty($cols) || isset($cols['created_at'])) {
        $insertCols[] = "\"created_at\"";
        $placeholders[] = ":created_at";
        $params['created_at'] = $now;
    }
    if (isset($cols['timestamp'])) {
        $insertCols[] = "\"timestamp\"";
        $placeholders[] = ":timestamp";
        $params['timestamp'] = $now;
    }

    $sql = "INSERT INTO transactions (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
}

/**
 * Ensure loans table and schema are up-to-date.
 * Each operation is individually wrapped in try-catch for resilience.
 */
function loanEnsureSchema(PDO $db): void {
    // Create loans table if missing
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS loans (
            id SERIAL PRIMARY KEY,
            loan_number VARCHAR(30) NOT NULL UNIQUE,
            customer_id INTEGER NOT NULL,
            customer_name VARCHAR(255) NOT NULL DEFAULT '',
            branch VARCHAR(20) DEFAULT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
            principal DECIMAL(18,2) NOT NULL DEFAULT 0,
            outstanding DECIMAL(18,2) NOT NULL DEFAULT 0,
            accrued_interest DECIMAL(18,2) NOT NULL DEFAULT 0,
            interest_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
            term_months INTEGER NOT NULL DEFAULT 0,
            repayment_freq VARCHAR(30) DEFAULT 'Monthly',
            disbursed_at DATE DEFAULT NULL,
            maturity_date DATE DEFAULT NULL,
            next_due DATE DEFAULT NULL,
            debit_account_id INTEGER DEFAULT NULL,
            debit_account_number VARCHAR(30) DEFAULT NULL,
            guarantor_customer_id INTEGER DEFAULT NULL,
            guarantor_account_id INTEGER DEFAULT NULL,
            guarantor_account_number VARCHAR(30) DEFAULT NULL,
            source TEXT DEFAULT NULL,
            product_type VARCHAR(50) DEFAULT NULL,
            repayment_mode VARCHAR(30) NOT NULL DEFAULT 'SCHEDULED',
            repayment_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            repayment_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
            interest_included BOOLEAN DEFAULT TRUE,
            auto_deduct BOOLEAN DEFAULT TRUE,
            loan_module VARCHAR(20) DEFAULT 'BANK',
            insurance_fee DECIMAL(18,2) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER DEFAULT NULL
        )");
    } catch (PDOException $e) {
        error_log("[Loans Schema] CREATE TABLE loans failed: " . $e->getMessage());
    }

    // Create loan_schedule table if missing
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS loan_schedule (
            id SERIAL PRIMARY KEY,
            loan_id INTEGER NOT NULL,
            installment INT NOT NULL DEFAULT 1,
            due_date DATE NOT NULL,
            principal DECIMAL(18,2) NOT NULL DEFAULT 0,
            interest DECIMAL(18,2) NOT NULL DEFAULT 0,
            paid DECIMAL(18,2) NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'DUE',
            penalty_applied_date DATE DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (PDOException $e) {
        error_log("[Loans Schema] CREATE TABLE loan_schedule failed: " . $e->getMessage());
    }

    // Fix column name: schema SQL uses 'due' but runtime code uses 'due_date'
    try {
        $col = $db->query("SELECT column_name AS \"Field\" FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'loan_schedule' AND column_name = 'due'")->fetch();
        if ($col) {
            $col2 = $db->query("SELECT column_name AS \"Field\" FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'loan_schedule' AND column_name = 'due_date'")->fetch();
            if (!$col2) {
                $db->exec("ALTER TABLE loan_schedule RENAME COLUMN \"due\" TO \"due_date\"");
            }
        }
    } catch (PDOException $e) {
        error_log('[Loans Schema] ALTER loan_schedule due→due_date failed: ' . $e->getMessage());
    }

    // Ensure penalty_applied_date column exists on loan_schedule
    loanAddCol($db, 'loan_schedule', 'penalty_applied_date', 'DATE DEFAULT NULL');
    // ★ NEW: columns for compound interest tracking and early payoff
    loanAddCol($db, 'loan_schedule', 'interest_capitalized', "BOOLEAN DEFAULT FALSE");
    loanAddCol($db, 'loan_schedule', 'settled_via', "VARCHAR(30) DEFAULT NULL");

    // Fix repayment_mode if it's a restrictive ENUM — alter to VARCHAR
    try {
        $col = $db->query("SELECT data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'loans' AND column_name = 'repayment_mode'")->fetch(PDO::FETCH_ASSOC);
        if ($col && str_contains(strtolower($col['data_type']), 'enum')) {
            $db->exec("ALTER TABLE loans ALTER COLUMN repayment_mode TYPE VARCHAR(30)");
            $db->exec("ALTER TABLE loans ALTER COLUMN repayment_mode SET DEFAULT 'SCHEDULED'");
        }
    } catch (PDOException $e) {
        error_log("[Loans Schema] ALTER repayment_mode failed: " . $e->getMessage());
    }

    // Fix status ENUM if it doesn't include newer statuses
    try {
        $col2 = $db->query("SELECT data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'loans' AND column_name = 'status'")->fetch(PDO::FETCH_ASSOC);
        if ($col2 && str_contains(strtolower($col2['data_type']), 'enum')) {
            $db->exec("ALTER TABLE loans ALTER COLUMN status TYPE VARCHAR(30)");
        }
    } catch (PDOException $e) {
        error_log("[Loans Schema] ALTER status failed: " . $e->getMessage());
    }

    // Ensure individual columns exist (for tables created by older schemas)
    loanAddCol($db, 'loans', 'source', "TEXT DEFAULT NULL");
    loanAddCol($db, 'loans', 'product_type', "VARCHAR(50) DEFAULT NULL");
    loanAddCol($db, 'loans', 'repayment_amount', "DECIMAL(18,2) NOT NULL DEFAULT 0");
    loanAddCol($db, 'loans', 'repayment_pct', "DECIMAL(5,2) NOT NULL DEFAULT 0");
    loanAddCol($db, 'loans', 'auto_deduct', "BOOLEAN DEFAULT TRUE");
    loanAddCol($db, 'loans', 'interest_included', "BOOLEAN DEFAULT TRUE");
    loanAddCol($db, 'loans', 'debit_account_id', "INTEGER DEFAULT NULL");
    loanAddCol($db, 'loans', 'debit_account_number', "VARCHAR(30) DEFAULT NULL");
    loanAddCol($db, 'loans', 'guarantor_customer_id', "INTEGER DEFAULT NULL");
    loanAddCol($db, 'loans', 'guarantor_account_id', "INTEGER DEFAULT NULL");
    loanAddCol($db, 'loans', 'guarantor_account_number', "VARCHAR(30) DEFAULT NULL");
    loanAddCol($db, 'loans', 'disbursed_at', "DATE DEFAULT NULL");
    loanAddCol($db, 'loans', 'maturity_date', "DATE DEFAULT NULL");
    loanAddCol($db, 'loans', 'next_due', "DATE DEFAULT NULL");
    loanAddCol($db, 'loans', 'created_by', "INTEGER DEFAULT NULL");
    loanAddCol($db, 'loans', 'loan_module', "VARCHAR(20) DEFAULT 'BANK'");
    loanAddCol($db, 'loans', 'insurance_fee', "DECIMAL(18,2) DEFAULT 0");
    // ★ NEW: columns for compound interest capitalization and early payoff tracking
    loanAddCol($db, 'loans', 'capitalized_interest', "DECIMAL(18,2) NOT NULL DEFAULT 0");
    loanAddCol($db, 'loans', 'settled_at', "TIMESTAMP NULL DEFAULT NULL");

    // Fix schedule status ENUM if restrictive — ensure it includes UPCOMING, PARTIALLY_PAID and WAIVED
    try {
        $schCol = $db->query("SELECT data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'loan_schedule' AND column_name = 'status'")->fetch(PDO::FETCH_ASSOC);
        if ($schCol && str_contains(strtolower($schCol['data_type']), 'enum')) {
            $db->exec("ALTER TABLE loan_schedule ALTER COLUMN status TYPE VARCHAR(30)");
        }
    } catch (PDOException $e) {
        error_log("[Loans Schema] ALTER loan_schedule status failed: " . $e->getMessage());
    }

    // Repair any legacy scientific-notation loan numbers early in the loan module
    // so unrelated panels do not have to discover and clean them later.
    loanRepairCorruptedNumbers($db);
    loanRepairLegacyLoanStatuses($db);
    loanRepairScheduleStatuses($db);
    loanEnsureTransactionsCompat($db);
}

function loanEnsureLoanFundReportingSchema(PDO $db): void {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS loan_fund_accounts (
            id SERIAL PRIMARY KEY,
            account_number VARCHAR(30) NOT NULL UNIQUE,
            account_name VARCHAR(200) NOT NULL,
            fund_type VARCHAR(20) NOT NULL,
            balance DECIMAL(20,2) DEFAULT 0,
            currency VARCHAR(5) DEFAULT 'XAF',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (PDOException $e) {
        error_log('[Loans] CREATE TABLE loan_fund_accounts failed: ' . $e->getMessage());
    }
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS loan_fund_transactions (
            id SERIAL PRIMARY KEY,
            ref VARCHAR(50),
            loan_fund_account_id INT NOT NULL,
            loan_id INT DEFAULT NULL,
            transaction_ref VARCHAR(50) DEFAULT NULL,
            date DATE NOT NULL,
            type VARCHAR(10) NOT NULL,
            description TEXT,
            amount DECIMAL(20,2) NOT NULL,
            balance_after DECIMAL(20,2) NOT NULL,
            branch VARCHAR(20) DEFAULT NULL,
            operator VARCHAR(200),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        try {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_lf_account ON loan_fund_transactions (loan_fund_account_id)");
        } catch (PDOException $e) {}
        try {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_lf_ref ON loan_fund_transactions (transaction_ref)");
        } catch (PDOException $e) {}
        try {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_lf_branch ON loan_fund_transactions (branch)");
        } catch (PDOException $e) {}
    } catch (PDOException $e) {
        error_log('[Loans] CREATE TABLE loan_fund_transactions failed: ' . $e->getMessage());
    }
    try {
        $c = (int)$db->query("SELECT COUNT(*) FROM loan_fund_accounts")->fetchColumn();
        if ($c === 0) {
            $db->exec("INSERT INTO loan_fund_accounts (account_number, account_name, fund_type, balance, currency)
                       VALUES ('BANK-LF-0001', 'Loans & Advances Fund', 'LOAN_FUND', 0, 'XAF')");
            $db->exec("INSERT INTO loan_fund_accounts (account_number, account_name, fund_type, balance, currency)
                       VALUES ('BANK-LI-0001', 'Loan Interest Income', 'LOAN_INTEREST', 0, 'XAF')");
        }
    } catch (PDOException $e) {
        error_log('[Loans] Seed loan_fund_accounts failed: ' . $e->getMessage());
    }
}

function loanGetLoanFundAccountId(PDO $db, string $accountNumber): ?int {
    try {
        $s = $db->prepare("SELECT id FROM loan_fund_accounts WHERE account_number = ? LIMIT 1");
        $s->execute([$accountNumber]);
        $id = $s->fetchColumn();
        return $id ? (int)$id : null;
    } catch (PDOException $e) {
        return null;
    }
}

function loanGetGLIncomeBalance(PDO $db, string $accountCode, string $branch = ''): float {
    try {
        $sql = "SELECT COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) AS net_balance
                  FROM general_ledger
                 WHERE account_code = ?";
        $params = [$accountCode];
        $b = strtoupper(trim($branch));
        if ($b !== '') {
            $sql .= " AND UPPER(TRIM(COALESCE(branch,''))) = ?";
            $params[] = $b;
        }
        $s = $db->prepare($sql);
        $s->execute($params);
        return (float)$s->fetchColumn();
    } catch (PDOException $e) {
        return 0.0;
    }
}

function loanGetGLAssetBalance(PDO $db, string $accountCode, string $branch = ''): float {
    try {
        $sql = "SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS net_balance
                  FROM general_ledger
                 WHERE account_code = ?";
        $params = [$accountCode];
        $b = strtoupper(trim($branch));
        if ($b !== '') {
            $sql .= " AND UPPER(TRIM(COALESCE(branch,''))) = ?";
            $params[] = $b;
        }
        $s = $db->prepare($sql);
        $s->execute($params);
        return (float)$s->fetchColumn();
    } catch (PDOException $e) {
        return 0.0;
    }
}

function loanInsertLoanFundTxnIfMissing(PDO $db, array $row): void {
    try {
        $acctId = (int)($row['loan_fund_account_id'] ?? 0);
        $txnRef = (string)($row['transaction_ref'] ?? '');
        $type = (string)($row['type'] ?? '');
        if ($acctId <= 0 || $txnRef === '' || ($type !== 'CREDIT' && $type !== 'DEBIT')) return;

        $exists = $db->prepare("SELECT id FROM loan_fund_transactions WHERE loan_fund_account_id = ? AND transaction_ref = ? AND type = ? LIMIT 1");
        $exists->execute([$acctId, $txnRef, $type]);
        if ($exists->fetchColumn()) return;

        $ins = $db->prepare("INSERT INTO loan_fund_transactions (ref, loan_fund_account_id, loan_id, transaction_ref, date, type, description, amount, balance_after, branch, operator)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([
            (string)($row['ref'] ?? ''),
            $acctId,
            isset($row['loan_id']) ? (int)$row['loan_id'] : null,
            $txnRef,
            (string)($row['date'] ?? date('Y-m-d')),
            $type,
            (string)($row['description'] ?? ''),
            (float)($row['amount'] ?? 0),
            (float)($row['balance_after'] ?? 0),
            (string)($row['branch'] ?? ''),
            (string)($row['operator'] ?? '')
        ]);
    } catch (PDOException $e) {
    }
}

switch ($method) {
    case 'GET':
        if ($id !== null) {
            try {
                $db = getDB();
                loanEnsureSchema($db);
                $stmt = $db->prepare('SELECT * FROM loans WHERE id = :id');
                $stmt->execute([':id' => $id]);
                $record = $stmt->fetch();
                if (!$record) { notFoundResponse('Loan not found.'); }
                // ★ FIX (LOAN-017): Branch isolation for single loan GET
                if (!loanCanAccessBranch($staff, (string)($record['branch'] ?? ''))) {
                    errorResponse('Access denied: Loan belongs to branch ' . $record['branch'] . ' which is outside your scope.', 403);
                }
                $sStmt = $db->prepare('SELECT * FROM loan_schedule WHERE loan_id = :lid ORDER BY installment ASC');
                $sStmt->execute([':lid' => $id]);
                $record['schedule'] = $sStmt->fetchAll();
                successResponse($record);
            } catch (PDOException $e) {
                error_log('[Loans GET by ID] ' . $e->getMessage());
                serverErrorResponse('Database error.');
            }
        } else {
            try { $db = getDB(); loanEnsureSchema($db); } catch (Exception $_) { $db = getDB(); }
            $page = max(1, (int)($_GET['page'] ?? 1));
            // ★ FIX (RA-GLA-002): Increased max pageSize from 100 to 5000. The frontend needs to
            // load all loans for KPI cards, GL account overrides, and Cash Flow computations.
            // With max=100, the loan portfolio was massively understated in GL and Cash Flow panels.
            $pageSize = max(1, min((int)($_GET['pageSize'] ?? 20), 5000));
            $offset = ($page - 1) * $pageSize;
            $params = [];
            $where = buildWhere($_GET, ['status', 'branch'], ['branch' => '='], $params);
            // ★ FIX (API-038): Apply branch isolation to loans list
            $staffBranches = $staff['branches'] ?? [];
            $clientBranch = sanitize($_GET['branch'] ?? '');
            $branchFilter = applyBranchFilter($staffBranches, $clientBranch, $params, $staff['role'] ?? '', 'branch');
            if ($branchFilter) { $where .= ($where ? ' AND ' : ' WHERE ') . substr($branchFilter, 5); }
            try {
                $db = getDB();
                $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM loans ' . $where);
                $countStmt->execute($params);
                $total = (int)$countStmt->fetch()['total'];
                $stmt = $db->prepare('SELECT * FROM loans ' . $where . ' ORDER BY created_at DESC LIMIT CAST(:limit AS INTEGER) OFFSET CAST(:offset AS INTEGER)');
                foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
                $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                // Embed schedule for each loan (needed for frontend schedule rendering on load)
                $schStmt = $db->prepare('SELECT * FROM loan_schedule WHERE loan_id = :lid ORDER BY installment ASC');
                foreach ($rows as &$row) {
                    $schStmt->execute([':lid' => (int)$row['id']]);
                    $row['schedule'] = $schStmt->fetchAll(PDO::FETCH_ASSOC);
                }
                unset($row);
                paginatedResponse($rows, $total, $page, $pageSize);
            } catch (PDOException $e) {
                error_log('[Loans GET list] ' . $e->getMessage());
                serverErrorResponse('Database error.');
            }
        }
        break;
    case 'POST':
        /* ─── POST: Rollback disbursement (orphaned loan cleanup) ───
           POST /api/loans/{id}/rollback-disbursement
           Atomically reverts a failed disbursement: loan → APPROVED, schedule deleted,
           disbursed dates cleared. Used when the fund debit failed but the loan was
           already marked ACTIVE and schedule persisted. */
        if (($_ROUTE['subResource'] ?? '') === 'rollback-disbursement') {
            requireRole(['ADMIN', 'MANAGER', 'ACCOUNTANT'], $staff);
            if ($id === null) { validationError(['id' => 'Loan ID is required.']); }
            errorResponse('Loan rollback is disabled by policy. Reversal/rollback of disbursed loans is not allowed.', 403);
            try {
                $db = getDB();
                loanEnsureSchema($db);
                $input = getRequestInput();
                $rollbackReason = sanitize((string)($input['reason'] ?? 'Fund debit failed or disbursement integrity rollback.'));
                if ($rollbackReason === '') {
                    $rollbackReason = 'Fund debit failed or disbursement integrity rollback.';
                }

                // Verify loan exists and lock row for atomic rollback.
                $checkStmt = $db->prepare(
                    'SELECT id, loan_number, status, customer_id, principal, outstanding, debit_account_id, debit_account_number, branch, disbursed_at
                     FROM loans WHERE id = :id FOR UPDATE'
                );
                $checkStmt->execute([':id' => (int)$id]);
                $loan = $checkStmt->fetch(PDO::FETCH_ASSOC);
                if (!$loan) { notFoundResponse('Loan not found.'); }
                if (!loanCanAccessBranch($staff, (string)($loan['branch'] ?? ''))) {
                    errorResponse('Access denied: This loan belongs to a branch outside your assignment.', 403);
                }
                $currentStatus = strtoupper((string)($loan['status'] ?? ''));
                if ($currentStatus !== 'ACTIVE') {
                    // Idempotent success if a previous rollback already returned the loan to APPROVED.
                    if ($currentStatus === 'APPROVED' && empty($loan['disbursed_at'])) {
                        successResponse([
                            'loan_id' => (int)$id,
                            'loan_number' => (string)($loan['loan_number'] ?? ''),
                            'new_status' => 'APPROVED',
                            'schedule_entries_deleted' => 0,
                            'applications_reverted' => 0,
                            'transactions_reversed' => 0,
                            'reversed_amount' => 0.0,
                            'idempotent' => true,
                            'message' => 'Loan is already in APPROVED state with no disbursement date.'
                        ]);
                        break;
                    }
                    errorResponse('Only ACTIVE loans can be rolled back. Current status: ' . $currentStatus . '.', 400);
                    break;
                }
                // Business guard: rollback is forbidden once any repayment cycle has been paid.
                $paidCycleStmt = $db->prepare(
                    "SELECT COUNT(*) FROM loan_schedule
                      WHERE loan_id = :lid
                        AND (
                          COALESCE(paid,0) > 0.009
                          OR status IN ('PAID','PARTIALLY_PAID','WAIVED')
                        )"
                );
                $paidCycleStmt->execute([':lid' => (int)$id]);
                $paidCycleCount = (int)$paidCycleStmt->fetchColumn();
                if ($paidCycleCount > 0) {
                    errorResponse(
                        'Rollback blocked: this loan already has repayment activity. Use adjustment/reversal workflow instead.',
                        409
                    );
                    break;
                }

                $loanNumber = $loan['loan_number'];
                $principal = (float)$loan['principal'];
                $debitAccountId = $loan['debit_account_id'];
                $debitAccountNumber = sanitize((string)($loan['debit_account_number'] ?? ''));
                $customerId = (int)$loan['customer_id'];

                $db->beginTransaction();
                try {
                    // 1. Revert loan status to APPROVED, clear disbursed dates
                    $updStmt = $db->prepare(
                        "UPDATE loans SET status = 'APPROVED', outstanding = principal,
                            disbursed_at = NULL, maturity_date = NULL, next_due = NULL,
                            accrued_interest = 0, capitalized_interest = 0, settled_at = NULL,
                            updated_at = NOW() WHERE id = :id"
                    );
                    $updStmt->execute([':id' => (int)$id]);

                    // 2. Delete all schedule entries for this loan
                    $delStmt = $db->prepare('DELETE FROM loan_schedule WHERE loan_id = :id');
                    $delStmt->execute([':id' => (int)$id]);
                    $scheduleCount = $delStmt->rowCount();

                    // 3. ★ ALSO revert loan_applications status from DISBURSED back to APPROVED
                    //    (prevents the application from disappearing from the Applications tab)
                    $appRevertCount = 0;
                    try {
                        $appRevertStmt = $db->prepare(
                            "UPDATE loan_applications SET status = 'APPROVED', loan_id = NULL, decided_at = NULL WHERE loan_id = :loan_id"
                        );
                        $appRevertStmt->execute([':loan_id' => (int)$id]);
                        $appRevertCount = $appRevertStmt->rowCount();
                    } catch (PDOException $e) {
                        $appRevertCount = 0;
                    }

                    // 4. Find and reverse any POSTED LOAN_DISBURSEMENT transactions on the customer account.
                    // Use transaction amounts as the authority for account reversal (net disbursement in CU mode),
                    // not loan principal.
                    $reversedCount = 0;
                    $reversedAmount = 0.0;
                    $likeParam = '%' . $loanNumber . '%';
                    $txns = [];
                    $fundLinkedTxnRefs = [];
                    try {
                        // Build a deterministic list of disbursement refs from loan_fund_transactions.
                        // These refs are the strongest linkage between loan_id and customer-account credits.
                        try {
                            $refStmt = $db->prepare(
                                "SELECT transaction_ref
                                   FROM loan_fund_transactions
                                  WHERE loan_id = :lid
                                    AND type = 'DEBIT'
                                    AND COALESCE(transaction_ref, '') <> ''
                                  ORDER BY id DESC"
                            );
                            $refStmt->execute([':lid' => (int)$id]);
                            $fundLinkedTxnRefs = array_values(array_unique(array_filter(array_map(
                                fn($r) => sanitize((string)($r['transaction_ref'] ?? '')),
                                $refStmt->fetchAll(PDO::FETCH_ASSOC) ?: []
                            ))));
                        } catch (PDOException $e) {
                            $fundLinkedTxnRefs = [];
                        }

                        if ($debitAccountId) {
                            $txnStmt = $db->prepare(
                                "SELECT id, ref, amount, direction, branch, account_id, account
                                   FROM transactions
                                  WHERE account_id = :acct_id
                                    AND type = 'LOAN_DISBURSEMENT'
                                    AND status IN ('POSTED','COMPLETED')
                                    AND (description LIKE :desc1 OR description LIKE :desc2 OR memo LIKE :desc1 OR memo LIKE :desc2 OR ref LIKE :desc2)
                                  ORDER BY id DESC"
                            );
                            $txnStmt->execute([
                                ':acct_id' => (int)$debitAccountId,
                                ':desc1' => $likeParam,
                                ':desc2' => '%' . (int)$id . '%'
                            ]);
                            $txns = $txnStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        }
                        if (empty($txns) && $debitAccountNumber !== '') {
                            $txnStmt = $db->prepare(
                                "SELECT id, ref, amount, direction, branch, account_id, account
                                   FROM transactions
                                  WHERE account = :acct
                                    AND type = 'LOAN_DISBURSEMENT'
                                    AND status IN ('POSTED','COMPLETED')
                                    AND (description LIKE :desc1 OR description LIKE :desc2 OR memo LIKE :desc1 OR memo LIKE :desc2 OR ref LIKE :desc2)
                                  ORDER BY id DESC"
                            );
                            $txnStmt->execute([
                                ':acct' => $debitAccountNumber,
                                ':desc1' => $likeParam,
                                ':desc2' => '%' . (int)$id . '%'
                            ]);
                            $txns = $txnStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        }

                        // Fallback/augmentation: if loan_fund_transactions captured transaction refs,
                        // use them to fetch exact disbursement credits even when legacy memo/desc matching fails.
                        if (!empty($fundLinkedTxnRefs)) {
                            $ph = [];
                            $params = [];
                            foreach ($fundLinkedTxnRefs as $i => $ref) {
                                $k = ':r' . $i;
                                $ph[] = $k;
                                $params[$k] = $ref;
                            }
                            if (!empty($ph)) {
                                $txnRefStmt = $db->prepare(
                                    "SELECT id, ref, amount, direction, branch, account_id, account
                                       FROM transactions
                                      WHERE ref IN (" . implode(',', $ph) . ")
                                        AND type = 'LOAN_DISBURSEMENT'
                                        AND status IN ('POSTED','COMPLETED')
                                      ORDER BY id DESC"
                                );
                                foreach ($params as $k => $v) $txnRefStmt->bindValue($k, $v);
                                $txnRefStmt->execute();
                                $txnsByRef = $txnRefStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                                if (!empty($txnsByRef)) {
                                    $seen = [];
                                    $merged = [];
                                    foreach (array_merge($txns, $txnsByRef) as $row) {
                                        $tid = (int)($row['id'] ?? 0);
                                        if ($tid <= 0 || isset($seen[$tid])) continue;
                                        $seen[$tid] = true;
                                        $merged[] = $row;
                                    }
                                    $txns = $merged;
                                }
                            }
                        }
                    } catch (PDOException $e) {
                        $txns = [];
                    }

                    foreach ($txns as $txn) {
                        $reversedAmount += max(0, (float)($txn['amount'] ?? 0));
                    }
                    if (empty($txns)) {
                        // Never allow a "status-only" rollback that does not reverse the credited funds.
                        errorResponse(
                            'Rollback blocked: no posted loan disbursement transaction was found to reverse on the credited account.',
                            409
                        );
                    }
                    if ($reversedAmount <= 0) {
                        errorResponse('Rollback blocked: disbursement reversal amount is zero.', 409);
                    }

                    // Reverse balances per actual credited account touched by disbursement transactions.
                    // This avoids relying on a single loan.debit_account_id that may be stale/missing.
                    $reversalByAccount = [];
                    foreach ($txns as $txn) {
                        $amt = max(0, (float)($txn['amount'] ?? 0));
                        if ($amt <= 0) continue;
                        $accId = (int)($txn['account_id'] ?? 0);
                        $accNo = sanitize((string)($txn['account'] ?? ''));
                        $key = $accId > 0 ? ('id:' . $accId) : ('no:' . $accNo);
                        if (!isset($reversalByAccount[$key])) {
                            $reversalByAccount[$key] = ['account_id' => $accId, 'account_no' => $accNo, 'amount' => 0.0];
                        }
                        $reversalByAccount[$key]['amount'] += $amt;
                    }
                    if (empty($reversalByAccount)) {
                        errorResponse('Rollback blocked: no valid credited account was resolved for reversal.', 409);
                    }

                    $acctCheck = $db->prepare("SELECT id, ledger_balance, available_balance FROM accounts WHERE id = :acct_id FOR UPDATE");
                    $acctFindByNo = $db->prepare("SELECT id FROM accounts WHERE account_number = :acc LIMIT 1");
                    $acctDebit = $db->prepare(
                        "UPDATE accounts
                            SET ledger_balance = ledger_balance - :amt_ledger,
                                available_balance = available_balance - :amt_avail
                          WHERE id = :acct_id"
                    );

                    foreach ($reversalByAccount as $accRow) {
                        $targetAccountId = (int)($accRow['account_id'] ?? 0);
                        if ($targetAccountId <= 0) {
                            $accNo = (string)($accRow['account_no'] ?? '');
                            if ($accNo === '') {
                                errorResponse('Rollback blocked: destination account is unresolved for a disbursement transaction.', 409);
                            }
                            $acctFindByNo->execute([':acc' => $accNo]);
                            $targetAccountId = (int)($acctFindByNo->fetchColumn() ?: 0);
                        }
                        if ($targetAccountId <= 0) {
                            errorResponse('Rollback blocked: destination account not found for disbursement reversal.', 409);
                        }

                        $amt = (float)($accRow['amount'] ?? 0);
                        if ($amt <= 0) continue;

                        $acctCheck->execute([':acct_id' => $targetAccountId]);
                        $current = $acctCheck->fetch(PDO::FETCH_ASSOC);
                        if (!$current) {
                            errorResponse('Rollback blocked: destination account record is missing for reversal.', 409);
                        }
                        $ledgerBal = (float)($current['ledger_balance'] ?? 0);
                        $availBal = (float)($current['available_balance'] ?? 0);
                        if ($ledgerBal + 0.0001 < $amt || $availBal + 0.0001 < $amt) {
                            errorResponse(
                                'Rollback blocked: destination account balance is lower than required reversal amount. ' .
                                'Available=' . number_format($availBal, 2) . ' XAF, required=' . number_format($amt, 2) . ' XAF.',
                                409
                            );
                        }

                        $acctDebit->execute([
                            ':amt_ledger' => $amt,
                            ':amt_avail' => $amt,
                            ':acct_id' => $targetAccountId
                        ]);
                    }
                    if (!empty($txns)) {
                        $rollbackStamp = '[ROLLED BACK on ' . date('Y-m-d H:i:s') . ' by ' . sanitize((string)($staff['full_name'] ?? 'System')) . '. Reason: ' . $rollbackReason . ']';
                        $revStmt = $db->prepare(
                            "UPDATE transactions
                                SET status = 'REVERSED',
                                    memo = COALESCE(memo,'') || :memo_suffix
                              WHERE id = :tid AND status IN ('POSTED','COMPLETED')"
                        );
                        foreach ($txns as $txn) {
                            $revStmt->execute([
                                ':tid' => (int)$txn['id'],
                                ':memo_suffix' => ' ' . $rollbackStamp
                            ]);
                            $reversedCount += $revStmt->rowCount();

                            // Keep GL in sync with transaction status reversal.
                            // Original disbursement transaction is a credit (handled like DEPOSIT in txn API: DR 1000 / CR 2000),
                            // so rollback posts the inverse: DR 2000 / CR 1000.
                            $txnAmt = (float)($txn['amount'] ?? 0);
                            if ($txnAmt > 0) {
                                $txnRef = sanitize((string)($txn['ref'] ?? ('TXN-' . (int)$txn['id'])));
                                $txnBranch = sanitize((string)($txn['branch'] ?? ($loan['branch'] ?? '')));
                                $glRef = 'REV-' . $txnRef;
                                try {
                                    processTransaction('LOAN_DISBURSEMENT_ROLLBACK_TXN', [
                                        'amount' => $txnAmt,
                                        'ref' => $glRef,
                                        'description' => 'Reversal of Loan Disbursement: ' . $txnRef,
                                        'branch' => $txnBranch,
                                        'staff_id' => (int)($staff['id'] ?? 0)
                                    ]);
                                } catch (Throwable $e) {
                                    error_log('[Loans Rollback] GL reversal post failed for transaction ' . $txnRef . ': ' . $e->getMessage());
                                    throw $e;
                                }
                            }
                        }
                    }

                    // 5. Reconcile loan-fund accounting if the disbursement fund debit was already posted.
                    // Disbursement posts DR 1201 / CR 1200 (fund decreases). Rollback must post
                    // DR 1200 / CR 1201 and add a CREDIT artifact to loan_fund_transactions.
                    $fundReversalCount = 0;
                    $fundReversedAmount = 0.0;
                    try {
                        $fundAccountIdStmt = $db->query("SELECT id FROM loan_fund_accounts WHERE account_number = 'BANK-LF-0001' LIMIT 1");
                        $fundAccountId = (int)($fundAccountIdStmt->fetchColumn() ?: 0);
                        if ($fundAccountId > 0) {
                            $fundDebitsStmt = $db->prepare(
                                "SELECT id, ref, amount, COALESCE(branch,'') AS branch
                                   FROM loan_fund_transactions
                                  WHERE loan_fund_account_id = :faid
                                    AND loan_id = :lid
                                    AND type = 'DEBIT'
                                    AND description LIKE '%disbursement%'
                                  ORDER BY id ASC
                                  FOR UPDATE"
                            );
                            $fundDebitsStmt->execute([
                                ':faid' => $fundAccountId,
                                ':lid' => (int)$id
                            ]);
                            $fundDebits = $fundDebitsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                            $fundRollbackExistsStmt = $db->prepare(
                                "SELECT id FROM loan_fund_transactions
                                  WHERE loan_fund_account_id = :faid
                                    AND loan_id = :lid
                                    AND type = 'CREDIT'
                                    AND COALESCE(transaction_ref,'') = :txn_ref
                                    AND description LIKE '%LOAN_DISBURSEMENT_ROLLBACK%'
                                  LIMIT 1"
                            );
                            $insGlStmt = $db->prepare(
                                "INSERT INTO general_ledger
                                    (account_code, account_name, debit, credit, date, reference, description, branch, posted_by, transaction_type, contra_account)
                                 VALUES
                                    (:code, :name, :debit, :credit, CURRENT_DATE, :ref, :desc, :branch, :posted_by, :txn_type, :contra)"
                            );
                            $insFundTxnStmt = $db->prepare(
                                "INSERT INTO loan_fund_transactions
                                    (ref, loan_fund_account_id, loan_id, transaction_ref, date, type, description, amount, balance_after, branch, operator)
                                 VALUES
                                    (:ref, :faid, :lid, :txn_ref, CURRENT_DATE, 'CREDIT', :desc, :amount, :bal_after, :branch, :operator)"
                            );
                            $gl1200BalStmt = $db->prepare(
                                "SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS net_balance
                                   FROM general_ledger
                                  WHERE account_code = '1200'"
                            );
                            foreach ($fundDebits as $fd) {
                                $fdRef = sanitize((string)($fd['ref'] ?? ''));
                                $fdAmount = (float)($fd['amount'] ?? 0);
                                if ($fdAmount <= 0) continue;
                                $fundRollbackExistsStmt->execute([
                                    ':faid' => $fundAccountId,
                                    ':lid' => (int)$id,
                                    ':txn_ref' => $fdRef
                                ]);
                                if ($fundRollbackExistsStmt->fetch(PDO::FETCH_ASSOC)) {
                                    continue; // idempotent: reversal already posted for this fund debit ref
                                }

                                $restoreRef = 'LFRB-' . (int)$id . '-' . (int)($fd['id'] ?? 0);
                                $restoreBranch = sanitize((string)($fd['branch'] ?? '')) ?: sanitize((string)($loan['branch'] ?? ''));
                                $restoreDesc = 'Loan disbursement rollback restore — ' . $loanNumber .
                                    ' [LOAN_DISBURSEMENT_ROLLBACK — DR 1200 Loans and Advances / CR 1201 Loans Receivable]';

                                // DR 1200
                                $insGlStmt->execute([
                                    ':code' => '1200',
                                    ':name' => 'Loans and Advances',
                                    ':debit' => $fdAmount,
                                    ':credit' => 0,
                                    ':ref' => $restoreRef,
                                    ':desc' => $restoreDesc,
                                    ':branch' => $restoreBranch,
                                    ':posted_by' => (int)($staff['id'] ?? 0) ?: null,
                                    ':txn_type' => 'LOAN_DISBURSEMENT_ROLLBACK',
                                    ':contra' => '1201'
                                ]);
                                // CR 1201
                                $insGlStmt->execute([
                                    ':code' => '1201',
                                    ':name' => 'Loans Receivable',
                                    ':debit' => 0,
                                    ':credit' => $fdAmount,
                                    ':ref' => $restoreRef,
                                    ':desc' => $restoreDesc,
                                    ':branch' => $restoreBranch,
                                    ':posted_by' => (int)($staff['id'] ?? 0) ?: null,
                                    ':txn_type' => 'LOAN_DISBURSEMENT_ROLLBACK',
                                    ':contra' => '1200'
                                ]);

                                $gl1200BalStmt->execute();
                                $gl1200After = (float)$gl1200BalStmt->fetchColumn();

                                $insFundTxnStmt->execute([
                                    ':ref' => $restoreRef,
                                    ':faid' => $fundAccountId,
                                    ':lid' => (int)$id,
                                    ':txn_ref' => $fdRef,
                                    ':desc' => $restoreDesc,
                                    ':amount' => $fdAmount,
                                    ':bal_after' => $gl1200After,
                                    ':branch' => $restoreBranch,
                                    ':operator' => (string)($staff['full_name'] ?? 'System')
                                ]);

                                $db->prepare("UPDATE loan_fund_accounts SET balance = :bal WHERE id = :id")
                                   ->execute([':bal' => $gl1200After, ':id' => $fundAccountId]);

                                $fundReversalCount++;
                                $fundReversedAmount += $fdAmount;
                            }
                        }
                    } catch (PDOException $e) {
                        error_log('[Loans Rollback] Loan-fund reconciliation failed: ' . $e->getMessage());
                        throw $e;
                    }

                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    throw $e;
                }

                logAudit(
                    $staff['full_name'] ?? 'System', 'LOAN_ROLLBACK', 'LOAN', (string)$id, 'SUCCESS',
                    "Rolled back disbursement for loan $loanNumber (ID: $id). Reason: $rollbackReason. " .
                    "Schedule entries deleted: $scheduleCount. Applications reverted: $appRevertCount. " .
                    "Transactions reversed: $reversedCount. Reversed amount: " . number_format($reversedAmount, 2) . ' XAF. ' .
                    "Fund reversals: $fundReversalCount. Fund amount restored: " . number_format($fundReversedAmount, 2) . ' XAF.',
                    $staff['department'] ?? '', getClientIp()
                );

                successResponse([
                    'loan_id' => (int)$id,
                    'loan_number' => $loanNumber,
                    'new_status' => 'APPROVED',
                    'schedule_entries_deleted' => $scheduleCount,
                    'applications_reverted' => $appRevertCount,
                    'transactions_reversed' => $reversedCount,
                    'reversed_amount' => $reversedAmount,
                    'fund_reversals' => $fundReversalCount,
                    'fund_reversed_amount' => $fundReversedAmount
                ]);
            } catch (Throwable $e) {
                error_log('[Loans Rollback] ' . $e->getMessage());
                serverErrorResponse('Failed to rollback disbursement.');
            }
            break;
        }

        /* ─── POST: Auto-pay all due installments (server-side auto-deduction engine) ───
           POST /api/loans/auto-pay
           Processes all ACTIVE/DELINQUENT loans with auto_deduct=1 whose installments are due.
           For each loan, builds an account chain: PRIMARY (disbursement/debit account) first,
           then ALL other customer ACTIVE accounts sorted by available_balance DESC.
           Drains accounts in order: if primary pays partial, remainder comes from fallback accounts.
           Only marks installment as MISSED when ALL customer accounts are exhausted.
           100% database-driven — no frontend involvement required.
           Can be called by cron job, page-load trigger, or external scheduler. */
        if (($_ROUTE['id'] ?? '') === 'auto-pay' || ($_ROUTE['subResource'] ?? '') === 'auto-pay') {
            // The enterprise console triggers this sweep during login to catch installments
            // that became due while the app was offline. Do not hard-fail with 403 for staff
            // who are authenticated but do not have the Loans module; return a safe no-op
            // instead so login/restore flows remain stable.
            if (!hasModuleAccess('LOANS', $staff)) {
                successResponse([
                    'processed' => 0,
                    'full' => 0,
                    'partial' => 0,
                    'missed' => 0,
                    'settled' => 0,
                    'skipped' => 1,
                    'details' => []
                ], 'Auto-pay sweep skipped: current user has no Loans module access.');
            }
            $today = date('Y-m-d');
            $results = ['processed' => 0, 'full' => 0, 'partial' => 0, 'missed' => 0, 'settled' => 0, 'skipped' => 0, 'details' => []];

            try {
                $db = getDB();
                loanEnsureSchema($db);
                loanEnsureLoanFundReportingSchema($db);

                // Ensure auto-pay log table exists
                try {
                    $db->exec("CREATE TABLE IF NOT EXISTS loan_auto_pay_log (
                        id SERIAL PRIMARY KEY,
                        loan_id INTEGER NOT NULL,
                        installment INT NOT NULL,
                        account_id INTEGER NOT NULL,
                        account_number VARCHAR(50) NOT NULL DEFAULT '',
                        amount DECIMAL(18,2) NOT NULL DEFAULT 0,
                        principal_paid DECIMAL(18,2) NOT NULL DEFAULT 0,
                        interest_paid DECIMAL(18,2) NOT NULL DEFAULT 0,
                        payment_type VARCHAR(10) NOT NULL DEFAULT 'FULL',
                        txn_ref VARCHAR(50) NOT NULL DEFAULT '',
                        processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        operator VARCHAR(100) DEFAULT 'AUTO-PAY'
                    )");
                    try {
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_apay_loan ON loan_auto_pay_log (loan_id)");
                    } catch (PDOException $e) {}
                    try {
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_apay_date ON loan_auto_pay_log (processed_at)");
                    } catch (PDOException $e) {}
                } catch (PDOException $e) {
                    error_log('[AutoPay Schema] CREATE TABLE loan_auto_pay_log failed: ' . $e->getMessage());
                }

                // Find all eligible loans: ACTIVE or DELINQUENT with auto_deduct=1
                $loanStmt = $db->prepare(
                    "SELECT * FROM loans WHERE status IN ('ACTIVE','DELINQUENT') AND auto_deduct = 1 ORDER BY id ASC"
                );
                $loanStmt->execute();
                $loans = $loanStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($loans as $loan) {
                    $loanId = (int)$loan['id'];
                    $loanNumber = sanitize($loan['loan_number']);
                    $customerId = (int)$loan['customer_id'];
                    $results['processed']++;

                    // Get due installments: DUE, MISSED, or PARTIALLY_PAID with due_date <= today
                    $schedStmt = $db->prepare(
                        "SELECT * FROM loan_schedule WHERE loan_id = :lid
                         AND status IN ('DUE','MISSED','PARTIALLY_PAID')
                         AND due_date <= :today
                         ORDER BY installment ASC"
                    );
                    $schedStmt->execute([':lid' => $loanId, ':today' => $today]);
                    $dueInstallments = $schedStmt->fetchAll(PDO::FETCH_ASSOC);

                    if (empty($dueInstallments)) {
                        $results['skipped']++;
                        continue;
                    }

                    // Build account chain: borrower primary first, borrower fallback by balance DESC,
                    // then configured guarantor account (if active) as final liability fallback.
                    $acctStmt = $db->prepare(
                        "SELECT * FROM accounts WHERE customer_id = :cid AND status = 'ACTIVE' ORDER BY
                         CASE WHEN id = :debit_id THEN 0 ELSE 1 END ASC,
                         available_balance DESC
                         FOR UPDATE"
                    );
                    $acctStmt->execute([
                        ':cid' => $customerId,
                        ':debit_id' => (int)$loan['debit_account_id']
                    ]);
                    $accountChain = $acctStmt->fetchAll(PDO::FETCH_ASSOC);
                    $guarantorCustomerId = (int)($loan['guarantor_customer_id'] ?? 0);
                    $guarantorAccountId = (int)($loan['guarantor_account_id'] ?? 0);
                    if ($guarantorCustomerId > 0 && $guarantorAccountId > 0) {
                        $gAcctStmt = $db->prepare(
                            "SELECT * FROM accounts
                             WHERE id = :aid AND customer_id = :cid AND status = 'ACTIVE'
                             LIMIT 1
                             FOR UPDATE"
                        );
                        $gAcctStmt->execute([
                            ':aid' => $guarantorAccountId,
                            ':cid' => $guarantorCustomerId
                        ]);
                        $gAcct = $gAcctStmt->fetch(PDO::FETCH_ASSOC);
                        if ($gAcct) {
                            $alreadyInChain = false;
                            foreach ($accountChain as $chainAcct) {
                                if ((int)($chainAcct['id'] ?? 0) === (int)$gAcct['id']) {
                                    $alreadyInChain = true;
                                    break;
                                }
                            }
                            if (!$alreadyInChain) {
                                $accountChain[] = $gAcct;
                            }
                        }
                    }

                    try {
                        $db->beginTransaction();

                    if (empty($accountChain)) {
                        // No active accounts for this customer — mark all as MISSED
                        foreach ($dueInstallments as &$inst) {
                            if ((string)$inst['status'] === 'DUE') {
                                $db->prepare("UPDATE loan_schedule SET status = 'MISSED' WHERE id = :id")
                                   ->execute([':id' => (int)$inst['id']]);
                                $results['missed']++;
                            }
                        }
                        unset($inst);
                        // Flag loan as DELINQUENT if still ACTIVE
                        if ($loan['status'] === 'ACTIVE') {
                            $db->prepare("UPDATE loans SET status = 'DELINQUENT' WHERE id = :id")
                               ->execute([':id' => $loanId]);
                            logAudit($staff['full_name'] ?? 'AUTO-PAY', 'LOAN_DELINQUENT_AUTO', 'LOAN', (string)$loanId, 'SUCCESS',
                                "Auto-pay: $loanNumber flagged DELINQUENT — no active borrower/guarantor accounts.", $staff['department'] ?? '', getClientIp());
                        }
                        $results['details'][] = ['loan' => $loanNumber, 'result' => 'NO_ACCOUNTS', 'installments' => count($dueInstallments)];
                        $db->commit();
                        continue;
                    }

                    $loanModified = false;
                    $loanTotalPrincipalPaid = 0;
                    $loanTotalInterestPaid = 0;
                    $loanTotalAmountPaid = 0;
                    $loanTotalAccountsUsed = [];

                    foreach ($dueInstallments as &$inst) {
                        $instId = (int)$inst['id'];
                        $instNumber = (int)$inst['installment'];
                        $totalDue = round((float)$inst['principal'] + (float)$inst['interest'] - (float)$inst['paid'], 2);
                        if ($totalDue <= 0) continue;

                        $remainingDue = $totalDue;
                        $totalPrincipalPaid = 0;
                        $totalInterestPaid = 0;
                        $totalAmountPaid = 0;
                        $anyPaymentMade = false;
                        $paymentAccounts = [];

                        // Drain accounts in chain order
                        foreach ($accountChain as &$acct) {
                            $acctId = (int)$acct['id'];
                            $acctNumber = sanitize($acct['account_number']);
                            $availBal = (float)$acct['available_balance'];

                            if ($availBal <= 0) continue;

                            // How much can this account pay?
                            $canPay = min($remainingDue, $availBal);
                            if ($canPay <= 0) continue;

                            // Interest-first allocation (matching frontend logic)
                            $remainingInterest = max(0, (float)$inst['interest'] - min((float)$inst['paid'] + $totalInterestPaid, (float)$inst['interest']));
                            $interestPayment = min($remainingInterest, $canPay);
                            $principalPayment = min(max(0, (float)$inst['principal'] - max(0, ((float)$inst['paid'] + $totalAmountPaid) - (float)$inst['interest'])), $canPay - $interestPayment);
                            if ($principalPayment < 0) $principalPayment = 0;
                            $paymentAmount = round($interestPayment + $principalPayment, 2);
                            if ($paymentAmount <= 0) continue;

                            // Debit the account
                            $db->prepare(
                                "UPDATE accounts
                                 SET ledger_balance = ledger_balance - :amt_ledger,
                                     available_balance = available_balance - :amt_avail
                                 WHERE id = :aid"
                            )->execute([
                                ':amt_ledger' => $paymentAmount,
                                ':amt_avail' => $paymentAmount,
                                ':aid' => $acctId
                            ]);

                            // Update tracking
                            $totalPrincipalPaid += $principalPayment;
                            $totalInterestPaid += $interestPayment;
                            $totalAmountPaid += $paymentAmount;
                            $remainingDue = round($totalDue - $totalAmountPaid, 2);
                            $anyPaymentMade = true;
                            $loanModified = true;

                            // Refresh this account's balance in the chain (for next installment)
                            $acct['available_balance'] = round($availBal - $paymentAmount, 2);
                            $acct['ledger_balance'] = round((float)$acct['ledger_balance'] - $paymentAmount, 2);

                            $paymentAccounts[] = [
                                'account_id' => $acctId,
                                'account_number' => $acctNumber,
                                'customer_id' => (int)($acct['customer_id'] ?? 0),
                                'account_type' => (string)($acct['product_type'] ?? ($acct['account_type'] ?? '')),
                                'amount' => $paymentAmount,
                                'principal' => $principalPayment,
                                'interest' => $interestPayment
                            ];

                            if ($remainingDue <= 0) break; // Installment fully paid
                        }
                        unset($acct);

                        // Create transaction(s) and log for each account that paid
                        foreach ($paymentAccounts as $pAcct) {
                            $payerLabel = ((int)($pAcct['customer_id'] ?? 0) === $customerId)
                                ? ('borrower account ' . $pAcct['account_number'])
                                : ('guarantor account ' . $pAcct['account_number']);
                            // ★ FIX (API-036): Use generateRef() which has race-safe MAX-based approach
                            $txnRef = generateRef('TXN');
                            $postingBranch = trim((string)($loan['branch'] ?? ($pAcct['branch'] ?? ($staff['branch'] ?? ''))));
                            loanInsertTransaction($db, [
                                'ref' => $txnRef,
                                'type' => 'LOAN_REPAYMENT',
                                'direction' => 'debit',
                                'account' => $pAcct['account_number'],
                                'account_type' => $pAcct['account_type'] ?? '',
                                'customer_name' => $loan['customer_name'] ?? '',
                                'branch' => $postingBranch,
                                'amount' => (float)$pAcct['amount'],
                                'net_amount' => (float)$pAcct['amount'],
                                'status' => 'POSTED',
                                'category' => 'Loan Repayment',
                                'module' => 'Loans',
                                'description' => "Auto repayment — $loanNumber Installment #$instNumber",
                                'memo' => "AUTO-PAY " . ($remainingDue <= 0 ? 'full' : 'partial') . " installment from {$payerLabel}. Principal: {$pAcct['principal']}, Interest: {$pAcct['interest']}. Chain payment across borrower and guarantor accounts.",
                                'fee' => 0,
                                'fee_pct' => 0,
                                'total_tax' => 0,
                                'fee_mode' => '',
                                'operator_id' => (int)($staff['id'] ?? 0),
                                'operator_name' => $staff['full_name'] ?? 'AUTO-PAY'
                            ]);

                            // Log to auto-pay table
                            try {
                                // ★ GL INTEGRATION (AUTO-PAY)
                                // Principal: DR 1200 (Loans & Advances Fund) / CR 1201 (Loans Receivable)
                                if ($pAcct['principal'] > 0) {
                                    processTransaction('LOAN_PRINCIPAL_REPAYMENT', [
                                        'amount' => (float)$pAcct['principal'],
                                        'ref' => $txnRef,
                                        'description' => "Auto principal repayment - $loanNumber",
                                        'branch' => $postingBranch,
                                        'staff_id' => (int)($staff['id'] ?? 0)
                                    ]);
                                    $lfId = loanGetLoanFundAccountId($db, 'BANK-LF-0001');
                                    if ($lfId) {
                                        $lfBalAfter = loanGetGLAssetBalance($db, '1200', $postingBranch);
                                        loanInsertLoanFundTxnIfMissing($db, [
                                            'ref' => $txnRef,
                                            'loan_fund_account_id' => $lfId,
                                            'loan_id' => $loanId,
                                            'transaction_ref' => $txnRef,
                                            'date' => $today,
                                            'type' => 'CREDIT',
                                            'description' => "Loan principal recovered - $loanNumber",
                                            'amount' => (float)$pAcct['principal'],
                                            'balance_after' => $lfBalAfter,
                                            'branch' => $postingBranch,
                                            'operator' => $staff['full_name'] ?? 'AUTO-PAY'
                                        ]);
                                    }
                                }
                                // Interest: DR 1201 (Loans Receivable) / CR 4200 (Loan Interest Income)
                                if ($pAcct['interest'] > 0) {
                                    processTransaction('LOAN_INTEREST_PAYMENT', [
                                        'amount' => (float)$pAcct['interest'],
                                        'ref' => $txnRef,
                                        'description' => "Auto interest payment - $loanNumber",
                                        'branch' => $postingBranch,
                                        'staff_id' => (int)($staff['id'] ?? 0)
                                    ]);
                                    $liId = loanGetLoanFundAccountId($db, 'BANK-LI-0001');
                                    if ($liId) {
                                        $balAfter = loanGetGLIncomeBalance($db, '4200', $postingBranch);
                                        loanInsertLoanFundTxnIfMissing($db, [
                                            'ref' => $txnRef,
                                            'loan_fund_account_id' => $liId,
                                            'loan_id' => $loanId,
                                            'transaction_ref' => $txnRef,
                                            'date' => $today,
                                            'type' => 'CREDIT',
                                            'description' => "Loan interest received - $loanNumber",
                                            'amount' => (float)$pAcct['interest'],
                                            'balance_after' => $balAfter,
                                            'branch' => $postingBranch,
                                            'operator' => $staff['full_name'] ?? 'AUTO-PAY'
                                        ]);
                                    }
                                }

                                $db->prepare(
                                    "INSERT INTO loan_auto_pay_log (loan_id, installment, account_id, account_number, amount, principal_paid, interest_paid, payment_type, txn_ref, operator)
                                     VALUES (:lid, :inst, :aid, :anum, :amt, :ppaid, :ipaid, :ptype, :ref, :op)"
                                )->execute([
                                    ':lid'   => $loanId,
                                    ':inst'  => $instNumber,
                                    ':aid'   => $pAcct['account_id'],
                                    ':anum'  => $pAcct['account_number'],
                                    ':amt'   => $pAcct['amount'],
                                    ':ppaid' => $pAcct['principal'],
                                    ':ipaid' => $pAcct['interest'],
                                    ':ptype' => ($remainingDue <= 0 && !$anyPaymentMade ? 'FULL' : ($remainingDue <= 0 ? 'FULL' : 'PARTIAL')),
                                    ':ref'   => $txnRef,
                                    ':op'    => $staff['full_name'] ?? 'AUTO-PAY'
                                ]);
                            } catch (Throwable $e) {
                                throw $e;
                            }
                        }

                        // Update installment status and paid amount
                        $newPaid = round((float)$inst['paid'] + $totalAmountPaid, 2);
                        $totalInstallment = round((float)$inst['principal'] + (float)$inst['interest'], 2);

                        if ($newPaid >= $totalInstallment - 0.01) {
                            // Fully paid
                            $db->prepare("UPDATE loan_schedule SET paid = :total, status = 'PAID' WHERE id = :id")
                               ->execute([':total' => $totalInstallment, ':id' => $instId]);
                            $results['full']++;
                        } elseif ($anyPaymentMade) {
                            // Partially paid
                            $db->prepare("UPDATE loan_schedule SET paid = :paid, status = 'PARTIALLY_PAID' WHERE id = :id")
                               ->execute([':paid' => $newPaid, ':id' => $instId]);
                            $results['partial']++;
                        } else {
                            // No account could pay — mark MISSED
                            if ((string)$inst['status'] === 'DUE') {
                                $db->prepare("UPDATE loan_schedule SET status = 'MISSED' WHERE id = :id")
                                   ->execute([':id' => $instId]);
                                $results['missed']++;
                            }
                        }
                        // Accumulate into loan-level totals
                        $loanTotalPrincipalPaid += $totalPrincipalPaid;
                        $loanTotalInterestPaid += $totalInterestPaid;
                        $loanTotalAmountPaid += $totalAmountPaid;
                        foreach ($paymentAccounts as $pa) { $loanTotalAccountsUsed[] = $pa['account_id']; }
                    }
                    unset($inst);

                    // Update loan: outstanding, accrued_interest, status, next_due
                    if ($loanModified) {
                        $newOutstanding = max(0, round((float)$loan['outstanding'] - $loanTotalPrincipalPaid, 2));
                        $newAccrued = max(0, round((float)$loan['accrued_interest'] - $loanTotalInterestPaid, 2));
                        $newStatus = (string)$loan['status'];

                        if ($newOutstanding <= 0) {
                            $newOutstanding = 0;
                            $newAccrued = 0;
                            $newStatus = 'SETTLED';
                            $results['settled']++;
                        }

                        // Recalculate next_due from remaining schedule
                        $nextDueStmt = $db->prepare(
                            "SELECT due_date FROM loan_schedule WHERE loan_id = :lid AND status IN ('DUE','MISSED','PARTIALLY_PAID') ORDER BY due_date ASC LIMIT 1"
                        );
                        $nextDueStmt->execute([':lid' => $loanId]);
                        $nextDueRow = $nextDueStmt->fetch(PDO::FETCH_ASSOC);
                        $nextDue = $nextDueRow ? $nextDueRow['due_date'] : null;
                        if ($newStatus === 'SETTLED') $nextDue = null;

                        $db->prepare(
                            "UPDATE loans SET outstanding = :out, accrued_interest = :acc, status = :st, next_due = :nd, updated_at = NOW() WHERE id = :id"
                        )->execute([
                            ':out' => $newOutstanding,
                            ':acc' => $newAccrued,
                            ':st'  => $newStatus,
                            ':nd'  => $nextDue,
                            ':id'  => $loanId
                        ]);

                        $results['details'][] = [
                            'loan' => $loanNumber,
                            'result' => $newStatus === 'SETTLED' ? 'SETTLED' : 'PAID',
                            'amount_paid' => $loanTotalAmountPaid,
                            'accounts_used' => count(array_unique($loanTotalAccountsUsed))
                        ];
                    }

                    // If loan was ACTIVE and any installment was MISSED, flag as DELINQUENT
                    // ★ FIX (LOAN-023): Check per-loan missed installments, not global counter
                    if ($loan['status'] === 'ACTIVE') {
                        $checkMissed = $db->prepare("SELECT COUNT(*) FROM loan_schedule WHERE loan_id = :lid AND status = 'MISSED'");
                        $checkMissed->execute([':lid' => $loanId]);
                        if ((int)$checkMissed->fetchColumn() > 0) {
                            $db->prepare("UPDATE loans SET status = 'DELINQUENT' WHERE id = :id AND status = 'ACTIVE'")
                               ->execute([':id' => $loanId]);
                        }
                    }

                        $db->commit();
                    } catch (Throwable $txnEx) {
                        try { $db->rollBack(); } catch (Exception $_) {}
                        error_log('[AutoPay] Per-loan transaction failed for loan ' . $loanId . ': ' . $txnEx->getMessage());
                        $results['details'][] = ['loan' => $loanNumber, 'result' => 'TXN_FAILED', 'error' => $txnEx->getMessage()];
                        continue;
                    }
                }

                logAudit($staff['full_name'] ?? 'AUTO-PAY', 'AUTO_PAY_BATCH', 'LOAN', 'ALL', 'SUCCESS',
                    "Auto-pay batch: {$results['processed']} loans processed, {$results['full']} full, {$results['partial']} partial, {$results['missed']} missed, {$results['settled']} settled, {$results['skipped']} skipped.",
                    $staff['department'] ?? '', getClientIp());

                successResponse($results);

            } catch (PDOException $e) {
                error_log('[Loans AutoPay] ' . $e->getMessage());
                serverErrorResponse('Auto-pay processing failed.');
            }
            break;
        }

        /* ─── POST: Record Repayment ───
           POST /api/loans/{id}/repay
           Atomically processes a manual repayment for a loan.
           Supports FULL (next installment), PARTIAL, and PAYOFF (entire loan).
        */
        if (($_ROUTE['subResource'] ?? '') === 'repay') {
            if ($id === null) { validationError(['id' => 'Loan ID is required.']); }
            $input = getRequestInput();
            $payType = $input['pay_type'] ?? 'full'; // 'full', 'partial', 'payoff'
            $amountParsed = parseDecimalInput($input['amount'] ?? 0, 'Repayment amount', 2, 0, 1000000000000, false);
            if (!$amountParsed['ok']) { validationError(['amount' => $amountParsed['error']]); }
            $amount = $amountParsed['value'];
            $debitAcctId = (int)($input['debit_account_id'] ?? 0);
            $memo = sanitize($input['memo'] ?? '');

            try {
                $stage = 'init';
                $db = getDB();
                loanEnsureSchema($db);
                loanEnsureLoanFundReportingSchema($db);

                // 1. Get Loan and Account info
                $stage = 'load_loan';
                $loanStmt = $db->prepare("SELECT * FROM loans WHERE id = :id");
                $loanStmt->execute(['id' => $id]);
                $loan = $loanStmt->fetch(PDO::FETCH_ASSOC);
                if (!$loan) { notFoundResponse('Loan not found.'); }
                if (!loanCanAccessBranch($staff, (string)($loan['branch'] ?? ''))) {
                    errorResponse('Access denied: This loan belongs to a branch outside your assignment.', 403);
                }
                if (!in_array($loan['status'], ['ACTIVE', 'DELINQUENT', 'RESTRUCTURED'], true)) {
                    errorResponse('Only ACTIVE, DELINQUENT, or RESTRUCTURED loans can accept repayments. Current status: ' . $loan['status'] . '.', 400);
                }

                $stage = 'load_account';
                $acctStmt = $db->prepare("SELECT * FROM accounts WHERE id = :id AND status = 'ACTIVE'");
                $acctStmt->execute(['id' => $debitAcctId]);
                $acct = $acctStmt->fetch(PDO::FETCH_ASSOC);
                if (!$acct) { errorResponse('Active debit account not found.', 400); }
                $loanCustomerId = (int)($loan['customer_id'] ?? 0);
                $loanGuarantorCustomerId = (int)($loan['guarantor_customer_id'] ?? 0);
                $loanGuarantorAccountId = (int)($loan['guarantor_account_id'] ?? 0);
                $isBorrowerAccount = (int)($acct['customer_id'] ?? 0) === $loanCustomerId;
                $isGuarantorAccount = $loanGuarantorCustomerId > 0
                    && $loanGuarantorAccountId > 0
                    && (int)($acct['id'] ?? 0) === $loanGuarantorAccountId
                    && (int)($acct['customer_id'] ?? 0) === $loanGuarantorCustomerId;
                if (!$isBorrowerAccount && !$isGuarantorAccount) {
                    errorResponse('Repayment account must be a borrower account or the configured guarantor account for this loan.', 400);
                }
                if (!loanCanAccessBranch($staff, (string)($acct['branch'] ?? ''))) {
                    errorResponse('Access denied: This debit account belongs to a branch outside your assignment.', 403);
                }
                $repaymentPayerLabel = $isGuarantorAccount ? ('guarantor account ' . ($acct['account_number'] ?? '')) : ('borrower account ' . ($acct['account_number'] ?? ''));

                // 2. Calculate amounts
                $today = date('Y-m-d');
                $stage = 'load_schedule';
                $allScheduleStmt = $db->prepare("SELECT * FROM loan_schedule WHERE loan_id = :lid AND status IN ('DUE','MISSED','PARTIALLY_PAID','UPCOMING') ORDER BY installment ASC");
                $allScheduleStmt->execute(['lid' => $id]);
                $allSchedule = $allScheduleStmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($allSchedule)) {
                    errorResponse('No repayment schedule is available for this loan.', 400);
                }

                $totalPrincipalToPay = 0;
                $totalInterestToPay = 0;
                $installmentsToUpdate = [];

                if ($payType === 'payoff') {
                    // Early payoff logic (borrower benefit: waived future interest)
                    foreach ($allSchedule as $inst) {
                        $remainingInterest = max(0, (float)$inst['interest'] - (float)$inst['paid']);
                        $remainingPrincipal = max(0, (float)$inst['principal'] - max(0, (float)$inst['paid'] - (float)$inst['interest']));
                        
                        // If cycle clocked or due, pay full interest. Otherwise principal only.
                        if ($inst['due_date'] <= $today || $inst['status'] !== 'UPCOMING') {
                            $totalInterestToPay += $remainingInterest;
                            $totalPrincipalToPay += $remainingPrincipal;
                        } else {
                            $totalPrincipalToPay += $remainingPrincipal;
                        }
                        $installmentsToUpdate[] = [
                            'id' => $inst['id'],
                            'paid' => $inst['principal'] + $inst['interest'], // mark fully paid
                            'status' => (($inst['due_date'] <= $today || $inst['status'] !== 'UPCOMING') ? 'PAID' : 'WAIVED')
                        ];
                    }
                } elseif ($payType === 'full') {
                    // Pay next installment (prefer due/overdue; fallback to upcoming for early repayment)
                    $next = null;
                    foreach ($allSchedule as $inst) {
                        if (in_array($inst['status'], ['DUE','MISSED','PARTIALLY_PAID'], true)) {
                            $next = $inst;
                            break;
                        }
                    }
                    if (!$next) {
                        foreach ($allSchedule as $inst) {
                            if (($inst['status'] ?? '') === 'UPCOMING') {
                                $next = $inst;
                                break;
                            }
                        }
                    }
                    if (!$next) { errorResponse('No payable installments found for full payment.', 400); }
                    
                    $totalInterestToPay = max(0, (float)$next['interest'] - (float)$next['paid']);
                    $totalPrincipalToPay = max(0, (float)$next['principal'] - max(0, (float)$next['paid'] - (float)$next['interest']));
                    $installmentsToUpdate[] = [
                        'id' => $next['id'],
                        'paid' => $next['principal'] + $next['interest'],
                        'status' => 'PAID'
                    ];
                } else {
                    // Partial payment (allocated to next installment; early partial allowed on upcoming)
                    if ($amount <= 0) { validationError(['amount' => 'Repayment amount must be positive.']); }
                    $next = null;
                    foreach ($allSchedule as $inst) {
                        if (in_array($inst['status'], ['DUE','MISSED','PARTIALLY_PAID'], true)) {
                            $next = $inst;
                            break;
                        }
                    }
                    if (!$next) {
                        foreach ($allSchedule as $inst) {
                            if (($inst['status'] ?? '') === 'UPCOMING') {
                                $next = $inst;
                                break;
                            }
                        }
                    }
                    if (!$next) { errorResponse('No payable installments found for partial payment.', 400); }
                    
                    $remainingInterest = max(0, (float)$next['interest'] - (float)$next['paid']);
                    $maxInstallmentDue = round(
                        max(0, (float)$next['principal'] + (float)$next['interest'] - (float)$next['paid']),
                        2
                    );
                    if ($amount - $maxInstallmentDue > 0.01) {
                        validationError(['amount' => 'Partial repayment cannot exceed the current installment due amount of ' . $maxInstallmentDue . '.']);
                    }
                    $totalInterestToPay = min($remainingInterest, $amount);
                    $totalPrincipalToPay = $amount - $totalInterestToPay;
                    
                    $newPaid = (float)$next['paid'] + $amount;
                    $fullAmt = (float)$next['principal'] + (float)$next['interest'];
                    $installmentsToUpdate[] = [
                        'id' => $next['id'],
                        'paid' => $newPaid,
                        'status' => ($newPaid >= $fullAmt - 0.01) ? 'PAID' : 'PARTIALLY_PAID'
                    ];
                }

                $totalPayment = round($totalPrincipalToPay + $totalInterestToPay, 2);
                if ($totalPayment <= 0) {
                    errorResponse('No payable amount was calculated for this repayment.', 400);
                }
                if ($acct['available_balance'] < $totalPayment) {
                    errorResponse("Insufficient funds. Required: $totalPayment, Available: {$acct['available_balance']}", 400);
                }

                // 3. Atomically Process
                $stage = 'begin_txn';
                $db->beginTransaction();
                
                // A. Debit Account
                $stage = 'debit_account';
                $db->prepare(
                    "UPDATE accounts
                     SET ledger_balance = ledger_balance - :amt_ledger,
                         available_balance = available_balance - :amt_avail
                     WHERE id = :aid"
                )->execute([
                    'amt_ledger' => $totalPayment,
                    'amt_avail' => $totalPayment,
                    'aid' => $debitAcctId
                ]);

                // B. Update Schedule
                $stage = 'update_schedule';
                $updSchStmt = $db->prepare("UPDATE loan_schedule SET paid = :paid, status = :st WHERE id = :id");
                foreach ($installmentsToUpdate as $iu) {
                    $updSchStmt->execute(['paid' => $iu['paid'], 'st' => $iu['status'], 'id' => $iu['id']]);
                }

                // C. Update Loan
                $stage = 'update_loan';
                $newStatus = ($payType === 'payoff') ? 'SETTLED' : $loan['status'];
                $newOutstanding = max(0, (float)$loan['outstanding'] - $totalPrincipalToPay);
                if ($newOutstanding <= 0.01) { $newOutstanding = 0; $newStatus = 'SETTLED'; }
                
                if ($newStatus === 'SETTLED') {
                    $db->prepare("UPDATE loans SET outstanding = :out, status = :st, next_due = NULL WHERE id = :id")
                       ->execute(['out' => $newOutstanding, 'st' => $newStatus, 'id' => $id]);
                } else {
                    $db->prepare("UPDATE loans SET outstanding = :out, status = :st, next_due = (SELECT due_date FROM loan_schedule WHERE loan_id = :lid AND status IN ('DUE','MISSED','PARTIALLY_PAID','UPCOMING') ORDER BY due_date ASC LIMIT 1) WHERE id = :id")
                       ->execute(['out' => $newOutstanding, 'st' => $newStatus, 'lid' => $id, 'id' => $id]);
                }

                // D. Insert Transaction Record
                $stage = 'insert_transaction';
                $txnRef = generateRef('TXN');
                $postingBranch = trim((string)($loan['branch'] ?? ($acct['branch'] ?? ($staff['branch'] ?? ''))));
                loanInsertTransaction($db, [
                    'ref' => $txnRef,
                    'type' => 'LOAN_REPAYMENT',
                    'direction' => 'debit',
                    'account' => $acct['account_number'],
                    'account_type' => $acct['product_type'] ?? ($acct['account_type'] ?? ''),
                    'customer_name' => $loan['customer_name'] ?? ($acct['customer_name'] ?? ''),
                    'branch' => $postingBranch,
                    'amount' => $totalPayment,
                    'net_amount' => $totalPayment,
                    'status' => 'POSTED',
                    'category' => 'Loan Repayment',
                    'module' => 'Loans',
                    'description' => ($payType === 'payoff' ? 'Early loan payoff' : 'Loan repayment') . " - {$loan['loan_number']}",
                    'memo' => "Source: {$repaymentPayerLabel}. Principal: $totalPrincipalToPay, Interest: $totalInterestToPay. $memo",
                    'fee' => 0,
                    'fee_pct' => 0,
                    'total_tax' => 0,
                    'fee_mode' => '',
                    'operator_id' => (int)($staff['id'] ?? 0),
                    'operator_name' => $staff['full_name'] ?? ''
                ]);

                // E. ★ GL INTEGRATION
                // Principal: DR 1200 (Loans & Advances Fund) / CR 1201 (Loans Receivable)
                $stage = 'post_gl_principal';
                processTransaction('LOAN_PRINCIPAL_REPAYMENT', [
                    'amount' => $totalPrincipalToPay,
                    'ref' => $txnRef,
                    'description' => "Principal repayment - {$loan['loan_number']}",
                    'branch' => $postingBranch,
                    'staff_id' => (int)($staff['id'] ?? 0)
                ]);
                $stage = 'record_principal_fund';
                if ($totalPrincipalToPay > 0) {
                    $lfId = loanGetLoanFundAccountId($db, 'BANK-LF-0001');
                    if ($lfId) {
                        $lfBalAfter = loanGetGLAssetBalance($db, '1200', $postingBranch);
                        loanInsertLoanFundTxnIfMissing($db, [
                            'ref' => $txnRef,
                            'loan_fund_account_id' => $lfId,
                            'loan_id' => (int)$id,
                            'transaction_ref' => $txnRef,
                            'date' => $today,
                            'type' => 'CREDIT',
                            'description' => "Loan principal recovered - {$loan['loan_number']}",
                            'amount' => (float)$totalPrincipalToPay,
                            'balance_after' => $lfBalAfter,
                            'branch' => $postingBranch,
                            'operator' => $staff['full_name'] ?? ''
                        ]);
                    }
                }
                // Interest: DR 1201 (Loans Receivable) / CR 4200 (Loan Interest Income)
                $stage = 'post_gl_interest';
                processTransaction('LOAN_INTEREST_PAYMENT', [
                    'amount' => $totalInterestToPay,
                    'ref' => $txnRef,
                    'description' => "Interest payment - {$loan['loan_number']}",
                    'branch' => $postingBranch,
                    'staff_id' => (int)($staff['id'] ?? 0)
                ]);
                $stage = 'record_interest_income';
                if ($totalInterestToPay > 0) {
                    $liId = loanGetLoanFundAccountId($db, 'BANK-LI-0001');
                    if ($liId) {
                        $balAfter = loanGetGLIncomeBalance($db, '4200', $postingBranch);
                        loanInsertLoanFundTxnIfMissing($db, [
                            'ref' => $txnRef,
                            'loan_fund_account_id' => $liId,
                            'loan_id' => (int)$id,
                            'transaction_ref' => $txnRef,
                            'date' => $today,
                            'type' => 'CREDIT',
                            'description' => "Loan interest received - {$loan['loan_number']}",
                            'amount' => (float)$totalInterestToPay,
                            'balance_after' => $balAfter,
                            'branch' => $postingBranch,
                            'operator' => $staff['full_name'] ?? ''
                        ]);
                    }
                }

                $stage = 'commit';
                $db->commit();
                
                $stage = 'audit';
                logAudit($staff['full_name'], 'LOAN_REPAYMENT', 'LOAN', (string)$id, 'SUCCESS', "Repayment of $totalPayment recorded for {$loan['loan_number']}", $staff['department'], getClientIp());
                
                successResponse([
                    'loan_id' => $id,
                    'amount_paid' => $totalPayment,
                    'principal_paid' => $totalPrincipalToPay,
                    'interest_paid' => $totalInterestToPay,
                    'new_outstanding' => $newOutstanding,
                    'new_status' => $newStatus,
                    'txn_ref' => $txnRef
                ]);

            } catch (Throwable $e) {
                $inTxn = isset($db) && ($db instanceof PDO) && $db->inTransaction();
                if ($inTxn) {
                    try { $db->rollBack(); } catch (Exception $_) {}
                }
                $errId = 'LR-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
                $msg = $e->getMessage();
                $loc = basename($e->getFile()) . ':' . $e->getLine();
                error_log('[Loan Repay][' . $errId . '][' . ($stage ?? 'unknown') . '] ' . $msg . ' @ ' . $loc);
                $extra = [
                    'ref' => $errId,
                    'stage' => ($stage ?? 'unknown')
                ];
                if (defined('APP_DEBUG') && APP_DEBUG) {
                    $extra['debug'] = [
                        'message' => $e->getMessage(),
                        'file' => basename($e->getFile()),
                        'line' => $e->getLine()
                    ];
                }
                $stageLabel = (string)($stage ?? 'unknown');
                errorResponse('Failed to record repayment (stage: ' . $stageLabel . '). Ref: ' . $errId, 500, $extra);
            }
            break;
        }

        // ─── POST: Create new loan ───
        $input = getRequestInput();
        $errors = validateRequired($input, ['customer_id', 'principal', 'interest_rate', 'term_months']);
        if (!empty($errors)) { validationError($errors); }

        // ★ SECURITY: Validate financial constraints server-side
        $principalParsed = parseDecimalInput($input['principal'] ?? null, 'Loan principal', 2, 0.01, 999999999);
        if (!$principalParsed['ok']) { validationError(['principal' => $principalParsed['error']]); }
        $principal = $principalParsed['value'];
        if ($principal <= 0) { validationError(['principal' => 'Loan principal must be greater than zero.']); }
        // ★ FIX: Add upper bound to prevent absurd values (e.g., 1e18) that could cause
        // DECIMAL overflow, schedule calculation errors, or display issues.
        if ($principal > 999999999) { validationError(['principal' => 'Loan principal exceeds maximum allowed amount (999,999,999 FCFA).']); }
        $interestRateParsed = parseDecimalInput($input['interest_rate'] ?? null, 'Interest rate', 4, 0, 36);
        if (!$interestRateParsed['ok']) { validationError(['interest_rate' => $interestRateParsed['error']]); }
        $interestRate = $interestRateParsed['value'];
        if ($interestRate < 0 || $interestRate > 36) { validationError(['interest_rate' => 'Interest rate must be between 0% and 36%.']); }
        $termMonthsParsed = parseIntegerInput($input['term_months'] ?? null, 'Loan term (months)', 1, 360);
        if (!$termMonthsParsed['ok']) { validationError(['term_months' => $termMonthsParsed['error']]); }
        $termMonths = $termMonthsParsed['value'];
        if ($termMonths <= 0) { validationError(['term_months' => 'Loan term must be at least 1 month.']); }
        // ★ FIX: Add upper bound for term to prevent schedule explosion (360 installments max = 30 years)
        if ($termMonths > 360) { validationError(['term_months' => 'Loan term cannot exceed 360 months (30 years).']); }
        $repaymentAmountParsed = parseDecimalInput($input['repayment_amount'] ?? 0, 'Repayment amount', 2, 0, 1000000000000, false);
        if (!$repaymentAmountParsed['ok']) { validationError(['repayment_amount' => $repaymentAmountParsed['error']]); }
        $repaymentPctParsed = parseDecimalInput($input['repayment_pct'] ?? 0, 'Repayment percentage', 4, 0, 100, false);
        if (!$repaymentPctParsed['ok']) { validationError(['repayment_pct' => $repaymentPctParsed['error']]); }
        $insuranceParsed = parseDecimalInput($input['insurance_fee'] ?? 0, 'Insurance fee', 2, 0, 1000000000, false);
        if (!$insuranceParsed['ok']) { validationError(['insurance_fee' => $insuranceParsed['error']]); }
        $guarantorCustomerId = null;
        $guarantorAccountId = null;
        $guarantorAccountNumber = sanitize($input['guarantor_account_number'] ?? '');
        if (($input['guarantor_customer_id'] ?? '') !== '') {
            $parsed = parseIntegerInput($input['guarantor_customer_id'], 'Guarantor customer ID', 1, 2147483647);
            if (!$parsed['ok']) { validationError(['guarantor_customer_id' => $parsed['error']]); }
            $guarantorCustomerId = $parsed['value'];
        }
        if (($input['guarantor_account_id'] ?? '') !== '') {
            $parsed = parseIntegerInput($input['guarantor_account_id'], 'Guarantor account ID', 1, 2147483647);
            if (!$parsed['ok']) { validationError(['guarantor_account_id' => $parsed['error']]); }
            $guarantorAccountId = $parsed['value'];
        }
        if (($guarantorCustomerId && !$guarantorAccountId) || (!$guarantorCustomerId && $guarantorAccountId)) {
            validationError(['guarantor_account_id' => 'Select both guarantor customer and guarantor account, or leave both empty.']);
        }

        // ★ ELIGIBILITY CHECK: Server-side enforcement of loan eligibility rules
        // Rule 1: Customer with ACTIVE loan → BLOCKED
        // Rule 2: Customer with DELINQUENT loan → RESTRICTED (requires double approval)
        // Rule 3: Customer with WRITTEN_OFF loan → BLOCKED (permanent ban)
        $customerId = (int)$input['customer_id'];
        try {
            $eligDb = getDB();
            // Check for ACTIVE loan
            $activeCheck = $eligDb->prepare("SELECT id, loan_number, outstanding FROM loans WHERE customer_id = :cid AND status = 'ACTIVE' LIMIT 1");
            $activeCheck->execute([':cid' => $customerId]);
            $activeLoan = $activeCheck->fetch(PDO::FETCH_ASSOC);
            if ($activeLoan) {
                $activeOutstanding = (float)$activeLoan['outstanding'];
                $activeLoanNumber = sanitize($activeLoan['loan_number']);
                errorResponse("Loan application blocked. Customer has an active loan ($activeLoanNumber) with outstanding balance of $activeOutstanding. No new loans can be granted until the existing loan is fully settled.", 403);
                break;
            }
            // Check for WRITTEN_OFF loan (permanent ban)
            $woCheck = $eligDb->prepare("SELECT id, loan_number FROM loans WHERE customer_id = :cid AND status = 'WRITTEN_OFF' LIMIT 1");
            $woCheck->execute([':cid' => $customerId]);
            $woLoan = $woCheck->fetch(PDO::FETCH_ASSOC);
            if ($woLoan) {
                $woLoanNumber = sanitize($woLoan['loan_number']);
                errorResponse("Loan application blocked. Customer has a written-off loan ($woLoanNumber). Per banking policy, customers with written-off loans are permanently ineligible for new credit facilities.", 403);
                break;
            }
            // Check for DELINQUENT loan (restricted — requires double approval flag)
            $delCheck = $eligDb->prepare("SELECT id, loan_number, outstanding FROM loans WHERE customer_id = :cid AND status = 'DELINQUENT' LIMIT 1");
            $delCheck->execute([':cid' => $customerId]);
            $delLoan = $delCheck->fetch(PDO::FETCH_ASSOC);
            $isRestricted = false;
            $restrictedReason = '';
            if ($delLoan) {
                $isRestricted = true;
                $restrictedReason = ' Customer has a delinquent loan (' . sanitize($delLoan['loan_number']) . ') with outstanding ' . (float)$delLoan['outstanding'] . '. Double approval (ADMIN + MANAGER) required.';
            }
        } catch (PDOException $e) {
            error_log('[Loans POST] Eligibility check failed (non-fatal): ' . $e->getMessage());
            // Non-fatal — continue with loan creation if DB check fails
            $isRestricted = false;
            $restrictedReason = '';
        }

        try {
            $db = getDB();
            loanEnsureSchema($db);

            // --- Robust loan number generation ---
            $year = date('Y');
            $prefix = 'LN-' . $year . '-';
            try {
                // REGEXP filter excludes corrupted rows like 'LN-2026-9.2233720368548E+18-d1'
                // whose CAST would return 9223372036854775808, poisoning all subsequent numbers.
                $maxNum = $db->query(
                    "SELECT MAX(CAST(SUBSTRING(loan_number, " . strlen($prefix) . ") AS INTEGER)) AS max_num FROM loans WHERE loan_number LIKE '" . $prefix . "%' AND loan_number ~ '^LN-" . $year . "-[0-9]{3}$'"
                )->fetch();
            } catch (PDOException $e) {
                error_log('[Loans POST] Loan number query failed: ' . $e->getMessage());
                $maxNum = ['max_num' => null];
            }
            $nextSeq = ((int)($maxNum['max_num'] ?? 0)) + 1;
            $loanNum = $prefix . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);

            // Verify the generated number is unique (defensive check)
            try {
                $existsStmt = $db->prepare('SELECT id FROM loans WHERE loan_number = :ln LIMIT 1');
                $existsStmt->execute([':ln' => $loanNum]);
                if ($existsStmt->fetch()) {
                    $loanNum = $prefix . str_pad($nextSeq, 3, '0', STR_PAD_LEFT) . '-' . bin2hex(random_bytes(2));
                }
            } catch (PDOException $e) {
                error_log('[Loans POST] Uniqueness check failed: ' . $e->getMessage());
            }

            // --- Customer lookup (graceful failure) ---
            $customerName = '';
            $customerBranch = '';
            try {
                $custStmt = $db->prepare('SELECT full_name, branch FROM customers WHERE id = :id');
                $custStmt->execute([':id' => $input['customer_id']]);
                $cust = $custStmt->fetch();
                if ($cust) {
                    $customerName = $cust['full_name'];
                    $customerBranch = $cust['branch'] ?? '';
                } else {
                    error_log('[Loans POST] Customer ID ' . (int)$input['customer_id'] . ' not found in customers table.');
                }
            } catch (PDOException $custErr) {
                error_log('[Loans POST] Customer lookup failed: ' . $custErr->getMessage());
            }

            // Validate staff access to the effective branch for this loan.
            $requestedBranch = sanitize($input['branch'] ?? '');
            $effectiveBranch = $customerBranch !== '' ? $customerBranch : $requestedBranch;
            if (!loanCanAccessBranch($staff, $effectiveBranch)) {
                errorResponse('Access denied: Cannot create a loan for branch ' . $effectiveBranch . ' — outside your branch scope.', 403);
                break;
            }

            if ($guarantorCustomerId && $guarantorAccountId) {
                $gStmt = $db->prepare(
                    'SELECT id, customer_id, account_number, branch, status
                     FROM accounts
                     WHERE id = :id AND customer_id = :cid
                     LIMIT 1'
                );
                $gStmt->execute([
                    ':id' => $guarantorAccountId,
                    ':cid' => $guarantorCustomerId
                ]);
                $gAcct = $gStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$gAcct && $guarantorAccountNumber !== '') {
                    $gByNo = $db->prepare(
                        'SELECT id, customer_id, account_number, branch, status
                         FROM accounts
                         WHERE account_number = :acc_no AND customer_id = :cid
                         LIMIT 1'
                    );
                    $gByNo->execute([
                        ':acc_no' => $guarantorAccountNumber,
                        ':cid' => $guarantorCustomerId
                    ]);
                    $gAcct = $gByNo->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                if (!$gAcct) {
                    validationError(['guarantor_account_id' => 'Guarantor account not found for the selected guarantor customer.']);
                }
                if (!loanCanAccessBranch($staff, (string)($gAcct['branch'] ?? ''))) {
                    errorResponse('Access denied: Guarantor account belongs to a branch outside your assignment.', 403);
                    break;
                }
                $guarantorCustomerId = (int)($gAcct['customer_id'] ?? $guarantorCustomerId);
                $guarantorAccountId = (int)($gAcct['id'] ?? $guarantorAccountId);
                $guarantorAccountNumber = sanitize((string)($gAcct['account_number'] ?? $guarantorAccountNumber));
            }

            // --- Build and execute the INSERT ---
            // Map frontend field names to DB columns
            $repaymentFreq = $input['repayment_freq'] ?? 'Monthly';
            $repaymentMode = $input['repayment_mode'] ?? 'SCHEDULED';
            $productType   = $input['product_type'] ?? '';
            $source        = $input['source'] ?? '';
            $interestIncluded = isset($input['interest_included']) ? (bool)$input['interest_included'] : true;

            $stmt = $db->prepare(
                'INSERT INTO loans (loan_number, customer_id, customer_name, branch, status, principal,
                    outstanding, interest_rate, term_months, repayment_freq, product_type, source,
                    repayment_mode, repayment_amount, repayment_pct, interest_included, auto_deduct,
                    guarantor_customer_id, guarantor_account_id, guarantor_account_number, created_by)
                 VALUES (:num, :cid, :cname, :branch, :status, :principal,
                    :outstanding, :rate, :term, :freq, :ptype, :source,
                    :rmode, :ramt, :rpct, :intinc, :auto,
                    :guarantor_customer_id, :guarantor_account_id, :guarantor_account_number, :created_by)'
            );
            $stmt->execute([
                ':num'        => $loanNum,
                ':cid'        => (int)$input['customer_id'],
                ':cname'      => $customerName,
                ':branch'     => $effectiveBranch,
                ':status'     => 'PENDING',
                ':principal'  => $principal,
                ':outstanding' => $principal,
                ':rate'       => $interestRate,
                ':term'       => $termMonths,
                ':freq'       => sanitize($repaymentFreq),
                ':ptype'      => sanitize($productType),
                ':source'     => sanitize($source),
                ':rmode'      => sanitize($repaymentMode),
                ':ramt'       => $repaymentAmountParsed['value'],
                ':rpct'       => $repaymentPctParsed['value'],
                ':intinc'     => $interestIncluded ? 1 : 0,
                ':auto'       => isset($input['auto_deduct']) ? (int)$input['auto_deduct'] : 1,
                ':guarantor_customer_id' => $guarantorCustomerId,
                ':guarantor_account_id' => $guarantorAccountId,
                ':guarantor_account_number' => $guarantorAccountNumber,
                ':created_by' => (int)($staff['id'] ?? 0)
            ]);
            $newId = (int)$db->lastInsertId('loans_id_seq');

            logAudit($staff['full_name'], 'LOAN_CREATE', 'LOAN', (string)$newId, 'SUCCESS', 'Created loan ' . $loanNum, $staff['department'], getClientIp());
            createdResponse(['id' => $newId, 'loan_number' => $loanNum], 'Loan created successfully.');
        } catch (PDOException $e) {
            $errMsg = $e->getMessage();
            $errCode = $e->getCode();
            error_log('[Loans POST] PDO Error: ' . $errMsg . ' | Code: ' . $errCode);
            serverErrorResponse('Failed to create loan.');
        }
        break;
    case 'PUT':
        if ($id === null) { validationError(['id' => 'Loan ID is required.']); }
        $input = getRequestInput();
        try {
            $db = getDB();
            loanEnsureSchema($db);
            $existingStmt = $db->prepare('SELECT * FROM loans WHERE id = :id LIMIT 1');
            $existingStmt->execute([':id' => $id]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) { notFoundResponse('Loan not found.'); }
            if (!loanCanAccessBranch($staff, (string)($existing['branch'] ?? ''))) {
                errorResponse('Access denied: This loan belongs to a branch outside your assignment.', 403);
            }

            // ★ BACKEND HARDENING: Block status changes for terminal-status loans (best practice)
            if (isset($input['status'])) {
                if ($existing) {
                    $currentStatus = $existing['status'];
                    $newStatus = strtoupper($input['status']);
                    $terminalStatuses = ['SETTLED', 'CLOSED', 'WRITTEN_OFF', 'DEFAULTED'];
                    if (in_array($currentStatus, $terminalStatuses)) {
                        errorResponse("Cannot change status of a {$currentStatus} loan. This loan has reached its terminal state.");
                    }
                    // Prevent setting to DELINQUENT if not ACTIVE
                    if ($newStatus === 'DELINQUENT' && $currentStatus !== 'ACTIVE') {
                        errorResponse("Only ACTIVE loans can be marked as DELINQUENT. Current status: {$currentStatus}.");
                    }
                    // Prevent setting to SETTLED if outstanding > 0
                    // ★ FIX: Use payload outstanding if provided (early payoff sends outstanding=0 + status=SETTLED
                    // in a single PUT; the DB still has the old outstanding since it hasn't been updated yet).
                    $effectiveOutstanding = isset($input['outstanding']) ? (float)$input['outstanding'] : (float)$existing['outstanding'];
                    if ($newStatus === 'SETTLED' && $effectiveOutstanding > 0) {
                        errorResponse("Cannot SETTLE a loan with outstanding balance of " . $effectiveOutstanding . ".");
                    }
                    // Valid status transitions whitelist
                    $validTransitions = [
                        'PENDING'       => ['UNDER_REVIEW','APPROVED','REJECTED','PENDING'],
                        'UNDER_REVIEW'  => ['APPROVED','REJECTED','CLOSED','PENDING'],
                        'REJECTED'      => ['CLOSED'],
                        'APPROVED'      => ['ACTIVE','APPROVED','PENDING'],
                        'ACTIVE'        => ['DELINQUENT','SETTLED','CLOSED','RESTRUCTURED','ACTIVE'],
                        'DELINQUENT'    => ['SETTLED','WRITTEN_OFF','RESTRUCTURED','DEFAULTED','DELINQUENT'],
                        'RESTRUCTURED'  => ['ACTIVE','DELINQUENT','SETTLED','WRITTEN_OFF','RESTRUCTURED'],
                    ];
                    if (isset($validTransitions[$currentStatus]) && !in_array($newStatus, $validTransitions[$currentStatus])) {
                        errorResponse("Invalid status transition from {$currentStatus} to {$newStatus}.");
                    }
                    if ($newStatus === 'ACTIVE' && $currentStatus !== 'ACTIVE') {
                        requireRole(['ADMIN', 'MANAGER', 'ACCOUNTANT'], $staff);
                    }
                    if ($newStatus === 'APPROVED' && in_array($currentStatus, ['PENDING', 'UNDER_REVIEW'], true)) {
                        try {
                            $approvalStmt = $db->prepare(
                                "SELECT id FROM approvals
                                 WHERE entity_type = 'Loan Application'
                                   AND entity_id = :loan_id
                                   AND status = 'PENDING'
                                 LIMIT 1"
                            );
                            $approvalStmt->execute([':loan_id' => (int)$id]);
                            if ($approvalStmt->fetch(PDO::FETCH_ASSOC)) {
                                conflictResponse('This loan still has a pending approval workflow. Decide the approval record before marking the loan APPROVED directly.');
                            }
                        } catch (PDOException $approvalErr) {
                            error_log('[Loans PUT] Pending approval check skipped: ' . $approvalErr->getMessage());
                        }
                    }
                }
            }

            $fields = []; $params = [':id' => $id];
            if (isset($input['outstanding'])) {
                $parsed = parseDecimalInput($input['outstanding'], 'Outstanding amount', 2, 0, 1000000000000);
                if (!$parsed['ok']) { validationError(['outstanding' => $parsed['error']]); }
                $input['outstanding'] = $parsed['value'];
            }
            if (isset($input['accrued_interest'])) {
                $parsed = parseDecimalInput($input['accrued_interest'], 'Accrued interest', 2, 0, 1000000000000);
                if (!$parsed['ok']) { validationError(['accrued_interest' => $parsed['error']]); }
                $input['accrued_interest'] = $parsed['value'];
            }
            if (isset($input['repayment_amount'])) {
                $parsed = parseDecimalInput($input['repayment_amount'], 'Repayment amount', 2, 0, 1000000000000);
                if (!$parsed['ok']) { validationError(['repayment_amount' => $parsed['error']]); }
                $input['repayment_amount'] = $parsed['value'];
            }
            if (isset($input['repayment_pct'])) {
                $parsed = parseDecimalInput($input['repayment_pct'], 'Repayment percentage', 4, 0, 100);
                if (!$parsed['ok']) { validationError(['repayment_pct' => $parsed['error']]); }
                $input['repayment_pct'] = $parsed['value'];
            }
            if (isset($input['insurance_fee'])) {
                $parsed = parseDecimalInput($input['insurance_fee'], 'Insurance fee', 2, 0, 1000000000);
                if (!$parsed['ok']) { validationError(['insurance_fee' => $parsed['error']]); }
                $input['insurance_fee'] = $parsed['value'];
            }
            if (isset($input['capitalized_interest'])) {
                $parsed = parseDecimalInput($input['capitalized_interest'], 'Capitalized interest', 2, 0, 1000000000000);
                if (!$parsed['ok']) { validationError(['capitalized_interest' => $parsed['error']]); }
                $input['capitalized_interest'] = $parsed['value'];
            }
            if (isset($input['debit_account_id']) && $input['debit_account_id'] !== null && $input['debit_account_id'] !== '') {
                $parsed = parseIntegerInput($input['debit_account_id'], 'Debit account ID', 1, 2147483647);
                if (!$parsed['ok']) { validationError(['debit_account_id' => $parsed['error']]); }
                $input['debit_account_id'] = $parsed['value'];
            }
            if (isset($input['guarantor_customer_id']) && $input['guarantor_customer_id'] !== null && $input['guarantor_customer_id'] !== '') {
                $parsed = parseIntegerInput($input['guarantor_customer_id'], 'Guarantor customer ID', 1, 2147483647);
                if (!$parsed['ok']) { validationError(['guarantor_customer_id' => $parsed['error']]); }
                $input['guarantor_customer_id'] = $parsed['value'];
            }
            if (isset($input['guarantor_account_id']) && $input['guarantor_account_id'] !== null && $input['guarantor_account_id'] !== '') {
                $parsed = parseIntegerInput($input['guarantor_account_id'], 'Guarantor account ID', 1, 2147483647);
                if (!$parsed['ok']) { validationError(['guarantor_account_id' => $parsed['error']]); }
                $input['guarantor_account_id'] = $parsed['value'];
            }
            if (
                isset($input['guarantor_customer_id']) ||
                isset($input['guarantor_account_id']) ||
                isset($input['guarantor_account_number'])
            ) {
                $effectiveGuarantorCustomerId = isset($input['guarantor_customer_id'])
                    ? (int)$input['guarantor_customer_id']
                    : (int)($existing['guarantor_customer_id'] ?? 0);
                $effectiveGuarantorAccountId = isset($input['guarantor_account_id'])
                    ? (int)$input['guarantor_account_id']
                    : (int)($existing['guarantor_account_id'] ?? 0);
                $existingGuarantorCustomerId = (int)($existing['guarantor_customer_id'] ?? 0);
                $existingGuarantorAccountId = (int)($existing['guarantor_account_id'] ?? 0);
                if (
                    ($existingGuarantorCustomerId > 0 || $existingGuarantorAccountId > 0) &&
                    $effectiveGuarantorCustomerId <= 0 &&
                    $effectiveGuarantorAccountId <= 0 &&
                    (float)($existing['outstanding'] ?? 0) > 0.01
                ) {
                    validationError(['guarantor_account_id' => 'Cannot remove guarantor liability while loan outstanding is greater than zero. Settle the loan first.']);
                }
                if (($effectiveGuarantorCustomerId > 0 && $effectiveGuarantorAccountId <= 0) || ($effectiveGuarantorCustomerId <= 0 && $effectiveGuarantorAccountId > 0)) {
                    validationError(['guarantor_account_id' => 'Select both guarantor customer and guarantor account, or clear both.']);
                }
                if ($effectiveGuarantorCustomerId > 0 && $effectiveGuarantorAccountId > 0) {
                    $gStmt = $db->prepare(
                        'SELECT id, customer_id, account_number, branch
                         FROM accounts
                         WHERE id = :id AND customer_id = :cid
                         LIMIT 1'
                    );
                    $gStmt->execute([
                        ':id' => $effectiveGuarantorAccountId,
                        ':cid' => $effectiveGuarantorCustomerId
                    ]);
                    $gAcct = $gStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$gAcct) {
                        validationError(['guarantor_account_id' => 'Guarantor account not found for the selected guarantor customer.']);
                    }
                    if (!loanCanAccessBranch($staff, (string)($gAcct['branch'] ?? ''))) {
                        errorResponse('Access denied: Guarantor account belongs to a branch outside your assignment.', 403);
                    }
                    $input['guarantor_account_number'] = sanitize((string)($gAcct['account_number'] ?? ''));
                }
            }
            foreach (['status', 'outstanding', 'accrued_interest', 'next_due', 'repayment_mode', 'repayment_amount', 'repayment_pct', 'interest_included', 'disbursed_at', 'maturity_date', 'loan_module', 'insurance_fee', 'capitalized_interest', 'debit_account_id', 'debit_account_number', 'guarantor_customer_id', 'guarantor_account_id', 'guarantor_account_number'] as $f) {
                if (isset($input[$f])) { $fields[] = "\"$f\" = :$f"; $params[":$f"] = $input[$f]; }
            }
            // auto_deduct is boolean (0/1)
            if (isset($input['auto_deduct'])) {
                $fields[] = "\"auto_deduct\" = :auto_deduct";
                $params[":auto_deduct"] = (int)$input['auto_deduct'];
            }
            if (empty($fields) && empty($input['schedule'])) { errorResponse('No fields to update.'); }

            $db->beginTransaction();

            // Update loan fields if any
            if (!empty($fields)) {
                $stmt = $db->prepare("UPDATE loans SET ' . implode(', ', $fields) . ' WHERE id = :id");
                $stmt->execute($params);
            }

            // ── Schedule persistence: replace all schedule entries for this loan ──
            if (isset($input['schedule']) && is_array($input['schedule'])) {
                $db->exec("DELETE FROM loan_schedule WHERE loan_id = " . (int)$id);
                $insStmt = $db->prepare(
                    'INSERT INTO loan_schedule (loan_id, installment, due_date, principal, interest, paid, status)
                     VALUES (:lid, :inst, :due, :principal, :interest, :paid, :status)'
                );
                foreach ($input['schedule'] as $s) {
                    $insStmt->execute([
                        ':lid'       => (int)$id,
                        ':inst'      => (int)($s['installment'] ?? 1),
                        ':due'       => sanitize($s['due_date'] ?? $s['due'] ?? date('Y-m-d')),
                        ':principal' => (float)($s['principal'] ?? 0),
                        ':interest'  => (float)($s['interest'] ?? 0),
                        ':paid'      => (float)($s['paid'] ?? 0),
                        ':status'    => sanitize($s['status'] ?? 'UPCOMING')
                    ]);
                }
            }

            $db->commit();
            logAudit($staff['full_name'], 'LOAN_UPDATE', 'LOAN', $id, 'SUCCESS',
                'Updated loan ID: ' . $id . (isset($input['schedule']) ? ' (schedule synced, ' . count($input['schedule']) . ' entries)' : ''),
                $staff['department'], getClientIp());
            successMessage('Loan updated successfully.');
        } catch (PDOException $e) {
            error_log('[Loans PUT] ' . $e->getMessage());
            serverErrorResponse('Failed to update loan.');
        }
        break;
    default:
        errorResponse('Method not allowed.', 405);
}
