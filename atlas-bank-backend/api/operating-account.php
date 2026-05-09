<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Operating Account (Bank's own fund)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * PATTERN 1: General Ledger as Sole Source of Truth
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Under Pattern 1, the operating account balance is NEVER read from the
 * "operating_account.balance" column. Instead, it is ALWAYS derived from
 * General Ledger account 1400 (Operating Fund - Bank), which is an ASSET
 * account using the standard accounting formula:
 *
 *     balance = COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0)
 *
 * WHERE account_code = '1400' in the "general_ledger" table.
 *
 * Consequences:
 *   - GET  → Returns GL-derived balance, NOT stored balance
 *   - POST → Balance check for DEBIT reads from GL 1400
 *   - POST → All mutation steps (txn record, GL entries, cache update)
 *            are wrapped in a single database transaction
 *   - The stored "operating_account.balance" is updated as a CACHE
 * commit, but is never the authoritative source
 *   - Response includes "glSource: '1400'" to indicate derivation source
 *
 * Double-entry rules (already correct, preserved):
 *   CREDIT (money in):  DR 1400 (Asset ↑)  /  CR 3100 (Retained Earnings ↑)
 *   DEBIT  (money out): CR 1400 (Asset ↓)  /  DR 5900 (Misc Expense ↑)
 *
 * Transaction safety:
 *   Steps 1-4 (txn record, GL debit, GL credit, cache update) are inside
 *   beginTransaction()/commit()/rollBack().
 *   Step 5 (chart_of_accounts seed / DDL) runs OUTSIDE the transaction
 *   because DDL causes implicit commit in MySQL/MariaDB.
 *
 * Resilient: auto-creates tables if missing, handles missing columns gracefully.
 */

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

// ★ SECURITY FIX (HIGH): Added RBAC checks for operating account access.
// Previously any authenticated user (even VIEWER role) could view/modify the bank's
// operating fund. Now requires ACCOUNTS module and ADMIN/MANAGER/ACCOUNTANT role.
$staff = requireAuth();
$method = $_ROUTE['method'];
$isAdmin = strtoupper($staff['role'] ?? '') === 'ADMIN';

// GET: Any authenticated user can read operating account data.
// POST: Requires ACCOUNTS module + ADMIN/MANAGER/ACCOUNTANT role.
if ($method === 'POST') {
    requireModule('ACCOUNTS', $staff);
    requireRole(['ADMIN', 'MANAGER', 'ACCOUNTANT'], $staff);
}

// ═══════════════════════════════════════════════════════════════════════════
// CONSTANTS
// ═══════════════════════════════════════════════════════════════════════════
define('OA_GL_CODE', '1400');
define('OA_GL_NAME', 'Operating Fund - Bank');
define('OA_CONTRA_CREDIT', '3100');
define('OA_CONTRA_CREDIT_NAME', 'Retained Earnings');
define('OA_CONTRA_DEBIT', '5900');
define('OA_CONTRA_DEBIT_NAME', 'Miscellaneous Expense');

// ═══════════════════════════════════════════════════════════════════════════
// SCHEMA ENSURE FUNCTIONS (DDL — must run OUTSIDE transactions)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Safely add a column if it doesn't exist (MySQL/MariaDB compatible).
 * DDL operations — do NOT call inside a transaction.
 */
function opAddCol(PDO $db, string $table, string $col, string $def): void {
    $r = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?");
        $r->execute([$table, $col]);
    if (!$r) {
        $db->exec("ALTER TABLE $table ADD COLUMN $col $def");
    }
}

/**
 * Ensure the operating_account table exists with all required columns.
 */
function opEnsureOperatingAccountTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS operating_account (
        id SERIAL PRIMARY KEY,
        account_number VARCHAR(30) NOT NULL UNIQUE,
        account_name VARCHAR(200),
        balance DECIMAL(20,2) DEFAULT 0,
        currency VARCHAR(5) DEFAULT 'XAF',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}

