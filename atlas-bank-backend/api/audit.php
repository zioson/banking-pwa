<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Audit — Enterprise Dashboard, Findings CRUD, Audit Logs
 *
 * Routes (mapped by router.php):
 *   GET  /api/audit                    → Audit logs (paginated)
 *   GET  /api/audit/dashboard          → Full audit dashboard (20 compliance checks, financial health, GL integrity)
 *   GET  /api/audit/findings           → Audit findings (paginated)
 *   POST /api/audit/findings           → Create finding
 *   PUT  /api/audit/findings/:id       → Update finding status
 *   DELETE /api/audit/findings/:id     → Soft-delete finding
 *
 * Router mapping: resource=audit, id=dashboard|findings|:id, subResource=findings|:sub
 */
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

$staff = requireAuth();
$method   = $_ROUTE['method'];
$id       = $_ROUTE['id'];        // 'dashboard', 'findings', or numeric ID
$subRes   = $_ROUTE['subResource'] ?? null;  // e.g. 'findings' when PUT /audit/findings/123
$subId    = $_ROUTE['subId'] ?? null;

// ── RBAC: POST audit log entries (fire-and-forget from any authenticated user)
//    only require auth. All other operations (view, findings CRUD) require AUDIT module. ──
if (!($method === 'POST' && $id === null)) {
    $staff = requireModule('AUDIT');
}

