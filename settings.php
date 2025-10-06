<?php
$pageTitle = 'Advanced Settings';
require_once 'config.php';
requirePermission('settings', 'view');

// Simple encryption/decryption functions
function encryptSetting($value) {
    $key = hash('sha256', 'zuri_crm_secret_key_2024');
    $iv = substr(hash('sha256', 'zuri_crm_iv_2024'), 0, 16);
    return base64_encode(openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv));
}

function decryptSetting($value) {
    if (empty($value)) return '';
    $key = hash('sha256', 'zuri_crm_secret_key_2024');
    $iv = substr(hash('sha256', 'zuri_crm_iv_2024'), 0, 16);
    return openssl_decrypt(base64_decode($value), 'AES-256-CBC', $key, 0, $iv);
}

// Get all settings
function getAllSettings($pdo) {
    $stmt = $pdo->query("SELECT * FROM system_settings");
    $rows = $stmt->fetchAll();
    $settings = [];
    foreach ($rows as $row) {
        $value = $row['setting_value'];
        if ($row['is_encrypted']) {
            $value = decryptSetting($value);
        }
        $settings[$row['setting_key']] = $value;
    }
    return $settings;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hasPermission('settings', 'edit')) {
    $tab = $_POST['tab'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        if ($tab === 'email') {
            // Email settings
            $emailSettings = [
                'smtp_host' => $_POST['smtp_host'],
                'smtp_port' => $_POST['smtp_port'],
                'smtp_username' => $_POST['smtp_username'],
                'smtp_encryption' => $_POST['smtp_encryption'],
                'email_from_address' => $_POST['email_from_address'],
                'email_from_name' => $_POST['email_from_name']
            ];
            
            // Encrypt password if provided
            if (!empty($_POST['smtp_password'])) {
                $emailSettings['smtp_password'] = $_POST['smtp_password'];
            }
            
            foreach ($emailSettings as $key => $value) {
                $isEncrypted = ($key === 'smtp_password') ? 1 : 0;
                $finalValue = $isEncrypted ? encryptSetting($value) : $value;
                
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, is_encrypted) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, is_encrypted = ?");
                $stmt->execute([$key, $finalValue, $isEncrypted, $finalValue, $isEncrypted]);
            }
        }
        elseif ($tab === 'sms') {
            // SMS/WhatsApp settings
            $smsSettings = [
                'sms_gateway' => $_POST['sms_gateway'],
                'sms_sender_id' => $_POST['sms_sender_id'],
                'whatsapp_phone_id' => $_POST['whatsapp_phone_id'],
                'enable_sms_notifications' => isset($_POST['enable_sms_notifications']) ? '1' : '0',
                'enable_whatsapp_notifications' => isset($_POST['enable_whatsapp_notifications']) ? '1' : '0'
            ];
            
            // Encrypt API keys
            if (!empty($_POST['sms_api_key'])) {
                $smsSettings['sms_api_key'] = $_POST['sms_api_key'];
            }
            if (!empty($_POST['whatsapp_api_token'])) {
                $smsSettings['whatsapp_api_token'] = $_POST['whatsapp_api_token'];
            }
            
            foreach ($smsSettings as $key => $value) {
                $isEncrypted = (strpos($key, 'api') !== false || strpos($key, 'token') !== false) ? 1 : 0;
                $finalValue = $isEncrypted ? encryptSetting($value) : $value;
                
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, is_encrypted) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, is_encrypted = ?");
                $stmt->execute([$key, $finalValue, $isEncrypted, $finalValue, $isEncrypted]);
            }
        }
        elseif ($tab === 'payment') {
            // Payment gateway settings
            $paymentSettings = [
                'enable_mpesa' => isset($_POST['enable_mpesa']) ? '1' : '0',
                'mpesa_environment' => $_POST['mpesa_environment'],
                'mpesa_shortcode' => $_POST['mpesa_shortcode'],
                'enable_stripe' => isset($_POST['enable_stripe']) ? '1' : '0',
                'enable_paypal' => isset($_POST['enable_paypal']) ? '1' : '0',
                'bank_name' => $_POST['bank_name'],
                'bank_account_number' => $_POST['bank_account_number'],
                'bank_account_name' => $_POST['bank_account_name']
            ];
            
            // Encrypt sensitive data
            if (!empty($_POST['mpesa_consumer_key'])) {
                $paymentSettings['mpesa_consumer_key'] = $_POST['mpesa_consumer_key'];
            }
            if (!empty($_POST['mpesa_consumer_secret'])) {
                $paymentSettings['mpesa_consumer_secret'] = $_POST['mpesa_consumer_secret'];
            }
            if (!empty($_POST['mpesa_passkey'])) {
                $paymentSettings['mpesa_passkey'] = $_POST['mpesa_passkey'];
            }
            if (!empty($_POST['stripe_publishable_key'])) {
                $paymentSettings['stripe_publishable_key'] = $_POST['stripe_publishable_key'];
            }
            if (!empty($_POST['stripe_secret_key'])) {
                $paymentSettings['stripe_secret_key'] = $_POST['stripe_secret_key'];
            }
            if (!empty($_POST['paypal_client_id'])) {
                $paymentSettings['paypal_client_id'] = $_POST['paypal_client_id'];
            }
            if (!empty($_POST['paypal_secret'])) {
                $paymentSettings['paypal_secret'] = $_POST['paypal_secret'];
            }
            
            foreach ($paymentSettings as $key => $value) {
                $sensitiveKeys = ['consumer_key', 'consumer_secret', 'passkey', 'secret_key', 'client_id', 'secret'];
                $isEncrypted = 0;
                foreach ($sensitiveKeys as $sensitiveKey) {
                    if (strpos($key, $sensitiveKey) !== false) {
                        $isEncrypted = 1;
                        break;
                    }
                }
                
                $finalValue = $isEncrypted ? encryptSetting($value) : $value;
                
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, is_encrypted) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, is_encrypted = ?");
                $stmt->execute([$key, $finalValue, $isEncrypted, $finalValue, $isEncrypted]);
            }
        }
        elseif ($tab === 'business') {
            // Business settings
            $businessSettings = [
                'company_registration_number' => $_POST['company_registration_number'],
                'tax_number' => $_POST['tax_number'],
                'fiscal_year_start' => $_POST['fiscal_year_start'],
                'default_currency' => $_POST['default_currency'],
                'commission_percentage' => $_POST['commission_percentage'],
                'payment_terms' => $_POST['payment_terms']
            ];
            
            foreach ($businessSettings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, is_encrypted) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $value, $value]);
            }
        }
        elseif ($tab === 'notifications') {
            // Notification preferences
            $notificationSettings = [
                'notify_lead_created' => isset($_POST['notify_lead_created']) ? '1' : '0',
                'notify_sale_created' => isset($_POST['notify_sale_created']) ? '1' : '0',
                'notify_payment_received' => isset($_POST['notify_payment_received']) ? '1' : '0',
                'notify_task_assigned' => isset($_POST['notify_task_assigned']) ? '1' : '0',
                'notify_via_email' => isset($_POST['notify_via_email']) ? '1' : '0',
                'notify_via_sms' => isset($_POST['notify_via_sms']) ? '1' : '0',
                'notify_via_push' => isset($_POST['notify_via_push']) ? '1' : '0',
                'quiet_hours_start' => $_POST['quiet_hours_start'],
                'quiet_hours_end' => $_POST['quiet_hours_end']
            ];
            
            foreach ($notificationSettings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, is_encrypted) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $value, $value]);
            }
        }
        elseif ($tab === 'security') {
            // Security settings
            $securitySettings = [
                'session_timeout' => $_POST['session_timeout'],
                'password_min_length' => $_POST['password_min_length'],
                'password_require_uppercase' => isset($_POST['password_require_uppercase']) ? '1' : '0',
                'password_require_numbers' => isset($_POST['password_require_numbers']) ? '1' : '0',
                'password_require_special' => isset($_POST['password_require_special']) ? '1' : '0',
                'enable_2fa' => isset($_POST['enable_2fa']) ? '1' : '0',
                'max_login_attempts' => $_POST['max_login_attempts'],
                'ip_whitelist' => $_POST['ip_whitelist'],
                'audit_log_retention' => $_POST['audit_log_retention']
            ];
            
            foreach ($securitySettings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, is_encrypted) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $value, $value]);
            }
        }
        elseif ($tab === 'system') {
            // System preferences
            $systemSettings = [
                'date_format' => $_POST['date_format'],
                'time_zone' => $_POST['time_zone'],
                'language' => $_POST['language'],
                'decimal_separator' => $_POST['decimal_separator'],
                'thousands_separator' => $_POST['thousands_separator'],
                'items_per_page' => $_POST['items_per_page']
            ];
            
            foreach ($systemSettings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, is_encrypted) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $value, $value]);
            }
        }
        elseif ($tab === 'backup') {
            // Backup settings
            $backupSettings = [
                'backup_schedule' => $_POST['backup_schedule'],
                'backup_retention_days' => $_POST['backup_retention_days'],
                'enable_maintenance_mode' => isset($_POST['enable_maintenance_mode']) ? '1' : '0',
                'maintenance_message' => $_POST['maintenance_message'],
                'log_cleanup_days' => $_POST['log_cleanup_days']
            ];
            
            foreach ($backupSettings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, is_encrypted) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $value, $value]);
            }
        }
        elseif ($tab === 'integrations') {
            // Integration settings
            $integrationSettings = [
                'google_maps_api_key' => $_POST['google_maps_api_key'],
                'google_analytics_id' => $_POST['google_analytics_id'],
                'facebook_url' => $_POST['facebook_url'],
                'instagram_url' => $_POST['instagram_url'],
                'twitter_url' => $_POST['twitter_url'],
                'linkedin_url' => $_POST['linkedin_url'],
                'webhook_url' => $_POST['webhook_url']
            ];
            
            foreach ($integrationSettings as $key => $value) {
                $isEncrypted = ($key === 'google_maps_api_key') ? 1 : 0;
                $finalValue = $isEncrypted ? encryptSetting($value) : $value;
                
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, is_encrypted) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, is_encrypted = ?");
                $stmt->execute([$key, $finalValue, $isEncrypted, $finalValue, $isEncrypted]);
            }
        }
        elseif ($tab === 'features') {
            // Advanced features
            $featureSettings = [
                'enable_ai_predictions' => isset($_POST['enable_ai_predictions']) ? '1' : '0',
                'enable_lead_scoring' => isset($_POST['enable_lead_scoring']) ? '1' : '0',
                'enable_workflow_automation' => isset($_POST['enable_workflow_automation']) ? '1' : '0',
                'enable_client_portal' => isset($_POST['enable_client_portal']) ? '1' : '0',
                'enable_mobile_app' => isset($_POST['enable_mobile_app']) ? '1' : '0',
                'enable_analytics_dashboard' => isset($_POST['enable_analytics_dashboard']) ? '1' : '0'
            ];
            
            foreach ($featureSettings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, is_encrypted) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $value, $value]);
            }
        }
        
        $pdo->commit();
        logActivity('Update Settings', "Updated $tab settings");
        flashMessage('Settings saved successfully!', 'success');
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Settings error: " . $e->getMessage());
        flashMessage('Error saving settings: ' . $e->getMessage(), 'error');
    }
    
    redirect('/settings.php');
}

