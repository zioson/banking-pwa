<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Accounts
 */

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

$staff = requireAuth();
$method = $_ROUTE['method'];
$id = $_ROUTE['id'];

// GET: Any authenticated user can view accounts (branch isolation is applied inside GET).
// POST/PUT/DELETE: Requires ACCOUNTS module.
if ($method !== 'GET') {
 requireModule('ACCOUNTS', $staff);
}

// ── Auto-create accounts table if missing ──
$db = getDB();
// ★ FIX (CUST-002): Added hold_balance column — the frontend references it but
// it was missing from the schema, causing hold_balance to always be 0/null.
$db->exec("CREATE TABLE IF NOT EXISTS accounts (
 id SERIAL PRIMARY KEY,
 account_number VARCHAR(50) NOT NULL,
 customer_id INT DEFAULT NULL,
 customer_name VARCHAR(255);
// ★ FIX (CUST-002): Add hold_balance column if it doesn't exist (migration for existing DBs)
try {
 $db->exec("ALTER TABLE accounts ADD COLUMN hold_balance DECIMAL(20,2) NOT NULL DEFAULT 0 AFTER available_balance");
} catch (PDOException $e) { /* column already exists — ignore */ }

switch ($method) {
 case 'GET':
 if ($id !== null) {
 try {
 $db = getDB();
 $stmt = $db->prepare('SELECT a.*, c.customer_number FROM accounts a LEFT JOIN customers c ON a.customer_id = c.id WHERE a.id = :id');
 $stmt->execute([':id' => $id]);
 $record = $stmt->fetch(PDO::FETCH_ASSOC);
 if (!$record) { notFoundResponse('Account not found.'); }

 // ★ FIX (ACC-011): Apply branch isolation to single-account GET
 // Prevents staff from viewing account details of customers in other branches.
 $role = strtoupper((string)($staff['role'] ?? ''));
 if ($role !== 'ADMIN') {
 $staffBranchesRaw = $staff['branches'] ?? [];
 if (is_string($staffBranchesRaw)) {
 $staffBranchesRaw = [$staffBranchesRaw];
 }
 $staffBranchesNorm = array_values(array_unique(array_filter(array_map(function ($b) {
 $v = strtoupper(trim((string)$b));
 if (in_array($v, ['ALL BRANCHES', 'ALL_BRANCHES'], true)) return 'ALL';
 return $v;
 }, is_array($staffBranchesRaw) ? $staffBranchesRaw : []))));

 if (!empty($staffBranchesNorm) && !in_array('ALL', $staffBranchesNorm, true)) {
 $accBranchNorm = strtoupper(trim((string)($record['branch'] ?? '')));
 if ($accBranchNorm !== '' && !in_array($accBranchNorm, $staffBranchesNorm, true)) {
 errorResponse('Access denied. Account belongs to a different branch.', 403);
 }
 }
 }

 // Decode JSON columns so they are not double-encoded
 if (isset($record['tax_exemptions']) && is_string($record['tax_exemptions'])) {
 $decoded = json_decode($record['tax_exemptions'], true);
 $record['tax_exemptions'] = is_array($decoded) ? $decoded : [];
 }
 successResponse($record);
 } catch (PDOException $e) { serverErrorResponse('Database error.'); }
 } else {
 $page = max(1, (int)($_GET['page'] ?? 1));
 // ★ FIX (RA-ACC-001): Allow up to 5000 for bulk data loads. The frontend needs all
 // accounts for KPI cards, GL 2000 (Customer Deposits), and Balance Sheet computations.
 // MAX_PAGE_SIZE (100) was too restrictive — all financial totals were understated.
 $pageSize = max(1, min((int)($_GET['pageSize'] ?? DEFAULT_PAGE_SIZE), 5000));
 $offset = ($page - 1) * $pageSize;
 $params = [];
 $where = buildWhere($_GET, ['status', 'product_type', 'branch', 'currency'], ['product_type' => '='], $params);
 // ★ FIXED: Server-side branch filtering
 $staffBranches = $staff['branches'] ?? [];
 $clientBranch = sanitize($_GET['branch'] ?? '');
 $branchFilter = applyBranchFilter($staffBranches, $clientBranch, $params, $staff['role'] ?? '', 'branch');
 if ($branchFilter) { $where .= ($where ? ' AND ' : ' WHERE ') . substr($branchFilter, 5); }
 try {
 $db = getDB();
 $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM accounts ' . $where);
 $countStmt->execute($params);
 $total = (int)$countStmt->fetch()['total'];
 $stmt = $db->prepare('SELECT * FROM accounts ' . $where . ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset');
 foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
 $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
 $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
 $stmt->execute();
 $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
 // Decode JSON columns for each row
 foreach ($rows as &$row) {
 if (isset($row['tax_exemptions']) && is_string($row['tax_exemptions'])) {
 $decoded = json_decode($row['tax_exemptions'], true);
 $row['tax_exemptions'] = is_array($decoded) ? $decoded : [];
 }
 }
 unset($row);
 paginatedResponse($rows, $total, $page, $pageSize);
 } catch (PDOException $e) { serverErrorResponse('Database error.'); }
 }
 break;
 case 'POST':
 $input = getRequestInput();
 $errors = validateRequired($input, ['customer_id', 'product_type', 'currency']);
 if (!empty($errors)) { validationError($errors); }
 try {
 $db = getDB();
 // ── Duplicate account type check: same customer + same product_type ──
 $dupStmt = $db->prepare('SELECT id, account_number FROM accounts WHERE customer_id = :cid AND product_type = :ptype LIMIT 1');
 $dupStmt->execute([':cid' => (int)$input['customer_id'], ':ptype' => sanitize($input['product_type'])]);
 $existing = $dupStmt->fetch();
 if ($existing) {
 errorResponse('Customer already has a ' . htmlspecialchars($input['product_type']) . ' account (Account #' . $existing['account_number'] . ').', 409);
 }

 $accNum = generateAccountNumber();
 $custStmt = $db->prepare('SELECT full_name, branch FROM customers WHERE id = :id');
 $custStmt->execute([':id' => $input['customer_id']]);
 $cust = $custStmt->fetch();
 $stmt = $db->prepare(
 'INSERT INTO accounts (account_number, customer_id, customer_name, product_type, branch, status, currency, opened_at)
 VALUES (:num, :cid, :cname, :type, :branch, :status, :currency, :opened)'
 );
 $stmt->execute([
 ':num' => $accNum,
 ':cid' => (int)$input['customer_id'],
 ':cname' => $cust['full_name'] ?? '',
 ':type' => sanitize($input['product_type']),
 ':branch' => sanitize($input['branch'] ?? $cust['branch'] ?? ''),
 ':status' => sanitize($input['status'] ?? 'PENDING_OPENING'),
 ':currency' => sanitize($input['currency'] ?? 'XAF'),
 ':opened' => date('Y-m-d')
 ]);
 $newId = (int)$db->lastInsertId('accounts_id_seq');
 logAudit($staff['full_name'], 'ACCOUNT_CREATE', 'ACCOUNT', (string)$newId, 'SUCCESS', 'Opened account ' . $accNum, $staff['department'], getClientIp());
 createdResponse(['id' => $newId, 'account_number' => $accNum], 'Account opened successfully.');
 } catch (PDOException $e) { serverErrorResponse('Failed to create account.'); }
 break;
 case 'PUT':
 if ($id === null) { validationError(['id' => 'Account ID is required.']); }
 $input = getRequestInput();
 try {
 $db = getDB();
 $fields = []; $params = [':id' => $id];
 foreach (['status', 'branch', 'currency'] as $f) {
 if (isset($input[$f])) { $fields[] = ""$f" = :$f"; $params[":$f"] = sanitize($input[$f]); }
 }
 // ★ FIX (CUST-002): Allow hold_balance updates from the frontend
 // (e.g., when freezing/closing an account, hold_balance is set to ledger_balance)
 if (isset($input['hold_balance'])) {
 $fields[] = ""hold_balance" = :hold_balance";
 $params[":hold_balance"] = number_format((float)$input['hold_balance'], 2, '.', '');
 }
 // Allow tax_exemptions as JSON array (persisted per-account exemption toggles)
 if (isset($input['tax_exemptions']) && is_array($input['tax_exemptions'])) {
 $fields[] = ""tax_exemptions" = :tax_exemptions";
 $params[":tax_exemptions"] = json_encode($input['tax_exemptions']);
 }
 if (empty($fields)) { errorResponse('No fields to update.'); }
 $stmt = $db->prepare('UPDATE accounts SET ' . implode(', ', $fields) . ' WHERE id = :id');
 $stmt->execute($params);
 logAudit($staff['full_name'], 'ACCOUNT_UPDATE', 'ACCOUNT', $id, 'SUCCESS', 'Updated account ID: ' . $id, $staff['department'], getClientIp());
 successMessage('Account updated successfully.');
 } catch (PDOException $e) { serverErrorResponse('Failed to update account.'); }
 break;
 default:
 errorResponse('Method not allowed.', 405);
}
