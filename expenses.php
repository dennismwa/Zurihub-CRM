<?php
$pageTitle = 'Expense Management';
require_once 'config.php';
requirePermission('expenses', 'view');

$action = $_GET['action'] ?? 'list';
$expenseId = $_GET['id'] ?? null;
$userId = getUserId();
$userRole = getUserRole();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'create' && hasPermission('expenses', 'create')) {
        $categoryId = intval($_POST['category_id']);
        $departmentId = !empty($_POST['department_id']) ? intval($_POST['department_id']) : null;
        $amount = floatval($_POST['amount']);
        $expenseDate = $_POST['expense_date'];
        $description = sanitize($_POST['description']);
        $vendorName = sanitize($_POST['vendor_name']);
        $paymentMethod = $_POST['payment_method'];
        $referenceNumber = sanitize($_POST['reference_number']);
        
        // Generate expense number
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM expenses");
        $count = $stmt->fetch()['count'] + 1;
        $expenseNumber = 'EXP-' . date('Y') . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
        
        // Handle file upload
        $receiptPath = null;
        if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === 0) {
            $uploadResult = uploadFile($_FILES['receipt'], 'expenses');
            if ($uploadResult['success']) {
                $receiptPath = $uploadResult['path'];
            }
        }
        
        $stmt = $pdo->prepare("INSERT INTO expenses (expense_number, category_id, department_id, amount, expense_date, description, vendor_name, receipt_path, payment_method, reference_number, submitted_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
        
        if ($stmt->execute([$expenseNumber, $categoryId, $departmentId, $amount, $expenseDate, $description, $vendorName, $receiptPath, $paymentMethod, $referenceNumber, $userId])) {
            logActivity('Create Expense', "Created expense: $expenseNumber");
            flashMessage('Expense submitted successfully!');
            redirect('/expenses.php');
        }
    } elseif ($action === 'approve' && hasPermission('expenses', 'approve')) {
        $stmt = $pdo->prepare("UPDATE expenses SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        if ($stmt->execute([$userId, $expenseId])) {
            logActivity('Approve Expense', "Approved expense ID: $expenseId");
            flashMessage('Expense approved successfully!');
        }
        redirect('/expenses.php');
    } elseif ($action === 'reject' && hasPermission('expenses', 'approve')) {
        $notes = sanitize($_POST['rejection_notes']);
        $stmt = $pdo->prepare("UPDATE expenses SET status = 'rejected', approved_by = ?, approved_at = NOW(), notes = ? WHERE id = ?");
        if ($stmt->execute([$userId, $notes, $expenseId])) {
            logActivity('Reject Expense', "Rejected expense ID: $expenseId");
            flashMessage('Expense rejected!');
        }
        redirect('/expenses.php');
    }
}

