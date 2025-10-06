<?php
// api/settings/reset.php
require_once '../../config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || !hasPermission('settings', 'edit')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$section = $data['section'] ?? '';

$defaultSettings = [
    'email' => [
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_username' => '',
        'smtp_encryption' => 'tls',
        'email_from_address' => '',
        'email_from_name' => 'Zuri CRM'
    ],
    'sms' => [
        'sms_gateway' => '',
        'sms_sender_id' => '',
        'enable_sms_notifications' => '1',
        'enable_whatsapp_notifications' => '1'
    ],
    'business' => [
        'commission_percentage' => '5',
        'default_currency' => 'KES',
        'fiscal_year_start' => date('Y-01-01')
    ],
    'security' => [
        'session_timeout' => '60',
        'password_min_length' => '8',
        'password_require_uppercase' => '1',
        'password_require_numbers' => '1',
        'password_require_special' => '0',
        'max_login_attempts' => '5',
        'audit_log_retention' => '90'
    ],
    'system' => [
        'date_format' => 'Y-m-d',
        'time_zone' => 'Africa/Nairobi',
        'language' => 'en',
        'items_per_page' => '20',
        'decimal_separator' => '.',
        'thousands_separator' => ','
    ],
    'backup' => [
        'backup_schedule' => 'daily',
        'backup_retention_days' => '30',
        'log_cleanup_days' => '30'
    ],
    'features' => [
        'enable_ai_predictions' => '1',
        'enable_lead_scoring' => '1',
        'enable_workflow_automation' => '1',
        'enable_client_portal' => '0',
        'enable_mobile_app' => '1',
        'enable_analytics_dashboard' => '1'
    ]
];

if (!isset($defaultSettings[$section])) {
    echo json_encode(['success' => false, 'message' => 'Invalid section']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    foreach ($defaultSettings[$section] as $key => $value) {
        $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
        $stmt->execute([$value, $key]);
    }
    
    $pdo->commit();
    
    logActivity('Reset Settings', "Reset $section settings to defaults");
    echo json_encode(['success' => true, 'message' => 'Settings reset successfully']);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>