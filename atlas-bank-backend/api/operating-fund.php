<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Operating Fund — Balance and Transactions
 *
 * GET    /api/operating-fund              — List accounts with balances
 * GET    /api/operating-fund/{id}        — Get single account details
 * GET    /api/operating-fund/{id}/transactions — Get transaction history (paginated, filterable)
 * POST   /api/operating-fund/credit      — Credit (inject) funds into operating account
 * POST   /api/operating-fund/debit       — Debit (withdraw) funds from operating account
 * POST   /api/operating-fund/transfer    — Internal transfer between GL accounts
 *
 * ── Double-Entry Bookkeeping Rules ──
 * CREDIT  : DR 1400 (Operating Fund — Bank, asset up)  + CR 3100 (Retained Earnings, equity up)
 * DEBIT   : CR 1400 (Operating Fund — Bank, asset down) + DR 5900 (Misc Expense, expense up)
 * TRANSFER: DR target GL (asset up)                       + CR 1400  (Operating Fund — Bank, asset down)
 */
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

$staff = requireModule('ACCOUNTS');
$method = $_ROUTE['method'];
$id = $_ROUTE['id'];
$subResource = $_ROUTE['subResource'] ?? '';

// ═══════════════════════════════════════════════════════════════════
// GL Account Constants
// ═══════════════════════════════════════════════════════════════════
define('GL_OP_FUND_BANK',       '1400');   // Operating Fund — Bank (Asset)
define('GL_RETAINED_EARNINGS',  '3100');   // Retained Earnings (Equity)
define('GL_MISC_EXPENSE',       '5900');   // Miscellaneous Expense (Expense)

// ═══════════════════════════════════════════════════════════════════
// Helper: ensure operating_account_transactions schema columns
// NOTE: DDL operations must run BEFORE beginTransaction() because
// MySQL implicitly commits the active transaction on any DDL statement.
// ═══════════════════════════════════════════════════════════════════
function ensureOpFundColumns(PDO $db): void {
    $cols = ['description', 'source_type', 'source_ref', 'category', 'gl_code', 'branch'];
    foreach ($cols as $col) {
        $check = $db->query("SHOW COLUMNS FROM operating_account_transactions LIKE '$col'")->fetch();
        if (!$check) {
            $type = ($col === 'description') ? 'TEXT' : "VARCHAR(100) DEFAULT NULL";
            $db->exec("ALTER TABLE operating_account_transactions ADD COLUMN `$col` $type");
        }
    }
    // Safe migration: add branch index for filtered queries
    $brIdx = $db->query("SHOW INDEX FROM operating_account_transactions WHERE Key_name = 'idx_oat_branch'")->fetch();
    if (!$brIdx) {
        $db->exec("ALTER TABLE operating_account_transactions ADD INDEX idx_oat_branch (branch)");
    }
}

