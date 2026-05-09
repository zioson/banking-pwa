<?php
/**
 * Atlas Bank — Deep Auth Flow Diagnostic
 * DELETE THIS FILE after debugging!
 * 
 * This tool tests each step of the authentication flow individually
 * to identify exactly where the 500 error occurs.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

header('Content-Type: application/json; charset=utf-8');

$result = [
    'timestamp' => date('c'),
    'php_version' => PHP_VERSION,
    'steps' => [],
];

function step(string $name, callable $fn): array {
    try {
        $data = $fn();
        return ['step' => $name, 'status' => 'OK', 'data' => $data];
    } catch (Throwable $e) {
        return [
            'step' => $name, 
            'status' => 'ERROR', 
            'error' => get_class($e) . ': ' . $e->getMessage(),
            'file' => basename($e->getFile()) . ':' . $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 8),
        ];
    }
}

// ── STEP 1: Load core files ──
$result['steps'][] = step('load_constants', function() {
    require_once __DIR__ . '/config/constants.php';
    return 'constants loaded';
});

$result['steps'][] = step('load_database', function() {
    require_once __DIR__ . '/config/database.php';
    return 'database.php loaded, APP_DEBUG=' . var_export(APP_DEBUG, true);
});

$result['steps'][] = step('load_helpers', function() {
    require_once __DIR__ . '/includes/helpers.php';
    return 'helpers loaded';
});

$result['steps'][] = step('load_response', function() {
    require_once __DIR__ . '/includes/Response.php';
    return 'Response loaded';
});

$result['steps'][] = step('load_cors', function() {
    require_once __DIR__ . '/middleware/cors.php';
    return 'cors loaded';
});

$result['steps'][] = step('load_csrf', function() {
    require_once __DIR__ . '/middleware/csrf.php';
    return 'csrf loaded';
});

// ── STEP 2: Test PHP session ──
$result['steps'][] = step('session_test', function() {
    // Test if session can be started
    $sessionDir = session_save_path();
    $canWrite = is_writable($sessionDir ?: sys_get_temp_dir());
    
    if (defined('SESSION_NAME')) {
        session_name(SESSION_NAME);
    }
    $started = session_start();
    $sessionId = session_id();
    
    return [
        'session_save_path' => $sessionDir ?: '(empty, using default)',
        'temp_dir_writable' => $canWrite,
        'session_started' => $started,
        'session_id' => substr($sessionId, 0, 8) . '...',
        'session_name' => session_name(),
    ];
});

// ── STEP 3: Test database connection ──
$db = null;
$result['steps'][] = step('db_connection', function() use (&$db) {
    $db = getDB();
    $test = $db->query('SELECT 1')->fetchColumn();
    
    // Check search_path
    $spResult = $db->query('SHOW search_path')->fetchColumn();
    $currentSchema = $db->query('SELECT current_schema()')->fetchColumn();
    
    return [
        'connected' => true,
        'search_path' => $spResult,
        'current_schema' => $currentSchema,
    ];
});

if ($db === null) {
    $result['fatal'] = 'Database connection failed — cannot continue diagnostics';
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── STEP 4: Test staff table query (SELECT * — same as auth.php) ──
$result['steps'][] = step('staff_select_star', function() use ($db) {
    $stmt = $db->prepare(
        'SELECT * FROM staff WHERE (username = :login_username OR email = :login_email) AND employment_status = :status LIMIT 1'
    );
    $stmt->execute([':login_username' => 'admin', ':login_email' => 'admin', ':status' => 'ACTIVE']);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$staff) {
        return 'NO admin staff found with ACTIVE status';
    }
    
    // Check critical columns
    return [
        'id' => $staff['id'],
        'username' => $staff['username'],
        'has_password_hash' => isset($staff['password_hash']),
        'password_hash_prefix' => isset($staff['password_hash']) ? substr($staff['password_hash'], 0, 7) . '...' : 'MISSING',
        'role' => $staff['role'] ?? 'MISSING',
        'employment_status' => $staff['employment_status'] ?? 'MISSING',
        'account_locked' => $staff['account_locked'] ?? 'MISSING',
        'mfa_required' => $staff['mfa_required'] ?? 'MISSING',
        'mfa_secret' => isset($staff['mfa_secret']) ? (!empty($staff['mfa_secret']) ? 'SET' : 'EMPTY') : 'MISSING',
        'force_password_change' => $staff['force_password_change'] ?? 'MISSING',
        'all_columns' => array_keys($staff),
    ];
});

// ── STEP 5: Test password verification ──
$result['steps'][] = step('password_verify', function() use ($db) {
    $stmt = $db->prepare('SELECT password_hash FROM staff WHERE username = :u AND employment_status = :s LIMIT 1');
    $stmt->execute([':u' => 'admin', ':s' => 'ACTIVE']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) return 'No admin user found';
    
    $hash = $row['password_hash'];
    $hashInfo = substr($hash, 0, 4); // Should be $2y$ for bcrypt
    
    return [
        'hash_algorithm' => $hashInfo,
        'hash_length' => strlen($hash),
        'is_bcrypt' => ($hashInfo === '$2y$' || $hashInfo === '$2a$' || $hashInfo === '$2b$'),
    ];
});

// ── STEP 6: Test getSetting function ──
$result['steps'][] = step('getSetting_test', function() use ($db) {
    $maxAttempts = getSetting($db, 'security.max_login_attempts', 5);
    $lockoutDuration = getSetting($db, 'security.lockout_duration', 30);
    $sessionTimeout = getSetting($db, 'security.session_timeout', 15);
    
    return [
        'security.max_login_attempts' => $maxAttempts,
        'security.lockout_duration' => $lockoutDuration,
        'security.session_timeout' => $sessionTimeout,
    ];
});

// ── STEP 7: Test _ensureStaffColumns ──
$result['steps'][] = step('ensureStaffColumns', function() use ($db) {
    _ensureStaffColumns($db);
    return 'OK — no exception';
});

// ── STEP 8: Test _ensureSessionColumns ──
$result['steps'][] = step('ensureSessionColumns', function() use ($db) {
    _ensureSessionColumns($db);
    return 'OK — no exception';
});

// ── STEP 9: Test rate_limits table creation ──
$result['steps'][] = step('rate_limits_table', function() use ($db) {
    ensureRateLimitsTable();
    $count = $db->query('SELECT COUNT(*) FROM rate_limits')->fetchColumn();
    return 'rate_limits table exists, rows=' . $count;
});

// ── STEP 10: Test createSession function ──
$result['steps'][] = step('createSession_test', function() use ($db) {
    // Test if createSession works (will create a real session)
    $testToken = createSession(1, '127.0.0.1', 'DiagnosticTool/1.0');
    
    if (empty($testToken)) {
        return 'FAIL — createSession returned empty token';
    }
    
    // Verify the session was actually created
    $verifyStmt = $db->prepare('SELECT id FROM sessions WHERE id = :id LIMIT 1');
    $verifyStmt->execute([':id' => $testToken]);
    $found = $verifyStmt->fetchColumn();
    
    // Clean up the test session
    $db->prepare('DELETE FROM sessions WHERE id = :id')->execute([':id' => $testToken]);
    
    return [
        'token_created' => !empty($testToken),
        'token_length' => strlen($testToken),
        'session_found_in_db' => !empty($found),
    ];
});

// ── STEP 11: Test login_history table ──
$result['steps'][] = step('login_history_test', function() use ($db) {
    _ensureLoginHistoryColumns($db);
    $count = $db->query('SELECT COUNT(*) FROM login_history')->fetchColumn();
    return 'login_history table OK, rows=' . $count;
});

// ── STEP 12: Test notifications table ──
$result['steps'][] = step('notifications_test', function() use ($db) {
    try {
        $count = $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
        return 'notifications table OK, rows=' . $count;
    } catch (PDOException $e) {
        return 'notifications table MISSING: ' . $e->getMessage();
    }
});

// ── STEP 13: Test staff_modules table ──
$result['steps'][] = step('staff_modules_test', function() use ($db) {
    try {
        $stmt = $db->prepare('SELECT module_name, COALESCE(access_level, \'FULL\') AS access_level FROM staff_modules WHERE staff_id = :staff_id');
        $stmt->execute([':staff_id' => 1]);
        $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['modules_found' => count($modules), 'modules' => $modules];
    } catch (PDOException $e) {
        // Try without access_level column
        try {
            $stmt = $db->prepare('SELECT module_name FROM staff_modules WHERE staff_id = :staff_id');
            $stmt->execute([':staff_id' => 1]);
            $modules = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            return ['modules_found' => count($modules), 'modules' => $modules, 'note' => 'access_level column missing, using fallback'];
        } catch (PDOException $e2) {
            return 'staff_modules table ERROR: ' . $e2->getMessage();
        }
    }
});

// ── STEP 14: Test staff_branches table ──
$result['steps'][] = step('staff_branches_test', function() use ($db) {
    try {
        $stmt = $db->prepare('SELECT branch_name FROM staff_branches WHERE staff_id = :staff_id');
        $stmt->execute([':staff_id' => 1]);
        $branches = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        return ['branches_found' => count($branches), 'branches' => $branches];
    } catch (PDOException $e) {
        return 'staff_branches table ERROR: ' . $e->getMessage();
    }
});

// ── STEP 15: Test computeDeviceFingerprint function ──
$result['steps'][] = step('device_fingerprint', function() {
    $fp = computeDeviceFingerprint('Mozilla/5.0 DiagnosticTool');
    $label = computeDeviceLabel('Mozilla/5.0 DiagnosticTool');
    return ['fingerprint' => substr($fp, 0, 16) . '...', 'label' => $label];
});

// ── STEP 16: Test isKnownDevice function ──
$result['steps'][] = step('isKnownDevice_test', function() {
    $known = isKnownDevice(1, 'dummy-fingerprint-for-test');
    return ['result' => $known, 'note' => 'true means known device or DB error (fail-safe)'];
});

// ── STEP 17: Test the auth endpoint via HTTP (simulating the real request) ──
$result['steps'][] = step('auth_http_test', function() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $port = $_SERVER['SERVER_PORT'] ?? 80;
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    $baseUrl = $protocol . '://' . $host . ($port == 80 || $port == 443 ? '' : ':' . $port) . $scriptDir;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $baseUrl . '/api/auth',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['username' => 'admin', 'password' => 'wrongpassword']),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $decoded = json_decode($response, true);
    
    return [
        'http_code' => $httpCode,
        'curl_error' => $curlError ?: null,
        'response_success' => $decoded['success'] ?? null,
        'response_error' => $decoded['error'] ?? null,
        'response_message' => $decoded['message'] ?? null,
        'response_file' => $decoded['file'] ?? null,
        'response_line' => $decoded['line'] ?? null,
        'response_trace' => $decoded['trace'] ?? null,
        'full_response' => substr($response, 0, 500),
    ];
});

// ── STEP 18: Try enabling APP_DEBUG temporarily and calling the endpoint ──
$result['steps'][] = step('auth_debug_test', function() use ($db) {
    // Directly simulate the auth.php login flow with error visibility
    $loginIdentifier = 'admin';
    $password = 'wrongpassword';
    
    // This is what auth.php does:
    $db = getDB();
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
    
    if (!$staff) {
        return 'No staff found — would return 401';
    }
    
    // Test password_verify with wrong password (should return false, not throw)
    $pwResult = password_verify($password, $staff['password_hash']);
    
    // Test account_locked access
    $locked = $staff['account_locked'] ?? false;
    
    // Test accessing all the fields that auth.php accesses
    $fieldAccess = [];
    $requiredFields = [
        'id', 'username', 'full_name', 'email', 'role', 'department',
        'password_hash', 'employment_status', 'account_locked', 'locked_until',
        'mfa_required', 'mfa_secret', 'failed_login_attempts', 'last_login_ip',
        'force_password_change', 'initials', 'position', 'phone',
        'approval_limit', 'profile_picture',
    ];
    foreach ($requiredFields as $field) {
        $fieldAccess[$field] = array_key_exists($field, $staff) ? 'OK' : 'MISSING';
    }
    
    return [
        'staff_found' => true,
        'password_verify_result' => $pwResult,
        'account_locked' => $locked,
        'field_access' => $fieldAccess,
    ];
});

// ── STEP 19: Check if there's a table missing that auth.php needs ──
$result['steps'][] = step('table_existence_check', function() use ($db) {
    $requiredTables = [
        'staff', 'sessions', 'settings', 'rate_limits', 'login_history',
        'notifications', 'staff_modules', 'staff_branches', 'audit_logs',
        'mfa_pending_tokens',
    ];
    $tableStatus = [];
    foreach ($requiredTables as $table) {
        try {
            $count = $db->query('SELECT COUNT(*) FROM ' . $db->quote($table))->fetchColumn();
            // $db->quote adds quotes around the table name which won't work for SELECT
            // Let's try a different approach
            $tableStatus[$table] = 'EXISTS (using direct query)';
        } catch (PDOException $e) {
            // Try with information_schema
            $check = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = :tbl");
            $check->execute([':tbl' => $table]);
            $tableStatus[$table] = $check->fetchColumn() ? 'EXISTS' : 'MISSING';
        }
    }
    
    // Fix the count queries for tables that exist
    foreach ($requiredTables as $table) {
        if ($tableStatus[$table] === 'EXISTS' || $tableStatus[$table] === 'EXISTS (using direct query)') {
            try {
                $count = $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
                $tableStatus[$table] = 'EXISTS (' . $count . ' rows)';
            } catch (PDOException $e) {
                $tableStatus[$table] = 'EXISTS but ERROR: ' . $e->getMessage();
            }
        }
    }
    
    return $tableStatus;
});

// ── STEP 20: Test settings table data ──
$result['steps'][] = step('settings_data_check', function() use ($db) {
    try {
        $stmt = $db->query('SELECT "key", value_data FROM settings WHERE "key" LIKE \'security.%\' ORDER BY "key"');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['security_settings' => $rows];
    } catch (PDOException $e) {
        return 'ERROR: ' . $e->getMessage();
    }
});

// ── SUMMARY ──
$errorSteps = array_filter($result['steps'], fn($s) => $s['status'] === 'ERROR');
$result['summary'] = [
    'total_steps' => count($result['steps']),
    'errors' => count($errorSteps),
    'error_details' => empty($errorSteps) ? 'All steps passed!' : array_map(fn($s) => $s['step'] . ': ' . ($s['error'] ?? 'unknown'), $errorSteps),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
