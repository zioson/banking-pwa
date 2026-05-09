<?php
declare(strict_types=1);

namespace AtlasBank\Config;

class Cors
{
    /**
     * Allowed origins for CORS requests.
     * Read from environment variable; defaults to wildcard.
     *
     * @var array<int, string>
     */
    private static array $allowedOrigins = [];

    /**
     * Allowed HTTP methods for CORS preflight and actual requests.
     *
     * @var string
     */
    private static string $allowedMethods = 'GET, POST, PUT, DELETE, OPTIONS';

    /**
     * Allowed request headers.
     *
     * @var string
     */
    private static string $allowedHeaders = 'Content-Type, Authorization, X-Requested-With, X-API-Key, Accept, Origin';

    /**
     * Headers exposed to the client via Access-Control-Expose-Headers.
     *
     * @var string
     */
    private static string $exposedHeaders = 'X-Request-Id, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset';

    /**
     * Whether to allow credentials (cookies, HTTP auth, etc.).
     */
    private static bool $allowCredentials = true;

    /**
     * Preflight cache duration in seconds (1 hour).
     */
    private static int $maxAge = 3600;

    /**
     * Whether CORS headers have already been sent this request.
     */
    private static bool $headersSent = false;

    /**
     * Initialize CORS configuration from environment variables.
     * Call this once during application bootstrap.
     */
    public static function init(): void
    {
        $originEnv = getenv('CORS_ALLOWED_ORIGINS');

        if ($originEnv !== false && $originEnv !== '') {
            self::$allowedOrigins = array_map(
                'trim',
                explode(',', $originEnv)
            );
        }
    }

    /**
     * Send CORS headers for the current request.
     * Handles both preflight (OPTIONS) and normal requests.
     *
     * Returns true if the request should continue, false if it should be rejected.
     */
    public static function handle(): bool
    {
        if (self::$headersSent) {
            return true;
        }

        self::$headersSent = true;

        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '*';

        // Resolve the origin header value
        $originValue = self::resolveOrigin($requestOrigin);

        // Handle preflight OPTIONS request
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            self::sendHeaders($originValue);

            // Additional preflight-specific headers
            header('Access-Control-Max-Age: ' . self::$maxAge);
            header('Content-Length: 0');
            exit(0);
        }

        // Normal request — send CORS headers and continue
        self::sendHeaders($originValue);

        return true;
    }

    /**
     * Send all CORS response headers.
     */
    private static function sendHeaders(string $origin): void
    {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: ' . self::$allowedMethods);
        header('Access-Control-Allow-Headers: ' . self::$allowedHeaders);
        header('Access-Control-Expose-Headers: ' . self::$exposedHeaders);

        if (self::$allowCredentials) {
            header('Access-Control-Allow-Credentials: true');
        }

        // Vary header for proper caching when origin-specific
        if ($origin !== '*') {
            header('Vary: Origin');
        }
    }

    /**
     * Resolve the CORS origin for the response.
     * If the requesting origin is in the whitelist, reflect it back.
     * Otherwise, use the configured default or wildcard.
     */
    private static function resolveOrigin(string $requestOrigin): string
    {
        // No specific origins configured — allow all
        if (empty(self::$allowedOrigins)) {
            return '*';
        }

        // Normalize and check whitelist
        $normalized = strtolower(trim($requestOrigin));

        foreach (self::$allowedOrigins as $allowed) {
            if (strtolower(trim($allowed)) === $normalized) {
                return $requestOrigin;
            }
        }

        // Check for wildcard subdomain patterns like *.atlasbank.com
        foreach (self::$allowedOrigins as $allowed) {
            if (str_starts_with($allowed, '*.')) {
                $baseDomain = substr($allowed, 1); // e.g., .atlasbank.com
                if (str_ends_with($normalized, $baseDomain) || $normalized === substr($baseDomain, 1)) {
                    return $requestOrigin;
                }
            }
        }

        // Origin not in whitelist — deny
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Origin not allowed.',
        ], JSON_THROW_ON_ERROR);
        exit(1);
    }

    /**
     * Get the currently configured allowed origins.
     *
     * @return array<int, string>
     */
    public static function getAllowedOrigins(): array
    {
        return self::$allowedOrigins;
    }

    /**
     * Get the configured allowed methods string.
     */
    public static function getAllowedMethods(): string
    {
        return self::$allowedMethods;
    }

    /**
     * Get the configured allowed headers string.
     */
    public static function getAllowedHeaders(): string
    {
        return self::$allowedHeaders;
    }

    /**
     * Check if credentials support is enabled.
     */
    public static function hasCredentialsSupport(): bool
    {
        return self::$allowCredentials;
    }
}
