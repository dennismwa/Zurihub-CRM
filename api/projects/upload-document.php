<?php
require_once '../../config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || !hasPermission('projects', 'edit')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!isset($_FILES['document']) || $_FILES['document']['error'] !== 0) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit;
}

$projectId = intval($_POST['project_id'] ?? 0);
if (!$projectId) {
    echo json_encode(['success' => false, 'message' => 'Invalid project ID']);
    exit;
}

try {
    $uploadResult = uploadFile($_FILES['document'], 'projects');
    
    if (!$uploadResult['success']) {
        echo json_encode($uploadResult);
        exit;
    }
    
    $documentName = $_FILES['document']['name'];
    $filePath = $uploadResult['path'];
    $fileType = $_FILES['document']['type'];
    $fileSize = $_FILES['document']['size'];
    $userId = getUserId();
    
    $stmt = $pdo->prepare("INSERT INTO project_documents (project_id, document_name, file_path, file_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
    
    if ($stmt->execute([$projectId, $documentName, $filePath, $fileType, $fileSize, $userId])) {
        logActivity('Upload Document', "Uploaded document to project ID: $projectId");
        echo json_encode(['success' => true, 'message' => 'Document uploaded successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    
} catch (Exception $e) {
    error_log("Upload error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Upload failed']);
}
?>
