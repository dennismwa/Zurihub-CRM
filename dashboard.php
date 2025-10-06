<?php
// dashboard.php - Complete Working Dashboard with All Features
$pageTitle = 'Dashboard';
require_once 'config.php';
requireLogin();

$settings = getSettings();
$userId = getUserId();
$userRole = getUserRole();
$userName = getUserName();

// Initialize AI services safely
$aiService = null;
$aiInsights = [];
$revenuePredictions = [];
$leadPredictions = [];

if (file_exists('app/services/AIPredictionService.php')) {
    require_once 'app/services/AIPredictionService.php';
    try {
        $aiService = new AIPredictionService($pdo);
        $aiInsights = $aiService->getInsightsSummary();
        $revenuePredictions = $aiService->predictRevenue(3);
        if (is_array($revenuePredictions) && !isset($revenuePredictions['error'])) {
            // Revenue predictions are valid
        } else {
            $revenuePredictions = [];
        }
    } catch (Exception $e) {
        error_log("AI Service Error: " . $e->getMessage());
        $aiInsights = [];
        $revenuePredictions = [];
    }
}

// Get current period dates
$currentMonth = date('Y-m');
$currentYear = date('Y');
$today = date('Y-m-d');
$firstDayOfMonth = date('Y-m-01');
$lastDayOfMonth = date('Y-m-t');

// Initialize all statistics
$stats = [
    // Projects
    'total_projects' => 0,
    'active_projects' => 0,
    
    // Plots
    'total_plots' => 0,
    'available_plots' => 0,
    'booked_plots' => 0,
    'sold_plots' => 0,
    'plots_value' => 0,
    
    // Sales
    'total_sales' => 0,
    'monthly_sales' => 0,
    'today_sales' => 0,
    'total_revenue' => 0,
    'monthly_revenue' => 0,
    'outstanding_balance' => 0,
    
    // Payments
    'total_payments' => 0,
    'monthly_payments' => 0,
    'today_payments' => 0,
    
    // Leads
    'total_leads' => 0,
    'new_leads' => 0,
    'qualified_leads' => 0,
    'converted_leads' => 0,
    'conversion_rate' => 0,
    
    // Clients
    'total_clients' => 0,
    'new_clients_month' => 0,
    
    // Staff
    'total_staff' => 0,
    'active_staff' => 0,
    'clocked_in_today' => 0
];

// Fetch Projects Statistics
if (hasPermission('projects', 'view')) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM projects");
        $stats['total_projects'] = $stmt->fetch()['count'];
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM projects WHERE status = 'active'");
        $stats['active_projects'] = $stmt->fetch()['count'];
    } catch (Exception $e) {
        error_log("Projects stats error: " . $e->getMessage());
    }
}

// Fetch Plots Statistics
if (hasPermission('plots', 'view')) {
    try {
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_plots,
                SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_plots,
                SUM(CASE WHEN status = 'booked' THEN 1 ELSE 0 END) as booked_plots,
                SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold_plots,
                COALESCE(SUM(CASE WHEN status = 'available' THEN price ELSE 0 END), 0) as plots_value
            FROM plots
        ");
        $plotStats = $stmt->fetch();
        $stats = array_merge($stats, $plotStats);
    } catch (Exception $e) {
        error_log("Plots stats error: " . $e->getMessage());
    }
}

