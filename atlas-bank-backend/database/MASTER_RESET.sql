-- ================================================================
-- ATLAS BANK — MASTER DATABASE RESET
-- ================================================================
-- INSTRUCTIONS:
--   1. Open phpMyAdmin (http://localhost/phpmyadmin)
--   2. Select the "atlas_bank" database (or create it)
--   3. Go to the SQL tab
--   4. Paste this ENTIRE script and click "Go"
--
-- This script:
--   - Drops ALL existing tables (clean slate)
--   - Creates ALL tables matching what the PHP backend expects
--   - Inserts complete seed data (staff, customers, accounts, etc.)
--   - Is the SINGLE source of truth for the database schema
-- ================================================================

CREATE DATABASE IF NOT EXISTS atlas_bank
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE atlas_bank;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- DROP ALL TABLES (reverse dependency order)
-- ================================================================
DROP TABLE IF EXISTS balance_trends;
DROP TABLE IF EXISTS account_tax_exemptions;
DROP TABLE IF EXISTS customer_products;
DROP TABLE IF EXISTS loan_application_checks;
DROP TABLE IF EXISTS loan_schedule;
DROP TABLE IF EXISTS loan_applications;
DROP TABLE IF EXISTS loans;
DROP TABLE IF EXISTS transaction_deductions;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS accounts;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS staff_modules;
DROP TABLE IF EXISTS staff_branches;
DROP TABLE IF EXISTS staff;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS login_history;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS approvals;
DROP TABLE IF EXISTS policies;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS chart_of_accounts;
DROP TABLE IF EXISTS expenses;
DROP TABLE IF EXISTS profit_ledger;
DROP TABLE IF EXISTS operating_account_transactions;
DROP TABLE IF EXISTS operating_account;
DROP TABLE IF EXISTS generated_documents;
DROP TABLE IF EXISTS audit_findings;
DROP TABLE IF EXISTS branches;
DROP TABLE IF EXISTS bank_branding;

-- ================================================================
-- 1. bank_branding
-- ================================================================
CREATE TABLE bank_branding (
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

-- ================================================================
-- 2. branches
-- ================================================================
CREATE TABLE branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(200) NOT NULL,
  region VARCHAR(100),
  country VARCHAR(10) DEFAULT 'CM',
  status ENUM('ACTIVE','INACTIVE','CLOSED') DEFAULT 'ACTIVE',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_code (code),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 3. staff
-- Columns used by PHP backend:
--   auth.php: password_hash, employment_status, mfa_required,
--             mfa_code, mfa_code_expires, account_locked, locked_until,
--             failed_login_attempts, last_login, last_login_ip, position
--   middleware/auth.php: id, username, full_name, initials, email, phone,
--             position, role, department, approval_limit, mfa_required,
--             last_login, ip_restrictions, account_locked, locked_until
-- ================================================================
CREATE TABLE staff (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  full_name VARCHAR(200) NOT NULL,
  initials VARCHAR(10),
  email VARCHAR(200),
  phone VARCHAR(50),
  position VARCHAR(100),
  role VARCHAR(50) NOT NULL,
  department VARCHAR(100),
  password_hash VARCHAR(255) NOT NULL,
  salt VARCHAR(64) DEFAULT '',
  mfa_required TINYINT(1) DEFAULT 1,
  mfa_secret VARCHAR(100) DEFAULT NULL,
  mfa_code VARCHAR(10) DEFAULT NULL,
  mfa_code_expires DATETIME DEFAULT NULL,
  employment_status ENUM('ACTIVE','INACTIVE','TERMINATED','SUSPENDED') DEFAULT 'ACTIVE',
  approval_limit DECIMAL(20,2) DEFAULT 0,
  ip_restrictions TEXT,
  last_login DATETIME NULL,
  last_login_ip VARCHAR(50) DEFAULT NULL,
  failed_login_attempts INT DEFAULT 0,
  account_locked TINYINT(1) DEFAULT 0,
  locked_until DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_username (username),
  INDEX idx_role (role),
  INDEX idx_status (employment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 4. staff_branches (junction table — used by auth middleware)
-- ================================================================
CREATE TABLE staff_branches (
  staff_id INT NOT NULL,
  branch_name VARCHAR(200) NOT NULL,
  PRIMARY KEY (staff_id, branch_name),
  INDEX idx_branch (branch_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 5. staff_modules (junction table — used by auth middleware)
-- ================================================================
CREATE TABLE staff_modules (
  staff_id INT NOT NULL,
  module_name VARCHAR(50) NOT NULL,
  PRIMARY KEY (staff_id, module_name),
  INDEX idx_module (module_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 6. customers
-- Columns used by PHP backend (customers.php):
--   INSERT: customer_number, customer_type, full_name, status, risk_rating,
--          branch, relationship_started, phone, email, kyc_verified
--   UPDATE: full_name, status, risk_rating, branch, phone, email, next_action
-- Extra columns for seed data: kyc_status, occupation, address, id_type, id_number, etc.
-- ================================================================
CREATE TABLE customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_number VARCHAR(30) NOT NULL UNIQUE,
  customer_type ENUM('INDIVIDUAL','BUSINESS') NOT NULL DEFAULT 'INDIVIDUAL',
  full_name VARCHAR(200) NOT NULL,
  status ENUM('DRAFT','PENDING_KYC','ACTIVE','RESTRICTED','FROZEN','CLOSED') DEFAULT 'DRAFT',
  risk_rating ENUM('LOW','MEDIUM','HIGH','CRITICAL') DEFAULT 'MEDIUM',
  branch VARCHAR(200),
  relationship_started DATE,
  next_action TEXT,
  phone VARCHAR(50),
  email VARCHAR(200),
  address TEXT,
  id_type VARCHAR(50) DEFAULT NULL,
  id_number VARCHAR(100) DEFAULT NULL,
  kyc_document LONGTEXT,
  kyc_verified TINYINT(1) DEFAULT 0,
  kyc_status ENUM('NOT_STARTED','PENDING','IN_REVIEW','VERIFIED','REJECTED') DEFAULT 'NOT_STARTED',
  kyc_submitted_at DATETIME DEFAULT NULL,
  occupation VARCHAR(100) DEFAULT NULL,
  employer VARCHAR(200) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_customer_number (customer_number),
  INDEX idx_full_name (full_name),
  INDEX idx_status (status),
  INDEX idx_branch (branch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 7. customer_products (junction table — used by customers.php)
-- ================================================================
CREATE TABLE customer_products (
  customer_id INT NOT NULL,
  product_name VARCHAR(50) NOT NULL,
  PRIMARY KEY (customer_id, product_name),
  INDEX idx_product (product_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 8. accounts
-- ================================================================
CREATE TABLE accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_number VARCHAR(30) NOT NULL UNIQUE,
  customer_id INT NOT NULL,
  customer_name VARCHAR(200),
  product_type ENUM('SALARY','CURRENT','SAVINGS','FIXED_DEPOSIT','CALL_DEPOSIT','LOAN','TREASURY','FOREX','ESCROW','JOINT','CORPORATE','ISLAMIC','DEMAND_DEPOSIT','TARGET_SAVINGS','CURRENT_PLUS') NOT NULL,
  branch VARCHAR(200),
  status ENUM('PENDING_OPENING','ACTIVE','FROZEN','DORMANT','CLOSED','RESTRICTED') DEFAULT 'PENDING_OPENING',
  currency VARCHAR(5) DEFAULT 'XAF',
  ledger_balance DECIMAL(20,2) DEFAULT 0,
  available_balance DECIMAL(20,2) DEFAULT 0,
  hold_balance DECIMAL(20,2) DEFAULT 0,
  opened_at DATE,
  closed_at DATE,
  tax_exemptions JSON DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_account_number (account_number),
  INDEX idx_customer_id (customer_id),
  INDEX idx_product_type (product_type),
  INDEX idx_status (status),
  INDEX idx_branch (branch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 9. transactions
-- Columns used by PHP backend (transactions.php):
--   INSERT: ref, type, status, branch, account, account_type, customer_name,
--          description, category, direction, amount, fee, fee_pct, memo, module,
--          operator_id, operator_name, created_at
-- ================================================================
CREATE TABLE transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(50) NOT NULL UNIQUE,
  type VARCHAR(50) NOT NULL,
  status ENUM('PENDING','PENDING_APPROVAL','POSTED','FAILED','REVERSED','CANCELLED') DEFAULT 'PENDING',
  branch VARCHAR(200),
  account VARCHAR(30),
  account_type VARCHAR(50),
  customer_name VARCHAR(200),
  description TEXT,
  category VARCHAR(100),
  direction ENUM('credit','debit') NOT NULL,
  amount DECIMAL(20,2) NOT NULL DEFAULT 0,
  fee DECIMAL(20,2) DEFAULT 0,
  fee_pct DECIMAL(5,2) DEFAULT 0,
  memo TEXT,
  module VARCHAR(50),
  operator_id INT,
  operator_name VARCHAR(200),
  approved_by INT,
  approved_at DATETIME DEFAULT NULL,
  posted_at DATETIME DEFAULT NULL,
  created_by INT,
  deduction_breakdown JSON DEFAULT NULL,
  timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ref (ref),
  INDEX idx_account (account),
  INDEX idx_status (status),
  INDEX idx_type (type),
  INDEX idx_branch (branch),
  INDEX idx_date (created_at),
  INDEX idx_direction (direction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 10. transaction_deductions (used by transactions.php GET handler)
-- ================================================================
CREATE TABLE transaction_deductions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transaction_id INT NOT NULL,
  deduction_key VARCHAR(100) NOT NULL,
  deduction_name VARCHAR(200),
  deduction_type ENUM('TAX','FEE','CONTRIBUTION','DEDUCTION') DEFAULT 'TAX',
  rate DECIMAL(10,4) DEFAULT 0,
  amount DECIMAL(20,2) DEFAULT 0,
  is_exempt TINYINT(1) DEFAULT 0,
  INDEX idx_transaction (transaction_id),
  INDEX idx_deduction_key (deduction_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 11. approvals
-- ================================================================
CREATE TABLE approvals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(50) NOT NULL,
  entity_id INT NOT NULL,
  scope_code VARCHAR(100) NOT NULL,
  status ENUM('PENDING','APPROVED','REJECTED','CANCELLED') DEFAULT 'PENDING',
  submitted_by VARCHAR(200),
  branch VARCHAR(200),
  value TEXT,
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  decided_by INT,
  decided_at DATETIME DEFAULT NULL,
  reason TEXT,
  INDEX idx_status (status),
  INDEX idx_entity (entity_type, entity_id),
  INDEX idx_scope (scope_code),
  INDEX idx_submitted (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 12. audit_logs
-- Columns used by helpers.php logAudit():
--   INSERT: uuid, actor, actor_branch, action, entity, entity_id, result, ip, details
-- ================================================================
CREATE TABLE audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid VARCHAR(50) NOT NULL UNIQUE,
  actor VARCHAR(100),
  actor_branch VARCHAR(200),
  action VARCHAR(100) NOT NULL,
  entity VARCHAR(50),
  entity_id VARCHAR(100),
  result ENUM('SUCCESS','FAILURE','DENIED') DEFAULT 'SUCCESS',
  ip VARCHAR(50),
  details TEXT,
  timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_actor (actor),
  INDEX idx_action (action),
  INDEX idx_entity (entity, entity_id),
  INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 13. login_history
-- Columns used by helpers.php recordLoginHistory():
--   INSERT: username, result, ip, user_agent, risk
-- ================================================================
CREATE TABLE login_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50),
  result ENUM('SUCCESS','FAILURE','LOCKED','MFA_CHALLENGE','MFA_FAILURE') DEFAULT 'SUCCESS',
  ip VARCHAR(50),
  user_agent VARCHAR(500),
  risk ENUM('NONE','LOW','MEDIUM','HIGH','CRITICAL') DEFAULT 'NONE',
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_username (username),
  INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 14. sessions
-- Columns used by helpers.php createSession() and middleware/auth.php:
--   id VARCHAR(128) PK, staff_id FK, ip_address, user_agent, expires_at
-- ================================================================
CREATE TABLE sessions (
  id VARCHAR(128) PRIMARY KEY,
  staff_id INT NOT NULL,
  ip_address VARCHAR(50),
  user_agent VARCHAR(500),
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_staff (staff_id),
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 15. loans
-- ================================================================
CREATE TABLE loans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loan_number VARCHAR(30) NOT NULL UNIQUE,
  customer_id INT NOT NULL,
  customer_name VARCHAR(200),
  branch VARCHAR(200),
  status ENUM('PENDING','ACTIVE','DELINQUENT','CLOSED','WRITTEN_OFF','RESTRUCTURED','DEFAULTED') DEFAULT 'PENDING',
  principal DECIMAL(20,2) NOT NULL DEFAULT 0,
  outstanding DECIMAL(20,2) DEFAULT 0,
  accrued_interest DECIMAL(20,2) DEFAULT 0,
  interest_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  term_months INT NOT NULL DEFAULT 0,
  repayment_freq ENUM('Weekly','Bi-Weekly','Monthly','Quarterly') DEFAULT 'Monthly',
  disbursed_at DATE,
  maturity_date DATE,
  next_due DATE,
  debit_account_id INT,
  debit_account_number VARCHAR(30),
  source TEXT,
  product_type VARCHAR(50),
  repayment_mode ENUM('MANUAL','SCHEDULED','PERCENTAGE') NOT NULL DEFAULT 'MANUAL',
  repayment_amount DECIMAL(20,2) DEFAULT 0,
  repayment_pct DECIMAL(5,2) DEFAULT 0,
  auto_deduct TINYINT(1) DEFAULT 1,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_loan_number (loan_number),
  INDEX idx_customer_id (customer_id),
  INDEX idx_status (status),
  INDEX idx_branch (branch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 16. loan_applications
-- ================================================================
CREATE TABLE loan_applications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(30) NOT NULL UNIQUE,
  customer_id INT NOT NULL,
  customer_name VARCHAR(200),
  product_type VARCHAR(50),
  amount DECIMAL(20,2) NOT NULL DEFAULT 0,
  interest_rate DECIMAL(5,2) DEFAULT NULL,
  term INT NOT NULL DEFAULT 0,
  purpose TEXT,
  status ENUM('PENDING','UNDER_REVIEW','APPROVED','REJECTED','WITHDRAWN') DEFAULT 'PENDING',
  branch VARCHAR(200),
  applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  decided_by INT,
  decided_at DATETIME NULL,
  decision_reason TEXT,
  checks JSON DEFAULT NULL,
  reviewed_by INT,
  reviewed_at DATETIME DEFAULT NULL,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ref (ref),
  INDEX idx_status (status),
  INDEX idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 17. loan_application_checks
-- ================================================================
CREATE TABLE loan_application_checks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(200),
  status ENUM('PENDING','PASSED','FAILED','WAIVED') DEFAULT 'PENDING',
  INDEX idx_application (application_id),
  INDEX idx_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 18. loan_schedule
-- ================================================================
CREATE TABLE loan_schedule (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loan_id INT NOT NULL,
  installment INT NOT NULL,
  due DATE NOT NULL,
  principal DECIMAL(20,2) NOT NULL DEFAULT 0,
  interest DECIMAL(20,2) NOT NULL DEFAULT 0,
  paid DECIMAL(20,2) DEFAULT 0,
  status ENUM('DUE','PAID','MISSED','PARTIALLY_PAID','WAIVED') DEFAULT 'DUE',
  paid_at DATETIME NULL,
  INDEX idx_loan_due (loan_id, due),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 19. expenses
-- Columns used by PHP backend (expenses.php):
--   INSERT: date, category, gl_code, gl_account_name, amount, vendor,
--          description, branch, status, operator_id, operator_name
--   UPDATE: status, approved_by, approved_at
-- ================================================================
CREATE TABLE expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  date DATE NOT NULL,
  category VARCHAR(50) NOT NULL,
  gl_code VARCHAR(20) DEFAULT NULL,
  gl_account_name VARCHAR(200) DEFAULT NULL,
  amount DECIMAL(20,2) NOT NULL DEFAULT 0,
  vendor VARCHAR(200) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  branch VARCHAR(200) DEFAULT NULL,
  status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  approved_by INT DEFAULT NULL,
  approved_at DATETIME DEFAULT NULL,
  operator_id INT DEFAULT NULL,
  operator_name VARCHAR(200) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_date (date),
  INDEX idx_category (category),
  INDEX idx_status (status),
  INDEX idx_branch (branch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 20. generated_documents
-- Columns used by PHP backend (documents.php):
--   INSERT: document_number, type, subtype, account_number, account_type,
--          customer_name, customer_id, branch, period_start, period_end,
--          generated_by, generated_by_name, status, content
-- ================================================================
CREATE TABLE generated_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  document_number VARCHAR(50) NOT NULL UNIQUE,
  type ENUM('STATEMENT','PAYSLIP','RECEIPT','REPORT','STMT','PAY','RCPT') NOT NULL,
  account_number VARCHAR(30) DEFAULT NULL,
  account_type VARCHAR(50) DEFAULT NULL,
  customer_name VARCHAR(200) DEFAULT NULL,
  customer_id INT DEFAULT NULL,
  branch VARCHAR(200) DEFAULT NULL,
  period_start DATE DEFAULT NULL,
  period_end DATE DEFAULT NULL,
  generated_by INT DEFAULT NULL,
  generated_by_name VARCHAR(200) DEFAULT NULL,
  subtype VARCHAR(50) DEFAULT NULL,
  status ENUM('ACTIVE','VOIDED','DRAFT','FINAL','CANCELLED') DEFAULT 'ACTIVE',
  content LONGTEXT DEFAULT NULL,
  print_count INT DEFAULT 0,
  last_printed_at DATETIME NULL DEFAULT NULL,
  export_count INT DEFAULT 0,
  last_exported_at DATETIME NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_document_number (document_number),
  INDEX idx_type (type),
  INDEX idx_account (account_number),
  INDEX idx_customer (customer_name),
  INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 21. notifications
-- Columns used by helpers.php addNotification():
--   INSERT: type, title, body, channel, target_staff_id
-- ================================================================
CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(50) NOT NULL,
  title VARCHAR(300) NOT NULL,
  body TEXT DEFAULT NULL,
  status ENUM('PENDING','READ','ARCHIVED') DEFAULT 'PENDING',
  channel VARCHAR(50) DEFAULT 'IN_APP',
  target_staff_id INT DEFAULT NULL,
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_staff (target_staff_id),
  INDEX idx_read (status),
  INDEX idx_created (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 22. policies
-- ================================================================
CREATE TABLE policies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NOT NULL UNIQUE,
  version INT DEFAULT 1,
  name VARCHAR(200) NOT NULL,
  effective_from DATE,
  effective_to DATE,
  description TEXT,
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_code (code),
  INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 23. settings
-- IMPORTANT: Column name is `key` (backtick-quoted) because the PHP
-- backend uses $setting['key'] in helpers.php and settings.php
-- ================================================================
CREATE TABLE settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(200) NOT NULL,
  value TEXT DEFAULT NULL,
  category VARCHAR(100) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  effective_from DATE DEFAULT NULL,
  requires_approval TINYINT(1) DEFAULT 0,
  approved_by INT DEFAULT NULL,
  approved_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_key (`key`),
  INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 24. chart_of_accounts
-- ================================================================
CREATE TABLE chart_of_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(200) NOT NULL,
  type ENUM('ASSET','LIABILITY','EQUITY','INCOME','EXPENSE') NOT NULL,
  category VARCHAR(100),
  description TEXT,
  is_active TINYINT(1) DEFAULT 1,
  INDEX idx_code (code),
  INDEX idx_type (type),
  INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 25. profit_ledger
-- ================================================================
CREATE TABLE profit_ledger (
  id INT AUTO_INCREMENT PRIMARY KEY,
  gl_code VARCHAR(20) NOT NULL,
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

-- ================================================================
-- 26. operating_account
-- ================================================================
CREATE TABLE operating_account (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_number VARCHAR(30) NOT NULL UNIQUE,
  account_name VARCHAR(200),
  balance DECIMAL(20,2) DEFAULT 0,
  currency VARCHAR(5) DEFAULT 'XAF',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 27. operating_account_transactions
-- ================================================================
CREATE TABLE operating_account_transactions (
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

-- ================================================================
-- 28. audit_findings
-- ================================================================
CREATE TABLE audit_findings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  severity ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL,
  category VARCHAR(100),
  description TEXT,
  recommendation TEXT,
  branch VARCHAR(200),
  status ENUM('OPEN','IN_PROGRESS','RESOLVED','CLOSED','ESCALATED') DEFAULT 'OPEN',
  assignee VARCHAR(200),
  created_date DATE,
  created_by VARCHAR(200),
  updated_at DATETIME NULL DEFAULT NULL,
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_severity (severity),
  INDEX idx_status (status),
  INDEX idx_branch (branch),
  INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 29. balance_trends
-- ================================================================
CREATE TABLE balance_trends (
  id INT AUTO_INCREMENT PRIMARY KEY,
  snapshot_date DATE NOT NULL,
  product_type VARCHAR(50) NOT NULL,
  total_balance DECIMAL(20,2) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_date_type (snapshot_date, product_type),
  INDEX idx_date (snapshot_date),
  INDEX idx_product (product_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 30. account_tax_exemptions
-- ================================================================
CREATE TABLE account_tax_exemptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  tax_key VARCHAR(100) NOT NULL,
  tax_name VARCHAR(200),
  is_exempt TINYINT(1) DEFAULT 0,
  reason TEXT,
  exempted_by INT,
  exempted_at DATETIME NULL,
  UNIQUE KEY uk_account_tax (account_id, tax_key),
  INDEX idx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ================================================================
--                     SEED DATA
-- ================================================================
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- 1. Bank Branding
-- ================================================================
INSERT INTO bank_branding (
  bank_name, bank_name_short, tagline, logo,
  primary_color, accent_color,
  head_office_address, phone, phone_alt, email, website,
  swift_code, cbn_license_number, registration_number,
  tax_identification_number, slogan
) VALUES (
  'ATLAS BANK', 'Atlas Bank', 'Enterprise Operations Console', NULL,
  '#58b7ff', '#67e8b5',
  '45 Avenue de la Liberte, BP 12345, Douala, Littoral, Cameroon',
  '+237 233 42 15 00', '+237 699 00 00 01', 'info@atlasbank.cm', 'www.atlasbank.cm',
  'ATLSCMCD', 'CBN-LIC-2024-0847', 'RC-DC-2024-B0012', 'TIN-CM-91000-ATLAS',
  'Building Trust, Securing Futures'
);

-- ================================================================
-- 2. Branches (3 rows)
-- ================================================================
INSERT INTO branches (id, code, name, region, status) VALUES
(1, 'DCV', 'Douala Centre-Ville', 'Littoral', 'ACTIVE'),
(2, 'DBP', 'Douala Bonapriso', 'Littoral', 'ACTIVE'),
(3, 'YBA', 'Yaounde Bastos', 'Centre', 'ACTIVE');

-- ================================================================
-- 3. Staff (5 rows)
--    All passwords: admin123
--    bcrypt hash: $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
-- ================================================================
INSERT INTO staff (id, username, full_name, initials, email, phone,
  position, role, department, password_hash, salt,
  mfa_required, employment_status, approval_limit) VALUES
(1, 'admin',     'Emmanuel Nkoulou',      'EN', 'e.nkoulou@atlasbank.cm',  '+237 699 11 22 33',
  'Chief Operations Officer', 'ADMIN',      'Operations',       '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '',
  0, 'ACTIVE', 999999999),
(2, 'jtchinda',  'Jean-Pierre Tchinda',    'JT', 'jp.tchinda@atlasbank.cm', '+237 699 22 33 44',
  'Senior Teller',           'TELLER',     'Operations',       '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '',
  0, 'ACTIVE', 5000000),
(3, 'cfotso',   'Clementine Fotso',       'CF', 'c.fotso@atlasbank.cm',    '+237 699 33 44 55',
  'Compliance Officer',      'COMPLIANCE','Compliance',        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '',
  0, 'ACTIVE', 10000000),
(4, 'andongo',  'Alain Ndongo',           'AN', 'a.ndongo@atlasbank.cm',   '+237 699 44 55 66',
  'Teller',                  'TELLER',     'Operations',       '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '',
  0, 'ACTIVE', 2000000),
(5, 'enkoulou', 'Emmanuel Nkoulou Jr',    'EJ', 'e.nkouloujr@atlasbank.cm','+237 699 55 66 77',
  'Branch Manager',          'SUPERVISOR','Operations',       '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '',
  0, 'ACTIVE', 15000000);

-- ================================================================
-- 4. Staff-Branch assignments
-- ================================================================
INSERT INTO staff_branches (staff_id, branch_name) VALUES
(1, 'Douala Centre-Ville'), (1, 'Douala Bonapriso'), (1, 'Yaounde Bastos'),
(2, 'Douala Centre-Ville'), (2, 'Douala Bonapriso'),
(3, 'Douala Centre-Ville'), (3, 'Yaounde Bastos'),
(4, 'Douala Bonapriso'),
(5, 'Yaounde Bastos');

-- ================================================================
-- 5. Staff-Module permissions (ALL for active staff)
-- ================================================================
INSERT INTO staff_modules (staff_id, module_name) VALUES
(1, 'ALL'),
(2, 'DASHBOARD'), (2, 'CUSTOMERS'), (2, 'ACCOUNTS'), (2, 'TRANSACTIONS'),
(2, 'LOANS'), (2, 'TRANSFERS'), (2, 'REPORTS'), (2, 'BRANCHES'),
(2, 'STAFF'), (2, 'SETTINGS'), (2, 'APPROVALS'), (2, 'AUDIT'),
(2, 'NOTIFICATIONS'), (2, 'DOCUMENTS'), (2, 'CHART_OF_ACCOUNTS'),
(2, 'EXPENSES'), (2, 'POLICIES'),
(3, 'DASHBOARD'), (3, 'CUSTOMERS'), (3, 'ACCOUNTS'), (3, 'TRANSACTIONS'),
(3, 'LOANS'), (3, 'TRANSFERS'), (3, 'APPROVALS'), (3, 'NOTIFICATIONS'),
(3, 'DOCUMENTS'), (3, 'EXPENSES'),
(4, 'DASHBOARD'), (4, 'CUSTOMERS'), (4, 'ACCOUNTS'), (4, 'TRANSACTIONS'),
(4, 'LOANS'), (4, 'TRANSFERS'),
(5, 'DASHBOARD'), (5, 'CUSTOMERS'), (5, 'ACCOUNTS'), (5, 'TRANSACTIONS'),
(5, 'LOANS'), (5, 'APPROVALS'), (5, 'BRANCHES'), (5, 'STAFF'),
(5, 'SETTINGS'), (5, 'REPORTS');

-- ================================================================
-- 6. Customers (8 rows)
-- ================================================================
INSERT INTO customers (
  id, customer_number, customer_type, full_name, status, risk_rating,
  branch, relationship_started, phone, email,
  kyc_verified, kyc_status, occupation
) VALUES
(1, 'CUST-000001', 'INDIVIDUAL', 'Paul Nkoulou Mebe',      'ACTIVE', 'LOW',    'Douala Centre-Ville', '2022-03-15', '+237 655 01 02 03', 'p.mebe@email.cm',     1, 'VERIFIED', 'Civil Servant'),
(2, 'CUST-000002', 'INDIVIDUAL', 'Marie-Claire Assamba',    'ACTIVE', 'LOW',    'Douala Bonapriso',   '2021-08-20', '+237 655 04 05 06', 'mc.assamba@email.cm', 1, 'VERIFIED', 'Business Owner'),
(3, 'CUST-000003', 'INDIVIDUAL', 'Andre Kamga Fotso',       'ACTIVE', 'MEDIUM', 'Douala Centre-Ville', '2023-01-10', '+237 655 07 08 09', 'a.kamga@email.cm',    1, 'VERIFIED', 'Teacher'),
(4, 'CUST-000004', 'INDIVIDUAL', 'Chantal Biyong',          'ACTIVE', 'LOW',    'Yaounde Bastos',     '2023-05-22', '+237 655 10 11 12', 'c.biyong@email.cm',   1, 'VERIFIED', 'Nurse'),
(5, 'CUST-000005', 'INDIVIDUAL', 'Francois Mengolo',        'ACTIVE', 'MEDIUM', 'Douala Bonapriso',   '2025-06-12', '+237 655 13 14 15', 'f.mengolo@email.cm',  1, 'VERIFIED', 'Contractor'),
(6, 'CUST-000006', 'BUSINESS',  'SARL Douala Transport',   'ACTIVE', 'MEDIUM', 'Douala Centre-Ville', '2020-12-01', '+237 233 55 66 77', 'dt@sarl.cm',          1, 'VERIFIED', 'Logistics'),
(7, 'CUST-000007', 'INDIVIDUAL', 'Nadine Ngassa',           'ACTIVE', 'LOW',    'Yaounde Bastos',     '2024-12-01', '+237 655 16 17 18', 'n.ngassa@email.cm',   1, 'VERIFIED', 'Accountant'),
(8, 'CUST-000008', 'INDIVIDUAL', 'Gilbert Tchoumba',        'ACTIVE', 'LOW',    'Douala Centre-Ville', '2025-09-28', '+237 655 19 20 21', 'g.tchoumba@email.cm', 1, 'VERIFIED', 'Engineer');

-- ================================================================
-- 7. Customer-Products
-- ================================================================
INSERT INTO customer_products (customer_id, product_name) VALUES
(1, 'Salary Account'), (1, 'Savings Account'),
(2, 'Current Account'), (2, 'Treasury Account'),
(3, 'Salary Account'), (3, 'Savings Account'),
(4, 'Savings Account'),
(5, 'Current Account'),
(6, 'Corporate Account'), (6, 'Current Account'),
(7, 'Salary Account'), (7, 'Current Account'),
(8, 'Current Account');

-- ================================================================
-- 8. Accounts (8 rows)
-- ================================================================
INSERT INTO accounts (
  id, account_number, customer_id, customer_name, product_type,
  branch, status, currency, ledger_balance, available_balance, hold_balance,
  opened_at
) VALUES
(1, 'ACC-20010001', 1, 'Paul Nkoulou Mebe',     'SALARY',    'Douala Centre-Ville', 'ACTIVE', 'XAF', 350000,    350000,    0, '2022-03-15'),
(2, 'ACC-20010002', 2, 'Marie-Claire Assamba',  'CURRENT',   'Douala Bonapriso',   'ACTIVE', 'XAF', 4200000,   4200000,   0, '2021-08-20'),
(3, 'ACC-20010003', 3, 'Andre Kamga Fotso',     'SALARY',    'Douala Centre-Ville', 'ACTIVE', 'XAF', 180000,    180000,    0, '2023-01-10'),
(4, 'ACC-20010004', 4, 'Chantal Biyong',        'SAVINGS',   'Yaounde Bastos',     'ACTIVE', 'XAF', 1250000,   1250000,   0, '2023-05-22'),
(5, 'ACC-20010005', 5, 'Francois Mengolo',      'CURRENT',   'Douala Bonapriso',   'ACTIVE', 'XAF', 850000,    850000,    0, '2025-06-12'),
(6, 'ACC-20010006', 6, 'SARL Douala Transport', 'CORPORATE', 'Douala Centre-Ville', 'ACTIVE', 'XAF', 29900000,  29900000,  0, '2020-12-01'),
(7, 'ACC-20010007', 7, 'Nadine Ngassa',         'SALARY',    'Yaounde Bastos',     'ACTIVE', 'XAF', 275000,    275000,    0, '2024-12-01'),
(8, 'ACC-20010008', 8, 'Gilbert Tchoumba',      'CURRENT',   'Douala Centre-Ville', 'ACTIVE', 'XAF', 3200000,   3200000,   0, '2025-09-28');

-- ================================================================
-- 9. Transactions (5 sample rows)
-- ================================================================
INSERT INTO transactions (
  ref, type, status, branch, account, account_type, customer_name,
  description, category, direction, amount, fee, fee_pct,
  memo, module, operator_id, operator_name, created_at, timestamp
) VALUES
('TXN-20260401-0001', 'DEPOSIT',     'POSTED', 'Douala Centre-Ville', 'ACC-20010001', 'SALARY', 'Paul Nkoulou Mebe',
 'Cash deposit', 'Cash', 'credit', 350000, 0, 0, 'Counter deposit', 'TRANSACTIONS', 2, 'Jean-Pierre Tchinda', '2026-04-01 10:00:00', '2026-04-01 10:00:00'),

('TXN-20260401-0002', 'WITHDRAWAL',  'POSTED', 'Douala Centre-Ville', 'ACC-20010001', 'SALARY', 'Paul Nkoulou Mebe',
 'Cash withdrawal at counter', 'Cash', 'debit', 100000, 500, 0.50, 'Counter withdrawal with 0.50% fee', 'TRANSACTIONS', 2, 'Jean-Pierre Tchinda', '2026-04-01 11:00:00', '2026-04-01 11:00:00'),

('TXN-20260402-0001', 'TRANSFER',    'POSTED', 'Douala Bonapriso', 'ACC-20010002', 'CURRENT', 'Marie-Claire Assamba',
 'Transfer to supplier', 'Wire Transfer', 'debit', 2500000, 500, 0, 'Supplier payment', 'TRANSFERS', 4, 'Alain Ndongo', '2026-04-02 09:30:00', '2026-04-02 09:30:00'),

('TXN-20260402-0002', 'DEPOSIT',     'POSTED', 'Douala Bonapriso', 'ACC-20010006', 'CORPORATE', 'SARL Douala Transport',
 'Cash deposit from operations', 'Cash', 'credit', 5000000, 0, 0, 'Weekly cash deposit', 'TRANSACTIONS', 4, 'Alain Ndongo', '2026-04-02 14:20:00', '2026-04-02 14:20:00'),

('TXN-20260403-0001', 'FEE',         'POSTED', 'Douala Centre-Ville', 'ACC-20010002', 'CURRENT', 'Marie-Claire Assamba',
 'Monthly account maintenance fee', 'Fee', 'debit', 5000, 0, 0, 'April 2026 maintenance fee', 'TRANSACTIONS', 2, 'Jean-Pierre Tchinda', '2026-04-03 06:00:00', '2026-04-03 06:00:00');

-- ================================================================
-- 10. Loans (2 rows)
-- ================================================================
INSERT INTO loans (
  loan_number, customer_id, customer_name, branch, status,
  principal, outstanding, accrued_interest, interest_rate, term_months,
  repayment_freq, disbursed_at, maturity_date, next_due,
  debit_account_id, debit_account_number, source, product_type,
  repayment_mode, repayment_amount, auto_deduct
) VALUES
('LN-2024-001', 6, 'SARL Douala Transport', 'Douala Centre-Ville',
 'ACTIVE', 10000000, 8333333, 250000, 14.50, 12, 'Monthly',
 '2024-07-15', '2025-07-15', '2026-04-15', 6, 'ACC-20010006',
 'Branch Application', 'Working Capital', 'SCHEDULED', 916667, 1),

('LN-2024-002', 2, 'Marie-Claire Assamba', 'Douala Bonapriso',
 'ACTIVE', 25000000, 20833333, 625000, 12.00, 24, 'Monthly',
 '2024-04-01', '2026-04-01', '2026-05-01', 2, 'ACC-20010002',
 'Direct Application', 'Asset Finance', 'SCHEDULED', 1250000, 1);

-- ================================================================
-- 11. Loan Applications (3 rows)
-- ================================================================
INSERT INTO loan_applications (
  ref, customer_id, customer_name, amount, term, purpose,
  status, branch, checks
) VALUES
('LA-2026-0001', 1, 'Paul Nkoulou Mebe', 2000000, 6,
 'Personal vehicle purchase', 'PENDING', 'Douala Centre-Ville',
 '[{"code":"KYC","name":"KYC Verification","status":"PASSED"},{"code":"CREDIT","name":"Credit Bureau Check","status":"PENDING"}]'),

('LA-2026-0002', 3, 'Andre Kamga Fotso', 5000000, 12,
 'Home improvement loan', 'UNDER_REVIEW', 'Douala Centre-Ville',
 '[{"code":"KYC","name":"KYC Verification","status":"PASSED"},{"code":"CREDIT","name":"Credit Bureau Check","status":"PASSED"},{"code":"AFFORDABILITY","name":"Affordability Assessment","status":"PENDING"}]'),

('LA-2026-0003', 8, 'Gilbert Tchoumba', 1500000, 6,
 'Emergency personal expenses', 'APPROVED', 'Douala Centre-Ville',
 '[{"code":"KYC","name":"KYC Verification","status":"PASSED"},{"code":"CREDIT","name":"Credit Bureau Check","status":"PASSED"},{"code":"AFFORDABILITY","name":"Affordability Assessment","status":"PASSED"}]');

-- ================================================================
-- 12. Loan Schedule (4 rows)
-- ================================================================
INSERT INTO loan_schedule (loan_id, installment, due, principal, interest, paid, status) VALUES
(1, 1, '2024-08-15', 783333.33, 133333.33, 916667, 'PAID'),
(1, 2, '2024-09-15', 792916.67, 123750.00, 916667, 'PAID'),
(1, 3, '2024-10-15', 802500.00, 114167.00, 916667, 'PAID'),
(2, 1, '2024-05-01', 1000000.00, 250000.00, 1250000, 'PAID');

-- ================================================================
-- 13. Approvals (3 rows)
-- ================================================================
INSERT INTO approvals (
  entity_type, entity_id, scope_code, status,
  submitted_by, branch, value, submitted_at
) VALUES
('TRANSACTION', 1, 'LOAN_APPROVAL', 'PENDING',
 'Jean-Pierre Tchinda', 'Douala Centre-Ville', '2,000,000 FCFA', NOW()),
('LOAN_APPLICATION', 2, 'LOAN_APPROVAL', 'PENDING',
 'Jean-Pierre Tchinda', 'Douala Centre-Ville', '5,000,000 FCFA', NOW()),
('EXPENSE', 1, 'EXPENSE_APPROVAL', 'APPROVED',
 'Alain Ndongo', 'Douala Bonapriso', '750,000 FCFA', '2026-04-01 10:00:00');

-- ================================================================
-- 14. Notifications (4 rows)
-- ================================================================
INSERT INTO notifications (type, title, body, status, channel, target_staff_id) VALUES
('APPROVAL', 'Loan Application Pending',
 'Paul Nkoulou Mebe has submitted a loan application for XAF 2,000,000.',
 'PENDING', 'IN_APP', 1),
('ALERT', 'New Expense Recorded',
 'A new expense of XAF 750,000 has been recorded for approval.',
 'PENDING', 'IN_APP', 1),
('SYSTEM', 'Database Reset Complete',
 'Master database reset was performed. All tables recreated with correct schema.',
 'READ', 'IN_APP', 1),
('SECURITY', 'Password Update Recommended',
 'Default passwords are in use. Please update staff passwords after first login.',
 'PENDING', 'IN_APP', 1);

-- ================================================================
-- 15. Policies (4 rows)
-- ================================================================
INSERT INTO policies (code, version, name, effective_from, effective_to, description) VALUES
('KYC-POL-001', 1, 'Customer Due Diligence Policy', '2024-01-01', '2027-12-31',
 'Policy governing customer identification, verification, and ongoing due diligence.'),
('AML-POL-001', 2, 'Anti-Money Laundering Policy', '2024-01-01', '2027-12-31',
 'Comprehensive AML policy including transaction monitoring and suspicious activity reporting.'),
('LOAN-POL-001', 1, 'Credit Risk Management Policy', '2024-01-01', '2027-12-31',
 'Framework for credit assessment, loan approval, and portfolio risk monitoring.'),
('IT-SEC-POL-001', 1, 'Information Security Policy', '2024-01-01', '2027-12-31',
 'Data protection, access controls, incident response, and cybersecurity measures.');

-- ================================================================
-- 16. Settings (Cameroon Tax + Operations + Fees)
-- IMPORTANT: Column is `key` not `setting_key`
-- ================================================================
INSERT INTO settings (`key`, name, category, value, description, requires_approval) VALUES
-- Fees
('FEE_WITHDRAWAL_COUNTER',    'Counter Withdrawal Fee (%)',   'FEES',              '0.50', 'Percentage fee for counter withdrawals',          0),
('FEE_WITHDRAWAL_ATM',        'ATM Withdrawal Fee (XAF)',     'FEES',              '200',  'Flat fee for ATM withdrawals',                     0),
('FEE_TRANSFER_INTERNAL',     'Internal Transfer Fee (XAF)',  'FEES',              '0',    'Fee for transfers within Atlas Bank',              0),
('FEE_TRANSFER_EXTERNAL',     'External Transfer Fee (XAF)', 'FEES',              '500',  'Fee for transfers to other banks',                 0),
('FEE_TRANSFER_HIGH_VALUE',   'High-Value Transfer Threshold (XAF)', 'FEES',     '5000000', 'Threshold above which transfers require approval', 1),
('FEE_ACCOUNT_MAINTENANCE',   'Monthly Account Maintenance (XAF)', 'FEES',        '5000', 'Monthly fee for account maintenance',              0),
('FEE_LOAN_PROCESSING',       'Loan Processing Fee (%)',      'FEES',              '1.00', 'Percentage fee for loan processing',               1),
('FEE_LOAN_LATE_PAYMENT',     'Loan Late Payment Penalty (%)','FEES',              '2.00', 'Penalty percentage for late loan repayments',      0),
-- Cameroon Tax
('TAX_IR_RATE',               'Income Tax Rate (IR) (%)',     'TAX',              '11.25', 'Personal income tax withholding rate',             1),
('TAX_IR_THRESHOLD',          'Income Tax Exemption Threshold (XAF)', 'TAX',     '62000', 'Monthly salary below which no IR is deducted',    1),
('TAX_CNPS_EMPLOYEE_RATE',    'CNPS Employee Rate (%)',       'TAX',              '2.80', 'Social security - employee portion',                1),
('TAX_CNPS_EMPLOYER_RATE',    'CNPS Employer Rate (%)',       'TAX',              '4.20', 'Social security - employer portion',                1),
('TAX_CNPS_CEILING',          'CNPS Contribution Ceiling (XAF)', 'TAX',           '750000', 'Max monthly salary for CNPS calculation',          1),
('TAX_REGISTRATION',          'Registration Tax Rate (%)',    'TAX',              '1.00',  'Tax on financial transactions registration',        1),
('TAX_STAMP_DUTY',            'Stamp Duty Rate (%)',         'TAX',              '0.20',  'Stamp duty on transactions',                        0),
('TAX_VAT_RATE',              'VAT Rate (%)',                'TAX',              '19.25', 'Standard VAT rate for services',                   1),
('TAX_WITHHOLDING_RATE',      'Withholding Tax Rate (%)',    'TAX',              '5.00',  'Standard withholding tax rate',                     1),
-- Lending
('loan.min_holding_days',     'Loan Minimum Holding Days',    'LENDING',          '90',   'Minimum days before loan eligibility',             1),
('loan.default_interest_rate','Default Loan Interest Rate (%)','LENDING',         '5.50', 'Default interest rate for new loans',              1),
('loan.max_amount',           'Maximum Loan Amount (XAF)',    'LENDING',          '600000000', 'Maximum single loan amount',                      1),
('loan.late_payment_penalty', 'Late Payment Penalty (%)',    'LENDING',          '2.00',  'Penalty for overdue loan payments',                 1),
-- Operations
('OPR_MAX_LOGIN_ATTEMPTS',    'Max Login Attempts',           'OPERATIONS',       '5',    'Failed login attempts before lockout',             1),
('OPR_LOCKOUT_DURATION',      'Lockout Duration (minutes)',   'OPERATIONS',       '30',   'Duration of account lockout',                      1),
('OPR_SESSION_TIMEOUT',       'Session Timeout (minutes)',    'OPERATIONS',       '480',  'Session timeout duration',                         1),
('OPR_MFA_REQUIRED',          'MFA Required',                'OPERATIONS',       'false', 'Whether MFA is required for login',                1),
('OPR_CURRENCY',              'Default Currency',            'OPERATIONS',       'XAF',  'Default currency code',                           0),
('txn.approval_threshold',    'Transaction Approval Threshold','OPERATIONS',       '5000000', 'Amount above which transactions need approval',    1),
-- Withdrawal Taxes (Cameroon - detailed)
('tax.paye_rate',             'PAYE Income Tax Rate (%)',    'Withdrawal Taxes', '7.50', 'Pay-As-You-Earn income tax rate',                 1),
('tax.cnps_retraite_employee','CNPS Retraite Employee (%)',  'Withdrawal Taxes', '4.80', 'Pension scheme - employee contribution',            1),
('tax.cnps_retraite_employer','CNPS Retraite Employer (%)',  'Withdrawal Taxes', '8.40', 'Pension scheme - employer contribution',           1),
('tax.cnps_prestations',      'CNPS Prestations Familiales (%)', 'Withdrawal Taxes', '7.00', 'Family benefits - employer contribution',          1),
('tax.citec_employee',        'CITEC Logement Employee (%)', 'Withdrawal Taxes', '1.00', 'Housing fund - employee contribution',              1),
('tax.citec_employer',        'CITEC Logement Employer (%)', 'Withdrawal Taxes', '2.50', 'Housing fund - employer contribution',              1),
('tax.stamp_duty_rate',       'Stamp Duty Rate (%)',         'Withdrawal Taxes', '0.50', 'Stamp duty on withdrawal transactions',             1),
('tax.consolidated_relief_annual', 'Annual Consolidated Relief (XAF)', 'Withdrawal Taxes', '200000', 'Annual tax-free allowance / 12 monthly',          1),
('tax.percentage_relief_rate','Percentage Relief Rate (%)',  'Withdrawal Taxes', '20.00', 'Additional 20% relief on gross earnings',           1);

-- ================================================================
-- 17. Chart of Accounts (20 rows)
-- ================================================================
INSERT INTO chart_of_accounts (code, name, type, category, description, is_active) VALUES
('1000', 'Cash and Cash Equivalents',    'ASSET',    'LIQUIDITY',  'Physical cash, central bank reserves',           1),
('1100', 'Due from Banks',               'ASSET',    'LIQUIDITY',  'Balances held at correspondent banks',            1),
('1200', 'Loans and Advances',           'ASSET',    'CREDIT',     'Customer loans and advances',                    1),
('1210', 'Loan Loss Provision',          'ASSET',    'CREDIT',     'Provision for expected credit losses',            1),
('1300', 'Investment Securities',        'ASSET',    'INVESTMENT', 'Government bonds and treasury bills',             1),
('1400', 'Fixed Assets',                 'ASSET',    'PREMISES',   'Property, equipment, and IT infrastructure',      1),
('1500', 'Other Assets',                 'ASSET',    'OTHER',      'Prepayments, receivables, and other assets',      1),
('2000', 'Customer Deposits',            'LIABILITY','DEPOSITS',   'Current, savings, and fixed deposit accounts',     1),
('2100', 'Due to Banks',                 'LIABILITY','LIQUIDITY',  'Amounts owed to correspondent banks',             1),
('2200', 'Borrowings',                   'LIABILITY','FUNDING',    'Interbank loans and institutional borrowings',     1),
('2300', 'Tax Payable',                  'LIABILITY','TAX',        'Accrued tax obligations',                        1),
('2400', 'Other Liabilities',            'LIABILITY','OTHER',      'Accrued expenses and other payables',             1),
('3000', 'Share Capital',                'EQUITY',   'CAPITAL',    'Paid-in share capital',                           1),
('3100', 'Retained Earnings',            'EQUITY',   'RESERVES',   'Accumulated retained earnings',                    1),
('4000', 'Interest Income',              'INCOME',   'INTEREST',   'Interest earned on loans and investments',        1),
('4100', 'Fee and Commission Income',    'INCOME',   'FEE',        'Banking fees and service charges',                1),
('4200', 'Other Operating Income',       'INCOME',   'OTHER',      'Non-interest operating income',                   1),
('5000', 'Interest Expense',             'EXPENSE',  'INTEREST',   'Interest paid on deposits and borrowings',        1),
('5100', 'Operating Expenses',           'EXPENSE',  'ADMIN',      'Salaries, rent, utilities, and admin costs',       1),
('5200', 'Impairment Losses',            'EXPENSE',  'CREDIT',     'Expected credit loss provisions',                  1);

-- ================================================================
-- 18. Expenses (3 rows)
-- ================================================================
INSERT INTO expenses (
  date, category, gl_code, gl_account_name, amount,
  vendor, description, branch, status,
  approved_by, approved_at, operator_id, operator_name
) VALUES
('2026-04-01', 'UTILITIES',  '5100', 'Operating Expenses', 350000,
 'ENEO Cameroon', 'Electricity bill - Main Branch', 'Douala Centre-Ville', 'APPROVED',
 1, '2026-04-02 09:00:00', 2, 'Jean-Pierre Tchinda'),
('2026-04-02', 'TECHNOLOGY','5100', 'Operating Expenses', 750000,
 'TechServe SARL', 'Monthly managed IT services', 'Douala Centre-Ville', 'PENDING',
 NULL, NULL, 2, 'Jean-Pierre Tchinda'),
('2026-04-03', 'MARKETING', '5100', 'Operating Expenses', 150000,
 'Atlas Media', 'Branch signage refresh', 'Douala Bonapriso', 'REJECTED',
 1, '2026-04-03 14:00:00', 4, 'Alain Ndongo');

-- ================================================================
-- 19. Operating Account (1 row)
-- ================================================================
INSERT INTO operating_account (account_number, account_name, balance, currency) VALUES
('OPS-000001', 'Atlas Bank Operating Account', 145000000, 'XAF');

-- ================================================================
-- 20. Audit Findings (3 rows)
-- ================================================================
INSERT INTO audit_findings (
  severity, category, description, recommendation,
  branch, status, assignee, created_date, created_by
) VALUES
('HIGH', 'KYC', 'Incomplete KYC documentation for 12 customers onboarded in Q1 2026',
 'Initiate customer outreach to complete KYC within 30 days.',
 'Douala Centre-Ville', 'OPEN', 'Clementine Fotso', '2026-01-10', 'Emmanuel Nkoulou'),
('MEDIUM', 'OPERATIONS', 'Teller cash variance exceeding XAF 50,000 on 3 occasions in March 2026',
 'Implement mandatory end-of-day cash reconciliation with dual control.',
 'Douala Bonapriso', 'IN_PROGRESS', 'Alain Ndongo', '2026-01-08', 'Emmanuel Nkoulou'),
('CRITICAL', 'AML', 'Delayed suspicious activity reporting - 2 reports filed beyond regulatory deadline',
 'Automate SAR generation triggers in the transaction monitoring system.',
 'Douala Centre-Ville', 'OPEN', 'Jean-Pierre Tchinda', '2026-01-12', 'Emmanuel Nkoulou');

-- ================================================================
-- 21. Balance Trends (5 entries)
-- ================================================================
INSERT INTO balance_trends (snapshot_date, product_type, total_balance) VALUES
('2026-04-01', 'SALARY',    805000),
('2026-04-01', 'CURRENT',   8395000),
('2026-04-01', 'SAVINGS',   1250000),
('2026-04-01', 'CORPORATE', 29900000);

SET FOREIGN_KEY_CHECKS = 1;

-- ================================================================
-- VERIFICATION
-- ================================================================
SELECT '=== MASTER RESET COMPLETE ===' AS status;
SELECT CONCAT(table_name, ' (', table_rows, ' rows)') AS `Tables`
FROM information_schema.tables
WHERE table_schema = 'atlas_bank'
ORDER BY table_name;