// Create system_settings table if it doesn't exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        is_encrypted TINYINT(1) DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    error_log("Table creation error: " . $e->getMessage());
}

$settings = getAllSettings($pdo);
$activeTab = $_GET['tab'] ?? 'general';

include 'includes/header.php';
?>

<div class="p-4 md:p-6 pb-20 md:pb-6">
    <div class="max-w-7xl mx-auto">
        <div class="mb-6 flex justify-between items-center">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold text-gray-800">Advanced Settings</h1>
                <p class="text-gray-600 mt-1">Configure your system</p>
            </div>
            <div class="flex gap-2">
                <button onclick="exportSettings()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <i class="fas fa-download mr-2"></i>Export
                </button>
                <button onclick="document.getElementById('importFile').click()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    <i class="fas fa-upload mr-2"></i>Import
                </button>
                <input type="file" id="importFile" accept=".json" class="hidden" onchange="importSettings(this)">
            </div>
        </div>
        
        <!-- Search -->
        <div class="mb-6">
            <input type="text" id="settingsSearch" placeholder="Search settings..." 
                   class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-primary"
                   onkeyup="searchSettings(this.value)">
        </div>
        
        <div class="bg-white rounded-lg shadow">
            <!-- Tabs -->
            <div class="border-b border-gray-200 overflow-x-auto">
                <nav class="flex">
                    <button onclick="switchTab('general')" class="settings-tab <?php echo $activeTab === 'general' ? 'active' : ''; ?> px-4 py-3 text-sm font-semibold border-b-2" data-tab="general">
                        <i class="fas fa-cog mr-2"></i>General
                    </button>
                    <button onclick="switchTab('email')" class="settings-tab <?php echo $activeTab === 'email' ? 'active' : ''; ?> px-4 py-3 text-sm font-semibold border-b-2" data-tab="email">
                        <i class="fas fa-envelope mr-2"></i>Email
                    </button>
                    <button onclick="switchTab('sms')" class="settings-tab <?php echo $activeTab === 'sms' ? 'active' : ''; ?> px-4 py-3 text-sm font-semibold border-b-2" data-tab="sms">
                        <i class="fas fa-sms mr-2"></i>SMS/WhatsApp
                    </button>
                    <button onclick="switchTab('payment')" class="settings-tab <?php echo $activeTab === 'payment' ? 'active' : ''; ?> px-4 py-3 text-sm font-semibold border-b-2" data-tab="payment">
                        <i class="fas fa-credit-card mr-2"></i>Payments
                    </button>
                    <button onclick="switchTab('business')" class="settings-tab <?php echo $activeTab === 'business' ? 'active' : ''; ?> px-4 py-3 text-sm font-semibold border-b-2" data-tab="business">
                        <i class="fas fa-briefcase mr-2"></i>Business
                    </button>
                    <button onclick="switchTab('notifications')" class="settings-tab <?php echo $activeTab === 'notifications' ? 'active' : ''; ?> px-4 py-3 text-sm font-semibold border-b-2" data-tab="notifications">
                        <i class="fas fa-bell mr-2"></i>Notifications
                    </button>
                    <button onclick="switchTab('security')" class="settings-tab <?php echo $activeTab === 'security' ? 'active' : ''; ?> px-4 py-3 text-sm font-semibold border-b-2" data-tab="security">
                        <i class="fas fa-shield-alt mr-2"></i>Security
                    </button>
                    <button onclick="switchTab('system')" class="settings-tab <?php echo $activeTab === 'system' ? 'active' : ''; ?> px-4 py-3 text-sm font-semibold border-b-2" data-tab="system">
                        <i class="fas fa-desktop mr-2"></i>System
                    </button>
                    <button onclick="switchTab('backup')" class="settings-tab <?php echo $activeTab === 'backup' ? 'active' : ''; ?> px-4 py-3 text-sm font-semibold border-b-2" data-tab="backup">
                        <i class="fas fa-database mr-2"></i>Backup
                    </button>
                    <button onclick="switchTab('integrations')" class="settings-tab <?php echo $activeTab === 'integrations' ? 'active' : ''; ?> px-4 py-3 text-sm font-semibold border-b-2" data-tab="integrations">
                        <i class="fas fa-plug mr-2"></i>Integrations
                    </button>
                    <button onclick="switchTab('features')" class="settings-tab <?php echo $activeTab === 'features' ? 'active' : ''; ?> px-4 py-3 text-sm font-semibold border-b-2" data-tab="features">
                        <i class="fas fa-magic mr-2"></i>Features
                    </button>
                </nav>
            </div>
            
            <!-- Tab Content -->
            <div class="p-6">
                <!-- General Settings -->
                <div id="general-tab" class="tab-content <?php echo $activeTab !== 'general' ? 'hidden' : ''; ?>">
                    <form method="POST">
                        <input type="hidden" name="tab" value="general">
                        <h3 class="text-lg font-bold mb-4">General Information</h3>
                        <!-- Content from original settings.php -->
                        <div class="flex gap-3 mt-6">
                            <button type="submit" class="px-6 py-3 bg-primary text-white rounded-lg hover:opacity-90">
                                Save Changes
                            </button>
                            <button type="button" onclick="resetSection('general')" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                                Reset to Default
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Email Settings -->
                <div id="email-tab" class="tab-content <?php echo $activeTab !== 'email' ? 'hidden' : ''; ?>">
                    <form method="POST">
                        <input type="hidden" name="tab" value="email">
                        <h3 class="text-lg font-bold mb-4">Email Configuration</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-semibold mb-2">
                                    SMTP Host
                                    <i class="fas fa-info-circle text-gray-400 ml-1" title="Mail server address (e.g., smtp.gmail.com)"></i>
                                </label>
                                <input type="text" name="smtp_host" value="<?php echo $settings['smtp_host'] ?? ''; ?>"
                                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold mb-2">SMTP Port</label>
                                <input type="number" name="smtp_port" value="<?php echo $settings['smtp_port'] ?? '587'; ?>"
                                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold mb-2">SMTP Username</label>
                                <input type="text" name="smtp_username" value="<?php echo $settings['smtp_username'] ?? ''; ?>"
                                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold mb-2">SMTP Password</label>
                                <input type="password" name="smtp_password" placeholder="••••••••"
                                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                <p class="text-xs text-gray-500 mt-1">Leave blank to keep current password</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold mb-2">Encryption</label>
                                <select name="smtp_encryption" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                    <option value="tls" <?php echo ($settings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                    <option value="ssl" <?php echo ($settings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                    <option value="none" <?php echo ($settings['smtp_encryption'] ?? '') === 'none' ? 'selected' : ''; ?>>None</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold mb-2">From Email</label>
                                <input type="email" name="email_from_address" value="<?php echo $settings['email_from_address'] ?? ''; ?>"
                                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                            </div>
                            
                            <div class="md:col-span-2">
                                <label class="block text-sm font-semibold mb-2">From Name</label>
                                <input type="text" name="email_from_name" value="<?php echo $settings['email_from_name'] ?? ''; ?>"
                                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                            </div>
                        </div>
                        
                        <div class="flex gap-3 mt-6">
                            <button type="submit" class="px-6 py-3 bg-primary text-white rounded-lg hover:opacity-90">
                                Save Changes
                            </button>
                            <button type="button" onclick="testEmail()" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                <i class="fas fa-paper-plane mr-2"></i>Send Test Email
                            </button>
                            <button type="button" onclick="resetSection('email')" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                                Reset to Default
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- SMS/WhatsApp Settings -->
                <div id="sms-tab" class="tab-content <?php echo $activeTab !== 'sms' ? 'hidden' : ''; ?>">
                    <form method="POST">
                        <input type="hidden" name="tab" value="sms">
                        <h3 class="text-lg font-bold mb-4">SMS & WhatsApp Configuration</h3>
                        
                        <div class="mb-6">
                            <h4 class="font-semibold mb-3">SMS Settings (Africa's Talking)</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold mb-2">Gateway Username</label>
                                    <input type="text" name="sms_gateway" value="<?php echo $settings['sms_gateway'] ?? ''; ?>"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold mb-2">API Key</label>
                                    <input type="password" name="sms_api_key" placeholder="••••••••"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                    <p class="text-xs text-gray-500 mt-1">Leave blank to keep current key</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold mb-2">Sender ID</label>
                                    <input type="text" name="sms_sender_id" value="<?php echo $settings['sms_sender_id'] ?? ''; ?>"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                                
                                <div>
                                    <label class="flex items-center">
                                        <input type="checkbox" name="enable_sms_notifications" 
                                               <?php echo ($settings['enable_sms_notifications'] ?? '0') === '1' ? 'checked' : ''; ?>
                                               class="mr-2">
                                        <span class="text-sm font-semibold">Enable SMS Notifications</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-6">
                            <h4 class="font-semibold mb-3">WhatsApp Business API</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold mb-2">Phone Number ID</label>
                                    <input type="text" name="whatsapp_phone_id" value="<?php echo $settings['whatsapp_phone_id'] ?? ''; ?>"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold mb-2">API Token</label>
                                    <input type="password" name="whatsapp_api_token" placeholder="••••••••"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                    <p class="text-xs text-gray-500 mt-1">Leave blank to keep current token</p>
                                </div>
                                
                                <div>
                                    <label class="flex items-center">
                                        <input type="checkbox" name="enable_whatsapp_notifications" 
                                               <?php echo ($settings['enable_whatsapp_notifications'] ?? '0') === '1' ? 'checked' : ''; ?>
                                               class="mr-2">
                                        <span class="text-sm font-semibold">Enable WhatsApp Notifications</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex gap-3 mt-6">
                            <button type="submit" class="px-6 py-3 bg-primary text-white rounded-lg hover:opacity-90">
                                Save Changes
                            </button>
                            <button type="button" onclick="testSMS()" class="px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700">
                                <i class="fas fa-mobile-alt mr-2"></i>Send Test SMS
                            </button>
                            <button type="button" onclick="testWhatsApp()" class="px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700">
                                <i class="fab fa-whatsapp mr-2"></i>Send Test WhatsApp
                            </button>
                            <button type="button" onclick="resetSection('sms')" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                                Reset to Default
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Payment Gateway Settings -->
                <div id="payment-tab" class="tab-content <?php echo $activeTab !== 'payment' ? 'hidden' : ''; ?>">
                    <form method="POST">
                        <input type="hidden" name="tab" value="payment">
                        <h3 class="text-lg font-bold mb-4">Payment Gateway Configuration</h3>
                        
                        <!-- M-Pesa -->
                        <div class="mb-6 p-4 border rounded-lg">
                            <div class="flex items-center justify-between mb-4">
                                <h4 class="font-semibold flex items-center">
                                    <i class="fas fa-mobile-alt text-green-600 mr-2"></i>M-Pesa (Lipa Na M-Pesa)
                                </h4>
                                <label class="flex items-center">
                                    <input type="checkbox" name="enable_mpesa" 
                                           <?php echo ($settings['enable_mpesa'] ?? '0') === '1' ? 'checked' : ''; ?>
                                           class="mr-2">
                                    <span class="text-sm font-semibold">Enable</span>
                                </label>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold mb-2">Environment</label>
                                    <select name="mpesa_environment" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                        <option value="sandbox" <?php echo ($settings['mpesa_environment'] ?? 'sandbox') === 'sandbox' ? 'selected' : ''; ?>>Sandbox</option>
                                        <option value="production" <?php echo ($settings['mpesa_environment'] ?? '') === 'production' ? 'selected' : ''; ?>>Production</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold mb-2">Business Shortcode</label>
                                    <input type="text" name="mpesa_shortcode" value="<?php echo $settings['mpesa_shortcode'] ?? ''; ?>"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold mb-2">Consumer Key</label>
                                    <input type="password" name="mpesa_consumer_key" placeholder="••••••••"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold mb-2">Consumer Secret</label>
                                    <input type="password" name="mpesa_consumer_secret" placeholder="••••••••"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                                
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold mb-2">Passkey</label>
                                    <input type="password" name="mpesa_passkey" placeholder="••••••••"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                            </div>
                            <button type="button" onclick="testMpesa()" class="mt-3 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm">
                                <i class="fas fa-check mr-1"></i>Test M-Pesa Connection
                            </button>
                        </div>
                        
                        <!-- Stripe -->
                        <div class="mb-6 p-4 border rounded-lg">
                            <div class="flex items-center justify-between mb-4">
                                <h4 class="font-semibold flex items-center">
                                    <i class="fab fa-cc-stripe text-blue-600 mr-2"></i>Stripe
                                </h4>
                                <label class="flex items-center">
                                    <input type="checkbox" name="enable_stripe" 
                                           <?php echo ($settings['enable_stripe'] ?? '0') === '1' ? 'checked' : ''; ?>
                                           class="mr-2">
                                    <span class="text-sm font-semibold">Enable</span>
                                </label>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold mb-2">Publishable Key</label>
                                    <input type="text" name="stripe_publishable_key" placeholder="pk_..."
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold mb-2">Secret Key</label>
                                    <input type="password" name="stripe_secret_key" placeholder="sk_..."
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                            </div>
                            <button type="button" onclick="testStripe()" class="mt-3 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                                <i class="fas fa-check mr-1"></i>Test Stripe Connection
                            </button>
                        </div>
                        
                        <!-- PayPal -->
                        <div class="mb-6 p-4 border rounded-lg">
                            <div class="flex items-center justify-between mb-4">
                                <h4 class="font-semibold flex items-center">
                                    <i class="fab fa-paypal text-blue-500 mr-2"></i>PayPal
                                </h4>
                                <label class="flex items-center">
                                    <input type="checkbox" name="enable_paypal" 
                                           <?php echo ($settings['enable_paypal'] ?? '0') === '1' ? 'checked' : ''; ?>
                                           class="mr-2">
                                    <span class="text-sm font-semibold">Enable</span>
                                </label>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold mb-2">Client ID</label>
                                    <input type="password" name="paypal_client_id" placeholder="••••••••"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold mb-2">Secret</label>
                                    <input type="password" name="paypal_secret" placeholder="••••••••"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Bank Transfer -->
                        <div class="mb-6 p-4 border rounded-lg">
                            <h4 class="font-semibold mb-4 flex items-center">
                                <i class="fas fa-university text-purple-600 mr-2"></i>Bank Transfer Details
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold mb-2">Bank Name</label>
                                    <input type="text" name="bank_name" value="<?php echo $settings['bank_name'] ?? ''; ?>"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold mb-2">Account Number</label>
                                    <input type="text" name="bank_account_number" value="<?php echo $settings['bank_account_number'] ?? ''; ?>"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                                
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold mb-2">Account Name</label>
                                    <input type="text" name="bank_account_name" value="<?php echo $settings['bank_account_name'] ?? ''; ?>"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex gap-3 mt-6">
                            <button type="submit" class="px-6 py-3 bg-primary text-white rounded-lg hover:opacity-90">
                                Save Changes
                            </button>
                            <button type="button" onclick="resetSection('payment')" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                                Reset to Default
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Business Settings -->
                <div id="business-tab" class="tab-content <?php echo $activeTab !== 'business' ? 'hidden' : ''; ?>">
                    <form method="POST">
                        <input type="hidden" name="tab" value="business">
                        <h3 class="text-lg font-bold mb-4">Business Information</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-semibold mb-2">Company Registration Number</label>
                                <input type="text" name="company_registration_number" value="<?php echo $settings['company_registration_number'] ?? ''; ?>"
                                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold mb-2">Tax/VAT Number</label>
                                <input type="text" name="tax_number" value="<?php echo $settings['tax_number'] ?? ''; ?>"
                                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold mb-2">Fiscal Year Start Date</label>
                                <input type="date" name="fiscal_year_start" value="<?php echo $settings['fiscal_year_start'] ?? date('Y-01-01'); ?>"
                                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold mb-2">Default Currency</label>
                                <select name="default_currency" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                    <option value="KES" <?php echo ($settings['default_currency'] ?? 'KES') === 'KES' ? 'selected' : ''; ?>>KES - Kenyan Shilling</option>
                                    <option value="USD" <?php echo ($settings['default_currency'] ?? '') === 'USD' ? 'selected' : ''; ?>>USD - US Dollar</option>
                                    <option value="EUR" <?php echo ($settings['default_currency'] ?? '') === 'EUR' ? 'selected' : ''; ?>>EUR - Euro</option>
                                    <option value="GBP" <?php echo ($settings['default_currency'] ?? '') === 'GBP' ? 'selected' : ''; ?>>GBP - British Pound</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold mb-2">
                                    Sales Commission (%)
                                    <i class="fas fa-info-circle text-gray-400 ml-1" title="Default commission percentage for sales agents"></i>
                                </label>
                                <input type="number" name="commission_percentage" value="<?php echo $settings['commission_percentage'] ?? '5'; ?>" 
                                       step="0.1" min="0" max="100"
                                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                            </div>
                            
                            <div class="md:col-span-2">
                                <label class="block text-sm font-semibold mb-2">Payment Terms & Conditions</label>
                                <textarea name="payment_terms" rows="4" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary"><?php echo $settings['payment_terms'] ?? ''; ?></textarea>
                            </div>
                        </div>
                        
                        <div class="flex gap-3 mt-6">
                            <button type="submit" class="px-6 py-3 bg-primary text-white rounded-lg hover:opacity-90">
                                Save Changes
                            </button>
                            <button type="button" onclick="resetSection('business')" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                                Reset to Default
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Notification Preferences -->
                <div id="notifications-tab" class="tab-content <?php echo $activeTab !== 'notifications' ? 'hidden' : ''; ?>">
                    <form method="POST">
                        <input type="hidden" name="tab" value="notifications">
                        <h3 class="text-lg font-bold mb-4">Notification Preferences</h3>
                        
                        <div class="mb-6">
                            <h4 class="font-semibold mb-3">Notification Channels</h4>
                            <div class="space-y-2">
                                <label class="flex items-center">
                                    <input type="checkbox" name="notify_via_email" 
                                           <?php echo ($settings['notify_via_email'] ?? '1') === '1' ? 'checked' : ''; ?>
                                           class="mr-2">
                                    <span class="text-sm"><i class="fas fa-envelope mr-2"></i>Email Notifications</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="notify_via_sms" 
                                           <?php echo ($settings['notify_via_sms'] ?? '0') === '1' ? 'checked' : ''; ?>
                                           class="mr-2">
                                    <span class="text-sm"><i class="fas fa-sms mr-2"></i>SMS Notifications</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="notify_via_push" 
                                           <?php echo ($settings['notify_via_push'] ?? '1') === '1' ? 'checked' : ''; ?>
                                           class="mr-2">
                                    <span class="text-sm"><i class="fas fa-bell mr-2"></i>Push Notifications</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-6">
                            <h4 class="font-semibold mb-3">Event Triggers</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <label class="flex items-center">
                                    <input type="checkbox" name="notify_lead_created" 
                                           <?php echo ($settings['notify_lead_created'] ?? '1') === '1' ? 'checked' : ''; ?>
                                           class="mr-2">
                                    <span class="text-sm">New Lead Created</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="notify_sale_created" 
                                           <?php echo ($settings['notify_sale_created'] ?? '1') === '1' ? 'checked' : ''; ?>
                                           class="mr-2">
                                    <span class="text-sm">New Sale Recorded</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="notify_payment_received" 
                                           <?php echo ($settings['notify_payment_received'] ?? '1') === '1' ? 'checked' : ''; ?>
                                           class="mr-2">
                                    <span class="text-sm">Payment Received</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="notify_task_assigned" 
                                           <?php echo ($settings['notify_task_assigned'] ?? '1') === '1' ? 'checked' : ''; ?>
                                           class="mr-2">
                                    <span class="text-sm">Task Assigned</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-6">
                            <h4 class="font-semibold mb-3">Quiet Hours</h4>
                            <p class="text-sm text-gray-600 mb-3">No notifications will be sent during these hours</p>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold mb-2">Start Time</label>
                                    <input type="time" name="quiet_hours_start" value="<?php echo $settings['quiet_hours_start'] ?? '22:00'; ?>"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold mb-2">End Time</label>
                                    <input type="time" name="quiet_hours_end" value="<?php echo $settings['quiet_hours_end'] ?? '07:00'; ?>"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex gap-3 mt-6">
                            <button type="submit" class="px-6 py-3 bg-primary text-white rounded-lg hover:opacity-90">
                                Save Changes
                            </button>
                            <button type="button" onclick="resetSection('notifications')" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                                Reset to Default
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Security Settings -->
                <div id="security-tab" class="tab-content <?php echo $activeTab !== 'security' ? 'hidden' : ''; ?>">
                    <form method="POST">
                        <input type="hidden" name="tab" value="security">
                        <h3 class="text-lg font-bold mb-4">Security Configuration</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                            <div>
                                <label class="block text-sm font-semibold mb-2">Session Timeout (minutes)</label>
                                <input type="number" name="session_timeout" value="<?php echo $settings['session_timeout'] ?? '60'; ?>" min="5" max="1440"
                                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold mb-2">Max Login Attempts</label>
                                <input type="number" name="max_login_attempts" value="<?php echo $settings['max_login_attempts'] ?? '5'; ?>" min="3" max="10"
                                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold mb-2">Audit Log Retention (days)</label>
                                <input type="number" name="audit_log_retention" value="<?php echo $settings['audit_log_retention'] ?? '90'; ?>" min="30" max="365"
                                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                            </div>
                            
                            <div>
                                <label class="flex items-center h-full">
                                    <input type="checkbox" name="enable_2fa" 
                                           <?php echo ($settings['enable_2fa'] ?? '0') === '1' ? 'checked' : ''; ?>
                                           class="mr-2">
                                    <span class="text-sm font-semibold">Enable Two-Factor Authentication</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-6">
                            <h4 class="font-semibold mb-3">Password Policy</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm font-semibold mb-2">Minimum Length</label>
                                    <input type="number" name="password_min_length" value="<?php echo $settings['password_min_length'] ?? '8'; ?>" min="6" max="20"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                                <div class="space-y-2">
                                    <label class="flex items-center">
                                        <input type="checkbox" name="password_require_uppercase" 
                                               <?php echo ($settings['password_require_uppercase'] ?? '1') === '1' ? 'checked' : ''; ?>
                                               class="mr-2">
                                        <span class="text-sm">Require Uppercase Letters</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="checkbox" name="password_require_numbers" 
                                               <?php echo ($settings['password_require_numbers'] ?? '1') === '1' ? 'checked' : ''; ?>
                                               class="mr-2">
                                        <span class="text-sm">Require Numbers</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="checkbox" name="password_require_special" 
                                               <?php echo ($settings['password_require_special'] ?? '0') === '1' ? 'checked' : ''; ?>
                                               class="mr-2">
                                        <span class="text-sm">Require Special Characters</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-6">
                            <label class="block text-sm font-semibold mb-2">IP Whitelist (one per line, leave blank to allow all)</label>
                            <textarea name="ip_whitelist" rows="4" placeholder="192.168.1.1&#10;10.0.0.1"
                                      class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary"><?php echo $settings['ip_whitelist'] ?? ''; ?></textarea>
                        </div>
                        
                        <div class="flex gap-3 mt-6">
                            <button type="submit" class="px-6 py-3 bg-primary text-white rounded-lg hover:opacity-90">
                                Save Changes
                            </button>
                            <button type="button" onclick="resetSection('security')" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                                Reset to Default
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- System Preferences -->
                <div id="system-tab" class="tab-content <?php echo $activeTab !== 'system' ? 'hidden' : ''; ?>">
                    <form method="POST">
                        <input type="hidden" name="tab" value="system">
                        <h3 class="text-lg font-bold mb-4">System Preferences</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-semibold mb-2">Date Format</label>
                                <select name="date_format" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                    <option value="Y-m-d" <?php echo ($settings['date_format'] ?? 'Y-m-d') === 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                                    <option value="d/m/Y" <?php echo ($settings['date_format'] ?? '') === 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY</option>
                                    <option value="m/d/Y" <?php echo ($settings['date_format'] ?? '') === 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY</option>
                                    <option value="d-M-Y" <?php echo ($settings['date_format'] ?? '') === 'd-M-Y' ? 'selected' : ''; ?>>DD-MMM-YYYY</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold mb-2">Time Zone</label>
                                <select name="time_zone" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                    <option value="Africa/Nairobi" <?php echo ($settings['time_zone'] ?? 'Africa/Nairobi') === 'Africa/Nairobi' ? 'selected' : ''; ?>>Africa/Nairobi (EAT)</option>
                                    <option value="UTC" <?php echo ($settings['time_zone'] ?? '') === 'UTC' ? 'selected' : ''; ?>>UTC</option>
                                    <option value="America/New_York" <?php echo ($settings['time_zone'] ?? '') === 'America/New_York' ? 'selected' : ''; ?>>America/New_York (EST)</option>
                                    <option value="Europe/London" <?php echo ($settings['time_zone'] ?? '') === 'Europe/London' ? 'selected' : ''; ?>>Europe/London (GMT)</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold mb-2">Language</label>
                                <select name="language" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                    <option value="en" <?php echo ($settings['language'] ?? 'en') === 'en' ? 'selected' : ''; ?>>English</option>
                                    <option value="sw" <?php echo ($settings['language'] ?? '') === 'sw' ? 'selected' : ''; ?>>Kiswahili</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold mb-2">Items Per Page</label>
                                <input type="number" name="items_per_page" value="<?php echo $settings['items_per_page'] ?? '20'; ?>" min="10" max="100"
                                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold mb-2">Decimal Separator</label>
                                <select name="decimal_separator" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                    <option value="." <?php echo ($settings['decimal_separator'] ?? '.') === '.' ? 'selected' : ''; ?>>. (dot)</option>
                                    <option value="," <?php echo ($settings['decimal_separator'] ?? '') === ',' ? 'selected' : ''; ?>>, (comma)</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold mb-2">Thousands Separator</label>
                                <select name="thousands_separator" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                    <option value="," <?php echo ($settings['thousands_separator'] ?? ',') === ',' ? 'selected' : ''; ?>>, (comma)</option>
                                    <option value="." <?php echo ($settings['thousands_separator'] ?? '') === '.' ? 'selected' : ''; ?>>. (dot)</option>
                                    <option value=" " <?php echo ($settings['thousands_separator'] ?? '') === ' ' ? 'selected' : ''; ?>>  (space)</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="flex gap-3 mt-6">
                            <button type="submit" class="px-6 py-3 bg-primary text-white rounded-lg hover:opacity-90">
                                Save Changes
                            </button>
                            <button type="button" onclick="resetSection('system')" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                                Reset to Default
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Backup & Maintenance -->
                <div id="backup-tab" class="tab-content <?php echo $activeTab !== 'backup' ? 'hidden' : ''; ?>">
                    <form method="POST">
                        <input type="hidden" name="tab" value="backup">
                        <h3 class="text-lg font-bold mb-4">Backup & Maintenance</h3>
                        
                        <div class="mb-6">
                            <h4 class="font-semibold mb-3">Automated Backups</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold mb-2">Backup Schedule</label>
                                    <select name="backup_schedule" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                        <option value="disabled" <?php echo ($settings['backup_schedule'] ?? 'daily') === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                                        <option value="daily" <?php echo ($settings['backup_schedule'] ?? 'daily') === 'daily' ? 'selected' : ''; ?>>Daily</option>
                                        <option value="weekly" <?php echo ($settings['backup_schedule'] ?? '') === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                        <option value="monthly" <?php echo ($settings['backup_schedule'] ?? '') === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold mb-2">Retention Period (days)</label>
                                    <input type="number" name="backup_retention_days" value="<?php echo $settings['backup_retention_days'] ?? '30'; ?>" min="7" max="365"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                            </div>
                            
                            <button type="button" onclick="createBackupNow()" class="mt-3 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                <i class="fas fa-database mr-2"></i>Create Backup Now
                            </button>
                        </div>
                        
                        <div class="mb-6">
                            <h4 class="font-semibold mb-3">Maintenance Mode</h4>
                            <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                                <label class="flex items-center mb-3">
                                    <input type="checkbox" name="enable_maintenance_mode" 
                                           <?php echo ($settings['enable_maintenance_mode'] ?? '0') === '1' ? 'checked' : ''; ?>
                                           class="mr-2">
                                    <span class="text-sm font-semibold text-yellow-800">Enable Maintenance Mode</span>
                                </label>
                                <div>
                                    <label class="block text-sm font-semibold mb-2 text-yellow-800">Maintenance Message</label>
                                    <textarea name="maintenance_message" rows="3" 
                                              class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary"><?php echo $settings['maintenance_message'] ?? 'System is under maintenance. Please check back soon.'; ?></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-6">
                            <h4 class="font-semibold mb-3">System Logs</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold mb-2">Log Cleanup (days)</label>
                                    <input type="number" name="log_cleanup_days" value="<?php echo $settings['log_cleanup_days'] ?? '30'; ?>" min="7" max="90"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                                <div class="flex items-end">
                                    <button type="button" onclick="optimizeDatabase()" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                                        <i class="fas fa-tools mr-2"></i>Optimize Database
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex gap-3 mt-6">
                            <button type="submit" class="px-6 py-3 bg-primary text-white rounded-lg hover:opacity-90">
                                Save Changes
                            </button>
                            <button type="button" onclick="resetSection('backup')" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                                Reset to Default
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Integrations -->
                <div id="integrations-tab" class="tab-content <?php echo $activeTab !== 'integrations' ? 'hidden' : ''; ?>">
                    <form method="POST">
                        <input type="hidden" name="tab" value="integrations">
                        <h3 class="text-lg font-bold mb-4">Third-Party Integrations</h3>
                        
                        <div class="mb-6">
                            <h4 class="font-semibold mb-3">Google Services</h4>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-sm font-semibold mb-2">Google Maps API Key</label>
                                    <input type="password" name="google_maps_api_key" placeholder="••••••••"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold mb-2">Google Analytics Tracking ID</label>
                                    <input type="text" name="google_analytics_id" value="<?php echo $settings['google_analytics_id'] ?? ''; ?>" placeholder="G-XXXXXXXXXX"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-6">
                            <h4 class="font-semibold mb-3">Social Media Links</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold mb-2"><i class="fab fa-facebook text-blue-600 mr-2"></i>Facebook</label>
                                    <input type="url" name="facebook_url" value="<?php echo $settings['facebook_url'] ?? ''; ?>" placeholder="https://facebook.com/yourpage"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold mb-2"><i class="fab fa-instagram text-pink-600 mr-2"></i>Instagram</label>
                                    <input type="url" name="instagram_url" value="<?php echo $settings['instagram_url'] ?? ''; ?>" placeholder="https://instagram.com/yourprofile"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold mb-2"><i class="fab fa-twitter text-blue-400 mr-2"></i>Twitter</label>
                                    <input type="url" name="twitter_url" value="<?php echo $settings['twitter_url'] ?? ''; ?>" placeholder="https://twitter.com/yourhandle"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold mb-2"><i class="fab fa-linkedin text-blue-700 mr-2"></i>LinkedIn</label>
                                    <input type="url" name="linkedin_url" value="<?php echo $settings['linkedin_url'] ?? ''; ?>" placeholder="https://linkedin.com/company/yourcompany"
                                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-6">
                            <h4 class="font-semibold mb-3">Webhook Integration</h4>
                            <div>
                                <label class="block text-sm font-semibold mb-2">Webhook URL</label>
                                <input type="url" name="webhook_url" value="<?php echo $settings['webhook_url'] ?? ''; ?>" placeholder="https://your-service.com/webhook"
                                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                                <p class="text-xs text-gray-500 mt-1">Receive real-time notifications about system events</p>
                            </div>
                            <button type="button" onclick="testWebhook()" class="mt-3 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                                <i class="fas fa-paper-plane mr-1"></i>Test Webhook
                            </button>
                        </div>
                        
                        <div class="flex gap-3 mt-6">
                            <button type="submit" class="px-6 py-3 bg-primary text-white rounded-lg hover:opacity-90">
                                Save Changes
                            </button>
                            <button type="button" onclick="resetSection('integrations')" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                                Reset to Default
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Advanced Features -->
                <div id="features-tab" class="tab-content <?php echo $activeTab !== 'features' ? 'hidden' : ''; ?>">
                    <form method="POST">
                        <input type="hidden" name="tab" value="features">
                        <h3 class="text-lg font-bold mb-4">Advanced Features</h3>
                        <p class="text-gray-600 mb-6">Enable or disable advanced features for your system</p>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="p-4 border rounded-lg hover:border-primary transition">
                                <label class="flex items-start cursor-pointer">
                                    <input type="checkbox" name="enable_ai_predictions" 
                                           <?php echo ($settings['enable_ai_predictions'] ?? '1') === '1' ? 'checked' : ''; ?>
                                           class="mt-1 mr-3">
                                    <div>
                                        <span class="text-sm font-semibold block mb-1">
                                            <i class="fas fa-brain text-purple-600 mr-2"></i>AI Predictions
                                        </span>
                                        <p class="text-xs text-gray-600">Enable AI-powered predictions for lead conversion and revenue forecasting</p>
                                    </div>
                                </label>
                            </div>
                            
                            <div class="p-4 border rounded-lg hover:border-primary transition">
                                <label class="flex items-start cursor-pointer">
                                    <input type="checkbox" name="enable_lead_scoring" 
                                           <?php echo ($settings['enable_lead_scoring'] ?? '1') === '1' ? 'checked' : ''; ?>
                                           class="mt-1 mr-3">
                                    <div>
                                        <span class="text-sm font-semibold block mb-1">
                                            <i class="fas fa-star text-yellow-600 mr-2"></i>Lead Scoring
                                        </span>
                                        <p class="text-xs text-gray-600">Automatically score and prioritize leads based on engagement</p>
                                    </div>
                                </label>
                            </div>
                            
                            <div class="p-4 border rounded-lg hover:border-primary transition">
                                <label class="flex items-start cursor-pointer">
                                    <input type="checkbox" name="enable_workflow_automation" 
                                           <?php echo ($settings['enable_workflow_automation'] ?? '1') === '1' ? 'checked' : ''; ?>
                                           class="mt-1 mr-3">
                                    <div>
                                        <span class="text-sm font-semibold block mb-1">
                                            <i class="fas fa-project-diagram text-blue-600 mr-2"></i>Workflow Automation
                                        </span>
                                        <p class="text-xs text-gray-600">Automate repetitive tasks with custom workflows</p>
                                    </div>
                                </label>
                            </div>
                            
                            <div class="p-4 border rounded-lg hover:border-primary transition">
                                <label class="flex items-start cursor-pointer">
                                    <input type="checkbox" name="enable_client_portal" 
                                           <?php echo ($settings['enable_client_portal'] ?? '0') === '1' ? 'checked' : ''; ?>
                                           class="mt-1 mr-3">
                                    <div>
                                        <span class="text-sm font-semibold block mb-1">
                                            <i class="fas fa-user-circle text-green-600 mr-2"></i>Client Portal
                                        </span>
                                        <p class="text-xs text-gray-600">Give clients access to view their purchases and payments</p>
                                    </div>
                                </label>
                            </div>
                            
                            <div class="p-4 border rounded-lg hover:border-primary transition">
                                <label class="flex items-start cursor-pointer">
                                    <input type="checkbox" name="enable_mobile_app" 
                                           <?php echo ($settings['enable_mobile_app'] ?? '1') === '1' ? 'checked' : ''; ?>
                                           class="mt-1 mr-3">
                                    <div>
                                        <span class="text-sm font-semibold block mb-1">
                                            <i class="fas fa-mobile-alt text-indigo-600 mr-2"></i>Mobile App Access
                                        </span>
                                        <p class="text-xs text-gray-600">Allow staff to access the system via mobile apps</p>
                                    </div>
                                </label>
                            </div>
                            
                            <div class="p-4 border rounded-lg hover:border-primary transition">
                                <label class="flex items-start cursor-pointer">
                                    <input type="checkbox" name="enable_analytics_dashboard" 
                                           <?php echo ($settings['enable_analytics_dashboard'] ?? '1') === '1' ? 'checked' : ''; ?>
                                           class="mt-1 mr-3">
                                    <div>
                                        <span class="text-sm font-semibold block mb-1">
                                            <i class="fas fa-chart-pie text-red-600 mr-2"></i>Analytics Dashboard
                                        </span>
                                        <p class="text-xs text-gray-600">Access advanced analytics and reporting features</p>
                                    </div>
                                </label>
                            </div>
                        </div>
                        
                        <div class="flex gap-3 mt-6">
                            <button type="submit" class="px-6 py-3 bg-primary text-white rounded-lg hover:opacity-90">
                                Save Changes
                            </button>
                            <button type="button" onclick="resetSection('features')" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                                Reset to Default
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Tab switching
function switchTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.add('hidden');
    });
    
    // Remove active class from all buttons
    document.querySelectorAll('.settings-tab').forEach(btn => {
        btn.classList.remove('active', 'border-primary', 'text-primary');
        btn.classList.add('border-transparent', 'text-gray-600');
    });
    
    // Show selected tab
    document.getElementById(tabName + '-tab').classList.remove('hidden');
    
    // Add active class to clicked button
    const activeBtn = document.querySelector(`[data-tab="${tabName}"]`);
    activeBtn.classList.add('active', 'border-primary', 'text-primary');
    activeBtn.classList.remove('border-transparent', 'text-gray-600');
    
    // Update URL
    const url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    window.history.pushState({}, '', url);
}

