<?php
/**
 * Atlas Bank Enterprise Operations Console
 * Application Constants
 */

// -----------------------------------------------------------
// API
// -----------------------------------------------------------
define('API_VERSION', '1.0.0');
define('API_PREFIX', '/api');

// -----------------------------------------------------------
// Security
// -----------------------------------------------------------
// ★ SECURITY FIX: Key for encrypting sensitive data at rest (MFA secrets, PII).
// In production, this MUST be moved to an environment variable or secret manager.
define('DATA_ENCRYPTION_KEY', '7fb493c7d6b84e8a9f3e1c0d8b2a5f6e'); 

// -----------------------------------------------------------
// Modules (18 modules matching MODULE_VIEW_MAP)
// -----------------------------------------------------------
// ★ FIX (FIN-2b-027): Added missing modules that exist in router but weren't in MODULES
define('MODULES', [
    'DASHBOARD',
    'CUSTOMERS',
    'ACCOUNTS',
    'TRANSACTIONS',
    'LOANS',
    'TRANSFERS',
    'REPORTS',
    'BRANCHES',
    'STAFF',
    'SETTINGS',
    'APPROVALS',
    'AUDIT',
    'NOTIFICATIONS',
    'DOCUMENTS',
    'CHART_OF_ACCOUNTS',
    'GL_ACCOUNTS',
    'EXPENSES',
    'POLICIES',
    'OPERATING_ACCOUNT',
    'LOAN_APPLICATIONS',
    'LOAN_FUND_ACCOUNTS',
    'OPERATING_FUND',
    'GENERAL_LEDGER'
]);

// Module display names (human-readable)
define('MODULE_LABELS', [
    'DASHBOARD'             => 'Dashboard',
    'CUSTOMERS'             => 'Customers',
    'ACCOUNTS'              => 'Accounts',
    'TRANSACTIONS'          => 'Transactions',
    'LOANS'                 => 'Loans',
    'TRANSFERS'             => 'Transfers',
    'REPORTS'               => 'Reports',
    'BRANCHES'              => 'Branches',
    'STAFF'                 => 'Staff Management',
    'SETTINGS'              => 'Settings',
    'APPROVALS'             => 'Approvals',
    'AUDIT'                 => 'Audit',
    'NOTIFICATIONS'         => 'Notifications',
    'DOCUMENTS'             => 'Documents',
    'CHART_OF_ACCOUNTS'     => 'Chart of Accounts',
    'GL_ACCOUNTS'           => 'GL Accounts',
    'EXPENSES'              => 'Expenses',
    'POLICIES'              => 'Policies',
    'OPERATING_ACCOUNT'     => 'Operating Account',
    'LOAN_APPLICATIONS'     => 'Loan Applications',
    'LOAN_FUND_ACCOUNTS'    => 'Loan Fund Accounts',
    'OPERATING_FUND'        => 'Operating Fund',
    'GENERAL_LEDGER'        => 'General Ledger'
]);

// -----------------------------------------------------------
// Account Product Types
// -----------------------------------------------------------
define('ACCOUNT_TYPES', [
    'SALARY'        => 'Salary Account',
    'CURRENT'       => 'Current Account',
    'SAVINGS'       => 'Savings Account',
    'FIXED_DEPOSIT' => 'Fixed Deposit',
    'CALL_DEPOSIT'  => 'Call Deposit',
    'LOAN'          => 'Loan Account',
    'TREASURY'      => 'Treasury Account',
    'FOREX'         => 'Forex Account',
    'ESCROW'        => 'Escrow Account',
    'JOINT'         => 'Joint Account',
    'CORPORATE'     => 'Corporate Account',
    'ISLAMIC'       => 'Islamic Account'
]);

// -----------------------------------------------------------
// Transaction Types
// -----------------------------------------------------------
define('TRANSACTION_TYPES', [
    'DEPOSIT',
    'WITHDRAWAL',
    'TRANSFER',
    'SALARY_PAYMENT',
    'SALARY_WITHDRAWAL',
    'FEE_COLLECTION',
    'LOAN_DISBURSEMENT',
    'LOAN_REPAYMENT',
    'FOREX_PURCHASE',
    'FOREX_SALE',
    'TAX_PAYMENT',
    'REVERSAL'
]);

// -----------------------------------------------------------
// Transaction Statuses
// -----------------------------------------------------------
define('TRANSACTION_STATUSES', [
    'PENDING',
    'PENDING_APPROVAL',
    'POSTED',
    'FAILED',
    'REVERSED',
    'CANCELLED'
]);

// -----------------------------------------------------------
// Loan Statuses
// -----------------------------------------------------------
define('LOAN_STATUSES', [
    'PENDING',
    'ACTIVE',
    'DELINQUENT',
    'CLOSED',
    'WRITTEN_OFF',
    'RESTRUCTURED'
]);

