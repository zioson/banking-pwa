-- ================================================================
-- Atlas Bank — Fix Stuck Reversal Data
-- Run this ONCE in phpMyAdmin or MySQL CLI on the atlas_bank database
-- to clean up transactions that are stuck in PENDING_REVERSAL or
-- approvals that were approved but the reversal never executed.
-- ================================================================

-- STEP 1: Find transactions stuck in PENDING_REVERSAL whose approvals
-- were already APPROVED or REJECTED but the reversal never ran.
-- These need manual cleanup.

-- Show what we're about to fix (read-only diagnostic)
SELECT t.id AS txn_id, t.ref, t.status AS txn_status, t.amount, t.account,
       a.id AS approval_id, a.status AS approval_status
FROM transactions t
LEFT JOIN approvals a ON a.entity_type = 'TRANSACTION'
                      AND a.entity_id = t.id
                      AND a.scope_code = 'TXN_REVERSAL'
WHERE t.status IN ('PENDING_REVERSAL', 'PENDING_APPROVAL');

-- STEP 2: For transactions stuck in PENDING_REVERSAL where the approval
-- was APPROVED but reversal never executed — restore to POSTED for now
-- (the reversal can be re-done through the UI after applying the code fixes)

UPDATE transactions t
INNER JOIN approvals a ON a.entity_type = 'TRANSACTION'
                      AND a.entity_id = t.id
                      AND a.scope_code = 'TXN_REVERSAL'
                      AND a.status = 'APPROVED'
SET t.status = 'POSTED'
WHERE t.status = 'PENDING_REVERSAL';

-- STEP 3: For transactions stuck in PENDING_REVERSAL where the approval
-- was REJECTED — restore to POSTED
UPDATE transactions t
INNER JOIN approvals a ON a.entity_type = 'TRANSACTION'
                      AND a.entity_id = t.id
                      AND a.scope_code = 'TXN_REVERSAL'
                      AND a.status = 'REJECTED'
SET t.status = 'POSTED'
WHERE t.status = 'PENDING_REVERSAL';

-- STEP 4: For transactions stuck in PENDING_REVERSAL where there's
-- NO matching approval at all — restore to POSTED (phantom reversal request)
UPDATE transactions t
LEFT JOIN approvals a ON a.entity_type = 'TRANSACTION'
                     AND a.entity_id = t.id
                     AND a.scope_code = 'TXN_REVERSAL'
SET t.status = 'POSTED'
WHERE t.status = 'PENDING_REVERSAL'
  AND a.id IS NULL;

-- STEP 5: Same for PENDING_APPROVAL stuck transactions
UPDATE transactions t
LEFT JOIN approvals a ON a.entity_type = 'TRANSACTION'
                     AND a.entity_id = t.id
                     AND a.status = 'PENDING'
SET t.status = 'POSTED'
WHERE t.status = 'PENDING_APPROVAL'
  AND a.id IS NULL;

UPDATE transactions t
INNER JOIN approvals a ON a.entity_type = 'TRANSACTION'
                      AND a.entity_id = t.id
                      AND a.status = 'REJECTED'
SET t.status = 'REJECTED'
WHERE t.status = 'PENDING_APPROVAL';

UPDATE transactions t
INNER JOIN approvals a ON a.entity_type = 'TRANSACTION'
                      AND a.entity_id = t.id
                      AND a.status = 'APPROVED'
SET t.status = 'POSTED'
WHERE t.status = 'PENDING_APPROVAL';

-- STEP 6: Verify cleanup — should return 0 rows if all stuck states resolved
SELECT 'STUCK TRANSACTIONS REMAINING' AS check_name, COUNT(*) AS count
FROM transactions
WHERE status IN ('PENDING_REVERSAL', 'PENDING_APPROVAL');

-- STEP 7: Clean up any phantom approval records that have no matching
-- transaction (orphaned approvals with entity_type TRANSACTION)
-- These are safe to cancel
UPDATE approvals SET status = 'CANCELLED', reason = 'Auto-cleaned: no matching transaction found'
WHERE entity_type = 'TRANSACTION'
  AND status = 'PENDING'
  AND entity_id NOT IN (SELECT id FROM transactions);

-- Done! All stuck states resolved.
-- After running this SQL, hard-refresh the browser and the data will be clean.