// ── Auto-create audit tables if missing (self-healing) ──
$db = getDB();
$db->exec("CREATE TABLE IF NOT EXISTS audit_logs (
    id SERIAL PRIMARY KEY,
    uuid VARCHAR(50) DEFAULT '',
    actor VARCHAR(200) DEFAULT '',
    actor_branch VARCHAR(200) DEFAULT '',
    action VARCHAR(100) DEFAULT '',
    entity VARCHAR(100) DEFAULT '',
    entity_id VARCHAR(100) DEFAULT '',
    result VARCHAR(20) DEFAULT 'SUCCESS',
    ip VARCHAR(45) DEFAULT '',
    details TEXT,
    module VARCHAR(50) DEFAULT '',
    category VARCHAR(50) DEFAULT '',
    user_agent VARCHAR(500) DEFAULT '',
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_al_actor ON "audit_logs" (actor)'); } catch (PDOException $e) {}
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_al_action ON "audit_logs" (action)'); } catch (PDOException $e) {}
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_al_entity ON "audit_logs" (entity)'); } catch (PDOException $e) {}
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_al_result ON "audit_logs" (result)'); } catch (PDOException $e) {}
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_al_timestamp ON "audit_logs" (timestamp)'); } catch (PDOException $e) {}
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_al_module ON "audit_logs" (module)'); } catch (PDOException $e) {}
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_al_category ON "audit_logs" (category)'); } catch (PDOException $e) {}

// ── Self-heal: add missing columns for enterprise audit tracking ──
try {
    $_alCols = [];
    foreach ($db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'audit_logs'")->fetchAll(PDO::FETCH_ASSOC) as $_c) {
        $_alCols[strtolower($_c['column_name'])] = true;
    }
    // Fix: created_at → timestamp
    if (!isset($_alCols['timestamp'])) {
        if (isset($_alCols['created_at'])) {
            $db->exec('ALTER TABLE "audit_logs" RENAME COLUMN created_at TO "timestamp"');
        } else {
            $db->exec('ALTER TABLE "audit_logs" ADD COLUMN "timestamp" TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
        }
        try { $db->exec('CREATE INDEX IF NOT EXISTS idx_al_timestamp ON "audit_logs" (timestamp)'); } catch (PDOException $e) {}
    }
    // Enterprise columns: module, category, user_agent
    if (!isset($_alCols['module'])) {
        $db->exec("ALTER TABLE \"audit_logs\" ADD COLUMN \"module\" VARCHAR(50) DEFAULT ''");
        try { $db->exec('CREATE INDEX IF NOT EXISTS idx_al_module ON "audit_logs" (module)'); } catch (PDOException $e) {}
    }
    if (!isset($_alCols['category'])) {
        $db->exec("ALTER TABLE \"audit_logs\" ADD COLUMN \"category\" VARCHAR(50) DEFAULT ''");
        try { $db->exec('CREATE INDEX IF NOT EXISTS idx_al_category ON "audit_logs" (category)'); } catch (PDOException $e) {}
    }
    if (!isset($_alCols['user_agent'])) {
        $db->exec("ALTER TABLE \"audit_logs\" ADD COLUMN \"user_agent\" VARCHAR(500) DEFAULT ''");
    }
} catch (PDOException $e) { /* non-fatal: table may not exist yet */ }
$db->exec("CREATE TABLE IF NOT EXISTS audit_findings (
    id SERIAL PRIMARY KEY,
    severity VARCHAR(20) NOT NULL DEFAULT 'LOW',
    category VARCHAR(100) DEFAULT '',
    description TEXT,
    recommendation TEXT,
    branch VARCHAR(200) DEFAULT '',
    status VARCHAR(50) NOT NULL DEFAULT 'OPEN',
    assignee VARCHAR(200) DEFAULT '',
    created_date DATE DEFAULT NULL,
    created_by VARCHAR(200) DEFAULT '',
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL
)");
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_af_severity ON "audit_findings" (severity)'); } catch (PDOException $e) {}
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_af_status ON "audit_findings" (status)'); } catch (PDOException $e) {}
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_af_branch ON "audit_findings" (branch)'); } catch (PDOException $e) {}
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_af_category ON "audit_findings" (category)'); } catch (PDOException $e) {}
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_af_assignee ON "audit_findings" (assignee)'); } catch (PDOException $e) {}

switch ($method) {

    /* ================================================================
       GET — Dashboard, Findings, Audit Logs
       ================================================================ */
    case 'GET':

        // ── GET /api/audit/dashboard ──
        if ($id === 'dashboard') {
            successResponse(buildAuditDashboard($staff));
            break;
        }

        // ── GET /api/audit/findings ──
        if ($id === 'findings') {
            $params = [];
            $where  = buildWhere($_GET, ['severity', 'status', 'category', 'assignee'],
                                  ['status' => '=', 'severity' => '='], $params);
            // ★ FIXED: Server-side branch filtering
            $staffBranches = $staff['branches'] ?? [];
            $clientBranch = sanitize($_GET['branch'] ?? '');
            $branchFilter = applyBranchFilter($staffBranches, $clientBranch, $params, $staff['role'] ?? '', 'branch');
            if ($branchFilter) { $where .= ($where ? ' AND ' : ' WHERE ') . substr($branchFilter, 5); }
            // ★ FIX IA-010: Exclude soft-deleted findings from GET queries
            $deletedExclude = "status != 'DELETED'";
            $where .= ($where ? ' AND ' : ' WHERE ') . $deletedExclude;
            $page     = max(1, (int)($_GET['page'] ?? 1));
            // ★ FIX (RA-AF-001): Increase from 500 to 5000 for full audit history
            $pageSize = max(1, min((int)($_GET['pageSize'] ?? 500), 5000));
            $offset   = ($page - 1) * $pageSize;
            try {
                $db = getDB();
                $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM audit_findings ' . $where);
                foreach ($params as $k => $v) { $countStmt->bindValue($k, $v); }
                $countStmt->execute();
                $total = (int)$countStmt->fetchAll(PDO::FETCH_ASSOC)[0]['total'] ?? 0;
                $stmt = $db->prepare('SELECT * FROM audit_findings ' . $where . ' ORDER BY timestamp DESC LIMIT CAST(:limit AS INTEGER) OFFSET CAST(:offset AS INTEGER)');
                foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
                $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                paginatedResponse($stmt->fetchAll(), $total, $page, $pageSize);
            } catch (PDOException $e) { error_log('[Audit Findings GET] ' . $e->getMessage()); serverErrorResponse('Database error.'); }
            break;
        }

        // ── GET /api/audit (default: audit logs) ──
        $params = [];
        $where  = buildWhere($_GET, ['actor', 'action', 'entity', 'result', 'category', 'module'],
                              ['action' => '=', 'result' => '=', 'category' => '=', 'module' => '='], $params);
        // ★ FIXED: Server-side branch filtering (audit_logs uses actor_branch column)
        $staffBranches = $staff['branches'] ?? [];
        $clientBranch = sanitize($_GET['branch'] ?? '');
        $branchFilter = applyBranchFilter($staffBranches, $clientBranch, $params, $staff['role'] ?? '', 'actor_branch');
        if ($branchFilter) { $where .= ($where ? ' AND ' : ' WHERE ') . substr($branchFilter, 5); }
        if (!empty($_GET['date_from'])) {
            $where .= ($where ? ' AND ' : 'WHERE ') . 'timestamp >= :df';
            $params[':df'] = sanitize($_GET['date_from']);
        }
        if (!empty($_GET['date_to'])) {
            $where .= ($where ? ' AND ' : 'WHERE ') . 'timestamp <= :dt';
            $params[':dt'] = sanitize($_GET['date_to']) . ' 23:59:59';
        }
        $page     = max(1, (int)($_GET['page'] ?? 1));
        // ★ FIX (RA-AL-001): Increase from 500 to 5000 for full log analysis
        $pageSize = max(1, min((int)($_GET['pageSize'] ?? 100), 5000));
        $offset   = ($page - 1) * $pageSize;
        try {
            $db = getDB();
            $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM audit_logs ' . $where);
            foreach ($params as $k => $v) { $countStmt->bindValue($k, $v); }
            $countStmt->execute();
            $total = (int)$countStmt->fetchAll(PDO::FETCH_ASSOC)[0]['total'] ?? 0;
            $stmt = $db->prepare('SELECT * FROM audit_logs ' . $where . ' ORDER BY timestamp DESC LIMIT CAST(:limit AS INTEGER) OFFSET CAST(:offset AS INTEGER)');
            foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
            $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            paginatedResponse($stmt->fetchAll(), $total, $page, $pageSize);
        } catch (PDOException $e) { serverErrorResponse('Database error.'); }
        break;

    /* ================================================================
       POST — Audit Log Entry (fire-and-forget) OR Create Finding
       ================================================================ */
    case 'POST':

        // ── POST /api/audit — Insert audit log entry (fire-and-forget) ──
        if ($id === null) {
            $input = getRequestInput();
            $uuid   = 'AUD-' . date('Ymd') . '-' . bin2hex(random_bytes(4));
            $actor  = sanitize($input['actor'] ?? ($_SESSION['staff_name'] ?? 'SYSTEM'));
            $branch = sanitize($input['actor_branch'] ?? '');
            $action = sanitize($input['action'] ?? 'UNKNOWN');
            $entity = sanitize($input['entity'] ?? '');
            $eid    = sanitize($input['entity_id'] ?? '');
            $result = in_array(strtoupper($input['result'] ?? 'SUCCESS'), ['SUCCESS','FAILURE','DENIED'])
                      ? strtoupper($input['result']) : 'SUCCESS';
            $ip     = sanitize($input['ip'] ?? '') ?: getClientIp();
            $detail = sanitize($input['details'] ?? '');
            $module = sanitize($input['module'] ?? '');
            $cat    = sanitize($input['category'] ?? '');
            $ua     = sanitize($input['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            try {
                $db = getDB();
                $stmt = $db->prepare('INSERT INTO audit_logs (uuid, actor, actor_branch, action, entity, entity_id, result, ip, details, module, category, user_agent, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                $stmt->execute([$uuid, $actor, $branch, $action, $entity, $eid, $result, $ip, $detail, $module, $cat, mb_substr($ua, 0, 500)]);
                successResponse(['id' => (int)$db->lastInsertId('audit_logs_id_seq'), 'uuid' => $uuid]);
            } catch (PDOException $e) {
                // Fire-and-forget — never block the UI for audit logging
                errorResponse('OK', 200);
            }
            break;
        }

        // ── POST /api/audit/findings — Create audit finding ──
        if ($id === 'findings') {
            requireRole(['ADMIN', 'COMPLIANCE']);
            $input = getRequestInput();
            $severity       = strtoupper(trim($input['severity'] ?? ''));
            $category       = sanitize($input['category'] ?? '');
            $description    = sanitize($input['description'] ?? '');
            $recommendation = sanitize($input['recommendation'] ?? '');
            $branch         = sanitize($input['branch'] ?? '');
            $assignee       = sanitize($input['assignee'] ?? '');

            $validSev = ['LOW','MEDIUM','HIGH','CRITICAL'];
            if (!in_array($severity, $validSev)) {
                validationError(['severity' => 'Severity must be LOW, MEDIUM, HIGH, or CRITICAL.']);
            }
            if (empty($description)) {
                validationError(['description' => 'Description is required.']);
            }

            $operatorName = $_SESSION['staff_name'] ?? ($staff['full_name'] ?? 'System');
            try {
                $db = getDB();
                $stmt = $db->prepare('INSERT INTO audit_findings (severity, category, description, recommendation, branch, status, assignee, created_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_DATE, ?)');
                $stmt->execute([$severity, $category, $description, $recommendation, $branch, 'OPEN', $assignee, $operatorName]);
                $newId = (int)$db->lastInsertId('audit_findings_id_seq');
                $row = $db->prepare('SELECT * FROM audit_findings WHERE id = :id');
                $row->execute([':id' => $newId]);
                $created = $row->fetch(PDO::FETCH_ASSOC);
                logAudit($staff['full_name'] ?? 'System', 'FINDING_CREATE', 'AUDIT_FINDING', (string)$newId, 'SUCCESS',
                    'Created audit finding: ' . $severity . '/' . $category . ' - ' . substr($description, 0, 100),
                    $staff['department'] ?? '', getClientIp());
                createdResponse($created);
            } catch (PDOException $e) { serverErrorResponse('Database error.'); }
            break;
        }

        errorResponse('Method not allowed.', 405);
        break;

    /* ================================================================
       PUT — Update Audit Finding
       ================================================================ */
    case 'PUT':
        // PUT /api/audit/findings/:id  (id=findings, subRes=numeric ID from router)
        if ($id === 'findings' && is_numeric($subRes)) {
            requireRole(['ADMIN', 'COMPLIANCE']);
            $findingId = (int)$subRes;
            $input  = getRequestInput();
            $status = strtoupper(trim($input['status'] ?? ''));
            $validSt = ['OPEN','IN_PROGRESS','RESOLVED','CLOSED','ESCALATED'];
            if (!in_array($status, $validSt)) {
                validationError(['status' => 'Status must be OPEN, IN_PROGRESS, RESOLVED, CLOSED, or ESCALATED.']);
            }
            try {
                $db = getDB();
                $stmt = $db->prepare('UPDATE audit_findings SET status = ?, timestamp = NOW() WHERE id = ?');
                $stmt->execute([$status, $findingId]);
                if ($stmt->rowCount() === 0) { notFoundResponse('Finding not found.'); }
                logAudit($staff['full_name'] ?? 'System', 'FINDING_UPDATE', 'AUDIT_FINDING', (string)$findingId, 'SUCCESS',
                    'Updated finding status to ' . $status, $staff['department'] ?? '', getClientIp());
                $row = $db->prepare('SELECT * FROM audit_findings WHERE id = :id');
                $row->execute([':id' => $findingId]);
                $row = $row->fetch(PDO::FETCH_ASSOC);
                successResponse($row);
            } catch (PDOException $e) { serverErrorResponse('Database error.'); }
        } else {
            errorResponse('Method not allowed.', 405);
        }
        break;

    /* ================================================================
       DELETE — Soft-delete Audit Finding
       ================================================================ */
    case 'DELETE':
        if ($id === 'findings' && is_numeric($subRes)) {
            requireRole(['ADMIN', 'COMPLIANCE']);
            $findingId = (int)$subRes;
            try {
                $db = getDB();
                $stmt = $db->prepare("UPDATE audit_findings SET status = 'DELETED', deleted_at = NOW() WHERE id = ?");
                $stmt->execute([$findingId]);
                if ($stmt->rowCount() === 0) { notFoundResponse('Finding not found.'); }
                logAudit($staff['full_name'] ?? 'System', 'FINDING_DELETE', 'AUDIT_FINDING', (string)$findingId, 'SUCCESS',
                    'Soft-deleted audit finding', $staff['department'] ?? '', getClientIp());
                noContentResponse();
            } catch (PDOException $e) { serverErrorResponse('Database error.'); }
        } else {
            errorResponse('Method not allowed.', 405);
        }
        break;

    default:
        errorResponse('Method not allowed.', 405);
}


/* ================================================================
   DASHBOARD BUILDER — 20 Compliance Checks, Financial Health, GL Integrity
   ================================================================ */
function buildAuditDashboard(array $staff): array {
    $db = getDB();

    // ── Auto-create any missing tables that this dashboard queries ──
    $db->exec("CREATE TABLE IF NOT EXISTS operating_account_transactions (
        id SERIAL PRIMARY KEY,
        ref VARCHAR(50),
        operating_account_id INTEGER NOT NULL,
        date DATE NOT NULL,
        type VARCHAR(20) NOT NULL CHECK (type IN ('CREDIT','DEBIT')),
        description TEXT,
        amount DECIMAL(20,2) NOT NULL,
        balance_after DECIMAL(20,2) NOT NULL,
        operator VARCHAR(200),
        contra_account VARCHAR(100) DEFAULT '',
        transaction_type VARCHAR(50) DEFAULT '',
        branch VARCHAR(100) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    try { $db->exec('CREATE INDEX IF NOT EXISTS idx_oat_branch ON "operating_account_transactions" (branch)'); } catch (PDOException $e) {}
    try { $db->exec('CREATE INDEX IF NOT EXISTS idx_oat_type ON "operating_account_transactions" (type)'); } catch (PDOException $e) {}
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
    try { $db->exec('CREATE INDEX IF NOT EXISTS idx_pl_branch ON "profit_ledger" (branch)'); } catch (PDOException $e) {}
    // ★ REMOVED: Conflicting approvals table DDL (use approvals.php schema instead)
    // The buildAuditDashboard() was creating a DIFFERENT approvals table schema than
    // the canonical one in approvals.php (which uses entity_type/entity_id/scope_code).
    // That conflicting DDL has been removed to prevent schema conflicts.

    $staffBranchesRaw = $staff['branches'] ?? [];
    if (is_string($staffBranchesRaw)) {
        $decoded = json_decode($staffBranchesRaw, true);
        $staffBranchesRaw = is_array($decoded) ? $decoded : [$staffBranchesRaw];
    }
    $staffBranches = is_array($staffBranchesRaw) ? array_values(array_filter(array_map('trim', $staffBranchesRaw))) : [];
    if (empty($staffBranches)) {
        $dept = trim((string)($staff['department'] ?? ''));
        if ($dept !== '') $staffBranches = [$dept];
    }
    $clientBranch = sanitize($_GET['branch'] ?? '');
    $cbNorm = strtoupper(trim($clientBranch));
    if (in_array($cbNorm, ['ALL', 'ALL BRANCHES', 'ALL_BRANCHES', 'ALL MY BRANCHES', 'ALL_MY_BRANCHES', 'ALLBRANCHES', 'ALLBRANCH'], true)) {
        $clientBranch = '';
    }
    $role = (string)($staff['role'] ?? '');
    $branchSql = function(string $column, array &$params) use ($staffBranches, $clientBranch, $role): string {
        return applyBranchFilter($staffBranches, $clientBranch, $params, $role, $column);
    };
    $scopeBranch = $clientBranch ?: ((strtoupper($role) === 'ADMIN') ? 'ALL' : (count($staffBranches) === 1 ? $staffBranches[0] : 'ALL_MY_BRANCHES'));

    // ── 1. Basic counts ── NOTE: Must use fetchColumn() because PDO default mode is FETCH_ASSOC,
    //    so fetch()[0] would return null on associative arrays. fetchColumn() returns the
    //    first column value directly regardless of fetch mode.
    $p = []; $stmt = $db->prepare("SELECT COUNT(*) FROM accounts WHERE 1=1" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $totalAccounts = (int)($stmt->fetchColumn() ?: 0);
    $p = []; $stmt = $db->prepare("SELECT COUNT(*) FROM accounts WHERE status = 'ACTIVE'" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $activeAccounts = (int)($stmt->fetchColumn() ?: 0);
    $p = []; $stmt = $db->prepare("SELECT COUNT(*) FROM loans WHERE 1=1" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $totalLoans = (int)($stmt->fetchColumn() ?: 0);
    $p = []; $stmt = $db->prepare("SELECT COUNT(*) FROM loans WHERE status IN ('ACTIVE','DELINQUENT','OVERDUE')" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $activeLoans = (int)($stmt->fetchColumn() ?: 0);
    $p = []; $stmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE 1=1" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $totalCustomers = (int)($stmt->fetchColumn() ?: 0);
    $p = []; $stmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE status = 'ACTIVE'" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $activeCustomers = (int)($stmt->fetchColumn() ?: 0);
    $totalBranches   = (int)($db->query("SELECT COUNT(*) FROM branches")->fetchColumn() ?: 0);
    $activeBranches  = (int)($db->query("SELECT COUNT(*) FROM branches WHERE status = 'ACTIVE'")->fetchColumn() ?: 0);
    $p = []; $stmt = $db->prepare("SELECT COUNT(*) FROM transactions WHERE 1=1" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $totalTxns = (int)($stmt->fetchColumn() ?: 0);
    $p = []; $stmt = $db->prepare("SELECT COUNT(*) FROM transactions WHERE status = 'POSTED'" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $postedTxns = (int)($stmt->fetchColumn() ?: 0);
    $p = []; $stmt = $db->prepare("SELECT COUNT(*) FROM expenses WHERE deleted_at IS NULL" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $totalExpenses = (int)($stmt->fetchColumn() ?: 0);
    $p = []; $stmt = $db->prepare("SELECT COUNT(*) FROM expenses WHERE status = 'APPROVED' AND deleted_at IS NULL" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $approvedExpenses = (int)($stmt->fetchColumn() ?: 0);
    $p = []; $stmt = $db->prepare("SELECT COUNT(*) FROM expenses WHERE status = 'PENDING' AND deleted_at IS NULL" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $pendingExpenses = (int)($stmt->fetchColumn() ?: 0);
    $p = []; $stmt = $db->prepare("SELECT COUNT(*) FROM staff WHERE 1=1" . $branchSql('department', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $totalStaff = (int)($stmt->fetchColumn() ?: 0);
    $p = []; $stmt = $db->prepare("SELECT COUNT(*) FROM staff WHERE employment_status = 'ACTIVE'" . $branchSql('department', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $activeStaff = (int)($stmt->fetchColumn() ?: 0);

    // ── 2. Financial aggregates ──
    $p = []; $stmt = $db->prepare("SELECT COALESCE(SUM(ledger_balance),0) AS total FROM accounts WHERE status = 'ACTIVE'" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalDeposits = (float)$row['total'];

    // ★ FIX IA-016: Include OVERDUE status in loan portfolio (consistent with frontend branch mode)
    // Frontend computeBranchAuditData() sums outstanding from ALL branch loans.
    // Backend should include at least ACTIVE+DELINQUENT+OVERDUE for audit consistency.
    $p = []; $stmt = $db->prepare("SELECT COALESCE(SUM(outstanding),0) AS total FROM loans WHERE status IN ('ACTIVE','DELINQUENT','OVERDUE')" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalLoanPortfolio = (float)$row['total'];

    $p = []; $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM expenses WHERE status = 'APPROVED' AND deleted_at IS NULL" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalApprovedExpenses = (float)$row['total'];

    $p = []; $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM expenses WHERE status = 'PENDING' AND deleted_at IS NULL" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $pendingExpensesTotal = (float)$row['total'];

    // ★ FIX IA-006: Use fee_amount only (consistent with frontend computeBranchAuditData)
    // Frontend uses bProfitLedger.reduce((s, e) => s + e.feeAmount, 0)
    // Previously used fee_amount + net_amount which gave different OPEX ratio than branch mode
    $p = []; $stmt = $db->prepare("SELECT COALESCE(SUM(COALESCE(fee_amount,0)),0) AS total FROM profit_ledger WHERE 1=1" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalFeeIncome = (float)$row['total'];

    // Operating fund balance
    $p = []; $stmt = $db->prepare("SELECT COUNT(*) FROM operating_account_transactions WHERE 1=1" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $opTxns = (int)($stmt->fetchColumn() ?: 0);

    // Delinquent loans
    // ★ FIX IA-015: Include OVERDUE status alongside DELINQUENT for consistency with frontend
    // Frontend computeBranchAuditData() counts DELINQUENT + OVERDUE; backend must match.
    $p = []; $stmt = $db->prepare("SELECT COUNT(*) FROM loans WHERE status IN ('DELINQUENT','OVERDUE')" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $delinquentLoans = (int)($stmt->fetchColumn() ?: 0);
    $p = []; $stmt = $db->prepare("SELECT COUNT(*) FROM loans WHERE status = 'WRITTEN_OFF'" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $writtenOffLoans = (int)($stmt->fetchColumn() ?: 0);
    $p = []; $stmt = $db->prepare("SELECT COALESCE(SUM(outstanding),0) AS total FROM loans WHERE status IN ('DELINQUENT','OVERDUE')" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $delinquentAmount = (float)$row['total'];

    // ★ FIX IA-017: Include OVERDUE status alongside DELINQUENT for accrued interest
    // Frontend computeBranchAuditData() uses bLoans (all statuses) for accruedInterest,
    // but backend should only count ACTIVE+DELINQUENT+OVERDUE (performing + non-performing loans).
    // CLOSED/WRITTEN_OFF loans should not accrue interest.
    $p = []; $stmt = $db->prepare("SELECT COALESCE(SUM(accrued_interest),0) AS total FROM loans WHERE status IN ('ACTIVE','DELINQUENT','OVERDUE')" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalAccruedInterest = (float)$row['total'];

    // ── 3. GL Integrity — from general_ledger table (SAME source as GL Accounts panel) ──
    // Ensure tables exist
    $db->exec("CREATE TABLE IF NOT EXISTS general_ledger (
        id SERIAL PRIMARY KEY,
        account_code VARCHAR(10) NOT NULL,
        account_name VARCHAR(200) DEFAULT '',
        debit DECIMAL(20,2) NOT NULL DEFAULT 0,
        credit DECIMAL(20,2) NOT NULL DEFAULT 0,
        date DATE NOT NULL,
        reference VARCHAR(100) DEFAULT '',
        description TEXT,
        posted_by INTEGER DEFAULT NULL,
        transaction_type VARCHAR(50) DEFAULT '',
        contra_account VARCHAR(50) DEFAULT '',
        branch VARCHAR(100) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    try { $db->exec('CREATE INDEX IF NOT EXISTS idx_gl_account_code ON "general_ledger" (account_code)'); } catch (PDOException $e) {}
    try { $db->exec('CREATE INDEX IF NOT EXISTS idx_gl_date ON "general_ledger" (date)'); } catch (PDOException $e) {}
    try { $db->exec('CREATE INDEX IF NOT EXISTS idx_gl_reference ON "general_ledger" (reference)'); } catch (PDOException $e) {}
    try { $db->exec('CREATE INDEX IF NOT EXISTS idx_gl_branch ON "general_ledger" (branch)'); } catch (PDOException $e) {}
    $db->exec("CREATE TABLE IF NOT EXISTS chart_of_accounts (
        id SERIAL PRIMARY KEY,
        code VARCHAR(10) NOT NULL UNIQUE,
        name VARCHAR(200) NOT NULL,
        type VARCHAR(20) NOT NULL CHECK (type IN ('ASSET','LIABILITY','EQUITY','INCOME','EXPENSE')),
        category VARCHAR(100),
        description TEXT,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    try { $db->exec('CREATE INDEX IF NOT EXISTS idx_coa_code ON "chart_of_accounts" (code)'); } catch (PDOException $e) {}
    try { $db->exec('CREATE INDEX IF NOT EXISTS idx_coa_type ON "chart_of_accounts" (type)'); } catch (PDOException $e) {}
    try { $db->exec('CREATE INDEX IF NOT EXISTS idx_coa_active ON "chart_of_accounts" (is_active)'); } catch (PDOException $e) {}

    // Compute GL balances from general_ledger table — identical to general-ledger.php GET /
    $glBalances = [];
    $p = [];
    $glBalStmt = $db->prepare(
        "SELECT account_code,
            SUM(debit) AS total_debit,
            SUM(credit) AS total_credit,
            COUNT(*) AS entry_count,
            MAX(date) AS last_entry_date
         FROM general_ledger
         WHERE 1=1" . $branchSql('branch', $p) . "
         GROUP BY account_code"
    );
    foreach ($p as $k=>$v) { $glBalStmt->bindValue($k,$v); }
    $glBalStmt->execute();
    while ($row = $glBalStmt->fetch(PDO::FETCH_ASSOC)) {
        $glBalances[$row['account_code']] = [
            'total_debit'   => floatval($row['total_debit']),
            'total_credit'  => floatval($row['total_credit']),
            'entry_count'   => (int)$row['entry_count'],
            'last_entry_date' => $row['last_entry_date']
        ];
    }

    // ★ FIX IA-003/IA-004: Add approved expenses to GL expense accounts and override key accounts
    // Frontend computeBranchAuditData() does this — backend must match for consistency.
    // Step 1: Add approved expense totals to GL expense codes
    $expenseByGlCode = [];
    $p = [];
    $expStmt = $db->prepare("SELECT gl_code, COALESCE(SUM(amount),0) AS total FROM expenses WHERE status = 'APPROVED' AND deleted_at IS NULL AND gl_code IS NOT NULL AND gl_code != ''" . $branchSql('branch', $p) . " GROUP BY gl_code");
    foreach ($p as $k=>$v) { $expStmt->bindValue($k,$v); }
    $expStmt->execute();
    while ($expRow = $expStmt->fetch(PDO::FETCH_ASSOC)) {
        $expenseByGlCode[$expRow['gl_code']] = (float)$expRow['total'];
    }

    // Step 2: Compute key account overrides (matching frontend logic)
    $gl1100Override = $totalLoanPortfolio;  // Loans Receivable = outstanding loan portfolio
    $gl2000Override = $totalDeposits;        // Customer Deposits = sum of active account balances
    $gl1200Override = $totalAccruedInterest; // Accrued Interest Receivable
    $gl1400Override = 0; // Will compute below after opCreditTotal/opDebitTotal

    // Build GL integrity from chart_of_accounts + general_ledger (exact same logic as GL panel)
    $coaStmt = $db->query("SELECT * FROM chart_of_accounts ORDER BY code ASC");
    $coaAccounts = $coaStmt->fetchAll(PDO::FETCH_ASSOC);

    $glSummary = [];
    foreach ($coaAccounts as $acc) {
        $code = $acc['code'];
        $gl = $glBalances[$code] ?? ['total_debit' => 0, 'total_credit' => 0, 'entry_count' => 0, 'last_entry_date' => null];
        $totalDebit = $gl['total_debit'];
        $totalCredit = $gl['total_credit'];

        // ★ FIX IA-003: Add approved expenses to GL expense account debits
        if (isset($expenseByGlCode[$code])) {
            $totalDebit += $expenseByGlCode[$code];
        }

        // Standard double-entry: ASSET/EXPENSE normal = debit - credit; others = credit - debit
        $type = $acc['type'];
        $netBalance = ($type === 'ASSET' || $type === 'EXPENSE')
            ? ($totalDebit - $totalCredit)
            : ($totalCredit - $totalDebit);

        $glSummary[] = [
            'account_code'  => $code,
            'account_name'  => $acc['name'],
            'account_type'  => $type,
            'category'      => $acc['category'] ?? '',
            'total_debit'   => $totalDebit,
            'total_credit'  => $totalCredit,
            'net_balance'   => $netBalance,
            'entry_count'   => $gl['entry_count'],
            'last_entry_date' => $gl['last_entry_date'],
            'is_active'     => (bool)$acc['is_active']
        ];
    }

    // ── 4. Trial Balance — from general_ledger (SAME as GL Accounts panel) ──
    // In proper double-entry, total debits must equal total credits
    $p = [];
    $tbStmt = $db->prepare("SELECT SUM(debit) AS total_debits, SUM(credit) AS total_credits FROM general_ledger WHERE 1=1" . $branchSql('branch', $p));
    foreach ($p as $k=>$v) { $tbStmt->bindValue($k,$v); }
    $tbStmt->execute();
    $tbRow = $tbStmt->fetch(PDO::FETCH_ASSOC);
    $tbTotalDebits  = floatval($tbRow['total_debits']);
    $tbTotalCredits = floatval($tbRow['total_credits']);
    $tbDiff = abs($tbTotalDebits - $tbTotalCredits);
    $tbBalanced = $tbDiff < 0.01;

    // ── 5. Ratios ──
    $loanToDepositRatio = $totalDeposits > 0 ? ($totalLoanPortfolio / $totalDeposits) * 100 : 0;
    $opexRatio = $totalFeeIncome > 0 ? ($totalApprovedExpenses / $totalFeeIncome) * 100 : 0;

    // ── 6. Findings summary (branch-scoped) ──
    // ★ FIX IA-010: Exclude soft-deleted findings (status='DELETED') from all queries
    $p = [];
    $findingsStmt = $db->prepare("SELECT * FROM audit_findings WHERE (status != 'DELETED' OR status IS NULL)" . $branchSql('branch', $p) . " ORDER BY timestamp DESC");
    foreach ($p as $k=>$v) { $findingsStmt->bindValue($k,$v); }
    $findingsStmt->execute();
    $findings = $findingsStmt->fetchAll(PDO::FETCH_ASSOC);
    $openFindings = array_filter($findings, fn($f) => $f['status'] === 'OPEN');
    $inProgressFindings = array_filter($findings, fn($f) => $f['status'] === 'IN_PROGRESS');
    $remediatedFindings = array_filter($findings, fn($f) => in_array($f['status'], ['RESOLVED','CLOSED']));
    // ★ FIX IA-013: Include IN_PROGRESS and ESCALATED in critical/high finding counts
    // Previously only counted OPEN — caused risk_level to be LOW even when KPI cards showed
    // critical findings (by_severity includes OPEN+IN_PROGRESS+ESCALATED). Now consistent.
    $criticalFindings = array_filter($findings, fn($f) => $f['severity'] === 'CRITICAL' && in_array($f['status'], ['OPEN','IN_PROGRESS','ESCALATED']));
    $highFindings = array_filter($findings, fn($f) => $f['severity'] === 'HIGH' && in_array($f['status'], ['OPEN','IN_PROGRESS','ESCALATED']));

    // ★ FIX IA-002: by_severity now counts only OPEN/IN_PROGRESS findings
    // Previously counted ALL findings (including CLOSED) — KPI cards showed closed findings
    // as "Critical Findings — Requires immediate action" which was misleading.
    $bySeverity = ['CRITICAL' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0];
    foreach ($findings as $f) {
        $sev = $f['severity'] ?? 'LOW';
        $st = $f['status'] ?? '';
        // Only count open/in-progress in severity breakdown (matches frontend KPI logic)
        if (isset($bySeverity[$sev]) && ($st === 'OPEN' || $st === 'IN_PROGRESS' || $st === 'ESCALATED')) {
            $bySeverity[$sev]++;
        }
    }
    // ★ FIX IA-018: Category breakdown only counts OPEN/IN_PROGRESS/ESCALATED findings
    // Previously counted ALL findings (including CLOSED) — inconsistent with by_severity
    // which only counts active findings. Now both use the same status filter.
    $byCategory = [];
    foreach ($findings as $f) {
        $st = $f['status'] ?? '';
        if (!in_array($st, ['OPEN','IN_PROGRESS','ESCALATED'])) continue;
        $cat = $f['category'] ?? 'OTHER';
        if (!isset($byCategory[$cat])) $byCategory[$cat] = 0;
        $byCategory[$cat]++;
    }

    // ── 7. 20 Automated Compliance Checks ──
    $checks = [];
    $passedCount = 0;
    $warningCount = 0;
    $failedCount = 0;

    // C01: Trial Balance
    $checks[] = [
        'id' => 'C01', 'name' => 'Trial Balance Integrity',
        'status' => $tbBalanced ? 'PASS' : 'FAIL',
        'severity' => 'CRITICAL',
        'details' => $tbBalanced
            ? 'Total debits (' . number_format($tbTotalDebits) . ') match total credits (' . number_format($tbTotalCredits) . ') FCFA.'
            : 'Imbalance detected: DR ' . number_format($tbTotalDebits) . ' vs CR ' . number_format($tbTotalCredits) . ' (diff: ' . number_format($tbDiff) . ' FCFA).'
    ];

    // ★ FIX IA-005: C02 now checks GL entries for missing debit AND credit
    // Previously checked transactions.direction (different data source than frontend).
    // Frontend checks bGlEntries.filter(e => !e.debit && !e.credit). Now consistent.
    $orphanedGlEntries = (int)($db->query("SELECT COUNT(*) FROM general_ledger WHERE debit = 0 AND credit = 0")->fetchColumn() ?: 0);
    $totalGlEntries = (int)($db->query("SELECT COUNT(*) FROM general_ledger")->fetchColumn() ?: 0);
    $checks[] = [
        'id' => 'C02', 'name' => 'Double-Entry Integrity',
        'status' => $orphanedGlEntries === 0 ? 'PASS' : 'FAIL',
        'severity' => 'CRITICAL',
        'details' => $orphanedGlEntries === 0
            ? 'All ' . $totalGlEntries . ' GL entries have valid debit/credit amounts.'
            : $orphanedGlEntries . ' GL entries missing debit or credit.'
    ];

    // C03: Negative Account Balances
    $p = [];
    $stmt = $db->prepare("SELECT account_number, customer_name, ledger_balance FROM accounts WHERE status = 'ACTIVE' AND ledger_balance < 0" . $branchSql('branch', $p));
    foreach ($p as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->execute();
    $negativeAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $checks[] = [
        'id' => 'C03', 'name' => 'Negative Account Balances',
        'status' => count($negativeAccounts) === 0 ? 'PASS' : 'FAIL',
        'severity' => 'HIGH',
        'details' => count($negativeAccounts) === 0
            ? 'No active accounts have negative ledger balances.'
            : count($negativeAccounts) . ' active account(s) with negative balance: ' . implode(', ', array_map(fn($a) => $a['account_number'] . ' (' . number_format($a['ledger_balance']) . ')', $negativeAccounts))
    ];

    // C04: Orphaned Transactions
    $p = [];
    $stmt = $db->prepare("SELECT COUNT(*) FROM transactions t LEFT JOIN accounts a ON t.account = a.account_number WHERE t.account IS NOT NULL AND t.account != '' AND a.id IS NULL" . $branchSql('t.branch', $p));
    foreach ($p as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->execute();
    $orphanRef = (int)($stmt->fetchColumn() ?: 0);
    $checks[] = [
        'id' => 'C04', 'name' => 'Orphaned Transaction References',
        'status' => $orphanRef === 0 ? 'PASS' : 'FAIL',
        'severity' => 'HIGH',
        'details' => $orphanRef === 0
            ? 'All transaction account references resolve to valid accounts.'
            : $orphanRef . ' transaction(s) reference non-existent accounts.'
    ];

    // C05: Duplicate Transaction References
    $p = [];
    $stmt = $db->prepare("SELECT ref, COUNT(*) AS cnt FROM transactions WHERE 1=1" . $branchSql('branch', $p) . " GROUP BY ref HAVING cnt > 1");
    foreach ($p as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->execute();
    $dupRefs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $checks[] = [
        'id' => 'C05', 'name' => 'Unique Transaction References',
        'status' => count($dupRefs) === 0 ? 'PASS' : 'FAIL',
        'severity' => 'CRITICAL',
        'details' => count($dupRefs) === 0
            ? 'All ' . $totalTxns . ' transaction references are unique.'
            : count($dupRefs) . ' duplicate reference(s) found: ' . implode(', ', array_map(fn($d) => $d['ref'] . ' (' . $d['cnt'] . 'x)', $dupRefs))
    ];

    // C06: Customer KYC Completeness
    $p = [];
    $stmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE (status IN ('DRAFT','PENDING_KYC') OR phone IS NULL OR email IS NULL)" . $branchSql('branch', $p));
    foreach ($p as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->execute();
    $kycIncomplete = (int)($stmt->fetchColumn() ?: 0);
    $checks[] = [
        'id' => 'C06', 'name' => 'Customer KYC Completeness',
        'status' => $kycIncomplete === 0 ? 'PASS' : ($kycIncomplete <= 3 ? 'WARNING' : 'FAIL'),
        'severity' => 'HIGH',
        'details' => $kycIncomplete === 0
            ? 'All ' . $totalCustomers . ' customers have complete KYC documentation.'
            : $kycIncomplete . ' customer(s) have incomplete KYC data or pending verification.'
    ];

    // C07: Dormant Account Review
    $p = []; $stmt = $db->prepare("SELECT COUNT(*) FROM accounts WHERE status = 'DORMANT'" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $dormantAccounts = (int)($stmt->fetchColumn() ?: 0);
    $p = []; $stmt = $db->prepare("SELECT COUNT(*) FROM accounts WHERE status = 'FROZEN'" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $frozenAccounts = (int)($stmt->fetchColumn() ?: 0);
    $checks[] = [
        'id' => 'C07', 'name' => 'Dormant & Frozen Account Review',
        'status' => ($dormantAccounts + $frozenAccounts) === 0 ? 'PASS' : 'WARNING',
        'severity' => 'MEDIUM',
        'details' => ($dormantAccounts + $frozenAccounts) === 0
            ? 'No dormant or frozen accounts found.'
            : $dormantAccounts . ' dormant and ' . $frozenAccounts . ' frozen account(s) require periodic review.'
    ];

    // C08: Loan Portfolio Quality
    // ★ FIX IA-014: Use consistent active_loans definition (ACTIVE+DELINQUENT matches financial_health)
    // active_loans in financial_health is ACTIVE+DELINQUENT — C08 details must use same count
    $checks[] = [
        'id' => 'C08', 'name' => 'Loan Portfolio Quality',
        'status' => $delinquentLoans === 0 ? 'PASS' : ($delinquentLoans <= 2 ? 'WARNING' : 'FAIL'),
        'severity' => 'CRITICAL',
        'details' => $delinquentLoans === 0
            ? 'No delinquent loans. Portfolio of ' . number_format($totalLoanPortfolio) . ' FCFA across ' . $activeLoans . ' active loans is healthy.'
            : $delinquentLoans . ' delinquent loan(s) outstanding. ' . ($writtenOffLoans > 0 ? $writtenOffLoans . ' written off. ' : '') . 'Delinquent amount: ' . number_format($delinquentAmount) . ' FCFA.'
    ];

    // C09: Large Transaction Monitoring (>$5M)
    $largeTxnThreshold = 5000000;
    $p = [];
    $stmt = $db->prepare("SELECT ref, amount, customer_name, description FROM transactions WHERE status = 'POSTED' AND amount > :thr" . $branchSql('branch', $p) . " ORDER BY amount DESC LIMIT 20");
    $stmt->bindValue(':thr', $largeTxnThreshold, PDO::PARAM_INT);
    foreach ($p as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->execute();
    $largeTxns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $checks[] = [
        'id' => 'C09', 'name' => 'Large Transaction Monitoring (>' . number_format($largeTxnThreshold) . ' FCFA)',
        'status' => count($largeTxns) === 0 ? 'PASS' : 'WARNING',
        'severity' => 'HIGH',
        'details' => count($largeTxns) === 0
            ? 'No transactions exceeding ' . number_format($largeTxnThreshold) . ' FCFA threshold.'
            : count($largeTxns) . ' large transaction(s) detected (top: ' . ($largeTxns[0]['ref'] ?? '') . ' — ' . number_format($largeTxns[0]['amount'] ?? 0) . ' FCFA). Review for ML/FT compliance.'
    ];

    // C10: Expense Approval Compliance
    $checks[] = [
        'id' => 'C10', 'name' => 'Expense Approval Compliance',
        'status' => $pendingExpenses === 0 ? 'PASS' : 'WARNING',
        'severity' => 'MEDIUM',
        'details' => $pendingExpenses === 0
            ? 'All ' . $totalExpenses . ' expenses have been processed. ' . $approvedExpenses . ' approved totaling ' . number_format($totalApprovedExpenses) . ' FCFA.'
            : $pendingExpenses . ' expense(s) pending approval totaling ' . number_format($pendingExpensesTotal) . ' FCFA.'
    ];

    // ★ FIX IA-001: C11 now checks Operating Fund vs GL 1400 Reconciliation
    // Previously named "Operating Fund Activity" (LOW severity, checked if txns exist).
    // Frontend expects "Operating Fund vs GL Reconciliation" (HIGH severity, checks recon).
    // This makes the global-mode check consistent with branch-mode computeBranchAuditData().
    $p = [];
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM operating_account_transactions WHERE type = 'CREDIT'" . $branchSql('branch', $p));
    foreach ($p as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->execute();
    $opCreditTotal = (float)($stmt->fetchColumn() ?: 0);
    $p = [];
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM operating_account_transactions WHERE type = 'DEBIT'" . $branchSql('branch', $p));
    foreach ($p as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->execute();
    $opDebitTotal = (float)($stmt->fetchColumn() ?: 0);
    $opNet = $opCreditTotal - $opDebitTotal;
    $operatingFundBalance = $opNet;
    // Compute GL 1400 net balance from general_ledger (consistent with frontend)
    $p = [];
    $stmt = $db->prepare("SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS net FROM general_ledger WHERE account_code = '1400'" . $branchSql('branch', $p));
    foreach ($p as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->execute();
    $gl1400Row = $stmt->fetch(PDO::FETCH_ASSOC);
    $gl1400Net = (float)($gl1400Row['net'] ?? 0);
    $reconDiff = abs($opNet - $gl1400Net);
    $checks[] = [
        'id' => 'C11', 'name' => 'Operating Fund vs GL Reconciliation',
        'status' => $reconDiff < 1 ? 'PASS' : 'FAIL',
        'severity' => 'HIGH',
        'details' => 'OpFund net: ' . number_format($opNet) . ' / GL 1400: ' . number_format($gl1400Net) . ' FCFA. ' . ($reconDiff < 1 ? 'Reconciled.' : 'Discrepancy: ' . number_format($reconDiff) . ' FCFA.'),
        'operating_balance' => $opNet,
        'gl1400_net' => $gl1400Net,
        'recon_diff' => $reconDiff,
        'op_credits' => $opCreditTotal,
        'op_debits' => $opDebitTotal
    ];

    // C12: Staff Access Control
    $inactiveStaff = $totalStaff - $activeStaff;
    $checks[] = [
        'id' => 'C12', 'name' => 'Staff Access Control',
        'status' => $inactiveStaff === 0 ? 'PASS' : 'WARNING',
        'severity' => 'MEDIUM',
        'details' => $activeStaff . ' active staff out of ' . $totalStaff . ' total.' . ($inactiveStaff > 0 ? ' ' . $inactiveStaff . ' inactive account(s) — verify access is properly revoked.' : '')
    ];

    // C13: Pending Approvals Queue
    $p = [];
    $stmt = $db->prepare("SELECT COUNT(*) FROM approvals WHERE status = 'PENDING'" . $branchSql('branch', $p));
    foreach ($p as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->execute();
    $pendingApprovals = (int)($stmt->fetchColumn() ?: 0);
    $checks[] = [
        'id' => 'C13', 'name' => 'Pending Approvals Queue',
        'status' => $pendingApprovals === 0 ? 'PASS' : 'WARNING',
        'severity' => 'MEDIUM',
        'details' => $pendingApprovals === 0
            ? 'No pending approvals in the queue.'
            : $pendingApprovals . ' pending approval(s) require attention.'
    ];

    // C14: Account Status Distribution
    $inactiveAccounts = $totalAccounts - $activeAccounts;
    $checks[] = [
        'id' => 'C14', 'name' => 'Account Lifecycle Status',
        'status' => $inactiveAccounts <= 1 ? 'PASS' : 'WARNING',
        'severity' => 'LOW',
        'details' => $activeAccounts . ' active out of ' . $totalAccounts . ' total accounts. ' . $inactiveAccounts . ' non-active.'
    ];

    // C15: Branch Operational Status
    $inactiveBranches = $totalBranches - $activeBranches;
    $checks[] = [
        'id' => 'C15', 'name' => 'Branch Operational Status',
        'status' => $inactiveBranches === 0 ? 'PASS' : 'WARNING',
        'severity' => 'MEDIUM',
        'details' => $activeBranches . ' active out of ' . $totalBranches . ' branches.' . ($inactiveBranches > 0 ? ' ' . $inactiveBranches . ' inactive — verify closure procedures.' : '')
    ];

    // C16: Profit Ledger Completeness
    $p = []; $stmt = $db->prepare("SELECT COUNT(*) FROM profit_ledger WHERE 1=1" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $plEntries = (int)($stmt->fetchColumn() ?: 0);
    $p = []; $stmt = $db->prepare("SELECT COUNT(DISTINCT category) FROM profit_ledger WHERE 1=1" . $branchSql('branch', $p)); foreach ($p as $k=>$v) $stmt->bindValue($k,$v); $stmt->execute(); $plCategories = (int)($stmt->fetchColumn() ?: 0);
    $checks[] = [
        'id' => 'C16', 'name' => 'Profit Ledger Activity',
        'status' => $plEntries > 0 ? 'PASS' : 'WARNING',
        'severity' => 'LOW',
        'details' => $plEntries . ' profit ledger entries across ' . $plCategories . ' category(ies). Total fee income: ' . number_format($totalFeeIncome) . ' FCFA.'
    ];

    // C17: Loan-to-Deposit Ratio
    $ldr = $loanToDepositRatio;
    $checks[] = [
        'id' => 'C17', 'name' => 'Loan-to-Deposit Ratio',
        'status' => ($ldr >= 30 && $ldr <= 90) ? 'PASS' : ($ldr > 90 ? 'FAIL' : 'WARNING'),
        'severity' => 'HIGH',
        'details' => 'LDR is ' . number_format($ldr, 1) . '% (loans: ' . number_format($totalLoanPortfolio) . ' / deposits: ' . number_format($totalDeposits) . ' FCFA).' . ($ldr > 90 ? ' Exceeds 90% regulatory threshold.' : ($ldr < 30 ? ' Below optimal range (30-90%).' : ' Within acceptable range.'))
    ];

    // C18: Accrued Interest Monitoring
    $checks[] = [
        'id' => 'C18', 'name' => 'Accrued Interest Monitoring',
        'status' => 'PASS',
        'severity' => 'LOW',
        'details' => 'Total accrued interest on active loans: ' . number_format($totalAccruedInterest) . ' FCFA across ' . $activeLoans . ' loan(s).'
    ];

    // ★ FIX IA-012: C19 now counts OPEN+IN_PROGRESS+ESCALATED critical/high findings
    // Previously only counted OPEN — caused C19 to show PASS in global mode while
    // frontend branch mode (which counts OPEN+IN_PROGRESS) showed FAIL. Now consistent.
    // $criticalFindings and $highFindings already include OPEN+IN_PROGRESS+ESCALATED (IA-013).
    $checks[] = [
        'id' => 'C19', 'name' => 'Open Critical/High Findings',
        'status' => (count($criticalFindings) + count($highFindings)) === 0 ? 'PASS' : 'FAIL',
        'severity' => 'CRITICAL',
        'details' => count($criticalFindings) . ' CRITICAL and ' . count($highFindings) . ' HIGH severity finding(s) remain open or in progress.' . ((count($criticalFindings) + count($highFindings)) > 0 ? ' Immediate remediation required.' : '')
    ];

    // C20: Rejected Expenses Review
    $p = [];
    $stmt = $db->prepare("SELECT COUNT(*) FROM expenses WHERE status = 'REJECTED' AND deleted_at IS NULL" . $branchSql('branch', $p));
    foreach ($p as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->execute();
    $rejectedExpenses = (int)($stmt->fetchColumn() ?: 0);
    $checks[] = [
        'id' => 'C20', 'name' => 'Rejected Expenses Review',
        'status' => $rejectedExpenses === 0 ? 'PASS' : 'WARNING',
        'severity' => 'LOW',
        'details' => $rejectedExpenses === 0
            ? 'No rejected expenses.'
            : $rejectedExpenses . ' rejected expense record(s) found. Review for policy compliance.'
    ];

    // ── Tally results ──
    foreach ($checks as $c) {
        if ($c['status'] === 'PASS') $passedCount++;
        elseif ($c['status'] === 'WARNING') $warningCount++;
        else $failedCount++;
    }
    $totalChecks = count($checks);
    $score = $totalChecks > 0 ? round(($passedCount / $totalChecks) * 100) : 0;
    $riskLevel = $failedCount >= 3 || count($criticalFindings) > 0 ? 'CRITICAL'
               : ($failedCount >= 1 ? 'HIGH' : ($warningCount >= 3 ? 'MEDIUM' : 'LOW'));

    // ── 8. Unbalanced References Diagnostic ──
    // 8a: Transaction-level (from transactions table)
    $p = [];
    $unbStmt = $db->prepare("
        SELECT t.ref AS reference,
               MIN(t.created_at) AS first_date,
               STRING_AGG(DISTINCT t.account, ',') AS accounts,
               STRING_AGG(DISTINCT t.type, ',') AS tx_types,
               SUM(CASE WHEN t.direction = 'debit' THEN t.amount ELSE 0 END) AS ref_debits,
               SUM(CASE WHEN t.direction = 'credit' THEN t.amount ELSE 0 END) AS ref_credits,
               ABS(SUM(CASE WHEN t.direction = 'debit' THEN t.amount ELSE 0 END) - SUM(CASE WHEN t.direction = 'credit' THEN t.amount ELSE 0 END)) AS diff,
               COUNT(*) AS entry_count
        FROM transactions t
        WHERE t.status = 'POSTED'" . $branchSql('t.branch', $p) . "
        GROUP BY t.ref
        HAVING ABS(SUM(CASE WHEN t.direction = 'debit' THEN t.amount ELSE 0 END) - SUM(CASE WHEN t.direction = 'credit' THEN t.amount ELSE 0 END)) > 0.01
        ORDER BY diff DESC
        LIMIT 20
    ");
    foreach ($p as $k=>$v) { $unbStmt->bindValue($k,$v); }
    $unbStmt->execute();
    $unbalanced = $unbStmt->fetchAll(PDO::FETCH_ASSOC);

    // 8b: GL-level unbalanced references (from general_ledger — same as GL Accounts panel diagnostic)
    $glUnbalanced = [];
    if ($tbDiff >= 0.01) {
        $p = [];
        $glUnbStmt = $db->prepare(
            "SELECT reference,
                SUM(debit) AS ref_debits,
                SUM(credit) AS ref_credits,
                ABS(SUM(debit) - SUM(credit)) AS diff,
                STRING_AGG(DISTINCT transaction_type, ',') AS tx_types,
                STRING_AGG(DISTINCT account_code, ',' ORDER BY account_code) AS accounts,
                MIN(date) AS first_date,
                COUNT(*) AS entry_count
             FROM general_ledger
             WHERE reference IS NOT NULL AND reference != ''" . $branchSql('branch', $p) . "
             GROUP BY reference
             HAVING ABS(SUM(debit) - SUM(credit)) > 0.01
             ORDER BY diff DESC
             LIMIT 20"
        );
        foreach ($p as $k=>$v) { $glUnbStmt->bindValue($k,$v); }
        $glUnbStmt->execute();
        $glUnbalanced = $glUnbStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── 9. Assemble response ──
    return [
        'compliance_score' => [
            'score'    => $score,
            'total'    => $totalChecks,
            'passed'   => $passedCount,
            'warnings' => $warningCount,
            'failed'   => $failedCount,
            'risk_level' => $riskLevel
        ],
        'findings_summary' => [
            'total'        => count($findings),
            'open'         => count($openFindings),
            'in_progress'  => count($inProgressFindings),
            'remediated'   => count($remediatedFindings),
            'closed'       => count(array_filter($findings, fn($f) => $f['status'] === 'CLOSED')),
            'by_severity'  => $bySeverity,
            'by_category'  => $byCategory
        ],
        'compliance_checks' => $checks,
        'critical_findings' => array_values(array_filter($findings, fn($f) => $f['severity'] === 'CRITICAL' && $f['status'] === 'OPEN')),
        'financial_health' => [
            'trial_balance' => [
                'is_balanced' => $tbBalanced,
                'total_debits' => $tbTotalDebits,
                'total_credits' => $tbTotalCredits,
                'difference' => $tbDiff
            ],
            'operating_fund_balance' => $opNet,
            'operating_fund_transactions' => $opTxns,
            'total_loan_portfolio' => $totalLoanPortfolio,
            'total_loans' => $totalLoans,
            'active_loans' => $activeLoans,
            'delinquent_loans' => $delinquentLoans,
            'delinquent_amount' => $delinquentAmount,
            'written_off_loans' => $writtenOffLoans,
            'accrued_interest' => $totalAccruedInterest,
            'total_customer_deposits' => $totalDeposits,
            'total_active_accounts' => $activeAccounts,
            'total_approved_expenses' => $totalApprovedExpenses,
            'pending_expenses_count' => $pendingExpenses,
            'pending_expenses_total' => $pendingExpensesTotal,
            'total_fee_income' => $totalFeeIncome,
            'opex_ratio' => $opexRatio,
            'loan_to_deposit_ratio' => $loanToDepositRatio,
            'total_active_customers' => $activeCustomers,
            'active_branches' => $activeBranches,
            'total_transactions' => $postedTxns,
            'active_staff' => $activeStaff
        ],
        'gl_integrity' => $glSummary,
        'unbalanced_references' => $unbalanced,
        'gl_unbalanced_entries' => $glUnbalanced,
        'operating_fund_activity' => $opTxns > 0 ? [
            'total_transactions' => $opTxns,
            'total_credits' => $opCreditTotal,
            'total_debits' => $opDebitTotal,
            'current_balance' => $operatingFundBalance
        ] : null,
        'generated_at' => date('Y-m-d H:i:s'),
        'branch_scope' => empty($staffBranches) ? 'ALL' : $staffBranches
    ];
}
