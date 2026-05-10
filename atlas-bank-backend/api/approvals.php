<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Approvals
 *
 * Handles the approval workflow for sensitive operations (reversals, loans, etc.)
 * Auto-migrates the approvals table and a "details" JSON column for storing
 * extra context such as reversal details.
 */
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

$staff = requireModule('APPROVALS');
$method = $_ROUTE['method'];
$id = $_ROUTE['id'];

function apprNormalizeBranches(array $branches): array {
    return array_values(array_unique(array_filter(array_map(function ($b) {
        $v = strtoupper(trim((string)$b));
        if (in_array($v, ['ALL BRANCHES', 'ALL_BRANCHES'], true)) return 'ALL';
        return $v;
    }, $branches))));
}

function apprCanAccessBranch(array $staff, string $branch): bool {
    $branch = strtoupper(trim($branch));
    if ($branch === '') return true;
    if (strtoupper($staff['role'] ?? '') === 'ADMIN') return true;
    $staffBranches = apprNormalizeBranches($staff['branches'] ?? []);
    if (in_array('ALL', $staffBranches, true)) return true;
    return empty($staffBranches) ? false : in_array($branch, $staffBranches, true);
}

function apprResolveEntityBranch(PDO $db, string $entityType, int $entityId): string {
    if ($entityId <= 0) return '';
    $entityType = strtoupper(trim($entityType));
    $map = [
        'LOAN APPLICATION' => ['loan_applications', 'branch'],
        'LOAN' => ['loans', 'branch'],
        'TRANSACTION' => ['transactions', 'branch'],
        'TRANSACTION REVERSAL' => ['transactions', 'branch'],
        'EXPENSE' => ['expenses', 'branch']
    ];
    if (!isset($map[$entityType])) return '';
    [$table, $column] = $map[$entityType];
    try {
        $stmt = $db->prepare("SELECT \"$column\" FROM \"$table\" WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $entityId]);
        return sanitize((string)($stmt->fetchColumn() ?: ''));
    } catch (PDOException $e) {
        return '';
    }
}

function apprNormalizeDetails($details, string $scopeCode): ?array {
    if ($details === null || $details === '') {
        $details = [];
    } elseif (is_string($details)) {
        $decoded = json_decode($details, true);
        $details = is_array($decoded) ? $decoded : ['raw' => $details];
    } elseif (!is_array($details)) {
        $details = ['value' => $details];
    }

    if ($scopeCode === 'loans.double_approve') {
        $details['requires_double_approval'] = true;
        $details['required_approver_roles'] = ['ADMIN', 'MANAGER'];
        if (!isset($details['approval_chain']) || !is_array($details['approval_chain'])) {
            $details['approval_chain'] = [];
        }
    }

    return empty($details) ? null : $details;
}

switch ($method) {
    case 'GET':
        try {
            $db = getDB();

            // ── Auto-migrate: ensure approvals table exists ──
            $db->exec("CREATE TABLE IF NOT EXISTS \"approvals\" (
                \"id\" SERIAL PRIMARY KEY,
                \"entity_type\" VARCHAR(50) NOT NULL,
                \"entity_id\" INTEGER DEFAULT NULL,
                \"scope_code\" VARCHAR(50) NOT NULL,
                \"status\" VARCHAR(50) NOT NULL DEFAULT 'PENDING' CHECK (\"status\" IN ('PENDING','APPROVED','REJECTED','CANCELLED')),
                \"submitted_by\" INTEGER DEFAULT NULL,
                \"branch\" VARCHAR(20) DEFAULT NULL,
                \"value\" TEXT DEFAULT NULL,
                \"details\" TEXT DEFAULT NULL,
                \"submitted_at\" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                \"decided_by\" INTEGER DEFAULT NULL,
                \"decided_at\" TIMESTAMP DEFAULT NULL,
                \"reason\" TEXT DEFAULT NULL,
                \"created_at\" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            try { $db->exec('CREATE INDEX IF NOT EXISTS idx_approvals_entity ON "approvals" (entity_type, entity_id)'); } catch (PDOException $e) {}
            try { $db->exec('CREATE INDEX IF NOT EXISTS idx_approvals_status ON "approvals" (status)'); } catch (PDOException $e) {}

            // ── Auto-migrate: add details column if missing (for older installs) ──
            $col = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'approvals' AND column_name = 'details'")->fetch();
            if (!$col) {
                $db->exec('ALTER TABLE "approvals" ADD COLUMN "details" TEXT DEFAULT NULL');
            }

            // ── Auto-migrate: ensure status column supports CANCELLED ──
            // PostgreSQL uses CHECK constraints; if the column exists but lacks CANCELLED,
            // we drop and re-add the constraint.
            try {
                $statusCol = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'approvals' AND column_name = 'status'")->fetch();
                if ($statusCol) {
                    // Try to add/drop the check constraint to include CANCELLED
                    try { $db->exec('ALTER TABLE "approvals" DROP CONSTRAINT IF EXISTS approvals_status_check'); } catch (PDOException $e) {}
                    try { $db->exec("ALTER TABLE \"approvals\" ADD CONSTRAINT approvals_status_check CHECK (\"status\" IN ('PENDING','APPROVED','REJECTED','CANCELLED'))"); } catch (PDOException $e) {}
                }
            } catch (PDOException $e) {
                error_log('[Approvals Schema] status check fix failed: ' . $e->getMessage());
            }

            if ($id !== null) {
                // ★ FIX (APPR-B001): Apply branch isolation to single-approval GET
                $stmt = $db->prepare('SELECT a.*, s1.full_name AS submitted_by_name, s2.full_name AS decided_by_name FROM approvals a LEFT JOIN staff s1 ON a.submitted_by = s1.id LEFT JOIN staff s2 ON a.decided_by = s2.id WHERE a.id = :id');
                $stmt->execute([':id' => $id]);
                $record = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$record) { notFoundResponse('Approval not found.'); }
                // ★ FIX (APPR-B001): Enforce branch isolation — non-admin cannot view approvals from other branches
                if (!apprCanAccessBranch($staff, (string)($record['branch'] ?? ''))) {
                    errorResponse('Access denied. This approval belongs to a branch you are not assigned to.', 403);
                }
                successResponse($record);
            } else {
                $page = max(1, (int)($_GET['page'] ?? 1));
                $pageSize = max(1, min((int)($_GET['pageSize'] ?? 20), 100));
                $offset = ($page - 1) * $pageSize;
                $params = [];
                $where = buildWhere($_GET, ['status', 'entity_type', 'scope_code', 'branch'], [], $params);
                // ★ FIX (APPR-B001): Apply branch isolation to approvals list —
                // non-admin staff can only see approvals from their assigned branches
                $staffBranches = $staff['branches'] ?? [];
                $clientBranch = sanitize($_GET['branch'] ?? '');
                $branchFilter = applyBranchFilter($staffBranches, $clientBranch, $params, $staff['role'] ?? '', 'branch');  // ★ FIX: Use 'branch' not 'a.branch' — applyBranchFilter wraps in backticks
                if ($branchFilter) { $where .= ($where ? ' AND ' : ' WHERE ') . substr($branchFilter, 5); }

                $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM approvals a ' . $where);
                $countStmt->execute($params);
                $total = (int)$countStmt->fetch()['total'];
                // ★ FIX (APPR-B002): JOIN staff table to resolve submitted_by and decided_by names
                $stmt = $db->prepare('SELECT a.*, s1.full_name AS submitted_by_name, s2.full_name AS decided_by_name FROM approvals a LEFT JOIN staff s1 ON a.submitted_by = s1.id LEFT JOIN staff s2 ON a.decided_by = s2.id ' . $where . ' ORDER BY a.submitted_at DESC LIMIT CAST(:limit AS INTEGER) OFFSET CAST(:offset AS INTEGER)');
                foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
                $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                paginatedResponse($stmt->fetchAll(PDO::FETCH_ASSOC), $total, $page, $pageSize);
            }
        } catch (PDOException $e) {
            error_log('[Approvals GET] PDO error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $msg = (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'Database error.';
            serverErrorResponse($msg);
        }
        break;

    case 'POST':
        $input = getRequestInput();
        $errors = validateRequired($input, ['entity_type', 'entity_id', 'scope_code', 'value']);
        if (!empty($errors)) { validationError($errors); }
        try {
            $db = getDB();
            $entityType = sanitize($input['entity_type']);
            $entityId = (int)$input['entity_id'];
            $scopeCode = sanitize($input['scope_code']);
            $resolvedBranch = apprResolveEntityBranch($db, $entityType, $entityId);
            $approvalBranch = sanitize($input['branch'] ?? '') ?: $resolvedBranch;
            if (!apprCanAccessBranch($staff, $approvalBranch)) {
                errorResponse('Access denied. You cannot submit an approval for a branch outside your assignment.', 403);
            }
            $detailsPayload = apprNormalizeDetails($input['details'] ?? null, $scopeCode);

            // ── Auto-migrate table (same as GET) ──
            $db->exec("CREATE TABLE IF NOT EXISTS \"approvals\" (
                \"id\" SERIAL PRIMARY KEY,
                \"entity_type\" VARCHAR(50) NOT NULL,
                \"entity_id\" INTEGER DEFAULT NULL,
                \"scope_code\" VARCHAR(50) NOT NULL,
                \"status\" VARCHAR(50) NOT NULL DEFAULT 'PENDING' CHECK (\"status\" IN ('PENDING','APPROVED','REJECTED','CANCELLED')),
                \"submitted_by\" INTEGER DEFAULT NULL,
                \"branch\" VARCHAR(20) DEFAULT NULL,
                \"value\" TEXT DEFAULT NULL,
                \"details\" TEXT DEFAULT NULL,
                \"submitted_at\" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                \"decided_by\" INTEGER DEFAULT NULL,
                \"decided_at\" TIMESTAMP DEFAULT NULL,
                \"reason\" TEXT DEFAULT NULL,
                \"created_at\" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            try { $db->exec('CREATE INDEX IF NOT EXISTS idx_approvals_entity ON "approvals" (entity_type, entity_id)'); } catch (PDOException $e) {}
            try { $db->exec('CREATE INDEX IF NOT EXISTS idx_approvals_status ON "approvals" (status)'); } catch (PDOException $e) {}
            $col = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'approvals' AND column_name = 'details'")->fetch();
            if (!$col) {
                $db->exec('ALTER TABLE "approvals" ADD COLUMN "details" TEXT DEFAULT NULL');
            }

            // ★ FIX (APPR-B006): Prevent duplicate PENDING approvals for the same entity + scope
            $dupStmt = $db->prepare('SELECT id FROM approvals WHERE entity_type = :etype AND entity_id = :eid AND scope_code = :scope AND status = :pending LIMIT 1');
            $dupStmt->execute([
                ':etype'  => $entityType,
                ':eid'    => $entityId,
                ':scope'  => $scopeCode,
                ':pending' => 'PENDING'
            ]);
            if ($dupStmt->fetch()) {
                conflictResponse('A pending approval already exists for this ' . $entityType . ' #' . $input['entity_id'] . '. It must be decided before a new one can be submitted.');
            }

            $stmt = $db->prepare(
                'INSERT INTO approvals (entity_type, entity_id, scope_code, status, submitted_by, branch, value, details)
                 VALUES (:etype, :eid, :scope, :status, :by, :branch, :value, :details)'
            );
            $detailsJson = $detailsPayload ? json_encode($detailsPayload) : null;
            $stmt->execute([
                ':etype'   => $entityType,
                ':eid'     => $entityId,
                ':scope'   => $scopeCode,
                ':status'  => 'PENDING',
                ':by'      => (int)$staff['id'],            // FIX: use staff ID (INT), not full_name (string)
                ':branch'  => $approvalBranch,
                ':value'   => sanitize($input['value']),
                ':details' => $detailsJson
            ]);
            $newId = (int)$db->lastInsertId('approvals_id_seq');
            logAudit($staff['full_name'], 'APPROVAL_SUBMIT', 'APPROVAL', (string)$newId, 'SUCCESS',
                'Submitted approval for ' . $input['entity_type'] . ' #' . $input['entity_id'],
                $staff['department'], getClientIp());
            createdResponse(['id' => $newId], 'Approval submitted successfully.');
        } catch (PDOException $e) {
            error_log('[Approvals POST] PDO error: ' . $e->getMessage());
            serverErrorResponse('Failed to submit approval.');
        }
        break;

    case 'PUT':
        if ($id === null) { validationError(['id' => 'Approval ID is required.']); }
        $input = getRequestInput();
        $newStatus = strtoupper(sanitize($input['status'] ?? ''));
        if (!in_array($newStatus, ['APPROVED', 'REJECTED', 'CANCELLED'])) {
            validationError(['status' => 'Status must be APPROVED, REJECTED, or CANCELLED.']);
        }
        try {
            $db = getDB();

            // ── Auto-migrate ──
            $db->exec("CREATE TABLE IF NOT EXISTS \"approvals\" (
                \"id\" SERIAL PRIMARY KEY,
                \"entity_type\" VARCHAR(50) NOT NULL,
                \"entity_id\" INTEGER DEFAULT NULL,
                \"scope_code\" VARCHAR(50) NOT NULL,
                \"status\" VARCHAR(50) NOT NULL DEFAULT 'PENDING' CHECK (\"status\" IN ('PENDING','APPROVED','REJECTED','CANCELLED')),
                \"submitted_by\" INTEGER DEFAULT NULL,
                \"branch\" VARCHAR(20) DEFAULT NULL,
                \"value\" TEXT DEFAULT NULL,
                \"details\" TEXT DEFAULT NULL,
                \"submitted_at\" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                \"decided_by\" INTEGER DEFAULT NULL,
                \"decided_at\" TIMESTAMP DEFAULT NULL,
                \"reason\" TEXT DEFAULT NULL,
                \"created_at\" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            try { $db->exec('CREATE INDEX IF NOT EXISTS idx_approvals_entity ON "approvals" (entity_type, entity_id)'); } catch (PDOException $e) {}
            try { $db->exec('CREATE INDEX IF NOT EXISTS idx_approvals_status ON "approvals" (status)'); } catch (PDOException $e) {}

            // ── Fetch approval record for self-approval check ──
            $fetchStmt = $db->prepare('SELECT * FROM approvals WHERE id = :id');
            $fetchStmt->execute([':id' => $id]);
            $approval = $fetchStmt->fetch(PDO::FETCH_ASSOC);
            if (!$approval) {
                errorResponse('Approval #' . $id . ' does not exist in the database.', 404);
            }

            // ★ FIX (APPR-B001): Enforce branch isolation on PUT — non-admin cannot decide approvals from other branches
            if (!apprCanAccessBranch($staff, (string)($approval['branch'] ?? ''))) {
                errorResponse('Access denied. You cannot decide on approvals from a branch you are not assigned to.', 403);
            }

            // ★ SECURITY FIX (FIN-2b-012): Prevent self-approval — dual-control principle.
            // The same person who submitted a request cannot approve or reject it.
            // ★ FIX (APPR-B007): Extended to ALL decisions (approve, reject, cancel), not just approve
            if ((int)$staff['id'] === (int)$approval['submitted_by']) {
                errorResponse('Cannot decide on your own request. Dual-control principle requires a different person.', 403);
            }

            // ★ FIX (APPR-B003): Check approval limit for APPROVED decisions
            // Parse the monetary value from the approval's "value" field or "details" JSON
            if ($newStatus === 'APPROVED') {
                $approvalAmount = 0;
                // Try to extract amount from details JSON (structured data)
                $parsedDetails = null;
                if (!empty($approval['details'])) {
                    $parsedDetails = json_decode($approval['details'], true);
                }
                if ($parsedDetails) {
                    // Priority: explicit fields in details
                    $approvalAmount = (float)($parsedDetails['netAmount'] ?? $parsedDetails['outstanding'] ?? $parsedDetails['amount'] ?? 0);
                }
                // Fallback: extract first FCFA amount from the value string
                if ($approvalAmount <= 0 && !empty($approval['value'])) {
                    // Match patterns like "5,000,000 FCFA" or "5000000" in the value string
                    if (preg_match('/([\d,]+(?:\.\d+)?)/', $approval['value'], $m)) {
                        $approvalAmount = (float)str_replace(',', '', $m[1]);
                    }
                }
                if ($approvalAmount > 0) {
                    requireApprovalLimit($approvalAmount, $staff);
                }

                if (($approval['scope_code'] ?? '') === 'loans.double_approve') {
                    $currentRole = strtoupper($staff['role'] ?? '');
                    if (!in_array($currentRole, ['ADMIN', 'MANAGER'], true)) {
                        errorResponse('Double-approval loan requests can only be approved by an ADMIN and a MANAGER.', 403);
                    }

                    $parsedDetails = apprNormalizeDetails($parsedDetails, 'loans.double_approve') ?? [];
                    $approvalChain = $parsedDetails['approval_chain'] ?? [];
                    foreach ($approvalChain as $signoff) {
                        if ((int)($signoff['staff_id'] ?? 0) === (int)$staff['id']) {
                            conflictResponse('You have already recorded a sign-off on this double-approval request. A second, distinct approver is required.');
                        }
                        if (strtoupper((string)($signoff['role'] ?? '')) === $currentRole) {
                            conflictResponse('A ' . $currentRole . ' sign-off already exists. The second approval must come from the complementary role.');
                        }
                    }

                    $approvalChain[] = [
                        'staff_id' => (int)$staff['id'],
                        'role' => $currentRole,
                        'name' => $staff['full_name'] ?? '',
                        'at' => date('c'),
                        'reason' => sanitize($input['reason'] ?? '')
                    ];
                    $parsedDetails['approval_chain'] = $approvalChain;

                    if (count($approvalChain) < 2) {
                        $partialStmt = $db->prepare(
                            'UPDATE approvals SET details = :details, reason = :reason WHERE id = :id AND status = :pending'
                        );
                        $partialStmt->execute([
                            ':details' => json_encode($parsedDetails),
                            ':reason' => sanitize($input['reason'] ?? ''),
                            ':id' => $id,
                            ':pending' => 'PENDING'
                        ]);
                        if ($partialStmt->rowCount() === 0) {
                            conflictResponse('Unable to record the first approval sign-off because this request is no longer pending.');
                        }

                        logAudit($staff['full_name'], 'APPROVAL_SIGNOFF', 'APPROVAL', (string)$id, 'SUCCESS',
                            'Recorded first sign-off for double-approval request #' . $id,
                            $staff['department'], getClientIp());
                        successResponse([
                            'id' => (int)$id,
                            'status' => 'PENDING',
                            'needs_second_approval' => true,
                            'completed_signoffs' => count($approvalChain),
                            'required_signoffs' => 2,
                            'approval_chain' => $approvalChain
                        ], 'First approval sign-off recorded. A second sign-off from the complementary role is still required.');
                    }

                    $approval['details'] = json_encode($parsedDetails);
                }
            }

            $stmt = $db->prepare(
                'UPDATE approvals SET status = :status, decided_by = :by, decided_at = NOW(), reason = :reason, details = :details
                 WHERE id = :id AND status = :pending'
            );
            $stmt->execute([
                ':status'  => $newStatus,
                ':by'      => (int)$staff['id'],
                ':reason'  => sanitize($input['reason'] ?? ''),
                ':details' => $approval['details'] ?? null,
                ':id'      => $id,
                ':pending' => 'PENDING'
            ]);
            if ($stmt->rowCount() === 0) {
                // Provide a more specific error message
                $check = $db->prepare('SELECT id, status FROM approvals WHERE id = :id');
                $check->execute([':id' => $id]);
                $existing = $check->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    errorResponse('Approval #' . $id . ' does not exist in the database. It may have been created in a previous session that did not sync properly.', 404);
                } else {
                    errorResponse('Approval #' . $id . ' already decided (status: ' . $existing['status'] . ').', 409);
                }
            }
            logAudit($staff['full_name'], 'APPROVAL_DECIDE', 'APPROVAL', (string)$id, 'SUCCESS',
                $newStatus . ' approval #' . $id, $staff['department'], getClientIp());
            successMessage('Approval ' . strtolower($newStatus) . ' successfully.');
        } catch (PDOException $e) {
            error_log('[Approvals PUT] PDO error: ' . $e->getMessage());
            serverErrorResponse('Failed to decide approval.');
        }
        break;

    default:
        errorResponse('Method not allowed.', 405);
}
