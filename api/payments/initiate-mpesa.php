<?php
require_once '../../config.php';
require_once '../../app/services/PaymentGatewayService.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$paymentGateway = new PaymentGatewayService($pdo);

try {
    $result = $paymentGateway->processPayment(
        'mpesa',
        $data['amount'],
        $data['sale_id'],
        ['phone' => $data['phone']]
    );
    
    echo json_encode($result);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}