// Search functionality
function searchSettings(query) {
    query = query.toLowerCase();
    const allContent = document.querySelectorAll('.tab-content');
    
    if (!query) {
        // Show current active tab only
        return;
    }
    
    allContent.forEach(content => {
        const text = content.textContent.toLowerCase();
        if (text.includes(query)) {
            content.classList.remove('hidden');
        } else {
            content.classList.add('hidden');
        }
    });
}

// Test functions
function testEmail() {
    const email = prompt('Enter test email address:');
    if (email) {
        fetch('/api/test/email.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({email: email})
        })
        .then(res => res.json())
        .then(data => {
            alert(data.success ? 'Test email sent successfully!' : 'Error: ' + data.message);
        });
    }
}

function testSMS() {
    const phone = prompt('Enter test phone number:');
    if (phone) {
        fetch('/api/test/sms.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({phone: phone})
        })
        .then(res => res.json())
        .then(data => {
            alert(data.success ? 'Test SMS sent successfully!' : 'Error: ' + data.message);
        });
    }
}

function testWhatsApp() {
    const phone = prompt('Enter test WhatsApp number:');
    if (phone) {
        fetch('/api/test/whatsapp.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({phone: phone})
        })
        .then(res => res.json())
        .then(data => {
            alert(data.success ? 'Test WhatsApp message sent!' : 'Error: ' + data.message);
        });
    }
}

