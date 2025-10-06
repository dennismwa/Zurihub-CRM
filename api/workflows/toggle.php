<?php
require_once '../../config.php';
require_once '../../app/services/WorkflowEngine.php';
header('Content-Type: application/json');

if (!hasPermission('workflows', 'edit')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$workflowEngine = new WorkflowEngine($pdo);

try {
    $workflowEngine->toggleWorkflow($data['id']);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}