<?php
// api/maintenance/optimize.php
require_once '../../config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || !hasPermission('settings', 'edit')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Get all tables
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $optimized = 0;
    foreach ($tables as $table) {
        $pdo->exec("OPTIMIZE TABLE `$table`");
        $optimized++;
    }
    
    logActivity('Optimize Database', "Optimized $optimized tables");
    echo json_encode([
        'success' => true, 
        'message' => "Database optimized successfully. $optimized tables processed."
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>