// ═══════════════════════════════════════════════════════════════════
// Helper: ensure general_ledger table exists (for double-entry bookkeeping)
// NOTE: Must run BEFORE beginTransaction() — DDL causes implicit commit in MySQL.
// ═══════════════════════════════════════════════════════════════════
function ensureGeneralLedgerTable(PDO $db): void {
    $check = $db->query("SHOW TABLES LIKE 'general_ledger'")->fetch();
    if (!$check) {
        $db->exec("CREATE TABLE general_ledger (
            id INT AUTO_INCREMENT PRIMARY KEY,
            date DATE NOT NULL,
            reference VARCHAR(50),
            account_code VARCHAR(20),
            account_name VARCHAR(200),
            debit DECIMAL(20,2) DEFAULT 0,
            credit DECIMAL(20,2) DEFAULT 0,
            description TEXT,
            posted_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_date (date),
            INDEX idx_ref (reference),
            INDEX idx_account (account_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

// ═══════════════════════════════════════════════════════════════════
// Helper: safely add transaction_type and contra_account columns
// to general_ledger table (idempotent DDL migration).
// NOTE: Must run BEFORE beginTransaction() — DDL causes implicit commit.
// ═══════════════════════════════════════════════════════════════════
function ensureGeneralLedgerColumns(PDO $db): void {
    // transaction_type — categorises the source operation
    $colTransactionType = $db->query(
        "SHOW COLUMNS FROM general_ledger LIKE 'transaction_type'"
    )->fetch();
    if (!$colTransactionType) {
        $db->exec(
            "ALTER TABLE general_ledger ADD COLUMN `transaction_type` VARCHAR(50) DEFAULT NULL "
          . "COMMENT 'Source operation type, e.g. OP_FUND_CREDIT, OP_FUND_DEBIT, OP_FUND_TRANSFER'"
        );
    }

    // contra_account — the other leg of the double-entry pair
    $colContraAccount = $db->query(
        "SHOW COLUMNS FROM general_ledger LIKE 'contra_account'"
    )->fetch();
    if (!$colContraAccount) {
        $db->exec(
            "ALTER TABLE general_ledger ADD COLUMN `contra_account` VARCHAR(20) DEFAULT NULL "
          . "COMMENT 'The paired account_code for this double-entry line'"
        );
    }
}

// ═══════════════════════════════════════════════════════════════════
// Helper: ensure chart_of_accounts table exists and seed required GL
// codes (1400, 3100, 5900) if absent.
// NOTE: Must run BEFORE beginTransaction() — DDL causes implicit commit.
// ═══════════════════════════════════════════════════════════════════
function ensureChartOfAccounts(PDO $db): void {
    // Create the table if it does not exist
    $tableCheck = $db->query("SHOW TABLES LIKE 'chart_of_accounts'")->fetch();
    if (!$tableCheck) {
        $db->exec("CREATE TABLE chart_of_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_code VARCHAR(20) NOT NULL,
            account_name VARCHAR(200) NOT NULL,
            account_type ENUM('ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE') NOT NULL,
            description TEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_code (account_code),
            INDEX idx_type (account_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // Seed the three required GL accounts (idempotent INSERT IGNORE)
    $seedAccounts = [
        [
            'code'  => GL_OP_FUND_BANK,
            'name'  => 'Operating Fund — Bank',
            'type'  => 'ASSET',
            'desc'  => 'Primary operating bank account asset',
        ],
        [
            'code'  => GL_RETAINED_EARNINGS,
            'name'  => 'Retained Earnings',
            'type'  => 'EQUITY',
            'desc'  => 'Accumulated retained earnings / capital reserve',
        ],
        [
            'code'  => GL_MISC_EXPENSE,
            'name'  => 'Miscellaneous Expense',
            'type'  => 'EXPENSE',
            'desc'  => 'Miscellaneous operating expenses',
        ],
    ];

    $seedStmt = $db->prepare(
        "INSERT IGNORE INTO chart_of_accounts (account_code, account_name, account_type, description, is_active)
         VALUES (:code, :name, :type, :desc, 1)"
    );
    foreach ($seedAccounts as $acct) {
        $seedStmt->execute([
            ':code' => $acct['code'],
            ':name' => $acct['name'],
            ':type' => $acct['type'],
            ':desc' => $acct['desc'],
        ]);
    }
}

// ═══════════════════════════════════════════════════════════════════
// Helper: get or create default operating account
// ═══════════════════════════════════════════════════════════════════
function getDefaultOpAccount(PDO $db): array {
    $stmt = $db->query("SELECT * FROM operating_account WHERE account_number = 'BANK-OP-0001' LIMIT 1");
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        $db->exec("INSERT INTO operating_account (account_number, account_name, balance, currency) VALUES ('BANK-OP-0001', 'Atlas Bank Operating Fund', 0, 'XAF')");
        $stmt = $db->query("SELECT * FROM operating_account WHERE account_number = 'BANK-OP-0001' LIMIT 1");
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    return $account;
}

// ═══════════════════════════════════════════════════════════════════
// Helper: generate unique transaction reference
// ═══════════════════════════════════════════════════════════════════
function generateOpRef(PDO $db, string $type): string {
    $prefix = strtoupper($type);
    $date = date('Ymd');

    // ★ FIX (OP-FUND-010): Removed COUNT(*) + CURDATE() approach.
    // Timezone mismatches between MySQL (CURDATE()) and PHP (date()) caused
    // sequence resets, leading to duplicate key errors.
    // Now uses MAX-based approach matching helpers.php.
    $like = $prefix . '-' . $date . '-%';
    $stmt = $db->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(ref, '-', -1) AS UNSIGNED)), 0) AS max_seq
         FROM operating_account_transactions
         WHERE ref LIKE :pattern"
    );
    $stmt->execute([':pattern' => $like]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $sequence = ((int)($row['max_seq'])) + 1;

    // Use a simpler sequence format without microtime to keep it consistent with other refs
    return sprintf('%s-%s-%04d', $prefix, $date, $sequence);
}

/**
 * Helper: compute real-time GL balance for account 1400
 *
 * ★ FIX (OP-FUND-011): Added branch filtering to match operating-account.php
 * and ensure non-admin users only see their branch's share of the fund.
 *
 * @param PDO $db Database connection
 * @param string|array|null $branch Branch scope
 * @return float — SUM(debit) - SUM(credit) for GL code 1400
 */
function getGL1400Balance(PDO $db, string|array|null $branch = null): float {
    $where = "WHERE account_code = :code";
    $params = [':code' => GL_OP_FUND_BANK];

    if ($branch !== null) {
        if (is_array($branch)) {
            if (!empty($branch) && !in_array('ALL', $branch)) {
                $placeholders = [];
                foreach ($branch as $i => $br) {
                    $key = ':br' . $i;
                    $placeholders[] = $key;
                    $params[$key] = $br;
                }
                $where .= " AND branch IN (" . implode(',', $placeholders) . ")";
            }
        } elseif ($branch !== '' && $branch !== 'ALL') {
            $where .= " AND branch = :br";
            $params[':br'] = $branch;
        }
    }

    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS gl_balance
           FROM general_ledger
          $where"
    );
    $stmt->execute($params);
    return (float)$stmt->fetch(PDO::FETCH_ASSOC)['gl_balance'];
}

// ═══════════════════════════════════════════════════════════════════
// Helper: prepare database (all DDL) before any transaction.
// This must be called ONCE before starting any transactional work.
// DDL statements (ALTER TABLE, CREATE TABLE) in MySQL cause implicit
// commits, so they MUST run outside of beginTransaction()/commit().
// ═══════════════════════════════════════════════════════════════════
function prepareDatabase(PDO $db): void {
    ensureOpFundColumns($db);
    ensureGeneralLedgerTable($db);
    ensureGeneralLedgerColumns($db);
    ensureChartOfAccounts($db);
}

// ═══════════════════════════════════════════════════════════════════
// Helper: build a reusable prepared statement for GL inserts that
// includes transaction_type and contra_account.
// ═══════════════════════════════════════════════════════════════════
function buildGLInsertSQL(): string {
    return "INSERT INTO general_ledger
        (date, reference, account_code, account_name, debit, credit,
         description, posted_by, created_at, transaction_type, contra_account, branch)
        VALUES (CURDATE(), :ref, :acode, :aname, :debit, :credit,
                :desc, :opid, NOW(), :txtype, :contra, :branch)";
}

switch ($method) {
    // ═══════════════════════════════════════════
    // GET — List accounts or get transactions
    // ═══════════════════════════════════════════
    case 'GET':
        try {
            $db = getDB();
            prepareDatabase($db);

            // GET /api/operating-fund — list all operating accounts
            if (!$id && !$subResource) {
                $stmt = $db->query("SELECT * FROM operating_account ORDER BY id");
                $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                successResponse($accounts);
                break;
            }

            // GET /api/operating-fund/{id}/transactions — paginated transaction history
            if ($subResource === 'transactions') {
                $accountId = (int)$id;
                $params = [];
                $where = "WHERE operating_account_id = :aid";
                $params[':aid'] = $accountId;

                if (!empty($_GET['date_from'])) {
                    $where .= " AND date >= :df";
                    $params[':df'] = sanitize($_GET['date_from']);
                }
                if (!empty($_GET['date_to'])) {
                    $where .= " AND date <= :dt";
                    $params[':dt'] = sanitize($_GET['date_to']);
                }
                if (!empty($_GET['type'])) {
                    $where .= " AND type = :tp";
                    $params[':tp'] = sanitize($_GET['type']);
                }
                if (!empty($_GET['category'])) {
                    $where .= " AND category = :cat";
                    $params[':cat'] = sanitize($_GET['category']);
                }
                if (!empty($_GET['search'])) {
                    $where .= " AND (ref LIKE :srch OR description LIKE :srch)";
                    $params[':srch'] = '%' . sanitize($_GET['search']) . '%';
                }

                $page = max(1, (int)($_GET['page'] ?? 1));
                $pageSize = max(1, min((int)($_GET['pageSize'] ?? 50), 500));
                $offset = ($page - 1) * $pageSize;

                $countStmt = $db->prepare("SELECT COUNT(*) AS total FROM operating_account_transactions $where");
                $countStmt->execute($params);
                $total = (int)$countStmt->fetch()['total'];

                $stmt = $db->prepare("SELECT * FROM operating_account_transactions $where ORDER BY date DESC, id DESC LIMIT :limit OFFSET :offset");
                foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
                $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                paginatedResponse($stmt->fetchAll(PDO::FETCH_ASSOC), $total, $page, $pageSize);
                break;
            }

            // GET /api/operating-fund/{id} — single account with summary
            $accountId = (int)$id;
            $stmt = $db->prepare("SELECT * FROM operating_account WHERE id = :id");
            $stmt->execute([':id' => $accountId]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$account) { errorResponse('Operating account not found.', 404); break; }

            // Attach summary stats
            $sumStmt = $db->prepare("SELECT
                COALESCE(SUM(CASE WHEN type='CREDIT' THEN amount ELSE 0 END), 0) AS total_credits,
                COALESCE(SUM(CASE WHEN type='DEBIT' THEN amount ELSE 0 END), 0) AS total_debits,
                COUNT(*) AS total_transactions,
                MAX(date) AS last_transaction_date
            FROM operating_account_transactions WHERE operating_account_id = :aid");
            $sumStmt->execute([':aid' => $accountId]);
            $summary = $sumStmt->fetch(PDO::FETCH_ASSOC);

            // Also attach the real-time GL balance for account 1400
            // ★ FIX (OP-FUND-011): Apply branch filtering for non-admin users
            $_isAdmin = strtoupper($staff['role'] ?? '') === 'ADMIN';
            $branchScope = $_isAdmin ? null : ($staff['branches'] ?? null);
            $glBalance = getGL1400Balance($db, $branchScope);
            $summary['gl_1400_balance'] = $glBalance;

            $account['summary'] = $summary;

            successResponse($account);
        } catch (PDOException $e) { serverErrorResponse('Database error.'); }
        break;

    // ═══════════════════════════════════════════
    // POST — Credit, Debit, Transfer
    // ═══════════════════════════════════════════
    case 'POST':
        // ── Read JSON body DIRECTLY from php://input ──
        $input = [];
        $rawBody = file_get_contents('php://input');

        if (!empty($rawBody)) {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $input = $decoded;
            }
        }

        // Fallback: if php://input was empty or not JSON, try other methods
        if (empty($input)) {
            $input = getRequestInput();
        }
        if (empty($input) && !empty($_POST)) {
            $input = $_POST;
        }

        // Route by sub-path
        $action = $_ROUTE['id'] ?? '';  // e.g. "credit", "debit", "transfer"

        // ─────────────────────────────────────────────
        // CREDIT — Inject funds (capital injection)
        // Double-entry: DR 1400 (Asset ↑) + CR 3100 (Equity ↑)
        // ─────────────────────────────────────────────
        if ($action === 'credit') {
            $errors = validateRequired($input, ['amount', 'description']);
            if (!empty($errors)) { validationError($errors); }
            $amount = floatval($input['amount']);
            if ($amount <= 0) { validationError(['amount' => 'Amount must be greater than zero.']); }
            try {
                $db = getDB();
                // DDL first — MySQL implicitly commits on ALTER/CREATE, so these
                // MUST run before beginTransaction() to avoid breaking the transaction.
                prepareDatabase($db);
                $account = getDefaultOpAccount($db);

                // ★ FIX (OP-FUND-011): Use branch scope for balance check
                $_isAdmin = strtoupper($staff['role'] ?? '') === 'ADMIN';
                $branchScope = $_isAdmin ? null : ($staff['branches'] ?? null);

                $currentGLBalance = getGL1400Balance($db, $branchScope);
                $newBalance = $currentGLBalance + $amount;
                $ref = generateOpRef($db, 'CR');
                $desc = sanitize($input['description']);

                $db->beginTransaction();

                // 1. Update the operating_account denormalised balance
                $opBalance = floatval($account['balance']) + $amount;
                $upd = $db->prepare("UPDATE operating_account SET balance = :bal WHERE id = :id");
                $upd->execute([':bal' => $opBalance, ':id' => $account['id']]);

                // 2. Insert into operating_account_transactions (audit trail)
                // ★ FIX: Added branch column to enable per-branch filtering in the UI.
                $branch = sanitize($input['branch'] ?? ($staff['branches'][0] ?? ''));
                $ins = $db->prepare("INSERT INTO operating_account_transactions
                    (ref, operating_account_id, date, type, description, amount, balance_after, operator, source_type, source_ref, category, gl_code, branch)
                    VALUES (:ref, :aid, CURDATE(), 'CREDIT', :desc, :amt, :bal, :op, :stype, :sref, :cat, :gl, :branch)");
                $ins->execute([
                    ':ref' => $ref, ':aid' => $account['id'],
                    ':desc' => $desc,
                    ':amt' => $amount, ':bal' => $opBalance,
                    ':op' => $staff['full_name'],
                    ':stype' => sanitize($input['source_type'] ?? 'MANUAL'),
                    ':sref' => sanitize($input['source_ref'] ?? ''),
                    ':cat' => sanitize($input['category'] ?? 'CAPITAL_INJECTION'),
                    ':gl' => sanitize($input['gl_code'] ?? GL_OP_FUND_BANK),
                    ':branch' => $branch
                ]);

                // 3. GL Entry #1 — DEBIT 1400 (Operating Fund — Bank, asset increases)
                $glSQL = buildGLInsertSQL();
                $glStmt = $db->prepare($glSQL);
                $glStmt->execute([
                    ':ref'    => $ref,
                    ':acode'  => GL_OP_FUND_BANK,
                    ':aname'  => 'Operating Fund — Bank',
                    ':debit'  => $amount,
                    ':credit' => 0,
                    ':desc'   => $desc . ' (Credit — DR 1400)',
                    ':opid'   => $staff['id'],
                    ':txtype' => 'OP_FUND_CREDIT',
                    ':contra' => GL_RETAINED_EARNINGS,
                    ':branch' => $branch
                ]);

                // 4. GL Entry #2 — CREDIT 3100 (Retained Earnings, equity increases)
                $glStmt2 = $db->prepare($glSQL);
                $glStmt2->execute([
                    ':ref'    => $ref,
                    ':acode'  => GL_RETAINED_EARNINGS,
                    ':aname'  => 'Retained Earnings',
                    ':debit'  => 0,
                    ':credit' => $amount,
                    ':desc'   => $desc . ' (Credit — CR 3100)',
                    ':opid'   => $staff['id'],
                    ':txtype' => 'OP_FUND_CREDIT',
                    ':contra' => GL_OP_FUND_BANK,
                    ':branch' => $branch
                ]);

                $db->commit();
                logAudit($staff['full_name'], 'OP_FUND_CREDIT', 'OPERATING_FUND', $ref, 'SUCCESS',
                    'Credited ' . moneyFormat($amount) . ' to operating fund: ' . $desc
                    . ' [GL: DR 1400 + CR 3100]',
                    $staff['department'], getClientIp());
                createdResponse([
                    'id' => (int)$db->lastInsertId(),
                    'ref' => $ref,
                    'balance' => $opBalance,
                    'gl_1400_balance' => $newBalance,
                    'account_id' => (int)$account['id']
                ], 'Funds credited successfully. GL entries posted: DR 1400 + CR 3100.');
            } catch (PDOException $e) {
                if ($db->inTransaction()) $db->rollBack();
                serverErrorResponse('Failed to credit operating fund.');
            }

        // ─────────────────────────────────────────────
        // DEBIT — Withdraw funds (operational expense)
        // Double-entry: CR 1400 (Asset ↓) + DR 5900 (Expense ↑)
        // ─────────────────────────────────────────────
        } elseif ($action === 'debit') {
            $errors = validateRequired($input, ['amount', 'description']);
            if (!empty($errors)) { validationError($errors); }
            $amount = floatval($input['amount']);
            if ($amount <= 0) { validationError(['amount' => 'Amount must be greater than zero.']); }
            try {
                $db = getDB();
                // DDL first — before transaction to avoid MySQL implicit commit
                prepareDatabase($db);

                // ── Balance check from GL 1400 (source of truth) BEFORE beginTransaction ──
                // This avoids leaking an open transaction on error.
                // ★ FIX (OP-FUND-011): Use branch scope for balance check
                $_isAdmin = strtoupper($staff['role'] ?? '') === 'ADMIN';
                $branchScope = $_isAdmin ? null : ($staff['branches'] ?? null);
                $currentGLBalance = getGL1400Balance($db, $branchScope);

                if ($currentGLBalance < $amount) {
                    errorResponse(
                        'Insufficient operating fund balance. Available (GL 1400): ' . moneyFormat($currentGLBalance),
                        400
                    );
                }

                $account = getDefaultOpAccount($db);

                $db->beginTransaction();

                $newGLBalance = $currentGLBalance - $amount;
                $newOpBalance = floatval($account['balance']) - $amount;
                $ref = generateOpRef($db, 'DR');
                $desc = sanitize($input['description']);

                // 1. Update the operating_account denormalised balance
                $upd = $db->prepare("UPDATE operating_account SET balance = :bal WHERE id = :id");
                $upd->execute([':bal' => $newOpBalance, ':id' => $account['id']]);

                // 2. Insert into operating_account_transactions (audit trail)
                // ★ FIX: Added branch column to enable per-branch filtering in the UI.
                $branch = sanitize($input['branch'] ?? ($staff['branches'][0] ?? ''));
                $ins = $db->prepare("INSERT INTO operating_account_transactions
                    (ref, operating_account_id, date, type, description, amount, balance_after, operator, source_type, source_ref, category, gl_code, branch)
                    VALUES (:ref, :aid, CURDATE(), 'DEBIT', :desc, :amt, :bal, :op, :stype, :sref, :cat, :gl, :branch)");
                $ins->execute([
                    ':ref' => $ref, ':aid' => $account['id'],
                    ':desc' => $desc,
                    ':amt' => $amount, ':bal' => $newOpBalance,
                    ':op' => $staff['full_name'],
                    ':stype' => sanitize($input['source_type'] ?? 'MANUAL'),
                    ':sref' => sanitize($input['source_ref'] ?? ''),
                    ':cat' => sanitize($input['category'] ?? 'OPERATIONAL'),
                    ':gl' => sanitize($input['gl_code'] ?? GL_OP_FUND_BANK),
                    ':branch' => $branch
                ]);

                // 3. GL Entry #1 — CREDIT 1400 (Operating Fund — Bank, asset decreases)
                $glSQL = buildGLInsertSQL();
                $glStmt = $db->prepare($glSQL);
                $glStmt->execute([
                    ':ref'    => $ref,
                    ':acode'  => GL_OP_FUND_BANK,
                    ':aname'  => 'Operating Fund — Bank',
                    ':debit'  => 0,
                    ':credit' => $amount,
                    ':desc'   => $desc . ' (Debit — CR 1400)',
                    ':opid'   => $staff['id'],
                    ':txtype' => 'OP_FUND_DEBIT',
                    ':contra' => GL_MISC_EXPENSE,
                    ':branch' => $branch
                ]);

                // 4. GL Entry #2 — DEBIT 5900 (Miscellaneous Expense, expense increases)
                $glStmt2 = $db->prepare($glSQL);
                $glStmt2->execute([
                    ':ref'    => $ref,
                    ':acode'  => GL_MISC_EXPENSE,
                    ':aname'  => 'Miscellaneous Expense',
                    ':debit'  => $amount,
                    ':credit' => 0,
                    ':desc'   => $desc . ' (Debit — DR 5900)',
                    ':opid'   => $staff['id'],
                    ':txtype' => 'OP_FUND_DEBIT',
                    ':contra' => GL_OP_FUND_BANK,
                    ':branch' => $branch
                ]);

                $db->commit();
                logAudit($staff['full_name'], 'OP_FUND_DEBIT', 'OPERATING_FUND', $ref, 'SUCCESS',
                    'Debited ' . moneyFormat($amount) . ' from operating fund: ' . $desc
                    . ' [GL: CR 1400 + DR 5900]',
                    $staff['department'], getClientIp());
                createdResponse([
                    'id' => (int)$db->lastInsertId(),
                    'ref' => $ref,
                    'balance' => $newOpBalance,
                    'gl_1400_balance' => $newGLBalance,
                    'account_id' => (int)$account['id']
                ], 'Funds debited successfully. GL entries posted: CR 1400 + DR 5900.');
            } catch (PDOException $e) {
                if ($db->inTransaction()) $db->rollBack();
                serverErrorResponse('Failed to debit operating fund.');
            }

        // ─────────────────────────────────────────────
        // TRANSFER — Internal transfer between GL accounts
        // Double-entry: DR target (Asset ↑) + CR 1400 (Asset ↓)
        // ─────────────────────────────────────────────
        } elseif ($action === 'transfer') {
            $errors = validateRequired($input, ['amount', 'description', 'to_gl_code', 'to_gl_name']);
            if (!empty($errors)) { validationError($errors); }
            $amount = floatval($input['amount']);
            if ($amount <= 0) { validationError(['amount' => 'Amount must be greater than zero.']); }
            try {
                $db = getDB();
                // DDL first — before transaction to avoid MySQL implicit commit
                prepareDatabase($db);

                // ── Balance check from GL 1400 (source of truth) BEFORE beginTransaction ──
                // ★ FIX (OP-FUND-011): Use branch scope for balance check
                $_isAdmin = strtoupper($staff['role'] ?? '') === 'ADMIN';
                $branchScope = $_isAdmin ? null : ($staff['branches'] ?? null);
                $currentGLBalance = getGL1400Balance($db, $branchScope);

                if ($currentGLBalance < $amount) {
                    errorResponse(
                        'Insufficient operating fund balance. Available (GL 1400): ' . moneyFormat($currentGLBalance),
                        400
                    );
                }

                $account = getDefaultOpAccount($db);

                $db->beginTransaction();

                $newGLBalance = $currentGLBalance - $amount;
                $newOpBalance = floatval($account['balance']) - $amount;
                $ref = generateOpRef($db, 'TR');
                $desc = sanitize($input['description']);
                $toGl = sanitize($input['to_gl_code']);
                $toName = sanitize($input['to_gl_name']);

                // 1. Update the operating_account denormalised balance
                $upd = $db->prepare("UPDATE operating_account SET balance = :bal WHERE id = :id");
                $upd->execute([':bal' => $newOpBalance, ':id' => $account['id']]);

                // 2. Insert into operating_account_transactions (audit trail)
                // ★ FIX: Added branch column to enable per-branch filtering in the UI.
                $branch = sanitize($input['branch'] ?? ($staff['branches'][0] ?? ''));
                $ins = $db->prepare("INSERT INTO operating_account_transactions
                    (ref, operating_account_id, date, type, description, amount, balance_after, operator, source_type, source_ref, category, gl_code, branch)
                    VALUES (:ref, :aid, CURDATE(), 'DEBIT', :desc, :amt, :bal, :op, :stype, :sref, :cat, :gl, :branch)");
                $ins->execute([
                    ':ref' => $ref, ':aid' => $account['id'],
                    ':desc' => $desc,
                    ':amt' => $amount, ':bal' => $newOpBalance,
                    ':op' => $staff['full_name'],
                    ':stype' => 'TRANSFER',
                    ':sref' => $toGl,
                    ':cat' => 'GL_TRANSFER',
                    ':gl' => $toGl,
                    ':branch' => $branch
                ]);

                // 3. GL Entry #1 — DEBIT target account (receiving side, asset increases)
                $glSQL = buildGLInsertSQL();
                $glStmt1 = $db->prepare($glSQL);
                $glStmt1->execute([
                    ':ref'    => $ref,
                    ':acode'  => $toGl,
                    ':aname'  => $toName,
                    ':debit'  => $amount,
                    ':credit' => 0,
                    ':desc'   => $desc . ' (Transfer — DR ' . $toGl . ')',
                    ':opid'   => $staff['id'],
                    ':txtype' => 'OP_FUND_TRANSFER',
                    ':contra' => GL_OP_FUND_BANK,
                ]);

                // 4. GL Entry #2 — CREDIT 1400 (Operating Fund — Bank, asset decreases)
                $glStmt2 = $db->prepare($glSQL);
                $glStmt2->execute([
                    ':ref'    => $ref,
                    ':acode'  => GL_OP_FUND_BANK,
                    ':aname'  => 'Operating Fund — Bank',
                    ':debit'  => 0,
                    ':credit' => $amount,
                    ':desc'   => $desc . ' (Transfer — CR 1400)',
                    ':opid'   => $staff['id'],
                    ':txtype' => 'OP_FUND_TRANSFER',
                    ':contra' => $toGl,
                ]);

                $db->commit();
                logAudit($staff['full_name'], 'OP_FUND_TRANSFER', 'OPERATING_FUND', $ref, 'SUCCESS',
                    'Transferred ' . moneyFormat($amount) . ' to GL ' . $toGl . ' (' . $toName . '): ' . $desc
                    . ' [GL: DR ' . $toGl . ' + CR 1400]',
                    $staff['department'], getClientIp());
                createdResponse([
                    'id' => (int)$db->lastInsertId(),
                    'ref' => $ref,
                    'balance' => $newOpBalance,
                    'gl_1400_balance' => $newGLBalance,
                    'account_id' => (int)$account['id'],
                    'to_gl_code' => $toGl,
                    'to_gl_name' => $toName,
                ], 'Fund transfer completed successfully. GL entries posted: DR ' . $toGl . ' + CR 1400.');
            } catch (PDOException $e) {
                if ($db->inTransaction()) $db->rollBack();
                serverErrorResponse('Failed to process fund transfer.');
            } catch (\Throwable $e) {
                if (isset($db) && $db->inTransaction()) $db->rollBack();
                serverErrorResponse('Unexpected error in fund transfer.');
            }

        } else {
            errorResponse('Unknown action. Use: credit, debit, or transfer.', 400);
        }
        break;

    default:
        errorResponse('Method not allowed.', 405);
}