// Fetch Sales Statistics
if (hasPermission('sales', 'view')) {
    try {
        // Total sales
        $query = "SELECT 
            COUNT(*) as total_sales,
            COALESCE(SUM(sale_price), 0) as total_revenue,
            COALESCE(SUM(balance), 0) as outstanding_balance
            FROM sales WHERE status != 'cancelled'";
        
        if ($userRole === 'sales_agent') {
            $query .= " AND agent_id = ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$userId]);
        } else {
            $stmt = $pdo->query($query);
        }
        $salesStats = $stmt->fetch();
        $stats = array_merge($stats, $salesStats);
        
        // Monthly sales
        $query = "SELECT 
            COUNT(*) as monthly_sales,
            COALESCE(SUM(sale_price), 0) as monthly_revenue
            FROM sales 
            WHERE MONTH(sale_date) = MONTH(CURRENT_DATE()) 
            AND YEAR(sale_date) = YEAR(CURRENT_DATE())
            AND status != 'cancelled'";
        
        if ($userRole === 'sales_agent') {
            $query .= " AND agent_id = ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$userId]);
        } else {
            $stmt = $pdo->query($query);
        }
        $monthlySales = $stmt->fetch();
        $stats['monthly_sales'] = $monthlySales['monthly_sales'];
        $stats['monthly_revenue'] = $monthlySales['monthly_revenue'];
        
        // Today's sales
        $query = "SELECT COUNT(*) as today_sales FROM sales WHERE DATE(sale_date) = CURDATE() AND status != 'cancelled'";
        if ($userRole === 'sales_agent') {
            $query .= " AND agent_id = ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$userId]);
        } else {
            $stmt = $pdo->query($query);
        }
        $stats['today_sales'] = $stmt->fetch()['today_sales'];
        
    } catch (Exception $e) {
        error_log("Sales stats error: " . $e->getMessage());
    }
}

// Fetch Payment Statistics
if (hasPermission('payments', 'view')) {
    try {
        // Total payments
        $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total_payments FROM payments");
        $stats['total_payments'] = $stmt->fetch()['total_payments'];
        
        // Monthly payments
        $stmt = $pdo->query("
            SELECT COALESCE(SUM(amount), 0) as monthly_payments 
            FROM payments 
            WHERE MONTH(payment_date) = MONTH(CURRENT_DATE()) 
            AND YEAR(payment_date) = YEAR(CURRENT_DATE())
        ");
        $stats['monthly_payments'] = $stmt->fetch()['monthly_payments'];
        
        // Today's payments
        $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as today_payments FROM payments WHERE DATE(payment_date) = CURDATE()");
        $stats['today_payments'] = $stmt->fetch()['today_payments'];
        
    } catch (Exception $e) {
        error_log("Payments stats error: " . $e->getMessage());
    }
}

// Fetch Lead Statistics
if (hasPermission('leads', 'view')) {
    try {
        $query = "SELECT 
            COUNT(*) as total_leads,
            SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_leads,
            SUM(CASE WHEN status = 'qualified' THEN 1 ELSE 0 END) as qualified_leads,
            SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) as converted_leads
            FROM leads";
        
        if ($userRole === 'sales_agent') {
            $query .= " WHERE assigned_to = ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$userId]);
        } else {
            $stmt = $pdo->query($query);
        }
        
        $leadStats = $stmt->fetch();
        $stats['total_leads'] = $leadStats['total_leads'];
        $stats['new_leads'] = $leadStats['new_leads'];
        $stats['qualified_leads'] = $leadStats['qualified_leads'];
        $stats['converted_leads'] = $leadStats['converted_leads'];
        $stats['conversion_rate'] = $stats['total_leads'] > 0 ? 
            round(($stats['converted_leads'] / $stats['total_leads']) * 100, 1) : 0;
            
    } catch (Exception $e) {
        error_log("Leads stats error: " . $e->getMessage());
    }
}

// Fetch Client Statistics
if (hasPermission('clients', 'view')) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total_clients FROM clients");
        $stats['total_clients'] = $stmt->fetch()['total_clients'];
        
        $stmt = $pdo->query("
            SELECT COUNT(*) as new_clients_month 
            FROM clients 
            WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) 
            AND YEAR(created_at) = YEAR(CURRENT_DATE())
        ");
        $stats['new_clients_month'] = $stmt->fetch()['new_clients_month'];
        
    } catch (Exception $e) {
        error_log("Clients stats error: " . $e->getMessage());
    }
}

// Fetch Staff Statistics
if (hasPermission('users', 'view')) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total_staff FROM users");
        $stats['total_staff'] = $stmt->fetch()['total_staff'];
        
        $stmt = $pdo->query("SELECT COUNT(*) as active_staff FROM users WHERE status = 'active'");
        $stats['active_staff'] = $stmt->fetch()['active_staff'];
        
        $stmt = $pdo->query("
            SELECT COUNT(DISTINCT user_id) as clocked_in_today 
            FROM attendance 
            WHERE DATE(clock_in) = CURDATE() 
            AND clock_out IS NULL
        ");
        $stats['clocked_in_today'] = $stmt->fetch()['clocked_in_today'];
        
    } catch (Exception $e) {
        error_log("Staff stats error: " . $e->getMessage());
    }
}

