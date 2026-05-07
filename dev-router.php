<?php
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uriPath = $uriPath ?: '/';

$docRoot = __DIR__;
$candidate = realpath($docRoot . str_replace('/', DIRECTORY_SEPARATOR, $uriPath));

if ($candidate && str_starts_with($candidate, realpath($docRoot)) && is_file($candidate)) {
    return false;
}

if (str_starts_with($uriPath, '/atlas-bank-backend/api') || str_starts_with($uriPath, '/api')) {
    $_SERVER['SCRIPT_NAME'] = '/atlas-bank-backend/router.php';
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/atlas-bank-backend/router.php';
    require __DIR__ . '/atlas-bank-backend/router.php';
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not found";

