-- ============================================================
-- Atlas Bank Withdrawal Fee Settings Migration
-- Run this SQL in phpMyAdmin or MySQL CLI
--
-- The frontend expects settings with keys like:
--   withdrawal.fee_salary, withdrawal.fee_current, withdrawal.fee_savings
--   withdrawal.fee_mode_salary, withdrawal.fee_mode_current, withdrawal.fee_mode_savings
-- But the seed data only had FEE_WITHDRAWAL_COUNTER and FEE_WITHDRAWAL_ATM.
-- This migration adds the correct keys.
-- ============================================================

-- Withdrawal fee percentages per account type
INSERT INTO settings (`key`, name, category, value, description, effective_from, requires_approval) VALUES
('withdrawal.fee_salary', 'Salary Account Withdrawal Fee', 'WITHDRAWAL_FEES', '0.50', 'Withdrawal fee rate for salary accounts (%)', '2024-01-01', 0),
('withdrawal.fee_current', 'Current Account Withdrawal Fee', 'WITHDRAWAL_FEES', '0.50', 'Withdrawal fee rate for current accounts (%)', '2024-01-01', 0),
('withdrawal.fee_savings', 'Savings Account Withdrawal Fee', 'WITHDRAWAL_FEES', '0.25', 'Withdrawal fee rate for savings accounts (%)', '2024-01-01', 0)
ON DUPLICATE KEY UPDATE `key` = VALUES(`key`);

-- Fee deduction mode per account type (WITHDRAWAL = deduct from amount, ACCOUNT = charge separately)
INSERT INTO settings (`key`, name, category, value, description, effective_from, requires_approval) VALUES
('withdrawal.fee_mode_salary', 'Salary Fee Deduction Mode', 'WITHDRAWAL_FEES', 'WITHDRAWAL', 'Whether fee is deducted from withdrawal or charged to account', '2024-01-01', 0),
('withdrawal.fee_mode_current', 'Current Fee Deduction Mode', 'WITHDRAWAL_FEES', 'WITHDRAWAL', 'Whether fee is deducted from withdrawal or charged to account', '2024-01-01', 0),
('withdrawal.fee_mode_savings', 'Savings Fee Deduction Mode', 'WITHDRAWAL_FEES', 'WITHDRAWAL', 'Whether fee is deducted from withdrawal or charged to account', '2024-01-01', 0)
ON DUPLICATE KEY UPDATE `key` = VALUES(`key`);

-- Verify
SELECT `key`, name, value FROM settings WHERE `key` LIKE 'withdrawal.fee%';
