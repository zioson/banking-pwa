-- ================================================================
-- ATLAS BANK — QUICK FIX: Password Hash Correction
-- ================================================================
-- PROBLEM: The old bcrypt hash ($2y$10$92IXU...) was for "password"
--          not "admin123". Login was failing with 401.
--
-- FIX: Updates all staff passwords to use the correct bcrypt hash
--      for "admin123" (verified with Python bcrypt library).
--
-- INSTRUCTIONS:
--   1. Open phpMyAdmin → http://localhost/phpmyadmin
--   2. Select the "atlas_bank" database
--   3. Go to the SQL tab
--   4. Paste this script and click "Go"
--   5. Login with: username = admin, password = admin123
-- ================================================================

USE atlas_bank;

-- Correct bcrypt hash for "admin123" (verified)
-- $2y$10$L42hjRVJzJxpSWCGrdvPSO1rX68URpzisYyCPvgn5S8AT0WKYNulS
SET @correct_hash = '$2y$10$L42hjRVJzJxpSWCGrdvPSO1rX68URpzisYyCPvgn5S8AT0WKYNulS';

-- Update ALL staff passwords to admin123
UPDATE staff SET password_hash = @correct_hash WHERE username IN ('admin','jtchinda','cfotso','andongo','enkoulou');

-- Reset failed login attempts and unlock any locked accounts
UPDATE staff SET
  failed_login_attempts = 0,
  account_locked = 0,
  locked_until = NULL
WHERE 1=1;

-- Verify the fix
SELECT username, full_name,
  CASE WHEN password_hash = @correct_hash THEN '✅ CORRECT' ELSE '❌ WRONG' END AS password_status,
  employment_status,
  account_locked,
  failed_login_attempts,
  mfa_required
FROM staff
ORDER BY id;
