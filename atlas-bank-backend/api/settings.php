<?php
/**
 * Atlas Bank Enterprise Console
 * API: Settings
 *
 * All settings are stored in the "settings" table with UPSERT semantics.
 * Supports filtering by category and key.
 *
 * Access policy:
 *   GET  — All authenticated staff can read settings (needed for withdrawal fees,
 *          limits, session timeout, currency, etc.)
 *   PUT  — Restricted to ADMIN role (setting changes are administrative)
 */
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

$method = $_ROUTE['method'];

// GET is allowed for any authenticated user — settings are operational parameters
// (withdrawal fees, limits, session timeout, currency) needed by ALL staff.
// PUT (modifications) requires SETTINGS module + ADMIN role.
if ($method === 'GET') {
    $staff = requireAuth();
} else {
    $staff = requireModule('SETTINGS');
}

$db = getDB();

// ── Ensure table exists with ALL required columns ──
$db->exec("CREATE TABLE IF NOT EXISTS \"settings\" (
    \"id\" SERIAL PRIMARY KEY,
    \"key\" VARCHAR(191) NOT NULL,
    \"name\" VARCHAR(191) DEFAULT NULL,
    \"category\" VARCHAR(100) NOT NULL DEFAULT 'General',
    \"value\" TEXT NOT NULL,
    \"description\" TEXT DEFAULT NULL,
    \"effective_from\" DATE DEFAULT NULL,
    \"requires_approval\" BOOLEAN NOT NULL DEFAULT FALSE,
    \"created_at\" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    \"updated_at\" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (\"key\")
)");
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_settings_category ON "settings" (category)'); } catch (PDOException $e) {}

// ── Self-heal: add columns that may be missing from older installs ──
foreach ([
    'ALTER TABLE "settings" ADD COLUMN "description" TEXT DEFAULT NULL',
    'ALTER TABLE "settings" ADD COLUMN "effective_from" DATE DEFAULT NULL',
    'ALTER TABLE "settings" ADD COLUMN "requires_approval" BOOLEAN NOT NULL DEFAULT FALSE'
] as $alterSql) {
    try { $db->exec($alterSql); } catch (PDOException $e) { /* column already exists */ }
}

