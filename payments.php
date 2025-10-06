<?php
$pageTitle = 'Payments Management';
require_once 'config.php';
requirePermission('payments', 'view');

$action = $_GET['action'] ?? 'list';
$saleId = $_GET['sale_id'] ?? null;
$paymentId = $_GET['id'] ?? null;
$userId = getUserId();
$userRole = getUserRole();

// Handle payment creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create' && hasPermission('payments', 'create')) {
    $saleIdPost = intval($_POST['sale_id']);
    $amount = floatval($_POST['amount']);
    $paymentMethod = $_POST['payment_method'];
    $referenceNumber = sanitize($_POST['reference_number']);
    $paymentDate = $_POST['payment_date'];
    $notes = sanitize($_POST['notes']);
    
    try {
        $pdo->beginTransaction();
        
        // Verify sale exists and get details
        $stmt = $pdo->prepare("SELECT balance, client_id FROM sales WHERE id = ?");
        $stmt->execute([$saleIdPost]);
        $sale = $stmt->fetch();
        
        if (!$sale) {
            throw new Exception('Sale not found');
        }
        
        if ($amount > $sale['balance']) {
            throw new Exception('Payment amount exceeds outstanding balance');
        }
        
        // Record payment
        $stmt = $pdo->prepare("
            INSERT INTO payments 
            (sale_id, amount, payment_method, reference_number, payment_date, received_by, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$saleIdPost, $amount, $paymentMethod, $referenceNumber, $paymentDate, $userId, $notes]);
        $newPaymentId = $pdo->lastInsertId();
        
        // Update sale balance
        $stmt = $pdo->prepare("UPDATE sales SET balance = balance - ? WHERE id = ?");
        $stmt->execute([$amount, $saleIdPost]);
        
        // Check if fully paid and update status
        $stmt = $pdo->prepare("SELECT balance FROM sales WHERE id = ?");
        $stmt->execute([$saleIdPost]);
        $updatedSale = $stmt->fetch();
        
        if ($updatedSale['balance'] <= 0) {
            $stmt = $pdo->prepare("UPDATE sales SET status = 'completed', balance = 0 WHERE id = ?");
            $stmt->execute([$saleIdPost]);
        }
        
        $pdo->commit();
        
        logActivity('Record Payment', "Recorded payment of " . formatMoney($amount));
        
        // Send notification to client
        $stmt = $pdo->prepare("
            SELECT c.full_name, c.email, c.phone 
            FROM clients c 
            JOIN sales s ON c.id = s.client_id 
            WHERE s.id = ?
        ");
        $stmt->execute([$saleIdPost]);
        $client = $stmt->fetch();
        
        if ($client) {
            createNotification(
                $sale['client_id'], 
                'Payment Received', 
                "Payment of " . formatMoney($amount) . " received. Balance: " . formatMoney($updatedSale['balance']), 
                'success'
            );
        }
        
        flashMessage('Payment recorded successfully!');
        redirect("/payments.php?action=receipt&id=$newPaymentId");
    } catch (Exception $e) {
        $pdo->rollBack();
        flashMessage('Error recording payment: ' . $e->getMessage(), 'error');
    }
}

