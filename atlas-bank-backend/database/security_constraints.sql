-- ============================================================
-- Atlas Bank — Security & Integrity Constraints
-- Add these AFTER the main schema is imported
-- ============================================================

USE atlas_bank;

-- 1. Prevent negative available_balance (non-negative check)
-- ALTER TABLE accounts ADD CONSTRAINT chk_available_balance CHECK (available_balance >= 0);

-- 2. Prevent negative ledger_balance
-- ALTER TABLE accounts ADD CONSTRAINT chk_ledger_balance CHECK (ledger_balance >= 0);

-- 3. Prevent negative operating account balance
-- ALTER TABLE operating_account ADD CONSTRAINT chk_op_balance CHECK (balance >= 0);

-- 4. Prevent negative loan outstanding
-- ALTER TABLE loans ADD CONSTRAINT chk_loan_outstanding CHECK (outstanding >= 0);

-- 5. Prevent negative loan principal
-- ALTER TABLE loans ADD CONSTRAINT chk_loan_principal CHECK (principal > 0);

-- 6. Prevent negative transaction amount
-- ALTER TABLE transactions ADD CONSTRAINT chk_txn_amount CHECK (amount >= 0);

-- 7. Add foreign key for operator_id in transactions
-- ALTER TABLE transactions ADD CONSTRAINT fk_txn_operator FOREIGN KEY (operator_id) REFERENCES staff(id) ON DELETE SET NULL;

-- 8. Add foreign key for approved_by in transactions
-- ALTER TABLE transactions ADD CONSTRAINT fk_txn_approver FOREIGN KEY (approved_by) REFERENCES staff(id) ON DELETE SET NULL;

-- 9. Add foreign key for operator_id in expenses
-- ALTER TABLE expenses ADD CONSTRAINT fk_exp_operator FOREIGN KEY (operator_id) REFERENCES staff(id) ON DELETE SET NULL;

-- 10. Add foreign key for approved_by in expenses
-- ALTER TABLE expenses ADD CONSTRAINT fk_exp_approver FOREIGN KEY (approved_by) REFERENCES staff(id) ON DELETE SET NULL;

-- ============================================================
-- Audit Log Protection: Read-only for non-admin users
-- ============================================================
-- Create a dedicated MySQL user for the application with limited privileges:
-- 
-- CREATE USER 'atlas_app'@'localhost' IDENTIFIED BY 'STRONG_RANDOM_PASSWORD_HERE';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.accounts TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.account_tax_exemptions TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.approvals TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.balance_trends TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.branches TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.chart_of_accounts TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.customer_products TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.customers TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.expenses TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.generated_documents TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.loan_application_checks TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.loan_applications TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.loan_schedule TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.loans TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.notifications TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.operating_account TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.operating_account_transactions TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE ON atlas_bank.policies TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE ON atlas_bank.profit_ledger TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.settings TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.staff TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, DELETE ON atlas_bank.staff_branches TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, DELETE ON atlas_bank.staff_modules TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.transaction_deductions TO 'atlas_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON atlas_bank.transactions TO 'atlas_app'@'localhost';
-- GRANT SELECT ON atlas_bank.audit_logs TO 'atlas_app'@'localhost';  -- READ ONLY!
-- GRANT SELECT ON atlas_bank.audit_findings TO 'atlas_app'@'localhost';  -- READ ONLY!
-- GRANT SELECT ON atlas_bank.login_history TO 'atlas_app'@'localhost';  -- READ ONLY!
-- GRANT SELECT, INSERT, DELETE ON atlas_bank.sessions TO 'atlas_app'@'localhost';
-- GRANT SELECT ON atlas_bank.bank_branding TO 'atlas_app'@'localhost';
-- FLUSH PRIVILEGES;

-- ============================================================
-- Encryption at Rest for PII (MySQL 8.0)
-- ============================================================
-- Install MySQL encryption functions:
-- https://github.com/mysql/mysql-server/tree/8.0/components/mysql_encryption

-- Example: Encrypt sensitive columns using AES-256
-- UPDATE customers SET phone = TO_BASE64(AES_ENCRYPT(phone, 'YOUR_ENCRYPTION_KEY_256BIT'));
-- UPDATE customers SET email = TO_BASE64(AES_ENCRYPT(email, 'YOUR_ENCRYPTION_KEY_256BIT'));
-- UPDATE staff SET phone = TO_BASE64(AES_ENCRYPT(phone, 'YOUR_ENCRYPTION_KEY_256BIT'));
-- UPDATE staff SET email = TO_BASE64(AES_ENCRYPT(email, 'YOUR_ENCRYPTION_KEY_256BIT'));

-- Then create views for application access:
-- CREATE VIEW v_customers AS
--   SELECT id, customer_number, customer_type, full_name, status, risk_rating, branch,
--          CAST(AES_DECRYPT(FROM_BASE64(phone), 'YOUR_ENCRYPTION_KEY_256BIT') AS CHAR(50)) AS phone,
--          CAST(AES_DECRYPT(FROM_BASE64(email), 'YOUR_ENCRYPTION_KEY_256BIT') AS CHAR(200)) AS email,
--          relationship_started, next_action, kyc_verified, created_at, updated_at
--   FROM customers;

-- ============================================================
-- Database Triggers for Audit Trail Integrity
-- ============================================================

DELIMITER //

-- Prevent deletion of audit logs
CREATE TRIGGER trg_audit_logs_no_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs cannot be deleted. This action has been logged.';
END//

-- Prevent update of audit logs (immutable)
CREATE TRIGGER trg_audit_logs_no_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
    IF OLD.details != NEW.details OR OLD.result != NEW.result OR OLD.action != NEW.action THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are immutable and cannot be modified.';
    END IF;
END//

-- Auto-cleanup expired sessions (run via event scheduler)
-- CREATE EVENT IF NOT EXISTS evt_cleanup_sessions
-- ON SCHEDULE EVERY 1 HOUR
-- DO
--   DELETE FROM sessions WHERE expires_at < NOW();

DELIMITER ;

-- ============================================================
-- Enable Event Scheduler for session cleanup
-- ============================================================
SET GLOBAL event_scheduler = ON;
