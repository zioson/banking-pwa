<?php
/**
 * Atlas Bank Diagnostic Endpoint
 * DELETE THIS FILE AFTER DEBUGGING!
 * Access: /atlas-bank-backend/diag.php
 */

header('Content-Type: application/json; charset=utf-8');

$results = [
    'timestamp' => date('c'),
    'php_version' => PHP_VERSION,
    'checks' => []
];

// ── 1. PHP Extensions ──
$required = ['pdo', 'pdo_pgsql', 'pgsql', 'json', 'mbstring', 'openssl', 'session'];
$results['checks']['extensions'] = [];
foreach ($required as $ext) {
    $results['checks']['extensions'][$ext] = extension_loaded($ext);
}

// ── 2. Environment Variables ──
$envVars = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_SCHEMA', 'DB_SSLMODE', 'APP_ENV', 'APP_DEBUG'];
$results['checks']['env_vars'] = [];
foreach ($envVars as $v) {
    $val = getenv($v);
    $results['checks']['env_vars'][$v] = $val !== false ? ('set(' . strlen($val) . ' chars)') : 'NOT SET';
}

// ── 3. Constants Check ──
$results['checks']['constants'] = [
    'DB_HOST' => defined('DB_HOST') ? DB_HOST : 'not defined',
    'DB_NAME' => defined('DB_NAME') ? DB_NAME : 'not defined',
    'DB_USER' => defined('DB_USER') ? DB_USER : 'not defined',
    'DB_SCHEMA' => defined('DB_SCHEMA') ? DB_SCHEMA : 'not defined',
    'DB_SSLMODE' => defined('DB_SSLMODE') ? DB_SSLMODE : 'not defined',
];

// ── 4. Database Connection ──
try {
    $host = getenv('DB_HOST') ?: 'dpg-d7ungdtb910c73ep2i20-a.oregon-postgres.render.com';
    $port = getenv('DB_PORT') ?: '5432';
    $dbname = getenv('DB_NAME') ?: 'atlas_bank_q3gq';
    $user = getenv('DB_USER') ?: 'atlas_bank_q3gq_user';
    $pass = getenv('DB_PASS') ?: '3UPC6Q7P97ZDtFYNervRXVFb1o2ijLB9';
    $schema = getenv('DB_SCHEMA') ?: 'atlas_bank_schema';
    $sslmode = getenv('DB_SSLMODE') ?: 'require';

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
        $host,
        $port,
        $dbname,
        $sslmode
    );

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Set search_path after connection (not via DSN options — that causes encoding issues)
    $pdo->exec('SET search_path TO ' . $pdo->quote($schema) . ', public');

    $results['checks']['database'] = [
        'status' => 'CONNECTED',
        'dsn_used' => preg_replace('/password=[^;]+/', 'password=***', $dsn),
    ];

    // Test query
    $ver = $pdo->query('SELECT version()')->fetchColumn();
    $results['checks']['database']['version'] = substr($ver, 0, 80);

    // Check schema
    $schemaCheck = $pdo->query("SELECT schema_name FROM information_schema.schemata WHERE schema_name = '{$schema}'")->fetchColumn();
    $results['checks']['database']['schema_exists'] = $schemaCheck ? true : false;

    // Check tables count
    $tableCount = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$schema}'")->fetchColumn();
    $results['checks']['database']['table_count'] = (int)$tableCount;

    // Check staff table
    try {
        $staffCount = $pdo->query("SELECT COUNT(*) FROM {$schema}.staff")->fetchColumn();
        $results['checks']['database']['staff_count'] = (int)$staffCount;
    } catch (PDOException $e) {
        $results['checks']['database']['staff_error'] = $e->getMessage();
    }

    // Check sessions table
    try {
        $sessionsExists = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$schema}' AND table_name = 'sessions'")->fetchColumn();
        $results['checks']['database']['sessions_table_exists'] = (int)$sessionsExists > 0;
    } catch (PDOException $e) {
        $results['checks']['database']['sessions_error'] = $e->getMessage();
    }

    $pdo = null;

} catch (PDOException $e) {
    $results['checks']['database'] = [
        'status' => 'FAILED',
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
    ];
}

// ── 5. File Include Test ──
$files = [
    'config/constants.php',
    'config/database.php',
    'config/cors.php',
    'middleware/cors.php',
    'middleware/csrf.php',
    'middleware/auth.php',
    'middleware/rbac.php',
    'middleware/rate_limit.php',
    'includes/Response.php',
    'includes/helpers.php',
    'includes/Auth.php',
    'includes/Middleware.php',
    'includes/AuditLogger.php',
    'api/auth.php',
    'router.php',
];

$results['checks']['files'] = [];
foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    $results['checks']['files'][$f] = [
        'exists' => file_exists($path),
        'readable' => is_readable($path),
        'size' => file_exists($path) ? filesize($path) : 0,
    ];
}

// ── 6. PHP Syntax Check on critical files ──
$results['checks']['syntax'] = [];
$criticalFiles = ['config/database.php', 'api/auth.php', 'router.php', 'includes/helpers.php', 'middleware/auth.php'];
foreach ($criticalFiles as $f) {
    $path = __DIR__ . '/' . $f;
    if (file_exists($path)) {
        exec("php -l " . escapeshellarg($path) . " 2>&1", $output, $code);
        $results['checks']['syntax'][$f] = [
            'valid' => $code === 0,
            'output' => implode("\n", $output)
        ];
        $output = [];
    }
}

// ── 7. Session Test ──
try {
    session_name('ATLAS_BANK_SESSION');
    session_start();
    $results['checks']['session'] = [
        'status' => 'OK',
        'id' => session_id(),
        'save_path' => session_save_path(),
    ];
    session_write_close();
} catch (Exception $e) {
    $results['checks']['session'] = [
        'status' => 'FAILED',
        'error' => $e->getMessage()
    ];
}

// ── 8. Try loading the full auth pipeline ──
$results['checks']['auth_pipeline'] = [];
try {
    // Simulate what router.php does
    require_once __DIR__ . '/config/constants.php';
    $results['checks']['auth_pipeline']['step1_constants'] = 'OK';

    require_once __DIR__ . '/config/database.php';
    $results['checks']['auth_pipeline']['step2_database'] = 'OK';

    require_once __DIR__ . '/config/cors.php';
    $results['checks']['auth_pipeline']['step3_cors_config'] = 'OK';

    require_once __DIR__ . '/middleware/cors.php';
    $results['checks']['auth_pipeline']['step4_cors_middleware'] = 'OK';

    require_once __DIR__ . '/includes/Response.php';
    $results['checks']['auth_pipeline']['step5_response'] = 'OK';

    require_once __DIR__ . '/includes/helpers.php';
    $results['checks']['auth_pipeline']['step6_helpers'] = 'OK';

    // Test getDB()
    $db = getDB();
    $results['checks']['auth_pipeline']['step7_getdb'] = 'OK - got PDO connection';

} catch (Throwable $e) {
    $results['checks']['auth_pipeline']['error'] = [
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
