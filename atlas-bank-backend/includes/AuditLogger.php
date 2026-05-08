<?php
declare(strict_types=1);

namespace AtlasBank\Includes;

use AtlasBank\Config\Database;
use RuntimeException;

class AuditLogger
{
 // -----------------------------------------------------------------------
 // Action Constants
 // -----------------------------------------------------------------------

 // Authentication
 public const LOGIN_SUCCESS = 'LOGIN_SUCCESS';
 public const LOGIN_FAILURE = 'LOGIN_FAILURE';
 public const LOGOUT = 'LOGOUT';
 public const TOKEN_REFRESH = 'TOKEN_REFRESH';
 public const PASSWORD_CHANGE = 'PASSWORD_CHANGE';
 public const PASSWORD_RESET = 'PASSWORD_RESET';
 public const MFA_ATTEMPT = 'MFA_ATTEMPT';
 public const MFA_SUCCESS = 'MFA_SUCCESS';
 public const MFA_FAILURE = 'MFA_FAILURE';

 // Transaction Operations
 public const TXN_CREATE = 'TXN_CREATE';
 public const TXN_UPDATE = 'TXN_UPDATE';
 public const TXN_CANCEL = 'TXN_CANCEL';
 public const TXN_REVERSE = 'TXN_REVERSE';
 public const TXN_VIEW = 'TXN_VIEW';
 public const TXN_EXPORT = 'TXN_EXPORT';

 // Approval Operations
 public const APPROVAL_CREATE = 'APPROVAL_CREATE';
 public const APPROVAL_DECISION = 'APPROVAL_DECISION';
 public const APPROVAL_APPROVE = 'APPROVAL_APPROVE';
 public const APPROVAL_REJECT = 'APPROVAL_REJECT';
 public const APPROVAL_ESCALATE = 'APPROVAL_ESCALATE';

 // Staff / User Management
 public const STAFF_CREATE = 'STAFF_CREATE';
 public const STAFF_UPDATE = 'STAFF_UPDATE';
 public const STAFF_DELETE = 'STAFF_DELETE';
 public const STAFF_DEACTIVATE = 'STAFF_DEACTIVATE';
 public const STAFF_REACTIVATE = 'STAFF_REACTIVATE';

 // Settings & Configuration
 public const SETTINGS_UPDATE = 'SETTINGS_UPDATE';
 public const SETTINGS_VIEW = 'SETTINGS_VIEW';
 public const ROLE_CREATE = 'ROLE_CREATE';
 public const ROLE_UPDATE = 'ROLE_UPDATE';
 public const ROLE_DELETE = 'ROLE_DELETE';
 public const PERMISSION_UPDATE = 'PERMISSION_UPDATE';

 // Branch Operations
 public const BRANCH_CREATE = 'BRANCH_CREATE';
 public const BRANCH_UPDATE = 'BRANCH_UPDATE';
 public const BRANCH_DEACTIVATE = 'BRANCH_DEACTIVATE';

 // Reports & Data
 public const REPORT_GENERATE = 'REPORT_GENERATE';
 public const REPORT_EXPORT = 'REPORT_EXPORT';
 public const DATA_EXPORT = 'DATA_EXPORT';
 public const DATA_IMPORT = 'DATA_IMPORT';

 // System
 public const SYSTEM_BACKUP = 'SYSTEM_BACKUP';
 public const SYSTEM_RESTORE = 'SYSTEM_RESTORE';
 public const SYSTEM_CONFIG = 'SYSTEM_CONFIG';

 /** Audit log detail level for debug environments. */
 private const MAX_DETAIL_LENGTH = 4096;

 private readonly Database $db;

 public function __construct(?Database $db = null)
 {
 $this->db = $db ?? Database::getInstance();
 }

 // -----------------------------------------------------------------------
 // Core Logging Method
 // -----------------------------------------------------------------------

 /**
 * Write an entry to the audit_logs table.
 *
 * @param string $actor Who performed the action (username or system identifier).
 * @param int|string|null $actorBranch The actor's branch ID, or null.
 * @param string $action One of the class action constants.
 * @param string $entity Entity type (e.g., 'staff', 'transaction', 'settings').
 * @param int|string|null $entityId Primary key of the affected entity, or null.
 * @param string $result Result of the action: 'success', 'failure', 'error'.
 * @param string $ip IP address of the actor.
 * @param array<string, mixed>|string|null $details Additional context or serialized data.
 *
 * @return string The UUID of the created audit log entry.
 */
 public function log(
 string $actor,
 int|string|null $actorBranch,
 string $action,
 string $entity,
 int|string|null $entityId,
 string $result,
 string $ip,
 array|string|null $details = null
): string {
 $id = $this->generateUuid();

 // Normalize and truncate details
 $detailJson = $this->serializeDetails($details);

 try {
 // ★ FIX (FIN-2b-007): Aligned column names with procedural logAudit() in helpers.php
 $this->db->query(
 <<<'SQL'
 INSERT INTO audit_logs
 (uuid, actor, actor_branch, action, entity, entity_id, result, ip, details, timestamp)
 VALUES
 (:id, :actor, :actor_branch, :action, :entity, :entity_id, :result, :ip, :details, NOW())
 SQL,
 [
 'id' => $id,
 'actor' => $actor,
 'actor_branch' => $actorBranch,
 'action' => $action,
 'entity' => $entity,
 'entity_id' => $entityId,
 'result' => $result,
 'ip' => $ip,
 'details' => $detailJson,
 ]
 );
 } catch (\Throwable $e) {
 // Fallback to error_log if DB write fails — audit should never crash the app
 error_log(sprintf(
 '[AtlasBank AuditLogger] Failed to write audit log: %s | Action: %s | Entity: %s | Actor: %s',
 $e->getMessage(),
 $action,
 $entity,
 $actor
 ));
 }

 return $id;
 }

