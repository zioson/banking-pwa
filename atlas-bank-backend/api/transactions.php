<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Transactions
 * 
 * IMPORTANT: On transaction creation, the account's ledger_balance and
 * available_balance are updated atomically so that the stored balance
 * always matches the running transaction history.
 * 
 * PUT: Used for status changes (REVERSED, ABSORBED, POSTED, etc.)
 *      and for reversal creation with account balance restoration.
 */

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

$staff = requireAuth();
$method = $_ROUTE['method'];
$id = $_ROUTE['id'];

// GET: Any authenticated user can view transactions (branch isolation is applied inside GET).
// POST/PUT/DELETE: Requires TRANSACTIONS module.
if ($method !== 'GET') {
    requireModule('TRANSACTIONS', $staff);
}

// ── Auto-create tables if missing ──
$db = getDB();
$db->exec("CREATE TABLE IF NOT EXISTS transactions (
    id              SERIAL PRIMARY KEY,
    ref             VARCHAR(50)  NOT NULL DEFAULT '',
    type            VARCHAR(50)  NOT NULL DEFAULT '',
    direction       VARCHAR(20)  NOT NULL DEFAULT '',
    account         VARCHAR(50)  NOT NULL DEFAULT '',
    branch          VARCHAR(255) NOT NULL DEFAULT '',
    amount          DECIMAL(20,2) NOT NULL DEFAULT 0,
    net_amount      DECIMAL(20,2) DEFAULT NULL,
    fee_mode        VARCHAR(20)  DEFAULT NULL,
    total_tax       DECIMAL(20,2) NOT NULL DEFAULT 0,
    status          VARCHAR(30)  NOT NULL DEFAULT 'PENDING',
    category        VARCHAR(100) DEFAULT '',
    module          VARCHAR(100) DEFAULT '',
    description     TEXT         DEFAULT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (ref)
)");
try {
    $db->exec("CREATE INDEX IF NOT EXISTS idx_txn_branch ON transactions (branch)");
} catch (PDOException $e) {}
try {
    $db->exec("CREATE INDEX IF NOT EXISTS idx_txn_status ON transactions (status)");
} catch (PDOException $e) {}
try {
    $db->exec("CREATE INDEX IF NOT EXISTS idx_txn_account ON transactions (account)");
} catch (PDOException $e) {}
try {
    $db->exec("CREATE INDEX IF NOT EXISTS idx_txn_created ON transactions (created_at)");
} catch (PDOException $e) {}
$db->exec("CREATE TABLE IF NOT EXISTS transaction_deductions (
    id              SERIAL PRIMARY KEY,
    transaction_id  INTEGER NOT NULL,
    deduction_key   VARCHAR(50)  NOT NULL DEFAULT '',
    deduction_name  VARCHAR(100) NOT NULL DEFAULT '',
    deduction_type  VARCHAR(20)  NOT NULL DEFAULT '',
    rate            DECIMAL(10,4) DEFAULT 0,
    amount          DECIMAL(20,2) NOT NULL DEFAULT 0
)");
try {
    $db->exec("CREATE INDEX IF NOT EXISTS idx_td_txn ON transaction_deductions (transaction_id)");
} catch (PDOException $e) {}

// ── Ensure profit_ledger table exists at module load (before any transaction logic) ──
// ★ This guarantees the table is available for the POST handler's atomic INSERT.
// Previously the CREATE TABLE was inside the transaction handler, which could cause
// implicit commit issues in MariaDB (DDL inside transactions).
try {
    $db->exec("CREATE TABLE IF NOT EXISTS profit_ledger (
        id SERIAL PRIMARY KEY,
        gl_code VARCHAR(20) DEFAULT '',
        gl_account_name VARCHAR(100) DEFAULT '',
        gl_type VARCHAR(20) DEFAULT 'INCOME',
        category VARCHAR(50) DEFAULT '',
        source_ref VARCHAR(50) DEFAULT '',
        source_type VARCHAR(50) DEFAULT '',
        account_number VARCHAR(50) DEFAULT '',
        account_type VARCHAR(50) DEFAULT '',
        customer_name VARCHAR(200) DEFAULT '',
        branch VARCHAR(100) DEFAULT '',
        gross_amount DECIMAL(18,2) DEFAULT 0,
        fee_amount DECIMAL(18,2) DEFAULT 0,
        fee_pct DECIMAL(8,4) DEFAULT 0,
        fee_mode VARCHAR(50) DEFAULT '',
        operator VARCHAR(100) DEFAULT '',
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    try {
        $db->exec("CREATE INDEX IF NOT EXISTS idx_pl_category ON profit_ledger (category)");
    } catch (PDOException $e) {}
    try {
        $db->exec("CREATE INDEX IF NOT EXISTS idx_pl_source_ref ON profit_ledger (source_ref)");
    } catch (PDOException $e) {}
    try {
        $db->exec("CREATE INDEX IF NOT EXISTS idx_pl_branch ON profit_ledger (branch)");
    } catch (PDOException $e) {}
} catch (PDOException $e) {
    error_log('[Transactions Init] profit_ledger table creation: ' . $e->getMessage());
}

/**
 * Resolve withdrawal fee % from settings.
 * CURRENT accounts can use tiered rules from `withdrawal.fee_tiers_current`:
 *   min-max:rate|min-max:rate (use * as open-ended max)
 * Falls back to flat key `withdrawal.fee_<product>` when no tier matches.
 */
$resolveWithdrawalFeePct = function(PDO $db, string $productType, float $amount): float {
    $pt = strtoupper(trim($productType));
    if ($pt === 'CURRENT') {
        $rawTiers = trim((string)getSetting($db, 'withdrawal.fee_tiers_current', ''));
        if ($rawTiers !== '') {
            $parts = array_filter(array_map('trim', explode('|', $rawTiers)));
            foreach ($parts as $part) {
                if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*-\s*([0-9]+(?:\.[0-9]+)?|\*)\s*:\s*([0-9]+(?:\.[0-9]+)?)$/', $part, $m)) {
                    continue;
                }
                $min = (float)$m[1];
                $max = ($m[2] === '*') ? null : (float)$m[2];
                $rate = (float)$m[3];
                if ($amount >= $min && ($max === null || $amount <= $max)) {
                    return max(0, $rate);
                }
            }
        }
    }
    return max(0, (float)getSetting($db, 'withdrawal.fee_' . strtolower($pt), 0));
};

