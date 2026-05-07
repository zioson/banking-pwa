-- Migration: Fix generated_documents table for full document tracking
-- Run this SQL in phpMyAdmin or MySQL CLI

-- 1. Fix type enum to match frontend values
ALTER TABLE generated_documents MODIFY COLUMN type ENUM('STATEMENT','PAYSLIP','RECEIPT','REPORT','STMT','PAY','RCPT') NOT NULL;

-- 2. Fix status enum to match frontend values
ALTER TABLE generated_documents MODIFY COLUMN status ENUM('ACTIVE','VOIDED','DRAFT','FINAL','CANCELLED') DEFAULT 'ACTIVE';

-- 3. Add print tracking columns
ALTER TABLE generated_documents ADD COLUMN print_count INT DEFAULT 0;
ALTER TABLE generated_documents ADD COLUMN last_printed_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE generated_documents ADD COLUMN export_count INT DEFAULT 0;
ALTER TABLE generated_documents ADD COLUMN last_exported_at TIMESTAMP NULL DEFAULT NULL;

-- 4. Add subtype column for detailed document type
ALTER TABLE generated_documents ADD COLUMN subtype VARCHAR(50) DEFAULT NULL;

-- 5. Add customer_id column for linking
ALTER TABLE generated_documents ADD COLUMN customer_id INT DEFAULT NULL;

-- 6. Update any existing records to match new type values
UPDATE generated_documents SET type = 'STATEMENT' WHERE type = 'STMT';
UPDATE generated_documents SET type = 'PAYSLIP' WHERE type = 'PAY';
UPDATE generated_documents SET type = 'RECEIPT' WHERE type = 'RCPT';
UPDATE generated_documents SET status = 'ACTIVE' WHERE status = 'FINAL';
UPDATE generated_documents SET status = 'VOIDED' WHERE status = 'CANCELLED';