// ── Self-heal: seed all operational settings if they don't exist ──
// This ensures the System Settings UI always has the full set of enterprise settings,
// even if the SQL seed file (MASTER_RESET.sql / setup_atlas_bank.sql) wasn't imported.
$operationalDefaults = [
    // ── Security ──
    ['key' => 'security.max_login_attempts', 'name' => 'Max Login Attempts', 'category' => 'Security', 'value' => '5', 'description' => 'Number of failed login attempts before account is temporarily locked.', 'requires_approval' => 1],
    ['key' => 'security.lockout_duration', 'name' => 'Lockout Duration (minutes)', 'category' => 'Security', 'value' => '30', 'description' => 'Duration in minutes that a locked account remains locked before auto-unlock.', 'requires_approval' => 0],
    ['key' => 'security.session_timeout', 'name' => 'Session Timeout (minutes)', 'category' => 'Security', 'value' => '480', 'description' => 'Session inactivity timeout in minutes. After this period, the user must log in again.', 'requires_approval' => 0],
    ['key' => 'security.mfa_required', 'name' => 'MFA Required', 'category' => 'Security', 'value' => 'false', 'description' => 'Whether Multi-Factor Authentication is required for all staff logins.', 'requires_approval' => 1],
    // ── Operations ──
    ['key' => 'operations.currency', 'name' => 'Default Currency', 'category' => 'Operations', 'value' => 'XAF', 'description' => 'Default currency code for all financial operations.', 'requires_approval' => 0],
    ['key' => 'txn.approval_threshold', 'name' => 'Transaction Approval Threshold', 'category' => 'Operations', 'value' => '5000000', 'description' => 'Amount above which transactions require supervisory approval.', 'requires_approval' => 1],
    ['key' => 'operations.date_format', 'name' => 'Date Format', 'category' => 'Operations', 'value' => 'd M Y', 'description' => 'Default date display format across the application.', 'requires_approval' => 0],
    // ── Loans ──
    ['key' => 'loan.module', 'name' => 'Loan Calculation Module', 'category' => 'Loans', 'value' => 'BANK', 'description' => 'Loan calculation method: BANK (Equal Principal) or CREDIT_UNION (Reducing Balance per COBAC).', 'requires_approval' => 1],
    ['key' => 'loan.min_holding_days', 'name' => 'Loan Minimum Holding Days', 'category' => 'Loans', 'value' => '90', 'description' => 'Minimum number of days an account must be active before loan eligibility.', 'requires_approval' => 1],
    ['key' => 'loan.default_interest_rate', 'name' => 'Default Loan Interest Rate (%)', 'category' => 'Loans', 'value' => '5.50', 'description' => 'Default annual interest rate applied to new loan applications.', 'requires_approval' => 1],
    ['key' => 'loan.max_amount', 'name' => 'Maximum Loan Amount', 'category' => 'Loans', 'value' => '600000000', 'description' => 'Maximum single loan amount permitted by policy.', 'requires_approval' => 1],
    ['key' => 'loan.late_payment_penalty', 'name' => 'Late Payment Penalty (%)', 'category' => 'Loans', 'value' => '2.00', 'description' => 'Penalty percentage applied to overdue loan repayments.', 'requires_approval' => 0],
    ['key' => 'loan.dti_ratio', 'name' => 'Maximum DTI Ratio (%)', 'category' => 'Loans', 'value' => '40', 'description' => 'Maximum Debt-to-Income ratio allowed for loan approval.', 'requires_approval' => 1],
    ['key' => 'auto_deduction_enabled', 'name' => 'Auto Loan Deduction', 'category' => 'Loans', 'value' => 'OFF', 'description' => 'Enable automatic loan repayment deduction from salary accounts on payday.', 'requires_approval' => 0],
    // ── Fees ──
    ['key' => 'fee.withdrawal_counter', 'name' => 'Counter Withdrawal Fee (%)', 'category' => 'Fees', 'value' => '0.50', 'description' => 'Percentage fee charged for counter withdrawals.', 'requires_approval' => 0],
    ['key' => 'fee.withdrawal_atm', 'name' => 'ATM Withdrawal Fee', 'category' => 'Fees', 'value' => '200', 'description' => 'Flat fee (FCFA) charged for ATM withdrawals.', 'requires_approval' => 0],
    ['key' => 'fee.transfer_internal', 'name' => 'Internal Transfer Fee', 'category' => 'Fees', 'value' => '0', 'description' => 'Fee (FCFA) for transfers within Atlas Bank accounts.', 'requires_approval' => 0],
    ['key' => 'fee.transfer_external', 'name' => 'External Transfer Fee', 'category' => 'Fees', 'value' => '500', 'description' => 'Fee (FCFA) for transfers to other banks.', 'requires_approval' => 0],
    ['key' => 'fee.account_maintenance', 'name' => 'Monthly Account Maintenance', 'category' => 'Fees', 'value' => '5000', 'description' => 'Monthly fee (FCFA) for account maintenance.', 'requires_approval' => 0],
    ['key' => 'fee.loan_processing', 'name' => 'Loan Processing Fee (%)', 'category' => 'Fees', 'value' => '1.00', 'description' => 'Percentage fee for loan processing.', 'requires_approval' => 1],
    ['key' => 'fee.loan_late_payment', 'name' => 'Loan Late Payment Penalty (%)', 'category' => 'Fees', 'value' => '2.00', 'description' => 'Penalty percentage for late loan repayments.', 'requires_approval' => 0],
    // ── Withdrawal Tax Application Toggles ──
    ['key' => 'tax.apply_on_salary_withdrawal', 'name' => 'Tax on Salary Withdrawals', 'category' => 'Withdrawal Taxes', 'value' => 'YES', 'description' => 'Enable statutory deductions (PAYE, CNPS, CITEC) on salary account withdrawals.', 'requires_approval' => 0],
    ['key' => 'tax.apply_on_current_withdrawal', 'name' => 'Tax on Current Account Withdrawals', 'category' => 'Withdrawal Taxes', 'value' => 'NO', 'description' => 'Enable statutory deductions on current account withdrawals.', 'requires_approval' => 0],
    ['key' => 'tax.apply_on_savings_withdrawal', 'name' => 'Tax on Savings Withdrawals', 'category' => 'Withdrawal Taxes', 'value' => 'NO', 'description' => 'Enable statutory deductions on savings account withdrawals.', 'requires_approval' => 0],
    // ── Withdrawal Tax Rates (Cameroon) ──
    ['key' => 'tax.paye_rate', 'name' => 'PAYE Income Tax Rate (%)', 'category' => 'Withdrawal Taxes', 'value' => '7.50', 'description' => 'Pay-As-You-Earn income tax withholding rate.', 'requires_approval' => 1],
    ['key' => 'tax.cnps_retraite_employee', 'name' => 'CNPS Retraite Employee (%)', 'category' => 'Withdrawal Taxes', 'value' => '4.80', 'description' => 'Pension scheme employee contribution rate.', 'requires_approval' => 1],
    ['key' => 'tax.cnps_retraite_employer', 'name' => 'CNPS Retraite Employer (%)', 'category' => 'Withdrawal Taxes', 'value' => '8.40', 'description' => 'Pension scheme employer contribution rate.', 'requires_approval' => 1],
    ['key' => 'tax.cnps_prestations', 'name' => 'CNPS Prestations Familiales (%)', 'category' => 'Withdrawal Taxes', 'value' => '7.00', 'description' => 'Family benefits employer contribution rate.', 'requires_approval' => 1],
    ['key' => 'tax.citec_employee', 'name' => 'CITEC Logement Employee (%)', 'category' => 'Withdrawal Taxes', 'value' => '1.00', 'description' => 'Housing fund employee contribution rate.', 'requires_approval' => 1],
    ['key' => 'tax.citec_employer', 'name' => 'CITEC Logement Employer (%)', 'category' => 'Withdrawal Taxes', 'value' => '2.50', 'description' => 'Housing fund employer contribution rate.', 'requires_approval' => 1],
    ['key' => 'tax.stamp_duty_rate', 'name' => 'Stamp Duty Rate (%)', 'category' => 'Withdrawal Taxes', 'value' => '0.50', 'description' => 'Stamp duty rate on withdrawal transactions.', 'requires_approval' => 0],
    // ★ FIX (ST-013): Added tax.union_dues_rate — was used in frontend calculateWithdrawalTaxes()
    // but never seeded in the backend, making it invisible and uneditable in the Settings UI.
    ['key' => 'tax.union_dues_rate', 'name' => 'Union / Syndicat Dues (%)', 'category' => 'Withdrawal Taxes', 'value' => '1.00', 'description' => 'Trade union / syndicat membership dues rate deducted from salary withdrawals.', 'requires_approval' => 0],
    ['key' => 'tax.consolidated_relief_annual', 'name' => 'Annual Consolidated Relief', 'category' => 'Withdrawal Taxes', 'value' => '200000', 'description' => 'Annual tax-free allowance divided by 12 for monthly computation.', 'requires_approval' => 1],
    ['key' => 'tax.percentage_relief_rate', 'name' => 'Percentage Relief Rate (%)', 'category' => 'Withdrawal Taxes', 'value' => '20.00', 'description' => 'Additional relief percentage applied on gross earnings.', 'requires_approval' => 1],
    // ── Withdrawal Limits ──
    ['key' => 'withdrawal.minimum_salary', 'name' => 'Min Salary Withdrawal', 'category' => 'Withdrawal Taxes', 'value' => '5000', 'description' => 'Minimum withdrawal amount for salary accounts.', 'requires_approval' => 0],
    ['key' => 'withdrawal.maximum_daily_salary', 'name' => 'Max Daily Salary Withdrawal', 'category' => 'Withdrawal Taxes', 'value' => '2000000', 'description' => 'Maximum daily withdrawal limit for salary accounts.', 'requires_approval' => 0],
    // ── Withdrawal Fees ──
    ['key' => 'withdrawal.fee_salary', 'name' => 'Salary Withdrawal Fee (%)', 'category' => 'Fees', 'value' => '0.00', 'description' => 'Bank processing fee for salary account withdrawals.', 'requires_approval' => 0],
    ['key' => 'withdrawal.fee_current', 'name' => 'Current Withdrawal Fee (%)', 'category' => 'Fees', 'value' => '0.00', 'description' => 'Bank processing fee for current account withdrawals.', 'requires_approval' => 0],
    ['key' => 'withdrawal.fee_tiers_current', 'name' => 'Current Withdrawal Tier Rules', 'category' => 'Fees', 'value' => '0-100000:0.50|100000.01-500000:0.75|500000.01-*:1.00', 'description' => 'Tiered percentage fee rules for current account withdrawals. Format: min-max:rate|min-max:rate. Use * as open-ended max.', 'requires_approval' => 0],
    ['key' => 'withdrawal.fee_savings', 'name' => 'Savings Withdrawal Fee (%)', 'category' => 'Fees', 'value' => '0.00', 'description' => 'Bank processing fee for savings account withdrawals.', 'requires_approval' => 0],
    ['key' => 'withdrawal.fee_mode_SALARY', 'name' => 'Salary Fee Deduction Mode', 'category' => 'Fees', 'value' => 'WITHDRAWAL', 'description' => 'How salary withdrawal fees are charged: WITHDRAWAL (from amount) or ACCOUNT (separate charge).', 'requires_approval' => 0],
    ['key' => 'withdrawal.fee_mode_CURRENT', 'name' => 'Current Fee Deduction Mode', 'category' => 'Fees', 'value' => 'WITHDRAWAL', 'description' => 'How current withdrawal fees are charged: WITHDRAWAL (from amount) or ACCOUNT (separate charge).', 'requires_approval' => 0],
    ['key' => 'withdrawal.fee_mode_SAVINGS', 'name' => 'Savings Fee Deduction Mode', 'category' => 'Fees', 'value' => 'WITHDRAWAL', 'description' => 'How savings withdrawal fees are charged: WITHDRAWAL (from amount) or ACCOUNT (separate charge).', 'requires_approval' => 0],
    // ── GL Control Accounts ──
    ['key' => 'gl.control.clearing_account', 'name' => 'GL Clearing Account Code', 'category' => 'Accounting', 'value' => '1990', 'description' => 'Control account for in-flight settlement and temporary transaction clearing.', 'requires_approval' => 1],
    ['key' => 'gl.control.suspense_account', 'name' => 'GL Suspense Account Code', 'category' => 'Accounting', 'value' => '1999', 'description' => 'Control account used to hold unresolved posting anomalies pending investigation.', 'requires_approval' => 1],
    ['key' => 'gl.control.accrued_interest_account', 'name' => 'GL Accrued Interest Account Code', 'category' => 'Accounting', 'value' => '1210', 'description' => 'Control account for accrued loan interest receivable recognition.', 'requires_approval' => 1],
    ['key' => 'gl.enforce_balanced_journals', 'name' => 'Enforce Balanced Journals', 'category' => 'Accounting', 'value' => 'ON', 'description' => 'When ON, financial posting endpoints fail-fast if double-entry posting cannot be completed atomically.', 'requires_approval' => 1],
    // ── Salary Allowance Ratios ──
    ['key' => 'salary.housing_allowance_pct', 'name' => 'Housing Allowance (%)', 'category' => 'Withdrawal Taxes', 'value' => '15.00', 'description' => 'Housing allowance as percentage of basic salary for enterprise payslip.', 'requires_approval' => 0],
    ['key' => 'salary.transport_allowance_pct', 'name' => 'Transport Allowance (%)', 'category' => 'Withdrawal Taxes', 'value' => '5.00', 'description' => 'Transport allowance as percentage of basic salary for enterprise payslip.', 'requires_approval' => 0],
    // ── Cameroon Tax (General) ──
    ['key' => 'tax.ir_rate', 'name' => 'Income Tax Rate (IR) (%)', 'category' => 'Tax', 'value' => '11.25', 'description' => 'Personal income tax withholding rate.', 'requires_approval' => 1],
    ['key' => 'tax.ir_threshold', 'name' => 'Income Tax Exemption Threshold', 'category' => 'Tax', 'value' => '62000', 'description' => 'Monthly salary below which no IR is deducted.', 'requires_approval' => 1],
    ['key' => 'tax.cnps_employee_rate', 'name' => 'CNPS Employee Rate (%)', 'category' => 'Tax', 'value' => '2.80', 'description' => 'Social security employee contribution rate.', 'requires_approval' => 1],
    ['key' => 'tax.cnps_employer_rate', 'name' => 'CNPS Employer Rate (%)', 'category' => 'Tax', 'value' => '4.20', 'description' => 'Social security employer contribution rate.', 'requires_approval' => 1],
    ['key' => 'tax.cnps_ceiling', 'name' => 'CNPS Contribution Ceiling', 'category' => 'Tax', 'value' => '750000', 'description' => 'Max monthly salary for CNPS calculation.', 'requires_approval' => 1],
    ['key' => 'tax.registration', 'name' => 'Registration Tax Rate (%)', 'category' => 'Tax', 'value' => '1.00', 'description' => 'Tax on financial transactions registration.', 'requires_approval' => 1],
    ['key' => 'tax.stamp_duty', 'name' => 'Stamp Duty Rate (%)', 'category' => 'Tax', 'value' => '0.20', 'description' => 'Stamp duty on transactions.', 'requires_approval' => 0],
    ['key' => 'tax.vat_rate', 'name' => 'VAT Rate (%)', 'category' => 'Tax', 'value' => '19.25', 'description' => 'Standard VAT rate for services.', 'requires_approval' => 1],
    ['key' => 'tax.withholding_rate', 'name' => 'Withholding Tax Rate (%)', 'category' => 'Tax', 'value' => '5.00', 'description' => 'Standard withholding tax rate.', 'requires_approval' => 1],
];
$seedCheckStmt = $db->prepare('SELECT 1 FROM settings WHERE "key" = :key LIMIT 1');
$seedInsertStmt = $db->prepare(
    'INSERT INTO settings ("key", name, category, value, description, requires_approval)
     VALUES (:key, :name, :cat, :val, :desc, :reqApp)'
);
// ── Self-heal: repair corrupted settings where name was overwritten with the key ──
$repairStmt = $db->prepare(
    'UPDATE settings SET name = :name, category = :cat, description = :desc, requires_approval = :reqApp
     WHERE "key" = :key AND (name = "key" OR name IS NULL OR category = \'General\' OR description IS NULL)'
);
foreach ($operationalDefaults as $def) {
    try {
        $seedCheckStmt->execute([':key' => $def['key']]);
        if (!$seedCheckStmt->fetch()) {
            $seedInsertStmt->execute([
                ':key' => $def['key'],
                ':name' => $def['name'],
                ':cat' => $def['category'],
                ':val' => $def['value'],
                ':desc' => $def['description'],
                ':reqApp' => $def['requires_approval']
            ]);
        } else {
            // Setting exists — repair corrupted metadata if needed
            $repairStmt->execute([
                ':key' => $def['key'],
                ':name' => $def['name'],
                ':cat' => $def['category'],
                ':desc' => $def['description'],
                ':reqApp' => $def['requires_approval']
            ]);
        }
    } catch (PDOException $e) { /* setting already exists or table issue */ }
}

switch ($method) {
    case 'GET':
        $params = [];
        $where = buildWhere($_GET, ['category', '"key"'], ['category' => '=', '"key"' => '='], $params);
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM settings ' . $where . ' ORDER BY category, \"key\" ASC");
            $stmt->execute($params);
            successResponse($stmt->fetchAll());
        } catch (PDOException $e) { serverErrorResponse('Database error.'); }
        break;

    case 'PUT':
        requireRole(['ADMIN']);
        $input = getRequestInput();
        $errors = validateRequired($input, ['key', 'value']);
        if (!empty($errors)) { validationError($errors); }

        // ★ SECURITY FIX (FIN-2b-013): Validate setting values before persisting.
        // Prevents admins (or compromised sessions) from disabling security controls
        // by setting invalid values (e.g., max_login_attempts to "abc" or -1).
        // ★ FIX (ST-024): Expanded validation to cover ALL financial/tax/fee settings.
        // Previously only 15 rules — now covers all 40+ operational settings.
        // ★ FIX (ST-025): Replaced dead 'tax.citec_rate' with actual 'tax.citec_employee'/'tax.citec_employer'.
        $validationRules = [
            // Security
            'security.max_login_attempts' => ['type' => 'int', 'min' => 1, 'max' => 20],
            'security.lockout_duration'   => ['type' => 'int', 'min' => 1, 'max' => 1440],
            'security.session_timeout'    => ['type' => 'int', 'min' => 5, 'max' => 1440],
            'security.max_concurrent_sessions' => ['type' => 'int', 'min' => 1, 'max' => 10],
            // General Tax
            'tax.ir_rate'                 => ['type' => 'float', 'min' => 0, 'max' => 100],
            'tax.ir_threshold'            => ['type' => 'float', 'min' => 0],
            'tax.cnps_employee_rate'      => ['type' => 'float', 'min' => 0, 'max' => 100],
            'tax.cnps_employer_rate'      => ['type' => 'float', 'min' => 0, 'max' => 100],
            'tax.cnps_ceiling'            => ['type' => 'float', 'min' => 0],
            'tax.registration'            => ['type' => 'float', 'min' => 0],
            'tax.stamp_duty'              => ['type' => 'float', 'min' => 0],
            'tax.vat_rate'                => ['type' => 'float', 'min' => 0, 'max' => 100],
            'tax.withholding_rate'        => ['type' => 'float', 'min' => 0, 'max' => 100],
            // Withdrawal Tax rates
            'tax.paye_rate'               => ['type' => 'float', 'min' => 0, 'max' => 50],
            'tax.cnps_retraite_employee'  => ['type' => 'float', 'min' => 0, 'max' => 100],
            'tax.cnps_retraite_employer'  => ['type' => 'float', 'min' => 0, 'max' => 100],
            'tax.cnps_prestations'        => ['type' => 'float', 'min' => 0, 'max' => 100],
            'tax.citec_employee'          => ['type' => 'float', 'min' => 0, 'max' => 100],
            'tax.citec_employer'          => ['type' => 'float', 'min' => 0, 'max' => 100],
            'tax.stamp_duty_rate'         => ['type' => 'float', 'min' => 0, 'max' => 100],
            'tax.union_dues_rate'         => ['type' => 'float', 'min' => 0, 'max' => 100],
            'tax.consolidated_relief_annual' => ['type' => 'float', 'min' => 0],
            'tax.percentage_relief_rate'  => ['type' => 'float', 'min' => 0, 'max' => 100],
            // Withdrawal Limits
            'withdrawal.minimum_salary'   => ['type' => 'float', 'min' => 0],
            'withdrawal.maximum_daily_salary' => ['type' => 'float', 'min' => 0],
            // Withdrawal Fees
            'withdrawal.fee_salary'       => ['type' => 'float', 'min' => 0, 'max' => 100],
            'withdrawal.fee_current'      => ['type' => 'float', 'min' => 0, 'max' => 100],
            'withdrawal.fee_savings'      => ['type' => 'float', 'min' => 0, 'max' => 100],
            // Fee rates
            'fee.processing_fee_rate'     => ['type' => 'float', 'min' => 0, 'max' => 100],
            'fee.withdrawal_fee_rate'     => ['type' => 'float', 'min' => 0, 'max' => 100],
            'fee.transfer_fee_rate'       => ['type' => 'float', 'min' => 0, 'max' => 100],
            'fee.withdrawal_counter'      => ['type' => 'float', 'min' => 0, 'max' => 100],
            'fee.withdrawal_atm'          => ['type' => 'float', 'min' => 0],
            'fee.transfer_internal'       => ['type' => 'float', 'min' => 0],
            'fee.transfer_external'       => ['type' => 'float', 'min' => 0],
            'fee.account_maintenance'     => ['type' => 'float', 'min' => 0],
            'fee.loan_processing'         => ['type' => 'float', 'min' => 0, 'max' => 100],
            'fee.loan_late_payment'       => ['type' => 'float', 'min' => 0, 'max' => 100],
            // Loan settings
            'txn.approval_threshold'      => ['type' => 'float', 'min' => 0],
            'loan.default_interest_rate'  => ['type' => 'float', 'min' => 0, 'max' => 50],
            'loan.max_amount'             => ['type' => 'float', 'min' => 0],
            'loan.late_payment_penalty'   => ['type' => 'float', 'min' => 0, 'max' => 50],
            'loan.dti_ratio'              => ['type' => 'float', 'min' => 0, 'max' => 100],
            'loan.min_holding_days'       => ['type' => 'int', 'min' => 0, 'max' => 3650],
            // Salary allowances
            'salary.housing_allowance_pct' => ['type' => 'float', 'min' => 0, 'max' => 100],
            'salary.transport_allowance_pct' => ['type' => 'float', 'min' => 0, 'max' => 100],
        ];

        // ★ FIX (ST-058): Add enum validation for settings that accept only specific values.
        // Without this, an admin could set loan.module to 'INVALID' or security.mfa_required
        // to 'maybe', causing unpredictable frontend behavior and rendering errors.
        $enumValidation = [
            'security.mfa_required'       => ['true', 'false'],
            'loan.module'                 => ['BANK', 'CREDIT_UNION'],
            'auto_deduction_enabled'      => ['ON', 'OFF'],
            'withdrawal.fee_mode_SALARY'  => ['WITHDRAWAL', 'ACCOUNT'],
            'withdrawal.fee_mode_CURRENT' => ['WITHDRAWAL', 'ACCOUNT'],
            'withdrawal.fee_mode_SAVINGS' => ['WITHDRAWAL', 'ACCOUNT'],
            'gl.enforce_balanced_journals' => ['ON', 'OFF'],
            'tax.apply_on_salary_withdrawal'   => ['YES', 'NO'],
            'tax.apply_on_current_withdrawal'  => ['YES', 'NO'],
            'tax.apply_on_savings_withdrawal'  => ['YES', 'NO'],
            'operations.currency'         => ['XAF', 'XOF', 'EUR', 'USD', 'GBP', 'NGN', 'GHS', 'KES', 'ZAR', 'MAD', 'TND', 'EGP'],
            'operations.date_format'      => ['d M Y', 'Y-m-d', 'm/d/Y', 'd/m/Y', 'd-m-Y', 'M d, Y'],
        ];

        $settingKey = $input['key'] ?? '';
        // ★ FIX (ST-058): Check enum validation FIRST (before numeric rules).
        if (isset($enumValidation[$settingKey])) {
            $allowed = $enumValidation[$settingKey];
            $val = $input['value'] ?? '';
            if (!in_array($val, $allowed, true)) {
                validationError(
                    ['value' => 'Value must be one of: ' . implode(', ', $allowed) . '.'],
                    'Invalid value for ' . $settingKey
                );
            }
        } elseif (isset($validationRules[$settingKey])) {
            $rule = $validationRules[$settingKey];
            $val = $input['value'] ?? '';

            if ($rule['type'] === 'int') {
                if (!is_numeric($val) || (int)$val != $val) {
                    validationError(['value' => 'Value must be a whole number.'], 'Invalid value for ' . $settingKey);
                }
                $intVal = (int)$val;
                if (isset($rule['min']) && $intVal < $rule['min']) {
                    validationError(['value' => 'Value must be at least ' . $rule['min'] . '.'], 'Value too low for ' . $settingKey);
                }
                if (isset($rule['max']) && $intVal > $rule['max']) {
                    validationError(['value' => 'Value must be at most ' . $rule['max'] . '.'], 'Value too high for ' . $settingKey);
                }
            } elseif ($rule['type'] === 'float') {
                if (!is_numeric($val)) {
                    validationError(['value' => 'Value must be a number.'], 'Invalid value for ' . $settingKey);
                }
                $floatVal = (float)$val;
                if (isset($rule['min']) && $floatVal < $rule['min']) {
                    validationError(['value' => 'Value must be at least ' . $rule['min'] . '.'], 'Value too low for ' . $settingKey);
                }
                if (isset($rule['max']) && $floatVal > $rule['max']) {
                    validationError(['value' => 'Value must be at most ' . $rule['max'] . '.'], 'Value too high for ' . $settingKey);
                }
            }
        } elseif ($settingKey === 'withdrawal.fee_tiers_current') {
            $raw = trim((string)($input['value'] ?? ''));
            if ($raw === '') {
                validationError(['value' => 'Tier rules cannot be empty.'], 'Invalid value for ' . $settingKey);
            }
            $parts = array_values(array_filter(array_map('trim', explode('|', $raw))));
            if (empty($parts)) {
                validationError(['value' => 'Provide at least one tier rule.'], 'Invalid value for ' . $settingKey);
            }
            foreach ($parts as $p) {
                if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*-\s*([0-9]+(?:\.[0-9]+)?|\*)\s*:\s*([0-9]+(?:\.[0-9]+)?)$/', $p, $m)) {
                    validationError(
                        ['value' => 'Invalid tier format. Use min-max:rate|min-max:rate, e.g. 0-100000:0.50|100000.01-500000:0.75|500000.01-*:1.00'],
                        'Invalid value for ' . $settingKey
                    );
                }
                $min = (float)$m[1];
                $max = ($m[2] === '*') ? null : (float)$m[2];
                $rate = (float)$m[3];
                if ($max !== null && $max < $min) {
                    validationError(['value' => 'Tier max must be greater than or equal to min.'], 'Invalid value for ' . $settingKey);
                }
                if ($rate < 0 || $rate > 100) {
                    validationError(['value' => 'Tier rate must be between 0 and 100.'], 'Invalid value for ' . $settingKey);
                }
            }
        } elseif (str_starts_with($settingKey, 'gl.control.')) {
            $val = trim((string)($input['value'] ?? ''));
            if ($val === '' || !preg_match('/^[A-Za-z0-9._-]{3,20}$/', $val)) {
                validationError(
                    ['value' => 'GL control account code must be 3-20 characters (letters, numbers, dot, underscore, hyphen).'],
                    'Invalid value for ' . $settingKey
                );
            }
        }

        try {
            $db = getDB();
            $key   = sanitize($input['key']);
            $value = sanitize($input['value']);

            // ── Detect whether this is a new setting or an existing one ──
            $existing = $db->prepare('SELECT id FROM settings WHERE "key" = :key LIMIT 1');
            $existing->execute([':key' => $key]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            $isNew = !$row;

            if ($isNew) {
                // ── INSERT: use provided values, fall back to defaults ──
                $name   = isset($input['name']) ? sanitize($input['name']) : $key;
                $cat    = isset($input['category']) ? sanitize($input['category']) : 'General';
                $desc   = isset($input['description']) ? sanitize($input['description']) : null;
                $efrom  = isset($input['effective_from']) ? sanitize($input['effective_from']) : null;
                $reqApp = isset($input['requires_approval']) ? (int)$input['requires_approval'] : 0;

                $stmt = $db->prepare(
                    'INSERT INTO settings ("key", name, category, value, description, effective_from, requires_approval)
                     VALUES (:key, :name, :cat, :value, :desc, :efrom, :reqApp)'
                );
                $stmt->execute([
                    ':key'    => $key,
                    ':name'   => $name,
                    ':cat'    => $cat,
                    ':value'  => $value,
                    ':desc'   => $desc,
                    ':efrom'  => $efrom,
                    ':reqApp' => $reqApp
                ]);
            } else {
                // ── UPDATE: only modify fields that are explicitly provided ──
                // This prevents callers that send only {key, value} from
                // overwriting name, category, description, requires_approval
                // with defaults.
                $setClauses = ['"value" = :value'];
                $params = [':key' => $key, ':value' => $value];

                foreach (['name', 'category', 'description', 'effective_from', 'requires_approval'] as $field) {
                    if (array_key_exists($field, $input)) {
                        $setClauses[] = '"' . $field . '" = :' . $field;
                        $params[":$field"] = ($field === 'requires_approval')
                            ? (int)$input[$field]
                            : sanitize($input[$field]);
                    }
                }
                $setClauses[] = '"updated_at" = NOW()';

                $sql = 'UPDATE settings SET ' . implode(', ', $setClauses) . ' WHERE "key" = :key';
                $db->prepare($sql)->execute($params);
            }

            $action = $isNew ? 'Created' : 'Updated';
            logAudit($staff['full_name'], 'SETTING_UPDATE', 'SETTING', $key, 'SUCCESS',
                $action . ' setting: ' . $key . ' = ' . $value,
                $staff['department'], getClientIp());

            // Return the updated/created record
            $fetchStmt = $db->prepare('SELECT * FROM settings WHERE "key" = :key LIMIT 1');
            $fetchStmt->execute([':key' => $key]);
            $record = $fetchStmt->fetch();
            if ($record) {
                successResponse($record, 'Setting ' . strtolower($action) . ' successfully.');
            } else {
                successMessage('Setting ' . strtolower($action) . ' successfully.');
            }
        } catch (PDOException $e) { serverErrorResponse('Failed to save setting.'); }
        break;

    default:
        errorResponse('Method not allowed.', 405);
}