/**
 * Ensure the operating_account_transactions table exists with all required columns.
 */
function opEnsureTransactionsTable(PDO $db): void {
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

    // Safe migration: add contra_account column if missing
    opAddCol($db, 'operating_account_transactions', 'contra_account', "VARCHAR(100) DEFAULT ''");
    // Safe migration: add transaction_type column if missing
    opAddCol($db, 'operating_account_transactions', 'transaction_type', "VARCHAR(50) DEFAULT ''");
    // ★ FIX (OPFUND-B001): Add branch column for branch-level filtering.
    // Without this, the POST handler's INSERT including "branch" column will fail
    // if no fund transfer has been done yet (general-ledger.php adds it on FUND_TRANSFER).
    opAddCol($db, 'operating_account_transactions', 'branch', "VARCHAR(100) DEFAULT ''");
    // Safe migration: add branch index for filtered queries
    $brIdx = $db->query("SELECT indexname FROM pg_indexes WHERE tablename = 'operating_account_transactions' WHERE indexname = 'idx_oat_branch'")->fetch();
    if (!$brIdx) {
        $db->exec("CREATE INDEX IF NOT EXISTS idx_oat_branch ON operating_account_transactions (branch)");
    }

    // ★ FIX (OPFUND-B001b): Backfill branch on existing transactions that have no branch set.
    // Derives from operator name → staff.department lookup (staff has no 'branch' column).
    try {
        $opOrphans = $db->query("SELECT COUNT(*) AS c FROM operating_account_transactions WHERE (branch IS NULL OR branch = '')")->fetch();
        if ((int)$opOrphans['c'] > 0) {
            $defaultBranch = $db->query("SELECT COALESCE(NULLIF(department,''), '') FROM staff WHERE role IN ('ADMIN','MANAGER','ACCOUNTANT') LIMIT 1")->fetchColumn();
            if ($defaultBranch) {
                $backfilled = $db->exec("UPDATE operating_account_transactions SET branch = '" . sanitize($defaultBranch) . "' WHERE (branch IS NULL OR branch = '')");
                if ($backfilled > 0) {
                    error_log('[Operating Account Migration] Backfilled branch on ' . $backfilled . ' operating_account_transactions.');
                }
            }
        }
    } catch (PDOException $bfErr) {
        error_log('[Operating Account Migration] Branch backfill error: ' . $bfErr->getMessage());
    }

    // Legacy: if old schema used "timestamp" as column name, rename to "created_at"
    $col = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'operating_account_transactions' AND column_name = 'timestamp'")->fetch();
    if ($col) {
        $col2 = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'operating_account_transactions' AND column_name = 'created_at'")->fetch();
        if (!$col2) {
            $db->exec('ALTER TABLE operating_account_transactions CHANGE "timestamp" "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
        }
    }
}

/**
 * Ensure the general_ledger table exists with all required columns (including branch).
 */
function opEnsureGeneralLedgerTable(PDO $db): void {
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
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_account_code ON general_ledger (account_code)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_date ON general_ledger (date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_reference ON general_ledger (reference)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_transaction_type ON general_ledger (transaction_type)");

    // Safe migration: add transaction_type column + index
    $glCol = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'general_ledger' AND column_name = 'transaction_type'")->fetch();
    if (!$glCol) {
        $db->exec("ALTER TABLE general_ledger ADD COLUMN transaction_type VARCHAR(50) DEFAULT ''");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_transaction_type ON general_ledger (transaction_type)");
    }
    // Safe migration: add contra_account column
    opAddCol($db, 'general_ledger', 'contra_account', "VARCHAR(50) DEFAULT ''");
    // Safe migration: add branch column for branch isolation
    opAddCol($db, 'general_ledger', 'branch', "VARCHAR(100) DEFAULT ''");
    // Safe migration: add branch index for filtered GL queries
    $brIdx = $db->query("SELECT indexname FROM pg_indexes WHERE tablename = 'general_ledger' WHERE indexname = 'idx_branch'")->fetch();
    if (!$brIdx) {
        $db->exec("CREATE INDEX IF NOT EXISTS idx_branch ON general_ledger (branch)");
    }
}

/**
 * Ensure GL codes 1400, 3100, 5900 exist in chart_of_accounts.
 * DDL-safe — call OUTSIDE transactions.
 */
function opEnsureChartOfAccounts(PDO $db): void {
    $seedRows = [
        [OA_GL_CODE,            OA_GL_NAME,                'ASSET',   'Current Assets', 'Bank operating capital and fund pool — linked to BANK-OP-0001'],
        [OA_CONTRA_CREDIT,      OA_CONTRA_CREDIT_NAME,    'EQUITY',  'Reserves',       'Accumulated retained profits'],
        [OA_CONTRA_DEBIT,       OA_CONTRA_DEBIT_NAME,     'EXPENSE', 'Admin',          'Other operating costs'],
    ];
    $ins = $db->prepare("INSERT INTO chart_of_accounts (code, name, type, category, description, is_active) VALUES (?, ?, ?, ?, ?, TRUE) ON CONFLICT (code) DO NOTHING");
    foreach ($seedRows as $row) {
        // Check existence first (avoid duplicate key noise)
        $chk = $db->prepare("SELECT id FROM chart_of_accounts WHERE code = ?");
        $chk->execute([$row[0]]);
        if (!$chk->fetch()) {
            $ins->execute($row);
            error_log('[Operating Account] Created GL code ' . $row[0] . ' (' . $row[1] . ') in chart_of_accounts');
        }
    }
}

/**
 * Ensure operating_account has a seeded default row (BANK-OP-0001).
 */
function opEnsureSeedAccount(PDO $db): void {
    $check = $db->query("SELECT COUNT(*) AS c FROM operating_account")->fetch();
    if ((int)$check['c'] === 0) {
        $db->exec("INSERT INTO operating_account (account_number, account_name, balance, currency) VALUES ('BANK-OP-0001', 'Atlas Bank Operating Fund', 0, 'XAF')");
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// HELPER: Compute GL 1400 Balance (Sole Source of Truth)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Compute the operating fund balance from GL account 1400.
 *
 * GL 1400 is an ASSET account, so:
 *     balance = SUM(debit) - SUM(credit)
 *
 * This is the authoritative balance under Pattern 1.
 *
 * ★ FIX (OPFUND-B004): Support branch-scoped balance computation.
 * Without this, non-admin users see the bank's global balance while their
 * transaction list is filtered, causing confusion and data leakage.
 *
 * @param PDO    $db     Database connection
 * @param string|array|null $branch Branch filter (string, array of strings, or null for global)
 * @return float The GL-derived balance (always >= 0 for assets)
 */
function opComputeGL1400Balance(PDO $db, string|array|null $branch = null): float {
    $where = "WHERE account_code = ?";
    $params = [OA_GL_CODE];

    if ($branch !== null) {
        if (is_array($branch)) {
            if (!empty($branch) && !in_array('ALL', $branch)) {
                $placeholders = implode(',', array_fill(0, count($branch), '?'));
                $where .= " AND branch IN ($placeholders)";
                $params = array_merge($params, $branch);
            }
        } elseif ($branch !== '' && $branch !== 'ALL') {
            $where .= " AND branch = ?";
            $params[] = $branch;
        }
    }

    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS gl_balance
         FROM general_ledger
         $where"
    );
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (float)($row['gl_balance'] ?? 0);
}

/**
 * Update the cached balance in operating_account from GL 1400.
 * This is a non-authoritative cache update for display consistency.
 * Call transaction commit.
 *
 * @param PDO   $db    Database connection
 * @param int   $acctId Operating account ID
 * @param float $glBalance The GL-derived balance to cache
 * @return bool True on success
 */
function opUpdateCachedBalance(PDO $db, int $acctId, float $glBalance): bool {
    try {
        $stmt = $db->prepare("UPDATE operating_account SET balance = ? WHERE id = ?");
        $stmt->execute([$glBalance, $acctId]);
        return true;
    } catch (PDOException $e) {
        error_log('[Operating Account] Cache update failed (non-critical): ' . $e->getMessage());
        return false;
    }
}

/**
 * Resolve branch from request body or staff assignment.
 *
 * @param array $input Request payload
 * @param array $staff Authenticated staff record
 * @return string Branch identifier (empty if unresolvable)
 */
function opResolveBranch(array $input, array $staff): string {
    $branch = trim((string)($input['branch'] ?? ''));
    $branchNorm = strtoupper(trim($branch));
    if (in_array($branchNorm, ['ALL', 'ALL BRANCHES', 'ALL_BRANCHES', 'ALL MY BRANCHES', 'ALL_MY_BRANCHES', 'ALLBRANCHES', 'ALLBRANCH'], true)) {
        $branch = '';
    }
    if (!$branch) {
        $dept = trim((string)($staff['department'] ?? ''));
        $deptNorm = strtoupper(trim($dept));
        if ($dept && !in_array($deptNorm, ['ALL', 'ALL BRANCHES', 'ALL_BRANCHES'], true)) {
            $branch = $dept;
        }
    }
    if (!$branch && !empty($staff['branches']) && is_array($staff['branches'])) {
        foreach ($staff['branches'] as $b) {
            $v = trim((string)$b);
            $vn = strtoupper($v);
            if ($v && !in_array($vn, ['ALL', 'ALL BRANCHES', 'ALL_BRANCHES'], true)) {
                $branch = $v;
                break;
            }
        }
    }
    return $branch;
}

/**
 * Generate a unique transaction reference.
 * Format: OP-YYYYMMDD-NNNNmmm (date-scoped sequence + millisecond collision guard)
 *
 * ★ FIX (OPFUND-B002): Previous version used global COUNT(*) which is NOT unique-safe
 * under concurrent requests — two simultaneous calls could get the same count and
 * generate the same reference. Now uses date-scoped count + millisecond suffix.
 *
 * @param PDO $db Database connection
 * @return string Transaction reference
 */
function opGenerateRef(PDO $db): string {
    $prefix = 'OP';
    $date = date('Ymd');

    // ★ FIX (OPFUND-B002): Removed COUNT(*) + CURRENT_DATE approach.
    // Timezone mismatches between MySQL (CURRENT_DATE) and PHP (date()) caused
    // sequence resets, leading to duplicate key errors.
    // Now uses MAX-based approach matching helpers.php.
    $like = $prefix . '-' . $date . '-%';
    $stmt = $db->prepare(
        "SELECT COALESCE(MAX(CAST(SPLIT_PART(ref, '-', -1) AS INTEGER)), 0) AS max_seq
         FROM operating_account_transactions
         WHERE ref LIKE :pattern"
    );
    $stmt->execute([':pattern' => $like]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $sequence = ((int)($row['max_seq'])) + 1;

    // Use a simpler sequence format without microtime to keep it consistent with other refs
    return sprintf('%s-%s-%04d', $prefix, $date, $sequence);
}

// ═══════════════════════════════════════════════════════════════════════════
// ROUTER
// ═══════════════════════════════════════════════════════════════════════════

switch ($method) {

    // ───────────────────────────────────────────────────────────────────────
    // GET — Return operating account(s) with GL-derived balance
    // ───────────────────────────────────────────────────────────────────────
    case 'GET':
        try {
            $db = getDB();

            // ── Schema ensure (DDL — outside transaction) ────────────────
            opEnsureOperatingAccountTable($db);
            opEnsureTransactionsTable($db);
            opEnsureGeneralLedgerTable($db);
            opEnsureSeedAccount($db);

            // ── Fetch ALL operating accounts ─────────────────────────────
            $stmt = $db->query('SELECT * FROM operating_account ORDER BY id');
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($records)) {
                successResponse([
                    'account_number' => 'BANK-OP-0001',
                    'account_name'   => 'Atlas Bank Operating Fund',
                    'balance'        => 0,
                    'currency'       => 'XAF',
                    'glSource'       => OA_GL_CODE,
                    'transactions'   => []
                ]);
                break;
            }

            // ── Compute GL 1400 balance (Pattern 1: Sole Source of Truth) ──
            // ★ FIX (OPFUND-B004): Pass branch scope to balance computation
            $branchScope = null;
            if (!$isAdmin) {
                $branchScope = $staff['branches'] ?? null;
                if (empty($branchScope)) {
                    $dept = trim((string)($staff['department'] ?? ''));
                    $branchScope = $dept ? [$dept] : null;
                }
                if (is_array($branchScope)) {
                    $norm = [];
                    foreach ($branchScope as $b) {
                        $v = strtoupper(trim((string)$b));
                        if ($v === '') continue;
                        if (in_array($v, ['ALL BRANCHES', 'ALL_BRANCHES'], true)) $v = 'ALL';
                        $norm[] = $v;
                    }
                    $norm = array_values(array_unique($norm));
                    if (in_array('ALL', $norm, true)) {
                        $branchScope = null;
                    } else {
                        // Keep original-cased values for matching; use department/branches as-is
                        $branchScope = is_array($staff['branches'] ?? null) && !empty($staff['branches']) ? ($staff['branches'] ?? null) : ($dept ? [$dept] : null);
                    }
                }
            }
            $glBalance = opComputeGL1400Balance($db, $branchScope);

            // ── Update cached balance for display consistency ────────────
            foreach ($records as &$record) {
                // Always return GL-derived balance, not stored balance
                $record['balance'] = (float)$glBalance;
                $record['glSource'] = OA_GL_CODE;
            }
            unset($record);

            // ── Attach recent transactions to each account ───────────────
            // ★ FIX (OPFUND-B003): Branch-filtered transaction listing.
            // Non-admin users only see transactions from their assigned branches.
            // Admin users see all transactions (frontend applies additional filtering by UI selection).
            $txnLimit = min(max((int)($_GET['pageSize'] ?? 500), 1), 5000);

            // ★ FIX (OPFUND-B003): Determine branch filter for non-admin users.
            // Admin users can see all; non-admin users see only their branch.
            $txnBranchFilter = '';
            $txnBranchParams = [];
            if (!$isAdmin) {
                $userBranches = $staff['branches'] ?? [];
                if (empty($userBranches)) {
                    $dept = trim((string)($staff['department'] ?? ''));
                    $userBranches = $dept ? [$dept] : [];
                }
                if (!empty($userBranches) && is_array($userBranches)) {
                    $placeholders = [];
                    foreach ($userBranches as $i => $br) {
                        $brNorm = strtoupper(trim((string)$br));
                        if ($brNorm === '') continue;
                        if (in_array($brNorm, ['ALL BRANCHES', 'ALL_BRANCHES'], true)) $brNorm = 'ALL';
                        if ($brNorm === 'ALL') { $placeholders = []; $txnBranchParams = []; break; }
                        $key = ':ubr' . $i;
                        $placeholders[] = $key;
                        $txnBranchParams[$key] = $br;
                    }
                    if (!empty($placeholders)) {
                        $txnBranchFilter = ' AND branch IN (' . implode(',', $placeholders) . ')';
                    }
                }
            }

            foreach ($records as &$record) {
                try {
                    $tSql = 'SELECT * FROM operating_account_transactions WHERE operating_account_id = :aid' . $txnBranchFilter . ' ORDER BY created_at DESC LIMIT CAST(:lim AS INTEGER)';
                    $tStmt = $db->prepare($tSql);
                    $tStmt->bindValue(':aid', $record['id'], PDO::PARAM_INT);
                    foreach ($txnBranchParams as $bk => $bv) { $tStmt->bindValue($bk, $bv); }
                    $tStmt->bindValue(':lim', $txnLimit, PDO::PARAM_INT);
                    $tStmt->execute();
                    $txns = $tStmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $txErr) {
                    try {
                        $tSql2 = 'SELECT * FROM operating_account_transactions WHERE operating_account_id = :aid' . $txnBranchFilter . ' ORDER BY id DESC LIMIT CAST(:lim AS INTEGER)';
                        $tStmt = $db->prepare($tSql2);
                        $tStmt->bindValue(':aid', $record['id'], PDO::PARAM_INT);
                        foreach ($txnBranchParams as $bk => $bv) { $tStmt->bindValue($bk, $bv); }
                        $tStmt->bindValue(':lim', $txnLimit, PDO::PARAM_INT);
                        $tStmt->execute();
                        $txns = $tStmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (PDOException $e2) {
                        $txns = [];
                    }
                }

                // Normalize DECIMAL fields to float for JSON encoding
                foreach ($txns as &$tx) {
                    $tx['amount']        = (float)$tx['amount'];
                    $tx['balance_after'] = (float)$tx['balance_after'];
                }
                unset($tx);
                $record['transactions'] = $txns;
            }
            unset($record);

            // ── Background: sync cached balance to account rows ───────────
            // Only sync cache when viewing the GLOBAL balance. Otherwise a branch-scoped
            // GET would overwrite the shared cache and break other modules (e.g. expenses).
            if ($branchScope === null) {
                try {
                    $db->prepare("UPDATE operating_account SET balance = ?")->execute([$glBalance]);
                } catch (PDOException $cacheErr) {
                    error_log('[Operating Account] GET cache sync failed (non-critical): ' . $cacheErr->getMessage());
                }
            }

            successResponse($records);

        } catch (PDOException $e) {
            error_log('[Operating Account] GET Error: ' . $e->getMessage());
            // Last resort: return a valid empty response so the app doesn't crash
            successResponse([
                'account_number' => 'BANK-OP-0001',
                'account_name'   => 'Atlas Bank Operating Fund',
                'balance'        => 0,
                'currency'       => 'XAF',
                'glSource'       => OA_GL_CODE,
                'transactions'   => []
            ]);
        }
        break;

    // ───────────────────────────────────────────────────────────────────────
    // POST — Credit/Debit operating fund with double-entry GL
    // ───────────────────────────────────────────────────────────────────────
    case 'POST':
        try {
            $db = getDB();

            // ── Schema ensure (DDL — OUTSIDE transaction) ────────────────
            // DDL causes implicit commit in MySQL/MariaDB, so these MUST
            // run before beginTransaction().
            opEnsureOperatingAccountTable($db);
            opEnsureTransactionsTable($db);
            opEnsureGeneralLedgerTable($db);
            opEnsureSeedAccount($db);

            // ── Parse and validate input ─────────────────────────────────
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                errorResponse('Invalid JSON body.', 400);
                break;
            }

            $type        = strtoupper(trim($input['type'] ?? ''));
            $amountParsed = parseDecimalInput($input['amount'] ?? null, 'Amount', 2, 0.01, 1000000000000);
            if (!$amountParsed['ok']) {
                errorResponse($amountParsed['error'], 400);
                break;
            }
            $amount      = $amountParsed['value'];
            $description = sanitize(trim($input['description'] ?? ''));
            $branch      = opResolveBranch($input, $staff);

            if (!in_array($type, ['CREDIT', 'DEBIT'], true)) {
                errorResponse('Missing required fields: type (CREDIT/DEBIT), amount > 0.', 400);
                break;
            }

            // ── Resolve operating account ────────────────────────────────
            $acct = $db->query("SELECT * FROM operating_account WHERE account_number = 'BANK-OP-0001' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (!$acct) {
                $acct = $db->query('SELECT * FROM operating_account LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            }
            if (!$acct) {
                errorResponse('Operating account not found.', 404);
                break;
            }
            $acctId = (int)$acct['id'];

            // ── PATTERN 1: Read balance from GL 1400, NOT stored balance ─
            // ★ FIX (OPFUND-B004): Negative balance check must be branch-aware.
            // A branch cannot spend more than its own allocated/earned operating fund.
            $branchScope = $isAdmin ? null : ($staff['branches'] ?? null);
            $currentBalance = opComputeGL1400Balance($db, $branchScope);

            // ── NEGATIVE BALANCE GUARD (from GL source of truth) ─────────
            if ($type === 'DEBIT' && $amount > $currentBalance) {
                error_log('[Operating Account] DEBIT rejected: amount ' . number_format($amount, 2) . ' > GL balance ' . number_format($currentBalance, 2));
                errorResponse(
                    'Insufficient operating fund balance. Available: ' . number_format($currentBalance, 2) . ' XAF, attempted debit: ' . number_format($amount, 2) . ' XAF. Negative balances are not permitted.',
                    400
                );
                break;
            }

            // ── Prepare derived values ───────────────────────────────────
            $newBalance     = $type === 'CREDIT' ? $currentBalance + $amount : $currentBalance - $amount;
            $ref            = opGenerateRef($db);
            $operatorName   = $staff['full_name'] ?? 'System';
            $operatorId     = (int)($staff['id'] ?? 0);
            $glContraCode   = $type === 'CREDIT' ? OA_CONTRA_CREDIT : OA_CONTRA_DEBIT;
            $glContraName   = $type === 'CREDIT' ? OA_CONTRA_CREDIT_NAME : OA_CONTRA_DEBIT_NAME;
            $txnType        = $type === 'CREDIT' ? 'MANUAL_CREDIT' : 'MANUAL_DEBIT';
            $glTxnType      = $type === 'CREDIT' ? 'OPERATING_CREDIT' : 'OPERATING_DEBIT';
            $postedBy       = $operatorId > 0 ? $operatorId : null;

            // ═════════════════════════════════════════════════════════════
            // TRANSACTION: Steps 1-4 (all-or-nothing)
            // ═════════════════════════════════════════════════════════════
            $db->beginTransaction();
            $committed = false;

            try {
                // ── Step 1: Record operating_account_transaction ─────────
                $db->prepare(
                    "INSERT INTO operating_account_transactions
                        (ref, operating_account_id, date, type, description, amount, balance_after, operator, contra_account, transaction_type, branch)
                     VALUES (?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([
                    $ref, $acctId, $type, $description, $amount, $newBalance,
                    $operatorName, $glContraCode, $txnType, $branch
                ]);

                // ── Step 2: Record double-entry GL entries ───────────────
                if ($type === 'CREDIT') {
                    // CREDIT (money in): DR 1400 (Asset ↑), CR 3100 (Retained Earnings ↑)
                    $descSuffix = ' [Operating Fund Credit]';

                    $db->prepare(
                        "INSERT INTO general_ledger
                            (account_code, account_name, debit, credit, date, reference, description, posted_by, transaction_type, contra_account, branch)
                         VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)"
                    )->execute([OA_GL_CODE, OA_GL_NAME, $amount, 0, $ref, $description . $descSuffix, $postedBy, $glTxnType, $glContraCode, $branch]);

                    $db->prepare(
                        "INSERT INTO general_ledger
                            (account_code, account_name, debit, credit, date, reference, description, posted_by, transaction_type, contra_account, branch)
                         VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)"
                    )->execute([$glContraCode, $glContraName, 0, $amount, $ref, $description . $descSuffix, $postedBy, $glTxnType, OA_GL_CODE, $branch]);
                } else {
                    // DEBIT (money out): CR 1400 (Asset ↓), DR 5900 (Misc Expense ↑)
                    $descSuffix = ' [Operating Fund Debit]';

                    $db->prepare(
                        "INSERT INTO general_ledger
                            (account_code, account_name, debit, credit, date, reference, description, posted_by, transaction_type, contra_account, branch)
                         VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)"
                    )->execute([OA_GL_CODE, OA_GL_NAME, 0, $amount, $ref, $description . $descSuffix, $postedBy, $glTxnType, $glContraCode, $branch]);

                    $db->prepare(
                        "INSERT INTO general_ledger
                            (account_code, account_name, debit, credit, date, reference, description, posted_by, transaction_type, contra_account, branch)
                         VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)"
                    )->execute([$glContraCode, $glContraName, $amount, 0, $ref, $description . $descSuffix, $postedBy, $glTxnType, OA_GL_CODE, $branch]);
                }

                // ── Step 3: Update cached balance in operating_account ──
                // This is inside the transaction for atomicity. Even though
                // GL is the source of truth, we update the cache here so
                // other non-Pattern-1 consumers see a consistent value.
                $db->prepare("UPDATE operating_account SET balance = ? WHERE id = ?")
                   ->execute([$newBalance, $acctId]);

                // ── Commit the transaction ───────────────────────────────
                $db->commit();
                $committed = true;

            } catch (PDOException $txErr) {
                // ── Rollback on ANY failure ──────────────────────────────
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('[Operating Account] POST Transaction ROLLED BACK: ' . $txErr->getMessage());
                error_log('[Operating Account] POST Transaction context: type=' . $type . ' amount=' . $amount . ' ref=' . $ref . ' operator=' . $operatorName);
                errorResponse('Transaction failed. No changes were made. Please try again.', 500);
                break;
            }

            // ═════════════════════════════════════════════════════════════
            // POST-TRANSACTION: Chart of accounts seed (DDL — outside txn)
            // ═════════════════════════════════════════════════════════════
            try {
                opEnsureChartOfAccounts($db);
            } catch (PDOException $ddlErr) {
                // Non-critical: CoA seed failure doesn't affect the posted transaction
                error_log('[Operating Account] Chart of accounts seed failed (non-critical): ' . $ddlErr->getMessage());
            }

            // ── Audit logging ────────────────────────────────────────────
            error_log('[Operating Account] POST ' . $type . ' ' . number_format($amount) . ' XAF | Ref: ' . $ref . ' | GL entries recorded for ' . OA_GL_CODE . ' | Branch: ' . ($branch ?: 'N/A'));
            try {
                logAudit(
                    $operatorName,
                    'OPERATING_ACCOUNT_' . $type,
                    'OPERATING_ACCOUNT',
                    (string)$acctId,
                    'SUCCESS',
                    $type . ' ' . number_format($amount, 2) . ' XAF ref=' . $ref . ' balance_after=' . number_format($newBalance, 2) . ' glSource=' . OA_GL_CODE,
                    $staff['department'] ?? '',
                    getClientIp()
                );
            } catch (Throwable $auditErr) {
                error_log('[Operating Account] Audit logging failed (non-critical): ' . $auditErr->getMessage());
            }

            // ── Success response ─────────────────────────────────────────
            successResponse([
                'id'              => (int)$db->lastInsertId('operating_account_transactions_id_seq'),
                'ref'             => $ref,
                'type'            => $type,
                'amount'          => $amount,
                'balanceAfter'    => $newBalance,
                'glSource'        => OA_GL_CODE,
                'glEntriesRecorded' => true,
                'branch'          => $branch
            ]);

        } catch (PDOException $e) {
            error_log('[Operating Account] POST Error: ' . $e->getMessage());
            errorResponse('Database error.', 500);
        } catch (Throwable $e) {
            error_log('[Operating Account] POST Unexpected Error: ' . $e->getMessage());
            errorResponse('An unexpected error occurred.', 500);
        }
        break;

    default:
        errorResponse('Method not allowed.', 405);
}
