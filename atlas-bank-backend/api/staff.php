<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Staff Management — Full CRUD with branch/module assignment
 *
 * Supports self-service operations (any authenticated user):
 *   - GET  /staff/me               — View own profile
 *   - GET  /staff/me/login-history — View own login history
 *   - GET  /staff/me/activity      — View own audit trail
 *   - GET  /staff/me/sessions      — View own active sessions
 *   - PUT  /staff/me               — Update own profile (limited fields)
 *   - PUT  /staff/me/password      — Change own password (requires current password)
 *   - PUT  /staff/me/sessions      — Revoke all other sessions
 *   - DELETE /staff/me/sessions    — Revoke a specific other session
 *
 * Admin/Manager operations (require STAFF module + appropriate role):
 *   - GET    /staff          — List all staff (with branch isolation)
 *   - GET    /staff/:id      — View any staff member
 *   - POST   /staff          — Create staff member
 *   - PUT    /staff/:id      — Update any staff member
 *   - PUT    /staff/:id/password — Admin password reset
 *   - DELETE /staff/:id      — Delete staff member
 */

// ── Top-level error handler ──
set_error_handler(function($severity, $msg, $file, $line) {
    if (!(error_reporting() & $severity)) return;
    throw new ErrorException($msg, 0, $severity, $file, $line);
});
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Staff API fatal error. Contact system administrator.']);
        exit;
    }
});

try {

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

// ── Base authentication — any logged-in user can access this endpoint ──
$staff = requireAuth();

$method        = $_ROUTE['method'];
$id            = $_ROUTE['id'];
$subRes        = $_ROUTE['subResource'] ?? null;
$originalId    = $id; // Preserve original route ID ('me' vs numeric) before resolution

// ★ DEBUG: Log route variables for staff/me/password
if ($originalId === 'me' && $subRes === 'password') {
    error_log('[STAFF DEBUG] staff/me/password reached. method=' . $method . ' originalId=' . var_export($originalId, true) . ' subRes=' . var_export($subRes, true));
}

// ── Resolve "me" to the actual staff ID for self-service operations ──
$ownStaffId = (string)$staff['staff_id'];
if ($id === 'me') {
    $id = $ownStaffId;
}

$db = getDB();

// ── Auto-create/enrich tables ──
// ★ FIX: Changed id from INTEGER NOT NULL to SERIAL PRIMARY KEY for PostgreSQL.
// Previously used MySQL-style INTEGER NOT NULL with AUTO_INCREMENT, which doesn't
// work in PostgreSQL. SERIAL creates an auto-incrementing sequence automatically.
$db->exec("CREATE TABLE IF NOT EXISTS staff (
    id                    SERIAL PRIMARY KEY,
    username              VARCHAR(100) NOT NULL,
    full_name             VARCHAR(255) NOT NULL,
    initials              VARCHAR(10)  DEFAULT '',
    email                 VARCHAR(255) DEFAULT '',
    phone                 VARCHAR(50)  DEFAULT '',
    position              VARCHAR(255) DEFAULT '',
    role                  VARCHAR(100) NOT NULL DEFAULT 'Operations Staff',
    department            VARCHAR(100) NOT NULL DEFAULT 'Operations',
    password_hash         TEXT         NOT NULL,
    salt                  VARCHAR(128) DEFAULT '',
    mfa_required          BOOLEAN   NOT NULL DEFAULT 1,
    mfa_secret            VARCHAR(255) DEFAULT '',
    employment_status     VARCHAR(20)  NOT NULL DEFAULT 'ACTIVE',
    approval_limit        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    ip_restrictions       VARCHAR(500) DEFAULT 'Any',
    last_login            TIMESTAMP    NULL DEFAULT NULL,
    last_login_ip         VARCHAR(45)  DEFAULT '',
    failed_login_attempts INTEGER NOT NULL DEFAULT 0,
    account_locked        BOOLEAN   NOT NULL DEFAULT 0,
    locked_until          TIMESTAMP    NULL DEFAULT NULL,
    force_password_change BOOLEAN   NOT NULL DEFAULT 0,
    password_changed_at   TIMESTAMP    NULL DEFAULT NULL,
    created_at            TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (username)
)");
// ★ FIX: Ensure sequence exists for staff.id (migration for tables created with old schema).
// If the table was created with `id INTEGER NOT NULL`, there's no auto-increment sequence.
// This creates one if missing, using COALESCE(MAX(id),0)+1 as the start value.
try {
    $seqCheck = $db->query("SELECT COUNT(*) FROM pg_sequences WHERE schemaname = current_schema() AND sequencename = 'staff_id_seq'")->fetchColumn();
    if (!$seqCheck) {
        $maxId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM staff")->fetchColumn();
        $db->exec("CREATE SEQUENCE IF NOT EXISTS staff_id_seq START " . ($maxId + 1));
        $db->exec("ALTER TABLE staff ALTER COLUMN id SET DEFAULT nextval('staff_id_seq')");
        $db->exec("ALTER SEQUENCE staff_id_seq OWNED BY staff.id");
    }
} catch (PDOException $e) {
    error_log('[Staff Schema] Sequence migration failed (may already exist): ' . $e->getMessage());
}
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_staff_role ON staff (role)'); } catch (PDOException $e) {}
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_staff_department ON staff (department)'); } catch (PDOException $e) {}
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_staff_status ON staff (employment_status)'); } catch (PDOException $e) {}

// ── Migration: Add missing columns for existing installations ──
try {
    $cols = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'staff' AND column_name = 'force_password_change'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE staff ADD COLUMN force_password_change BOOLEAN NOT NULL DEFAULT 0");
    }
    $cols2 = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'staff' AND column_name = 'password_changed_at'")->fetchAll();
    if (empty($cols2)) {
        $db->exec("ALTER TABLE staff ADD COLUMN password_changed_at TIMESTAMP NULL DEFAULT NULL");
    }
    $cols3 = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'staff' AND column_name = 'timezone'")->fetchAll();
    if (empty($cols3)) {
        $db->exec("ALTER TABLE staff ADD COLUMN timezone VARCHAR(50) DEFAULT 'Africa/Douala'");
    }
    $cols4 = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'staff' AND column_name = 'locale'")->fetchAll();
    if (empty($cols4)) {
        $db->exec("ALTER TABLE staff ADD COLUMN locale VARCHAR(10) DEFAULT 'en'");
    }
    $cols5 = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'staff' AND column_name = 'notifications_enabled'")->fetchAll();
    if (empty($cols5)) {
        $db->exec("ALTER TABLE staff ADD COLUMN notifications_enabled BOOLEAN NOT NULL DEFAULT 1");
    }
    $cols6 = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'staff' AND column_name = 'profile_picture'")->fetchAll();
    if (empty($cols6)) {
        $db->exec("ALTER TABLE staff ADD COLUMN profile_picture TEXT DEFAULT NULL");
    }
    // ★ FIX (SP-045): Add deactivation_reason and suspension_reason columns.
    // Previously these were sent by the frontend but silently discarded by the backend,
    // as the staff table had no columns for them. Now they are persisted alongside
    // the status change, making them visible on the staff record itself.
    $cols7 = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'staff' AND column_name = 'deactivation_reason'")->fetchAll();
    if (empty($cols7)) {
        $db->exec("ALTER TABLE staff ADD COLUMN deactivation_reason VARCHAR(500) DEFAULT NULL");
    }
    $cols8 = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'staff' AND column_name = 'suspension_reason'")->fetchAll();
    if (empty($cols8)) {
        $db->exec("ALTER TABLE staff ADD COLUMN suspension_reason VARCHAR(500) DEFAULT NULL");
    }
} catch (PDOException $e) { /* Columns may already exist — safe to ignore */ }

