<?php
/**
 * Atlas Bank Enterprise Operations Console
 * CSRF (Cross-Site Request Forgery) Protection Middleware
 *
 * Generates a token per session, validates it on all mutating requests.
 * The token is delivered to the frontend via the login response and
 * the GET /api/auth session-check endpoint.
 *
 * Usage:
 *   - callCsrfCheck() at the top of each POST/PUT/DELETE handler
 *   - The frontend must send the token as X-CSRF-Token header
 *   - Token rotates after each successful mutation for double-submit safety
 */

/**
 * Ensure the PHP session is started with the correct session name.
 * Uses the ATLAS_BANK_SESSION name if defined, otherwise PHP default.
 *
 * @return bool True if session was started or already active
 */
function ensureCsrfSession(): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }

    // Set the session name if configured and not yet set.
    // session_name() must be called BEFORE session_start().
    // router.php bootstrap already calls session_name(), so this is
    // a safety net for code paths that bypass the router.
    if (defined('SESSION_NAME') && session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
    }

    return session_start();
}

/**
 * Generate a new CSRF token, store it in the PHP session, and return it.
 *
 * @return string The generated token (hex, 64 chars)
 */
function generateCsrfToken(): string
{
    ensureCsrfSession();

    $token = bin2hex(random_bytes(32));

    $_SESSION['csrf_token'] = $token;
    $_SESSION['csrf_token_time'] = time();

    return $token;
}

/**
 * Get the current CSRF token from the session (without generating a new one).
 *
 * @return string|null The current token, or null if none exists
 */
function getCsrfToken(): ?string
{
    ensureCsrfSession();

    return $_SESSION['csrf_token'] ?? null;
}

/**
 * Validate a CSRF token submitted by the client.
 *
 * Checks the X-CSRF-Token header first, then falls back to:
 *   - $_POST['_token']
 *   - $_GET['_token']
 *
 * If valid, the token is rotated (a new one is generated) for double-submit safety.
 *
 * @return string The new token (to be sent back to the client)
 * @throws \RuntimeException If token is missing or invalid
 */
function validateCsrfToken(): string
{
    ensureCsrfSession();

    // Read submitted token from header or request body.
    // ★ DEEP FIX (SEC-CSRF-001): Read token from JSON body if not in header/POST.
    // The frontend sends application/json, so $_POST is empty. If the token
    // is passed in the JSON payload as "_token", we must extract it via
    // getRequestInput() to avoid a false 403.
    $input = getRequestInput();
    $submitted = $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? ($input['_token'] ?? null)
        ?? ($_POST['_token'] ?? null)
        ?? '';

    // Strip whitespace and sanitize
    $submitted = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', trim($submitted));

    if (empty($submitted)) {
        error_log('[CSRF] Token missing from request. IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => 'CSRF token missing. Please reload the page and try again.'
        ]);
        exit;
    }

    $stored = $_SESSION['csrf_token'] ?? '';

    // Debug logging (only in development)
    if (defined('APP_DEBUG') && APP_DEBUG) {
        error_log('[CSRF] Submitted token: ' . substr($submitted, 0, 8) . '..., Stored token: ' . substr($stored, 0, 8) . '..., Session ID: ' . session_id());
    }

    // Timing-safe comparison
    if (!hash_equals($stored, $submitted)) {
        error_log('[CSRF] Token mismatch. Submitted: ' . substr($submitted, 0, 8) . '..., Expected: ' . substr($stored, 0, 8) . '...');
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => 'CSRF token validation failed. Please reload the page and try again.'
        ]);
        exit;
    }

    // Check token age (max 8 hours)
    $tokenTime = $_SESSION['csrf_token_time'] ?? 0;
    if ((time() - $tokenTime) > 28800) {
        error_log('[CSRF] Token expired. Age: ' . (time() - $tokenTime) . ' seconds.');
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => 'CSRF token expired. Please reload the page and try again.'
        ]);
        exit;
    }

    // Rotate token after successful validation
    $newToken = generateCsrfToken();

    return $newToken;
}

/**
 * Call this at the top of any endpoint that needs CSRF protection.
 *
 * Validates the submitted token and returns the new rotated token.
 * Returns null for GET/OPTIONS requests (no CSRF check needed).
 *
 * @param string $httpMethod The HTTP method of the current request
 * @return string|null The new rotated token (null if no check was performed)
 */
function callCsrfCheck(string $httpMethod): ?string
{
    // Only validate on mutating methods
    if (!in_array(strtoupper($httpMethod), ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        return null;
    }

    // Note: Auth is already exempted at the router level (it's in $publicResources).
    // This function is only called for authenticated resources that need CSRF protection.
    return validateCsrfToken();
}
