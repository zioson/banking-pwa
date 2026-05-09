<?php
/**
 * Runtime diagnostic — tests the actual auth flow to find runtime errors.
 * DELETE THIS FILE after debugging!
 */
header('Content-Type: application/json; charset=utf-8');

$result = [
    'timestamp' => date('c'),
    'php_version' => PHP_VERSION,
];

// ── Step 1: Load core files ──
$coreFiles = [
    __DIR__ . '/config/constants.php',
    __DIR__ . '/config/database.php',
    __DIR__ . '/config/cors.php',
    __DIR__ . '/includes/Response.php',
    __DIR__ . '/includes/helpers.php',
    __DIR__ . '/includes/Auth.php',
    __DIR__ . '/includes/Middleware.php',
    __DIR__ . '/includes/AuditLogger.php',
    __DIR__ . '/middleware/cors.php',
    __DIR__ . '/middleware/auth.php',
];

$result['core_includes'] = [];
foreach ($coreFiles as $file) {
    try {
        require_once $file;
        $result['core_includes'][basename($file)] = 'OK';
    } catch (Throwable $e) {
        $result['core_includes'][basename($file)] = [
            'error' => get_class($e) . ': ' . $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ];
    }
}

// ── Step 2: Test database connection and query ──
try {
    $db = getDB();
    $result['db_connection'] = 'OK';
    
    // Test basic query
    $testQuery = $db->query('SELECT COUNT(*) FROM staff');
    $result['staff_count'] = (int)$testQuery->fetchColumn();
    
    // Check staff table columns
    $colQuery = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'staff' ORDER BY ordinal_position");
    $result['staff_columns'] = $colQuery->fetchAll(PDO::FETCH_COLUMN, 0);
    
    // Check sessions table columns
    $colQuery2 = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'sessions' ORDER BY ordinal_position");
    $result['sessions_columns'] = $colQuery2->fetchAll(PDO::FETCH_COLUMN, 0);
    
    // Check settings table columns
    $colQuery3 = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'settings' ORDER BY ordinal_position");
    $result['settings_columns'] = $colQuery3->fetchAll(PDO::FETCH_COLUMN, 0);
    
    // Test fetching a staff member (like auth does)
    try {
        $staffStmt = $db->query("SELECT id, username, email, role, employment_status FROM staff LIMIT 3");
        $result['sample_staff'] = $staffStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $result['sample_staff_error'] = $e->getMessage();
    }
    
} catch (Throwable $e) {
    $result['db_connection'] = [
        'error' => get_class($e) . ': ' . $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
    ];
}

// ── Step 3: Test the auth endpoint flow directly ──
try {
    // Simulate what router.php does for POST /api/auth
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_ROUTE = ['method' => 'POST', 'id' => null, 'subResource' => null];
    
    // Capture the auth endpoint output
    ob_start();
    
    // Set up the minimal environment for auth.php
    $method = 'POST';
    $id = null;
    $sub = null;
    
    // Try to load auth.php in isolation to see what error occurs
    // We need to be careful - auth.php expects $_ROUTE and other globals
    
    // Instead, let's test the key operations auth.php performs:
    
    // 3a. Test password_hash / password_verify
    $testHash = password_hash('test123', PASSWORD_DEFAULT);
    $result['password_hash_test'] = password_verify('test123', $testHash) ? 'OK' : 'FAIL';
    
    // 3b. Test the actual staff login query
    if (isset($db)) {
        try {
            $loginStmt = $db->prepare('SELECT id, username, email, password_hash, role, employment_status FROM staff WHERE (username = :login1 OR email = :login2) AND employment_status = :status LIMIT 1');
            $loginStmt->execute([':login1' => 'admin', ':login2' => 'admin', ':status' => 'ACTIVE']);
            $staffRow = $loginStmt->fetch(PDO::FETCH_ASSOC);
            if ($staffRow) {
                $result['login_query_test'] = 'FOUND staff member';
                $result['login_staff_id'] = $staffRow['id'];
                $result['login_staff_role'] = $staffRow['role'];
                $result['login_has_password_hash'] = !empty($staffRow['password_hash']);
                $result['login_password_hash_starts'] = substr($staffRow['password_hash'], 0, 10) . '...';
            } else {
                $result['login_query_test'] = 'NO staff found with username=admin, status=ACTIVE';
                
                // Try without status filter
                $anyStmt = $db->prepare('SELECT id, username, email, role, employment_status FROM staff WHERE username = :login OR email = :login LIMIT 1');
                $anyStmt->execute([':login' => 'admin']);
                $anyRow = $anyStmt->fetch(PDO::FETCH_ASSOC);
                if ($anyRow) {
                    $result['login_any_staff'] = $anyRow;
                } else {
                    $result['login_any_staff'] = 'NO staff with username admin at all';
                }
            }
        } catch (PDOException $e) {
            $result['login_query_test'] = 'ERROR: ' . $e->getMessage();
        }
    }
    
    // 3c. Test session table operations
    if (isset($db)) {
        try {
            $sessionTest = $db->query("SELECT COUNT(*) FROM sessions");
            $result['session_count'] = (int)$sessionTest->fetchColumn();
        } catch (PDOException $e) {
            $result['session_error'] = $e->getMessage();
        }
    }
    
    ob_end_clean();
    
} catch (Throwable $e) {
    $result['auth_flow_error'] = [
        'class' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ];
}

// ── Step 4: Try actual POST to /api/auth with test credentials ──
try {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'http://localhost' . ($_SERVER['SERVER_PORT'] == 443 ? '' : ':' . $_SERVER['SERVER_PORT']) . '/atlas-bank-backend/api/auth',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['username' => 'admin', 'password' => 'wrongpassword']),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $authResponse = curl_exec($ch);
    $authHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $result['auth_endpoint_test'] = [
        'http_code' => $authHttpCode,
        'curl_error' => $curlError ?: null,
        'response_preview' => substr($authResponse, 0, 500),
    ];
} catch (Throwable $e) {
    $result['auth_endpoint_test'] = [
        'error' => get_class($e) . ': ' . $e->getMessage(),
    ];
}

// ── Step 5: Check Render server logs hint ──
$result['hint'] = 'If auth_endpoint_test still shows 500, check Render logs for the actual PHP error. The app error handler hides details.';

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
