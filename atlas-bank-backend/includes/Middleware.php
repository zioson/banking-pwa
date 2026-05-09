<?php
declare(strict_types=1);

namespace AtlasBank\Includes;

use AtlasBank\Config\Database;
use RuntimeException;

class Middleware
{
    private readonly Auth $auth;
    private readonly Database $db;

    /** Rate limit storage (in production, use Redis/Memcached). */
    private static array $rateLimitStore = [];

    public function __construct(?Auth $auth = null, ?Database $db = null)
    {
        $this->db   = $db ?? Database::getInstance();
        $this->auth = $auth ?? new Auth($this->db);
    }

    // -----------------------------------------------------------------------
    // Authentication & Authorization
    // -----------------------------------------------------------------------

    /**
     * Verify JWT from the Authorization header.
     *
     * @return array{authenticated: bool, user?: array<string, mixed>, error?: string}
     */
    public function auth(): array
    {
        $token = $this->extractBearerToken();

        if ($token === null) {
            return [
                'authenticated' => false,
                'error'         => 'Authorization header missing or malformed. Expected: Bearer <token>.',
            ];
        }

        $result = $this->auth->verify($token);

        if (!$result['valid']) {
            return [
                'authenticated' => false,
                'error'         => $result['error'] ?? 'Token verification failed.',
            ];
        }

        return [
            'authenticated' => true,
            'user'          => $result['data'],
        ];
    }

    /**
     * Require the authenticated user to have one of the specified roles.
     *
     * @param array<int, string> $roles  e.g., ['Manager', 'Auditor', 'Admin']
     * @return array{authorized: bool, user?: array<string, mixed>, error?: string}
     */
    public function requireRole(array $roles, ?array $userData = null): array
    {
        if ($userData === null) {
            $authResult = $this->auth();
            if (!$authResult['authenticated']) {
                return ['authorized' => false, 'error' => $authResult['error']];
            }
            $userData = $authResult['user'];
        }

        $userRole = $userData['role'] ?? '';

        if (!in_array($userRole, $roles, true)) {
            return [
                'authorized' => false,
                'error'      => sprintf('Access denied. Required role: %s. Your role: %s.', implode(', ', $roles), $userRole),
            ];
        }

        return ['authorized' => true, 'user' => $userData];
    }

    /**
     * Check if the authenticated user has access to a specific module.
     *
     * @return array{authorized: bool, user?: array<string, mixed>, error?: string}
     */
    public function requirePermission(string $module, ?array $userData = null): array
    {
        if ($userData === null) {
            $authResult = $this->auth();
            if (!$authResult['authenticated']) {
                return ['authorized' => false, 'error' => $authResult['error']];
            }
            $userData = $authResult['user'];
        }

        $userId = $userData['user_id'];

        // Admin role has access to all modules
        if (($userData['role'] ?? '') === 'Admin') {
            return ['authorized' => true, 'user' => $userData];
        }

        // Check role_permissions table
        $permission = $this->db->fetch(
            'SELECT 1 FROM role_permissions rp
             JOIN staff_roles sr ON sr.role_id = rp.role_id
             WHERE sr.staff_id = :staff_id AND rp.module = :module AND rp.can_access = TRUE
             LIMIT 1',
            ['staff_id' => $userId, 'module' => $module]
        );

        if ($permission === null) {
            return [
                'authorized' => false,
                'error'      => sprintf('Access denied. You do not have permission to access the "%s" module.', $module),
            ];
        }

        return ['authorized' => true, 'user' => $userData];
    }

    /**
     * Verify the authenticated user belongs to the specified branch(es).
     *
     * @param int|array<int, int> $branch  A single branch ID or an array of allowed branch IDs.
     * @return array{authorized: bool, user?: array<string, mixed>, error?: string}
     */
    public function requireBranch(int|array $branch, ?array $userData = null): array
    {
        if ($userData === null) {
            $authResult = $this->auth();
            if (!$authResult['authenticated']) {
                return ['authorized' => false, 'error' => $authResult['error']];
            }
            $userData = $authResult['user'];
        }

        // ★ PG-MIGRATION FIX: staff table doesn't have branch_id column.
        // Branch assignments are in staff_branches table (many-to-many).
        $userBranch = $userData['branch_id'] ?? null;
        $allowedBranches = is_array($branch) ? $branch : [$branch];

        // Admin can access any branch
        if (($userData['role'] ?? '') === 'Admin') {
            return ['authorized' => true, 'user' => $userData];
        }

        if (!in_array($userBranch, $allowedBranches, true)) {
            return [
                'authorized' => false,
                'error'      => sprintf('Access denied. You are not authorized for the requested branch. Your branch: %d.', $userBranch),
            ];
        }

        return ['authorized' => true, 'user' => $userData];
    }

