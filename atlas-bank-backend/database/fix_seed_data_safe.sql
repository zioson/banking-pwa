-- ============================================================
-- Atlas Bank — COMPLETE SEED DATA (Safe / Idempotent)
-- ============================================================
-- This file is SAFE to run multiple times.
-- Uses INSERT IGNORE and SET FOREIGN_KEY_CHECKS=0
-- so existing data is preserved and duplicates are skipped.
--
-- RUN THIS IN phpMyAdmin or MySQL CLI:
--   mysql -u root atlas_bank < fix_seed_data_safe.sql
--
-- Or paste into phpMyAdmin SQL tab and click Go.
-- ============================================================

USE atlas_bank;

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. BANK BRANDING (1 row)
-- ============================================================
INSERT IGNORE INTO bank_branding (id, bank_name, bank_name_short, tagline, primary_color, accent_color,
  head_office_address, phone, phone_alt, email, website,
  swift_code, cbn_license_number, registration_number, tax_identification_number, slogan)
VALUES (1, 'ATLAS BANK', 'Atlas Bank', 'Enterprise Operations Console', '#58b7ff', '#67e8b5',
  '45 Avenue de la Liberte, BP 12345, Douala, Littoral, Cameroon',
  '+237 233 42 15 00', '+237 699 00 00 01', 'info@atlasbank.cm', 'www.atlasbank.cm',
  'ATLSCMCD', 'CBN-LIC-2024-0847', 'RC-DC-2024-B0012', 'TIN-CM-91000-ATLAS',
  'Building Trust, Securing Futures');

-- ============================================================
-- 2. BRANCHES (3 rows)
-- ============================================================
INSERT IGNORE INTO branches (id, code, name, region, country, status) VALUES
(1, 'DLA', 'Douala Main Branch', 'Littoral', 'CM', 'ACTIVE'),
(2, 'DLP', 'Douala Port Branch', 'Littoral', 'CM', 'ACTIVE'),
(3, 'YDE', 'Yaounde Head Office', 'Centre', 'CM', 'ACTIVE');

-- ============================================================
-- 3. STAFF (5 rows)
--    Logins: admin/admin123, supervisor/admin123, teller/admin123,
--            auditor/auditor123, compliance/auditor123
-- ============================================================
INSERT IGNORE INTO staff (id, username, full_name, initials, email, phone,
  position, role, department, password_hash, salt,
  mfa_required, employment_status, approval_limit) VALUES
(1, 'admin', 'Emmanuel Nkoulou', 'EN', 'e.nkoulou@atlasbank.cm', '+237 699 11 00 01',
  'Chief Operations Officer', 'ADMIN', 'Operations',
  '$2b$10$H2e9CSfHhyRlxWWxlQWDEutQTvHBXftFuWP73HGRRwtoUs.XphBcS',
  'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
  0, 'ACTIVE', 50000000),
(2, 'supervisor', 'Clementine Fotso', 'CF', 'c.fotso@atlasbank.cm', '+237 699 22 00 02',
  'Branch Supervisor', 'SUPERVISOR', 'Operations',
  '$2b$10$H2e9CSfHhyRlxWWxlQWDEutQTvHBXftFuWP73HGRRwtoUs.XphBcS',
  'b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3',
  1, 'ACTIVE', 10000000),
(3, 'teller', 'Alain Ndongo', 'AN', 'a.ndongo@atlasbank.cm', '+237 699 33 00 03',
  'Senior Teller', 'TELLER', 'Operations',
  '$2b$10$H2e9CSfHhyRlxWWxlQWDEutQTvHBXftFuWP73HGRRwtoUs.XphBcS',
  'c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
  1, 'ACTIVE', 2000000),
(4, 'auditor', 'Isabelle Mbarga', 'IM', 'i.mbarga@atlasbank.cm', '+237 699 44 00 04',
  'Internal Auditor', 'AUDITOR', 'Compliance & Audit',
  '$2b$10$gGxtuPLj6m30vsB.Vbr9YOTR73m5taW3F144Y/J0Z4yWoswV5e77W',
  'd4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6',
  1, 'ACTIVE', 0),
(5, 'compliance', 'Jean-Pierre Tchinda', 'JT', 'jp.tchinda@atlasbank.cm', '+237 699 55 00 05',
  'Compliance Officer', 'COMPLIANCE', 'Compliance & Audit',
  '$2b$10$gGxtuPLj6m30vsB.Vbr9YOTR73m5taW3F144Y/J0Z4yWoswV5e77W',
  'e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1',
  1, 'ACTIVE', 0);

-- ============================================================
-- 4. STAFF-BRANCH MAPPINGS
-- ============================================================
INSERT IGNORE INTO staff_branches (staff_id, branch_name) VALUES
(1, 'Douala Main Branch'), (1, 'Douala Port Branch'), (1, 'Yaounde Head Office'),
(2, 'Douala Main Branch'), (2, 'Douala Port Branch'),
(3, 'Douala Main Branch'),
(4, 'Douala Main Branch'), (4, 'Yaounde Head Office'),
(5, 'Douala Main Branch'), (5, 'Yaounde Head Office');

-- ============================================================
-- 5. STAFF-MODULE MAPPINGS (RBAC)
-- ============================================================
INSERT IGNORE INTO staff_modules (staff_id, module_name) VALUES
(1, 'ALL'),
(2, 'DASHBOARD'), (2, 'CUSTOMERS'), (2, 'ACCOUNTS'), (2, 'TRANSACTIONS'),
(2, 'LOANS'), (2, 'TRANSFERS'), (2, 'BRANCHES'), (2, 'APPROVALS'), (2, 'NOTIFICATIONS'),
(3, 'DASHBOARD'), (3, 'CUSTOMERS'), (3, 'ACCOUNTS'), (3, 'TRANSACTIONS'), (3, 'LOANS'), (3, 'TRANSFERS'),
(4, 'DASHBOARD'), (4, 'CUSTOMERS'), (4, 'ACCOUNTS'), (4, 'TRANSACTIONS'), (4, 'LOANS'),
(4, 'REPORTS'), (4, 'AUDIT'), (4, 'BRANCHES'), (4, 'STAFF'), (4, 'DOCUMENTS'),
(4, 'CHART_OF_ACCOUNTS'), (4, 'EXPENSES'), (4, 'POLICIES'),
(5, 'DASHBOARD'), (5, 'CUSTOMERS'), (5, 'REPORTS'), (5, 'AUDIT'), (5, 'POLICIES'), (5, 'NOTIFICATIONS');

