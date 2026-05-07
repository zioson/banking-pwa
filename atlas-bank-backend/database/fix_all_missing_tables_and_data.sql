-- ============================================================
-- Atlas Bank — Create missing tables & insert seed data
-- Run in phpMyAdmin or MySQL CLI
-- Safe to run multiple times (uses IF NOT EXISTS / INSERT IGNORE)
-- ============================================================

USE atlas_bank;

-- ============================================================
-- STEP 1: Create any missing tables
-- ============================================================

-- Bank Branding
CREATE TABLE IF NOT EXISTS bank_branding (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bank_name VARCHAR(200) NOT NULL DEFAULT 'ATLAS BANK',
  bank_name_short VARCHAR(100) DEFAULT 'Atlas Bank',
  tagline VARCHAR(200) DEFAULT 'Enterprise Operations Console',
  logo LONGTEXT,
  primary_color VARCHAR(20) DEFAULT '#58b7ff',
  accent_color VARCHAR(20) DEFAULT '#67e8b5',
  head_office_address TEXT,
  phone VARCHAR(50),
  phone_alt VARCHAR(50),
  email VARCHAR(100),
  website VARCHAR(100),
  swift_code VARCHAR(20),
  cbn_license_number VARCHAR(50),
  registration_number VARCHAR(50),
  tax_identification_number VARCHAR(50),
  slogan VARCHAR(200),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chart of Accounts
CREATE TABLE IF NOT EXISTS chart_of_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(200) NOT NULL,
  type ENUM('ASSET','LIABILITY','EQUITY','INCOME','EXPENSE') NOT NULL,
  category VARCHAR(100),
  description TEXT,
  is_active TINYINT(1) DEFAULT 1,
  INDEX idx_code (code),
  INDEX idx_type (type),
  INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Policies
CREATE TABLE IF NOT EXISTS policies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NOT NULL UNIQUE,
  version INT DEFAULT 1,
  name VARCHAR(200) NOT NULL,
  effective_from DATE,
  effective_to DATE,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_code (code),
  INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Profit Ledger
CREATE TABLE IF NOT EXISTS profit_ledger (
  id INT AUTO_INCREMENT PRIMARY KEY,
  gl_code VARCHAR(10) NOT NULL,
  gl_account_name VARCHAR(200),
  gl_type VARCHAR(20),
  gl_category VARCHAR(100),
  total_debit DECIMAL(20,2) DEFAULT 0,
  total_credit DECIMAL(20,2) DEFAULT 0,
  net_amount DECIMAL(20,2) DEFAULT 0,
  period_start DATE,
  period_end DATE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_gl (gl_code),
  INDEX idx_period (period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Operating Account
CREATE TABLE IF NOT EXISTS operating_account (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_number VARCHAR(30) NOT NULL UNIQUE,
  account_name VARCHAR(200),
  balance DECIMAL(20,2) DEFAULT 0,
  currency VARCHAR(5) DEFAULT 'XAF',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operating_account_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(50),
  operating_account_id INT NOT NULL,
  date DATE NOT NULL,
  type ENUM('CREDIT','DEBIT') NOT NULL,
  description TEXT,
  amount DECIMAL(20,2) NOT NULL,
  balance_after DECIMAL(20,2) NOT NULL,
  operator VARCHAR(200),
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_date (date),
  INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Balance Trends
CREATE TABLE IF NOT EXISTS balance_trends (
  id INT AUTO_INCREMENT PRIMARY KEY,
  snapshot_date DATE NOT NULL,
  product_type VARCHAR(50) NOT NULL,
  total_balance DECIMAL(20,2) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_date_type (snapshot_date, product_type),
  INDEX idx_date (snapshot_date),
  INDEX idx_product (product_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit Findings
CREATE TABLE IF NOT EXISTS audit_findings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  severity ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL,
  category VARCHAR(100),
  description TEXT,
  recommendation TEXT,
  branch VARCHAR(200),
  status ENUM('OPEN','IN_PROGRESS','RESOLVED','CLOSED') DEFAULT 'OPEN',
  assignee VARCHAR(200),
  created_date DATE,
  created_by VARCHAR(200),
  updated_at TIMESTAMP NULL DEFAULT NULL,
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_severity (severity),
  INDEX idx_status (status),
  INDEX idx_branch (branch),
  INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- STEP 2: Insert seed data (INSERT IGNORE = skip if exists)
-- ============================================================

-- Bank Branding
INSERT IGNORE INTO bank_branding (id, bank_name, bank_name_short, tagline, primary_color, accent_color,
  head_office_address, phone, phone_alt, email, website, swift_code, cbn_license_number,
  registration_number, tax_identification_number, slogan)
VALUES (1, 'ATLAS BANK', 'Atlas Bank', 'Enterprise Operations Console', '#58b7ff', '#67e8b5',
  '45 Avenue de la Liberte, BP 12345, Douala, Littoral, Cameroon',
  '+237 233 42 15 00', '+237 699 00 00 01', 'info@atlasbank.cm', 'www.atlasbank.cm',
  'ATLSCMCD', 'CBN-LIC-2024-0847', 'RC-DC-2024-B0012', 'TIN-CM-91000-ATLAS',
  'Building Trust, Securing Futures');

-- Chart of Accounts (20 entries)
INSERT IGNORE INTO chart_of_accounts (code, name, type, category, description, is_active) VALUES
('1000', 'Cash and Cash Equivalents', 'ASSET', 'LIQUIDITY', 'Physical cash, central bank reserves, and correspondent balances', 1),
('1100', 'Due from Banks', 'ASSET', 'LIQUIDITY', 'Balances held at correspondent banks', 1),
('1200', 'Loans and Advances', 'ASSET', 'CREDIT', 'Customer loans and advances', 1),
('1210', 'Loan Loss Provision', 'ASSET', 'CREDIT', 'Provision for expected credit losses', 1),
('1300', 'Investment Securities', 'ASSET', 'INVESTMENT', 'Government bonds and treasury bills', 1),
('1400', 'Fixed Assets', 'ASSET', 'PREMISES', 'Property, equipment, and IT infrastructure', 1),
('1500', 'Other Assets', 'ASSET', 'OTHER', 'Prepayments, receivables, and other assets', 1),
('2000', 'Customer Deposits', 'LIABILITY', 'DEPOSITS', 'Current, savings, and fixed deposit accounts', 1),
('2100', 'Due to Banks', 'LIABILITY', 'LIQUIDITY', 'Amounts owed to correspondent banks', 1),
('2200', 'Borrowings', 'LIABILITY', 'FUNDING', 'Interbank loans and institutional borrowings', 1),
('2300', 'Tax Payable', 'LIABILITY', 'TAX', 'Accrued tax obligations', 1),
('2400', 'Other Liabilities', 'LIABILITY', 'OTHER', 'Accrued expenses and other payables', 1),
('3000', 'Share Capital', 'EQUITY', 'CAPITAL', 'Paid-in share capital', 1),
('3100', 'Retained Earnings', 'EQUITY', 'RESERVES', 'Accumulated retained earnings', 1),
('4000', 'Interest Income', 'INCOME', 'INTEREST', 'Interest earned on loans and investments', 1),
('4100', 'Fee and Commission Income', 'INCOME', 'FEE', 'Banking fees, commissions, and service charges', 1),
('4200', 'Other Operating Income', 'INCOME', 'OTHER', 'Non-interest operating income', 1),
('5000', 'Interest Expense', 'EXPENSE', 'INTEREST', 'Interest paid on deposits and borrowings', 1),
('5100', 'Operating Expenses', 'EXPENSE', 'ADMIN', 'Salaries, rent, utilities, and general admin costs', 1),
('5200', 'Impairment Losses', 'EXPENSE', 'CREDIT', 'Expected credit loss provisions', 1);

-- Policies (4 entries)
INSERT IGNORE INTO policies (code, version, name, effective_from, effective_to, description) VALUES
('KYC-POL-001', 1, 'Customer Due Diligence Policy', '2024-01-01', '2025-12-31',
 'Policy governing customer identification, verification, and ongoing due diligence procedures in compliance with CEMAC AML/CFT regulations.'),
('AML-POL-001', 2, 'Anti-Money Laundering Policy', '2024-01-01', '2025-12-31',
 'Comprehensive AML policy including transaction monitoring, suspicious activity reporting, and compliance with BEAC directives.'),
('LOAN-POL-001', 1, 'Credit Risk Management Policy', '2024-01-01', '2025-12-31',
 'Framework for credit assessment, loan approval processes, collateral requirements, and portfolio risk monitoring.'),
('IT-SEC-POL-001', 1, 'Information Security Policy', '2024-01-01', '2025-12-31',
 'Policy covering data protection, access controls, incident response, and cybersecurity measures for banking operations.');

-- Audit Findings (7 entries)
INSERT IGNORE INTO audit_findings (id, severity, category, description, recommendation, branch, status, assignee, created_date, created_by) VALUES
(1, 'HIGH', 'KYC', 'Incomplete KYC documentation for 12 customers onboarded in Q3 2024',
 'Initiate customer outreach to complete KYC within 30 days. Escalate to compliance if not resolved.',
 'Douala Main Branch', 'OPEN', 'Clémentine Fotso', '2025-01-10', 'Isabelle Mbarga'),
(2, 'MEDIUM', 'OPERATIONS', 'Teller cash variance exceeding XAF 50,000 on 3 occasions in December 2024',
 'Implement mandatory end-of-day cash reconciliation with dual control. Retrain tellers on cash handling procedures.',
 'Douala Port Branch', 'IN_PROGRESS', 'Alain Ndongo', '2025-01-08', 'Isabelle Mbarga'),
(3, 'CRITICAL', 'AML', 'Delayed suspicious activity reporting (SAR) - 2 reports filed beyond 72-hour regulatory deadline',
 'Automate SAR generation triggers in the transaction monitoring system. Escalate delays to COO immediately.',
 'Douala Main Branch', 'OPEN', 'Jean-Pierre Tchinda', '2025-01-12', 'Isabelle Mbarga'),
(4, 'LOW', 'IT_SECURITY', 'Server room access log showing unauthorized after-hours access on January 5',
 'Review CCTV footage, revoke unneeded access badges, and implement biometric access controls.',
 'Yaoundé Head Office', 'RESOLVED', 'IT Department', '2025-01-06', 'Isabelle Mbarga'),
(5, 'HIGH', 'CREDIT', 'Loan disbursement approved without complete collateral documentation for LN-2024-003',
 'Obtain and verify all collateral documents. Add checklist enforcement to loan disbursement workflow.',
 'Yaoundé Head Office', 'OPEN', 'Emmanuel Nkoulou', '2025-01-15', 'Isabelle Mbarga'),
(6, 'MEDIUM', 'COMPLIANCE', 'Expired staff training certificates for 4 operations team members',
 'Schedule mandatory refresher training within 15 days. Block system access until completed.',
 'Douala Main Branch', 'IN_PROGRESS', 'Jean-Pierre Tchinda', '2025-01-14', 'Isabelle Mbarga'),
(7, 'LOW', 'OPERATIONS', 'Branch signage not updated with new regulatory disclosure requirements',
 'Coordinate with marketing department to update all branch signage within 30 days.',
 'Douala Port Branch', 'CLOSED', 'Alain Ndongo', '2025-01-03', 'Isabelle Mbarga');

-- Operating Account
INSERT IGNORE INTO operating_account (id, account_number, account_name, balance, currency) VALUES
(1, 'OPS-000001', 'Atlas Bank Operating Account', 145000000, 'XAF');

-- Balance Trends
INSERT IGNORE INTO balance_trends (snapshot_date, product_type, total_balance) VALUES
('2025-01-18', 'SALARY', 1955000),
('2025-01-18', 'CURRENT', 16090000),
('2025-01-18', 'SAVINGS', 3700000),
('2025-01-18', 'CORPORATE', 29900000),
('2025-01-18', 'TREASURY', 37500000);

-- ============================================================
-- STEP 3: Ensure staff module permissions (from previous fix)
-- ============================================================
INSERT IGNORE INTO staff_modules (staff_id, module_name)
SELECT s.id, 'ALL'
FROM staff s
WHERE s.employment_status = 'ACTIVE';

INSERT IGNORE INTO staff_modules (staff_id, module_name)
SELECT s.id, m.module_name
FROM staff s
CROSS JOIN (
  SELECT 'DASHBOARD' AS module_name UNION SELECT 'CUSTOMERS' UNION SELECT 'ACCOUNTS'
  UNION SELECT 'TRANSACTIONS' UNION SELECT 'LOANS' UNION SELECT 'TRANSFERS'
  UNION SELECT 'REPORTS' UNION SELECT 'BRANCHES' UNION SELECT 'STAFF'
  UNION SELECT 'SETTINGS' UNION SELECT 'APPROVALS' UNION SELECT 'AUDIT'
  UNION SELECT 'NOTIFICATIONS' UNION SELECT 'DOCUMENTS' UNION SELECT 'CHART_OF_ACCOUNTS'
  UNION SELECT 'EXPENSES' UNION SELECT 'POLICIES'
) m
WHERE s.employment_status = 'ACTIVE';

-- ============================================================
-- STEP 4: Verify
-- ============================================================
SELECT 'chart_of_accounts' AS tbl, COUNT(*) AS rows_count FROM chart_of_accounts
UNION ALL SELECT 'bank_branding', COUNT(*) FROM bank_branding
UNION ALL SELECT 'policies', COUNT(*) FROM policies
UNION ALL SELECT 'audit_findings', COUNT(*) FROM audit_findings
UNION ALL SELECT 'operating_account', COUNT(*) FROM operating_account
UNION ALL SELECT 'balance_trends', COUNT(*) FROM balance_trends
UNION ALL SELECT 'staff_modules', COUNT(*) FROM staff_modules;