function testMpesa() {
    alert('Testing M-Pesa connection...');
    fetch('/api/test/mpesa.php', {
        method: 'POST'
    })
    .then(res => res.json())
    .then(data => {
        alert(data.success ? 'M-Pesa connection successful!' : 'Error: ' + data.message);
    });
}

function testStripe() {
    fetch('/api/test/stripe.php', {
        method: 'POST'
    })
    .then(res => res.json())
    .then(data => {
        alert(data.success ? 'Stripe connection successful!' : 'Error: ' + data.message);
    });
}

function testWebhook() {
    fetch('/api/test/webhook.php', {
        method: 'POST'
    })
    .then(res => res.json())
    .then(data => {
        alert(data.success ? 'Webhook test sent successfully!' : 'Error: ' + data.message);
    });
}

// Backup and maintenance
function createBackupNow() {
    if (confirm('Create a full system backup now?')) {
        alert('Backup process started. This may take a few minutes...');
        fetch('/api/backup/create.php', {
            method: 'POST'
        })
        .then(res => res.json())
        .then(data => {
            alert(data.success ? 'Backup created successfully!' : 'Error: ' + data.message);
        });
    }
}

function optimizeDatabase() {
    if (confirm('Optimize database? This may take a few minutes.')) {
        fetch('/api/maintenance/optimize.php', {
            method: 'POST'
        })
        .then(res => res.json())
        .then(data => {
            alert(data.success ? 'Database optimized successfully!' : 'Error: ' + data.message);
        });
    }
}

