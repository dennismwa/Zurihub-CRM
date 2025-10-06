<?php
require_once '../../config.php';
require_once '../../app/services/LeadScoringService.php';
header('Content-Type: application/json');

if (!hasPermission('leads', 'edit')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$scoringService = new LeadScoringService($pdo);

try {
    $results = $scoringService->bulkCalculateScores();
    echo json_encode(['success' => true, 'count' => count($results)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}