-- ============================================================
-- 6. CUSTOMERS (8 rows)
-- ============================================================
INSERT IGNORE INTO customers (id, customer_number, customer_type, full_name, status, risk_rating,
  branch, relationship_started, phone, email, kyc_verified) VALUES
(1, 'CUST-000001', 'INDIVIDUAL', 'Paul Biyong', 'ACTIVE', 'LOW',
  'Douala Main Branch', '2022-03-15', '+237 677 12 34 56', 'p.biyong@email.com', 1),
(2, 'CUST-000002', 'BUSINESS', 'Cameroon Logistics SARL', 'ACTIVE', 'MEDIUM',
  'Douala Main Branch', '2021-08-20', '+237 233 45 67 89', 'info@cmlogistics.com', 1),
(3, 'CUST-000003', 'INDIVIDUAL', 'Marie Ndongo', 'ACTIVE', 'LOW',
  'Douala Port Branch', '2023-01-10', '+237 698 23 45 67', 'm.ndongo@email.com', 1),
(4, 'CUST-000004', 'BUSINESS', 'AfrikaTech Solutions', 'ACTIVE', 'MEDIUM',
  'Yaounde Head Office', '2022-11-05', '+237 222 34 56 78', 'contact@africatech.cm', 1),
(5, 'CUST-000005', 'INDIVIDUAL', 'Jean-Pierre Kamga', 'RESTRICTED', 'HIGH',
  'Douala Main Branch', '2021-06-18', '+237 696 45 67 89', 'jp.kamga@email.com', 0),
(6, 'CUST-000006', 'INDIVIDUAL', 'Aminata Moussa', 'ACTIVE', 'LOW',
  'Yaounde Head Office', '2023-05-22', '+237 690 56 78 90', 'a.moussa@email.com', 1),
(7, 'CUST-000007', 'BUSINESS', 'GreenPlantations SA', 'ACTIVE', 'MEDIUM',
  'Douala Port Branch', '2020-12-01', '+237 233 67 89 01', 'admin@greenplantations.cm', 1),
(8, 'CUST-000008', 'INDIVIDUAL', 'Francois Nganou', 'FROZEN', 'CRITICAL',
  'Douala Main Branch', '2022-07-30', '+237 691 78 90 12', 'f.nganou@email.com', 0);

-- ============================================================
-- 7. CUSTOMER PRODUCTS (15 rows)
-- ============================================================
INSERT IGNORE INTO customer_products (customer_id, product_name) VALUES
(1, 'Salary Account'), (1, 'Savings Account'),
(2, 'Current Account'), (2, 'Treasury Account'), (2, 'Forex Account'),
(3, 'Salary Account'), (3, 'Savings Account'),
(4, 'Corporate Account'), (4, 'Current Account'),
(5, 'Current Account'), (5, 'Fixed Deposit'),
(6, 'Savings Account'),
(7, 'Corporate Account'), (7, 'Treasury Account'), (7, 'Loan'),
(8, 'Current Account');

-- ============================================================
-- 8. ACCOUNTS (12 rows)
-- ============================================================
INSERT IGNORE INTO accounts (id, account_number, customer_id, customer_name, product_type,
  branch, status, currency, ledger_balance, available_balance, hold_balance, opened_at) VALUES
(1,  'ACC-20010001', 1, 'Paul Biyong',              'SALARY',    'Douala Main Branch', 'ACTIVE',     'XAF', 850000,   850000,   0,       '2022-03-15'),
(2,  'ACC-20010002', 1, 'Paul Biyong',              'SAVINGS',   'Douala Main Branch', 'ACTIVE',     'XAF', 2500000,  2500000,  0,       '2022-03-15'),
(3,  'ACC-20010003', 2, 'Cameroon Logistics SARL',  'CURRENT',   'Douala Main Branch', 'ACTIVE',     'XAF', 15750000, 15750000, 0,       '2021-08-20'),
(4,  'ACC-20010004', 2, 'Cameroon Logistics SARL',  'TREASURY',  'Douala Main Branch', 'ACTIVE',     'XAF', 32000000, 32000000, 0,       '2021-08-20'),
(5,  'ACC-20010005', 2, 'Cameroon Logistics SARL',  'FOREX',     'Douala Main Branch', 'ACTIVE',     'USD', 45000,    45000,    0,       '2021-09-10'),
(6,  'ACC-20010006', 3, 'Marie Ndongo',             'SALARY',    'Douala Port Branch', 'ACTIVE',     'XAF', 620000,   620000,   0,       '2023-01-10'),
(7,  'ACC-20010007', 4, 'AfrikaTech Solutions',     'CORPORATE', 'Yaounde Head Office','ACTIVE',     'XAF', 8900000,  8900000,  0,       '2022-11-05'),
(8,  'ACC-20010008', 5, 'Jean-Pierre Kamga',        'CURRENT',   'Douala Main Branch', 'RESTRICTED', 'XAF', 340000,   280000,   60000,   '2021-06-18'),
(9,  'ACC-20010009', 6, 'Aminata Moussa',           'SAVINGS',   'Yaounde Head Office','ACTIVE',     'XAF', 1200000,  1200000,  0,       '2023-05-22'),
(10, 'ACC-20010010', 7, 'GreenPlantations SA',      'CORPORATE', 'Douala Port Branch', 'ACTIVE',     'XAF', 21000000, 21000000, 0,       '2020-12-01'),
(11, 'ACC-20010011', 8, 'Francois Nganou',          'CURRENT',   'Douala Main Branch', 'FROZEN',     'XAF', 500000,   0,        500000,  '2022-07-30'),
(12, 'ACC-20010012', 7, 'GreenPlantations SA',      'TREASURY',  'Douala Port Branch', 'ACTIVE',     'XAF', 5500000,  5500000,  0,       '2021-02-15');

