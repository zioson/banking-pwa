<?php
/**
 * Ultra-lightweight health check - NO database, NO includes, NO dependencies.
 * If this returns 200, the PHP server is running.
 * If you get 502, the PHP process is crashing on startup.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    'status'    => 'ok',
    'service'   => 'Atlas Bank API',
    'php'       => phpversion(),
    'sapi'      => php_sapi_name(),
    'time'      => date('c'),
    'extensions' => [
        'pdo'         => extension_loaded('pdo'),
        'pdo_pgsql'   => extension_loaded('pdo_pgsql'),
        'pgsql'       => extension_loaded('pgsql'),
        'json'        => extension_loaded('json'),
        'mbstring'    => extension_loaded('mbstring'),
        'openssl'     => extension_loaded('openssl'),
        'session'     => extension_loaded('session'),
        'curl'        => extension_loaded('curl'),
        'bcmath'      => extension_loaded('bcmath'),
    ],
    'env_vars'  => [
        'DB_HOST'  => getenv('DB_HOST') ? 'set' : 'not set (using fallback)',
        'DB_PORT'  => getenv('DB_PORT') ?: 'not set (using fallback)',
        'DB_NAME'  => getenv('DB_NAME') ? 'set' : 'not set (using fallback)',
        'APP_ENV'  => getenv('APP_ENV') ?: 'not set',
    ]
], JSON_PRETTY_PRINT);