    // -----------------------------------------------------------------------
    // Rate Limiting
    // -----------------------------------------------------------------------

    /**
     * Basic rate limiter (in-memory; for production use Redis/APCu).
     *
     * @param string $ip       Client IP address.
     * @param int    $limit    Max requests allowed within the window.
     * @param int    $window   Time window in seconds.
     * @return array{allowed: bool, remaining: int, retry_after?: int}
     */
    public function rateLimit(string $ip, int $limit = 60, int $window = 60): array
    {
        $key = $ip;
        $now = time();

        if (!isset(self::$rateLimitStore[$key])) {
            self::$rateLimitStore[$key] = [
                'count'     => 0,
                'window_end' => $now + $window,
            ];
        }

        $record = &self::$rateLimitStore[$key];

        // Reset window if expired
        if ($now >= $record['window_end']) {
            $record = [
                'count'     => 1,
                'window_end' => $now + $window,
            ];

            return ['allowed' => true, 'remaining' => $limit - 1];
        }

        $record['count']++;

        if ($record['count'] > $limit) {
            $retryAfter = $record['window_end'] - $now;
            return [
                'allowed'    => false,
                'remaining'  => 0,
                'retry_after' => $retryAfter,
            ];
        }

        return ['allowed' => true, 'remaining' => $limit - $record['count']];
    }

    // -----------------------------------------------------------------------
    // Input Sanitization & Validation
    // -----------------------------------------------------------------------

    /**
     * Recursively sanitize input data.
     *
     * @param array<string, mixed>|string $data
     * @return array<string, mixed>|string
     */
    public function sanitize(array|string $data): array|string
    {
        if (is_string($data)) {
            $data = trim($data);
            $data = stripslashes($data);
            $data = htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return $data;
        }

        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                $sanitizedKey = is_string($key) ? $this->sanitize($key) : $key;
                $sanitized[$sanitizedKey] = $this->sanitize($value);
            }
            return $sanitized;
        }

        return $data;
    }

    /**
     * Validate that all required fields are present and non-empty.
     *
     * @param array<string, mixed> $data    Input data to check.
     * @param array<int, string>  $fields  Required field names.
     * @return array{valid: bool, missing: array<int, string>}
     */
    public function validateRequired(array $data, array $fields): array
    {
        $missing = [];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                $missing[] = $field;
            } elseif (is_string($data[$field]) && trim($data[$field]) === '') {
                $missing[] = $field;
            } elseif (is_null($data[$field])) {
                $missing[] = $field;
            }
        }

        return [
            'valid'   => empty($missing),
            'missing' => $missing,
        ];
    }

    // -----------------------------------------------------------------------
    // Utility
    // -----------------------------------------------------------------------

    /**
     * Extract the Bearer token from the Authorization header.
     */
    public function extractBearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        // ★ FIX (FIN-2b-032): Removed GET/POST token fallback — tokens in URLs leak via
        // browser history, server logs, and Referer headers. Only Authorization header is accepted.

        if (!str_starts_with(strtolower($header), 'bearer ')) {
            return null;
        }

        $token = substr($header, 7);

        return trim($token) !== '' ? $token : null;
    }

    /**
     * Get the client's IP address (respects proxy headers in production).
     */
    public static function getClientIp(): string
    {
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($headers as $header) {
            $ip = $_SERVER[$header] ?? '';

            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                // X-Forwarded-For may contain multiple IPs; take the first one
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                return $ip;
            }
        }

        return '0.0.0.0';
    }

    /**
     * Get the client's User-Agent string.
     */
    public static function getUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    }
}