// Get payment details for receipt
if ($action === 'receipt' && $paymentId) {
    $stmt = $pdo->prepare("
        SELECT p.*, 
               s.sale_price, s.balance as sale_balance,
               c.full_name as client_name, c.phone as client_phone, c.email as client_email,
               c.id_number as client_id_number, c.address as client_address,
               pl.plot_number, pl.size as plot_size,
               pr.project_name, pr.location as project_location,
               u.full_name as received_by_name
        FROM payments p
        JOIN sales s ON p.sale_id = s.id
        JOIN clients c ON s.client_id = c.id
        JOIN plots pl ON s.plot_id = pl.id
        JOIN projects pr ON pl.project_id = pr.id
        JOIN users u ON p.received_by = u.id
        WHERE p.id = ?
    ");
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch();
    
    if (!$payment) {
        redirect('/payments.php');
    }
    
    // Calculate total paid
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total_paid 
        FROM payments 
        WHERE sale_id = ?
    ");
    $stmt->execute([$payment['sale_id']]);
    $totalPaid = $stmt->fetch()['total_paid'];
}

// Get sale info if creating payment
if ($saleId && $action === 'create') {
    $stmt = $pdo->prepare("
        SELECT s.*, 
               c.full_name as client_name, c.phone as client_phone,
               p.plot_number, p.price as plot_price,
               pr.project_name, pr.location
        FROM sales s
        JOIN clients c ON s.client_id = c.id
        JOIN plots p ON s.plot_id = p.id
        JOIN projects pr ON p.project_id = pr.id
        WHERE s.id = ?
    ");
    $stmt->execute([$saleId]);
    $saleInfo = $stmt->fetch();
    
    if ($saleInfo) {
        // Get payment history
        $stmt = $pdo->prepare("
            SELECT p.*, u.full_name as received_by_name
            FROM payments p
            JOIN users u ON p.received_by = u.id
            WHERE p.sale_id = ?
            ORDER BY p.payment_date DESC
        ");
        $stmt->execute([$saleId]);
        $paymentHistory = $stmt->fetchAll();
    }
}

// Get all payments with filters
$filterStatus = $_GET['status'] ?? 'all';
$filterMethod = $_GET['method'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';

$query = "
    SELECT p.*, 
           s.sale_price, s.balance as sale_balance,
           c.full_name as client_name, c.phone as client_phone,
           pl.plot_number,
           pr.project_name,
           u.full_name as received_by_name
    FROM payments p
    JOIN sales s ON p.sale_id = s.id
    JOIN clients c ON s.client_id = c.id
    JOIN plots pl ON s.plot_id = pl.id
    JOIN projects pr ON pl.project_id = pr.id
    JOIN users u ON p.received_by = u.id
    WHERE 1=1
";

$params = [];

if ($searchQuery) {
    $query .= " AND (c.full_name LIKE ? OR c.phone LIKE ? OR p.reference_number LIKE ?)";
    $searchParam = "%$searchQuery%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if ($filterMethod !== 'all') {
    $query .= " AND p.payment_method = ?";
    $params[] = $filterMethod;
}

$query .= " ORDER BY p.created_at DESC LIMIT 100";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll();

// Calculate statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_payments,
        COALESCE(SUM(amount), 0) as total_amount
    FROM payments
");
$stats = $stmt->fetch();

$stmt = $pdo->query("
    SELECT 
        COALESCE(SUM(amount), 0) as total 
    FROM payments 
    WHERE MONTH(payment_date) = MONTH(CURRENT_DATE()) 
    AND YEAR(payment_date) = YEAR(CURRENT_DATE())
");
$monthlyTotal = $stmt->fetch()['total'];

$stmt = $pdo->query("
    SELECT 
        COALESCE(SUM(amount), 0) as total 
    FROM payments 
    WHERE DATE(payment_date) = CURDATE()
");
$todayTotal = $stmt->fetch()['total'];

$settings = getSettings();

include 'includes/header.php';
?>

<div class="p-4 md:p-6 pb-20 md:pb-6">
    <?php if ($action === 'list'): ?>
        <!-- Payments List -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold text-gray-800">Payments</h1>
                <p class="text-gray-600 mt-1">Track all payment transactions</p>
            </div>
            
            <div class="flex gap-2 mt-4 md:mt-0">
                <button onclick="window.print()" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                    <i class="fas fa-print mr-2"></i>Print
                </button>
                <button onclick="exportToExcel()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    <i class="fas fa-file-excel mr-2"></i>Export
                </button>
            </div>
        </div>
        
        <!-- Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Total Payments</p>
                        <p class="text-2xl font-bold text-primary mt-1"><?php echo number_format($stats['total_payments']); ?></p>
                    </div>
                    <i class="fas fa-receipt text-3xl text-primary opacity-20"></i>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Total Amount</p>
                        <p class="text-lg font-bold text-green-600 mt-1"><?php echo formatMoney($stats['total_amount']); ?></p>
                    </div>
                    <i class="fas fa-money-bill-wave text-3xl text-green-600 opacity-20"></i>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">This Month</p>
                        <p class="text-lg font-bold text-blue-600 mt-1"><?php echo formatMoney($monthlyTotal); ?></p>
                    </div>
                    <i class="fas fa-calendar text-3xl text-blue-600 opacity-20"></i>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Today</p>
                        <p class="text-lg font-bold text-purple-600 mt-1"><?php echo formatMoney($todayTotal); ?></p>
                    </div>
                    <i class="fas fa-clock text-3xl text-purple-600 opacity-20"></i>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <input type="text" name="search" placeholder="Search client, phone, or reference..." 
                           value="<?php echo sanitize($searchQuery); ?>"
                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                </div>
                
                <div>
                    <select name="method" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary">
                        <option value="all">All Methods</option>
                        <option value="cash" <?php echo $filterMethod === 'cash' ? 'selected' : ''; ?>>Cash</option>
                        <option value="bank_transfer" <?php echo $filterMethod === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                        <option value="mpesa" <?php echo $filterMethod === 'mpesa' ? 'selected' : ''; ?>>M-Pesa</option>
                        <option value="cheque" <?php echo $filterMethod === 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                        <option value="card" <?php echo $filterMethod === 'card' ? 'selected' : ''; ?>>Card</option>
                    </select>
                </div>
                
                <div>
                    <button type="submit" class="w-full px-4 py-2 bg-primary text-white rounded-lg hover:opacity-90">
                        <i class="fas fa-filter mr-2"></i>Filter
                    </button>
                </div>
                
                <div>
                    <a href="/payments.php" class="block w-full text-center px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                        <i class="fas fa-redo mr-2"></i>Reset
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Payments Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full" id="paymentsTable">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Receipt #</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Client</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Property</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Amount</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Method</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Reference</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Received By</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($payments as $payment): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm font-mono">
                                #<?php echo str_pad($payment['id'], 6, '0', STR_PAD_LEFT); ?>
                            </td>
                            <td class="px-4 py-3 text-sm"><?php echo formatDate($payment['payment_date'], 'M d, Y'); ?></td>
                            <td class="px-4 py-3">
                                <p class="font-semibold text-sm"><?php echo sanitize($payment['client_name']); ?></p>
                                <p class="text-xs text-gray-500"><?php echo sanitize($payment['client_phone']); ?></p>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <p class="font-semibold"><?php echo sanitize($payment['project_name']); ?></p>
                                <p class="text-xs text-gray-500">Plot <?php echo sanitize($payment['plot_number']); ?></p>
                            </td>
                            <td class="px-4 py-3 font-bold text-green-600"><?php echo formatMoney($payment['amount']); ?></td>
                            <td class="px-4 py-3 text-sm">
                                <span class="px-2 py-1 text-xs rounded-full <?php 
                                    echo $payment['payment_method'] === 'mpesa' ? 'bg-green-100 text-green-800' :
                                        ($payment['payment_method'] === 'cash' ? 'bg-blue-100 text-blue-800' :
                                        ($payment['payment_method'] === 'bank_transfer' ? 'bg-purple-100 text-purple-800' :
                                        'bg-gray-100 text-gray-800'));
                                ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm font-mono">
                                <?php echo $payment['reference_number'] ? sanitize($payment['reference_number']) : '-'; ?>
                            </td>
                            <td class="px-4 py-3 text-sm"><?php echo sanitize($payment['received_by_name']); ?></td>
                            <td class="px-4 py-3">
                                <div class="flex gap-2">
                                    <a href="/payments.php?action=receipt&id=<?php echo $payment['id']; ?>" 
                                       class="text-primary hover:text-opacity-80" title="View Receipt">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <button onclick="printReceipt(<?php echo $payment['id']; ?>)" 
                                            class="text-blue-600 hover:text-blue-800" title="Print">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    <button onclick="downloadReceipt(<?php echo $payment['id']; ?>)" 
                                            class="text-green-600 hover:text-green-800" title="Download">
                                        <i class="fas fa-download"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-gray-500">
                                <i class="fas fa-inbox text-4xl mb-2 opacity-20"></i>
                                <p>No payments recorded yet</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
    <?php elseif ($action === 'create'): ?>
        <!-- Create Payment Form -->
        <div class="max-w-4xl mx-auto">
            <div class="mb-6">
                <a href="/payments.php" class="text-primary hover:underline">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Payments
                </a>
            </div>
            
            <?php if ($saleInfo): ?>
            <!-- Sale Information Card -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h3 class="font-bold text-lg mb-4">Sale Information</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <p class="text-sm text-gray-600">Client</p>
                        <p class="font-semibold"><?php echo sanitize($saleInfo['client_name']); ?></p>
                        <p class="text-sm text-gray-500"><?php echo sanitize($saleInfo['client_phone']); ?></p>
                    </div>
                    
                    <div>
                        <p class="text-sm text-gray-600">Property</p>
                        <p class="font-semibold"><?php echo sanitize($saleInfo['project_name']); ?></p>
                        <p class="text-sm text-gray-500">Plot <?php echo sanitize($saleInfo['plot_number']); ?></p>
                    </div>
                    
                    <div>
                        <p class="text-sm text-gray-600">Sale Price</p>
                        <p class="font-semibold text-lg"><?php echo formatMoney($saleInfo['sale_price']); ?></p>
                    </div>
                    
                    <div>
                        <p class="text-sm text-gray-600">Outstanding Balance</p>
                        <p class="font-semibold text-lg text-secondary"><?php echo formatMoney($saleInfo['balance']); ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Payment History -->
            <?php if (!empty($paymentHistory)): ?>
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h3 class="font-bold text-lg mb-4">Payment History</h3>
                <div class="space-y-2">
                    <?php foreach ($paymentHistory as $hist): ?>
                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded">
                        <div>
                            <p class="font-semibold"><?php echo formatMoney($hist['amount']); ?></p>
                            <p class="text-sm text-gray-600">
                                <?php echo formatDate($hist['payment_date'], 'M d, Y'); ?> - 
                                <?php echo ucfirst(str_replace('_', ' ', $hist['payment_method'])); ?>
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-gray-500">Received by</p>
                            <p class="text-sm font-semibold"><?php echo sanitize($hist['received_by_name']); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Payment Form -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-2xl font-bold mb-6">Record Payment</h2>
                
                <form method="POST" action="">
                    <input type="hidden" name="sale_id" value="<?php echo $saleId; ?>">
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                Payment Amount *
                            </label>
                            <div class="relative">
                                <span class="absolute left-3 top-2 text-gray-500">KES</span>
                                <input type="number" name="amount" required step="0.01" min="0.01" 
                                       max="<?php echo $saleInfo['balance']; ?>"
                                       placeholder="Enter payment amount"
                                       class="w-full pl-16 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
                            </div>
                            <p class="text-xs text-gray-500 mt-1">
                                Maximum: <?php echo formatMoney($saleInfo['balance']); ?>
                            </p>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Payment Method *
                                </label>
                                <select name="payment_method" required id="paymentMethod"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
                                    <option value="">Select Method</option>
                                    <option value="cash">Cash</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="cheque">Cheque</option>
                                    <option value="mpesa">M-Pesa</option>
                                    <option value="card">Card</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Payment Date *
                                </label>
                                <input type="date" name="payment_date" required 
                                       value="<?php echo date('Y-m-d'); ?>"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                Reference Number
                            </label>
                            <input type="text" name="reference_number" 
                                   placeholder="Transaction/Receipt number"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
                            <p class="text-xs text-gray-500 mt-1">
                                Enter M-Pesa code, cheque number, or transaction reference
                            </p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                Notes (Optional)
                            </label>
                            <textarea name="notes" rows="3" 
                                      placeholder="Additional notes about this payment"
                                      class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"></textarea>
                        </div>
                    </div>
                    
                    <div class="flex gap-3 mt-6">
                        <button type="submit" 
                                class="flex-1 bg-primary text-white py-3 rounded-lg font-semibold hover:opacity-90 transition">
                            <i class="fas fa-save mr-2"></i>Record Payment
                        </button>
                        <a href="/payments.php" 
                           class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg font-semibold hover:bg-gray-300 transition">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Online Payment Options -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-bold mb-4">
                    <i class="fas fa-globe mr-2"></i>Online Payment Options
                </h3>
                <p class="text-sm text-gray-600 mb-4">Allow client to pay online via these methods:</p>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- M-Pesa STK Push -->
                    <button onclick="initiatePayment('mpesa', <?php echo $saleInfo['balance']; ?>, <?php echo $saleId; ?>)" 
                            class="p-6 border-2 border-gray-200 rounded-lg hover:border-green-500 hover:bg-green-50 transition text-left">
                        <div class="flex items-center justify-between mb-3">
                            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-mobile-alt text-2xl text-green-600"></i>
                            </div>
                            <span class="text-xs bg-green-100 text-green-800 px-2 py-1 rounded-full">Instant</span>
                        </div>
                        <p class="font-semibold text-lg mb-1">M-Pesa (Lipa Na M-Pesa)</p>
                        <p class="text-sm text-gray-600">Instant payment via STK Push</p>
                        <p class="text-xs text-gray-500 mt-2">Client enters PIN on their phone</p>
                    </button>
                    
                    <!-- Card Payment -->
                    <button onclick="initiatePayment('card', <?php echo $saleInfo['balance']; ?>, <?php echo $saleId; ?>)" 
                            class="p-6 border-2 border-gray-200 rounded-lg hover:border-blue-500 hover:bg-blue-50 transition text-left">
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex gap-2">
                                <i class="fab fa-cc-visa text-3xl text-blue-600"></i>
                                <i class="fab fa-cc-mastercard text-3xl text-red-600"></i>
                            </div>
                            <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded-full">Secure</span>
                        </div>
                        <p class="font-semibold text-lg mb-1">Card Payment</p>
                        <p class="text-sm text-gray-600">Visa, Mastercard, Amex</p>
                        <p class="text-xs text-gray-500 mt-2">Powered by Stripe</p>
                    </button>
                    
                    <!-- Bank Transfer -->
                    <button onclick="initiatePayment('bank', <?php echo $saleInfo['balance']; ?>, <?php echo $saleId; ?>)" 
                            class="p-6 border-2 border-gray-200 rounded-lg hover:border-purple-500 hover:bg-purple-50 transition text-left">
                        <div class="flex items-center justify-between mb-3">
                            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-university text-2xl text-purple-600"></i>
                            </div>
                            <span class="text-xs bg-purple-100 text-purple-800 px-2 py-1 rounded-full">Manual</span>
                        </div>
                        <p class="font-semibold text-lg mb-1">Bank Transfer</p>
                        <p class="text-sm text-gray-600">Direct bank deposit</p>
                        <p class="text-xs text-gray-500 mt-2">Get bank details via SMS/Email</p>
                    </button>
                    
                    <!-- PayPal (Optional) -->
                    <button onclick="initiatePayment('paypal', <?php echo $saleInfo['balance']; ?>, <?php echo $saleId; ?>)" 
                            class="p-6 border-2 border-gray-200 rounded-lg hover:border-yellow-500 hover:bg-yellow-50 transition text-left">
                        <div class="flex items-center justify-between mb-3">
                            <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                                <i class="fab fa-paypal text-2xl text-blue-600"></i>
                            </div>
                            <span class="text-xs bg-yellow-100 text-yellow-800 px-2 py-1 rounded-full">Global</span>
                        </div>
                        <p class="font-semibold text-lg mb-1">PayPal</p>
                        <p class="text-sm text-gray-600">International payments</p>
                        <p class="text-xs text-gray-500 mt-2">For overseas clients</p>
                    </button>
                </div>
            </div>
            
            <?php else: ?>
            <!-- No Sale Selected -->
            <div class="bg-white rounded-lg shadow p-12 text-center">
                <i class="fas fa-exclamation-triangle text-6xl text-yellow-500 mb-4"></i>
                <p class="text-xl font-semibold text-gray-800 mb-2">No Sale Selected</p>
                <p class="text-gray-600 mb-6">Please select a sale from the sales page to record a payment.</p>
                <a href="/sales.php" 
                   class="inline-block px-6 py-3 bg-primary text-white rounded-lg hover:opacity-90 transition">
                    <i class="fas fa-arrow-left mr-2"></i>Go to Sales
                </a>
            </div>
            <?php endif; ?>
        </div>
        
    <?php elseif ($action === 'receipt' && $payment): ?>
        <!-- Receipt View -->
        <div class="max-w-4xl mx-auto">
            <div class="mb-6 flex justify-between items-center print:hidden">
                <a href="/payments.php" class="text-primary hover:underline">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Payments
                </a>
                <div class="flex gap-2">
                    <button onclick="window.print()" 
                            class="px-4 py-2 bg-primary text-white rounded-lg hover:opacity-90">
                        <i class="fas fa-print mr-2"></i>Print Receipt
                    </button>
                    <button onclick="downloadPDF()" 
                            class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                        <i class="fas fa-download mr-2"></i>Download PDF
                    </button>
                    <button onclick="sendReceipt()" 
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        <i class="fas fa-envelope mr-2"></i>Email Receipt
                    </button>
                </div>
            </div>
            
            <!-- Receipt -->
            <div id="receiptContent" class="bg-white rounded-lg shadow-lg p-8">
                <!-- Header -->
                <div class="text-center mb-8 pb-6 border-b-2 border-gray-200">
                    <?php if ($settings['logo_path']): ?>
                    <img src="<?php echo $settings['logo_path']; ?>" alt="Logo" class="h-16 mx-auto mb-4">
                    <?php endif; ?>
                    <h1 class="text-3xl font-bold text-gray-800"><?php echo sanitize($settings['site_name']); ?></h1>
                    <p class="text-gray-600 mt-1"><?php echo sanitize($settings['contact_phone']); ?> | <?php echo sanitize($settings['contact_email']); ?></p>
                    <?php if ($settings['contact_address']): ?>
                    <p class="text-sm text-gray-500"><?php echo sanitize($settings['contact_address']); ?></p>
                    <?php endif; ?>
                    <div class="mt-4">
                        <h2 class="text-2xl font-bold text-primary uppercase tracking-wider">Payment Receipt</h2>
                        <p class="text-sm text-gray-500 mt-1">Official Receipt of Payment</p>
                    </div>
                </div>
                
                <!-- Receipt Details -->
                <div class="grid grid-cols-2 gap-8 mb-8">
                    <!-- Left Column -->
                    <div>
                        <div class="mb-6">
                            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Receipt Number</p>
                            <p class="text-xl font-bold text-primary">#<?php echo str_pad($payment['id'], 6, '0', STR_PAD_LEFT); ?></p>
                        </div>
                        
                        <div class="mb-6">
                            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Payment Date</p>
                            <p class="font-semibold"><?php echo formatDate($payment['payment_date'], 'F d, Y'); ?></p>
                            <p class="text-sm text-gray-600"><?php echo formatDate($payment['created_at'], 'h:i A'); ?></p>
                        </div>
                        
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Payment Method</p>
                            <p class="font-semibold"><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></p>
                            <?php if ($payment['reference_number']): ?>
                            <p class="text-sm text-gray-600 mt-1">Ref: <?php echo sanitize($payment['reference_number']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Right Column -->
                    <div>
                        <div class="mb-6">
                            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Received From</p>
                            <p class="font-bold text-lg"><?php echo sanitize($payment['client_name']); ?></p>
                            <p class="text-sm text-gray-600"><?php echo sanitize($payment['client_phone']); ?></p>
                            <?php if ($payment['client_email']): ?>
                            <p class="text-sm text-gray-600"><?php echo sanitize($payment['client_email']); ?></p>
                            <?php endif; ?>
                            <?php if ($payment['client_id_number']): ?>
                            <p class="text-sm text-gray-600">ID: <?php echo sanitize($payment['client_id_number']); ?></p>
                            <?php endif; ?>
                        </div>
                        
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Property Details</p>
                            <p class="font-semibold"><?php echo sanitize($payment['project_name']); ?></p>
                            <p class="text-sm text-gray-600">Plot Number: <?php echo sanitize($payment['plot_number']); ?></p>
                            <p class="text-sm text-gray-600">Size: <?php echo number_format($payment['plot_size'], 2); ?> m²</p>
                            <p class="text-sm text-gray-600"><?php echo sanitize($payment['project_location']); ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- Amount Section -->
                <div class="bg-gradient-to-r from-primary to-secondary text-white rounded-lg p-6 mb-8">
                    <div class="text-center">
                        <p class="text-sm opacity-90 mb-2">Amount Paid</p>
                        <p class="text-4xl font-bold"><?php echo formatMoney($payment['amount']); ?></p>
                        <p class="text-sm opacity-90 mt-2"><?php echo ucwords(strtolower(numberToWords($payment['amount']))); ?> Shillings Only</p>
                    </div>
                </div>
                
                <!-- Payment Breakdown -->
                <div class="border border-gray-200 rounded-lg p-6 mb-8">
                    <h3 class="font-bold text-lg mb-4">Payment Summary</h3>
                    <table class="w-full">
                        <tr class="border-b">
                            <td class="py-3 text-gray-600">Total Sale Price</td>
                            <td class="py-3 text-right font-semibold"><?php echo formatMoney($payment['sale_price']); ?></td>
                        </tr>
                        <tr class="border-b">
                            <td class="py-3 text-gray-600">Total Amount Paid</td>
                            <td class="py-3 text-right font-semibold text-green-600"><?php echo formatMoney($totalPaid); ?></td>
                        </tr>
                        <tr class="border-b bg-yellow-50">
                            <td class="py-3 font-semibold">Outstanding Balance</td>
                            <td class="py-3 text-right font-bold text-lg <?php echo $payment['sale_balance'] > 0 ? 'text-orange-600' : 'text-green-600'; ?>">
                                <?php echo formatMoney($payment['sale_balance']); ?>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Payment Notes -->
                <?php if ($payment['notes']): ?>
                <div class="mb-8">
                    <p class="text-xs text-gray-500 uppercase tracking-wide mb-2">Notes</p>
                    <p class="text-sm text-gray-700 bg-gray-50 p-4 rounded"><?php echo sanitize($payment['notes']); ?></p>
                </div>
                <?php endif; ?>
                
                <!-- Received By -->
                <div class="mb-8">
                    <p class="text-xs text-gray-500 uppercase tracking-wide mb-2">Received By</p>
                    <p class="font-semibold"><?php echo sanitize($payment['received_by_name']); ?></p>
                    <p class="text-sm text-gray-600">Date: <?php echo formatDate($payment['created_at'], 'F d, Y h:i A'); ?></p>
                </div>
                
                <!-- Signature Section -->
                <div class="grid grid-cols-2 gap-8 mt-12 pt-8 border-t border-gray-200">
                    <div>
                        <div class="border-t-2 border-gray-400 pt-2 mt-16">
                            <p class="text-sm font-semibold">Authorized Signature</p>
                            <p class="text-xs text-gray-500"><?php echo sanitize($settings['site_name']); ?></p>
                        </div>
                    </div>
                    <div>
                        <div class="border-t-2 border-gray-400 pt-2 mt-16">
                            <p class="text-sm font-semibold">Client Signature</p>
                            <p class="text-xs text-gray-500"><?php echo sanitize($payment['client_name']); ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- Footer -->
                <div class="mt-12 pt-8 border-t border-gray-200 text-center">
                    <p class="text-sm text-gray-600 mb-2">Thank you for your payment!</p>
                    <p class="text-xs text-gray-500">This is a computer-generated receipt and is valid without signature.</p>
                    <p class="text-xs text-gray-500 mt-2">For any queries, please contact us at <?php echo sanitize($settings['contact_phone']); ?> or <?php echo sanitize($settings['contact_email']); ?></p>
                    
                    <?php if ($payment['sale_balance'] > 0): ?>
                    <div class="mt-6 p-4 bg-blue-50 rounded-lg">
                        <p class="text-sm font-semibold text-blue-800">
                            <i class="fas fa-info-circle mr-2"></i>Next Payment Due
                        </p>
                        <p class="text-sm text-blue-700 mt-1">
                            Outstanding balance: <?php echo formatMoney($payment['sale_balance']); ?>
                        </p>
                    </div>
                    <?php else: ?>
                    <div class="mt-6 p-4 bg-green-50 rounded-lg">
                        <p class="text-sm font-semibold text-green-800">
                            <i class="fas fa-check-circle mr-2"></i>Payment Complete
                        </p>
                        <p class="text-sm text-green-700 mt-1">
                            All payments have been received. Thank you!
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- QR Code (Optional) -->
                <div class="mt-8 text-center">
                    <div class="inline-block p-4 bg-gray-50 rounded-lg">
                        <div id="qrcode" class="inline-block"></div>
                        <p class="text-xs text-gray-500 mt-2">Scan to verify receipt</p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Payment Processing Modal -->
<div id="paymentModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg max-w-md w-full p-6">
        <div class="text-center">
            <div id="modalContent">
                <!-- Content will be dynamically loaded -->
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<script>
// Generate QR Code for receipt verification
<?php if ($action === 'receipt' && $payment): ?>
document.addEventListener('DOMContentLoaded', function() {
    const qrData = {
        receipt_id: '<?php echo $payment['id']; ?>',
        amount: '<?php echo $payment['amount']; ?>',
        date: '<?php echo $payment['payment_date']; ?>',
        verify_url: '<?php echo APP_URL; ?>/verify-receipt.php?id=<?php echo $payment['id']; ?>'
    };
    
    new QRCode(document.getElementById("qrcode"), {
        text: JSON.stringify(qrData),
        width: 128,
        height: 128
    });
});
<?php endif; ?>

// Print Receipt
function printReceipt(paymentId) {
    window.open('/payments.php?action=receipt&id=' + paymentId, '_blank');
}

// Download Receipt as PDF
function downloadPDF() {
    const element = document.getElementById('receiptContent');
    const opt = {
        margin: 10,
        filename: 'receipt_<?php echo isset($payment) ? str_pad($payment['id'], 6, '0', STR_PAD_LEFT) : 'payment'; ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };
    
    html2pdf().set(opt).from(element).save();
}

// Send Receipt via Email
function sendReceipt() {
    const paymentId = <?php echo isset($payment) ? $payment['id'] : 0; ?>;
    
    if (confirm('Send receipt to client via email?')) {
        fetch('/api/payments/send-receipt.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ payment_id: paymentId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Receipt sent successfully!');
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to send receipt');
        });
    }
}

// Export to Excel
function exportToExcel() {
    const table = document.getElementById('paymentsTable');
    let html = table.outerHTML;
    const url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'payments_' + new Date().toISOString().split('T')[0] + '.xls';
    link.click();
}

// Initiate Online Payment
function initiatePayment(gateway, amount, saleId) {
    const modal = document.getElementById('paymentModal');
    const modalContent = document.getElementById('modalContent');
    
    if (gateway === 'mpesa') {
        modalContent.innerHTML = `
            <i class="fas fa-mobile-alt text-6xl text-green-600 mb-4"></i>
            <h3 class="text-xl font-bold mb-4">M-Pesa Payment</h3>
            <p class="text-gray-600 mb-4">Enter client's M-Pesa phone number</p>
            <input type="tel" id="mpesaPhone" placeholder="07XX XXX XXX" 
                   class="w-full px-4 py-2 border rounded-lg mb-4 focus:ring-2 focus:ring-green-500">
            <button onclick="processMpesa(${amount}, ${saleId})" 
                    class="w-full bg-green-600 text-white py-3 rounded-lg font-semibold hover:bg-green-700">
                Send STK Push
            </button>
            <button onclick="closeModal()" 
                    class="w-full mt-2 bg-gray-200 text-gray-700 py-2 rounded-lg hover:bg-gray-300">
                Cancel
            </button>
        `;
    } else if (gateway === 'card') {
        modalContent.innerHTML = `
            <i class="fas fa-credit-card text-6xl text-blue-600 mb-4"></i>
            <h3 class="text-xl font-bold mb-4">Card Payment</h3>
            <p class="text-gray-600 mb-4">Redirect to secure payment page?</p>
            <button onclick="processCard(${amount}, ${saleId})" 
                    class="w-full bg-blue-600 text-white py-3 rounded-lg font-semibold hover:bg-blue-700">
                Continue to Payment
            </button>
            <button onclick="closeModal()" 
                    class="w-full mt-2 bg-gray-200 text-gray-700 py-2 rounded-lg hover:bg-gray-300">
                Cancel
            </button>
        `;
    } else if (gateway === 'bank') {
        modalContent.innerHTML = `
            <i class="fas fa-university text-6xl text-purple-600 mb-4"></i>
            <h3 class="text-xl font-bold mb-4">Bank Transfer</h3>
            <p class="text-gray-600 mb-4">Send bank details to client?</p>
            <button onclick="processBank(${amount}, ${saleId})" 
                    class="w-full bg-purple-600 text-white py-3 rounded-lg font-semibold hover:bg-purple-700">
                Send Bank Details
            </button>
            <button onclick="closeModal()" 
                    class="w-full mt-2 bg-gray-200 text-gray-700 py-2 rounded-lg hover:bg-gray-300">
                Cancel
            </button>
        `;
    } else if (gateway === 'paypal') {
        modalContent.innerHTML = `
            <i class="fab fa-paypal text-6xl text-blue-600 mb-4"></i>
            <h3 class="text-xl font-bold mb-4">PayPal Payment</h3>
            <p class="text-gray-600 mb-4">Generate PayPal payment link?</p>
            <button onclick="processPayPal(${amount}, ${saleId})" 
                    class="w-full bg-yellow-500 text-white py-3 rounded-lg font-semibold hover:bg-yellow-600">
                Generate Link
            </button>
            <button onclick="closeModal()" 
                    class="w-full mt-2 bg-gray-200 text-gray-700 py-2 rounded-lg hover:bg-gray-300">
                Cancel
            </button>
        `;
    }
    
    modal.classList.remove('hidden');
}

function closeModal() {
    document.getElementById('paymentModal').classList.add('hidden');
}

function processMpesa(amount, saleId) {
    const phone = document.getElementById('mpesaPhone').value;
    
    if (!phone || phone.length < 10) {
        alert('Please enter a valid phone number');
        return;
    }
    
    const modalContent = document.getElementById('modalContent');
    modalContent.innerHTML = `
        <div class="flex flex-col items-center">
            <div class="animate-spin rounded-full h-16 w-16 border-b-2 border-green-600 mb-4"></div>
            <p class="text-gray-600">Sending STK Push to ${phone}...</p>
            <p class="text-sm text-gray-500 mt-2">Please wait for the prompt on the phone</p>
        </div>
    `;
    
    fetch('/api/payments/initiate-mpesa.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            phone: phone,
            amount: amount,
            sale_id: saleId
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            modalContent.innerHTML = `
                <i class="fas fa-check-circle text-6xl text-green-600 mb-4"></i>
                <h3 class="text-xl font-bold mb-2">STK Push Sent!</h3>
                <p class="text-gray-600 mb-4">${data.message}</p>
                <p class="text-sm text-gray-500">Checking payment status...</p>
            `;
            
            // Poll for payment status
            checkPaymentStatus(data.transaction_id);
        } else {
            modalContent.innerHTML = `
                <i class="fas fa-times-circle text-6xl text-red-600 mb-4"></i>
                <h3 class="text-xl font-bold mb-2">Error</h3>
                <p class="text-gray-600 mb-4">${data.message}</p>
                <button onclick="closeModal()" 
                        class="w-full bg-gray-200 text-gray-700 py-2 rounded-lg hover:bg-gray-300">
                    Close
                </button>
            `;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to initiate payment');
        closeModal();
    });
}

function checkPaymentStatus(transactionId) {
    let attempts = 0;
    const maxAttempts = 40; // 2 minutes (40 * 3 seconds)
    
    const interval = setInterval(() => {
        attempts++;
        
        fetch('/api/payments/check-status.php?transaction_id=' + transactionId)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'completed') {
                    clearInterval(interval);
                    const modalContent = document.getElementById('modalContent');
                    modalContent.innerHTML = `
                        <i class="fas fa-check-circle text-6xl text-green-600 mb-4"></i>
                        <h3 class="text-xl font-bold mb-2">Payment Successful!</h3>
                        <p class="text-gray-600 mb-4">The payment has been received</p>
                        <button onclick="window.location.reload()" 
                                class="w-full bg-green-600 text-white py-3 rounded-lg font-semibold hover:bg-green-700">
                            View Receipt
                        </button>
                    `;
                } else if (data.status === 'failed') {
                    clearInterval(interval);
                    const modalContent = document.getElementById('modalContent');
                    modalContent.innerHTML = `
                        <i class="fas fa-times-circle text-6xl text-red-600 mb-4"></i>
                        <h3 class="text-xl font-bold mb-2">Payment Failed</h3>
                        <p class="text-gray-600 mb-4">The payment was not completed</p>
                        <button onclick="closeModal()" 
                                class="w-full bg-gray-200 text-gray-700 py-2 rounded-lg hover:bg-gray-300">
                            Close
                        </button>
                    `;
                } else if (attempts >= maxAttempts) {
                    clearInterval(interval);
                    const modalContent = document.getElementById('modalContent');
                    modalContent.innerHTML = `
                        <i class="fas fa-clock text-6xl text-yellow-600 mb-4"></i>
                        <h3 class="text-xl font-bold mb-2">Timeout</h3>
                        <p class="text-gray-600 mb-4">Payment status check timed out. Please verify manually.</p>
                        <button onclick="closeModal()" 
                                class="w-full bg-gray-200 text-gray-700 py-2 rounded-lg hover:bg-gray-300">
                            Close
                        </button>
                    `;
                }
            });
    }, 3000); // Check every 3 seconds
}

function processCard(amount, saleId) {
    // Redirect to Stripe checkout page
    window.location.href = `/api/payments/card-checkout.php?sale_id=${saleId}&amount=${amount}`;
}

function processBank(amount, saleId) {
    const modalContent = document.getElementById('modalContent');
    modalContent.innerHTML = `
        <div class="flex flex-col items-center">
            <div class="animate-spin rounded-full h-16 w-16 border-b-2 border-purple-600 mb-4"></div>
            <p class="text-gray-600">Sending bank details...</p>
        </div>
    `;
    
    fetch('/api/payments/send-bank-details.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            sale_id: saleId,
            amount: amount
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            modalContent.innerHTML = `
                <i class="fas fa-check-circle text-6xl text-green-600 mb-4"></i>
                <h3 class="text-xl font-bold mb-2">Details Sent!</h3>
                <p class="text-gray-600 mb-4">Bank details sent to client via SMS and Email</p>
                <button onclick="closeModal()" 
                        class="w-full bg-purple-600 text-white py-2 rounded-lg hover:bg-purple-700">
                    Done
                </button>
            `;
        } else {
            modalContent.innerHTML = `
                <i class="fas fa-times-circle text-6xl text-red-600 mb-4"></i>
                <h3 class="text-xl font-bold mb-2">Error</h3>
                <p class="text-gray-600 mb-4">${data.message}</p>
                <button onclick="closeModal()" 
                        class="w-full bg-gray-200 text-gray-700 py-2 rounded-lg hover:bg-gray-300">
                    Close
                </button>
            `;
        }
    });
}

