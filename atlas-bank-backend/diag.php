<?php
/**
 * Atlas Bank — Quick Diagnostic
 * Drop in atlas-bank-backend/ and visit /atlas-bank-backend/diag.php
 * Then DELETE this file after use (security).
 */
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== ATLAS BANK DIAGNOSTIC ===\n\n";

// 1. DB Connection
echo "1. Database Connection: ";
try {
    require_once __DIR__ . '/config/database.php';
    $db = getDB();
    echo "OK (MySQL connected)\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    die();
}

// 2. Check tables
$tables = ['branches', 'staff_modules', 'staff_branches', 'audit_logs', 'sessions', 'staff'];
foreach ($tables as $t) {
    echo "2. Table '$t': ";
    try {
        $r = $db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "EXISTS ($r rows)\n";
    } catch (Throwable $e) {
        echo "MISSING — " . $e->getMessage() . "\n";
    }
}

// 3. Check branches columns
echo "\n3. Branches table columns:\n";
try {
    $cols = $db->query("SHOW COLUMNS FROM branches")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "   - {$c['Field']} ({$c['Type']}) Null={$c['Null']} Default={$c['Default']}\n";
    }
} catch (Throwable $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

// 4. Try the exact query branches.php uses (GET list)
echo "\n4. Test GET branches query: ";
try {
    $totalResult = $db->query("SELECT COUNT(*) AS total FROM branches");
    $totalRow = $totalResult->fetchAll(PDO::FETCH_ASSOC);
    echo "OK — " . ($totalRow[0]['total'] ?? 0) . " branches found\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

// 5. Try SELECT * FROM branches
echo "5. Test SELECT * FROM branches: ";
try {
    $rows = $db->query("SELECT * FROM branches ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    echo "OK — returned " . count($rows) . " rows\n";
    foreach ($rows as $r) {
        echo "   ID={$r['id']} code={$r['code']} name={$r['name']} status={$r['status']}\n";
    }
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

// 6. Check auth — sessions
echo "\n6. Active sessions: ";
try {
    $r = $db->query("SELECT COUNT(*) FROM sessions WHERE expires_at > NOW()")->fetchColumn();
    echo "$r active session(s)\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// 7. Check staff_modules
echo "7. Staff module assignments: ";
try {
    $r = $db->query("SELECT staff_id, module_name FROM staff_modules LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    echo count($r) . " assignment(s) found\n";
    foreach ($r as $m) {
        echo "   staff_id={$m['staff_id']} module={$m['module_name']}\n";
    }
    if (count($r) === 0) echo "   WARNING: No module assignments! Staff won't pass RBAC check.\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// 8. Check staff_branches
echo "8. Staff-branch assignments: ";
try {
    $r = $db->query("SELECT staff_id, branch_name FROM staff_branches LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    echo count($r) . " assignment(s) found\n";
    foreach ($r as $b) {
        echo "   staff_id={$b['staff_id']} branch={$b['branch_name']}\n";
    }
    if (count($r) === 0) echo "   WARNING: No branch assignments!\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// 9. Test actual branches.php include (simulate the request)
echo "\n9. Simulating branches.php execution:\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['REQUEST_URI'] = '/atlas-bank-backend/api/branches';
$_GET = [];
$_POST = [];
$_ROUTE = ['resource' => 'branches', 'id' => null, 'subResource' => null, 'subId' => null, 'method' => 'GET', 'segments' => ['branches'], 'query' => [], 'params' => []];

try {
    // Test just the table creation part
    $db->exec("CREATE TABLE IF NOT EXISTS branches (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        code        VARCHAR(20)  NOT NULL,
        name        VARCHAR(255) NOT NULL,
        region      VARCHAR(100) DEFAULT NULL,
        country     VARCHAR(100) NOT NULL DEFAULT 'Cameroon',
        status      VARCHAR(20)  NOT NULL DEFAULT 'ACTIVE',
        address     VARCHAR(500) DEFAULT NULL,
        phone       VARCHAR(50)  DEFAULT NULL,
        manager     VARCHAR(255) DEFAULT NULL,
        opened_date DATE         DEFAULT NULL,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_branches_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "   CREATE TABLE IF NOT EXISTS branches: OK (skipped or created)\n";
} catch (Throwable $e) {
    echo "   CREATE TABLE FAIL: " . $e->getMessage() . "\n";
}

try {
    $_cols = [];
    foreach ($db->query("SHOW COLUMNS FROM branches")->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $_cols[strtolower($col['Field'])] = true;
    }
    echo "   Column check OK — " . count($_cols) . " columns found\n";
    echo "   Has address: " . (isset($_cols['address']) ? 'YES' : 'NO') . "\n";
    echo "   Has phone: " . (isset($_cols['phone']) ? 'YES' : 'NO') . "\n";
    echo "   Has manager: " . (isset($_cols['manager']) ? 'YES' : 'NO') . "\n";
    echo "   Has opened_date: " . (isset($_cols['opened_date']) ? 'YES' : 'NO') . "\n";
} catch (Throwable $e) {
    echo "   Column check FAIL: " . $e->getMessage() . "\n";
}

// 10. Check the actual branches.php file for "critical error" text
echo "\n10. Searching branches.php for 'critical error' text:\n";
$branchesFile = __DIR__ . '/api/branches.php';
if (file_exists($branchesFile)) {
    $content = file_get_contents($branchesFile);
    if (strpos($content, 'A critical error occurred') !== false) {
        $line = 0;
        foreach (explode("\n", $content) as $ln => $txt) {
            if (strpos($txt, 'A critical error occurred') !== false) {
                $line = $ln + 1;
                break;
            }
        }
        echo "   FOUND at line $line! This is the source of your error.\n";
    } elseif (strpos($content, 'Branches API fatal error') !== false) {
        echo "   Has 'Branches API fatal error' handler (correct version)\n";
    } elseif (strpos($content, 'Branches API error') !== false) {
        echo "   Has 'Branches API error' catch (correct version)\n";
    } else {
        echo "   No 'critical error' or 'API error' text found in branches.php\n";
    }
    echo "   File size: " . strlen($content) . " bytes\n";
} else {
    echo "   ERROR: branches.php not found at $branchesFile\n";
}

echo "\n=== DIAGNOSTIC COMPLETE ===\n";
echo "DELETE this file (diag.php) after use for security.\n";
