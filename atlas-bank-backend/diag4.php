<?php
/**
 * Atlas Bank — Auth 500 Error Diagnostic
 * DELETE THIS FILE after debugging!
 * 
 * This diagnostic simulates the EXACT auth.php POST login flow
 * step by step to identify the exact point where the 500 error occurs.
 * It also tests the auth endpoint directly with correct credentials.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Don't display errors — capture them in JSON
ini_set('display_startup_errors', '0');

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
            'step'    => $name,
            'status'  => 'ERROR',
            'error'   => get_class($e) . ': ' . $e->getMessage(),
            'file'    => basename($e->getFile()) . ':' . $e->getLine(),
            'trace'   => array_slice(explode("\n", $e->getTraceAsString()), 0, 5),
        ];
    }
}

// ── STEP 1: Bootstrap (same as router.php) ──
$result['steps'][] = step('bootstrap', function() {
    require_once __DIR__ . '/config/constants.php';
    require_once __DIR__ . '/config/database.php';
    return 'APP_DEBUG=' . var_export(APP_DEBUG, true);
});

// ── STEP 2: Test DB connection ──
$db = null;
$result['steps'][] = step('db_connect', function() use (&$db) {
    $db = getDB();
    $v = $db->query('SELECT version()')->fetchColumn();
    $schema = $db->query('SELECT current_schema()')->fetchColumn();
    return ['version' => substr($v, 0, 50), 'schema' => $schema];
});

if ($db === null) {
    $result['fatal'] = 'DB connection failed';
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

// ── STEP 3: Self-heal mfa_pending_tokens ──
$result['steps'][] = step('ensure_mfa_table', function() use ($db) {
    _ensureMfaPendingTokensTable($db);
    $exists = $db->query(
        "SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'mfa_pending_tokens'"
    )->fetchColumn();
    return ['table_exists' => (bool)$exists];
});

// ── STEP 4: Self-heal staff columns ──
$result['steps'][] = step('ensure_staff_columns', function() use ($db) {
    _ensureStaffColumns($db);
    return 'OK';
});

// ── STEP 5: Self-heal session columns ──
$result['steps'][] = step('ensure_session_columns', function() use ($db) {
    _ensureSessionColumns($db);
    return 'OK';
});

// ── STEP 6: Load rate_limit middleware ──
$result['steps'][] = step('load_rate_limit', function() {
    require_once __DIR__ . '/middleware/rate_limit.php';
    ensureRateLimitsTable();
    return 'OK';
});

// ── STEP 7: Query staff (same as auth.php) ──
$staff = null;
$result['steps'][] = step('query_staff', function() use ($db, &$staff) {
    $stmt = $db->prepare(
        'SELECT * FROM staff WHERE (username = :u OR email = :e) AND employment_status = :s LIMIT 1'
    );
    $stmt->execute([':u' => 'admin', ':e' => 'admin', ':s' => 'ACTIVE']);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$staff) return 'NO admin staff found';
    return [
        'id' => $staff['id'],
        'username' => $staff['username'],
        'role' => $staff['role'],
        'employment_status' => $staff['employment_status'],
        'account_locked' => $staff['account_locked'] ?? null,
        'mfa_required' => $staff['mfa_required'] ?? null,
        'mfa_secret_empty' => empty($staff['mfa_secret']),
        'password_hash_prefix' => substr($staff['password_hash'] ?? '', 0, 10) . '...',
        'all_columns' => array_keys($staff),
    ];
});

// ── STEP 8: Test password_verify with KNOWN passwords ──
$result['steps'][] = step('password_test', function() use ($db, &$staff) {
    if (!$staff) return 'No staff to test';
    
    $hash = $staff['password_hash'] ?? '';
    $results = [];
    
    // Test common passwords
    $testPasswords = ['admin123', 'password', 'Admin123', 'admin', 'AtlasBank2024'];
    foreach ($testPasswords as $pw) {
        $results[$pw] = password_verify($pw, $hash);
    }
    
    return [
        'hash_algorithm' => substr($hash, 0, 4),
        'hash_length' => strlen($hash),
        'test_results' => $results,
        'any_match' => in_array(true, $results, true),
    ];
});

// ── STEP 9: Test device fingerprint ──
$result['steps'][] = step('device_fingerprint', function() {
    $fp = computeDeviceFingerprint('DiagnosticTool/1.0');
    $label = computeDeviceLabel('DiagnosticTool/1.0');
    return ['fingerprint' => substr($fp, 0, 16) . '...', 'label' => $label];
});

// ── STEP 10: Test isKnownDevice ──
$result['steps'][] = step('is_known_device', function() use ($staff) {
    if (!$staff) return 'No staff';
    $known = isKnownDevice((int)$staff['id'], 'dummy-fingerprint');
    return ['result' => $known];
});

// ── STEP 11: Test recordLoginHistory ──
$result['steps'][] = step('record_login_history', function() use ($staff) {
    if (!$staff) return 'No staff';
    $ok = recordLoginHistory($staff['username'], 'DIAG_TEST', '127.0.0.1', 'DiagnosticTool', 'NONE');
    return ['success' => $ok];
});

// ── STEP 12: Test createSession ──
$result['steps'][] = step('create_session', function() use ($db, $staff) {
    if (!$staff) return 'No staff';
    $token = createSession((int)$staff['id'], '127.0.0.1', 'DiagnosticTool/1.0');
    
    if (empty($token)) return ['error' => 'createSession returned empty token'];
    
    // Verify session was created
    $verifyStmt = $db->prepare('SELECT id FROM sessions WHERE id = :id LIMIT 1');
    $verifyStmt->execute([':id' => $token]);
    $found = (bool)$verifyStmt->fetchColumn();
    
    // Clean up
    $db->prepare('DELETE FROM sessions WHERE id = :id')->execute([':id' => $token]);
    
    return ['token_created' => true, 'session_found' => $found, 'token_length' => strlen($token)];
});

// ── STEP 13: Test getSetting ──
$result['steps'][] = step('get_setting', function() use ($db) {
    $v1 = getSetting($db, 'security.max_login_attempts', 5);
    $v2 = getSetting($db, 'security.lockout_duration', 30);
    $v3 = getSetting($db, 'security.session_timeout', 480);
    return ['max_login_attempts' => $v1, 'lockout_duration' => $v2, 'session_timeout' => $v3];
});

// ── STEP 14: Test staff_modules query ──
$result['steps'][] = step('staff_modules', function() use ($db, $staff) {
    if (!$staff) return 'No staff';
    try {
        $stmt = $db->prepare("SELECT module_name, COALESCE(access_level, 'FULL') AS access_level FROM staff_modules WHERE staff_id = :sid");
        $stmt->execute([':sid' => $staff['id']]);
        $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['count' => count($modules), 'first_3' => array_slice($modules, 0, 3)];
    } catch (PDOException $e) {
        return ['error' => $e->getMessage()];
    }
});

// ── STEP 15: Test staff_branches query ──
$result['steps'][] = step('staff_branches', function() use ($db, $staff) {
    if (!$staff) return 'No staff';
    try {
        $stmt = $db->prepare('SELECT branch_name FROM staff_branches WHERE staff_id = :sid');
        $stmt->execute([':sid' => $staff['id']]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (PDOException $e) {
        return ['error' => $e->getMessage()];
    }
});

// ── STEP 16: Test HTTP auth endpoint with CORRECT password ──
$result['steps'][] = step('auth_http_correct_pw', function() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    $baseUrl = $protocol . '://' . $host . $scriptDir;
    
    // Test with admin123 (the expected password per QUICK_FIX_PASSWORDS.sql)
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $baseUrl . '/api/auth',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['username' => 'admin', 'password' => 'admin123']),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true, // Follow redirects
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
        'debug_info' => $decoded['debug'] ?? null,
        'has_token' => isset($decoded['data']['token']) || isset($decoded['token']),
    ];
});

// ── STEP 17: Test HTTP auth endpoint with "password" ──
$result['steps'][] = step('auth_http_password_pw', function() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    $baseUrl = $protocol . '://' . $host . $scriptDir;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $baseUrl . '/api/auth',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['username' => 'admin', 'password' => 'password']),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
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
        'has_token' => isset($decoded['data']['token']) || isset($decoded['token']),
    ];
});

// ── SUMMARY ──
$errorSteps = array_filter($result['steps'], fn($s) => $s['status'] === 'ERROR');
$result['summary'] = [
    'total_steps' => count($result['steps']),
    'errors' => count($errorSteps),
    'error_details' => empty($errorSteps) ? 'All steps passed!' : array_map(fn($s) => $s['step'] . ': ' . ($s['error'] ?? 'unknown'), $errorSteps),
    'recommendation' => '',
];

// Add recommendation based on results
if (count($errorSteps) > 0) {
    $result['summary']['recommendation'] = 'Fix the ERROR steps above. The 500 error is caused by one of these failures.';
} else {
    // Check if any password matched
    $pwStep = array_filter($result['steps'], fn($s) => $s['step'] === 'password_test');
    $pwData = !empty($pwStep) ? reset($pwStep)['data'] ?? null : null;
    if ($pwData && !($pwData['any_match'] ?? false)) {
        $result['summary']['recommendation'] = 'CRITICAL: No password matches the stored hash! The admin password needs to be reset. Run: UPDATE staff SET password_hash = \'$2y$10$L42hjRVJzJxpSWCGrdvPSO1rX68URpzisYyCPvgn5S8AT0WKYNulS\' WHERE username = \'admin\'; to set password to "admin123".';
    } else {
        $result['summary']['recommendation'] = 'All diagnostic steps passed. The auth endpoint should work. Check the HTTP test results for the actual endpoint response.';
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
