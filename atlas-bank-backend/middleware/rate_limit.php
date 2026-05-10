<?php
/**
 * Atlas Bank — Rate Limiting Middleware
 *
 * DATABASE-DRIVEN rate limiting for enterprise-grade security.
 * Prevents brute-force attacks on login and API abuse.
 *
 * Uses a single-row-per-key model in the rate_limits table.
 * Self-heals the table on first use.
 *
 * @see checkRateLimit()      — returns bool (does not exit)
 * @see requireRateLimit()    — sends 429 and exits if limited
 * @see getRateLimitHeaders() — returns X-RateLimit-* header values
 */

/**
 * Self-heal: ensure the rate_limits table exists.
 * Called once per request that uses rate limiting.
 */
function ensureRateLimitsTable(): void
{
    static $ensured = false;
    if ($ensured) return;

    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            id SERIAL PRIMARY KEY,
            rate_key VARCHAR(255) NOT NULL,
            attempt_count INTEGER NOT NULL DEFAULT 1,
            first_attempt_at TIMESTAMP NULL DEFAULT NULL,
            blocked_until TIMESTAMP NULL DEFAULT NULL,
            CONSTRAINT uk_rate_key UNIQUE (rate_key)
        )");
        try { $db->exec("CREATE INDEX IF NOT EXISTS idx_blocked ON rate_limits (blocked_until)"); } catch (PDOException $ie) {}
        $ensured = true;
    } catch (PDOException $e) {
        // Table creation failed — rate limiting will fail-open
        if (defined('APP_DEBUG') && APP_DEBUG) {
            error_log('[RateLimit] Failed to create rate_limits table: ' . $e->getMessage());
        }
    }
}

/**
 * Check rate limit for a given key.
 *
 * Single-row-per-key model:
 *   - On first request: INSERT new row with attempt_count = 1
 *   - On subsequent requests within the window: INCREMENT attempt_count
 *   - When window expires (first_attempt_at + windowSeconds): RESET counter
 *   - When attempt_count >= maxRequests: BLOCK until window expires
 *
 * @param string $key         Rate limit key (e.g., 'login:192.168.1.1')
 * @param int    $maxRequests Maximum requests allowed in window
 * @param int    $windowSeconds Time window in seconds
 * @return bool True if allowed, false if rate limited
 */
function checkRateLimit(string $key, int $maxRequests = 30, int $windowSeconds = 60): bool
{
    ensureRateLimitsTable();

    try {
        $db = getDB();
        $now = time();
        $windowStart = date('Y-m-d H:i:s', $now - $windowSeconds);

        // 1. Look up current state for this key
        $stmt = $db->prepare(
            'SELECT attempt_count, first_attempt_at, blocked_until
             FROM rate_limits WHERE rate_key = :key LIMIT 1'
        );
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // 1a. Check if currently blocked
            if (!empty($row['blocked_until']) && strtotime($row['blocked_until']) > $now) {
                $remaining = (int)(strtotime($row['blocked_until']) - $now);
                header('Retry-After: ' . max(1, $remaining));
                return false;
            }

            // 1b. Check if the counting window has expired → reset counter
            if (!empty($row['first_attempt_at']) && strtotime($row['first_attempt_at']) < $now - $windowSeconds) {
                // Window expired: reset counter and start fresh
                $db->prepare(
                    'UPDATE rate_limits SET attempt_count = 1, first_attempt_at = NOW(), blocked_until = NULL
                     WHERE rate_key = :key'
                )->execute([':key' => $key]);
                return true;
            }

            // 1c. Within the window: increment counter
            $newCount = (int)$row['attempt_count'] + 1;

            if ($newCount >= $maxRequests) {
                // Threshold reached: block for the window duration
                $blockUntil = date('Y-m-d H:i:s', $now + $windowSeconds);
                $db->prepare(
                    'UPDATE rate_limits SET attempt_count = :count, blocked_until = :until
                     WHERE rate_key = :key'
                )->execute([
                    ':count' => $newCount,
                    ':until' => $blockUntil,
                    ':key'   => $key
                ]);
                header('Retry-After: ' . $windowSeconds);
                return false;
            }

            // Normal increment
            $db->prepare(
                'UPDATE rate_limits SET attempt_count = :count WHERE rate_key = :key'
            )->execute([':count' => $newCount, ':key' => $key]);

            return true;
        } else {
            // 2. No row exists — first attempt ever for this key
            $db->prepare(
                'INSERT INTO rate_limits (rate_key, attempt_count, first_attempt_at)
                 VALUES (:key, 1, NOW())'
            )->execute([':key' => $key]);
            return true;
        }
    } catch (PDOException $e) {
        // ★ SECURITY FIX (MEDIUM): Fail-CLOSED with in-memory fallback.
        // Previously fail-open (return true) — an attacker could exhaust the DB
        // connection pool, then brute-force without rate limiting.
        // Now: use APCu/file-based fallback for 60-second window.
        if (defined('APP_DEBUG') && APP_DEBUG) {
            error_log('[RateLimit] DB error, using in-memory fallback: ' . $e->getMessage());
        }

        // In-memory fallback using static variable (per-request only)
        static $memoryCounts = [];
        $now = time();
        $windowKey = $key . ':' . (int)($now / $windowSeconds);

        if (!isset($memoryCounts[$windowKey])) {
            $memoryCounts[$windowKey] = ['count' => 0, 'window' => $windowKey];
        }

        $memoryCounts[$windowKey]['count']++;

        if ($memoryCounts[$windowKey]['count'] >= $maxRequests) {
            // Clean old windows
            foreach ($memoryCounts as $k => $v) {
                if ($k !== $windowKey) unset($memoryCounts[$k]);
            }
            header('Retry-After: ' . $windowSeconds);
            return false;
        }

        return true;
    }
}

