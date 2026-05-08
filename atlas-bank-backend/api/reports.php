<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Reports — Enterprise Grade
 *
 * Endpoints:
 *   GET  ?type=dashboard              → Summary stats + recent transactions
 *   GET  ?type=balance_summary        → Account balances by product type
 *   GET  ?type=profit_loss            → Raw profit_ledger entries (legacy compat)
 *   GET  ?type=balance_trends         → Historical balance snapshots
 *   GET  ?type=profit_loss_summary    → ★ Comprehensive P&L aggregation (income, expenses, net, by category/branch/period)
 *   GET  ?type=profit_loss_entries    → ★ Paginated filtered P&L entries
 *   GET  ?type=profit_loss_trend      → ★ Daily/monthly P&L trend data for charts
 *   GET  ?type=profit_loss_comparison → ★ Current vs previous period comparison
 *   POST ?type=profit_loss            → Insert profit ledger entry
 */
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

// ★ FIXED: Method-level access control — same enterprise pattern as settings.php.
// GET: Any authenticated user can VIEW reports/P&L data (read-only).
// POST: Requires REPORTS module to write profit ledger entries.
// Previously, requireModule('REPORTS') blocked ALL non-admin users from the
// P&L panel, causing 403 on every GET request. Non-admin tellers could do
// withdrawals (with fees) but could never see the P&L results.
//
// Note: The backfill_profit_ledger POST requires REPORTS module. This is
// acceptable because: (1) the server-side profit_ledger write in
// transactions.php handles all new withdrawals automatically, and (2) the
// backfill is a one-time historical sync that admins can trigger.
$method = $_ROUTE['method'];
if ($method === 'GET') {
    $staff = requireAuth();
} else {
    $staff = requireModule('REPORTS');
}

// ── Branch isolation helper ──
function rptNormalizeBranch(string $branch): string {
    $b = strtoupper(trim($branch));
    $b = preg_replace('/\s+BRANCH$/', '', $b);
    if (in_array($b, ['ALL', 'ALL BRANCHES', 'ALL_BRANCHES', 'ALL MY BRANCHES', 'ALL_MY_BRANCHES', 'ALLBRANCHES', 'ALLBRANCH'], true)) {
        return '';
    }
    return $b;
}

function rptGetBranchFilter(PDO $db, array $staff, string $requestedBranch = ''): string {
    $isAdmin = strtoupper($staff['role'] ?? '') === 'ADMIN';
    $requestedBranch = rptNormalizeBranch($requestedBranch);
    
    // If an Admin explicitly requests a branch, filter by it.
    if ($isAdmin && $requestedBranch !== '') {
        return " AND UPPER(TRIM(branch)) = :_req_branch";
    }
    
    if ($isAdmin) return '';
    
    $userBranchesRaw = rptGetUserBranches($db, $staff);
    $userBranches = [];
    foreach ($userBranchesRaw as $ub) {
        $n = rptNormalizeBranch((string)$ub);
        if ($n !== '') $userBranches[] = $n;
    }
    if (empty($userBranches) || in_array('ALL', $userBranches, true)) return '';
    
    // If non-admin requests a branch, ensure it's within their scope
    if ($requestedBranch !== '') {
        if (in_array($requestedBranch, $userBranches, true)) {
            return " AND UPPER(TRIM(branch)) = :_req_branch";
        }
    }

    $ph = array_map(function($i) { return ':_ubr_' . $i; }, array_keys($userBranches));
    return " AND UPPER(TRIM(branch)) IN (" . implode(',', $ph) . ")";
}