// Get Recent Sales
$recentSales = [];
if (hasPermission('sales', 'view')) {
    try {
        $query = "SELECT s.*, c.full_name as client_name, p.plot_number, pr.project_name, u.full_name as agent_name
                  FROM sales s
                  JOIN clients c ON s.client_id = c.id
                  JOIN plots p ON s.plot_id = p.id
                  JOIN projects pr ON p.project_id = pr.id
                  JOIN users u ON s.agent_id = u.id
                  WHERE s.status != 'cancelled'";
        
        if ($userRole === 'sales_agent') {
            $query .= " AND s.agent_id = ?";
            $query .= " ORDER BY s.created_at DESC LIMIT 5";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$userId]);
        } else {
            $query .= " ORDER BY s.created_at DESC LIMIT 5";
            $stmt = $pdo->query($query);
        }
        $recentSales = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Recent sales error: " . $e->getMessage());
    }
}

// Get Top Agents (Admin/Manager only)
$topAgents = [];
if (in_array($userRole, ['admin', 'manager']) && hasPermission('sales', 'view')) {
    try {
        $stmt = $pdo->query("
            SELECT 
                u.id,
                u.full_name,
                COUNT(s.id) as sales_count,
                COALESCE(SUM(s.sale_price), 0) as total_revenue,
                COALESCE(AVG(s.sale_price), 0) as avg_sale_value
            FROM users u
            LEFT JOIN sales s ON u.id = s.agent_id 
                AND MONTH(s.sale_date) = MONTH(CURRENT_DATE())
                AND YEAR(s.sale_date) = YEAR(CURRENT_DATE())
                AND s.status != 'cancelled'
            WHERE u.role = 'sales_agent' AND u.status = 'active'
            GROUP BY u.id, u.full_name
            ORDER BY total_revenue DESC
            LIMIT 5
        ");
        $topAgents = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Top agents error: " . $e->getMessage());
    }
}

// Get Sales Trend (Last 7 Days)
$salesTrend = [];
if (hasPermission('sales', 'view')) {
    try {
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $dayName = date('D', strtotime($date));
            
            $query = "SELECT 
                COUNT(*) as count,
                COALESCE(SUM(sale_price), 0) as revenue
                FROM sales 
                WHERE DATE(sale_date) = ? AND status != 'cancelled'";
            
            if ($userRole === 'sales_agent') {
                $query .= " AND agent_id = ?";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$date, $userId]);
            } else {
                $stmt = $pdo->prepare($query);
                $stmt->execute([$date]);
            }
            
            $data = $stmt->fetch();
            $salesTrend[] = [
                'day' => $dayName,
                'date' => $date,
                'count' => $data['count'],
                'revenue' => $data['revenue']
            ];
        }
    } catch (Exception $e) {
        error_log("Sales trend error: " . $e->getMessage());
    }
}

// Get Upcoming Site Visits
$upcomingSiteVisits = [];
if (hasPermission('site_visits', 'view')) {
    try {
        $stmt = $pdo->query("
            SELECT sv.*, pr.project_name, pr.location
            FROM site_visits sv
            JOIN projects pr ON sv.project_id = pr.id
            WHERE sv.visit_date >= NOW() 
            AND sv.status = 'scheduled'
            ORDER BY sv.visit_date ASC
            LIMIT 5
        ");
        $upcomingSiteVisits = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Site visits error: " . $e->getMessage());
    }
}

// Get Hot Leads (if lead scoring is available)
$hotLeads = [];
if (hasPermission('leads', 'view')) {
    try {
        // Check if lead_scores table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'lead_scores'");
        if ($stmt->fetch()) {
            $query = "
                SELECT l.*, ls.score, ls.grade, u.full_name as agent_name
                FROM leads l 
                LEFT JOIN lead_scores ls ON l.id = ls.lead_id 
                LEFT JOIN users u ON l.assigned_to = u.id
                WHERE l.status NOT IN ('converted', 'lost')";
            
            if ($userRole === 'sales_agent') {
                $query .= " AND l.assigned_to = ?";
                $query .= " ORDER BY ls.score DESC LIMIT 5";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$userId]);
            } else {
                $query .= " ORDER BY ls.score DESC LIMIT 5";
                $stmt = $pdo->query($query);
            }
            $hotLeads = $stmt->fetchAll();
        }
    } catch (Exception $e) {
        error_log("Hot leads error: " . $e->getMessage());
    }
}

