<?php
// analytics-dashboard.php - Complete Working Analytics Dashboard
$pageTitle = 'Analytics Dashboard';
require_once 'config.php';
requirePermission('reports', 'view');

// Get date range
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$compareMode = $_GET['compare'] ?? 'previous_period';

// Initialize AI Service if available
$aiService = null;
if (file_exists('app/services/AIPredictionService.php')) {
    require_once 'app/services/AIPredictionService.php';
    try {
        $aiService = new AIPredictionService($pdo);
    } catch (Exception $e) {
        error_log("AI Service initialization error: " . $e->getMessage());
    }
}

// Analytics Engine Class
class AnalyticsEngine {
    private $pdo;
    private $startDate;
    private $endDate;
    
    public function __construct($pdo, $startDate, $endDate) {
        $this->pdo = $pdo;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }
    
    // Sales Forecasting
    public function getSalesForcast($months = 3) {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    DATE_FORMAT(sale_date, '%Y-%m') as month,
                    COUNT(*) as sales_count,
                    COALESCE(SUM(sale_price), 0) as revenue
                FROM sales
                WHERE sale_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                AND status != 'cancelled'
                GROUP BY DATE_FORMAT(sale_date, '%Y-%m')
                ORDER BY month
            ");
            
            $historicalData = $stmt->fetchAll();
            
            if (count($historicalData) < 3) {
                return ['forecast' => [], 'accuracy' => 0, 'trend' => 'insufficient_data', 'historical' => []];
            }
            
            // Simple linear regression
            $n = count($historicalData);
            $x = range(1, $n);
            $y = array_column($historicalData, 'revenue');
            
            if (empty($y) || array_sum($y) == 0) {
                return ['forecast' => [], 'accuracy' => 0, 'trend' => 'no_data', 'historical' => []];
            }
            
            $sumX = array_sum($x);
            $sumY = array_sum($y);
            $sumXY = 0;
            $sumX2 = 0;
            
            for ($i = 0; $i < $n; $i++) {
                $sumXY += $x[$i] * ($y[$i] ?? 0);
                $sumX2 += $x[$i] * $x[$i];
            }
            
            $denominator = ($n * $sumX2 - $sumX * $sumX);
            if ($denominator == 0) {
                return ['forecast' => [], 'accuracy' => 0, 'trend' => 'no_variance', 'historical' => []];
            }
            
            $slope = ($n * $sumXY - $sumX * $sumY) / $denominator;
            $intercept = ($sumY - $slope * $sumX) / $n;
            
            // Generate forecast
            $forecast = [];
            for ($i = 1; $i <= $months; $i++) {
                $forecastValue = $slope * ($n + $i) + $intercept;
                $forecastMonth = date('Y-m', strtotime("+$i month"));
                $forecast[] = [
                    'month' => $forecastMonth,
                    'predicted_revenue' => max(0, $forecastValue),
                    'confidence' => max(0.5, 0.75 - (0.05 * $i))
                ];
            }
            
            return [
                'forecast' => $forecast, 
                'trend' => $slope > 0 ? 'up' : 'down',
                'historical' => $historicalData
            ];
            
        } catch (Exception $e) {
            error_log("Forecast error: " . $e->getMessage());
            return ['forecast' => [], 'accuracy' => 0, 'trend' => 'error', 'historical' => []];
        }
    }
    
    // Lead Conversion Funnel
    public function getConversionFunnel() {
        try {
            $funnel = [
                'leads' => 0,
                'contacted' => 0,
                'qualified' => 0,
                'negotiation' => 0,
                'converted' => 0,
                'rates' => []
            ];
            
            // Total Leads
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count 
                FROM leads 
                WHERE created_at BETWEEN ? AND ?
            ");
            $stmt->execute([$this->startDate, $this->endDate . ' 23:59:59']);
            $funnel['leads'] = $stmt->fetch()['count'] ?? 0;
            
            // Status counts
            $stmt = $this->pdo->prepare("
                SELECT 
                    status,
                    COUNT(*) as count
                FROM leads 
                WHERE created_at BETWEEN ? AND ?
                GROUP BY status
            ");
            $stmt->execute([$this->startDate, $this->endDate . ' 23:59:59']);
            $statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $funnel['contacted'] = ($statusCounts['contacted'] ?? 0) + 
                                  ($statusCounts['qualified'] ?? 0) + 
                                  ($statusCounts['negotiation'] ?? 0) + 
                                  ($statusCounts['converted'] ?? 0);
            
            $funnel['qualified'] = ($statusCounts['qualified'] ?? 0) + 
                                  ($statusCounts['negotiation'] ?? 0) + 
                                  ($statusCounts['converted'] ?? 0);
            
            $funnel['negotiation'] = ($statusCounts['negotiation'] ?? 0) + 
                                    ($statusCounts['converted'] ?? 0);
            
            $funnel['converted'] = $statusCounts['converted'] ?? 0;
            
            // Calculate conversion rates
            $funnel['rates'] = [
                'contact_rate' => $funnel['leads'] > 0 ? 
                    round(($funnel['contacted'] / $funnel['leads']) * 100, 1) : 0,
                'qualification_rate' => $funnel['contacted'] > 0 ? 
                    round(($funnel['qualified'] / $funnel['contacted']) * 100, 1) : 0,
                'negotiation_rate' => $funnel['qualified'] > 0 ? 
                    round(($funnel['negotiation'] / $funnel['qualified']) * 100, 1) : 0,
                'conversion_rate' => $funnel['negotiation'] > 0 ? 
                    round(($funnel['converted'] / $funnel['negotiation']) * 100, 1) : 0,
                'overall_conversion' => $funnel['leads'] > 0 ? 
                    round(($funnel['converted'] / $funnel['leads']) * 100, 1) : 0
            ];
            
            return $funnel;
            
        } catch (Exception $e) {
            error_log("Funnel error: " . $e->getMessage());
            return [
                'leads' => 0,
                'contacted' => 0,
                'qualified' => 0,
                'negotiation' => 0,
                'converted' => 0,
                'rates' => []
            ];
        }
    }
    
    // Agent Performance Scoring
    public function getAgentPerformanceScores() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    u.id,
                    u.full_name,
                    COUNT(DISTINCT s.id) as total_sales,
                    COALESCE(SUM(s.sale_price), 0) as total_revenue,
                    COUNT(DISTINCT l.id) as total_leads,
                    COUNT(DISTINCT CASE WHEN l.status = 'converted' THEN l.id END) as converted_leads,
                    COALESCE(AVG(CASE WHEN s.id IS NOT NULL 
                        THEN DATEDIFF(s.sale_date, l.created_at) 
                        ELSE NULL END), 0) as avg_conversion_days
                FROM users u
                LEFT JOIN sales s ON u.id = s.agent_id 
                    AND s.sale_date BETWEEN ? AND ?
                    AND s.status != 'cancelled'
                LEFT JOIN leads l ON u.id = l.assigned_to 
                    AND l.created_at BETWEEN ? AND ?
                WHERE u.role = 'sales_agent' AND u.status = 'active'
                GROUP BY u.id, u.full_name
            ");
            
            $stmt->execute([
                $this->startDate, $this->endDate,
                $this->startDate, $this->endDate
            ]);
            
            $agents = $stmt->fetchAll();
            
            // Get site visits count separately
            foreach ($agents as &$agent) {
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(DISTINCT sv.id) as site_visits
                    FROM site_visit_attendees sva
                    JOIN site_visits sv ON sva.site_visit_id = sv.id
                    WHERE sva.user_id = ? 
                    AND sv.visit_date BETWEEN ? AND ?
                ");
                $stmt->execute([$agent['id'], $this->startDate, $this->endDate]);
                $result = $stmt->fetch();
                $agent['site_visits'] = $result['site_visits'] ?? 0;
            }
            
            // Calculate performance scores
            $maxRevenue = max(array_column($agents, 'total_revenue')) ?: 1;
            $maxSales = max(array_column($agents, 'total_sales')) ?: 1;
            $maxVisits = max(array_column($agents, 'site_visits')) ?: 1;
            
            foreach ($agents as &$agent) {
                $score = 0;
                
                // Revenue contribution (40%)
                if ($maxRevenue > 0) {
                    $score += ($agent['total_revenue'] / $maxRevenue) * 40;
                }
                
                // Sales count (20%)
                if ($maxSales > 0) {
                    $score += ($agent['total_sales'] / $maxSales) * 20;
                }
                
                // Conversion rate (20%)
                $conversionRate = $agent['total_leads'] > 0 ? 
                    ($agent['converted_leads'] / $agent['total_leads']) : 0;
                $score += $conversionRate * 20;
                
                // Activity level (10%)
                if ($maxVisits > 0) {
                    $score += ($agent['site_visits'] / $maxVisits) * 10;
                }
                
                // Speed bonus (10%)
                $avgDays = $agent['avg_conversion_days'] ?: 30;
                $speedScore = max(0, 10 - ($avgDays / 3));
                $score += $speedScore;
                
                $agent['performance_score'] = round($score, 1);
                $agent['grade'] = $this->getGrade($score);
                $agent['conversion_rate'] = $agent['total_leads'] > 0 ? 
                    round(($agent['converted_leads'] / $agent['total_leads']) * 100, 1) : 0;
            }
            
            // Sort by score
            usort($agents, function($a, $b) {
                return $b['performance_score'] <=> $a['performance_score'];
            });
            
            return $agents;
            
        } catch (Exception $e) {
            error_log("Agent scores error: " . $e->getMessage());
            return [];
        }
    }
    
    private function getGrade($score) {
        if ($score >= 90) return 'A+';
        if ($score >= 80) return 'A';
        if ($score >= 70) return 'B';
        if ($score >= 60) return 'C';
        if ($score >= 50) return 'D';
        return 'F';
    }
    
    // Project ROI Analysis
    public function getProjectROI() {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    pr.id,
                    pr.project_name,
                    pr.location,
                    COUNT(DISTINCT p.id) as total_plots,
                    COUNT(DISTINCT CASE WHEN p.status = 'sold' THEN p.id END) as sold_plots,
                    COALESCE(SUM(CASE WHEN p.status = 'sold' THEN s.sale_price END), 0) as revenue,
                    COUNT(DISTINCT sv.id) as site_visits,
                    COUNT(DISTINCT l.id) as leads_generated
                FROM projects pr
                LEFT JOIN plots p ON pr.id = p.project_id
                LEFT JOIN sales s ON p.id = s.plot_id AND s.status != 'cancelled'
                LEFT JOIN site_visits sv ON pr.id = sv.project_id
                LEFT JOIN leads l ON l.notes LIKE CONCAT('%', pr.project_name, '%')
                GROUP BY pr.id, pr.project_name, pr.location
            ");
            
            $projects = $stmt->fetchAll();
            
            foreach ($projects as &$project) {
                // Estimate costs (this would ideally come from a project_expenses table)
                $estimatedCostPerPlot = 100000; // Default estimate
                $totalCost = $project['total_plots'] * $estimatedCostPerPlot;
                
                $project['total_cost'] = $totalCost;
                $project['roi'] = $totalCost > 0 ? 
                    (($project['revenue'] - $totalCost) / $totalCost) * 100 : 0;
                $project['occupancy_rate'] = $project['total_plots'] > 0 ?
                    ($project['sold_plots'] / $project['total_plots']) * 100 : 0;
                $project['avg_plot_value'] = $project['sold_plots'] > 0 ?
                    $project['revenue'] / $project['sold_plots'] : 0;
                $project['conversion_efficiency'] = $project['leads_generated'] > 0 ?
                    ($project['sold_plots'] / $project['leads_generated']) * 100 : 0;
            }
            
            // Sort by ROI
            usort($projects, function($a, $b) {
                return $b['roi'] <=> $a['roi'];
            });
            
            return $projects;
            
        } catch (Exception $e) {
            error_log("Project ROI error: " . $e->getMessage());
            return [];
        }
    }
    
    // Heat Map Data
    public function getLocationHeatmap() {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    pr.location,
                    COALESCE(pr.office_latitude, -1.2921) as lat,
                    COALESCE(pr.office_longitude, 36.8219) as lng,
                    COUNT(DISTINCT s.id) as sales_count,
                    COALESCE(SUM(s.sale_price), 0) as total_revenue,
                    AVG(CASE WHEN l.id IS NOT NULL 
                        THEN DATEDIFF(s.sale_date, l.created_at) 
                        ELSE NULL END) as avg_conversion_time
                FROM projects pr
                JOIN plots p ON pr.id = p.project_id
                LEFT JOIN sales s ON p.id = s.plot_id AND s.status != 'cancelled'
                LEFT JOIN clients c ON s.client_id = c.id
                LEFT JOIN leads l ON c.lead_id = l.id
                GROUP BY pr.location, pr.office_latitude, pr.office_longitude
                HAVING sales_count > 0
            ");
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            error_log("Heatmap error: " . $e->getMessage());
            return [];
        }
    }
    
    // Get Lead Sources Performance
    public function getLeadSourcesPerformance() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    l.source,
                    COUNT(*) as total_leads,
                    SUM(CASE WHEN l.status = 'converted' THEN 1 ELSE 0 END) as converted,
                    SUM(CASE WHEN l.status = 'converted' THEN s.sale_price ELSE 0 END) as revenue
                FROM leads l
                LEFT JOIN clients c ON l.id = c.lead_id
                LEFT JOIN sales s ON c.id = s.client_id AND s.status != 'cancelled'
                WHERE l.created_at BETWEEN ? AND ?
                GROUP BY l.source
                ORDER BY converted DESC
            ");
            
            $stmt->execute([$this->startDate, $this->endDate]);
            $sources = $stmt->fetchAll();
            
            foreach ($sources as &$source) {
                $source['conversion_rate'] = $source['total_leads'] > 0 ?
                    round(($source['converted'] / $source['total_leads']) * 100, 1) : 0;
                $source['avg_value'] = $source['converted'] > 0 ?
                    round($source['revenue'] / $source['converted'], 2) : 0;
            }
            
            return $sources;
            
        } catch (Exception $e) {
            error_log("Lead sources error: " . $e->getMessage());
            return [];
        }
    }
}

