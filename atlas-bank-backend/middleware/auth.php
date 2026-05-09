<?php
/**
 * Atlas Bank Enterprise Operations Console
 * Authentication Middleware
 *
 * Supports both cookie-based and Authorization header (Bearer token) auth.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * Get the currently authenticated staff member.
 *
 * Checks for session token in three places (in order of priority):
 *   1. Authorization: Bearer <token> header
 *   2. X-Atlas-Session header
 *   3. X-Atlas-Session cookie
 *
 * If valid and not expired, returns the staff record from the database.
 * Otherwise sends a 401 response and exits.
 *
 * @return array Staff record from the staff table
 */
function requireAuth(): array
{
    $token = null;

    // Priority 1: Authorization header (Bearer token)
    // Try multiple sources because Apache often strips this header from $_SERVER.
    // Order: $_SERVER → REDIRECT_ prefix (SetEnvIf) → getallheaders() fallback
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if (empty($authHeader) && function_exists('getallheaders')) {
        $allHeaders = getallheaders();
        $authHeader = $allHeaders['Authorization'] ?? $allHeaders['authorization'] ?? '';
    }
    if (!empty($authHeader) && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        $token = trim($matches[1]);
    }

    // Priority 2: Explicit session header fallback.
    // The SPA sends this when the browser/server path drops Authorization headers
    // or when the app is opened outside the backend origin and cookies are unreliable.
    if (empty($token)) {
        $token = $_SERVER['HTTP_X_ATLAS_SESSION']
            ?? $_SERVER['REDIRECT_HTTP_X_ATLAS_SESSION']
            ?? '';
        if (empty($token) && function_exists('getallheaders')) {
            $allHeaders = getallheaders();
            $token = $allHeaders['X-Atlas-Session']
                ?? $allHeaders['x-atlas-session']
                ?? '';
        }
    }

    // Priority 3: Session cookie ONLY.
    // ★ SECURITY FIX (CRITICAL): Removed $_GET['session_token'] fallback.
    // Tokens in query strings leak via browser history, server logs, Referer headers,
    // and enable CSRF attacks. Session tokens are now accepted ONLY from:
    //   1. Authorization: Bearer <token> header
    //   2. X-Atlas-Session header
    //   3. X-Atlas-Session cookie
    if (empty($token)) {
        $token = $_COOKIE['X-Atlas-Session'] ?? '';
    }

    // ★ DEFENSIVE: Ensure $_ROUTE is always initialized.
    // Some requests (CORS preflight, early middleware calls) may invoke requireAuth()
    // before the router sets $_ROUTE. Without this, the force-password gate at the
    // bottom of this function reads empty route variables and incorrectly allows or
    // blocks requests. This also prevents "Undefined index" PHP notices.
    $_ROUTE = $_ROUTE ?? ['resource' => '', 'id' => '', 'subResource' => '', 'method' => 'GET'];

    if (empty($token)) {
        sendAuthError('Authentication required. No session token provided.', 401);
    }

    // Sanitize the token
    $token = sanitizeToken($token);
    if (empty($token)) {
        sendAuthError('Invalid session token format.', 401);
    }

    try {
        $db = getDB();

        // ── Self-heal: ensure sessions + staff tables have enterprise columns ──
        _ensureSessionColumns($db);
        _ensureStaffColumns($db);

        // Look up the session in the database
        $stmt = $db->prepare(
            'SELECT s.id AS session_id, s.staff_id, s.ip_address, s.expires_at, s.created_at,
                    s.last_activity, s.device_fingerprint, s.label AS session_label,
                    st.id, st.username, st.full_name, st.initials, st.email, st.phone,
                    st.position, st.role, st.department, st.employment_status,
                    st.approval_limit, st.mfa_required, st.last_login,
                    st.account_locked, st.locked_until, st.failed_login_attempts,
                    st.force_password_change, st.password_changed_at
             FROM sessions s
             INNER JOIN staff st ON s.staff_id = st.id
             WHERE s.id = :token
             LIMIT 1'
        );
        $stmt->execute([':token' => $token]);
        $session = $stmt->fetch();

        if (!$session) {
            sendAuthError('Session not found. Please log in again.', 401);
        }

        // Check if session has expired
        $now = new DateTime();
        $expiresAt = new DateTime($session['expires_at']);
        if ($now > $expiresAt) {
            // Clean up expired session
            $deleteStmt = $db->prepare('DELETE FROM sessions WHERE id = :token');
            $deleteStmt->execute([':token' => $token]);
            sendAuthError('Session expired. Please log in again.', 401);
        }

        // ★ FIX: Read session timeout from DB settings instead of hardcoding 15 minutes.
        try {
            $timeoutMinutes = (int)getSetting($db, 'security.session_timeout', 480);
        } catch (Throwable $_) {
            $timeoutMinutes = defined('SESSION_LIFETIME') ? (int)SESSION_LIFETIME : 480;
        }
        try {
            // DB clocks can drift; enforce timeout using the authoritative expires_at
            // and avoid deleting fresh sessions due to stale DB NOW() values.
            $lastActivityStr = $session['last_activity'] ?? $session['created_at'] ?? null;
            if ($lastActivityStr) {
                $lastActivity = new DateTime($lastActivityStr);
                $idleSeconds = $now->getTimestamp() - $lastActivity->getTimestamp();
                if ($idleSeconds > ($timeoutMinutes * 60)) {
                    $expiresAtTs = $expiresAt->getTimestamp();
                    if ($expiresAtTs <= $now->getTimestamp()) {
                        $deleteStmt = $db->prepare('DELETE FROM sessions WHERE id = :token');
                        $deleteStmt->execute([':token' => $token]);
                        sendAuthError('Session expired due to inactivity. Please log in again.', 401);
                    }
                }
            }
        } catch (Throwable $e) { /* non-critical */ }

        // Check if staff member is still active
        if ($session['employment_status'] !== 'ACTIVE') {
            // Terminate the session
            $deleteStmt = $db->prepare('DELETE FROM sessions WHERE id = :token');
            $deleteStmt->execute([':token' => $token]);
            sendAuthError('Account is not active. Contact administrator.', 403);
        }

        // Check if account is locked
        if ($session['account_locked']) {
            $lockedUntil = $session['locked_until'];
            if ($lockedUntil !== null) {
                $lockedAt = new DateTime($lockedUntil);
                if ($now < $lockedAt) {
                    sendAuthError('Account is temporarily locked. Try again later.', 403);
                } else {
                    // Unlock the account
                    $unlockStmt = $db->prepare(
                        'UPDATE staff SET account_locked = FALSE, locked_until = NULL, failed_login_attempts = 0
                         WHERE id = :staff_id'
                    );
                    $unlockStmt->execute([':staff_id' => $session['staff_id']]);
                }
            }
        }

        // Sliding idle timeout: update last_activity + extend expiry on each authenticated request
        try {
            $slideMinutes = $timeoutMinutes;
            $nextExpiry = date('Y-m-d H:i:s', time() + ($slideMinutes * 60));
            $db->prepare('UPDATE sessions SET last_activity = :last_activity, expires_at = :expires_at WHERE id = :token')
               ->execute([
                   ':last_activity' => date('Y-m-d H:i:s'),
                   ':expires_at' => $nextExpiry,
                   ':token' => $token
               ]);
            $cookieExpire = time() + ($slideMinutes * 60);
            setcookie('X-Atlas-Session', $token, [
                'expires'  => $cookieExpire,
                'path'     => '/',
                'domain'   => '',
                'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
        } catch (PDOException $e) { /* non-critical */ }

        // ── IP drift detection: alert if session IP changed ──
        try {
            $currentIp = getClientIp();
            if (!empty($session['ip_address']) && $session['ip_address'] !== $currentIp) {
                logAudit(
                    $session['full_name'], 'SESSION_IP_DRIFT', 'SESSION',
                    (string)$session['staff_id'], 'SUCCESS',
                    'Session IP changed from ' . $session['ip_address'] . ' to ' . $currentIp,
                    $session['department'] ?? '', $currentIp
                );
                // Update session IP to the current one
                $db->prepare('UPDATE sessions SET ip_address = :ip WHERE id = :token')
                   ->execute([':ip' => $currentIp, ':token' => $token]);
                $session['ip_address'] = $currentIp;
            }
        } catch (PDOException $e) { /* non-critical */ }

        // ── Force password change enforcement ──
        if (!empty($session['force_password_change'])) {
            $resource = $_ROUTE['resource'] ?? '';
            $routeId = $_ROUTE['id'] ?? '';
            $subRes = $_ROUTE['subResource'] ?? '';
            $method = $_ROUTE['method'] ?? '';
            $isOwnStaffRoute = ($resource === 'staff' && (string)$routeId === (string)($session['staff_id'] ?? ''));

            // Allow these routes when password change is forced:
            //   GET /auth                    → session validation (so the UI can show the password change prompt)
            //   GET /auth/login-history      → view own login history
            //   GET /staff/me                → load own profile (needed by password change form)
            //   GET /staff/me/login-history  → view own login history (profile modal)
            //   GET /staff/me/activity      → view own activity log (profile modal)
            //   GET /staff/me/sessions       → view/manage own sessions (profile modal)
            //   DELETE /staff/me/sessions    → revoke own sessions
            //   DELETE /auth/sessions        → terminate other sessions
            //   PUT /staff/me/password       → actual password change
            //   PUT /staff/me                → update profile (timezone, notifications)
            $isSelfRead = (
                $resource === 'staff' && $routeId === 'me' && $method === 'GET' &&
                (empty($subRes) || in_array($subRes, ['login-history', 'activity', 'sessions'], true))
            );
            $isSelfDelete = (
                $resource === 'staff' && $routeId === 'me' && $method === 'DELETE' &&
                in_array($subRes, ['sessions'], true)
            );
            $isAuthDelete = (
                $resource === 'auth' && $routeId === 'sessions' && $method === 'DELETE'
            );
            $allowed = (
                ($resource === 'auth' && $method === 'GET') ||
                ($isAuthDelete) ||
                ($isSelfRead) ||
                ($isSelfDelete) ||
                ($resource === 'staff' && $routeId === 'me' && $subRes === 'password' && ($method === 'PUT' || $method === 'POST')) ||
                ($resource === 'staff' && $routeId === 'me' && empty($subRes) && ($method === 'PUT' || $method === 'POST')) ||
                ($isOwnStaffRoute && $subRes === 'password' && ($method === 'PUT' || $method === 'POST')) ||
                ($isOwnStaffRoute && empty($subRes) && ($method === 'PUT' || $method === 'POST'))
            );

            // ★ DEFENSIVE: The password change sub-resource is ALWAYS allowed when the
            // force_password_change session flag is set. This is a safety net — even if
            // the route-condition above fails to evaluate correctly (e.g. edge case with
            // whitespace, case mismatch, or _method override), the user must be able to
            // change their password to escape the forced-change sandbox.
            if ($resource === 'staff' && ($routeId === 'me' || (string)$routeId === (string)($session['staff_id'] ?? '')) && $subRes === 'password') {
                $allowed = true;
            }

            // ★ DEBUG: Log the gate evaluation for staff/me/password
            if ($resource === 'staff' && $routeId === 'me' && $subRes === 'password') {
                error_log('[AUTH DEBUG] force_password_change=1, staff/me/password detected. allowed=' . ($allowed ? 'true' : 'false') . ' method=' . $method . ' subRes=' . var_export($subRes, true));
            }

            // ★ FORCE PASSWORD 403 EXIT POINT
            error_log('[AUTH FORCE_PWD BLOCK] resource=' . ($resource ?? 'NULL') . ' routeId=' . ($routeId ?? 'NULL') . ' subRes=' . var_export($subRes, true) . ' method=' . ($method ?? 'NULL') . ' allowed=' . ($allowed ? 'true' : 'false'));
            if (!$allowed) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                header('X-Password-Change-Required: 1');
                echo json_encode([
                    'success'  => false,
                    'error'    => 'Password change required. Please update your password before continuing.',
                    'code'     => 403,
                    'requires_password_change' => true
                ]);
                exit;
            }
        }

        // expiry extension handled above (idle sliding window)

        // Get staff modules for RBAC — self-healing
        // If staff_modules table doesn't exist yet, auto-create it and default to ALL access.
        try {
            // Try loading with access_level (new schema) — if column doesn't exist, fall back
            try {
                $modStmt = $db->prepare("SELECT module_name, COALESCE(access_level, 'FULL') AS access_level FROM staff_modules WHERE staff_id = :staff_id");
                $modStmt->execute([':staff_id' => $session['staff_id']]);
                $modRows = $modStmt->fetchAll(PDO::FETCH_ASSOC);
                $modules = array_column($modRows, 'module_name');
                $session['modules'] = $modules;
                // Store per-module access levels for future granular permission checks
                $session['module_access'] = array_column($modRows, 'access_level', 'module_name');
            } catch (PDOException $e) {
                // Fallback: access_level column doesn't exist yet
                $modStmt = $db->prepare('SELECT module_name FROM staff_modules WHERE staff_id = :staff_id');
                $modStmt->execute([':staff_id' => $session['staff_id']]);
                $modules = $modStmt->fetchAll(PDO::FETCH_COLUMN, 0);
                $session['modules'] = $modules;
                // Store per-module access levels for future granular permission checks
                $session['module_access'] = array_combine($modules, array_fill(0, count($modules), 'FULL'));
            }

            // ── Self-heal: auto-assign TRANSACTIONS module for staff with zero modules ──
            // In enterprise banking, all active staff (tellers, cashiers, officers) must be
            // able to perform deposits and withdrawals. If the admin created a staff account
            // but forgot to assign any modules, the user gets a silent 403 on every operation.
            // This one-time self-heal grants the TRANSACTIONS module so the teller can operate.
            if (empty($modules) && strtoupper($session['role'] ?? '') !== 'SUPER_ADMIN') {
                $defaultModules = ['TRANSACTIONS', 'ACCOUNTS', 'CUSTOMERS', 'DASHBOARD'];
                $insStmt = $db->prepare('INSERT INTO staff_modules (staff_id, module_name) VALUES (:sid, :mod) ON CONFLICT (staff_id, module_name) DO NOTHING');
                foreach ($defaultModules as $mod) {
                    $insStmt->execute([':sid' => $session['staff_id'], ':mod' => $mod]);
                }
                $session['modules'] = $defaultModules;
                $session['module_access'] = array_combine($defaultModules, array_fill(0, count($defaultModules), 'FULL'));
                error_log('[AUTH SELF-HEAL] Staff ID ' . $session['staff_id'] . ' (' . $session['username'] . ') had zero modules. Auto-assigned: ' . implode(', ', $defaultModules));
                try {
                    logAudit(
                        $session['full_name'], 'MODULE_AUTO_ASSIGN', 'STAFF',
                        (string)$session['staff_id'], 'SUCCESS',
                        'Auto-assigned modules to staff with zero assignments: ' . implode(', ', $defaultModules),
                        $session['department'] ?? '', getClientIp()
                    );
                } catch (\Throwable $auditErr) { /* non-critical */ }
            }
        } catch (PDOException $e) {
            // Table doesn't exist — auto-create it
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS staff_modules (
                    id SERIAL PRIMARY KEY,
                    staff_id INTEGER NOT NULL,
                    module_name VARCHAR(50) NOT NULL,
                    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT uk_staff_module UNIQUE (staff_id, module_name)
                )");
                try { $db->exec("CREATE INDEX IF NOT EXISTS idx_sm_staff ON staff_modules (staff_id)"); } catch (PDOException $ie) {}
            } catch (PDOException $e2) { /* table creation also failed, continue with defaults */ }
            // ★ SECURITY FIX (MEDIUM): Changed from ['ALL'] to [].
            // When staff_modules table is unavailable (DB error, DDL race), granting
            // full admin access is a privilege escalation risk. Deny all modules
            // instead — the user gets a 403 on every operation, which is safe.
            $session['modules'] = [];
            error_log('[AUTH SECURITY] Staff ID ' . ($session['staff_id'] ?? 'unknown') . ': staff_modules query failed — granted ZERO modules (fail-safe).');
        }

        // Get staff branches — self-healing
        // If staff_branches table doesn't exist yet, auto-create it.
        try {
            $brStmt = $db->prepare('SELECT branch_name FROM staff_branches WHERE staff_id = :staff_id');
            $brStmt->execute([':staff_id' => $session['staff_id']]);
            $branches = $brStmt->fetchAll(PDO::FETCH_COLUMN, 0);
            $session['branches'] = $branches;
        } catch (PDOException $e) {
            // Table doesn't exist — auto-create it
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS staff_branches (
                    id SERIAL PRIMARY KEY,
                    staff_id INTEGER NOT NULL,
                    branch_name VARCHAR(255) NOT NULL,
                    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT uk_staff_branch UNIQUE (staff_id, branch_name)
                )");
                try { $db->exec("CREATE INDEX IF NOT EXISTS idx_sb_branch ON staff_branches (branch_name)"); } catch (PDOException $ie) {}
                try { $db->exec("CREATE INDEX IF NOT EXISTS idx_sb_staff ON staff_branches (staff_id)"); } catch (PDOException $ie) {}
            } catch (PDOException $e2) { /* table creation also failed, continue with defaults */ }
            $session['branches'] = [];
        }

        // Store session token
        $session['session_token'] = $token;

        return $session;

    } catch (PDOException $e) {
        sendAuthError('Authentication system error.', 500);
    }
}