$db->exec("CREATE TABLE IF NOT EXISTS staff_branches (
    id          SERIAL PRIMARY KEY,
    staff_id    INTEGER NOT NULL,
    branch_name VARCHAR(255) NOT NULL,
    UNIQUE (staff_id, branch_name),
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
)");
// ★ FIX: Ensure sequence exists for staff_branches.id (migration for old schema)
try {
    $seqCheck = $db->query("SELECT COUNT(*) FROM pg_sequences WHERE schemaname = current_schema() AND sequencename = 'staff_branches_id_seq'")->fetchColumn();
    if (!$seqCheck) {
        $maxId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM staff_branches")->fetchColumn();
        $db->exec("CREATE SEQUENCE IF NOT EXISTS staff_branches_id_seq START " . ($maxId + 1));
        $db->exec("ALTER TABLE staff_branches ALTER COLUMN id SET DEFAULT nextval('staff_branches_id_seq')");
        $db->exec("ALTER SEQUENCE staff_branches_id_seq OWNED BY staff_branches.id");
    }
} catch (PDOException $e) {}
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_sb_branch ON staff_branches (branch_name)'); } catch (PDOException $e) {}

$db->exec("CREATE TABLE IF NOT EXISTS staff_modules (
    id          SERIAL PRIMARY KEY,
    staff_id    INTEGER NOT NULL,
    module_name VARCHAR(100) NOT NULL,
    access_level VARCHAR(20) NOT NULL DEFAULT 'FULL',
    UNIQUE (staff_id, module_name),
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
)");
// ★ FIX: Ensure sequence exists for staff_modules.id (migration for old schema)
try {
    $seqCheck = $db->query("SELECT COUNT(*) FROM pg_sequences WHERE schemaname = current_schema() AND sequencename = 'staff_modules_id_seq'")->fetchColumn();
    if (!$seqCheck) {
        $maxId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM staff_modules")->fetchColumn();
        $db->exec("CREATE SEQUENCE IF NOT EXISTS staff_modules_id_seq START " . ($maxId + 1));
        $db->exec("ALTER TABLE staff_modules ALTER COLUMN id SET DEFAULT nextval('staff_modules_id_seq')");
        $db->exec("ALTER SEQUENCE staff_modules_id_seq OWNED BY staff_modules.id");
    }
} catch (PDOException $e) {}
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_sm_module ON staff_modules (module_name)'); } catch (PDOException $e) {}

/**
 * Normalise module names from the frontend to canonical display names.
 * Handles legacy RBAC keys, self-heal auto-assign keys, and display-name variants.
 * This ensures the DB always stores a single canonical form per module.
 */
function normaliseModuleNames(array $rawModules): array
{
    $canonical = [
        // ★ FIX (SP-023): Consolidated all mappings into a single section.
        // Previously had duplicate keys (display-name + legacy sections with same lowercase keys).
        // PHP overwrites earlier keys, so the duplicates were harmless but misleading.
        // Now uses one consolidated map — lowercase key → canonical display name.
        'staff management'      => 'Staff',
        'staff'                 => 'Staff',
        'dashboard'             => 'Dashboard',
        'customers'             => 'Customers',
        'accounts'              => 'Accounts',
        'transactions'          => 'Transactions',
        'loans'                 => 'Loans',
        'approvals'             => 'Approvals',
        'expenses'              => 'Expenses',
        'operating fund'        => 'Operating Fund',
        'operating'             => 'Operating Fund',
        'operating_fund'        => 'Operating Fund',
        'gl accounts'           => 'GL Accounts',
        'glaccounts'            => 'GL Accounts',
        'gl_accounts'           => 'GL Accounts',
        'chart_of_accounts'     => 'GL Accounts',
        'internal audit'        => 'Internal Audit',
        'internalaudit'         => 'Internal Audit',
        'internal_audit'        => 'Internal Audit',
        'branch management'     => 'Branch Management',
        'branches'              => 'Branch Management',
        'settings'              => 'Settings',
        'documents'             => 'Documents',
        'investments'           => 'Investments',
        'investment portal'     => 'Investments',
        'financial reports'     => 'Financial Reports',
        'reports'               => 'Financial Reports',
        'profit & loss'         => 'Profit & Loss',
        'profit and loss'       => 'Profit & Loss',
        'profitloss'            => 'Profit & Loss',
        'audit log'             => 'Audit Log',
        'audit'                 => 'Audit Log',
        'balance sheet'         => 'Balance Sheet',
        'balancesheet'          => 'Balance Sheet',
        'cash flow'             => 'Cash Flow',
        'cashflow'              => 'Cash Flow',
        'financial ratios'      => 'Financial Ratios',
        'ratios'                => 'Financial Ratios',
        'all modules'           => 'ALL',
        'all'                   => 'ALL',
    ];

    $names = [];
    foreach ($rawModules as $m) {
        // ★ FIX: Handle both plain strings and objects like ['name'=>'Staff', 'access'=>'FULL']
        if (is_array($m)) {
            $trimmed = trim($m['name'] ?? $m['module_name'] ?? '');
        } else {
            $trimmed = trim((string)$m);
        }
        if ($trimmed === '') continue;
        $lower = strtolower($trimmed);
        // Expand "ALL" / "ALL MODULES" into every module
        if ($lower === 'all' || $lower === 'all modules') {
            $allModules = ['Dashboard','Customers','Accounts','Transactions','Loans','Approvals',
                'Expenses','Operating Fund','GL Accounts','Internal Audit','Branch Management','Investments',
                'Staff','Settings','Documents','Financial Reports','Profit & Loss','Audit Log'];
            foreach ($allModules as $am) {
                $names[strtoupper($am)] = $am;
            }
            continue;
        }
        if (isset($canonical[$lower])) {
            $trimmed = $canonical[$lower];
        }
        $names[strtoupper($trimmed)] = $trimmed;
    }
    return array_values($names);
}

