<?php
/**
 * Atlas Bank Enterprise Operations Console
 * API: Notifications
 *
 * 100% database-driven - zero mock data, zero localStorage.
 * All notification CRUD is persisted to the notifications table.
 * Events throughout the system (loan submission, approvals, transactions,
 * security alerts, etc.) create notifications via POST.
 *
 * Endpoints:
 *   GET    /api/notifications              - List notifications for current staff (paginated)
 *   GET    /api/notifications?status=PENDING - Filter by status
 *   POST   /api/notifications              - Create a new notification (system-generated)
 *   PUT    /api/notifications/{id}         - Update a single notification status
 *   PUT    /api/notifications/mark-all-read - Mark all as READ for current staff
 *
 * @version 2 — mark-all-read fix + defensive PDO + table/column safety
 */
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/rbac.php';

$staff = requireAuth();
$method = $_ROUTE['method'];
$id = $_ROUTE['id'];

// Auto-create and migrate notifications table
try {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS \"notifications\" (
        \"id\" SERIAL PRIMARY KEY,
        \"type\" VARCHAR(100) DEFAULT NULL,
        \"title\" VARCHAR(255) DEFAULT NULL,
        \"body\" TEXT DEFAULT NULL,
        \"channel\" VARCHAR(50) DEFAULT NULL,
        \"target_staff_id\" INTEGER DEFAULT NULL,
        \"status\" VARCHAR(20) DEFAULT 'PENDING',
        \"is_read\" BOOLEAN DEFAULT FALSE,
        \"created_at\" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        \"timestamp\" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    // Create indexes separately for PostgreSQL compatibility
    try { $db->exec('CREATE INDEX IF NOT EXISTS idx_notif_target_staff ON "notifications" (target_staff_id)'); } catch (PDOException $e) {}
    try { $db->exec('CREATE INDEX IF NOT EXISTS idx_notif_status ON "notifications" (status)'); } catch (PDOException $e) {}
    try { $db->exec('CREATE INDEX IF NOT EXISTS idx_notif_type ON "notifications" (type)'); } catch (PDOException $e) {}

    // Migrate: add columns if table was created by CANONICAL_SCHEMA (which omits them)
    $colCheck = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'notifications' AND column_name = 'is_read'")->fetch();
    if (!$colCheck) {
        $db->exec('ALTER TABLE "notifications" ADD COLUMN "is_read" BOOLEAN DEFAULT FALSE');
    }
    $colCheck2 = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'notifications' AND column_name = 'created_at'")->fetch();
    if (!$colCheck2) {
        $db->exec('ALTER TABLE "notifications" ADD COLUMN "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
    }
} catch (PDOException $e) {
    error_log('[Notifications Schema] Migration failed: ' . $e->getMessage());
}

switch ($method) {

    /* -- GET: List notifications for the authenticated staff member -- */
    case 'GET':
        $params = [':sid' => $staff['id']];
        $where = 'WHERE target_staff_id = :sid';
        if (!empty($_GET['status'])) {
            $where .= ' AND status = :status';
            $params[':status'] = sanitize($_GET['status']);
        }
        if (!empty($_GET['type'])) {
            $where .= ' AND type = :type';
            $params[':type'] = sanitize($_GET['type']);
        }
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = max(1, min((int)($_GET['pageSize'] ?? 50), 200));
        $offset = ($page - 1) * $pageSize;
        try {
            $db = getDB();
            $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM notifications ' . $where);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetch()['total'];
            $stmt = $db->prepare('SELECT * FROM notifications ' . $where . ' ORDER BY timestamp DESC LIMIT CAST(:limit AS INTEGER) OFFSET CAST(:offset AS INTEGER)');
            foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
            $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            paginatedResponse($stmt->fetchAll(), $total, $page, $pageSize);
        } catch (PDOException $e) { serverErrorResponse('Database error.'); }
        break;

    /* -- POST: Create a new notification (system/event-generated) -- */
    case 'POST':
        $input = getRequestInput();
        // Validate required fields
        $errors = [];
        if (empty($input['title'])) $errors['title'] = 'Title is required.';
        if (empty($input['type']))  $errors['type']  = 'Type is required.';
        if (!empty($errors)) { validationError($errors); }

        // Determine target - default to current staff if not specified
        $targetStaffId = !empty($input['target_staff_id']) ? (int)$input['target_staff_id'] : $staff['id'];
        if ($targetStaffId !== $staff['id'] && !($staff['is_admin'] ?? false)) {
            $targetStaffId = $staff['id']; // Non-admins can only notify themselves
        }

        $type     = strtoupper(sanitize($input['type']));
        $title    = sanitize($input['title']);
        $body     = sanitize($input['body'] ?? $input['message'] ?? '');
        $status   = strtoupper(sanitize($input['status'] ?? 'PENDING'));
        $channel  = strtoupper(sanitize($input['channel'] ?? 'IN_APP'));
        $priority = strtoupper(sanitize($input['priority'] ?? 'NORMAL'));
        $entityType = sanitize($input['entity_type'] ?? '');
        $entityId   = isset($input['entity_id']) ? (int)$input['entity_id'] : null;

        if (!in_array($status, ['PENDING', 'READ', 'ARCHIVED'])) { $status = 'PENDING'; }
        if (!in_array($priority, ['LOW', 'NORMAL', 'HIGH', 'URGENT'])) { $priority = 'NORMAL'; }

        try {
            $db = getDB();
            $stmt = $db->prepare(
                'INSERT INTO notifications (type, title, body, status, channel, target_staff_id, priority, entity_type, entity_id, timestamp)
                 VALUES (:type, :title, :body, :status, :channel, :tsid, :priority, :etype, :eid, NOW())'
            );
            $stmt->execute([
                ':type'   => $type,
                ':title'  => $title,
                ':body'   => $body,
                ':status' => $status,
                ':channel'=> $channel,
                ':tsid'   => $targetStaffId,
                ':priority'=> $priority,
                ':etype'  => $entityType,
                ':eid'    => $entityId
            ]);
            $newId = $db->lastInsertId('notifications_id_seq');
            $notifStmt = $db->prepare('SELECT * FROM notifications WHERE id = :id');
            $notifStmt->execute([':id' => $newId]);
            $notif = $notifStmt->fetch();
            jsonResponse(['success' => true, 'data' => $notif, 'message' => 'Notification created.']);
        } catch (PDOException $e) { serverErrorResponse('Failed to create notification.'); }
        break;

    /* -- PUT: Update a single notification or mark-all-read -- */
    case 'PUT':
        // Handle mark-all-read special endpoint: PUT /api/notifications/mark-all-read
        if ($id === 'mark-all-read') {
            try {
                $db = getDB();

                // Defensive: verify table and columns exist before UPDATE
                $tableExists = $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'notifications' LIMIT 1")->fetch();
                if (!$tableExists) {
                    serverErrorResponse('Notifications table does not exist. Please reload the page to trigger schema creation.');
                    break;
                }

                $statusCol = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'notifications' AND column_name = 'status'")->fetch();
                $staffCol  = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'notifications' AND column_name = 'target_staff_id'")->fetch();
                if (!$statusCol || !$staffCol) {
                    $missing = [];
                    if (!$statusCol) $missing[] = 'status';
                    if (!$staffCol) $missing[] = 'target_staff_id';
                    serverErrorResponse('Notifications table is missing column(s): ' . implode(', ', $missing) . '.');
                    break;
                }

                // Update all unread notifications for this staff member
                $stmt = $db->query("UPDATE \"notifications\" SET \"status\" = 'READ' WHERE \"target_staff_id\" = " . (int)$staff['id'] . " AND \"status\" != 'READ'");
                $updatedCount = $stmt->rowCount();

                jsonResponse([
                    'success'   => true,
                    'data'      => ['updated_count' => $updatedCount],
                    'message'   => $updatedCount . ' notification(s) marked as read.',
                    '_version'  => '2'
                ]);
            } catch (PDOException $e) {
                error_log('[Notifications mark-all-read] PDO #' . $e->getCode() . ': ' . $e->getMessage());
                serverErrorResponse('Failed to mark notifications as read. [PDO ' . $e->getCode() . ': ' . $e->getMessage() . ']');
            } catch (Throwable $e) {
                error_log('[Notifications mark-all-read] Error: ' . get_class($e) . ' — ' . $e->getMessage());
                serverErrorResponse('Failed to mark notifications as read. [' . get_class($e) . ': ' . $e->getMessage() . ']');
            }
            break;
        }

        // Single notification update
        if ($id === null) { validationError(['id' => 'Notification ID is required.']); }

        // Read status from JSON body (sent by frontend) - fallback to query string for backward compat
        $input = getRequestInput();
        $newStatus = strtoupper(sanitize($input['status'] ?? $_GET['status'] ?? 'READ'));
        if (!in_array($newStatus, ['READ', 'ARCHIVED', 'PENDING'])) {
            validationError(['status' => 'Invalid status. Must be READ, ARCHIVED, or PENDING.']);
        }

        try {
            $db = getDB();

            // Verify the notification exists and belongs to this staff before updating
            $checkStmt = $db->prepare('SELECT id, status FROM notifications WHERE id = :id AND target_staff_id = :sid');
            $checkStmt->execute([':id' => $id, ':sid' => $staff['id']]);
            $existing = $checkStmt->fetch();

            if (!$existing) {
                notFoundResponse('Notification not found or access denied.');
            }

            // Skip if already in the requested status (avoid unnecessary writes)
            if ($existing['status'] === $newStatus) {
                jsonResponse(['success' => true, 'data' => ['id' => $id, 'status' => $newStatus], 'message' => 'Notification already ' . $newStatus . '.']);
                break;
            }

            $stmt = $db->prepare('UPDATE notifications SET status = :status WHERE id = :id AND target_staff_id = :sid');
            $stmt->execute([':status' => $newStatus, ':id' => $id, ':sid' => $staff['id']]);
            jsonResponse(['success' => true, 'data' => ['id' => $id, 'status' => $newStatus], 'message' => 'Notification marked as ' . $newStatus . '.']);
        } catch (PDOException $e) { serverErrorResponse('Failed to update notification.'); }
        break;

    /* -- DELETE: Archive/delete a notification (admin only) -- */
    case 'DELETE':
        if ($id === null) { validationError(['id' => 'Notification ID is required.']); }
        try {
            $db = getDB();
            $stmt = $db->prepare('DELETE FROM notifications WHERE id = :id AND target_staff_id = :sid');
            $stmt->execute([':id' => $id, ':sid' => $staff['id']]);
            if ($stmt->rowCount() === 0) { notFoundResponse('Notification not found or access denied.'); }
            jsonResponse(['success' => true, 'message' => 'Notification deleted.']);
        } catch (PDOException $e) { serverErrorResponse('Failed to delete notification.'); }
        break;

    default:
        errorResponse('Method not allowed.', 405);
}
