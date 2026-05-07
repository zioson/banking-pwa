-- ============================================================
-- Atlas Bank — Withdrawal Fee & Tax Settings (Safe / Idempotent)
-- ============================================================
-- This file adds all withdrawal fee percentile settings per
-- account type, tax engine settings, and fee mode toggles
-- that the frontend withdrawal engine requires.
--
-- RUN THIS IN phpMyAdmin or MySQL CLI:
--   mysql -u root atlas_bank < fix_withdrawal_fees_settings.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. WITHDRAWAL FEE PERCENTILE PER ACCOUNT TYPE
--    Keys expected by getWithdrawalFeePct(productType):
--      withdrawal.fee_salary  → Salary Account fee %
--      withdrawal.fee_current → Current Account fee %
--      withdrawal.fee_savings → Savings Account fee %
-- ============================================================
INSERT IGNORE INTO settings (`key`, name, category, value, description, effective_from, requires_approval) VALUES
('withdrawal.fee_salary',       'Salary Account Withdrawal Fee (%)',  'Fees', '0.00',  'Bank processing fee percentage on withdrawals from Salary accounts. Set to 0 for no fee.',  '2024-01-01', 0),
('withdrawal.fee_current',      'Current Account Withdrawal Fee (%)', 'Fees', '0.50',  'Bank processing fee percentage on withdrawals from Current accounts. Default 0.5%.',           '2024-01-01', 1),
('withdrawal.fee_savings',      'Savings Account Withdrawal Fee (%)', 'Fees', '0.25',  'Bank processing fee percentage on withdrawals from Savings accounts. Default 0.25%.',          '2024-01-01', 1);

-- ============================================================
-- 2. WITHDRAWAL FEE DEDUCTION MODE PER ACCOUNT TYPE
--    Keys expected by getWithdrawalFeeMode(productType):
--      withdrawal.fee_mode_salary  → WITHDRAWAL or ACCOUNT
--      withdrawal.fee_mode_current → WITHDRAWAL or ACCOUNT
--      withdrawal.fee_mode_savings → WITHDRAWAL or ACCOUNT
--    WITHDRAWAL = deduct fee FROM withdrawal amount
--    ACCOUNT   = charge fee ON TOP of withdrawal (extra debit)
-- ============================================================
INSERT IGNORE INTO settings (`key`, name, category, value, description, effective_from, requires_approval) VALUES
('withdrawal.fee_mode_salary',   'Salary Fee Deduction Mode',  'Fees', 'WITHDRAWAL', 'How fee is applied for Salary accounts. WITHDRAWAL = deducted from amount, ACCOUNT = charged separately.',  '2024-01-01', 1),
('withdrawal.fee_mode_current',  'Current Fee Deduction Mode', 'Fees', 'ACCOUNT',    'How fee is applied for Current accounts. WITHDRAWAL = deducted from amount, ACCOUNT = charged separately.',   '2024-01-01', 1),
('withdrawal.fee_mode_savings',  'Savings Fee Deduction Mode', 'Fees', 'WITHDRAWAL', 'How fee is applied for Savings accounts. WITHDRAWAL = deducted from amount, ACCOUNT = charged separately.',  '2024-01-01', 1);

-- ============================================================
-- 3. TAX APPLICATION TOGGLES PER ACCOUNT TYPE
--    Keys expected by taxesApplyToAccount(productType):
--      tax.apply_on_salary_withdrawal  → YES or NO
--      tax.apply_on_current_withdrawal → YES or NO
--      tax.apply_on_savings_withdrawal → YES or NO
-- ============================================================
INSERT IGNORE INTO settings (`key`, name, category, value, description, effective_from, requires_approval) VALUES
('tax.apply_on_salary_withdrawal',  'Tax on Salary Withdrawals',  'Withdrawal Taxes', 'NO',  'Enable/disable statutory deductions (PAYE, CNPS, CITEC, etc.) on Salary account withdrawals.',  '2024-01-01', 1),
('tax.apply_on_current_withdrawal', 'Tax on Current Withdrawals', 'Withdrawal Taxes', 'NO',  'Enable/disable statutory deductions on Current account withdrawals.',                                 '2024-01-01', 1),
('tax.apply_on_savings_withdrawal', 'Tax on Savings Withdrawals', 'Withdrawal Taxes', 'NO',  'Enable/disable statutory deductions on Savings account withdrawals.',                                 '2024-01-01', 1);

