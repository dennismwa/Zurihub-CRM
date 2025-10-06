<?php
// api/test/webhook.php
require_once '../../config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || !hasPermission('settings', 'edit')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'webhook_url'");
    $webhookUrl = $stmt->fetchColumn();
    
    if (empty($webhookUrl)) {
        echo json_encode(['success' => false, 'message' => 'Webhook URL not configured']);
        exit;
    }
    
    $testData = [
        'event' => 'test',
        'message' => 'Test webhook from Zuri CRM',
        'timestamp' => date('Y-m-d H:i:s'),
        'source' => 'settings_test'
    ];
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $webhookUrl);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($testData));
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        echo json_encode(['success' => true, 'message' => 'Webhook test successful']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Webhook returned status: ' . $httpCode]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>