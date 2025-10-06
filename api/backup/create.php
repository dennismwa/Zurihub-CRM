<?php
// api/backup/create.php
require_once '../../config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || !hasPermission('settings', 'edit')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $backupDir = __DIR__ . '/../../backups/';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $backupDir . $filename;
    
    // Execute mysqldump
    $command = sprintf(
        'mysqldump --user=%s --password=%s --host=%s %s > %s 2>&1',
        DB_USER,
        DB_PASS,
        DB_HOST,
        DB_NAME,
        $filepath
    );
    
    exec($command, $output, $returnVar);
    
    if ($returnVar === 0 && file_exists($filepath)) {
        logActivity('Create Backup', 'Manual database backup created');
        echo json_encode([
            'success' => true, 
            'message' => 'Backup created successfully',
            'filename' => $filename
        ]);
    } else {
        throw new Exception('Backup command failed: ' . implode("\n", $output));
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>