-- ============================================================
-- 4. WITHDRAWAL LIMITS
--    Keys used by getMinimumWithdrawal() and getMaximumDailyWithdrawal()
-- ============================================================
INSERT IGNORE INTO settings (`key`, name, category, value, description, effective_from, requires_approval) VALUES
('tax.minimum_withdrawal',          'Minimum Withdrawal Amount (FCFA)',   'Withdrawal Taxes', '1000',    'Lowest amount allowed per withdrawal transaction.',                         '2024-01-01', 1),
('tax.maximum_daily_withdrawal',    'Maximum Daily Withdrawal (FCFA)',    'Withdrawal Taxes', '2000000', 'Maximum total withdrawal amount per account per calendar day.',              '2024-01-01', 1);

-- ============================================================
-- 5. STATUTORY TAX RATES (Cameroon)
--    Keys used by calculateWithdrawalTaxes():
--      tax.paye_rate               → PAYE Income Tax rate
--      tax.cnps_retraite_employee  → CNPS Retraite employee rate
--      tax.cnps_prestations        → CNPS Prestations rate
--      tax.citec_employee          → CITEC Logement employee rate
--      tax.stamp_duty_rate         → Stamp Duty / Droit de Timbre rate
--      tax.consolidated_relief_annual → Annual consolidated relief
--      tax.percentage_relief_rate  → Percentage relief rate
--      tax.union_dues_rate         → Union / Syndicat dues rate
--      tax.allowance_pct_housing   → Housing allowance %
--      tax.allowance_pct_transport → Transport allowance %
-- ============================================================
INSERT IGNORE INTO settings (`key`, name, category, value, description, effective_from, requires_approval) VALUES
('tax.paye_rate',                    'PAYE Income Tax Rate (%)',              'Withdrawal Taxes', '7.50',     'Personal Income Tax (Impôt sur le Revenu des Personnes Physiques) withholding rate.',   '2024-01-01', 1),
('tax.cnps_retraite_employee',       'CNPS Retraite Employee Rate (%)',       'Withdrawal Taxes', '4.80',     'Caisse Nationale de Prévoyance Sociale — Retraite employee contribution rate.',        '2024-01-01', 1),
('tax.cnps_prestations',             'CNPS Prestations Rate (%)',             'Withdrawal Taxes', '1.70',     'CNPS Prestations Familiales employee contribution rate.',                               '2024-01-01', 1),
('tax.citec_employee',               'CITEC Logement Employee Rate (%)',      'Withdrawal Taxes', '1.00',     'Caisse Interprofessionnelle de Prévoyance des Travailleurs — employee rate.',          '2024-01-01', 1),
('tax.stamp_duty_rate',              'Stamp Duty Rate (%)',                   'Withdrawal Taxes', '0.50',     'Droit de Timbre on financial transactions.',                                          '2024-01-01', 0),
('tax.consolidated_relief_annual',   'Consolidated Relief Annual (FCFA)',     'Withdrawal Taxes', '200000',   'Annual consolidated relief threshold for PAYE computation.',                              '2024-01-01', 1),
('tax.percentage_relief_rate',       'Percentage Relief Rate (%)',            'Withdrawal Taxes', '20.00',    'Percentage of gross salary applied as taxable income relief.',                           '2024-01-01', 1),
('tax.union_dues_rate',              'Union / Syndicat Dues Rate (%)',        'Withdrawal Taxes', '1.00',     'Trade union membership dues rate on salary.',                                         '2024-01-01', 0),
('tax.allowance_pct_housing',        'Housing Allowance Ratio (%)',           'Withdrawal Taxes', '15.00',    'Standard housing allowance percentage of basic salary for payslip computation.',        '2024-01-01', 0),
('tax.allowance_pct_transport',      'Transport Allowance Ratio (%)',         'Withdrawal Taxes', '5.00',     'Standard transport allowance percentage of basic salary for payslip computation.',      '2024-01-01', 0);

