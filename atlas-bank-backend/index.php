<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API Health Check Endpoint
 *
 * Access: GET / or GET /index.php
 * Returns basic API status information.
 */

header('Content-Type: application/json; charset=utf-8');
// ★ SECURITY FIX: Removed X-Powered-By header. Revealing server technology
// (e.g., "Atlas Bank Enterprise API", PHP version) aids attackers in
// fingerprinting and targeting known vulnerabilities.

// Check database connectivity
$dbStatus = 'disconnected';
$dbError = null;

try {
    require_once __DIR__ . '/config/database.php';
    $db = getDB();
    $stmt = $db->query('SELECT 1');
    $stmt->fetch();
    $dbStatus = 'connected';
} catch (Exception $e) {
    $dbError = $e->getMessage();
}

// Build response
$response = [
    'service'   => 'Atlas Bank Enterprise API',
    'version'   => '1.0.0',
    'status'    => 'operational',
    'timestamp' => date('c'),
    'database'  => [
        'status' => $dbStatus
    ],
    'endpoints' => [
        'auth'              => '/api/auth',
        'staff'             => '/api/staff',
        'customers'         => '/api/customers',
        'accounts'          => '/api/accounts',
        'transactions'      => '/api/transactions',
        'loans'             => '/api/loans',
        'approvals'         => '/api/approvals',
        'documents'         => '/api/documents',
        'branches'          => '/api/branches',
        'chart-of-accounts' => '/api/chart-of-accounts',
        'settings'          => '/api/settings',
        'branding'          => '/api/branding',
        'expenses'          => '/api/expenses',
        'reports'           => '/api/reports',
        'audit'             => '/api/audit',
        'notifications'     => '/api/notifications',
        'policies'          => '/api/policies',
        'search'            => '/api/search',
        'deductions'        => '/api/deductions'
    ]
];

if ($dbError !== null && defined('APP_DEBUG') && APP_DEBUG) {
    $response['database']['error'] = $dbError;
}

if ($dbStatus !== 'connected') {
    $response['status'] = 'degraded';
    http_response_code(503);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
