<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Customers
 */

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

$staff = requireAuth();
$method = $_ROUTE['method'];
$id = $_ROUTE['id'];
$subResource = $_ROUTE['subResource'] ?? null;

// GET: Any authenticated user can view customers (branch isolation is applied inside GET).
// POST/PUT/DELETE: Requires CUSTOMERS module.
if ($method !== 'GET') {
    requireModule('CUSTOMERS', $staff);
}
$db = getDB();

function generateInitialClientPassword(int $length = 14): string {
    $length = max(12, min($length, 32));
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%*?';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

// Client portal access management (enterprise controlled, fully DB-driven)
if ($subResource === 'portal-access') {
    if ($id === null) {
        validationError(['id' => 'Customer ID is required.']);
    }
    requireModule('CUSTOMERS', $staff);
    require_once __DIR__ . '/../middleware/client_auth.php';
    ensureClientPortalTables($db);

    if ($method === 'GET') {
        $stmt = $db->prepare(
            "SELECT cpu.customer_id, cpu.username, cpu.status, cpu.failed_login_attempts, cpu.locked_until,
                    cpu.require_password_change, cpu.mfa_required, cpu.mfa_enrolled_at,
                    cpu.last_login, cpu.last_login_ip
             FROM customer_portal_users cpu
             WHERE cpu.customer_id = :cid
             LIMIT 1"
        );
        $stmt->execute([':cid' => (int)$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            successResponse([
                'enabled' => false,
                'customer_id' => (int)$id
            ]);
        }
        successResponse([
            'enabled' => true,
            'customer_id' => (int)$id,
            'username' => $row['username'],
            'status' => $row['status'],
            'failed_login_attempts' => (int)$row['failed_login_attempts'],
            'locked_until' => $row['locked_until'],
            'require_password_change' => (bool)$row['require_password_change'],
            'mfa_required' => (bool)$row['mfa_required'],
            'mfa_enrolled_at' => $row['mfa_enrolled_at'],
            'last_login' => $row['last_login'],
            'last_login_ip' => $row['last_login_ip']
        ]);
    }

    if ($method === 'PUT' || $method === 'POST') {
        $input = getRequestInput();

        $custStmt = $db->prepare('SELECT id, customer_number, full_name, branch FROM customers WHERE id = :id LIMIT 1');
        $custStmt->execute([':id' => (int)$id]);
        $cust = $custStmt->fetch(PDO::FETCH_ASSOC);
        if (!$cust) {
            notFoundResponse('Customer not found.');
        }

        $existingStmt = $db->prepare('SELECT * FROM customer_portal_users WHERE customer_id = :cid LIMIT 1');
        $existingStmt->execute([':cid' => (int)$id]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        $rawUsername = strtolower(trim((string)($input['username'] ?? ($existing['username'] ?? $cust['customer_number']))));
        if (!preg_match('/^[a-z0-9._-]{4,120}$/', $rawUsername)) {
            validationError(['username' => 'Username must be 4-120 chars and use only letters, numbers, dot, underscore, or hyphen.']);
        }

        $status = strtoupper(trim((string)($input['status'] ?? ($existing['status'] ?? 'ACTIVE'))));
        if (!in_array($status, ['ACTIVE', 'LOCKED', 'DISABLED'], true)) {
            validationError(['status' => 'Status must be ACTIVE, LOCKED, or DISABLED.']);
        }

        $password = (string)($input['password'] ?? '');
        $generateInitial = !empty($input['generate_initial_credentials']);
        $mfaRequired = isset($input['mfa_required'])
            ? ((int)$input['mfa_required'] ? 1 : 0)
            : (int)($existing['mfa_required'] ?? 0);
        $mfaSecret = trim((string)($input['mfa_secret'] ?? ($existing['mfa_secret'] ?? '')));
        $requirePasswordChange = isset($input['require_password_change'])
            ? ((int)$input['require_password_change'] ? 1 : 0)
            : (int)($existing['require_password_change'] ?? 0);
        $tempPassword = '';

        if ($generateInitial) {
            $password = generateInitialClientPassword(14);
            $requirePasswordChange = 1;
        }

        if (!$existing && $password === '') {
            validationError(['password' => 'Password is required when enabling client portal access.']);
        }
        if ($password !== '' && strlen($password) < 10) {
            validationError(['password' => 'Password must be at least 10 characters.']);
        }
        if ($mfaRequired && $mfaSecret === '') {
            $mfaSecret = generateClientMfaSecret();
        }

        $passwordHash = $existing['password_hash'] ?? '';
        if ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if ($generateInitial) {
                $tempPassword = $password;
            }
        }

        if ($existing) {
            $upd = $db->prepare(
                'UPDATE customer_portal_users
                 SET username = :username, password_hash = :ph, status = :status,
                     require_password_change = :rpc, mfa_required = :mfa_required,
                     mfa_secret = :mfa_secret, mfa_enrolled_at = :mfa_enrolled_at,
                     failed_login_attempts = 0,
                     locked_until = NULL
                 WHERE customer_id = :cid'
            );
            $upd->execute([
                ':username' => $rawUsername,
                ':ph' => $passwordHash,
                ':status' => $status,
                ':rpc' => $requirePasswordChange,
                ':mfa_required' => $mfaRequired,
                ':mfa_secret' => $mfaSecret !== '' ? $mfaSecret : null,
                ':mfa_enrolled_at' => $mfaRequired ? date('Y-m-d H:i:s') : null,
                ':cid' => (int)$id
            ]);
        } else {
            $ins = $db->prepare(
                'INSERT INTO customer_portal_users
                 (customer_id, username, password_hash, status, require_password_change, mfa_required, mfa_secret, mfa_enrolled_at)
                 VALUES (:cid, :username, :ph, :status, :rpc, :mfa_required, :mfa_secret, :mfa_enrolled_at)'
            );
            $ins->execute([
                ':cid' => (int)$id,
                ':username' => $rawUsername,
                ':ph' => $passwordHash,
                ':status' => $status,
                ':rpc' => $requirePasswordChange,
                ':mfa_required' => $mfaRequired,
                ':mfa_secret' => $mfaSecret !== '' ? $mfaSecret : null,
                ':mfa_enrolled_at' => $mfaRequired ? date('Y-m-d H:i:s') : null
            ]);
        }

        logAudit(
            $staff['full_name'],
            'CUSTOMER_PORTAL_ACCESS_UPDATE',
            'CUSTOMER',
            (string)$id,
            'SUCCESS',
            'Client portal access updated for ' . $cust['full_name'] . ' (username: ' . $rawUsername . ', status: ' . $status . ', mfa: ' . ($mfaRequired ? 'on' : 'off') . ')',
            (string)($staff['department'] ?? ''),
            getClientIp()
        );

        $response = [
            'customer_id' => (int)$id,
            'username' => $rawUsername,
            'status' => $status,
            'require_password_change' => (bool)$requirePasswordChange,
            'mfa_required' => (bool)$mfaRequired
        ];
        if ($tempPassword !== '') {
            $response['initial_password'] = $tempPassword;
            $response['credential_notice'] = 'Display this password once to the customer and require immediate change at first sign-in.';
        }
        successResponse($response, 'Client portal access updated successfully.');
    }

    errorResponse('Method not allowed.', 405);
}

/** Get products for a customer as a simple array of strings */
function getCustomerProducts(PDO $db, int $customerId): array {
    $stmt = $db->prepare('SELECT product_name FROM customer_products WHERE customer_id = :cid');
    $stmt->execute([':cid' => $customerId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

/** Save products for a customer (delete-then-insert) */
function saveCustomerProducts(PDO $db, int $customerId, array $products): void {
    $db->prepare('DELETE FROM customer_products WHERE customer_id = :cid')->execute([':cid' => $customerId]);
    foreach ($products as $p) {
        $p = strtoupper(trim($p));
        if ($p === '') continue;
        // ★ FIX (CUST-010): Accept any uppercase product name — no arbitrary whitelist.
        // The original code had a redundant in_array check that accepted everything anyway.
        $db->prepare('INSERT INTO customer_products (customer_id, product_name) VALUES (:cid, :pn)')
            ->execute([':cid' => $customerId, ':pn' => $p]);
    }
}

/**
 * ★ FIX (CUST-B002): Validate customer status transitions.
 * Enforces enterprise-grade state machine: only valid transitions are allowed.
 * @return string|null  Error message if invalid, null if valid.
 */
function validateCustomerStatusTransition(string $current, string $target): ?string {
    $validTransitions = [
        'DRAFT'       => ['PENDING_KYC', 'ACTIVE', 'REJECTED'],
        'PENDING_KYC' => ['ACTIVE', 'REJECTED', 'DRAFT'],
        'ACTIVE'      => ['RESTRICTED', 'CLOSED'],
        'RESTRICTED'  => ['ACTIVE', 'CLOSED'],
        'REJECTED'    => ['DRAFT'],  // allow re-application
        'CLOSED'      => [],         // closed is terminal — no reactivation without admin override
    ];
    $current = strtoupper($current);
    $target  = strtoupper($target);
    if ($current === $target) return null; // no-op is allowed
    $allowed = $validTransitions[$current] ?? null;
    if ($allowed === null) return "Unknown current status: $current";
    if (!in_array($target, $allowed, true)) {
        return "Invalid status transition: $current → $target. Allowed: " . implode(', ', $allowed);
    }
    return null;
}

switch ($method) {
    case 'GET':
        if ($id !== null) {
            try {
                $db = getDB();
                $stmt = $db->prepare('SELECT * FROM customers WHERE id = :id');
                $stmt->execute([':id' => $id]);
                $record = $stmt->fetch();
                if (!$record) { notFoundResponse('Customer not found.'); }

                // ★ FIX (CUST-011): Apply branch isolation to single-customer GET
                // Prevents staff from viewing customer details from other branches.
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
                        $custBranchNorm = strtoupper(trim((string)($record['branch'] ?? '')));
                        if ($custBranchNorm !== '' && !in_array($custBranchNorm, $staffBranchesNorm, true)) {
                            errorResponse('Access denied. Customer belongs to a different branch.', 403);
                        }
                    }
                }

                // Get products
                $record['products'] = getCustomerProducts($db, (int)$id);
                // Get accounts count
                $aStmt = $db->prepare('SELECT COUNT(*) AS cnt FROM accounts WHERE customer_id = :cid');
                $aStmt->execute([':cid' => $id]);
                $record['accounts_count'] = (int)$aStmt->fetch()['cnt'];
                successResponse($record);
            } catch (PDOException $e) { serverErrorResponse('Database error.'); }
        } else {
            $page = max(1, (int)($_GET['page'] ?? 1));
            // ★ FIX (RA-CUST-002): Increase from 100 to 5000 for analytical reports
            $pageSize = max(1, min((int)($_GET['pageSize'] ?? 20), 5000));
            $offset = ($page - 1) * $pageSize;
            $params = [];
            $where = buildWhere($_GET, ['status', 'customer_type', 'risk_rating', 'branch', 'full_name'], [
                'full_name' => 'like', 'branch' => 'like'
            ], $params);
            // ★ FIXED: Server-side branch filtering
            $staffBranches = $staff['branches'] ?? [];
            $clientBranch = sanitize($_GET['branch'] ?? '');
            $branchFilter = applyBranchFilter($staffBranches, $clientBranch, $params, $staff['role'] ?? '', 'branch');
            if ($branchFilter) { $where .= ($where ? ' AND ' : ' WHERE ') . substr($branchFilter, 5); }
            try {
                $db = getDB();
                $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM customers ' . $where);
                $countStmt->execute($params);
                $total = (int)$countStmt->fetch()['total'];
                $stmt = $db->prepare(
                    'SELECT * FROM customers ' . $where . ' ORDER BY created_at DESC LIMIT CAST(:limit AS INTEGER) OFFSET CAST(:offset AS INTEGER)'
                );
                foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
                $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll();

                // ★ PERFORMANCE FIX: Bulk-fetch products for all customers to avoid N+1 queries.
                // Previously, getCustomerProducts() was called in a loop, triggering up to 5001 DB calls.
                if (!empty($rows)) {
                    $customerIds = array_column($rows, 'id');
                    $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
                    $prodStmt = $db->prepare("SELECT customer_id, product_name FROM customer_products WHERE customer_id IN ($placeholders)");
                    $prodStmt->execute($customerIds);
                    $allProducts = $prodStmt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
                    
                    foreach ($rows as &$row) {
                        $cid = $row['id'];
                        $row['products'] = isset($allProducts[$cid]) ? array_column($allProducts[$cid], 'product_name') : [];
                    }
                    unset($row);
                }
                
                paginatedResponse($rows, $total, $page, $pageSize);
            } catch (PDOException $e) { serverErrorResponse('Database error.'); }
        }
        break;
    case 'POST':
        $input = getRequestInput();
        $errors = validateRequired($input, ['full_name', 'customer_type']);
        if (!empty($errors)) { validationError($errors); }
        try {
            $db = getDB();
            // ── Duplicate customer check: same full_name + same branch ──
            $dupStmt = $db->prepare('SELECT id, customer_number FROM customers WHERE LOWER(full_name) = LOWER(:name) AND branch = :branch LIMIT 1');
            $dupStmt->execute([':name' => sanitize($input['full_name']), ':branch' => sanitize($input['branch'] ?? '')]);
            $existing = $dupStmt->fetch();
            if ($existing) {
                errorResponse('A customer named "' . htmlspecialchars($input['full_name']) . '" already exists in this branch (Customer #' . $existing['customer_number'] . ').', 409);
            }

            $custNum = generateCustomerNumber();
            // ★ FIX (CUST-007): Include notes in customer creation
            $stmt = $db->prepare(
                'INSERT INTO customers (customer_number, customer_type, full_name, status, risk_rating,
                                       branch, relationship_started, phone, email, kyc_verified, next_action, notes)
                 VALUES (:num, :type, :name, :status, :risk, :branch, :rel, :phone, :email, :kyc, :next_action, :notes)'
            );
            $stmt->execute([
                ':num'    => $custNum,
                ':type'   => sanitize($input['customer_type']),
                ':name'   => sanitize($input['full_name']),
                ':status' => sanitize($input['status'] ?? 'DRAFT'),
                ':risk'   => sanitize($input['risk_rating'] ?? 'MEDIUM'),
                ':branch' => sanitize($input['branch'] ?? ''),
                ':rel'    => date('Y-m-d'),
                ':phone'  => sanitize($input['phone'] ?? ''),
                ':email'  => sanitize($input['email'] ?? ''),
                ':kyc'    => FALSE,
                ':next_action' => sanitize($input['next_action'] ?? ''),
                ':notes'  => sanitize($input['notes'] ?? ''),
            ]);
            $newId = (int)$db->lastInsertId();

            // ── Save requested products ──
            $rawProducts = is_array($input['products'] ?? null) ? $input['products'] : [];
            saveCustomerProducts($db, $newId, $rawProducts);

            logAudit($staff['full_name'], 'CUSTOMER_CREATE', 'CUSTOMER', (string)$newId, 'SUCCESS',
                'Created customer: ' . $input['full_name'] . ' (products: ' . implode(', ', array_map('strtoupper', $rawProducts)) . ')',
                $staff['department'], getClientIp());
            createdResponse(['id' => $newId, 'customer_number' => $custNum], 'Customer created successfully.');
        } catch (PDOException $e) { serverErrorResponse('Failed to create customer.'); }
        break;
    case 'PUT':
        if ($id === null) { validationError(['id' => 'Customer ID is required.']); }
        $input = getRequestInput();
        try {
            $db = getDB();
            // ★ FIX (CUST-B002): Validate status transition before updating
            if (isset($input['status'])) {
                $currentStmt = $db->prepare('SELECT status FROM customers WHERE id = :id');
                $currentStmt->execute([':id' => $id]);
                $currentRow = $currentStmt->fetch();
                if (!$currentRow) { notFoundResponse('Customer not found.'); }
                $transitionError = validateCustomerStatusTransition($currentRow['status'], $input['status']);
                if ($transitionError !== null) {
                    errorResponse($transitionError, 422);
                }
            }
            // ★ FIX (CUST-008): Allow kyc_verified to be set via PUT
            // ★ FIX (CUST-007): Allow notes to be set via PUT
            $fields = []; $params = [':id' => $id];
            foreach (['full_name', 'status', 'risk_rating', 'branch', 'phone', 'email', 'next_action', 'notes'] as $f) {
                if (isset($input[$f])) { $fields[] = "\"$f\" = :$f"; $params[":$f"] = sanitize($input[$f]); }
            }
            // kyc_verified is BOOLEAN — handle separately to ensure TRUE/FALSE value
            if (isset($input['kyc_verified'])) {
                $fields[] = "\"kyc_verified\" = :kyc_verified";
                $params[":kyc_verified"] = (int)$input['kyc_verified'] ? TRUE : FALSE;
            }
            if (empty($fields)) { errorResponse('No fields to update.'); }
            $stmt = $db->prepare("UPDATE customers SET ' . implode(', ', $fields) . ' WHERE id = :id");
            $stmt->execute($params);
            // Update products if provided
            if (isset($input['products'])) {
                $rawProducts = is_array($input['products']) ? $input['products'] : [];
                saveCustomerProducts($db, (int)$id, $rawProducts);
            }
            logAudit($staff['full_name'], 'CUSTOMER_UPDATE', 'CUSTOMER', $id, 'SUCCESS', 'Updated customer ID: ' . $id, $staff['department'], getClientIp());
            successMessage('Customer updated successfully.');
        } catch (PDOException $e) { serverErrorResponse('Failed to update customer.'); }
        break;
    default:
        errorResponse('Method not allowed.', 405);
}
