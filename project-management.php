<?php
$pageTitle = 'Project Management';
require_once 'config.php';
requirePermission('projects', 'view');

$projectId = $_GET['project_id'] ?? null;
$action = $_GET['action'] ?? 'view';

if (!$projectId) {
    redirect('/projects.php');
}

// Get project details
$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

if (!$project) {
    redirect('/projects.php');
}

// Handle task creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_task' && hasPermission('projects', 'edit')) {
    $taskName = sanitize($_POST['task_name']);
    $description = sanitize($_POST['description']);
    $assignedTo = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;
    $priority = $_POST['priority'];
    $startDate = $_POST['start_date'];
    $dueDate = $_POST['due_date'];
    
    $stmt = $pdo->prepare("INSERT INTO project_tasks (project_id, task_name, description, assigned_to, priority, start_date, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt->execute([$projectId, $taskName, $description, $assignedTo, $priority, $startDate, $dueDate, getUserId()])) {
        flashMessage('Task created successfully!');
        redirect("/project-management.php?project_id=$projectId");
    }
}

// Handle task status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_task') {
    $taskId = intval($_POST['task_id']);
    $status = $_POST['status'];
    $progress = intval($_POST['progress']);
    
    $completionDate = ($status === 'completed') ? date('Y-m-d') : null;
    
    $stmt = $pdo->prepare("UPDATE project_tasks SET status = ?, progress = ?, completion_date = ? WHERE id = ? AND project_id = ?");
    if ($stmt->execute([$status, $progress, $completionDate, $taskId, $projectId])) {
        echo json_encode(['success' => true]);
        exit;
    }
}

