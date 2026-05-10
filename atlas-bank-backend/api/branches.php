<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Branches — Full CRUD with enterprise features
 *
 * Routes:
 *   GET    /api/branches              → List all branches (with filters & pagination)
 *   GET    /api/branches/stats         → Branch aggregate statistics
 *   GET    /api/branches/:id           → Single branch detail with financials
 *   POST   /api/branches              → Create branch
 *   PUT    /api/branches/:id          → Update branch
 *   DELETE /api/branches/:id          → Deactivate branch (soft-delete)
 *   POST   /api/branches/:id/activate  → Reactivate branch
 */

// ── Top-level error handler — prevents raw PHP errors from leaking to client ──
set_error_handler(function($severity, $msg, $file, $line) {
    if (!(error_reporting() & $severity)) return;
    throw new ErrorException($msg, 0, $severity, $file, $line);
});
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => 'Branches API fatal error. Contact system administrator.'
        ]);
        exit;
    }
});

try {

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

$staff     = requireAuth();
$method    = $_ROUTE['method'];
$id        = $_ROUTE['id'];          // numeric ID or 'stats'
$subRes    = $_ROUTE['subResource'] ?? null;

// ── GET: Any authenticated user can read branches (branch isolation is applied inside GET).
// ── POST/PUT/DELETE: Requires BRANCHES module.
if ($method !== 'GET') {
    requireModule('BRANCHES', $staff);
}

// ── Auto-create/enrich branches table ──
$db = getDB();

$db->exec("CREATE TABLE IF NOT EXISTS branches (
    id SERIAL PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(255) NOT NULL,
    region VARCHAR(100) DEFAULT NULL,
    country VARCHAR(100) NOT NULL DEFAULT 'CM',
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    address VARCHAR(500) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    manager VARCHAR(255) DEFAULT NULL,
    opened_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (code)
)");
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_branches_status ON "branches" (status)'); } catch (PDOException $e) {}
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_branches_region ON "branches" (region)'); } catch (PDOException $e) {}

// ── Self-heal: ensure dependent tables exist ──
$db->exec("CREATE TABLE IF NOT EXISTS audit_logs (
    id SERIAL PRIMARY KEY,
    uuid VARCHAR(50) NOT NULL,
    actor VARCHAR(255) NOT NULL DEFAULT 'System',
    actor_branch VARCHAR(255) DEFAULT '',
    action VARCHAR(100) NOT NULL DEFAULT '',
    entity VARCHAR(100) DEFAULT '',
    entity_id VARCHAR(100) DEFAULT '',
    result VARCHAR(20) NOT NULL DEFAULT 'SUCCESS',
    ip VARCHAR(45) DEFAULT '',
    details TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (uuid)
)");
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_audit_action ON "audit_logs" (action)'); } catch (PDOException $e) {}
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_audit_entity ON "audit_logs" (entity)'); } catch (PDOException $e) {}
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_audit_created ON "audit_logs" (created_at)'); } catch (PDOException $e) {}

$db->exec("CREATE TABLE IF NOT EXISTS staff_branches (
    id SERIAL PRIMARY KEY,
    staff_id INTEGER NOT NULL,
    branch_name VARCHAR(255) NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (staff_id, branch_name)
)");
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_sb_branch ON "staff_branches" (branch_name)'); } catch (PDOException $e) {}
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_sb_staff ON "staff_branches" (staff_id)'); } catch (PDOException $e) {}

// ── Ensure columns exist (safe ALTER — ignores if column already exists) ──
$_cols = [];
foreach ($db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'branches'")->fetchAll(PDO::FETCH_ASSOC) as $col) {
    $_cols[strtolower($col['column_name'])] = true;
}
if (!isset($_cols['address']))      $db->exec('ALTER TABLE "branches" ADD COLUMN "address" VARCHAR(500) DEFAULT NULL');
if (!isset($_cols['phone']))       $db->exec('ALTER TABLE "branches" ADD COLUMN "phone" VARCHAR(50) DEFAULT NULL');
if (!isset($_cols['manager']))     $db->exec('ALTER TABLE "branches" ADD COLUMN "manager" VARCHAR(255) DEFAULT NULL');
if (!isset($_cols['opened_date'])) $db->exec('ALTER TABLE "branches" ADD COLUMN "opened_date" DATE DEFAULT NULL');

switch ($method) {

    /* ================================================================
       GET — List, Stats, Single Detail
       ================================================================ */
    case 'GET':

        // ── GET /api/branches/stats ──
        if ($id === 'stats') {
            // Branch isolation: non-admin only sees their assigned branches
            $isAdmin = (strtoupper($staff['role'] ?? '') === 'ADMIN');
            $branchFilterClause = '';
            $branchFilterParams = [];
            if (!$isAdmin) {
                $userBranchNames = [];
                try {
                    $ubStmt = $db->prepare("SELECT branch_name FROM staff_branches WHERE staff_id = ?");
                    $ubStmt->execute([(int)$staff['id']]);
                    $userBranchNames = $ubStmt->fetchAll(PDO::FETCH_COLUMN, 0);
                } catch (PDOException $e) {}
                if (!empty($userBranchNames)) {
                    $placeholders = array_map(function($i) { return ':ubranch_' . $i; }, array_keys($userBranchNames));
                    $branchFilterClause = ' WHERE b.name IN (' . implode(',', $placeholders) . ')';
                    foreach ($userBranchNames as $i => $branch) {
                        $branchFilterParams[':ubranch_' . $i] = $branch;
                    }
                }
            }

            $statsWhere = str_replace('b.name', 'name', $branchFilterClause);
            // ★ FIX (BRNCH-003): Use prepared statements for stats counts
            $totalStmt = $db->prepare("SELECT COUNT(*) FROM branches" . ($statsWhere ?: ''));
            $totalStmt->execute($branchFilterParams);
            $total = (int)($totalStmt->fetchColumn() ?: 0);

            $activeStmt = $db->prepare("SELECT COUNT(*) FROM branches WHERE status = 'ACTIVE'" . ($statsWhere ? str_replace('WHERE', 'AND', $statsWhere) : ''));
            $activeStmt->execute($branchFilterParams);
            $active = (int)($activeStmt->fetchColumn() ?: 0);

            $inactive = $total - $active;

            // Per-branch financial aggregates — use TRY/CATCH for each sub-query
            // so that missing tables (accounts, loans, staff_branches, customers)
            // don't break the entire stats response
            $branchStats = [];
            try {
                $bsStmt = $db->prepare("
                    SELECT b.id, b.code, b.name, b.region, b.country, b.status,
                           b.address, b.phone, b.manager, b.opened_date,
                           COALESCE(ac.account_count, 0) AS account_count,
                           COALESCE(ac.total_deposits, 0) AS total_deposits,
                           COALESCE(lc.active_loans, 0) AS active_loans,
                           COALESCE(lc.loan_portfolio, 0) AS loan_portfolio,
                           COALESCE(of.operating_fund, 0) AS operating_fund,
                           COALESCE(sc.staff_count, 0) AS staff_count,
                           COALESCE(cc.customer_count, 0) AS customer_count,
                           b.created_at, b.updated_at
                    FROM branches b
                    LEFT JOIN (
                        SELECT branch, COUNT(*) AS account_count,
                               SUM(ledger_balance) AS total_deposits
                        FROM accounts WHERE status = 'ACTIVE'
                        GROUP BY branch
                    ) ac ON ac.branch = b.name
                    LEFT JOIN (
                        SELECT branch, COUNT(*) AS active_loans,
                               SUM(outstanding) AS loan_portfolio
                        FROM loans WHERE status IN ('ACTIVE','DELINQUENT')
                        GROUP BY branch
                    ) lc ON lc.branch = b.name
                    LEFT JOIN (
                        SELECT branch, SUM(debit) - SUM(credit) AS operating_fund
                        FROM general_ledger WHERE account_code = '1400'
                        GROUP BY branch
                    ) of ON of.branch = b.name
                    LEFT JOIN (
                        SELECT sb.branch_name, COUNT(*) AS staff_count
                        FROM staff_branches sb
                        INNER JOIN staff s ON sb.staff_id = s.id
                        WHERE s.employment_status = 'ACTIVE'
                        GROUP BY sb.branch_name
                    ) sc ON sc.branch_name = b.name
                    LEFT JOIN (
                        SELECT branch, COUNT(*) AS customer_count
                        FROM customers WHERE status = 'ACTIVE'
                        GROUP BY branch
                    ) cc ON cc.branch = b.name
                    ORDER BY b.name ASC" .
                    $branchFilterClause
                );
                $bsStmt->execute($branchFilterParams);
                $branchStats = $bsStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // Fallback: just get branch list without financials
                $branchStats = $db->query("SELECT * FROM branches ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
                // Add zero defaults for financial fields
                foreach ($branchStats as &$bs) {
                    $bs['account_count']   = 0;
                    $bs['total_deposits']  = 0;
                    $bs['active_loans']    = 0;
                    $bs['loan_portfolio']  = 0;
                    $bs['staff_count']     = 0;
                    $bs['customer_count']  = 0;
                }
                unset($bs);
            }

            $totalDeposits  = 0;
            $totalLoans     = 0;
            $totalOpFunds   = 0;
            $totalAccounts  = 0;
            $totalCustomers = 0;
            $totalStaff     = 0;
            foreach ($branchStats as $bs) {
                $totalDeposits  += (float)$bs['total_deposits'];
                $totalLoans     += (float)$bs['loan_portfolio'];
                $totalOpFunds   += (float)($bs['operating_fund'] ?? 0);
                $totalAccounts  += (int)$bs['account_count'];
                $totalCustomers += (int)$bs['customer_count'];
                $totalStaff     += (int)$bs['staff_count'];
            }

            successResponse([
                'summary' => [
                    'total_branches'      => $total,
                    'active_branches'     => $active,
                    'inactive_branches'   => $inactive,
                    'total_deposits'      => $totalDeposits,
                    'total_loan_portfolio'=> $totalLoans,
                    'total_operating_fund'=> $totalOpFunds,
                    'total_accounts'      => $totalAccounts,
                    'total_customers'     => $totalCustomers,
                    'total_staff'         => $totalStaff
                ],
                'branches' => $branchStats
            ]);
            break;
        }

        // ── GET /api/branches/:id ──
        if ($id !== null && is_numeric($id)) {
            $branchId = (int)$id;
            $row = $db->prepare("SELECT * FROM branches WHERE id = ?");
            $row->execute([$branchId]);
            $branch = $row->fetch(PDO::FETCH_ASSOC);
            if (!$branch) { notFoundResponse('Branch not found.'); }

            // Attach financials — resilient to missing tables
            $financials = [
                'account_count' => 0, 'total_deposits' => 0, 'active_loans' => 0,
                'loan_portfolio' => 0, 'operating_fund' => 0, 'customer_count' => 0, 'transaction_count' => 0
            ];
            try {
                $fin = $db->prepare("
                    SELECT
                        (SELECT COUNT(*) FROM accounts WHERE branch = ? AND status = 'ACTIVE') AS account_count,
                        (SELECT COALESCE(SUM(ledger_balance),0) FROM accounts WHERE branch = ? AND status = 'ACTIVE') AS total_deposits,
                        (SELECT COUNT(*) FROM loans WHERE branch = ? AND status IN ('ACTIVE','DELINQUENT')) AS active_loans,
                        (SELECT COALESCE(SUM(outstanding),0) FROM loans WHERE branch = ? AND status IN ('ACTIVE','DELINQUENT')) AS loan_portfolio,
                        (SELECT COALESCE(SUM(debit)-SUM(credit),0) FROM general_ledger WHERE branch = ? AND account_code = '1400') AS operating_fund,
                        (SELECT COUNT(*) FROM customers WHERE branch = ? AND status = 'ACTIVE') AS customer_count,
                        (SELECT COUNT(*) FROM transactions WHERE branch = ? AND status = 'POSTED') AS transaction_count
                ");
                $fin->execute([$branch['name'], $branch['name'], $branch['name'], $branch['name'], $branch['name'], $branch['name'], $branch['name']]);
                $finResult = $fin->fetch(PDO::FETCH_ASSOC);
                if ($finResult) $financials = $finResult;
            } catch (PDOException $e) {
                // Financials unavailable — use defaults
            }

            // Staff assigned to this branch — resilient to missing tables
            $branchStaff = [];
            try {
                $staffStmt = $db->prepare("
                    SELECT s.id, s.username, s.full_name, s.role, s.position, s.employment_status
                    FROM staff s
                    INNER JOIN staff_branches sb ON s.id = sb.staff_id
                    WHERE sb.branch_name = ?
                    ORDER BY s.full_name ASC
                ");
                $staffStmt->execute([$branch['name']]);
                $branchStaff = $staffStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // Staff data unavailable
            }

            $branch['financials'] = array_map(fn($v) => is_numeric($v) ? (float)$v : $v, $financials);
            $branch['staff'] = $branchStaff;

            successResponse($branch);
            break;
        }

        // ── GET /api/branches (default: list all) ──
        $params = [];
        $where  = buildWhere($_GET, ['status', 'region'], [], $params);

        // ── Branch isolation: non-admin only sees their assigned branches ──
        $isAdmin = (strtoupper($staff['role'] ?? '') === 'ADMIN');
        if (!$isAdmin) {
            $userBranchNames = [];
            try {
                $ubStmt = $db->prepare("SELECT branch_name FROM staff_branches WHERE staff_id = ?");
                $ubStmt->execute([(int)$staff['id']]);
                $userBranchNames = $ubStmt->fetchAll(PDO::FETCH_COLUMN, 0);
            } catch (PDOException $e) {}
            if (!empty($userBranchNames)) {
                $placeholders = array_map(function($i) { return ':ubranch_' . $i; }, array_keys($userBranchNames));
                $where .= ($where ? ' AND ' : 'WHERE ') . 'name IN (' . implode(',', $placeholders) . ')';
                foreach ($userBranchNames as $i => $branch) {
                    $params[':ubranch_' . $i] = $branch;
                }
            }
        }
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = max(1, min((int)($_GET['pageSize'] ?? 500), 500));
        $offset   = ($page - 1) * $pageSize;

        // Search by name or code or region or manager
        $search = sanitize($_GET['search'] ?? '');
        if (!empty($search)) {
            $searchWhere = " (name LIKE :search OR code LIKE :search2 OR region LIKE :search3 OR manager LIKE :search4)";
            $where .= ($where ? ' AND ' : 'WHERE ') . $searchWhere;
            $params[':search']  = '%' . $search . '%';
            $params[':search2'] = '%' . $search . '%';
            $params[':search3'] = '%' . $search . '%';
            $params[':search4'] = '%' . $search . '%';
        }

        $total = 0;
        $items = [];
        try {
            // ★ FIX (BRNCH-002): Use prepared statement for count to handle $params placeholders
            $totalStmt = $db->prepare("SELECT COUNT(*) AS total FROM branches " . $where);
            $totalStmt->execute($params);
            $totalRow = $totalStmt->fetch(PDO::FETCH_ASSOC);
            $total = (int)($totalRow['total'] ?? 0);

            $stmt = $db->prepare("SELECT * FROM branches " . $where . " ORDER BY id DESC LIMIT CAST(:limit AS INTEGER) OFFSET CAST(:offset AS INTEGER)");
            foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
            $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $items = $stmt->fetchAll();
        } catch (PDOException $e) {
            serverErrorResponse('Database error loading branches.');
        }

        paginatedResponse($items, $total, $page, $pageSize);
        break;

    /* ================================================================
       POST — Create Branch
       ================================================================ */
    case 'POST':

        // ── POST /api/branches/:id/activate ──
        if ($id !== null && is_numeric($id) && $subRes === 'activate') {
            $branchId = (int)$id;
            try {
                $stmt = $db->prepare("UPDATE branches SET status = 'ACTIVE', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$branchId]);
                if ($stmt->rowCount() === 0) { notFoundResponse('Branch not found.'); }
                $row = $db->prepare("SELECT * FROM branches WHERE id = ?");
                $row->execute([$branchId]);
                $branch = $row->fetch(PDO::FETCH_ASSOC);

                logAudit($staff['full_name'] ?? 'System', 'BRANCH_REACTIVATED', 'Branch', (string)$branchId, 'SUCCESS',
                    "Branch {$branch['name']} ({$branch['code']}) reactivated.", $branch['name'] ?? '');
                successResponse($branch);
            } catch (PDOException $e) { serverErrorResponse('Database error.'); }
            break;
        }

        // ── POST /api/branches (create) ──
        if ($id === null) {
            $input = getRequestInput();

            $name       = sanitize(trim($input['name'] ?? ''));
            $code       = strtoupper(sanitize(trim($input['code'] ?? '')));
            $region     = sanitize(trim($input['region'] ?? ''));
            $country    = strtoupper(sanitize(trim($input['country'] ?? 'CM')));
            $address    = sanitize(trim($input['address'] ?? ''));
            $phone      = sanitize(trim($input['phone'] ?? ''));
            $manager    = sanitize(trim($input['manager'] ?? ''));
            $openedDate = sanitize(trim($input['opened_date'] ?? ''));
            $status     = in_array(strtoupper($input['status'] ?? 'ACTIVE'), ['ACTIVE','INACTIVE'])
                          ? strtoupper($input['status']) : 'ACTIVE';

            // Validation
            $errors = [];
            if (empty($name))   $errors['name']   = 'Branch name is required.';
            if (empty($code))   $errors['code']   = 'Branch code is required.';
            if (empty($region)) $errors['region'] = 'Region is required.';
            if (strlen($code) > 20) $errors['code'] = 'Branch code must be 20 characters or less.';
            if (!preg_match('/^[A-Z0-9\-]+$/', $code)) $errors['code'] = 'Branch code must be alphanumeric (A-Z, 0-9, -).';

            // Check unique code
            if (!empty($code)) {
                $checkStmt = $db->prepare("SELECT COUNT(*) FROM branches WHERE code = ?");
                $checkStmt->execute([$code]);
                $exists = (int)$checkStmt->fetchColumn();
                if ($exists > 0) $errors['code'] = 'Branch code "' . $code . '" already exists.';
            }

            if (!empty($errors)) { validationError($errors); }

            try {
                $stmt = $db->prepare(
                    "INSERT INTO branches (code, name, region, country, status, address, phone, manager, opened_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, " . (empty($openedDate) ? "NULL" : "?") . ")"
                );
                $bindParams = [$code, $name, $region, $country ?: 'CM', $status, $address, $phone, $manager];
                if (!empty($openedDate)) $bindParams[] = $openedDate;
                $stmt->execute($bindParams);
                $newId = (int)$db->lastInsertId('branches_id_seq');

                $rowStmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
                $rowStmt->execute([$newId]);
                $row = $rowStmt->fetch(PDO::FETCH_ASSOC);

                logAudit($staff['full_name'] ?? 'System', 'BRANCH_CREATED', 'Branch', (string)$newId, 'SUCCESS',
                    "New branch {$name} ({$code}) created in {$region}, {$country}.", $name);

                createdResponse($row);
            } catch (PDOException $e) { serverErrorResponse('Database error creating branch.'); }
            break;
        }

        errorResponse('Method not allowed.', 405);
        break;

    /* ================================================================
       PUT — Update Branch
       ================================================================ */
    case 'PUT':
        if ($id !== null && is_numeric($id)) {
            $branchId = (int)$id;
            $input = getRequestInput();

            // Fetch existing
            $existing = $db->prepare("SELECT * FROM branches WHERE id = ?");
            $existing->execute([$branchId]);
            $current = $existing->fetch(PDO::FETCH_ASSOC);
            if (!$current) { notFoundResponse('Branch not found.'); }

            $name       = sanitize(trim($input['name'] ?? $current['name']));
            $code       = strtoupper(sanitize(trim($input['code'] ?? $current['code'])));
            $region     = sanitize(trim($input['region'] ?? $current['region']));
            $country    = strtoupper(sanitize(trim($input['country'] ?? $current['country'] ?? 'CM')));
            $address    = sanitize(trim($input['address'] ?? ''));
            $phone      = sanitize(trim($input['phone'] ?? ''));
            $manager    = sanitize(trim($input['manager'] ?? ''));
            $openedDate = sanitize(trim($input['opened_date'] ?? ''));
            if (isset($input['status'])) {
                $status = in_array(strtoupper($input['status']), ['ACTIVE','INACTIVE']) ? strtoupper($input['status']) : $current['status'];
            } else {
                $status = $current['status'];
            }

            // Validate code uniqueness (exclude self)
            if ($code !== $current['code']) {
                $checkStmt = $db->prepare("SELECT COUNT(*) FROM branches WHERE code = ? AND id != ?");
                $checkStmt->execute([$code, $branchId]);
                $exists = (int)$checkStmt->fetchColumn();
                if ($exists > 0) { validationError(['code' => 'Branch code "' . $code . '" already exists.']); }
            }

            // Build dynamic UPDATE (only set fields that were provided)
            $setClauses = [];
            $bindValues = [];
            if (isset($input['name']))         { $setClauses[] = 'name = ?';        $bindValues[] = $name; }
            if (isset($input['code']))         { $setClauses[] = 'code = ?';        $bindValues[] = $code; }
            if (isset($input['region']))       { $setClauses[] = 'region = ?';      $bindValues[] = $region; }
            if (isset($input['country']))      { $setClauses[] = 'country = ?';     $bindValues[] = $country; }
            if (isset($input['status']))       { $setClauses[] = 'status = ?';      $bindValues[] = $status; }
            if (isset($input['address']))      { $setClauses[] = 'address = ?';     $bindValues[] = $address; }
            if (isset($input['phone']))        { $setClauses[] = 'phone = ?';       $bindValues[] = $phone; }
            if (isset($input['manager']))      { $setClauses[] = 'manager = ?';     $bindValues[] = $manager; }
            if (isset($input['opened_date']))  { $setClauses[] = 'opened_date = ' . (empty($openedDate) ? 'NULL' : '?'); if (!empty($openedDate)) $bindValues[] = $openedDate; }

            if (empty($setClauses)) { errorResponse('No fields to update.', 400); }

            $setClauses[] = 'updated_at = NOW()';
            $bindValues[] = $branchId;

            try {
                $sql = "UPDATE branches SET " . implode(', ', $setClauses) . " WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute($bindValues);

                $rowStmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
                $rowStmt->execute([$branchId]);
                $row = $rowStmt->fetch(PDO::FETCH_ASSOC);

                if ($status === 'INACTIVE' && $current['status'] === 'ACTIVE') {
                    $action = 'BRANCH_DEACTIVATED';
                } elseif ($status === 'ACTIVE' && $current['status'] === 'INACTIVE') {
                    $action = 'BRANCH_REACTIVATED';
                } else {
                    $action = 'BRANCH_UPDATED';
                }
                logAudit($staff['full_name'] ?? 'System', $action, 'Branch', (string)$branchId, 'SUCCESS',
                    "Branch {$row['name']} ({$row['code']}) updated.", $row['name'] ?? '');

                successResponse($row);
            } catch (PDOException $e) { serverErrorResponse('Database error updating branch.'); }
        } else {
            errorResponse('Method not allowed.', 405);
        }
        break;

    /* ================================================================
       DELETE — Deactivate Branch (soft-delete)
       ================================================================ */
    case 'DELETE':
        if ($id !== null && is_numeric($id)) {
            $branchId = (int)$id;
            try {
                // Check if branch exists
                $existing = $db->prepare("SELECT * FROM branches WHERE id = ?");
                $existing->execute([$branchId]);
                $current = $existing->fetch(PDO::FETCH_ASSOC);
                if (!$current) { notFoundResponse('Branch not found.'); }

                // Check if branch has active accounts (resilient to missing table)
                $accountCount = 0;
                try {
                    $accStmt = $db->prepare("SELECT COUNT(*) FROM accounts WHERE branch = ? AND status = 'ACTIVE'");
                    $accStmt->execute([$current['name']]);
                    $accountCount = (int)$accStmt->fetchColumn();
                } catch (PDOException $e) {
                    // accounts table may not exist — allow deactivation
                }

                if ($accountCount > 0) {
                    validationError(['accounts' => "Cannot deactivate branch with {$accountCount} active account(s). Transfer or close all accounts first."]);
                }

                $stmt = $db->prepare("UPDATE branches SET status = 'INACTIVE', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$branchId]);
                if ($stmt->rowCount() === 0) { notFoundResponse('Branch not found.'); }

                $rowStmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
                $rowStmt->execute([$branchId]);
                $row = $rowStmt->fetch(PDO::FETCH_ASSOC);

                logAudit($staff['full_name'] ?? 'System', 'BRANCH_DEACTIVATED', 'Branch', (string)$branchId, 'SUCCESS',
                    "Branch {$row['name']} ({$row['code']}) deactivated.", $row['name'] ?? '');

                successResponse($row);
            } catch (PDOException $e) { serverErrorResponse('Database error deactivating branch.'); }
        } else {
            errorResponse('Method not allowed.', 405);
        }
        break;

    default:
        errorResponse('Method not allowed.', 405);
}

} catch (Throwable $e) {
    // SECURITY: Never expose internal error details to client.
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    error_log('[Branches API] Unhandled exception: ' . get_class($e) . ': ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error'   => 'Branches API error.'
    ]);
    exit;
}
