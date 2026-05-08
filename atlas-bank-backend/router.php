<?php
/**
 * Atlas Bank Enterprise Operations Console
 * Lightweight API Router
 *
 * Routes all /api/* requests to the appropriate API endpoint files.
 * Supports URL patterns:
 *   /api/{resource}
 *   /api/{resource}/{id}
 *   /api/{resource}/{id}/{sub-resource}
 *   /api/{resource}/{id}/{sub-resource}/{sub-id}
 *
 * Query string parameters are preserved and accessible via $_GET.
 */

// ── Global error handler — prevents raw PHP errors from leaking to client ──
set_error_handler(function($severity, $msg, $file, $line) {
    if (!(error_reporting() & $severity)) return;
    throw new ErrorException($msg, 0, $severity, $file, $line);
});

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        $isDebug = defined('APP_DEBUG') && APP_DEBUG;
        echo json_encode([
            'success' => false,
            'error'   => $isDebug ? ('Router fatal error: ' . $err['message']) : 'Internal server error.',
            'file'    => $isDebug ? basename($err['file']) : null,
            'line'    => $isDebug ? $err['line'] : null
        ]);
        exit;
    }
});

try {

// -----------------------------------------------------------
// Bootstrap
// -----------------------------------------------------------
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/middleware/cors.php';
require_once __DIR__ . '/middleware/csrf.php';
require_once __DIR__ . '/includes/Response.php';
require_once __DIR__ . '/includes/helpers.php';

// Handle CORS and preflight
handlePreflightRequest();

// -----------------------------------------------------------
// Enterprise Security HTTP Headers
// These headers apply to ALL API responses.
// frame-ancestors, HSTS, and referrer-policy CANNOT be set via
// HTML <meta> tags (browsers ignore them) — they MUST be HTTP headers.
// -----------------------------------------------------------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Content-Security-Policy for API endpoints — API responses are JSON only,
// no scripts, no frames, no plugins.
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");

// Strict-Transport-Security (HSTS) — enforce HTTPS in production.
// In development (HTTP), browsers reject HSTS headers, so we conditionally send.
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

// ★ FIX (FIN-2b-016): Limit request body size to prevent denial-of-service
// via oversized JSON payloads. 1MB is generous for API JSON; file uploads
// should go through a dedicated upload endpoint.
$maxBodySize = 1048576; // 1 MB
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > $maxBodySize) {
    http_response_code(413);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => 'Request body too large. Maximum size is 1MB.',
        'code'    => 413
    ]);
    exit;
}

// -----------------------------------------------------------
// PHP Session — set name BEFORE anything else touches sessions
// This must run before any session_start() so that all endpoints
// (auth, CSRF, etc.) share the same session cookie.
// -----------------------------------------------------------
if (defined('SESSION_NAME')) {
    session_name(SESSION_NAME);
}

// Secure session cookie configuration — banking-grade settings.
// HttpOnly: prevents JavaScript access to the session cookie (anti-XSS).
// SameSite=Strict: prevents CSRF by ensuring cookies are only sent
//   in first-party requests (never on cross-site GET/POST).
// Secure: only send over HTTPS (disabled in HTTP/development).
// These settings are applied when session_start() is called.
$sessionSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
if ($sessionSecure) {
    ini_set('session.cookie_secure', '1');
}

// -----------------------------------------------------------
// Resource-to-File Mapping
// -----------------------------------------------------------
$resourceMap = [
    'auth'              => 'api/auth.php',
    'staff'             => 'api/staff.php',
    'customers'         => 'api/customers.php',
    'accounts'          => 'api/accounts.php',
    'transactions'      => 'api/transactions.php',
    'loans'             => 'api/loans.php',
    'approvals'         => 'api/approvals.php',
    'documents'         => 'api/documents.php',
    'branches'          => 'api/branches.php',
    'chart-of-accounts' => 'api/chart-of-accounts.php',
    'general-ledger'     => 'api/general-ledger.php',
    'settings'          => 'api/settings.php',
    'branding'          => 'api/branding.php',
    'expenses'          => 'api/expenses.php',
    'reports'           => 'api/reports.php',
    'audit'             => 'api/audit.php',
    'notifications'     => 'api/notifications.php',
    'policies'          => 'api/policies.php',
    'search'            => 'api/search.php',
    'deductions'        => 'api/deductions.php',
    'operating-account'     => 'api/operating-account.php',
    'loan-applications'     => 'api/loan-applications.php',
    'loan-fund-accounts'    => 'api/loan-fund-accounts.php',
    'operating-fund'        => 'api/operating-fund.php',
    'investments'           => 'api/investments.php',
    'client-auth'           => 'api/client-auth.php',
    'client-portal'         => 'api/client-portal.php',
    'client-statements'     => 'api/client-statements.php',
];

// Resources that do NOT require authentication
$publicResources = [
    'auth',
    'client-auth'
];

// -----------------------------------------------------------
// Parse the Request URI
// -----------------------------------------------------------

// Get the request URI path (without query string)
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestUri = '/' . trim($requestUri, '/');

// Dynamically detect the /api prefix based on where router.php lives.
// This lets the entire backend work from ANY subdirectory without code changes:
//   - If router.php is at /router.php          → prefix = /api
//   - If router.php is at /atlas-bank-backend/router.php → prefix = /atlas-bank-backend/api
//   - If router.php is at /a/b/c/router.php    → prefix = /a/b/c/api
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$scriptDir = ($scriptDir === '/' || $scriptDir === '\\') ? '' : $scriptDir;
$apiPrefix = $scriptDir . '/api';