-- ============================================================
-- 9. TRANSACTIONS (16 rows)
-- ============================================================
INSERT IGNORE INTO transactions (id, ref, type, status, branch, account, account_type,
  customer_name, description, category, direction, amount, fee, fee_pct, memo, module,
  operator_id, operator_name, approved_by, approved_at, posted_at, created_at) VALUES
(1,  'TXN-20250115-0001', 'SALARY_PAYMENT',    'POSTED',            'Douala Main Branch', 'ACC-20010001', 'SALARY',    'Paul Biyong',              'January 2025 Salary Payment',             'PAYROLL',        'credit', 485000,   0,     0,    'Monthly salary credit',       'TRANSACTIONS', 3, 'Alain Ndongo',       2, '2025-01-15 08:30:00', '2025-01-15 09:00:00', '2025-01-15 08:00:00'),
(2,  'TXN-20250115-0002', 'SALARY_PAYMENT',    'POSTED',            'Douala Main Branch', 'ACC-20010006', 'SALARY',    'Marie Ndongo',             'January 2025 Salary Payment',             'PAYROLL',        'credit', 380000,   0,     0,    'Monthly salary credit',       'TRANSACTIONS', 3, 'Alain Ndongo',       2, '2025-01-15 08:30:00', '2025-01-15 09:00:00', '2025-01-15 08:05:00'),
(3,  'TXN-20250116-0001', 'TRANSFER',          'POSTED',            'Douala Main Branch', 'ACC-20010003', 'CURRENT',   'Cameroon Logistics SARL',  'Wire Transfer to supplier',                'WIRE_TRANSFER',  'debit',  2500000,  12500, 0.50, 'Payment for Q4 shipping',      'TRANSFERS',    2, 'Clementine Fotso',   1, '2025-01-16 10:15:00', '2025-01-16 10:30:00', '2025-01-16 10:00:00'),
(4,  'TXN-20250116-0002', 'DEPOSIT',           'POSTED',            'Douala Port Branch', 'ACC-20010010', 'CORPORATE', 'GreenPlantations SA',      'Cash deposit from cocoa sales',           'CASH',           'credit', 5000000,  0,     0,    'Weekly cash deposit',          'TRANSACTIONS', 3, 'Alain Ndongo',       NULL, NULL, '2025-01-16 14:20:00', '2025-01-16 14:00:00'),
(5,  'TXN-20250117-0001', 'WITHDRAWAL',        'POSTED',            'Douala Main Branch', 'ACC-20010001', 'SALARY',    'Paul Biyong',              'Cash withdrawal at counter',              'CASH',           'debit',  100000,   500,   0.50, 'Counter withdrawal',          'TRANSACTIONS', 3, 'Alain Ndongo',       NULL, NULL, '2025-01-17 11:00:00', '2025-01-17 10:45:00'),
(6,  'TXN-20250117-0002', 'FOREX_PURCHASE',    'POSTED',            'Douala Main Branch', 'ACC-20010005', 'FOREX',     'Cameroon Logistics SARL',  'USD purchase for import',                  'FOREX',          'debit',  5000,     250,   0.50, 'USD for supplier payment',    'TRANSFERS',    2, 'Clementine Fotso',   1, '2025-01-17 15:00:00', '2025-01-17 15:15:00', '2025-01-17 14:30:00'),
(7,  'TXN-20250118-0001', 'TRANSFER',          'PENDING_APPROVAL', 'Douala Main Branch', 'ACC-20010004', 'TREASURY',  'Cameroon Logistics SARL',  'Inter-company transfer to Ghana',         'WIRE_TRANSFER',  'debit',  15000000, 75000, 0.50, 'Monthly inter-company',       'TRANSFERS',    2, 'Clementine Fotso',   NULL, NULL, NULL, '2025-01-18 09:30:00'),
(8,  'TXN-20250118-0002', 'LOAN_DISBURSEMENT', 'POSTED',            'Douala Port Branch', 'ACC-20010010', 'CORPORATE', 'GreenPlantations SA',      'Working capital loan disbursement',       'LOAN',           'credit', 10000000, 0,     0,    'Loan LN-2024-003',            'LOANS',        1, 'Emmanuel Nkoulou',   1, '2025-01-18 14:00:00', '2025-01-18 14:30:00', '2025-01-18 13:30:00'),
(9,  'TXN-20250119-0001', 'DEPOSIT',           'POSTED',            'Yaounde Head Office','ACC-20010007', 'CORPORATE', 'AfrikaTech Solutions',     'Cheque deposit from client',              'CHEQUE',         'credit', 3200000,  0,     0,    'Client payment cheque',       'TRANSACTIONS', 3, 'Alain Ndongo',       NULL, NULL, '2025-01-19 10:00:00', '2025-01-19 09:45:00'),
(10, 'TXN-20250119-0002', 'SALARY_WITHDRAWAL', 'POSTED',            'Douala Main Branch', 'ACC-20010001', 'SALARY',    'Paul Biyong',              'Full salary withdrawal - resignation',     'PAYROLL',        'debit',  485000,   2425,  0.50, 'Final salary settlement',     'TRANSACTIONS', 3, 'Alain Ndongo',       2, '2025-01-19 16:00:00', '2025-01-19 16:15:00', '2025-01-19 15:30:00'),
(11, 'TXN-20250120-0001', 'FEE_COLLECTION',    'POSTED',            'Douala Main Branch', 'ACC-20010003', 'CURRENT',   'Cameroon Logistics SARL',  'Monthly account maintenance fee',         'FEE',            'debit',  5000,     0,     0,    'January 2025 maintenance',    'TRANSACTIONS', 3, 'Alain Ndongo',       NULL, NULL, '2025-01-20 06:00:00', '2025-01-20 00:00:00'),
(12, 'TXN-20250120-0002', 'TRANSFER',          'POSTED',            'Yaounde Head Office','ACC-20010009', 'SAVINGS',   'Aminata Moussa',           'Transfer to fixed deposit',               'INTERNAL',       'debit',  500000,   0,     0,    'Savings to FD conversion',    'TRANSFERS',    2, 'Clementine Fotso',   NULL, NULL, '2025-01-20 11:30:00', '2025-01-20 11:00:00'),
(13, 'TXN-20250120-0003', 'TAX_PAYMENT',       'POSTED',            'Douala Main Branch', 'ACC-20010008', 'CURRENT',   'Jean-Pierre Kamga',        'Withholding tax payment',                  'TAX',            'debit',  45000,    0,     0,    'Monthly withholding tax',     'TRANSACTIONS', 2, 'Clementine Fotso',   1, '2025-01-20 14:00:00', '2025-01-20 14:30:00', '2025-01-20 13:00:00'),
(14, 'TXN-20250121-0001', 'LOAN_REPAYMENT',    'POSTED',            'Douala Port Branch', 'ACC-20010010', 'CORPORATE', 'GreenPlantations SA',      'Monthly loan repayment',                   'LOAN',           'debit',  916667,   0,     0,    'LN-2024-001 monthly',         'LOANS',        3, 'Alain Ndongo',       NULL, NULL, '2025-01-21 09:00:00', '2025-01-21 08:45:00'),
(15, 'TXN-20250121-0002', 'DEPOSIT',           'FAILED',            'Douala Main Branch', 'ACC-20010011', 'CURRENT',   'Francois Nganou',          'Attempted cash deposit',                   'CASH',           'credit', 200000,   0,     0,    'Rejected - account frozen',   'TRANSACTIONS', 3, 'Alain Ndongo',       NULL, NULL, NULL, '2025-01-21 14:30:00'),
(16, 'TXN-20250122-0001', 'TRANSFER',          'CANCELLED',         'Douala Main Branch', 'ACC-20010002', 'CURRENT',   'Cameroon Logistics SARL',  'Cancelled wire transfer',                 'WIRE_TRANSFER',  'debit',  7500000,  0,     0,    'Cancelled by initiator',      'TRANSFERS',    2, 'Clementine Fotso',   NULL, NULL, NULL, '2025-01-22 10:00:00');