// Get project tasks
$stmt = $pdo->prepare("
    SELECT t.*, u.full_name as assigned_to_name, c.full_name as created_by_name
    FROM project_tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    LEFT JOIN users c ON t.created_by = c.id
    WHERE t.project_id = ?
    ORDER BY 
        CASE t.priority 
            WHEN 'urgent' THEN 1
            WHEN 'high' THEN 2
            WHEN 'medium' THEN 3
            WHEN 'low' THEN 4
        END,
        t.due_date ASC
");
$stmt->execute([$projectId]);
$tasks = $stmt->fetchAll();

// Get project documents
$stmt = $pdo->prepare("
    SELECT d.*, u.full_name as uploaded_by_name
    FROM project_documents d
    LEFT JOIN users u ON d.uploaded_by = u.id
    WHERE d.project_id = ?
    ORDER BY d.uploaded_at DESC
");
$stmt->execute([$projectId]);
$documents = $stmt->fetchAll();

// Get team members
$stmt = $pdo->query("SELECT id, full_name FROM users WHERE status = 'active' ORDER BY full_name");
$teamMembers = $stmt->fetchAll();

// Calculate statistics
$totalTasks = count($tasks);
$completedTasks = count(array_filter($tasks, fn($t) => $t['status'] === 'completed'));
$inProgressTasks = count(array_filter($tasks, fn($t) => $t['status'] === 'in_progress'));
$overdueTasks = count(array_filter($tasks, fn($t) => $t['status'] !== 'completed' && strtotime($t['due_date']) < time()));

$completionPercentage = $totalTasks > 0 ? ($completedTasks / $totalTasks) * 100 : 0;

include 'includes/header.php';
?>

<div class="p-4 md:p-6 pb-20 md:pb-6">
    <!-- Header -->
    <div class="mb-6">
        <a href="/projects.php" class="text-primary hover:underline mb-4 inline-block">
            <i class="fas fa-arrow-left mr-2"></i>Back to Projects
        </a>
        <div class="flex flex-col md:flex-row md:items-center md:justify-between">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold text-gray-800"><?php echo sanitize($project['project_name']); ?></h1>
                <p class="text-gray-600 mt-1">
                    <i class="fas fa-map-marker-alt mr-1"></i><?php echo sanitize($project['location']); ?>
                </p>
            </div>
            <button onclick="openTaskModal()" class="mt-4 md:mt-0 px-4 py-2 bg-primary text-white rounded-lg hover:opacity-90">
                <i class="fas fa-plus mr-2"></i>Add Task
            </button>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-gray-600">Total Tasks</p>
            <p class="text-2xl font-bold text-primary"><?php echo $totalTasks; ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-gray-600">Completed</p>
            <p class="text-2xl font-bold text-green-600"><?php echo $completedTasks; ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-gray-600">In Progress</p>
            <p class="text-2xl font-bold text-blue-600"><?php echo $inProgressTasks; ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-gray-600">Overdue</p>
            <p class="text-2xl font-bold text-red-600"><?php echo $overdueTasks; ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-gray-600">Progress</p>
            <p class="text-2xl font-bold text-purple-600"><?php echo number_format($completionPercentage, 0); ?>%</p>
        </div>
    </div>
    
    <!-- Progress Bar -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h3 class="font-bold mb-3">Overall Project Progress</h3>
        <div class="w-full bg-gray-200 rounded-full h-4">
            <div class="bg-gradient-to-r from-primary to-secondary h-4 rounded-full transition-all duration-500" 
                 style="width: <?php echo $completionPercentage; ?>%"></div>
        </div>
        <p class="text-sm text-gray-600 mt-2"><?php echo $completedTasks; ?> of <?php echo $totalTasks; ?> tasks completed</p>
    </div>
    
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Tasks Column -->
        <div class="lg:col-span-2 space-y-4">
            <!-- Tasks by Status -->
            <?php
            $statuses = [
                'pending' => ['title' => 'Pending Tasks', 'color' => 'yellow', 'icon' => 'clock'],
                'in_progress' => ['title' => 'In Progress', 'color' => 'blue', 'icon' => 'spinner'],
                'completed' => ['title' => 'Completed', 'color' => 'green', 'icon' => 'check-circle'],
                'on_hold' => ['title' => 'On Hold', 'color' => 'gray', 'icon' => 'pause-circle']
            ];
            
            foreach ($statuses as $statusKey => $statusInfo):
                $statusTasks = array_filter($tasks, fn($t) => $t['status'] === $statusKey);
                if (empty($statusTasks)) continue;
            ?>
            <div class="bg-white rounded-lg shadow">
                <div class="p-4 border-b bg-<?php echo $statusInfo['color']; ?>-50">
                    <h3 class="font-bold text-<?php echo $statusInfo['color']; ?>-800">
                        <i class="fas fa-<?php echo $statusInfo['icon']; ?> mr-2"></i>
                        <?php echo $statusInfo['title']; ?> (<?php echo count($statusTasks); ?>)
                    </h3>
                </div>
                <div class="p-4 space-y-3">
                    <?php foreach ($statusTasks as $task): 
                        $isOverdue = ($task['status'] !== 'completed' && strtotime($task['due_date']) < time());
                    ?>
                    <div class="border rounded-lg p-4 hover:shadow-md transition <?php echo $isOverdue ? 'border-red-300 bg-red-50' : ''; ?>">
                        <div class="flex items-start justify-between mb-2">
                            <div class="flex-1">
                                <h4 class="font-semibold"><?php echo sanitize($task['task_name']); ?></h4>
                                <?php if ($task['description']): ?>
                                <p class="text-sm text-gray-600 mt-1"><?php echo sanitize($task['description']); ?></p>
                                <?php endif; ?>
                            </div>
                            <span class="px-2 py-1 text-xs rounded-full ml-2 <?php 
                                echo $task['priority'] === 'urgent' ? 'bg-red-100 text-red-800' : 
                                    ($task['priority'] === 'high' ? 'bg-orange-100 text-orange-800' : 
                                    ($task['priority'] === 'medium' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800'));
                            ?>">
                                <?php echo ucfirst($task['priority']); ?>
                            </span>
                        </div>
                        
                        <div class="flex items-center justify-between text-sm text-gray-600 mb-3">
                            <div>
                                <i class="fas fa-user mr-1"></i>
                                <?php echo $task['assigned_to_name'] ? sanitize($task['assigned_to_name']) : 'Unassigned'; ?>
                            </div>
                            <div class="<?php echo $isOverdue ? 'text-red-600 font-semibold' : ''; ?>">
                                <i class="fas fa-calendar mr-1"></i>
                                <?php echo formatDate($task['due_date'], 'M d, Y'); ?>
                            </div>
                        </div>
                        
                        <!-- Progress Bar -->
                        <div class="mb-3">
                            <div class="flex justify-between items-center mb-1">
                                <span class="text-xs text-gray-600">Progress</span>
                                <span class="text-xs font-semibold"><?php echo $task['progress']; ?>%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-primary h-2 rounded-full" style="width: <?php echo $task['progress']; ?>%"></div>
                            </div>
                        </div>
                        
                        <div class="flex gap-2">
                            <select onchange="updateTaskStatus(<?php echo $task['id']; ?>, this.value)" 
                                    class="text-sm px-2 py-1 border rounded">
                                <option value="pending" <?php echo $task['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="in_progress" <?php echo $task['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="completed" <?php echo $task['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="on_hold" <?php echo $task['status'] === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                            </select>
                            <input type="range" min="0" max="100" value="<?php echo $task['progress']; ?>" 
                                   onchange="updateTaskProgress(<?php echo $task['id']; ?>, this.value)"
                                   class="flex-1" title="Update progress">
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Project Info -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold mb-4">Project Details</h3>
                <div class="space-y-3">
                    <div>
                        <p class="text-sm text-gray-600">Total Plots</p>
                        <p class="font-semibold"><?php echo $project['total_plots']; ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Status</p>
                        <span class="px-2 py-1 text-xs rounded-full <?php 
                            echo $project['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800';
                        ?>">
                            <?php echo ucfirst($project['status']); ?>
                        </span>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Created</p>
                        <p class="font-semibold"><?php echo formatDate($project['created_at'], 'M d, Y'); ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Documents -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold">Documents</h3>
                    <button onclick="document.getElementById('uploadDoc').click()" class="text-primary hover:underline text-sm">
                        <i class="fas fa-upload mr-1"></i>Upload
                    </button>
                    <input type="file" id="uploadDoc" class="hidden" onchange="uploadDocument(this)">
                </div>
                <div class="space-y-2">
                    <?php if (empty($documents)): ?>
                    <p class="text-sm text-gray-500">No documents uploaded</p>
                    <?php else: ?>
                        <?php foreach ($documents as $doc): ?>
                        <div class="flex items-center justify-between p-2 hover:bg-gray-50 rounded">
                            <div class="flex items-center flex-1 min-w-0">
                                <i class="fas fa-file-pdf text-red-500 mr-2"></i>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold truncate"><?php echo sanitize($doc['document_name']); ?></p>
                                    <p class="text-xs text-gray-500"><?php echo formatDate($doc['uploaded_at'], 'M d, Y'); ?></p>
                                </div>
                            </div>
                            <a href="<?php echo $doc['file_path']; ?>" target="_blank" class="text-primary ml-2">
                                <i class="fas fa-download"></i>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Task Modal -->
<div id="taskModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg max-w-2xl w-full p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">Create New Task</h3>
            <button onclick="closeTaskModal()" class="text-gray-600 hover:text-gray-900">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <form method="POST" action="/project-management.php?project_id=<?php echo $projectId; ?>&action=create_task">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold mb-2">Task Name *</label>
                    <input type="text" name="task_name" required 
                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                </div>
                
                <div>
                    <label class="block text-sm font-semibold mb-2">Description</label>
                    <textarea name="description" rows="3" 
                              class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary"></textarea>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold mb-2">Assign To</label>
                        <select name="assigned_to" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                            <option value="">Unassigned</option>
                            <?php foreach ($teamMembers as $member): ?>
                            <option value="<?php echo $member['id']; ?>"><?php echo sanitize($member['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold mb-2">Priority</label>
                        <select name="priority" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold mb-2">Start Date</label>
                        <input type="date" name="start_date" value="<?php echo date('Y-m-d'); ?>"
                               class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold mb-2">Due Date *</label>
                        <input type="date" name="due_date" required 
                               class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                    </div>
                </div>
            </div>
            
            <div class="flex gap-3 mt-6">
                <button type="submit" class="flex-1 bg-primary text-white py-3 rounded-lg font-semibold hover:opacity-90">
                    Create Task
                </button>
                <button type="button" onclick="closeTaskModal()" class="px-6 py-3 bg-gray-200 rounded-lg hover:bg-gray-300">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openTaskModal() {
    document.getElementById('taskModal').classList.remove('hidden');
}

function closeTaskModal() {
    document.getElementById('taskModal').classList.add('hidden');
}

function updateTaskStatus(taskId, status) {
    const progress = (status === 'completed') ? 100 : (status === 'in_progress' ? 50 : 0);
    updateTask(taskId, status, progress);
}

function updateTaskProgress(taskId, progress) {
    const currentStatus = event.target.closest('.border').querySelector('select').value;
    const newStatus = (progress >= 100) ? 'completed' : (progress > 0 ? 'in_progress' : 'pending');
    updateTask(taskId, newStatus, progress);
}

function updateTask(taskId, status, progress) {
    fetch('/project-management.php?project_id=<?php echo $projectId; ?>&action=update_task', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `task_id=${taskId}&status=${status}&progress=${progress}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

function uploadDocument(input) {
    if (input.files && input.files[0]) {
        const formData = new FormData();
        formData.append('document', input.files[0]);
        formData.append('project_id', <?php echo $projectId; ?>);
        
        fetch('/api/projects/upload-document.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('Document uploaded successfully!');
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        });
    }
}
</script>

<?php include 'includes/footer.php'; ?>
