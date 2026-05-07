<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Search (Global search across entities)
 */
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

$staff = requireAuth();
$method = $_ROUTE['method'];

switch ($method) {
    case 'GET':
        $query = sanitize($_GET['q'] ?? '');
        $entity = sanitize($_GET['entity'] ?? '');
        $limit = max(1, min((int)($_GET['limit'] ?? 20), 100));

        if (empty($query)) { validationError(['q' => 'Search query is required.']); }

        try {
            $db = getDB();
            $results = [];
            $like = '%' . $query . '%';

            // ★ FIX (API-037): Apply branch isolation to search results
            $staffBranches = $staff['branches'] ?? [];

            // Search customers
            if (empty($entity) || $entity === 'customers') {
                $custParams = [':q' => $like, ':limit' => $limit];
                $custBranchFilter = applyBranchFilter($staffBranches, '', $custParams, $staff['role'] ?? '', 'branch');
                $custWhere = $custBranchFilter ? ' AND (1=1' . $custBranchFilter . ')' : '';
                $stmt = $db->prepare("SELECT id, customer_number, full_name, status, customer_type, branch FROM customers WHERE (full_name LIKE :q OR customer_number LIKE :q){$custWhere} LIMIT :limit");
                $stmt->execute($custParams);
                $results['customers'] = $stmt->fetchAll();
            }

            // Search accounts
            if (empty($entity) || $entity === 'accounts') {
                $acctParams = [':q' => $like, ':limit' => $limit];
                $acctBranchFilter = applyBranchFilter($staffBranches, '', $acctParams, $staff['role'] ?? '', 'branch');
                $acctWhere = $acctBranchFilter ? ' AND (1=1' . $acctBranchFilter . ')' : '';
                $stmt = $db->prepare("SELECT id, account_number, customer_name, product_type, status, currency, available_balance FROM accounts WHERE (account_number LIKE :q OR customer_name LIKE :q){$acctWhere} LIMIT :limit");
                $stmt->execute($acctParams);
                $results['accounts'] = $stmt->fetchAll();
            }

            // Search transactions
            if (empty($entity) || $entity === 'transactions') {
                $txnParams = [':q' => $like, ':limit' => $limit];
                $txnBranchFilter = applyBranchFilter($staffBranches, '', $txnParams, $staff['role'] ?? '', 'branch');
                $txnWhere = $txnBranchFilter ? ' AND (1=1' . $txnBranchFilter . ')' : '';
                $stmt = $db->prepare("SELECT id, ref, type, status, direction, amount, customer_name, created_at FROM transactions WHERE (ref LIKE :q OR customer_name LIKE :q OR description LIKE :q){$txnWhere} LIMIT :limit");
                $stmt->execute($txnParams);
                $results['transactions'] = $stmt->fetchAll();
            }

            // Search loans
            if (empty($entity) || $entity === 'loans') {
                $loanParams = [':q' => $like, ':limit' => $limit];
                $loanBranchFilter = applyBranchFilter($staffBranches, '', $loanParams, $staff['role'] ?? '', 'branch');
                $loanWhere = $loanBranchFilter ? ' AND (1=1' . $loanBranchFilter . ')' : '';
                $stmt = $db->prepare("SELECT id, loan_number, customer_name, status, principal, outstanding FROM loans WHERE (loan_number LIKE :q OR customer_name LIKE :q){$loanWhere} LIMIT :limit");
                $stmt->execute($loanParams);
                $results['loans'] = $stmt->fetchAll();
            }

            successResponse([
                'query' => $query,
                'results' => $results
            ]);

        } catch (PDOException $e) { serverErrorResponse('Search error.'); }
        break;

    default:
        errorResponse('Method not allowed.', 405);
}
