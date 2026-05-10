<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Loan Applications & Due Diligence Checks
 *
 * Reads from the dedicated loan_applications and loan_application_checks tables.
 * GET  — list all applications (with embedded checks) or fetch one by ID
 * POST — create a new loan application (also creates default checks)
 * PUT  — update application status, create/update checks
 */

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

$staff = requireModule('LOANS');
$method = $_ROUTE['method'];
$id = $_ROUTE['id'];

function laNormalizeBranches(array $branches): array {
    return array_values(array_unique(array_filter(array_map(function ($b) {
        $v = strtoupper(trim((string)$b));
        if (in_array($v, ['ALL BRANCHES', 'ALL_BRANCHES'], true)) return 'ALL';
        return $v;
    }, $branches))));
}

function laCanAccessBranch(array $staff, string $branch): bool {
    $branch = strtoupper(trim($branch));
    if ($branch === '') return true;
    if (in_array(strtoupper($staff['role'] ?? ''), ['ADMIN', 'SUPER_ADMIN'])) return true;
    $staffBranches = laNormalizeBranches($staff['branches'] ?? []);
    if (in_array('ALL', $staffBranches, true)) return true;
    return empty($staffBranches) ? false : in_array($branch, $staffBranches, true);
}

/**
 * Safely add a column if it doesn't exist
 */
function laAddCol(PDO $db, string $table, string $col, string $def): void {
    try {
        $r = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?");
        $r->execute([$table, $col]);
        if (!$r->fetch()) $db->exec("ALTER TABLE \"$table\" ADD COLUMN \"$col\" $def");
    } catch (PDOException $e) {
        error_log("[LoanApps Schema] laAddCol($table, $col) failed: " . $e->getMessage());
    }
}

/**
 * Ensure loan_applications and loan_application_checks tables exist
 */
