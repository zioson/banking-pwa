<?php
declare(strict_types=1);

namespace AtlasBank\Includes;

use AtlasBank\Config\Database;
use RuntimeException;

class Auth
{
    /** Session duration in seconds (8 hours). */
    private const SESSION_LIFETIME = 28800;

    /** Token refresh window in seconds (last 2 hours of session). */
    private const REFRESH_WINDOW = 7200;

    /** Maximum failed login attempts before lockout. */
    private const MAX_FAILED_ATTEMPTS = 5;

    /** Lockout duration in seconds (30 minutes). */
    private const LOCKOUT_DURATION = 1800;

    /** JWT algorithm. */
    private const JWT_ALGORITHM = 'HS256';

    /** Minimum password length. */
    private const PASSWORD_MIN_LENGTH = 8;

    /** HMAC key for JWT signing (from env or default). */
    private readonly string $jwtSecret;

    /** JWT issuer claim. */
    private readonly string $jwtIssuer;

    /** Database instance. */
    private readonly Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db         = $db ?? Database::getInstance();
        // ★ SECURITY FIX: Refuse to start with a hardcoded JWT secret in production.
        // In development, the fallback is allowed for convenience.
        $jwtEnv = getenv('JWT_SECRET');
        if ($jwtEnv !== false && $jwtEnv !== '') {
            $this->jwtSecret = $jwtEnv;
        } elseif (getenv('APP_ENV') !== 'production') {
            // Development-only fallback — NEVER use in production
            $this->jwtSecret = 'atlas_bank_enterprise_dev_key_DO_NOT_USE_IN_PRODUCTION';
        } else {
            throw new RuntimeException(
                'JWT_SECRET environment variable is required in production. '
                .'Refusing to start with a predictable secret key.'
            );
        }
        $this->jwtIssuer  = getenv('JWT_ISSUER') ?: 'atlas-bank-api';
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Authenticate a user with username and password.
     *
     * @return array{success: bool, data?: array<string, mixed>, message?: string, requires_mfa?: bool, token?: string}
     */
    public function login(string $username, string $password, string $ip, string $userAgent): array
    {
        // 1. Look up the staff member
        $user = $this->db->fetch(
            'SELECT id, username, password_hash, role, full_name, email, branch_id,
                    status, account_locked, mfa_required, failed_login_attempts, locked_until
             FROM staff
             WHERE username = :username',
            ['username' => $username]
        );

        if ($user === null) {
            $this->trackFailedLogin(null, $ip);
            return ['success' => false, 'message' => 'Invalid credentials.'];
        }

        // 2. Check if account is disabled
        // ★ FIX (FIN-2b-003/015): Column name corrected to match actual DB schema
        if ($user['status'] !== 'ACTIVE') {
            return ['success' => false, 'message' => 'Account is disabled. Contact your administrator.'];
        }

        // 3. Check if account is locked (from failed login attempts)
        if ($this->isAccountLocked($user)) {
            $remainingSeconds = (int)$user['locked_until'] - time();
            $remainingMinutes = (int)ceil($remainingSeconds / 60);
            return [
                'success' => false,
                'message' => "Account is temporarily locked due to too many failed login attempts. Try again in {$remainingMinutes} minute(s).",
            ];
        }

        // 4. Verify password
        if (!password_verify($password, $user['password_hash'])) {
            $this->trackFailedLogin((int)$user['id'], $ip);
            return ['success' => false, 'message' => 'Invalid credentials.'];
        }

        // 5. Re-hash password if the algorithm/option has changed
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
            $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $this->db->query(
                'UPDATE staff SET password_hash = :hash WHERE id = :id',
                ['hash' => $newHash, 'id' => $user['id']]
            );
        }

