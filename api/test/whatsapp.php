<?php
// api/test/whatsapp.php
require_once '../../config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || !hasPermission('settings', 'edit')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$testPhone = $data['phone'] ?? '';

if (empty($testPhone)) {
    echo json_encode(['success' => false, 'message' => 'Phone number required']);
    exit;
}

try {
    // Get WhatsApp settings
    $stmt = $pdo->query("SELECT setting_key, setting_value, is_encrypted FROM system_settings WHERE setting_key LIKE 'whatsapp_%'");
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
    
    $phoneId = $settings['whatsapp_phone_id'] ?? '';
    $apiToken = $settings['whatsapp_api_token'] ?? '';
    
    if (empty($phoneId) || empty($apiToken)) {
        echo json_encode(['success' => false, 'message' => 'WhatsApp settings not configured']);
        exit;
    }
    
    $message = "Test message from Zuri CRM sent at " . date('Y-m-d H:i:s');
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, "https://graph.facebook.com/v17.0/$phoneId/messages");
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
        'messaging_product' => 'whatsapp',
        'to' => $testPhone,
        'type' => 'text',
        'text' => ['body' => $message]
    ]));
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        echo json_encode(['success' => true, 'message' => 'Test WhatsApp message sent successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to send WhatsApp message: ' . $response]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>