 // -----------------------------------------------------------------------
 // Convenience Methods
 // -----------------------------------------------------------------------

 /**
 * Log a successful action.
 */
 public function logSuccess(
 string $actor,
 int|string|null $actorBranch,
 string $action,
 string $entity,
 int|string|null $entityId = null,
 string $ip = '',
 array|string|null $details = null
): string {
 return $this->log($actor, $actorBranch, $action, $entity, $entityId, 'success', $ip, $details);
 }

 /**
 * Log a failed action.
 */
 public function logFailure(
 string $actor,
 int|string|null $actorBranch,
 string $action,
 string $entity,
 int|string|null $entityId = null,
 string $ip = '',
 array|string|null $details = null
): string {
 return $this->log($actor, $actorBranch, $action, $entity, $entityId, 'failure', $ip, $details);
 }

 /**
 * Log a system error.
 */
 public function logError(
 string $actor,
 int|string|null $actorBranch,
 string $action,
 string $entity,
 int|string|null $entityId = null,
 string $ip = '',
 array|string|null $details = null
): string {
 return $this->log($actor, $actorBranch, $action, $entity, $entityId, 'error', $ip, $details);
 }

 // -----------------------------------------------------------------------
 // Query Methods
 // -----------------------------------------------------------------------

 /**
 * Retrieve audit log entries with optional filters and pagination.
 *
 * @param array<string, mixed> $filters Supported keys: actor, action, entity, entity_id, result, date_from, date_to.
 * @param int $page Page number (1-based).
 * @param int $pageSize Items per page.
 *
 * @return array{data: array<int, array<string, mixed>>, total: int, page: int, page_size: int}
 */
 public function getLogs(array $filters = [], int $page = 1, int $pageSize = 50): array
 {
 $where = ['1 = 1'];
 $params = [];

 $allowedFilters = [
 'actor', 'action', 'entity', 'entity_id', 'result',
 'date_from', 'date_to',
 ];

 foreach ($filters as $key => $value) {
 if (!in_array($key, $allowedFilters, true) || $value === '' || $value === null) {
 continue;
 }

 switch ($key) {
 case 'date_from':
 $where[] = 'created_at >= :date_from';
 $params['date_from'] = $value;
 break;
 case 'date_to':
 $where[] = 'created_at <= :date_to';
 $params['date_to'] = $value . ' 23:59:59';
 break;
 default:
 $where[] = "{$key} = :{$key}";
 $params[$key] = $value;
 break;
 }
 }

 $whereClause = implode(' AND ', $where);
 $offset = max(0, ($page - 1) * $pageSize);

 $total = (int)$this->db->fetch(
 "SELECT COUNT(*) AS cnt FROM audit_logs WHERE {$whereClause}",
 $params
 )['cnt'];

 $data = $this->db->fetchAll(
 "SELECT * FROM audit_logs WHERE {$whereClause} ORDER BY created_at DESC LIMIT :limit OFFSET :offset",
 array_merge($params, ['limit' => $pageSize, 'offset' => $offset])
 );

 return [
 'data' => $data,
 'total' => $total,
 'page' => $page,
 'page_size' => $pageSize,
 ];
 }

 /**
 * Get audit logs for a specific entity.
 *
 * @return array<int, array<string, mixed>>
 */
 public function getEntityHistory(string $entity, string|int $entityId, int $limit = 50): array
 {
 return $this->db->fetchAll(
 'SELECT * FROM audit_logs WHERE entity = :entity AND entity_id = :entity_id ORDER BY created_at DESC LIMIT :limit',
 ['entity' => $entity, 'entity_id' => (string)$entityId, 'limit' => $limit]
 );
 }

 /**
 * Get audit logs for a specific actor.
 *
 * @return array<int, array<string, mixed>>
 */
 public function getActorHistory(string $actor, int $limit = 50): array
 {
 return $this->db->fetchAll(
 'SELECT * FROM audit_logs WHERE actor = :actor ORDER BY created_at DESC LIMIT :limit',
 ['actor' => $actor, 'limit' => $limit]
 );
 }

 // -----------------------------------------------------------------------
 // Internal Helpers
 // -----------------------------------------------------------------------

 /**
 * Serialize details to a JSON string, truncating if necessary.
 */
 private function serializeDetails(array|string|null $details): ?string
 {
 if ($details === null) {
 return null;
 }

 $json = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

 if ($json === false) {
 $json = json_encode(['raw' => (string)$details]);
 }

 if (strlen($json) > self::MAX_DETAIL_LENGTH) {
 $json = substr($json, 0, self::MAX_DETAIL_LENGTH) . '... [truncated]';
 }

 return $json;
 }

 /**
 * Generate a UUID v4 string.
 */
 private function generateUuid(): string
 {
 $data = random_bytes(16);
 $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
 $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
 return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
 }
}
