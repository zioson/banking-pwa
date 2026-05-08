<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: General Ledger — GL Account Balances and Transaction History
 *
 * GET /api/general-ledger                  — List all GL accounts with computed balances + trial balance
 * GET /api/general-ledger?with_entries=1   — Include last N entries per account
 * GET /api/general-ledger/{code}/entries   — Paginated GL entries for a specific account
 * GET /api/general-ledger/entries          — All GL entries (paginated, filterable)
 * POST /api/general-ledger                  — Fund transfer, loan fund credit/debit, repayment, writeoff, journal entry
 */
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

$staff = requireModule('ACCOUNTS');
$method = $_ROUTE['method'];
$id = $_ROUTE['id'];
$subResource = $_ROUTE['subResource'] ?? '';

/**
 * Ensure the general_ledger table exists. Creates it if missing.
 * Also adds transaction_type and contra_account columns for enterprise-grade categorization.
 */
function ensureGeneralLedgerTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS general_ledger (
        id SERIAL PRIMARY KEY,
        account_code VARCHAR(10) NOT NULL,
        account_name VARCHAR(200) DEFAULT '',
        debit DECIMAL(20,2) NOT NULL DEFAULT 0,
        credit DECIMAL(20,2) NOT NULL DEFAULT 0,
        date DATE NOT NULL,
        reference VARCHAR(100) DEFAULT '',
        description TEXT,
        posted_by INT DEFAULT NULL,
        transaction_type VARCHAR(50) DEFAULT '',
        contra_account VARCHAR(50) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_account_code (account_code),
        INDEX idx_date (date),
        INDEX idx_reference (reference),
        INDEX idx_transaction_type (transaction_type)
    )");

    // Safe migration: add transaction_type column if missing (for existing installations)
    $col = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'general_ledger' AND column_name = 'transaction_type'")->fetch();
    if (!$col) {
        $db->exec("ALTER TABLE general_ledger ADD COLUMN transaction_type VARCHAR(50) DEFAULT ''");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_transaction_type ON general_ledger (transaction_type)");
    }
    // Safe migration: add contra_account column if missing
    $col2 = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'general_ledger' AND column_name = 'contra_account'")->fetch();
    if (!$col2) {
        $db->exec("ALTER TABLE general_ledger ADD COLUMN contra_account VARCHAR(50) DEFAULT ''");
    } else {
        // ★ Widen contra_account if it was created as VARCHAR(10) — some GL codes like '1400' are fine,
        // but descriptions like 'Operating Fund - Bank' need more space
        $colType = $db->query("SELECT column_name, data_type AS Type, character_maximum_length FROM information_schema.columns WHERE table_name = 'general_ledger' AND column_name = 'contra_account'")->fetch();
        if ($colType && $colType['character_maximum_length'] !== null && (int)$colType['character_maximum_length'] <= 10) {
            $db->exec('ALTER TABLE general_ledger ALTER COLUMN contra_account TYPE VARCHAR(50)');
        }
    }
    // ★ Safe migration: add branch column if missing (for branch-level GL filtering)
    $col3 = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'general_ledger' AND column_name = 'branch'")->fetch();
    if (!$col3) {
        $db->exec("ALTER TABLE general_ledger ADD COLUMN branch VARCHAR(100) DEFAULT ''");
        $brIdx = $db->query("SELECT indexname FROM pg_indexes WHERE tablename = 'general_ledger' WHERE indexname = 'idx_branch'")->fetch();
        if (!$brIdx) { $db->exec("CREATE INDEX IF NOT EXISTS idx_branch ON general_ledger (branch)"); }
    }

    // ★ BACKFILL MIGRATION: Populate branch on existing GL entries that have no branch.
    // Derives branch from posted_by → staff.department lookup.
    // staff has no 'branch' column (uses 'department' instead) — use COALESCE on department.
    // Idempotent — skips entries that already have a branch.
    try {
        $orphans = $db->query("SELECT COUNT(*) AS c FROM general_ledger WHERE (branch IS NULL OR branch = '') AND posted_by IS NOT NULL")->fetch();
        if ((int)$orphans['c'] > 0) {
            $backfilled = $db->exec(
                "UPDATE general_ledger SET branch = COALESCE(NULLIF(s.department,''), '') FROM staff s WHERE general_ledger.posted_by = s.id AND (general_ledger.branch IS NULL OR general_ledger.branch = '') AND general_ledger.posted_by IS NOT NULL"
            );
            if ($backfilled > 0) {
                error_log('[General Ledger Migration] Backfilled branch on ' . $backfilled . ' GL entries from staff.department.');
            }
        }
    } catch (PDOException $bfErr) {
        error_log('[General Ledger Migration] Branch backfill error: ' . $bfErr->getMessage());
    }
}

/**
 * Ensure the chart_of_accounts table exists and is seeded.
 */
