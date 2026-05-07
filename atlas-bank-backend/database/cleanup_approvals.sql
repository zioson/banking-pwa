-- ============================================================
-- Atlas Bank — Approval Cleanup (Safe)
-- ============================================================
-- This file cleans up orphaned or stale approval records
-- that were created with incorrect IDs from previous code.
-- Also resets any transactions stuck in PENDING_REVERSAL
-- back to POSTED if their approval was already decided.
--
-- RUN THIS IN phpMyAdmin or MySQL CLI:
--   mysql -u root atlas_bank < cleanup_approvals.sql
-- ============================================================

-- Step 1: Find transactions stuck in PENDING_REVERSAL whose
-- approval has already been decided (APPROVED or REJECTED)
-- These transactions should be either REVERSED (if approved)
-- or restored to POSTED (if rejected)

-- Restore transactions to POSTED where the reversal was REJECTED
UPDATE transactions t
JOIN approvals a ON a.entity_id = t.id AND a.scope_code = 'TXN_REVERSAL'
SET t.status = 'POSTED'
WHERE t.status = 'PENDING_REVERSAL'
  AND a.status = 'REJECTED';

-- Mark transactions as REVERSED where the reversal was APPROVED
UPDATE transactions t
JOIN approvals a ON a.entity_id = t.id AND a.scope_code = 'TXN_REVERSAL'
SET t.status = 'REVERSED'
WHERE t.status = 'PENDING_REVERSAL'
  AND a.status = 'APPROVED';

-- Step 2: Find transactions stuck in PENDING_REVERSAL but
-- have NO matching approval at all (orphaned)
-- Restore them to POSTED
UPDATE transactions t
LEFT JOIN approvals a ON a.entity_id = t.id AND a.scope_code = 'TXN_REVERSAL' AND a.status = 'PENDING'
SET t.status = 'POSTED'
WHERE t.status = 'PENDING_REVERSAL'
  AND a.id IS NULL;

-- Step 3: Find transactions in PENDING_APPROVAL with no
-- matching PENDING approval — restore to POSTED
UPDATE transactions t
LEFT JOIN approvals a ON a.entity_id = t.id AND a.entity_type = 'TRANSACTION' AND a.status = 'PENDING'
SET t.status = 'POSTED'
WHERE t.status = 'PENDING_APPROVAL'
  AND a.id IS NULL;

-- Step 4: Show what was cleaned up (informational)
SELECT 'Cleaned PENDING_REVERSAL transactions' AS action, COUNT(*) AS affected
FROM transactions
WHERE status IN ('POSTED', 'REVERSED')
  AND id IN (
    SELECT entity_id FROM approvals WHERE scope_code = 'TXN_REVERSAL'
  );

SELECT 'Remaining PENDING_REVERSAL' AS status, COUNT(*) AS count
FROM transactions WHERE status = 'PENDING_REVERSAL';

SELECT 'Remaining PENDING_APPROVAL' AS status, COUNT(*) AS count
FROM transactions WHERE status = 'PENDING_APPROVAL';

SELECT 'PENDING Approvals' AS status, COUNT(*) AS count
FROM approvals WHERE status = 'PENDING';

-- ============================================================
-- DONE. All orphaned transaction states have been cleaned.
-- ============================================================
