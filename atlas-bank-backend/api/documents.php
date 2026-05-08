<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Documents (Generated Statements, Receipts, Reports)
 */
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

$staff = requireModule('DOCUMENTS');
$method = $_ROUTE['method'];
$id = $_ROUTE['id'];

/**
 * Safely add a column to a table if it doesn't exist (MySQL/MariaDB compatible)
 */
function addColumnIfMissing(PDO $db, string $table, string $column, string $definition): void {
 try {
 $col = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = '$table' AND column_name = '$column'")->fetch();
 if (!$col) {
 $db->exec("ALTER TABLE "$table" ADD COLUMN "$column" $definition");
 }
 } catch (PDOException $e) {
 error_log("[Schema] addColumnIfMissing($table, $column) failed: " . $e->getMessage());
 }
}

/** Ensure all extra columns exist on generated_documents */
function ensureDocumentColumns(PDO $db): void {
 // ── Column renames (schema drift from older SQL dumps) ──
 try {
 $cols = [];
 foreach ($db->query('SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'generated_documents'')->fetchAll(PDO::FETCH_ASSOC) as $c) {
 $cols[strtolower($c['Field'])] = true;
 }
 // generated_at → created_at
 if (isset($cols['generated_at']) && !isset($cols['created_at'])) {
 $db->exec("ALTER TABLE "generated_documents" RENAME COLUMN "generated_at" TO "created_at" DEFAULT CURRENT_TIMESTAMP");
 $cols['created_at'] = true;
 }
 // period_from → period_start
 if (isset($cols['period_from']) && !isset($cols['period_start'])) {
 $db->exec("ALTER TABLE "generated_documents" RENAME COLUMN "period_from" TO "period_start"
 $cols['period_start'] = true;
 }
 // period_to → period_end
 if (isset($cols['period_to']) && !isset($cols['period_end'])) {
 $db->exec("ALTER TABLE "generated_documents" RENAME COLUMN "period_to" TO "period_end"
 $cols['period_end'] = true;
 }
 // data → content (add content if missing, copy data if content is empty)
 if (!isset($cols['content'])) {
 if (isset($cols['data'])) {
 $db->exec("ALTER TABLE generated_documents ADD COLUMN "content" TEXT DEFAULT NULL AFTER "status"");
 } else {
 $db->exec("ALTER TABLE generated_documents ADD COLUMN "content" TEXT DEFAULT NULL AFTER "status"");
 }
 }
 } catch (PDOException $e) {
 error_log("[Schema] Document column rename failed: " . $e->getMessage());
 }

 // ── Add missing columns ──
 addColumnIfMissing($db, 'generated_documents', 'print_count', "INT DEFAULT 0");
 addColumnIfMissing($db, 'generated_documents', 'last_printed_at', "TIMESTAMP NULL DEFAULT NULL");
 addColumnIfMissing($db, 'generated_documents', 'export_count', "INT DEFAULT 0");
 addColumnIfMissing($db, 'generated_documents', 'last_exported_at', "TIMESTAMP NULL DEFAULT NULL");
 addColumnIfMissing($db, 'generated_documents', 'subtype', "VARCHAR(100);
 addColumnIfMissing($db, 'generated_documents', 'summary', "JSON DEFAULT NULL");
 addColumnIfMissing($db, 'generated_documents', 'branch', "VARCHAR(100);
 addColumnIfMissing($db, 'generated_documents', 'account_type', "VARCHAR(50);
 addColumnIfMissing($db, 'generated_documents', 'customer_id', "VARCHAR(50);
 addColumnIfMissing($db, 'generated_documents', 'generated_by_name', "VARCHAR(255);

 // ── Fix restrictive ENUMs to VARCHAR ──
 try {
 $col = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'generated_documents' AND column_name = 'type'")->fetch();
 if ($col && str_contains($col['Type'], 'enum(')) {
 $db->exec("ALTER TABLE generated_documents MODIFY COLUMN "type" VARCHAR(20) NOT NULL");
 }
 } catch (PDOException $e) {
 error_log("[Schema] Document type ENUM fix failed: " . $e->getMessage());
 }
 try {
 $col = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'generated_documents' AND column_name = 'status'")->fetch();
 if ($col && str_contains($col['Type'], 'enum(')) {
 $db->exec("ALTER TABLE generated_documents MODIFY COLUMN "status" VARCHAR(20);
 }
 } catch (PDOException $e) {
 error_log("[Schema] Document status ENUM fix failed: " . $e->getMessage());
 }
}

$db = getDB();
$db->exec("CREATE TABLE IF NOT EXISTS "generated_documents" (
 "id" INT PRIMARY KEY,
 "document_number" VARCHAR(100) NOT NULL,
 "type" VARCHAR(20) NOT NULL,
 "subtype" VARCHAR(100);

switch ($method) {
 case 'GET':
 $params = [];
 $where = buildWhere($_GET, ['type', 'account_number', 'customer_name', 'branch', 'status'], [], $params);
 // ★ DOC-IA-008 FIX: Apply branch isolation — non-admin users should only see documents from their assigned branches.
 // Previously, the GET endpoint returned ALL documents regardless of the authenticated user's branch access.
 // Direct API access would expose documents from all branches — a data leak.
 $staffBranches = $staff['branches'] ?? [];
 $clientBranch = sanitize($_GET['branch'] ?? '');
 $branchFilter = applyBranchFilter($staffBranches, $clientBranch, $params, $staff['role'] ?? '', 'branch');
 if ($branchFilter) { $where .= ($where ? ' AND ' : ' WHERE ') . substr($branchFilter, 5); }
 $page = max(1, (int)($_GET['page'] ?? 1));
 $pageSize = max(1, min((int)($_GET['pageSize'] ?? 20), 500));
 $offset = ($page - 1) * $pageSize;
 try {
 $db = getDB();
 ensureDocumentColumns($db);

 $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM generated_documents ' . $where);
 $countStmt->execute($params);
 $total = (int)$countStmt->fetch()['total'];
 $stmt = $db->prepare('SELECT * FROM generated_documents ' . $where . ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset');
 foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
 $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
 $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
 $stmt->execute();
 $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

 // Decode JSON summary for each row
 foreach ($rows as &$row) {
 if (!empty($row['summary'])) {
 $decoded = json_decode($row['summary'], true);
 $row['summary'] = $decoded !== null ? $decoded : [];
 } else {
 $row['summary'] = null;
 }
 }
 unset($row);

 paginatedResponse($rows, $total, $page, $pageSize);
 } catch (PDOException $e) {
 error_log('[Documents GET] PDO error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
 $msg = (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'Database error.';
 serverErrorResponse($msg);
 }
 break;
 case 'POST':
 $input = getRequestInput();
 // Reports (expense statements, audit reports, etc.) may not have account_number or customer_name
 // Also treat pre-mapped types (STMT, PAY, RCPT) as report-like when no account/customer provided
 $rawType = ($input['type'] ?? '');
 $isReport = in_array($rawType, ['REPORT', 'STMT', 'PAY', 'RCPT', 'STATEMENT', 'PAYSLIP', 'RECEIPT']);
 $hasAccountInfo = !empty($input['account_number']) || !empty($input['customer_name']);
 if ($isReport && !$hasAccountInfo) {
 $required = ['type'];
 } elseif ($hasAccountInfo) {
 $required = ['type'];
 } else {
 $required = ['type', 'account_number', 'customer_name'];
 }
 $errors = validateRequired($input, $required);
 if (!empty($errors)) { validationError($errors); }
 try {
 $db = getDB();
 ensureDocumentColumns($db);

 // Map frontend type values to DB enum
 $typeMap = ['STATEMENT' => 'STMT', 'PAYSLIP' => 'PAY', 'RECEIPT' => 'RCPT', 'REPORT' => 'REPORT'];
 $rawType = sanitize($input['type']);
 $dbType = $typeMap[$rawType] ?? $rawType;

 // Use the document_number sent by the frontend if provided, otherwise generate one
 $docNum = !empty($input['document_number']) ? sanitize($input['document_number']) : generateDocumentNumber($dbType);

 // Encode summary as JSON
 $summaryJson = null;
 if (!empty($input['summary'])) {
 $summaryJson = is_string($input['summary']) ? $input['summary'] : json_encode($input['summary']);
 }

 // Check if this document_number already exists (re-print scenario)
 $checkStmt = $db->prepare('SELECT id, print_count FROM generated_documents WHERE document_number = :dnum');
 $checkStmt->execute([':dnum' => $docNum]);
 $existing = $checkStmt->fetch();

 if ($existing) {
 // Document already registered — increment print_count and return existing
 $updateStmt = $db->prepare('UPDATE generated_documents SET print_count = print_count + 1, last_printed_at = NOW() WHERE id = :id');
 $updateStmt->execute([':id' => $existing['id']]);
 logAudit($staff['full_name'], 'DOCUMENT_REPRINT', 'DOCUMENT', (string)$existing['id'], 'SUCCESS', 'Reprinted document ' . $docNum, $staff['department'], getClientIp());
 successResponse(['id' => (int)$existing['id'], 'document_number' => $docNum, 'reprinted' => true], 'Document reprinted successfully.');
 } else {
 // New document — insert with full data
 $stmt = $db->prepare(
 'INSERT INTO generated_documents (document_number, type, subtype, account_number, account_type,
 customer_name, branch, period_start, period_end, generated_by, generated_by_name,
 print_count, summary)
 VALUES (:dnum, :type, :subtype, :acc, :atype, :cname, :branch, :pstart, :pend, :by, :byname, 0, :summary)'
 );
 $stmt->execute([
 ':dnum' => $docNum, ':type' => $dbType,
 ':subtype' => sanitize($input['subtype'] ?? ''),
 ':acc' => sanitize($input['account_number']), ':atype' => sanitize($input['account_type'] ?? ''),
 ':cname' => sanitize($input['customer_name']), ':branch' => sanitize($input['branch'] ?? ''),
 ':pstart' => sanitize($input['period_start'] ?? ''), ':pend' => sanitize($input['period_end'] ?? ''),
 ':by' => $staff['id'], ':byname' => $staff['full_name'],
 ':summary' => $summaryJson
 ]);
 logAudit($staff['full_name'], 'DOCUMENT_GENERATE', 'DOCUMENT', (string)$db->lastInsertId('generated_documents_id_seq'), 'SUCCESS', 'Generated document ' . $docNum, $staff['department'], getClientIp());
 createdResponse(['id' => (int)$db->lastInsertId('generated_documents_id_seq'), 'document_number' => $docNum], 'Document generated successfully.');
 }
 } catch (PDOException $e) {
 error_log('[Documents POST] PDO error: ' . $e->getMessage());
 serverErrorResponse('Failed to generate document.');
 }
 break;
 case 'PUT':
 // Allow updating document fields (e.g., status for void, print count)
 $input = getRequestInput();
 try {
 $db = getDB();
 ensureDocumentColumns($db);

 $docId = (int)($id ?? $input['id'] ?? 0);
 if (!$docId) { validationError(['id' => 'Document ID is required.']); }

 // ★ DOC-IA-008 FIX: Branch isolation on PUT — verify the document belongs to user's branch
 // before allowing updates (especially void operations)
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
 $checkStmt = $db->prepare('SELECT branch FROM generated_documents WHERE id = :id');
 $checkStmt->execute([':id' => $docId]);
 $docRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
 if ($docRow && !empty($docRow['branch'])) {
 $docBranchNorm = strtoupper(trim((string)$docRow['branch']));
 if ($docBranchNorm !== '' && !in_array($docBranchNorm, $staffBranchesNorm, true)) {
 errorResponse('Access denied. Document belongs to a different branch.', 403);
 }
 }
 }
 }

 $fields = [];
 $params = [':id' => $docId];

 if (isset($input['status'])) {
 $statusMap = ['VOIDED' => 'CANCELLED', 'ACTIVE' => 'FINAL', 'DRAFT' => 'DRAFT'];
 $fields[] = 'status = :status';
 $params[':status'] = $statusMap[$input['status']] ?? $input['status'];
 }
 if (isset($input['print_count'])) {
 $fields[] = 'print_count = :pc';
 $params[':pc'] = (int)$input['print_count'];
 }
 if (isset($input['last_printed_at'])) {
 $fields[] = 'last_printed_at = :lpt';
 $params[':lpt'] = $input['last_printed_at'];
 }
 if (isset($input['summary'])) {
 $fields[] = 'summary = :summary';
 $params[':summary'] = is_string($input['summary']) ? $input['summary'] : json_encode($input['summary']);
 }

 if (empty($fields)) { errorResponse('No fields to update.', 400); }

 $sql = 'UPDATE generated_documents SET ' . implode(', ', $fields) . ' WHERE id = :id';
 $stmt = $db->prepare($sql);
 $stmt->execute($params);
 $rowCount = $stmt->rowCount();
 if ($rowCount > 0) {
 logAudit($staff['full_name'] ?? 'System', 'DOCUMENT_UPDATE', 'DOCUMENT', (string)$docId, 'SUCCESS',
 'Updated document fields: ' . implode(', ', array_keys($input)), $staff['department'] ?? '', getClientIp());
 }
 successResponse(['updated' => $rowCount], 'Document updated.');
 } catch (PDOException $e) {
 error_log('[Documents PUT] PDO error: ' . $e->getMessage());
 serverErrorResponse('Failed to update document.');
 }
 break;
 default:
 errorResponse('Method not allowed.', 405);
}