if (stripos($requestUri, $apiPrefix) === 0) {
    $requestUri = substr($requestUri, strlen($apiPrefix));
} elseif (stripos($requestUri, '/api') === 0) {
    // Fallback: no subdirectory prefix (router at web root)
    $requestUri = substr($requestUri, strlen('/api'));
}
$requestUri = '/' . trim($requestUri, '/');

// Split into path segments
$segments = array_values(array_filter(explode('/', $requestUri), function ($seg) {
    return $seg !== '';
}));

// -----------------------------------------------------------
// Route Parameters
// -----------------------------------------------------------
$resource     = $segments[0] ?? '';
$id           = $segments[1] ?? null;
$subResource  = $segments[2] ?? null;
$subId        = $segments[3] ?? null;

// HTTP method
$method = strtoupper($_SERVER['REQUEST_METHOD']);

// Override method via X-HTTP-Method-Override header or _method parameter
// Priority: Header → Body/Query (if actual method is POST)
// ★ SECURITY FIX: Only allow _method override if the ACTUAL method is POST.
// This prevents CSRF-like attacks via simple GET links.
$overrideMethod = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $overrideMethod = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']
        ?? ($_POST['_method'] ?? ($_GET['_method'] ?? ''));
}
if (!empty($overrideMethod)) {
    $method = strtoupper($overrideMethod);
}

// -----------------------------------------------------------
// Set Route Variables (available to API files)
// -----------------------------------------------------------
$_ROUTE = [
    'resource'    => $resource,
    'id'          => $id,
    'subResource' => $subResource,
    'subId'       => $subId,
    'method'      => $method,
    'segments'    => $segments,
    'query'       => $_GET,
    'params'      => array_merge($_GET, $_POST)
];

// -----------------------------------------------------------
// Route Validation
// -----------------------------------------------------------

// Empty resource — serve health check endpoint
if (empty($resource)) {
    if ($method === 'GET' && (isset($_GET['health']) || isset($_GET['ping']))) {
        // /api/?health — lightweight health check (no DB required)
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(200);
        echo json_encode([
            'status'    => 'healthy',
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'version'   => defined('API_VERSION') ? API_VERSION : '1.0.0'
        ]);
        exit;
    }
    jsonResponse([
        'success' => false,
        'error'   => 'No resource specified. Available endpoints: ' . implode(', ', array_keys($resourceMap))
    ], 400);
}

// Unknown resource
if (!isset($resourceMap[$resource])) {
    jsonResponse([
        'success' => false,
        'error'   => 'Unknown resource: ' . sanitize($resource),
        'available' => array_keys($resourceMap)
    ], 404);
}

// -----------------------------------------------------------
// Authentication Check
// -----------------------------------------------------------
if (!in_array($resource, $publicResources, true)) {
    // The individual API file is responsible for calling requireAuth()
    // but we can pre-load the auth middleware here for convenience
    require_once __DIR__ . '/middleware/auth.php';
    require_once __DIR__ . '/middleware/rbac.php';

    // Resources that skip CSRF (internal/system endpoints called fire-and-forget)
    // Auth: login has no session yet — login itself generates the first CSRF token
    // Audit: fire-and-forget logging — exempt to avoid token race conditions
    $csrfExemptResources = ['audit', 'auth'];

    // CSRF check for all authenticated mutating requests
    // GET/OPTIONS are safe — no CSRF check needed
    // Auth handles CSRF internally (login generates the first token)
    // Audit is fire-and-forget logging — exempt from CSRF to avoid token race conditions
    $newCsrf = null;
    if (!in_array($resource, $csrfExemptResources, true)) {
        // ★ SECURITY FIX: Base CSRF check on the ACTUAL HTTP method, not overridden.
        // This prevents method-override bypass via GET parameters.
        $actualMethod = strtoupper($_SERVER['REQUEST_METHOD']);
        $newCsrf = callCsrfCheck($actualMethod);
    }
    if ($newCsrf !== null) {
        // Send the new rotated token back to the client in a response header
        header('X-CSRF-Token: ' . $newCsrf);
    }
}

// -----------------------------------------------------------
// Route to the API file
// -----------------------------------------------------------
$apiFile = __DIR__ . '/' . $resourceMap[$resource];

if (!file_exists($apiFile)) {
    jsonResponse([
        'success' => false,
        'error'   => 'API endpoint not yet implemented: ' . sanitize($resource),
        'resource' => $resource
    ], 501);
}

// Include the API file (it handles the request and response)
require_once $apiFile;

} catch (Throwable $e) {
    // Catch any unhandled exception — prevents raw PHP errors
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    $isDebug = defined('APP_DEBUG') && APP_DEBUG;
    echo json_encode([
        'success' => false,
        'error'   => $isDebug ? ('API error: ' . get_class($e)) : 'Internal server error.',
        'message' => $isDebug ? $e->getMessage() : 'An unexpected error occurred. Please try again or contact the system administrator.',
        'file'    => $isDebug ? basename($e->getFile()) : null,
        'line'    => $isDebug ? $e->getLine() : null,
        'trace'   => $isDebug ? $e->getTraceAsString() : null
    ]);
    exit;
}
