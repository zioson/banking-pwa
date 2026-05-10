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
 * Send CORS headers for the response.
 *
 * When credentials are needed (default), the allowed origin is set to the
 * request's Origin header (mirrored back). This satisfies the CORS spec
 * requirement that credentials + wildcard origin is forbidden.
 *
 * In development mode, all origins are permitted.
 * In production, restrict via $allowedOrigins.
 *
 * @param array|null $allowedOrigins Optional whitelist of allowed origins
 */
function sendCorsHeaders(?array $allowedOrigins = null): void
{
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

    // ★ FIX: If no explicit origins provided, build from environment
    if (empty($allowedOrigins)) {
        $envOrigins = getenv('CORS_ALLOWED_ORIGINS');
        if ($envOrigins !== false && $envOrigins !== '') {
            $allowedOrigins = array_map('trim', explode(',', $envOrigins));
        }
    }

    // Determine the origin to echo back
    if (empty($allowedOrigins)) {
        // ★ SECURITY FIX: Restrict origin mirroring even in development.
        // Previously, when no $allowedOrigins was passed (dev mode), ANY origin was
        // mirrored back with Allow-Credentials: true — allowing any website to make
        // authenticated cross-origin requests. Now we restrict to localhost variants.
        $safeDevOrigins = [
            'http://localhost',
            'http://127.0.0.1',
            'http://localhost:8000',
            'http://127.0.0.1:8000',
            'http://localhost:3000',
            'http://127.0.0.1:3000',
            'http://localhost:5173',
            'http://127.0.0.1:5173',
            'https://banking-pwa.onrender.com',
        ];
        if (in_array($requestOrigin, $safeDevOrigins, true)) {
            $origin = $requestOrigin;
        } else {
            $origin = '';
        }
    } elseif (in_array($requestOrigin, $allowedOrigins, true)) {
        // Production mode: only echo whitelisted origins
        $origin = $requestOrigin;
    } elseif (in_array('*', $allowedOrigins, true) && !empty($requestOrigin)) {
        // ★ FIX: When CORS_ALLOWED_ORIGINS=*, reflect the request origin back
        // (wildcard origin is forbidden with credentials, so we mirror instead)
        $origin = $requestOrigin;
    } else {
        // Origin not in whitelist — do not set Allow-Origin at all
        $origin = '';
    }

    // Set the origin (may be empty if not whitelisted in production)
    if (!empty($origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    // Allow credentials (cookies, HTTP auth, Authorization header)
    header('Access-Control-Allow-Credentials: true');

    // Allowed HTTP methods
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');

    // Allowed request headers
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Atlas-Session, X-HTTP-Method-Override, X-CSRF-Token, Accept, Origin, Cache-Control');

    // Expose these response headers to the client
    header('Access-Control-Expose-Headers: X-Total-Count, X-Page, X-Page-Size, X-Total-Pages, X-CSRF-Token');

    // Cache preflight response for 1 hour (3600 seconds)
    // Reduced from 86400 to allow origin changes to propagate faster
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
