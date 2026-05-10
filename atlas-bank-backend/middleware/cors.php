<?php
/**
 * Atlas Bank Enterprise Operations Console
 * CORS Middleware
 *
 * Sets Cross-Origin Resource Sharing headers.
 *
 * IMPORTANT: When Access-Control-Allow-Credentials is true, the origin
 * CANNOT be wildcard '*'. The browser will reject the response. This middleware
 * dynamically mirrors the request Origin header (after validation) so that
 * credentials (cookies, Authorization headers) are transmitted correctly.
 */

/**
 * Parse allowed CORS origins from environment or explicit array.
 *
 * @param array|null $allowedOrigins Optional explicit list
 * @return array List of allowed origin strings
 */
function parseAllowedCorsOrigins(?array $allowedOrigins = null): array
{
    if ($allowedOrigins !== null) {
        return array_values(array_filter(array_map('trim', $allowedOrigins), static fn($v) => $v !== ''));
    }

    $originEnv = getenv('CORS_ALLOWED_ORIGINS');
    if ($originEnv !== false && trim((string)$originEnv) !== '') {
        return array_values(array_filter(array_map('trim', explode(',', (string)$originEnv)), static fn($v) => $v !== ''));
    }

    // Safe local-development defaults. Production must set CORS_ALLOWED_ORIGINS explicitly.
    return [
        'http://localhost',
        'http://127.0.0.1',
        'http://localhost:8000',
        'http://127.0.0.1:8000',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ];
}

/**
 * Check if a request origin is in the allowed list.
 *
 * @param string $requestOrigin  The Origin header from the request
 * @param array  $allowedOrigins List of allowed origin patterns
 * @return bool True if allowed
 */
function isCorsOriginAllowed(string $requestOrigin, array $allowedOrigins): bool
{
    if ($requestOrigin === '') {
        return false;
    }

    $normalized = strtolower(trim($requestOrigin));
    $isProduction = strtolower((string)(getenv('APP_ENV') ?: 'development')) === 'production';

    foreach ($allowedOrigins as $allowed) {
        $allowed = trim((string)$allowed);
        if ($allowed === '') {
            continue;
        }

        // Wildcard is intentionally not supported in production because credentials are enabled.
        if ($allowed === '*') {
            if (!$isProduction && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i', $requestOrigin)) {
                return true;
            }
            continue;
        }

        if (str_starts_with($allowed, '*.')) {
            $baseDomain = strtolower(substr($allowed, 1));
            $host = strtolower((string)(parse_url($requestOrigin, PHP_URL_HOST) ?: ''));
            $allowedHost = ltrim($baseDomain, '.');
            if ($host === $allowedHost || str_ends_with($host, $baseDomain)) {
                return true;
            }
            continue;
        }

        if (strtolower($allowed) === $normalized) {
            return true;
        }
    }

    return false;
}

/**
 * Send CORS headers for the response.
 *
 * When credentials are needed (default), the allowed origin is set to the
 * request's Origin header (mirrored back). This satisfies the CORS spec
 * requirement that credentials + wildcard origin is forbidden.
 *
 * In development mode, safe localhost origins are permitted.
 * In production, restrict via CORS_ALLOWED_ORIGINS env var.
 *
 * @param array|null $allowedOrigins Optional whitelist of allowed origins
 */
function sendCorsHeaders(?array $allowedOrigins = null): void
{
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = parseAllowedCorsOrigins($allowedOrigins);

    if (isCorsOriginAllowed($requestOrigin, $allowed)) {
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
    }

    // Allowed HTTP methods
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');

    // Allowed request headers
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Atlas-Session, X-HTTP-Method-Override, X-CSRF-Token, Accept, Origin, Cache-Control, X-Idempotency-Key, X-MFA-Token');

    // Expose these response headers to the client
    header('Access-Control-Expose-Headers: X-Total-Count, X-Page, X-Page-Size, X-Total-Pages, X-CSRF-Token, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset');

    // Cache preflight response for 1 hour (3600 seconds)
    header('Access-Control-Max-Age: 3600');
}

/**
 * Handle preflight OPTIONS requests.
 * Call this at the top of your entry point to handle CORS preflight.
 */
function handlePreflightRequest(): void
{
    // Send CORS headers
    sendCorsHeaders();

    // If this is an OPTIONS preflight request, respond with 204 No Content and exit
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        header('Content-Length: 0');
        header('HTTP/1.1 204 No Content');
        exit;
    }
}

/**
 * Send CORS headers with a restricted origin for production.
 *
 * @param array $allowedOrigins Array of allowed origin URLs
 */
function sendCorsHeadersRestricted(array $allowedOrigins): void
{
    sendCorsHeaders($allowedOrigins);
}