// Get Recent Activities
$recentActivities = [];
try {
    $query = "SELECT * FROM activity_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 10";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$userId]);
    $recentActivities = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Activities error: " . $e->getMessage());
}

// Get Tasks
$pendingTasks = [];
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'tasks'");
    if ($stmt->fetch()) {
        $query = "SELECT * FROM tasks WHERE assigned_to = ? AND status = 'pending' ORDER BY due_date ASC LIMIT 5";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId]);
        $pendingTasks = $stmt->fetchAll();
    }
} catch (Exception $e) {
    error_log("Tasks error: " . $e->getMessage());
}

include 'includes/header.php';
?>

<div class="p-4 md:p-6 pb-20 md:pb-6">
    <!-- Welcome Section -->
    <div class="mb-6">
        <?php
        $hour = date('G');
        $greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
        ?>
        <h1 class="text-3xl md:text-4xl font-bold text-gray-800">
            <?php echo $greeting; ?>, <?php echo sanitize(explode(' ', $userName)[0]); ?>
        </h1>
        <p class="text-gray-600 mt-1">
            <?php echo date('l, F j, Y'); ?> • Here's your business overview
        </p>
    </div>
    
    <!-- AI Insights Alert (if available) 
    <?php if (!empty($aiInsights)): ?>
    <div class="bg-gradient-to-r from-purple-600 to-indigo-600 rounded-xl shadow-lg p-6 mb-6 text-white">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-bold flex items-center">
                <i class="fas fa-brain mr-2"></i> AI Insights
            </h2>
            <span class="text-xs bg-white/20 px-3 py-1 rounded-full">Powered by AI</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <?php foreach (array_slice($aiInsights, 0, 3) as $insight): ?>
            <div class="bg-white/10 backdrop-blur rounded-lg p-4">
                <div class="flex items-start">
                    <i class="fas <?php echo $insight['icon']; ?> text-2xl mr-3 text-<?php echo $insight['color']; ?>-300"></i>
                    <div>
                        <h4 class="font-semibold mb-1"><?php echo $insight['title']; ?></h4>
                        <p class="text-sm text-white/90"><?php echo $insight['message']; ?></p>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>-->
    
    <!-- Primary Stats Cards -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        <!-- Today's Sales -->
        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow-lg p-4 text-white transform hover:scale-105 transition">
            <div class="flex items-center justify-between mb-1">
                <div class="bg-white/30 p-2 rounded-lg">
                    <i class="fas fa-calendar-day text-xl"></i>
                </div>
                <span class="text-xs bg-white/20 px-2 py-0.5 rounded-full">Today</span>
            </div>
            <p class="text-2xl font-bold mb-0.5"><?php echo number_format($stats['today_sales']); ?></p>
            <p class="text-xs opacity-90">Sales Today</p>
        </div>
        
        <!-- Monthly Revenue -->
        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg p-4 text-white transform hover:scale-105 transition">
            <div class="flex items-center justify-between mb-1">
                <div class="bg-white/30 p-2 rounded-lg">
                    <i class="fas fa-chart-line text-xl"></i>
                </div>
                <span class="text-xs bg-white/20 px-2 py-0.5 rounded-full">Month</span>
            </div>
            <p class="text-lg font-bold mb-0.5"><?php echo formatMoney($stats['monthly_revenue']); ?></p>
            <p class="text-xs opacity-90"><?php echo $stats['monthly_sales']; ?> Sales</p>
        </div>
        
        <!-- Available Plots -->
        <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl shadow-lg p-4 text-white transform hover:scale-105 transition">
            <div class="flex items-center justify-between mb-1">
                <div class="bg-white/30 p-2 rounded-lg">
                    <i class="fas fa-map text-xl"></i>
                </div>
                <span class="text-xs bg-white/20 px-2 py-0.5 rounded-full">Plots</span>
            </div>
            <p class="text-2xl font-bold mb-0.5"><?php echo number_format($stats['available_plots']); ?></p>
            <p class="text-xs opacity-90">Available</p>
        </div>
        
        <!-- New Leads -->
        <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-xl shadow-lg p-4 text-white transform hover:scale-105 transition">
            <div class="flex items-center justify-between mb-1">
                <div class="bg-white/30 p-2 rounded-lg">
                    <i class="fas fa-user-plus text-xl"></i>
                </div>
                <span class="text-xs bg-white/20 px-2 py-0.5 rounded-full">New</span>
            </div>
            <p class="text-2xl font-bold mb-0.5"><?php echo number_format($stats['new_leads']); ?></p>
            <p class="text-xs opacity-90">New Leads</p>
        </div>
        
        <!-- Total Clients -->
        <div class="bg-gradient-to-br from-teal-500 to-teal-600 rounded-xl shadow-lg p-4 text-white transform hover:scale-105 transition">
            <div class="flex items-center justify-between mb-1">
                <div class="bg-white/30 p-2 rounded-lg">
                    <i class="fas fa-users text-xl"></i>
                </div>
                <span class="text-xs bg-white/20 px-2 py-0.5 rounded-full">Total</span>
            </div>
            <p class="text-2xl font-bold mb-0.5"><?php echo number_format($stats['total_clients']); ?></p>
            <p class="text-xs opacity-90">Clients</p>
        </div>
        
        <!-- Conversion Rate -->
        <div class="bg-gradient-to-br from-pink-500 to-pink-600 rounded-xl shadow-lg p-4 text-white transform hover:scale-105 transition">
            <div class="flex items-center justify-between mb-1">
                <div class="bg-white/30 p-2 rounded-lg">
                    <i class="fas fa-percentage text-xl"></i>
                </div>
                <span class="text-xs bg-white/20 px-2 py-0.5 rounded-full">Rate</span>
            </div>
            <p class="text-2xl font-bold mb-0.5"><?php echo $stats['conversion_rate']; ?>%</p>
            <p class="text-xs opacity-90">Conversion</p>
        </div>
    </div>
    
    <!-- Financial Overview Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-lg p-4 border-l-4 border-green-500">
            <div class="flex items-center mb-2">
                <div class="bg-green-100 p-2 rounded-lg mr-3">
                    <i class="fas fa-dollar-sign text-green-600 text-lg"></i>
                </div>
                <div class="flex-1">
                    <p class="text-gray-600 text-xs">Total Revenue</p>
                    <p class="text-lg font-bold text-gray-800"><?php echo formatMoney($stats['total_revenue']); ?></p>
                </div>
            </div>
            <div class="text-xs text-green-600">
                <i class="fas fa-arrow-up mr-1"></i>
                <?php echo number_format($stats['total_sales']); ?> total sales
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-lg p-4 border-l-4 border-blue-500">
            <div class="flex items-center mb-2">
                <div class="bg-blue-100 p-2 rounded-lg mr-3">
                    <i class="fas fa-money-bill-wave text-blue-600 text-lg"></i>
                </div>
                <div class="flex-1">
                    <p class="text-gray-600 text-xs">Monthly Payments</p>
                    <p class="text-lg font-bold text-gray-800"><?php echo formatMoney($stats['monthly_payments']); ?></p>
                </div>
            </div>
            <div class="text-xs text-blue-600">
                <i class="fas fa-check-circle mr-1"></i>
                Collected this month
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-lg p-4 border-l-4 border-orange-500">
            <div class="flex items-center mb-2">
                <div class="bg-orange-100 p-2 rounded-lg mr-3">
                    <i class="fas fa-exclamation-triangle text-orange-600 text-lg"></i>
                </div>
                <div class="flex-1">
                    <p class="text-gray-600 text-xs">Outstanding</p>
                    <p class="text-lg font-bold text-gray-800"><?php echo formatMoney($stats['outstanding_balance']); ?></p>
                </div>
            </div>
            <div class="text-xs text-orange-600">
                Pending collection
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-lg p-4 border-l-4 border-purple-500">
            <div class="flex items-center mb-2">
                <div class="bg-purple-100 p-2 rounded-lg mr-3">
                    <i class="fas fa-building text-purple-600 text-lg"></i>
                </div>
                <div class="flex-1">
                    <p class="text-gray-600 text-xs">Active Projects</p>
                    <p class="text-lg font-bold text-gray-800"><?php echo number_format($stats['active_projects']); ?></p>
                </div>
            </div>
            <div class="text-xs text-purple-600">
                <?php echo number_format($stats['total_plots']); ?> total plots
            </div>
        </div>
    </div>
    
    <!-- Charts Section -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <!-- Sales Trend Chart -->
        <div class="lg:col-span-2 bg-white rounded-xl shadow-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-gray-800">7-Day Sales Trend</h2>
                <div class="flex items-center space-x-4">
                    <div class="flex items-center">
                        <div class="w-3 h-3 bg-blue-500 rounded-full mr-2"></div>
                        <span class="text-sm text-gray-600">Count</span>
                    </div>
                    <div class="flex items-center">
                        <div class="w-3 h-3 bg-green-500 rounded-full mr-2"></div>
                        <span class="text-sm text-gray-600">Revenue</span>
                    </div>
                </div>
            </div>
            <canvas id="salesTrendChart" class="w-full" style="max-height: 300px;"></canvas>
        </div>
        
        <!-- Plot Distribution -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Plot Status</h2>
            <canvas id="plotChart" style="max-height: 200px;"></canvas>
            <div class="mt-4 space-y-2">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-3 h-3 bg-green-500 rounded-full mr-2"></div>
                        <span class="text-sm text-gray-600">Available</span>
                    </div>
                    <span class="text-sm font-semibold"><?php echo number_format($stats['available_plots']); ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-3 h-3 bg-yellow-500 rounded-full mr-2"></div>
                        <span class="text-sm text-gray-600">Booked</span>
                    </div>
                    <span class="text-sm font-semibold"><?php echo number_format($stats['booked_plots']); ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-3 h-3 bg-red-500 rounded-full mr-2"></div>
                        <span class="text-sm text-gray-600">Sold</span>
                    </div>
                    <span class="text-sm font-semibold"><?php echo number_format($stats['sold_plots']); ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Activity Sections -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Recent Sales -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-gray-800">Recent Sales</h2>
                <?php if (hasPermission('sales', 'view')): ?>
                <a href="/sales.php" class="text-sm text-primary hover:underline">View All →</a>
                <?php endif; ?>
            </div>
            <div class="space-y-3 overflow-y-auto" style="max-height: 350px;">
                <?php if (!empty($recentSales)): ?>
                    <?php foreach ($recentSales as $sale): ?>
                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                        <div class="flex items-center flex-1 min-w-0">
                            <div class="w-10 h-10 rounded-full bg-primary text-white flex items-center justify-center font-bold mr-3 flex-shrink-0">
                                <?php echo strtoupper(substr($sale['client_name'], 0, 1)); ?>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-sm truncate"><?php echo sanitize($sale['client_name']); ?></p>
                                <p class="text-xs text-gray-600 truncate">
                                    <?php echo sanitize($sale['project_name'] . ' - Plot ' . $sale['plot_number']); ?>
                                </p>
                                <p class="text-xs text-gray-500"><?php echo formatDate($sale['sale_date'], 'M d'); ?></p>
                            </div>
                        </div>
                        <div class="text-right ml-4">
                            <p class="font-bold text-sm text-primary whitespace-nowrap"><?php echo formatMoney($sale['sale_price']); ?></p>
                            <span class="text-xs px-2 py-1 rounded-full <?php echo $sale['status'] === 'completed' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'; ?>">
                                <?php echo ucfirst($sale['status']); ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-center text-gray-500 py-8">No recent sales</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Top Performers / Hot Leads -->
        <?php if (!empty($topAgents)): ?>
        <div class="bg-white rounded-xl shadow-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-gray-800">Top Performers</h2>
                <span class="text-sm text-gray-600"><?php echo date('F Y'); ?></span>
            </div>
            <div class="space-y-3">
                <?php foreach ($topAgents as $index => $agent): ?>
                <div class="flex items-center justify-between p-4 bg-gradient-to-r from-gray-50 to-white rounded-lg border border-gray-200">
                    <div class="flex items-center">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-white mr-3 
                            <?php echo $index === 0 ? 'bg-yellow-500' : ($index === 1 ? 'bg-gray-400' : ($index === 2 ? 'bg-orange-600' : 'bg-gray-300')); ?>">
                            <?php echo $index + 1; ?>
                        </div>
                        <div>
                            <p class="font-semibold text-sm"><?php echo sanitize($agent['full_name']); ?></p>
                            <p class="text-xs text-gray-600"><?php echo number_format($agent['sales_count']); ?> sales</p>
                        </div>
                    </div>
                    <p class="font-bold text-primary"><?php echo formatMoney($agent['total_revenue']); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php elseif (!empty($hotLeads)): ?>
        <div class="bg-white rounded-xl shadow-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-gray-800">Hot Leads 🔥</h2>
                <?php if (hasPermission('leads', 'view')): ?>
                <a href="/leads.php" class="text-sm text-primary hover:underline">View All →</a>
                <?php endif; ?>
            </div>
            <div class="space-y-3">
                <?php foreach ($hotLeads as $lead): ?>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                    <div class="flex items-center flex-1">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-r from-orange-400 to-red-500 text-white flex items-center justify-center font-bold mr-3">
                            <?php echo $lead['grade'] ?? 'N'; ?>
                        </div>
                        <div>
                            <p class="font-semibold text-sm"><?php echo sanitize($lead['full_name']); ?></p>
                            <p class="text-xs text-gray-600">Score: <?php echo $lead['score'] ?? '0'; ?>/100 • <?php echo ucfirst($lead['status']); ?></p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Upcoming Events & Quick Actions -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Upcoming Site Visits -->
        <?php if (!empty($upcomingSiteVisits)): ?>
        <div class="lg:col-span-2 bg-white rounded-xl shadow-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-gray-800">Upcoming Site Visits</h2>
                <?php if (hasPermission('site_visits', 'view')): ?>
                <a href="/site-visits.php" class="text-sm text-primary hover:underline">View All →</a>
                <?php endif; ?>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach (array_slice($upcomingSiteVisits, 0, 4) as $visit): ?>
                <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition">
                    <h3 class="font-semibold text-sm mb-2"><?php echo sanitize($visit['title']); ?></h3>
                    <p class="text-xs text-gray-600 mb-1">
                        <i class="fas fa-building mr-1"></i> <?php echo sanitize($visit['project_name']); ?>
                    </p>
                    <p class="text-xs text-gray-600">
                        <i class="fas fa-calendar mr-1"></i> <?php echo formatDate($visit['visit_date'], 'M d, h:i A'); ?>
                    </p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Quick Actions -->
        <div class="<?php echo empty($upcomingSiteVisits) ? 'lg:col-span-3' : ''; ?> bg-white rounded-xl shadow-lg p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Quick Actions</h2>
            <div class="grid grid-cols-2 <?php echo empty($upcomingSiteVisits) ? 'md:grid-cols-3 lg:grid-cols-6' : ''; ?> gap-3">
                <?php if (hasPermission('leads', 'create')): ?>
                <a href="/leads.php?action=create" class="flex flex-col items-center justify-center p-4 rounded-xl border-2 border-gray-200 hover:border-primary hover:bg-primary hover:bg-opacity-5 transition group">
                    <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center mb-2 group-hover:bg-primary group-hover:bg-opacity-20 transition">
                        <i class="fas fa-user-plus text-xl text-blue-600 group-hover:text-primary"></i>
                    </div>
                    <span class="text-xs font-semibold text-center">Add Lead</span>
                </a>
                <?php endif; ?>
                
                <?php if (hasPermission('sales', 'create')): ?>
                <a href="/sales.php?action=create" class="flex flex-col items-center justify-center p-4 rounded-xl border-2 border-gray-200 hover:border-green-500 hover:bg-green-50 transition group">
                    <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center mb-2 group-hover:bg-green-200 transition">
                        <i class="fas fa-handshake text-xl text-green-600"></i>
                    </div>
                    <span class="text-xs font-semibold text-center">New Sale</span>
                </a>
                <?php endif; ?>
                
                <?php if (hasPermission('payments', 'create')): ?>
                <a href="/payments.php" class="flex flex-col items-center justify-center p-4 rounded-xl border-2 border-gray-200 hover:border-yellow-500 hover:bg-yellow-50 transition group">
                    <div class="w-12 h-12 rounded-full bg-yellow-100 flex items-center justify-center mb-2 group-hover:bg-yellow-200 transition">
                        <i class="fas fa-money-bill-wave text-xl text-yellow-600"></i>
                    </div>
                    <span class="text-xs font-semibold text-center">Payment</span>
                </a>
                <?php endif; ?>
                
                <?php if (hasPermission('clients', 'create')): ?>
                <a href="/clients.php?action=create" class="flex flex-col items-center justify-center p-4 rounded-xl border-2 border-gray-200 hover:border-orange-500 hover:bg-orange-50 transition group">
                    <div class="w-12 h-12 rounded-full bg-orange-100 flex items-center justify-center mb-2 group-hover:bg-orange-200 transition">
                        <i class="fas fa-users text-xl text-orange-600"></i>
                    </div>
                    <span class="text-xs font-semibold text-center">Add Client</span>
                </a>
                <?php endif; ?>
                
                <?php if (hasPermission('site_visits', 'create')): ?>
                <a href="/site-visits.php?action=create" class="flex flex-col items-center justify-center p-4 rounded-xl border-2 border-gray-200 hover:border-purple-500 hover:bg-purple-50 transition group">
                    <div class="w-12 h-12 rounded-full bg-purple-100 flex items-center justify-center mb-2 group-hover:bg-purple-200 transition">
                        <i class="fas fa-calendar-check text-xl text-purple-600"></i>
                    </div>
                    <span class="text-xs font-semibold text-center">Site Visit</span>
                </a>
                <?php endif; ?>
                
                <?php if (hasPermission('reports', 'view')): ?>
                <a href="/reports.php" class="flex flex-col items-center justify-center p-4 rounded-xl border-2 border-gray-200 hover:border-indigo-500 hover:bg-indigo-50 transition group">
                    <div class="w-12 h-12 rounded-full bg-indigo-100 flex items-center justify-center mb-2 group-hover:bg-indigo-200 transition">
                        <i class="fas fa-chart-bar text-xl text-indigo-600"></i>
                    </div>
                    <span class="text-xs font-semibold text-center">Reports</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Sales Trend Chart
