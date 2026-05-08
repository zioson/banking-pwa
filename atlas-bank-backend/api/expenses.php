<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Expenses — Full CRUD (database-driven)
 *
 * GET    /api/expenses          — List expenses (paginated, filterable)
 * POST   /api/expenses          — Create expense (always PENDING on server)
 * PUT    /api/expenses/:id      — Approve, reject, or edit an expense
 * DELETE /api/expenses/:id      — Soft-delete an expense (only PENDING)
 */
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

$staff = requireAuth();
$method = $_ROUTE['method'];
$id     = $_ROUTE['id'] ? (int)$_ROUTE['id'] : null;
$input  = getRequestInput();
$bodyAction = strtoupper(sanitize($input['action'] ?? ''));
$isApprovalAction = $method === 'PUT' && in_array($bodyAction, ['APPROVE', 'REJECT'], true);

// Approval actions are cross-cutting and may be launched from the Approvals panel
// by users who have approval authority but not the full Expenses module.
if ($isApprovalAction) {
    requireAnyModule(['APPROVALS', 'EXPENSES'], $staff);
} else {
    requireModule('EXPENSES', $staff);
}

switch ($method) {

    /* ── GET: List expenses (paginated + filterable) ─────────────────── */
    case 'GET':
        $params = [];
        $where  = buildWhere($_GET, ['status', 'category', 'branch'], [
            'category' => '=', 'status' => '=', 'branch' => '='
        ], $params);
        if (!empty($_GET['date_from'])) {
            $where .= ($where ? ' AND ' : ' WHERE ') . 'e.date >= :df';
            $params[':df'] = sanitize($_GET['date_from']);
        }
        if (!empty($_GET['date_to'])) {
            $where .= ($where ? ' AND ' : ' WHERE ') . 'e.date <= :dt';
            $params[':dt'] = sanitize($_GET['date_to']);
        }
        // Only show non-deleted records
        $where .= ($where ? ' AND ' : ' WHERE ') . 'e.deleted_at IS NULL';

        // ★ FIX (EXP-B001): Apply branch isolation to expenses list —
        // non-admin staff can only see expenses from their assigned branches
        // ★ FIXED: Server-side branch filtering with Admin Bypass
        $staffBranches = $staff['branches'] ?? [];
        $clientBranch = sanitize($_GET['branch'] ?? '');
        $branchFilter = applyBranchFilter($staffBranches, $clientBranch, $params, $staff['role'] ?? '', 'branch');
        if ($branchFilter) { $where .= ($where ? ' AND ' : ' WHERE ') . substr($branchFilter, 5); }

        $page     = max(1, (int)($_GET['page'] ?? 1));
        // ★ FIX (RA-CF-002/RA-TXN-002): Allow up to 5000 for bulk data loads. The frontend
        // needs all expenses for Cash Flow operating-expense computations. MAX_PAGE_SIZE (100)
        // was too restrictive — expense totals were understated.
        $pageSize = max(1, min((int)($_GET['pageSize'] ?? DEFAULT_PAGE_SIZE), 5000));
        $offset   = ($page - 1) * $pageSize;

        try {
            $db = getDB();
            $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM expenses e ' . $where);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetch()['total'];

            // ★ FIX (EXP-B008): Removed no-op identity assignment on approved_by_name
            $stmt = $db->prepare(
                'SELECT e.*, s.full_name AS approved_by_name
                 FROM expenses e
                 LEFT JOIN staff s ON e.approved_by = s.id
                 ' . $where . '
                 ORDER BY e.date DESC, e.id DESC
                 LIMIT CAST(:limit AS INTEGER) OFFSET CAST(:offset AS INTEGER)'
            );
            foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
            $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            paginatedResponse($stmt->fetchAll(PDO::FETCH_ASSOC), $total, $page, $pageSize);
        } catch (PDOException $e) {
            error_log('[Expenses GET] ' . $e->getMessage());
            serverErrorResponse('Database error.');
        }
        break;

    /* ── POST: Create expense (server always sets PENDING) ───────────── */
    case 'POST':
        requireRole(['ADMIN', 'MANAGER', 'OPERATIONS']);
        $input  = getRequestInput();
        $errors = validateRequired($input, ['date', 'category', 'amount', 'description']);
        if (!empty($errors)) { validationError($errors); }

        $amountParsed = parseDecimalInput($input['amount'] ?? null, 'Amount', 2, 0.01, 1000000000000);
        if (!$amountParsed['ok']) { validationError(['amount' => $amountParsed['error']]); }
        $amount = $amountParsed['value'];

        // Validate category against known list
        $allowedCats = ['RENT','SALARIES','UTILITIES','EQUIPMENT','MARKETING',
                        'PROFESSIONAL','TECHNOLOGY','INSURANCE','MISCELLANEOUS'];
        $category = strtoupper(sanitize($input['category']));
        if (!in_array($category, $allowedCats)) {
            validationError(['category' => 'Invalid expense category.']);
        }

        // GL code mapping
        $glMap = [
            'RENT'         => ['5100', 'Rent & Lease Expense'],
            'SALARIES'     => ['5200', 'Salaries & Wages Expense'],
            'UTILITIES'    => ['5300', 'Utilities Expense'],
            'EQUIPMENT'    => ['5400', 'Equipment & Maintenance Expense'],
            'MARKETING'    => ['5500', 'Marketing & Advertising Expense'],
            'PROFESSIONAL' => ['5600', 'Professional Services Expense'],
            'TECHNOLOGY'   => ['5700', 'Technology & IT Expense'],
            'INSURANCE'    => ['5800', 'Insurance Expense'],
            'MISCELLANEOUS'=> ['5900', 'Miscellaneous Expense'],
        ];
        $glCode     = $glMap[$category][0];
        $glAcctName = $glMap[$category][1];

        try {
            $db   = getDB();
            $stmt = $db->prepare(
                'INSERT INTO expenses (date, category, gl_code, gl_account_name, amount,
                    vendor, description, notes, branch, status,
                    operator_id, operator_name)
                 VALUES (:date, :cat, :gl, :glname, :amount, :vendor,
                    :desc, :notes, :branch, :status, :opid, :opname)'
            );
            $stmt->execute([
                ':date'    => sanitize($input['date']),
                ':cat'     => $category,
                ':gl'      => $glCode,
                ':glname'  => $glAcctName,
                ':amount'  => $amount,
                ':vendor'  => sanitize($input['vendor'] ?? ''),
                ':desc'    => sanitize($input['description']),
                ':notes'   => sanitize($input['notes'] ?? ''),
                ':branch'  => sanitize($input['branch'] ?? ''),
                ':status'  => 'PENDING',   // Server ALWAYS creates as PENDING
                ':opid'    => $staff['id'],
                ':opname'  => $staff['full_name']
            ]);

            $newId = (int)$db->lastInsertId();
            logAudit(
                $staff['full_name'], 'EXPENSE_CREATE', 'EXPENSE',
                (string)$newId, 'SUCCESS',
                'New ' . $category . ' expense: ' . moneyFormat($amount) . ' from ' . sanitize($input['vendor'] ?? 'N/A'),
                $staff['department'], getClientIp()
            );
            createdResponse([
                'id'             => $newId,
                'status'         => 'PENDING',
                'gl_code'        => $glCode,
                'gl_account_name'=> $glAcctName
            ], 'Expense recorded. Pending approval.');
        } catch (PDOException $e) {
            error_log('[Expenses POST] ' . $e->getMessage());
            serverErrorResponse('Failed to record expense.');
        }
        break;

    /* ── PUT: Approve / Reject / Edit expense ────────────────────────── */
    case 'PUT':
        if (!$id) { validationError(['id' => 'Expense ID is required.']); }

        $action = sanitize($input['action'] ?? '');
        if (!in_array(strtoupper($action), ['APPROVE', 'REJECT'], true)) {
            requireRole(['ADMIN', 'MANAGER', 'OFFICER'], $staff);
        }

        try {
            $db = getDB();

            /*
             * ────────────────────────────────────────────────────────────
             * DDL SAFETY: Create / migrate all required tables and columns
             * BEFORE starting any transaction. DDL statements (CREATE TABLE,
             * ALTER TABLE) cause an implicit commit in MySQL/MariaDB, which
             * would destroy any active transaction and break atomicity.
             * ────────────────────────────────────────────────────────────
             */

            // Ensure operating_account table exists
            $db->exec("CREATE TABLE IF NOT EXISTS operating_account (
                id SERIAL PRIMARY KEY,
                account_number VARCHAR(50) NOT NULL UNIQUE,
                account_name VARCHAR(200) DEFAULT '',
                balance DECIMAL(20,2) NOT NULL DEFAULT 0,
                currency VARCHAR(10) DEFAULT 'XAF',
                status VARCHAR(20) DEFAULT 'ACTIVE',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");

            // Ensure operating_account_transactions table exists
            $db->exec("CREATE TABLE IF NOT EXISTS operating_account_transactions (
                id SERIAL PRIMARY KEY,
                ref VARCHAR(100) DEFAULT '',
                operating_account_id INT DEFAULT NULL,
                date DATE DEFAULT NULL,
                type VARCHAR(20) DEFAULT '',
                description TEXT,
                amount DECIMAL(20,2) NOT NULL DEFAULT 0,
                balance_after DECIMAL(20,2) DEFAULT 0,
                operator VARCHAR(200) DEFAULT '',
                contra_account VARCHAR(50) DEFAULT '',
                transaction_type VARCHAR(50) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ref (ref),
                INDEX idx_operating_account_id (operating_account_id),
                INDEX idx_date (date),
                INDEX idx_transaction_type (transaction_type)
            )");

            // Ensure general_ledger table exists
            $db->exec("CREATE TABLE IF NOT EXISTS general_ledger (
                id SERIAL PRIMARY KEY,
                account_code VARCHAR(10) NOT NULL,
                account_name VARCHAR(200) DEFAULT '',
                debit DECIMAL(20,2) NOT NULL DEFAULT 0,
                credit DECIMAL(20,2) NOT NULL DEFAULT 0,
                date DATE NOT NULL,
                reference VARCHAR(100) DEFAULT '',
                description TEXT,
                branch VARCHAR(100) DEFAULT '',
                posted_by INT DEFAULT NULL,
                transaction_type VARCHAR(50) DEFAULT '',
                contra_account VARCHAR(50) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_account_code (account_code),
                INDEX idx_date (date),
                INDEX idx_reference (reference),
                INDEX idx_transaction_type (transaction_type),
                INDEX idx_branch (branch)
            )");

            // Safe migration: add branch column if missing from general_ledger
            $glCols = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'general_ledger' AND column_name = 'branch'")->fetchAll();
            if (empty($glCols)) {
                $db->exec("ALTER TABLE general_ledger ADD COLUMN branch VARCHAR(100) DEFAULT ''");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_branch ON general_ledger (branch)");
            }

            // Safe migration: add transaction_type column if missing from general_ledger
            $ttCols = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'general_ledger' AND column_name = 'transaction_type'")->fetchAll();
            if (empty($ttCols)) {
                $db->exec("ALTER TABLE general_ledger ADD COLUMN transaction_type VARCHAR(50) DEFAULT ''");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_transaction_type ON general_ledger (transaction_type)");
            }

            // Safe migration: add contra_account column if missing from general_ledger
            $caCols = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'general_ledger' AND column_name = 'contra_account'")->fetchAll();
            if (empty($caCols)) {
                $db->exec("ALTER TABLE general_ledger ADD COLUMN contra_account VARCHAR(50) DEFAULT ''");
            }

            // Fetch the expense
            $stmt = $db->prepare('SELECT * FROM expenses WHERE id = :id AND deleted_at IS NULL');
            $stmt->execute([':id' => $id]);
            $expense = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$expense) { notFoundResponse('Expense not found.'); }

            // ★ FIX (EXP-B002): Enforce branch isolation on PUT —
            // non-admin cannot approve/reject/edit expenses from other branches
            if (strtoupper($staff['role'] ?? '') !== 'ADMIN' && !hasBranchAccess((string)($expense['branch'] ?? ''), $staff)) {
                errorResponse('Access denied. You cannot act on expenses from a branch you are not assigned to.', 403);
            }

            // ── APPROVE (fully transactional) ──
            if ($action === 'APPROVE') {
                if ($expense['status'] !== 'PENDING') {
                    errorResponse('Only PENDING expenses can be approved.', 409);
                }

                // ★ FIX (EXP-B003): Prevent self-approval — maker-checker principle.
                // The operator who recorded an expense cannot approve it.
                if ((int)$staff['id'] === (int)$expense['operator_id']) {
                    errorResponse('Cannot approve your own expense request. Dual-control requires a different person.', 403);
                }

                // ★ FIX (EXP-B004): Check approval limit before proceeding
                requireApprovalLimit((float)$expense['amount'], $staff);

                /*
                 * ────────────────────────────────────────────────────────
                 * ATOMIC APPROVAL TRANSACTION
                 *
                 * All mutating operations (status update, balance debit,
                 * operating-account transaction, and both GL entries) are
                 * wrapped in a single database transaction. If ANY step
                 * fails, everything is rolled back — the expense stays
                 * PENDING, the operating fund is untouched, and no GL
                 * entries are written. This prevents unbalanced trial
                 * balances caused by partial writes.
                 *
                 * DDL has already been executed above (before the
                 * transaction) to avoid implicit commits.
                 * ────────────────────────────────────────────────────────
                 */
                $db->beginTransaction();
                try {
                    // ── Step 1: UPDATE expense status to APPROVED ──
                    $stmt = $db->prepare(
                        "UPDATE expenses SET status = 'APPROVED', approved_by = :uid,
                         approved_at = NOW(), updated_at = NOW()
                         WHERE id = :id AND status = 'PENDING'"
                    );
                    $stmt->execute([':uid' => $staff['id'], ':id' => $id]);
                    if ($stmt->rowCount() === 0) {
                        // Another request already approved this expense
                        $db->rollBack();
                        conflictResponse('Expense was already acted on by another user.');
                    }

                    // ── Step 2: Fetch operating account with row-level lock (FOR UPDATE) ──
                    $stmt = $db->prepare(
                        "SELECT * FROM operating_account WHERE account_number = 'BANK-OP-0001' LIMIT 1 FOR UPDATE"
                    );
                    $stmt->execute();
                    $opAcct = $stmt->fetch(PDO::FETCH_ASSOC);

                    // Fallback: if no specific operating account, try any available
                    if (!$opAcct) {
                        $stmt = $db->query("SELECT * FROM operating_account LIMIT 1 FOR UPDATE");
                        $opAcct = $stmt->fetch(PDO::FETCH_ASSOC);
                    }

                    if (!$opAcct) {
                        $db->rollBack();
                        error_log('[Expenses APPROVE] No operating account found in database.');
                        errorResponse('No operating account configured. Cannot approve expense.', 500);
                    }

                    $opAcctId   = (int)$opAcct['id'];
                    $expAmount  = (float)$expense['amount'];

                    // Derive branch for GL and OA transaction entries
                    $expenseBranch = trim((string)($expense['branch'] ?? ''));
                    if ($expenseBranch === '') {
                        $expenseBranch = trim((string)($staff['department'] ?? ''));
                    }

                    // ── Step 3: Check sufficient balance from GL 1400 (source of truth) ──
                    // This prevents the cached operating_account.balance being treated as authoritative,
                    // which can be stale or overwritten by branch-scoped views.
                    $glBalStmt = null;
                    $branchBal = 0.0;
                    if ($expenseBranch !== '') {
                        $glBalStmt = $db->prepare(
                            "SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS bal
                               FROM general_ledger
                              WHERE account_code = '1400' AND UPPER(branch) = UPPER(:br)"
                        );
                        $glBalStmt->execute([':br' => $expenseBranch]);
                        $branchBal = (float)($glBalStmt->fetch(PDO::FETCH_ASSOC)['bal'] ?? 0);
                    } else {
                        $glBalStmt = $db->query(
                            "SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS bal
                               FROM general_ledger
                              WHERE account_code = '1400'"
                        );
                        $branchBal = (float)($glBalStmt->fetch(PDO::FETCH_ASSOC)['bal'] ?? 0);
                    }

                    $branchBalAfter = $branchBal - $expAmount;
                    if ($branchBalAfter < 0) {
                        $db->rollBack();
                        error_log('[Expenses APPROVE] Insufficient operating fund (GL 1400) for branch ' . $expenseBranch . ': ' . number_format($branchBal, 2) . ' XAF, attempted: ' . number_format($expAmount, 2) . ' XAF');
                        errorResponse('Insufficient operating fund balance. Available: ' . number_format($branchBal, 2) . ' XAF', 400);
                    }

                    // ── Step 4: UPDATE operating account balance cache (global) ──
                    $globalBalRow = $db->query(
                        "SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS bal
                           FROM general_ledger
                          WHERE account_code = '1400'"
                    )->fetch(PDO::FETCH_ASSOC);
                    $globalBal = (float)($globalBalRow['bal'] ?? 0);
                    $newGlobalBal = $globalBal - $expAmount;
                    $stmt = $db->prepare("UPDATE operating_account SET balance = :newbal WHERE id = :opid");
                    $stmt->execute([':newbal' => $newGlobalBal, ':opid' => $opAcctId]);

                    // ── Step 5: INSERT operating account transaction ──
                    // ★ FIX (EXP-B006): Use MAX-based reference instead of COUNT for race safety
                    $maxRefStmt = $db->query("SELECT MAX(id) FROM operating_account_transactions");
                    $maxRefId = (int)$maxRefStmt->fetchColumn();
                    $expRef   = 'EXP-' . date('Y-m-d') . '-' . str_pad($maxRefId + 1, 3, '0', STR_PAD_LEFT);

                    $stmt = $db->prepare(
                        "INSERT INTO operating_account_transactions
                            (ref, operating_account_id, date, type, description, amount, balance_after, operator, contra_account, transaction_type, branch)
                         VALUES (?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->execute([
                        $expRef, $opAcctId, 'DEBIT',
                        'Expense #' . $id . ' — ' . ($expense['description'] ?? '') . ' - ' . ($expense['vendor'] ?? ''),
                        $expAmount, $branchBalAfter,
                        $staff['full_name'],
                        $expense['gl_code'] ?? '',
                        'EXPENSE_APPROVAL',
                        $expenseBranch
                    ]);

                    // ── Step 6: INSERT GL Entry — Credit 1400 (Operating Fund decreases) ──
                    $glStmt = $db->prepare(
                        "INSERT INTO general_ledger
                            (account_code, account_name, debit, credit, date, reference, description, branch, posted_by, transaction_type, contra_account)
                         VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?)"
                    );
                    $glStmt->execute([
                        '1400', 'Operating Fund - Bank',
                        0, $expAmount,
                        $expRef,
                        'Expense #' . $id . ' approved — ' . ($expense['description'] ?? ''),
                        $expenseBranch,
                        $staff['id'] > 0 ? $staff['id'] : null,
                        'EXPENSE_APPROVAL',
                        $expense['gl_code'] ?? ''
                    ]);

                    // ── Step 7: INSERT GL Entry — Debit expense GL code (Expense increases) ──
                    $glCode = $expense['gl_code'] ?? '5900';
                    $glName = $expense['gl_account_name'] ?? 'Miscellaneous Expense';
                    $glStmt->execute([
                        $glCode, $glName,
                        $expAmount, 0,
                        $expRef,
                        'Expense #' . $id . ' approved — ' . ($expense['description'] ?? ''),
                        $expenseBranch,
                        $staff['id'] > 0 ? $staff['id'] : null,
                        'EXPENSE_APPROVAL',
                        '1400'
                    ]);

                    // ── Commit: All steps succeeded atomically ──
                    $db->commit();

                    error_log('[Expenses APPROVE] Operating fund debited: ' . number_format($expAmount, 2) . ' XAF. Branch balance after: ' . number_format($branchBalAfter, 2) . ' XAF. GL entries recorded. Reference: ' . $expRef);

                    logAudit($staff['full_name'], 'EXPENSE_APPROVE', 'EXPENSE', (string)$id, 'SUCCESS',
                        'Approved expense #' . $id . ' of ' . moneyFormat($expAmount) . ' (ref: ' . $expRef . ')',
                        $staff['department'], getClientIp());
                    successMessage('Expense approved and operating fund debited.');

                } catch (PDOException $txErr) {
                    $db->rollBack();
                    error_log('[Expenses APPROVE] Transaction rolled back — ' . $txErr->getMessage());
                    errorResponse('Expense approval failed — all changes rolled back. Operating fund was NOT debited.', 500);
                }

            // ── REJECT ──
            } elseif ($action === 'REJECT') {
                if ($expense['status'] !== 'PENDING') {
                    errorResponse('Only PENDING expenses can be rejected.', 409);
                }
                $rejectionReason = sanitize($input['reason'] ?? 'No reason provided');
                $stmt = $db->prepare(
                    "UPDATE expenses SET status = 'REJECTED', rejection_reason = :reason, updated_at = NOW()
                     WHERE id = :id AND status = 'PENDING'"
                );
                $stmt->execute([':reason' => $rejectionReason, ':id' => $id]);
                if ($stmt->rowCount() === 0) {
                    conflictResponse('Expense was already acted on by another user.');
                }

                logAudit($staff['full_name'], 'EXPENSE_REJECT', 'EXPENSE', (string)$id, 'SUCCESS',
                    'Rejected expense #' . $id . '. Reason: ' . $rejectionReason,
                    $staff['department'], getClientIp());
                successMessage('Expense rejected.');

            // ── EDIT (update description, vendor, branch, notes) ──
            } else {
                // ★ FIX (EXP-B005): Only allow editing PENDING expenses —
                // APPROVED/REJECTED expenses must not be modified without re-approval
                if ($expense['status'] !== 'PENDING') {
                    errorResponse('Only PENDING expenses can be edited. APPROVED/REJECTED expenses require a new record.', 409);
                }
                $allowed = ['description', 'vendor', 'branch', 'notes', 'date', 'amount', 'category'];
                $sets = [];
                $params = [':id' => $id];
                foreach ($allowed as $field) {
                    if (isset($input[$field])) {
                        $dbField = $field === 'date' ? 'date' : $field;
                        $sets[] = $dbField . ' = :' . $field;
                        $params[':' . $field] = sanitize($input[$field]);
                    }
                }
                if (empty($sets)) { errorResponse('No fields to update.', 400); }
                $sets[] = 'updated_at = NOW()';
                $sql = 'UPDATE expenses SET ' . implode(', ', $sets) . ' WHERE id = :id AND deleted_at IS NULL';
                $db->prepare($sql)->execute($params);

                logAudit($staff['full_name'], 'EXPENSE_UPDATE', 'EXPENSE', (string)$id, 'SUCCESS',
                    'Updated expense #' . $id, $staff['department'], getClientIp());
                successMessage('Expense updated.');
            }
        } catch (PDOException $e) {
            error_log('[Expenses PUT] ' . $e->getMessage());
            serverErrorResponse('Failed to update expense.');
        }
        break;

    /* ── DELETE: Soft-delete (only PENDING expenses) ─────────────────── */
    case 'DELETE':
        requireRole(['ADMIN']);
        if (!$id) { validationError(['id' => 'Expense ID is required.']); }

        try {
            $db = getDB();
            $stmt = $db->prepare(
                "UPDATE expenses SET deleted_at = NOW(), updated_at = NOW()
                 WHERE id = :id AND status = 'PENDING' AND deleted_at IS NULL"
            );
            $stmt->execute([':id' => $id]);
            if ($stmt->rowCount() === 0) {
                errorResponse('Cannot delete: expense not found, not PENDING, or already deleted.', 409);
            }
            logAudit($staff['full_name'], 'EXPENSE_DELETE', 'EXPENSE', (string)$id, 'SUCCESS',
                'Soft-deleted expense #' . $id, $staff['department'], getClientIp());
            successMessage('Expense deleted.');
        } catch (PDOException $e) {
            error_log('[Expenses DELETE] ' . $e->getMessage());
            serverErrorResponse('Failed to delete expense.');
        }
        break;

    default:
        errorResponse('Method not allowed.', 405);
}
