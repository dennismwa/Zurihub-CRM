<?php
$pageTitle = 'Departments';
require_once 'config.php';
requirePermission('departments', 'view');

$action = $_GET['action'] ?? 'list';
$departmentId = $_GET['id'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'create' && hasPermission('departments', 'create')) {
        $departmentName = sanitize($_POST['department_name']);
        $departmentHead = !empty($_POST['department_head']) ? intval($_POST['department_head']) : null;
        $description = sanitize($_POST['description']);
        $budget = floatval($_POST['budget']);
        
        $stmt = $pdo->prepare("INSERT INTO departments (department_name, department_head, description, budget) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$departmentName, $departmentHead, $description, $budget])) {
            logActivity('Create Department', "Created department: $departmentName");
            flashMessage('Department created successfully!');
            redirect('/departments.php');
        }
    } elseif ($action === 'edit' && hasPermission('departments', 'edit')) {
        $departmentName = sanitize($_POST['department_name']);
        $departmentHead = !empty($_POST['department_head']) ? intval($_POST['department_head']) : null;
        $description = sanitize($_POST['description']);
        $budget = floatval($_POST['budget']);
        $status = $_POST['status'];
        
        $stmt = $pdo->prepare("UPDATE departments SET department_name = ?, department_head = ?, description = ?, budget = ?, status = ? WHERE id = ?");
        if ($stmt->execute([$departmentName, $departmentHead, $description, $budget, $status, $departmentId])) {
            logActivity('Update Department', "Updated department: $departmentName");
            flashMessage('Department updated successfully!');
            redirect('/departments.php');
        }
    }
}

// Get department data
if ($action === 'edit' && $departmentId) {
    $stmt = $pdo->prepare("SELECT * FROM departments WHERE id = ?");
    $stmt->execute([$departmentId]);
    $department = $stmt->fetch();
    
    if (!$department) {
        redirect('/departments.php');
    }
}

