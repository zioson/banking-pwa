-- ============================================================
-- Atlas Bank — Complete Approval & Transaction Cleanup
-- ============================================================
-- Fixes ALL accumulated data corruption from previous code:
-- 1. Transactions stuck in PENDING_REVERSAL/PENDING_APPROVAL
-- 2. Duplicate approvals for the same entity
-- 3. Orphaned approvals (entity no longer exists)
--
-- SAFE TO RUN MULTIPLE TIMES — only modifies broken records.
--
-- RUN: mysql -u root atlas_bank < cleanup_approvals.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- STEP 1: Fix transactions stuck in PENDING_REVERSAL
-- ============================================

-- 1a. If reversal was APPROVED → mark transaction as REVERSED
UPDATE transactions t
INNER JOIN approvals a ON a.entity_id = t.id 
    AND a.scope_code = 'TXN_REVERSAL' 
    AND a.entity_type = 'TRANSACTION'
    AND a.status = 'APPROVED'
SET t.status = 'REVERSED'
WHERE t.status = 'PENDING_REVERSAL';

-- 1b. If reversal was REJECTED → restore transaction to POSTED
UPDATE transactions t
INNER JOIN approvals a ON a.entity_id = t.id 
    AND a.scope_code = 'TXN_REVERSAL' 
    AND a.entity_type = 'TRANSACTION'
    AND a.status = 'REJECTED'
SET t.status = 'POSTED'
WHERE t.status = 'PENDING_REVERSAL';

-- 1c. If reversal was CANCELLED → restore transaction to POSTED
UPDATE transactions t
INNER JOIN approvals a ON a.entity_id = t.id 
    AND a.scope_code = 'TXN_REVERSAL' 
    AND a.entity_type = 'TRANSACTION'
    AND a.status = 'CANCELLED'
SET t.status = 'POSTED'
WHERE t.status = 'PENDING_REVERSAL';

-- 1d. If NO matching approval exists at all → restore to POSTED
UPDATE transactions t
LEFT JOIN approvals a ON a.entity_id = t.id 
    AND a.scope_code = 'TXN_REVERSAL' 
    AND a.entity_type = 'TRANSACTION'
SET t.status = 'POSTED'
WHERE t.status = 'PENDING_REVERSAL'
  AND a.id IS NULL;

-- ============================================
-- STEP 2: Fix transactions stuck in PENDING_APPROVAL
-- ============================================

-- 2a. If approval was APPROVED → mark transaction as POSTED
UPDATE transactions t
INNER JOIN approvals a ON a.entity_id = t.id 
    AND a.entity_type = 'TRANSACTION'
    AND a.status = 'APPROVED'
SET t.status = 'POSTED'
WHERE t.status = 'PENDING_APPROVAL';

-- 2b. If approval was REJECTED → mark transaction as REJECTED
UPDATE transactions t
INNER JOIN approvals a ON a.entity_id = t.id 
    AND a.entity_type = 'TRANSACTION'
    AND a.status = 'REJECTED'
SET t.status = 'REJECTED'
WHERE t.status = 'PENDING_APPROVAL';

-- 2c. If NO matching PENDING approval → restore to POSTED
UPDATE transactions t
LEFT JOIN approvals a ON a.entity_id = t.id 
    AND a.entity_type = 'TRANSACTION'
    AND a.status = 'PENDING'
SET t.status = 'POSTED'
WHERE t.status = 'PENDING_APPROVAL'
  AND a.id IS NULL;

-- ============================================
-- STEP 3: Clean up duplicate approvals
-- (Keep the oldest PENDING one, cancel the rest)
-- ============================================

-- 3a. For each entity, cancel duplicate PENDING approvals
-- (keep the one with the lowest ID)
UPDATE approvals a1
INNER JOIN (
    SELECT entity_type, entity_id, scope_code, MIN(id) AS keep_id
    FROM approvals
    WHERE status = 'PENDING'
    GROUP BY entity_type, entity_id, scope_code
    HAVING COUNT(*) > 1
) dup ON a1.entity_type = dup.entity_type 
    AND a1.entity_id = dup.entity_id 
    AND a1.scope_code = dup.scope_code
    AND a1.id != dup.keep_id
SET a1.status = 'CANCELLED', a1.decided_at = NOW(), a1.reason = 'Auto-cancelled duplicate approval';

-- ============================================
-- STEP 4: Cancel orphaned PENDING approvals
-- (entity no longer exists in transactions table)
-- ============================================

-- 4a. Cancel PENDING transaction approvals where the transaction doesn't exist
UPDATE approvals a
LEFT JOIN transactions t ON t.id = a.entity_id
SET a.status = 'CANCELLED', a.decided_at = NOW(), a.reason = 'Auto-cancelled: referenced transaction does not exist'
WHERE a.entity_type = 'TRANSACTION'
  AND a.status = 'PENDING'
  AND t.id IS NULL;

-- ============================================
-- VERIFY: Show results
-- ============================================
SELECT '=== CLEANUP RESULTS ===' AS info;

SELECT 'Remaining PENDING_REVERSAL' AS check_item, COUNT(*) AS count
FROM transactions WHERE status = 'PENDING_REVERSAL';

SELECT 'Remaining PENDING_APPROVAL' AS check_item, COUNT(*) AS count
FROM transactions WHERE status = 'PENDING_APPROVAL';

SELECT 'PENDING Approvals' AS check_item, COUNT(*) AS count
FROM approvals WHERE status = 'PENDING';

SELECT 'Total Approvals' AS check_item, COUNT(*) AS count
FROM approvals;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- After running this SQL:
-- 1. Refresh the browser page (Ctrl+F5)
-- 2. Log in again
-- 3. All phantom/stuck approvals will be gone
-- ============================================================
