<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Deductions (Tax/Fee deduction management)
 */
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

$staff = requireModule('TRANSACTIONS');
$method = $_ROUTE['method'];

switch ($method) {
    case 'GET':
        $txnId = (int)($_GET['transaction_id'] ?? 0);
        if ($txnId === 0) { validationError(['transaction_id' => 'Transaction ID is required.']); }
        try {
            $db = getDB();
            $stmt = $db->prepare('SELECT * FROM transaction_deductions WHERE transaction_id = :tid ORDER BY deduction_type, deduction_name');
            $stmt->execute([':tid' => $txnId]);
            successResponse($stmt->fetchAll());
        } catch (PDOException $e) { serverErrorResponse('Database error.'); }
        break;

    case 'POST':
        requireRole(['ADMIN', 'SUPERVISOR'], $staff);
        $input = getRequestInput();
        $errors = validateRequired($input, ['transaction_id', 'deduction_key', 'deduction_name', 'deduction_type', 'amount']);
        if (!empty($errors)) { validationError($errors); }
        try {
            $db = getDB();
            // Verify transaction exists
            $checkStmt = $db->prepare('SELECT id, ref, status FROM transactions WHERE id = :tid');
            $checkStmt->execute([':tid' => (int)$input['transaction_id']]);
            $txn = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$txn) { notFoundResponse('Transaction not found.'); }

            // ★ FIX (API-040): Block deduction modification on POSTED transactions
            if (isset($txn['status']) && $txn['status'] === 'POSTED') {
                errorResponse('Cannot modify deductions on a POSTED transaction. Reverse the transaction first.', 409);
            }

            $stmt = $db->prepare(
                'INSERT INTO transaction_deductions (transaction_id, deduction_key, deduction_name, deduction_type, rate, amount, is_exempt)
                 VALUES (:tid, :key, :name, :type, :rate, :amount, :exempt)'
            );
            $stmt->execute([
                ':tid' => (int)$input['transaction_id'],
                ':key' => sanitize($input['deduction_key']),
                ':name' => sanitize($input['deduction_name']),
                ':type' => sanitize($input['deduction_type']),
                ':rate' => (float)($input['rate'] ?? 0),
                ':amount' => (float)$input['amount'],
                ':exempt' => isset($input['is_exempt']) ? (int)$input['is_exempt'] : 0
            ]);
            $newId = (int)$db->lastInsertId('deductions_id_seq');
            logAudit($staff['full_name'], 'DEDUCTION_CREATE', 'DEDUCTION', (string)$newId, 'SUCCESS',
                'Added deduction "' . $input['deduction_name'] . '" amount=' . $input['amount'] . ' on txn ' . $input['transaction_id'],
                $staff['department'], getClientIp());
            createdResponse(['id' => $newId], 'Deduction added successfully.');
        } catch (PDOException $e) { serverErrorResponse('Failed to add deduction.'); }
        break;

    case 'PUT':
        requireRole(['ADMIN', 'SUPERVISOR'], $staff);
        $input = getRequestInput();
        $errors = validateRequired($input, ['id', 'is_exempt']);
        if (!empty($errors)) { validationError($errors); }
        try {
            $db = getDB();
            // Fetch existing deduction to preserve amount if not provided
            $existingStmt = $db->prepare('SELECT amount FROM transaction_deductions WHERE id = :id');
            $existingStmt->execute([':id' => (int)$input['id']]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) { notFoundResponse('Deduction not found.'); }

            // Only update amount if explicitly provided, otherwise preserve original
            $amount = isset($input['amount']) ? (float)$input['amount'] : (float)$existing['amount'];

            $stmt = $db->prepare(
                'UPDATE transaction_deductions SET is_exempt = :exempt, amount = :amount WHERE id = :id'
            );
            $stmt->execute([
                ':exempt' => (int)$input['is_exempt'],
                ':amount' => $amount,
                ':id' => (int)$input['id']
            ]);
            if ($stmt->rowCount() === 0) { notFoundResponse('Deduction not found.'); }
            logAudit($staff['full_name'], 'DEDUCTION_UPDATE', 'DEDUCTION', (string)$input['id'], 'SUCCESS',
                'Updated deduction: is_exempt=' . $input['is_exempt'] . ', amount=' . $amount,
                $staff['department'], getClientIp());
            successMessage('Deduction updated successfully.');
        } catch (PDOException $e) { serverErrorResponse('Failed to update deduction.'); }
        break;

    default:
        errorResponse('Method not allowed.', 405);
}
