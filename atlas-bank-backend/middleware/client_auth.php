<?php
/**
 * Atlas Bank Enterprise - Client Portal Authentication Middleware
 * View-only customer authentication and session controls.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/Response.php';

function ensureClientPortalTables(PDO $db): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $db->exec("CREATE TABLE IF NOT EXISTS customer_portal_users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_id INT UNSIGNED NOT NULL,
        username VARCHAR(120) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
        mfa_required TINYINT(1) NOT NULL DEFAULT 0,
        mfa_secret VARCHAR(255) DEFAULT NULL,
        mfa_enrolled_at DATETIME NULL,
        failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        locked_until DATETIME NULL,
        last_login DATETIME NULL,
        last_login_ip VARCHAR(50) DEFAULT NULL,
        require_password_change TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_customer_portal_customer (customer_id),
        UNIQUE KEY uk_customer_portal_username (username),
        INDEX idx_customer_portal_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS customer_sessions (
        id VARCHAR(128) NOT NULL,
        customer_id INT UNSIGNED NOT NULL,
        ip_address VARCHAR(50) DEFAULT NULL,
        user_agent VARCHAR(500) DEFAULT NULL,
        last_activity DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_customer_sessions_customer (customer_id),
        INDEX idx_customer_sessions_exp (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS customer_login_history (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_id INT UNSIGNED NULL,
        username VARCHAR(120) NOT NULL,
        result VARCHAR(30) NOT NULL,
        ip VARCHAR(50) DEFAULT NULL,
        user_agent VARCHAR(500) DEFAULT NULL,
        risk VARCHAR(20) DEFAULT 'NONE',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_clh_username (username),
        INDEX idx_clh_customer (customer_id),
        INDEX idx_clh_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS customer_trusted_devices (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_id INT UNSIGNED NOT NULL,
        device_fingerprint VARCHAR(64) NOT NULL,
        label VARCHAR(255) DEFAULT '',
        trusted_at DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        expires_at DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_ip VARCHAR(50) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_ctd_customer_fingerprint (customer_id, device_fingerprint),
        INDEX idx_ctd_customer (customer_id),
        INDEX idx_ctd_exp (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS customer_mfa_pending_tokens (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_id INT UNSIGNED NOT NULL,
        token VARCHAR(128) NOT NULL,
        device_fingerprint VARCHAR(64) DEFAULT '',
        ip_address VARCHAR(50) DEFAULT NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_cmp_token (token),
        INDEX idx_cmp_customer (customer_id),
        INDEX idx_cmp_exp (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS customer_portal_consents (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_id INT UNSIGNED NOT NULL,
        terms_version VARCHAR(50) NOT NULL,
        privacy_version VARCHAR(50) NOT NULL,
        accepted_at DATETIME NOT NULL,
        ip_address VARCHAR(50) DEFAULT NULL,
        user_agent VARCHAR(500) DEFAULT NULL,
        evidence_note TEXT DEFAULT NULL,
        PRIMARY KEY (id),
        INDEX idx_cpc_customer (customer_id),
        INDEX idx_cpc_accepted (accepted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS customer_statement_links (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        token_hash VARCHAR(128) NOT NULL,
        customer_id INT UNSIGNED NOT NULL,
        account_number VARCHAR(50) NOT NULL,
        period_start DATE NULL,
        period_end DATE NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_ip VARCHAR(50) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_csl_token (token_hash),
        INDEX idx_csl_customer (customer_id),
        INDEX idx_csl_exp (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Lightweight self-heal for environments with older table versions.
    try { $db->exec("ALTER TABLE customer_portal_users ADD COLUMN mfa_required TINYINT(1) NOT NULL DEFAULT 0 AFTER status"); } catch (PDOException $e) {}
    try { $db->exec("ALTER TABLE customer_portal_users ADD COLUMN mfa_secret VARCHAR(255) DEFAULT NULL AFTER mfa_required"); } catch (PDOException $e) {}
    try { $db->exec("ALTER TABLE customer_portal_users ADD COLUMN mfa_enrolled_at DATETIME NULL AFTER mfa_secret"); } catch (PDOException $e) {}

    $ensured = true;
}

function getClientSessionTimeoutMinutes(PDO $db): int
{
    $timeout = (int)(getSetting($db, 'security.client_session_timeout', 15));
    if ($timeout < 5) {
        $timeout = 5;
    }
    if ($timeout > 120) {
        $timeout = 120;
    }
    return $timeout;
}

function recordClientLoginHistory(PDO $db, ?int $customerId, string $username, string $result, string $risk = 'NONE'): void
{
    $stmt = $db->prepare(
        'INSERT INTO customer_login_history (customer_id, username, result, ip, user_agent, risk)
         VALUES (:cid, :uname, :result, :ip, :ua, :risk)'
    );
    $stmt->execute([
        ':cid' => $customerId,
        ':uname' => $username,
        ':result' => $result,
        ':ip' => getClientIp(),
        ':ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ':risk' => $risk
    ]);
}

function getClientConsentVersions(PDO $db): array
{
    $terms = (string)getSetting($db, 'portal.terms_version', 'v1');
    $privacy = (string)getSetting($db, 'portal.privacy_version', 'v1');
    return ['terms' => $terms, 'privacy' => $privacy];
}

function getClientConsentStatus(PDO $db, int $customerId): array
{
    $versions = getClientConsentVersions($db);
    $stmt = $db->prepare(
        'SELECT terms_version, privacy_version, accepted_at
         FROM customer_portal_consents
         WHERE customer_id = :cid
         ORDER BY accepted_at DESC
         LIMIT 1'
    );
    $stmt->execute([':cid' => $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $accepted = false;
    if ($row) {
        $accepted = ((string)$row['terms_version'] === $versions['terms'] && (string)$row['privacy_version'] === $versions['privacy']);
    }
    return [
        'accepted' => $accepted,
        'terms_version' => $versions['terms'],
        'privacy_version' => $versions['privacy'],
        'accepted_at' => $row['accepted_at'] ?? null
    ];
}

function recordClientConsent(PDO $db, int $customerId, string $termsVersion, string $privacyVersion, string $evidenceNote = ''): void
{
    $stmt = $db->prepare(
        'INSERT INTO customer_portal_consents (customer_id, terms_version, privacy_version, accepted_at, ip_address, user_agent, evidence_note)
         VALUES (:cid, :tv, :pv, :accepted_at, :ip, :ua, :ev)'
    );
    $stmt->execute([
        ':cid' => $customerId,
        ':tv' => $termsVersion,
        ':pv' => $privacyVersion,
        ':accepted_at' => date('Y-m-d H:i:s'),
        ':ip' => getClientIp(),
        ':ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ':ev' => $evidenceNote
    ]);
}

function getClientDeviceFingerprint(): string
{
    return computeDeviceFingerprint((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function isTrustedClientDevice(PDO $db, int $customerId, string $fingerprint): bool
{
    $stmt = $db->prepare(
        "SELECT id
         FROM customer_trusted_devices
         WHERE customer_id = :cid
           AND device_fingerprint = :fp
           AND is_active = 1
           AND (expires_at IS NULL OR expires_at > NOW())
         LIMIT 1"
    );
    $stmt->execute([':cid' => $customerId, ':fp' => $fingerprint]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    $db->prepare('UPDATE customer_trusted_devices SET last_seen = :seen WHERE id = :id')
       ->execute([':seen' => date('Y-m-d H:i:s'), ':id' => (int)$row['id']]);
    return true;
}

function trustClientDevice(PDO $db, int $customerId, string $fingerprint, string $label = '', int $days = 90): void
{
    $days = max(1, min($days, 365));
    $now = date('Y-m-d H:i:s');
    $exp = date('Y-m-d H:i:s', time() + ($days * 86400));
    $stmt = $db->prepare(
        'INSERT INTO customer_trusted_devices (customer_id, device_fingerprint, label, trusted_at, last_seen, expires_at, is_active, created_ip)
         VALUES (:cid, :fp, :label, :trusted_at, :last_seen, :exp, 1, :ip)
         ON DUPLICATE KEY UPDATE label = VALUES(label), trusted_at = VALUES(trusted_at), last_seen = VALUES(last_seen), expires_at = VALUES(expires_at), is_active = 1, created_ip = VALUES(created_ip)'
    );
    $stmt->execute([
        ':cid' => $customerId,
        ':fp' => $fingerprint,
        ':label' => substr($label, 0, 255),
        ':trusted_at' => $now,
        ':last_seen' => $now,
        ':exp' => $exp,
        ':ip' => getClientIp()
    ]);
}

function listTrustedClientDevices(PDO $db, int $customerId): array
{
    $stmt = $db->prepare(
        'SELECT id, label, trusted_at, last_seen, expires_at, is_active
         FROM customer_trusted_devices
         WHERE customer_id = :cid
         ORDER BY trusted_at DESC'
    );
    $stmt->execute([':cid' => $customerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function revokeTrustedClientDevice(PDO $db, int $customerId, int $deviceId): bool
{
    $stmt = $db->prepare('UPDATE customer_trusted_devices SET is_active = 0 WHERE id = :id AND customer_id = :cid');
    $stmt->execute([':id' => $deviceId, ':cid' => $customerId]);
    return $stmt->rowCount() > 0;
}

function createClientMfaPendingToken(PDO $db, int $customerId): string
{
    $token = bin2hex(random_bytes(32));
    $stmt = $db->prepare(
        'INSERT INTO customer_mfa_pending_tokens (customer_id, token, device_fingerprint, ip_address, created_at, expires_at)
         VALUES (:cid, :token, :fp, :ip, :created_at, :expires_at)'
    );
    $stmt->execute([
        ':cid' => $customerId,
        ':token' => $token,
        ':fp' => getClientDeviceFingerprint(),
        ':ip' => getClientIp(),
        ':created_at' => date('Y-m-d H:i:s'),
        ':expires_at' => date('Y-m-d H:i:s', time() + 300)
    ]);
    return $token;
}

function getClientMfaPendingToken(PDO $db, string $token): ?array
{
    $stmt = $db->prepare(
        'SELECT * FROM customer_mfa_pending_tokens WHERE token = :token AND expires_at > NOW() LIMIT 1'
    );
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return $row;
}

function deleteClientMfaPendingToken(PDO $db, string $token): void
{
    $db->prepare('DELETE FROM customer_mfa_pending_tokens WHERE token = :token')->execute([':token' => $token]);
}

function generateClientMfaSecret(): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < 32; $i++) {
        $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $secret;
}

function getClientTokenFromRequest(): string
{
    $token = $_SERVER['HTTP_X_ATLAS_CLIENT_SESSION']
        ?? $_SERVER['REDIRECT_HTTP_X_ATLAS_CLIENT_SESSION']
        ?? '';
    if ($token === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $token = $headers['X-Atlas-Client-Session'] ?? $headers['x-atlas-client-session'] ?? '';
    }
    if ($token === '') {
        $token = $_COOKIE['X-Atlas-Client-Session'] ?? '';
    }
    return preg_replace('/[^a-zA-Z0-9\-_\.]/', '', (string)$token);
}

function createClientSession(PDO $db, int $customerId, string $username = ''): string
{
    $token = bin2hex(random_bytes(32));
    $timeout = getClientSessionTimeoutMinutes($db);
    $now = date('Y-m-d H:i:s');
    $expiresAt = date('Y-m-d H:i:s', time() + ($timeout * 60));

    $stmt = $db->prepare(
        'INSERT INTO customer_sessions (id, customer_id, ip_address, user_agent, last_activity, expires_at)
         VALUES (:id, :cid, :ip, :ua, :last_activity, :expires_at)'
    );
    $stmt->execute([
        ':id' => $token,
        ':cid' => $customerId,
        ':ip' => getClientIp(),
        ':ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ':last_activity' => $now,
        ':expires_at' => $expiresAt
    ]);

    setcookie('X-Atlas-Client-Session', $token, [
        'expires' => time() + ($timeout * 60),
        'path' => '/',
        'domain' => '',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);

    if ($username !== '') {
        $db->prepare('UPDATE customer_portal_users SET last_login = NOW(), last_login_ip = :ip WHERE customer_id = :cid')
           ->execute([':ip' => getClientIp(), ':cid' => $customerId]);
        $db->prepare('UPDATE customer_portal_users SET failed_login_attempts = 0, locked_until = NULL WHERE customer_id = :cid')
           ->execute([':cid' => $customerId]);
    }

    return $token;
}

function destroyClientSession(PDO $db, string $token): void
{
    if ($token !== '') {
        $stmt = $db->prepare('DELETE FROM customer_sessions WHERE id = :id');
        $stmt->execute([':id' => $token]);
    }
    setcookie('X-Atlas-Client-Session', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => '',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
}

function issueClientStatementLink(PDO $db, int $customerId, string $accountNumber, ?string $fromDate = null, ?string $toDate = null, int $ttlSeconds = 300): string
{
    $ttlSeconds = max(60, min($ttlSeconds, 1800));
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $stmt = $db->prepare(
        'INSERT INTO customer_statement_links (token_hash, customer_id, account_number, period_start, period_end, created_at, expires_at, created_ip)
         VALUES (:th, :cid, :acc, :ps, :pe, :created_at, :expires_at, :ip)'
    );
    $stmt->execute([
        ':th' => $hash,
        ':cid' => $customerId,
        ':acc' => $accountNumber,
        ':ps' => $fromDate,
        ':pe' => $toDate,
        ':created_at' => date('Y-m-d H:i:s'),
        ':expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds),
        ':ip' => getClientIp()
    ]);
    return $token;
}

function resolveClientStatementLink(PDO $db, string $token): ?array
{
    $hash = hash('sha256', $token);
    $stmt = $db->prepare(
        'SELECT * FROM customer_statement_links WHERE token_hash = :th LIMIT 1'
    );
    $stmt->execute([':th' => $hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    if (!empty($row['used_at']) || strtotime((string)$row['expires_at']) <= time()) {
        return null;
    }
    $db->prepare('UPDATE customer_statement_links SET used_at = :used_at WHERE id = :id AND used_at IS NULL')
       ->execute([':used_at' => date('Y-m-d H:i:s'), ':id' => (int)$row['id']]);
    return $row;
}

function requireClientAuth(): array
{
    $db = getDB();
    ensureClientPortalTables($db);
    $token = getClientTokenFromRequest();
    if ($token === '') {
        unauthorizedResponse('Client authentication required.');
    }

    $stmt = $db->prepare(
        "SELECT s.id AS session_id, s.customer_id, s.last_activity, s.expires_at,
                c.customer_number, c.full_name, c.branch, c.status AS customer_status,
                cpu.username, cpu.require_password_change, cpu.mfa_required
         FROM customer_sessions s
         INNER JOIN customers c ON c.id = s.customer_id
         LEFT JOIN customer_portal_users cpu ON cpu.customer_id = c.id
         WHERE s.id = :token
         LIMIT 1"
    );
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        unauthorizedResponse('Invalid client session. Please log in again.');
    }

    $now = time();
    $expires = strtotime((string)$row['expires_at']);
    if ($expires <= $now) {
        destroyClientSession($db, $token);
        unauthorizedResponse('Session expired. Please log in again.');
    }

    $timeout = getClientSessionTimeoutMinutes($db);
    $newExpiry = date('Y-m-d H:i:s', $now + ($timeout * 60));
    $upd = $db->prepare('UPDATE customer_sessions SET last_activity = :last, expires_at = :exp WHERE id = :id');
    $upd->execute([
        ':last' => date('Y-m-d H:i:s'),
        ':exp' => $newExpiry,
        ':id' => $token
    ]);

    setcookie('X-Atlas-Client-Session', $token, [
        'expires' => $now + ($timeout * 60),
        'path' => '/',
        'domain' => '',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);

    return [
        'customer_id' => (int)$row['customer_id'],
        'customer_number' => (string)($row['customer_number'] ?? ''),
        'full_name' => (string)($row['full_name'] ?? ''),
        'branch' => (string)($row['branch'] ?? ''),
        'username' => (string)($row['username'] ?? ''),
        'require_password_change' => !empty($row['require_password_change']),
        'mfa_required' => !empty($row['mfa_required']),
        'session_token' => $token,
        'session_expires_at' => $newExpiry
    ];
}