-- ============================================================
-- 10. LOANS (3 rows)
-- ============================================================
INSERT IGNORE INTO loans (id, loan_number, customer_id, customer_name, branch,
  status, principal, outstanding, accrued_interest, interest_rate, term_months,
  repayment_freq, disbursed_at, maturity_date, next_due,
  debit_account_id, debit_account_number, source, product_type,
  repayment_mode, repayment_amount, repayment_pct, auto_deduct) VALUES
(1, 'LN-2024-001', 7, 'GreenPlantations SA',      'Douala Port Branch', 'ACTIVE',
  10000000, 8333333, 250000, 14.50, 12, 'Monthly',
  '2024-07-15', '2025-07-15', '2025-02-15',
  10, 'ACC-20010010', 'Branch Application', 'Working Capital', 'SCHEDULED', 916667, 0, 1),
(2, 'LN-2024-002', 2, 'Cameroon Logistics SARL',   'Douala Main Branch', 'ACTIVE',
  25000000, 20833333, 625000, 12.00, 24, 'Monthly',
  '2024-04-01', '2026-04-01', '2025-02-01',
  3, 'ACC-20010003', 'Direct Application', 'Asset Finance', 'SCHEDULED', 1250000, 0, 1),
(3, 'LN-2024-003', 4, 'AfrikaTech Solutions',      'Yaounde Head Office','ACTIVE',
  5000000, 4166667, 104167, 15.00, 12, 'Monthly',
  '2024-09-01', '2025-09-01', '2025-02-01',
  7, 'ACC-20010007', 'Referral', 'Tech Startup', 'SCHEDULED', 458333, 0, 1);

-- ============================================================
-- 11. LOAN APPLICATIONS (4 rows)
-- ============================================================
INSERT IGNORE INTO loan_applications (id, ref, customer_id, customer_name, amount, term,
  purpose, status, branch, applied_at, decided_by, decided_at, decision_reason) VALUES
(1, 'LA-2025-0001', 1, 'Paul Biyong',           2000000, 6,  'Personal vehicle purchase',  'PENDING',       'Douala Main Branch', '2025-01-20 10:00:00', NULL, NULL, NULL),
(2, 'LA-2025-0002', 3, 'Marie Ndongo',          5000000, 12, 'Home improvement loan',     'UNDER_REVIEW',  'Douala Port Branch', '2025-01-18 14:00:00', NULL, NULL, NULL),
(3, 'LA-2025-0003', 6, 'Aminata Moussa',        1500000, 24, 'Small business startup',    'APPROVED',      'Yaounde Head Office','2025-01-10 09:00:00', 1, '2025-01-15 16:00:00', 'Good credit history'),
(4, 'LA-2025-0004', 8, 'Francois Nganou',       3000000, 12, 'Debt consolidation',        'REJECTED',      'Douala Main Branch', '2025-01-05 11:00:00', 1, '2025-01-08 10:00:00', 'Account frozen');

-- ============================================================
-- 12. LOAN SCHEDULE (9 rows)
-- ============================================================
INSERT IGNORE INTO loan_schedule (loan_id, installment, due, principal, interest, paid, status, paid_at) VALUES
(1, 1, '2024-08-15', 783333,  120833, 916667, 'PAID', '2024-08-15 10:00:00'),
(1, 2, '2024-09-15', 792917,  123750, 916667, 'PAID', '2024-09-15 09:30:00'),
(1, 3, '2024-10-15', 802500,  114167, 916667, 'PAID', '2024-10-15 11:00:00'),
(2, 1, '2024-05-01', 1000000, 250000, 1250000, 'PAID', '2024-05-01 08:00:00'),
(2, 2, '2024-06-01', 1010000, 240000, 1250000, 'PAID', '2024-06-01 09:00:00'),
(2, 3, '2024-07-01', 1020000, 230000, 1250000, 'PAID', '2024-07-01 08:30:00'),
(3, 1, '2024-10-01', 395833,  62500,  458333, 'PAID', '2024-10-01 10:00:00'),
(3, 2, '2024-11-01', 400833,  57500,  458333, 'PAID', '2024-11-01 09:00:00'),
(3, 3, '2024-12-01', 405833,  52500,  458333, 'PAID', '2024-12-01 10:30:00');

