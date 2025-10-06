<?php
// api/test/stripe.php
require_once '../../config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || !hasPermission('settings', 'edit')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Get Stripe settings
    $stmt = $pdo->query("SELECT setting_key, setting_value, is_encrypted FROM system_settings WHERE setting_key LIKE 'stripe_%'");
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
    
    $secretKey = $settings['stripe_secret_key'] ?? '';
    
    if (empty($secretKey)) {
        echo json_encode(['success' => false, 'message' => 'Stripe secret key not configured']);
        exit;
    }
    
    // Test Stripe connection by retrieving account
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, 'https://api.stripe.com/v1/account');
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $secretKey
    ]);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    if ($httpCode === 200) {
        echo json_encode(['success' => true, 'message' => 'Stripe connection successful']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to connect to Stripe: ' . $response]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