-- ============================================================
-- 6. TRANSFER FEE SETTINGS (additional per-type fees)
-- ============================================================
INSERT IGNORE INTO settings (`key`, name, category, value, description, effective_from, requires_approval) VALUES
('transfer.fee_internal_pct',    'Internal Transfer Fee (%)',      'Fees', '0.00',  'Percentage fee for internal account-to-account transfers within the same bank.',    '2024-01-01', 0),
('transfer.fee_external_pct',    'External Transfer Fee (%)',      'Fees', '0.10',  'Percentage fee for external bank-to-bank transfers.',                                    '2024-01-01', 1),
('transfer.fee_mobile_pct',      'Mobile Money Transfer Fee (%)',  'Fees', '0.50',  'Percentage fee for mobile money transfers (MTN MoMo, Orange Money).',                    '2024-01-01', 1),
('transfer.fee_international_pct','International Transfer Fee (%)', 'Fees', '0.25',  'Percentage fee for international wire transfers.',                                        '2024-01-01', 1);

-- ============================================================
-- 7. LOAN FEE SETTINGS
-- ============================================================
INSERT IGNORE INTO settings (`key`, name, category, value, description, effective_from, requires_approval) VALUES
('loan.default_interest_rate',     'Default Loan Interest Rate (%)',    'Fees', '12.50',  'Default annual interest rate applied to new loan applications.',                        '2024-01-01', 1),
('loan.late_payment_penalty_rate', 'Late Payment Penalty Rate (%)',    'Fees', '2.00',   'Penalty percentage charged on overdue loan repayments.',                                  '2024-01-01', 1),
('loan.max_dti_ratio',             'Maximum DTI Ratio (%)',            'Fees', '40.00',  'Maximum debt-to-income ratio allowed for new loans.',                                     '2024-01-01', 1),
('loan.processing_fee_pct',        'Loan Processing Fee (%)',          'Fees', '1.00',   'One-time fee charged when a loan is disbursed.',                                         '2024-01-01', 1),
('loan.auto_deduction_enabled',    'Auto-Deduction Enabled',           'OPERATIONS', 'ON', 'Whether loan repayments are automatically deducted from debit accounts.',               '2024-01-01', 1),
('loan.auto_deduction_interval_seconds', 'Auto-Deduction Interval (sec)','OPERATIONS', '30','Interval in seconds between automatic loan deduction checks.',                           '2024-01-01', 0);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- DONE. The following settings are now available:
--
-- WITHDRAWAL FEES per account type (configurable %):
--   withdrawal.fee_salary  (default 0%)
--   withdrawal.fee_current (default 0.5%)
--   withdrawal.fee_savings (default 0.25%)
--
-- FEE DEDUCTION MODE per account type:
--   withdrawal.fee_mode_salary  (default: FROM withdrawal)
--   withdrawal.fee_mode_current (default: SEPARATE charge)
--   withdrawal.fee_mode_savings (default: FROM withdrawal)
--
-- TAX TOGGLES per account type:
--   tax.apply_on_salary_withdrawal  (default: NO)
--   tax.apply_on_current_withdrawal (default: NO)
--   tax.apply_on_savings_withdrawal (default: NO)
--
-- STATUTORY RATES (Cameroon):
--   PAYE: 7.5%, CNPS Retraite: 4.8%, CNPS Prestations: 1.7%,
--   CITEC: 1%, Stamp Duty: 0.5%, Union Dues: 1%
--
-- WITHDRAWAL LIMITS:
--   Minimum: 1,000 FCFA, Maximum daily: 2,000,000 FCFA
--
-- TRANSFER FEES:
--   Internal: 0%, External: 0.1%, Mobile: 0.5%, International: 0.25%
--
-- LOAN SETTINGS:
--   Interest: 12.5%, Late penalty: 2%, Max DTI: 40%, Processing: 1%
-- ============================================================
