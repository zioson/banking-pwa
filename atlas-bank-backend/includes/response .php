<?php
/**
 * Atlas Bank Enterprise Operations Console
 * JSON Response Helpers
 *
 * Standardized API response formatting.
 */

/**
 * Send a generic JSON response with custom status code.
 *
 * @param mixed $data Response data
 * @param int   $code HTTP status code
 * @return void
 */
function jsonResponse($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Send a success response.
 *
 * @param mixed  $data    Response data
 * @param string $message Optional success message
 * @param int    $code    HTTP status code (default: 200)
 * @return void
 */
function successResponse($data = null, string $message = '', int $code = 200): void
{
    $response = [
        'success' => true
    ];

    if (!empty($message)) {
        $response['message'] = $message;
    }

    if ($data !== null) {
        $response['data'] = $data;
    }

    jsonResponse($response, $code);
}

/**
 * Send a success response with a message only (no data payload).
 *
 * @param string $message Success message
 * @param int    $code    HTTP status code (default: 200)
 * @return void
 */
function successMessage(string $message, int $code = 200): void
{
    jsonResponse([
        'success' => true,
        'message' => $message
    ], $code);
}

/**
 * Send a created response (201).
 *
 * @param mixed  $data    Created resource data
 * @param string $message Optional message
 * @return void
 */
function createdResponse($data = null, string $message = 'Resource created successfully'): void
{
    successResponse($data, $message, 201);
}

/**
 * Send an error response.
 *
 * @param string $message Error message
 * @param int    $code    HTTP status code (default: 400)
 * @param array  $extra   Optional extra data
 * @return void
 */
function errorResponse(string $message, int $code = 400, array $extra = []): void
{
    $response = [
        'success' => false,
        'error'   => $message
    ];

    if (!empty($extra)) {
        $response = array_merge($response, $extra);
    }

    jsonResponse($response, $code);
}

/**
 * Send a validation error response (422).
 *
 * @param array $errors Associative array of field => error message
 * @param string $message General error message
 * @return void
 */
function validationError(array $errors, string $message = 'Validation failed'): void
{
    // ── DEBUG: Log every validationError call with full backtrace ──
    error_log('[VALIDATION-ERROR-422] CALLED with ' . count($errors) . ' error(s):');
    error_log('[VALIDATION-ERROR-422] errors=' . json_encode($errors));
    error_log('[VALIDATION-ERROR-422] message=' . $message);
    // ★ FIX (FIN-2b-017): Guard debug backtrace with APP_DEBUG check — server file paths
    // and internal code structure should not leak into production error logs.
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        foreach ($backtrace as $i => $bt) {
            error_log("[VALIDATION-ERROR-422] backtrace[$i]: {$bt['file']}:{$bt['line']} ({$bt['function']})");
        }
    }
    jsonResponse([
        'success' => false,
        'error'   => $message,
        'errors'  => $errors
    ], 422);
}

/**
 * Send a not found response (404).
 *
 * @param string $message Error message
 * @return void
 */
function notFoundResponse(string $message = 'Resource not found'): void
{
    errorResponse($message, 404);
}

/**
 * Send an unauthorized response (401).
 *
 * @param string $message Error message
 * @return void
 */
function unauthorizedResponse(string $message = 'Authentication required'): void
{
    errorResponse($message, 401);
}

/**
 * Send a forbidden response (403).
 *
 * @param string $message Error message
 * @return void
 */
function forbiddenResponse(string $message = 'Access denied'): void
{
    errorResponse($message, 403);
}

/**
 * Send a paginated response with metadata.
 *
 * @param array $items    Array of items for the current page
 * @param int   $total    Total number of items across all pages
 * @param int   $page     Current page number (1-based)
 * @param int   $pageSize Number of items per page
 * @return void
 */
function paginatedResponse(array $items, int $total, int $page, int $pageSize): void
{
    $totalPages = max(1, (int)ceil($total / $pageSize));

    // Set pagination headers for consumers that read headers
    header('X-Total-Count: ' . $total);
    header('X-Page: ' . $page);
    header('X-Page-Size: ' . $pageSize);
    header('X-Total-Pages: ' . $totalPages);

    jsonResponse([
        'success' => true,
        'data'    => $items,
        'pagination' => [
            'page'       => $page,
            'pageSize'   => $pageSize,
            'total'      => $total,
            'totalPages' => $totalPages,
            'hasNext'    => $page < $totalPages,
            'hasPrev'    => $page > 1
        ]
    ]);
}

/**
 * Send a no content response (204).
 *
 * @return void
 */
function noContentResponse(): void
{
    http_response_code(204);
    header('Content-Type: application/json; charset=utf-8');
    exit;
}

/**
 * Send a server error response (500).
 *
 * @param string $message Error message
 * @return void
 */
function serverErrorResponse(string $message = 'Internal server error'): void
{
    errorResponse($message, 500);
}

/**
 * Send a conflict response (409).
 *
 * @param string $message Error message
 * @return void
 */
function conflictResponse(string $message = 'Resource conflict'): void
{
    errorResponse($message, 409);
}

/**
 * Send a rate limit response (429).
 *
 * @param string $message Error message
 * @param int    $retryAfter Seconds until the user can retry
 * @return void
 */
function rateLimitResponse(string $message = 'Too many requests', int $retryAfter = 60): void
{
    http_response_code(429);
    header('Retry-After: ' . $retryAfter);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => $message,
        'retry_after' => $retryAfter
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
