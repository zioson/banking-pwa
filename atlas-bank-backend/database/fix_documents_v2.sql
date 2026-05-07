-- ============================================================
-- Atlas Bank — generated_documents table fix (v2)
-- Run in phpMyAdmin or MySQL CLI
-- ============================================================

-- 1. Fix type ENUM to support both old and new values
ALTER TABLE generated_documents MODIFY COLUMN type ENUM('STATEMENT','PAYSLIP','RECEIPT','REPORT','STMT','PAY','RCPT') NOT NULL;

-- 2. Fix status ENUM to support ACTIVE/VOIDED
ALTER TABLE generated_documents MODIFY COLUMN status ENUM('ACTIVE','VOIDED','DRAFT','FINAL','CANCELLED') DEFAULT 'ACTIVE';

-- 3. Add missing columns (may fail with #1060 if already exists — that is OK, skip those)
ALTER TABLE generated_documents ADD COLUMN print_count INT DEFAULT 0;
ALTER TABLE generated_documents ADD COLUMN last_printed_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE generated_documents ADD COLUMN export_count INT DEFAULT 0;
ALTER TABLE generated_documents ADD COLUMN last_exported_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE generated_documents ADD COLUMN subtype VARCHAR(50) DEFAULT NULL;
ALTER TABLE generated_documents ADD COLUMN customer_id INT DEFAULT NULL;

-- 4. Normalize existing type values
UPDATE generated_documents SET type = 'STATEMENT' WHERE type = 'STMT';
UPDATE generated_documents SET type = 'PAYSLIP' WHERE type = 'PAY';
UPDATE generated_documents SET type = 'RECEIPT' WHERE type = 'RCPT';

-- 5. Normalize existing status values
UPDATE generated_documents SET status = 'ACTIVE' WHERE status = 'FINAL';
UPDATE generated_documents SET status = 'VOIDED' WHERE status = 'CANCELLED';

-- 6. Fix any 0000-00-00 dates to NULL
UPDATE generated_documents SET period_start = NULL WHERE period_start = '0000-00-00';
UPDATE generated_documents SET period_end = NULL WHERE period_end = '0000-00-00';
