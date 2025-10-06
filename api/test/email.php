<?php
// api/test/email.php
require_once '../../config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || !hasPermission('settings', 'edit')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$testEmail = $data['email'] ?? '';

if (empty($testEmail)) {
    echo json_encode(['success' => false, 'message' => 'Email address required']);
    exit;
}

try {
    // Get SMTP settings
    $stmt = $pdo->query("SELECT setting_key, setting_value, is_encrypted FROM system_settings WHERE setting_key LIKE 'smtp_%' OR setting_key LIKE 'email_%'");
    $settings = [];
    while ($row = $stmt->fetch()) {
        $value = $row['setting_value'];
        if ($row['is_encrypted']) {
            $key = hash('sha256', 'zuri_crm_secret_key_2024');
            $iv = substr(hash('sha256', 'zuri_crm_iv_2024'), 0, 16);
            $value = openssl_decrypt(base64_decode($value), 'AES-256-CBC', $key, 0, $iv);
        }
        $settings[$row['setting_key']] = $value;
    }
    
    // Test email sending (simplified version)
    $to = $testEmail;
    $subject = "Test Email from Zuri CRM";
    $message = "This is a test email sent from your Zuri CRM system at " . date('Y-m-d H:i:s');
    $headers = "From: " . ($settings['email_from_name'] ?? 'Zuri CRM') . " <" . ($settings['email_from_address'] ?? 'noreply@zuricrm.com') . ">\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    if (mail($to, $subject, $message, $headers)) {
        echo json_encode(['success' => true, 'message' => 'Test email sent successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to send test email']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>