<?php
/**
 * Atlas Bank Enterprise - Client Portal Data API (View-only)
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/Response.php';
require_once __DIR__ . '/../middleware/client_auth.php';

$method = $_ROUTE['method'] ?? 'GET';
if ($method !== 'GET') {
    errorResponse('Method not allowed. Client portal is view-only.', 405);
}

$db = getDB();
ensureClientPortalTables($db);
$client = requireClientAuth();
$customerId = (int)$client['customer_id'];

try {
    $customerStmt = $db->prepare(
        'SELECT id, customer_number, customer_type, full_name, status, risk_rating, branch, phone, email, relationship_started
         FROM customers
         WHERE id = :id
         LIMIT 1'
    );
    $customerStmt->execute([':id' => $customerId]);
    $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        notFoundResponse('Customer profile not found.');
    }

    $accountsStmt = $db->prepare(
        'SELECT id, account_number, product_type, status, currency, ledger_balance, available_balance, hold_balance, opened_at
         FROM accounts
         WHERE customer_id = :cid
         ORDER BY created_at DESC'
    );
    $accountsStmt->execute([':cid' => $customerId]);
    $accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);
    $accountNumbers = array_map(static fn($a) => (string)$a['account_number'], $accounts);

    $transactions = [];
    if (!empty($accountNumbers)) {
        $limit = max(10, min((int)($_GET['txnLimit'] ?? 100), 500));
        $ph = implode(',', array_fill(0, count($accountNumbers), '?'));
        $txnSql = "SELECT id, ref, type, direction, account, amount, net_amount, fee, fee_pct, total_tax, status, category, description, created_at
                   FROM transactions
                   WHERE account IN ($ph)
                   ORDER BY created_at DESC
                   LIMIT ?";
        $txnStmt = $db->prepare($txnSql);
        $bind = $accountNumbers;
        $bind[] = $limit;
        $txnStmt->execute($bind);
        $transactions = $txnStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $loansStmt = $db->prepare(
        'SELECT id, loan_number, status, principal, outstanding, interest_rate, term_months, disbursed_at, maturity_date, next_due
         FROM loans
         WHERE customer_id = :cid
         ORDER BY created_at DESC'
    );
    $loansStmt->execute([':cid' => $customerId]);
    $loans = $loansStmt->fetchAll(PDO::FETCH_ASSOC);

    $docsStmt = $db->prepare(
        "SELECT id, document_number, type, subtype, account_number, generated_by_name, period_start, period_end, status, created_at AS generated_at
         FROM generated_documents
         WHERE customer_id = :cid OR customer_name = :cname
         ORDER BY created_at DESC
         LIMIT 100"
    );
    $docsStmt->execute([
        ':cid' => (string)$customerId,
        ':cname' => (string)($customer['full_name'] ?? '')
    ]);
    $documents = $docsStmt->fetchAll(PDO::FETCH_ASSOC);

    successResponse([
        'customer' => $customer,
        'accounts' => $accounts,
        'transactions' => $transactions,
        'loans' => $loans,
        'documents' => $documents,
        'session_expires_at' => $client['session_expires_at']
    ]);
} catch (PDOException $e) {
    error_log('[Client Portal GET] PDO error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    serverErrorResponse('Failed to load client portal data.');
}