function laEnsureSchema(PDO $db): void {
    // ── loan_applications table ──
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS loan_applications (
            id SERIAL PRIMARY KEY,
            ref VARCHAR(30) NOT NULL UNIQUE,
            customer_id INT NOT NULL,
            customer_name VARCHAR(200) DEFAULT '',
            amount DECIMAL(20,2) NOT NULL,
            term INT NOT NULL,
            interest_rate DECIMAL(10,2) DEFAULT 0,
            purpose TEXT DEFAULT NULL,
            status VARCHAR(20) DEFAULT 'PENDING',
            branch VARCHAR(200) DEFAULT '',
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            decided_by INT DEFAULT NULL,
            decided_at TIMESTAMP NULL,
            decision_reason TEXT DEFAULT NULL,
            loan_id INT DEFAULT NULL,
            guarantor_customer_id INT DEFAULT NULL,
            guarantor_account_id INT DEFAULT NULL,
            guarantor_account_number VARCHAR(30) DEFAULT ''
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ref ON loan_applications (ref)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_status ON loan_applications (status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_customer ON loan_applications (customer_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_branch ON loan_applications (branch)");
    } catch (PDOException $e) {
        error_log("[LoanApps Schema] CREATE loan_applications failed: " . $e->getMessage());
    }

    // ── loan_application_checks table ──
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS loan_application_checks (
            id SERIAL PRIMARY KEY,
            application_id INT NOT NULL,
            code VARCHAR(50) NOT NULL,
            name VARCHAR(200) DEFAULT '',
            status VARCHAR(20) DEFAULT 'PENDING',
            updated_by INT DEFAULT NULL,
            updated_at TIMESTAMP NULL
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_application ON loan_application_checks (application_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_code ON loan_application_checks (code)");
    } catch (PDOException $e) {
        error_log("[LoanApps Schema] CREATE loan_application_checks failed: " . $e->getMessage());
    }

    // Ensure interest_rate column exists on loan_applications
    laAddCol($db, 'loan_applications', 'interest_rate', "DECIMAL(10,2) DEFAULT 0");
    laAddCol($db, 'loan_applications', 'product_type', "VARCHAR(50) DEFAULT ''");
    laAddCol($db, 'loan_applications', 'repayment_mode', "VARCHAR(30) DEFAULT 'SCHEDULED'");
    laAddCol($db, 'loan_applications', 'repayment_amount', "DECIMAL(20,2) DEFAULT 0");
    laAddCol($db, 'loan_applications', 'repayment_pct', "DECIMAL(10,4) DEFAULT 0");
    laAddCol($db, 'loan_applications', 'auto_deduct', "BOOLEAN DEFAULT TRUE");
    laAddCol($db, 'loan_applications', 'interest_included', "BOOLEAN DEFAULT TRUE");
    laAddCol($db, 'loan_applications', 'loan_module', "VARCHAR(30) DEFAULT 'BANK'");
    laAddCol($db, 'loan_applications', 'insurance_fee', "DECIMAL(20,2) DEFAULT 0");
    laAddCol($db, 'loan_applications', 'requires_double_approval', "BOOLEAN DEFAULT FALSE");
    laAddCol($db, 'loan_applications', 'debit_account_number', "VARCHAR(30) DEFAULT ''");
    laAddCol($db, 'loan_applications', 'debit_account_id', "INT DEFAULT NULL");
    laAddCol($db, 'loan_applications', 'disbursement_account_id', "INT DEFAULT NULL");
    laAddCol($db, 'loan_applications', 'guarantor_customer_id', "INT DEFAULT NULL");
    laAddCol($db, 'loan_applications', 'guarantor_account_id', "INT DEFAULT NULL");
    laAddCol($db, 'loan_applications', 'guarantor_account_number', "VARCHAR(30) DEFAULT ''");
    laAddCol($db, 'loan_application_checks', 'updated_by', "INT DEFAULT NULL");
    laAddCol($db, 'loan_application_checks', 'updated_at', "TIMESTAMP NULL");

    // Fix status ENUM if restrictive — ensure WITHDRAWN and DISBURSED are included
    // ★ CRITICAL: DISBURSED status prevents re-disbursement loop on page refresh.
    // Without it, already-disbursed applications reappear as APPROVED in the UI.
    try {
        $col = $db->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'loan_applications' AND column_name = 'status'")->fetch(PDO::FETCH_ASSOC);
        if ($col && (str_contains(strtolower($col['data_type']), 'char') || str_contains(strtolower($col['data_type']), 'varchar'))) {
            // Status column exists and is VARCHAR — ensure it's wide enough for all statuses
            $colLen = $db->query("SELECT character_maximum_length FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'loan_applications' AND column_name = 'status'")->fetchColumn();
            if ($colLen && (int)$colLen < 20) {
                $db->exec("ALTER TABLE loan_applications ALTER COLUMN status TYPE VARCHAR(20)");
            }
        }
    } catch (PDOException $e) {
        error_log("[LoanApps Schema] ALTER status failed: " . $e->getMessage());
    }

    // ★ Add loan_id column to track which loan was created from this application.
    // This enables the system to detect already-disbursed applications even if
    // the status update to DISBURSED somehow fails (defensive cross-reference).
    laAddCol($db, 'loan_applications', 'loan_id', "INT DEFAULT NULL");
}

/**
 * Ensure approvals table exists for routing applications to approval queue.
 */
function laEnsureApprovalsSchema(PDO $db): void {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS approvals (
            id            SERIAL PRIMARY KEY,
            entity_type   VARCHAR(50)     NOT NULL,
            entity_id     INTEGER    DEFAULT NULL,
            scope_code    VARCHAR(50)     NOT NULL,
            status        VARCHAR(20) NOT NULL DEFAULT 'PENDING',
            submitted_by  INTEGER    DEFAULT NULL,
            branch        VARCHAR(20)     DEFAULT NULL,
            value         TEXT            DEFAULT NULL,
            details       TEXT            DEFAULT NULL,
            submitted_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            decided_by    INTEGER    DEFAULT NULL,
            decided_at    TIMESTAMP       DEFAULT NULL,
            reason        TEXT            DEFAULT NULL,
            created_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_approvals_entity ON approvals (entity_type, entity_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_approvals_status ON approvals (status)");
    } catch (PDOException $e) {
        error_log("[LoanApps Schema] CREATE approvals failed: " . $e->getMessage());
    }

    try {
        $col = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'approvals' AND column_name = 'details'")->fetch();
        if (!$col) {
            $db->exec("ALTER TABLE approvals ADD COLUMN details TEXT DEFAULT NULL");
        }
    } catch (PDOException $e) {
        error_log("[LoanApps Schema] approvals.details migration failed: " . $e->getMessage());
    }
}

/**
 * Queue application into approvals if no pending approval already exists.
 */
function laEnsureApprovalQueued(PDO $db, array $staff, array $app, bool $requiresDoubleApproval): void {
    $entityType = 'Loan Application';
    $entityId = (int)($app['id'] ?? 0);
    if ($entityId <= 0) return;

    $scopeCode = $requiresDoubleApproval ? 'loans.double_approve' : 'loans.approve';
    $branch = sanitize((string)($app['branch'] ?? ''));
    $amount = (float)($app['amount'] ?? 0);
    $customerName = sanitize((string)($app['customer_name'] ?? ''));
    $rate = (float)($app['interest_rate'] ?? 0);
    $value = number_format($amount, 2) . ' FCFA loan application for ' . $customerName . ' at ' . $rate . '% (Branch: ' . $branch . ')' .
        ($requiresDoubleApproval ? ' [DOUBLE APPROVAL REQUIRED]' : '');

    $dupStmt = $db->prepare(
        "SELECT id FROM approvals
         WHERE entity_type = :etype AND entity_id = :eid AND scope_code = :scope AND status = 'PENDING'
         LIMIT 1"
    );
    $dupStmt->execute([
        ':etype' => $entityType,
        ':eid' => $entityId,
        ':scope' => $scopeCode
    ]);
    if ($dupStmt->fetch()) return;

    $ins = $db->prepare(
        "INSERT INTO approvals (entity_type, entity_id, scope_code, status, submitted_by, branch, value)
         VALUES (:etype, :eid, :scope, 'PENDING', :submitted_by, :branch, :value)"
    );
    $ins->execute([
        ':etype' => $entityType,
        ':eid' => $entityId,
        ':scope' => $scopeCode,
        ':submitted_by' => (int)($staff['id'] ?? 0),
        ':branch' => $branch,
        ':value' => $value
    ]);
}

/**
 * Normalize legacy/frontend check codes to canonical DB codes.
 */
function laCanonicalCheckCode(string $code): string {
    $k = strtoupper(trim($code));
    $map = [
        'KYC' => 'KYC_CHECK',
        'KYC_CHECK' => 'KYC_CHECK',
        'CREDIT' => 'CREDIT_SCORE',
        'CREDIT_SCORE' => 'CREDIT_SCORE',
        'HOLDING' => 'INCOME_VERIFY',
        'INCOME_VERIFY' => 'INCOME_VERIFY',
        'AFFORDABILITY' => 'COLLATERAL_CHECK',
        'COLLATERAL_CHECK' => 'COLLATERAL_CHECK'
    ];
    return $map[$k] ?? $k;
}

// PHP 7.x compatibility
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle !== '' && strpos($haystack, $needle) !== false;
    }
}

switch ($method) {

    /* ─── GET: Fetch loan application(s) with embedded due diligence checks ─── */
    case 'GET':
        try {
            $db = getDB();
            laEnsureSchema($db);

            if ($id !== null) {
                // Single application
                $stmt = $db->prepare('SELECT * FROM loan_applications WHERE id = :id');
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) { notFoundResponse('Loan application not found.'); }
                if (!laCanAccessBranch($staff, (string)($row['branch'] ?? ''))) {
                    errorResponse('Access denied: This loan application belongs to a branch outside your assignment.', 403);
                }

                // Fetch checks for this application
                $cStmt = $db->prepare('SELECT * FROM loan_application_checks WHERE application_id = :aid ORDER BY id ASC');
                $cStmt->execute([':aid' => $id]);
                $row['checks'] = $cStmt->fetchAll(PDO::FETCH_ASSOC);

                successResponse($row);
            } else {
                // List all applications with pagination
                $page = max(1, (int)($_GET['page'] ?? 1));
                $pageSize = max(1, min((int)($_GET['pageSize'] ?? 50), 500));
                $offset = ($page - 1) * $pageSize;
                $params = [];
                $where = buildWhere($_GET, ['status', 'branch'], ['branch' => '='], $params);
                // ★ FIX (LOAN-018): Apply branch isolation to loan applications list
                $staffBranches = $staff['branches'] ?? [];
                $clientBranch = sanitize($_GET['branch'] ?? '');
                $branchFilter = applyBranchFilter($staffBranches, $clientBranch, $params, $staff['role'] ?? '', 'branch');
                if ($branchFilter) { $where .= ($where ? ' AND ' : ' WHERE ') . substr($branchFilter, 5); }

                $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM loan_applications ' . $where);
                $countStmt->execute($params);
                $total = (int)$countStmt->fetch()['total'];

                $stmt = $db->prepare(
                    'SELECT * FROM loan_applications ' . $where . ' ORDER BY applied_at DESC LIMIT CAST(:limit AS INTEGER) OFFSET CAST(:offset AS INTEGER)'
                );
                foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
                $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Embed checks for each application
                $cStmt = $db->prepare('SELECT * FROM loan_application_checks WHERE application_id = :aid ORDER BY id ASC');
                foreach ($rows as &$row) {
                    $cStmt->execute([':aid' => (int)$row['id']]);
                    $row['checks'] = $cStmt->fetchAll(PDO::FETCH_ASSOC);
                }
                unset($row);

                paginatedResponse($rows, $total, $page, $pageSize);
            }
        } catch (PDOException $e) {
            error_log('[LoanApps GET] ' . $e->getMessage());
            serverErrorResponse('Database error.');
        }
        break;

    /* ─── POST: Create a new loan application with default checks ─── */
    case 'POST':
        $input = getRequestInput();
        $errors = validateRequired($input, ['customer_id', 'amount', 'term']);
        if (!empty($errors)) { validationError($errors); }
        $amountParsed = parseDecimalInput($input['amount'] ?? null, 'Loan amount', 2, 1, 1000000000000);
        if (!$amountParsed['ok']) { validationError(['amount' => $amountParsed['error']]); }
        $termParsed = parseIntegerInput($input['term'] ?? null, 'Loan term (months)', 1, 600);
        if (!$termParsed['ok']) { validationError(['term' => $termParsed['error']]); }
        $rateParsed = parseDecimalInput($input['interest_rate'] ?? 0, 'Interest rate', 4, 0, 100, false);
        if (!$rateParsed['ok']) { validationError(['interest_rate' => $rateParsed['error']]); }
        $repaymentAmountParsed = parseDecimalInput($input['repayment_amount'] ?? 0, 'Repayment amount', 2, 0, 1000000000000, false);
        if (!$repaymentAmountParsed['ok']) { validationError(['repayment_amount' => $repaymentAmountParsed['error']]); }
        $repaymentPctParsed = parseDecimalInput($input['repayment_pct'] ?? 0, 'Repayment percentage', 4, 0, 100, false);
        if (!$repaymentPctParsed['ok']) { validationError(['repayment_pct' => $repaymentPctParsed['error']]); }
        $insuranceParsed = parseDecimalInput($input['insurance_fee'] ?? 0, 'Insurance fee', 2, 0, 1000000000, false);
        if (!$insuranceParsed['ok']) { validationError(['insurance_fee' => $insuranceParsed['error']]); }

        try {
            $db = getDB();
            laEnsureSchema($db);

            $customerId = (int)$input['customer_id'];
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS loans (id SERIAL PRIMARY KEY)");
                $eligStmt = $db->prepare(
                    "SELECT id, loan_number, status, principal FROM loans
                     WHERE customer_id = :cid
                       AND status IN ('ACTIVE', 'DELINQUENT', 'WRITTEN_OFF', 'DEFAULTED')
                     ORDER BY CASE WHEN status = 'ACTIVE' THEN 1 WHEN status = 'DELINQUENT' THEN 2 WHEN status = 'WRITTEN_OFF' THEN 3 WHEN status = 'DEFAULTED' THEN 4 ELSE 0 END
                     LIMIT 1"
                );
                $eligStmt->execute([':cid' => $customerId]);
                $ineligibleLoan = $eligStmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $ineligibleLoan = null;
            }

            if ($ineligibleLoan) {
                $loanStatus = strtoupper($ineligibleLoan['status']);
                $loanNumber = $ineligibleLoan['loan_number'];
                $loanPrincipal = number_format((float)$ineligibleLoan['principal'], 2);

                if ($loanStatus === 'ACTIVE') {
                    errorResponse(
                        'Loan application rejected: Customer already has an active loan (' . $loanNumber . ') for ' . $loanPrincipal . ' FCFA. ' .
                        'A customer with an active loan cannot take another loan until the existing loan is fully settled or closed.',
                        403
                    );
                    break;
                }
                if ($loanStatus === 'WRITTEN_OFF') {
                    errorResponse(
                        'Loan application rejected: Customer has a written-off loan (' . $loanNumber . ') for ' . $loanPrincipal . ' FCFA. ' .
                        'Customers with written-off loans are permanently ineligible for new loans. This restriction can only be lifted by a system administrator through direct database intervention.',
                        403
                    );
                    break;
                }
                if ($loanStatus === 'DEFAULTED') {
                    errorResponse(
                        'Loan application rejected: Customer has a defaulted loan (' . $loanNumber . ') for ' . $loanPrincipal . ' FCFA. ' .
                        'Customers with defaulted loans are permanently ineligible for new loans. This restriction can only be lifted by a system administrator through direct database intervention.',
                        403
                    );
                    break;
                }
            }

            $requiresDoubleApproval = !empty($ineligibleLoan) && strtoupper((string)($ineligibleLoan['status'] ?? '')) === 'DELINQUENT';

            try {
                $pendingStmt = $db->prepare(
                    "SELECT id, ref, status, amount FROM loan_applications
                     WHERE customer_id = :cid
                       AND status IN ('PENDING', 'UNDER_REVIEW', 'APPROVED')
                     LIMIT 1"
                );
                $pendingStmt->execute([':cid' => $customerId]);
                $pendingApp = $pendingStmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $pendingApp = null;
            }

            if ($pendingApp) {
                errorResponse(
                    'Loan application rejected: Customer already has a ' . strtoupper($pendingApp['status']) . ' application (' . $pendingApp['ref'] . ') for ' . number_format((float)$pendingApp['amount'], 2) . ' FCFA. ' .
                    'Please wait for the existing application to be processed before submitting a new one.',
                    409
                );
                break;
            }

            $year = date('Y');
            $prefix = 'LA-' . $year . '-';
            try {
                $maxNum = $db->query(
                    "SELECT MAX(CAST(SUBSTRING(ref, " . strlen($prefix) . ") AS INTEGER)) AS max_num
                     FROM loan_applications WHERE ref LIKE '" . $prefix . "%'
                     AND ref ~ '^LA-" . $year . "-[0-9]{3,4}$'"
                )->fetch();
            } catch (PDOException $e) {
                $maxNum = ['max_num' => null];
            }
            $nextSeq = ((int)($maxNum['max_num'] ?? 0)) + 1;
            $appRef = $prefix . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);

            try {
                $existsStmt = $db->prepare('SELECT id FROM loan_applications WHERE ref = :ref LIMIT 1');
                $existsStmt->execute([':ref' => $appRef]);
                if ($existsStmt->fetch()) {
                    $appRef = $prefix . str_pad($nextSeq, 3, '0', STR_PAD_LEFT) . '-' . bin2hex(random_bytes(2));
                }
            } catch (PDOException $e) {}

            $customerName = '';
            $customerBranch = '';
            try {
                $custStmt = $db->prepare('SELECT full_name, branch FROM customers WHERE id = :id');
                $custStmt->execute([':id' => $input['customer_id']]);
                $cust = $custStmt->fetch();
                if ($cust) {
                    $customerName = $cust['full_name'];
                    $customerBranch = $cust['branch'] ?? '';
                }
            } catch (PDOException $e) {}

            if ($customerName === '') {
                notFoundResponse('Customer not found.');
            }
            if (!laCanAccessBranch($staff, $customerBranch)) {
                errorResponse('Access denied: Cannot create a loan application for branch ' . $customerBranch . ' — outside your branch scope.', 403);
            }

            $debitAccountIdRaw = $input['debit_account_id'] ?? null;
            $debitAccountId = ($debitAccountIdRaw === null || $debitAccountIdRaw === '') ? null : (int)$debitAccountIdRaw;
            $debitAccountNumber = sanitize($input['debit_account_number'] ?? '');
            $acct = null;

            if ($debitAccountId) {
                $acctStmt = $db->prepare('SELECT id, customer_id, account_number, branch, status FROM accounts WHERE id = :id LIMIT 1');
                $acctStmt->execute([':id' => $debitAccountId]);
                $acct = $acctStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            // Fallback for mixed front-end state: if id is stale or absent, resolve by account number.
            if (!$acct && $debitAccountNumber !== '') {
                $acctByNoStmt = $db->prepare(
                    'SELECT id, customer_id, account_number, branch, status
                     FROM accounts
                     WHERE account_number = :acc_no AND customer_id = :cid
                     LIMIT 1'
                );
                $acctByNoStmt->execute([
                    ':acc_no' => $debitAccountNumber,
                    ':cid' => $customerId
                ]);
                $acct = $acctByNoStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            if (!$acct) {
                validationError(['debit_account_id' => 'Debit account not found for this customer.']);
            }
            if ((int)($acct['customer_id'] ?? 0) !== $customerId) {
                validationError(['debit_account_id' => 'Debit account must belong to the same customer as the application.']);
            }
            if (!laCanAccessBranch($staff, (string)($acct['branch'] ?? ''))) {
                errorResponse('Access denied: Debit account belongs to a branch outside your assignment.', 403);
            }
            $debitAccountId = (int)($acct['id'] ?? $debitAccountId);
            $debitAccountNumber = sanitize((string)($acct['account_number'] ?? $debitAccountNumber));

            $guarantorCustomerIdRaw = $input['guarantor_customer_id'] ?? null;
            $guarantorAccountIdRaw = $input['guarantor_account_id'] ?? null;
            $guarantorAccountNoRaw = sanitize($input['guarantor_account_number'] ?? '');
            $guarantorCustomerId = ($guarantorCustomerIdRaw === null || $guarantorCustomerIdRaw === '') ? null : (int)$guarantorCustomerIdRaw;
            $guarantorAccountId = ($guarantorAccountIdRaw === null || $guarantorAccountIdRaw === '') ? null : (int)$guarantorAccountIdRaw;
            $guarantorAccountNumber = '';
            if (($guarantorCustomerId && !$guarantorAccountId) || (!$guarantorCustomerId && $guarantorAccountId)) {
                validationError(['guarantor_account_id' => 'Select both guarantor customer and guarantor account, or leave both empty.']);
            }
            if ($guarantorCustomerId && $guarantorAccountId) {
                $gAcctStmt = $db->prepare(
                    'SELECT id, customer_id, account_number, branch, status
                     FROM accounts
                     WHERE id = :id AND customer_id = :cid
                     LIMIT 1'
                );
                $gAcctStmt->execute([
                    ':id' => $guarantorAccountId,
                    ':cid' => $guarantorCustomerId
                ]);
                $gAcct = $gAcctStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$gAcct && $guarantorAccountNoRaw !== '') {
                    $gAcctByNoStmt = $db->prepare(
                        'SELECT id, customer_id, account_number, branch, status
                         FROM accounts
                         WHERE account_number = :acc_no AND customer_id = :cid
                         LIMIT 1'
                    );
                    $gAcctByNoStmt->execute([
                        ':acc_no' => $guarantorAccountNoRaw,
                        ':cid' => $guarantorCustomerId
                    ]);
                    $gAcct = $gAcctByNoStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                if (!$gAcct) {
                    validationError(['guarantor_account_id' => 'Guarantor account not found for the selected guarantor customer.']);
                }
                if (!laCanAccessBranch($staff, (string)($gAcct['branch'] ?? ''))) {
                    errorResponse('Access denied: Guarantor account belongs to a branch outside your assignment.', 403);
                }
                $guarantorCustomerId = (int)($gAcct['customer_id'] ?? $guarantorCustomerId);
                $guarantorAccountId = (int)($gAcct['id'] ?? $guarantorAccountId);
                $guarantorAccountNumber = sanitize((string)($gAcct['account_number'] ?? ''));
            }

            $db->beginTransaction();
            try {
                $insStmt = $db->prepare(
                    'INSERT INTO loan_applications (ref, customer_id, customer_name, amount, term,
                        interest_rate, purpose, status, branch, product_type, repayment_mode,
                        repayment_amount, repayment_pct, auto_deduct, interest_included, loan_module,
                        insurance_fee, requires_double_approval, debit_account_number, debit_account_id,
                        disbursement_account_id, loan_id, guarantor_customer_id, guarantor_account_id,
                        guarantor_account_number, applied_at)
                     VALUES (:ref, :cid, :cname, :amount, :term,
                        :rate, :purpose, :status, :branch, :ptype, :rmode,
                        :ramt, :rpct, :auto_deduct, :interest_included, :loan_module,
                        :insurance_fee, :requires_double_approval, :debit_acc, :debit_id,
                        :disbursement_account_id, :loan_id, :guarantor_customer_id, :guarantor_account_id,
                        :guarantor_account_number, NOW())'
                );
                $insStmt->execute([
                    ':ref'       => $appRef,
                    ':cid'       => (int)$input['customer_id'],
                    ':cname'     => $customerName,
                    ':amount'    => $amountParsed['value'],
                    ':term'      => $termParsed['value'],
                    ':rate'      => $rateParsed['value'],
                    ':purpose'   => sanitize($input['purpose'] ?? ''),
                    ':status'    => 'PENDING',
                    ':branch'    => $customerBranch,
                    ':ptype'     => sanitize($input['product_type'] ?? ''),
                    ':rmode'     => sanitize($input['repayment_mode'] ?? 'SCHEDULED'),
                    ':ramt'      => $repaymentAmountParsed['value'],
                    ':rpct'      => $repaymentPctParsed['value'],
                    ':auto_deduct' => isset($input['auto_deduct']) ? (int)$input['auto_deduct'] : 1,
                    ':interest_included' => isset($input['interest_included']) ? (int)$input['interest_included'] : 1,
                    ':loan_module' => sanitize($input['loan_module'] ?? 'BANK'),
                    ':insurance_fee' => $insuranceParsed['value'],
                    ':requires_double_approval' => $requiresDoubleApproval ? 1 : 0,
                    ':debit_acc' => $debitAccountNumber,
                    ':debit_id'  => $debitAccountId,
                    ':disbursement_account_id' => isset($input['disbursement_account_id']) && $input['disbursement_account_id'] !== '' ? (int)$input['disbursement_account_id'] : null,
                    ':loan_id'   => isset($input['loan_id']) ? (int)$input['loan_id'] : null,
                    ':guarantor_customer_id' => $guarantorCustomerId,
                    ':guarantor_account_id' => $guarantorAccountId,
                    ':guarantor_account_number' => $guarantorAccountNumber,
                ]);
                $newId = (int)$db->lastInsertId('loan_applications_id_seq');

                $defaultChecks = [
                    ['code' => 'KYC_CHECK', 'name' => 'KYC Verification'],
                    ['code' => 'CREDIT_SCORE', 'name' => 'Credit Bureau Check'],
                    ['code' => 'INCOME_VERIFY', 'name' => 'Income Verification'],
                    ['code' => 'COLLATERAL_CHECK', 'name' => 'Collateral Assessment'],
                ];
                $chkStmt = $db->prepare(
                    'INSERT INTO loan_application_checks (application_id, code, name, status)
                     VALUES (:aid, :code, :name, :status)'
                );
                foreach ($defaultChecks as $chk) {
                    $chkStmt->execute([
                        ':aid'    => $newId,
                        ':code'   => $chk['code'],
                        ':name'   => $chk['name'],
                        ':status' => 'PENDING'
                    ]);
                }

                $db->commit();

                logAudit($staff['full_name'], 'LOAN_APPLICATION_CREATE', 'LOAN_APPLICATION', (string)$newId, 'SUCCESS',
                    'Created loan application ' . $appRef . ' for ' . number_format($amountParsed['value'], 2) . ' XAF',
                    $staff['department'], getClientIp());

                createdResponse(['id' => $newId, 'ref' => $appRef, 'requires_double_approval' => $requiresDoubleApproval ? 1 : 0], 'Loan application created successfully.');
            } catch (PDOException $e) {
                $db->rollBack();
                throw $e;
            }
        } catch (PDOException $e) {
            error_log('[LoanApps POST] ' . $e->getMessage());
            serverErrorResponse('Failed to create loan application.');
        }
        break;

    /* ─── PUT: Update application status, checks, or both ─── */
    case 'PUT':
        if ($id === null) { validationError(['id' => 'Application ID is required.']); }
        $input = getRequestInput();

        try {
            $db = getDB();
            laEnsureSchema($db);
            // Ensure approvals schema before opening a transaction.
            // DDL can trigger implicit commits in MySQL, which breaks active transactions.
            laEnsureApprovalsSchema($db);

            // Fetch existing application
            $fetchStmt = $db->prepare('SELECT * FROM loan_applications WHERE id = :id');
            $fetchStmt->execute([':id' => $id]);
            $app = $fetchStmt->fetch(PDO::FETCH_ASSOC);
            if (!$app) { notFoundResponse('Loan application not found.'); }
            if (!laCanAccessBranch($staff, (string)($app['branch'] ?? ''))) {
                errorResponse('Access denied: This loan application belongs to a branch outside your assignment.', 403);
            }

            $db->beginTransaction();
            try {
                // ★ STATUS TRANSITION VALIDATION: Enforce valid state machine transitions
                $validTransitions = [
                    'PENDING'      => ['UNDER_REVIEW', 'REJECTED', 'WITHDRAWN'],
                    'UNDER_REVIEW' => ['APPROVED', 'REJECTED'],
                    'APPROVED'     => ['DISBURSED', 'REJECTED', 'WITHDRAWN'],
                    'REJECTED'     => [],  // terminal — no exits
                    'WITHDRAWN'    => [],  // terminal — no exits
                    'DISBURSED'    => [],  // terminal — no exits
                ];
                if (isset($input['status']) && isset($app['status'])) {
                    $currentStatus = strtoupper($app['status']);
                    $newStatus = strtoupper($input['status']);
                    if ($currentStatus !== $newStatus) {
                        $allowed = $validTransitions[$currentStatus] ?? [];
                        if (!in_array($newStatus, $allowed)) {
                            if ($db->inTransaction()) { $db->rollBack(); }
                            errorResponse(
                                'Invalid status transition: ' . $currentStatus . ' → ' . $newStatus . '. ' .
                                'Allowed transitions from ' . $currentStatus . ': ' .
                                (empty($allowed) ? '(none — terminal state)' : implode(', ', $allowed)) . '.',
                                409
                            );
                        }
                    }
                }

                // ── Update application fields ──
                $fields = [];
                $params = [':id' => $id];
                if (isset($input['interest_rate'])) {
                    $parsed = parseDecimalInput($input['interest_rate'], 'Interest rate', 4, 0, 100);
                    if (!$parsed['ok']) { validationError(['interest_rate' => $parsed['error']]); }
                    $input['interest_rate'] = $parsed['value'];
                }
                if (isset($input['repayment_amount'])) {
                    $parsed = parseDecimalInput($input['repayment_amount'], 'Repayment amount', 2, 0, 1000000000000);
                    if (!$parsed['ok']) { validationError(['repayment_amount' => $parsed['error']]); }
                    $input['repayment_amount'] = $parsed['value'];
                }
                if (isset($input['repayment_pct'])) {
                    $parsed = parseDecimalInput($input['repayment_pct'], 'Repayment percentage', 4, 0, 100);
                    if (!$parsed['ok']) { validationError(['repayment_pct' => $parsed['error']]); }
                    $input['repayment_pct'] = $parsed['value'];
                }
                if (isset($input['insurance_fee'])) {
                    $parsed = parseDecimalInput($input['insurance_fee'], 'Insurance fee', 2, 0, 1000000000);
                    if (!$parsed['ok']) { validationError(['insurance_fee' => $parsed['error']]); }
                    $input['insurance_fee'] = $parsed['value'];
                }
                if (isset($input['debit_account_id'])) {
                    $parsed = parseIntegerInput($input['debit_account_id'], 'Debit account ID', 1, 2147483647);
                    if (!$parsed['ok']) { validationError(['debit_account_id' => $parsed['error']]); }
                    $input['debit_account_id'] = $parsed['value'];
                }
                if (isset($input['loan_id']) && $input['loan_id'] !== null && $input['loan_id'] !== '') {
                    $parsed = parseIntegerInput($input['loan_id'], 'Loan ID', 1, 2147483647);
                    if (!$parsed['ok']) { validationError(['loan_id' => $parsed['error']]); }
                    $input['loan_id'] = $parsed['value'];
                }
                if (isset($input['disbursement_account_id']) && $input['disbursement_account_id'] !== null && $input['disbursement_account_id'] !== '') {
                    $parsed = parseIntegerInput($input['disbursement_account_id'], 'Disbursement account ID', 1, 2147483647);
                    if (!$parsed['ok']) { validationError(['disbursement_account_id' => $parsed['error']]); }
                    $input['disbursement_account_id'] = $parsed['value'];
                }
                if (isset($input['guarantor_customer_id']) && $input['guarantor_customer_id'] !== null && $input['guarantor_customer_id'] !== '') {
                    $parsed = parseIntegerInput($input['guarantor_customer_id'], 'Guarantor customer ID', 1, 2147483647);
                    if (!$parsed['ok']) { validationError(['guarantor_customer_id' => $parsed['error']]); }
                    $input['guarantor_customer_id'] = $parsed['value'];
                }
                if (isset($input['guarantor_account_id']) && $input['guarantor_account_id'] !== null && $input['guarantor_account_id'] !== '') {
                    $parsed = parseIntegerInput($input['guarantor_account_id'], 'Guarantor account ID', 1, 2147483647);
                    if (!$parsed['ok']) { validationError(['guarantor_account_id' => $parsed['error']]); }
                    $input['guarantor_account_id'] = $parsed['value'];
                }
                if (
                    isset($input['guarantor_customer_id']) ||
                    isset($input['guarantor_account_id']) ||
                    isset($input['guarantor_account_number'])
                ) {
                    $effectiveGuarantorCustomerId = isset($input['guarantor_customer_id'])
                        ? (int)$input['guarantor_customer_id']
                        : (int)($app['guarantor_customer_id'] ?? 0);
                    $effectiveGuarantorAccountId = isset($input['guarantor_account_id'])
                        ? (int)$input['guarantor_account_id']
                        : (int)($app['guarantor_account_id'] ?? 0);
                    if (($effectiveGuarantorCustomerId > 0 && $effectiveGuarantorAccountId <= 0) || ($effectiveGuarantorCustomerId <= 0 && $effectiveGuarantorAccountId > 0)) {
                        validationError(['guarantor_account_id' => 'Select both guarantor customer and guarantor account, or clear both.']);
                    }
                    if ($effectiveGuarantorCustomerId > 0 && $effectiveGuarantorAccountId > 0) {
                        $gStmt = $db->prepare(
                            'SELECT id, customer_id, account_number, branch FROM accounts WHERE id = :id AND customer_id = :cid LIMIT 1'
                        );
                        $gStmt->execute([
                            ':id' => $effectiveGuarantorAccountId,
                            ':cid' => $effectiveGuarantorCustomerId
                        ]);
                        $gAcct = $gStmt->fetch(PDO::FETCH_ASSOC);
                        if (!$gAcct) {
                            validationError(['guarantor_account_id' => 'Guarantor account not found for the selected guarantor customer.']);
                        }
                        if (!laCanAccessBranch($staff, (string)($gAcct['branch'] ?? ''))) {
                            errorResponse('Access denied: Guarantor account belongs to a branch outside your assignment.', 403);
                        }
                        $input['guarantor_account_number'] = sanitize((string)($gAcct['account_number'] ?? ''));
                    }
                }
                foreach (['status', 'decision_reason', 'purpose', 'interest_rate', 'product_type',
                         'repayment_mode', 'repayment_amount', 'repayment_pct', 'auto_deduct', 'interest_included',
                         'loan_module', 'insurance_fee', 'requires_double_approval',
                         'debit_account_number', 'debit_account_id', 'loan_id', 'disbursement_account_id',
                         'guarantor_customer_id', 'guarantor_account_id', 'guarantor_account_number'] as $f) {
                    if (isset($input[$f])) {
                        $fields[] = "\"$f\" = :$f";
                        $params[":$f"] = $input[$f];
                    }
                }

                // If status changed to a terminal/dispositive state, record who decided and when
                if (isset($input['status']) && in_array(strtoupper($input['status']), ['APPROVED','REJECTED','WITHDRAWN','DISBURSED'])) {
                    $fields[] = "\"decided_by\" = :decided_by";
                    $fields[] = "\"decided_at\" = NOW()";
                    $params[':decided_by'] = $staff['id'] ?? null;
                }

                // ★ DISBURSEMENT IDEMPOTENCY: Check if a loan already exists for this application
                // (prevents creating duplicate loans if the frontend sends a duplicate DISBURSED request)
                if (isset($input['status']) && strtoupper($input['status']) === 'DISBURSED') {
                    // Check if this application already has loan_id set
                    if (!empty($app['loan_id'])) {
                        // Verify the referenced loan still exists
                        $loanCheck = $db->prepare('SELECT id, status FROM loans WHERE id = :lid');
                        $loanCheck->execute([':lid' => (int)$app['loan_id']]);
                        $existingLoan = $loanCheck->fetch(PDO::FETCH_ASSOC);
                        if ($existingLoan && in_array(strtoupper($existingLoan['status']), ['ACTIVE', 'DELINQUENT'])) {
                            // Already disbursed — idempotent success (no-op)
                            if ($db->inTransaction()) { $db->rollBack(); }
                            successMessage('Application is already marked as disbursed (idempotent).');
                            break;
                        }
                    }
                    // Check for orphaned ACTIVE loan by customer_id + amount
                    $orphanCheck = $db->prepare(
                        'SELECT id, loan_number, status FROM loans ' .
                        'WHERE customer_id = :cid AND principal = :amt AND status IN (\'ACTIVE\', \'DELINQUENT\') ' .
                        'ORDER BY id DESC LIMIT 1'
                    );
                    $orphanCheck->execute([':cid' => (int)$app['customer_id'], ':amt' => (float)$app['amount']]);
                    $orphan = $orphanCheck->fetch(PDO::FETCH_ASSOC);
                    if ($orphan) {
                        // Auto-link the orphaned loan and return idempotent success
                        $db->prepare('UPDATE loan_applications SET loan_id = :lid WHERE id = :id')
                            ->execute([':lid' => (int)$orphan['id'], ':id' => (int)$id]);
                        $db->commit();
                        successMessage('Application linked to existing active loan ' . $orphan['loan_number'] . ' (orphaned disbursement detected).');
                        break;
                    }
                }

                if (!empty($fields)) {
                    $updStmt = $db->prepare('UPDATE loan_applications SET ' . implode(', ', $fields) . ' WHERE id = :id');
                    $updStmt->execute($params);
                }

                // ── Update individual checks ──
                if (isset($input['checks']) && is_array($input['checks'])) {
                    $chkStmt = $db->prepare(
                        'UPDATE loan_application_checks SET status = :status, updated_by = :uby, updated_at = NOW()
                         WHERE application_id = :aid AND UPPER(code) = :code'
                    );
                    $insMissing = $db->prepare(
                        'INSERT INTO loan_application_checks (application_id, code, name, status, updated_by, updated_at)
                         VALUES (:aid, :code, :name, :status, :uby, NOW())'
                    );
                    $existsStmt = $db->prepare(
                        'SELECT id FROM loan_application_checks WHERE application_id = :aid AND UPPER(code) = :code LIMIT 1'
                    );
                    $nameMap = [
                        'KYC_CHECK' => 'KYC Verification',
                        'CREDIT_SCORE' => 'Credit Bureau Check',
                        'INCOME_VERIFY' => 'Income Verification',
                        'COLLATERAL_CHECK' => 'Collateral Assessment'
                    ];
                    foreach ($input['checks'] as $chk) {
                        if (!isset($chk['code']) || !isset($chk['status'])) continue;
                        $canonCode = laCanonicalCheckCode((string)$chk['code']);
                        $status = strtoupper(sanitize((string)$chk['status']));
                        if (!in_array($status, ['PENDING', 'PASSED', 'FAILED', 'WAIVED'], true)) {
                            $status = 'PENDING';
                        }
                        $chkStmt->execute([
                            ':status' => $status,
                            ':uby'    => $staff['id'] ?? null,
                            ':aid'    => $id,
                            ':code'   => $canonCode
                        ]);
                        if ($chkStmt->rowCount() === 0) {
                            $existsStmt->execute([':aid' => $id, ':code' => $canonCode]);
                            if (!$existsStmt->fetch(PDO::FETCH_ASSOC)) {
                                $insMissing->execute([
                                    ':aid' => $id,
                                    ':code' => $canonCode,
                                    ':name' => $nameMap[$canonCode] ?? $canonCode,
                                    ':status' => $status,
                                    ':uby' => $staff['id'] ?? null
                                ]);
                            }
                        }
                    }
                }

                // Auto-route to approval queue when due diligence is complete.
                $effectiveStatus = strtoupper((string)($input['status'] ?? $app['status'] ?? ''));
                $remainingChecksStmt = $db->prepare(
                    "SELECT COUNT(*) FROM loan_application_checks
                     WHERE application_id = :aid AND UPPER(status) NOT IN ('PASSED','WAIVED')"
                );
                $remainingChecksStmt->execute([':aid' => (int)$id]);
                $remainingChecks = (int)$remainingChecksStmt->fetchColumn();

                if ($effectiveStatus === 'PENDING' && $remainingChecks === 0) {
                    $promoteStmt = $db->prepare(
                        "UPDATE loan_applications SET status = 'UNDER_REVIEW' WHERE id = :id AND status = 'PENDING'"
                    );
                    $promoteStmt->execute([':id' => (int)$id]);
                    if ($promoteStmt->rowCount() > 0) {
                        $effectiveStatus = 'UNDER_REVIEW';
                    }
                }

                if ($effectiveStatus === 'UNDER_REVIEW' && $remainingChecks > 0) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    validationError([
                        'checks' => 'All due diligence checks must be PASSED or WAIVED before moving an application to UNDER_REVIEW.'
                    ]);
                }

                if ($effectiveStatus === 'UNDER_REVIEW' && $remainingChecks === 0) {
                    $refetch = $db->prepare('SELECT * FROM loan_applications WHERE id = :id LIMIT 1');
                    $refetch->execute([':id' => (int)$id]);
                    $freshApp = $refetch->fetch(PDO::FETCH_ASSOC) ?: $app;
                    $requiresDoubleApproval = ((int)($freshApp['requires_double_approval'] ?? 0) === 1);
                    laEnsureApprovalQueued($db, $staff, $freshApp, $requiresDoubleApproval);
                }

                $db->commit();

                $action = isset($input['status']) ? 'status=' . $input['status'] : 'fields updated';
                logAudit($staff['full_name'], 'LOAN_APPLICATION_UPDATE', 'LOAN_APPLICATION', (string)$id, 'SUCCESS',
                    'Updated application ' . ($app['ref'] ?? $id) . ': ' . $action,
                    $staff['department'], getClientIp());

                successMessage('Loan application updated successfully.');
            } catch (PDOException $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
        } catch (Throwable $e) {
            error_log('[LoanApps PUT] ' . $e->getMessage());
            serverErrorResponse('Failed to update loan application.');
        }
        break;

    default:
        errorResponse('Method not allowed.', 405);
}
