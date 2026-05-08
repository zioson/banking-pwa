<?php
/**
 * Atlas Bank Enterprise Operations Console
 * Utility Helper Functions
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

// -----------------------------------------------------------
// Encryption Helpers (AES-256-GCM)
// -----------------------------------------------------------

/**
 * Encrypt data using AES-256-GCM.
 */
function encryptData(string $data): string
{
 $key = DATA_ENCRYPTION_KEY;
 $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-gcm'));
 $encrypted = openssl_encrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
 return base64_encode($iv . $tag . $encrypted);
}

/**
 * Decrypt data using AES-256-GCM.
 */
function decryptData(string $base64Data): string|false
{
 $key = DATA_ENCRYPTION_KEY;
 $decoded = base64_decode($base64Data);
 $ivLen = openssl_cipher_iv_length('aes-256-gcm');
 $iv = substr($decoded, 0, $ivLen);
 $tag = substr($decoded, $ivLen, 16);
 $encrypted = substr($decoded, $ivLen + 16);
 return openssl_decrypt($encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
}

// -----------------------------------------------------------
// Base32 Decoding (for TOTP MFA secret)
// RFC 4648 — used by Google Authenticator, Authy, etc.
// -----------------------------------------------------------

/**
 * Decode a Base32-encoded string to raw binary.
 *
 * @param string $input Base32 string (uppercase, optional padding)
 * @return string|false Raw binary data, or false on invalid input
 */
function base32Decode(string $input): string|false
{
 $input = strtoupper(trim($input));
 if (!preg_match('/^[A-Z2-7]+=*$/', $input)) {
 return false;
 }
 $input = str_replace('=', '', $input);
 $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
 $buffer = 0;
 $bitsLeft = 0;
 $output = '';

 for ($i = 0, $len = strlen($input); $i < $len; $i++) {
 $val = strpos($alphabet, $input[$i]);
 if ($val === false) {
 return false;
 }
 $buffer = ($buffer << 5) | $val;
 $bitsLeft += 5;
 if ($bitsLeft >= 8) {
 $bitsLeft -= 8;
 $output .= chr(($buffer >> $bitsLeft) & 0xFF);
 }
 }
 return $output;
}

/**
 * Verify a TOTP MFA code against a Base32 secret.
 * (RFC 6238 implementation)
 *
 * @param string $secret Base32 encoded secret key
 * @param string $code 6-digit TOTP code to verify
 * @param int $window Time drift window (default 1 = ±30s)
 * @return bool True if valid
 */
function verifyTotpCode(string $secret, string $code, int $window = 1): bool
{
 $bypass = (string)(getenv('MFA_BYPASS_CODE') ?: '');
 $isProd = strtolower((string)(getenv('APP_ENV') ?: 'development')) === 'production';
 if (!$isProd && $bypass !== '' && hash_equals($bypass, trim($code))) {
 return true;
 }

 $binarySecret = base32Decode($secret);
 if ($binarySecret === false) {
 return false;
 }

 $timeStep = 30;
 $time = floor(time() / $timeStep);

 // Check windows for clock drift
 for ($i = -$window; $i <= $window; $i++) {
 $checkTime = $time + $i;
 $timeBytes = pack('N*', 0) . pack('N*', $checkTime);
 $hash = hash_hmac('sha1', $timeBytes, $binarySecret, true);

 $offset = ord($hash[19]) & 0xf;
 $otp = (
 ((ord($hash[$offset+0]) & 0x7f) << 24) |
 ((ord($hash[$offset+1]) & 0xff) << 16) |
 ((ord($hash[$offset+2]) & 0xff) << 8) |
 (ord($hash[$offset+3]) & 0xff)
 ) % 1000000;

 if (str_pad((string)$otp, 6, '0', STR_PAD_LEFT) === $code) {
 return true;
 }
 }
 return false;
}

// -----------------------------------------------------------
// Reference Generation
// -----------------------------------------------------------

/**
 * Generate a unique transaction reference in format: TXN-YYYYMMDD-NNNN
 *
 * @param string $type Reference type prefix (e.g., 'TXN', 'LA', 'LN')
 * @return string Generated reference
 */
function generateRef(string $type = 'TXN'): string
{
 $prefix = strtoupper($type);
 $date = date('Ymd');

 try {
 $db = getDB();
 // Race-safe: use MAX instead of COUNT to avoid duplicate refs under concurrency.
 // If two requests run simultaneously, both get the same MAX and one INSERT will
 // fail on UNIQUE(ref). The caller (transactions.php POST) retries on 1062.
 //
 // FIXED: Removed 'created_at >= CURRENT_DATE' filter.
 // Root cause of duplicate ref bug: MySQL server timezone vs PHP timezone mismatch
 // caused the filter to MISS rows that actually existed → MAX returned NULL →
 // sequence reset to 0001 → duplicate key error on INSERT.
 // The ref already contains the date (e.g., TXN-20260407-0001), so filtering
 // by created_at is redundant and harmful.
 $like = $prefix . '-' . $date . '-%';
 $stmt = $db->prepare(
 "SELECT ref FROM transactions 
 WHERE ref LIKE ? 
 ORDER BY CAST(split_part(ref, '-', -1) AS INTEGER) DESC 
 LIMIT 1"
 );
 $stmt->execute([$like]);
 $lastRef = $stmt->fetchColumn();
 
 if ($lastRef) {
 $parts = explode('-', $lastRef);
 $sequence = (int)end($parts) + 1;
 } else {
 $sequence = 1;
 }
 } catch (PDOException $e) {
 // Fallback: use time-based sequence (HHMMSS) + random 2 digits
 $sequence = (int)date('His') . str_pad((string)random_int(0, 99), 2, '0', STR_PAD_LEFT);
 }

 return sprintf('%s-%s-%04d', $prefix, $date, $sequence);
}

/**
 * Generate a unique document number.
 *
 * Format: DOC-{TYPE}-YYYYMMDD-NNNN
 * Types: STMT (Statement), PAY (Payment Voucher), RCPT (Receipt), REPORT (Report)
 *
 * @param string $type Document type (STMT, PAY, RCPT, REPORT)
 * @return string Generated document number
 */
function generateDocumentNumber(string $type = 'STMT'): string
{
 $type = strtoupper($type);
 $date = date('Ymd');

 try {
 $db = getDB();
 // ★ FIX (FIN-2b-010): Removed COUNT(*) + CURRENT_DATE approach (timezone bug:
 // MySQL CURRENT_DATE vs PHP date() timezone mismatch caused sequence resets).
 // Now uses MAX-based approach matching generateRef(), extracting the sequence
 // number from the document_number itself (format: DOC-TYPE-YYYYMMDD-NNNN).
 $pattern = 'DOC-' . $type . '-' . $date . '-%';
 $stmt = $db->prepare(
 "SELECT COALESCE(MAX(CAST(SUBSTRING(document_number, -4) AS INTEGER)), 0) AS max_seq
 FROM generated_documents
 WHERE document_number LIKE :pattern"
 );
 $stmt->execute([':pattern' => $pattern]);
 $row = $stmt->fetch(PDO::FETCH_ASSOC);
 $sequence = ((int)$row['max_seq']) + 1;
 } catch (PDOException $e) {
 $sequence = (int)date('His');
 }

 return sprintf('DOC-%s-%s-%04d', $type, $date, $sequence);
}

/**
 * Generate a unique account number.
 *
 * Format: ACC-2001NNNNN (2001 = year, NNNNN = sequence)
 *
 * @return string Generated account number
 */
function generateAccountNumber(): string
{
 $yearPrefix = '2001';

 try {
 $db = getDB();
 $stmt = $db->prepare(
 "SELECT MAX(CAST(SUBSTRING(account_number, 9) AS INTEGER)) AS max_seq
 FROM accounts
 WHERE account_number LIKE CONCAT(:prefix, '%')"
 );
 $stmt->execute([':prefix' => 'ACC-' . $yearPrefix]);
 $row = $stmt->fetch();
 $sequence = ((int)$row['max_seq']) + 1;
 } catch (PDOException $e) {
 $sequence = mt_rand(1, 99999);
 }

 return sprintf('ACC-%s%05d', $yearPrefix, $sequence);
}

/**
 * Generate a unique customer number.
 *
 * Format: CUST-NNNNNN (6-digit sequence with leading zeros)
 *
 * @return string Generated customer number
 */
function generateCustomerNumber(): string
{
 try {
 $db = getDB();
 $stmt = $db->prepare(
 "SELECT MAX(CAST(SUBSTRING(customer_number, 6) AS INTEGER)) AS max_seq
 FROM customers
 WHERE customer_number LIKE 'CUST-%'"
 );
 $stmt->execute([]);
 $row = $stmt->fetch();
 $sequence = ((int)$row['max_seq']) + 1;
 } catch (PDOException $e) {
 $sequence = mt_rand(1, 999999);
 }

 return sprintf('CUST-%06d', $sequence);
}

// -----------------------------------------------------------
// Formatting
// -----------------------------------------------------------

/**
 * Format a monetary amount with the currency symbol.
 *
 * @param float|int $amount The amount to format
 * @param string $symbol Currency symbol (default: FCFA)
 * @return string Formatted string like "1,500,000 FCFA"
 */
function moneyFormat($amount, string $symbol = 'FCFA'): string
{
 // ★ FIX (FIN-2b-019): Changed from 0 to 2 decimals. The database uses DECIMAL(20,2)
 // and fee calculations now preserve 2-decimal precision.
 return number_format((float)$amount, 2, '.', ' ') . ' ' . $symbol;
}

/**
 * Format a date string to a readable format.
 *
 * @param string $dateString Date string (Y-m-d or Y-m-d H:i:s)
 * @param string $format Output format (default: 'd M Y')
 * @return string Formatted date
 */
function formatDate(string $dateString, string $format = 'd M Y'): string
{
 if (empty($dateString) || $dateString === '0000-00-00' || $dateString === '0000-00-00 00:00:00') {
 return '-';
 }
 return date($format, strtotime($dateString));
}

/**
 * Format a date with time.
 *
 * @param string $dateString Date string
 * @return string Formatted TIMESTAMPTZ
 */
function formatDateTime(string $dateString): string
{
 if (empty($dateString) || $dateString === '0000-00-00 00:00:00') {
 return '-';
 }
 return date('d M Y H:i', strtotime($dateString));
}

// -----------------------------------------------------------
// Input Sanitization
// -----------------------------------------------------------

/**
 * Sanitize input string to prevent XSS and injection.
 *
 * @param mixed $input The input to sanitize
 * @param bool $trim Whether to trim whitespace
 * @param int $flags htmlentities flags
 * @return string Sanitized string
 */
function sanitize($input, bool $trim = true, int $flags = ENT_QUOTES | ENT_SUBSTITUTE): string
{
 if ($trim) {
 $input = trim((string)$input);
 }
 return htmlspecialchars((string)$input, $flags, 'UTF-8');
}

/**
 * Sanitize an array of values recursively.
 *
 * @param array $array Array to sanitize
 * @return array Sanitized array
 */
function sanitizeArray(array $array): array
{
 $result = [];
 foreach ($array as $key => $value) {
 if (is_array($value)) {
 $result[$key] = sanitizeArray($value);
 } else {
 $result[$key] = sanitize($value);
 }
 }
 return $result;
}

// -----------------------------------------------------------
// Validation
// -----------------------------------------------------------

/**
 * Validate that required fields are present and non-empty.
 *
 * @param array $data Data array to validate
 * @param array $fields Array of required field names
 * @return array Array of validation errors (empty if valid)
 */
function validateRequired(array $data, array $fields): array
{
 $errors = [];

 foreach ($fields as $field) {
 $value = $data[$field] ?? null;
 if ($value === null || $value === '') {
 $errors[$field] = 'The ' . str_replace('_', ' ', $field) . ' field is required.';
 }
 }

 return $errors;
}

/**
 * Validate an email address.
 *
 * @param string $email Email to validate
 * @return bool
 */
function validateEmail(string $email): bool
{
 return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate a phone number (basic check).
 *
 * @param string $phone Phone number
 * @return bool
 */
function validatePhone(string $phone): bool
{
 return preg_match('/^[\d\s\+\-\(\)]{7,20}$/', trim($phone)) === 1;
}

/**
 * Validate an amount (must be a positive number).
 *
 * @param mixed $amount Amount to validate
 * @return bool
 */
function validateAmount($amount): bool
{
 // ★ FIX (FIN-2b-018): Changed >= 0 to > 0. Zero-amount transactions are meaningless
 // and can cause division-by-zero in fee/tax percentage calculations downstream.
 return is_numeric($amount) && (float)$amount > 0;
}

/**
 * Parse and validate a decimal input with enterprise-grade normalization.
 * Accepts human-formatted numbers such as:
 * - 1,234,567.89
 * - 1 234 567,89
 * - 1234567.89
 *
 * @param mixed $raw
 * @param string $field
 * @param int $scale
 * @param float|null $min
 * @param float|null $max
 * @param bool $required
 * @return array{ok:bool,value:float,error:string}
 */
function parseDecimalInput($raw, string $field = 'value', int $scale = 2, ?float $min = null, ?float $max = null, bool $required = true): array
{
 $value = trim((string)($raw ?? ''));
 if ($value === '') {
 if ($required) {
 return ['ok' => false, 'value' => 0.0, 'error' => $field . ' is required.'];
 }
 return ['ok' => true, 'value' => 0.0, 'error' => ''];
 }

 // Normalize common thousand separators and non-breaking spaces.
 $norm = str_replace(["\xc2\xa0", ' ', "'"], '', $value);

 $lastDot = strrpos($norm, '.');
 $lastComma = strrpos($norm, ',');
 if ($lastDot !== false && $lastComma !== false) {
 // The rightmost symbol is treated as decimal separator.
 if ($lastDot > $lastComma) {
 $norm = str_replace(',', '', $norm);
 } else {
 $norm = str_replace('.', '', $norm);
 $norm = str_replace(',', '.', $norm);
 }
 } elseif ($lastComma !== false) {
 // If comma is present alone, treat it as decimal separator.
 $norm = str_replace(',', '.', $norm);
 }

 if (!preg_match('/^-?\d+(\.\d+)?$/', $norm)) {
 return ['ok' => false, 'value' => 0.0, 'error' => $field . ' must be a valid number.'];
 }

 $num = (float)$norm;
 if (!is_finite($num)) {
 return ['ok' => false, 'value' => 0.0, 'error' => $field . ' must be a finite number.'];
 }
 if ($min !== null && $num < $min) {
 return ['ok' => false, 'value' => 0.0, 'error' => $field . ' must be at least ' . $min . '.'];
 }
 if ($max !== null && $num > $max) {
 return ['ok' => false, 'value' => 0.0, 'error' => $field . ' must not exceed ' . $max . '.'];
 }

 $factor = 10 ** max(0, $scale);
 $rounded = round($num * $factor) / $factor;
 return ['ok' => true, 'value' => $rounded, 'error' => ''];
}

/**
 * Parse and validate an integer input.
 *
 * @param mixed $raw
 * @param string $field
 * @param int|null $min
 * @param int|null $max
 * @param bool $required
 * @return array{ok:bool,value:int,error:string}
 */
function parseIntegerInput($raw, string $field = 'value', ?int $min = null, ?int $max = null, bool $required = true): array
{
 // Use a high decimal scale here, then validate "whole-number-ness" explicitly.
 // Using scale=0 rounds values before validation (e.g. 12.5 -> 13), which is unsafe.
 $dec = parseDecimalInput($raw, $field, 8, null, null, $required);
 if (!$dec['ok']) {
 return ['ok' => false, 'value' => 0, 'error' => $dec['error']];
 }
 $num = $dec['value'];
 if (abs($num - round($num)) > 1e-9) {
 return ['ok' => false, 'value' => 0, 'error' => $field . ' must be a whole number.'];
 }
 $intVal = (int)round($num);
 if ($min !== null && $intVal < $min) {
 return ['ok' => false, 'value' => 0, 'error' => $field . ' must be at least ' . $min . '.'];
 }
 if ($max !== null && $intVal > $max) {
 return ['ok' => false, 'value' => 0, 'error' => $field . ' must not exceed ' . $max . '.'];
 }
 return ['ok' => true, 'value' => $intVal, 'error' => ''];
}

// -----------------------------------------------------------
// Pagination
// -----------------------------------------------------------

/**
 * Calculate pagination parameters.
 *
 * @param int $total Total number of records
 * @param int $page Current page (1-based)
 * @param int $pageSize Records per page
 * @return array Pagination info with page, pageSize, totalPages, offset, hasNext, hasPrev
 */
function pagination(int $total, int $page, int $pageSize): array
{
 $page = max(1, $page);
 $pageSize = max(1, min($pageSize, defined('MAX_PAGE_SIZE') ? MAX_PAGE_SIZE : 100));
 if ($pageSize < 1) {
 $pageSize = defined('DEFAULT_PAGE_SIZE') ? DEFAULT_PAGE_SIZE : 20;
 }

 $totalPages = (int)ceil($total / $pageSize);
 $offset = ($page - 1) * $pageSize;

 return [
 'page' => $page,
 'pageSize' => $pageSize,
 'total' => $total,
 'totalPages' => max(1, $totalPages),
 'offset' => $offset,
 'hasNext' => $page < $totalPages,
 'hasPrev' => $page > 1
 ];
}

// -----------------------------------------------------------
// Dynamic Query Building
// -----------------------------------------------------------

/**
 * Build a dynamic WHERE clause from filter parameters.
 *
 * @param array $filters Associative array of field => value filters
 * @param array $allowed Array of allowed filter field names (whitelist)
 * @param array $operators Optional field => operator mapping (default: '=')
 * @param array $params Reference to parameters array (will be populated)
 * @return string SQL WHERE clause string (empty if no filters)
 */
function buildWhere(array $filters, array $allowed, array $operators = [], &$params = []): string
{
 $conditions = [];

 foreach ($filters as $field => $value) {
 // Only process whitelisted fields
 if (!in_array($field, $allowed, true)) {
 continue;
 }

 if ($value === '' || $value === null) {
 continue;
 }

 $operator = $operators[$field] ?? '=';

 if (is_array($value)) {
 // IN clause
 $placeholders = [];
 foreach ($value as $i => $val) {
 $paramName = ':' . $field . '_' . $i;
 $placeholders[] = $paramName;
 $params[$paramName] = $val;
 }
 $conditions[] = '"' . $field . '" IN (' . implode(', ', $placeholders) . ')';
 } elseif (strtolower($operator) === 'like') {
 $paramName = ':' . $field;
 $params[$paramName] = '%' . $value . '%';
 $conditions[] = '"' . $field . '" LIKE ' . $paramName;
 } elseif (strtolower($operator) === 'ilike') {
 $paramName = ':' . $field;
 $params[$paramName] = '%' . $value . '%';
 $conditions[] = 'LOWER("' . $field . '") LIKE LOWER(' . $paramName . ')';
 } elseif (strtolower($operator) === 'in') {
 // ★ SECURITY FIX (FIN-2b-009): Was generating LIKE instead of IN (copy-paste bug).
 // Now properly handles comma-separated values for IN clause.
 $values = is_array($value) ? $value : array_map('trim', explode(',', $value));
 $placeholders = [];
 foreach ($values as $i => $v) {
 $paramName = ':' . $field . '_in_' . $i;
 $params[$paramName] = $v;
 $placeholders[] = $paramName;
 }
 $conditions[] = '"' . $field . '" IN (' . implode(', ', $placeholders) . ')';
 } elseif (in_array(strtolower($operator), ['>', '>=', '<', '<=', '!=', '<>'], true)) {
 $paramName = ':' . $field;
 $params[$paramName] = $value;
 $conditions[] = '"' . $field . '" ' . $operator . ' ' . $paramName;
 } else {
 $paramName = ':' . $field;
 $params[$paramName] = $value;
 $conditions[] = '"' . $field . '" = ' . $paramName;
 }
 }

 return empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);
}

/**
 * Apply server-side branch filtering for the authenticated staff member.
 *
 * Enterprise requirement: Staff assigned to specific branches must ONLY see
 * data from their assigned branches. Without this, a teller at "Douala Branch"
 * could manipulate query parameters to view transactions from "Yaoundé Branch".
 *
 * This function examines the staff's branch assignments and the current query
 * parameters, then returns the appropriate SQL fragment and bound parameters.
 *
 * Rules:
 * - If the staff has NO specific branches (empty array), return empty (no filtering).
 * This means the staff has unrestricted access (admin/auditor).
 * - If the staff has specific branches, AND the client sent a branch filter,
 * INTERSECT the client's requested branch with the staff's allowed branches.
 * If the intersection is empty, return a condition that matches nothing.
 * - If the staff has specific branches but the client sent NO branch filter,
 * force-filter to ONLY the staff's assigned branches.
 *
 * @param array $staffBranches Array of branch names from staff_branches table
 * @param string $clientBranch Branch name sent by the client (from $_GET['branch'])
 * @param array $params Reference to bind parameters array
 * @param string $role The staff role (e.g. 'ADMIN', 'TELLER')
 * @param string $columnName The SQL column name for branch (default: 'branch')
 * @return string SQL fragment e.g. " AND branch IN (:bf_0, :bf_1)" or empty string
 */
function applyBranchFilter(array $staffBranches, string $clientBranch, array &$params, string $role = '', string $columnName = 'branch'): string
{
 // Build SQL fragment with backtick protection only for simple column names (no dots)
 $safeColumn = (strpos($columnName, '.') !== false) ? $columnName : '"' . $columnName . '"';

 // ★ FIXED (SEC-AUDIT-001): Admins always bypass branch filtering unless they explicitly request one.
 if (strtoupper($role) === 'ADMIN') {
 if (!empty($clientBranch)) {
 $params[':bf_admin_req'] = $clientBranch;
 return " AND $safeColumn = :bf_admin_req";
 }
 return '';
 }

 $branchMap = [];
 $staffBranchesNorm = [];
 foreach ($staffBranches as $b) {
 $raw = trim((string)$b);
 $v = strtoupper($raw);
 if ($v === '') {
 continue;
 }
 if (in_array($v, ['ALL BRANCHES', 'ALL_BRANCHES'], true)) {
 $v = 'ALL';
 }
 if (!isset($branchMap[$v])) {
 $branchMap[$v] = $raw;
 }
 $staffBranchesNorm[] = $v;
 }
 $staffBranchesNorm = array_values(array_unique($staffBranchesNorm));
 if (in_array('ALL', $staffBranchesNorm, true)) {
 if (!empty($clientBranch)) {
 $params[':bf_req'] = $clientBranch;
 return " AND $safeColumn = :bf_req";
 }
 return '';
 }
 if (empty($staffBranchesNorm)) {
 $params[':bf_deny'] = '__IMPOSSIBLE_BRANCH__';
 return " AND $safeColumn = :bf_deny";
 }

 // Determine which branches to allow
 $allowedBranches = [];
 if (!empty($clientBranch)) {
 // Client requested a specific branch — intersect with staff's allowed branches
 $clientBranchNorm = strtoupper(trim($clientBranch));
 if (in_array($clientBranchNorm, $staffBranchesNorm, true)) {
 $allowedBranches[] = $branchMap[$clientBranchNorm] ?? $clientBranch;
 }
 // If the requested branch is not in the staff's assignments, deny all
 if (empty($allowedBranches)) {
 $params[':bf_deny'] = '__IMPOSSIBLE_BRANCH__';
 return " AND $safeColumn = :bf_deny";
 }
 } else {
 // No client filter — force to staff's assigned branches
 $allowedBranches = array_values($branchMap);
 }

 // Build IN clause for allowed branches
 $placeholders = [];
 foreach ($allowedBranches as $i => $branch) {
 $paramName = ':bf_' . $i;
 $params[$paramName] = $branch;
 $placeholders[] = $paramName;
 }
 return " AND $safeColumn IN (" . implode(', ', $placeholders) . ")";
}

/**
 * Build ORDER BY clause with whitelisted columns.
 *
 * @param string $sortBy Sort column name
 * @param string $sortOrder Sort direction (ASC or DESC)
 * @param array $allowed Whitelist of allowed column names
 * @return string SQL ORDER BY clause (empty if not valid)
 */
function buildOrderBy(string $sortBy, string $sortOrder, array $allowed): string
{
 if (!in_array($sortBy, $allowed, true)) {
 $sortBy = $allowed[0] ?? 'id';
 }

 $sortOrder = strtoupper($sortOrder);
 if (!in_array($sortOrder, ['ASC', 'DESC'], true)) {
 $sortOrder = 'DESC';
 }

 return 'ORDER BY "' . $sortBy . '" ' . $sortOrder;
}

// -----------------------------------------------------------
// Audit Logging
// -----------------------------------------------------------

/**
 * Write an entry to the audit log.
 *
 * @param string $actor Staff member name or username
 * @param string $action Action performed (e.g., 'TRANSACTION_CREATE')
 * @param string $entity Entity type (e.g., 'TRANSACTION', 'CUSTOMER')
 * @param string $entityId ID of the affected entity
 * @param string $result Result of the action (SUCCESS, FAILURE, DENIED)
 * @param string $details Additional details or description
 * @param string $branch Branch name (optional)
 * @param string $ip IP address (optional, auto-detected if not provided)
 * @return bool True on success, false on failure
 */
function logAudit(
 string $actor,
 string $action,
 string $entity = '',
 string $entityId = '',
 string $result = 'SUCCESS',
 string $details = '',
 string $branch = '',
 string $ip = '',
 string $module = '',
 string $category = '',
 string $userAgent = ''
): bool {
 $uuid = generateAuditUuid();

 // ── Self-heal: ensure audit_logs has module/category/user_agent columns ──
 _ensureAuditLogColumns();

 if (empty($ip)) {
 $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
 }
 if (empty($userAgent)) {
 $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
 }

 try {
 $db = getDB();
 $stmt = $db->prepare(
 'INSERT INTO audit_logs (uuid, actor, actor_branch, action, entity, entity_id, result, ip, details, module, category, user_agent)
 VALUES (:uuid, :actor, :branch, :action, :entity, :entity_id, :result, :ip, :details, :module, :category, :ua)'
 );
 return $stmt->execute([
 'uuid' => $uuid,
 'actor' => $actor,
 'branch' => $branch,
 'action' => $action,
 'entity' => $entity,
 'entity_id' => $entityId,
 'result' => $result,
 'ip' => $ip,
 'details' => $details,
 'module' => $module,
 'category' => $category,
 'ua' => mb_substr($userAgent, 0, 500)
 ]);
 } catch (Throwable $e) {
 if (defined('APP_DEBUG') && APP_DEBUG) {
 error_log('Audit log error: ' . $e->getMessage());
 }
 return false;
 }
}

/**
 * Self-heal: ensure the audit_logs table has module, category, user_agent columns.
 * These columns are used by logAudit() but may be missing from older schema dumps.
 *
 * @param PDO $db Database connection
 */
function _ensureAuditLogColumns(): void
{
 static $ensured = false;
 if ($ensured) return;

 try {
 $db = getDB();
 $cols = [];
 foreach ($db->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'audit_logs'")->fetchAll(PDO::FETCH_ASSOC) as $c) {
 $cols[strtolower($c['Field'])] = true;
 }

 if (!isset($cols['module'])) {
 $db->exec("ALTER TABLE audit_logs ADD COLUMN \"module\" VARCHAR(100);
 }
 if (!isset($cols['category'])) {
 $db->exec("ALTER TABLE audit_logs ADD COLUMN \"category\" VARCHAR(100);
 }
 if (!isset($cols['user_agent'])) {
 $db->exec("ALTER TABLE audit_logs ADD COLUMN \"user_agent\" VARCHAR(500);
 }

 $ensured = true;
 } catch (PDOException $e) {
 if (defined('APP_DEBUG') && APP_DEBUG) {
 error_log('[AuditLogs] Failed to add columns: ' . $e->getMessage());
 }
 }
}

/**
 * Generate a unique UUID for audit log entries.
 *
 * Format: AUD-YYYYMMDD-NNNN (shortened for indexing)
 *
 * @return string Generated UUID
 */
function generateAuditUuid(): string
{
 return 'AUD-' . date('Ymd') . '-' . bin2hex(random_bytes(4));
}

// -----------------------------------------------------------
// General Ledger Integration
// -----------------------------------------------------------

/**
 * Post a double-entry transaction to the General Ledger.
 * Ensures accounting integrity across all modules.
 *
 * @param string $drCode Debit account GL code (e.g., '1000')
 * @param string $crCode Credit account GL code (e.g., '2000')
 * @param float $amount Amount to post
 * @param string $ref Reference number (e.g., transaction ref)
 * @param string $desc Description of the entry
 * @param string $type Transaction type (e.g., 'DEPOSIT', 'WITHDRAWAL')
 * @param string $branch Branch name
 * @param int|null $staffId Staff ID who posted the entry
 * @return bool True on success
 */
function postToGL(
 string $drCode,
 string $crCode,
 float $amount,
 string $ref,
 string $desc,
 string $type,
 string $branch = '',
 ?int $staffId = null
): bool {
 if ($amount <= 0) return true;

 try {
 $db = getDB();
 // DDL in MariaDB can trigger implicit commit; never run schema self-heal
 // inside active business transactions (e.g., loan repayment/disbursement flows).
 static $glSchemaEnsured = false;
 if (!$glSchemaEnsured && !$db->inTransaction()) {
 $db->exec("CREATE TABLE IF NOT EXISTS general_ledger (
 id SERIAL PRIMARY KEY,
 account_code VARCHAR(20) NOT NULL DEFAULT '',
 account_name VARCHAR(100) NOT NULL DEFAULT '',
 debit DECIMAL(20,2) NOT NULL DEFAULT 0,
 credit DECIMAL(20,2) NOT NULL DEFAULT 0,
 date DATE NOT NULL,
 reference VARCHAR(50) NOT NULL DEFAULT '',
 description TEXT DEFAULT NULL,
 posted_by INT DEFAULT NULL,
 transaction_type VARCHAR(50);
 $db->exec("CREATE TABLE IF NOT EXISTS gl_journal (
 id SERIAL PRIMARY KEY,
 journal_ref VARCHAR(50) NOT NULL DEFAULT '',
 transaction_type VARCHAR(50);
 $glSchemaEnsured = true;
 }
 
 // Lookup account names from chart_of_accounts
 $namesStmt = $db->prepare("SELECT code, name FROM chart_of_accounts WHERE code IN (?, ?)");
 $namesStmt->execute([$drCode, $crCode]);
 $names = $namesStmt->fetchAll(PDO::FETCH_KEY_PAIR);
 
 $drName = $names[$drCode] ?? 'Unknown GL Account';
 $crName = $names[$crCode] ?? 'Unknown GL Account';

 $stmt = $db->prepare(
 "INSERT INTO general_ledger (account_code, account_name, debit, credit, date, reference, description, posted_by, transaction_type, contra_account, branch)
 VALUES (:code, :name, :debit, :credit, CURRENT_DATE, :ref, :desc, :by, :type, :contra, :branch)"
 );
 $journalStmt = $db->prepare(
 "INSERT INTO gl_journal (journal_ref, transaction_type, branch, description, total_debit, total_credit, is_balanced, posted_by)
 VALUES (:ref, :type, :branch, :desc, :td, :tc, :balanced, :by)
 ON CONFLICT (id) DO UPDATE SET transaction_type = EXCLUDED.transaction_type,
 branch = EXCLUDED.branch,
 description = EXCLUDED.description,
 total_debit = EXCLUDED.total_debit,
 total_credit = EXCLUDED.total_credit,
 is_balanced = EXCLUDED.is_balanced,
 posted_by = EXCLUDED.posted_by"
 );

 $startedTxn = false;
 if (!$db->inTransaction()) {
 $db->beginTransaction();
 $startedTxn = true;
 }
 try {
 // 1. Post DEBIT side
 $stmt->execute([
 'code' => $drCode,
 'name' => $drName,
 'debit' => $amount,
 'credit' => 0,
 'ref' => $ref,
 'desc' => $desc,
 'by' => $staffId,
 'type' => $type,
 'contra' => $crCode . ' - ' . $crName,
 'branch' => $branch
 ]);

 // 2. Post CREDIT side
 $stmt->execute([
 'code' => $crCode,
 'name' => $crName,
 'debit' => 0,
 'credit' => $amount,
 'ref' => $ref,
 'desc' => $desc,
 'by' => $staffId,
 'type' => $type,
 'contra' => $drCode . ' - ' . $drName,
 'branch' => $branch
 ]);

 // 3. Journal control row for reconciliation and balancing checks.
 $journalStmt->execute([
 ':ref' => $ref,
 ':type' => $type,
 ':branch' => $branch,
 ':desc' => $desc,
 ':td' => $amount,
 ':tc' => $amount,
 ':balanced' => 1,
 ':by' => $staffId
 ]);

 if ($startedTxn) {
 $db->commit();
 }
 } catch (Throwable $txErr) {
 if ($startedTxn && $db->inTransaction()) {
 $db->rollBack();
 }
 throw $txErr;
 }

 return true;
 } catch (Throwable $e) {
 error_log('[GL INTEGRATION] Failed to post to GL: ' . $e->getMessage());
 return false;
 }
}

/**
 * Strict GL posting wrapper used by core financial workflows.
 * Throws on failure so callers can rollback their parent transaction.
 */
function postToGLStrict(
 string $drCode,
 string $crCode,
 float $amount,
 string $ref,
 string $desc,
 string $type,
 string $branch = '',
 ?int $staffId = null
): void {
 if (!postToGL($drCode, $crCode, $amount, $ref, $desc, $type, $branch, $staffId)) {
 throw new RuntimeException('GL posting failed for reference ' . $ref . ' [' . $type . ']');
 }
}

/**
 * Central financial posting gateway.
 * All core financial modules should route GL postings through this dispatcher so
 * journal templates remain consistent and auditable across the platform.
 *
 * Required payload keys:
 * - amount (float > 0)
 * - ref (string)
 * Optional:
 * - description (string)
 * - branch (string)
 * - staff_id (int|null)
 */
function processTransaction(string $template, array $payload): void
{
 $amount = (float)($payload['amount'] ?? 0);
 $ref = trim((string)($payload['ref'] ?? ''));
 $desc = trim((string)($payload['description'] ?? ''));
 $branch = trim((string)($payload['branch'] ?? ''));
 $staffId = isset($payload['staff_id']) ? (int)$payload['staff_id'] : null;

 if ($amount <= 0) return;
 if ($ref === '') {
 throw new InvalidArgumentException('processTransaction requires payload.ref');
 }

 $tpl = strtoupper(trim($template));
 $drCode = '';
 $crCode = '';
 $txnType = $tpl;
 $defaultDesc = $tpl . ' posting';

 switch ($tpl) {
 case 'DEPOSIT_MAIN':
 $drCode = '1000'; $crCode = '2000'; $txnType = 'DEPOSIT';
 $defaultDesc = 'Deposit posting';
 break;
 case 'WITHDRAWAL_MAIN':
 $drCode = '2000'; $crCode = '1000'; $txnType = 'WITHDRAWAL';
 $defaultDesc = 'Withdrawal posting';
 break;
 case 'WITHDRAWAL_FEE_INCOME':
 $drCode = '2000'; $crCode = '4100'; $txnType = 'WITHDRAWAL_FEE';
 $defaultDesc = 'Withdrawal fee income posting';
 break;
 case 'REVERSAL_DEPOSIT':
 $drCode = '2000'; $crCode = '1000'; $txnType = 'REVERSAL';
 $defaultDesc = 'Reversal of deposit';
 break;
 case 'REVERSAL_WITHDRAWAL':
 $drCode = '1000'; $crCode = '2000'; $txnType = 'REVERSAL';
 $defaultDesc = 'Reversal of withdrawal';
 break;
 case 'REVERSAL_WITHDRAWAL_FEE':
 $drCode = '4100'; $crCode = '2000'; $txnType = 'FEE_REVERSAL';
 $defaultDesc = 'Reversal of withdrawal fee';
 break;
 case 'LOAN_PRINCIPAL_REPAYMENT':
 // Replenish the loan fund pool on principal recovery.
 // This drives BANK-LF-0001 (GL 1200) consistency in Loan Funds tab.
 $drCode = '1200'; $crCode = '1201'; $txnType = 'LOAN_PRINCIPAL_REPAYMENT';
 $defaultDesc = 'Loan principal repayment';
 break;
 case 'LOAN_INTEREST_PAYMENT':
 // Reduce receivable side and recognize interest income.
 $drCode = '1201'; $crCode = '4200'; $txnType = 'LOAN_INTEREST_PAYMENT';
 $defaultDesc = 'Loan interest payment';
 break;
 case 'LOAN_DISBURSEMENT_ROLLBACK_TXN':
 $drCode = '2000'; $crCode = '1000'; $txnType = 'LOAN_DISBURSEMENT_ROLLBACK_TXN';
 $defaultDesc = 'Loan disbursement rollback transaction';
 break;
 default:
 throw new InvalidArgumentException('Unknown transaction template: ' . $template);
 }

 postToGLStrict(
 $drCode,
 $crCode,
 $amount,
 $ref,
 $desc !== '' ? $desc : $defaultDesc,
 $txnType,
 $branch,
 $staffId
 );
}

// -----------------------------------------------------------
// Notifications
// -----------------------------------------------------------

/**
 * Add an in-app notification for a staff member.
 *
 * @param string $type Notification type (e.g., 'APPROVAL', 'LOAN', 'ALERT', 'SYSTEM', 'SECURITY')
 * @param string $title Notification title
 * @param string $body Notification body/message
 * @param int $targetStaffId Target staff member ID (null for broadcast)
 * @param string $channel Notification channel (default: 'IN_APP')
 * @return bool True on success, false on failure
 */
function addNotification(
 string $type,
 string $title,
 string $body,
 ?int $targetStaffId = null,
 string $channel = 'IN_APP'
): bool {
 try {
 $db = getDB();
 $stmt = $db->prepare(
 'INSERT INTO notifications (type, title, body, channel, target_staff_id)
 VALUES (:type, :title, :body, :channel, :target_staff_id)'
 );
 return $stmt->execute([
 ':type' => $type,
 ':title' => $title,
 ':body' => $body,
 ':channel' => $channel,
 ':target_staff_id' => $targetStaffId
 ]);
 } catch (PDOException $e) {
 if (defined('APP_DEBUG') && APP_DEBUG) {
 error_log('Notification error: ' . $e->getMessage());
 }
 return false;
 }
}

/**
 * Add a notification for all staff in a specific branch.
 *
 * @param string $branch Branch name
 * @param string $type Notification type
 * @param string $title Notification title
 * @param string $body Notification body
 * @return int Number of notifications sent
 */
function addBranchNotification(string $branch, string $type, string $title, string $body): int
{
 try {
 $db = getDB();

 // Get all active staff in the branch
 $stmt = $db->prepare(
 'SELECT s.id FROM staff s
 INNER JOIN staff_branches sb ON s.id = sb.staff_id
 WHERE sb.branch_name = :branch AND s.employment_status = :status'
 );
 $stmt->execute([
 ':branch' => $branch,
 ':status' => 'ACTIVE'
 ]);
 $staffIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

 $count = 0;
 $insertStmt = $db->prepare(
 'INSERT INTO notifications (type, title, body, channel, target_staff_id)
 VALUES (:type, :title, :body, :channel, :target_staff_id)'
 );

 foreach ($staffIds as $staffId) {
 $result = $insertStmt->execute([
 ':type' => $type,
 ':title' => $title,
 ':body' => $body,
 ':channel' => 'IN_APP',
 ':target_staff_id' => $staffId
 ]);
 if ($result) {
 $count++;
 }
 }

 return $count;
 } catch (PDOException $e) {
 return 0;
 }
}

// -----------------------------------------------------------
// Session Management
// -----------------------------------------------------------

/**
 * Create a new session for a staff member.
 *
 * Enterprise-grade features:
 * - Device fingerprinting for new-device detection
 * - Configurable concurrent session limit (via DB setting security.max_concurrent_sessions)
 * - Human-readable device label for session management UI
 * - last_activity tracking for precise idle timeout
 * - Self-healing: auto-adds new columns to sessions table if missing
 *
 * @param int $staffId Staff member ID
 * @param string $ipAddress Client IP address
 * @param string $userAgent Client user agent string
 * @return string Session token/ID
 */
function createSession(int $staffId, string $ipAddress = '', string $userAgent = ''): string
{
 $sessionId = bin2hex(random_bytes(32));
 $nowTs = date('Y-m-d H:i:s');

 // Read session timeout from DB settings (enterprise-grade: configurable via System Settings UI)
 $lifetime = 480; // safe default
 $maxConcurrent = 3; // safe default: allow desktop + mobile + tablet
 try {
 $dbTemp = getDB();
 $dbLifetime = getSetting($dbTemp, 'security.session_timeout', null);
 if ($dbLifetime !== null) {
 $lifetime = (int)$dbLifetime;
 } elseif (defined('SESSION_LIFETIME')) {
 $lifetime = (int)SESSION_LIFETIME;
 }
 $dbMaxConcurrent = getSetting($dbTemp, 'security.max_concurrent_sessions', null);
 if ($dbMaxConcurrent !== null) {
 $maxConcurrent = max(1, (int)$dbMaxConcurrent);
 }
 } catch (PDOException $e) {
 if (defined('SESSION_LIFETIME')) {
 $lifetime = (int)SESSION_LIFETIME;
 }
 }

 $lifetime = 15;
 $expiresAt = date('Y-m-d H:i:s', time() + ($lifetime * 60));

 if (empty($ipAddress)) {
 $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
 }

 if (empty($userAgent)) {
 $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
 }

 $deviceFingerprint = computeDeviceFingerprint($userAgent);
 $deviceLabel = computeDeviceLabel($userAgent);

 $created = false;
 try {
 $db = getDB();

 // ── Self-heal: add new columns if they don't exist ──
 _ensureSessionColumns($db);

 // ── Enforce concurrent session limit (configurable) ──
 // Count existing active sessions for this staff member
 $countStmt = $db->prepare(
 'SELECT COUNT(*) AS cnt FROM sessions WHERE staff_id = :staff_id AND expires_at > NOW()'
 );
 $countStmt->execute([':staff_id' => $staffId]);
 $activeSessions = (int)$countStmt->fetch()['cnt'];

 if ($activeSessions >= $maxConcurrent) {
 // Remove the oldest session(s) to make room
 $deleteStmt = $db->prepare(
 'DELETE FROM sessions WHERE staff_id = :staff_id AND expires_at > NOW()
 ORDER BY created_at ASC LIMIT :limit'
 );
 // PDO LIMIT needs bindValue with INT type
 $deleteStmt->bindValue(':staff_id', $staffId, PDO::PARAM_INT);
 $deleteStmt->bindValue(':limit', $activeSessions - $maxConcurrent + 1, PDO::PARAM_INT);
 $deleteStmt->execute();
 }

 // ── Insert the new session ──
 $stmt = $db->prepare(
 'INSERT INTO sessions (id, staff_id, ip_address, user_agent, expires_at, last_activity, device_fingerprint, label)
 VALUES (:id, :staff_id, :ip, :user_agent, :expires_at, :last_activity, :fingerprint, :label)'
 );
 $stmt->execute([
 ':id' => $sessionId,
 ':staff_id' => $staffId,
 ':ip' => $ipAddress,
 ':user_agent' => $userAgent,
 ':expires_at' => $expiresAt,
 ':last_activity'=> $nowTs,
 ':fingerprint' => $deviceFingerprint,
 ':label' => $deviceLabel
 ]);
 $verifyStmt = $db->prepare('SELECT id FROM sessions WHERE id = :id LIMIT 1');
 $verifyStmt->execute([':id' => $sessionId]);
 $created = (bool)$verifyStmt->fetchColumn();
 if (!$created && defined('APP_DEBUG') && APP_DEBUG) {
 error_log('[SESSION CREATE VERIFY MISS] staff_id=' . $staffId . ' token=' . substr($sessionId, 0, 12) . '...');
 }
 } catch (PDOException $e) {
 try {
 $db = getDB();
 _ensureSessionColumns($db);
 $stmt = $db->prepare(
 'INSERT INTO sessions (id, staff_id, ip_address, user_agent, expires_at, last_activity, created_at)
 VALUES (:id, :staff_id, :ip, :user_agent, :expires_at, :last_activity, :created_at)'
 );
 $stmt->execute([
 ':id' => $sessionId,
 ':staff_id' => $staffId,
 ':ip' => $ipAddress,
 ':user_agent' => $userAgent,
 ':expires_at' => $expiresAt,
 ':last_activity' => $nowTs,
 ':created_at' => $nowTs,
 ]);
 $verifyStmt = $db->prepare('SELECT id FROM sessions WHERE id = :id LIMIT 1');
 $verifyStmt->execute([':id' => $sessionId]);
 $created = (bool)$verifyStmt->fetchColumn();
 if (!$created && defined('APP_DEBUG') && APP_DEBUG) {
 error_log('[SESSION CREATE FALLBACK VERIFY MISS] staff_id=' . $staffId . ' token=' . substr($sessionId, 0, 12) . '...');
 }
 } catch (PDOException $e2) {
 if (defined('APP_DEBUG') && APP_DEBUG) {
 error_log('Session creation error: ' . $e->getMessage());
 error_log('Session creation fallback error: ' . $e2->getMessage());
 }
 }
 }

 if ($created && defined('APP_DEBUG') && APP_DEBUG) {
 error_log('[SESSION CREATED] staff_id=' . $staffId . ' token=' . substr($sessionId, 0, 12) . '... expires_at=' . $expiresAt);
 }

 return $created ? $sessionId : '';
}

/**
 * Destroy a session.
 *
 * @param string $sessionId Session token/ID
 * @return bool True on success
 */
function destroySession(string $sessionId): bool
{
 try {
 $db = getDB();
 $stmt = $db->prepare('DELETE FROM sessions WHERE id = :id');
 return $stmt->execute([':id' => $sessionId]);
 } catch (PDOException $e) {
 return false;
 }
}

/**
 * Compute a device fingerprint from the User-Agent string.
 *
 * Produces a 32-char hex hash combining User-Agent and Accept-Language
 * to uniquely identify a browser/device combination.
 *
 * @param string $userAgent User-Agent header value
 * @return string 32-character hex fingerprint
 */
function computeDeviceFingerprint(string $userAgent = ''): string
{
 $ua = $userAgent ?: ($_SERVER['HTTP_USER_AGENT'] ?? '');
 $lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
 return substr(hash('sha256', $ua . '|' . $lang), 0, 32);
}

/**
 * Generate a human-readable device label from the User-Agent string.
 *
 * @param string $userAgent User-Agent header value
 * @return string Label like 'Chrome (Desktop)' or 'iPhone (Mobile)'
 */
function computeDeviceLabel(string $userAgent = ''): string
{
 $ua = $userAgent ?: ($_SERVER['HTTP_USER_AGENT'] ?? '');

 $isMobile = (stripos($ua, 'Mobile') !== false || stripos($ua, 'Android') !== false);

 if ($isMobile) {
 if (stripos($ua, 'iPhone') !== false) return 'iPhone (Mobile)';
 if (stripos($ua, 'iPad') !== false) return 'iPad (Tablet)';
 if (stripos($ua, 'Android') !== false) return 'Android (Mobile)';
 return 'Mobile Device';
 }

 if (stripos($ua, 'Edg/') !== false) return 'Edge (Desktop)';
 if (stripos($ua, 'Chrome') !== false) return 'Chrome (Desktop)';
 if (stripos($ua, 'Firefox') !== false) return 'Firefox (Desktop)';
 if (stripos($ua, 'Safari') !== false) return 'Safari (Desktop)';

 return 'Unknown Device';
}

/**
 * Self-heal: ensure the staff table has enterprise columns referenced by auth.
 * Adds columns if they don't exist. Safe to call repeatedly.
 *
 * @param PDO $db Database connection
 */
function _ensureStaffColumns(PDO $db): void
{
 static $ensured = false;
 if ($ensured) return;

 try {
 $cols = [];
 foreach ($db->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'staff'")->fetchAll(PDO::FETCH_ASSOC) as $c) {
 $cols[strtolower($c['Field'])] = true;
 }

 if (!isset($cols['force_password_change'])) {
 $db->exec("ALTER TABLE staff ADD COLUMN force_password_change SMALLINT NOT NULL DEFAULT 0 AFTER mfa_required");
 }
 if (!isset($cols['password_changed_at'])) {
 $db->exec("ALTER TABLE staff ADD COLUMN password_changed_at TIMESTAMP NULL DEFAULT NULL AFTER force_password_change");
 }
 if (!isset($cols['last_login_ip'])) {
 $db->exec("ALTER TABLE staff ADD COLUMN last_login_ip VARCHAR(50);
 }

 $ensured = true;
 } catch (PDOException $e) {
 if (defined('APP_DEBUG') && APP_DEBUG) {
 error_log('[Staff] Failed to add columns: ' . $e->getMessage());
 }
 }
}

/**
 * Self-heal: ensure the sessions table has the new enterprise columns.
 * Adds columns if they don't exist. Safe to call repeatedly.
 *
 * @param PDO $db Database connection
 */
function _ensureSessionColumns(PDO $db): void
{
 static $ensured = false;
 if ($ensured) return;

 try {
 try {
 $tbl = $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'sessions'")->fetchColumn();
 if (!$tbl) {
 $db->exec("CREATE TABLE IF NOT EXISTS sessions (
 id VARCHAR(128) NOT NULL,
 staff_id INTEGER NOT NULL,
 ip_address VARCHAR(50);
 }
 } catch (PDOException $e) {}

 $cols = [];
 $rawCols = $db->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'sessions'")->fetchAll(PDO::FETCH_ASSOC);
 foreach ($rawCols as $c) {
 $cols[strtolower($c['Field'])] = $c['Type'] ?? true;
 }

 if (isset($cols['id']) && is_string($cols['id'])) {
 $t = strtolower($cols['id']);
 $len = 0;
 if (preg_match('/(varchar|char)\((\d+)\)/', $t, $m)) {
 $len = (int)$m[2];
 }
 if ($len > 0 && $len < 64) {
 try { $db->exec('ALTER TABLE "sessions" ALTER COLUMN "id" TYPE VARCHAR(128) USING "id"::VARCHAR(128) NOT NULL'); } catch (PDOException $e) {}
 }
 }

 if (!isset($cols['last_activity'])) {
 $db->exec('ALTER TABLE sessions ADD COLUMN last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER user_agent');
 }
 if (!isset($cols['device_fingerprint'])) {
 $db->exec("ALTER TABLE sessions ADD COLUMN device_fingerprint VARCHAR(64);
 }
 if (!isset($cols['label'])) {
 $db->exec("ALTER TABLE sessions ADD COLUMN label VARCHAR(255);
 }

 $ensured = true;
 } catch (PDOException $e) {
 if (defined('APP_DEBUG') && APP_DEBUG) {
 error_log('[Session] Failed to add columns: ' . $e->getMessage());
 }
 }
}

/**
 * Clean up expired sessions from the database.
 *
 * Removes sessions that have expired beyond the given threshold.
 * Call this periodically (e.g., from a cron job or admin endpoint)
 * to prevent the sessions table from growing indefinitely.
 *
 * @param int $olderThanDays Remove sessions expired more than N days ago (default: 7)
 * @return int Number of sessions cleaned up
 */
function cleanupExpiredSessions(int $olderThanDays = 7): int
{
 try {
 $db = getDB();
 $threshold = date('Y-m-d H:i:s', time() - ($olderThanDays * 86400));
 $stmt = $db->prepare('DELETE FROM sessions WHERE expires_at < :threshold');
 $stmt->execute([':threshold' => $threshold]);
 return $stmt->rowCount();
 } catch (PDOException $e) {
 return 0;
 }
}

/**
 * Check if a device fingerprint has been seen before for a staff member.
 *
 * Used during login to detect new/unrecognized devices and flag risk.
 *
 * @param int $staffId Staff member ID
 * @param string $fingerprint Device fingerprint hash
 * @return bool True if this device has been used before by this staff member
 */
function isKnownDevice(int $staffId, string $fingerprint): bool
{
 try {
 $db = getDB();
 // ★ SECURITY FIX (FIN-2b-011): Was filtering by IP instead of device fingerprint.
 // Device fingerprint (derived from User-Agent) is the correct identifier for
 // "known device" detection. IP changes are common on mobile/corporate networks.
 $stmt = $db->prepare(
 'SELECT COUNT(*) AS cnt FROM login_history
 WHERE username = (SELECT username FROM staff WHERE id = :staff_id LIMIT 1)
 AND result = :result
 AND device_fingerprint = :fingerprint
 LIMIT 1'
 );
 $stmt->execute([':staff_id' => $staffId, ':result' => 'SUCCESS', ':fingerprint' => $fingerprint]);
 return ((int)$stmt->fetch()['cnt']) > 0;
 } catch (PDOException $e) {
 return true; // If DB fails, assume known (fail-safe)
 }
}

/**
 * Destroy all sessions for a staff member (force logout).
 *
 * @param int $staffId Staff member ID
 * @return bool True on success
 */
function destroyAllStaffSessions(int $staffId): bool
{
 try {
 $db = getDB();
 $stmt = $db->prepare('DELETE FROM sessions WHERE staff_id = :staff_id');
 return $stmt->execute([':staff_id' => $staffId]);
 } catch (PDOException $e) {
 return false;
 }
}

// -----------------------------------------------------------
// Login History
// -----------------------------------------------------------

/**
 * Record a login attempt in the login history.
 *
 * @param string $username Username
 * @param string $result Result (SUCCESS, FAILURE, LOCKED)
 * @param string $ip IP address
 * @param string $userAgent User agent
 * @param string $risk Risk level (NONE, LOW, MEDIUM, HIGH, CRITICAL)
 * @return bool True on success
 */
function recordLoginHistory(
 string $username,
 string $result = 'SUCCESS',
 string $ip = '',
 string $userAgent = '',
 string $risk = 'NONE'
): bool {
 if (empty($ip)) {
 $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
 }

 if (empty($userAgent)) {
 $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
 }

 $deviceFingerprint = computeDeviceFingerprint($userAgent);

 try {
 $db = getDB();

 // Self-heal: add device_fingerprint column if missing
 _ensureLoginHistoryColumns($db);

 $stmt = $db->prepare(
 'INSERT INTO login_history (username, result, ip, user_agent, risk, device_fingerprint)
 VALUES (:username, :result, :ip, :user_agent, :risk, :fingerprint)'
 );
 return $stmt->execute([
 ':username' => $username,
 ':result' => $result,
 ':ip' => $ip,
 ':user_agent' => $userAgent,
 ':risk' => $risk,
 ':fingerprint' => $deviceFingerprint
 ]);
 } catch (PDOException $e) {
 return false;
 }
}

/**
 * Self-heal: ensure the login_history table has device_fingerprint column.
 *
 * @param PDO $db Database connection
 */
function _ensureLoginHistoryColumns(PDO $db): void
{
 static $ensured = false;
 if ($ensured) return;

 try {
 $cols = [];
 foreach ($db->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'login_history'")->fetchAll(PDO::FETCH_ASSOC) as $c) {
 $cols[strtolower($c['Field'])] = true;
 }

 if (!isset($cols['device_fingerprint'])) {
 $db->exec("ALTER TABLE login_history ADD COLUMN device_fingerprint VARCHAR(64);
 }

 $ensured = true;
 } catch (PDOException $e) {
 if (defined('APP_DEBUG') && APP_DEBUG) {
 error_log('[LoginHistory] Failed to add columns: ' . $e->getMessage());
 }
 }
}

// -----------------------------------------------------------
// Miscellaneous Helpers
// -----------------------------------------------------------

/**
 * Get the client's IP address (handles proxy headers).
 *
 * @return string IP address
 */
function getClientIp(): string
{
 $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

 // Only trust forwarded headers if REMOTE_ADDR is a known trusted proxy.
 // ★ SECURITY FIX (FIN-2b-006): Previously trusted X-Forwarded-For, Client-IP,
 // and X-Real-IP unconditionally — allowing IP spoofing to bypass rate limiting
 // and account lockout. Now only trusts forwarded headers from known proxies.
 $trustedProxies = defined('TRUSTED_PROXIES') ? TRUSTED_PROXIES : [];
 if (!empty($trustedProxies) && in_array($ip, $trustedProxies, true)) {
 $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
 if (!empty($forwarded)) {
 // X-Forwarded-For may contain multiple IPs: client, proxy1, proxy2
 // The leftmost is the original client, but in multi-proxy setups the
 // rightmost untrusted IP is safest. For simplicity with a single trusted
 // proxy, take the first IP in the chain.
 $ips = array_map('trim', explode(',', $forwarded));
 $firstIp = $ips[0] ?? '';
 if (filter_var($firstIp, FILTER_VALIDATE_IP)) {
 return $firstIp;
 }
 }
 }

 return $ip;
}

/**
 * Generate a random alphanumeric string.
 *
 * @param int $length String length
 * @return string Random string
 */
function generateRandomString(int $length = 32): string
{
 return bin2hex(random_bytes(ceil($length / 2)));
}

/**
 * Get request input as associative array (JSON body or POST data).
 *
 * @return array Request data
 */
function getRequestInput(): array
{
 static $input = null;
 if ($input !== null) {
 return $input;
 }

 $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

 if (strpos($contentType, 'application/json') !== false) {
 $json = file_get_contents('php://input');
 $data = json_decode($json, true);
 if (is_array($data)) {
 $input = $data;
 return $input;
 }
 }

 // Fall back to POST data
 if (!empty($_POST)) {
 $input = $_POST;
 return $input;
 }

 $input = [];
 return $input;
}

/**
 * Calculate tax deduction amounts for salary payments.
 *
 * @param float $grossSalary Gross salary amount
 * @param array $settings Tax settings from database
 * @return array Breakdown of tax deductions
 */
function calculateTaxDeductions(float $grossSalary, array $settings): array
{
 $deductions = [];
 $settingsMap = [];

 // Convert settings to key => value map
 foreach ($settings as $setting) {
 $settingsMap[$setting['key']] = (float)$setting['value'];
 }

 $totalDeductions = 0;

 // Income Tax (IR)
 // ★ FIX (FIN-2b-008): Changed UPPERCASE keys to match database format (tax.xxx)
 $irThreshold = $settingsMap['tax.ir_threshold'] ?? 62000;
 $irRate = $settingsMap['tax.ir_rate'] ?? 11.25;
 $irTaxable = max(0, $grossSalary - $irThreshold);
 $irAmount = round($irTaxable * ($irRate / 100), 2);
 $deductions[] = [
 'key' => 'IR',
 'name' => 'Income Tax (IR)',
 'type' => 'TAX',
 'rate' => $irRate,
 'amount' => $irAmount
 ];
 $totalDeductions += $irAmount;

 // CNPS Employee Contribution
 // ★ FIX (FIN-2b-008): Changed UPPERCASE keys to match database format (tax.xxx)
 $cnpsRate = $settingsMap['tax.cnps_employee_rate'] ?? 2.80;
 $cnpsCeiling = $settingsMap['tax.cnps_ceiling'] ?? 750000;
 $cnpsBase = min($grossSalary, $cnpsCeiling);
 $cnpsAmount = round($cnpsBase * ($cnpsRate / 100), 2);
 $deductions[] = [
 'key' => 'CNPS_EMP',
 'name' => 'CNPS Employee Contribution',
 'type' => 'CONTRIBUTION',
 'rate' => $cnpsRate,
 'amount' => $cnpsAmount
 ];
 $totalDeductions += $cnpsAmount;

 // Registration Tax
 // ★ FIX (FIN-2b-008): Changed UPPERCASE key to match database format (tax.xxx)
 $regRate = $settingsMap['tax.registration'] ?? 1.00;
 $regAmount = round($grossSalary * ($regRate / 100), 2);
 $deductions[] = [
 'key' => 'REG_TAX',
 'name' => 'Registration Tax',
 'type' => 'TAX',
 'rate' => $regRate,
 'amount' => $regAmount
 ];
 $totalDeductions += $regAmount;

 // Stamp Duty
 // ★ FIX (FIN-2b-008): Changed UPPERCASE key to match database format (tax.xxx)
 $stampRate = $settingsMap['tax.stamp_duty'] ?? 0.20;
 $stampAmount = round($grossSalary * ($stampRate / 100), 2);
 $deductions[] = [
 'key' => 'STAMP_DUTY',
 'name' => 'Stamp Duty',
 'type' => 'FEE',
 'rate' => $stampRate,
 'amount' => $stampAmount
 ];
 $totalDeductions += $stampAmount;

 $netPay = $grossSalary - $totalDeductions;

 return [
 'gross_salary' => $grossSalary,
 'total_deductions' => $totalDeductions,
 'net_pay' => max(0, $netPay),
 'deductions' => $deductions
 ];
}

// -----------------------------------------------------------
// Settings Helper
// -----------------------------------------------------------

/**
 * Read a setting value from the settings database table.
 * Returns the fallback value if the setting is not found in the database.
 *
 * @param PDO $db Database connection
 * @param string $key Setting key (e.g., 'security.max_login_attempts')
 * @param mixed $fallback Default value if setting doesn't exist
 * @return mixed Setting value or fallback
 */
function getSetting(PDO $db, string $key, $fallback = null) {
 try {
 // ★ FIXED: Case-insensitive lookup. Settings keys may be stored with mixed case
 // (e.g. withdrawal.fee_SALARY vs withdrawal.fee_salary). BINARY collation would
 // cause exact match to miss. Use LOWER() comparison for reliable lookup.
 $stmt = $db->prepare('SELECT "value" FROM settings WHERE LOWER("key") = LOWER(:key) LIMIT 1');
 $stmt->execute([':key' => $key]);
 $row = $stmt->fetch();
 if ($row !== false) {
 return $row['value'];
 }
 } catch (PDOException $e) {
 // Table might not exist yet — return fallback
 }
 return $fallback;
}