-- ============================================================
-- 13. APPROVALS (5 rows)
-- ============================================================
INSERT IGNORE INTO approvals (id, entity_type, entity_id, scope_code, status,
  submitted_by, branch, value, submitted_at, decided_by, decided_at, reason) VALUES
(1, 'TRANSACTION',       7, 'TRANSFER_HIGH_VALUE', 'PENDING',  'Clementine Fotso', 'Douala Main Branch', '15000000', '2025-01-18 09:30:00', NULL, NULL, NULL),
(2, 'LOAN_APPLICATION',   1, 'LOAN_APPROVAL',      'PENDING',  'Alain Ndongo',     'Douala Main Branch', '2000000',  '2025-01-20 10:00:00', NULL, NULL, NULL),
(3, 'EXPENSE',           3, 'EXPENSE_APPROVAL',   'APPROVED', 'Alain Ndongo',     'Douala Port Branch', '750000',   '2025-01-15 14:00:00', 2, '2025-01-16 09:00:00', 'Approved - within budget'),
(4, 'LOAN_APPLICATION',   4, 'LOAN_APPROVAL',      'APPROVED', 'Alain Ndongo',     'Yaounde Head Office','1500000',  '2025-01-10 09:00:00', 1, '2025-01-15 16:00:00', 'Good credit history'),
(5, 'SETTING_CHANGE',    5, 'SETTING_FEE_UPDATE', 'REJECTED', 'Clementine Fotso', 'Douala Main Branch', '750',      '2025-01-12 11:00:00', 1, '2025-01-13 10:00:00', 'Requires board approval');

-- ============================================================
-- 14. AUDIT LOGS (10 rows)
-- ============================================================
INSERT IGNORE INTO audit_logs (id, uuid, actor, actor_branch, action, entity, entity_id,
  result, ip, details, timestamp) VALUES
(1,  'AUD-20250115-0001', 'Emmanuel Nkoulou',   'Douala Main Branch', 'LOGIN',                 'STAFF',             '1',    'SUCCESS', '192.168.1.100', 'Successful login',               '2025-01-15 07:45:00'),
(2,  'AUD-20250115-0002', 'Alain Ndongo',       'Douala Main Branch', 'TRANSACTION_CREATE',     'TRANSACTION',       '1',    'SUCCESS', '192.168.1.105', 'Created salary payment',         '2025-01-15 08:00:00'),
(3,  'AUD-20250115-0003', 'Clementine Fotso',   'Douala Main Branch', 'TRANSACTION_APPROVE',    'TRANSACTION',       '1',    'SUCCESS', '192.168.1.102', 'Approved salary batch',           '2025-01-15 08:30:00'),
(4,  'AUD-20250116-0001', 'Clementine Fotso',   'Douala Main Branch', 'TRANSFER_CREATE',        'TRANSACTION',       '3',    'SUCCESS', '192.168.1.102', 'Created wire transfer',           '2025-01-16 10:00:00'),
(5,  'AUD-20250117-0001', 'Isabelle Mbarga',    'Douala Main Branch', 'AUDIT_EXPORT',           'REPORT',            'RPT-001','SUCCESS', '192.168.1.200', 'Exported weekly report',          '2025-01-17 09:00:00'),
(6,  'AUD-20250118-0001', 'Emmanuel Nkoulou',   'Douala Main Branch', 'LOAN_DISBURSE',          'LOAN',              '3',    'SUCCESS', '192.168.1.100', 'Disbursed loan LN-2024-003',     '2025-01-18 14:30:00'),
(7,  'AUD-20250119-0001', 'Alain Ndongo',       'Douala Main Branch', 'WITHDRAWAL_CREATE',      'TRANSACTION',       '10',   'SUCCESS', '192.168.1.105', 'Created salary withdrawal',       '2025-01-19 15:30:00'),
(8,  'AUD-20250121-0001', 'Alain Ndongo',       'Douala Main Branch', 'TRANSACTION_REJECT',     'TRANSACTION',       '15',   'SUCCESS', '192.168.1.105', 'Rejected deposit - frozen',      '2025-01-21 14:30:00'),
(9,  'AUD-20250122-0001', 'Jean-Pierre Tchinda','Douala Main Branch', 'SUSPICIOUS_ACTIVITY',    'CUSTOMER',          '8',    'SUCCESS', '192.168.1.201', 'Flagged suspicious activity',     '2025-01-22 11:00:00'),
(10, 'AUD-20250122-0002', 'Isabelle Mbarga',    'Yaounde Head Office','LOAN_APPLICATION_REVIEW','LOAN_APPLICATION',  '2',    'SUCCESS', '192.168.1.200', 'Reviewed loan application',      '2025-01-22 15:00:00');

-- ============================================================
-- 15. NOTIFICATIONS (6 rows)
-- ============================================================
INSERT IGNORE INTO notifications (id, type, title, body, status, channel, target_staff_id, timestamp) VALUES
(1, 'APPROVAL', 'Transfer Pending Approval',     'A high-value transfer of XAF 15,000,000 from Cameroon Logistics SARL requires your approval.',          'PENDING', 'IN_APP', 1, '2025-01-18 09:30:00'),
(2, 'LOAN',     'New Loan Application',          'Paul Biyong has submitted a loan application for XAF 2,000,000.',                                  'PENDING', 'IN_APP', 2, '2025-01-20 10:00:00'),
(3, 'ALERT',    'Account Frozen - Compliance',   'Account ACC-20010011 (Francois Nganou) has been frozen pending compliance review.',                   'PENDING', 'IN_APP', 1, '2025-01-21 14:30:00'),
(4, 'LOAN',     'Loan Disbursement Complete',    'Loan LN-2024-003 has been disbursed to AfrikaTech Solutions.',                                      'READ',    'IN_APP', 2, '2025-01-18 14:30:00'),
(5, 'SYSTEM',   'Scheduled Maintenance Notice',  'System maintenance scheduled for January 25, 2025 from 02:00 to 04:00 CAT.',                        'READ',    'IN_APP', 1, '2025-01-20 06:00:00'),
(6, 'SECURITY', 'Suspicious Login Detected',     'Multiple failed login attempts detected for user "admin" from IP 10.0.0.55.',                       'PENDING', 'IN_APP', 5, '2025-01-22 04:30:00');

