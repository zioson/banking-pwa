<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Policies (Bank Policies & Procedures)
 *
 * Enterprise-grade policy management with:
 *  - Full version history tracking (policy_revisions table)
 *  - Create / Edit / Archive / Restore lifecycle
 *  - Revision diff and rollback support
 *  - Expiry monitoring
 */
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

$staff = requireAuth();
$method = $_ROUTE['method'];
$id     = $_ROUTE['id'];
$sub    = $_ROUTE['subResource'] ?? null;  // e.g. policies/{id}/revisions, policies/{id}/archive

$db = getDB();

// ── Main policies table ──
$db->exec("CREATE TABLE IF NOT EXISTS `policies` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL,
    `version` VARCHAR(20) DEFAULT '1.0',
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `content` LONGTEXT DEFAULT NULL,
    `category` VARCHAR(100) DEFAULT 'GENERAL',
    `status` VARCHAR(20) DEFAULT 'ACTIVE',
    `severity` VARCHAR(20) DEFAULT 'MEDIUM',
    `owner` VARCHAR(191) DEFAULT NULL,
    `review_cycle_days` INT DEFAULT 365,
    `effective_from` DATE DEFAULT NULL,
    `effective_to` DATE DEFAULT NULL,
    `last_reviewed_at` DATE DEFAULT NULL,
    `next_review_date` DATE DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` VARCHAR(191) DEFAULT NULL,
    UNIQUE KEY `uk_code_version` (`code`, `version`),
    KEY `idx_category` (`category`),
    KEY `idx_status` (`status`),
    KEY `idx_effective_to` (`effective_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Revisions history table ──
$db->exec("CREATE TABLE IF NOT EXISTS `policy_revisions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `policy_id` INT NOT NULL,
    `revision` INT NOT NULL DEFAULT 1,
    `version` VARCHAR(20) DEFAULT '1.0',
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `content` LONGTEXT DEFAULT NULL,
    `category` VARCHAR(100) DEFAULT NULL,
    `severity` VARCHAR(20) DEFAULT NULL,
    `owner` VARCHAR(191) DEFAULT NULL,
    `effective_from` DATE DEFAULT NULL,
    `effective_to` DATE DEFAULT NULL,
    `change_summary` TEXT DEFAULT NULL,
    `changed_by` VARCHAR(191) DEFAULT NULL,
    `changed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_policy_id` (`policy_id`),
    KEY `idx_revision` (`policy_id`, `revision`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Self-heal: migrate old unique key + add missing columns ──
// NOTE: No AFTER clauses — they fail if the reference column doesn't exist yet,
// causing cascading failures for subsequent columns.
foreach ([
    "ALTER TABLE `policies` ADD COLUMN `version` VARCHAR(20) DEFAULT '1.0'",
    "ALTER TABLE `policies` ADD COLUMN `category` VARCHAR(100) DEFAULT 'GENERAL'",
    "ALTER TABLE `policies` ADD COLUMN `status` VARCHAR(20) DEFAULT 'ACTIVE'",
    "ALTER TABLE `policies` ADD COLUMN `content` LONGTEXT DEFAULT NULL",
    "ALTER TABLE `policies` ADD COLUMN `severity` VARCHAR(20) DEFAULT 'MEDIUM'",
    "ALTER TABLE `policies` ADD COLUMN `owner` VARCHAR(191) DEFAULT NULL",
    "ALTER TABLE `policies` ADD COLUMN `review_cycle_days` INT DEFAULT 365",
    "ALTER TABLE `policies` ADD COLUMN `last_reviewed_at` DATE DEFAULT NULL",
    "ALTER TABLE `policies` ADD COLUMN `next_review_date` DATE DEFAULT NULL",
    "ALTER TABLE `policies` ADD COLUMN `created_by` VARCHAR(191) DEFAULT NULL"
] as $alterSql) {
    try { $db->exec($alterSql); } catch (PDOException $e) { /* column already exists */ }
}

// Migrate old UNIQUE KEY uk_code(code) → uk_code_version(code, version)
try {
    $db->exec("ALTER TABLE `policies` DROP INDEX `uk_code`");
} catch (PDOException $e) { /* index doesn't exist or already removed */ }
try {
    $db->exec("ALTER TABLE `policies` ADD UNIQUE KEY `uk_code_version` (`code`, `version`)");
} catch (PDOException $e) { /* index already exists */ }

// Add missing indexes
foreach ([
    "ALTER TABLE `policies` ADD INDEX `idx_status` (`status`)",
    "ALTER TABLE `policies` ADD INDEX `idx_effective_to` (`effective_to`)"
] as $idxSql) {
    try { $db->exec($idxSql); } catch (PDOException $e) { /* index already exists */ }
}

// Migrate old column name effective_date → effective_from if it exists
try {
    $db->exec("ALTER TABLE `policies` CHANGE COLUMN `effective_date` `effective_from` DATE DEFAULT NULL");
} catch (PDOException $e) { /* column doesn't exist or already renamed */ }

// Ensure effective_to column exists and has proper default
try {
    $db->exec("ALTER TABLE `policies` MODIFY COLUMN `effective_to` DATE DEFAULT NULL");
} catch (PDOException $e) { /* ignore */ }

// Ensure status column has proper default
try {
    $db->exec("ALTER TABLE `policies` MODIFY COLUMN `status` VARCHAR(20) DEFAULT 'ACTIVE'");
} catch (PDOException $e) { /* ignore */ }

// ════════════════════════════════════════════════════════════════
// ROUTING
// ════════════════════════════════════════════════════════════════

// Sub-routes: policies/{id}/revisions, policies/{id}/archive, policies/{id}/restore
if ($sub === 'revisions' && $method === 'GET') {
    // Get revision history for a policy
    $stmt = $db->prepare('SELECT * FROM policy_revisions WHERE policy_id = :pid ORDER BY revision DESC');
    $stmt->execute([':pid' => $id]);
    successResponse($stmt->fetchAll());
    exit;
}

if ($sub === 'archive' && $method === 'POST') {
    requireRole(['ADMIN', 'COMPLIANCE']);
    $stmt = $db->prepare('UPDATE policies SET status = :st, updated_at = NOW() WHERE id = :id');
    $stmt->execute([':st' => 'ARCHIVED', ':id' => $id]);
    logAudit($staff['full_name'], 'POLICY_ARCHIVE', 'POLICY', $id, 'SUCCESS',
        'Archived policy ID: ' . $id, $staff['department'], getClientIp());
    successMessage('Policy archived successfully.');
    exit;
}

if ($sub === 'restore' && $method === 'POST') {
    requireRole(['ADMIN', 'COMPLIANCE']);
    $stmt = $db->prepare('UPDATE policies SET status = :st, updated_at = NOW() WHERE id = :id');
    $stmt->execute([':st' => 'ACTIVE', ':id' => $id]);
    logAudit($staff['full_name'], 'POLICY_RESTORE', 'POLICY', $id, 'SUCCESS',
        'Restored policy ID: ' . $id, $staff['department'], getClientIp());
    successMessage('Policy restored successfully.');
    exit;
}

if ($sub === 'rollback' && $method === 'POST') {
    requireRole(['ADMIN', 'COMPLIANCE']);
    $input = getRequestInput();
    $revId = intval($input['revision_id'] ?? 0);
    if (!$revId) { validationError(['revision_id' => 'Revision ID is required.']); }

    // Get the revision
    $revStmt = $db->prepare('SELECT * FROM policy_revisions WHERE id = :rid AND policy_id = :pid LIMIT 1');
    $revStmt->execute([':rid' => $revId, ':pid' => $id]);
    $rev = $revStmt->fetch();
    if (!$rev) { errorResponse('Revision not found.', 404); }

    // Save current state as a new revision before rollback
    $curStmt = $db->prepare('SELECT * FROM policies WHERE id = :id LIMIT 1');
    $curStmt->execute([':id' => $id]);
    $cur = $curStmt->fetch();
    if ($cur) {
        $maxRev = $db->prepare('SELECT COALESCE(MAX(revision),0)+1 AS nr FROM policy_revisions WHERE policy_id = :pid');
        $maxRev->execute([':pid' => $id]);
        $nr = $maxRev->fetch()['nr'];
        $ins = $db->prepare('INSERT INTO policy_revisions (policy_id, revision, version, name, description, content, category, severity, owner, effective_from, effective_to, change_summary, changed_by) VALUES (:pid, :rev, :ver, :name, :desc, :content, :cat, :sev, :owner, :efrom, :eto, :summary, :by)');
        $ins->execute([
            ':pid' => $id, ':rev' => $nr, ':ver' => $cur['version'], ':name' => $cur['name'],
            ':desc' => $cur['description'], ':content' => $cur['content'], ':cat' => $cur['category'],
            ':sev' => $cur['severity'], ':owner' => $cur['owner'],
            ':efrom' => $cur['effective_from'], ':eto' => $cur['effective_to'],
            ':summary' => 'Pre-rollback snapshot before restoring revision ' . $rev['revision'],
            ':by' => $staff['full_name']
        ]);
    }

    // Apply rollback
    $up = $db->prepare('UPDATE policies SET name=:n, description=:d, content=:c, category=:cat, severity=:sev, owner=:o, effective_from=:ef, effective_to=:et, version=:v, updated_at=NOW() WHERE id=:id');
    $up->execute([
        ':n' => $rev['name'], ':d' => $rev['description'], ':c' => $rev['content'],
        ':cat' => $rev['category'], ':sev' => $rev['severity'], ':o' => $rev['owner'],
        ':ef' => $rev['effective_from'], ':et' => $rev['effective_to'],
        ':v' => $rev['version'], ':id' => $id
    ]);
    logAudit($staff['full_name'], 'POLICY_ROLLBACK', 'POLICY', $id, 'SUCCESS',
        'Rolled back policy ID ' . $id . ' to revision ' . $rev['revision'], $staff['department'], getClientIp());
    successMessage('Policy rolled back to revision ' . $rev['revision'] . ' successfully.');
    exit;
}

if ($sub === 'review' && $method === 'POST') {
    requireRole(['ADMIN', 'COMPLIANCE']);
    try {
        $today = date('Y-m-d');
        $stmt = $db->prepare('UPDATE policies SET last_reviewed_at = :today1, next_review_date = DATE_ADD(:today2, INTERVAL review_cycle_days DAY), updated_at = NOW() WHERE id = :id');
        $stmt->execute([':today1' => $today, ':today2' => $today, ':id' => $id]);
        logAudit($staff['full_name'], 'POLICY_REVIEW', 'POLICY', $id, 'SUCCESS',
            'Marked policy ID ' . $id . ' as reviewed', $staff['department'], getClientIp());
        successMessage('Policy marked as reviewed. Next review date updated.');
    } catch (PDOException $e) { serverErrorResponse('Failed to mark policy as reviewed.'); }
    exit;
}

switch ($method) {
    case 'GET':
        $params = [];
        $where = '1=1';
        if (!empty($_GET['category'])) { $where .= ' AND category = :cat'; $params[':cat'] = sanitize($_GET['category']); }
        if (!empty($_GET['code']))     { $where .= ' AND code = :code'; $params[':code'] = sanitize($_GET['code']); }
        if (!empty($_GET['status']))   { $where .= ' AND status = :st';   $params[':st'] = sanitize($_GET['status']); }
        if (!empty($_GET['search']))   { $where .= ' AND (name LIKE :q OR description LIKE :q OR code LIKE :q)'; $params[':q'] = '%' . sanitize($_GET['search']) . '%'; }
        try {
            $stmt = $db->prepare('SELECT * FROM policies WHERE ' . $where . ' ORDER BY effective_from DESC, code ASC');
            $stmt->execute($params);
            $policies = $stmt->fetchAll();

            // Enrich each policy with revision count and next review status
            foreach ($policies as &$p) {
                $rc = $db->prepare('SELECT COUNT(*) AS cnt FROM policy_revisions WHERE policy_id = :pid');
                $rc->execute([':pid' => $p['id']]);
                $p['revision_count'] = (int)$rc->fetch()['cnt'];
                $p['is_expired'] = $p['effective_to'] && $p['effective_to'] < date('Y-m-d');
                $p['is_expiring_soon'] = !$p['is_expired'] && $p['effective_to'] && (strtotime($p['effective_to']) - strtotime(date('Y-m-d'))) <= 30 * 86400;
                $p['review_overdue'] = $p['next_review_date'] && $p['next_review_date'] < date('Y-m-d') && $p['status'] === 'ACTIVE';
            }

            successResponse($policies);
        } catch (PDOException $e) { serverErrorResponse('Database error.'); }
        break;

    case 'POST':
        requireRole(['ADMIN', 'COMPLIANCE']);
        $input = getRequestInput();
        $errors = validateRequired($input, ['code', 'name', 'description']);
        if (!empty($errors)) { validationError($errors); }

        $code  = strtoupper(sanitize($input['code']));
        $ver   = sanitize($input['version'] ?? '1.0');
        $name  = sanitize($input['name']);
        $desc  = sanitize($input['description'] ?? '');
        $cat   = sanitize($input['category'] ?? 'GENERAL');
        $sev   = sanitize($input['severity'] ?? 'MEDIUM');
        $owner = sanitize($input['owner'] ?? $staff['full_name']);
        $efrom = sanitize($input['effective_from'] ?? date('Y-m-d'));
        $eto   = sanitize($input['effective_to'] ?? null);
        $rcyc  = intval($input['review_cycle_days'] ?? 365);

        // ★ FIX (ST-061): Validate effective_to > effective_from on the backend too.
        // Frontend validates first, but backend must be the final authority.
        if ($eto && $efrom && $eto < $efrom) {
            validationError(['effective_to' => 'Effective To date must be after Effective From date.']);
        }

        // Calculate next review date
        $nextReview = date('Y-m-d', strtotime($efrom . ' + ' . $rcyc . ' days'));

        try {
            $stmt = $db->prepare(
                'INSERT INTO policies (code, version, name, description, content, category, status, severity, owner, review_cycle_days, effective_from, effective_to, next_review_date, created_by)
                 VALUES (:code, :ver, :name, :desc, :content, :cat, :st, :sev, :owner, :rcyc, :efrom, :eto, :nrev, :cby)'
            );
            $stmt->execute([
                ':code' => $code, ':ver' => $ver, ':name' => $name, ':desc' => $desc,
                ':content' => sanitize($input['content'] ?? ''),
                ':cat' => $cat, ':st' => 'ACTIVE', ':sev' => $sev, ':owner' => $owner,
                ':rcyc' => $rcyc, ':efrom' => $efrom, ':eto' => $eto ?: null,
                ':nrev' => $nextReview, ':cby' => $staff['full_name']
            ]);
            $newId = (int)$db->lastInsertId();

            // Create initial revision (v1)
            $db->prepare('INSERT INTO policy_revisions (policy_id, revision, version, name, description, content, category, severity, owner, effective_from, effective_to, change_summary, changed_by) VALUES (:pid, 1, :ver, :name, :desc, :content, :cat, :sev, :owner, :efrom, :eto, :summary, :by)')->execute([
                ':pid' => $newId, ':ver' => $ver, ':name' => $name, ':desc' => $desc,
                ':content' => sanitize($input['content'] ?? ''),
                ':cat' => $cat, ':sev' => $sev, ':owner' => $owner,
                ':efrom' => $efrom, ':eto' => $eto ?: null,
                ':summary' => 'Initial policy creation', ':by' => $staff['full_name']
            ]);

            logAudit($staff['full_name'], 'POLICY_CREATE', 'POLICY', (string)$newId, 'SUCCESS',
                'Created policy: ' . $name . ' [' . $code . '] v' . $ver, $staff['department'], getClientIp());
            createdResponse(['id' => $newId], 'Policy created successfully.');
        } catch (PDOException $e) { serverErrorResponse('Failed to create policy.'); }
        break;

    case 'PUT':
        requireRole(['ADMIN', 'COMPLIANCE']);
        if ($id === null) { validationError(['id' => 'Policy ID is required.']); }
        $input = getRequestInput();

        // ★ FIX (ST-061): Validate effective_to > effective_from on PUT too.
        $putEfrom = $input['effective_from'] ?? null;
        $putEto   = $input['effective_to'] ?? null;
        if ($putEto && $putEfrom && sanitize($putEto) < sanitize($putEfrom)) {
            validationError(['effective_to' => 'Effective To date must be after Effective From date.']);
        }

        try {
            // Fetch current policy for revision snapshot
            $curStmt = $db->prepare('SELECT * FROM policies WHERE id = :id LIMIT 1');
            $curStmt->execute([':id' => $id]);
            $current = $curStmt->fetch();
            if (!$current) { errorResponse('Policy not found.', 404); }

            // Build diff summary
            $diffParts = [];
            $updatableFields = ['name', 'description', 'content', 'category', 'severity', 'owner', 'effective_from', 'effective_to', 'version'];
            $fields = []; $params = [':id' => $id];
            foreach ($updatableFields as $f) {
                $dbCol = $f;
                $paramName = ':' . $f;
                if (isset($input[$f])) {
                    $newVal = sanitize($input[$f]);
                    $oldVal = $current[$dbCol] ?? '';
                    if ((string)$newVal !== (string)$oldVal) {
                        $diffParts[] = $f . ': "' . mb_substr($oldVal, 0, 60) . '" -> "' . mb_substr($newVal, 0, 60) . '"';
                    }
                    $fields[] = "`$dbCol` = $paramName";
                    $params[$paramName] = $newVal;
                }
            }

            // Handle review_cycle_days and next_review_date
            if (isset($input['review_cycle_days'])) {
                $rcyc = intval($input['review_cycle_days']);
                $fields[] = "`review_cycle_days` = :rcyc";
                $params[':rcyc'] = $rcyc;
            }

            if (empty($fields)) { errorResponse('No fields to update.'); }

            // Save current state as a revision BEFORE update
            $summary = implode('; ', $diffParts) ?: 'Updated';
            $maxRevStmt = $db->prepare('SELECT COALESCE(MAX(revision),0)+1 AS nr FROM policy_revisions WHERE policy_id = :pid');
            $maxRevStmt->execute([':pid' => $id]);
            $nextRev = (int)$maxRevStmt->fetch()['nr'];

            $db->prepare('INSERT INTO policy_revisions (policy_id, revision, version, name, description, content, category, severity, owner, effective_from, effective_to, change_summary, changed_by) VALUES (:pid, :rev, :ver, :name, :desc, :content, :cat, :sev, :owner, :efrom, :eto, :summary, :by)')->execute([
                ':pid' => $id, ':rev' => $nextRev, ':ver' => $current['version'], ':name' => $current['name'],
                ':desc' => $current['description'], ':content' => $current['content'], ':cat' => $current['category'],
                ':sev' => $current['severity'], ':owner' => $current['owner'],
                ':efrom' => $current['effective_from'], ':eto' => $current['effective_to'],
                ':summary' => $summary, ':by' => $staff['full_name']
            ]);

            // Now apply the update
            $stmt = $db->prepare('UPDATE policies SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id');
            $stmt->execute($params);

            logAudit($staff['full_name'], 'POLICY_UPDATE', 'POLICY', $id, 'SUCCESS',
                'Updated policy ID ' . $id . ': ' . $summary, $staff['department'], getClientIp());
            successMessage('Policy updated. Revision #' . $nextRev . ' saved.');
        } catch (PDOException $e) { serverErrorResponse('Failed to update policy.'); }
        break;

    case 'DELETE':
        requireRole(['ADMIN', 'COMPLIANCE']);
        if ($id === null) { validationError(['id' => 'Policy ID is required.']); }
        try {
            // Archive instead of hard delete
            $db->prepare('UPDATE policies SET status = :st, updated_at = NOW() WHERE id = :id')
               ->execute([':st' => 'DELETED', ':id' => $id]);
            logAudit($staff['full_name'], 'POLICY_DELETE', 'POLICY', $id, 'SUCCESS',
                'Soft-deleted policy ID: ' . $id, $staff['department'], getClientIp());
            successMessage('Policy deleted (archived).');
        } catch (PDOException $e) { serverErrorResponse('Failed to delete policy.'); }
        break;

    default:
        errorResponse('Method not allowed.', 405);
}