// ★ FIX (DP-026): Cache user branches per request to avoid opening a new DB connection.
function rptGetUserBranches(PDO $db, array $staff): array {
    static $cache = [];
    $key = (int)($staff['staff_id'] ?? ($staff['id'] ?? 0));
    if ($key === 0) return [];
    if (isset($cache[$key])) return $cache[$key];
    $branches = [];
    try {
        $s = $db->prepare("SELECT branch_name FROM staff_branches WHERE staff_id = ?");
        $s->execute([$key]);
        $branches = $s->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (PDOException $e) {}
    $cache[$key] = $branches;
    return $branches;
}

function rptBindBranchFilter(PDO $db, PDOStatement $stmt, array $staff, string $requestedBranch = ''): void {
    $isAdmin = strtoupper($staff['role'] ?? '') === 'ADMIN';
    $requestedBranch = rptNormalizeBranch($requestedBranch);

    if ($isAdmin && $requestedBranch !== '') {
        $stmt->bindValue(':_req_branch', $requestedBranch);
        return;
    }

    if ($isAdmin) return;
    
    try {
        $userBranchesRaw = rptGetUserBranches($db, $staff);
        $userBranches = [];
        foreach ($userBranchesRaw as $ub) {
            $n = rptNormalizeBranch((string)$ub);
            if ($n !== '') $userBranches[] = $n;
        }
        
        if ($requestedBranch !== '') {
            if (in_array($requestedBranch, $userBranches, true)) {
                $stmt->bindValue(':_req_branch', $requestedBranch);
                return;
            }
        }

        if (in_array('ALL', $userBranches, true)) return;
        foreach ($userBranches as $i => $branch) {
            $stmt->bindValue(':_ubr_' . $i, $branch);
        }
    } catch (Throwable $e) {}
}

/**
 * Safely add a column to any table if it doesn't exist (MySQL/MariaDB compatible)
 */
function reportSafeAddCol(PDO $db, string $table, string $col, string $def): void {
    try {
        $r = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?");
        $r->execute([$table, $col]);
        if (!$r) $db->exec("ALTER TABLE $table ADD COLUMN $col $def");
    } catch (PDOException $e) {
        error_log('[Reports Schema] safeAddCol(' . $table . '.' . $col . ') failed: ' . $e->getMessage());
    }
}

/**
 * Safely add a column to profit_ledger if it doesn't exist (MySQL/MariaDB compatible)
 */
function reportAddCol(PDO $db, string $col, string $def): void {
    try {
        $r = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'profit_ledger' AND column_name = '$col'")->fetch();
        if (!$r) $db->exec("ALTER TABLE profit_ledger ADD COLUMN $col $def");
    } catch (PDOException $e) {
        error_log('[Reports Schema] reportAddCol(' . $col . ') failed: ' . $e->getMessage());
    }
}

/**
 * Ensure profit_ledger table exists with all required columns.
 */
function ensureProfitLedgerSchema(PDO $db): void {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS profit_ledger (
            id SERIAL PRIMARY KEY,
            gl_code VARCHAR(20) DEFAULT '',
            gl_account_name VARCHAR(100) DEFAULT '',
            gl_type VARCHAR(20) DEFAULT 'INCOME',
            category VARCHAR(50) DEFAULT '',
            source_ref VARCHAR(50) DEFAULT '',
            source_type VARCHAR(50) DEFAULT '',
            account_number VARCHAR(50) DEFAULT '',
            account_type VARCHAR(50) DEFAULT '',
            customer_name VARCHAR(200) DEFAULT '',
            branch VARCHAR(100) DEFAULT '',
            gross_amount DECIMAL(18,2) DEFAULT 0,
            fee_amount DECIMAL(18,2) DEFAULT 0,
            fee_pct DECIMAL(8,4) DEFAULT 0,
            fee_mode VARCHAR(50) DEFAULT '',
            operator VARCHAR(100) DEFAULT '',
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (PDOException $e) {
        error_log('[Reports Schema] CREATE TABLE profit_ledger failed: ' . $e->getMessage());
    }

    reportAddCol($db, 'gl_code', "VARCHAR(20) DEFAULT ''");
    reportAddCol($db, 'gl_account_name', "VARCHAR(100) DEFAULT ''");
    reportAddCol($db, 'gl_type', "VARCHAR(20) DEFAULT 'INCOME'");
    reportAddCol($db, 'category', "VARCHAR(50) DEFAULT ''");
    reportAddCol($db, 'source_ref', "VARCHAR(50) DEFAULT ''");
    reportAddCol($db, 'source_type', "VARCHAR(50) DEFAULT ''");
    reportAddCol($db, 'account_number', "VARCHAR(50) DEFAULT ''");
    reportAddCol($db, 'account_type', "VARCHAR(50) DEFAULT ''");
    reportAddCol($db, 'customer_name', "VARCHAR(200) DEFAULT ''");
    reportAddCol($db, 'branch', "VARCHAR(100) DEFAULT ''");
    reportAddCol($db, 'gross_amount', "DECIMAL(18,2) DEFAULT 0");
    reportAddCol($db, 'fee_amount', "DECIMAL(18,2) DEFAULT 0");
    reportAddCol($db, 'fee_pct', "DECIMAL(8,4) DEFAULT 0");
    reportAddCol($db, 'fee_mode', "VARCHAR(50) DEFAULT ''");
    reportAddCol($db, 'operator', "VARCHAR(100) DEFAULT ''");
    reportAddCol($db, 'description', "TEXT");
    reportAddCol($db, 'gl_category', "VARCHAR(100) DEFAULT ''");
    reportAddCol($db, 'total_debit', "DECIMAL(20,2) DEFAULT 0.00");
    reportAddCol($db, 'total_credit', "DECIMAL(20,2) DEFAULT 0.00");
    reportAddCol($db, 'net_amount', "DECIMAL(20,2) DEFAULT 0.00");
    reportAddCol($db, 'period_start', "DATE DEFAULT NULL");
    reportAddCol($db, 'period_end', "DATE DEFAULT NULL");
}

/**
 * Ensure balance_trends table exists with all required columns.
 * FIX: This table was queried but NEVER auto-created, causing 500 errors.
 */
function ensureBalanceTrendsSchema(PDO $db): void {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS balance_trends (
            id SERIAL PRIMARY KEY,
            snapshot_date  DATE NOT NULL,
            product_type   VARCHAR(50) NOT NULL,
            total_balance  DECIMAL(20,2) DEFAULT 0,
            total_available DECIMAL(20,2) DEFAULT 0,
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_snapshot (snapshot_date),
            INDEX idx_product (product_type)
        )");
    } catch (PDOException $e) {
        error_log('[Reports Schema] CREATE TABLE balance_trends failed: ' . $e->getMessage());
    }
    // Add total_available if table was created by CANONICAL_SCHEMA (which omits it)
    reportSafeAddCol($db, 'balance_trends', 'total_available', "DECIMAL(20,2) DEFAULT 0");
}

/**
 * Ensure transactions table has all columns needed by reports.
 * FIX: CANONICAL_SCHEMA creates transactions without net_amount, fee_mode, total_tax.
 */
function ensureTransactionsSchema(PDO $db): void {
    reportSafeAddCol($db, 'transactions', 'net_amount', "DECIMAL(20,2) DEFAULT 0");
    reportSafeAddCol($db, 'transactions', 'fee_mode', "VARCHAR(50) DEFAULT ''");
    reportSafeAddCol($db, 'transactions', 'total_tax', "DECIMAL(20,2) DEFAULT 0");
    reportSafeAddCol($db, 'transactions', 'created_by', "INT DEFAULT NULL");
    reportSafeAddCol($db, 'transactions', 'deduction_breakdown', "TEXT DEFAULT NULL");
}

function reportHasTable(PDO $db, string $table): bool {
    try {
        $stmt = $db->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function ensureLoanFundTxSchema(PDO $db): void {
    try {
        if (!reportHasTable($db, 'loan_fund_accounts')) {
            $db->exec("CREATE TABLE IF NOT EXISTS loan_fund_accounts (
                id SERIAL PRIMARY KEY,
                account_number VARCHAR(30) NOT NULL UNIQUE,
                account_name VARCHAR(200) NOT NULL,
                fund_type VARCHAR(20) NOT NULL,
                balance DECIMAL(20,2) DEFAULT 0,
                currency VARCHAR(5) DEFAULT 'XAF',
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        }
        if (!reportHasTable($db, 'loan_fund_transactions')) {
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
                branch VARCHAR(20) DEFAULT NULL,
                operator VARCHAR(200),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_lft_date (date),
                INDEX idx_lft_branch (branch)
            )");
        } else {
            reportSafeAddCol($db, 'loan_fund_transactions', 'branch', "VARCHAR(20) DEFAULT NULL");
            reportSafeAddCol($db, 'loan_fund_transactions', 'operator', "VARCHAR(200) DEFAULT ''");
            reportSafeAddCol($db, 'loan_fund_transactions', 'transaction_ref', "VARCHAR(50) DEFAULT NULL");
            reportSafeAddCol($db, 'loan_fund_transactions', 'ref', "VARCHAR(50) DEFAULT ''");
        }
        try {
            $db->exec("
                UPDATE loan_fund_transactions lft
                JOIN loans l ON l.id = lft.loan_id
                SET lft.branch = l.branch
                WHERE (lft.branch IS NULL OR TRIM(lft.branch) = '')
                  AND l.branch IS NOT NULL AND TRIM(l.branch) <> ''
            ");
        } catch (PDOException $e) {}
    } catch (PDOException $e) {}
}

/**
 * Build P&L date filter clauses and bind params.
 * Returns ['where' => string, 'params' => array, 'exp_where' => string, 'exp_params' => array]
 */
function buildPLDateFilters(array $get): array {
    $plWhere = '';
    $plParams = [];
    $expWhere = '';
    $expParams = [];

    $dateFrom = sanitize($get['date_from'] ?? '');
    $dateTo = sanitize($get['date_to'] ?? '');

    if (!empty($dateFrom)) {
        $plWhere .= ($plWhere ? ' AND ' : ' WHERE ') . 'DATE(created_at) >= :pl_df';
        $plParams[':pl_df'] = $dateFrom;
        $expWhere .= ($expWhere ? ' AND ' : ' WHERE ') . 'date >= :exp_df';
        $expParams[':exp_df'] = $dateFrom;
    }
    if (!empty($dateTo)) {
        $plWhere .= ($plWhere ? ' AND ' : ' WHERE ') . 'DATE(created_at) <= :pl_dt';
        $plParams[':pl_dt'] = $dateTo;
        $expWhere .= ($expWhere ? ' AND ' : ' WHERE ') . 'date <= :exp_dt';
        $expParams[':exp_dt'] = $dateTo;
    }

    return ['where' => $plWhere, 'params' => $plParams, 'exp_where' => $expWhere, 'exp_params' => $expParams];
}

/**
 * Build branch filter clauses for both P&L and expenses.
 * IMPORTANT: The returned clause uses AND prefix — the caller must ensure
 * a WHERE clause exists first. If date filters are empty, we still return
 * " AND branch = ..." and the caller wraps with proper WHERE handling.
 */
function buildPLBranchFilters(PDO $db, array $staff, array $get): array {
    $result = ['pl_branch' => '', 'exp_branch' => ''];
    $branch = sanitize($get['branch'] ?? '');
    $bn = strtoupper(trim($branch));
    $bn = preg_replace('/\s+BRANCH$/', '', $bn);
    if (in_array($bn, ['ALL', 'ALL BRANCHES', 'ALL_BRANCHES', 'ALL MY BRANCHES', 'ALL_MY_BRANCHES', 'ALLBRANCHES', 'ALLBRANCH'], true)) {
        $branch = '';
    }
    if ($branch !== '') {
        $branch = $bn;
    }
    $isAdmin = strtoupper($staff['role'] ?? '') === 'ADMIN';
    $userBranches = $isAdmin ? [] : rptGetUserBranches($db, $staff);
    if (!empty($userBranches)) {
        $userBranches = array_values(array_filter(array_map(function($b) {
            $x = strtoupper(trim((string)$b));
            $x = preg_replace('/\s+BRANCH$/', '', $x);
            return $x;
        }, $userBranches), function($b) { return $b !== ''; }));
    }
    $hasScopedBranches = !$isAdmin && !empty($userBranches) && !in_array('ALL', $userBranches, true);

    // If an explicit branch was requested, enforce RBAC scope instead of bypassing it.
    if (!empty($branch)) {
        if ($hasScopedBranches) {
            $allowed = false;
            foreach ($userBranches as $userBranch) {
                if (strtoupper(trim($userBranch)) === strtoupper(trim($branch))) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                $result['pl_branch'] = ' AND branch = :pl_branch_deny';
                $result['pl_branch_param'] = [':pl_branch_deny' => '__DENIED_BRANCH__'];
                $result['exp_branch'] = ' AND branch = :exp_branch_deny';
                $result['exp_branch_param'] = [':exp_branch_deny' => '__DENIED_BRANCH__'];
                return $result;
            }
        }

        $result['pl_branch'] = ' AND UPPER(TRIM(branch)) = :pl_branch';
        $result['pl_branch_param'] = [':pl_branch' => $branch];
        $result['exp_branch'] = ' AND UPPER(TRIM(branch)) = :exp_branch';
        $result['exp_branch_param'] = [':exp_branch' => $branch];
        return $result;
    }

    // Otherwise apply RBAC branch isolation for non-admin scoped users.
    if ($hasScopedBranches) {
        $ph = array_map(function($i) { return ':_ubr_' . $i; }, array_keys($userBranches));
        $clause = " AND UPPER(TRIM(branch)) IN (" . implode(',', $ph) . ")";
        $result['pl_branch'] = $clause;
        $result['pl_branch_params'] = array_combine(
            array_map(function($i) { return ':_ubr_' . $i; }, array_keys($userBranches)),
            $userBranches
        );
        $result['exp_branch'] = $clause;
        $result['exp_branch_params'] = $result['pl_branch_params'];
    }
    return $result;
}

/**
 * Safely combine date and branch filters into a WHERE clause.
 * Ensures valid SQL even when date filters are empty but branch is set.
 */
function buildPLCombinedWhere(string $dateWhere, string $branchClause): string {
    if (empty($branchClause)) return $dateWhere;
    if (empty($dateWhere)) return ' WHERE 1=1' . $branchClause;
    return $dateWhere . $branchClause;
}

/**
 * Safely combine date and branch filters for expenses table.
 */
function buildPLCombinedExpWhere(string $dateWhere, string $branchClause): string {
    // ★ FIX (DP-003): ALWAYS include status='APPROVED' filter for expenses.
    // Previously, this was only added when dateWhere was empty but branchClause was not.
    // In 3 of 4 cases, PENDING/REJECTED expenses were included in totals.
    if (empty($dateWhere) && empty($branchClause)) {
        return " WHERE status = 'APPROVED'";
    }
    if (empty($dateWhere)) {
        return " WHERE status = 'APPROVED'" . $branchClause;
    }
    // dateWhere already contains a WHERE clause, so append with AND
    return $dateWhere . " AND status = 'APPROVED'" . $branchClause;
}

/**
 * Compute the operating fund balance for the P&L panel from GL 1400.
 *
 * Enterprise rule: The P&L operating fund KPI must use the same source of truth
 * as the operating fund / GL panels, and it must honor branch scope.
 */
function computeOperatingFundForPL(PDO $db, array $staff, string $requestedBranch = ''): float
{
    $params = [':gl_code' => '1400'];
    $where = ' WHERE account_code = :gl_code';

    $isAdmin = strtoupper($staff['role'] ?? '') === 'ADMIN';
    $userBranches = $isAdmin ? [] : rptGetUserBranches($db, $staff);
    $hasScopedBranches = !$isAdmin && !empty($userBranches) && !in_array('ALL', $userBranches, true);

    $rb = strtoupper(trim($requestedBranch));
    $rb = preg_replace('/\s+BRANCH$/', '', $rb);
    if (in_array($rb, ['ALL', 'ALL BRANCHES', 'ALL_BRANCHES', 'ALL MY BRANCHES', 'ALL_MY_BRANCHES', 'ALLBRANCHES', 'ALLBRANCH'], true)) {
        $requestedBranch = '';
    }
    if ($requestedBranch !== '') {
        $requestedBranch = $rb;
    }

    if ($requestedBranch !== '') {
        if ($hasScopedBranches) {
            $allowed = false;
            foreach ($userBranches as $userBranch) {
                $ub = strtoupper(trim((string)$userBranch));
                $ub = preg_replace('/\s+BRANCH$/', '', $ub);
                if ($ub === strtoupper(trim($requestedBranch))) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                return 0.0;
            }
        }
        $where .= ' AND UPPER(TRIM(branch)) = :op_branch';
        $params[':op_branch'] = $requestedBranch;
    } elseif ($hasScopedBranches) {
        $ph = [];
        foreach ($userBranches as $i => $userBranch) {
            $key = ':op_br_' . $i;
            $ph[] = $key;
            $ub = strtoupper(trim((string)$userBranch));
            $ub = preg_replace('/\s+BRANCH$/', '', $ub);
            $params[$key] = $ub;
        }
        $where .= ' AND UPPER(TRIM(branch)) IN (' . implode(',', $ph) . ')';
    }

    try {
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS balance
             FROM general_ledger {$where}"
        );
        $stmt->execute($params);
        return (float)($stmt->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        try {
            $fallback = $db->query("SELECT COALESCE(balance, 0) FROM operating_account WHERE account_number = 'BANK-OP-0001' LIMIT 1");
            return (float)($fallback->fetchColumn() ?: 0);
        } catch (PDOException $fallbackErr) {
            return 0.0;
        }
    }
}

switch ($method) {
    case 'GET':
        $reportType = sanitize($_GET['type'] ?? 'dashboard');
        $branch = sanitize($_GET['branch'] ?? '');

        try {
            $db = getDB();

            // ═══════════════════════════════════════════════════════════
            //  DASHBOARD — Summary stats
            // ═══════════════════════════════════════════════════════════
            if ($reportType === 'dashboard') {
                // ★ FIX (API-047): Apply branch filtering to dashboard summary queries
                // Now supports explicit ?branch=... parameter for Admin-level drilldown.
                $dashBrFilter = rptGetBranchFilter($db, $staff, $branch);

                $customers = 0; $accounts = 0; $activeLoans = 0; $pendingApprovals = 0;
                $pendingTxns = 0; $pendingLoans = 0; $totalDeposits = 0; $totalLoanPortfolio = 0;
                $monthlyExpenses = 0; $openFindings = 0;
                try {
                    $s = $db->prepare("SELECT COUNT(*) FROM customers WHERE status='ACTIVE'" . $dashBrFilter);
                    rptBindBranchFilter($db, $s, $staff, $branch); $s->execute();
                    $customers = (int)$s->fetchColumn();
                } catch (PDOException $e) {}
                try {
                    $s = $db->prepare("SELECT COUNT(*) FROM accounts WHERE status='ACTIVE'" . $dashBrFilter);
                    rptBindBranchFilter($db, $s, $staff, $branch); $s->execute();
                    $accounts = (int)$s->fetchColumn();
                } catch (PDOException $e) {}
                try {
                    // Active portfolio = performing + delinquent loans that are still live on the books.
                    $s = $db->prepare("SELECT COUNT(*) FROM loans WHERE status IN ('ACTIVE','DELINQUENT')" . $dashBrFilter);
                    rptBindBranchFilter($db, $s, $staff, $branch); $s->execute();
                    $activeLoans = (int)$s->fetchColumn();
                } catch (PDOException $e) {}
                // ★ FIX (DP-006): Add branch filtering to pending_approvals count.
                // Previously used $db->query() with no branch filter — non-admin users
                // would see global approval count, violating branch isolation.
                try {
                    $s = $db->prepare("SELECT COUNT(*) FROM approvals WHERE status='PENDING'" . $dashBrFilter);
                    rptBindBranchFilter($db, $s, $staff, $branch); $s->execute();
                    $pendingApprovals = (int)$s->fetchColumn();
                } catch (PDOException $e) {}
                try {
                    $s = $db->prepare("SELECT COUNT(*) FROM transactions WHERE status='PENDING_APPROVAL'" . $dashBrFilter);
                    rptBindBranchFilter($db, $s, $staff, $branch); $s->execute();
                    $pendingTxns = (int)$s->fetchColumn();
                } catch (PDOException $e) {}
                // ★ FIXED: Count from loan_applications table (the real source), not loans table.
                // Falls back to loans table count if loan_applications doesn't exist.
                try {
                    $s = $db->prepare("SELECT COUNT(*) FROM loan_applications WHERE status IN ('PENDING','UNDER_REVIEW')" . $dashBrFilter);
                    rptBindBranchFilter($db, $s, $staff, $branch); $s->execute();
                    $pendingLoans = (int)$s->fetchColumn();
                } catch (PDOException $e) {
                    try {
                        $s = $db->prepare("SELECT COUNT(*) FROM loans WHERE status IN ('PENDING','APPROVED','UNDER_REVIEW')" . $dashBrFilter);
                        rptBindBranchFilter($db, $s, $staff, $branch); $s->execute();
                        $pendingLoans = (int)$s->fetchColumn();
                    } catch (PDOException $e2) {}
                }
                try {
                    // ★ FIX (RA-ACC-002): Changed available_balance to ledger_balance for total_deposits.
                    // "Total Deposits" in banking = ledger_balance (full liability), not available_balance
                    // (which excludes holds). This aligns with branches.php and the frontend Dashboard.
                    // Also removed AND currency='XAF' filter — the frontend doesn't filter by currency.
                    $s = $db->prepare("SELECT COALESCE(SUM(ledger_balance), 0) FROM accounts WHERE status='ACTIVE'" . $dashBrFilter);
                    rptBindBranchFilter($db, $s, $staff, $branch); $s->execute();
                    $totalDeposits = (float)$s->fetchColumn();
                } catch (PDOException $e) {}
                try {
                    // Canonical live-loan portfolio: ACTIVE + DELINQUENT.
                    // OVERDUE is derived from schedule aging in this system, not persisted as a loan status.
                    $s = $db->prepare("SELECT COALESCE(SUM(outstanding), 0) FROM loans WHERE status IN ('ACTIVE','DELINQUENT')" . $dashBrFilter);
                    rptBindBranchFilter($db, $s, $staff, $branch); $s->execute();
                    $totalLoanPortfolio = (float)$s->fetchColumn();
                } catch (PDOException $e) {}
                try {
                    $s = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE status='APPROVED' AND EXTRACT(MONTH FROM date) = EXTRACT(MONTH FROM CURRENT_DATE) AND EXTRACT(YEAR FROM date) = EXTRACT(YEAR FROM CURRENT_DATE)" . $dashBrFilter);
                    rptBindBranchFilter($db, $s, $staff, $branch); $s->execute();
                    $monthlyExpenses = (float)$s->fetchColumn();
                } catch (PDOException $e) {}
                // ★ FIX (FR-DASH-001): Add all-time total_income and total_expenses to dashboard.
                // Previously, the dashboard returned only monthly_expenses (current month only),
                // but the frontend Net Profit KPI is labeled "All-Time" — mixing all-time income
                // (from local fallback) with monthly-only expenses caused Net Profit = 562,611
                // on Dashboard vs 726,028 on Financial Reports. Now both panels use all-time data.
                $totalIncome = 0;
                $totalExpensesAllTime = 0;
                try {
                    $s = $db->prepare("SELECT COALESCE(SUM(fee_amount), 0) FROM profit_ledger WHERE gl_type = 'INCOME'" . $dashBrFilter);
                    rptBindBranchFilter($db, $s, $staff, $branch); $s->execute();
                    $totalIncome = (float)$s->fetchColumn();
                } catch (PDOException $e) {}
                try {
                    $s = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE status = 'APPROVED'" . $dashBrFilter);
                    rptBindBranchFilter($db, $s, $staff, $branch); $s->execute();
                    $totalExpensesAllTime = (float)$s->fetchColumn();
                } catch (PDOException $e) {}
                // ★ FIX (DP-004): Add ESCALATED status to open findings count + branch filter.
                // Previously: (1) missing ESCALATED — frontend uses OPEN+IN_PROGRESS+ESCALATED,
                // (2) no branch filter — non-admin could see global findings count.
                try {
                    $s = $db->prepare("SELECT COUNT(*) FROM audit_findings WHERE status IN ('OPEN','IN_PROGRESS','ESCALATED')" . $dashBrFilter);
                    rptBindBranchFilter($db, $s, $staff, $branch); $s->execute();
                    $openFindings = (int)$s->fetchColumn();
                } catch (PDOException $e) {}

                // ★ FIX (OP-FUND-DASH): Include branch-scoped operating fund in dashboard summary.
                $operatingFundBalance = 0;
                try {
                    $operatingFundBalance = computeOperatingFundForPL($db, $staff, $branch);
                } catch (PDOException $e) {}

                // FIX: Wrap in try-catch — table may still be missing columns
                $recentTransactions = [];
                try {
                    $recentStmt = $db->prepare('SELECT id, ref, type, status, direction, amount, fee, fee_pct, net_amount, fee_mode, total_tax, account, account_type, customer_name, description, category, branch, memo, module, operator_id, operator_name, created_at FROM transactions WHERE 1=1' . $dashBrFilter . ' ORDER BY created_at DESC LIMIT 10');
                    rptBindBranchFilter($db, $recentStmt, $staff, $branch);
                    $recentStmt->execute();
                    $recentTransactions = $recentStmt->fetchAll();
                } catch (PDOException $e) {
                    error_log('[Reports Dashboard] Recent transactions query: ' . $e->getMessage());
                }

                successResponse([
                    'summary' => [
                        'total_customers' => $customers,
                        'total_accounts' => $accounts,
                        'active_loans' => $activeLoans,
                        'pending_approvals' => $pendingApprovals,
                        'pending_transactions' => $pendingTxns,
                        'pending_loan_applications' => $pendingLoans,
                        'total_deposits' => $totalDeposits,
                        'total_loan_portfolio' => $totalLoanPortfolio,
                        'operating_fund' => $operatingFundBalance,
                        'monthly_expenses' => $monthlyExpenses,
                        'open_audit_findings' => $openFindings,
                        // ★ FIX (FR-DASH-001): All-time income/expenses for consistent Net Profit
                        'total_income' => $totalIncome,
                        'total_expenses' => $totalExpensesAllTime
                    ],
                    'recent_transactions' => $recentTransactions
                ]);

            // ═══════════════════════════════════════════════════════════
            //  BALANCE SUMMARY — Account balances by product type
            // ═══════════════════════════════════════════════════════════
            } elseif ($reportType === 'balance_summary') {
                // ★ FIX (DP-022): Remove currency='XAF' filter to match dashboard behavior.
                // The dashboard total_deposits doesn't filter by currency, so balance_summary
                // should be consistent. Also added branch filtering for non-admin users.
                $brFilter = rptGetBranchFilter($db, $staff, $branch);
                try {
                    $s = $db->prepare(
                        "SELECT product_type, COUNT(*) AS count, SUM(ledger_balance) AS total_balance,
                                SUM(available_balance) AS total_available
                         FROM accounts WHERE status='ACTIVE'" . $brFilter . "
                         GROUP BY product_type ORDER BY total_balance DESC"
                    );
                    rptBindBranchFilter($db, $s, $staff, $branch); $s->execute();
                    successResponse($s->fetchAll(PDO::FETCH_ASSOC));
                } catch (PDOException $e) {
                    successResponse([]);
                }

            // ═══════════════════════════════════════════════════════════
            //  PROFIT & LOSS — Raw entries (legacy compat for DB cache)
            // ═══════════════════════════════════════════════════════════
            } elseif ($reportType === 'profit_loss') {
                $bf = buildPLBranchFilters($db, $staff, $_GET);
                $where = buildPLCombinedWhere('', $bf['pl_branch'] ?? '');
                $params = array_merge($bf['pl_branch_params'] ?? [], $bf['pl_branch_param'] ?? []);
                $stmt = $db->prepare(
                    'SELECT id, gl_code, gl_account_name, gl_type, category, source_ref, source_type, account_number, account_type, customer_name, branch, gross_amount, fee_amount, fee_pct, fee_mode, operator, description, created_at, gl_category, total_debit, total_credit, net_amount, period_start, period_end FROM profit_ledger ' . $where . ' ORDER BY gl_type, gl_code'
                );
                $stmt->execute($params);
                successResponse($stmt->fetchAll());

            // ═══════════════════════════════════════════════════════════
            //  BALANCE TRENDS — Historical balance snapshots
            // ═══════════════════════════════════════════════════════════
            } elseif ($reportType === 'balance_trends') {

                $pt = sanitize($_GET['product_type'] ?? '');
                $params = [];
                $where = '';
                if (!empty($pt)) { $where = 'WHERE product_type = :pt'; $params[':pt'] = $pt; }
                $stmt = $db->prepare('SELECT id, snapshot_date, product_type, total_balance, total_available, created_at FROM balance_trends ' . $where . ' ORDER BY snapshot_date DESC, product_type ASC LIMIT 90');
                $stmt->execute($params);
                successResponse($stmt->fetchAll());

            // ═══════════════════════════════════════════════════════════════════
            //  ★ PROFIT & LOSS SUMMARY — Enterprise-grade server-side aggregation
            //  Parameters: date_from, date_to, branch, category, account_type, period
            //  Returns: complete P&L with income/expense breakdowns, comparison, trend
            // ═══════════════════════════════════════════════════════════════════
            } elseif ($reportType === 'profit_loss_summary') {
                ensureProfitLedgerSchema($db);
                ensureLoanFundTxSchema($db);

                $df = buildPLDateFilters($_GET);
                $bf = buildPLBranchFilters($db, $staff, $_GET);
                $category = sanitize($_GET['category'] ?? '');
                $accountType = sanitize($_GET['account_type'] ?? '');
                $period = sanitize($_GET['period'] ?? '');
                $dateFrom = sanitize($_GET['date_from'] ?? '');
                $dateTo = sanitize($_GET['date_to'] ?? '');

                // Category filter for income entries
                $catFilter = '';
                $catParams = [];
                if (!empty($category)) {
                    $catFilter = ' AND category = :pl_cat';
                    $catParams[':pl_cat'] = $category;
                }

                // Account type filter
                $acctFilter = '';
                $acctParams = [];
                if (!empty($accountType)) {
                    $acctFilter = ' AND account_type = :pl_acct';
                    $acctParams[':pl_acct'] = $accountType;
                }

                $response = [];

                // ── 1. INCOME SUMMARY ──
                // Total income from profit_ledger
                $incWhere = buildPLCombinedWhere($df['where'], $bf['pl_branch'] ?? '') . $catFilter . $acctFilter;
                $incParams = array_merge($df['params'], $bf['pl_branch_params'] ?? [], $bf['pl_branch_param'] ?? [], $catParams, $acctParams);

                $totalIncome = 0;
                $incomeEntries = 0;
                try {
                    // ★ FIX (DP-002/DP-012): Use fee_amount consistently for total income.
                    // Previously used SUM(GREATEST(fee_amount, gross_amount)) which:
                    // (1) doesn't match the category breakdown (which uses fee_amount),
                    // (2) doesn't match the frontend fallback (which uses feeAmount),
                    // (3) violates total = sum(parts. Using fee_amount everywhere
                    // ensures total_income = sum(income_by_category[].total).
                    // ★ FIX (FR-DASH-001/FR-NP-001): Use ONLY gl_type='INCOME' filter — no
                    // OR gl_type='' OR gl_type IS NULL. The broader filter included entries
                    // with empty/null gl_type, overstating revenue relative to the Dashboard
                    // endpoint which uses strict gl_type='INCOME'. This caused Net Profit
                    // = 726,028 in P&L Summary vs 562,111 on Dashboard. Now both use the
                    // same strict filter, producing identical Fee Income totals.
                    $incTypeFilter = " AND gl_type = 'INCOME'";
                    $stmt = $db->prepare("SELECT COALESCE(SUM(fee_amount), 0) AS total, COUNT(*) AS cnt FROM profit_ledger {$incWhere}{$incTypeFilter}");
                    $stmt->execute($incParams);
                    $row = $stmt->fetch();
                    $totalIncome = (float)$row['total'];
                    $incomeEntries = (int)$row['cnt'];
                } catch (PDOException $e) {
                    error_log('[PL Summary] Income total query: ' . $e->getMessage());
                }

                $loanInterestIncome = 0.0;
                $loanInterestEntries = 0;
                $loanInterestByBranch = [];
                $includeLoanInterest = true;
                if (!empty($category) && strtoupper($category) !== 'LOAN_INTEREST') $includeLoanInterest = false;
                if (!empty($accountType)) $includeLoanInterest = false;
                if ($includeLoanInterest) {
                    $glWhere = " WHERE account_code = '4200'";
                    $glParams = [];
                    if (!empty($dateFrom)) { $glWhere .= " AND date >= :gl_df"; $glParams[':gl_df'] = $dateFrom; }
                    if (!empty($dateTo)) { $glWhere .= " AND date <= :gl_dt"; $glParams[':gl_dt'] = $dateTo; }
                    $glBranchClause = $bf['pl_branch'] ?? '';
                    if (!empty($glBranchClause)) {
                        $glWhere .= preg_replace('/\bbranch\b/', 'branch', $glBranchClause);
                        $glParams = array_merge($glParams, $bf['pl_branch_params'] ?? [], $bf['pl_branch_param'] ?? []);
                    } elseif (strtoupper($staff['role'] ?? '') !== 'ADMIN') {
                        $userBr = rptGetUserBranches($db, $staff);
                        if (!empty($userBr) && !in_array('ALL', $userBr, true)) {
                            $ph = [];
                            foreach ($userBr as $i => $ub) {
                                $k = ':_gl_ubr_' . $i;
                                $ph[] = $k;
                                $glParams[$k] = strtoupper(trim((string)$ub));
                            }
                            $glWhere .= " AND UPPER(TRIM(branch)) IN (" . implode(',', $ph) . ")";
                        }
                    }

                    try {
                        $stmt = $db->prepare(
                            "SELECT COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS total, COUNT(*) AS cnt
                             FROM general_ledger {$glWhere}"
                        );
                        $stmt->execute($glParams);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        $loanInterestIncome = (float)($row['total'] ?? 0);
                        $loanInterestEntries = (int)($row['cnt'] ?? 0);
                    } catch (PDOException $e) {
                        error_log('[PL Summary] Loan interest income total: ' . $e->getMessage());
                    }

                    try {
                        $stmt = $db->prepare(
                            "SELECT COALESCE(branch,'') AS branch, COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS total, COUNT(*) AS count
                             FROM general_ledger {$glWhere}
                             GROUP BY branch ORDER BY total DESC"
                        );
                        $stmt->execute($glParams);
                        $loanInterestByBranch = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) {
                        error_log('[PL Summary] Loan interest income by branch: ' . $e->getMessage());
                    }

                    if ($loanInterestIncome <= 0.0) {
                        $lftWhere = " WHERE lfa.account_number = 'BANK-LI-0001' AND lft.type = 'CREDIT'";
                        $lftParams = [];
                        if (!empty($dateFrom)) { $lftWhere .= " AND lft.date >= :lft_df"; $lftParams[':lft_df'] = $dateFrom; }
                        if (!empty($dateTo)) { $lftWhere .= " AND lft.date <= :lft_dt"; $lftParams[':lft_dt'] = $dateTo; }
                        $lftBranchClause = $bf['pl_branch'] ?? '';
                        if (!empty($lftBranchClause)) {
                            $lftWhere .= preg_replace('/\bbranch\b/', 'lft.branch', $lftBranchClause);
                            $lftParams = array_merge($lftParams, $bf['pl_branch_params'] ?? [], $bf['pl_branch_param'] ?? []);
                        } elseif (strtoupper($staff['role'] ?? '') !== 'ADMIN') {
                            $userBr = rptGetUserBranches($db, $staff);
                            if (!empty($userBr) && !in_array('ALL', $userBr, true)) {
                                $ph = [];
                                foreach ($userBr as $i => $ub) {
                                    $k = ':_lft_ubr_' . $i;
                                    $ph[] = $k;
                                    $lftParams[$k] = strtoupper(trim((string)$ub));
                                }
                                $lftWhere .= " AND UPPER(TRIM(lft.branch)) IN (" . implode(',', $ph) . ")";
                            }
                        }
                        try {
                            $stmt = $db->prepare(
                                "SELECT COALESCE(SUM(lft.amount), 0) AS total, COUNT(*) AS cnt
                                 FROM loan_fund_transactions lft
                                 JOIN loan_fund_accounts lfa ON lfa.id = lft.loan_fund_account_id
                                 {$lftWhere}"
                            );
                            $stmt->execute($lftParams);
                            $row = $stmt->fetch(PDO::FETCH_ASSOC);
                            $loanInterestIncome = (float)($row['total'] ?? 0);
                            $loanInterestEntries = (int)($row['cnt'] ?? 0);
                        } catch (PDOException $e) {}
                        try {
                            $stmt = $db->prepare(
                                "SELECT COALESCE(lft.branch,'') AS branch, COALESCE(SUM(lft.amount), 0) AS total, COUNT(*) AS count
                                 FROM loan_fund_transactions lft
                                 JOIN loan_fund_accounts lfa ON lfa.id = lft.loan_fund_account_id
                                 {$lftWhere}
                                 GROUP BY lft.branch ORDER BY total DESC"
                            );
                            $stmt->execute($lftParams);
                            $loanInterestByBranch = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (PDOException $e) {}
                    }
                }

                // Income breakdown by category
                $incomeByCategory = [];
                try {
                    $stmt = $db->prepare(
                        "SELECT category, gl_code, gl_account_name,
                                SUM(fee_amount) AS total, COUNT(*) AS count,
                                AVG(fee_amount) AS avg_amount,
                                SUM(gross_amount) AS total_gross
                         FROM profit_ledger {$incWhere}{$incTypeFilter}
                         GROUP BY category, gl_code, gl_account_name
                         ORDER BY total DESC"
                    );
                    $stmt->execute($incParams);
                    $incomeByCategory = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    error_log('[PL Summary] Income by category: ' . $e->getMessage());
                }

                if ($loanInterestIncome > 0 || $loanInterestEntries > 0) {
                    $intGlName = 'Loan Interest Income';
                    try {
                        $glNamesStmt = $db->query("SELECT code, name FROM chart_of_accounts WHERE code = '4200' LIMIT 1");
                        $glRow = $glNamesStmt->fetch(PDO::FETCH_ASSOC);
                        if ($glRow && !empty($glRow['name'])) $intGlName = $glRow['name'];
                    } catch (PDOException $e) {}

                    $totalIncome += $loanInterestIncome;
                    $incomeEntries += $loanInterestEntries;

                    $mergedCat = false;
                    foreach ($incomeByCategory as &$r) {
                        if (($r['category'] ?? '') === 'LOAN_INTEREST' || ($r['gl_code'] ?? '') === '4200') {
                            $r['total'] = (float)($r['total'] ?? 0) + $loanInterestIncome;
                            $r['count'] = (int)($r['count'] ?? 0) + $loanInterestEntries;
                            $r['avg_amount'] = ((int)($r['count'] ?? 0)) > 0 ? ((float)$r['total'] / (int)$r['count']) : 0;
                            $r['total_gross'] = (float)($r['total_gross'] ?? 0) + $loanInterestIncome;
                            $r['category'] = 'LOAN_INTEREST';
                            $r['gl_code'] = '4200';
                            $r['gl_account_name'] = $intGlName;
                            $mergedCat = true;
                            break;
                        }
                    }
                    unset($r);
                    if (!$mergedCat) {
                        $incomeByCategory[] = [
                            'category' => 'LOAN_INTEREST',
                            'gl_code' => '4200',
                            'gl_account_name' => $intGlName,
                            'total' => $loanInterestIncome,
                            'count' => $loanInterestEntries,
                            'avg_amount' => $loanInterestEntries > 0 ? ($loanInterestIncome / $loanInterestEntries) : 0,
                            'total_gross' => $loanInterestIncome
                        ];
                    }
                }

                // Income breakdown by branch
                $incomeByBranch = [];
                try {
                    // ★ FIX (FR-AUDIT-002): Apply RBAC branch isolation to income_by_branch.
                    // Previously this used buildPLCombinedWhere($df['where'], '') which strips
                    // the branch filter entirely — non-admin users could see ALL branches'
                    // income, violating branch isolation (data leakage).
                    // Now: if no explicit branch filter AND user is non-admin, apply their
                    // assigned branches as a filter. Admin sees all branches as before.
                    $incBrchClause = $bf['pl_branch'] ?? '';
                    $incBrchParams = $bf['pl_branch_params'] ?? [];
                    $incBrchParam = $bf['pl_branch_param'] ?? [];
                    if (empty($incBrchClause) && strtoupper($staff['role'] ?? '') !== 'ADMIN') {
                        $userBr = rptGetUserBranches($db, $staff);
                        if (!empty($userBr)) {
                            $ph = array_map(function($i) { return ':_ibr_' . $i; }, array_keys($userBr));
                            $incBrchClause = ' AND branch IN (' . implode(',', $ph) . ')';
                            $incBrchParams = array_combine(
                                array_map(function($i) { return ':_ibr_' . $i; }, array_keys($userBr)),
                                $userBr
                            );
                            $incBrchParam = []; // clear single-branch param
                        }
                    }
                    $brchWhere = buildPLCombinedWhere($df['where'], $incBrchClause) . $catFilter . $acctFilter;
                    $brchParams = array_merge($df['params'], $incBrchParams, $incBrchParam, $catParams, $acctParams);
                    $stmt = $db->prepare(
                        "SELECT branch, SUM(fee_amount) AS total, COUNT(*) AS count
                         FROM profit_ledger {$brchWhere}{$incTypeFilter}
                         GROUP BY branch ORDER BY total DESC"
                    );
                    $stmt->execute($brchParams);
                    $incomeByBranch = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    error_log('[PL Summary] Income by branch: ' . $e->getMessage());
                }

                if (!empty($loanInterestByBranch)) {
                    foreach ($loanInterestByBranch as $liRow) {
                        $liBranch = (string)($liRow['branch'] ?? '');
                        $liTotal = (float)($liRow['total'] ?? 0);
                        $liCount = (int)($liRow['count'] ?? 0);
                        $found = false;
                        foreach ($incomeByBranch as &$brRow) {
                            if (strtoupper(trim((string)($brRow['branch'] ?? ''))) === strtoupper(trim($liBranch))) {
                                $brRow['total'] = (float)($brRow['total'] ?? 0) + $liTotal;
                                $brRow['count'] = (int)($brRow['count'] ?? 0) + $liCount;
                                $found = true;
                                break;
                            }
                        }
                        unset($brRow);
                        if (!$found) {
                            $incomeByBranch[] = ['branch' => $liBranch, 'total' => $liTotal, 'count' => $liCount];
                        }
                    }
                    usort($incomeByBranch, function($a, $b) { return (float)($b['total'] ?? 0) <=> (float)($a['total'] ?? 0); });
                }

                // Income breakdown by account type
                $incomeByAcctType = [];
                try {
                    $stmt = $db->prepare(
                        "SELECT account_type, SUM(fee_amount) AS total, COUNT(*) AS count
                         FROM profit_ledger {$incWhere}{$incTypeFilter}
                         GROUP BY account_type ORDER BY total DESC"
                    );
                    $stmt->execute($incParams);
                    $incomeByAcctType = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    error_log('[PL Summary] Income by account type: ' . $e->getMessage());
                }

                if ($loanInterestIncome > 0 || $loanInterestEntries > 0) {
                    $found = false;
                    foreach ($incomeByAcctType as &$ar) {
                        if (strtoupper((string)($ar['account_type'] ?? '')) === 'LOAN') {
                            $ar['total'] = (float)($ar['total'] ?? 0) + $loanInterestIncome;
                            $ar['count'] = (int)($ar['count'] ?? 0) + $loanInterestEntries;
                            $found = true;
                            break;
                        }
                    }
                    unset($ar);
                    if (!$found) {
                        $incomeByAcctType[] = ['account_type' => 'LOAN', 'total' => $loanInterestIncome, 'count' => $loanInterestEntries];
                    }
                    usort($incomeByAcctType, function($a, $b) { return (float)($b['total'] ?? 0) <=> (float)($a['total'] ?? 0); });
                }

                // ── 2. EXPENSE SUMMARY ──
                $expWhere = buildPLCombinedExpWhere($df['exp_where'], $bf['exp_branch'] ?? '');
                $expParams = array_merge($df['exp_params'], $bf['exp_branch_params'] ?? [], $bf['exp_branch_param'] ?? []);

                $totalExpenses = 0;
                $expenseCount = 0;
                try {
                    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt FROM expenses {$expWhere}");
                    $stmt->execute($expParams);
                    $row = $stmt->fetch();
                    $totalExpenses = (float)$row['total'];
                    $expenseCount = (int)$row['cnt'];
                } catch (PDOException $e) {
                    error_log('[PL Summary] Expense total: ' . $e->getMessage());
                }

                // Expense breakdown by category
                $expensesByCategory = [];
                try {
                    $stmt = $db->prepare(
                        "SELECT category, gl_code, gl_account_name,
                                SUM(amount) AS total, COUNT(*) AS count,
                                AVG(amount) AS avg_amount
                         FROM expenses {$expWhere}
                         GROUP BY category, gl_code, gl_account_name
                         ORDER BY total DESC"
                    );
                    $stmt->execute($expParams);
                    $expensesByCategory = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    error_log('[PL Summary] Expenses by category: ' . $e->getMessage());
                }

                // Expense breakdown by branch
                $expensesByBranch = [];
                try {
                    // ★ FIX (FR-AUDIT-002): Apply RBAC branch isolation to expenses_by_branch.
                    // Same fix as income_by_branch — non-admin users must only see their branches.
                    $expBrchClause = $bf['exp_branch'] ?? '';
                    $expBrchParams = $bf['exp_branch_params'] ?? [];
                    $expBrchParam = $bf['exp_branch_param'] ?? [];
                    if (empty($expBrchClause) && strtoupper($staff['role'] ?? '') !== 'ADMIN') {
                        $userBr = rptGetUserBranches($db, $staff);
                        if (!empty($userBr)) {
                            $ph = array_map(function($i) { return ':_ebr_' . $i; }, array_keys($userBr));
                            $expBrchClause = ' AND branch IN (' . implode(',', $ph) . ')';
                            $expBrchParams = array_combine(
                                array_map(function($i) { return ':_ebr_' . $i; }, array_keys($userBr)),
                                $userBr
                            );
                            $expBrchParam = []; // clear single-branch param
                        }
                    }
                    $ebrchWhere = buildPLCombinedExpWhere($df['exp_where'], $expBrchClause);
                    $ebrchParams = array_merge($df['exp_params'], $expBrchParams, $expBrchParam);
                    $stmt = $db->prepare(
                        "SELECT branch, SUM(amount) AS total, COUNT(*) AS count
                         FROM expenses {$ebrchWhere}
                         GROUP BY branch ORDER BY total DESC"
                    );
                    $stmt->execute($ebrchParams);
                    $expensesByBranch = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    error_log('[PL Summary] Expenses by branch: ' . $e->getMessage());
                }

                // ── 3. NET PROFIT & MARGINS ──
                $netProfit = $totalIncome - $totalExpenses;
                $marginPct = $totalIncome > 0 ? round(($netProfit / $totalIncome) * 100, 1) : 0.0;

                // Operating expense ratio (OPEX)
                $opexRatio = $totalIncome > 0 ? round(($totalExpenses / $totalIncome) * 100, 1) : 0.0;

                // Operating fund balance
                $operatingFund = 0;
                try {
                    $operatingFund = computeOperatingFundForPL($db, $staff, sanitize($_GET['branch'] ?? ''));
                } catch (PDOException $e) {}

                // ── 4. PERIOD COMPARISON ──
                $comparison = null;
                if (!empty($dateFrom) && !empty($dateTo)) {
                    try {
                        // Calculate previous period of equal length
                        $start = new DateTime($dateFrom);
                        $end = new DateTime($dateTo);
                        $interval = $start->diff($end);
                        $prevEnd = (new DateTime($dateFrom))->modify('-1 day');
                        $prevStart = (clone $prevEnd)->sub($interval);

                        $prevFrom = $prevStart->format('Y-m-d');
                        $prevTo = $prevEnd->format('Y-m-d');

                        // Previous period income (same filters except dates)
                        // ★ FIX (FR-NP-001): Add gl_type='INCOME' filter to match main summary.
                        $prevIncWhere = ' WHERE DATE(created_at) >= :prev_df AND DATE(created_at) <= :prev_dt '
                            . ($bf['pl_branch'] ?? '') . $catFilter . $acctFilter . " AND gl_type = 'INCOME'"; // comparison always has date filters
                        $prevIncParams = array_merge(
                            [':prev_df' => $prevFrom, ':prev_dt' => $prevTo],
                            $bf['pl_branch_params'] ?? [],
                            $bf['pl_branch_param'] ?? [],
                            $catParams, $acctParams
                        );
                        $stmt = $db->prepare("SELECT COALESCE(SUM(fee_amount), 0) AS total, COUNT(*) AS cnt FROM profit_ledger {$prevIncWhere}");
                        $stmt->execute($prevIncParams);
                        $prevIncRow = $stmt->fetch();
                        $prevIncome = (float)$prevIncRow['total'];
                        $prevIncomeEntries = (int)$prevIncRow['cnt'];

                        if ($includeLoanInterest) {
                            $prevGlWhere = " WHERE account_code = '4200' AND date >= :prev_gl_df AND date <= :prev_gl_dt";
                            $prevGlParams = array_merge(
                                [':prev_gl_df' => $prevFrom, ':prev_gl_dt' => $prevTo],
                                $bf['pl_branch_params'] ?? [],
                                $bf['pl_branch_param'] ?? []
                            );
                            $prevGlBranchClause = $bf['pl_branch'] ?? '';
                            if (!empty($prevGlBranchClause)) {
                                $prevGlWhere .= preg_replace('/\bbranch\b/', 'branch', $prevGlBranchClause);
                            }
                            try {
                                $stmt = $db->prepare(
                                    "SELECT COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS total, COUNT(*) AS cnt
                                     FROM general_ledger {$prevGlWhere}"
                                );
                                $stmt->execute($prevGlParams);
                                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                                $prevIncome += (float)($row['total'] ?? 0);
                                $prevIncomeEntries += (int)($row['cnt'] ?? 0);
                            } catch (PDOException $e) {}
                        }

                        // Previous period expenses
                        $prevExpWhere = ' WHERE date >= :prev_exp_df AND date <= :prev_exp_dt AND status = \'APPROVED\' '
                            . ($bf['exp_branch'] ?? ''); // comparison always has date filters
                        $prevExpParams = array_merge(
                            [':prev_exp_df' => $prevFrom, ':prev_exp_dt' => $prevTo],
                            $bf['exp_branch_params'] ?? [],
                            $bf['exp_branch_param'] ?? []
                        );
                        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt FROM expenses {$prevExpWhere}");
                        $stmt->execute($prevExpParams);
                        $prevExpRow = $stmt->fetch();
                        $prevExpenses = (float)$prevExpRow['total'];
                        $prevExpenseCount = (int)$prevExpRow['cnt'];

                        $prevNet = $prevIncome - $prevExpenses;
                        $prevMargin = $prevIncome > 0 ? round(($prevNet / $prevIncome) * 100, 1) : 0.0;

                        $comparison = [
                            'previous_period' => [
                                'date_from' => $prevFrom,
                                'date_to' => $prevTo,
                                'total_income' => $prevIncome,
                                'total_expenses' => $prevExpenses,
                                'net_profit' => $prevNet,
                                'margin_pct' => $prevMargin,
                                'income_entries' => $prevIncomeEntries,
                                'expense_entries' => $prevExpenseCount
                            ],
                            'change' => [
                                'income' => $prevIncome > 0 ? round((($totalIncome - $prevIncome) / $prevIncome) * 100, 1) : 0.0,
                                'expenses' => $prevExpenses > 0 ? round((($totalExpenses - $prevExpenses) / $prevExpenses) * 100, 1) : 0.0,
                                'net_profit' => $prevNet != 0 ? round((($netProfit - $prevNet) / abs($prevNet)) * 100, 1) : 0.0,
                                'margin' => round($marginPct - $prevMargin, 1)
                            ]
                        ];
                    } catch (Throwable $e) {
                        error_log('[PL Summary] Comparison: ' . $e->getMessage());
                    }
                }

                // ── 5. KEY FINANCIAL RATIOS ──
                $ratios = [];
                try {
                    // ★ FIX (FR-AUDIT-001): Apply branch filtering to P&L ratio queries.
                    // Previously these used $db->query() with NO branch filter — non-admin users
                    // saw global ratios even when viewing a specific branch. This caused the
                    // P&L "Key Financial Ratios" to show different numbers than the Financial
                    // Ratios panel (which properly filters by branch). Now uses the same branch
                    // filter as the income/expense queries above.
                    $ratioBrFilter = rptGetBranchFilter($db, $staff);
                    // If explicit branch filter was requested, use it for ratios too
                    $ratioBranch = sanitize($_GET['branch'] ?? '');
                    if (!empty($ratioBranch)) {
                        $rb = strtoupper(trim($ratioBranch));
                        $rb = preg_replace('/\s+BRANCH$/', '', $rb);
                        $ratioBrFilter = " AND UPPER(TRIM(branch)) = :_rbr";
                        $ratioBrParams = [':_rbr' => $rb];
                    } else {
                        $ratioBrParams = [];
                    }

                    // Loan-to-Deposit ratio
                    // Use the canonical live-loan portfolio definition from the dashboard summary.
                    $stmt = $db->prepare("SELECT COALESCE(SUM(outstanding), 0) FROM loans WHERE status IN ('ACTIVE','DELINQUENT')" . $ratioBrFilter);
                    foreach ($ratioBrParams as $k => $v) $stmt->bindValue($k, $v);
                    rptBindBranchFilter($db, $stmt, $staff); $stmt->execute();
                    $totalLoans = (float)$stmt->fetchColumn();
                    $stmt = $db->prepare("SELECT COALESCE(SUM(ledger_balance), 0) FROM accounts WHERE status='ACTIVE'" . $ratioBrFilter);
                    foreach ($ratioBrParams as $k => $v) $stmt->bindValue($k, $v);
                    rptBindBranchFilter($db, $stmt, $staff); $stmt->execute();
                    $totalDepositsAmt = (float)$stmt->fetchColumn();
                    $ratios['loan_to_deposit'] = $totalDepositsAmt > 0 ? round(($totalLoans / $totalDepositsAmt) * 100, 1) : 0.0;
                    $ratios['total_loans'] = $totalLoans;
                    $ratios['total_deposits'] = $totalDepositsAmt;

                    // Cost-to-Income ratio
                    $ratios['cost_to_income'] = $totalIncome > 0 ? round(($totalExpenses / $totalIncome) * 100, 1) : 0.0;

                    // Return on Assets (simplified)
                    $stmt = $db->prepare("SELECT COALESCE(SUM(ledger_balance), 0) FROM accounts WHERE status='ACTIVE'" . $ratioBrFilter);
                    foreach ($ratioBrParams as $k => $v) $stmt->bindValue($k, $v);
                    rptBindBranchFilter($db, $stmt, $staff); $stmt->execute();
                    $totalAssets = (float)$stmt->fetchColumn();
                    $ratios['return_on_assets'] = $totalAssets > 0 ? round(($netProfit / $totalAssets) * 100, 2) : 0.0;
                    $ratios['total_assets'] = $totalAssets;
                } catch (PDOException $e) {
                    error_log('[PL Summary] Ratios: ' . $e->getMessage());
                }

                successResponse([
                    'summary' => [
                        'total_income' => $totalIncome,
                        'total_expenses' => $totalExpenses,
                        'net_profit' => $netProfit,
                        'margin_pct' => $marginPct,
                        'opex_ratio' => $opexRatio,
                        'operating_fund' => $operatingFund,
                        'income_entries' => $incomeEntries,
                        'expense_entries' => $expenseCount,
                        'period_from' => $dateFrom ?: null,
                        'period_to' => $dateTo ?: null
                    ],
                    'income_by_category' => $incomeByCategory,
                    'expenses_by_category' => $expensesByCategory,
                    'income_by_branch' => $incomeByBranch,
                    'expenses_by_branch' => $expensesByBranch,
                    'income_by_account_type' => $incomeByAcctType,
                    'comparison' => $comparison,
                    'ratios' => $ratios
                ]);

            // ═══════════════════════════════════════════════════════════════════
            //  ★ PROFIT & LOSS ENTRIES — Paginated filtered entries
            //  Parameters: date_from, date_to, branch, category, account_type,
            //              page, page_size, sort_by, sort_order
            // ═══════════════════════════════════════════════════════════════════
            } elseif ($reportType === 'profit_loss_entries') {
                ensureProfitLedgerSchema($db);
                ensureLoanFundTxSchema($db);

                $bf = buildPLBranchFilters($db, $staff, $_GET);
                $category = sanitize($_GET['category'] ?? '');
                $accountType = sanitize($_GET['account_type'] ?? '');
                $search = sanitize($_GET['search'] ?? '');

                $where = ' WHERE 1=1';
                $params = array_merge($bf['pl_branch_params'] ?? [], $bf['pl_branch_param'] ?? []);
                if (!empty($bf['pl_branch'] ?? '')) $where .= ($bf['pl_branch'] ?? '');

                $dateFrom = sanitize($_GET['date_from'] ?? '');
                $dateTo = sanitize($_GET['date_to'] ?? '');
                if (!empty($dateFrom)) { $where .= ' AND txn_date >= :pl_df'; $params[':pl_df'] = $dateFrom; }
                if (!empty($dateTo)) { $where .= ' AND txn_date <= :pl_dt'; $params[':pl_dt'] = $dateTo; }

                if (!empty($category)) {
                    $where .= ' AND category = :pl_cat';
                    $params[':pl_cat'] = $category;
                }
                if (!empty($accountType)) {
                    $where .= ' AND account_type = :pl_acct';
                    $params[':pl_acct'] = $accountType;
                }
                if (!empty($search)) {
                    $where .= ' AND (source_ref LIKE :pl_search OR customer_name LIKE :pl_search OR account_number LIKE :pl_search OR description LIKE :pl_search)';
                    $params[':pl_search'] = '%' . $search . '%';
                }

                $page = max(1, (int)($_GET['page'] ?? 1));
                $pageSize = max(1, min((int)($_GET['page_size'] ?? 50), 500));
                $offset = ($page - 1) * $pageSize;

                $sortBy = in_array($_GET['sort_by'] ?? '', ['id','created_at','fee_amount','category','branch','gl_code']) ? sanitize($_GET['sort_by']) : 'created_at';
                $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');
                if (!in_array($sortOrder, ['ASC', 'DESC'])) $sortOrder = 'DESC';

                $plSelect = "SELECT
                    ('PL-' || id::TEXT) AS id,
                    gl_code, gl_account_name, gl_type, category, source_ref, source_type,
                    account_number, account_type, customer_name, branch,
                    gross_amount, fee_amount, fee_pct, fee_mode, operator, description, created_at,
                    gl_category, total_debit, total_credit, net_amount, period_start, period_end,
                    DATE(created_at) AS txn_date
                  FROM profit_ledger";
                $liSelect = "SELECT
                    ('LI-' || lft.id::TEXT) AS id,
                    '4200' AS gl_code,
                    'Loan Interest Income' AS gl_account_name,
                    'INCOME' AS gl_type,
                    'LOAN_INTEREST' AS category,
                    COALESCE(NULLIF(lft.transaction_ref,''), NULLIF(lft.ref,''), ('LFT-' || lft.id::TEXT)) AS source_ref,
                    'LOAN_INTEREST' AS source_type,
                    COALESCE(l.loan_number,'') AS account_number,
                    'LOAN' AS account_type,
                    COALESCE(l.customer_name,'') AS customer_name,
                    COALESCE(lft.branch,'') AS branch,
                    lft.amount AS gross_amount,
                    lft.amount AS fee_amount,
                    0 AS fee_pct,
                    'LOAN' AS fee_mode,
                    COALESCE(lft.operator,'') AS operator,
                    COALESCE(lft.description,'') AS description,
                    lft.created_at AS created_at,
                    '' AS gl_category,
                    0 AS total_debit,
                    0 AS total_credit,
                    lft.amount AS net_amount,
                    NULL AS period_start,
                    NULL AS period_end,
                    lft.date AS txn_date
                  FROM loan_fund_transactions lft
                  JOIN loan_fund_accounts lfa ON lfa.id = lft.loan_fund_account_id
                  LEFT JOIN loans l ON l.id = lft.loan_id
                  WHERE lfa.account_number = 'BANK-LI-0001' AND lft.type = 'CREDIT'";
                $unionSql = "({$plSelect}) UNION ALL ({$liSelect})";

                // Count
                $countStmt = $db->prepare("SELECT COUNT(*) AS total FROM ({$unionSql}) pl {$where}");
                $countStmt->execute($params);
                $total = (int)$countStmt->fetch()['total'];

                // Fetch
                $stmt = $db->prepare(
                    "SELECT id, gl_code, gl_account_name, gl_type, category, source_ref, source_type, account_number, account_type, customer_name, branch, gross_amount, fee_amount, fee_pct, fee_mode, operator, description, created_at, gl_category, total_debit, total_credit, net_amount, period_start, period_end
                     FROM ({$unionSql}) pl {$where}
                     ORDER BY {$sortBy} {$sortOrder}
                     LIMIT :pl_limit OFFSET :pl_offset"
                );
                foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
                $stmt->bindValue(':pl_limit', $pageSize, PDO::PARAM_INT);
                $stmt->bindValue(':pl_offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                successResponse([
                    'items' => $items,
                    'total' => $total,
                    'page' => $page,
                    'page_size' => $pageSize,
                    'pages' => (int)ceil($total / $pageSize)
                ]);

            // ═══════════════════════════════════════════════════════════════════
            //  ★ PROFIT & LOSS TREND — Daily/monthly P&L trend for charts
            //  Parameters: date_from, date_to, branch, trend_mode (daily|monthly|weekly)
            // ═══════════════════════════════════════════════════════════════════
            } elseif ($reportType === 'profit_loss_trend') {
                ensureProfitLedgerSchema($db);
                ensureLoanFundTxSchema($db);

                $df = buildPLDateFilters($_GET);
                $bf = buildPLBranchFilters($db, $staff, $_GET);
                $trendMode = sanitize($_GET['trend_mode'] ?? 'daily');
                $category = sanitize($_GET['category'] ?? '');
                $accountType = sanitize($_GET['account_type'] ?? '');

                // ★ FIXED: When no date range specified, include ALL data (consistent with
                // profit_loss_summary and profit_loss_entries which also return all data).
                // Previously this defaulted to 30 days only — creating an inconsistency
                // where summary showed total for all time but trend showed only 30 days.
                if (empty($_GET['date_from']) && empty($_GET['date_to'])) {
                    // No date filter — return all data (frontend sets defaults via period buttons)
                    $df['where'] = '';
                }

                $catFilter = '';
                $catParams = [];
                if (!empty($category)) {
                    $catFilter = ' AND category = :pl_cat';
                    $catParams[':pl_cat'] = $category;
                }

                $acctFilter = '';
                $acctParams = [];
                if (!empty($accountType)) {
                    $acctFilter = ' AND account_type = :pl_acct';
                    $acctParams[':pl_acct'] = $accountType;
                }

                $incWhere = buildPLCombinedWhere($df['where'], $bf['pl_branch'] ?? '') . $catFilter . $acctFilter;
                $incParams = array_merge(
                    $df['params'],
                    $bf['pl_branch_params'] ?? [],
                    $bf['pl_branch_param'] ?? [],
                    $catParams,
                    $acctParams
                );

                $expWhere = buildPLCombinedExpWhere($df['exp_where'], $bf['exp_branch'] ?? '');
                $expParams = array_merge($df['exp_params'], $bf['exp_branch_params'] ?? [], $bf['exp_branch_param'] ?? []);

                // Date expression for grouping
                if ($trendMode === 'monthly') {
                    $dateExpr = "TO_CHAR(created_at, 'YYYY-MM')";
                    $expDateExpr = "TO_CHAR(date, 'YYYY-MM')";
                } elseif ($trendMode === 'weekly') {
                    $dateExpr = "TO_CHAR(DATE_TRUNC('week', created_at), 'YYYY-MM-DD')";
                    $expDateExpr = "TO_CHAR(DATE_TRUNC('week', date), 'YYYY-MM-DD')";
                } else {
                    $dateExpr = "DATE(created_at)";
                    $expDateExpr = "date";
                }

                // Income trend
                $incomeTrend = [];
                try {
                    // ★ FIX (FR-NP-001): Add gl_type='INCOME' filter to match Dashboard
                    // and P&L Summary. Previously trend included ALL profit_ledger entries,
                    // overstating income relative to summary and dashboard KPIs.
                    $trendIncWhere = $incWhere . ($incWhere ? " AND gl_type = 'INCOME'" : (empty($incWhere) ? " WHERE gl_type = 'INCOME'" : " AND gl_type = 'INCOME'"));
                    $stmt = $db->prepare(
                        "SELECT {$dateExpr} AS period,
                                SUM(fee_amount) AS total,
                                SUM(CASE WHEN category='WITHDRAWAL_FEE' THEN fee_amount ELSE 0 END) AS fee_income,
                                SUM(CASE WHEN category='LOAN_INTEREST' THEN fee_amount ELSE 0 END) AS interest_income,
                                SUM(CASE WHEN category='LATE_PENALTY' THEN fee_amount ELSE 0 END) AS penalty_income,
                                SUM(CASE WHEN category='TRANSFER_FEE' THEN fee_amount ELSE 0 END) AS transfer_income,
                                COUNT(*) AS entries
                         FROM profit_ledger {$trendIncWhere}
                         GROUP BY period ORDER BY period ASC"
                    );
                    $stmt->execute($incParams);
                    $incomeTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    error_log('[PL Trend] Income trend: ' . $e->getMessage());
                }

                $includeLoanInterest = true;
                if (!empty($category) && strtoupper($category) !== 'LOAN_INTEREST') $includeLoanInterest = false;
                if (!empty($accountType)) $includeLoanInterest = false;
                if ($includeLoanInterest) {
                    $liDateExpr = $trendMode === 'monthly'
                        ? "TO_CHAR(date, 'YYYY-MM')"
                        : ($trendMode === 'weekly'
                            ? "TO_CHAR(DATE_TRUNC('week', date), 'YYYY-MM-DD')"
                            : "date");

                    $liWhere = " WHERE account_code = '4200'";
                    $liParams = [];
                    $reqDf = sanitize($_GET['date_from'] ?? '');
                    $reqDt = sanitize($_GET['date_to'] ?? '');
                    if (!empty($reqDf)) { $liWhere .= " AND date >= :li_df"; $liParams[':li_df'] = $reqDf; }
                    if (!empty($reqDt)) { $liWhere .= " AND date <= :li_dt"; $liParams[':li_dt'] = $reqDt; }
                    $liBranchClause = $bf['pl_branch'] ?? '';
                    if (!empty($liBranchClause)) {
                        $liWhere .= preg_replace('/\bbranch\b/', 'branch', $liBranchClause);
                        $liParams = array_merge($liParams, $bf['pl_branch_params'] ?? [], $bf['pl_branch_param'] ?? []);
                    } elseif (strtoupper($staff['role'] ?? '') !== 'ADMIN') {
                        $userBr = rptGetUserBranches($db, $staff);
                        if (!empty($userBr) && !in_array('ALL', $userBr, true)) {
                            $ph = [];
                            foreach ($userBr as $i => $ub) {
                                $k = ':_li_ubr_' . $i;
                                $ph[] = $k;
                                $liParams[$k] = strtoupper(trim((string)$ub));
                            }
                            $liWhere .= " AND UPPER(TRIM(branch)) IN (" . implode(',', $ph) . ")";
                        }
                    }

                    $liTrend = [];
                    try {
                        $stmt = $db->prepare(
                            "SELECT {$liDateExpr} AS period,
                                    COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS total,
                                    COUNT(*) AS entries
                             FROM general_ledger {$liWhere}
                             GROUP BY period ORDER BY period ASC"
                        );
                        $stmt->execute($liParams);
                        $liTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) {
                        error_log('[PL Trend] Loan interest trend: ' . $e->getMessage());
                    }

                    $incomeMap = [];
                    foreach ($incomeTrend as $row) {
                        $incomeMap[$row['period']] = $row;
                    }
                    foreach ($liTrend as $row) {
                        $period = (string)$row['period'];
                        $amt = (float)($row['total'] ?? 0);
                        $cnt = (int)($row['entries'] ?? 0);
                        if (!isset($incomeMap[$period])) {
                            $incomeMap[$period] = [
                                'period' => $period,
                                'total' => 0,
                                'fee_income' => 0,
                                'interest_income' => 0,
                                'penalty_income' => 0,
                                'transfer_income' => 0,
                                'entries' => 0
                            ];
                        }
                        $incomeMap[$period]['total'] = (float)($incomeMap[$period]['total'] ?? 0) + $amt;
                        $incomeMap[$period]['interest_income'] = (float)($incomeMap[$period]['interest_income'] ?? 0) + $amt;
                        $incomeMap[$period]['entries'] = (int)($incomeMap[$period]['entries'] ?? 0) + $cnt;
                    }
                    $incomeTrend = array_values($incomeMap);
                    usort($incomeTrend, function($a, $b) { return strcmp((string)$a['period'], (string)$b['period']); });
                }

                // Expense trend
                $expenseTrend = [];
                try {
                    $stmt = $db->prepare(
                        "SELECT {$expDateExpr} AS period,
                                SUM(amount) AS total,
                                COUNT(*) AS entries
                         FROM expenses {$expWhere}
                         GROUP BY period ORDER BY period ASC"
                    );
                    $stmt->execute($expParams);
                    $expenseTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    error_log('[PL Trend] Expense trend: ' . $e->getMessage());
                }

                // Merge income and expense by period
                $expMap = [];
                foreach ($expenseTrend as $row) {
                    $expMap[$row['period']] = $row;
                }

                $merged = [];
                foreach ($incomeTrend as $row) {
                    $period = $row['period'];
                    $exp = $expMap[$period] ?? ['total' => 0, 'entries' => 0];
                    $merged[] = [
                        'period' => $period,
                        'income' => (float)$row['total'],
                        'expenses' => (float)$exp['total'],
                        'net' => (float)$row['total'] - (float)$exp['total'],
                        'fee_income' => (float)$row['fee_income'],
                        'interest_income' => (float)$row['interest_income'],
                        'penalty_income' => (float)$row['penalty_income'],
                        'transfer_income' => (float)$row['transfer_income'],
                        'income_entries' => (int)$row['entries'],
                        'expense_entries' => (int)$exp['entries']
                    ];
                    unset($expMap[$period]);
                }

                // Add periods with expenses but no income
                foreach ($expMap as $period => $exp) {
                    $merged[] = [
                        'period' => $period,
                        'income' => 0,
                        'expenses' => (float)$exp['total'],
                        'net' => -(float)$exp['total'],
                        'fee_income' => 0,
                        'interest_income' => 0,
                        'penalty_income' => 0,
                        'transfer_income' => 0,
                        'income_entries' => 0,
                        'expense_entries' => (int)$exp['entries']
                    ];
                }

                // Sort by period ascending
                usort($merged, function($a, $b) { return strcmp($a['period'], $b['period']); });

                successResponse([
                    'trend' => $merged,
                    'trend_mode' => $trendMode,
                    'data_points' => count($merged)
                ]);

            // ═══════════════════════════════════════════════════════════════════
            //  ★ PROFIT & LOSS COMPARISON — Current vs Previous Period
            //  Parameters: date_from, date_to, branch, category
            // ═══════════════════════════════════════════════════════════════════
            } elseif ($reportType === 'profit_loss_comparison') {
                ensureProfitLedgerSchema($db);
                ensureLoanFundTxSchema($db);

                $dateFrom = sanitize($_GET['date_from'] ?? '');
                $dateTo = sanitize($_GET['date_to'] ?? '');
                $bf = buildPLBranchFilters($db, $staff, $_GET);
                $category = sanitize($_GET['category'] ?? '');

                $catFilter = '';
                $catParams = [];
                if (!empty($category)) {
                    $catFilter = ' AND category = :pl_cat';
                    $catParams[':pl_cat'] = $category;
                }

                if (empty($dateFrom) || empty($dateTo)) {
                    errorResponse('date_from and date_to are required for comparison.', 400);
                    break;
                }

                try {
                    $start = new DateTime($dateFrom);
                    $end = new DateTime($dateTo);
                    $interval = $start->diff($end);
                    $days = $interval->days + 1;

                    $prevEnd = (new DateTime($dateFrom))->modify('-1 day');
                    $prevStart = (clone $prevEnd)->sub($interval);
                    $prevFrom = $prevStart->format('Y-m-d');
                    $prevTo = $prevEnd->format('Y-m-d');

                    // Current period income by category
                    // ★ FIX (FR-NP-001): Add gl_type='INCOME' filter to match Dashboard.
                    $curIncWhere = ' WHERE DATE(created_at) >= :c_df AND DATE(created_at) <= :c_dt '
                        . ($bf['pl_branch'] ?? '') . $catFilter . " AND gl_type = 'INCOME'";
                    $curIncParams = array_merge(
                        [':c_df' => $dateFrom, ':c_dt' => $dateTo],
                        $bf['pl_branch_params'] ?? [], $bf['pl_branch_param'] ?? [],
                        $catParams
                    );
                    $stmt = $db->prepare(
                        "SELECT category, SUM(fee_amount) AS total, COUNT(*) AS count
                         FROM profit_ledger {$curIncWhere}
                         GROUP BY category ORDER BY total DESC"
                    );
                    $stmt->execute($curIncParams);
                    $curIncByCat = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    $includeLoanInterest = empty($category) || strtoupper($category) === 'LOAN_INTEREST';
                    if ($includeLoanInterest) {
                        $liWhere = " WHERE account_code = '4200' AND date >= :li_df AND date <= :li_dt";
                        $liParams = array_merge(
                            [':li_df' => $dateFrom, ':li_dt' => $dateTo],
                            $bf['pl_branch_params'] ?? [],
                            $bf['pl_branch_param'] ?? []
                        );
                        $liBranchClause = $bf['pl_branch'] ?? '';
                        if (!empty($liBranchClause)) {
                            $liWhere .= preg_replace('/\bbranch\b/', 'branch', $liBranchClause);
                        }
                        try {
                            $stmt = $db->prepare(
                                "SELECT COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS total, COUNT(*) AS count
                                 FROM general_ledger {$liWhere}"
                            );
                            $stmt->execute($liParams);
                            $row = $stmt->fetch(PDO::FETCH_ASSOC);
                            $liTotal = (float)($row['total'] ?? 0);
                            $liCount = (int)($row['count'] ?? 0);
                            if ($liTotal > 0 || $liCount > 0) {
                                $curIncByCat[] = ['category' => 'LOAN_INTEREST', 'total' => $liTotal, 'count' => $liCount];
                            }
                        } catch (PDOException $e) {}
                    }

                    // Previous period income by category
                    // ★ FIX (FR-NP-001): Add gl_type='INCOME' filter to match Dashboard.
                    $prevIncWhere = ' WHERE DATE(created_at) >= :p_df AND DATE(created_at) <= :p_dt '
                        . ($bf['pl_branch'] ?? '') . $catFilter . " AND gl_type = 'INCOME'";
                    $prevIncParams = array_merge(
                        [':p_df' => $prevFrom, ':p_dt' => $prevTo],
                        $bf['pl_branch_params'] ?? [], $bf['pl_branch_param'] ?? [],
                        $catParams
                    );
                    $stmt = $db->prepare(
                        "SELECT category, SUM(fee_amount) AS total, COUNT(*) AS count
                         FROM profit_ledger {$prevIncWhere}
                         GROUP BY category ORDER BY total DESC"
                    );
                    $stmt->execute($prevIncParams);
                    $prevIncByCat = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (!isset($includeLoanInterest)) {
                        $includeLoanInterest = empty($category) || strtoupper($category) === 'LOAN_INTEREST';
                    }
                    if ($includeLoanInterest) {
                        $pliWhere = " WHERE account_code = '4200' AND date >= :pli_df AND date <= :pli_dt";
                        $pliParams = array_merge(
                            [':pli_df' => $prevFrom, ':pli_dt' => $prevTo],
                            $bf['pl_branch_params'] ?? [],
                            $bf['pl_branch_param'] ?? []
                        );
                        $pliBranchClause = $bf['pl_branch'] ?? '';
                        if (!empty($pliBranchClause)) {
                            $pliWhere .= preg_replace('/\bbranch\b/', 'branch', $pliBranchClause);
                        }
                        try {
                            $stmt = $db->prepare(
                                "SELECT COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS total, COUNT(*) AS count
                                 FROM general_ledger {$pliWhere}"
                            );
                            $stmt->execute($pliParams);
                            $row = $stmt->fetch(PDO::FETCH_ASSOC);
                            $liTotal = (float)($row['total'] ?? 0);
                            $liCount = (int)($row['count'] ?? 0);
                            if ($liTotal > 0 || $liCount > 0) {
                                $prevIncByCat[] = ['category' => 'LOAN_INTEREST', 'total' => $liTotal, 'count' => $liCount];
                            }
                        } catch (PDOException $e) {}
                    }

                    // Current period expenses by category
                    $curExpWhere = ' WHERE date >= :c_exp_df AND date <= :c_exp_dt AND status = \'APPROVED\' '
                        . ($bf['exp_branch'] ?? '');
                    $curExpParams = array_merge(
                        [':c_exp_df' => $dateFrom, ':c_exp_dt' => $dateTo],
                        $bf['exp_branch_params'] ?? [], $bf['exp_branch_param'] ?? []
                    );
                    $stmt = $db->prepare(
                        "SELECT category, SUM(amount) AS total, COUNT(*) AS count
                         FROM expenses {$curExpWhere}
                         GROUP BY category ORDER BY total DESC"
                    );
                    $stmt->execute($curExpParams);
                    $curExpByCat = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Previous period expenses by category
                    $prevExpWhere = ' WHERE date >= :p_exp_df AND date <= :p_exp_dt AND status = \'APPROVED\' '
                        . ($bf['exp_branch'] ?? '');
                    $prevExpParams = array_merge(
                        [':p_exp_df' => $prevFrom, ':p_exp_dt' => $prevTo],
                        $bf['exp_branch_params'] ?? [], $bf['exp_branch_param'] ?? []
                    );
                    $stmt = $db->prepare(
                        "SELECT category, SUM(amount) AS total, COUNT(*) AS count
                         FROM expenses {$prevExpWhere}
                         GROUP BY category ORDER BY total DESC"
                    );
                    $stmt->execute($prevExpParams);
                    $prevExpByCat = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Totals
                    $curTotalInc = array_sum(array_column($curIncByCat, 'total'));
                    $prevTotalInc = array_sum(array_column($prevIncByCat, 'total'));
                    $curTotalExp = array_sum(array_column($curExpByCat, 'total'));
                    $prevTotalExp = array_sum(array_column($prevExpByCat, 'total'));
                    $curNet = $curTotalInc - $curTotalExp;
                    $prevNet = $prevTotalInc - $prevTotalExp;

                    successResponse([
                        'current_period' => [
                            'date_from' => $dateFrom,
                            'date_to' => $dateTo,
                            'days' => $days,
                            'total_income' => (float)$curTotalInc,
                            'total_expenses' => (float)$curTotalExp,
                            'net_profit' => (float)$curNet,
                            'income_by_category' => $curIncByCat,
                            'expenses_by_category' => $curExpByCat
                        ],
                        'previous_period' => [
                            'date_from' => $prevFrom,
                            'date_to' => $prevTo,
                            'days' => $days,
                            'total_income' => (float)$prevTotalInc,
                            'total_expenses' => (float)$prevTotalExp,
                            'net_profit' => (float)$prevNet,
                            'income_by_category' => $prevIncByCat,
                            'expenses_by_category' => $prevExpByCat
                        ],
                        'change' => [
                            'income' => $prevTotalInc > 0 ? round((($curTotalInc - $prevTotalInc) / $prevTotalInc) * 100, 1) : 0.0,
                            'expenses' => $prevTotalExp > 0 ? round((($curTotalExp - $prevTotalExp) / $prevTotalExp) * 100, 1) : 0.0,
                            'net_profit' => $prevNet != 0 ? round((($curNet - $prevNet) / abs($prevNet)) * 100, 1) : 0.0
                        ]
                    ]);
                } catch (Throwable $e) {
                    error_log('[PL Comparison] Error: ' . $e->getMessage());
                    serverErrorResponse('Comparison generation failed.');
                }

            // ═══════════════════════════════════════════════════════════════════
            //  ★ PROFIT LEDGER DIAGNOSTIC — Enterprise debugging endpoint
            //  Returns diagnostic data to trace the P&L data pipeline:
            //  - profit_ledger table status (count, last entries)
            //  - Recent withdrawals with fees from transactions table
            //  - Missing entries (transactions with fees but no profit_ledger record)
            //  - Settings check (withdrawal fee configuration)
            // ═══════════════════════════════════════════════════════════════════
            } elseif ($reportType === 'profit_ledger_debug') {
                ensureProfitLedgerSchema($db);
                ensureLoanFundTxSchema($db);
                $diagnostics = [];
                $bf = buildPLBranchFilters($db, $staff, $_GET);
                $plWhere = buildPLCombinedWhere('', $bf['pl_branch'] ?? '');
                $plParams = array_merge($bf['pl_branch_params'] ?? [], $bf['pl_branch_param'] ?? []);
                $txnWhere = buildPLCombinedWhere(' WHERE 1=1', $bf['pl_branch'] ?? '');
                $txnParams = $plParams;

                // 1. profit_ledger table status
                try {
                    $stmt = $db->prepare("SELECT COUNT(*) FROM profit_ledger {$plWhere}");
                    $stmt->execute($plParams);
                    $plCount = (int)$stmt->fetchColumn();

                    $stmt = $db->prepare("SELECT COALESCE(SUM(fee_amount), 0) FROM profit_ledger {$plWhere}");
                    $stmt->execute($plParams);
                    $plTotalFee = (float)$stmt->fetchColumn();

                    $stmt = $db->prepare("SELECT id, category, source_ref, account_number, fee_amount, branch, created_at FROM profit_ledger {$plWhere} ORDER BY id DESC LIMIT 5");
                    $stmt->execute($plParams);
                    $plLast5 = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $diagnostics['profit_ledger'] = [
                        'total_entries' => $plCount,
                        'total_fee_income' => $plTotalFee,
                        'last_5_entries' => $plLast5
                    ];
                } catch (PDOException $e) {
                    $diagnostics['profit_ledger'] = ['error' => $e->getMessage()];
                }

                try {
                    $glWhere = buildPLCombinedWhere(" WHERE account_code = '4200'", $bf['pl_branch'] ?? '');
                    $glParams = $plParams;
                    $stmt = $db->prepare("SELECT COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) AS total, COUNT(*) AS cnt FROM general_ledger {$glWhere}");
                    $stmt->execute($glParams);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $glTotal = (float)($row['total'] ?? 0);
                    $glCnt = (int)($row['cnt'] ?? 0);
                    $stmt = $db->prepare("SELECT id, reference, date, debit, credit, branch, description FROM general_ledger {$glWhere} ORDER BY id DESC LIMIT 5");
                    $stmt->execute($glParams);
                    $glLast5 = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $diagnostics['loan_interest_gl_4200'] = [
                        'total_income' => $glTotal,
                        'entries' => $glCnt,
                        'last_5_entries' => $glLast5
                    ];
                } catch (PDOException $e) {
                    $diagnostics['loan_interest_gl_4200'] = ['error' => $e->getMessage()];
                }

                try {
                    $lftWhere = " WHERE lfa.account_number = 'BANK-LI-0001' AND lft.type = 'CREDIT'";
                    $lftParams = [];
                    $lftBranchClause = $bf['pl_branch'] ?? '';
                    if (!empty($lftBranchClause)) {
                        $lftWhere .= preg_replace('/\bbranch\b/', 'lft.branch', $lftBranchClause);
                        $lftParams = array_merge($lftParams, $bf['pl_branch_params'] ?? [], $bf['pl_branch_param'] ?? []);
                    } else {
                        $lftParams = array_merge($bf['pl_branch_params'] ?? [], $bf['pl_branch_param'] ?? []);
                    }
                    $stmt = $db->prepare(
                        "SELECT COALESCE(SUM(lft.amount),0) AS total, COUNT(*) AS cnt
                         FROM loan_fund_transactions lft
                         JOIN loan_fund_accounts lfa ON lfa.id = lft.loan_fund_account_id
                         {$lftWhere}"
                    );
                    $stmt->execute($lftParams);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $lftTotal = (float)($row['total'] ?? 0);
                    $lftCnt = (int)($row['cnt'] ?? 0);
                    $stmt = $db->prepare(
                        "SELECT lft.id, lft.transaction_ref, lft.ref, lft.date, lft.amount, lft.branch, lft.description, lft.created_at
                         FROM loan_fund_transactions lft
                         JOIN loan_fund_accounts lfa ON lfa.id = lft.loan_fund_account_id
                         {$lftWhere}
                         ORDER BY lft.id DESC LIMIT 5"
                    );
                    $stmt->execute($lftParams);
                    $lftLast5 = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $diagnostics['loan_interest_bank_li'] = [
                        'total_interest_received' => $lftTotal,
                        'transactions' => $lftCnt,
                        'last_5_transactions' => $lftLast5
                    ];
                } catch (PDOException $e) {
                    $diagnostics['loan_interest_bank_li'] = ['error' => $e->getMessage()];
                }

                // 2. Recent withdrawals with fees
                try {
                    // Ensure fee column exists
                    try { $db->query("SELECT fee FROM transactions LIMIT 1"); } catch (PDOException $e) {
                        $db->exec("ALTER TABLE transactions ADD COLUMN fee DECIMAL(20,2) DEFAULT 0");
                    }
                    try { $db->query("SELECT fee_pct FROM transactions LIMIT 1"); } catch (PDOException $e) {
                        $db->exec("ALTER TABLE transactions ADD COLUMN fee_pct DECIMAL(8,4) DEFAULT 0");
                    }

                    $stmt = $db->prepare("
                        SELECT t.id, t.ref, t.account, t.customer_name, t.branch, t.amount, t.fee, t.fee_pct, t.status, t.created_at
                        FROM transactions t
                        {$txnWhere}
                          AND t.type IN ('WITHDRAWAL', 'CASH_WITHDRAWAL', 'WITHDRAW') AND t.status = 'POSTED'
                        ORDER BY t.created_at DESC LIMIT 10
                    ");
                    $stmt->execute($txnParams);
                    $recentWithdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $diagnostics['recent_withdrawals'] = $recentWithdrawals;

                    // 3. Missing entries: withdrawals with fee > 0 but not in profit_ledger
                    $countStmt = $db->prepare("
                        SELECT COUNT(*)
                        FROM transactions t
                        {$txnWhere}
                          AND t.type IN ('WITHDRAWAL', 'CASH_WITHDRAWAL', 'WITHDRAW')
                          AND t.status = 'POSTED'
                          AND t.fee > 0
                          AND t.ref NOT IN (SELECT source_ref FROM profit_ledger WHERE source_ref IS NOT NULL AND source_ref != '')
                    ");
                    $countStmt->execute($txnParams);
                    $missingCount = (int)$countStmt->fetchColumn();

                    $stmt = $db->prepare("
                        SELECT t.id, t.ref, t.account, t.customer_name, t.branch, t.amount, t.fee, t.fee_pct, t.created_at
                        FROM transactions t
                        {$txnWhere}
                          AND t.type IN ('WITHDRAWAL', 'CASH_WITHDRAWAL', 'WITHDRAW')
                          AND t.status = 'POSTED'
                          AND t.fee > 0
                          AND t.ref NOT IN (SELECT source_ref FROM profit_ledger WHERE source_ref IS NOT NULL AND source_ref != '')
                        ORDER BY t.created_at DESC LIMIT 10
                    ");
                    $stmt->execute($txnParams);
                    $missingEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $diagnostics['missing_profit_entries'] = $missingEntries;
                    $diagnostics['missing_count'] = $missingCount;
                } catch (PDOException $e) {
                    $diagnostics['recent_withdrawals'] = ['error' => $e->getMessage()];
                    $diagnostics['missing_profit_entries'] = [];
                    $diagnostics['missing_count'] = -1;
                }

                // 4. Settings check
                try {
                    $feeSettings = $db->query("SELECT \"key\", \"value\" FROM settings WHERE LOWER(\"key\") LIKE 'withdrawal.fee_%' AND LOWER(\"key\") NOT LIKE '%mode%' ORDER BY \"key\"")->fetchAll(PDO::FETCH_ASSOC);
                    $modeSettings = $db->query("SELECT \"key\", \"value\" FROM settings WHERE LOWER(\"key\") LIKE 'withdrawal.fee_mode_%' ORDER BY \"key\"")->fetchAll(PDO::FETCH_ASSOC);
                    $diagnostics['fee_settings'] = $feeSettings;
                    $diagnostics['fee_mode_settings'] = $modeSettings;
                } catch (PDOException $e) {
                    $diagnostics['fee_settings'] = ['error' => $e->getMessage()];
                }

                successResponse($diagnostics);

            } else {
                errorResponse('Unknown report type: ' . $reportType . '. Available: dashboard, balance_summary, profit_loss, balance_trends, profit_loss_summary, profit_loss_entries, profit_loss_trend, profit_loss_comparison, profit_ledger_debug', 400);
            }

        } catch (PDOException $e) { 
            error_log('[Reports API] PDOException: ' . $e->getMessage() . ' in ' . $e->getTraceAsString()); 
            serverErrorResponse('Report generation error.'); 
        } catch (Throwable $e) {
            error_log('[Reports API] Fatal Error: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            serverErrorResponse('An unexpected error occurred during report generation.');
        }
        break;

    case 'POST':
        $input = getRequestInput();
        if (empty($input['type'])) { validationError(['type' => 'Report type is required.']); }
        try {
            $db = getDB();
            if ($input['type'] === 'profit_loss') {
                ensureProfitLedgerSchema($db);
                $insertCols = [];
                $insertParams = [];
                $colMap = [
                    'gl_code'         => [sanitize($input['gl_code'] ?? ''),         's'],
                    'gl_account_name' => [sanitize($input['gl_account_name'] ?? ''), 's'],
                    'gl_type'         => [isset($input['gl_type']) ? sanitize($input['gl_type']) : 'INCOME', 's'],
                    'category'        => [sanitize($input['category'] ?? ''),        's'],
                    'source_ref'      => [sanitize($input['source_ref'] ?? ''),      's'],
                    'source_type'     => [sanitize($input['source_type'] ?? ''),     's'],
                    'account_number'  => [sanitize($input['account_number'] ?? ''),  's'],
                    'account_type'    => [sanitize($input['account_type'] ?? ''),    's'],
                    'customer_name'   => [sanitize($input['customer_name'] ?? ''),   's'],
                    'branch'          => [sanitize($input['branch'] ?? ''),          's'],
                    'gross_amount'    => [(float)($input['gross_amount'] ?? 0),     'd'],
                    'fee_amount'      => [(float)($input['fee_amount'] ?? 0),       'd'],
                    'fee_pct'         => [(float)($input['fee_pct'] ?? 0),          'd'],
                    'fee_mode'        => [sanitize($input['fee_mode'] ?? ''),        's'],
                    'operator'        => [sanitize($input['operator'] ?? ''),        's'],
                    'description'     => [sanitize($input['description'] ?? ''),     's'],
                ];
                $existingCols = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'profit_ledger' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN, 0);
                foreach ($colMap as $col => $val) {
                    if (in_array($col, $existingCols, true)) {
                        $insertCols[] = '"' . $col . '"';
                        $insertParams[':' . $col] = $val[0];
                    } else {
                        error_log('[Reports POST] Column ' . $col . ' missing from profit_ledger, skipping.');
                    }
                }
                if (empty($insertCols)) {
                    serverErrorResponse('Profit ledger table schema is incompatible.');
                }
                $sql = 'INSERT INTO profit_ledger (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', array_keys($insertParams)) . ')';
                $stmt = $db->prepare($sql);
                $stmt->execute($insertParams);
                $newId = (int)$db->lastInsertId();
                logAudit($staff['full_name'] ?? 'System', 'PROFIT_LEDGER_ENTRY', 'REPORTS', (string)$newId, 'SUCCESS',
                    'Recorded profit ledger entry for branch ' . ($input['branch'] ?? 'all'), $staff['department'] ?? '', getClientIp());
                createdResponse(['id' => $newId], 'Profit ledger entry recorded.');
            } elseif ($input['type'] === 'backfill_profit_ledger') {
                // ═══════════════════════════════════════════════════════════════
                // ★ BACKFILL: Scan all existing transactions for fees/interest
                // that were never recorded to profit_ledger and insert them.
                // This is idempotent — skips transactions already in profit_ledger
                // (matched by source_ref). Safe to run multiple times.
                // ═══════════════════════════════════════════════════════════════
                ensureProfitLedgerSchema($db);

                $backfilled = 0;
                $skipped = 0;

                // Find withdrawal fee transactions not yet in profit_ledger
                try {
                    // ★ ENTERPRISE FIX: Fetch GL account names from chart_of_accounts
                    // to ensure 100% consistency across the entire system.
                    $glNamesStmt = $db->query("SELECT code, name FROM chart_of_accounts WHERE code IN ('4100', '4200', '4300')");
                    $glNames = $glNamesStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                    $feeGlName = $glNames['4100'] ?? 'Withdrawal Fee Income';
                    $intGlName = $glNames['4200'] ?? 'Loan Interest Income';
                    $penGlName = $glNames['4300'] ?? 'Late Penalty Income';

                    // Ensure fee column exists
                    try {
                        foreach (['fee' => 'DECIMAL(20,2) DEFAULT 0', 'fee_pct' => 'DECIMAL(8,4) DEFAULT 0'] as $col => $def) {
                            $c = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'transactions' AND column_name = '$col'")->fetch();
                            if (!$c) $db->exec("ALTER TABLE transactions ADD COLUMN $col $def");
                        }
                    } catch (PDOException $colErr) {
                        error_log('[Backfill] Column migration warning: ' . $colErr->getMessage());
                    }

                    // Check if fee column actually exists now
                    $feeColExists = (bool)$db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'transactions' AND column_name = 'fee'")->fetch();

                    if ($feeColExists) {
                        // Full backfill: match both FEE-type transactions AND withdrawals with fee > 0
                        $feeTxns = $db->query("
                            SELECT t.id, t.ref, t.account, t.account_type, t.customer_name, t.branch,
                                   t.amount, t.fee, t.fee_pct, t.fee_mode, t.created_at, t.description
                            FROM transactions t
                            WHERE t.status = 'POSTED'
                              AND ((t.type = 'FEE' AND t.category = 'Withdrawal Fee' AND t.amount > 0)
                                OR (t.type IN ('WITHDRAWAL', 'CASH_WITHDRAWAL', 'WITHDRAW') AND t.fee > 0))
                              AND t.ref NOT IN (SELECT source_ref FROM profit_ledger WHERE source_ref IS NOT NULL AND source_ref != '')
                        ")->fetchAll(PDO::FETCH_ASSOC);
                    } else {
                        // Fallback: only match FEE-type transactions (no fee column available)
                        $feeTxns = $db->query("
                            SELECT t.id, t.ref, t.account,
                                   COALESCE((SELECT at.product_type FROM accounts at WHERE at.account_number = t.account LIMIT 1), '') AS account_type,
                                   t.customer_name, t.branch,
                                   t.amount, 0 AS fee, 0 AS fee_pct, '' AS fee_mode, t.created_at, t.description
                            FROM transactions t
                            WHERE t.status = 'POSTED'
                              AND t.type = 'FEE' AND t.category = 'Withdrawal Fee' AND t.amount > 0
                              AND t.ref NOT IN (SELECT source_ref FROM profit_ledger WHERE source_ref IS NOT NULL AND source_ref != '')
                        ")->fetchAll(PDO::FETCH_ASSOC);
                    }

                    foreach ($feeTxns as $ft) {
                        $feeAmt = (float)($ft['fee'] > 0 ? $ft['fee'] : $ft['amount']);
                        $feePctVal = (float)($ft['fee_pct'] ?? 0);
                        $grossAmt = (float)$ft['amount'];
                        // For FEE type transactions, gross is the parent withdrawal amount (unknown here), use fee as gross too
                        if ((float)$ft['fee'] > 0 && $ft['type'] !== 'FEE') {
                            // This is a withdrawal with fee embedded — gross is the full withdrawal amount
                            $grossAmt = (float)$ft['amount'];
                        }
                        $db->prepare("INSERT INTO profit_ledger
                            (gl_code, gl_account_name, category, source_ref, source_type,
                             account_number, account_type, customer_name, branch,
                             gross_amount, fee_amount, fee_pct, fee_mode, operator, description)
                            VALUES ('4100', :gl_name, 'WITHDRAWAL_FEE',
                                    :ref, 'WITHDRAWAL_FEE', :acc, :atype, :cname, :branch,
                                    :gross, :fee, :pct, :mode, 'System (backfill)', :desc)")
                        ->execute([
                            ':gl_name' => $feeGlName,
                            ':ref'    => $ft['ref'],
                            ':acc'    => $ft['account'],
                            ':atype'  => $ft['account_type'] ?? '',
                            ':cname'  => $ft['customer_name'] ?? '',
                            ':branch' => $ft['branch'] ?? '',
                            ':gross'  => $grossAmt,
                            ':fee'    => $feeAmt,
                            ':pct'    => $feePctVal,
                            ':mode'   => $ft['fee_mode'] ?? 'WITHDRAWAL',
                            ':desc'   => 'Backfilled: ' . ($ft['description'] ?? 'Withdrawal fee') . ' (Txn ID: ' . $ft['id'] . ')'
                        ]);
                        $backfilled++;
                    }
                } catch (PDOException $e) {
                    error_log('[Backfill] Fee backfill error: ' . $e->getMessage());
                }

                // Find loan interest transactions not yet in profit_ledger
                try {
                    $intTxns = $db->query("
                        SELECT t.id, t.ref, t.account, t.account_type, t.customer_name, t.branch,
                               t.amount, t.created_at, t.description
                        FROM transactions t
                        WHERE t.status = 'POSTED'
                          AND t.type = 'LOAN_INTEREST' AND t.amount > 0
                          AND t.ref NOT IN (SELECT source_ref FROM profit_ledger WHERE source_ref IS NOT NULL AND source_ref != '')
                    ")->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($intTxns as $it) {
                        $db->prepare("INSERT INTO profit_ledger
                            (gl_code, gl_account_name, category, source_ref, source_type,
                             account_number, account_type, customer_name, branch,
                             gross_amount, fee_amount, fee_pct, fee_mode, operator, description)
                            VALUES ('4200', :gl_name, 'LOAN_INTEREST',
                                    :ref, 'LOAN_INTEREST', :acc, :atype, :cname, :branch,
                                    :gross, :fee, :pct, :mode, 'System (backfill)', :desc)")
                        ->execute([
                            ':gl_name' => $intGlName,
                            ':ref'    => $it['ref'],
                            ':acc'    => $it['account'],
                            ':atype'  => $it['account_type'] ?? '',
                            ':cname'  => $it['customer_name'] ?? '',
                            ':branch' => $it['branch'] ?? '',
                            ':gross'  => (float)$it['amount'],
                            ':fee'    => (float)$it['amount'],
                            ':pct'    => 0,
                            ':mode'   => 'INTEREST',
                            ':desc'   => 'Backfilled: ' . ($it['description'] ?? 'Loan interest') . ' (Txn ID: ' . $it['id'] . ')'
                        ]);
                        $backfilled++;
                    }
                } catch (PDOException $e) {
                    error_log('[Backfill] Interest backfill error: ' . $e->getMessage());
                }

                // Find late penalty transactions not yet in profit_ledger
                try {
                    $penTxns = $db->query("
                        SELECT t.id, t.ref, t.account, t.account_type, t.customer_name, t.branch,
                               t.amount, t.created_at, t.description
                        FROM transactions t
                        WHERE t.status = 'POSTED'
                          AND t.type = 'LATE_PENALTY' AND t.amount > 0
                          AND t.ref NOT IN (SELECT source_ref FROM profit_ledger WHERE source_ref IS NOT NULL AND source_ref != '')
                    ")->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($penTxns as $pt) {
                        $db->prepare("INSERT INTO profit_ledger
                            (gl_code, gl_account_name, category, source_ref, source_type,
                             account_number, account_type, customer_name, branch,
                             gross_amount, fee_amount, fee_pct, fee_mode, operator, description)
                            VALUES ('4300', :gl_name, 'LATE_PENALTY',
                                    :ref, 'LATE_PENALTY', :acc, :atype, :cname, :branch,
                                    :gross, :fee, :pct, :mode, 'System (backfill)', :desc)")
                        ->execute([
                            ':gl_name' => $penGlName,
                            ':ref'    => $pt['ref'],
                            ':acc'    => $pt['account'],
                            ':atype'  => $pt['account_type'] ?? '',
                            ':cname'  => $pt['customer_name'] ?? '',
                            ':branch' => $pt['branch'] ?? '',
                            ':gross'  => (float)$pt['amount'],
                            ':fee'    => (float)$pt['amount'],
                            ':pct'    => 0,
                            ':mode'   => 'PENALTY',
                            ':desc'   => 'Backfilled: ' . ($pt['description'] ?? 'Late penalty') . ' (Txn ID: ' . $pt['id'] . ')'
                        ]);
                        $backfilled++;
                    }
                } catch (PDOException $e) {
                    error_log('[Backfill] Penalty backfill error: ' . $e->getMessage());
                }

                logAudit($staff['full_name'] ?? 'System', 'PROFIT_LEDGER_BACKFILL', 'REPORTS', '0', 'SUCCESS',
                    'Backfilled ' . $backfilled . ' entries into profit_ledger. Skipped: ' . $skipped,
                    $staff['department'] ?? '', getClientIp());
                successResponse(['backfilled' => $backfilled, 'skipped' => $skipped, 'message' => 'Profit ledger backfill complete. ' . $backfilled . ' entries added.']);
            } else {
                errorResponse('Unknown report type for POST: ' . $input['type'] . '. Available: profit_loss', 400);
            }
        } catch (PDOException $e) {
            $errMsg = $e->getMessage();
            error_log('[Reports POST] PDO Error: ' . $errMsg . ' | Code: ' . $e->getCode() . ' | Input: ' . json_encode($input));
            if (defined('APP_DEBUG') && APP_DEBUG) {
                serverErrorResponse('Failed to record report entry: ' . $errMsg);
            } else {
                serverErrorResponse('Failed to record report entry.');
            }
        } catch (Throwable $t) {
            error_log('[Reports POST] Fatal error: ' . get_class($t) . ': ' . $t->getMessage() . ' in ' . $t->getFile() . ':' . $t->getLine());
            if (defined('APP_DEBUG') && APP_DEBUG) {
                serverErrorResponse('Internal error.');
            } else {
                serverErrorResponse('An internal error occurred. Please contact support.');
            }
        }
        break;
    default:
        errorResponse('Method not allowed.', 405);
}