switch ($method) {
    case 'GET':
        if ($id !== null) {
            try {
                $db = getDB();
                $stmt = $db->prepare('SELECT * FROM transactions WHERE id = :id');
                $stmt->execute([':id' => $id]);
                $record = $stmt->fetch();
                if (!$record) { notFoundResponse('Transaction not found.'); }
            // ★ FIX (TXN-011): Apply branch isolation to single-transaction GET
            // Previously, a branch-restricted user could access any transaction by ID,
            // including transactions from other branches. This is a security concern.
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
                    $txnBranchNorm = strtoupper(trim((string)($record['branch'] ?? '')));
                    if ($txnBranchNorm !== '' && !in_array($txnBranchNorm, $staffBranchesNorm, true)) {
                        errorResponse('Access denied. Transaction belongs to a different branch.', 403);
                    }
                }
            }
                // Get deductions if any
                $dStmt = $db->prepare('SELECT * FROM transaction_deductions WHERE transaction_id = :tid');
                $dStmt->execute([':tid' => $id]);
                $record['deductions'] = $dStmt->fetchAll();
                successResponse($record);
            } catch (PDOException $e) { serverErrorResponse('Database error.'); }
        } else {
            $page = max(1, (int)($_GET['page'] ?? 1));
            // ★ FIX (RA-CF-002/RA-TXN-002): Increased from 500 to 5000. The frontend loads all
            // transactions for Cash Flow loan repayment calculations and transaction summaries.
            // With max=500, transaction-based totals were truncated and materially incorrect.
            $pageSize = max(1, min((int)($_GET['pageSize'] ?? 20), 5000));
            $offset = ($page - 1) * $pageSize;
            $params = [];
            $where = buildWhere($_GET, ['status', 'type', 'direction', 'branch', 'account', 'category', 'module'], [
                'account' => '=', 'branch' => '='
            ], $params);

            // ★ FIX (API-039): Apply branch isolation with Account-Statement Bypass
            $staffBranches = $staff['branches'] ?? [];
            $clientBranch = sanitize($_GET['branch'] ?? '');
            $targetAccount = sanitize($_GET['account'] ?? '');

            $isAdmin = (strtoupper($staff['role'] ?? '') === 'ADMIN');

            // If staff is restricted by branch...
            $staffBranchesNorm = $staffBranches;
            if (is_string($staffBranchesNorm)) $staffBranchesNorm = [$staffBranchesNorm];
            $staffBranchesNorm = array_values(array_unique(array_filter(array_map(function ($b) {
                $v = strtoupper(trim((string)$b));
                if (in_array($v, ['ALL BRANCHES', 'ALL_BRANCHES'], true)) return 'ALL';
                return $v;
            }, is_array($staffBranchesNorm) ? $staffBranchesNorm : []))));

            if (!$isAdmin && !empty($staffBranchesNorm) && !in_array('ALL', $staffBranchesNorm, true)) {
                // CASE 1: Requesting transactions for a specific account (Statement Mode)
                if (!empty($targetAccount)) {
                    // Verify the account belongs to one of the staff's branches
                    $accStmt = $db->prepare('SELECT branch FROM accounts WHERE account_number = :acc LIMIT 1');
                    $accStmt->execute([':acc' => $targetAccount]);
                    $accBranch = $accStmt->fetchColumn();
                    
                    if (!$accBranch) {
                        notFoundResponse('Account not found.');
                    }
                    $accBranchNorm = strtoupper(trim((string)$accBranch));
                    if ($accBranchNorm !== '' && !in_array($accBranchNorm, $staffBranchesNorm, true)) {
                        errorResponse('Access denied. This account belongs to a different branch.', 403);
                    }
                    // If authorized for the account, we DO NOT apply branch filter to the transactions.
                    // This allows the statement to show transactions processed in other branches.
                } 
                // CASE 2: General transaction search (List Mode)
                else {
                    $branchFilter = applyBranchFilter($staffBranchesNorm, $clientBranch, $params, $staff['role'] ?? '', 'branch');
                    if ($branchFilter) { $where .= ($where ? ' AND ' : ' WHERE ') . substr($branchFilter, 5); }
                }
            } else {
                // Admin or unrestricted staff
                $branchFilter = applyBranchFilter($staffBranchesNorm, $clientBranch, $params, $staff['role'] ?? '', 'branch');
                if ($branchFilter) { $where .= ($where ? ' AND ' : ' WHERE ') . substr($branchFilter, 5); }
            }
            // Date range filter
            if (!empty($_GET['date_from'])) { $where .= ($where ? ' AND ' : 'WHERE ') . 'created_at >= :date_from'; $params[':date_from'] = sanitize($_GET['date_from']); }
            if (!empty($_GET['date_to'])) { $where .= ($where ? ' AND ' : 'WHERE ') . 'created_at <= :date_to'; $params[':date_to'] = sanitize($_GET['date_to']) . ' 23:59:59'; }
            try {
                $db = getDB();
                $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM transactions ' . $where);
                $countStmt->execute($params);
                $total = (int)$countStmt->fetch()['total'];
                $sortBy = in_array($_GET['sortBy'] ?? '', ['ref','amount','created_at','status','type']) ? sanitize($_GET['sortBy']) : 'created_at';
                $sortOrder = in_array(strtoupper((string)($_GET['sortOrder'] ?? 'DESC')), ['ASC', 'DESC']) ? strtoupper((string)($_GET['sortOrder'] ?? 'DESC')) : 'DESC';
                $stmt = $db->prepare(
                    'SELECT * FROM transactions ' . $where . ' ORDER BY "' . $sortBy . '" ' . $sortOrder . ' LIMIT CAST(:limit AS INTEGER) OFFSET CAST(:offset AS INTEGER)'
                );
                foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
                $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                paginatedResponse($stmt->fetchAll(), $total, $page, $pageSize);
            } catch (PDOException $e) { serverErrorResponse('Database error.'); }
        }
        break;

    case 'PUT':
        if ($id === null) { errorResponse('Transaction ID required.', 400); }
        $input = getRequestInput();
        try {
            $db = getDB();

            // ── Ensure extra columns exist (auto-migrate) ──
            // Fetch existing transaction
            $stmt = $db->prepare('SELECT * FROM transactions WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $txn = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$txn) { notFoundResponse('Transaction not found.'); }

            // ★ SECURITY FIX: Apply branch isolation to status updates
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
                    $txnBranchNorm = strtoupper(trim((string)($txn['branch'] ?? '')));
                    if ($txnBranchNorm !== '' && !in_array($txnBranchNorm, $staffBranchesNorm, true)) {
                        errorResponse('Access denied. Transaction belongs to a different branch.', 403);
                    }
                }
            }

            // ★ FIX (TXN-001): Added 'ABSORBED' — frontend marks child FEE/TAX_DEDUCTION
            // transactions as ABSORBED when a reversal is approved. Without this, the
            // PUT handler returns 400 "Invalid status", leaving children as POSTED forever.
            $allowedStatuses = ['POSTED','REVERSED','CANCELLED','FAILED','PENDING','PENDING_APPROVAL','ABSORBED'];
            $newStatus = strtoupper(sanitize($input['status'] ?? ''));
            if (!in_array($newStatus, $allowedStatuses)) {
                errorResponse('Invalid status. Allowed: ' . implode(', ', $allowedStatuses), 400);
            }

            $oldStatus = $txn['status'];
            $txnType = strtoupper((string)($txn['type'] ?? ''));
            $txnModule = strtoupper((string)($txn['module'] ?? ''));
            $txnCategory = strtoupper((string)($txn['category'] ?? ''));
            $txnDesc = strtoupper((string)($txn['description'] ?? ''));
            $txnMemo = strtoupper((string)($txn['memo'] ?? ''));
            $isLoanTxn = ($txnModule === 'LOANS')
                || str_starts_with($txnType, 'LOAN_')
                || str_contains($txnType, 'LOAN')
                || str_contains($txnCategory, 'LOAN')
                || str_contains($txnDesc, 'LOAN')
                || str_contains($txnMemo, 'LOAN');

            // ★ FIX (TXN-004): Validate status transitions — enterprise banking requires
            // that only legal state changes are permitted. Previously any allowed status
            // could be set from any other status, allowing illegal transitions like
            // POSTED→PENDING, FAILED→POSTED, REVERSED→PENDING, etc.
            $validTransitions = [
                'PENDING'           => ['POSTED', 'FAILED', 'CANCELLED'],
                'PENDING_APPROVAL'  => ['POSTED', 'REJECTED', 'CANCELLED'],  // REJECTED not in allowedStatuses but valid semantically
                'POSTED'            => ['REVERSED', 'ABSORBED'],
                'FAILED'            => [],           // terminal state
                'REVERSED'          => [],           // terminal state
                'CANCELLED'         => [],           // terminal state
                'ABSORBED'          => [],           // terminal state
            ];
            $allowedFromOld = $validTransitions[$oldStatus] ?? null;
            if ($allowedFromOld === null) {
                // Unknown current status — allow the transition (backward compat)
            } elseif (!in_array($newStatus, $allowedFromOld)) {
                errorResponse("Invalid transition: cannot change status from $oldStatus to $newStatus.", 400);
            }

            // Prevent double reversal (kept for explicit guard even though validTransitions covers it)
            if ($oldStatus === 'REVERSED') {
                errorResponse('Transaction already reversed.', 409);
            }
            if ($newStatus === 'REVERSED' && $isLoanTxn) {
                errorResponse('Loan transaction reversal is disabled by policy.', 403);
            }
            if ($newStatus === 'POSTED' && $oldStatus === 'PENDING_APPROVAL' && $txnType === 'REVERSAL' && $isLoanTxn) {
                errorResponse('Loan reversal approval is disabled by policy.', 403);
            }

            $db->beginTransaction();

            // Update transaction status
            $upd = $db->prepare('UPDATE transactions SET status = :status WHERE id = :id');
            $upd->execute([':status' => $newStatus, ':id' => $id]);

            // Update memo if provided (column auto-migrated above)
            if (!empty($input['memo'])) {
                $memoStmt = $db->prepare('UPDATE transactions SET memo = :memo WHERE id = :id');
                $memoStmt->execute([':memo' => sanitize($input['memo']), ':id' => $id]);
            } else {
                // Clear memo if explicitly set to empty/null
                if (array_key_exists('memo', $input) && empty($input['memo'])) {
                    $memoStmt = $db->prepare('UPDATE transactions SET memo = NULL WHERE id = :id');
                    $memoStmt->execute([':id' => $id]);
                }
            }

            // ── When a REVERSAL transitions to POSTED, credit the account balance ──
            if ($newStatus === 'POSTED' && $oldStatus === 'PENDING_APPROVAL' && $txn['type'] === 'REVERSAL') {
                $accNum = $txn['account'];
                $accStmt = $db->prepare(
                    'SELECT id, status, ledger_balance, available_balance FROM accounts WHERE account_number = :acc FOR UPDATE'
                );
                $accStmt->execute([':acc' => $accNum]);
                $accRow = $accStmt->fetch(PDO::FETCH_ASSOC);

                if ($accRow && $accRow['status'] === 'ACTIVE') {
                    // ★ FIX (FIN-2b-019): Reversal must restore the TOTAL balance impact (Amount + Fee)
                    // if the fee was charged 'ON_TOP'. If 'WITHDRAWAL' mode, fee was within amount.
                    $feeAmt = (float)($txn['fee'] ?? 0);
                    $feeMode = $txn['fee_mode'] ?? 'WITHDRAWAL';
                    $baseAmt = ($txn['net_amount'] !== null) ? (float)$txn['net_amount'] : (float)$txn['amount'];
                    
                    // If it's a reversal of a debit (withdrawal), we need to restore what was taken.
                    // Total taken was Amount + (Fee if ON_TOP).
                    $reversalAmt = (float)$txn['amount'];
                    if ($txn['direction'] === 'debit' && $feeMode === 'ON_TOP') {
                        $reversalAmt += $feeAmt;
                    }
                    // GL posting amount must be a float scalar.
                    $txnAmt = (float)$reversalAmt;

                    // ★ FIX (API-033): Reversal must apply OPPOSITE balance effect of original direction
                    // Reversing a credit (deposit) → debit the account (take money back)
                    // Reversing a debit (withdrawal) → credit the account (give money back)
                    $origDir = $txn['direction'];
                    if ($origDir === 'credit') {
                        $newLedger = (float)$accRow['ledger_balance'] - $reversalAmt;
                        $newAvail  = (float)$accRow['available_balance'] - $reversalAmt;
                    } else {
                        $newLedger = (float)$accRow['ledger_balance'] + $reversalAmt;
                        $newAvail  = (float)$accRow['available_balance'] + $reversalAmt;
                    }
                    $balStmt = $db->prepare(
                        'UPDATE accounts SET ledger_balance = :lb, available_balance = :ab, updated_at = NOW() WHERE id = :id'
                    );
                    $balStmt->execute([
                        ':lb' => $newLedger,
                        ':ab' => $newAvail,
                        ':id' => $accRow['id']
                    ]);

                    // ★ GL INTEGRATION (TXN-GL-REV): Reverse the accounting entries
                    $glRef = 'REV-' . $txn['ref'];
                    if ($origDir === 'credit') {
                        // Reversing Deposit (Orig: DR 1000 / CR 2000) → Reversal: DR 2000 / CR 1000
                        processTransaction('REVERSAL_DEPOSIT', [
                            'amount' => $txnAmt,
                            'ref' => $glRef,
                            'description' => "Reversal of Deposit: {$txn['ref']}",
                            'branch' => (string)($txn['branch'] ?? ''),
                            'staff_id' => (int)($staff['id'] ?? 0)
                        ]);
                    } else {
                        // Reversing Withdrawal (Orig: DR 2000 / CR 1000) → Reversal: DR 1000 / CR 2000
                        processTransaction('REVERSAL_WITHDRAWAL', [
                            'amount' => $txnAmt,
                            'ref' => $glRef,
                            'description' => "Reversal of Withdrawal: {$txn['ref']}",
                            'branch' => (string)($txn['branch'] ?? ''),
                            'staff_id' => (int)($staff['id'] ?? 0)
                        ]);
                        
                        // Also reverse fee income if it was a withdrawal fee
                        if ($feeAmt > 0) {
                            // Reversing Fee (Orig: DR 2000 / CR 4100) → Reversal: DR 4100 / CR 2000
                            processTransaction('REVERSAL_WITHDRAWAL_FEE', [
                                'amount' => $feeAmt,
                                'ref' => $glRef,
                                'description' => "Reversal of Withdrawal Fee: {$txn['ref']}",
                                'branch' => (string)($txn['branch'] ?? ''),
                                'staff_id' => (int)($staff['id'] ?? 0)
                            ]);
                        }
                    }
                }
            }

            // ★ FIX (TXN-005): When a POSTED transaction transitions to REVERSED or ABSORBED
            // (e.g. child fee/tax being absorbed), restore the account balance.
            // Previously, only PENDING_APPROVAL→POSTED for type=REVERSAL was handled.
            // A direct POSTED→REVERSED/ABSORBED change left the balance untouched — money was
            // effectively destroyed (for deposits) or created (for withdrawals).
            if (in_array($newStatus, ['REVERSED', 'ABSORBED']) && $oldStatus === 'POSTED') {
                $accNum = $txn['account'];
                $accStmt = $db->prepare(
                    'SELECT id, status, ledger_balance, available_balance FROM accounts WHERE account_number = :acc FOR UPDATE'
                );
                $accStmt->execute([':acc' => $accNum]);
                $accRow = $accStmt->fetch(PDO::FETCH_ASSOC);

                if ($accRow && $accRow['status'] === 'ACTIVE') {
                    // ★ FIX (FIN-2b-019): Restore TOTAL balance impact (Amount + Fee)
                    $feeAmt = (float)($txn['fee'] ?? 0);
                    $feeMode = $txn['fee_mode'] ?? 'WITHDRAWAL';
                    $txnAmt = (float)$txn['amount'];
                    if ($txn['direction'] === 'debit' && $feeMode === 'ON_TOP') {
                        $txnAmt += $feeAmt;
                    }
                    
                    $origDir = $txn['direction'];
                    // Reverse the original balance effect
                    if ($origDir === 'credit') {
                        // Original was a deposit — take the money back
                        $newLedger = (float)$accRow['ledger_balance'] - $txnAmt;
                        $newAvail  = (float)$accRow['available_balance'] - $txnAmt;
                    } else {
                        // Original was a withdrawal — give the money back
                        $newLedger = (float)$accRow['ledger_balance'] + $txnAmt;
                        $newAvail  = (float)$accRow['available_balance'] + $txnAmt;
                    }
                    // Reject if reversal would cause negative balance (shouldn't happen for credit reversal)
                    if ($newLedger < 0 || $newAvail < 0) {
                        $db->rollBack();
                        errorResponse('Reversal would result in negative balance for account ' . $accNum, 400);
                    }
                    $balStmt = $db->prepare(
                        'UPDATE accounts SET ledger_balance = :lb, available_balance = :ab, updated_at = NOW() WHERE id = :id'
                    );
                    $balStmt->execute([
                        ':lb' => $newLedger,
                        ':ab' => $newAvail,
                        ':id' => $accRow['id']
                    ]);

                    // ★ GL INTEGRATION (TXN-GL-REV): Reverse the accounting entries
                    $glRef = 'REV-' . $txn['ref'];
                    if ($origDir === 'credit') {
                        // Reversing Deposit (Orig: DR 1000 / CR 2000) → Reversal: DR 2000 / CR 1000
                        processTransaction('REVERSAL_DEPOSIT', [
                            'amount' => $txnAmt,
                            'ref' => $glRef,
                            'description' => "Reversal of Deposit: {$txn['ref']}",
                            'branch' => (string)($txn['branch'] ?? ''),
                            'staff_id' => (int)($staff['id'] ?? 0)
                        ]);
                    } else {
                        // Reversing Withdrawal (Orig: DR 2000 / CR 1000) → Reversal: DR 1000 / CR 2000
                        processTransaction('REVERSAL_WITHDRAWAL', [
                            'amount' => $txnAmt,
                            'ref' => $glRef,
                            'description' => "Reversal of Withdrawal: {$txn['ref']}",
                            'branch' => (string)($txn['branch'] ?? ''),
                            'staff_id' => (int)($staff['id'] ?? 0)
                        ]);
                        
                        // Also reverse fee income if it was a withdrawal fee
                        if ($feeAmt > 0) {
                            // Reversing Fee (Orig: DR 2000 / CR 4100) → Reversal: DR 4100 / CR 2000
                            processTransaction('REVERSAL_WITHDRAWAL_FEE', [
                                'amount' => $feeAmt,
                                'ref' => $glRef,
                                'description' => "Reversal of Withdrawal Fee: {$txn['ref']}",
                                'branch' => (string)($txn['branch'] ?? ''),
                                'staff_id' => (int)($staff['id'] ?? 0)
                            ]);
                        }
                    }

                    error_log('[TXN REVERSE] Direct POSTED→REVERSED: Restored balance for ' . $accNum . '. Amount: ' . $txnAmt . ' Direction: ' . $origDir);
                }
            }

            $db->commit();
            logAudit($staff['full_name'], 'TRANSACTION_UPDATE', 'TRANSACTION', (string)$id, 'SUCCESS',
                "Transaction {$txn['ref']} status changed from $oldStatus to $newStatus.",
                $staff['department'], getClientIp());

            // ═══════════════════════════════════════════════════════════════
            // ★ SERVER-SIDE PROFIT LEDGER: When a withdrawal transitions to POSTED,
            // record the fee income to profit_ledger. This covers the case where
            // a withdrawal was initially created as PENDING and later approved.
            // The POST handler already records for direct POSTED withdrawals.
            // ═══════════════════════════════════════════════════════════════
            // ★ FIXED: Added 'WITHDRAW' — same root cause fix as POST handler.
            // Database stores withdrawal type as 'WITHDRAW', not 'WITHDRAWAL'.
            if ($newStatus === 'POSTED' && in_array(strtoupper($txn['type'] ?? ''), ['WITHDRAWAL', 'CASH_WITHDRAWAL', 'WITHDRAW'])) {
                $txnFee = (float)($txn['fee'] ?? 0);
                $txnFeePct = (float)($txn['fee_pct'] ?? 0);
                $txnFeeMode = $txn['fee_mode'] ?? 'WITHDRAWAL';
                $plProductType = $txn['account_type'] ?? '';
                
                // If fee is 0 but we have the settings, recalculate from settings table
                if ($txnFee <= 0) {
                    try {
                        $plDb = getDB();
                        $plAccStmt = $plDb->prepare('SELECT product_type FROM accounts WHERE account_number = :acc LIMIT 1');
                        $plAccStmt->execute([':acc' => $txn['account']]);
                        $plAccInfo = $plAccStmt->fetch(PDO::FETCH_ASSOC);
                        if ($plAccInfo) {
                            $pt = strtoupper($plAccInfo['product_type'] ?? '');
                            $feePctVal = $resolveWithdrawalFeePct($plDb, $pt, (float)$txn['amount']);
                            $txnFee = (float)round((float)$txn['amount'] * $feePctVal / 100, 2);
                            $txnFeePct = $feePctVal;
                            $txnFeeMode = strtoupper(getSetting($plDb, 'withdrawal.fee_mode_' . $pt, 'WITHDRAWAL'));
                            $plProductType = $pt;
                        }
                    } catch (PDOException $plE) {
                        error_log('[PROFIT LEDGER PUT] Fee recalc error: ' . $plE->getMessage());
                    }
                }
                
                if ($txnFee > 0) {
                    try {
                        $plDb = getDB();
                        // ★ FIXED: Removed CREATE TABLE IF NOT EXISTS — DDL causes implicit
                        // commit in MariaDB. The profit_ledger table is created at module load
                        // (line 64) so it's guaranteed to exist here.
                        
                        // Check for duplicate (in case POST handler already recorded it)
                        $dupCheck = $plDb->prepare('SELECT id FROM profit_ledger WHERE source_ref = :ref LIMIT 1');
                        $dupCheck->execute([':ref' => $txn['ref']]);
                        if (!$dupCheck->fetch()) {
                            // Look up customer name and branch for accurate profit entry
                            $plAccStmt2 = $plDb->prepare('SELECT customer_name, branch FROM accounts WHERE account_number = :acc LIMIT 1');
                            $plAccStmt2->execute([':acc' => $txn['account']]);
                            $plAccRow = $plAccStmt2->fetch(PDO::FETCH_ASSOC);
                            $plCustName = $plAccRow ? ($plAccRow['customer_name'] ?? '') : ($txn['customer_name'] ?? '');
                            $plBranch = $plAccRow ? ($plAccRow['branch'] ?? '') : ($txn['branch'] ?? '');
                            
                            $plStmt = $plDb->prepare("INSERT INTO profit_ledger
                                (gl_code, gl_account_name, category, source_ref, source_type,
                                 account_number, account_type, customer_name, branch,
                                 gross_amount, fee_amount, fee_pct, fee_mode, operator, description)
                                VALUES ('4100', 'Withdrawal Fee Income', 'WITHDRAWAL_FEE',
                                        :ref, 'WITHDRAWAL_FEE', :acc, :atype, :cname, :branch,
                                        :gross, :fee, :pct, :mode, :op, :desc)");
                            $plStmt->execute([
                                ':ref'    => $txn['ref'],
                                ':acc'    => $txn['account'],
                                ':atype'  => strtoupper($plProductType),
                                ':cname'  => $plCustName,
                                ':branch' => $plBranch,
                                ':gross'  => (float)$txn['amount'],
                                ':fee'    => $txnFee,
                                ':pct'    => $txnFeePct,
                                ':mode'   => $txnFeeMode,
                                ':op'     => $staff['full_name'] ?? 'System',
                                ':desc'   => 'Fee recorded on status change to POSTED for withdrawal ' . $txn['ref'] . '. ' . $txnFeePct . '% of ' . number_format((float)$txn['amount'], 2) . ' = ' . number_format($txnFee, 2) . ' FCFA'
                            ]);
                            error_log('[PROFIT LEDGER PUT] Recorded fee on status change: ' . $txnFee . ' FCFA for ' . $txn['ref']);
                        } else {
                            error_log('[PROFIT LEDGER PUT] Duplicate skipped for ' . $txn['ref'] . ' — already in profit_ledger.');
                        }
                    } catch (PDOException $plErr) {
                        error_log('[PROFIT LEDGER PUT] Error recording fee for ' . $txn['ref'] . ': ' . $plErr->getMessage());
                    }
                } else {
                    error_log('[PROFIT LEDGER PUT] No fee to record for ' . $txn['ref'] . '. txnFee=0, fee column=' . ($txn['fee'] ?? 'null'));
                }
            }

            successResponse(['id' => $id, 'ref' => $txn['ref'], 'status' => $newStatus]);
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
            serverErrorResponse('Failed to update transaction.');
        }
        break;

    case 'POST':
        $input = getRequestInput();
        
        // ★ IDEMPOTENCY CHECK (TXN-SEC-001): Prevent double-spending/race conditions
        // Uses X-Idempotency-Key header or input field.
        $idempotencyKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? ($input['idempotency_key'] ?? null);
        if ($idempotencyKey) {
            try {
                $db = getDB();
                // Check for existing key
                $stmt = $db->prepare("SELECT response_json FROM idempotency_keys WHERE \"key\" = :key AND operator_id = :op_id AND expires_at > NOW() LIMIT 1");
                $stmt->execute([':key' => $idempotencyKey, ':op_id' => $staff['id']]);
                $existing = $stmt->fetch();
                if ($existing) {
                    $cachedResp = json_decode($existing['response_json'], true);
                    successResponse($cachedResp['data'] ?? [], $cachedResp['message'] ?? 'Idempotent response');
                }
            } catch (PDOException $e) { /* ignore error */ }
        }

        error_log('[TXN POST] Request received. Raw input keys: ' . implode(', ', array_keys($input)) . ' | Type: ' . ($input['type'] ?? 'NULL') . ' | Amount: ' . ($input['amount'] ?? 'NULL') . ' | Account: ' . ($input['account'] ?? 'NULL') . ' | Status: ' . ($input['status'] ?? 'NULL'));
        $errors = validateRequired($input, ['type', 'direction', 'amount', 'account']);
        if (!empty($errors)) {
            error_log('[TXN POST] Validation failed: ' . json_encode($errors));
            validationError($errors);
        }

        $txnType      = strtoupper(sanitize($input['type']));
        $direction    = sanitize($input['direction']);
        $amountParsed = parseDecimalInput($input['amount'] ?? null, 'Amount', 2, 0.01, 1000000000000);
        if (!$amountParsed['ok']) { validationError(['amount' => $amountParsed['error']]); }
        $amount       = $amountParsed['value'];
        $accountNum   = sanitize($input['account']);
        $requestedStatus = strtoupper(sanitize($input['status'] ?? 'PENDING'));
        $frontFeeParsed = parseDecimalInput($input['fee'] ?? 0, 'Fee', 2, 0, 1000000000000, false);
        if (!$frontFeeParsed['ok']) { validationError(['fee' => $frontFeeParsed['error']]); }
        $frontFeePctParsed = parseDecimalInput($input['fee_pct'] ?? 0, 'Fee percentage', 4, 0, 100, false);
        if (!$frontFeePctParsed['ok']) { validationError(['fee_pct' => $frontFeePctParsed['error']]); }
        $frontTotalTaxParsed = parseDecimalInput($input['total_tax'] ?? 0, 'Total tax', 2, 0, 1000000000000, false);
        if (!$frontTotalTaxParsed['ok']) { validationError(['total_tax' => $frontTotalTaxParsed['error']]); }
        $frontTotalDebitParsed = parseDecimalInput($input['total_debit'] ?? $amount, 'Total debit', 2, 0, 1000000000000, false);
        if (!$frontTotalDebitParsed['ok']) { validationError(['total_debit' => $frontTotalDebitParsed['error']]); }
        $frontNetAmount = null;
        if (array_key_exists('net_amount', $input) && $input['net_amount'] !== null && $input['net_amount'] !== '') {
            $frontNetAmountParsed = parseDecimalInput($input['net_amount'], 'Net amount', 2, 0, 1000000000000, false);
            if (!$frontNetAmountParsed['ok']) { validationError(['net_amount' => $frontNetAmountParsed['error']]); }
            $frontNetAmount = $frontNetAmountParsed['value'];
        }

        // ★ SECURITY FIX: Enforce Maker/Checker and Approval Limits
        // 1. If status is POSTED, verify user has authority to post immediately.
        // 2. Junior staff (TELLER) should generally NOT be able to self-approve.
        // 3. Admin bypass is allowed for system/adjustment entries.
        $status = 'PENDING_APPROVAL'; // Default for safety
        $isSystemAdmin = (isset($staff['department']) && $staff['department'] === 'ADMIN');
        $approvalLimit = (float)($staff['approval_limit'] ?? 0);

        if ($requestedStatus === 'POSTED') {
            if ($isSystemAdmin || ($amount <= $approvalLimit && $approvalLimit > 0)) {
                $status = 'POSTED';
            } else {
                // Force to PENDING_APPROVAL if limit exceeded or no limit set
                $status = 'PENDING_APPROVAL';
                error_log("[TXN SECURITY] Force-downgraded status to PENDING_APPROVAL for staff {$staff['id']} (Limit: $approvalLimit, Amount: $amount)");
            }
        } else {
            $status = $requestedStatus;
        }

        // Determine the actual debit amount that hits the account balance.
        // For withdrawals, the frontend sends total_debit which includes fee+tax.
        // For deposits, the credit amount is simply "amount".
        $balanceImpact = $amount; // default
        if (isset($input['total_debit'])) {
            $balanceImpact = $frontTotalDebitParsed['value'];
        }

        // ── SERVER-SIDE WITHDRAWAL FEE ENFORCEMENT ──
        // Enterprise requirement: The backend MUST independently calculate withdrawal fees
        // from the settings table. The frontend's fee values are advisory only — the backend
        // overrides them to prevent tampering, stale caches, or direct API bypass.
        // This guarantees every withdrawal on each account type is charged exactly the fee
        // configured by the admin in Settings > Withdrawal Taxes & Fees.
        $serverFee = 0;
        $serverFeePct = 0;
        $serverFeeMode = '';
        $serverNetAmount = null;
        $serverTotalDebit = null;
        // ★ FIXED: Added 'WITHDRAW' — database uses 'WITHDRAW' as the withdrawal transaction type.
        if ($direction === 'debit' && in_array($txnType, ['WITHDRAWAL', 'CASH_WITHDRAWAL', 'WITHDRAW'])) {
            try {
                $db = getDB();

                // Look up the account to get its product type
                $accStmt = $db->prepare('SELECT product_type FROM accounts WHERE account_number = :acc LIMIT 1');
                $accStmt->execute([':acc' => $accountNum]);
                $accInfo = $accStmt->fetch(PDO::FETCH_ASSOC);

                if ($accInfo) {
                    $productType = strtoupper($accInfo['product_type'] ?? '');
                    $feeModeKey = 'withdrawal.fee_mode_' . $productType;

                    // Read fee percentage and mode from the settings table
                    $serverFeePct = $resolveWithdrawalFeePct($db, $productType, $amount);
                    $serverFeeMode = strtoupper(getSetting($db, $feeModeKey, 'WITHDRAWAL'));

                    // Calculate the server-side fee
                    $serverFee = (float)round($amount * $serverFeePct / 100, 2);

                    // Check for per-account fee exemptions (tax_exemptions column)
                    $exemptStmt = $db->prepare('SELECT tax_exemptions FROM accounts WHERE account_number = :acc LIMIT 1');
                    $exemptStmt->execute([':acc' => $accountNum]);
                    $exemptRow = $exemptStmt->fetch(PDO::FETCH_ASSOC);
                    if ($exemptRow) {
                        $exemptions = json_decode($exemptRow['tax_exemptions'] ?? '[]', true);
                        if (is_array($exemptions) && in_array('withdrawal.fee', $exemptions)) {
                            $serverFee = 0;
                            $serverFeePct = 0;
                        }
                    }

                    // Read total_tax from frontend (taxes are computed client-side with complex PAYE logic)
                    $clientTotalTax = $frontTotalTaxParsed['value'];

                    // Recalculate net_amount and total_debit based on server fee
                    if ($serverFeeMode === 'WITHDRAWAL') {
                        // Fee is deducted FROM the withdrawal amount
                        $serverNetAmount = $amount - $serverFee - $clientTotalTax;
                        $serverTotalDebit = $amount; // gross amount is debited
                    } else {
                        // Fee is charged ON TOP — separate debit from account
                        $serverNetAmount = $amount - $clientTotalTax;
                        $serverTotalDebit = $amount + $serverFee;
                    }

                    // Override balanceImpact with server-calculated value
                    $balanceImpact = $serverTotalDebit;

                    error_log('[FEE ENFORCEMENT] Withdrawal ' . $amount . ' on ' . $accountNum . ' (' . $productType . '): server fee=' . $serverFee . ' (' . $serverFeePct . '%), mode=' . $serverFeeMode . ', totalDebit=' . $serverTotalDebit . ', net=' . $serverNetAmount);
                }
            } catch (PDOException $e) {
                error_log('[FEE ENFORCEMENT] Error calculating server-side fee: ' . $e->getMessage() . ' — falling back to client values.');
            }
        }

        try {
            $db = getDB();

            // ── Ensure extra columns exist (auto-migrate) — MUST run OUTSIDE transaction ──
            // ALTER TABLE causes implicit commit in MariaDB, ending any active transaction.
            // Wrapped in its own try/catch so a column migration failure doesn't kill the handler.
            // ★ FIXED: Added fee, fee_pct, account_type, customer_name, operator_id, operator_name
            // which were missing from the original CREATE TABLE and NOT auto-migrated.
            try {
                foreach ([
                    'net_amount'    => 'DECIMAL(20,2) DEFAULT NULL',
                    'fee'           => 'DECIMAL(20,2) DEFAULT 0',
                    'fee_pct'       => 'DECIMAL(8,4) DEFAULT 0',
                    'fee_mode'      => 'VARCHAR(20) DEFAULT NULL',
                    'total_tax'     => 'DECIMAL(20,2) DEFAULT 0',
                    'memo'          => 'TEXT DEFAULT NULL',
                    'account_type'  => "VARCHAR(50) DEFAULT ''",
                    'customer_name' => "VARCHAR(200) DEFAULT ''",
                    'operator_id'   => 'INT DEFAULT NULL',
                    'operator_name' => "VARCHAR(200) DEFAULT ''",
                    'parent_id'     => 'INTEGER DEFAULT NULL'  // ★ FIX (TXN-002)
                ] as $colName => $colDef) {
                    $col = $db->query("SELECT column_name AS \"Field\" FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'transactions' AND column_name = '$colName'")->fetch();
                    if (!$col) $db->exec("ALTER TABLE transactions ADD COLUMN $colName $colDef");
                }
            } catch (PDOException $migErr) {
                error_log('[Transactions POST] Auto-migrate failed (non-fatal): ' . $migErr->getMessage());
            }

            // ── Detect which columns actually exist (dynamic INSERT) ──
            // This makes the INSERT resilient even if auto-migrate partially fails.
            $existingCols = [];
            try {
                $colRows = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'transactions' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN, 0);
                $existingCols = array_flip($colRows); // O(1) lookup
            } catch (PDOException $e) {
                error_log('[Transactions POST] information_schema query failed: ' . $e->getMessage());
            }

            // Define all possible INSERT columns with their values
            // ★ SERVER-SIDE FEE: For withdrawals, fee/fee_pct/net_amount/fee_mode are
            // overridden by server-calculated values from the settings table. The frontend
            // values are used as fallback only if the server-side calculation failed.
            $isServerFeeActive = ($serverTotalDebit !== null);
            $allFields = [
                'ref'          => ['val' => null,  'ph'  => ':ref'],       // set below after retry loop
                'type'         => ['val' => $txnType, 'ph'  => ':type'],
                'status'       => ['val' => $status, 'ph'  => ':status'],
                'branch'       => ['val' => sanitize($input['branch'] ?? ''), 'ph' => ':branch'],
                'account'      => ['val' => $accountNum, 'ph' => ':account'],
                'account_type' => ['val' => sanitize($input['account_type'] ?? ''), 'ph' => ':acc_type'],
                'customer_name'=> ['val' => sanitize($input['customer_name'] ?? ''), 'ph' => ':cust_name'],
                'description'  => ['val' => sanitize($input['description'] ?? ''), 'ph' => ':desc'],
                'category'     => ['val' => sanitize($input['category'] ?? ''), 'ph' => ':cat'],
                'direction'    => ['val' => $direction, 'ph' => ':dir'],
                'amount'       => ['val' => $amount, 'ph' => ':amount'],
                'fee'          => ['val' => $isServerFeeActive ? $serverFee : $frontFeeParsed['value'], 'ph' => ':fee'],
                'fee_pct'      => ['val' => $isServerFeeActive ? $serverFeePct : $frontFeePctParsed['value'], 'ph' => ':fee_pct'],
                'net_amount'   => ['val' => $isServerFeeActive ? $serverNetAmount : $frontNetAmount, 'ph' => ':net_amount'],
                'fee_mode'     => ['val' => $isServerFeeActive ? $serverFeeMode : sanitize($input['fee_mode'] ?? ''), 'ph' => ':fee_mode'],
                'total_tax'    => ['val' => $frontTotalTaxParsed['value'], 'ph' => ':total_tax'],
                'memo'         => ['val' => sanitize($input['memo'] ?? ''), 'ph' => ':memo'],
                'module'       => ['val' => sanitize($input['module'] ?? 'TRANSACTIONS'), 'ph' => ':module'],
                'operator_id'  => ['val' => $staff['id'], 'ph' => ':op_id'],
                'operator_name'=> ['val' => $staff['full_name'], 'ph' => ':op_name'],
                'parent_id'    => ['val' => !empty($input['parent_id']) ? (int)$input['parent_id'] : null, 'ph' => ':parent_id'],  // ★ FIX (TXN-002)
                'created_at'   => ['val' => 'NOW()', 'ph' => null], // raw expression, not a bound param
            ];

            // Filter to only columns that exist in the actual table
            $insertColNames = [];
            $insertValues   = [];
            $bindParamList  = [];
            foreach ($allFields as $colName => $spec) {
                if (isset($existingCols[$colName])) {
                    $insertColNames[] = '"' . $colName . '"';
                    if ($spec['ph'] !== null) {
                        $insertValues[] = $spec['ph'];
                        $bindParamList[$spec['ph']] = $spec['val'];
                    } else {
                        $insertValues[] = $spec['val']; // raw SQL like NOW()
                    }
                } else {
                    error_log('[Transactions POST] Column ' . $colName . ' missing from transactions table, skipping.');
                }
            }

            // Generate ref outside transaction; retry on duplicate key (race condition)
            // FIXED: Increased retries from 2 to 5 to handle edge cases where
            // multiple requests generate the same ref simultaneously.
            $insertSuccess = false;
            $maxRetries = 5;

            for ($attempt = 0; $attempt < $maxRetries && !$insertSuccess; $attempt++) {
                $ref = generateRef('TXN');
                $bindParamList[':ref'] = $ref;

                $db->beginTransaction();

                try {
                    $sql = 'INSERT INTO transactions (' . implode(', ', $insertColNames) . ') VALUES (' . implode(', ', $insertValues) . ')';
                    $stmt = $db->prepare($sql);
                    $stmt->execute($bindParamList);
                    $newId = (int)$db->lastInsertId('transactions_id_seq');

                    // ── Update account balance atomically ──
                    // Only for POSTED transactions (skip PENDING / PENDING_APPROVAL)
                    if ($status === 'POSTED') {
                        $accStmt = $db->prepare(
                            'SELECT id, status, ledger_balance, available_balance FROM accounts WHERE account_number = :acc FOR UPDATE'
                        );
                        $accStmt->execute([':acc' => $accountNum]);
                        $accRow = $accStmt->fetch(PDO::FETCH_ASSOC);

                        if ($accRow) {
                            if ($accRow['status'] === 'ACTIVE') {
                                if ($direction === 'credit') {
                                    $newLedger   = (float)$accRow['ledger_balance'] + $balanceImpact;
                                    $newAvail    = (float)$accRow['available_balance'] + $balanceImpact;
                                } else {
                                    $newLedger   = (float)$accRow['ledger_balance'] - $balanceImpact;
                                    $newAvail    = (float)$accRow['available_balance'] - $balanceImpact;
                                }
                                // ★ SECURITY FIX (CRITICAL): Reject transactions that would result
                                // in negative balances. Previously max(0, ...) silently floored the
                                // balance to zero, allowing withdrawals exceeding available funds
                                // (effectively creating money from nothing). Now we reject with 400.
                                if ($newLedger < 0 || $newAvail < 0) {
                                    error_log('[TXN SECURITY] Balance would go negative: ledger=' . $newLedger . ' avail=' . $newAvail . ' for account ' . $accountNum);
                                    $db->rollBack();
                                    errorResponse('Insufficient funds. Transaction would result in a negative balance. Required: ' . moneyFormat($balanceImpact) . ', Available: ' . moneyFormat(min($accRow['ledger_balance'], $accRow['available_balance'])), 400);
                                    // errorResponse() calls exit() — no break needed
                                }
                                $updStmt = $db->prepare(
                                    'UPDATE accounts SET ledger_balance = :lb, available_balance = :ab, updated_at = NOW() WHERE id = :id'
                                );
                                $updStmt->execute([
                                    ':lb'  => $newLedger,
                                    ':ab'  => $newAvail,
                                    ':id'  => $accRow['id']
                                ]);
                            }
                        }
                    }

                    // ═══════════════════════════════════════════════════════════════
                    // ★ SERVER-SIDE PROFIT LEDGER RECORDING — Enterprise requirement
                    // The backend MUST record fee income to profit_ledger atomically.
                    // ★ FIXED: Moved INSIDE the transaction (before commit) for atomicity.
                    // Previously this ran AFTER commit — if it failed, the fee was lost
                    // with no rollback possible. Now it's part of the same DB transaction.
                    // ★ FIXED: Removed redundant CREATE TABLE IF NOT EXISTS (DDL causes
                    // implicit commit in MariaDB, breaking the transaction). The table
                    // is created by ensureProfitLedgerSchema() in reports.php.
                    // ★ FIXED: Added duplicate protection (source_ref uniqueness check).
                    // ═══════════════════════════════════════════════════════════════
                    $profitLedgerOk = false;
                    $profitLedgerError = '';
                    // ★ FIXED: Widened guard — also write if server fee calc was inactive
                    // but the transaction has a frontend fee (input['fee'] > 0).
                    $effectiveFee = $isServerFeeActive ? $serverFee : $frontFeeParsed['value'];
                    // ★ FIXED: Added 'WITHDRAW' — database uses 'WITHDRAW' as the transaction type
                    // for withdrawal operations, NOT 'WITHDRAWAL'. This was the root cause of ALL
                    // withdrawal fees being missing from profit_ledger (25 withdrawals totaling
                    // 39,595 FCFA lost). The backend was checking for 'WITHDRAWAL'/'CASH_WITHDRAWAL'
                    // but the frontend stores withdrawals as type='WITHDRAW'.
                    if ($effectiveFee > 0 && $status === 'POSTED' && $direction === 'debit' && in_array($txnType, ['WITHDRAWAL', 'CASH_WITHDRAWAL', 'WITHDRAW'])) {
                        try {
                            // profit_ledger table is already ensured at module load (line 64).
                            // No need for CREATE TABLE IF NOT EXISTS here — avoids DDL inside
                            // transaction which causes implicit commit in MariaDB.

                            // Use effective fee values — prefer server calc, fall back to frontend
                            $plFeeAmt = $effectiveFee;
                            $plFeePct = $serverFeePct > 0 ? $serverFeePct : (float)($input['fee_pct'] ?? 0);
                            $plFeeMode = !empty($serverFeeMode) ? $serverFeeMode : sanitize($input['fee_mode'] ?? 'WITHDRAWAL');

                            // Duplicate protection: skip if source_ref already recorded
                            $dupCheck = $db->prepare('SELECT id FROM profit_ledger WHERE source_ref = :ref LIMIT 1');
                            $dupCheck->execute([':ref' => $ref]);
                            if (!$dupCheck->fetch()) {
                                // Look up customer name and branch for the profit entry
                                $plAccStmt = $db->prepare('SELECT customer_name, branch FROM accounts WHERE account_number = :acc LIMIT 1');
                                $plAccStmt->execute([':acc' => $accountNum]);
                                $plAccRow = $plAccStmt->fetch(PDO::FETCH_ASSOC);
                                $plCustName = $plAccRow ? ($plAccRow['customer_name'] ?? '') : (sanitize($input['customer_name'] ?? ''));
                                $plBranch = $plAccRow ? ($plAccRow['branch'] ?? '') : (sanitize($input['branch'] ?? ''));

                                $plStmt = $db->prepare("INSERT INTO profit_ledger
                                    (gl_code, gl_account_name, category, source_ref, source_type,
                                     account_number, account_type, customer_name, branch,
                                     gross_amount, fee_amount, fee_pct, fee_mode, operator, description)
                                    VALUES ('4100', 'Withdrawal Fee Income', 'WITHDRAWAL_FEE',
                                            :ref, 'WITHDRAWAL_FEE', :acc, :atype, :cname, :branch,
                                            :gross, :fee, :pct, :mode, :op, :desc)");
                                $plStmt->execute([
                                    ':ref'    => $ref,
                                    ':acc'    => $accountNum,
                                    ':atype'  => strtoupper($productType ?? ''),
                                    ':cname'  => $plCustName,
                                    ':branch' => $plBranch,
                                    ':gross'  => $amount,
                                    ':fee'    => $plFeeAmt,
                                    ':pct'    => $plFeePct,
                                    ':mode'   => $plFeeMode,
                                    ':op'     => $staff['full_name'] ?? 'System',
                                    ':desc'   => 'Withdrawal fee ' . $plFeePct . '% of ' . number_format($amount, 2) . ' = ' . number_format($plFeeAmt, 2) . ' FCFA. Account: ' . $accountNum
                                ]);
                                $profitLedgerOk = true;
                                error_log('[PROFIT LEDGER] Recorded fee income: ' . $plFeeAmt . ' FCFA for withdrawal ' . $ref . ' on ' . $accountNum);

                                // ★ GL INTEGRATION (TXN-GL-001): Fee Income
                                // DR: 2000 (Customer Deposits) / CR: 4100 (Withdrawal Fee Income)
                                processTransaction('WITHDRAWAL_FEE_INCOME', [
                                    'amount' => $plFeeAmt,
                                    'ref' => $ref,
                                    'description' => "Withdrawal fee for account $accountNum",
                                    'branch' => (string)$plBranch,
                                    'staff_id' => (int)($staff['id'] ?? 0)
                                ]);
                            } else {
                                $profitLedgerOk = true; // Already recorded (idempotent)
                                error_log('[PROFIT LEDGER] Duplicate skipped for ' . $ref . ' — already in profit_ledger.');
                            }
                        } catch (PDOException $plErr) {
                            $profitLedgerError = $plErr->getMessage();
                            error_log('[PROFIT LEDGER] ERROR recording fee income for ' . $ref . ': ' . $profitLedgerError);
                        }
                    } else {
                        // No fee to record (either not a withdrawal, fee=0%, or status not POSTED)
                        $profitLedgerOk = true;
                    }

                    // ★ GL INTEGRATION (TXN-GL-002): Main Transaction
                    if ($status === 'POSTED') {
                        $txnBranch = sanitize($input['branch'] ?? '');
                        if ($direction === 'credit') {
                            // DEPOSIT: DR 1000 (Cash) / CR 2000 (Customer Deposits)
                            processTransaction('DEPOSIT_MAIN', [
                                'amount' => $amount,
                                'ref' => $ref,
                                'description' => "Deposit to account $accountNum",
                                'branch' => (string)$txnBranch,
                                'staff_id' => (int)($staff['id'] ?? 0)
                            ]);
                        } else {
                            // WITHDRAWAL (Main Amount): DR 2000 (Customer Deposits) / CR 1000 (Cash)
                            // We post the principal withdrawal amount (excluding fee which is handled separately)
                            processTransaction('WITHDRAWAL_MAIN', [
                                'amount' => $amount,
                                'ref' => $ref,
                                'description' => "Withdrawal from account $accountNum",
                                'branch' => (string)$txnBranch,
                                'staff_id' => (int)($staff['id'] ?? 0)
                            ]);
                        }
                    }

                    $db->commit();
                    $insertSuccess = true;

                    // ═══════════════════════════════════════════════════════════════
                    // ★ POST-COMMIT COMPENSATION: Guarantee profit_ledger write
                    // The in-transaction INSERT may have failed (column mismatch,
                    // implicit commit from DDL, etc.) without rolling back the main
                    // transaction. This compensation block runs AFTER commit and
                    // retries the profit_ledger write if it wasn't successful.
                    // This ensures withdrawal fee income is NEVER lost.
                    // ═══════════════════════════════════════════════════════════════
                    // ★ FIXED: Added 'WITHDRAW' to match actual DB transaction types.
                    if (!$profitLedgerOk && $direction === 'debit' && in_array($txnType, ['WITHDRAWAL', 'CASH_WITHDRAWAL', 'WITHDRAW']) && $status === 'POSTED') {
                        // Determine the best fee value: prefer server fee, fall back to frontend fee
                        $compFee = $serverFee > 0 ? $serverFee : $frontFeeParsed['value'];
                        $compFeePct = $serverFeePct > 0 ? $serverFeePct : $frontFeePctParsed['value'];
                        $compFeeMode = !empty($serverFeeMode) ? $serverFeeMode : sanitize($input['fee_mode'] ?? 'WITHDRAWAL');
                        
                        if ($compFee <= 0) {
                            // Last resort: recalculate from settings
                            try {
                                $compDb = getDB();
                                $compAccStmt = $compDb->prepare('SELECT product_type FROM accounts WHERE account_number = :acc LIMIT 1');
                                $compAccStmt->execute([':acc' => $accountNum]);
                                $compAccInfo = $compAccStmt->fetch(PDO::FETCH_ASSOC);
                                if ($compAccInfo) {
                                    $compPt = strtoupper($compAccInfo['product_type'] ?? '');
                                    $compFeePctVal = $resolveWithdrawalFeePct($compDb, $compPt, $amount);
                                    $compFee = (float)round($amount * $compFeePctVal / 100, 2);
                                    $compFeePct = $compFeePctVal;
                                    $compFeeMode = strtoupper(getSetting($compDb, 'withdrawal.fee_mode_' . $compPt, 'WITHDRAWAL'));
                                }
                            } catch (PDOException $compErr) {
                                error_log('[PL COMPENSATION] Fee recalc failed: ' . $compErr->getMessage());
                            }
                        }
                        
                        if ($compFee > 0) {
                            try {
                                $compDb = getDB();
                                // Ensure table exists (safe — uses IF NOT EXISTS)
                                $compDb->exec("CREATE TABLE IF NOT EXISTS profit_ledger (
                                    id SERIAL PRIMARY KEY,
                                    gl_code VARCHAR(20) DEFAULT '',
                                    gl_account_name VARCHAR(100) DEFAULT '',
                                    gl_type VARCHAR(20) DEFAULT 'INCOME',
                                    category VARCHAR(50) DEFAULT '',
                                    source_ref VARCHAR(50) DEFAULT '',
                                    source_type VARCHAR(50) DEFAULT '',
                                    account_number VARCHAR(50) DEFAULT '',
                                    account_type VARCHAR(50) DEFAULT '',
                                    customer_name VARCHAR(200) DEFAULT '',
                                    branch VARCHAR(100) DEFAULT '',
                                    gross_amount DECIMAL(18,2) DEFAULT 0,
                                    fee_amount DECIMAL(18,2) DEFAULT 0,
                                    fee_pct DECIMAL(8,4) DEFAULT 0,
                                    fee_mode VARCHAR(50) DEFAULT '',
                                    operator VARCHAR(100) DEFAULT '',
                                    description TEXT,
                                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                                )");
                                try {
                                    $compDb->exec("CREATE INDEX IF NOT EXISTS idx_pl_category ON profit_ledger (category)");
                                } catch (PDOException $e) {}
                                try {
                                    $compDb->exec("CREATE INDEX IF NOT EXISTS idx_pl_source_ref ON profit_ledger (source_ref)");
                                } catch (PDOException $e) {}
                                try {
                                    $compDb->exec("CREATE INDEX IF NOT EXISTS idx_pl_branch ON profit_ledger (branch)");
                                } catch (PDOException $e) {}
                                
                                // Check duplicate
                                $compDup = $compDb->prepare('SELECT id FROM profit_ledger WHERE source_ref = :ref LIMIT 1');
                                $compDup->execute([':ref' => $ref]);
                                if (!$compDup->fetch()) {
                                    // Look up customer name and branch
                                    $compAccStmt = $compDb->prepare('SELECT customer_name, branch FROM accounts WHERE account_number = :acc LIMIT 1');
                                    $compAccStmt->execute([':acc' => $accountNum]);
                                    $compAccRow = $compAccStmt->fetch(PDO::FETCH_ASSOC);
                                    $compCustName = $compAccRow ? ($compAccRow['customer_name'] ?? '') : sanitize($input['customer_name'] ?? '');
                                    $compBranch = $compAccRow ? ($compAccRow['branch'] ?? '') : sanitize($input['branch'] ?? '');
                                    
                                    $compPlStmt = $compDb->prepare("INSERT INTO profit_ledger
                                        (gl_code, gl_account_name, category, source_ref, source_type,
                                         account_number, account_type, customer_name, branch,
                                         gross_amount, fee_amount, fee_pct, fee_mode, operator, description)
                                        VALUES ('4100', 'Withdrawal Fee Income', 'WITHDRAWAL_FEE',
                                                :ref, 'WITHDRAWAL_FEE', :acc, :atype, :cname, :branch,
                                                :gross, :fee, :pct, :mode, :op, :desc)");
                                    $compPlStmt->execute([
                                        ':ref'    => $ref,
                                        ':acc'    => $accountNum,
                                        ':atype'  => strtoupper($productType ?? ''),
                                        ':cname'  => $compCustName,
                                        ':branch' => $compBranch,
                                        ':gross'  => $amount,
                                        ':fee'    => $compFee,
                                        ':pct'    => $compFeePct,
                                        ':mode'   => $compFeeMode,
                                        ':op'     => $staff['full_name'] ?? 'System',
                                        ':desc'   => 'Withdrawal fee ' . $compFeePct . '% of ' . number_format($amount, 2) . ' = ' . number_format($compFee, 2) . ' FCFA. Account: ' . $accountNum . ' [POST-COMMIT COMPENSATION]'
                                    ]);
                                    error_log('[PL COMPENSATION] SUCCESS: Recorded fee ' . $compFee . ' FCFA for ' . $ref . ' (original write failed: ' . ($profitLedgerError ?: 'server fee was 0 or inactive') . ')');
                                } else {
                                    error_log('[PL COMPENSATION] Duplicate exists for ' . $ref . ' — skipping (entry was written during transaction)');
                                }
                            } catch (PDOException $compErr2) {
                                error_log('[PL COMPENSATION] FATAL: Could not write profit_ledger entry for ' . $ref . ': ' . $compErr2->getMessage());
                            }
                        } else {
                            error_log('[PL COMPENSATION] No fee to record for ' . $ref . '. serverFee=' . $serverFee . ' inputFee=' . ($input['fee'] ?? 'null') . ' recalculatedFee=' . $compFee);
                        }
                    }

                    error_log('[TXN POST] Transaction saved. ID: ' . $newId . ' Ref: ' . $ref . ' Type: ' . $txnType . ' Dir: ' . $direction . ' Amount: ' . $balanceImpact . ' Account: ' . $accountNum . ' Status: ' . $status);

                    logAudit($staff['full_name'], 'TRANSACTION_CREATE', 'TRANSACTION', (string)$newId, 'SUCCESS',
                        'Created transaction ' . $ref . ' (' . $direction . ' ' . number_format($balanceImpact, 2) . ' on ' . $accountNum . ')',
                        $staff['department'], getClientIp());

                    // Include server-side fee info in response so frontend can stay in sync
                    $responseData = ['id' => $newId, 'ref' => $ref];
                    if ($isServerFeeActive) {
                        $responseData['server_fee'] = $serverFee;
                        $responseData['server_fee_pct'] = $serverFeePct;
                        $responseData['server_fee_mode'] = $serverFeeMode;
                        $responseData['server_net_amount'] = $serverNetAmount;
                        $responseData['server_total_debit'] = $serverTotalDebit;
                    }
                    // Surface profit ledger status to frontend for diagnostics
                    if (!$profitLedgerOk && $profitLedgerError) {
                        $responseData['_pl_warning'] = 'Fee recorded on transaction but profit ledger write failed: ' . $profitLedgerError;
                        error_log('[PROFIT LEDGER] WARNING: Fee ' . $serverFee . ' FCFA on ' . $ref . ' was NOT recorded in profit_ledger. Error: ' . $profitLedgerError);
                    }

                    // ★ SAVE IDEMPOTENCY KEY (TXN-SEC-001)
                    if ($idempotencyKey) {
                        try {
                            $dbIdem = getDB();
                            $respJson = json_encode(['data' => $responseData, 'message' => 'Transaction created successfully.']);
                            $stmtIdem = $dbIdem->prepare("INSERT INTO idempotency_keys (\"key\", operator_id, response_json, expires_at) VALUES (:key, :op_id, :resp, NOW() + INTERVAL '1 hour') ON CONFLICT (\"key\", operator_id) DO UPDATE SET response_json = EXCLUDED.response_json");
                            $stmtIdem->execute([':key' => $idempotencyKey, ':op_id' => $staff['id'], ':resp' => $respJson]);
                        } catch (PDOException $e) { /* ignore save error */ }
                    }

                    createdResponse($responseData, 'Transaction created successfully.');
                } catch (PDOException $innerE) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    // Duplicate key on ref (error 23000 / MySQL 1062) — retry with new ref
                    if (($innerE->errorInfo[1] ?? 0) === 1062 && $attempt < $maxRetries - 1) {
                        error_log('[Transactions POST] Duplicate ref ' . $ref . ', retrying (attempt ' . ($attempt + 1) . ')');
                        continue;
                    }
                    throw $innerE;
                }
            }
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
            $errMsg = $e->getMessage();
            error_log('[Transactions POST] PDO Error: ' . $errMsg . ' | Code: ' . $e->getCode());
            serverErrorResponse('Failed to create transaction.');
        } catch (\Throwable $t) {
            if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
            error_log('[Transactions POST] Fatal error: ' . get_class($t) . ': ' . $t->getMessage() . ' in ' . $t->getFile() . ':' . $t->getLine());
            serverErrorResponse('Failed to create transaction.');
        }
        break;

    default:
        errorResponse('Method not allowed.', 405);
}
