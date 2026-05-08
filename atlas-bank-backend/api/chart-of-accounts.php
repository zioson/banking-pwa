<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Chart of Accounts
 *
 * FIXED: Migration now handles the case where GL code 1400 exists
 * with wrong name (e.g., "Fixed Assets" from old seed data).
 * Uses INSERT ... ON CONFLICT (id) DO UPDATE SET to force-correct.
 */
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

$staff = requireModule('CHART_OF_ACCOUNTS');
$method = $_ROUTE['method'];

switch ($method) {
 case 'GET':
 $params = [];
 $where = buildWhere($_GET, ['type', 'category', 'is_active'], ['type' => '=', 'is_active' => '='], $params);
 try {
 $db = getDB();

 // ★ CRITICAL MIGRATION: Ensure GL codes are correct
 // The OLD seed data had 1400 = "Fixed Assets" but ALL backend code
 // expects 1400 = "Operating Fund - Bank" (linked to BANK-OP-0001).
 // This migration uses INSERT...ON CONFLICT (id) DO UPDATE SET to fix any
 // wrong names without requiring the user to run SQL manually.
 $criticalCodes = [
 // [code, name, type, category, description]
 ['1400', 'Operating Fund - Bank', 'ASSET', 'Current Assets', 'Bank operating capital and fund pool — linked to BANK-OP-0001'],
 ['1600', 'Fixed Assets', 'ASSET', 'PREMISES', 'Property, equipment, and IT infrastructure'],
 ['3100', 'Retained Earnings', 'EQUITY', 'Reserves', 'Accumulated retained profits'],
 ['5900', 'Miscellaneous Expense', 'EXPENSE', 'Admin', 'Other operating costs']
 ];
 foreach ($criticalCodes as $row) {
 // First, check if code exists with WRONG name and fix it
 $chk = $db->prepare("SELECT id, name FROM chart_of_accounts WHERE code = ?");
 $chk->execute([$row[0]]);
 $existing = $chk->fetch(PDO::FETCH_ASSOC);
 if ($existing) {
 if ($existing['name'] !== $row[1]) {
 // Wrong name — update it
 $db->prepare("UPDATE chart_of_accounts SET name = ?, type = ?, category = ?, description = ?, is_active = TRUE WHERE code = ?")
 ->execute([$row[1], $row[2], $row[3], $row[4], $row[0]]);
 error_log('[Chart of Accounts] Migration: Fixed GL code ' . $row[0] . ' from "' . $existing['name'] . '" to "' . $row[1] . '"');
 }
 // Already correct — skip
 } else {
 // Doesn't exist — insert it
 $db->prepare("INSERT INTO chart_of_accounts (code, name, type, category, description, is_active) VALUES (?, ?, ?, ?, ?, 1)")
 ->execute($row);
 error_log('[Chart of Accounts] Migration: Created GL code ' . $row[0] . ' (' . $row[1] . ')');
 }
 }

 // Also ensure general_ledger entries for 1400 have correct account_name
 $db->prepare("UPDATE general_ledger SET account_name = 'Operating Fund - Bank' WHERE account_code = '1400' AND account_name LIKE '%Fixed Asset%'")
 ->execute();

 $stmt = $db->prepare('SELECT id, code, name, type, category, description, is_active FROM chart_of_accounts ' . $where . ' ORDER BY code ASC');
 $stmt->execute($params);
 successResponse($stmt->fetchAll());
 } catch (PDOException $e) { serverErrorResponse('Database error.'); }
 break;
 default:
 errorResponse('Method not allowed.', 405);
}