// Export/Import settings
function exportSettings() {
    fetch('/api/settings/export.php')
    .then(res => res.blob())
    .then(blob => {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'settings_' + new Date().toISOString().split('T')[0] + '.json';
        a.click();
    });
}

function importSettings(input) {
    const file = input.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            if (confirm('Import settings? This will overwrite current settings.')) {
                fetch('/api/settings/import.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: e.target.result
                })
                .then(res => res.json())
                .then(data => {
                    alert(data.success ? 'Settings imported successfully!' : 'Error: ' + data.message);
                    if (data.success) location.reload();
                });
            }
        };
        reader.readAsText(file);
    }
}

// Reset section to defaults
function resetSection(section) {
    if (confirm(`Reset ${section} settings to default values?`)) {
        fetch('/api/settings/reset.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({section: section})
        })
        .then(res => res.json())
        .then(data => {
            alert(data.success ? 'Settings reset successfully!' : 'Error: ' + data.message);
            if (data.success) location.reload();
        });
    }
}

// Style active tab
document.querySelectorAll('.settings-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        const tabName = this.getAttribute('data-tab');
        switchTab(tabName);
    });
});
</script>

<style>
.settings-tab.active {
    color: var(--primary-color);
    border-color: var(--primary-color);
}

.settings-tab {
    transition: all 0.3s ease;
}

.settings-tab:hover {
    color: var(--primary-color);
}
</style>

<?php include 'includes/footer.php'; ?>