-- ============================================================
-- 16. SETTINGS (36 rows)
-- ============================================================
INSERT IGNORE INTO settings (`key`, name, category, value, description, effective_from, requires_approval) VALUES
('FEE_TRANSFER_INTERNAL',       'Internal Transfer Fee',               'FEES',       '500',    'Fee for internal account-to-account transfers',   '2024-01-01', 0),
('FEE_TRANSFER_EXTERNAL',       'External Transfer Fee',               'FEES',       '2500',   'Fee for external bank transfers',                  '2024-01-01', 1),
('FEE_TRANSFER_HIGH_VALUE',     'High-Value Transfer Threshold',       'FEES',       '5000000','Threshold above which transfers require approval', '2024-01-01', 1),
('FEE_TRANSFER_HIGH_VALUE_RATE','High-Value Transfer Fee Rate (%)',    'FEES',       '0.50',   'Percentage fee for transfers above threshold',      '2024-01-01', 1),
('FEE_WITHDRAWAL_COUNTER',      'Counter Withdrawal Fee',              'FEES',       '500',    'Fee for over-the-counter cash withdrawals',         '2024-01-01', 0),
('FEE_WITHDRAWAL_ATM',          'ATM Withdrawal Fee',                  'FEES',       '200',    'Fee for ATM cash withdrawals',                     '2024-01-01', 0),
('FEE_DEPOSIT_CASH',            'Cash Deposit Fee',                    'FEES',       '0',      'Fee for cash deposits',                           '2024-01-01', 0),
('FEE_DEPOSIT_CHEQUE',          'Cheque Deposit Fee',                  'FEES',       '1000',   'Fee for cheque deposits',                         '2024-01-01', 0),
('FEE_FOREX_PURCHASE',          'Forex Purchase Fee',                  'FEES',       '2500',   'Fee for foreign currency purchase',               '2024-01-01', 0),
('FEE_FOREX_SPREAD',            'Forex Spread (%)',                    'FEES',       '1.50',   'Applied exchange rate spread for forex',           '2024-01-01', 1),
('FEE_ACCOUNT_MAINTENANCE',     'Monthly Account Maintenance Fee',     'FEES',       '5000',   'Monthly fee for account maintenance',              '2024-01-01', 0),
('FEE_STATEMENT_REQUEST',       'Statement Request Fee',               'FEES',       '1000',   'Fee for generating printed statements',            '2024-01-01', 0),
('FEE_LOAN_PROCESSING',         'Loan Processing Fee (%)',            'FEES',       '1.00',   'Percentage fee for loan processing',               '2024-01-01', 1),
('FEE_LOAN_LATE_PAYMENT',       'Loan Late Payment Penalty (%)',      'FEES',       '2.00',   'Penalty percentage for late loan repayments',      '2024-01-01', 0),
('FEE_CARD_ISSUANCE',           'Debit Card Issuance Fee',             'FEES',       '5000',   'Fee for new debit card issuance',                  '2024-01-01', 0),
('FEE_CARD_REPLACEMENT',        'Debit Card Replacement Fee',          'FEES',       '10000',  'Fee for replacing a lost/damaged debit card',     '2024-01-01', 0),
('FEE_SMS_ALERT',               'SMS Alert Fee (Monthly)',             'FEES',       '500',    'Monthly SMS notification fee',                    '2024-01-01', 0),
('TAX_IR_RATE',                 'Income Tax Rate (IR) (%)',           'TAX',        '11.25',  'Personal income tax withholding rate',            '2024-01-01', 1),
('TAX_IR_THRESHOLD',            'Income Tax Exemption Threshold',      'TAX',        '62000',  'Monthly salary below which no IR is deducted',    '2024-01-01', 1),
('TAX_CNPS_EMPLOYEE_RATE',      'CNPS Employee Rate (%)',             'TAX',        '2.80',   'Social security contribution - employee portion',   '2024-01-01', 1),
('TAX_CNPS_EMPLOYER_RATE',      'CNPS Employer Rate (%)',             'TAX',        '4.20',   'Social security contribution - employer portion',   '2024-01-01', 1),
('TAX_CNPS_CEILING',            'CNPS Contribution Ceiling',          'TAX',        '750000', 'Maximum monthly salary for CNPS calculation',     '2024-01-01', 1),
('TAX_REGISTRATION',            'Registration Tax Rate (%)',          'TAX',        '1.00',   'Tax on financial transactions registration',       '2024-01-01', 1),
('TAX_STAMP_DUTY',              'Stamp Duty Rate (%)',                'TAX',        '0.20',   'Stamp duty on transactions',                       '2024-01-01', 0),
('TAX_VAT_RATE',                'Value Added Tax Rate (%)',           'TAX',        '19.25',  'Standard VAT rate for services',                  '2024-01-01', 1),
('TAX_WITHHOLDING_RATE',        'Withholding Tax Rate (%)',           'TAX',        '5.00',   'Standard withholding tax rate',                    '2024-01-01', 1),
('TAX_DIVIDEND_RATE',           'Dividend Tax Rate (%)',              'TAX',        '15.00',  'Tax rate on dividend payments',                    '2024-01-01', 1),
('TAX_INTEREST_RATE',           'Interest Income Tax Rate (%)',       'TAX',        '15.00',  'Tax rate on interest income',                     '2024-01-01', 1),
('OPR_MAX_LOGIN_ATTEMPTS',      'Max Login Attempts Before Lock',     'OPERATIONS', '5',      'Number of failed login attempts before lockout',   '2024-01-01', 1),
('OPR_LOCKOUT_DURATION',        'Lockout Duration (Minutes)',          'OPERATIONS', '30',     'Duration of account lockout after max attempts',   '2024-01-01', 1),
('OPR_SESSION_TIMEOUT',         'Session Timeout (Minutes)',           'OPERATIONS', '480',    'Duration before inactive sessions expire',         '2024-01-01', 1),
('OPR_SESSION_TIMEOUT_WARN',    'Session Warning (Minutes)',          'OPERATIONS', '15',     'Warning before session expiry',                    '2024-01-01', 0),
('OPR_MFA_REQUIRED',            'MFA Required for All Staff',         'OPERATIONS', 'true',   'Whether multi-factor authentication is mandatory',  '2024-01-01', 1),
('OPR_PASSWORD_MIN_LENGTH',     'Minimum Password Length',             'OPERATIONS', '12',     'Minimum characters required for passwords',         '2024-01-01', 0),
('OPR_DAILY_TRANSFER_LIMIT',    'Daily Transfer Limit (XAF)',          'OPERATIONS', '25000000','Maximum total outbound transfers per day',        '2024-01-01', 1),
('OPR_CURRENCY',                'Default Currency',                   'OPERATIONS', 'XAF',    'Default currency for operations',                  '2024-01-01', 0);

