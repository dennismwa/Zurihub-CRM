<?php
// api/test/mpesa.php
require_once '../../config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || !hasPermission('settings', 'edit')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Get M-Pesa settings
    $stmt = $pdo->query("SELECT setting_key, setting_value, is_encrypted FROM system_settings WHERE setting_key LIKE 'mpesa_%'");
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
    
    $consumerKey = $settings['mpesa_consumer_key'] ?? '';
    $consumerSecret = $settings['mpesa_consumer_secret'] ?? '';
    $environment = $settings['mpesa_environment'] ?? 'sandbox';
    
    if (empty($consumerKey) || empty($consumerSecret)) {
        echo json_encode(['success' => false, 'message' => 'M-Pesa credentials not configured']);
        exit;
    }
    
    $apiUrl = $environment === 'production' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
    
    // Test getting OAuth token
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $apiUrl . '/oauth/v1/generate?grant_type=client_credentials');
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_USERPWD, $consumerKey . ':' . $consumerSecret);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    if ($httpCode === 200) {
        $result = json_decode($response);
        if (isset($result->access_token)) {
            echo json_encode(['success' => true, 'message' => 'M-Pesa connection successful']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid response from M-Pesa']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to connect to M-Pesa: ' . $response]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>