// Initialize Analytics Engine
$analytics = new AnalyticsEngine($pdo, $startDate, $endDate);
$forecast = $analytics->getSalesForcast();
$funnel = $analytics->getConversionFunnel();
$agentScores = $analytics->getAgentPerformanceScores();
$projectROI = $analytics->getProjectROI();
$heatmapData = $analytics->getLocationHeatmap();
$leadSources = $analytics->getLeadSourcesPerformance();

// Get comparison data if needed
$comparison = null;
if ($compareMode === 'previous_period') {
    $daysDiff = (strtotime($endDate) - strtotime($startDate)) / 86400;
    $compareStart = date('Y-m-d', strtotime($startDate . " -$daysDiff days"));
    $compareEnd = date('Y-m-d', strtotime($endDate . " -$daysDiff days"));
    
    $compareAnalytics = new AnalyticsEngine($pdo, $compareStart, $compareEnd);
    $comparison = [
        'funnel' => $compareAnalytics->getConversionFunnel(),
        'forecast' => $compareAnalytics->getSalesForcast()
    ];
}

// Get settings for styling
$settings = getSettings();

include 'includes/header.php';
?>

<div class="p-4 md:p-6 pb-20 md:pb-6">
    <!-- Header with Date Filter -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
        <div>
            <h1 class="text-2xl md:text-3xl font-bold text-gray-800">Analytics Dashboard</h1>
            <p class="text-gray-600 mt-1">Advanced insights and predictions</p>
        </div>
        
        <div class="flex gap-2 mt-4 md:mt-0">
            <input type="date" id="startDate" value="<?php echo $startDate; ?>" class="px-3 py-2 border rounded-lg">
            <input type="date" id="endDate" value="<?php echo $endDate; ?>" class="px-3 py-2 border rounded-lg">
            <button onclick="updateDateRange()" class="px-4 py-2 bg-primary text-white rounded-lg hover:opacity-90">
                Apply
            </button>
        </div>
    </div>
    
    <!-- Sales Forecast -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-bold">Revenue Forecast</h2>
            <span class="text-sm text-gray-600">
                Trend: <span class="<?php echo $forecast['trend'] === 'up' ? 'text-green-600' : 'text-red-600'; ?>">
                    <i class="fas fa-arrow-<?php echo $forecast['trend']; ?>"></i> <?php echo ucfirst($forecast['trend']); ?>
                </span>
            </span>
        </div>
        
        <canvas id="forecastChart" height="100"></canvas>
        
        <?php if (!empty($forecast['forecast'])): ?>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
            <?php foreach ($forecast['forecast'] as $f): ?>
            <div class="p-4 bg-gray-50 rounded-lg">
                <p class="text-sm text-gray-600"><?php echo date('F Y', strtotime($f['month'] . '-01')); ?></p>
                <p class="text-xl font-bold text-primary"><?php echo formatMoney($f['predicted_revenue']); ?></p>
                <p class="text-xs text-gray-500">Confidence: <?php echo ($f['confidence'] * 100); ?>%</p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Conversion Funnel -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
        <h2 class="text-xl font-bold mb-4">Conversion Funnel</h2>
        
        <div class="relative">
            <div class="flex justify-between items-center">
                <?php 
                $funnelStages = [
                    'leads' => ['label' => 'Leads', 'color' => 'blue'],
                    'contacted' => ['label' => 'Contacted', 'color' => 'indigo'],
                    'qualified' => ['label' => 'Qualified', 'color' => 'purple'],
                    'negotiation' => ['label' => 'Negotiation', 'color' => 'pink'],
                    'converted' => ['label' => 'Converted', 'color' => 'green']
                ];
                
                foreach ($funnelStages as $key => $stage): 
                    $width = $funnel['leads'] > 0 ? ($funnel[$key] / $funnel['leads']) * 100 : 0;
                ?>
                <div class="flex-1 text-center">
                    <div class="relative mb-2">
                        <div class="h-20 bg-<?php echo $stage['color']; ?>-100 mx-2" 
                             style="width: <?php echo $width; ?>%; margin: 0 auto; clip-path: polygon(0 0, 100% 0, 90% 100%, 10% 100%);">
                        </div>
                        <span class="absolute inset-0 flex items-center justify-center font-bold text-lg">
                            <?php echo $funnel[$key]; ?>
                        </span>
                    </div>
                    <p class="text-sm font-semibold"><?php echo $stage['label']; ?></p>
                    <?php if ($key !== 'leads' && isset($funnel['rates'])): ?>
                    <p class="text-xs text-gray-600">
                        <?php 
                        $rateKeys = array_keys($funnel['rates']);
                        $stageKeys = array_keys($funnelStages);
                        $index = array_search($key, $stageKeys) - 1;
                        if ($index >= 0 && isset($rateKeys[$index])) {
                            echo number_format($funnel['rates'][$rateKeys[$index]], 1) . '%';
                        }
                        ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="mt-6 p-4 bg-primary bg-opacity-10 rounded-lg">
            <p class="text-center">
                <span class="text-2xl font-bold text-primary"><?php echo number_format($funnel['rates']['overall_conversion'], 1); ?>%</span>
                <span class="text-gray-600 ml-2">Overall Conversion Rate</span>
            </p>
        </div>
    </div>
    
    <!-- Agent Performance Leaderboard -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
        <h2 class="text-xl font-bold mb-4">Agent Performance Leaderboard</h2>
        
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Rank</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Agent</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Score</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Sales</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Revenue</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Conv. Rate</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Grade</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach (array_slice($agentScores, 0, 10) as $index => $agent): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <?php if ($index < 3): ?>
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full 
                                <?php echo $index === 0 ? 'bg-yellow-400' : ($index === 1 ? 'bg-gray-400' : 'bg-orange-400'); ?> text-white font-bold">
                                <?php echo $index + 1; ?>
                            </span>
                            <?php else: ?>
                            <span class="text-gray-600 font-semibold"><?php echo $index + 1; ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-semibold"><?php echo sanitize($agent['full_name']); ?></p>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center">
                                <div class="w-24 bg-gray-200 rounded-full h-2 mr-2">
                                    <div class="bg-primary h-2 rounded-full" style="width: <?php echo min(100, $agent['performance_score']); ?>%"></div>
                                </div>
                                <span class="text-sm font-semibold"><?php echo $agent['performance_score']; ?></span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm"><?php echo $agent['total_sales']; ?></td>
                        <td class="px-4 py-3 text-sm font-semibold"><?php echo formatMoney($agent['total_revenue']); ?></td>
                        <td class="px-4 py-3 text-sm"><?php echo $agent['conversion_rate']; ?>%</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 text-xs font-bold rounded-full
                                <?php 
                                $gradeColors = [
                                    'A+' => 'bg-green-100 text-green-800',
                                    'A' => 'bg-green-100 text-green-800',
                                    'B' => 'bg-blue-100 text-blue-800',
                                    'C' => 'bg-yellow-100 text-yellow-800',
                                    'D' => 'bg-orange-100 text-orange-800',
                                    'F' => 'bg-red-100 text-red-800'
                                ];
                                echo $gradeColors[$agent['grade']] ?? 'bg-gray-100 text-gray-800';
                                ?>">
                                <?php echo $agent['grade']; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Project ROI Analysis -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
        <h2 class="text-xl font-bold mb-4">Project ROI Analysis</h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($projectROI as $project): ?>
            <div class="border border-gray-200 rounded-lg p-4 hover:shadow-lg transition">
                <h3 class="font-bold mb-2"><?php echo sanitize($project['project_name']); ?></h3>
                <p class="text-xs text-gray-600 mb-3"><?php echo sanitize($project['location']); ?></p>
                
                <div class="space-y-2">
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">ROI</span>
                        <span class="font-bold <?php echo $project['roi'] > 0 ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo number_format($project['roi'], 1); ?>%
                        </span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Occupancy</span>
                        <span class="font-semibold"><?php echo number_format($project['occupancy_rate'], 0); ?>%</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Revenue</span>
                        <span class="font-semibold text-primary"><?php echo formatMoney($project['revenue']); ?></span>
                    </div>
                    
                    <!-- Progress Bar -->
                    <div class="mt-3">
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-primary h-2 rounded-full" style="width: <?php echo min(100, $project['occupancy_rate']); ?>%"></div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">
                            <?php echo $project['sold_plots']; ?> / <?php echo $project['total_plots']; ?> plots sold
                        </p>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Lead Sources Performance -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
        <h2 class="text-xl font-bold mb-4">Lead Sources Performance</h2>
        
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Source</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Leads</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Converted</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Conv. Rate</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Revenue</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Avg. Value</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($leadSources as $source): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <span class="font-semibold"><?php echo ucfirst($source['source']); ?></span>
                        </td>
                        <td class="px-4 py-3 text-sm"><?php echo $source['total_leads']; ?></td>
                        <td class="px-4 py-3 text-sm"><?php echo $source['converted']; ?></td>
                        <td class="px-4 py-3">
                            <div class="flex items-center">
                                <div class="w-16 bg-gray-200 rounded-full h-2 mr-2">
                                    <div class="bg-green-500 h-2 rounded-full" style="width: <?php echo min(100, $source['conversion_rate']); ?>%"></div>
                                </div>
                                <span class="text-sm font-semibold"><?php echo $source['conversion_rate']; ?>%</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm font-semibold"><?php echo formatMoney($source['revenue'] ?? 0); ?></td>
                        <td class="px-4 py-3 text-sm"><?php echo formatMoney($source['avg_value']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Location Heat Map -->
    <div class="bg-white rounded-xl shadow-lg p-6">
        <h2 class="text-xl font-bold mb-4">Sales Heat Map</h2>
        <div id="heatmap" class="h-96 rounded-lg"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>

<script>
// Forecast Chart
const forecastData = <?php echo json_encode($forecast['forecast'] ?? []); ?>;
const historicalData = <?php echo json_encode($forecast['historical'] ?? []); ?>;

if (forecastData.length > 0 || historicalData.length > 0) {
    const ctx = document.getElementById('forecastChart');
    if (ctx) {
        // Combine historical and forecast data
        const allMonths = [
            ...historicalData.map(d => d.month),
            ...forecastData.map(d => d.month)
        ];
        
        const historicalRevenue = historicalData.map(d => d.revenue);
        const forecastRevenue = [
            ...new Array(historicalData.length).fill(null),
            ...forecastData.map(d => d.predicted_revenue)
        ];
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: allMonths,
                datasets: [
                    {
                        label: 'Historical Revenue',
                        data: [...historicalRevenue, ...new Array(forecastData.length).fill(null)],
                        borderColor: '<?php echo $settings['primary_color']; ?>',
                        backgroundColor: '<?php echo $settings['primary_color']; ?>20',
                        borderWidth: 2,
                        fill: false,
                        tension: 0.4
                    },
                    {
                        label: 'Predicted Revenue',
                        data: forecastRevenue,
                        borderColor: '<?php echo $settings['secondary_color']; ?>',
                        backgroundColor: '<?php echo $settings['secondary_color']; ?>20',
                        borderWidth: 2,
                        borderDash: [5, 5],
                        fill: false,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': KES ' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
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
}

// Heat Map
const heatmapData = <?php echo json_encode($heatmapData); ?>;
const mapElement = document.getElementById('heatmap');

if (mapElement) {
    const map = L.map('heatmap').setView([-1.2921, 36.8219], 10);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    if (heatmapData.length > 0) {
        const heatPoints = heatmapData.map(d => [
            d.lat || -1.2921, 
            d.lng || 36.8219, 
            d.total_revenue / 1000000 // Scale revenue for heat intensity
        ]);
        
        const heat = L.heatLayer(heatPoints, {
            radius: 25,
            blur: 15,
            maxZoom: 17,
            gradient: {
                0.4: 'blue',
                0.6: 'cyan',
                0.7: 'lime',
                0.8: 'yellow',
                1.0: 'red'
            }
        }).addTo(map);
        
        // Add markers for each location
        heatmapData.forEach(location => {
            const marker = L.marker([location.lat || -1.2921, location.lng || 36.8219])
                .addTo(map)
                .bindPopup(`
                    <strong>${location.location}</strong><br>
                    Sales: ${location.sales_count}<br>
                    Revenue: KES ${Number(location.total_revenue).toLocaleString()}<br>
                    Avg Conversion: ${location.avg_conversion_time ? Math.round(location.avg_conversion_time) + ' days' : 'N/A'}
                `);
        });
    }
}

function updateDateRange() {
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    window.location.href = `?start_date=${startDate}&end_date=${endDate}`;
}

// Auto-refresh every 5 minutes
setTimeout(() => {
    location.reload();
}, 300000);
</script>

<?php include 'includes/footer.php'; ?>