// Get expenses based on user role
if (in_array($userRole, ['admin', 'manager', 'finance'])) {
    $stmt = $pdo->query("
        SELECT e.*, 
               ec.category_name, ec.color, ec.icon,
               d.department_name,
               u.full_name as submitted_by_name,
               a.full_name as approved_by_name
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN users u ON e.submitted_by = u.id
        LEFT JOIN users a ON e.approved_by = a.id
        ORDER BY e.created_at DESC
        LIMIT 100
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT e.*, 
               ec.category_name, ec.color, ec.icon,
               d.department_name,
               u.full_name as submitted_by_name,
               a.full_name as approved_by_name
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN users u ON e.submitted_by = u.id
        LEFT JOIN users a ON e.approved_by = a.id
        WHERE e.submitted_by = ?
        ORDER BY e.created_at DESC
    ");
    $stmt->execute([$userId]);
}
$expenses = $stmt->fetchAll();

// Get categories
$stmt = $pdo->query("SELECT * FROM expense_categories WHERE is_active = 1 ORDER BY category_name");
$categories = $stmt->fetchAll();

// Get departments
$stmt = $pdo->query("SELECT * FROM departments WHERE status = 'active' ORDER BY department_name");
$departments = $stmt->fetchAll();

// Calculate statistics
$totalExpenses = array_sum(array_column($expenses, 'amount'));
$pendingCount = count(array_filter($expenses, fn($e) => $e['status'] === 'pending'));
$approvedCount = count(array_filter($expenses, fn($e) => $e['status'] === 'approved'));

// Monthly expenses
$stmt = $pdo->query("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM expenses 
    WHERE MONTH(expense_date) = MONTH(CURRENT_DATE()) 
    AND YEAR(expense_date) = YEAR(CURRENT_DATE())
    AND status IN ('approved', 'paid')
");
$monthlyTotal = $stmt->fetch()['total'];

// Expenses by category
$stmt = $pdo->query("
    SELECT ec.category_name, ec.color, COALESCE(SUM(e.amount), 0) as total
    FROM expense_categories ec
    LEFT JOIN expenses e ON ec.id = e.category_id 
        AND MONTH(e.expense_date) = MONTH(CURRENT_DATE()) 
        AND YEAR(e.expense_date) = YEAR(CURRENT_DATE())
        AND e.status IN ('approved', 'paid')
    WHERE ec.is_active = 1
    GROUP BY ec.id, ec.category_name, ec.color
    ORDER BY total DESC
");
$categoryExpenses = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="p-4 md:p-6 pb-20 md:pb-6">
    <?php if ($action === 'list'): ?>
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
        <div>
            <h1 class="text-2xl md:text-3xl font-bold text-gray-800">Expense Management</h1>
            <p class="text-gray-600 mt-1">Track and manage company expenses</p>
        </div>
        <?php if (hasPermission('expenses', 'create')): ?>
        <a href="/expenses.php?action=create" class="mt-4 md:mt-0 inline-flex items-center px-4 py-2 bg-primary text-white rounded-lg hover:opacity-90 transition">
            <i class="fas fa-plus mr-2"></i>Submit Expense
        </a>
        <?php endif; ?>
    </div>
    
    <!-- Statistics Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">Total Expenses</p>
                    <p class="text-xl font-bold text-primary mt-1"><?php echo formatMoney($totalExpenses); ?></p>
                </div>
                <i class="fas fa-receipt text-3xl text-primary opacity-20"></i>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">This Month</p>
                    <p class="text-xl font-bold text-green-600 mt-1"><?php echo formatMoney($monthlyTotal); ?></p>
                </div>
                <i class="fas fa-calendar text-3xl text-green-600 opacity-20"></i>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">Pending</p>
                    <p class="text-2xl font-bold text-yellow-600 mt-1"><?php echo $pendingCount; ?></p>
                </div>
                <i class="fas fa-clock text-3xl text-yellow-600 opacity-20"></i>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">Approved</p>
                    <p class="text-2xl font-bold text-blue-600 mt-1"><?php echo $approvedCount; ?></p>
                </div>
                <i class="fas fa-check-circle text-3xl text-blue-600 opacity-20"></i>
            </div>
        </div>
    </div>
    
    <!-- Charts -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Category Breakdown -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-bold mb-4">Expenses by Category (This Month)</h3>
            <div class="space-y-3">
                <?php foreach ($categoryExpenses as $cat): 
                    if ($cat['total'] > 0):
                        $percentage = $monthlyTotal > 0 ? ($cat['total'] / $monthlyTotal) * 100 : 0;
                ?>
                <div>
                    <div class="flex justify-between items-center mb-1">
                        <span class="text-sm font-semibold"><?php echo sanitize($cat['category_name']); ?></span>
                        <span class="text-sm font-bold"><?php echo formatMoney($cat['total']); ?></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="h-2 rounded-full" 
                             style="width: <?php echo $percentage; ?>%; background-color: <?php echo $cat['color']; ?>"></div>
                    </div>
                </div>
                <?php 
                    endif;
                endforeach; ?>
            </div>
        </div>
        
        <!-- Quick Stats -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-bold mb-4">Top Spending Categories</h3>
            <div class="space-y-3">
                <?php foreach (array_slice($categoryExpenses, 0, 5) as $cat): 
                    if ($cat['total'] > 0):
                ?>
                <div class="flex items-center justify-between p-3 rounded-lg" style="background-color: <?php echo $cat['color']; ?>20">
                    <div class="flex items-center">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center mr-3" 
                             style="background-color: <?php echo $cat['color']; ?>30">
                            <i class="fas fa-chart-pie" style="color: <?php echo $cat['color']; ?>"></i>
                        </div>
                        <span class="font-semibold"><?php echo sanitize($cat['category_name']); ?></span>
                    </div>
                    <span class="font-bold" style="color: <?php echo $cat['color']; ?>"><?php echo formatMoney($cat['total']); ?></span>
                </div>
                <?php 
                    endif;
                endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Expenses Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Expense #</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Category</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Description</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Amount</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($expenses as $expense): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm font-mono"><?php echo sanitize($expense['expense_number']); ?></td>
                        <td class="px-4 py-3 text-sm"><?php echo formatDate($expense['expense_date'], 'M d, Y'); ?></td>
                        <td class="px-4 py-3">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center mr-2" 
                                     style="background-color: <?php echo $expense['color']; ?>30">
                                    <i class="fas <?php echo $expense['icon']; ?> text-sm" style="color: <?php echo $expense['color']; ?>"></i>
                                </div>
                                <span class="text-sm font-semibold"><?php echo sanitize($expense['category_name']); ?></span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm max-w-xs truncate">
                            <?php echo sanitize($expense['description']); ?>
                            <?php if ($expense['vendor_name']): ?>
                            <p class="text-xs text-gray-500">Vendor: <?php echo sanitize($expense['vendor_name']); ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 font-bold text-gray-800"><?php echo formatMoney($expense['amount']); ?></td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 text-xs rounded-full <?php 
                                echo $expense['status'] === 'approved' ? 'bg-green-100 text-green-800' : 
                                    ($expense['status'] === 'rejected' ? 'bg-red-100 text-red-800' : 
                                    ($expense['status'] === 'paid' ? 'bg-blue-100 text-blue-800' : 'bg-yellow-100 text-yellow-800')); 
                            ?>">
                                <?php echo ucfirst($expense['status']); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex gap-2">
                                <button onclick="viewExpense(<?php echo $expense['id']; ?>)" class="text-primary hover:text-opacity-80" title="View">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($expense['status'] === 'pending' && hasPermission('expenses', 'approve')): ?>
                                <form method="POST" action="/expenses.php?action=approve&id=<?php echo $expense['id']; ?>" class="inline">
                                    <button type="submit" class="text-green-600 hover:text-green-800" title="Approve">
                                        <i class="fas fa-check"></i>
                                    </button>
                                </form>
                                <button onclick="rejectExpense(<?php echo $expense['id']; ?>)" class="text-red-600 hover:text-red-800" title="Reject">
                                    <i class="fas fa-times"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($expenses)): ?>
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                            No expenses recorded yet
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php elseif ($action === 'create'): ?>
    <!-- Create Expense Form -->
    <div class="max-w-2xl mx-auto">
        <div class="mb-6">
            <a href="/expenses.php" class="text-primary hover:underline">
                <i class="fas fa-arrow-left mr-2"></i>Back to Expenses
            </a>
        </div>
        
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-2xl font-bold mb-6">Submit Expense</h2>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Category *</label>
                            <select name="category_id" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>">
                                    <?php echo sanitize($cat['category_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Department</label>
                            <select name="department_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>">
                                    <?php echo sanitize($dept['department_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Amount *</label>
                            <input type="number" name="amount" required step="0.01" min="0"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Expense Date *</label>
                            <input type="date" name="expense_date" required value="<?php echo date('Y-m-d'); ?>"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Vendor/Supplier Name</label>
                        <input type="text" name="vendor_name"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Description *</label>
                        <textarea name="description" required rows="3" placeholder="Describe the expense..."
                                  class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"></textarea>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Payment Method</label>
                            <select name="payment_method" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="mpesa">M-Pesa</option>
                                <option value="card">Card</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Reference Number</label>
                            <input type="text" name="reference_number" placeholder="Transaction reference"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Upload Receipt/Invoice</label>
                        <input type="file" name="receipt" accept="image/*,.pdf" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
                        <p class="text-xs text-gray-500 mt-1">Accepted: Images, PDF (Max 10MB)</p>
                    </div>
                </div>
                
                <div class="flex gap-3 mt-6">
                    <button type="submit" class="flex-1 bg-primary text-white py-3 rounded-lg font-semibold hover:opacity-90 transition">
                        Submit Expense
                    </button>
                    <a href="/expenses.php" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg font-semibold hover:bg-gray-300 transition">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- View Expense Modal -->
<div id="expenseModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg max-w-2xl w-full p-6 max-h-screen overflow-y-auto">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-xl font-bold">Expense Details</h3>
            <button onclick="closeExpenseModal()" class="text-gray-600 hover:text-gray-900">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div id="expenseModalContent">
            <!-- Content loaded via JavaScript -->
        </div>
    </div>
</div>

<!-- Reject Expense Modal -->
<div id="rejectModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg max-w-md w-full p-6">
        <h3 class="text-xl font-bold mb-4">Reject Expense</h3>
        <form method="POST" id="rejectForm">
            <div class="mb-4">
                <label class="block text-sm font-semibold mb-2">Reason for Rejection</label>
                <textarea name="rejection_notes" required rows="4" 
                          class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary"
                          placeholder="Provide reason for rejection..."></textarea>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="flex-1 bg-red-600 text-white py-2 rounded-lg hover:bg-red-700">
                    Reject Expense
                </button>
                <button type="button" onclick="closeRejectModal()" class="px-6 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function viewExpense(id) {
    fetch('/api/expenses/view.php?id=' + id)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const expense = data.expense;
                const content = `
                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-sm text-gray-600">Expense Number</p>
                                <p class="font-semibold">${expense.expense_number}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Status</p>
                                <span class="px-2 py-1 text-xs rounded-full ${
                                    expense.status === 'approved' ? 'bg-green-100 text-green-800' : 
                                    expense.status === 'rejected' ? 'bg-red-100 text-red-800' : 
                                    'bg-yellow-100 text-yellow-800'
                                }">${expense.status}</span>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Category</p>
                                <p class="font-semibold">${expense.category_name}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Amount</p>
                                <p class="font-bold text-primary">${expense.amount_formatted}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Date</p>
                                <p class="font-semibold">${expense.expense_date_formatted}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Payment Method</p>
                                <p class="font-semibold">${expense.payment_method}</p>
                            </div>
                        </div>
                        ${expense.vendor_name ? `
                        <div>
                            <p class="text-sm text-gray-600">Vendor</p>
                            <p class="font-semibold">${expense.vendor_name}</p>
                        </div>` : ''}
                        <div>
                            <p class="text-sm text-gray-600">Description</p>
                            <p class="text-gray-800">${expense.description}</p>
                        </div>
                        ${expense.receipt_path ? `
                        <div>
                            <p class="text-sm text-gray-600 mb-2">Receipt/Invoice</p>
                            <a href="${expense.receipt_path}" target="_blank" class="text-primary hover:underline">
                                <i class="fas fa-file-download mr-2"></i>View Receipt
                            </a>
                        </div>` : ''}
                        <div class="border-t pt-4 mt-4">
                            <p class="text-sm text-gray-600">Submitted by</p>
                            <p class="font-semibold">${expense.submitted_by_name}</p>
                            <p class="text-xs text-gray-500">${expense.created_at_formatted}</p>
                        </div>
                        ${expense.approved_by_name ? `
                        <div>
                            <p class="text-sm text-gray-600">Approved/Rejected by</p>
                            <p class="font-semibold">${expense.approved_by_name}</p>
                            <p class="text-xs text-gray-500">${expense.approved_at_formatted}</p>
                        </div>` : ''}
                    </div>
                `;
                document.getElementById('expenseModalContent').innerHTML = content;
                document.getElementById('expenseModal').classList.remove('hidden');
            }
        });
}

function closeExpenseModal() {
    document.getElementById('expenseModal').classList.add('hidden');
}

function rejectExpense(id) {
    document.getElementById('rejectForm').action = '/expenses.php?action=reject&id=' + id;
    document.getElementById('rejectModal').classList.remove('hidden');
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.add('hidden');
}
</script>

<?php include 'includes/footer.php'; ?>