// Get all departments with statistics
$stmt = $pdo->query("
    SELECT d.*, 
           u.full_name as head_name,
           (SELECT COUNT(*) FROM users WHERE department_id = d.id AND status = 'active') as staff_count,
           (SELECT COALESCE(SUM(amount), 0) FROM expenses 
            WHERE department_id = d.id 
            AND MONTH(expense_date) = MONTH(CURRENT_DATE()) 
            AND YEAR(expense_date) = YEAR(CURRENT_DATE())
            AND status IN ('approved', 'paid')) as monthly_expenses
    FROM departments d
    LEFT JOIN users u ON d.department_head = u.id
    ORDER BY d.department_name
");
$departments = $stmt->fetchAll();

// Get users for department head selection
$stmt = $pdo->query("SELECT id, full_name FROM users WHERE status = 'active' ORDER BY full_name");
$users = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="p-4 md:p-6 pb-20 md:pb-6">
    <?php if ($action === 'list'): ?>
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
        <div>
            <h1 class="text-2xl md:text-3xl font-bold text-gray-800">Departments</h1>
            <p class="text-gray-600 mt-1">Manage organizational departments</p>
        </div>
        <?php if (hasPermission('departments', 'create')): ?>
        <a href="/departments.php?action=create" class="mt-4 md:mt-0 inline-flex items-center px-4 py-2 bg-primary text-white rounded-lg hover:opacity-90 transition">
            <i class="fas fa-plus mr-2"></i>Add Department
        </a>
        <?php endif; ?>
    </div>
    
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($departments as $dept): ?>
        <div class="bg-white rounded-lg shadow-lg p-6 hover:shadow-xl transition">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h3 class="text-xl font-bold text-gray-800"><?php echo sanitize($dept['department_name']); ?></h3>
                    <?php if ($dept['head_name']): ?>
                    <p class="text-sm text-gray-600 mt-1">
                        <i class="fas fa-user-tie mr-1"></i><?php echo sanitize($dept['head_name']); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <span class="px-2 py-1 text-xs rounded-full <?php echo $dept['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>">
                    <?php echo ucfirst($dept['status']); ?>
                </span>
            </div>
            
            <?php if ($dept['description']): ?>
            <p class="text-sm text-gray-600 mb-4"><?php echo sanitize($dept['description']); ?></p>
            <?php endif; ?>
            
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div class="text-center p-3 bg-blue-50 rounded-lg">
                    <p class="text-2xl font-bold text-blue-600"><?php echo $dept['staff_count']; ?></p>
                    <p class="text-xs text-gray-600">Staff Members</p>
                </div>
                <div class="text-center p-3 bg-purple-50 rounded-lg">
                    <p class="text-lg font-bold text-purple-600"><?php echo formatMoney($dept['budget']); ?></p>
                    <p class="text-xs text-gray-600">Budget</p>
                </div>
            </div>
            
            <div class="mb-4">
                <div class="flex justify-between items-center mb-1">
                    <span class="text-xs text-gray-600">Monthly Spending</span>
                    <span class="text-xs font-semibold"><?php echo formatMoney($dept['monthly_expenses']); ?></span>
                </div>
                <?php 
                $spendPercentage = $dept['budget'] > 0 ? ($dept['monthly_expenses'] / $dept['budget']) * 100 : 0;
                ?>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="h-2 rounded-full <?php echo $spendPercentage > 90 ? 'bg-red-500' : ($spendPercentage > 70 ? 'bg-yellow-500' : 'bg-green-500'); ?>" 
                         style="width: <?php echo min(100, $spendPercentage); ?>%"></div>
                </div>
                <p class="text-xs text-gray-500 mt-1"><?php echo number_format($spendPercentage, 1); ?>% of budget used</p>
            </div>
            
            <?php if (hasPermission('departments', 'edit')): ?>
            <a href="/departments.php?action=edit&id=<?php echo $dept['id']; ?>" 
               class="block w-full text-center px-4 py-2 bg-primary text-white rounded-lg hover:opacity-90 transition">
                <i class="fas fa-edit mr-2"></i>Edit Department
            </a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    
    <?php elseif ($action === 'create' || $action === 'edit'): ?>
    <div class="max-w-2xl mx-auto">
        <div class="mb-6">
            <a href="/departments.php" class="text-primary hover:underline">
                <i class="fas fa-arrow-left mr-2"></i>Back to Departments
            </a>
        </div>
        
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-2xl font-bold mb-6"><?php echo $action === 'create' ? 'Add Department' : 'Edit Department'; ?></h2>
            
            <form method="POST">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Department Name *</label>
                        <input type="text" name="department_name" required
                               value="<?php echo $action === 'edit' ? sanitize($department['department_name']) : ''; ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Department Head</label>
                        <select name="department_head" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
                            <option value="">Select Department Head</option>
                            <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" 
                                    <?php echo ($action === 'edit' && $department['department_head'] == $user['id']) ? 'selected' : ''; ?>>
                                <?php echo sanitize($user['full_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Budget</label>
                        <input type="number" name="budget" step="0.01" min="0"
                               value="<?php echo $action === 'edit' ? $department['budget'] : '0'; ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
                    </div>
                    
                    <?php if ($action === 'edit'): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Status</label>
                        <select name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
                            <option value="active" <?php echo $department['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $department['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Description</label>
                        <textarea name="description" rows="4"
                                  class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"><?php echo $action === 'edit' ? sanitize($department['description']) : ''; ?></textarea>
                    </div>
                </div>
                
                <div class="flex gap-3 mt-6">
                    <button type="submit" class="flex-1 bg-primary text-white py-3 rounded-lg font-semibold hover:opacity-90 transition">
                        <?php echo $action === 'create' ? 'Create Department' : 'Update Department'; ?>
                    </button>
                    <a href="/departments.php" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg font-semibold hover:bg-gray-300 transition">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