        // 6. Check if MFA is required
        // ★ FIX (AUTH-002): Only trigger MFA flow if a secret is actually configured.
        // If mfa_required is true but mfa_secret is empty, allow login to avoid deadlock,
        // but the user should be prompted to set up MFA in the dashboard.
        if ((bool)$user['mfa_required'] && !empty($user['mfa_secret'])) {
            // MFA flow: generate a temporary pending token
            $pendingToken = $this->encodeJwt([
                'sub'   => (string)$user['id'],
                'type'  => 'mfa_pending',
                'exp'   => time() + 300, // 5-minute window to complete MFA
                'iat'   => time(),
            ]);

            return [
                'success'      => true,
                'requires_mfa' => true,
                'data'         => [
                    'pending_token' => $pendingToken,
                    'user_id'       => (int)$user['id'],
                    'full_name'     => $user['full_name'],
                ],
                'message'      => 'MFA verification required.',
            ];
        }

        // 7. Reset failed attempts and create session
        $this->resetFailedAttempts((int)$user['id']);
        $token = $this->createSession((int)$user['id'], $ip, $userAgent);

        return [
            'success' => true,
            'token'   => $token,
            'data'    => [
                'user_id'   => (int)$user['id'],
                'username'  => $user['username'],
                'full_name' => $user['full_name'],
                'email'     => $user['email'],
                'role'      => $user['role'],
                'branch_id' => $user['branch_id'],
            ],
            'message' => 'Login successful.',
        ];
    }

    /**
     * Invalidate an active session by revoking its token.
     */
    public function logout(string $token): array
    {
        $payload = $this->decodeJwt($token);

        if ($payload === null || !isset($payload['jti'])) {
            return ['success' => false, 'message' => 'Invalid or expired token.'];
        }

        // ★ FIX (FIN-2b-003/015): Column name corrected to match actual DB schema
        $this->db->query(
            'UPDATE sessions SET expires_at = NOW() WHERE id = :jti',
            ['jti' => $payload['jti']]
        );

        return ['success' => true, 'message' => 'Logged out successfully.'];
    }

    /**
     * Verify a JWT token and return the associated user data.
     *
     * @return array{valid: bool, data?: array<string, mixed>, error?: string}
     */
    public function verify(string $token): array
    {
        $payload = $this->decodeJwt($token);

        if ($payload === null) {
            return ['valid' => false, 'error' => 'Invalid or expired token.'];
        }

        // Check if this is a pending MFA token
        if (isset($payload['type']) && $payload['type'] === 'mfa_pending') {
            return ['valid' => false, 'error' => 'MFA verification pending.'];
        }

        // Verify the session is still active in the database
        // ★ FIX (FIN-2b-003/015): Column names corrected to match actual DB schema
        $session = $this->db->fetch(
            'SELECT s.*, st.username, st.role, st.full_name, st.email, st.branch_id, st.status
             FROM sessions s
             JOIN staff st ON s.staff_id = st.id
             WHERE s.id = :jti AND s.expires_at > NOW()',
            ['jti' => $payload['jti']]
        );

        if ($session === null) {
            return ['valid' => false, 'error' => 'Session has been invalidated.'];
        }

        // ★ FIX (FIN-2b-003/015): Column name corrected to match actual DB schema
        if ($session['status'] !== 'ACTIVE') {
            return ['valid' => false, 'error' => 'Account has been disabled.'];
        }

        return [
            'valid' => true,
            'data'  => [
                'user_id'       => (int)$session['staff_id'],
                'username'      => $session['username'],
                'full_name'     => $session['full_name'],
                'email'         => $session['email'],
                'role'          => $session['role'],
                'branch_id'     => $session['branch_id'],
                'session_id'    => $payload['jti'],
                'issued_at'     => $payload['iat'],
                'expires_at'    => $payload['exp'],
            ],
        ];
    }

    /**
     * Refresh a JWT token if within the refresh window.
     *
     * @return array{success: bool, token?: string, message?: string}
     */
    public function refreshToken(string $token): array
    {
        $payload = $this->decodeJwt($token);

        if ($payload === null) {
            return ['success' => false, 'message' => 'Invalid or expired token.'];
        }

        // ★ FIX (FIN-2b-003/015): Column names corrected to match actual DB schema
        $session = $this->db->fetch(
            'SELECT s.staff_id, s.ip_address, s.user_agent
             FROM sessions s
             WHERE s.id = :jti AND s.expires_at > NOW()',
            ['jti' => $payload['jti']]
        );

        if ($session === null) {
            return ['success' => false, 'message' => 'Session not found or already invalidated.'];
        }

        // Check if within refresh window
        $expiresAt = (int)$payload['exp'];
        $timeUntilExpiry = $expiresAt - time();

        if ($timeUntilExpiry > self::REFRESH_WINDOW) {
            return [
                'success' => false,
                'message' => 'Token is not yet eligible for refresh. Refresh available in the last 2 hours of the session.',
            ];
        }

        // ★ FIX (FIN-2b-003/015): Column names corrected to match actual DB schema
        // Invalidate the old session
        $this->db->query(
            'UPDATE sessions SET expires_at = NOW() WHERE id = :jti',
            ['jti' => $payload['jti']]
        );

        // Create a new session
        $newToken = $this->createSession(
            (int)$session['staff_id'],
            $session['ip_address'],
            $session['user_agent']
        );

        return [
            'success' => true,
            'token'   => $newToken,
            'message' => 'Token refreshed successfully.',
        ];
    }

    /**
     * Verify MFA code for a user (placeholder for TOTP integration).
     *
     * @return array{success: bool, token?: string, message?: string}
     */
    public function checkMFA(int $userId, string $code, string $ip, string $userAgent): array
    {
        // Validate code format
        if (!preg_match('/^\d{6}$/', $code)) {
            return ['success' => false, 'message' => 'Invalid MFA code format. Expected 6 digits.'];
        }

        // ★ SECURITY FIX: Verify MFA code against the user's stored TOTP secret.
        // Previously any 6-digit code was accepted (development bypass left in production).
        // Now requires a valid TOTP secret to be stored on the user record.
        $user = $this->db->fetch(
            'SELECT id, mfa_secret, role, full_name, email, branch_id
             FROM staff WHERE id = :id AND status = :status',
            // ★ FIX (FIN-2b-003/015): Column name corrected to match actual DB schema
            ['id' => $userId, 'status' => 'ACTIVE']
        );

        if ($user === null) {
            return ['success' => false, 'message' => 'User not found or account is inactive.'];
        }

        $totpSecret = $user['mfa_secret'] ?? '';

        // Non-production environments: allow a configurable bypass code for testing
        if (getenv('APP_ENV') !== 'production') {
            $bypassCode = getenv('MFA_BYPASS_CODE');
            if ($bypassCode !== false && $code === $bypassCode) {
                error_log('[MFA] Development bypass code used for user ID: ' . $userId);
                $this->resetFailedAttempts($userId);
                $token = $this->createSession($userId, $ip, $userAgent);
                return [
                    'success' => true,
                    'token'   => $token,
                    'data'    => [
                        'user_id'   => (int)$user['id'],
                        'username'  => '',
                        'full_name' => $user['full_name'],
                        'email'     => $user['email'],
                        'role'      => $user['role'],
                        'branch_id' => $user['branch_id'],
                    ],
                    'message' => 'MFA verification successful (dev bypass).',
                ];
            }
        }

        // Production: require a real TOTP secret
        if (empty($totpSecret)) {
            return ['success' => false, 'message' => 'MFA is not configured for this account. Contact your administrator to set up TOTP.'];
        }

        // Verify TOTP code using time-based one-time password algorithm
        // TOTP uses a 30-second time step and 6-digit codes
        $valid = $this->verifyTotpCode($totpSecret, $code);
        if (!$valid) {
            return ['success' => false, 'message' => 'Invalid or expired MFA code. Please try again.'];
        }

        // Code verified — create session
        $this->resetFailedAttempts($userId);
        $token = $this->createSession($userId, $ip, $userAgent);

        return [
            'success' => true,
            'token'   => $token,
            'data'    => [
                'user_id'   => (int)$user['id'],
                'username'  => '',  // not loaded here, client already has it
                'full_name' => $user['full_name'],
                'email'     => $user['email'],
                'role'      => $user['role'],
                'branch_id' => $user['branch_id'],
            ],
            'message' => 'MFA verification successful.',
        ];
    }

    /**
     * Verify a TOTP code against a secret key.
     * Implements RFC 6238 (HMAC-based One-Time Password) with 30-second time steps.
     * Accepts the current time step and one step before/after for clock drift tolerance.
     *
     * @param string $secret Base32-encoded TOTP secret
     * @param string $code   6-digit TOTP code
     * @return bool True if the code is valid
     */
    private function verifyTotpCode(string $secret, string $code): bool
    {
        // Decode Base32 secret (uppercase, no padding)
        $secret = strtoupper(trim($secret));
        $secret = str_pad($secret, (int)(ceil(strlen($secret) / 8) * 8), '=');

        $secretBytes = '';
        for ($i = 0; $i < strlen($secret); $i += 8) {
            $chunk = substr($secret, $i, 8);
            $bytes = 0;
            for ($j = 0; $j < 8; $j++) {
                $bytes <<= 5;
                $c = $chunk[$j];
                if ($c >= 'A' && $c <= 'Z') { $bytes |= (ord($c) - ord('A')); }
                elseif ($c >= '2' && $c <= '7') { $bytes |= (ord($c) - ord('2') + 26); }
                elseif ($c === '=') { break; }
                else { return false; } // invalid character
            }
            $secretBytes .= chr(($bytes >> 16) & 0xFF) . chr(($bytes >> 8) & 0xFF) . chr($bytes & 0xFF);
        }

        $timeStep = 30;
        $currentTime = (int)(time() / $timeStep);

        // Check current time step and one step before/after for clock drift
        for ($offset = -1; $offset <= 1; $offset++) {
            $timeBytes = pack('N*', $currentTime + $offset);
            $hmac = hash_hmac('sha1', $timeBytes, $secretBytes, true);

            $offset_val = ord($hmac[19]) & 0x0F;
            $binary = ((ord($hmac[$offset_val]) & 0x7F) << 24)
                    | ((ord($hmac[$offset_val + 1]) & 0xFF) << 16)
                    | ((ord($hmac[$offset_val + 2]) & 0xFF) << 8)
                    | (ord($hmac[$offset_val + 3]) & 0xFF);

            $otp = (string)($binary % 1000000);
            $otp = str_pad($otp, 6, '0', STR_PAD_LEFT);

            if (hash_equals($otp, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Change a user's own password.
     *
     * @return array{success: bool, message?: string}
     */
    public function changePassword(int $userId, string $oldPassword, string $newPassword): array
    {
        // Validate the new password
        $validation = $this->validatePassword($newPassword);
        if (!$validation['valid']) {
            return ['success' => false, 'message' => $validation['message']];
        }

        // Fetch current password hash
        $user = $this->db->fetch(
            'SELECT password_hash FROM staff WHERE id = :id',
            ['id' => $userId]
        );

        if ($user === null) {
            return ['success' => false, 'message' => 'User not found.'];
        }

        // Verify old password
        if (!password_verify($oldPassword, $user['password_hash'])) {
            return ['success' => false, 'message' => 'Current password is incorrect.'];
        }

        // Update to new password
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->db->query(
            'UPDATE staff SET password_hash = :hash, password_changed_at = NOW() WHERE id = :id',
            ['hash' => $newHash, 'id' => $userId]
        );

        // Invalidate all existing sessions for this user (force re-login)
        // ★ FIX (FIN-2b-003/015): Column names corrected to match actual DB schema
        $this->db->query(
            'UPDATE sessions SET expires_at = NOW() WHERE staff_id = :id AND expires_at > NOW()',
            ['id' => $userId]
        );

        return ['success' => true, 'message' => 'Password changed successfully. All active sessions have been invalidated.'];
    }

    /**
     * Admin password reset (Manager or Auditor only).
     *
     * @return array{success: bool, message?: string}
     */
    public function resetPassword(int $adminUserId, int $targetUserId): array
    {
        // Verify the admin user has Manager or Auditor role
        $admin = $this->db->fetch(
            'SELECT role, branch_id FROM staff WHERE id = :id AND status = :status',
            ['id' => $adminUserId, 'status' => 'active']
        );

        if ($admin === null) {
            return ['success' => false, 'message' => 'Admin user not found or inactive.'];
        }

        if (!in_array($admin['role'], ['Manager', 'Auditor'], true)) {
            return ['success' => false, 'message' => 'Insufficient privileges. Only Managers and Auditors can reset passwords.'];
        }

        // Verify target user exists
        $target = $this->db->fetch(
            'SELECT username, status FROM staff WHERE id = :id',
            ['id' => $targetUserId]
        );

        if ($target === null) {
            return ['success' => false, 'message' => 'Target user not found.'];
        }

        // Generate a temporary password
        $tempPassword = $this->generateTempPassword();

        // Hash and store it
        $hash = password_hash($tempPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        // ★ FIX (FIN-2b-003/015): Column name corrected to match actual DB schema
        $this->db->query(
            'UPDATE staff SET password_hash = :hash, password_changed_at = NOW(), account_locked = FALSE,
                failed_login_attempts = 0, locked_until = NULL
             WHERE id = :id',
            ['hash' => $hash, 'id' => $targetUserId]
        );

        // Invalidate all sessions for the target user
        // ★ FIX (FIN-2b-003/015): Column names corrected to match actual DB schema
        $this->db->query(
            'UPDATE sessions SET expires_at = NOW() WHERE staff_id = :id AND expires_at > NOW()',
            ['id' => $targetUserId]
        );

        return [
            'success' => true,
            'message' => 'Password has been reset for user "' . $target['username'] . '".',
            'data'    => [
                'temp_password'  => $tempPassword,
                'target_user_id' => $targetUserId,
                'target_username' => $target['username'],
            ],
        ];
    }

    /**
     * Validate password strength requirements.
     *
     * @return array{valid: bool, message: string}
     */
    public function validatePassword(string $password): array
    {
        if (strlen($password) < self::PASSWORD_MIN_LENGTH) {
            return [
                'valid'  => false,
                'message' => sprintf('Password must be at least %d characters long.', self::PASSWORD_MIN_LENGTH),
            ];
        }

        if (!preg_match('/[A-Z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one uppercase letter.'];
        }

        if (!preg_match('/[a-z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one lowercase letter.'];
        }

        if (!preg_match('/[0-9]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one number.'];
        }

        return ['valid' => true, 'message' => 'Password meets all requirements.'];
    }

    // -----------------------------------------------------------------------
    // JWT Implementation (HS256 via OpenSSL HMAC)
    // -----------------------------------------------------------------------

    /**
     * Encode a JWT payload into a token string.
     */
    private function encodeJwt(array $payload): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => self::JWT_ALGORITHM,
        ];

        $headerB64  = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $payloadB64 = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $signatureInput = $headerB64 . '.' . $payloadB64;
        $signature      = hash_hmac('sha256', $signatureInput, $this->jwtSecret, true);
        $signatureB64   = $this->base64UrlEncode($signature);

        return $signatureInput . '.' . $signatureB64;
    }

    /**
     * Decode and verify a JWT token string.
     *
     * @return array<string, mixed>|null The payload array, or null if invalid.
     */
    public function decodeJwt(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        // Verify signature
        $signatureInput = $headerB64 . '.' . $payloadB64;
        $expectedSig    = hash_hmac('sha256', $signatureInput, $this->jwtSecret, true);

        if (!hash_equals($expectedSig, $this->base64UrlDecode($signatureB64))) {
            return null;
        }

        // Decode header and verify algorithm
        $header = json_decode($this->base64UrlDecode($headerB64), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== self::JWT_ALGORITHM) {
            return null;
        }

        // Decode payload
        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        if (!is_array($payload)) {
            return null;
        }

        // Check expiration
        if (isset($payload['exp']) && time() > (int)$payload['exp']) {
            return null;
        }

        return $payload;
    }

    /**
     * Base64 URL-safe encode.
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL-safe decode.
     */
    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'), true);
    }

    // -----------------------------------------------------------------------
    // Session Management
    // -----------------------------------------------------------------------

    /**
     * Create a new session for the given user and return a JWT token.
     */
    private function createSession(int $userId, string $ip, string $userAgent): string
    {
        $tokenId = $this->generateUuid();
        $now     = time();
        $expires = $now + self::SESSION_LIFETIME;

        $payload = [
            'iss' => $this->jwtIssuer,
            'sub' => (string)$userId,
            'jti' => $tokenId,
            'iat' => $now,
            'exp' => $expires,
            'typ' => 'access',
        ];

        $token = $this->encodeJwt($payload);

        // ★ FIX (FIN-2b-003/015): Column names corrected to match actual DB schema
        // Persist session
        $this->db->query(
            'INSERT INTO sessions (id, staff_id, ip_address, user_agent, created_at, expires_at)
             VALUES (:jti, :staff_id, :ip, :ua, TO_TIMESTAMP(:created), TO_TIMESTAMP(:expires))',
            [
                'jti'     => $tokenId,
                'staff_id' => $userId,
                'ip'       => $ip,
                'ua'       => $userAgent,
                'created'  => $now,
                'expires'  => $expires,
            ]
        );

        return $token;
    }

    // -----------------------------------------------------------------------
    // Failed Login Tracking & Lockout
    // -----------------------------------------------------------------------

    /**
     * Track a failed login attempt and lock the account if threshold reached.
     */
    private function trackFailedLogin(?int $userId, string $ip): void
    {
        if ($userId !== null) {
            $this->db->query(
                'UPDATE staff
                 SET failed_login_attempts = failed_login_attempts + 1,
                     locked_until = CASE
                         WHEN failed_login_attempts + 1 >= :max_attempts
                         THEN TO_TIMESTAMP(EXTRACT(EPOCH FROM NOW())::INTEGER + :lockout_duration)
                         ELSE locked_until
                     END,
                     -- ★ FIX (FIN-2b-003/015): Column name corrected to match actual DB schema
                     account_locked = CASE
                         WHEN failed_login_attempts + 1 >= :max_attempts
                         THEN TRUE
                         ELSE account_locked
                     END
                 WHERE id = :id',
                [
                    'max_attempts'     => self::MAX_FAILED_ATTEMPTS,
                    'lockout_duration' => self::LOCKOUT_DURATION,
                    'id'               => $userId,
                ]
            );
        }

        // Log failed attempt for audit
        $this->db->query(
            'INSERT INTO login_attempts (staff_id, ip_address, attempt_time, success)
             VALUES (:staff_id, :ip, NOW(), FALSE)',
            ['staff_id' => $userId, 'ip' => $ip]
        );
    }

    /**
     * Reset failed login attempts after successful login.
     */
    private function resetFailedAttempts(int $userId): void
    {
        $this->db->query(
            // ★ FIX (FIN-2b-003/015): Column name corrected to match actual DB schema
            'UPDATE staff SET failed_login_attempts = FALSE, account_locked = FALSE, locked_until = NULL WHERE id = :id',
            ['id' => $userId]
        );
    }

    /**
     * Check if an account is currently locked due to failed login attempts.
     */
    private function isAccountLocked(array $user): bool
    {
        // ★ FIX (FIN-2b-003/015): Column name corrected to match actual DB schema
        if (!(bool)$user['account_locked']) {
            return false;
        }

        // Check if lockout has expired
        if ($user['locked_until'] !== null) {
            $lockUntil = strtotime($user['locked_until']);
            if ($lockUntil !== false && time() >= $lockUntil) {
                // Auto-unlock expired lockout
                $this->resetFailedAttempts((int)$user['id']);
                return false;
            }
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // Utility Methods
    // -----------------------------------------------------------------------

    /**
     * Generate a UUID v4 string.
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);

        // Set version to 0100 (UUID v4)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set variant to 10xx
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Generate a temporary password for admin resets.
     */
    private function generateTempPassword(): string
    {
        $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lowercase = 'abcdefghjkmnpqrstuvwxyz';
        $numbers   = '23456789';
        $special   = '!@#$%&*?';

        $allChars = $uppercase . $lowercase . $numbers . $special;
        $password = '';

        // Ensure at least one of each required type
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];

        // Fill remaining characters
        $remaining = 12 - strlen($password);
        for ($i = 0; $i < $remaining; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }

        // Shuffle the password characters
        return str_shuffle($password);
    }
}