<?php if (!empty($salesTrend)): ?>
const trendCtx = document.getElementById('salesTrendChart');
if (trendCtx) {
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_column($salesTrend, 'day')); ?>,
            datasets: [
                {
                    label: 'Sales Count',
                    data: <?php echo json_encode(array_column($salesTrend, 'count')); ?>,
                    borderColor: 'rgb(59, 130, 246)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    yAxisID: 'y',
                    tension: 0.4
                },
                {
                    label: 'Revenue',
                    data: <?php echo json_encode(array_column($salesTrend, 'revenue')); ?>,
                    borderColor: 'rgb(34, 197, 94)',
                    backgroundColor: 'rgba(34, 197, 94, 0.1)',
                    yAxisID: 'y1',
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    beginAtZero: true,
                    grid: {
                        drawOnChartArea: false,
                    },
                    ticks: {
                        callback: function(value) {
                            return 'KES ' + (value / 1000) + 'k';
                        }
                    }
                }
            }
        }
    });
}
<?php endif; ?>

// Plot Distribution Chart
<?php if ($stats['total_plots'] > 0): ?>
const plotCtx = document.getElementById('plotChart');
if (plotCtx) {
    new Chart(plotCtx, {
        type: 'doughnut',
        data: {
            labels: ['Available', 'Booked', 'Sold'],
            datasets: [{
                data: [
                    <?php echo $stats['available_plots']; ?>,
                    <?php echo $stats['booked_plots']; ?>,
                    <?php echo $stats['sold_plots']; ?>
                ],
                backgroundColor: [
                    'rgb(34, 197, 94)',
                    'rgb(250, 204, 21)',
                    'rgb(239, 68, 68)'
                ],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            cutout: '70%'
        }
    });
}
<?php endif; ?>

// Auto-refresh every 5 minutes
setTimeout(() => {
    location.reload();
}, 300000);
</script>

<?php include 'includes/footer.php'; ?>