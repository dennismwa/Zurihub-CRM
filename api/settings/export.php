<?php
// api/settings/export.php
require_once '../../config.php';

if (!isLoggedIn() || !hasPermission('settings', 'view')) {
    http_response_code(403);
    exit;
}

try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE is_encrypted = 0");
    $settings = [];
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    // Also export general settings
    $stmt = $pdo->query("SELECT * FROM settings WHERE id = 1");
    $generalSettings = $stmt->fetch();
    
    $export = [
        'exported_at' => date('Y-m-d H:i:s'),
        'version' => '1.0',
        'general_settings' => $generalSettings,
        'system_settings' => $settings
    ];
    
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="zuri_crm_settings_' . date('Y-m-d') . '.json"');
    echo json_encode($export, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Export error: " . $e->getMessage());
    http_response_code(500);
}
?>