function processPayPal(amount, saleId) {
    // Generate PayPal payment link
    window.location.href = `/api/payments/paypal-checkout.php?sale_id=${saleId}&amount=${amount}`;
}
</script>

<?php 
// Helper function to convert numbers to words
function numberToWords($number) {
    $number = (int)$number;
    $words = array(
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 =>'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen',
        15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen',
        20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty', 50 => 'Fifty',
        60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety'
    );
    
    if ($number < 20) {
        return $words[$number];
    } elseif ($number < 100) {
        $tens = ((int)($number / 10)) * 10;
        $units = $number % 10;
        return $words[$tens] . ($units ? ' ' . $words[$units] : '');
    } elseif ($number < 1000) {
        $hundreds = (int)($number / 100);
        $remainder = $number % 100;
        return $words[$hundreds] . ' Hundred' . ($remainder ? ' and ' . numberToWords($remainder) : '');
    } elseif ($number < 1000000) {
        $thousands = (int)($number / 1000);
        $remainder = $number % 1000;
        return numberToWords($thousands) . ' Thousand' . ($remainder ? ' ' . numberToWords($remainder) : '');
    } elseif ($number < 1000000000) {
        $millions = (int)($number / 1000000);
        $remainder = $number % 1000000;
        return numberToWords($millions) . ' Million' . ($remainder ? ' ' . numberToWords($remainder) : '');
    }
    
    return 'Number too large';
}

include 'includes/footer.php';
?>