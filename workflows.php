<?php
$pageTitle = 'Workflow Automation';
require_once 'config.php';
require_once 'app/services/WorkflowEngine.php';
requirePermission('workflows', 'view');

$workflowEngine = new WorkflowEngine($pdo);
$action = $_GET['action'] ?? 'list';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $name = sanitize($_POST['name']);
    $triggerEvent = $_POST['trigger_event'];
    
    $conditions = [];
    if (!empty($_POST['conditions'])) {
        foreach ($_POST['conditions'] as $condition) {
            if (!empty($condition['field'])) {
                $conditions[] = $condition;
            }
        }
    }
    
    $actions = [];
    if (!empty($_POST['actions'])) {
        foreach ($_POST['actions'] as $actionData) {
            if (!empty($actionData['type'])) {
                $actions[] = $actionData;
            }
        }
    }
    
    $workflowEngine->createWorkflow($name, $triggerEvent, $conditions, $actions, getUserId());
    flashMessage('Workflow created successfully!');
    redirect('/workflows.php');
}

// Get all workflows
$workflows = $workflowEngine->getAllWorkflows();

include 'includes/header.php';
?>

<div class="p-4 md:p-6 pb-20 md:pb-6">
    <?php if ($action === 'list'): ?>
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl md:text-3xl font-bold text-gray-800">Workflow Automation</h1>
            <p class="text-gray-600 mt-1">Automate your business processes</p>
        </div>
        <a href="/workflows.php?action=create" class="px-4 py-2 bg-primary text-white rounded-lg hover:opacity-90">
            <i class="fas fa-plus mr-2"></i>Create Workflow
        </a>
    </div>
    
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($workflows as $workflow): ?>
        <div class="bg-white rounded-lg shadow-lg p-6">
            <div class="flex justify-between items-start mb-4">
                <h3 class="font-bold text-lg"><?php echo sanitize($workflow['name']); ?></h3>
                <div class="flex items-center">
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" 
                               <?php echo $workflow['is_active'] ? 'checked' : ''; ?> 
                               onchange="toggleWorkflow(<?php echo $workflow['id']; ?>)"
                               class="sr-only peer">
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-primary/25 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary"></div>
                    </label>
                </div>
            </div>
            
            <div class="space-y-2 mb-4">
                <div class="flex items-center text-sm">
                    <i class="fas fa-bolt text-yellow-500 mr-2"></i>
                    <span class="text-gray-600">Trigger:</span>
                    <span class="ml-2 font-semibold"><?php echo ucfirst(str_replace('_', ' ', $workflow['trigger_event'])); ?></span>
                </div>
                
                <?php 
                $conditions = json_decode($workflow['conditions'], true);
                $actions = json_decode($workflow['actions'], true);
                ?>
                
                <div class="flex items-center text-sm">
                    <i class="fas fa-filter text-blue-500 mr-2"></i>
                    <span class="text-gray-600">Conditions:</span>
                    <span class="ml-2 font-semibold"><?php echo count($conditions); ?></span>
                </div>
                
                <div class="flex items-center text-sm">
                    <i class="fas fa-cogs text-green-500 mr-2"></i>
                    <span class="text-gray-600">Actions:</span>
                    <span class="ml-2 font-semibold"><?php echo count($actions); ?></span>
                </div>
            </div>
            
            <div class="text-xs text-gray-500 mb-4">
                Created by <?php echo sanitize($workflow['created_by_name']); ?><br>
                <?php echo formatDate($workflow['created_at']); ?>
            </div>
            
            <div class="flex gap-2">
                <button onclick="editWorkflow(<?php echo $workflow['id']; ?>)" 
                        class="flex-1 px-3 py-2 bg-gray-100 text-gray-700 rounded hover:bg-gray-200 text-sm">
                    <i class="fas fa-edit mr-1"></i> Edit
                </button>
                <button onclick="deleteWorkflow(<?php echo $workflow['id']; ?>)" 
                        class="px-3 py-2 bg-red-100 text-red-600 rounded hover:bg-red-200 text-sm">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <?php elseif ($action === 'create'): ?>
    <div class="max-w-4xl mx-auto">
        <div class="mb-6">
            <a href="/workflows.php" class="text-primary hover:underline">
                <i class="fas fa-arrow-left mr-2"></i>Back to Workflows
            </a>
        </div>
        
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h2 class="text-2xl font-bold mb-6">Create Workflow</h2>
            
            <form method="POST">
                <div class="space-y-6">
                    <div>
                        <label class="block text-sm font-semibold mb-2">Workflow Name</label>
                        <input type="text" name="name" required 
                               class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold mb-2">Trigger Event</label>
                        <select name="trigger_event" required 
                                class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                            <option value="">Select Trigger</option>
                            <option value="lead_created">When lead is created</option>
                            <option value="lead_status_changed">When lead status changes</option>
                            <option value="sale_created">When sale is created</option>
                            <option value="payment_received">When payment is received</option>
                            <option value="client_created">When client is created</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold mb-2">Conditions (Optional)</label>
                        <div id="conditionsContainer" class="space-y-2">
                            <div class="flex gap-2">
                                <input type="text" name="conditions[0][field]" placeholder="Field name" 
                                       class="flex-1 px-3 py-2 border rounded">
                                <select name="conditions[0][operator]" class="px-3 py-2 border rounded">
                                    <option value="equals">Equals</option>
                                    <option value="not_equals">Not Equals</option>
                                    <option value="contains">Contains</option>
                                </select>
                                <input type="text" name="conditions[0][value]" placeholder="Value" 
                                       class="flex-1 px-3 py-2 border rounded">
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold mb-2">Actions</label>
                        <div id="actionsContainer" class="space-y-2">
                            <div class="p-4 border rounded-lg">
                                <select name="actions[0][type]" class="w-full px-3 py-2 border rounded mb-2">
                                    <option value="">Select Action</option>
                                    <option value="send_email">Send Email</option>
                                    <option value="send_sms">Send SMS</option>
                                    <option value="create_task">Create Task</option>
                                    <option value="assign_to_user">Assign to User</option>
                                    <option value="update_status">Update Status</option>
                                </select>
                                <div class="action-config"></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="mt-6 flex gap-3">
                    <button type="submit" class="flex-1 bg-primary text-white py-3 rounded-lg font-semibold hover:opacity-90">
                        Create Workflow
                    </button>
                    <a href="/workflows.php" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg font-semibold hover:bg-gray-300">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleWorkflow(id) {
    fetch('/api/workflows/toggle.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: id})
    });
}

function deleteWorkflow(id) {
    if (confirm('Are you sure you want to delete this workflow?')) {
        window.location.href = '/workflows.php?action=delete&id=' + id;
    }
}
</script>

<?php include 'includes/footer.php'; ?>