<?php
declare(strict_types=1);

namespace {
    // 1. Handle Render's DATABASE_URL for PostgreSQL
    $databaseUrl = getenv('DATABASE_URL');
    if ($databaseUrl) {
        $dbopts = parse_url($databaseUrl);
        define('DB_HOST', $dbopts["host"]);
        define('DB_PORT', $dbopts["port"] ?? '5432');
        define('DB_USER', $dbopts["user"]);
        define('DB_PASS', $dbopts["pass"]);
        define('DB_NAME', ltrim($dbopts["path"], '/'));
    } else {
        // Fallback for local development
        define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
        define('DB_PORT', getenv('DB_PORT') ?: '5432');
        define('DB_NAME', getenv('DB_NAME') ?: 'atlas_bank');
        define('DB_USER', getenv('DB_USER') ?: 'postgres');
        define('DB_PASS', getenv('DB_PASS') ?: '');
    }

    // Standard Configs
    if (!defined('APP_ENV')) {
        define('APP_ENV', getenv('APP_ENV') ?: 'development');
    }
    if (!defined('APP_DEBUG')) {
        $debugEnv = getenv('APP_DEBUG');
        $debugFromEnv = $debugEnv !== false && in_array(strtolower((string)$debugEnv), ['1', 'true', 'yes', 'on'], true);
        define('APP_DEBUG', $debugEnv !== false ? $debugFromEnv : APP_ENV === 'development');
    }

    if (!defined('SESSION_LIFETIME')) {
        define('SESSION_LIFETIME', (int)(getenv('SESSION_LIFETIME') ?: 480));
    }
    if (!defined('SESSION_NAME')) {
        define('SESSION_NAME', getenv('SESSION_NAME') ?: 'ATLAS_BANK_SESSION');
    }

    if (!APP_DEBUG) {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        error_reporting(0);
    }

    // ... (Keep your existing error handlers/shutdown functions here) ...

    /**
     * Get PDO database connection (Updated for PostgreSQL).
     */
    function getDB(): \PDO
    {
        static $pdo = null;
        if ($pdo instanceof \PDO) return $pdo;

        try {
            // Updated DSN for pgsql
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME
            );

            $options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_PERSISTENT => false,
            ];

            $pdo = new \PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (\PDOException $e) {
            $errorDetails = APP_DEBUG ? $e->getMessage() : 'Database connection failed';
            error_log('ATLAS_BANK_DB: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Service temporarily unavailable',
                'details' => $errorDetails
            ]);
            exit;
        }
        return $pdo;
    }
}
