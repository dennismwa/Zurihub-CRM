<?php
session_start();
require_once '../config.php';

// Delete session token from database
if (isset($_SESSION['client_session_token'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM client_portal_sessions WHERE session_token = ?");
        $stmt->execute([$_SESSION['client_session_token']]);
    } catch (Exception $e) {
        error_log("Logout error: " . $e->getMessage());
    }
}

// Clear all session data
session_unset();
session_destroy();

// Redirect to login page
header('Location: /portal/login.php');
exit;
?>
