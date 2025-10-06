<?php
$pageTitle = 'WhatsApp Communications';
require_once 'config.php';
require_once 'app/services/WhatsAppService.php';
requirePermission('communications', 'view');

$whatsapp = new WhatsAppService($pdo);
$action = $_GET['action'] ?? 'list';

// Handle sending messages
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'send') {
    $to = sanitize($_POST['phone']);
    $message = sanitize($_POST['message']);
    $type = $_POST['type'] ?? 'text';
    
    $result = $whatsapp->sendMessage($to, $message, $type);
    
    if ($result['success']) {
        flashMessage('WhatsApp message sent successfully!');
    } else {
        flashMessage('Failed to send message: ' . $result['error'], 'error');
    }
    
    redirect('/whatsapp-chat.php');
}

// Get WhatsApp messages
$stmt = $pdo->query("
    SELECT * FROM whatsapp_messages 
    ORDER BY created_at DESC 
    LIMIT 100
");
$messages = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="p-4 md:p-6 pb-20 md:pb-6">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl md:text-3xl font-bold text-gray-800">WhatsApp Communications</h1>
            <p class="text-gray-600 mt-1">Manage WhatsApp business messages</p>
        </div>
        <button onclick="openSendModal()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
            <i class="fab fa-whatsapp mr-2"></i>Send Message
        </button>
    </div>
    
    <!-- Messages List -->
    <div class="bg-white rounded-lg shadow">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Direction</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Contact</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Message</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Time</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($messages as $msg): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 text-xs rounded-full <?php 
                                echo $msg['direction'] === 'inbound' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'; 
                            ?>">
                                <?php echo $msg['direction'] === 'inbound' ? '← In' : 'Out →'; ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php echo sanitize($msg['direction'] === 'inbound' ? $msg['from_number'] : $msg['to_number']); ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <p class="truncate max-w-xs"><?php echo sanitize($msg['content']); ?></p>
                        </td>
                        <td class="px-4 py-3 text-sm"><?php echo ucfirst($msg['type']); ?></td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 text-xs rounded-full <?php 
                                echo $msg['status'] === 'sent' ? 'bg-green-100 text-green-800' : 
                                    ($msg['status'] === 'failed' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800');
                            ?>">
                                <?php echo ucfirst($msg['status']); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            <?php echo formatDate($msg['created_at'], 'M d, h:i A'); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Send Message Modal -->
<div id="sendModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg max-w-md w-full p-6">
        <h3 class="text-xl font-bold mb-4">Send WhatsApp Message</h3>
        
        <form method="POST" action="?action=send">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold mb-2">Phone Number</label>
                    <input type="tel" name="phone" required placeholder="+254..." 
                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                </div>
                
                <div>
                    <label class="block text-sm font-semibold mb-2">Message Type</label>
                    <select name="type" class="w-full px-4 py-2 border rounded-lg">
                        <option value="text">Text Message</option>
                        <option value="template">Template</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-semibold mb-2">Message</label>
                    <textarea name="message" rows="4" required 
                              class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
                              placeholder="Type your message here..."></textarea>
                </div>
            </div>
            
            <div class="flex gap-3 mt-6">
                <button type="submit" class="flex-1 bg-green-600 text-white py-2 rounded-lg hover:bg-green-700">
                    <i class="fab fa-whatsapp mr-2"></i>Send
                </button>
                <button type="button" onclick="closeSendModal()" 
                        class="px-6 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openSendModal() {
    document.getElementById('sendModal').classList.remove('hidden');
}

function closeSendModal() {
    document.getElementById('sendModal').classList.add('hidden');
}
</script>

<?php include 'includes/footer.php'; ?>