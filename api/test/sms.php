<?php
// api/test/sms.php
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
    // Get SMS settings
    $stmt = $pdo->query("SELECT setting_key, setting_value, is_encrypted FROM system_settings WHERE setting_key LIKE 'sms_%'");
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
    
    $message = "Test SMS from Zuri CRM sent at " . date('Y-m-d H:i:s');
    
    // Africa's Talking SMS
    $username = $settings['sms_gateway'] ?? '';
    $apiKey = $settings['sms_api_key'] ?? '';
    
    if (empty($username) || empty($apiKey)) {
        echo json_encode(['success' => false, 'message' => 'SMS settings not configured']);
        exit;
    }
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, 'https://api.africastalking.com/version1/messaging');
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query([
        'username' => $username,
        'to' => $testPhone,
        'message' => $message
    ]));
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['apiKey: ' . $apiKey]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    if ($httpCode === 200 || $httpCode === 201) {
        echo json_encode(['success' => true, 'message' => 'Test SMS sent successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to send SMS: ' . $response]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>