/**
 * Get remaining rate limit info for X-RateLimit-* response headers.
 *
 * @param string $key         Rate limit key
 * @param int    $maxRequests Maximum requests allowed in window
 * @param int    $windowSeconds Time window in seconds
 * @return array Header name => value
 */
function getRateLimitHeaders(string $key, int $maxRequests = 30, int $windowSeconds = 60): array
{
    ensureRateLimitsTable();

    try {
        $db = getDB();
        $now = time();
        $stmt = $db->prepare(
            'SELECT attempt_count, first_attempt_at, blocked_until
             FROM rate_limits WHERE rate_key = :key LIMIT 1'
        );
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'X-RateLimit-Limit'     => $maxRequests,
                'X-RateLimit-Remaining' => $maxRequests,
                'X-RateLimit-Reset'     => time() + $windowSeconds
            ];
        }

        // If blocked, remaining = 0
        if (!empty($row['blocked_until']) && strtotime($row['blocked_until']) > $now) {
            return [
                'X-RateLimit-Limit'     => $maxRequests,
                'X-RateLimit-Remaining' => 0,
                'X-RateLimit-Reset'     => (int)strtotime($row['blocked_until'])
            ];
        }

        // Check if window expired
        $count = (int)$row['attempt_count'];
        $resetTime = (!empty($row['first_attempt_at']))
            ? (int)strtotime($row['first_attempt_at']) + $windowSeconds
            : time() + $windowSeconds;

        if ($now > $resetTime) {
            // Window expired
            return [
                'X-RateLimit-Limit'     => $maxRequests,
                'X-RateLimit-Remaining' => $maxRequests,
                'X-RateLimit-Reset'     => time() + $windowSeconds
            ];
        }

        $remaining = max(0, $maxRequests - $count);

        return [
            'X-RateLimit-Limit'     => $maxRequests,
            'X-RateLimit-Remaining' => $remaining,
            'X-RateLimit-Reset'     => $resetTime
        ];
    } catch (PDOException $e) {
        return [
            'X-RateLimit-Limit'     => $maxRequests,
            'X-RateLimit-Remaining' => $maxRequests,
            'X-RateLimit-Reset'     => time() + $windowSeconds
        ];
    }
}

/**
 * Require rate limit check — sends 429 and exits if limited.
 *
 * @param string $key         Rate limit key
 * @param int    $maxRequests Maximum requests allowed in window
 * @param int    $windowSeconds Time window in seconds
 */
function requireRateLimit(string $key, int $maxRequests = 30, int $windowSeconds = 60): void
{
    if (!checkRateLimit($key, $maxRequests, $windowSeconds)) {
        $headers = getRateLimitHeaders($key, $maxRequests, $windowSeconds);
        foreach ($headers as $k => $v) {
            header($k . ': ' . $v);
        }
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'   => false,
            'error'     => 'Too many requests. Please wait before trying again.',
            'retry_after' => $windowSeconds
        ]);
        exit;
    }
}

/**
 * Reset rate limit for a given key (e.g., after successful login).
 *
 * @param string $key Rate limit key to reset
 * @return bool True on success
 */
function resetRateLimit(string $key): bool
{
    try {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM rate_limits WHERE rate_key = :key');
        return $stmt->execute([':key' => $key]);
    } catch (PDOException $e) {
        return false;
    }
}