// -----------------------------------------------------------
// Loan Application Statuses
// -----------------------------------------------------------
define('LOAN_APPLICATION_STATUSES', [
    'PENDING',
    'UNDER_REVIEW',
    'APPROVED',
    'REJECTED',
    'WITHDRAWN'
]);

// -----------------------------------------------------------
// Customer Statuses
// -----------------------------------------------------------
define('CUSTOMER_STATUSES', [
    'DRAFT',
    'PENDING_KYC',
    'ACTIVE',
    'RESTRICTED',
    'FROZEN',
    'CLOSED'
]);

// -----------------------------------------------------------
// Customer Types
// -----------------------------------------------------------
define('CUSTOMER_TYPES', [
    'INDIVIDUAL',
    'BUSINESS'
]);

// -----------------------------------------------------------
// Risk Ratings
// -----------------------------------------------------------
define('RISK_RATINGS', [
    'LOW',
    'MEDIUM',
    'HIGH',
    'CRITICAL'
]);

// -----------------------------------------------------------
// Account Statuses
// -----------------------------------------------------------
define('ACCOUNT_STATUSES', [
    'PENDING_OPENING',
    'ACTIVE',
    'FROZEN',
    'DORMANT',
    'CLOSED',
    'RESTRICTED'
]);

// -----------------------------------------------------------
// Staff Roles
// -----------------------------------------------------------
define('STAFF_ROLES', [
    'ADMIN',
    'SUPERVISOR',
    'TELLER',
    'AUDITOR',
    'COMPLIANCE'
]);

// -----------------------------------------------------------
// Employment Statuses
// -----------------------------------------------------------
define('EMPLOYMENT_STATUSES', [
    'ACTIVE',
    'INACTIVE',
    'TERMINATED',
    'SUSPENDED'
]);

// -----------------------------------------------------------
// Approval Statuses
// -----------------------------------------------------------
define('APPROVAL_STATUSES', [
    'PENDING',
    'APPROVED',
    'REJECTED',
    'CANCELLED'
]);

// -----------------------------------------------------------
// Branch Statuses
// -----------------------------------------------------------
define('BRANCH_STATUSES', [
    'ACTIVE',
    'INACTIVE',
    'CLOSED'
]);

// -----------------------------------------------------------
// Notification Channels
// -----------------------------------------------------------
define('NOTIFICATION_CHANNELS', [
    'IN_APP',
    'EMAIL',
    'SMS',
    'PUSH'
]);

// -----------------------------------------------------------
// Notification Statuses
// -----------------------------------------------------------
define('NOTIFICATION_STATUSES', [
    'PENDING',
    'READ',
    'ARCHIVED'
]);

// -----------------------------------------------------------
// Expense Statuses
// -----------------------------------------------------------
define('EXPENSE_STATUSES', [
    'PENDING',
    'APPROVED',
    'REJECTED'
]);

// -----------------------------------------------------------
// Document Types
// -----------------------------------------------------------
define('DOCUMENT_TYPES', [
    'STMT'   => 'Bank Statement',
    'PAY'    => 'Payment Voucher',
    'RCPT'   => 'Receipt',
    'REPORT' => 'Report'
]);

// -----------------------------------------------------------
// Deduction Types
// -----------------------------------------------------------
define('DEDUCTION_TYPES', [
    'TAX',
    'FEE',
    'CONTRIBUTION',
    'DEDUCTION'
]);

// -----------------------------------------------------------
// Cameroon Tax Setting Keys
// -----------------------------------------------------------
define('TAX_SETTINGS', [
    'TAX_IR_RATE',
    'TAX_IR_THRESHOLD',
    'TAX_CNPS_EMPLOYEE_RATE',
    'TAX_CNPS_EMPLOYER_RATE',
    'TAX_CNPS_CEILING',
    'TAX_REGISTRATION',
    'TAX_STAMP_DUTY',
    'TAX_VAT_RATE',
    'TAX_WITHHOLDING_RATE',
    'TAX_DIVIDEND_RATE',
    'TAX_INTEREST_RATE'
]);

// -----------------------------------------------------------
// Fee Setting Keys
// -----------------------------------------------------------
define('FEE_SETTINGS', [
    'FEE_TRANSFER_INTERNAL',
    'FEE_TRANSFER_EXTERNAL',
    'FEE_TRANSFER_HIGH_VALUE',
    'FEE_TRANSFER_HIGH_VALUE_RATE',
    'FEE_WITHDRAWAL_COUNTER',
    'FEE_WITHDRAWAL_ATM',
    'FEE_DEPOSIT_CASH',
    'FEE_DEPOSIT_CHEQUE',
    'FEE_FOREX_PURCHASE',
    'FEE_FOREX_SPREAD',
    'FEE_ACCOUNT_MAINTENANCE',
    'FEE_STATEMENT_REQUEST',
    'FEE_LOAN_PROCESSING',
    'FEE_LOAN_LATE_PAYMENT',
    'FEE_CARD_ISSUANCE',
    'FEE_CARD_REPLACEMENT',
    'FEE_SMS_ALERT'
]);