-- ============================================================
-- 17. CHART OF ACCOUNTS (20 rows)
-- ============================================================
INSERT IGNORE INTO chart_of_accounts (code, name, type, category, description, is_active) VALUES
('1000', 'Cash and Cash Equivalents',   'ASSET',     'LIQUIDITY',   'Physical cash, central bank reserves, and correspondent balances', 1),
('1100', 'Due from Banks',              'ASSET',     'LIQUIDITY',   'Balances held at correspondent banks',                               1),
('1200', 'Loans and Advances',          'ASSET',     'CREDIT',      'Customer loans and advances',                                        1),
('1210', 'Loan Loss Provision',         'ASSET',     'CREDIT',      'Provision for expected credit losses',                                1),
('1300', 'Investment Securities',       'ASSET',     'INVESTMENT',  'Government bonds and treasury bills',                                 1),
('1400', 'Fixed Assets',                'ASSET',     'PREMISES',    'Property, equipment, and IT infrastructure',                          1),
('1500', 'Other Assets',                'ASSET',     'OTHER',       'Prepayments, receivables, and other assets',                          1),
('2000', 'Customer Deposits',           'LIABILITY', 'DEPOSITS',    'Current, savings, and fixed deposit accounts',                        1),
('2100', 'Due to Banks',                'LIABILITY', 'LIQUIDITY',   'Amounts owed to correspondent banks',                                 1),
('2200', 'Borrowings',                  'LIABILITY', 'FUNDING',     'Interbank loans and institutional borrowings',                         1),
('2300', 'Tax Payable',                 'LIABILITY', 'TAX',         'Accrued tax obligations',                                             1),
('2400', 'Other Liabilities',           'LIABILITY', 'OTHER',       'Accrued expenses and other payables',                                 1),
('3000', 'Share Capital',               'EQUITY',    'CAPITAL',     'Paid-in share capital',                                               1),
('3100', 'Retained Earnings',           'EQUITY',    'RESERVES',    'Accumulated retained earnings',                                        1),
('4000', 'Interest Income',             'INCOME',    'INTEREST',    'Interest earned on loans and investments',                             1),
('4100', 'Fee and Commission Income',   'INCOME',    'FEE',         'Banking fees, commissions, and service charges',                      1),
('4200', 'Other Operating Income',      'INCOME',    'OTHER',       'Non-interest operating income',                                       1),
('5000', 'Interest Expense',            'EXPENSE',   'INTEREST',    'Interest paid on deposits and borrowings',                            1),
('5100', 'Operating Expenses',          'EXPENSE',   'ADMIN',       'Salaries, rent, utilities, and general admin costs',                   1),
('5200', 'Impairment Losses',           'EXPENSE',   'CREDIT',      'Expected credit loss provisions',                                     1);

-- ============================================================
-- 18. EXPENSES (7 rows)
-- ============================================================
INSERT IGNORE INTO expenses (id, date, category, gl_code, gl_account_name, amount,
  vendor, description, branch, status, approved_by, approved_at, operator_id, operator_name) VALUES
(1, '2025-01-05', 'UTILITIES',        '5100', 'Operating Expenses', 350000,  'ENEO Cameroon',            'January electricity bill',          'Douala Main Branch', 'APPROVED', 2, '2025-01-06 09:00:00', 3, 'Alain Ndongo'),
(2, '2025-01-08', 'OFFICE_SUPPLIES',  '5100', 'Operating Expenses', 125000,  'Douala Office Supply Co.', 'Printer cartridges and paper',     'Douala Main Branch', 'APPROVED', 2, '2025-01-09 10:00:00', 3, 'Alain Ndongo'),
(3, '2025-01-10', 'IT_SERVICES',      '5100', 'Operating Expenses', 750000,  'TechServe SARL',           'Monthly managed IT services',      'Douala Main Branch', 'PENDING',  NULL, NULL, 3, 'Alain Ndongo'),
(4, '2025-01-12', 'MAINTENANCE',      '5100', 'Operating Expenses', 280000,  'SecureGuard Ltd',          'Security system maintenance',      'Douala Port Branch', 'APPROVED', 2, '2025-01-13 11:00:00', 3, 'Alain Ndongo'),
(5, '2025-01-15', 'TRANSPORT',        '5100', 'Operating Expenses', 150000,  'Atlas Transport',          'Branch vehicle fuel',              'Yaounde Head Office','APPROVED', 2, '2025-01-16 14:00:00', 3, 'Alain Ndongo'),
(6, '2025-01-18', 'TRAINING',         '5100', 'Operating Expenses', 400000,  'CamBank Training Inst.',   'Staff compliance training',        'Douala Main Branch', 'PENDING',  NULL, NULL, 3, 'Alain Ndongo'),
(7, '2025-01-20', 'PROFESSIONAL',     '5100', 'Operating Expenses', 900000,  'Mbiama & Associates',      'External audit consultation',     'Douala Main Branch', 'REJECTED', 1, '2025-01-21 09:00:00', 2, 'Clementine Fotso');

