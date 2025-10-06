<?php
// api/settings/import.php
require_once '../../config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || !hasPermission('settings', 'edit')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if (!$data || !isset($data['system_settings'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid import file']);
        exit;
    }
    
    $pdo->beginTransaction();
    
    // Import system settings (non-encrypted only)
    foreach ($data['system_settings'] as $key => $value) {
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, is_encrypted) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$key, $value, $value]);
    }
    
    $pdo->commit();
    
    logActivity('Import Settings', 'Imported system settings');
    echo json_encode(['success' => true, 'message' => 'Settings imported successfully']);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>