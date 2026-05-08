<?php
declare(strict_types=1);

namespace {
    if (!defined('DB_HOST')) {
        define('DB_HOST', getenv('DB_HOST') ?: 'dpg-d7ungdtb910c73ep2i20-a.oregon-postgres.render.com');
    }
    if (!defined('DB_PORT')) {
        define('DB_PORT', getenv('DB_PORT') ?: '5432');
    }
    if (!defined('DB_NAME')) {
        define('DB_NAME', getenv('DB_NAME') ?: 'atlas_bank_q3gq');
    }
    if (!defined('DB_USER')) {
        define('DB_USER', getenv('DB_USER') ?: 'atlas_bank_q3gq_user');
    }
    if (!defined('DB_PASS')) {
        define('DB_PASS', getenv('DB_PASS') ?: '3UPC6Q7P97ZDtFYNervRXVFb1o2ijLB9');
    }
    if (!defined('DB_SCHEMA')) {
        define('DB_SCHEMA', getenv('DB_SCHEMA') ?: 'atlas_bank_schema');
    }
    if (!defined('DB_SSLMODE')) {
        define('DB_SSLMODE', getenv('DB_SSLMODE') ?: 'require');
    }

    if (!defined('APP_ENV')) {
        define('APP_ENV', getenv('APP_ENV') ?: 'development');
    }
    if (!defined('APP_DEBUG')) {
        $debugEnv = getenv('APP_DEBUG');
        $debugFromEnv = $debugEnv !== false
            && in_array(strtolower((string)$debugEnv), ['1', 'true', 'yes', 'on'], true);
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

    if (!defined('ATLAS_BANK_ERROR_HANDLERS_REGISTERED')) {
        define('ATLAS_BANK_ERROR_HANDLERS_REGISTERED', true);

        set_exception_handler(function (\Throwable $e): void {
            $errId = 'EX-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
            error_log('ATLAS_BANK_EXCEPTION[' . $errId . ']: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            $payload = [
                'success' => false,
                'error' => 'An internal error occurred. Please contact support.',
                'error_id' => $errId
            ];
            if (APP_DEBUG) {
                $payload['debug'] = [
                    'message' => $e->getMessage(),
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ];
            }
            echo json_encode($payload);
            exit;
        });

        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            error_log("ATLAS_BANK_ERROR [{$severity}]: {$message} in {$file}:{$line}");
            return true;
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $errId = 'FATAL-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
                error_log('ATLAS_BANK_FATAL[' . $errId . ']: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                $payload = [
                    'success' => false,
                    'error' => 'A critical error occurred.',
                    'error_id' => $errId
                ];
                if (APP_DEBUG) {
                    $payload['debug'] = [
                        'message' => $error['message'],
                        'file' => basename($error['file']),
                        'line' => $error['line']
                    ];
                }
                echo json_encode($payload);
                exit;
            }
        });
    }

    /**
     * Get PDO database connection (singleton pattern).
     */
    function getDB(): \PDO
    {
        static $pdo = null;

        if ($pdo instanceof \PDO) {
            return $pdo;
        }

        try {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s;options=-c%%20search_path%%3D%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_SSLMODE,
                urlencode(DB_SCHEMA)
            );

            $options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_PERSISTENT => false,
            ];

            $pdo = new \PDO($dsn, DB_USER, DB_PASS, $options);

            // Set search_path as a fallback (in case DSN options param is ignored)
            $pdo->exec("SET search_path TO ' . $pdo->quote(DB_SCHEMA) . ', public");
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

namespace AtlasBank\Config {

/**
 * OOP wrapper that stays compatible with the procedural PDO bootstrap.
 */
class Database
{
    private static ?self $instance = null;
    private \PDO $pdo;

    private function __construct()
    {
        $this->pdo = \getDB();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();
        return $result === false ? null : $result;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Get the last inserted ID.
     * PostgreSQL requires the sequence name for lastInsertId() when using SERIAL columns.
     * If $sequenceName is null, tries the generic call (works if PDO driver supports it).
     * Best practice: pass the sequence name, e.g. 'accounts_id_seq'.
     */
    public function lastInsertId(?string $sequenceName = null): string
    {
        return $this->pdo->lastInsertId($sequenceName);
    }

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }
}
}