-- ============================================================
-- 19. AUDIT FINDINGS (7 rows)
-- ============================================================
INSERT IGNORE INTO audit_findings (id, severity, category, description, recommendation,
  branch, status, assignee, created_date, created_by) VALUES
(1, 'HIGH',     'KYC',          'Incomplete KYC documentation for 12 customers onboarded in Q3 2024',
  'Initiate customer outreach to complete KYC within 30 days.',        'Douala Main Branch', 'OPEN',         'Clementine Fotso',   '2025-01-10', 'Isabelle Mbarga'),
(2, 'MEDIUM',   'OPERATIONS',   'Teller cash variance exceeding XAF 50,000 on 3 occasions in December 2024',
  'Implement mandatory end-of-day cash reconciliation with dual control.','Douala Port Branch', 'IN_PROGRESS',   'Alain Ndongo',       '2025-01-08', 'Isabelle Mbarga'),
(3, 'CRITICAL', 'AML',          'Delayed suspicious activity reporting - 2 reports filed beyond 72-hour deadline',
  'Automate SAR generation triggers. Escalate delays to COO immediately.','Douala Main Branch', 'OPEN',         'Jean-Pierre Tchinda','2025-01-12', 'Isabelle Mbarga'),
(4, 'LOW',      'IT_SECURITY',  'Server room access log showing unauthorized after-hours access on January 5',
  'Review CCTV footage, revoke unneeded access badges.',               'Yaounde Head Office','RESOLVED',     'IT Department',      '2025-01-06', 'Isabelle Mbarga'),
(5, 'HIGH',     'CREDIT',       'Loan disbursement approved without complete collateral documentation',
  'Obtain and verify all collateral documents.',                      'Yaounde Head Office','OPEN',         'Emmanuel Nkoulou',   '2025-01-15', 'Isabelle Mbarga'),
(6, 'MEDIUM',   'COMPLIANCE',   'Expired staff training certificates for 4 operations team members',
  'Schedule mandatory refresher training within 15 days.',            'Douala Main Branch', 'IN_PROGRESS',   'Jean-Pierre Tchinda','2025-01-14', 'Isabelle Mbarga'),
(7, 'LOW',      'OPERATIONS',   'Branch signage not updated with new regulatory disclosure requirements',
  'Coordinate with marketing department to update signage within 30 days.','Douala Port Branch','CLOSED',       'Alain Ndongo',       '2025-01-03', 'Isabelle Mbarga');

-- ============================================================
-- 20. POLICIES (4 rows)
-- ============================================================
INSERT IGNORE INTO policies (code, version, name, effective_from, effective_to, description) VALUES
('KYC-POL-001',    1, 'Customer Due Diligence Policy',  '2024-01-01', '2025-12-31',
 'Policy governing customer identification, verification, and ongoing due diligence.'),
('AML-POL-001',    2, 'Anti-Money Laundering Policy',   '2024-01-01', '2025-12-31',
 'Comprehensive AML policy including transaction monitoring and suspicious activity reporting.'),
('LOAN-POL-001',   1, 'Credit Risk Management Policy',  '2024-01-01', '2025-12-31',
 'Framework for credit assessment, loan approval processes, and portfolio risk monitoring.'),
('IT-SEC-POL-001', 1, 'Information Security Policy',    '2024-01-01', '2025-12-31',
 'Policy covering data protection, access controls, incident response, and cybersecurity.');

-- ============================================================
-- 21. OPERATING ACCOUNT (1 row)
-- ============================================================
INSERT IGNORE INTO operating_account (id, account_number, account_name, balance, currency) VALUES
(1, 'OPS-000001', 'Atlas Bank Operating Account', 145023000, 'XAF');

-- ============================================================
-- 22. BALANCE TRENDS (5 rows)
-- ============================================================
INSERT IGNORE INTO balance_trends (snapshot_date, product_type, total_balance) VALUES
('2025-01-18', 'SALARY',    1955000),
('2025-01-18', 'CURRENT',   16090000),
('2025-01-18', 'SAVINGS',   3700000),
('2025-01-18', 'CORPORATE', 29900000),
('2025-01-18', 'TREASURY',  37500000);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- VERIFY: Show row counts for all seeded tables
-- ============================================================
SELECT 'bank_branding' AS tbl, COUNT(*) AS rows_count FROM bank_branding
UNION ALL SELECT 'branches', COUNT(*) FROM branches
UNION ALL SELECT 'staff', COUNT(*) FROM staff
UNION ALL SELECT 'staff_branches', COUNT(*) FROM staff_branches
UNION ALL SELECT 'staff_modules', COUNT(*) FROM staff_modules
UNION ALL SELECT 'customers', COUNT(*) FROM customers
UNION ALL SELECT 'accounts', COUNT(*) FROM accounts
UNION ALL SELECT 'transactions', COUNT(*) FROM transactions
UNION ALL SELECT 'loans', COUNT(*) FROM loans
UNION ALL SELECT 'loan_applications', COUNT(*) FROM loan_applications
UNION ALL SELECT 'loan_schedule', COUNT(*) FROM loan_schedule
UNION ALL SELECT 'approvals', COUNT(*) FROM approvals
UNION ALL SELECT 'audit_logs', COUNT(*) FROM audit_logs
UNION ALL SELECT 'notifications', COUNT(*) FROM notifications
UNION ALL SELECT 'settings', COUNT(*) FROM settings
UNION ALL SELECT 'chart_of_accounts', COUNT(*) FROM chart_of_accounts
UNION ALL SELECT 'expenses', COUNT(*) FROM expenses
UNION ALL SELECT 'audit_findings', COUNT(*) FROM audit_findings
UNION ALL SELECT 'policies', COUNT(*) FROM policies
UNION ALL SELECT 'operating_account', COUNT(*) FROM operating_account
UNION ALL SELECT 'balance_trends', COUNT(*) FROM balance_trends;
