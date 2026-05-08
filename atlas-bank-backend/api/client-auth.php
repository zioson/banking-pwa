<?php
/**
 * Atlas Bank Enterprise - Client Portal Authentication API
 * Public login/logout/session validation for customer view-only portal.
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/Response.php';
require_once __DIR__ . '/../middleware/client_auth.php';
require_once __DIR__ . '/../middleware/rate_limit.php';

$db = getDB();
ensureClientPortalTables($db);

$method = $_ROUTE['method'] ?? 'GET';
$id = $_ROUTE['id'] ?? null;

function loadClientPortalUserByUsername(PDO $db, string $username): ?array
{
    $stmt = $db->prepare(
        "SELECT cpu.*, c.customer_number, c.full_name, c.branch, c.status AS customer_status
         FROM customer_portal_users cpu
         INNER JOIN customers c ON c.id = cpu.customer_id
         WHERE LOWER(cpu.username) = :username
         LIMIT 1"
    );
    $stmt->execute([':username' => $username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

switch ($method) {
    case 'GET':
        if ($id === 'trusted-devices') {
            $client = requireClientAuth();
            successResponse([
                'devices' => listTrustedClientDevices($db, (int)$client['customer_id'])
            ]);
        }

        $token = getClientTokenFromRequest();
        if ($token === '') {
            successResponse([
                'authenticated' => false,
                'session_timeout' => getClientSessionTimeoutMinutes($db),
                'consent' => getClientConsentVersions($db)
            ]);
        }

        $client = requireClientAuth();
        $consent = getClientConsentStatus($db, (int)$client['customer_id']);
        successResponse([
            'authenticated' => true,
            'customer_id' => $client['customer_id'],
            'customer_number' => $client['customer_number'],
            'full_name' => $client['full_name'],
            'branch' => $client['branch'],
            'username' => $client['username'],
            'mfa_required' => !empty($client['mfa_required']),
            'session_timeout' => getClientSessionTimeoutMinutes($db),
            'session_expires_at' => $client['session_expires_at'],
            'requires_password_change' => !empty($client['require_password_change']),
            'consent_required' => !$consent['accepted'],
            'consent' => $consent
        ]);
        break;

    case 'POST':
        if ($id === 'verify-mfa') {
            $input = getRequestInput();
            $pendingToken = sanitize($input['mfa_pending_token'] ?? '');
            $code = preg_replace('/\D/', '', (string)($input['code'] ?? ''));
            $trustDevice = !empty($input['trust_device']);
            $deviceLabel = trim((string)($input['device_label'] ?? 'My Device'));

            if ($pendingToken === '' || strlen($code) !== 6) {
                validationError(['code' => 'Valid MFA token and 6-digit code are required.']);
            }

            $pending = getClientMfaPendingToken($db, $pendingToken);
            if (!$pending) {
                errorResponse('MFA challenge expired. Please sign in again.', 401);
            }

            $stmt = $db->prepare(
                "SELECT cpu.*, c.customer_number, c.full_name, c.branch
                 FROM customer_portal_users cpu
                 INNER JOIN customers c ON c.id = cpu.customer_id
                 WHERE cpu.customer_id = :cid
                 LIMIT 1"
            );
            $stmt->execute([':cid' => (int)$pending['customer_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || empty($user['mfa_secret'])) {
                errorResponse('MFA is not configured for this account.', 403);
            }

            if (!verifyTotpCode((string)$user['mfa_secret'], $code)) {
                recordClientLoginHistory($db, (int)$pending['customer_id'], (string)$user['username'], 'MFA_FAILURE', 'MEDIUM');
                errorResponse('Invalid MFA code.', 401);
            }

            deleteClientMfaPendingToken($db, $pendingToken);

            $token = createClientSession($db, (int)$pending['customer_id'], (string)$user['username']);
            if ($trustDevice) {
                trustClientDevice($db, (int)$pending['customer_id'], getClientDeviceFingerprint(), $deviceLabel);
            }

            $consent = getClientConsentStatus($db, (int)$pending['customer_id']);
            recordClientLoginHistory($db, (int)$pending['customer_id'], (string)$user['username'], 'SUCCESS_MFA', 'NONE');
            logAudit(
                'CLIENT:' . ($user['full_name'] ?? $user['username']),
                'CLIENT_PORTAL_MFA_VERIFIED',
                'CUSTOMER',
                (string)$pending['customer_id'],
                'SUCCESS',
                'Client MFA verified and session issued',
                (string)($user['branch'] ?? ''),
                getClientIp()
            );

            successResponse([
                'token' => $token,
                'customer_id' => (int)$pending['customer_id'],
                'customer_number' => $user['customer_number'] ?? '',
                'full_name' => $user['full_name'] ?? '',
                'branch' => $user['branch'] ?? '',
                'username' => $user['username'] ?? '',
                'mfa_required' => true,
                'session_timeout' => getClientSessionTimeoutMinutes($db),
                'requires_password_change' => !empty($user['require_password_change']),
                'consent_required' => !$consent['accepted'],
                'consent' => $consent
            ], 'MFA verification successful.');
        }

        if ($id === 'accept-consent') {
            $client = requireClientAuth();
            $versions = getClientConsentVersions($db);
            $input = getRequestInput();
            $terms = (string)($input['terms_version'] ?? '');
            $privacy = (string)($input['privacy_version'] ?? '');
            $accepted = !empty($input['accepted']);
            if (!$accepted || $terms !== $versions['terms'] || $privacy !== $versions['privacy']) {
                errorResponse('Consent payload mismatch. Reload and accept latest terms.', 400);
            }
            recordClientConsent($db, (int)$client['customer_id'], $terms, $privacy, 'Accepted in client portal');
            logAudit(
                'CLIENT:' . ($client['full_name'] ?? $client['username']),
                'CLIENT_PORTAL_CONSENT_ACCEPT',
                'CUSTOMER',
                (string)$client['customer_id'],
                'SUCCESS',
                'Accepted terms ' . $terms . ' and privacy ' . $privacy,
                (string)$client['branch'],
                getClientIp()
            );
            successMessage('Consent recorded successfully.');
        }

        if ($id === 'change-password') {
            $client = requireClientAuth();
            $input = getRequestInput();
            $current = (string)($input['current_password'] ?? '');
            $new = (string)($input['new_password'] ?? '');
            if ($new === '') {
                validationError(['new_password' => 'New password is required.']);
            }
            if (strlen($new) < 10) {
                validationError(['new_password' => 'New password must be at least 10 characters.']);
            }
            $stmt = $db->prepare('SELECT id, username, password_hash FROM customer_portal_users WHERE customer_id = :cid LIMIT 1');
            $stmt->execute([':cid' => (int)$client['customer_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                errorResponse('Portal user profile not found.', 404);
            }
            $skipCurrentCheck = !empty($client['require_password_change']);
            if (!$skipCurrentCheck && !password_verify($current, (string)$user['password_hash'])) {
                errorResponse('Current password is incorrect.', 401);
            }
            $upd = $db->prepare('UPDATE customer_portal_users SET password_hash = :ph, require_password_change = FALSE WHERE id = :id');
            $upd->execute([
                ':ph' => password_hash($new, PASSWORD_DEFAULT),
                ':id' => (int)$user['id']
            ]);
            logAudit(
                'CLIENT:' . ($client['full_name'] ?? $client['username']),
                'CLIENT_PORTAL_PASSWORD_CHANGE',
                'CUSTOMER',
                (string)$client['customer_id'],
                'SUCCESS',
                'Client changed portal password',
                (string)$client['branch'],
                getClientIp()
            );
            successMessage('Password changed successfully.');
        }

        $input = getRequestInput();
        $username = strtolower(trim((string)($input['username'] ?? '')));
        $password = (string)($input['password'] ?? '');

        if ($username === '' || $password === '') {
            validationError([
                'username' => $username === '' ? 'Username is required.' : null,
                'password' => $password === '' ? 'Password is required.' : null
            ], 'Username and password are required.');
        }

        requireRateLimit('client-login-ip:' . getClientIp(), 12, 300);
        requireRateLimit('client-login-user:' . $username, 6, 300);

        $user = loadClientPortalUserByUsername($db, $username);

        if (!$user) {
            recordClientLoginHistory($db, null, $username, 'FAILURE', 'LOW');
            errorResponse('Invalid credentials.', 401);
        }

        $customerId = (int)$user['customer_id'];
        $nowTs = time();

        if (!empty($user['locked_until']) && strtotime((string)$user['locked_until']) > $nowTs) {
            recordClientLoginHistory($db, $customerId, $username, 'LOCKED', 'MEDIUM');
            errorResponse('Account is temporarily locked. Try again later.', 423);
        }

        $status = strtoupper((string)($user['status'] ?? 'ACTIVE'));
        if ($status !== 'ACTIVE') {
            recordClientLoginHistory($db, $customerId, $username, 'DENIED', 'MEDIUM');
            errorResponse('Portal access is not active for this account.', 403);
        }

        if (!password_verify($password, (string)$user['password_hash'])) {
            $newAttempts = ((int)$user['failed_login_attempts']) + 1;
            $lockMins = (int)getSetting($db, 'security.client_lockout_duration', 30);
            if ($lockMins < 5) {
                $lockMins = 5;
            }
            $maxAttempts = (int)getSetting($db, 'security.client_max_login_attempts', 5);
            if ($maxAttempts < 3) {
                $maxAttempts = 3;
            }

            $lockedUntil = null;
            if ($newAttempts >= $maxAttempts) {
                $lockedUntil = date('Y-m-d H:i:s', $nowTs + ($lockMins * 60));
            }
            $upd = $db->prepare(
                'UPDATE customer_portal_users
                 SET failed_login_attempts = :att, locked_until = :locked
                 WHERE id = :id'
            );
            $upd->execute([
                ':att' => $newAttempts,
                ':locked' => $lockedUntil,
                ':id' => (int)$user['id']
            ]);

            recordClientLoginHistory($db, $customerId, $username, $lockedUntil ? 'LOCKED' : 'FAILURE', $lockedUntil ? 'HIGH' : 'LOW');
            errorResponse('Invalid credentials.', 401);
        }

        $deviceTrusted = isTrustedClientDevice($db, $customerId, getClientDeviceFingerprint());
        $mfaRequired = !empty($user['mfa_required']) && !empty($user['mfa_secret']);
        if ($mfaRequired && !$deviceTrusted) {
            $mfaPendingToken = createClientMfaPendingToken($db, $customerId);
            recordClientLoginHistory($db, $customerId, $username, 'MFA_REQUIRED', 'LOW');
            successResponse([
                'mfa_required' => true,
                'mfa_pending_token' => $mfaPendingToken,
                'message' => 'MFA verification required.'
            ], 'MFA verification required.');
        }

        $token = createClientSession($db, $customerId, $username);
        $consent = getClientConsentStatus($db, $customerId);
        recordClientLoginHistory($db, $customerId, $username, 'SUCCESS', 'NONE');
        logAudit(
            'CLIENT:' . ($user['full_name'] ?? $username),
            'CLIENT_PORTAL_LOGIN',
            'CUSTOMER',
            (string)$customerId,
            'SUCCESS',
            'Client portal login successful',
            (string)($user['branch'] ?? ''),
            getClientIp()
        );

        resetRateLimit('client-login-ip:' . getClientIp());
        resetRateLimit('client-login-user:' . $username);

        successResponse([
            'token' => $token,
            'customer_id' => $customerId,
            'customer_number' => $user['customer_number'] ?? '',
            'full_name' => $user['full_name'] ?? '',
            'branch' => $user['branch'] ?? '',
            'username' => $user['username'] ?? '',
            'mfa_required' => !empty($user['mfa_required']),
            'session_timeout' => getClientSessionTimeoutMinutes($db),
            'requires_password_change' => !empty($user['require_password_change']),
            'consent_required' => !$consent['accepted'],
            'consent' => $consent
        ], 'Client login successful.');
        break;

    case 'PUT':
        if ($id === 'trusted-devices') {
            $client = requireClientAuth();
            $input = getRequestInput();
            $deviceId = (int)($input['device_id'] ?? 0);
            if ($deviceId <= 0) {
                validationError(['device_id' => 'Valid device ID is required.']);
            }
            $ok = revokeTrustedClientDevice($db, (int)$client['customer_id'], $deviceId);
            if (!$ok) {
                notFoundResponse('Trusted device not found.');
            }
            logAudit(
                'CLIENT:' . ($client['full_name'] ?? $client['username']),
                'CLIENT_TRUSTED_DEVICE_REVOKE',
                'CUSTOMER',
                (string)$client['customer_id'],
                'SUCCESS',
                'Client revoked trusted device #' . $deviceId,
                (string)$client['branch'],
                getClientIp()
            );
            successMessage('Trusted device revoked.');
        }
        errorResponse('Method not allowed.', 405);
        break;

    case 'DELETE':
        $token = getClientTokenFromRequest();
        if ($token !== '') {
            try {
                $client = requireClientAuth();
                logAudit(
                    'CLIENT:' . ($client['full_name'] ?? $client['username']),
                    'CLIENT_PORTAL_LOGOUT',
                    'CUSTOMER',
                    (string)$client['customer_id'],
                    'SUCCESS',
                    'Client portal logout',
                    (string)$client['branch'],
                    getClientIp()
                );
            } catch (Throwable $e) {
                // ignore and continue cookie/session cleanup
            }
        }
        destroyClientSession($db, $token);
        successMessage('Logged out successfully.');
        break;

    default:
        errorResponse('Method not allowed.', 405);
}