/**
 * Send an authentication error response and exit.
 *
 * @param string $message Error message
 * @param int    $code    HTTP status code
 */
function sendAuthError(string $message, int $code): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => $message,
        'code'    => $code
    ]);
    exit;
}

/**
 * Sanitize a session token to prevent injection.
 *
 * @param string $token Raw token string
 * @return string Sanitized token
 */
function sanitizeToken(string $token): string
{
    // Session tokens should be alphanumeric with limited special chars
    return preg_replace('/[^a-zA-Z0-9\-_\.]/', '', $token);
}

/**
 * Get the authenticated user or null (does not exit on failure).
 *
 * @return array|null Staff record or null if not authenticated
 */
function getAuthUser(): ?array
{
    $token = null;

    // Try multiple sources for Authorization header (Apache strips it)
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if (empty($authHeader) && function_exists('getallheaders')) {
        $allHeaders = getallheaders();
        $authHeader = $allHeaders['Authorization'] ?? $allHeaders['authorization'] ?? '';
    }
    if (!empty($authHeader) && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        $token = trim($matches[1]);
    }

    if (empty($token)) {
        $token = $_SERVER['HTTP_X_ATLAS_SESSION']
            ?? $_SERVER['REDIRECT_HTTP_X_ATLAS_SESSION']
            ?? '';
        if (empty($token) && function_exists('getallheaders')) {
            $allHeaders = getallheaders();
            $token = $allHeaders['X-Atlas-Session']
                ?? $allHeaders['x-atlas-session']
                ?? '';
        }
    }

    if (empty($token)) {
        $token = $_COOKIE['X-Atlas-Session'] ?? '';
        // ★ SECURITY FIX (CRITICAL): Removed $_GET['session_token'] fallback.
        // Tokens must only come from Authorization header, X-Atlas-Session header, or cookie.
    }

    if (empty($token)) {
        return null;
    }

    $token = sanitizeToken($token);
    if (empty($token)) {
        return null;
    }

    try {
        $db = getDB();

        // ── Self-heal: ensure sessions + staff tables have enterprise columns ──
        _ensureSessionColumns($db);
        _ensureStaffColumns($db);

        $stmt = $db->prepare(
            'SELECT s.id AS session_id, s.staff_id, s.ip_address, s.expires_at,
                    s.last_activity, s.device_fingerprint, s.label AS session_label,
                    st.id, st.username, st.full_name, st.initials, st.email, st.phone,
                    st.position, st.role, st.department, st.employment_status,
                    st.approval_limit, st.force_password_change
             FROM sessions s
             INNER JOIN staff st ON s.staff_id = st.id
             WHERE s.id = :token
             LIMIT 1'
        );
        $stmt->execute([':token' => $token]);
        $session = $stmt->fetch();

        if (!$session) {
            return null;
        }

        // Keep expiry checks aligned with requireAuth() to avoid DB clock-skew logout on refresh.
        $expiresAtTs = strtotime((string)($session['expires_at'] ?? ''));
        if ($expiresAtTs !== false && time() > $expiresAtTs) {
            try {
                $db->prepare('DELETE FROM sessions WHERE id = :token')->execute([':token' => $token]);
            } catch (PDOException $_) {}
            return null;
        }

        // Non-critical: if sliding update fails, keep current session valid.
        try {
            // ★ FIX: Read session timeout from DB settings instead of hardcoding 15 minutes.
            try {
                $timeoutMinutes = (int)getSetting($db, 'security.session_timeout', 480);
            } catch (Throwable $_) {
                $timeoutMinutes = defined('SESSION_LIFETIME') ? (int)SESSION_LIFETIME : 480;
            }
            $nextExpiry = date('Y-m-d H:i:s', time() + ($timeoutMinutes * 60));
            $db->prepare('UPDATE sessions SET last_activity = :last_activity, expires_at = :expires_at WHERE id = :token')
               ->execute([
                   ':last_activity' => date('Y-m-d H:i:s'),
                   ':expires_at' => $nextExpiry,
                   ':token' => $token
               ]);
            setcookie('X-Atlas-Session', $token, [
                'expires'  => time() + ($timeoutMinutes * 60),
                'path'     => '/',
                'domain'   => '',
                'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
        } catch (Throwable $_) { /* keep authenticated session */ }

        // Get modules — self-healing
        try {
            $modStmt = $db->prepare('SELECT module_name FROM staff_modules WHERE staff_id = :staff_id');
            $modStmt->execute([':staff_id' => $session['staff_id']]);
            $session['modules'] = $modStmt->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (PDOException $e) {
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS staff_modules (
                    id SERIAL PRIMARY KEY,
                    staff_id INTEGER NOT NULL,
                    module_name VARCHAR(50) NOT NULL,
                    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT uk_staff_module UNIQUE (staff_id, module_name)
                )");
                try { $db->exec("CREATE INDEX IF NOT EXISTS idx_sm_staff ON staff_modules (staff_id)"); } catch (PDOException $ie) {}
            } catch (PDOException $e2) { /* continue with defaults */ }
            // ★ SECURITY FIX (MEDIUM): Changed from ['ALL'] to [] (fail-safe, see requireAuth).
            $session['modules'] = [];
        }

        // Get branches — self-healing
        try {
            $brStmt = $db->prepare('SELECT branch_name FROM staff_branches WHERE staff_id = :staff_id');
            $brStmt->execute([':staff_id' => $session['staff_id']]);
            $session['branches'] = $brStmt->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (PDOException $e) {
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS staff_branches (
                    id SERIAL PRIMARY KEY,
                    staff_id INTEGER NOT NULL,
                    branch_name VARCHAR(255) NOT NULL,
                    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT uk_staff_branch UNIQUE (staff_id, branch_name)
                )");
                try { $db->exec("CREATE INDEX IF NOT EXISTS idx_sb_branch ON staff_branches (branch_name)"); } catch (PDOException $ie) {}
                try { $db->exec("CREATE INDEX IF NOT EXISTS idx_sb_staff ON staff_branches (staff_id)"); } catch (PDOException $ie) {}
            } catch (PDOException $e2) { /* continue with defaults */ }
            $session['branches'] = [];
        }

        $session['session_token'] = $token;

        return $session;

    } catch (PDOException $e) {
        return null;
    }
}
