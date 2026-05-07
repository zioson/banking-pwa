-- ================================================================
-- Atlas Bank Enterprise Operations Console
-- COMPLETE DATABASE SETUP — Run this ONCE in phpMyAdmin
-- 
-- This script creates ALL required tables and inserts seed data.
-- It is SAFE to run multiple times (uses IF NOT EXISTS everywhere).
--
-- INSTRUCTIONS:
-- 1. Open phpMyAdmin (http://localhost/phpmyadmin)
-- 2. Make sure you have a database called "atlas_bank"
--    (if not, create one first, or this script will create it)
-- 3. Go to the SQL tab
-- 4. Paste this entire script and click "Go"
-- ================================================================

CREATE DATABASE IF NOT EXISTS atlas_bank
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE atlas_bank;

-- ============================================================
-- TABLE 1: bank_branding
-- ============================================================
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

-- ============================================================
-- TABLE 2: branches
-- ============================================================
CREATE TABLE IF NOT EXISTS branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(200) NOT NULL,
  region VARCHAR(100),
  country VARCHAR(10) DEFAULT 'CM',
  status ENUM('ACTIVE','INACTIVE','CLOSED') DEFAULT 'ACTIVE',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_code (code),
  INDEX idx_status (status),
  INDEX idx_region (region)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 3: staff
-- ============================================================
CREATE TABLE IF NOT EXISTS staff (
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
  salt VARCHAR(64) NOT NULL,
  mfa_required TINYINT(1) DEFAULT 1,
  mfa_secret VARCHAR(100),
  employment_status ENUM('ACTIVE','INACTIVE','TERMINATED','SUSPENDED') DEFAULT 'ACTIVE',
  approval_limit DECIMAL(20,2) DEFAULT 0,
  ip_restrictions TEXT,
  last_login TIMESTAMP NULL,
  last_login_ip VARCHAR(50),
  failed_login_attempts INT DEFAULT 0,
  account_locked TINYINT(1) DEFAULT 0,
  locked_until TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_username (username),
  INDEX idx_role (role),
  INDEX idx_department (department),
  INDEX idx_status (employment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 4: staff_branches (many-to-many)
-- ============================================================
CREATE TABLE IF NOT EXISTS staff_branches (
  staff_id INT NOT NULL,
  branch_name VARCHAR(200) NOT NULL,
  PRIMARY KEY (staff_id, branch_name),
  INDEX idx_branch (branch_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 5: staff_modules (RBAC)
-- ============================================================
CREATE TABLE IF NOT EXISTS staff_modules (
  staff_id INT NOT NULL,
  module_name VARCHAR(50) NOT NULL,
  PRIMARY KEY (staff_id, module_name),
  INDEX idx_module (module_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 6: customers
-- ============================================================
CREATE TABLE IF NOT EXISTS customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_number VARCHAR(20) NOT NULL UNIQUE,
  full_name VARCHAR(200) NOT NULL,
  email VARCHAR(200),
  phone VARCHAR(50),
  date_of_birth DATE,
  gender ENUM('MALE','FEMALE','OTHER'),
  nationality VARCHAR(50) DEFAULT 'Cameroonian',
  id_type VARCHAR(50),
  id_number VARCHAR(50),
  id_issue_date DATE,
  id_expiry_date DATE,
  customer_type ENUM('INDIVIDUAL','BUSINESS') DEFAULT 'INDIVIDUAL',
  status ENUM('DRAFT','PENDING_KYC','ACTIVE','RESTRICTED','FROZEN','CLOSED') DEFAULT 'DRAFT',
  kyc_status ENUM('NONE','PENDING','VERIFIED','EXPIRED') DEFAULT 'NONE',
  kyc_verified_at TIMESTAMP NULL,
  risk_rating ENUM('LOW','MEDIUM','HIGH','CRITICAL') DEFAULT 'MEDIUM',
  occupation VARCHAR(100),
  employer VARCHAR(200),
  residential_address TEXT,
  city VARCHAR(100),
  branch VARCHAR(200),
  assigned_to INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_customer_number (customer_number),
  INDEX idx_full_name (full_name),
  INDEX idx_status (status),
  INDEX idx_branch (branch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 7: accounts
-- ============================================================
CREATE TABLE IF NOT EXISTS accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_number VARCHAR(30) NOT NULL UNIQUE,
  customer_id INT NOT NULL,
  customer_name VARCHAR(200),
  product_type ENUM('SALARY','CURRENT','SAVINGS','FIXED_DEPOSIT','CALL_DEPOSIT','LOAN','TREASURY','FOREX','ESCROW','JOINT','CORPORATE','ISLAMIC') NOT NULL,
  branch VARCHAR(200),
  status ENUM('PENDING_OPENING','ACTIVE','FROZEN','DORMANT','CLOSED','RESTRICTED') DEFAULT 'PENDING_OPENING',
  currency VARCHAR(5) DEFAULT 'XAF',
  ledger_balance DECIMAL(20,2) DEFAULT 0,
  available_balance DECIMAL(20,2) DEFAULT 0,
  hold_balance DECIMAL(20,2) DEFAULT 0,
  opened_at DATE,
  closed_at DATE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_account_number (account_number),
  INDEX idx_customer_id (customer_id),
  INDEX idx_product_type (product_type),
  INDEX idx_status (status),
  INDEX idx_branch (branch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 8: transactions
-- ============================================================
CREATE TABLE IF NOT EXISTS transactions (
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
  approved_at TIMESTAMP NULL,
  posted_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ref (ref),
  INDEX idx_account (account),
  INDEX idx_status (status),
  INDEX idx_type (type),
  INDEX idx_branch (branch),
  INDEX idx_customer (customer_name),
  INDEX idx_date (created_at),
  INDEX idx_direction (direction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 9: transaction_deductions
-- ============================================================
CREATE TABLE IF NOT EXISTS transaction_deductions (
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

-- ============================================================
-- TABLE 10: approvals
-- ============================================================
CREATE TABLE IF NOT EXISTS approvals (
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
  decided_at TIMESTAMP NULL,
  reason TEXT,
  INDEX idx_status (status),
  INDEX idx_entity (entity_type, entity_id),
  INDEX idx_scope (scope_code),
  INDEX idx_submitted (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 11: audit_logs
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid VARCHAR(20) NOT NULL UNIQUE,
  actor VARCHAR(100),
  actor_branch VARCHAR(200),
  action VARCHAR(100) NOT NULL,
  entity VARCHAR(50),
  entity_id VARCHAR(100),
  result ENUM('SUCCESS','FAILURE','DENIED') DEFAULT 'SUCCESS',
  ip VARCHAR(50),
  details TEXT,
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_actor (actor),
  INDEX idx_action (action),
  INDEX idx_entity (entity, entity_id),
  INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 12: loans
-- ============================================================
CREATE TABLE IF NOT EXISTS loans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loan_number VARCHAR(30) NOT NULL UNIQUE,
  customer_id INT NOT NULL,
  customer_name VARCHAR(200),
  product_type VARCHAR(50),
  principal DECIMAL(20,2) NOT NULL,
  interest_rate DECIMAL(5,2) NOT NULL,
  term_months INT NOT NULL,
  disbursement_date DATE,
  maturity_date DATE,
  outstanding DECIMAL(20,2) DEFAULT 0,
  status ENUM('PENDING','ACTIVE','DELINQUENT','CLOSED','WRITTEN_OFF','RESTRUCTURED') DEFAULT 'PENDING',
  branch VARCHAR(200),
  debit_account_id INT,
  debit_account_number VARCHAR(30),
  repayment_mode ENUM('MANUAL','SCHEDULED') DEFAULT 'MANUAL',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_loan_number (loan_number),
  INDEX idx_customer_id (customer_id),
  INDEX idx_status (status),
  INDEX idx_branch (branch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 13: loan_applications
-- ============================================================
CREATE TABLE IF NOT EXISTS loan_applications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  application_number VARCHAR(30) NOT NULL UNIQUE,
  customer_id INT NOT NULL,
  customer_name VARCHAR(200),
  product_type VARCHAR(50),
  amount DECIMAL(20,2) NOT NULL,
  interest_rate DECIMAL(5,2),
  term_months INT,
  purpose TEXT,
  status ENUM('PENDING','UNDER_REVIEW','APPROVED','REJECTED','WITHDRAWN') DEFAULT 'PENDING',
  branch VARCHAR(200),
  debit_account_id INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_application_number (application_number),
  INDEX idx_customer_id (customer_id),
  INDEX idx_status (status),
  INDEX idx_branch (branch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 14: expenses
-- ============================================================
CREATE TABLE IF NOT EXISTS expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  date DATE NOT NULL,
  category VARCHAR(50) NOT NULL,
  gl_code VARCHAR(10),
  gl_account_name VARCHAR(200),
  amount DECIMAL(20,2) NOT NULL,
  vendor VARCHAR(200),
  description TEXT,
  branch VARCHAR(200),
  status ENUM('PENDING','APPROVED','REJECTED') DEFAULT 'PENDING',
  approved_by INT,
  approved_at TIMESTAMP NULL,
  operator_id INT,
  operator_name VARCHAR(200),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_date (date),
  INDEX idx_category (category),
  INDEX idx_status (status),
  INDEX idx_branch (branch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 15: generated_documents
-- ============================================================
CREATE TABLE IF NOT EXISTS generated_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  document_number VARCHAR(50) NOT NULL UNIQUE,
  type ENUM('STATEMENT','PAYSLIP','RECEIPT','REPORT','STMT','PAY','RCPT') NOT NULL,
  account_number VARCHAR(30),
  account_type VARCHAR(50),
  customer_name VARCHAR(200),
  customer_id INT DEFAULT NULL,
  branch VARCHAR(200),
  period_start DATE,
  period_end DATE,
  generated_by INT,
  generated_by_name VARCHAR(200),
  subtype VARCHAR(50) DEFAULT NULL,
  status ENUM('ACTIVE','VOIDED','DRAFT','FINAL','CANCELLED') DEFAULT 'ACTIVE',
  content LONGTEXT,
  print_count INT DEFAULT 0,
  last_printed_at TIMESTAMP NULL DEFAULT NULL,
  export_count INT DEFAULT 0,
  last_exported_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_document_number (document_number),
  INDEX idx_type (type),
  INDEX idx_account (account_number),
  INDEX idx_customer (customer_name),
  INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 16: sessions
-- ============================================================
CREATE TABLE IF NOT EXISTS sessions (
  id VARCHAR(128) PRIMARY KEY,
  staff_id INT NOT NULL,
  ip_address VARCHAR(50),
  user_agent VARCHAR(500),
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_staff (staff_id),
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 17: login_history
-- ============================================================
CREATE TABLE IF NOT EXISTS login_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50),
  result ENUM('SUCCESS','FAILURE','LOCKED') DEFAULT 'SUCCESS',
  ip VARCHAR(50),
  user_agent VARCHAR(500),
  risk ENUM('NONE','LOW','MEDIUM','HIGH','CRITICAL') DEFAULT 'NONE',
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_username (username),
  INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 18: settings
-- ============================================================
CREATE TABLE IF NOT EXISTS settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(200) NOT NULL,
  value VARCHAR(500),
  category VARCHAR(50),
  description TEXT,
  requires_approval TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_key (`key`),
  INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 19: notifications
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  staff_id INT,
  title VARCHAR(200) NOT NULL,
  message TEXT,
  type VARCHAR(50) DEFAULT 'INFO',
  is_read TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_staff (staff_id),
  INDEX idx_read (is_read),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 20: policies
-- ============================================================
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

-- ============================================================
-- TABLE 21: chart_of_accounts
-- ============================================================
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

-- ============================================================
-- TABLE 22: profit_ledger
-- ============================================================
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

-- ============================================================
-- TABLE 23: operating_account
-- ============================================================
CREATE TABLE IF NOT EXISTS operating_account (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_number VARCHAR(30) NOT NULL UNIQUE,
  account_name VARCHAR(200),
  balance DECIMAL(20,2) DEFAULT 0,
  currency VARCHAR(5) DEFAULT 'XAF',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 24: operating_account_transactions
-- ============================================================
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

-- ============================================================
-- TABLE 25: audit_findings
-- ============================================================
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
-- TABLE 26: balance_trends
-- ============================================================
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

-- ============================================================
-- TABLE 27: account_tax_exemptions
-- ============================================================
CREATE TABLE IF NOT EXISTS account_tax_exemptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  tax_key VARCHAR(100) NOT NULL,
  tax_name VARCHAR(200),
  is_exempt TINYINT(1) DEFAULT 0,
  reason TEXT,
  exempted_by INT,
  exempted_at TIMESTAMP NULL,
  UNIQUE KEY uk_account_tax (account_id, tax_key),
  INDEX idx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ADD updated_at COLUMN TO expenses (if missing)
-- ============================================================
-- This column is referenced by the API but may not exist in older schema versions.
-- Safe ALTER: uses IF NOT EXISTS pattern via a procedure or just ignores the error.
SET @dbname = DATABASE();
SET @tablename = 'expenses';
SET @columnname = 'updated_at';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_schema = @dbname)
      AND (table_name = @tablename)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  'ALTER TABLE expenses ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- ============================================================
-- SEED DATA: Branches
-- ============================================================
INSERT IGNORE INTO branches (id, code, name, region, status) VALUES
(1, 'DCV', 'Douala Centre-Ville', 'Littoral', 'ACTIVE'),
(2, 'DBP', 'Douala Bonapriso', 'Littoral', 'ACTIVE'),
(3, 'YBA', 'Yaoundé Bastos', 'Centre', 'ACTIVE');

-- ============================================================
-- SEED DATA: Staff (password: atlas2024)
-- ============================================================
INSERT IGNORE INTO staff (id, username, full_name, initials, email, phone, position, role, department, password_hash, salt, employment_status, approval_limit, mfa_required) VALUES
(1, 'admin', 'Isabelle Mbarga', 'IM', 'i.mbarga@atlasbank.cm', '+237 699 11 22 33', 'Chief Operations Officer', 'ADMIN', 'Operations', 'a3f5b2c1d4e6f7a8b9c0d1e2f3a4b5c6', 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', 'ACTIVE', 999999999, 0),
(2, 'jtchinda', 'Jean-Pierre Tchinda', 'JT', 'jp.tchinda@atlasbank.cm', '+237 699 22 33 44', 'Senior Teller', 'TELLER', 'Operations', 'b4c6d3e2f5a7b8c9d0e1f2a3b4c5d6e7', 'b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7', 'ACTIVE', 5000000, 0),
(3, 'cfotso', 'Clémentine Fotso', 'CF', 'c.fotso@atlasbank.cm', '+237 699 33 44 55', 'Compliance Officer', 'COMPLIANCE', 'Compliance', 'c5d7e4f3a6b8c9d0e1f2a3b4c5d6e7f8', 'c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8', 'ACTIVE', 10000000, 0),
(4, 'andongo', 'Alain Ndongo', 'AN', 'a.ndongo@atlasbank.cm', '+237 699 44 55 66', 'Teller', 'TELLER', 'Operations', 'd6e8f5a4b7c9d0e1f2a3b4c5d6e7f8a9', 'd4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9', 'ACTIVE', 2000000, 0),
(5, 'enkoulou', 'Emmanuel Nkoulou', 'EN', 'e.nkoulou@atlasbank.cm', '+237 699 55 66 77', 'Branch Manager', 'SUPERVISOR', 'Operations', 'e7f9a6b5c8d0e1f2a3b4c5d6e7f8a9b0', 'e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0', 'ACTIVE', 15000000, 0);

-- ============================================================
-- SEED DATA: Staff-Branch assignments
-- ============================================================
INSERT IGNORE INTO staff_branches (staff_id, branch_name) VALUES
(1, 'Douala Centre-Ville'), (1, 'Douala Bonapriso'), (1, 'Yaoundé Bastos'),
(2, 'Douala Centre-Ville'), (2, 'Douala Bonapriso'),
(3, 'Douala Centre-Ville'), (3, 'Yaoundé Bastos'),
(4, 'Douala Bonapriso'),
(5, 'Yaoundé Bastos');

-- ============================================================
-- SEED DATA: Staff-Module permissions (ALL access for all active staff)
-- ============================================================
INSERT IGNORE INTO staff_modules (staff_id, module_name)
SELECT s.id, 'ALL'
FROM staff s
WHERE s.employment_status = 'ACTIVE'
AND 'ALL' NOT IN (SELECT module_name FROM staff_modules WHERE staff_id = s.id);

-- ============================================================
-- SEED DATA: Customers
-- ============================================================
INSERT IGNORE INTO customers (id, customer_number, full_name, email, phone, customer_type, status, kyc_status, risk_rating, branch, occupation) VALUES
(1, 'CUST-00001', 'Paul Nkoulou Mebe', 'p.mebe@email.cm', '+237 655 01 02 03', 'INDIVIDUAL', 'ACTIVE', 'VERIFIED', 'LOW', 'Douala Centre-Ville', 'Civil Servant'),
(2, 'CUST-00002', 'Marie-Claire Assamba', 'mc.assamba@email.cm', '+237 655 04 05 06', 'INDIVIDUAL', 'ACTIVE', 'VERIFIED', 'LOW', 'Douala Bonapriso', 'Business Owner'),
(3, 'CUST-00003', 'André Kamga Fotso', 'a.kamga@email.cm', '+237 655 07 08 09', 'INDIVIDUAL', 'ACTIVE', 'VERIFIED', 'MEDIUM', 'Douala Centre-Ville', 'Teacher'),
(4, 'CUST-00004', 'Chantal Biyong', 'c.biyong@email.cm', '+237 655 10 11 12', 'INDIVIDUAL', 'ACTIVE', 'VERIFIED', 'LOW', 'Yaoundé Bastos', 'Nurse'),
(5, 'CUST-00005', 'François Mengolo', 'f.mengolo@email.cm', '+237 655 13 14 15', 'INDIVIDUAL', 'ACTIVE', 'VERIFIED', 'MEDIUM', 'Douala Bonapriso', 'Contractor'),
(6, 'CUST-00006', 'SARL Douala Transport', 'dt@sarl.cm', '+237 233 55 66 77', 'BUSINESS', 'ACTIVE', 'VERIFIED', 'MEDIUM', 'Douala Centre-Ville', 'Logistics'),
(7, 'CUST-00007', 'Nadine Ngassa', 'n.ngassa@email.cm', '+237 655 16 17 18', 'INDIVIDUAL', 'ACTIVE', 'VERIFIED', 'LOW', 'Yaoundé Bastos', 'Accountant'),
(8, 'CUST-00008', 'Gilbert Tchoumba', 'g.tchoumba@email.cm', '+237 655 19 20 21', 'INDIVIDUAL', 'ACTIVE', 'VERIFIED', 'LOW', 'Douala Centre-Ville', 'Engineer');

-- ============================================================
-- SEED DATA: Accounts
-- ============================================================
INSERT IGNORE INTO accounts (id, account_number, customer_id, customer_name, product_type, branch, status, currency, ledger_balance, available_balance) VALUES
(1, 'ACC-200100001', 1, 'Paul Nkoulou Mebe', 'SALARY', 'Douala Centre-Ville', 'ACTIVE', 'XAF', 350000, 350000),
(2, 'ACC-200100002', 2, 'Marie-Claire Assamba', 'CURRENT', 'Douala Bonapriso', 'ACTIVE', 'XAF', 4200000, 4200000),
(3, 'ACC-200100003', 3, 'André Kamga Fotso', 'SALARY', 'Douala Centre-Ville', 'ACTIVE', 'XAF', 180000, 180000),
(4, 'ACC-200100004', 4, 'Chantal Biyong', 'SAVINGS', 'Yaoundé Bastos', 'ACTIVE', 'XAF', 1250000, 1250000),
(5, 'ACC-200100005', 5, 'François Mengolo', 'CURRENT', 'Douala Bonapriso', 'ACTIVE', 'XAF', 850000, 850000),
(6, 'ACC-200100006', 6, 'SARL Douala Transport', 'CORPORATE', 'Douala Centre-Ville', 'ACTIVE', 'XAF', 29900000, 29900000),
(7, 'ACC-200100007', 7, 'Nadine Ngassa', 'SALARY', 'Yaoundé Bastos', 'ACTIVE', 'XAF', 275000, 275000),
(8, 'ACC-200100008', 8, 'Gilbert Tchoumba', 'CURRENT', 'Douala Centre-Ville', 'ACTIVE', 'XAF', 3200000, 3200000);

-- ============================================================
-- SEED DATA: Bank Branding
-- ============================================================
INSERT IGNORE INTO bank_branding (id, bank_name, bank_name_short, tagline, primary_color, accent_color,
  head_office_address, phone, phone_alt, email, website, swift_code, cbn_license_number,
  registration_number, tax_identification_number, slogan)
VALUES (1, 'ATLAS BANK', 'Atlas Bank', 'Enterprise Operations Console', '#58b7ff', '#67e8b5',
  '45 Avenue de la Liberte, BP 12345, Douala, Littoral, Cameroon',
  '+237 233 42 15 00', '+237 699 00 00 01', 'info@atlasbank.cm', 'www.atlasbank.cm',
  'ATLSCMCD', 'CBN-LIC-2024-0847', 'RC-DC-2024-B0012', 'TIN-CM-91000-ATLAS',
  'Building Trust, Securing Futures');

-- ============================================================
-- SEED DATA: Chart of Accounts
-- ============================================================
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

-- ============================================================
-- SEED DATA: Operating Account
-- ============================================================
INSERT IGNORE INTO operating_account (id, account_number, account_name, balance, currency) VALUES
(1, 'OPS-000001', 'Atlas Bank Operating Account', 145000000, 'XAF');

-- ============================================================
-- SEED DATA: Policies
-- ============================================================
INSERT IGNORE INTO policies (code, version, name, effective_from, effective_to, description) VALUES
('KYC-POL-001', 1, 'Customer Due Diligence Policy', '2024-01-01', '2025-12-31',
 'Policy governing customer identification, verification, and ongoing due diligence procedures in compliance with CEMAC AML/CFT regulations.'),
('AML-POL-001', 2, 'Anti-Money Laundering Policy', '2024-01-01', '2025-12-31',
 'Comprehensive AML policy including transaction monitoring, suspicious activity reporting, and compliance with BEAC directives.'),
('LOAN-POL-001', 1, 'Credit Risk Management Policy', '2024-01-01', '2025-12-31',
 'Framework for credit assessment, loan approval processes, collateral requirements, and portfolio risk monitoring.'),
('IT-SEC-POL-001', 1, 'Information Security Policy', '2024-01-01', '2025-12-31',
 'Policy covering data protection, access controls, incident response, and cybersecurity measures for banking operations.');

-- ============================================================
-- SEED DATA: Settings
-- ============================================================
INSERT IGNORE INTO settings (`key`, name, value, category, description, requires_approval) VALUES
('FEE_WITHDRAWAL_COUNTER', 'Counter Withdrawal Fee (%)', '2', 'FEES', 'Percentage fee charged for counter withdrawals', 0),
('FEE_WITHDRAWAL_ATM', 'ATM Withdrawal Fee (XAF)', '200', 'FEES', 'Flat fee for ATM withdrawals', 0),
('FEE_TRANSFER_INTERNAL', 'Internal Transfer Fee (XAF)', '0', 'FEES', 'Fee for transfers within Atlas Bank', 0),
('FEE_TRANSFER_EXTERNAL', 'External Transfer Fee (XAF)', '500', 'FEES', 'Fee for transfers to other banks', 0),
('TAX_STAMP_DUTY', 'Stamp Duty (XAF)', '100', 'TAX', 'Stamp duty on financial transactions', 0),
('TAX_WITHHOLDING_RATE', 'Withholding Tax Rate (%)', '5.4', 'TAX', 'Standard withholding tax rate for interest and dividends', 0),
('OPR_MAX_LOGIN_ATTEMPTS', 'Max Login Attempts', '5', 'OPERATIONS', 'Maximum failed login attempts before lockout', 0),
('OPR_LOCKOUT_DURATION', 'Lockout Duration (minutes)', '30', 'OPERATIONS', 'Duration of account lockout after max failed attempts', 0),
('OPR_SESSION_TIMEOUT', 'Session Timeout (minutes)', '480', 'OPERATIONS', 'Session timeout duration', 0),
('OPR_MFA_REQUIRED', 'MFA Required', 'false', 'OPERATIONS', 'Whether MFA is required for login', 1),
('OPR_CURRENCY', 'Default Currency', 'XAF', 'OPERATIONS', 'Default currency code', 0);

-- ============================================================
-- VERIFICATION
-- ============================================================
SELECT '=== SETUP COMPLETE ===' AS status;
SELECT CONCAT(table_name, ' (', table_rows, ' rows)') AS `Tables Created`
FROM information_schema.tables
WHERE table_schema = 'atlas_bank'
ORDER BY table_name;
