<?php
session_start();
require_once '../config.php';

// Redirect if already logged in
if (isset($_SESSION['client_id'])) {
    header('Location: /portal/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = sanitize($_POST['phone'] ?? '');
    $idNumber = sanitize($_POST['id_number'] ?? '');
    
    if (empty($phone) || empty($idNumber)) {
        $error = 'Please fill in all fields';
    } else {
        // Verify client credentials
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE phone = ? AND id_number = ?");
        $stmt->execute([$phone, $idNumber]);
        $client = $stmt->fetch();
        
        if ($client) {
            // Create session
            $_SESSION['client_id'] = $client['id'];
            $_SESSION['client_name'] = $client['full_name'];
            $_SESSION['client_email'] = $client['email'];
            
            // Create session token for security
            $sessionToken = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
            
            $stmt = $pdo->prepare("INSERT INTO client_portal_sessions (client_id, session_token, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $client['id'],
                $sessionToken,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $expiresAt
            ]);
            
            $_SESSION['client_session_token'] = $sessionToken;
            
            header('Location: /portal/index.php');
            exit;
        } else {
            $error = 'Invalid phone number or ID number';
        }
    }
}

$settings = getSettings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Portal Login - <?php echo sanitize($settings['site_name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --primary-color: <?php echo $settings['primary_color']; ?>;
        }
        .bg-primary { background-color: var(--primary-color); }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen flex items-center justify-center px-4">
    <div class="max-w-md w-full">
        <div class="bg-white rounded-2xl shadow-2xl p-8">
            <div class="text-center mb-8">
                <img src="<?php echo $settings['logo_path']; ?>" alt="Logo" class="h-16 mx-auto mb-4">
                <h1 class="text-3xl font-bold text-gray-800">Client Portal</h1>
                <p class="text-gray-600 mt-2">Access your property information</p>
            </div>
            
            <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
                <?php echo sanitize($error); ?>
            </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Phone Number</label>
                        <input type="tel" name="phone" required placeholder="0712345678"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">ID Number</label>
                        <input type="text" name="id_number" required placeholder="Enter your ID number"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
                        <p class="text-xs text-gray-500 mt-1">Use the ID number provided during registration</p>
                    </div>
                </div>
                
                <button type="submit" class="w-full bg-primary text-white font-semibold py-3 rounded-lg mt-6 hover:opacity-90 transition">
                    Sign In
                </button>
            </form>
            
            <div class="mt-6 text-center">
                <p class="text-sm text-gray-600">
                    Need help? <a href="tel:<?php echo $settings['contact_phone']; ?>" class="text-primary hover:underline">Contact Us</a>
                </p>
            </div>
        </div>
        
        <div class="text-center mt-6 text-sm text-gray-600">
            <p>&copy; <?php echo date('Y'); ?> <?php echo sanitize($settings['site_name']); ?>. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
