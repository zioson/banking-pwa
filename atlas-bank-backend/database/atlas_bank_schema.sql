-- ============================================================
-- Atlas Bank Enterprise Operations Console
-- Complete Database Schema - MySQL 8.0
-- ============================================================

CREATE DATABASE IF NOT EXISTS atlas_bank
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE atlas_bank;

-- -----------------------------------------------------------
-- Table: bank_branding
-- Stores bank branding and corporate information
-- -----------------------------------------------------------
DROP TABLE IF EXISTS bank_branding;
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

-- -----------------------------------------------------------
-- Table: branches
-- Bank branch locations
-- -----------------------------------------------------------
DROP TABLE IF EXISTS branches;
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
  INDEX idx_status (status),
  INDEX idx_region (region)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: staff
-- Bank employees and their authentication details
-- -----------------------------------------------------------
DROP TABLE IF EXISTS staff_branches;
DROP TABLE IF EXISTS staff_modules;
DROP TABLE IF EXISTS staff;
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

-- -----------------------------------------------------------
-- Table: staff_branches
-- Many-to-Many mapping of staff to branches
-- -----------------------------------------------------------
DROP TABLE IF EXISTS staff_branches;
CREATE TABLE staff_branches (
  staff_id INT NOT NULL,
  branch_name VARCHAR(200) NOT NULL,
  PRIMARY KEY (staff_id, branch_name),
  FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
  INDEX idx_branch (branch_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: staff_modules
-- Role-Based Access Control - Many-to-Many mapping of staff to modules
-- -----------------------------------------------------------
DROP TABLE IF EXISTS staff_modules;
CREATE TABLE staff_modules (
  staff_id INT NOT NULL,
  module_name VARCHAR(50) NOT NULL,
  PRIMARY KEY (staff_id, module_name),
  FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
  INDEX idx_module (module_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: customers
-- Bank customers (individuals and businesses)
-- -----------------------------------------------------------
DROP TABLE IF EXISTS customer_products;
DROP TABLE IF EXISTS customers;
CREATE TABLE customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_number VARCHAR(20) NOT NULL UNIQUE,
  customer_type ENUM('INDIVIDUAL','BUSINESS') NOT NULL DEFAULT 'INDIVIDUAL',
  full_name VARCHAR(200) NOT NULL,
  status ENUM('DRAFT','PENDING_KYC','ACTIVE','RESTRICTED','FROZEN','CLOSED') DEFAULT 'DRAFT',
  risk_rating ENUM('LOW','MEDIUM','HIGH','CRITICAL') DEFAULT 'MEDIUM',
  branch VARCHAR(200),
  relationship_started DATE,
  next_action TEXT,
  phone VARCHAR(50),
  email VARCHAR(200),
  kyc_document LONGTEXT,
  kyc_verified TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_customer_number (customer_number),
  INDEX idx_status (status),
  INDEX idx_branch (branch),
  INDEX idx_name (full_name),
  INDEX idx_risk (risk_rating)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: customer_products
-- Products held by each customer
-- -----------------------------------------------------------
DROP TABLE IF EXISTS customer_products;
CREATE TABLE customer_products (
  customer_id INT NOT NULL,
  product_name VARCHAR(50) NOT NULL,
  PRIMARY KEY (customer_id, product_name),
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: accounts
-- Customer bank accounts
-- -----------------------------------------------------------
DROP TABLE IF EXISTS account_tax_exemptions;
DROP TABLE IF EXISTS accounts;
CREATE TABLE accounts (
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
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  INDEX idx_account_number (account_number),
  INDEX idx_customer_id (customer_id),
  INDEX idx_product_type (product_type),
  INDEX idx_status (status),
  INDEX idx_branch (branch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: account_tax_exemptions
-- Per-account tax exemption overrides
-- -----------------------------------------------------------
DROP TABLE IF EXISTS account_tax_exemptions;
CREATE TABLE account_tax_exemptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  tax_key VARCHAR(100) NOT NULL,
  tax_name VARCHAR(200),
  is_exempt TINYINT(1) DEFAULT 0,
  reason TEXT,
  exempted_by INT,
  exempted_at TIMESTAMP NULL,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  FOREIGN KEY (exempted_by) REFERENCES staff(id),
  UNIQUE KEY uk_account_tax (account_id, tax_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: transactions
-- All financial transactions
-- -----------------------------------------------------------
DROP TABLE IF EXISTS transaction_deductions;
DROP TABLE IF EXISTS transactions;
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

-- -----------------------------------------------------------
-- Table: transaction_deductions
-- Breakdown of deductions for transactions (e.g., salary withdrawals)
-- -----------------------------------------------------------
DROP TABLE IF EXISTS transaction_deductions;
CREATE TABLE transaction_deductions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transaction_id INT NOT NULL,
  deduction_key VARCHAR(100) NOT NULL,
  deduction_name VARCHAR(200),
  deduction_type ENUM('TAX','FEE','CONTRIBUTION','DEDUCTION') DEFAULT 'TAX',
  rate DECIMAL(10,4) DEFAULT 0,
  amount DECIMAL(20,2) DEFAULT 0,
  is_exempt TINYINT(1) DEFAULT 0,
  FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
  INDEX idx_transaction (transaction_id),
  INDEX idx_deduction_key (deduction_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: loans
-- Loan accounts
-- -----------------------------------------------------------
DROP TABLE IF EXISTS loan_schedule;
DROP TABLE IF EXISTS loan_application_checks;
DROP TABLE IF EXISTS loan_applications;
DROP TABLE IF EXISTS loans;
CREATE TABLE loans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loan_number VARCHAR(30) NOT NULL UNIQUE,
  customer_id INT NOT NULL,
  customer_name VARCHAR(200),
  branch VARCHAR(200),
  status ENUM('PENDING','ACTIVE','DELINQUENT','CLOSED','WRITTEN_OFF','RESTRUCTURED') DEFAULT 'PENDING',
  principal DECIMAL(20,2) NOT NULL,
  outstanding DECIMAL(20,2) DEFAULT 0,
  accrued_interest DECIMAL(20,2) DEFAULT 0,
  interest_rate DECIMAL(10,2) NOT NULL,
  term_months INT NOT NULL,
  repayment_freq ENUM('Weekly','Bi-Weekly','Monthly','Quarterly') DEFAULT 'Monthly',
  disbursed_at DATE,
  maturity_date DATE,
  next_due DATE,
  debit_account_id INT,
  debit_account_number VARCHAR(30),
  source TEXT,
  product_type VARCHAR(50),
  repayment_mode ENUM('MANUAL','SCHEDULED') DEFAULT 'SCHEDULED',
  repayment_amount DECIMAL(20,2) DEFAULT 0,
  repayment_pct DECIMAL(5,2) DEFAULT 0,
  auto_deduct TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  INDEX idx_loan_number (loan_number),
  INDEX idx_customer_id (customer_id),
  INDEX idx_status (status),
  INDEX idx_branch (branch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: loan_applications
-- Loan applications awaiting or past decision
-- -----------------------------------------------------------
DROP TABLE IF EXISTS loan_application_checks;
CREATE TABLE loan_applications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(30) NOT NULL UNIQUE,
  customer_id INT NOT NULL,
  customer_name VARCHAR(200),
  amount DECIMAL(20,2) NOT NULL,
  term INT NOT NULL,
  purpose TEXT,
  status ENUM('PENDING','UNDER_REVIEW','APPROVED','REJECTED','WITHDRAWN') DEFAULT 'PENDING',
  branch VARCHAR(200),
  applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  decided_by INT,
  decided_at TIMESTAMP NULL,
  decision_reason TEXT,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  INDEX idx_ref (ref),
  INDEX idx_status (status),
  INDEX idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: loan_application_checks
-- Checklist items for loan application review
-- -----------------------------------------------------------
DROP TABLE IF EXISTS loan_application_checks;
CREATE TABLE loan_application_checks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(200),
  status ENUM('PENDING','PASSED','FAILED','WAIVED') DEFAULT 'PENDING',
  FOREIGN KEY (application_id) REFERENCES loan_applications(id) ON DELETE CASCADE,
  INDEX idx_application (application_id),
  INDEX idx_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: loan_schedule
-- Repayment schedule for loans
-- -----------------------------------------------------------
DROP TABLE IF EXISTS loan_schedule;
CREATE TABLE loan_schedule (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loan_id INT NOT NULL,
  installment INT NOT NULL,
  due DATE NOT NULL,
  principal DECIMAL(20,2) NOT NULL,
  interest DECIMAL(20,2) NOT NULL,
  paid DECIMAL(20,2) DEFAULT 0,
  status ENUM('DUE','PAID','MISSED','PARTIALLY_PAID','WAIVED') DEFAULT 'DUE',
  paid_at TIMESTAMP NULL,
  FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
  INDEX idx_loan_due (loan_id, due),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: approvals
-- Multi-level approval workflow
-- -----------------------------------------------------------
DROP TABLE IF EXISTS approvals;
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
  decided_at TIMESTAMP NULL,
  reason TEXT,
  INDEX idx_status (status),
  INDEX idx_entity (entity_type, entity_id),
  INDEX idx_scope (scope_code),
  INDEX idx_submitted (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: audit_logs
-- Comprehensive audit trail
-- -----------------------------------------------------------
DROP TABLE IF EXISTS audit_logs;
CREATE TABLE audit_logs (
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

-- -----------------------------------------------------------
-- Table: login_history
-- Tracks all login attempts for security monitoring
-- -----------------------------------------------------------
DROP TABLE IF EXISTS login_history;
CREATE TABLE login_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50),
  result ENUM('SUCCESS','FAILURE','LOCKED') DEFAULT 'SUCCESS',
  ip VARCHAR(50),
  user_agent VARCHAR(500),
  risk ENUM('NONE','LOW','MEDIUM','HIGH','CRITICAL') DEFAULT 'NONE',
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_username (username),
  INDEX idx_timestamp (timestamp),
  INDEX idx_result (result)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: notifications
-- In-app notifications for staff
-- -----------------------------------------------------------
DROP TABLE IF EXISTS notifications;
CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(50) NOT NULL,
  title VARCHAR(300) NOT NULL,
  body TEXT,
  status ENUM('PENDING','READ','ARCHIVED') DEFAULT 'PENDING',
  channel VARCHAR(50) DEFAULT 'IN_APP',
  target_staff_id INT,
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_type (type),
  INDEX idx_status (status),
  INDEX idx_target (target_staff_id),
  INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: settings
-- System-wide configuration and fee/tax settings
-- -----------------------------------------------------------
DROP TABLE IF EXISTS settings;
CREATE TABLE settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(200) NOT NULL,
  category VARCHAR(100),
  value TEXT,
  description TEXT,
  effective_from DATE,
  requires_approval TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_key (`key`),
  INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: chart_of_accounts
-- General ledger chart of accounts
-- -----------------------------------------------------------
DROP TABLE IF EXISTS chart_of_accounts;
CREATE TABLE chart_of_accounts (
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

-- -----------------------------------------------------------
-- Table: expenses
-- Bank operating expenses
-- -----------------------------------------------------------
DROP TABLE IF EXISTS expenses;
CREATE TABLE expenses (
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

-- -----------------------------------------------------------
-- Table: operating_account
-- Bank's own operating account(s)
-- -----------------------------------------------------------
DROP TABLE IF EXISTS operating_account_transactions;
DROP TABLE IF EXISTS operating_account;
CREATE TABLE operating_account (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_number VARCHAR(30) NOT NULL UNIQUE,
  account_name VARCHAR(200),
  balance DECIMAL(20,2) DEFAULT 0,
  currency VARCHAR(5) DEFAULT 'XAF',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: operating_account_transactions
-- Transaction history for operating accounts
-- -----------------------------------------------------------
DROP TABLE IF EXISTS operating_account_transactions;
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
  FOREIGN KEY (operating_account_id) REFERENCES operating_account(id),
  INDEX idx_date (date),
  INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: generated_documents
-- Records of generated statements, receipts, and reports
-- -----------------------------------------------------------
DROP TABLE IF EXISTS generated_documents;
CREATE TABLE generated_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  document_number VARCHAR(50) NOT NULL UNIQUE,
  type ENUM('STMT','PAY','RCPT','REPORT') NOT NULL,
  account_number VARCHAR(30),
  account_type VARCHAR(50),
  customer_name VARCHAR(200),
  branch VARCHAR(200),
  period_start DATE,
  period_end DATE,
  generated_by INT,
  generated_by_name VARCHAR(200),
  status ENUM('DRAFT','FINAL','CANCELLED') DEFAULT 'FINAL',
  content LONGTEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_document_number (document_number),
  INDEX idx_type (type),
  INDEX idx_account (account_number),
  INDEX idx_customer (customer_name),
  INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: profit_ledger
-- Profit & Loss aggregated data by GL accounts
-- -----------------------------------------------------------
DROP TABLE IF EXISTS profit_ledger;
CREATE TABLE profit_ledger (
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

-- -----------------------------------------------------------
-- Table: policies
-- Bank policies and compliance documents
-- -----------------------------------------------------------
DROP TABLE IF EXISTS policies;
CREATE TABLE policies (
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

-- -----------------------------------------------------------
-- Table: audit_findings
-- Internal audit findings and remediation tracking
-- -----------------------------------------------------------
DROP TABLE IF EXISTS audit_findings;
CREATE TABLE audit_findings (
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
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_severity (severity),
  INDEX idx_status (status),
  INDEX idx_branch (branch),
  INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: sessions
-- Active user sessions for authentication
-- -----------------------------------------------------------
DROP TABLE IF EXISTS sessions;
CREATE TABLE sessions (
  id VARCHAR(128) PRIMARY KEY,
  staff_id INT NOT NULL,
  ip_address VARCHAR(50),
  user_agent VARCHAR(500),
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
  INDEX idx_staff (staff_id),
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: balance_trends
-- Daily balance snapshots per product type for reporting
-- -----------------------------------------------------------
DROP TABLE IF EXISTS balance_trends;
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

-- ============================================================
-- End of Atlas Bank Schema
-- ============================================================