// ── Helper: attach branches and modules to a staff record ──
function attachAssignments(PDO $db, array &$record): void {
    $staffId = (int)$record['id'];

    $record['branches'] = [];
    try {
        $bs = $db->prepare("SELECT branch_name FROM staff_branches WHERE staff_id = ? ORDER BY branch_name ASC");
        $bs->execute([$staffId]);
        $record['branches'] = $bs->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (PDOException $e) {}

    // Modules with access levels: [{name: "Staff", access: "FULL"}, ...]
    $record['modules'] = [];
    try {
        // Self-heal: add access_level column if it doesn't exist (backward compat)
        $cols = [];
        foreach ($db->query("SELECT column_name AS Field, data_type AS Type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'staff_modules' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_ASSOC) as $_c) {
            $cols[strtolower($_c['Field'])] = true;
        }
        if (!isset($cols['access_level'])) {
            $db->exec("ALTER TABLE staff_modules ADD COLUMN access_level VARCHAR(20) NOT NULL DEFAULT 'FULL'");
        }

        $ms = $db->prepare("SELECT module_name, COALESCE(access_level, 'FULL') AS access_level FROM staff_modules WHERE staff_id = ? ORDER BY module_name ASC");
        $ms->execute([$staffId]);
        $rows = $ms->fetchAll(PDO::FETCH_ASSOC);
        $record['modules'] = array_map(function($r) {
            return ['name' => $r['module_name'], 'access' => $r['access_level']];
        }, $rows);
    } catch (PDOException $e) {}
}

// ── Helper: sync branches for a staff member (delete all, re-insert) ──
function syncBranches(PDO $db, int $staffId, array $branches): void {
    $db->prepare("DELETE FROM staff_branches WHERE staff_id = ?")->execute([$staffId]);
    if (empty($branches)) return;
    $ins = $db->prepare("INSERT INTO staff_branches (staff_id, branch_name) VALUES (?, ?)");
    foreach ($branches as $b) {
        if (trim($b)) $ins->execute([$staffId, trim($b)]);
    }
}

// ── Helper: sync modules for a staff member (delete all, re-insert) ──
// $modules: array of either strings (legacy compat) or arrays ['name'=>string, 'access'=>string]
function syncModules(PDO $db, int $staffId, array $modules): void {
    $db->prepare("DELETE FROM staff_modules WHERE staff_id = ?")->execute([$staffId]);
    if (empty($modules)) return;
    // Ensure access_level column exists (self-heal for existing databases)
    try {
        $cols = [];
        foreach ($db->query("SELECT column_name AS Field, data_type AS Type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'staff_modules' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_ASSOC) as $_c) {
            $cols[strtolower($_c['Field'])] = true;
        }
        if (!isset($cols['access_level'])) {
            $db->exec("ALTER TABLE staff_modules ADD COLUMN access_level VARCHAR(20) NOT NULL DEFAULT 'FULL'");
        }
    } catch (PDOException $e) {}
    $ins = $db->prepare("INSERT INTO staff_modules (staff_id, module_name, access_level) VALUES (?, ?, ?)");
    foreach ($modules as $m) {
        if (is_array($m)) {
            $name = trim($m['name'] ?? $m['module_name'] ?? '');
            $access = strtoupper($m['access'] ?? $m['access_level'] ?? 'FULL');
            if ($name === '') continue;
            if (!in_array($access, ['FULL', 'VIEW_ONLY'], true)) $access = 'FULL';
            $ins->execute([$staffId, $name, $access]);
        } else {
            $name = trim($m);
            if ($name === '') continue;
            $ins->execute([$staffId, $name, 'FULL']); // Legacy: default to FULL access
        }
    }
}

// ── Helper: check if current user is admin ──
function isCurrentUserAdmin(array $staff): bool {
    return strtoupper($staff['role']) === 'ADMIN';
}

// ── Helper: resolve current session token (from header or cookie) ──
function resolveCurrentToken(): string {
    $currentToken = '';
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (empty($authHeader) && function_exists('getallheaders')) {
        $allH = getallheaders();
        $authHeader = $allH['Authorization'] ?? $allH['authorization'] ?? '';
    }
    if (preg_match('/^Bearer\\s+(.+)$/i', $authHeader, $m)) {
        $currentToken = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', trim($m[1]));
    }
    if (empty($currentToken)) {
        $currentToken = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', $_COOKIE['X-Atlas-Session'] ?? '');
    }
    return $currentToken;
}

switch ($method) {

    /* ================================================================
       GET — List, Single Detail, or Self-Service Sub-resources
       ================================================================ */
    case 'GET':

        // ── GET /api/staff/me — Own profile ──
        // This MUST come before any other numeric ID checks or the "List all" logic.
        if ($originalId === 'me' && empty($subRes)) {
            $stmt = $db->prepare(
                'SELECT id, username, full_name, initials, email, phone, position, role,
                        department, employment_status, approval_limit, mfa_required,
                        ip_restrictions, last_login, last_login_ip, failed_login_attempts,
                        account_locked, locked_until, force_password_change, password_changed_at,
                        timezone, locale, notifications_enabled, profile_picture,
                        deactivation_reason, suspension_reason,
                        created_at, updated_at
                 FROM staff WHERE id = ?'
            );
            $stmt->execute([(int)$staff['staff_id']]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$record) { notFoundResponse('Staff record not found.'); }
            attachAssignments($db, $record);
            successResponse($record);
            break;
        }

        // ── GET /api/staff/me/login-history — Own login history ──
        if ($originalId === 'me' && $subRes === 'login-history') {
            try {
                _ensureLoginHistoryColumns($db);
                $limit = max(1, min((int)($_GET['limit'] ?? 20), 100));
                $offset = max(0, (int)($_GET['offset'] ?? 0));
                $stmt = $db->prepare(
                    'SELECT id, username, result, ip, user_agent, risk, device_fingerprint,
                            timestamp AS created_at
                     FROM login_history WHERE username = ?
                     ORDER BY timestamp DESC LIMIT CAST(? AS INTEGER) OFFSET CAST(? AS INTEGER)'
                );
                $stmt->execute([$staff['username'], $limit, $offset]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $cntStmt = $db->prepare('SELECT COUNT(*) AS total FROM login_history WHERE username = ?');
                $cntStmt->execute([$staff['username']]);
                $total = (int)$cntStmt->fetch()['total'];

                successResponse(['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
            } catch (PDOException $e) {
                serverErrorResponse('Failed to load login history.');
            }
            break;
        }

        // ── GET /api/staff/me/activity — Own audit trail ──
        if ($originalId === 'me' && $subRes === 'activity') {
            try {
                $limit = max(1, min((int)($_GET['limit'] ?? 20), 100));
                $offset = max(0, (int)($_GET['offset'] ?? 0));
                $action = sanitize($_GET['action'] ?? '');
                $dateFrom = sanitize($_GET['date_from'] ?? '');
                $dateTo = sanitize($_GET['date_to'] ?? '');
                $category = sanitize($_GET['category'] ?? '');

                $where = 'WHERE actor = :actor';
                $params = [':actor' => $staff['full_name']];
                if (!empty($action)) {
                    $where .= ' AND action LIKE :action';
                    $params[':action'] = $action . '%';
                }
                if (!empty($category)) {
                    $where .= ' AND category = :category';
                    $params[':category'] = $category;
                }
                if (!empty($dateFrom)) {
                    $where .= ' AND timestamp >= :df';
                    $params[':df'] = $dateFrom;
                }
                if (!empty($dateTo)) {
                    $where .= ' AND timestamp <= :dt';
                    $params[':dt'] = $dateTo . ' 23:59:59';
                }

                $stmt = $db->prepare(
                    "SELECT id, uuid, actor, action, entity, entity_id, result, ip, details, module, category, user_agent,
                            COALESCE(timestamp, created_at) AS created_at
                     FROM audit_logs {$where}
                     ORDER BY COALESCE(timestamp, created_at) DESC LIMIT CAST(:lim AS INTEGER) OFFSET :off"
                );
                foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
                $stmt->execute();
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $cntStmt = $db->prepare("SELECT COUNT(*) AS total FROM audit_logs {$where}");
                $cntStmt->execute($params);
                $total = (int)$cntStmt->fetch()['total'];

                successResponse(['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
            } catch (PDOException $e) {
                serverErrorResponse('Failed to load activity.');
            }
            break;
        }

        // ── GET /api/staff/me/sessions — Own active sessions ──
        if ($originalId === 'me' && $subRes === 'sessions') {
            try {
                _ensureSessionColumns($db);
                $currentToken = resolveCurrentToken();

                $stmt = $db->prepare(
                    'SELECT id, ip_address, user_agent, created_at, expires_at, last_activity,
                            device_fingerprint, label,
                            CASE WHEN id = ? THEN 1 ELSE 0 END AS is_current
                     FROM sessions WHERE staff_id = ? AND expires_at > NOW()
                     ORDER BY created_at DESC'
                );
                $stmt->execute([$currentToken, (int)$staff['staff_id']]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                successResponse($items);
            } catch (PDOException $e) {
                serverErrorResponse('Failed to load sessions.');
            }
            break;
        }

        // ── GET /api/staff/:id — Single staff detail ──
        if ($id !== null && is_numeric($id)) {
            // Any user can view their own profile; viewing others requires STAFF module
            if ((string)$id !== (string)$staff['staff_id']) {
                requireModule('STAFF', $staff);
            }
            $staffId = (int)$id;
            $stmt = $db->prepare(
                'SELECT id, username, full_name, initials, email, phone, position, role,
                        department, employment_status, approval_limit, mfa_required,
                        ip_restrictions, last_login, last_login_ip, failed_login_attempts,
                        account_locked, locked_until, force_password_change, password_changed_at,
                        timezone, locale, notifications_enabled, profile_picture,
                        deactivation_reason, suspension_reason,
                        created_at, updated_at
                 FROM staff WHERE id = ?'
            );
            $stmt->execute([$staffId]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$record) { notFoundResponse('Staff member not found.'); }
            attachAssignments($db, $record);
            successResponse($record);
            break;
        }

        // ── GET /api/staff (list all) — requires STAFF module ──
        requireModule('STAFF', $staff);
        $page     = max(1, (int)($_GET['page'] ?? 1));
        // ★ FIX (FIN-2b-028): Use MAX_PAGE_SIZE instead of hardcoded 500
        $pageSize = max(1, min((int)($_GET['pageSize'] ?? DEFAULT_PAGE_SIZE), MAX_PAGE_SIZE));
        $offset   = ($page - 1) * $pageSize;
        $params   = [];

        // Build WHERE from filters
        $whereParts = [];
        if (!empty($_GET['role'])) {
            $whereParts[] = "role = :role";
            $params[':role'] = sanitize($_GET['role']);
        }
        if (!empty($_GET['department'])) {
            $whereParts[] = "department = :dept";
            $params[':dept'] = sanitize($_GET['department']);
        }
        if (!empty($_GET['employment_status'])) {
            $whereParts[] = "employment_status = :status";
            $params[':status'] = sanitize($_GET['employment_status']);
        }
        // Search by name or username
        $search = sanitize($_GET['search'] ?? '');
        if (!empty($search)) {
            $whereParts[] = "(full_name LIKE :search1 OR username LIKE :search2)";
            $params[':search1'] = '%' . $search . '%';
            $params[':search2'] = '%' . $search . '%';
        }
        // Branch isolation: non-admin users can only see staff assigned to their branches
        // ★ FIX (SP-001): If non-admin has NO assigned branches, show NO staff (not ALL).
        // Previously, empty $userBranches meant no WHERE clause was added, leaking all staff.
        if (!isCurrentUserAdmin($staff)) {
            $userBranches = $staff['branches'] ?? [];
            if (!empty($userBranches)) {
                $placeholders = array_map(function($i) { return ':ubranch_' . $i; }, array_keys($userBranches));
                $whereParts[] = "(SELECT COUNT(*) FROM staff_branches sb WHERE sb.staff_id = staff.id AND sb.branch_name IN (" . implode(',', $placeholders) . ")) > 0";
                foreach ($userBranches as $i => $branch) {
                    $params[':ubranch_' . $i] = $branch;
                }
            } else {
                // Non-admin with no branches — return nothing
                $whereParts[] = "1 = 0";
            }
        }
        $where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

        $totalStmt = $db->prepare("SELECT COUNT(*) AS total FROM staff $where");
        $totalStmt->execute($params);
        $total = (int)$totalStmt->fetch()['total'];

        $stmt = $db->prepare(
            "SELECT id, username, full_name, initials, email, phone, position, role,
                    department, employment_status, approval_limit, mfa_required,
                    ip_restrictions, last_login, last_login_ip, failed_login_attempts,
                    account_locked, profile_picture, created_at, updated_at
             FROM staff $where
             ORDER BY id DESC LIMIT CAST(:limit AS INTEGER) OFFSET CAST(:offset AS INTEGER)"
        );
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ★ PERFORMANCE FIX: Batch-load branches and modules to avoid N+1 queries.
        if (!empty($items)) {
            $staffIds = array_column($items, 'id');
            $placeholders = implode(',', array_fill(0, count($staffIds), '?'));

            $allBranches = [];
            try {
                $brStmt = $db->prepare("SELECT staff_id, branch_name FROM staff_branches WHERE staff_id IN ($placeholders) ORDER BY branch_name ASC");
                $brStmt->execute($staffIds);
                foreach ($brStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $allBranches[(int)$row['staff_id']][] = $row['branch_name'];
                }
            } catch (PDOException $e) {}

            $allModules = [];
            try {
                // ★ FIX (SP-010): Return modules as objects with access_level (matching GET /staff/:id).
                // Previously returned plain strings, causing inconsistency and breaking CSV export
                // detail API call converts cached modules from strings to objects.
                $modStmt = $db->prepare("SELECT staff_id, module_name, COALESCE(access_level, 'FULL') AS access_level FROM staff_modules WHERE staff_id IN ($placeholders) ORDER BY module_name ASC");
                $modStmt->execute($staffIds);
                foreach ($modStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $sid = (int)$row['staff_id'];
                    $allModules[$sid][] = ['name' => $row['module_name'], 'access' => $row['access_level']];
                }
            } catch (PDOException $e) {}

            foreach ($items as &$item) {
                $sid = (int)$item['id'];
                $item['branches'] = $allBranches[$sid] ?? [];
                $item['modules'] = $allModules[$sid] ?? [];
            }
            unset($item);
        }

        paginatedResponse($items, $total, $page, $pageSize);
        break;

    /* ================================================================
       POST — Create Staff Member
       ================================================================ */
    case 'POST':
        // ★ GUARD: POST is for creation only. If an ID is present, it's a routing error.
        if ($id !== null) {
            errorResponse('Method not allowed for this endpoint. Use PUT for updates.', 405);
        }
        $input = getRequestInput();

        // Access control: ADMIN can create staff freely; MANAGER can only create for own branches
        if (isCurrentUserAdmin($staff)) {
            requireRole(['ADMIN'], $staff);
        } elseif (strtoupper($staff['role']) === 'MANAGER') {
            // Manager: restrict branches to their own assigned branches
            $mgrBranches = $staff['branches'] ?? [];
            $inputBranches = $input['branches'] ?? [];
            $restricted = array_values(array_filter($inputBranches, function($b) use ($mgrBranches) {
                return in_array($b, $mgrBranches);
            }));
            if (empty($restricted)) {
                errorResponse('Access denied. You can only assign staff to your own branch(es): ' . implode(', ', $mgrBranches) . '. Contact Admin for cross-branch assignments.');
            }
            $input['branches'] = $restricted;
            // Manager cannot create other admins
            $input['role'] = 'Operations Staff';
        } else {
            forbiddenResponse('Only Admin and Manager roles can create staff.');
        }

        $errors = validateRequired($input, ['username', 'full_name', 'role', 'department']);
        if (!empty($errors)) { validationError($errors); }

        // Check username uniqueness
        $check = $db->prepare("SELECT id FROM staff WHERE username = ?");
        $check->execute([sanitize($input['username'])]);
        if ($check->fetch()) { conflictResponse('Username "' . $input['username'] . '" already exists.'); }

        $name     = sanitize($input['full_name']);
        $username = sanitize($input['username']);
        $initials = sanitize($input['initials'] ?? implode('', array_map(fn($w) => strtoupper($w[0] ?? ''), explode(' ', $name))));

        // ── Password handling ──
        // If the admin provided a password from the form, use it (after validation).
        // Otherwise, generate a cryptographically secure random password.
        // User MUST change on first login (force_password_change = TRUE).
        $providedPassword = $input['password'] ?? '';
        if (is_string($providedPassword) && strlen($providedPassword) >= 8) {
            // Validate complexity requirements
            $pwErrors = [];
            if (!preg_match('/[A-Z]/', $providedPassword)) { $pwErrors[] = 'one uppercase letter'; }
            if (!preg_match('/[a-z]/', $providedPassword)) { $pwErrors[] = 'one lowercase letter'; }
            if (!preg_match('/[0-9]/', $providedPassword)) { $pwErrors[] = 'one digit'; }
            if (!preg_match('/[!@#$%^&*()\-_+=\[\]{}|;:",.<>?\/\\`~]/', $providedPassword)) { $pwErrors[] = 'one special character'; }
            if (!empty($pwErrors)) {
                validationError(['password' => 'Password must contain ' . implode(', ', $pwErrors) . '.']);
            }
            $tempPassword = $providedPassword;
        } else {
            // ★ FIX (SP-004): Generate a strong random password with 128-bit entropy.
            // Previously used bin2hex(random_bytes(4)) = 8 hex chars = only 32 bits —
            // trivially brute-forceable. Also includes mixed character types to meet
            // the system's own password complexity policy (uppercase, lowercase, digit, special).
            $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
            $lower = 'abcdefghjkmnpqrstuvwxyz';
            $digits = '23456789';
            $special = '!@#$%&*?';
            $all = $upper . $lower . $digits . $special;
            $tempPassword = '';
            $tempPassword .= $upper[random_int(0, strlen($upper)-1)];
            $tempPassword .= $lower[random_int(0, strlen($lower)-1)];
            $tempPassword .= $digits[random_int(0, strlen($digits)-1)];
            $tempPassword .= $special[random_int(0, strlen($special)-1)];
            for ($i = strlen($tempPassword); $i < 16; $i++) {
                $tempPassword .= $all[random_int(0, strlen($all)-1)];
            }
            // Fisher-Yates shuffle
            $arr = str_split($tempPassword);
            for ($i = count($arr) - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
            }
            $tempPassword = implode('', $arr);
        }
        $hash = password_hash($tempPassword, PASSWORD_BCRYPT);

        $branches = $input['branches'] ?? [];
        $rawModules = $input['modules'] ?? [];
        // Frontend now sends: [{name:"Staff", access:"FULL"}, {name:"Audit Log", access:"VIEW_ONLY"}, ...]
        // Legacy compat: also handles plain string arrays ["Staff", "Customers", ...]
        $moduleKeys = normaliseModuleNames(is_array($rawModules) ? $rawModules : []);
        // Preserve access levels from frontend (normaliseModuleNames returns names only)
        $modulePayload = [];
        if (!empty($rawModules) && isset($rawModules[0]) && is_array($rawModules[0])) {
            // New format: [{name, access}, ...]
            foreach ($rawModules as $m) {
                $name = trim($m['name'] ?? '');
                $access = strtoupper($m['access'] ?? 'FULL');
                if ($name === '') continue;
                if (!in_array($access, ['FULL', 'VIEW_ONLY'], true)) $access = 'FULL';
                $modulePayload[] = ['name' => $name, 'access' => $access];
            }
        } else {
            // Legacy format: plain strings — default to FULL access
            foreach ($moduleKeys as $name) {
                $modulePayload[] = ['name' => $name, 'access' => 'FULL'];
            }
        }

        $stmt = $db->prepare(
            'INSERT INTO staff (username, full_name, initials, email, phone, position, role,
                                department, password_hash, salt, mfa_required, employment_status,
                                approval_limit, ip_restrictions, force_password_change)
             VALUES (:username, :full_name, :initials, :email, :phone, :position, :role,
                     :department, :hash, :salt, :mfa, :status, :limit, :ip, :force)'
        );
        $approvalLimitParsed = parseDecimalInput($input['approval_limit'] ?? 0, 'Approval limit', 2, 0, 999999999.99, false);
        if (!$approvalLimitParsed['ok']) { validationError(['approval_limit' => $approvalLimitParsed['error']]); }
        $stmt->execute([
            ':username'    => $username,
            ':full_name'   => $name,
            ':initials'    => substr($initials, 0, 4),
            ':email'       => sanitize($input['email'] ?? ''),
            ':phone'       => sanitize($input['phone'] ?? ''),
            ':position'    => sanitize($input['position'] ?? ''),
            ':role'        => sanitize($input['role']),
            ':department'  => sanitize($input['department']),
            ':hash'        => $hash,
            ':salt'        => generateRandomString(64),
            ':mfa'         => isset($input['mfa_required']) ? (int)(bool)$input['mfa_required'] : 1,
            ':status'      => sanitize($input['employment_status'] ?? 'ACTIVE'),
            ':limit'       => $approvalLimitParsed['value'],
            ':ip'          => sanitize($input['ip_restrictions'] ?? 'Any'),
            ':force'       => 1  // Always force password change for new accounts
        ]);
        $newId = (int)$db->lastInsertId();

        // Sync branch and module assignments
        if (is_array($branches)) syncBranches($db, $newId, $branches);
        if (!empty($modulePayload)) syncModules($db, $newId, $modulePayload);

        logAudit($staff['full_name'], 'STAFF_CREATE', 'Staff', (string)$newId, 'SUCCESS',
            'Created staff: ' . $name . ' (' . $username . ')', $staff['department'], getClientIp());

        // Return created record WITHOUT sensitive fields
        // ★ FIX (SP-039): Added profile_picture to SELECT — was missing, causing
        // inconsistent cache update (POST didn't return it while GET/PUT did).
        $row = $db->prepare(
            'SELECT id, username, full_name, initials, email, phone, position, role,
                    department, employment_status, approval_limit, mfa_required,
                    ip_restrictions, last_login, last_login_ip, failed_login_attempts,
                    account_locked, locked_until, force_password_change, password_changed_at,
                    timezone, locale, notifications_enabled, profile_picture,
                    deactivation_reason, suspension_reason,
                    created_at, updated_at
             FROM staff WHERE id = ?'
        );
        $row->execute([$newId]);
        $created = $row->fetch(PDO::FETCH_ASSOC);
        if ($created) attachAssignments($db, $created);

        // ★ Include auto-generated password in response so admin can communicate it
        $created['initial_password'] = $tempPassword;
        // ★ FIX (SP-051): Removed password from response message string — potential
        // log exposure if the API framework logs response messages. The password
        // is already available in the data object's initial_password field.
        createdResponse($created, 'Staff member created successfully.');
        break;

    /* ================================================================
       PUT — Update Staff Member or Self-Service Operations
       ================================================================ */
    case 'PUT':
        // ★ GUARD: PUT requires an ID or the 'me' keyword.
        if ($id === null && $originalId !== 'me') {
            validationError(['id' => 'Staff ID is required for updates.']);
        }

        // ★ DEBUG: Log PUT reaching
        error_log('[STAFF PUT] reached. originalId=' . var_export($originalId, true) . ' id=' . var_export($id, true) . ' subRes=' . var_export($subRes, true));

        // ── Self-service sub-resource endpoints (no ADMIN required, any authenticated user) ──
        // Only activate when the original route was /api/staff/me/* (not /api/staff/:id/*).
        // This prevents the self-service handler from intercepting admin edits to their own
        // record via the staff management modal (PUT /api/staff/1), which should use the
        // full admin handler with all fields (role, department, branches, modules, etc.).
        $isOwnSelfRoute = ($originalId === 'me') || ((string)$id === (string)$staff['staff_id']);
        if ($isOwnSelfRoute && $subRes !== null) {

            // PUT /api/staff/me/sessions — revoke all other sessions
            if ($subRes === 'sessions') {
                try {
                    $currentToken = resolveCurrentToken();
                    $stmt = $db->prepare("DELETE FROM sessions WHERE staff_id = ? AND id != ?");
                    $stmt->execute([(int)$staff['staff_id'], $currentToken]);
                    $revokedCount = $stmt->rowCount();
                    logAudit($staff['full_name'], 'SESSION_REVOKE', 'STAFF', (string)$staff['staff_id'], 'SUCCESS',
                        "Revoked {$revokedCount} other session(s)", $staff['department'], getClientIp());
                    successResponse(['revoked' => $revokedCount]);
                } catch (PDOException $e) {
                    serverErrorResponse('Failed to revoke sessions.');
                }
                break;
            }

            // PUT /api/staff/me/password — self-service password change
            if ($subRes === 'password') {
                require_once __DIR__ . '/../middleware/rate_limit.php';
                requireRateLimit('pwd_change:' . getClientIp() . ':' . $staff['staff_id'], 5, 300);
                $input = getRequestInput();
                $currentPassword = $input['current_password'] ?? '';
                $newPassword     = $input['new_password'] ?? '';

                if (empty($currentPassword)) { validationError(['current_password' => 'Current password is required.']); }
                if (strlen($newPassword) < 8) { validationError(['new_password' => 'New password must be at least 8 characters.']); }
                // SECURITY: Enforce password complexity (NIST-aligned)
                if (!preg_match('/[A-Z]/', $newPassword)) { validationError(['new_password' => 'Password must contain at least one uppercase letter.']); }
                if (!preg_match('/[a-z]/', $newPassword)) { validationError(['new_password' => 'Password must contain at least one lowercase letter.']); }
                if (!preg_match('/[0-9]/', $newPassword)) { validationError(['new_password' => 'Password must contain at least one digit.']); }
                if (!preg_match('/[!@#$%^&*()\-_+=\[\]{}|;:",.<>?\/\\`~]/', $newPassword)) { validationError(['new_password' => 'Password must contain at least one special character.']); }

                $row = $db->prepare("SELECT password_hash FROM staff WHERE id = ?");
                $row->execute([(int)$staff['staff_id']]);
                $existing = $row->fetch(PDO::FETCH_ASSOC);
                if (!$existing) { notFoundResponse('Staff record not found.'); }

                // Verify against canonical variants to avoid edge-cases where temporary/reset
                // passwords are copied with accidental whitespace or HTML-escaped symbols.
                $currentCandidates = [];
                $currentCandidates[] = (string)$currentPassword;
                $currentCandidates[] = trim((string)$currentPassword);
                $decoded = html_entity_decode((string)$currentPassword, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $currentCandidates[] = $decoded;
                $currentCandidates[] = trim($decoded);
                $currentCandidates = array_values(array_unique($currentCandidates));

                $currentMatched = false;
                foreach ($currentCandidates as $cand) {
                    if ($cand !== '' && password_verify($cand, $existing['password_hash'])) {
                        $currentMatched = true;
                        break;
                    }
                }

                if (!$currentMatched) {
                    errorResponse('Current password is incorrect.', 401);
                }

                // Prevent reusing the same password.
                foreach ($currentCandidates as $cand) {
                    if ($cand !== '' && hash_equals($cand, (string)$newPassword)) {
                        validationError(['new_password' => 'New password must be different from current password.']);
                    }
                }

                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                $db->prepare("UPDATE staff SET password_hash = ?, failed_login_attempts = 0, account_locked = FALSE, locked_until = NULL, force_password_change = FALSE, password_changed_at = NOW(), updated_at = NOW() WHERE id = ?")
                   ->execute([$hash, (int)$staff['staff_id']]);

                logAudit($staff['full_name'], 'PASSWORD_CHANGE_SELF', 'Staff', (string)$staff['staff_id'], 'SUCCESS',
                    'Self-service password change by ' . $staff['full_name'], $staff['department'], getClientIp());

                successMessage('Password changed successfully.');
                break;
            }
        }

        // PUT /api/staff/me — update own profile (restricted fields only)
        // Only activate when the original route was /api/staff/me (not /api/staff/:id).
        // Admin editing themselves via the staff management modal (PUT /api/staff/1) must
        // fall through to the full admin handler which allows all fields including role,
        // department, branches, modules, approval_limit, etc.
        if ($isOwnSelfRoute && $subRes === null) {
            $input = getRequestInput();
            $allowedFields = ['full_name', 'email', 'phone', 'position', 'timezone', 'locale', 'notifications_enabled'];
            $fields = [];
            $params = [':id' => (int)$staff['staff_id']];

            foreach ($allowedFields as $key) {
                if (isset($input[$key])) {
                    $fields[] = "$key = :$key";
                    $params[":$key"] = sanitize($input[$key]);
                }
            }

            // Profile picture: allow explicit null to remove, or base64 string to set
            // Use array_key_exists instead of isset() because null is a valid value (remove picture)
            if (array_key_exists('profile_picture', $input)) {
                if ($input['profile_picture'] === null || $input['profile_picture'] === '') {
                    $fields[] = "\"profile_picture\" = NULL";
                } else {
                    // ★ SECURITY: Validate profile picture size (max ~500KB base64)
                    if (strlen($input['profile_picture']) > 700000) {
                        validationError(['profile_picture' => 'Profile picture exceeds maximum size (500KB). Please compress and try again.']);
                    }
                    // ★ FIX (SP-026): Validate profile picture is a safe image data URI.
                    // Previously accepted any content (SVG with scripts, arbitrary data, etc.)
                    $pp = $input['profile_picture'];
                    if (!preg_match('/^data:image\/(png|jpeg|jpg|gif|webp);base64,/i', $pp)) {
                        validationError(['profile_picture' => 'Profile picture must be a valid image (PNG, JPEG, GIF, or WebP).']);
                    }
                    $fields[] = "\"profile_picture\" = :profile_picture";
                    $params[":profile_picture"] = $pp;
                }
            }

            if (empty($fields)) {
                validationError(['fields' => 'No updatable fields provided. Allowed: ' . implode(', ', $allowedFields)]);
            }

            $sql = 'UPDATE staff SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            logAudit($staff['full_name'], 'STAFF_UPDATE_SELF', 'Staff', (string)$staff['staff_id'], 'SUCCESS',
                'Self-service profile update by ' . $staff['full_name'], $staff['department'], getClientIp());

            $row = $db->prepare(
                'SELECT id, username, full_name, initials, email, phone, position, role,
                        department, employment_status, approval_limit, mfa_required,
                        ip_restrictions, last_login, last_login_ip, failed_login_attempts,
                        account_locked, locked_until, force_password_change, password_changed_at,
                        timezone, locale, notifications_enabled, profile_picture,
                        deactivation_reason, suspension_reason,
                        created_at, updated_at
                 FROM staff WHERE id = ?'
            );
            $row->execute([(int)$staff['staff_id']]);
            $updated = $row->fetch(PDO::FETCH_ASSOC);
            if ($updated) attachAssignments($db, $updated);

            successResponse($updated, 'Profile updated successfully.');
            break;
        }

        // ── Admin/Manager operations below this point ──
        if ($id === null) { validationError(['id' => 'Staff ID is required.']); }
        $staffId = (int)$id;
        $input = getRequestInput();

        // Access control: ADMIN can edit any staff; MANAGER can edit with restrictions
        if (isCurrentUserAdmin($staff)) {
            // Admin can do anything
        } elseif (strtoupper($staff['role']) === 'MANAGER') {
            // Manager: restrict branch changes to own branches
            if (isset($input['branches']) && is_array($input['branches'])) {
                $mgrBranches = $staff['branches'] ?? [];
                $input['branches'] = array_values(array_filter($input['branches'], function($b) use ($mgrBranches) {
                    return in_array($b, $mgrBranches);
                }));
            }
            // ★ FIX (SP-007): Manager cannot change anyone's role to ADMIN or MANAGER.
            if (isset($input['role']) && in_array(strtoupper($input['role']), ['ADMIN', 'MANAGER'])) {
                unset($input['role']);
            }
            // ★ FIX: Manager cannot change modules to "ALL" or assign modules they don't have
            if (isset($input['modules']) && is_array($input['modules'])) {
                $mgrModules = array_map('strtoupper', array_column($staff['modules'] ?? [], 'name'));
                $isMgrAll = in_array('ALL', $mgrModules);
                if (!$isMgrAll) {
                    $input['modules'] = array_values(array_filter($input['modules'], function($m) use ($mgrModules) {
                        $mName = strtoupper(is_array($m) ? ($m['name'] ?? '') : $m);
                        return in_array($mName, $mgrModules) && $mName !== 'ALL';
                    }));
                }
            }
            // ★ FIX: Manager cannot increase approval_limit beyond their own
            if (isset($input['approval_limit'])) {
                $mgrLimitParsed = parseDecimalInput($input['approval_limit'], 'Approval limit', 2, 0, 999999999.99);
                if (!$mgrLimitParsed['ok']) { validationError(['approval_limit' => $mgrLimitParsed['error']]); }
                $input['approval_limit'] = min($mgrLimitParsed['value'], (float)$staff['approval_limit']);
            }
        } else {
            // Non-admin/manager can only edit limited fields via the "me" handler.
            // If they reached here with a numeric ID, it's a direct API attempt.
            if ($originalId !== 'me') {
                forbiddenResponse('Only Admin and Manager roles can edit staff.');
            }
        }

        // ── Password reset sub-resource: PUT /api/staff/:id/password ──
        if ($subRes === 'password') {
            require_once __DIR__ . '/../middleware/rate_limit.php';
            requireRateLimit('pwd_reset:' . getClientIp() . ':' . $staffId, 5, 300);
            $newPassword = $input['password'] ?? '';
            if (strlen($newPassword) < 8) { validationError(['password' => 'Password must be at least 8 characters.']); }
            if (!preg_match('/[A-Z]/', $newPassword)) { validationError(['password' => 'Password must contain at least one uppercase letter.']); }
            if (!preg_match('/[a-z]/', $newPassword)) { validationError(['password' => 'Password must contain at least one lowercase letter.']); }
            if (!preg_match('/[0-9]/', $newPassword)) { validationError(['password' => 'Password must contain at least one digit.']); }
            if (!preg_match('/[!@#$%^&*()\-_+=\[\]{}|;:",.<>?\/\\`~]/', $newPassword)) { validationError(['password' => 'Password must contain at least one special character.']); }

            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            // ★ FIX: Admin password reset clears force_password_change.
            // The admin has already provided a new password — no need to force the user
            // to change it again. force_password_change = TRUE is only for NEW accounts
            // (first-time login via staff creation at line ~507).
            // ★ FIX (SP-005): Respect force_password_change from input for manager resets.
            // Previously hardcoded to 0 — manager-initiated resets should force change on next login.
            // If the frontend sends force_password_change: true, the user must change on next login.
            $forceChange = !empty($input['force_password_change']) ? 1 : 0;
            $db->prepare("UPDATE staff SET password_hash = ?, failed_login_attempts = 0, account_locked = FALSE, locked_until = NULL, force_password_change = ?, password_changed_at = NOW(), updated_at = NOW() WHERE id = ?")
               ->execute([$hash, $forceChange, $staffId]);
            logAudit($staff['full_name'], 'PASSWORD_RESET', 'Staff', (string)$staffId, 'SUCCESS',
                'Password reset by ' . $staff['full_name'], $staff['department'], getClientIp());
            successMessage('Password reset successfully.');
            break;
        }

        // Verify staff exists
        $check = $db->prepare("SELECT id FROM staff WHERE id = ?");
        $check->execute([$staffId]);
        if (!$check->fetch()) { notFoundResponse('Staff member not found.'); }

        // Check username uniqueness if changing
        if (!empty($input['username'])) {
            $dup = $db->prepare("SELECT id FROM staff WHERE username = ? AND id != ?");
            $dup->execute([sanitize($input['username']), $staffId]);
            if ($dup->fetch()) { conflictResponse('Username "' . $input['username'] . '" already taken.'); }
        }

        $fields = [];
        $params = [':id' => $staffId];

        $fieldMap = [
            'full_name'           => 'full_name',
            'initials'            => 'initials',
            'email'               => 'email',
            'phone'               => 'phone',
            'position'            => 'position',
            'role'                => 'role',
            'department'          => 'department',
            'employment_status'   => 'employment_status',
            'ip_restrictions'     => 'ip_restrictions',
            'deactivation_reason' => 'deactivation_reason',
            'suspension_reason'   => 'suspension_reason',
        ];
        // ★ FIX (SP-037): Validate employment_status to only accept ACTIVE/INACTIVE/SUSPENDED.
        // Previously any string (e.g. "TERMINATED", "HACKED") was stored — breaking frontend
        // logic that expects only these three valid statuses.
        if (isset($input['employment_status'])) {
            $validStatuses = ['ACTIVE', 'INACTIVE', 'SUSPENDED'];
            if (!in_array(strtoupper($input['employment_status']), $validStatuses)) {
                validationError(['employment_status' => 'Invalid status. Must be ACTIVE, INACTIVE, or SUSPENDED.']);
            }
        }
        foreach ($fieldMap as $key => $col) {
            if (isset($input[$key])) {
                $fields[] = "$col = :$key";
                $params[":$key"] = sanitize($input[$key]);
            }
        }
        // Profile picture: handle null (remove) and base64 (set) separately
        if (array_key_exists('profile_picture', $input)) {
            if ($input['profile_picture'] === null || $input['profile_picture'] === '') {
                $fields[] = '"profile_picture" = NULL';
            } else {
                // ★ SECURITY: Validate profile picture size (max ~500KB base64)
                if (strlen($input['profile_picture']) > 700000) {
                    validationError(['profile_picture' => 'Profile picture exceeds maximum size (500KB). Please compress and try again.']);
                }
                // ★ FIX (SP-026): Validate profile picture is a safe image data URI
                $pp = $input['profile_picture'];
                if (!preg_match('/^data:image\/(png|jpeg|jpg|gif|webp);base64,/i', $pp)) {
                    validationError(['profile_picture' => 'Profile picture must be a valid image (PNG, JPEG, GIF, or WebP).']);
                }
                $fields[] = '"profile_picture" = :profile_picture';
                $params[":profile_picture"] = $pp;
            }
        }
        if (isset($input['approval_limit'])) {
            $approvalLimitParsed = parseDecimalInput($input['approval_limit'], 'Approval limit', 2, 0, 999999999.99);
            if (!$approvalLimitParsed['ok']) { validationError(['approval_limit' => $approvalLimitParsed['error']]); }
            $fields[] = 'approval_limit = :limit';
            $params[':limit'] = $approvalLimitParsed['value'];
        }
        if (isset($input['mfa_required'])) {
            $fields[] = 'mfa_required = :mfa';
            $params[':mfa'] = (int)(bool)$input['mfa_required'];
        }
        if (isset($input['username'])) {
            $fields[] = 'username = :username';
            $params[':username'] = sanitize($input['username']);
        }

        if (!empty($fields)) {
            $sql = 'UPDATE staff SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
        }

        // Sync branch and module assignments
        if (isset($input['branches']) && is_array($input['branches'])) {
            syncBranches($db, $staffId, $input['branches']);
        }
        if (isset($input['modules']) && is_array($input['modules'])) {
            $rawMods = $input['modules'];
            $modulePayload = [];
            if (!empty($rawMods) && isset($rawMods[0]) && is_array($rawMods[0])) {
                // New format: [{name, access}, ...]
                foreach ($rawMods as $m) {
                    $name = trim($m['name'] ?? '');
                    $access = strtoupper($m['access'] ?? 'FULL');
                    if ($name === '') continue;
                    if (!in_array($access, ['FULL', 'VIEW_ONLY'], true)) $access = 'FULL';
                    $modulePayload[] = ['name' => $name, 'access' => $access];
                }
            } else {
                // Legacy format: plain strings — default to FULL access
                $moduleKeys = normaliseModuleNames($rawMods);
                foreach ($moduleKeys as $name) {
                    $modulePayload[] = ['name' => $name, 'access' => 'FULL'];
                }
            }
            syncModules($db, $staffId, $modulePayload);
        }

        logAudit($staff['full_name'], 'STAFF_UPDATE', 'Staff', (string)$staffId, 'SUCCESS',
            'Updated staff ID: ' . $staffId, $staff['department'], getClientIp());

        $row = $db->prepare(
            'SELECT id, username, full_name, initials, email, phone, position, role,
                    department, employment_status, approval_limit, mfa_required,
                    ip_restrictions, last_login, last_login_ip, failed_login_attempts,
                    account_locked, locked_until, force_password_change, password_changed_at,
                    timezone, locale, notifications_enabled, profile_picture,
                    deactivation_reason, suspension_reason,
                    created_at, updated_at
             FROM staff WHERE id = ?'
        );
        $row->execute([$staffId]);
        $updated = $row->fetch(PDO::FETCH_ASSOC);
        if ($updated) attachAssignments($db, $updated);

        successResponse($updated, 'Staff member updated successfully.');
        break;

    /* ================================================================
       DELETE — Individual Session Revoke (self-service) or Admin Delete
       ================================================================ */
    case 'DELETE':
        // DELETE /api/staff/me/sessions — revoke a specific other session (self-service)
        if ($originalId === 'me' && $subRes === 'sessions') {
            $sessionId = sanitize($_GET['session_id'] ?? ''); // ★ SECURITY: Removed $_REQUEST fallback (cookie injection vector)
            if (empty($sessionId)) { validationError(['session_id' => 'Session ID is required.']); }

            $currentToken = resolveCurrentToken();
            if ($sessionId === $currentToken) {
                errorResponse('Cannot revoke your own current session. Use Sign Out instead.', 400);
            }

            try {
                $stmt = $db->prepare("DELETE FROM sessions WHERE id = ? AND staff_id = ?");
                $stmt->execute([$sessionId, (int)$staff['staff_id']]);
                if ($stmt->rowCount() === 0) { notFoundResponse('Session not found or already expired.'); }
                logAudit($staff['full_name'], 'SESSION_REVOKE', 'Staff', (string)$staff['staff_id'], 'SUCCESS',
                    "Revoked individual session: " . substr($sessionId, 0, 16) . "...", $staff['department'], getClientIp());
                successMessage('Session revoked successfully.');
            } catch (PDOException $e) {
                serverErrorResponse('Failed to revoke session.');
            }
            break;
        }

        // DELETE /api/staff/:id — Admin delete staff member
        if ($id === null) { validationError(['id' => 'Staff ID is required.']); }
        requireRole(['ADMIN'], $staff);

        // ★ FIX (SP-038): Branch isolation on DELETE — previously any admin could delete
        // staff from any branch. Now non-superadmin users can only delete staff who share
        // at least one branch assignment with them.
        if (!isCurrentUserAdmin($staff)) {
            // This shouldn't happen due to requireRole above, but defense in depth
            forbiddenResponse('Only Admin can delete staff.');
        }
        // Even for Admin, verify branch overlap if they have assigned branches
        $adminBranches = $staff['branches'] ?? [];
        if (!empty($adminBranches)) {
            $targetBrStmt = $db->prepare("SELECT branch_name FROM staff_branches WHERE staff_id = ?");
            $targetBrStmt->execute([(int)$id]);
            $targetBranches = $targetBrStmt->fetchAll(PDO::FETCH_COLUMN, 0);
            if (!empty($targetBranches)) {
                $overlap = array_intersect($adminBranches, $targetBranches);
                if (empty($overlap)) {
                    forbiddenResponse('Cannot delete staff from branches you are not assigned to. Contact a super-admin.');
                }
            }
        }

        try {
            // ★ SECURITY: Kill all active sessions for deleted staff to prevent lingering access
            $db->prepare('DELETE FROM sessions WHERE staff_id = ?')->execute([(int)$id]);
            // Cascade: remove junction records first
            $db->prepare('DELETE FROM staff_modules WHERE staff_id = ?')->execute([(int)$id]);
            $db->prepare('DELETE FROM staff_branches WHERE staff_id = ?')->execute([(int)$id]);

            $stmt = $db->prepare('DELETE FROM staff WHERE id = ?');
            $stmt->execute([(int)$id]);

            if ($stmt->rowCount() === 0) { notFoundResponse('Staff member not found.'); }

            logAudit($staff['full_name'], 'STAFF_DELETE', 'Staff', (string)$id, 'SUCCESS',
                'Deleted staff ID: ' . $id, $staff['department'], getClientIp());
            successMessage('Staff member deleted successfully.');
        } catch (PDOException $e) {
            serverErrorResponse('Failed to delete staff member.');
        }
        break;

    default:
        errorResponse('Method not allowed.', 405);
}

} catch (Exception $e) {
    serverErrorResponse('Staff API error.');
}