// -----------------------------------------------------------
// Operation Setting Keys
// -----------------------------------------------------------
define('OPERATION_SETTINGS', [
    'OPR_MAX_LOGIN_ATTEMPTS',
    'OPR_LOCKOUT_DURATION',
    'OPR_SESSION_TIMEOUT',
    'OPR_SESSION_TIMEOUT_WARN',
    'OPR_MFA_REQUIRED',
    'OPR_PASSWORD_MIN_LENGTH',
    'OPR_DAILY_TRANSFER_LIMIT',
    'OPR_CURRENCY'
]);

// -----------------------------------------------------------
// Audit Result Types
// -----------------------------------------------------------
define('AUDIT_RESULTS', [
    'SUCCESS',
    'FAILURE',
    'DENIED'
]);

// -----------------------------------------------------------
// Login Result Types
// -----------------------------------------------------------
define('LOGIN_RESULTS', [
    'SUCCESS',
    'FAILURE',
    'LOCKED'
]);

// -----------------------------------------------------------
// Risk Levels
// -----------------------------------------------------------
define('RISK_LEVELS', [
    'NONE',
    'LOW',
    'MEDIUM',
    'HIGH',
    'CRITICAL'
]);

// -----------------------------------------------------------
// Settings Categories
// -----------------------------------------------------------
define('SETTING_CATEGORIES', [
    'FEES',
    'TAX',
    'OPERATIONS'
]);

// -----------------------------------------------------------
// Expense Categories
// -----------------------------------------------------------
define('EXPENSE_CATEGORIES', [
    'UTILITIES',
    'OFFICE_SUPPLIES',
    'IT_SERVICES',
    'MAINTENANCE',
    'TRANSPORT',
    'TRAINING',
    'PROFESSIONAL',
    'MARKETING',
    'LEGAL',
    'INSURANCE'
]);

// -----------------------------------------------------------
// Repayment Frequencies
// -----------------------------------------------------------
// ★ FIX (FIN-2b-026): Changed from Title Case to UPPER_SNAKE_CASE for consistency
define('REPAYMENT_FREQUENCIES', [
    'WEEKLY',
    'BI_WEEKLY',
    'MONTHLY',
    'QUARTERLY'
]);

// -----------------------------------------------------------
// Repayment Modes
// -----------------------------------------------------------
define('REPAYMENT_MODES', [
    'MANUAL',
    'SCHEDULED'
]);

// -----------------------------------------------------------
// Audit Finding Severities
// -----------------------------------------------------------
define('AUDIT_SEVERITIES', [
    'LOW',
    'MEDIUM',
    'HIGH',
    'CRITICAL'
]);

// -----------------------------------------------------------
// Audit Finding Statuses
// -----------------------------------------------------------
define('AUDIT_FINDING_STATUSES', [
    'OPEN',
    'IN_PROGRESS',
    'RESOLVED',
    'CLOSED'
]);

// -----------------------------------------------------------
// Loan Schedule Statuses
// -----------------------------------------------------------
define('LOAN_SCHEDULE_STATUSES', [
    'DUE',
    'PAID',
    'MISSED',
    'PARTIALLY_PAID',
    'WAIVED'
]);

// -----------------------------------------------------------
// Loan Application Check Statuses
// -----------------------------------------------------------
define('APPLICATION_CHECK_STATUSES', [
    'PENDING',
    'PASSED',
    'FAILED',
    'WAIVED'
]);

// -----------------------------------------------------------
// Default Pagination
// -----------------------------------------------------------
define('DEFAULT_PAGE_SIZE', 20);
define('MAX_PAGE_SIZE', 100);

// -----------------------------------------------------------
// Default Currency
// -----------------------------------------------------------
if (!defined('DEFAULT_CURRENCY')) define('DEFAULT_CURRENCY', 'XAF');
if (!defined('CURRENCY_SYMBOL')) define('CURRENCY_SYMBOL', 'FCFA');

// -----------------------------------------------------------
// Country Code
// -----------------------------------------------------------
define('DEFAULT_COUNTRY', 'CM');

// -----------------------------------------------------------
// Reference Prefixes
// -----------------------------------------------------------
define('REF_PREFIX_TRANSACTION', 'TXN');
define('REF_PREFIX_DOCUMENT_STATEMENT', 'STMT');
define('REF_PREFIX_DOCUMENT_PAYMENT', 'PAY');
define('REF_PREFIX_DOCUMENT_RECEIPT', 'RCPT');
define('REF_PREFIX_LOAN_APPLICATION', 'LA');
define('REF_PREFIX_LOAN', 'LN');
define('REF_PREFIX_AUDIT', 'AUD');
define('REF_PREFIX_CUSTOMER', 'CUST');
define('REF_PREFIX_ACCOUNT', 'ACC');
define('REF_PREFIX_OPERATING_TXN', 'OAT');