function ensureChartOfAccounts(PDO $db): void {
    // Create table if missing
    $db->exec("CREATE TABLE IF NOT EXISTS chart_of_accounts (
        id SERIAL PRIMARY KEY,
        code VARCHAR(10) NOT NULL UNIQUE,
        name VARCHAR(200) NOT NULL,
        type VARCHAR(20) NOT NULL,
        category VARCHAR(100),
        description TEXT,
        is_active BOOLEAN DEFAULT 1,
        INDEX idx_code (code),
        INDEX idx_type (type),
        INDEX idx_active (is_active)
    )");

    // Seed if empty
    $count = (int)$db->query("SELECT COUNT(*) FROM chart_of_accounts")->fetchColumn();
    if ($count === 0) {
        $db->exec("INSERT INTO chart_of_accounts (code, name, type, category, description, is_active) VALUES
            ('1000', 'Cash & Equivalents',             'ASSET',    'Current Assets',      'Bank cash holdings and reserves',                          1),
            ('1100', 'Customer Loan Portfolio',         'ASSET',    'Current Assets',      'Total outstanding customer loans',                          1),
            ('1200', 'Loans and Advances',              'ASSET',    'Current Assets',      'Loans & Advances Fund pool — linked to BANK-LF-0001',       1),
            ('1201', 'Loans Receivable',               'ASSET',    'Current Assets',      'Outstanding loan principal owed by customers',               1),
            ('1300', 'Fee Receivable',                  'ASSET',    'Current Assets',      'Fees earned but not yet collected',                         1),
            ('1400', 'Operating Fund - Bank',            'ASSET',    'Current Assets',      'Bank operating capital and fund pool',                       1),
            ('2000', 'Customer Deposits',               'LIABILITY','Current Liabilities',  'Total customer account balances',                           1),
            ('2100', 'Tax Payable',                     'LIABILITY','Current Liabilities',  'Statutory tax obligations',                                 1),
            ('3100', 'Retained Earnings',               'EQUITY',   'Equity',              'Cumulative retained profits',                               1),
            ('4100', 'Withdrawal Fee Income',           'INCOME',   'Fee Income',          'Fees earned from withdrawal transactions',                  1),
            ('4200', 'Loan Interest Income',            'INCOME',   'Interest Income',     'Interest earned from loan facilities',                      1),
            ('4300', 'Late Penalty Income',             'INCOME',   'Penalty Income',      'Penalties from overdue loan payments',                      1),
            ('4400', 'Transfer Fee Income',             'INCOME',   'Fee Income',          'Fees from inter-account and wire transfers',                1),
            ('4500', 'Savings Interest Expense',        'EXPENSE',  'Interest Expense',    'Interest paid on savings accounts',                         1),
            ('5100', 'Rent & Lease Expense',            'EXPENSE',  'Operating Expenses',  'Office rent, lease payments, property costs',               1),
            ('5200', 'Salaries & Wages Expense',        'EXPENSE',  'Operating Expenses',  'Staff compensation and payroll costs',                      1),
            ('5300', 'Utilities Expense',               'EXPENSE',  'Operating Expenses',  'Electricity, water, internet, phone',                      1),
            ('5400', 'Equipment & Maintenance Expense',  'EXPENSE',  'Operating Expenses',  'Equipment purchases, repairs, maintenance',                 1),
            ('5500', 'Marketing & Advertising Expense', 'EXPENSE',  'Operating Expenses',  'Marketing campaigns, advertising, branding',                1),
            ('5600', 'Professional Services Expense',   'EXPENSE',  'Operating Expenses',  'Legal, audit, consulting fees',                            1),
            ('5700', 'Technology & IT Expense',         'EXPENSE',  'Operating Expenses',  'Software licenses, hosting, IT infrastructure',            1),
            ('5800', 'Insurance Expense',               'EXPENSE',  'Operating Expenses',  'Insurance premiums and coverage',                           1),
            ('5900', 'Miscellaneous Expense',           'EXPENSE',  'Operating Expenses',  'Other operating costs',                                    1)");
        error_log('[General Ledger API] Seeded 23 chart_of_accounts entries.');
    }

    // ★ CRITICAL MIGRATION: Ensure GL codes exist AND have correct names
    // OLD seed had 1400 = "Fixed Assets" — must be 1400 = "Operating Fund - Bank"
    // OLD seed had 1200 = "Interest Receivable" — now 1200 = "Loans and Advances" (linked to BANK-LF-0001)
    $criticalCodes = [
        ['1200', 'Loans and Advances', 'ASSET', 'Current Assets', 'Loans & Advances Fund pool — linked to BANK-LF-0001 (Loan Fund). Debit to fund, credit to withdraw.'],
        ['1201', 'Loans Receivable', 'ASSET', 'Current Assets', 'Outstanding loan principal owed by customers. DR when loan disbursed, CR when repaid or written off.'],
        ['1400', 'Operating Fund - Bank', 'ASSET', 'Current Assets', 'Bank operating capital and fund pool — linked to BANK-OP-0001'],
        ['1600', 'Fixed Assets', 'ASSET', 'PREMISES', 'Property, equipment, and IT infrastructure'],
        ['3100', 'Retained Earnings', 'EQUITY', 'Equity', 'Cumulative retained profits'],
        ['4200', 'Loan Interest Income', 'INCOME', 'Interest Income', 'Interest earned from loan facilities — linked to BANK-LI-0001 (Loan Interest Income Fund).'],
        ['5900', 'Miscellaneous Expense', 'EXPENSE', 'Operating Expenses', 'Other operating costs']
    ];
    foreach ($criticalCodes as $row) {
        $chk = $db->prepare("SELECT id, name FROM chart_of_accounts WHERE code = ?");
        $chk->execute([$row[0]]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ($existing['name'] !== $row[1]) {
                $db->prepare("UPDATE chart_of_accounts SET name = ?, type = ?, category = ?, description = ?, is_active = TRUE WHERE code = ?")
                  ->execute([$row[1], $row[2], $row[3], $row[4], $row[0]]);
                error_log('[General Ledger API] Migration: Fixed GL code ' . $row[0] . ' from "' . $existing['name'] . '" to "' . $row[1] . '"');
            }
        } else {
            $db->prepare("INSERT INTO chart_of_accounts (code, name, type, category, description, is_active) VALUES (?, ?, ?, ?, ?, 1)")
              ->execute($row);
            error_log('[General Ledger API] Migration: Created GL code ' . $row[0] . ' (' . $row[1] . ') in chart_of_accounts');
        }
    }
    // Also fix any general_ledger entries with wrong names
    $db->prepare("UPDATE general_ledger SET account_name = 'Operating Fund - Bank' WHERE account_code = '1400' AND account_name LIKE '%Fixed Asset%'")
      ->execute();
    $db->prepare("UPDATE general_ledger SET account_name = 'Loans and Advances' WHERE account_code = '1200' AND account_name LIKE '%Interest Receivable%'")
      ->execute();
}

switch ($method) {
    case 'GET':
        try {
            $db = getDB();

            // Ensure both tables exist and chart_of_accounts is seeded
            ensureGeneralLedgerTable($db);
            ensureChartOfAccounts($db);

            // GET /api/general-ledger/entries — all GL entries (paginated, filterable)
            if ($id === 'entries' && !$subResource) {
                $params = [];
                $where = "WHERE 1=1";

                // ★ FIXED (SEC-AUDIT-003): Server-side branch filtering with Admin Bypass
                $staffBranches = $staff['branches'] ?? [];
                $clientBranch = sanitize($_GET['branch'] ?? '');
                $branchFilter = applyBranchFilter($staffBranches, $clientBranch, $params, $staff['role'] ?? '', 'gl.branch');
                if ($branchFilter) { $where .= $branchFilter; }

                if (!empty($_GET['account_code'])) {
                    $where .= " AND gl.account_code = :ac";
                    $params[':ac'] = sanitize($_GET['account_code']);
                }
                if (!empty($_GET['date_from'])) {
                    $where .= " AND gl.date >= :df";
                    $params[':df'] = sanitize($_GET['date_from']);
                }
                if (!empty($_GET['date_to'])) {
                    $where .= " AND gl.date <= :dt";
                    $params[':dt'] = sanitize($_GET['date_to']);
                }
                if (!empty($_GET['reference'])) {
                    $where .= " AND gl.reference LIKE :ref";
                    $params[':ref'] = '%' . sanitize($_GET['reference']) . '%';
                }
                if (!empty($_GET['type'])) {
                    if ($_GET['type'] === 'debit') {
                        $where .= " AND gl.debit > 0";
                    } elseif ($_GET['type'] === 'credit') {
                        $where .= " AND gl.credit > 0";
                    }
                }
                // ★ Enterprise filter: by transaction type
                if (!empty($_GET['transaction_type'])) {
                    $where .= " AND gl.transaction_type = :tt";
                    $params[':tt'] = sanitize($_GET['transaction_type']);
                }
                // Note: branch filter is now handled by applyBranchFilter above (BS-014)

                $page = max(1, (int)($_GET['page'] ?? 1));
                // ★ FIX (RA-CF-002/RA-TXN-002): Increased from 500 to 5000. The frontend loads
                // all GL entries for Cash Flow and Balance Sheet computations.
                $pageSize = max(1, min((int)($_GET['pageSize'] ?? 50), 5000));
                $offset = ($page - 1) * $pageSize;

                $countStmt = $db->prepare("SELECT COUNT(*) AS total FROM general_ledger gl $where");
                $countStmt->execute($params);
                $total = (int)$countStmt->fetch()['total'];

                $stmt = $db->prepare(
                    "SELECT gl.*, s.full_name AS posted_by_name
                     FROM general_ledger gl
                     LEFT JOIN staff s ON gl.posted_by = s.id
                     $where
                     ORDER BY gl.date DESC, gl.id DESC
                     LIMIT CAST(:limit AS INTEGER) OFFSET CAST(:offset AS INTEGER)"
                );
                foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
                $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                paginatedResponse($stmt->fetchAll(PDO::FETCH_ASSOC), $total, $page, $pageSize);
                break;
            }

            // GET /api/general-ledger/{code}/entries — entries for a specific account
            if ($subResource === 'entries' && $id) {
                $accountCode = sanitize($id);
                $params = [':ac' => $accountCode];
                $where = "WHERE gl.account_code = :ac";

                // ★ FIXED (SEC-AUDIT-003): Server-side branch filtering with Admin Bypass
                $staffBranches = $staff['branches'] ?? [];
                $clientBranch = sanitize($_GET['branch'] ?? '');
                $branchFilter = applyBranchFilter($staffBranches, $clientBranch, $params, $staff['role'] ?? '', 'gl.branch');
                if ($branchFilter) { $where .= $branchFilter; }

                if (!empty($_GET['date_from'])) {
                    $where .= " AND gl.date >= :df";
                    $params[':df'] = sanitize($_GET['date_from']);
                }
                if (!empty($_GET['date_to'])) {
                    $where .= " AND gl.date <= :dt";
                    $params[':dt'] = sanitize($_GET['date_to']);
                }
                // ★ Enterprise filter: by transaction type
                if (!empty($_GET['transaction_type'])) {
                    $where .= " AND gl.transaction_type = :tt";
                    $params[':tt'] = sanitize($_GET['transaction_type']);
                }

                $page = max(1, (int)($_GET['page'] ?? 1));
                // ★ FIX (RA-CF-002/RA-TXN-002): Increased from 500 to 5000. The frontend loads
                // all GL entries for Cash Flow and Balance Sheet computations.
                $pageSize = max(1, min((int)($_GET['pageSize'] ?? 50), 5000));
                $offset = ($page - 1) * $pageSize;

                $countStmt = $db->prepare("SELECT COUNT(*) AS total FROM general_ledger gl $where");
                $countStmt->execute($params);
                $total = (int)$countStmt->fetch()['total'];

                $stmt = $db->prepare(
                    "SELECT gl.*, s.full_name AS posted_by_name
                     FROM general_ledger gl
                     LEFT JOIN staff s ON gl.posted_by = s.id
                     $where
                     ORDER BY gl.date DESC, gl.id DESC
                     LIMIT CAST(:limit AS INTEGER) OFFSET CAST(:offset AS INTEGER)"
                );
                foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
                $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                paginatedResponse($stmt->fetchAll(PDO::FETCH_ASSOC), $total, $page, $pageSize);
                break;
            }

            // GET /api/general-ledger — all GL accounts with computed balances from general_ledger table
            // Get all chart of accounts
            $coaStmt = $db->query("SELECT * FROM chart_of_accounts ORDER BY code ASC");
            $accounts = $coaStmt->fetchAll(PDO::FETCH_ASSOC);

            // ★ FIX (BS-014): Apply branch isolation to the GL balance computation.
            // Previously this query aggregated ALL entries regardless of branch,
            // meaning any user with ACCOUNTS module access could see balances for
            // ALL branches — a data leakage issue. Now non-admin staff only see
            // balances from their assigned branches.
            $glBalWhere = "WHERE 1=1";
            $glBalParams = [];
            $staffBranches = $staff['branches'] ?? [];
            $clientBranch = sanitize($_GET['branch'] ?? '');
            $branchFilter = applyBranchFilter($staffBranches, $clientBranch, $glBalParams, $staff['role'] ?? '', 'branch');
            $glBalWhere .= $branchFilter;
            $dateFrom = sanitize($_GET['date_from'] ?? '');
            $dateTo = sanitize($_GET['date_to'] ?? '');
            if ($dateFrom !== '') {
                $glBalWhere .= " AND date >= :gldf";
                $glBalParams[':gldf'] = substr($dateFrom, 0, 10);
            }
            if ($dateTo !== '') {
                $glBalWhere .= " AND date <= :gldt";
                $glBalParams[':gldt'] = substr($dateTo, 0, 10);
            }

            // Compute balances from general_ledger for each account
            // Debit increases asset/expense accounts, credit increases liability/equity/income accounts
            $glBalances = [];
            $glStmt = $db->prepare(
                "SELECT account_code,
                    SUM(debit) AS total_debit,
                    SUM(credit) AS total_credit,
                    COUNT(*) AS entry_count,
                    MAX(date) AS last_entry_date
                 FROM general_ledger
                 $glBalWhere
                 GROUP BY account_code"
            );
            foreach ($glBalParams as $k => $v) { $glStmt->bindValue($k, $v); }
            $glStmt->execute();
            while ($row = $glStmt->fetch(PDO::FETCH_ASSOC)) {
                $code = $row['account_code'];
                $glBalances[$code] = [
                    'total_debit'   => floatval($row['total_debit']),
                    'total_credit'  => floatval($row['total_credit']),
                    'entry_count'   => (int)$row['entry_count'],
                    'last_entry_date' => $row['last_entry_date']
                ];
            }

            // Build response with computed net balances
            $result = [];
            foreach ($accounts as $acc) {
                $code = $acc['code'];
                $gl = $glBalances[$code] ?? ['total_debit' => 0, 'total_credit' => 0, 'entry_count' => 0, 'last_entry_date' => null];
                $totalDebit = $gl['total_debit'];
                $totalCredit = $gl['total_credit'];

                // Net balance logic (standard double-entry bookkeeping):
                // ASSET and EXPENSE: normal balance = debit - credit (debit increases)
                // LIABILITY, EQUITY, INCOME: normal balance = credit - debit (credit increases)
                $type = $acc['type'];
                if ($type === 'ASSET' || $type === 'EXPENSE') {
                    $netBalance = $totalDebit - $totalCredit;
                } else {
                    $netBalance = $totalCredit - $totalDebit;
                }

                $result[] = [
                    'code'              => $code,
                    'name'              => $acc['name'],
                    'type'              => $type,
                    'category'          => $acc['category'] ?? '',
                    'description'       => $acc['description'] ?? '',
                    'is_active'         => (bool)$acc['is_active'],
                    'total_debit'       => $totalDebit,
                    'total_credit'      => $totalCredit,
                    'net_balance'       => $netBalance,
                    'entry_count'       => $gl['entry_count'],
                    'last_entry_date'   => $gl['last_entry_date']
                ];
            }

            // ★ Trial Balance: total debits must equal total credits in balanced double-entry
            $trialDebits = 0;
            $trialCredits = 0;
            foreach ($result as $r) {
                $trialDebits += $r['total_debit'];
                $trialCredits += $r['total_credit'];
            }
            $trialDifference = abs($trialDebits - $trialCredits);

            // ★ DIAGNOSTIC: Find unbalanced entries — entries missing their contra pair
            // In proper double-entry, every debit must have a matching credit with same reference.
            // This query finds entries where the total debit != total credit for the same reference.
            $unbalancedEntries = [];
            if ($trialDifference >= 0.01) {
                $diagStmt = $db->prepare(
                    "SELECT reference,
                        SUM(debit) AS ref_debits,
                        SUM(credit) AS ref_credits,
                        ABS(SUM(debit) - SUM(credit)) AS diff,
                        STRING_AGG(DISTINCT transaction_type, ',') AS tx_types,
                        STRING_AGG(DISTINCT account_code, ',') AS accounts,
                        MIN(date) AS first_date,
                        COUNT(*) AS entry_count
                     FROM general_ledger
                     WHERE reference IS NOT NULL AND reference != ''" . $branchFilter . ($dateFrom !== '' ? " AND date >= :gldf" : "") . ($dateTo !== '' ? " AND date <= :gldt" : "") . "
                     GROUP BY reference
                     HAVING ABS(SUM(debit) - SUM(credit)) > 0.01
                     ORDER BY diff DESC
                     LIMIT 20"
                );
                foreach ($glBalParams as $k => $v) { $diagStmt->bindValue($k, $v); }
                $diagStmt->execute();
                $unbalancedEntries = $diagStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            successResponse([
                'accounts' => $result,
                'trial_balance' => [
                    'total_debits'  => $trialDebits,
                    'total_credits' => $trialCredits,
                    'difference'    => $trialDifference,
                    'is_balanced'   => $trialDifference < 0.01,
                    'unbalanced_count' => count($unbalancedEntries),
                    'unbalanced_entries' => $unbalancedEntries
                ]
            ]);

        } catch (PDOException $e) {
            error_log('[General Ledger API] Error: ' . $e->getMessage());
            serverErrorResponse('Database error.');
        }
        break;

    case 'POST':
        requireRole(['ADMIN', 'ACCOUNTANT', 'MANAGER'], $staff);
        try {
            $db = getDB();
            ensureGeneralLedgerTable($db);
            ensureChartOfAccounts($db);

            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) { errorResponse('Invalid JSON body.', 400); break; }

            $action = strtoupper(trim($input['action'] ?? 'journal_entry'));
            $debitAccount = trim($input['debit_account'] ?? '');
            $creditAccount = trim($input['credit_account'] ?? '');
            $amountParsed = parseDecimalInput($input['amount'] ?? null, 'Amount', 2, 0.01, 1000000000000);
            if (!$amountParsed['ok']) { errorResponse($amountParsed['error'], 400); break; }
            $amount = $amountParsed['value'];
            $description = trim($input['description'] ?? '');
            $reference = trim($input['reference'] ?? '');

            if ($amount <= 0) { errorResponse('Amount must be greater than zero.', 400); break; }

            $operatorName = $staff['full_name'] ?? 'System';
            $operatorId = (int)($staff['id'] ?? 0);
            // ★ Derive branch from logged-in staff member — used for ALL GL entries
            $operatorBranch = $staff['branch'] ?? ($staff['department'] ?? '');
            $inputBranch = sanitize($input['branch'] ?? '');
            if ($inputBranch !== '') {
                $bn = strtoupper(trim($inputBranch));
                if (in_array($bn, ['ALL', 'ALL BRANCHES', 'ALL_BRANCHES', 'ALL MY BRANCHES', 'ALL_MY_BRANCHES'], true)) {
                    $inputBranch = '';
                }
            }
            if ($inputBranch !== '') {
                if (!hasBranchAccess($staff, $inputBranch)) {
                    errorResponse('Access denied: you do not have branch access for this posting.', 403);
                    break;
                }
                $operatorBranch = $inputBranch;
            }

            // ── FUND TRANSFER (Operating Fund ↔ GL Account) ────────────────
            if ($action === 'FUND_TRANSFER') {
                $direction = strtoupper(trim($input['direction'] ?? '')); // TO_GL or FROM_GL
                $glAccount = trim($input['gl_account'] ?? '');

                if (!in_array($direction, ['TO_GL', 'FROM_GL']) || !$glAccount) {
                    errorResponse('fund_transfer requires: direction (TO_GL/FROM_GL), gl_account.', 400);
                    break;
                }

                // ★ Prevent transferring Operating Fund to itself (GL 1400 IS the Operating Fund)
                if ($glAccount === '1400') {
                    errorResponse('Cannot transfer funds to Operating Fund (GL 1400) — it is the source account. Select a different GL account.', 400);
                    break;
                }

                // Validate GL account exists
                $glAcc = $db->prepare("SELECT * FROM chart_of_accounts WHERE code = ? AND is_active = TRUE");
                $glAcc->execute([$glAccount]);
                $glAccRow = $glAcc->fetch(PDO::FETCH_ASSOC);
                if (!$glAccRow) { errorResponse('GL account ' . $glAccount . ' not found or inactive.', 404); break; }

                // ★ PREVENT NOMINAL ACCOUNT FUND TRANSFERS: Block transfers to/from EXPENSE and INCOME accounts.
                // Expense and Income accounts are nominal (temporary) accounts — they accumulate
                // costs/revenues over a period and get closed to Retained Earnings at period-end.
                // They do NOT hold "funds" or "budgets." Transferring money to an expense account
                // would double-charge the operating fund when the actual expense is later approved
                // (because approval posts DR Expense / CR Operating Fund — the same CR side).
                // Only real accounts (ASSET, LIABILITY, EQUITY) can receive or send fund transfers.
                $glAccType = $glAccRow['type'];
                if (in_array($glAccType, ['EXPENSE', 'INCOME'])) {
                    $directionLabel = ($direction === 'TO_GL') ? 'to' : 'from';
                    errorResponse(
                        'Cannot transfer funds ' . $directionLabel . ' GL ' . $glAccount . ' (' . $glAccRow['name'] . '). ' .
                        'This is a ' . $glAccType . ' account (nominal account). Fund transfers are only permitted ' .
                        'between real accounts: ASSET, LIABILITY, or EQUITY. ' .
                        'Expense accounts are debited automatically when expenses are approved. ' .
                        'No manual fund transfer to this account is needed.', 400
                    );
                    break;
                }

                // Ensure operating account tables exist
                $db->exec("CREATE TABLE IF NOT EXISTS operating_account (
                    id SERIAL PRIMARY KEY,
                    account_number VARCHAR(30) NOT NULL UNIQUE,
                    account_name VARCHAR(200),
                    balance DECIMAL(20,2) DEFAULT 0,
                    currency VARCHAR(5) DEFAULT 'XAF',
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                $db->exec("CREATE TABLE IF NOT EXISTS operating_account_transactions (
                    id SERIAL PRIMARY KEY,
                    ref VARCHAR(50),
                    operating_account_id INT NOT NULL,
                    date DATE NOT NULL,
                    type VARCHAR(20) NOT NULL,
                    description TEXT,
                    amount DECIMAL(20,2) NOT NULL,
                    balance_after DECIMAL(20,2) NOT NULL,
                    operator VARCHAR(200),
                    contra_account VARCHAR(100) DEFAULT '',
                    transaction_type VARCHAR(50) DEFAULT '',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (operating_account_id) REFERENCES operating_account(id)
                )");

                // ★ Safe migration: add contra_account and transaction_type columns if missing
                $ftCol1 = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'operating_account_transactions' AND column_name = 'contra_account'")->fetch();
                if (!$ftCol1) { $db->exec("ALTER TABLE operating_account_transactions ADD COLUMN contra_account VARCHAR(100) DEFAULT ''"); }
                $ftCol2 = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'operating_account_transactions' AND column_name = 'transaction_type'")->fetch();
                if (!$ftCol2) { $db->exec("ALTER TABLE operating_account_transactions ADD COLUMN transaction_type VARCHAR(50) DEFAULT ''"); }

                // ★ Safe migration: add branch column to operating_account_transactions
                $ftCol3 = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'operating_account_transactions' AND column_name = 'branch'")->fetch();
                if (!$ftCol3) { $db->exec("ALTER TABLE operating_account_transactions ADD COLUMN branch VARCHAR(100) DEFAULT ''"); }

                // ★ Backfill branch on existing operating_account_transactions from staff records
                try {
                    $opOrphans = $db->query("SELECT COUNT(*) AS c FROM operating_account_transactions oat WHERE (oat.branch IS NULL OR oat.branch = '')")->fetch();
                    if ((int)$opOrphans['c'] > 0) {
                        // All op txns were posted by staff — set branch to the first admin's branch
                        // since we can't trace individual operators. Use a default branch approach.
                        $defaultBranch = $db->query("SELECT COALESCE(NULLIF(branch,''), department, '') FROM staff WHERE role IN ('ADMIN','MANAGER','ACCOUNTANT') LIMIT 1")->fetchColumn();
                        if ($defaultBranch) {
                            $opBackfilled = $db->exec(
                                "UPDATE operating_account_transactions SET branch = ? WHERE (branch IS NULL OR branch = '')",
                                [$defaultBranch]
                            );
                            if ($opBackfilled > 0) {
                                error_log('[GL Migration] Backfilled branch on ' . $opBackfilled . ' operating_account_transactions.');
                            }
                        }
                    }
                } catch (PDOException $opBfErr) {
                    error_log('[GL Migration] Operating txn branch backfill error: ' . $opBfErr->getMessage());
                }

                $opAcct = $db->query("SELECT * FROM operating_account WHERE account_number = 'BANK-OP-0001' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if (!$opAcct) { $opAcct = $db->query('SELECT * FROM operating_account LIMIT 1')->fetch(PDO::FETCH_ASSOC); }
                if (!$opAcct) { errorResponse('Operating account not found.', 404); break; }
                $opAcctId = (int)$opAcct['id'];
                // ★ Pattern 1 Compliance: Read operating fund balance from GL 1400 (source of truth),
                // NOT from stored operating_account.balance (which may be stale).
                if ($operatorBranch !== '') {
                    $gl1400Bal = $db->prepare("SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS fund_balance FROM general_ledger WHERE account_code = '1400' AND branch = ?");
                    $gl1400Bal->execute([$operatorBranch]);
                } else {
                    $gl1400Bal = $db->prepare("SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS fund_balance FROM general_ledger WHERE account_code = '1400'");
                    $gl1400Bal->execute();
                }
                $currentBalance = (float)$gl1400Bal->fetchColumn();

                // Generate reference
                $count = (int)$db->query("SELECT COUNT(*) FROM operating_account_transactions")->fetchColumn();
                $ref = $reference ?: ('FT-' . date('Y-m-d') . '-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT));

                // ★ Enterprise description: clearly shows DR/CR with full account names
                $descSuffix = $direction === 'TO_GL'
                    ? ' [DR ' . $glAccount . ' ' . $glAccRow['name'] . ' / CR 1400 Operating Fund — Fund Transfer In]'
                    : ' [CR ' . $glAccount . ' ' . $glAccRow['name'] . ' / DR 1400 Operating Fund — Fund Transfer Out]';

                if ($direction === 'TO_GL') {
                    // ── Transfer FROM operating fund TO GL account ──
                    // Double-entry rules:
                    //   Credit 1400 (Operating Fund — ASSET decreases as money leaves)
                    //   Debit target GL (receiving account — ASSET/EXPENSE increases as money enters)
                    if ($amount > $currentBalance) {
                        errorResponse('Insufficient operating fund balance. Available: ' . number_format($currentBalance) . ' XAF', 400);
                        break;
                    }
                // ★ Balance check passed — wrap financial side-effects in transaction
                $db->beginTransaction();
                try {
                    $newBalance = $currentBalance - $amount;

                    // Record operating account transaction with contra account details
                    $db->prepare("INSERT INTO operating_account_transactions (ref, operating_account_id, date, type, description, amount, balance_after, operator, contra_account, transaction_type, branch) VALUES (?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?, ?, ?)")
                      ->execute([$ref, $opAcctId, 'DEBIT', $description, $amount, $newBalance, $operatorName, $glAccount, 'FUND_TRANSFER_TO_GL', $operatorBranch]);
                    // Update operating account balance
                    $db->prepare("UPDATE operating_account SET balance = ? WHERE id = ?")->execute([$newBalance, $opAcctId]);

                    // GL Entry 1: Credit 1400 — Operating Fund (asset decreases)
                    $db->prepare("INSERT INTO general_ledger (account_code, account_name, debit, credit, date, reference, description, branch, posted_by, transaction_type, contra_account) VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)")
                      ->execute(['1400', 'Operating Fund - Bank', 0, $amount, $ref, $description . $descSuffix, $operatorBranch, $operatorId > 0 ? $operatorId : null, 'FUND_TRANSFER_TO_GL', $glAccount]);
                    // GL Entry 2: Debit target GL (receiving account increases)
                    $db->prepare("INSERT INTO general_ledger (account_code, account_name, debit, credit, date, reference, description, branch, posted_by, transaction_type, contra_account) VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)")
                      ->execute([$glAccount, $glAccRow['name'], $amount, 0, $ref, $description . $descSuffix, $operatorBranch, $operatorId > 0 ? $operatorId : null, 'FUND_TRANSFER_TO_GL', '1400']);

                    $db->commit();
                } catch (PDOException $txErr) {
                    $db->rollBack();
                    serverErrorResponse('Fund transfer failed — changes rolled back.');
                    break;
                }

                } else { // FROM_GL
                    // ── Transfer FROM GL account TO operating fund ──
                    // Double-entry rules:
                    //   Debit 1400 (Operating Fund — ASSET increases as money enters)
                    //   Credit source GL (giving account — ASSET/LIABILITY decreases as money leaves)

                    // ★ NEGATIVE BALANCE GUARD: Check source GL account has sufficient balance
                    $glType = $glAccRow['type'];
                    if ($operatorBranch !== '') {
                        $glBalStmt = $db->prepare("SELECT SUM(debit) AS total_debit, SUM(credit) AS total_credit FROM general_ledger WHERE account_code = ? AND branch = ?");
                        $glBalStmt->execute([$glAccount, $operatorBranch]);
                    } else {
                        $glBalStmt = $db->prepare("SELECT SUM(debit) AS total_debit, SUM(credit) AS total_credit FROM general_ledger WHERE account_code = ?");
                        $glBalStmt->execute([$glAccount]);
                    }
                    $glBalRow = $glBalStmt->fetch(PDO::FETCH_ASSOC);
                    $glTotalDebit = (float)($glBalRow['total_debit'] ?? 0);
                    $glTotalCredit = (float)($glBalRow['total_credit'] ?? 0);
                    // ASSET/EXPENSE: normal balance = debit - credit; others = credit - debit
                    $glNetBalance = ($glType === 'ASSET' || $glType === 'EXPENSE')
                        ? ($glTotalDebit - $glTotalCredit)
                        : ($glTotalCredit - $glTotalDebit);
                    if ($glNetBalance < $amount) {
                        errorResponse('Insufficient GL balance in ' . $glAccount . ' (' . $glAccRow['name'] . '). Available: ' . number_format($glNetBalance, 2) . ' XAF, attempted transfer: ' . number_format($amount, 2) . ' XAF. Negative balances are not permitted.', 400);
                        break;
                    }

                    // ★ Balance check passed — wrap financial side-effects in transaction
                    $db->beginTransaction();
                    try {
                        $newBalance = $currentBalance + $amount;

                        // Record operating account transaction with contra account details
                        $db->prepare("INSERT INTO operating_account_transactions (ref, operating_account_id, date, type, description, amount, balance_after, operator, contra_account, transaction_type, branch) VALUES (?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?, ?, ?)")
                          ->execute([$ref, $opAcctId, 'CREDIT', $description, $amount, $newBalance, $operatorName, $glAccount, 'FUND_TRANSFER_FROM_GL', $operatorBranch]);
                        // Update operating account balance
                        $db->prepare("UPDATE operating_account SET balance = ? WHERE id = ?")->execute([$newBalance, $opAcctId]);

                        // GL Entry 1: Debit 1400 — Operating Fund (asset increases)
                        $db->prepare("INSERT INTO general_ledger (account_code, account_name, debit, credit, date, reference, description, branch, posted_by, transaction_type, contra_account) VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)")
                          ->execute(['1400', 'Operating Fund - Bank', $amount, 0, $ref, $description . $descSuffix, $operatorBranch, $operatorId > 0 ? $operatorId : null, 'FUND_TRANSFER_FROM_GL', $glAccount]);
                        // GL Entry 2: Credit source GL (giving account decreases)
                        $db->prepare("INSERT INTO general_ledger (account_code, account_name, debit, credit, date, reference, description, branch, posted_by, transaction_type, contra_account) VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)")
                          ->execute([$glAccount, $glAccRow['name'], 0, $amount, $ref, $description . $descSuffix, $operatorBranch, $operatorId > 0 ? $operatorId : null, 'FUND_TRANSFER_FROM_GL', '1400']);

                        $db->commit();
                    } catch (PDOException $txErr) {
                        $db->rollBack();
                        serverErrorResponse('Fund transfer failed — changes rolled back.');
                        break;
                    }
                }

                logAudit($staff['full_name'] ?? 'System', 'GL_FUND_TRANSFER', 'GENERAL_LEDGER', $ref, 'SUCCESS',
                    'Fund Transfer ' . $direction . ' GL ' . $glAccount . ' (' . $glAccRow['name'] . ') Amount: ' . number_format($amount, 2) . ' XAF',
                    $staff['department'] ?? '', getClientIp());

                successResponse([
                    'success' => true,
                    'action' => 'fund_transfer',
                    'direction' => $direction,
                    'gl_account' => $glAccount,
                    'amount' => $amount,
                    'reference' => $ref,
                    'operating_balance_after' => $newBalance,
                    'entries_recorded' => 2
                ]);
                break;
            }

            // ── LOAN FUND CREDIT (Fund BANK-LF-0001 from a GL source account) ──
            if ($action === 'LOAN_FUND_CREDIT') {
                $sourceAccount = trim($input['source_account'] ?? '');
                if (!$sourceAccount || $amount <= 0) {
                    errorResponse('loan_fund_credit requires: source_account, amount > 0.', 400);
                    break;
                }

                // Validate source GL account exists
                $srcStmt = $db->prepare("SELECT * FROM chart_of_accounts WHERE code = ? AND is_active = TRUE");
                $srcStmt->execute([$sourceAccount]);
                $srcRow = $srcStmt->fetch(PDO::FETCH_ASSOC);
                if (!$srcRow) { errorResponse('Source GL account ' . $sourceAccount . ' not found or inactive.', 404); break; }

                // Cannot fund from GL 1200 to itself
                if ($sourceAccount === '1200') {
                    errorResponse('Cannot fund the Loan Fund from itself (GL 1200). Select a different source account.', 400);
                    break;
                }

                // ★ FIX (GLA-006): Block EXPENSE and INCOME accounts as funding source.
                // Consistent with FUND_TRANSFER which already blocks nominal accounts.
                // You cannot "fund" a loan pool from an expense or income account —
                // these are temporary accounts that close to retained earnings at period-end.
                $srcType = $srcRow['type'];
                if (in_array($srcType, ['EXPENSE', 'INCOME'])) {
                    errorResponse(
                        'Cannot fund Loan Fund from GL ' . $sourceAccount . ' (' . $srcRow['name'] . '). ' .
                        'This is a ' . $srcType . ' account (nominal/temporary account). Loan fund credits are only permitted ' .
                        'from real accounts: ASSET, LIABILITY, or EQUITY.', 400
                    );
                    break;
                }

                // ★ NEGATIVE BALANCE GUARD: Check source account has sufficient balance
                // $srcType already set above for the nominal account guard
                $srcBalStmt = $db->prepare("SELECT SUM(debit) AS total_debit, SUM(credit) AS total_credit FROM general_ledger WHERE account_code = ?");
                $srcBalStmt->execute([$sourceAccount]);
                $srcBalRow = $srcBalStmt->fetch(PDO::FETCH_ASSOC);
                $srcNetBalance = ($srcType === 'ASSET' || $srcType === 'EXPENSE')
                    ? ((float)($srcBalRow['total_debit'] ?? 0) - (float)($srcBalRow['total_credit'] ?? 0))
                    : ((float)($srcBalRow['total_credit'] ?? 0) - (float)($srcBalRow['total_debit'] ?? 0));
                if ($srcNetBalance < $amount) {
                    errorResponse('Insufficient GL balance in ' . $sourceAccount . ' (' . $srcRow['name'] . '). Available: ' . number_format($srcNetBalance, 2) . ' XAF, attempted: ' . number_format($amount, 2) . ' XAF.', 400);
                    break;
                }

                // Ensure loan fund tables exist
                $db->exec("CREATE TABLE IF NOT EXISTS loan_fund_accounts (
                    id SERIAL PRIMARY KEY,
                    account_number VARCHAR(30) NOT NULL UNIQUE,
                    account_name VARCHAR(200) NOT NULL,
                    fund_type VARCHAR(20) NOT NULL,
                    balance DECIMAL(20,2) DEFAULT 0,
                    currency VARCHAR(5) DEFAULT 'XAF',
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                $db->exec("CREATE TABLE IF NOT EXISTS loan_fund_transactions (
                    id SERIAL PRIMARY KEY,
                    ref VARCHAR(50),
                    loan_fund_account_id INT NOT NULL,
                    loan_id INT DEFAULT NULL,
                    transaction_ref VARCHAR(50) DEFAULT NULL,
                    date DATE NOT NULL,
                    type VARCHAR(20) NOT NULL,
                    description TEXT,
                    amount DECIMAL(20,2) NOT NULL,
                    balance_after DECIMAL(20,2) NOT NULL,
                    operator VARCHAR(200),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (loan_fund_account_id) REFERENCES loan_fund_accounts(id)
                )");

                // Seed BANK-LF-0001 if missing
                $lfCheck = $db->query("SELECT COUNT(*) AS c FROM loan_fund_accounts WHERE account_number = 'BANK-LF-0001'")->fetch();
                if ((int)$lfCheck['c'] === 0) {
                    $db->exec("INSERT INTO loan_fund_accounts (account_number, account_name, fund_type, balance, currency) VALUES ('BANK-LF-0001', 'Loans & Advances Fund', 'LOAN_FUND', 0, 'XAF')");
                }

                // Get current loan fund balance
                $lfStmt = $db->prepare("SELECT * FROM loan_fund_accounts WHERE account_number = 'BANK-LF-0001' LIMIT 1");
                $lfStmt->execute();
                $lfRow = $lfStmt->fetch(PDO::FETCH_ASSOC);
                if (!$lfRow) { errorResponse('Loan fund account BANK-LF-0001 not found.', 404); break; }
                $lfId = (int)$lfRow['id'];
                // ★ Pattern 1: Read balance from GL 1200 (source of truth), not stored cache
                $lfGLBal = $db->prepare("SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS fund_balance FROM general_ledger WHERE account_code = '1200'");
                $lfGLBal->execute();
                $lfBalance = (float)$lfGLBal->fetchColumn();

                // Generate reference
                $count = (int)$db->query("SELECT COUNT(*) FROM general_ledger")->fetchColumn();
                $ref = $reference ?: ('LFC-' . date('Y-m-d') . '-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT));

                $descSuffix = ' [Loan Fund Credit — DR 1200 Loans and Advances / CR ' . $sourceAccount . ' ' . $srcRow['name'] . ']';

                $db->beginTransaction();
                try {
                    $newLfBalance = $lfBalance + $amount;

                    // 1. GL Entry: Debit 1200 (Loans & Advances — ASSET increases as fund is allocated)
                    $db->prepare("INSERT INTO general_ledger (account_code, account_name, debit, credit, date, reference, description, branch, posted_by, transaction_type, contra_account) VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)")
                      ->execute(['1200', 'Loans and Advances', $amount, 0, $ref, $description . $descSuffix, $operatorBranch, $operatorId > 0 ? $operatorId : null, 'LOAN_FUND_CREDIT', $sourceAccount]);

                    // 2. GL Entry: Credit source account (money leaves the source)
                    $db->prepare("INSERT INTO general_ledger (account_code, account_name, debit, credit, date, reference, description, branch, posted_by, transaction_type, contra_account) VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)")
                      ->execute([$sourceAccount, $srcRow['name'], 0, $amount, $ref, $description . $descSuffix, $operatorBranch, $operatorId > 0 ? $operatorId : null, 'LOAN_FUND_CREDIT', '1200']);

                    // 3. Credit BANK-LF-0001 (loan fund balance increases)
                    // ★ FIX (GLA-010): Replaced broken ternary with a simple count query.
                    // Previous code created an unused PDOStatement via a buggy ternary expression.
                    $lfCountVal = (int)$db->query("SELECT COUNT(*) FROM loan_fund_transactions WHERE loan_fund_account_id = " . $lfId)->fetchColumn();
                    $lfRef = 'LF-' . str_replace('-', '', date('Y-m-d')) . '-' . str_pad($lfCountVal + 1, 3, '0', STR_PAD_LEFT);

                    $db->prepare("INSERT INTO loan_fund_transactions (ref, loan_fund_account_id, loan_id, transaction_ref, date, type, description, amount, balance_after, operator) VALUES (?, ?, NULL, ?, CURRENT_DATE, 'CREDIT', ?, ?, ?)")
                      ->execute([$lfRef, $lfId, $ref, 'Loan Fund Credit via GL — ' . $description . ' (Source: GL ' . $sourceAccount . ' ' . $srcRow['name'] . ')', $amount, $newLfBalance, $operatorName]);

                    // 4. Update loan fund account balance
                    $db->prepare("UPDATE loan_fund_accounts SET balance = ? WHERE id = ? AND balance = ?")
                      ->execute([$newLfBalance, $lfId, $lfBalance]);

                    $db->commit();
                } catch (PDOException $txErr) {
                    $db->rollBack();
                    serverErrorResponse('Loan fund credit failed — changes rolled back.');
                    break;
                }

                logAudit($staff['full_name'] ?? 'System', 'LOAN_FUND_CREDIT', 'LOAN_FUND_ACCOUNT', 'BANK-LF-0001', 'SUCCESS',
                    'Funded Loan & Advances by ' . number_format($amount, 2) . ' XAF from GL ' . $sourceAccount . ' (' . $srcRow['name'] . '). New balance: ' . number_format($newLfBalance, 2) . ' XAF',
                    $staff['department'] ?? '', getClientIp());

                successResponse([
                    'success' => true,
                    'action' => 'loan_fund_credit',
                    'source_account' => $sourceAccount,
                    'source_name' => $srcRow['name'],
                    'amount' => $amount,
                    'reference' => $ref,
                    'loan_fund_balance_before' => $lfBalance,
                    'loan_fund_balance_after' => $newLfBalance,
                    'gl_entries_recorded' => 2,
                    'fund_entries_recorded' => 1
                ]);
                break;
            }

            // ── LOAN FUND DEBIT (Disburse from fund pool → Loans Receivable) ──
            // Pattern 1: GL as Sole Source of Truth
            // CR 1200 (Loans & Advances Fund — pool decreases)
            // DR 1201 (Loans Receivable — asset increases)
            if ($action === 'LOAN_FUND_DEBIT') {
                $loanId = isset($input['loan_id']) ? (int)$input['loan_id'] : null;

                // ★ Check GL 1200 balance (THE source of truth)
                $fundBalStmt = $db->prepare("SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS fund_balance FROM general_ledger WHERE account_code = '1200'");
                $fundBalStmt->execute();
                $currentFundBalance = (float)$fundBalStmt->fetchColumn();

                if ($amount > $currentFundBalance) {
                    errorResponse('Insufficient loan fund balance. GL 1200 available: ' . number_format($currentFundBalance, 2) . ' XAF, attempted debit: ' . number_format($amount, 2) . ' XAF. Fund the pool via LOAN_FUND_CREDIT first.', 400);
                    break;
                }

                // Ensure 1201 exists in chart_of_accounts
                $chk1201 = $db->prepare("SELECT id FROM chart_of_accounts WHERE code = '1201'");
                $chk1201->execute();
                if (!$chk1201->fetch()) {
                    $db->exec("INSERT INTO chart_of_accounts (code, name, type, category, is_active) VALUES ('1201', 'Loans Receivable', 'ASSET', 'Current Assets', 1)");
                }

                // Ensure loan fund tables exist (for reporting artifact)
                $db->exec("CREATE TABLE IF NOT EXISTS loan_fund_accounts (
                    id SERIAL PRIMARY KEY, account_number VARCHAR(30) NOT NULL UNIQUE,
                    account_name VARCHAR(200) NOT NULL, fund_type VARCHAR(20) NOT NULL,
                    balance DECIMAL(20,2) DEFAULT 0, currency VARCHAR(5) DEFAULT 'XAF',
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                $db->exec("CREATE TABLE IF NOT EXISTS loan_fund_transactions (
                    id SERIAL PRIMARY KEY, ref VARCHAR(50), loan_fund_account_id INT NOT NULL,
                    loan_id INT DEFAULT NULL, transaction_ref VARCHAR(50) DEFAULT NULL, date DATE NOT NULL,
                    type VARCHAR(20) NOT NULL, description TEXT, amount DECIMAL(20,2) NOT NULL,
                    balance_after DECIMAL(20,2) NOT NULL, operator VARCHAR(200),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (loan_fund_account_id) REFERENCES loan_fund_accounts(id)
                )");

                // Seed BANK-LF-0001 if missing
                $lfCheck = $db->query("SELECT COUNT(*) AS c FROM loan_fund_accounts WHERE account_number = 'BANK-LF-0001'")->fetch();
                if ((int)$lfCheck['c'] === 0) {
                    $db->exec("INSERT INTO loan_fund_accounts (account_number, account_name, fund_type, balance, currency) VALUES ('BANK-LF-0001', 'Loans & Advances Fund', 'LOAN_FUND', 0, 'XAF')");
                }

                // Generate references
                $count = (int)$db->query("SELECT COUNT(*) FROM general_ledger")->fetchColumn();
                $ref = $reference ?: ('LFD-' . date('Y-m-d') . '-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT));

                $lfStmt = $db->prepare("SELECT * FROM loan_fund_accounts WHERE account_number = 'BANK-LF-0001' LIMIT 1");
                $lfStmt->execute();
                $lfRow = $lfStmt->fetch(PDO::FETCH_ASSOC);
                $lfId = (int)($lfRow['id'] ?? 0);

                $newFundBalance = $currentFundBalance - $amount;
                $descSuffix = ' [Loan Fund Debit — CR 1200 Loans and Advances / DR 1201 Loans Receivable]';

                $db->beginTransaction();
                try {
                    // GL Entry 1: Credit 1200 (Loans & Advances Fund decreases)
                    $db->prepare("INSERT INTO general_ledger (account_code, account_name, debit, credit, date, reference, description, branch, posted_by, transaction_type, contra_account) VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)")
                      ->execute(['1200', 'Loans and Advances', 0, $amount, $ref, $description . $descSuffix, $operatorBranch, $operatorId > 0 ? $operatorId : null, 'LOAN_FUND_DEBIT', '1201']);

                    // GL Entry 2: Debit 1201 (Loans Receivable increases)
                    $db->prepare("INSERT INTO general_ledger (account_code, account_name, debit, credit, date, reference, description, branch, posted_by, transaction_type, contra_account) VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)")
                      ->execute(['1201', 'Loans Receivable', $amount, 0, $ref, $description . $descSuffix, $operatorBranch, $operatorId > 0 ? $operatorId : null, 'LOAN_FUND_DEBIT', '1200']);

                    // Reporting artifact: loan_fund_transactions
                    if ($lfId > 0) {
                        $lfCount = (int)$db->query("SELECT COUNT(*) FROM loan_fund_transactions WHERE loan_fund_account_id = " . $lfId)->fetchColumn();
                        $lfRef = 'LF-' . str_replace('-', '', date('Y-m-d')) . '-' . str_pad($lfCount + 1, 3, '0', STR_PAD_LEFT);
                        $db->prepare("INSERT INTO loan_fund_transactions (ref, loan_fund_account_id, loan_id, transaction_ref, date, type, description, amount, balance_after, operator) VALUES (?, ?, ?, ?, CURRENT_DATE, 'DEBIT', ?, ?, ?)")
                          ->execute([$lfRef, $lfId, $loanId, $ref, 'Loan Fund Debit via GL — ' . $description . ' (GL: CR 1200 / DR 1201)', $amount, $newFundBalance, $operatorName]);
                        // Cache update (not authoritative — GL 1200 is)
                        $db->prepare("UPDATE loan_fund_accounts SET balance = ? WHERE id = ?")->execute([$newFundBalance, $lfId]);
                    }

                    $db->commit();
                } catch (PDOException $txErr) {
                    $db->rollBack();
                    serverErrorResponse('Loan fund debit failed — changes rolled back.');
                    break;
                }

                logAudit($staff['full_name'] ?? 'System', 'LOAN_FUND_DEBIT', 'GENERAL_LEDGER', $ref, 'SUCCESS',
                    'Loan Fund Debit: ' . number_format($amount, 2) . ' XAF. GL 1200: ' . number_format($currentFundBalance, 2) . ' → ' . number_format($newFundBalance, 2) . ' XAF. GL 1201 receivable created. Ref: ' . $ref,
                    $staff['department'] ?? '', getClientIp());

                successResponse([
                    'success' => true, 'action' => 'loan_fund_debit', 'amount' => $amount,
                    'reference' => $ref, 'gl_1200_balance_before' => $currentFundBalance,
                    'gl_1200_balance_after' => $newFundBalance, 'gl_entries_recorded' => 2
                ]);
                break;
            }

            // ── LOAN REPAYMENT PRINCIPAL (Repayment → fund recycles, receivable decreases) ──
            // Pattern 1: DR 1200 (fund increases) / CR 1201 (receivable decreases)
            if ($action === 'LOAN_REPAYMENT_PRINCIPAL') {
                $loanId = isset($input['loan_id']) ? (int)$input['loan_id'] : null;

                // Ensure loan fund tables exist
                $db->exec("CREATE TABLE IF NOT EXISTS loan_fund_accounts (
                    id SERIAL PRIMARY KEY, account_number VARCHAR(30) NOT NULL UNIQUE,
                    account_name VARCHAR(200) NOT NULL, fund_type VARCHAR(20) NOT NULL,
                    balance DECIMAL(20,2) DEFAULT 0, currency VARCHAR(5) DEFAULT 'XAF',
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                $db->exec("CREATE TABLE IF NOT EXISTS loan_fund_transactions (
                    id SERIAL PRIMARY KEY, ref VARCHAR(50), loan_fund_account_id INT NOT NULL,
                    loan_id INT DEFAULT NULL, transaction_ref VARCHAR(50) DEFAULT NULL, date DATE NOT NULL,
                    type VARCHAR(20) NOT NULL, description TEXT, amount DECIMAL(20,2) NOT NULL,
                    balance_after DECIMAL(20,2) NOT NULL, operator VARCHAR(200),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (loan_fund_account_id) REFERENCES loan_fund_accounts(id)
                )");

                $lfCheck = $db->query("SELECT COUNT(*) AS c FROM loan_fund_accounts WHERE account_number = 'BANK-LF-0001'")->fetch();
                if ((int)$lfCheck['c'] === 0) {
                    $db->exec("INSERT INTO loan_fund_accounts (account_number, account_name, fund_type, balance, currency) VALUES ('BANK-LF-0001', 'Loans & Advances Fund', 'LOAN_FUND', 0, 'XAF')");
                }

                // Compute current GL 1200 balance
                $fundBalStmt = $db->prepare("SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS fund_balance FROM general_ledger WHERE account_code = '1200'");
                $fundBalStmt->execute();
                $currentFundBalance = (float)$fundBalStmt->fetchColumn();

                $count = (int)$db->query("SELECT COUNT(*) FROM general_ledger")->fetchColumn();
                $ref = $reference ?: ('LRP-' . date('Y-m-d') . '-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT));

                $lfStmt = $db->prepare("SELECT * FROM loan_fund_accounts WHERE account_number = 'BANK-LF-0001' LIMIT 1");
                $lfStmt->execute();
                $lfRow = $lfStmt->fetch(PDO::FETCH_ASSOC);
                $lfId = (int)($lfRow['id'] ?? 0);

                $newFundBalance = $currentFundBalance + $amount;
                $descSuffix = ' [Loan Repayment Principal — DR 1200 Loans and Advances / CR 1201 Loans Receivable]';

                $db->beginTransaction();
                try {
                    // GL Entry 1: Debit 1200 (Loans & Advances Fund increases — money recycled)
                    $db->prepare("INSERT INTO general_ledger (account_code, account_name, debit, credit, date, reference, description, branch, posted_by, transaction_type, contra_account) VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)")
                      ->execute(['1200', 'Loans and Advances', $amount, 0, $ref, $description . $descSuffix, $operatorBranch, $operatorId > 0 ? $operatorId : null, 'LOAN_REPAYMENT_PRINCIPAL', '1201']);

                    // GL Entry 2: Credit 1201 (Loans Receivable decreases)
                    $db->prepare("INSERT INTO general_ledger (account_code, account_name, debit, credit, date, reference, description, branch, posted_by, transaction_type, contra_account) VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)")
                      ->execute(['1201', 'Loans Receivable', 0, $amount, $ref, $description . $descSuffix, $operatorBranch, $operatorId > 0 ? $operatorId : null, 'LOAN_REPAYMENT_PRINCIPAL', '1200']);

                    // Reporting artifact
                    if ($lfId > 0) {
                        $lfCount = (int)$db->query("SELECT COUNT(*) FROM loan_fund_transactions WHERE loan_fund_account_id = " . $lfId)->fetchColumn();
                        $lfRef = 'LF-' . str_replace('-', '', date('Y-m-d')) . '-' . str_pad($lfCount + 1, 3, '0', STR_PAD_LEFT);
                        $db->prepare("INSERT INTO loan_fund_transactions (ref, loan_fund_account_id, loan_id, transaction_ref, date, type, description, amount, balance_after, operator) VALUES (?, ?, ?, ?, CURRENT_DATE, 'CREDIT', ?, ?, ?)")
                          ->execute([$lfRef, $lfId, $loanId, $ref, 'Loan Repayment Principal via GL — ' . $description . ' (GL: DR 1200 / CR 1201)', $amount, $newFundBalance, $operatorName]);
                        $db->prepare("UPDATE loan_fund_accounts SET balance = ? WHERE id = ?")->execute([$newFundBalance, $lfId]);
                    }

                    $db->commit();
                } catch (PDOException $txErr) {
                    $db->rollBack();
                    serverErrorResponse('Loan repayment GL posting failed — changes rolled back.');
                    break;
                }

                logAudit($staff['full_name'] ?? 'System', 'LOAN_REPAYMENT_PRINCIPAL', 'GENERAL_LEDGER', $ref, 'SUCCESS',
                    'Loan Repayment Principal: ' . number_format($amount, 2) . ' XAF. GL 1200: ' . number_format($currentFundBalance, 2) . ' → ' . number_format($newFundBalance, 2) . ' XAF. Ref: ' . $ref,
                    $staff['department'] ?? '', getClientIp());

                successResponse([
                    'success' => true, 'action' => 'loan_repayment_principal', 'amount' => $amount,
                    'reference' => $ref, 'gl_1200_balance_before' => $currentFundBalance,
                    'gl_1200_balance_after' => $newFundBalance, 'gl_entries_recorded' => 2
                ]);
                break;
            }

            // ── LOAN WRITEOFF (Remove receivable → record loss) ──
            // Pattern 1: CR 1201 (Loans Receivable decreases) / DR 5900 (loss expense)
            if ($action === 'LOAN_WRITEOFF') {
                $loanId = isset($input['loan_id']) ? (int)$input['loan_id'] : null;

                // Ensure 5900 exists
                $chk5900 = $db->prepare("SELECT id FROM chart_of_accounts WHERE code = '5900'");
                $chk5900->execute();
                if (!$chk5900->fetch()) {
                    $db->exec("INSERT INTO chart_of_accounts (code, name, type, category, is_active) VALUES ('5900', 'Miscellaneous Expense', 'EXPENSE', 'Operating Expenses', 1)");
                }

                // Ensure loan fund tables exist
                $db->exec("CREATE TABLE IF NOT EXISTS loan_fund_accounts (
                    id SERIAL PRIMARY KEY, account_number VARCHAR(30) NOT NULL UNIQUE,
                    account_name VARCHAR(200) NOT NULL, fund_type VARCHAR(20) NOT NULL,
                    balance DECIMAL(20,2) DEFAULT 0, currency VARCHAR(5) DEFAULT 'XAF',
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                $db->exec("CREATE TABLE IF NOT EXISTS loan_fund_transactions (
                    id SERIAL PRIMARY KEY, ref VARCHAR(50), loan_fund_account_id INT NOT NULL,
                    loan_id INT DEFAULT NULL, transaction_ref VARCHAR(50) DEFAULT NULL, date DATE NOT NULL,
                    type VARCHAR(20) NOT NULL, description TEXT, amount DECIMAL(20,2) NOT NULL,
                    balance_after DECIMAL(20,2) NOT NULL, operator VARCHAR(200),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (loan_fund_account_id) REFERENCES loan_fund_accounts(id)
                )");

                $lfCheck = $db->query("SELECT COUNT(*) AS c FROM loan_fund_accounts WHERE account_number = 'BANK-LF-0001'")->fetch();
                if ((int)$lfCheck['c'] === 0) {
                    $db->exec("INSERT INTO loan_fund_accounts (account_number, account_name, fund_type, balance, currency) VALUES ('BANK-LF-0001', 'Loans & Advances Fund', 'LOAN_FUND', 0, 'XAF')");
                }

                // Compute GL 1200 balance (unchanged by write-off, but needed for reporting)
                $fundBalStmt = $db->prepare("SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS fund_balance FROM general_ledger WHERE account_code = '1200'");
                $fundBalStmt->execute();
                $currentFundBalance = (float)$fundBalStmt->fetchColumn();

                $count = (int)$db->query("SELECT COUNT(*) FROM general_ledger")->fetchColumn();
                $ref = $reference ?: ('LWO-' . date('Y-m-d') . '-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT));

                $lfStmt = $db->prepare("SELECT * FROM loan_fund_accounts WHERE account_number = 'BANK-LF-0001' LIMIT 1");
                $lfStmt->execute();
                $lfRow = $lfStmt->fetch(PDO::FETCH_ASSOC);
                $lfId = (int)($lfRow['id'] ?? 0);

                $descSuffix = ' [Loan Write-Off — CR 1201 Loans Receivable / DR 5900 Miscellaneous Expense]';

                $db->beginTransaction();
                try {
                    // GL Entry 1: Credit 1201 (Loans Receivable — removing bad debt)
                    $db->prepare("INSERT INTO general_ledger (account_code, account_name, debit, credit, date, reference, description, branch, posted_by, transaction_type, contra_account) VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)")
                      ->execute(['1201', 'Loans Receivable', 0, $amount, $ref, $description . $descSuffix, $operatorBranch, $operatorId > 0 ? $operatorId : null, 'LOAN_WRITEOFF', '5900']);

                    // GL Entry 2: Debit 5900 (Miscellaneous Expense — recording the loss)
                    $db->prepare("INSERT INTO general_ledger (account_code, account_name, debit, credit, date, reference, description, branch, posted_by, transaction_type, contra_account) VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)")
                      ->execute(['5900', 'Miscellaneous Expense', $amount, 0, $ref, $description . $descSuffix, $operatorBranch, $operatorId > 0 ? $operatorId : null, 'LOAN_WRITEOFF', '1201']);

                    // Reporting artifact (fund pool is NOT affected by write-off)
                    if ($lfId > 0) {
                        $lfCount = (int)$db->query("SELECT COUNT(*) FROM loan_fund_transactions WHERE loan_fund_account_id = " . $lfId)->fetchColumn();
                        $lfRef = 'LF-' . str_replace('-', '', date('Y-m-d')) . '-' . str_pad($lfCount + 1, 3, '0', STR_PAD_LEFT);
                        $db->prepare("INSERT INTO loan_fund_transactions (ref, loan_fund_account_id, loan_id, transaction_ref, date, type, description, amount, balance_after, operator) VALUES (?, ?, ?, ?, CURRENT_DATE, 'DEBIT', ?, ?, ?)")
                          ->execute([$lfRef, $lfId, $loanId, $ref, 'Loan Write-Off via GL — ' . $description . ' (GL: CR 1201 / DR 5900 — fund pool unchanged)', $amount, $currentFundBalance, $operatorName]);
                    }

                    $db->commit();
                } catch (PDOException $txErr) {
                    $db->rollBack();
                    serverErrorResponse('Loan write-off GL posting failed — changes rolled back.');
                    break;
                }

                logAudit($staff['full_name'] ?? 'System', 'LOAN_WRITEOFF', 'GENERAL_LEDGER', $ref, 'SUCCESS',
                    'Loan Write-Off: ' . number_format($amount, 2) . ' XAF written off. GL 1201 receivable removed, GL 5900 loss recorded. GL 1200 fund pool unchanged: ' . number_format($currentFundBalance, 2) . ' XAF. Ref: ' . $ref,
                    $staff['department'] ?? '', getClientIp());

                successResponse([
                    'success' => true, 'action' => 'loan_writeoff', 'amount' => $amount,
                    'reference' => $ref, 'gl_1200_fund_balance' => $currentFundBalance,
                    'gl_entries_recorded' => 2, 'note' => 'GL 1200 fund pool unchanged — write-off affects receivable (1201) and expense (5900) only'
                ]);
                break;
            }

            // ── JOURNAL ENTRY (GL Account → GL Account) ────────────────────
            if ($action === 'JOURNAL_ENTRY' || $action === '') {
            if (!$debitAccount || !$creditAccount) {
                errorResponse('journal_entry requires: debit_account, credit_account, amount.', 400);
                break; // exits the switch case
            }
            if ($debitAccount === $creditAccount) {
                errorResponse('Debit and credit accounts must be different.', 400);
                break;
            }

            // Validate both accounts exist
            $dStmt = $db->prepare("SELECT * FROM chart_of_accounts WHERE code = ? AND is_active = TRUE");
            $dStmt->execute([$debitAccount]);
            $debitRow = $dStmt->fetch(PDO::FETCH_ASSOC);
            if (!$debitRow) { errorResponse('Debit GL account ' . $debitAccount . ' not found or inactive.', 404); break; }

            $cStmt = $db->prepare("SELECT * FROM chart_of_accounts WHERE code = ? AND is_active = TRUE");
            $cStmt->execute([$creditAccount]);
            $creditRow = $cStmt->fetch(PDO::FETCH_ASSOC);
            if (!$creditRow) { errorResponse('Credit GL account ' . $creditAccount . ' not found or inactive.', 404); break; }

            // ★ CORRECT NEGATIVE BALANCE GUARD:
            // In double-entry bookkeeping, the balance check depends on whether the
            // entry INCREASES or DECREASES the account's normal balance:
            //   DEBIT  an ASSET/EXPENSE  → balance INCREASES  → no check needed
            //   DEBIT  a LIABILITY/EQUITY/INCOME → balance DECREASES → check needed
            //   CREDIT an ASSET/EXPENSE  → balance DECREASES  → check needed
            //   CREDIT a LIABILITY/EQUITY/INCOME → balance INCREASES → no check needed
            $balStmt = $db->prepare("SELECT SUM(debit) AS total_debit, SUM(credit) AS total_credit FROM general_ledger WHERE account_code = ?");
            // Check DEBIT account — only if debiting DECREASES its normal balance
            $debitDecreases = in_array($debitRow['type'], ['LIABILITY', 'EQUITY', 'INCOME']);
            if ($debitDecreases) {
                $balStmt->execute([$debitAccount]);
                $dBal = $balStmt->fetch(PDO::FETCH_ASSOC);
                $dNet = (float)($dBal['total_credit'] ?? 0) - (float)($dBal['total_debit'] ?? 0);
                if ($dNet < $amount) {
                    errorResponse('Insufficient GL balance in debit account ' . $debitAccount . ' (' . $debitRow['name'] . '). ' .
                        'This is a ' . $debitRow['type'] . ' account (normal credit balance). ' .
                        'Available: ' . number_format($dNet, 2) . ' XAF, attempted debit: ' . number_format($amount, 2) . ' XAF. Negative balances are not permitted.', 400);
                    break;
                }
            }
            // Check CREDIT account — only if crediting DECREASES its normal balance
            $creditDecreases = in_array($creditRow['type'], ['ASSET', 'EXPENSE']);
            if ($creditDecreases) {
                $balStmt->execute([$creditAccount]);
                $cBal = $balStmt->fetch(PDO::FETCH_ASSOC);
                $cNet = (float)($cBal['total_debit'] ?? 0) - (float)($cBal['total_credit'] ?? 0);
                if ($cNet < $amount) {
                    errorResponse('Insufficient GL balance in credit account ' . $creditAccount . ' (' . $creditRow['name'] . '). ' .
                        'This is an ' . $creditRow['type'] . ' account (normal debit balance). ' .
                        'Available: ' . number_format($cNet, 2) . ' XAF, attempted credit: ' . number_format($amount, 2) . ' XAF. Negative balances are not permitted.', 400);
                    break;
                }
            }

            // Generate reference
            $count = (int)$db->query("SELECT COUNT(*) FROM general_ledger")->fetchColumn();
            $ref = $reference ?: ('JE-' . date('Y-m-d') . '-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT));

            // ★ IDEMPOTENCY CHECK: Prevent duplicate GL entries for the same reference
            $dupCheck = $db->prepare("SELECT COUNT(*) AS dup FROM general_ledger WHERE reference = ? AND transaction_type = 'JOURNAL_ENTRY'");
            $dupCheck->execute([$ref]);
            if ((int)$dupCheck->fetch()['dup'] > 0) {
                errorResponse('Duplicate journal entry detected. Reference ' . $ref . ' already exists with JOURNAL_ENTRY type. Entries were NOT duplicated.', 409);
                break;
            }

            // Paired description for journal entry
            $jeDesc = $description . ' [Journal Entry — DR ' . $debitAccount . ' / CR ' . $creditAccount . ']';

            // ★ Derive branch from the logged-in staff member
            $jeBranch = $staff['branch'] ?? ($staff['department'] ?? '');

            // Wrap journal entry in transaction for atomicity
            $db->beginTransaction();
            try {
                // Insert debit entry — ★ now includes branch column
                $db->prepare("INSERT INTO general_ledger (account_code, account_name, debit, credit, date, reference, description, branch, posted_by, transaction_type, contra_account) VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)")
                  ->execute([$debitAccount, $debitRow['name'], $amount, 0, $ref, $jeDesc, $jeBranch, $operatorId > 0 ? $operatorId : null, 'JOURNAL_ENTRY', $creditAccount]);

                // Insert credit entry — ★ now includes branch column
                $db->prepare("INSERT INTO general_ledger (account_code, account_name, debit, credit, date, reference, description, branch, posted_by, transaction_type, contra_account) VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)")
                  ->execute([$creditAccount, $creditRow['name'], 0, $amount, $ref, $jeDesc, $jeBranch, $operatorId > 0 ? $operatorId : null, 'JOURNAL_ENTRY', $debitAccount]);

                // ★ AUTO-SYNC: If debit account is 1200 (Loans and Advances), auto-credit BANK-LF-0001
                // This means any journal entry that puts money into GL 1200 also funds the loan pool.
                if ($debitAccount === '1200') {
                    $lfAuto = $db->prepare("SELECT * FROM loan_fund_accounts WHERE account_number = 'BANK-LF-0001' LIMIT 1 FOR UPDATE");
                    $lfAuto->execute();
                    $lfAutoRow = $lfAuto->fetch(PDO::FETCH_ASSOC);
                    if ($lfAutoRow) {
                        $lfAutoId = (int)$lfAutoRow['id'];
                        // ★ BUG FIX: Query GL 1200 balance debit insert.
                        // Since we're in the same transaction, the debit we just inserted
                        // is already visible. So $lfAutoBal ALREADY includes this $amount.
                        // We must NOT add $amount again — that would double-count.
                        $lfAutoGL = $db->prepare("SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS fund_balance FROM general_ledger WHERE account_code = '1200'");
                        $lfAutoGL->execute();
                        $lfAutoNew = (float)$lfAutoGL->fetchColumn();

                        $lfAutoCount = (int)$db->query("SELECT COUNT(*) FROM loan_fund_transactions WHERE loan_fund_account_id = " . $lfAutoId)->fetchColumn();
                        $lfAutoRef = 'LF-' . str_replace('-', '', date('Y-m-d')) . '-' . str_pad($lfAutoCount + 1, 3, '0', STR_PAD_LEFT);

                        $db->prepare("INSERT INTO loan_fund_transactions (ref, loan_fund_account_id, loan_id, transaction_ref, date, type, description, amount, balance_after, operator) VALUES (?, ?, NULL, ?, CURRENT_DATE, 'CREDIT', ?, ?, ?)")
                          ->execute([$lfAutoRef, $lfAutoId, $ref, 'Auto-funded via Journal Entry — ' . $description . ' (JE DR 1200 / CR ' . $creditAccount . ')', $amount, $lfAutoNew, $operatorName]);

                        $db->prepare("UPDATE loan_fund_accounts SET balance = ? WHERE id = ?")
                          ->execute([$lfAutoNew, $lfAutoId]);

                        logAudit($operatorName, 'LOAN_FUND_AUTO_CREDIT', 'LOAN_FUND_ACCOUNT', 'BANK-LF-0001', 'SUCCESS',
                            'Auto-funded Loan & Advances by ' . number_format($amount, 2) . ' XAF via Journal Entry (DR 1200 / CR ' . $creditAccount . '). Ref: ' . $ref,
                            $staff['department'] ?? '', getClientIp());
                    }
                }

                $db->commit();
            } catch (PDOException $txErr) {
                $db->rollBack();
                serverErrorResponse('Journal entry failed — changes rolled back.');
                break;
            }

            logAudit($staff['full_name'] ?? 'System', 'GL_JOURNAL_ENTRY', 'GENERAL_LEDGER', $ref, 'SUCCESS',
                'Journal Entry: DR ' . $debitAccount . ' (' . $debitRow['name'] . ') / CR ' . $creditAccount . ' (' . $creditRow['name'] . ') Amount: ' . number_format($amount, 2) . ' XAF',
                $staff['department'] ?? '', getClientIp());
            error_log('[General Ledger] Journal Entry | Ref: ' . $ref . ' | Debit: ' . $debitAccount . ' | Credit: ' . $creditAccount . ' | Amount: ' . number_format($amount));
            successResponse([
                'success' => true,
                'action' => 'journal_entry',
                'debit_account' => $debitAccount,
                'debit_name' => $debitRow['name'],
                'credit_account' => $creditAccount,
                'credit_name' => $creditRow['name'],
                'amount' => $amount,
                'reference' => $ref,
                'entries_recorded' => 2
            ]);
            } else {
                // ★ Unknown action — explicit error instead of silent fallthrough
                errorResponse('Unknown action: ' . $action . '. Supported: FUND_TRANSFER, LOAN_FUND_CREDIT, LOAN_FUND_DEBIT, LOAN_REPAYMENT_PRINCIPAL, LOAN_WRITEOFF, JOURNAL_ENTRY.', 400);
            }

        } catch (PDOException $e) {
            error_log('[General Ledger API] POST Error: ' . $e->getMessage());
            errorResponse('Database error.', 500);
        }
        break;

    default:
        errorResponse('Method not allowed.', 405);
}
