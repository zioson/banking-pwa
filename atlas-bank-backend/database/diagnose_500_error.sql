-- ================================================================
-- Atlas Bank — Diagnose 500 Errors on POST /api/transactions
-- Run this in phpMyAdmin or MySQL CLI on the atlas_bank database
-- This script checks for common issues that cause 500 errors.
-- ================================================================

USE atlas_bank;

-- ============================================================
-- CHECK 1: Verify all required tables exist
-- ============================================================
SELECT 'TABLE CHECK' AS check_type,
  CASE WHEN COUNT(*) = 0 THEN 'ALL TABLES EXIST' ELSE 'MISSING TABLES!' END AS result
FROM information_schema.tables t
WHERE t.table_schema = 'atlas_bank'
  AND t.table_name IN ('transactions','accounts','customers','staff','sessions',
    'generated_documents','audit_logs','approvals','staff_modules','staff_branches')
HAVING COUNT(*) < 10;

-- List all actual tables in the database
SELECT table_name, table_rows, create_time
FROM information_schema.tables
WHERE table_schema = 'atlas_bank'
ORDER BY table_name;

-- ============================================================
-- CHECK 2: Verify transactions table columns
-- ============================================================
SELECT column_name, column_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = 'atlas_bank' AND table_name = 'transactions'
ORDER BY ordinal_position;

-- ============================================================
-- CHECK 3: Verify accounts table columns
-- ============================================================
SELECT column_name, column_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = 'atlas_bank' AND table_name = 'accounts'
ORDER BY ordinal_position;

-- ============================================================
-- CHECK 4: Check for recent transactions (last 10)
-- ============================================================
SELECT id, ref, type, status, direction, amount, account, created_at
FROM transactions
ORDER BY id DESC
LIMIT 10;

-- ============================================================
-- CHECK 5: Verify audit_logs table exists and is writable
-- ============================================================
SELECT column_name, column_type
FROM information_schema.columns
WHERE table_schema = 'atlas_bank' AND table_name = 'audit_logs'
ORDER BY ordinal_position;

-- ============================================================
-- CHECK 6: Verify generated_documents table columns
-- ============================================================
SELECT column_name, column_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = 'atlas_bank' AND table_name = 'generated_documents'
ORDER BY ordinal_position;

-- ============================================================
-- CHECK 7: Check for any stuck PENDING_REVERSAL transactions
-- (these can cause issues if the ENUM doesn't support this value)
-- ============================================================
SELECT 'STUCK CHECK' AS check_type,
  GROUP_CONCAT(status) AS found_statuses,
  COUNT(*) AS count
FROM transactions
WHERE status NOT IN ('PENDING','PENDING_APPROVAL','POSTED','FAILED','REVERSED','CANCELLED');

-- ============================================================
-- CHECK 8: Verify ENUM values for transactions.status
-- ============================================================
SHOW COLUMNS FROM transactions WHERE Field = 'status';

-- ============================================================
-- CHECK 9: Check if storage directory is writable (manual check)
-- Verify by running: ls -la /path/to/atlas-bank-backend/storage/
-- ============================================================
SELECT 'STORAGE CHECK' AS check_type,
  'Run manually: ls -la atlas-bank-backend/storage/rate_limits/' AS instruction;

-- ============================================================
-- DONE
-- ============================================================
SELECT 'Diagnosis complete. Review results above.' AS status;
