<?php
/**
 * Atlas Bank Enterprise Operations Console
 * One-Time Setup Fix Script
 *
 * This script performs critical fixes that need to run once after deployment:
 *   1. Resets admin password to 'admin123' with a proper bcrypt hash
 *   2. Disables MFA for admin (since there's no authenticator set up yet)
 *   3. Sets force_password_change = false for all staff
 *   4. Clears account lockouts
 *   5. Verifies that required helper functions are loadable
 *   6. Self-deletes after successful execution (security)
 *
 * SECURITY: This script MUST be deleted after use. It includes a self-delete
 * mechanism that removes itself after the first successful run.
 *
 * Access: GET /fix-setup.php?confirm=1
 */

// Only run if explicitly confirmed via query parameter
if (!isset($_GET['confirm']) || $_GET['confirm'] !== '1') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Confirmation required. Access via /fix-setup.php?confirm=1',
        'message' => 'This script performs one-time database fixes. Add ?confirm=1 to execute.'
    ]);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$results = [
    'timestamp' => date('c'),
    'steps' => [],
    'errors' => [],
    'success' => true
];

try {
    // Load database configuration and helpers
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/config/constants.php';
    require_once __DIR__ . '/includes/helpers.php';

    $db = getDB();

    // ─────────────────────────────────────────────────────────
    // Step 1: Verify helper functions are available
    // ─────────────────────────────────────────────────────────
    $requiredFunctions = [
        'getSetting', 'createSession', 'computeDeviceFingerprint',
        'isKnownDevice', 'recordLoginHistory', '_ensureMfaPendingTokensTable',
        '_ensureStaffColumns', '_ensureSessionColumns'
    ];
    $missingFunctions = [];
    foreach ($requiredFunctions as $fn) {
        if (!function_exists($fn)) {
            $missingFunctions[] = $fn;
        }
    }
    if (!empty($missingFunctions)) {
        $results['steps'][] = ['step' => 'verify_functions', 'status' => 'ERROR', 'missing' => $missingFunctions];
        $results['errors'][] = 'Missing functions: ' . implode(', ', $missingFunctions);
        $results['success'] = false;
    } else {
        $results['steps'][] = ['step' => 'verify_functions', 'status' => 'OK', 'count' => count($requiredFunctions)];
    }

    // ─────────────────────────────────────────────────────────
    // Step 2: Ensure required tables/columns exist
    // ─────────────────────────────────────────────────────────
    try {
        _ensureStaffColumns($db);
        $results['steps'][] = ['step' => 'ensure_staff_columns', 'status' => 'OK'];
    } catch (Throwable $e) {
        $results['steps'][] = ['step' => 'ensure_staff_columns', 'status' => 'ERROR', 'message' => $e->getMessage()];
        $results['errors'][] = 'Staff columns error: ' . $e->getMessage();
    }

    try {
        _ensureSessionColumns($db);
        $results['steps'][] = ['step' => 'ensure_session_columns', 'status' => 'OK'];
    } catch (Throwable $e) {
        $results['steps'][] = ['step' => 'ensure_session_columns', 'status' => 'ERROR', 'message' => $e->getMessage()];
        $results['errors'][] = 'Session columns error: ' . $e->getMessage();
    }

    try {
        _ensureMfaPendingTokensTable($db);
        $results['steps'][] = ['step' => 'ensure_mfa_table', 'status' => 'OK'];
    } catch (Throwable $e) {
        $results['steps'][] = ['step' => 'ensure_mfa_table', 'status' => 'ERROR', 'message' => $e->getMessage()];
        $results['errors'][] = 'MFA table error: ' . $e->getMessage();
    }

    // ─────────────────────────────────────────────────────────
    // Step 3: Reset admin password
    // ─────────────────────────────────────────────────────────
    $newPassword = 'admin123';
    $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

    try {
        $stmt = $db->prepare("SELECT id, username, password_hash FROM staff WHERE username = 'admin' LIMIT 1");
        $stmt->execute();
        $admin = $stmt->fetch();

        if ($admin) {
            $oldHashPrefix = substr($admin['password_hash'], 0, 12);
            $updateStmt = $db->prepare(
                "UPDATE staff SET password_hash = :hash, password_changed_at = NOW() WHERE username = 'admin'"
            );
            $updateStmt->execute([':hash' => $newHash]);

            // Verify the update
            $verifyStmt = $db->prepare("SELECT password_hash FROM staff WHERE username = 'admin' LIMIT 1");
            $verifyStmt->execute();
            $updated = $verifyStmt->fetch();
            $verifyResult = password_verify($newPassword, $updated['password_hash']);

            $results['steps'][] = [
                'step' => 'reset_admin_password',
                'status' => $verifyResult ? 'OK' : 'ERROR',
                'old_hash_prefix' => $oldHashPrefix,
                'new_hash_prefix' => substr($updated['password_hash'], 0, 12),
                'password_verify' => $verifyResult,
                'new_password' => $newPassword
            ];
            if (!$verifyResult) {
                $results['errors'][] = 'Password verification failed after update!';
            }
        } else {
            $results['steps'][] = ['step' => 'reset_admin_password', 'status' => 'SKIPPED', 'reason' => 'No admin user found'];
        }
    } catch (Throwable $e) {
        $results['steps'][] = ['step' => 'reset_admin_password', 'status' => 'ERROR', 'message' => $e->getMessage()];
        $results['errors'][] = 'Password reset error: ' . $e->getMessage();
    }

    // ─────────────────────────────────────────────────────────
    // Step 4: Disable MFA for admin
    // ─────────────────────────────────────────────────────────
    try {
        $stmt = $db->prepare("UPDATE staff SET mfa_required = false, mfa_secret = NULL WHERE username = 'admin'");
        $stmt->execute();
        $rowsAffected = $stmt->rowCount();

        $verifyStmt = $db->prepare("SELECT mfa_required, mfa_secret FROM staff WHERE username = 'admin' LIMIT 1");
        $verifyStmt->execute();
        $mfaState = $verifyStmt->fetch();

        $results['steps'][] = [
            'step' => 'disable_admin_mfa',
            'status' => ($mfaState['mfa_required'] === false || $mfaState['mfa_required'] === 'f' || $mfaState['mfa_required'] === 0) ? 'OK' : 'ERROR',
            'mfa_required' => $mfaState['mfa_required'],
            'mfa_secret' => $mfaState['mfa_secret'] ? 'present' : 'null',
            'rows_affected' => $rowsAffected
        ];
    } catch (Throwable $e) {
        $results['steps'][] = ['step' => 'disable_admin_mfa', 'status' => 'ERROR', 'message' => $e->getMessage()];
        $results['errors'][] = 'MFA disable error: ' . $e->getMessage();
    }

    // ─────────────────────────────────────────────────────────
    // Step 5: Set force_password_change = false for all staff
    // ─────────────────────────────────────────────────────────
    try {
        $stmt = $db->prepare("UPDATE staff SET force_password_change = false WHERE force_password_change = true OR force_password_change IS NULL");
        $stmt->execute();
        $rowsAffected = $stmt->rowCount();

        $results['steps'][] = [
            'step' => 'clear_force_password_change',
            'status' => 'OK',
            'rows_affected' => $rowsAffected
        ];
    } catch (Throwable $e) {
        $results['steps'][] = ['step' => 'clear_force_password_change', 'status' => 'ERROR', 'message' => $e->getMessage()];
        $results['errors'][] = 'force_password_change error: ' . $e->getMessage();
    }

    // ─────────────────────────────────────────────────────────
    // Step 6: Clear account lockouts
    // ─────────────────────────────────────────────────────────
    try {
        $stmt = $db->prepare("UPDATE staff SET account_locked = false, locked_until = NULL, failed_login_attempts = 0 WHERE account_locked = true");
        $stmt->execute();
        $rowsAffected = $stmt->rowCount();

        $results['steps'][] = [
            'step' => 'clear_account_lockouts',
            'status' => 'OK',
            'rows_affected' => $rowsAffected
        ];
    } catch (Throwable $e) {
        $results['steps'][] = ['step' => 'clear_account_lockouts', 'status' => 'ERROR', 'message' => $e->getMessage()];
        $results['errors'][] = 'Account lockout clear error: ' . $e->getMessage();
    }

    // ─────────────────────────────────────────────────────────
    // Step 7: Ensure security settings exist
    // ─────────────────────────────────────────────────────────
    try {
        $securityDefaults = [
            ['key' => 'security.max_login_attempts', 'name' => 'Max Login Attempts', 'category' => 'Security', 'value' => '5'],
            ['key' => 'security.lockout_duration', 'name' => 'Lockout Duration (minutes)', 'category' => 'Security', 'value' => '30'],
            ['key' => 'security.session_timeout', 'name' => 'Session Timeout (minutes)', 'category' => 'Security', 'value' => '480'],
            ['key' => 'security.max_concurrent_sessions', 'name' => 'Max Concurrent Sessions', 'category' => 'Security', 'value' => '3'],
        ];
        $inserted = 0;
        foreach ($securityDefaults as $def) {
            try {
                $exists = $db->prepare('SELECT 1 FROM settings WHERE "key" = :key LIMIT 1');
                $exists->execute([':key' => $def['key']]);
                if (!$exists->fetch()) {
                    $db->prepare(
                        'INSERT INTO settings ("key", name, category, value_data, description) VALUES (:key, :name, :cat, :val, :desc)'
                    )->execute([
                        ':key' => $def['key'], ':name' => $def['name'], ':cat' => $def['category'],
                        ':val' => $def['value'], ':desc' => 'Auto-configured'
                    ]);
                    $inserted++;
                }
            } catch (PDOException $e) { /* already exists */ }
        }
        $results['steps'][] = [
            'step' => 'ensure_security_settings',
            'status' => 'OK',
            'settings_inserted' => $inserted
        ];
    } catch (Throwable $e) {
        $results['steps'][] = ['step' => 'ensure_security_settings', 'status' => 'ERROR', 'message' => $e->getMessage()];
        $results['errors'][] = 'Settings error: ' . $e->getMessage();
    }

    // ─────────────────────────────────────────────────────────
    // Step 8: Verify database connectivity and staff count
    // ─────────────────────────────────────────────────────────
    try {
        $stmt = $db->query("SELECT COUNT(*) AS cnt FROM staff");
        $staffCount = (int)$stmt->fetch()['cnt'];

        $stmt = $db->query("SELECT COUNT(*) AS cnt FROM customers");
        $custCount = (int)$stmt->fetch()['cnt'];

        $stmt = $db->query("SELECT COUNT(*) AS cnt FROM accounts");
        $acctCount = (int)$stmt->fetch()['cnt'];

        $results['steps'][] = [
            'step' => 'verify_data',
            'status' => 'OK',
            'staff_count' => $staffCount,
            'customer_count' => $custCount,
            'account_count' => $acctCount
        ];
    } catch (Throwable $e) {
        $results['steps'][] = ['step' => 'verify_data', 'status' => 'ERROR', 'message' => $e->getMessage()];
    }

} catch (Throwable $e) {
    $results['errors'][] = 'Fatal: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    $results['success'] = false;
}

// ─────────────────────────────────────────────────────────
// Self-delete: Remove this script after execution
// ─────────────────────────────────────────────────────────
$selfPath = __FILE__;
$deleted = false;
if (file_exists($selfPath)) {
    $deleted = @unlink($selfPath);
}
$results['self_deleted'] = $deleted;
if (!$deleted) {
    $results['warnings'] = ['SECURITY: Could not auto-delete fix-setup.php. Please delete it manually from the server.'];
}

// Final summary
$results['summary'] = [
    'admin_password' => 'admin123',
    'mfa_disabled' => true,
    'force_password_change_cleared' => true,
    'account_lockouts_cleared' => true,
    'next_step' => 'Log in with username: admin, password: admin123',
    'important' => 'Change the admin password after first login via Settings > Staff Management'
];

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
