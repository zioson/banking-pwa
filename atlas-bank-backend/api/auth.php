<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Authentication (Login / Logout / Session)
 *
 * Public endpoint - no authentication required.
 */

// ★ FIX: Wrap entire auth endpoint in top-level try-catch so that ANY exception
// (including ErrorException from warnings, TypeError, etc.) is caught and reported
// with full debug info instead of returning a generic 500 from the router.
try {

// Safety net: ensure SESSION_NAME is defined even if database.php wasn't loaded yet.
// (Normally defined in config/database.php and loaded via helpers.php chain.)
if (!defined('SESSION_NAME')) {
    define('SESSION_NAME', 'ATLAS_BANK_SESSION');
}
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', false);
}

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/Response.php';
require_once __DIR__ . '/../middleware/csrf.php';

// ── Self-heal: seed security settings if they don't exist ──
// These are read by the login handler to enforce account lockout and session timeout.
// They can be changed by admins via the System Settings UI.
try {
    $dbSeed = getDB();
    // ★ Self-heal: ensure mfa_pending_tokens table exists (required by MFA login flow)
    _ensureMfaPendingTokensTable($dbSeed);
    // NOTE: Settings table uses 'value_data' column (PG migration artifact). 
    // The getSetting() function in helpers.php handles both 'value' and 'value_data'.
    // INSERT statements use 'value_data' to match the actual column name.
    $securityDefaults = [
        ['key' => 'security.max_login_attempts', 'name' => 'Max Login Attempts', 'category' => 'Security', 'value' => '5', 'description' => 'Number of failed login attempts before account is temporarily locked.'],
        ['key' => 'security.lockout_duration', 'name' => 'Lockout Duration (minutes)', 'category' => 'Security', 'value' => '30', 'description' => 'Duration in minutes that a locked account remains locked before auto-unlock.'],
        ['key' => 'security.session_timeout', 'name' => 'Session Timeout (minutes)', 'category' => 'Security', 'value' => '480', 'description' => 'Session inactivity timeout in minutes. After this period, the user must log in again.'],
        ['key' => 'security.max_concurrent_sessions', 'name' => 'Max Concurrent Sessions', 'category' => 'Security', 'value' => '3', 'description' => 'Maximum number of simultaneous active sessions per user. Oldest sessions are terminated when the limit is reached.']
    ];
    foreach ($securityDefaults as $def) {
        try {
            $exists = $dbSeed->prepare('SELECT 1 FROM settings WHERE "key" = :key LIMIT 1');
            $exists->execute([':key' => $def['key']]);
            if (!$exists->fetch()) {
                $dbSeed->prepare(
                    "INSERT INTO settings (\"key\", name, category, value_data, description) VALUES (:key, :name, :cat, :val, :desc)"
                )->execute([
                    ':key' => $def['key'], ':name' => $def['name'], ':cat' => $def['category'],
                    ':val' => $def['value'], ':desc' => $def['description']
                ]);
            }
        } catch (PDOException $e) { /* already exists or table not ready */ }
    }
} catch (PDOException $e) { /* DB not available yet */ }
// ★ FIX: Also catch Throwable for non-PDO errors during seeding (e.g., ErrorException
// from "constant already defined" warnings that the old error handler would throw)
catch (Throwable $e) {
    error_log('[AUTH SEED ERROR] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}

$method = $_ROUTE['method'];
$routeId = $_ROUTE['id'] ?? null;

switch ($method) {
    case 'GET':
        require_once __DIR__ . '/../middleware/auth.php';

        // ── GET /auth/sessions — Current session info ──
        if ($routeId === 'sessions') {
            $staff = requireAuth();
            try {
                $db = getDB();
                $stmt = $db->prepare(
                    'SELECT id, ip_address, user_agent, expires_at, created_at, last_activity, device_fingerprint, label FROM sessions WHERE staff_id = :sid ORDER BY created_at DESC'
                );
                $stmt->execute([':sid' => $staff['staff_id']]);
                $sessions = $stmt->fetchAll();
                // Mark which session is the current one
                foreach ($sessions as &$s) {
                    $s['is_current'] = ($s['id'] === $staff['session_token']);
                }
                unset($s);
                successResponse($sessions);
            } catch (PDOException $e) { serverErrorResponse('Failed to fetch sessions.'); }
            break;
        }

        // ── GET /auth/login-history — Recent login attempts ──
        if ($routeId === 'login-history') {
            $staff = requireAuth();
            // ★ FIX (FIN-2b-025): Rate limit login-history endpoint to prevent enumeration.
            require_once __DIR__ . '/../middleware/rate_limit.php';
            requireRateLimit('login_history:' . $staff['username'], 30, 60); // 30 requests per minute
            $limit = max(1, min((int)($_GET['limit'] ?? 20), 100));
            try {
                $db = getDB();
                $stmt = $db->prepare(
                    'SELECT id, username, result, ip, user_agent, risk, device_fingerprint, timestamp AS created_at FROM login_history WHERE username = :uname ORDER BY timestamp DESC LIMIT :lim'
                );
                $stmt->execute([':uname' => $staff['username'], ':lim' => $limit]);
                successResponse($stmt->fetchAll());
            } catch (PDOException $e) { serverErrorResponse('Failed to fetch login history.'); }
            break;
        }

        // Session validation endpoint — used by frontend to restore session on page refresh.
        // ★ FIX: Use getAuthUser() instead of requireAuth() so that a CSRF token can be
        // obtained BEFORE login (when there is no session). requireAuth() calls exit on 401,
        // which breaks the pre-login flow: enqueueMutation → refreshCsrfToken → GET /api/auth → 401.
        $staff = getAuthUser();
        $db = getDB();

        // ★ Return existing token ONLY if it is valid and not expired.
        // Token rotation happens on POST/PUT/DELETE via validateCsrfToken() in csrf.php.
        // If the token is expired (>8h), or missing, or a force-refresh is requested,
        // generate a fresh token so the frontend can resume mutations.
        $maxAge = 28800; // 8 hours — must match csrf.php
        $existingToken = getCsrfToken();
        $tokenTime = $_SESSION['csrf_token_time'] ?? 0;
        $forceRefresh = isset($_GET['force_csrf']) && $_GET['force_csrf'] === '1';
        if ($existingToken && (time() - $tokenTime) <= $maxAge && !$forceRefresh) {
            $csrfToken = $existingToken;
        } else {
            $csrfToken = generateCsrfToken();
        }

        $sessionTimeoutMinutes = (int)getSetting($db, 'security.session_timeout', 480);

        if (!$staff) {
            // No active session — return CSRF token only for pre-login requests.
            // This allows the mutation queue to refresh CSRF before the login POST.
            successResponse([
                'csrf_token'    => $csrfToken,
                'authenticated' => false,
                'client_ip'     => getClientIp(),
                'session_timeout' => $sessionTimeoutMinutes
            ], 'No active session.');
            break;
        }

        successResponse([
            'staff_id'                 => (int)$staff['id'],
            'username'                 => $staff['username'],
            'full_name'                => $staff['full_name'],
            'initials'                 => $staff['initials'],
            'email'                    => $staff['email'],
            'phone'                    => $staff['phone'],
            'position'                 => $staff['position'],
            'role'                     => $staff['role'],
            'department'               => $staff['department'],
            'branches'                 => $staff['branches'],
            'modules'                  => $staff['modules'],
            'approval_limit'           => (float)$staff['approval_limit'],
            'profile_picture'          => $staff['profile_picture'] ?? null,
            'csrf_token'               => $csrfToken,
            'client_ip'                => getClientIp(),
            'requires_password_change' => !empty($staff['force_password_change']),
            'session_timeout'          => $sessionTimeoutMinutes,
            'session_expires_at'       => $staff['expires_at'] ?? null,
            'last_activity'            => $staff['last_activity'] ?? null
        ], 'Session is valid.');
        break;

    case 'POST':
        // ── POST /auth/verify-mfa — Verify TOTP code ──
        if ($routeId === 'verify-mfa') {
            $input = getRequestInput();
            $code  = sanitize($input['code'] ?? '');
            $token = sanitize($input['mfa_pending_token'] ?? $_SERVER['HTTP_X_MFA_TOKEN'] ?? '');

            if (empty($code) || empty($token)) {
                validationError(['code' => 'MFA code and pending token are required.']);
            }

            try {
                $db = getDB();
                // Find the pending token
                $stmt = $db->prepare('SELECT staff_id FROM mfa_pending_tokens WHERE token = :token AND expires_at > NOW() LIMIT 1');
                $stmt->execute([':token' => $token]);
                $pending = $stmt->fetch();

                if (!$pending) {
                    errorResponse('Invalid or expired MFA session. Please log in again.', 401);
                }

                $staffId = (int)$pending['staff_id'];

                // Get staff record
                $staffStmt = $db->prepare("SELECT * FROM staff WHERE id = :id AND employment_status = 'ACTIVE' LIMIT 1");
                $staffStmt->execute([':id' => $staffId]);
                $staff = $staffStmt->fetch();

                if (!$staff) {
                    errorResponse('Account not found or inactive.', 403);
                }

                // ★ SECURITY FIX: Decrypt MFA secret before verification
                $mfaSecret = $staff['mfa_secret'];
                if (!empty($mfaSecret)) {
                    $decrypted = decryptData($mfaSecret);
                    if ($decrypted !== false) {
                        $mfaSecret = $decrypted;
                    }
                }

                // Verify TOTP code
                if (!verifyTotpCode($mfaSecret, $code)) {
                    // Record failure
                    recordLoginHistory($staff['username'], 'MFA_FAILURE', getClientIp(), $_SERVER['HTTP_USER_AGENT'] ?? '', 'HIGH');
                    errorResponse('Invalid MFA code. Please try again.', 401);
                }

                // SUCCESS — create real session
                $sessionToken = createSession($staffId, getClientIp(), $_SERVER['HTTP_USER_AGENT'] ?? '');
                if (empty($sessionToken)) {
                    serverErrorResponse('Failed to create session. Please contact administrator.');
                }
                
                // ★ SECURITY FIX: Set session token in a secure, HttpOnly cookie
                $cookieExpire = time() + (getSetting($db, 'security.session_timeout', 480) * 60);
                setcookie('X-Atlas-Session', $sessionToken, [
                    'expires' => $cookieExpire,
                    'path' => '/',
                    'domain' => '',
                    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                    'httponly' => true,
                    'samesite' => 'Strict'
                ]);

                // Delete pending token
                $db->prepare('DELETE FROM mfa_pending_tokens WHERE staff_id = :sid')->execute([':sid' => $staffId]);

                // Log success
                recordLoginHistory($staff['username'], 'SUCCESS', getClientIp(), $_SERVER['HTTP_USER_AGENT'] ?? '', 'NONE');
                logAudit($staff['full_name'], 'LOGIN_MFA_SUCCESS', 'STAFF', (string)$staffId, 'SUCCESS', 'MFA verified successfully', $staff['department'], getClientIp());

                // Generate CSRF token
                $csrfToken = generateCsrfToken();

                // Get staff modules
                try {
                    $modStmt = $db->prepare("SELECT module_name, COALESCE(access_level, 'FULL') AS access_level FROM staff_modules WHERE staff_id = :staff_id");
                    $modStmt->execute([':staff_id' => $staffId]);
                    $modules = array_map(fn($r) => ['name' => $r['module_name'], 'access' => $r['access_level']], $modStmt->fetchAll(PDO::FETCH_ASSOC));
                } catch (PDOException $e) {
                    $modStmt = $db->prepare('SELECT module_name FROM staff_modules WHERE staff_id = :staff_id');
                    $modStmt->execute([':staff_id' => $staffId]);
                    $modules = array_map(fn($m) => ['name' => $m, 'access' => 'FULL'], $modStmt->fetchAll(PDO::FETCH_COLUMN, 0));
                }

                // Get staff branches
                $brStmt = $db->prepare('SELECT branch_name FROM staff_branches WHERE staff_id = :staff_id');
                $brStmt->execute([':staff_id' => $staffId]);
                $branches = $brStmt->fetchAll(PDO::FETCH_COLUMN, 0);

                successResponse([
                    'token'      => $sessionToken,
                    'staff_id'   => $staffId,
                    'username'   => $staff['username'],
                    'full_name'  => $staff['full_name'],
                    'initials'   => $staff['initials'],
                    'email'      => $staff['email'],
                    'role'       => $staff['role'],
                    'department' => $staff['department'],
                    'branches'   => $branches,
                    'modules'    => $modules,
                    'csrf_token' => $csrfToken,
                    'client_ip'  => getClientIp()
                ], 'MFA verification successful.');

            } catch (PDOException $e) {
                serverErrorResponse('MFA verification error.');
            }
            break;
        }

        // Login endpoint
        $input = getRequestInput();
        $loginIdentifier = sanitize($input['username'] ?? '');
        $password = $input['password'] ?? '';

        if (empty($loginIdentifier) || empty($password)) {
            validationError([
                'username' => empty($loginIdentifier) ? 'Username is required.' : null,
                'password' => empty($password) ? 'Password is required.' : null
            ], 'Username and password are required.');
        }

        // ── Rate limit on login per IP (database-driven) ──
        require_once __DIR__ . '/../middleware/rate_limit.php';
        $loginIp = getClientIp();
        requireRateLimit('login:' . $loginIp, 10, 300); // 10 attempts per 5 minutes per IP
        requireRateLimit('login_user:' . strtolower($loginIdentifier), 5, 300); // 5 attempts per 5 minutes per username/email

        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $loginFingerprint = computeDeviceFingerprint($userAgent);

        try {
            $db = getDB();
            // Ensure enterprise auth columns exist before login writes.
            _ensureStaffColumns($db);
            $stmt = $db->prepare(
                'SELECT * FROM staff WHERE (username = :login_username OR email = :login_email) AND employment_status = :status LIMIT 1'
            );
            $stmt->execute([
                ':login_username' => $loginIdentifier,
                ':login_email' => $loginIdentifier,
                ':status' => 'ACTIVE'
            ]);
            $staff = $stmt->fetch();
            $canonicalUsername = $staff['username'] ?? $loginIdentifier;

            if (!$staff || !password_verify($password, $staff['password_hash'])) {
                // Failed login — log the reason for debugging (without revealing to client)
                if (!$staff) {
                    error_log('[Auth] Login failed for identifier="' . $loginIdentifier . '" — no ACTIVE staff record found');
                } else {
                    error_log('[Auth] Login failed for identifier="' . $loginIdentifier . '" (staff_id=' . $staff['id'] . ') — password mismatch');
                }
                // Failed login
                recordLoginHistory($canonicalUsername, 'FAILURE', $loginIp, $userAgent, 'MEDIUM');

                // Increment failed attempts
                if ($staff) {
                    $updateStmt = $db->prepare(
                        'UPDATE staff SET failed_login_attempts = failed_login_attempts + 1 WHERE id = :id'
                    );
                    $updateStmt->execute([':id' => (int)$staff['id']]);

                    // Check if should lock
                    $checkStmt = $db->prepare(
                        'SELECT failed_login_attempts FROM staff WHERE id = :id'
                    );
                    $checkStmt->execute([':id' => (int)$staff['id']]);
                    $attemptData = $checkStmt->fetch();
                    $maxAttempts = (int)getSetting($db, 'security.max_login_attempts', 5);

                    if ((int)($attemptData['failed_login_attempts'] ?? 0) >= $maxAttempts) {
                        $lockMinutes = (int)getSetting($db, 'security.lockout_duration', 30);
                        $lockUntil = date('Y-m-d H:i:s', time() + ($lockMinutes * 60));
                        $lockStmt = $db->prepare(
                            'UPDATE staff SET account_locked = TRUE, locked_until = :locked_until WHERE id = :id'
                        );
                        $lockStmt->execute([':locked_until' => $lockUntil, ':id' => (int)$staff['id']]);
                        recordLoginHistory($canonicalUsername, 'LOCKED', $loginIp, $userAgent, 'CRITICAL');
                        logAudit($canonicalUsername, 'LOGIN_LOCKED', 'STAFF', (string)$staff['id'], 'FAILURE',
                            'Account locked after ' . $maxAttempts . ' failed attempts', (string)($staff['department'] ?? ''), $loginIp);
                    }
                }

                errorResponse('Invalid username or password.', 401);
            }

            // Check if account is locked
            if ($staff['account_locked']) {
                $lockedUntil = $staff['locked_until'];
                if ($lockedUntil !== null) {
                    $lockedAt = new DateTime($lockedUntil);
                    $now = new DateTime();
                    if ($now < $lockedAt) {
                        recordLoginHistory($canonicalUsername, 'LOCKED', $loginIp, $userAgent, 'HIGH');
                        logAudit($canonicalUsername, 'LOGIN_LOCKED', 'STAFF', (string)$staff['id'], 'DENIED',
                            'Locked account login attempt', $staff['department'], $loginIp);
                        errorResponse('Account is locked. Try again after ' . formatDate($lockedUntil, 'd M Y H:i'), 423);
                    } else {
                        // Unlock
                        $unlockStmt = $db->prepare(
                            'UPDATE staff SET account_locked = FALSE, locked_until = NULL, failed_login_attempts = 0
                             WHERE id = :id'
                        );
                        $unlockStmt->execute([':id' => $staff['id']]);
                    }
                }
            }

            // ── New device / risk assessment ──
            $isNewDevice = !isKnownDevice((int)$staff['id'], $loginFingerprint);
            $loginRisk = $isNewDevice ? 'LOW' : 'NONE';

            // Check for geographic anomalies: different IP than last known login
            if (!empty($staff['last_login_ip']) && $staff['last_login_ip'] !== $loginIp) {
                $loginRisk = 'MEDIUM';
            }

            // Reset failed attempts
            $resetStmt = $db->prepare(
                'UPDATE staff SET failed_login_attempts = 0, last_login = NOW(), last_login_ip = :ip
                 WHERE id = :id'
            );
            $resetStmt->execute([':ip' => $loginIp, ':id' => $staff['id']]);

            // Reset login rate limit on successful authentication
            resetRateLimit('login:' . $loginIp);
            resetRateLimit('login_user:' . strtolower($loginIdentifier));
            resetRateLimit('login_user:' . strtolower((string)$canonicalUsername));

            // ★ SECURITY FIX (CRITICAL): Enforce MFA before session creation.
            // Previously MFA fields existed in the database but were never checked
            // during login — the session was created immediately after password
            // verification, rendering MFA completely decorative. Now, if MFA is
            // enabled for this staff member, we return a pending MFA response
            // requiring the user to submit a valid TOTP code via a separate
            // verification step before any session is created.
            if (!empty($staff['mfa_required']) && !empty($staff['mfa_secret'])) {
                // Generate a temporary MFA-pending token (NOT a session token)
                // This token identifies the pre-authenticated user and expires in 5 minutes
                $mfaPendingToken = bin2hex(random_bytes(32));
                try {
                    $db->prepare(
                        "INSERT INTO mfa_pending_tokens (staff_id, token, ip_address, created_at, expires_at)
                         VALUES (:sid, :token, :ip, NOW(), NOW() + INTERVAL '5 minute')
                         ON CONFLICT (staff_id) DO UPDATE SET token = EXCLUDED.token, ip_address = EXCLUDED.ip_address,
                             created_at = NOW(), expires_at = NOW() + INTERVAL '5 minute'"
                    )->execute([
                        ':sid'   => (int)$staff['id'],
                        ':token' => $mfaPendingToken,
                        ':ip'    => $loginIp
                    ]);
                } catch (PDOException $mfaErr) {
                    // Table might not exist — create it and retry
                    try {
                        $db->exec("CREATE TABLE IF NOT EXISTS mfa_pending_tokens (
                            id SERIAL PRIMARY KEY,
                            staff_id INTEGER NOT NULL,
                            token VARCHAR(128) NOT NULL,
                            ip_address VARCHAR(50) DEFAULT '',
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            expires_at TIMESTAMP NULL,
                            UNIQUE (staff_id)
                        )");
                        $db->prepare(
                            "INSERT INTO mfa_pending_tokens (staff_id, token, ip_address, created_at, expires_at)
                             VALUES (:sid, :token, :ip, NOW(), NOW() + INTERVAL '5 minute')
                             ON CONFLICT (staff_id) DO UPDATE SET token = EXCLUDED.token, ip_address = EXCLUDED.ip_address,
                                 created_at = NOW(), expires_at = NOW() + INTERVAL '5 minute'"
                        )->execute([
                            ':sid'   => (int)$staff['id'],
                            ':token' => $mfaPendingToken,
                            ':ip'    => $loginIp
                        ]);
                    } catch (PDOException $retryErr) {
                        error_log('[Auth] MFA pending token storage failed: ' . $retryErr->getMessage());
                    }
                }

                recordLoginHistory($canonicalUsername, 'MFA_REQUIRED', $loginIp, $userAgent, 'LOW');
                logAudit($staff['full_name'], 'LOGIN_MFA_PENDING', 'STAFF', (string)$staff['id'], 'SUCCESS',
                    'Password verified. MFA code required before session creation.', $staff['department'], $loginIp);

                successResponse([
                    'mfa_required'    => true,
                    'mfa_pending_token' => $mfaPendingToken,
                    'staff_id'        => (int)$staff['id'],
                    'username'        => $staff['username'],
                    'full_name'       => $staff['full_name'],
                    'message'         => 'Password verified. Please enter your MFA code to complete login.'
                ], 'MFA verification required. A 6-digit code from your authenticator app.');
                break; // Exit the POST case — do NOT create a session yet
            }

            // Read session timeout from settings (in minutes), fallback to PHP constant or 480
            $sessionTimeoutMinutes = (int)getSetting($db, 'security.session_timeout', 480);

            // Create session
            $sessionToken = createSession(
                (int)$staff['id'],
                getClientIp(),
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            );
            if (empty($sessionToken)) {
                serverErrorResponse('Failed to create session. Please contact administrator.');
            }

            $sessionVerifyStmt = $db->prepare('SELECT id FROM sessions WHERE id = :id LIMIT 1');
            $sessionVerifyStmt->execute([':id' => $sessionToken]);
            if (!$sessionVerifyStmt->fetchColumn()) {
                if (defined('APP_DEBUG') && APP_DEBUG) {
                    error_log('[AUTH LOGIN VERIFY MISS] staff_id=' . (int)$staff['id'] . ' token=' . substr($sessionToken, 0, 12) . '... attempting repair insert');
                }
                try {
                    _ensureSessionColumns($db);
                    $repairStmt = $db->prepare(
                        'INSERT INTO sessions (id, staff_id, ip_address, user_agent, expires_at, created_at) VALUES (:id, :staff_id, :ip, :user_agent, :expires_at, NOW())'
                    );
                    $repairStmt->execute([
                        ':id' => $sessionToken,
                        ':staff_id' => (int)$staff['id'],
                        ':ip' => $loginIp,
                        ':user_agent' => $userAgent,
                        ':expires_at' => date('Y-m-d H:i:s', time() + ($sessionTimeoutMinutes * 60))
                    ]);
                } catch (PDOException $repairErr) {
                    if (defined('APP_DEBUG') && APP_DEBUG) {
                        error_log('[AUTH LOGIN REPAIR FAILED] staff_id=' . (int)$staff['id'] . ' token=' . substr($sessionToken, 0, 12) . '... error=' . $repairErr->getMessage());
                    }
                }
                $sessionVerifyStmt->execute([':id' => $sessionToken]);
                if (!$sessionVerifyStmt->fetchColumn()) {
                    serverErrorResponse('Failed to persist session. Please contact administrator.');
                }
            }
            if (defined('APP_DEBUG') && APP_DEBUG) {
                error_log('[AUTH LOGIN ISSUED] staff_id=' . (int)$staff['id'] . ' token=' . substr($sessionToken, 0, 12) . '...');
            }

            // ★ SECURITY FIX: Set session token in a secure, HttpOnly cookie
            $cookieExpire = time() + ($sessionTimeoutMinutes * 60);
            setcookie('X-Atlas-Session', $sessionToken, [
                'expires' => $cookieExpire,
                'path' => '/',
                'domain' => '',
                'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'httponly' => true,
                'samesite' => 'Strict'
            ]);

            // ★ FIX (FIN-2b-024): Removed redundant session timeout override.
            // createSession() in helpers.php already reads security.session_timeout from DB.
            // The previous double-write caused potential inconsistency if settings changed
            // between the two reads. createSession() is now the single source of truth.

            // Record successful login (with risk level and device fingerprint)
            recordLoginHistory($canonicalUsername, 'SUCCESS', $loginIp, $userAgent, $loginRisk);

            // Log audit (with device info)
            $deviceLabel = computeDeviceLabel($userAgent);
            $auditDetail = 'Successful login from ' . $deviceLabel;
            if ($isNewDevice) {
                $auditDetail .= ' (NEW DEVICE DETECTED)';
            }
            if ($loginRisk === 'MEDIUM') {
                $auditDetail .= ' (IP CHANGE: ' . ($staff['last_login_ip'] ?? 'none') . ' -> ' . $loginIp . ')';
            }
            logAudit($staff['full_name'], 'LOGIN', 'STAFF', (string)$staff['id'], 'SUCCESS', $auditDetail, $staff['department'], $loginIp);

            // Send notification for new device login
            if ($isNewDevice) {
                addNotification(
                    'SECURITY',
                    'New Device Login',
                    'Your account was accessed from a new device: ' . $deviceLabel . ' (IP: ' . $loginIp . '). If this was not you, please change your password immediately and contact the administrator.',
                    (int)$staff['id'],
                    'IN_APP'
                );
            }

            // Get staff modules with access levels
            try {
                $modStmt = $db->prepare("SELECT module_name, COALESCE(access_level, 'FULL') AS access_level FROM staff_modules WHERE staff_id = :staff_id");
                $modStmt->execute([':staff_id' => $staff['id']]);
                $modRows = $modStmt->fetchAll(PDO::FETCH_ASSOC);
                $modules = array_map(function($r) {
                    return ['name' => $r['module_name'], 'access' => $r['access_level']];
                }, $modRows);
            } catch (PDOException $e) {
                // Fallback: access_level column doesn't exist yet
                $modStmt = $db->prepare('SELECT module_name FROM staff_modules WHERE staff_id = :staff_id');
                $modStmt->execute([':staff_id' => $staff['id']]);
                $plainMods = $modStmt->fetchAll(PDO::FETCH_COLUMN, 0);
                $modules = array_map(function($m) {
                    return ['name' => $m, 'access' => 'FULL'];
                }, $plainMods);
            }

            // Get staff branches
            $brStmt = $db->prepare('SELECT branch_name FROM staff_branches WHERE staff_id = :staff_id');
            $brStmt->execute([':staff_id' => $staff['id']]);
            $branches = $brStmt->fetchAll(PDO::FETCH_COLUMN, 0);

            // Generate CSRF token for the session.
            // session_name is already set by router.php bootstrap.
            // ensureCsrfSession() inside generateCsrfToken() handles session start.
            $csrfToken = generateCsrfToken();

            successResponse([
                'token'                    => $sessionToken,
                'staff_id'                 => (int)$staff['id'],
                'username'                 => $staff['username'],
                'full_name'                => $staff['full_name'],
                'initials'                 => $staff['initials'],
                'email'                    => $staff['email'],
                'phone'                    => $staff['phone'],
                'position'                 => $staff['position'],
                'role'                     => $staff['role'],
                'department'               => $staff['department'],
                'branches'                 => $branches,
                'modules'                  => $modules,
                'approval_limit'           => (float)$staff['approval_limit'],
                'profile_picture'          => $staff['profile_picture'] ?? null,
                'csrf_token'               => $csrfToken,
                'client_ip'                => $loginIp,
                'session_timeout'           => $sessionTimeoutMinutes,
                'requires_password_change' => !empty($staff['force_password_change']),
                'new_device'               => $isNewDevice,
                'login_risk'               => $loginRisk,
                'device_label'             => $deviceLabel
            ], 'Login successful.');

        } catch (Throwable $e) {
            // ★ FIX: Catch Throwable instead of only PDOException.
            // Previously, non-PDO exceptions (TypeError, ErrorException, etc.) would
            // propagate to the router's catch block, returning a generic 500 with no
            // diagnostic information. Now we catch everything and log the details.
            error_log('[AUTH LOGIN ERROR] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            error_log('[AUTH LOGIN ERROR] Trace: ' . $e->getTraceAsString());
            $msg = 'Authentication error occurred.';
            if (defined('APP_DEBUG') && APP_DEBUG) {
                $msg .= ' ' . get_class($e) . ': ' . $e->getMessage();
            }
            serverErrorResponse($msg);
        }
        break;

    case 'DELETE':
        require_once __DIR__ . '/../middleware/auth.php';
        $staff = requireAuth();

        // ── DELETE /auth/sessions — Terminate all other sessions ──
        if ($routeId === 'sessions') {
            try {
                $db = getDB();
                // Delete all sessions except the current one
                $stmt = $db->prepare('DELETE FROM sessions WHERE staff_id = :sid AND id != :current');
                $stmt->execute([':sid' => $staff['staff_id'], ':current' => $staff['session_token']]);
                $terminated = $stmt->rowCount();
                logAudit($staff['full_name'], 'SESSIONS_TERMINATED', 'STAFF', (string)$staff['staff_id'], 'SUCCESS',
                    'Terminated ' . $terminated . ' other session(s)', $staff['department'], getClientIp());
                successMessage($terminated . ' other session(s) terminated successfully.');
            } catch (PDOException $e) { serverErrorResponse('Failed to terminate sessions.'); }
            break;
        }

        // Logout endpoint (DELETE /auth)
        $destroyed = destroySession($staff['session_token']);
        if ($destroyed) {
            // ★ SECURITY FIX: Clear the session cookie
            setcookie('X-Atlas-Session', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'domain' => '',
                'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
            logAudit($staff['full_name'], 'LOGOUT', 'STAFF', (string)$staff['id'], 'SUCCESS', 'User logged out', $staff['department'], getClientIp());
            successMessage('Logged out successfully.');
        } else {
            errorResponse('Failed to destroy session.');
        }
        break;

    default:
        errorResponse('Method not allowed for auth endpoint. Use POST for login, DELETE for logout.', 405);
}

} catch (Throwable $e) {
    // ★ Top-level catch: any exception that escapes the switch statement above.
    // This prevents the router from catching it and returning a generic 500
    // without debug info (which was the root cause of the invisible auth errors).
    error_log('[AUTH TOP-LEVEL ERROR] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    error_log('[AUTH TOP-LEVEL ERROR] Trace: ' . $e->getTraceAsString());
    $isDebug = defined('APP_DEBUG') && APP_DEBUG;
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => $isDebug ? ('Auth error: ' . get_class($e)) : 'Internal server error.',
        'message' => $isDebug ? $e->getMessage() : 'An unexpected error occurred.',
        'file'    => $isDebug ? basename($e->getFile()) : null,
        'line'    => $isDebug ? $e->getLine() : null,
        'trace'   => $isDebug ? $e->getTraceAsString() : null,
    ]);
    exit;
}
