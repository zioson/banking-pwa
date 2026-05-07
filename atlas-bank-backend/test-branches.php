<?php
/**
 * Direct test — bypasses router, auth, CSRF, everything.
 * Just tests if the branches query works in a real HTTP request.
 */
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== DIRECT BRANCHES TEST (no auth, no router) ===\n\n";

require_once __DIR__ . '/config/database.php';

try {
    $db = getDB();
    echo "1. DB connected: OK\n";
    
    // Test exact query from branches.php GET handler
    $total = 0;
    $where = '';
    $params = [];
    $page = 1;
    $pageSize = 500;
    $offset = 0;
    
    echo "2. Testing COUNT query...\n";
    $totalResult = $db->query("SELECT COUNT(*) AS total FROM branches " . $where);
    $totalRow = $totalResult->fetchAll(PDO::FETCH_ASSOC);
    $total = (int)($totalRow[0]['total'] ?? 0);
    echo "   Total branches: $total\n";
    
    echo "3. Testing SELECT * query...\n";
    $stmt = $db->prepare("SELECT * FROM branches " . $where . " ORDER BY id DESC LIMIT :limit OFFSET :offset");
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "   Returned " . count($items) . " rows\n";
    
    foreach ($items as $item) {
        echo "   - ID={$item['id']} code={$item['code']} name={$item['name']} status={$item['status']}\n";
    }
    
    echo "\n4. Testing JSON response (same as API)...\n";
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'data' => $items,
        'pagination' => [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'totalPages' => max(1, (int)ceil($total / $pageSize)),
            'hasNext' => $page < (int)ceil($total / $pageSize),
            'hasPrev' => $page > 1
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Throwable $e) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "\n!!! ERROR !!!\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
