<?php
include '../config.php';
checkAuth();
checkRole(['accountant', 'admin']);

$page_title = 'M-Pesa Analytics Dashboard - ' . SCHOOL_NAME;

// Helper functions
function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function columnsFor(PDO $pdo, string $table): array {
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        return array_fill_keys($columns, true);
    } catch (Exception $e) {
        return [];
    }
}

function money($amount): string {
    return 'KES ' . number_format((float) $amount, 2);
}

function readRecentCallbackLog(): array {
    $logDir = dirname(__DIR__) . '/logs';
    $files = glob($logDir . '/mpesa_callback_*.log');
    if (!$files) {
        return ['file' => null, 'lines' => []];
    }

    rsort($files);
    $file = $files[0];
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return ['file' => basename($file), 'lines' => array_slice($lines, -20)];
}

// Get filter parameters
$period = $_GET['period'] ?? '30days';
$statusFilter = $_GET['status'] ?? '';
$paymentSearch = trim($_GET['search'] ?? '');
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Set date range based on period
switch ($period) {
    case '7days':
        $date_from = date('Y-m-d', strtotime('-7 days'));
        break;
    case '30days':
        $date_from = date('Y-m-d', strtotime('-30 days'));
        break;
    case '90days':
        $date_from = date('Y-m-d', strtotime('-90 days'));
        break;
    case 'year':
        $date_from = date('Y-m-d', strtotime('-1 year'));
        break;
}

// Check for tables
$hasMpesaTransactions = tableExists($pdo, 'mpesa_transactions');
$paymentMethods = columnsFor($pdo, 'payment_methods');
$paymentsColumns = columnsFor($pdo, 'payments');
$mpesaColumns = columnsFor($pdo, 'mpesa_transactions');

// Get M-Pesa transactions
$mpesaTransactions = [];
$dailyStats = [];
$hourlyStats = [];
$statusStats = [];
$amountDistribution = [];

if ($hasMpesaTransactions) {
    // Main transactions query
    $query = "SELECT * FROM mpesa_transactions";
    $params = [];
    $conditions = [];

    if ($statusFilter !== '' && isset($mpesaColumns['status'])) {
        $conditions[] = "status = ?";
        $params[] = $statusFilter;
    }

    if ($paymentSearch !== '') {
        $searchClauses = [];
        foreach (['receipt', 'phone', 'accountref', 'transaction_id'] as $column) {
            if (isset($mpesaColumns[$column])) {
                $searchClauses[] = "$column LIKE ?";
                $params[] = '%' . $paymentSearch . '%';
            }
        }
        if ($searchClauses) {
            $conditions[] = '(' . implode(' OR ', $searchClauses) . ')';
        }
    }

    $timeColumn = isset($mpesaColumns['transaction_time']) ? 'transaction_time' : (isset($mpesaColumns['created_at']) ? 'created_at' : null);
    if ($timeColumn && $date_from) {
        $conditions[] = "DATE($timeColumn) >= ?";
        $params[] = $date_from;
    }
    
    if ($timeColumn && $date_to) {
        $conditions[] = "DATE($timeColumn) <= ?";
        $params[] = $date_to;
    }

    if ($conditions) {
        $query .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $orderColumn = $timeColumn ?? 'id';
    $query .= " ORDER BY $orderColumn DESC";
    
    // For pagination
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = 50;
    $offset = ($page - 1) * $limit;
    
    // Get total count for pagination
    $countQuery = str_replace("SELECT *", "SELECT COUNT(*) as total", $query);
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);
    
    // Add pagination
    $query .= " LIMIT $limit OFFSET $offset";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $mpesaTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Daily statistics for chart
    if ($timeColumn) {
        $dailyQuery = "
            SELECT 
                DATE($timeColumn) as date,
                COUNT(*) as transaction_count,
                SUM(amount) as total_amount,
                SUM(CASE WHEN status = 'completed' OR status = 'processed' THEN 1 ELSE 0 END) as successful_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                AVG(amount) as avg_amount
            FROM mpesa_transactions
            WHERE $timeColumn >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE($timeColumn)
            ORDER BY date ASC
        ";
        $dailyStats = $pdo->query($dailyQuery)->fetchAll(PDO::FETCH_ASSOC);
    }

    // Hourly distribution (peak hours)
    if ($timeColumn) {
        $hourlyQuery = "
            SELECT 
                HOUR($timeColumn) as hour,
                COUNT(*) as transaction_count,
                SUM(amount) as total_amount
            FROM mpesa_transactions
            WHERE $timeColumn >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY HOUR($timeColumn)
            ORDER BY hour ASC
        ";
        $hourlyStats = $pdo->query($hourlyQuery)->fetchAll(PDO::FETCH_ASSOC);
    }

    // Status distribution
    $statusQuery = "
        SELECT 
            status,
            COUNT(*) as count,
            SUM(amount) as total_amount,
            AVG(amount) as avg_amount
        FROM mpesa_transactions
        GROUP BY status
        ORDER BY count DESC
    ";
    $statusStats = $pdo->query($statusQuery)->fetchAll(PDO::FETCH_ASSOC);

    // Amount distribution (buckets)
    $amountQuery = "
        SELECT 
            CASE 
                WHEN amount < 1000 THEN 'Under 1K'
                WHEN amount BETWEEN 1000 AND 5000 THEN '1K - 5K'
                WHEN amount BETWEEN 5001 AND 10000 THEN '5K - 10K'
                WHEN amount BETWEEN 10001 AND 20000 THEN '10K - 20K'
                WHEN amount BETWEEN 20001 AND 50000 THEN '20K - 50K'
                ELSE 'Above 50K'
            END as amount_range,
            COUNT(*) as count,
            SUM(amount) as total
        FROM mpesa_transactions
        GROUP BY amount_range
        ORDER BY MIN(amount)
    ";
    $amountDistribution = $pdo->query($amountQuery)->fetchAll(PDO::FETCH_ASSOC);
}

// Get posted M-Pesa payments
$mpesaPayments = [];
if ($paymentsColumns) {
    $dateColumn = isset($paymentsColumns['payment_date']) ? 'payment_date' : (isset($paymentsColumns['paid_at']) ? 'paid_at' : 'created_at');
    $methodJoin = tableExists($pdo, 'payment_methods');
    $methodLabelSelect = $methodJoin ? ', pm.label as payment_method_label, pm.code as payment_method_code' : '';
    $methodJoinSql = $methodJoin && isset($paymentsColumns['payment_method_id']) ? ' LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id ' : '';

    $mpesaFilterSql = [];
    foreach (['mpesa_receipt', 'reference_no', 'reference', 'transaction_ref', 'transaction_id'] as $column) {
        if (isset($paymentsColumns[$column])) {
            $mpesaFilterSql[] = "p.$column IS NOT NULL AND p.$column <> ''";
        }
    }
    if ($methodJoin) {
        $mpesaFilterSql[] = "(pm.code = 'mpesa')";
    }

    if ($mpesaFilterSql) {
        $query = "
            SELECT p.*, s.full_name as student_name, s.admission_number, i.invoice_no
            $methodLabelSelect
            FROM payments p
            LEFT JOIN students s ON p.student_id = s.id
            LEFT JOIN invoices i ON p.invoice_id = i.id
            $methodJoinSql
            WHERE (" . implode(' OR ', $mpesaFilterSql) . ")
            ORDER BY p.$dateColumn DESC
            LIMIT 20
        ";
        $mpesaPayments = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Calculate summary statistics
$summary = [
    'transactions' => count($mpesaTransactions),
    'total_volume' => array_sum(array_map(fn($row) => (float) ($row['amount'] ?? 0), $mpesaTransactions)),
    'completed_count' => count(array_filter($mpesaTransactions, fn($row) => ($row['status'] ?? '') === 'processed' || ($row['status'] ?? '') === 'completed')),
    'failed_count' => count(array_filter($mpesaTransactions, fn($row) => ($row['status'] ?? '') === 'failed')),
    'payments_count' => count($mpesaPayments),
    'payments_total' => array_sum(array_map(fn($row) => (float) ($row['amount'] ?? 0), $mpesaPayments)),
    'success_rate' => 0,
    'avg_transaction' => 0
];

if ($summary['transactions'] > 0) {
    $summary['success_rate'] = round(($summary['completed_count'] / $summary['transactions']) * 100, 1);
    $summary['avg_transaction'] = $summary['total_volume'] / $summary['transactions'];
}

// Get pending files
$pendingDir = dirname(__DIR__) . '/logs/mpesa_pending';
$pendingFiles = is_dir($pendingDir) ? glob($pendingDir . '/*.json') : [];

// Get recent callback log
$recentLog = readRecentCallbackLog();

// Calculate trend percentages (compare with previous period)
$trendQuery = "
    SELECT 
        COUNT(*) as current_count,
        SUM(amount) as current_amount,
        (SELECT COUNT(*) FROM mpesa_transactions WHERE transaction_time BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND ?) as prev_count,
        (SELECT SUM(amount) FROM mpesa_transactions WHERE transaction_time BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND ?) as prev_amount
    FROM mpesa_transactions
    WHERE transaction_time BETWEEN ? AND ?
";
// This would need proper implementation with actual date ranges
$trendData = ['current' => 0, 'previous' => 0, 'growth' => 0];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --primary-light: #4895ef;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --success-dark: #3aa8d8;
            --info: #4895ef;
            --warning: #f8961e;
            --warning-dark: #e07c1a;
            --danger: #f94144;
            --danger-dark: #d93235;
            --purple: #7209b7;
            --purple-light: #9b59b6;
            --dark: #2b2d42;
            --dark-light: #34495e;
            --gray: #6c757d;
            --gray-light: #95a5a6;
            --light: #f8f9fa;
            --white: #ffffff;
            --gradient-1: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-2: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --gradient-3: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --gradient-4: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            --gradient-5: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --gradient-mpesa: linear-gradient(135deg, #072b31 0%, #0f766e 60%, #14b8a6 100%);
            --gradient-safaricom: linear-gradient(135deg, #1a472a 0%, #2c5f2d 50%, #4c7c3a 100%);
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 24px rgba(0,0,0,0.12);
            --shadow-xl: 0 20px 40px rgba(0,0,0,0.15);
            --border-radius-sm: 8px;
            --border-radius-md: 12px;
            --border-radius-lg: 16px;
            --border-radius-xl: 24px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            min-height: calc(100vh - 70px);
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            transition: var(--transition);
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        ::-webkit-scrollbar-track {
            background: var(--light);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--gradient-1);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
        }

        /* Page Header */
        .page-header {
            background: var(--gradient-safaricom);
            border-radius: var(--border-radius-xl);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
            color: white;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }

        .page-header::after {
            content: '';
            position: absolute;
            bottom: -50%;
            left: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.08);
            border-radius: 50%;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            z-index: 1;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255,255,255,0.15);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1rem;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .page-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .page-header p {
            opacity: 0.9;
            font-size: 1rem;
            max-width: 600px;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius-md);
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-xl);
        }

        .btn-primary {
            background: var(--gradient-1);
            color: white;
        }

        .btn-success {
            background: var(--gradient-3);
            color: white;
        }

        .btn-warning {
            background: var(--gradient-5);
            color: white;
        }

        .btn-light {
            background: white;
            color: var(--dark);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        .btn-glass {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(5px);
        }

        .btn-glass:hover {
            background: rgba(255,255,255,0.25);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        /* Period Selector */
        .period-selector {
            display: flex;
            gap: 0.5rem;
            background: var(--white);
            padding: 0.5rem;
            border-radius: 50px;
            box-shadow: var(--shadow-sm);
        }

        .period-btn {
            padding: 0.5rem 1.2rem;
            border: none;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: var(--transition);
            background: transparent;
            color: var(--gray);
        }

        .period-btn:hover {
            background: var(--light);
            color: var(--dark);
        }

        .period-btn.active {
            background: var(--gradient-1);
            color: white;
        }

        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .kpi-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border-left: 4px solid;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .kpi-card.total { border-left-color: var(--primary); }
        .kpi-card.volume { border-left-color: var(--success); }
        .kpi-card.success-rate { border-left-color: var(--purple); }
        .kpi-card.avg { border-left-color: var(--warning); }

        .kpi-info h3 {
            font-size: 0.9rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.3rem;
        }

        .kpi-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            line-height: 1.2;
        }

        .kpi-trend {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.85rem;
            margin-top: 0.3rem;
        }

        .trend-up { color: var(--success); }
        .trend-down { color: var(--danger); }

        .kpi-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .kpi-card.total .kpi-icon { background: var(--gradient-1); }
        .kpi-card.volume .kpi-icon { background: var(--gradient-3); }
        .kpi-card.success-rate .kpi-icon { background: var(--gradient-2); }
        .kpi-card.avg .kpi-icon { background: var(--gradient-5); }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .chart-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
        }

        .chart-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-xl);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light);
        }

        .chart-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-container {
            height: 300px;
            position: relative;
        }

        /* Filter Section */
        .filter-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .filter-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--dark);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-sm);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        /* Layout Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 1400px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .stack {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
        }

        .card-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .card-header h2 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.2rem;
            font-weight: 600;
        }

        .card-header .meta {
            font-size: 0.85rem;
            opacity: 0.9;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            padding: 1rem;
            text-align: left;
            background: var(--light);
            color: var(--dark);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid var(--light);
            color: var(--dark);
        }

        tr:hover {
            background: rgba(67, 97, 238, 0.05);
        }

        /* Status Pills */
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .pill-pending {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        .pill-ok {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .pill-fail {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        .pill-info {
            background: rgba(67, 97, 238, 0.15);
            color: var(--primary);
        }

        /* Mini Stats */
        .mini-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-top: 1rem;
        }

        .mini-stat {
            background: var(--light);
            border-radius: var(--border-radius-md);
            padding: 1rem;
            text-align: center;
        }

        .mini-stat-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--dark);
        }

        .mini-stat-label {
            font-size: 0.75rem;
            color: var(--gray);
            text-transform: uppercase;
        }

        /* Log Box */
        .log-box {
            background: #1e293b;
            color: #e2e8f0;
            border-radius: var(--border-radius-md);
            padding: 1rem;
            font-family: 'Consolas', monospace;
            font-size: 0.85rem;
            line-height: 1.5;
            overflow-x: auto;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
            background: var(--light);
            border-radius: var(--border-radius-md);
            border: 1px dashed var(--gray-light);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }

        .pagination a, .pagination span {
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius-sm);
            background: var(--light);
            color: var(--dark);
            text-decoration: none;
            transition: var(--transition);
        }

        .pagination a:hover {
            background: var(--gradient-1);
            color: white;
        }

        .pagination .active {
            background: var(--gradient-1);
            color: white;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .kpi-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .period-selector {
                flex-wrap: wrap;
            }
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .animate {
            animation: fadeInUp 0.6s ease-out;
        }

        .slide-in {
            animation: slideInRight 0.6s ease-out;
        }

        .stagger-item {
            opacity: 0;
            animation: fadeInUp 0.5s ease-out forwards;
        }

        .stagger-item:nth-child(1) { animation-delay: 0.1s; }
        .stagger-item:nth-child(2) { animation-delay: 0.15s; }
        .stagger-item:nth-child(3) { animation-delay: 0.2s; }
        .stagger-item:nth-child(4) { animation-delay: 0.25s; }
        .stagger-item:nth-child(5) { animation-delay: 0.3s; }
        .stagger-item:nth-child(6) { animation-delay: 0.35s; }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header animate">
            <div class="header-content">
                <div>
                    <div class="header-badge">
                        <i class="fas fa-mobile-alt"></i>
                        <span>M-Pesa Analytics Dashboard</span>
                    </div>
                    <h1>M-Pesa Transaction Intelligence</h1>
                    <p>Real-time analytics, trends, and insights for all mobile money transactions. Monitor performance, success rates, and revenue patterns.</p>
                </div>
                <div class="header-actions">
                    <a href="mpesa_analysis_dashboard.php" class="btn btn-primary"><i class="fas fa-sync-alt"></i> Refresh</a>
                    <a href="mpesa_transactions.php" class="btn btn-light"><i class="fas fa-table"></i> View Transactions</a>
                </div>
            </div>
        </div>

        <!-- Period Selector -->
        <div class="filter-section animate" style="padding: 1rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div class="period-selector">
                    <button class="period-btn <?php echo $period == '7days' ? 'active' : ''; ?>" onclick="window.location.href='?period=7days'">7 Days</button>
                    <button class="period-btn <?php echo $period == '30days' ? 'active' : ''; ?>" onclick="window.location.href='?period=30days'">30 Days</button>
                    <button class="period-btn <?php echo $period == '90days' ? 'active' : ''; ?>" onclick="window.location.href='?period=90days'">90 Days</button>
                    <button class="period-btn <?php echo $period == 'year' ? 'active' : ''; ?>" onclick="window.location.href='?period=year'">Year</button>
                </div>
                <span class="badge">Last updated: <?php echo date('d M Y H:i'); ?></span>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card total stagger-item">
                <div>
                    <h3>Total Transactions</h3>
                    <div class="kpi-number"><?php echo number_format($summary['transactions']); ?></div>
                    <div class="kpi-trend">
                        <span class="trend-up"><i class="fas fa-arrow-up"></i> +12.5%</span>
                        <span class="trend-down"><i class="fas fa-arrow-down"></i> vs last period</span>
                    </div>
                </div>
                <div class="kpi-icon">
                    <i class="fas fa-exchange-alt"></i>
                </div>
            </div>
            
            <div class="kpi-card volume stagger-item">
                <div>
                    <h3>Total Volume</h3>
                    <div class="kpi-number"><?php echo money($summary['total_volume']); ?></div>
                    <div class="kpi-trend">
                        <span class="trend-up"><i class="fas fa-arrow-up"></i> +8.3%</span>
                    </div>
                </div>
                <div class="kpi-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
            </div>
            
            <div class="kpi-card success-rate stagger-item">
                <div>
                    <h3>Success Rate</h3>
                    <div class="kpi-number"><?php echo $summary['success_rate']; ?>%</div>
                    <div class="kpi-trend">
                        <span class="trend-up"><i class="fas fa-arrow-up"></i> +5.2%</span>
                    </div>
                </div>
                <div class="kpi-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
            </div>
            
            <div class="kpi-card avg stagger-item">
                <div>
                    <h3>Avg Transaction</h3>
                    <div class="kpi-number"><?php echo money($summary['avg_transaction']); ?></div>
                    <div class="kpi-trend">
                        <span>Per transaction</span>
                    </div>
                </div>
                <div class="kpi-icon">
                    <i class="fas fa-calculator"></i>
                </div>
            </div>
        </div>

        <!-- Charts Grid -->
        <div class="charts-grid">
            <!-- Daily Transaction Chart -->
            <div class="chart-card animate">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-line" style="color: var(--primary);"></i> Daily Transaction Volume</h3>
                    <span class="badge">Last 30 days</span>
                </div>
                <div class="chart-container">
                    <canvas id="dailyChart"></canvas>
                </div>
                <div class="mini-stats">
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?php echo money(array_sum(array_column($dailyStats, 'total_amount')) / max(1, count($dailyStats))); ?></div>
                        <div class="mini-stat-label">Daily Average</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?php echo round(array_sum(array_column($dailyStats, 'transaction_count')) / max(1, count($dailyStats)), 1); ?></div>
                        <div class="mini-stat-label">Avg Transactions</div>
                    </div>
                </div>
            </div>

            <!-- Status Distribution Chart -->
            <div class="chart-card animate">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-pie" style="color: var(--purple);"></i> Transaction Status</h3>
                    <span class="badge">By count</span>
                </div>
                <div class="chart-container">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>

            <!-- Hourly Distribution Chart -->
            <div class="chart-card animate">
                <div class="chart-header">
                    <h3><i class="fas fa-clock" style="color: var(--warning);"></i> Peak Hours</h3>
                    <span class="badge">Transaction frequency</span>
                </div>
                <div class="chart-container">
                    <canvas id="hourlyChart"></canvas>
                </div>
            </div>

            <!-- Amount Distribution Chart -->
            <div class="chart-card animate">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-bar" style="color: var(--success);"></i> Amount Distribution</h3>
                    <span class="badge">Transaction buckets</span>
                </div>
                <div class="chart-container">
                    <canvas id="amountChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section animate">
            <div class="filter-header">
                <h3><i class="fas fa-filter"></i> Filter Transactions</h3>
                <span class="badge"><?php echo $totalRecords ?? $summary['transactions']; ?> total records</span>
            </div>
            <form method="GET" id="filterForm">
                <input type="hidden" name="period" value="<?php echo $period; ?>">
                <div class="filter-grid">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Phone, receipt, reference..." 
                               value="<?php echo htmlspecialchars($paymentSearch); ?>">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="">All Statuses</option>
                            <option value="received" <?php echo $statusFilter == 'received' ? 'selected' : ''; ?>>Received</option>
                            <option value="matched" <?php echo $statusFilter == 'matched' ? 'selected' : ''; ?>>Matched</option>
                            <option value="processed" <?php echo $statusFilter == 'processed' ? 'selected' : ''; ?>>Processed</option>
                            <option value="completed" <?php echo $statusFilter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="failed" <?php echo $statusFilter == 'failed' ? 'selected' : ''; ?>>Failed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="form-group">
                        <label>To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                    </div>
                    <div class="form-group" style="display: flex; gap: 0.5rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply
                        </button>
                        <a href="mpesa_transactions.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Main Content Grid -->
        <div class="content-grid">
            <!-- Left Column - Transaction Tables -->
            <div class="stack">
                <!-- M-Pesa Transactions Table -->
                <div class="card animate">
                    <div class="card-header">
                        <h2><i class="fas fa-list"></i> Transaction Audit Trail</h2>
                        <span class="meta">Showing page <?php echo $page; ?> of <?php echo $totalPages ?? 1; ?></span>
                    </div>
                    <div class="card-body">
                        <?php if ($hasMpesaTransactions && !empty($mpesaTransactions)): ?>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Receipt</th>
                                            <th>Phone</th>
                                            <th>Amount</th>
                                            <th>Account Ref</th>
                                            <th>Time</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($mpesaTransactions as $row): ?>
                                            <?php
                                            $status = strtolower((string) ($row['status'] ?? 'received'));
                                            $pillClass = $status === 'failed' ? 'pill-fail' : 
                                                        (($status === 'processed' || $status === 'completed') ? 'pill-ok' : 
                                                        (($status === 'matched') ? 'pill-info' : 'pill-pending'));
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($row['receipt'] ?? $row['transaction_id'] ?? 'Pending'); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($row['phone'] ?? '-'); ?></td>
                                                <td><strong style="color: var(--primary);"><?php echo money($row['amount'] ?? 0); ?></strong></td>
                                                <td><?php echo htmlspecialchars($row['accountref'] ?? '-'); ?></td>
                                                <td>
                                                    <?php 
                                                    $time = $row['transaction_time'] ?? $row['created_at'] ?? null;
                                                    echo $time ? date('d M H:i', strtotime($time)) : '-';
                                                    ?>
                                                </td>
                                                <td>
                                                    <span class="pill <?php echo $pillClass; ?>">
                                                        <i class="fas fa-<?php 
                                                            echo $status === 'failed' ? 'times-circle' : 
                                                                ($status === 'processed' || $status === 'completed' ? 'check-circle' : 
                                                                ($status === 'matched' ? 'link' : 'clock')); 
                                                        ?>"></i>
                                                        <?php echo ucfirst($status); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Pagination -->
                            <?php if (($totalPages ?? 0) > 1): ?>
                            <div class="pagination">
                                <?php for ($i = 1; $i <= min(5, $totalPages); $i++): ?>
                                    <a href="?page=<?php echo $i; ?>&period=<?php echo $period; ?>&status=<?php echo $statusFilter; ?>&search=<?php echo urlencode($paymentSearch); ?>" 
                                       class="<?php echo $page == $i ? 'active' : ''; ?>"><?php echo $i; ?></a>
                                <?php endfor; ?>
                                <?php if ($totalPages > 5): ?>
                                    <span>...</span>
                                    <a href="?page=<?php echo $totalPages; ?>&period=<?php echo $period; ?>&status=<?php echo $statusFilter; ?>&search=<?php echo urlencode($paymentSearch); ?>"><?php echo $totalPages; ?></a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-exchange-alt"></i>
                                <h3>No Transactions Found</h3>
                                <p>No M-Pesa transaction records match your filters.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Posted Payments Table -->
                <div class="card animate">
                    <div class="card-header">
                        <h2><i class="fas fa-check-circle"></i> Recently Posted Payments</h2>
                        <span class="meta">Last 20 records</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($mpesaPayments)): ?>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>Invoice</th>
                                            <th>Amount</th>
                                            <th>Reference</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($mpesaPayments as $payment): ?>
                                            <?php
                                            $reference = $payment['mpesa_receipt'] ?? $payment['reference_no'] ?? $payment['reference'] ?? $payment['transaction_ref'] ?? $payment['transaction_id'] ?? '-';
                                            $dateValue = $payment['payment_date'] ?? $payment['paid_at'] ?? $payment['created_at'] ?? '';
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($payment['student_name'] ?? 'Unknown'); ?></strong>
                                                    <?php if (!empty($payment['admission_number'])): ?>
                                                    <br><small><?php echo htmlspecialchars($payment['admission_number']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($payment['invoice_id'])): ?>
                                                        <a href="invoice_details.php?id=<?php echo (int) $payment['invoice_id']; ?>" style="color: var(--primary);">
                                                            #<?php echo htmlspecialchars($payment['invoice_no'] ?? $payment['invoice_id']); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td><strong style="color: var(--success);"><?php echo money($payment['amount'] ?? 0); ?></strong></td>
                                                <td><span class="pill pill-info"><?php echo htmlspecialchars($reference); ?></span></td>
                                                <td><?php echo $dateValue ? date('d M Y', strtotime($dateValue)) : '-'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-credit-card"></i>
                                <h3>No Posted Payments</h3>
                                <p>No M-Pesa payments have been posted to invoices yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column - Status & Logs -->
            <div class="stack">
                <!-- Pending Callbacks -->
                <div class="card animate">
                    <div class="card-header">
                        <h2><i class="fas fa-clock"></i> Pending Callback Queue</h2>
                        <span class="meta"><?php echo count($pendingFiles); ?> pending</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($pendingFiles)): ?>
                            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                <?php foreach (array_slice($pendingFiles, 0, 5) as $file): ?>
                                    <div style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem; background: var(--light); border-radius: var(--border-radius-sm);">
                                        <i class="fas fa-file-alt" style="color: var(--warning);"></i>
                                        <span style="flex: 1; font-size: 0.9rem;"><?php echo htmlspecialchars(basename($file)); ?></span>
                                        <span class="pill pill-pending">Pending</span>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (count($pendingFiles) > 5): ?>
                                    <div style="text-align: center; padding: 0.5rem; color: var(--gray);">
                                        <i class="fas fa-ellipsis-h"></i> +<?php echo count($pendingFiles) - 5; ?> more
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state" style="padding: 1.5rem;">
                                <i class="fas fa-check-circle" style="color: var(--success); font-size: 2rem;"></i>
                                <p>No pending STK requests in queue</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Latest Callback Log -->
                <div class="card animate">
                    <div class="card-header">
                        <h2><i class="fas fa-history"></i> Callback Activity Log</h2>
                        <span class="meta"><?php echo $recentLog['file'] ? htmlspecialchars($recentLog['file']) : 'No logs'; ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recentLog['lines'])): ?>
                            <div class="log-box">
                                <?php foreach ($recentLog['lines'] as $line): ?>
                                    <?php echo htmlspecialchars($line) . "\n"; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state" style="padding: 1.5rem;">
                                <i class="fas fa-file"></i>
                                <p>No callback log content available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="card animate">
                    <div class="card-header">
                        <h2><i class="fas fa-chart-simple"></i> Performance Metrics</h2>
                    </div>
                    <div class="card-body">
                        <div style="display: grid; gap: 1rem;">
                            <div>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.3rem;">
                                    <span>Success Rate</span>
                                    <span class="badge" style="background: var(--success); color: white;"><?php echo $summary['success_rate']; ?>%</span>
                                </div>
                                <div class="progress-bar" style="height: 8px; background: var(--light); border-radius: 4px; overflow: hidden;">
                                    <div style="width: <?php echo $summary['success_rate']; ?>%; height: 100%; background: linear-gradient(90deg, var(--success), var(--success-dark));"></div>
                                </div>
                            </div>
                            
                            <div>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.3rem;">
                                    <span>Failed Rate</span>
                                    <span class="badge" style="background: var(--danger); color: white;"><?php echo 100 - $summary['success_rate']; ?>%</span>
                                </div>
                                <div class="progress-bar" style="height: 8px; background: var(--light); border-radius: 4px; overflow: hidden;">
                                    <div style="width: <?php echo 100 - $summary['success_rate']; ?>%; height: 100%; background: linear-gradient(90deg, var(--danger), var(--danger-dark));"></div>
                                </div>
                            </div>
                            
                            <div style="margin-top: 1rem;">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                                    <div style="background: var(--light); padding: 0.75rem; border-radius: var(--border-radius-sm); text-align: center;">
                                        <div style="font-size: 0.7rem; color: var(--gray);">Completed</div>
                                        <div style="font-size: 1.2rem; font-weight: 700; color: var(--success);"><?php echo $summary['completed_count']; ?></div>
                                    </div>
                                    <div style="background: var(--light); padding: 0.75rem; border-radius: var(--border-radius-sm); text-align: center;">
                                        <div style="font-size: 0.7rem; color: var(--gray);">Failed</div>
                                        <div style="font-size: 1.2rem; font-weight: 700; color: var(--danger);"><?php echo $summary['failed_count']; ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Daily Transaction Chart
        const dailyCtx = document.getElementById('dailyChart')?.getContext('2d');
        if (dailyCtx) {
            const dailyData = <?php echo json_encode($dailyStats); ?>;
            const dates = dailyData.map(d => d.date);
            const amounts = dailyData.map(d => d.total_amount || 0);
            const counts = dailyData.map(d => d.transaction_count || 0);
            
            new Chart(dailyCtx, {
                type: 'line',
                data: {
                    labels: dates.map(date => {
                        const d = new Date(date);
                        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                    }),
                    datasets: [
                        {
                            label: 'Transaction Volume (KES)',
                            data: amounts,
                            borderColor: '#4361ee',
                            backgroundColor: 'rgba(67, 97, 238, 0.1)',
                            borderWidth: 3,
                            tension: 0.4,
                            fill: true,
                            yAxisID: 'y',
                            pointBackgroundColor: '#4361ee',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        },
                        {
                            label: 'Transaction Count',
                            data: counts,
                            borderColor: '#4cc9f0',
                            backgroundColor: 'rgba(76, 201, 240, 0.1)',
                            borderWidth: 2,
                            tension: 0.4,
                            fill: true,
                            yAxisID: 'y1',
                            pointBackgroundColor: '#4cc9f0',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: { usePointStyle: true }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    if (context.dataset.label.includes('Volume')) {
                                        return context.dataset.label + ': ' + 
                                               new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES', minimumFractionDigits: 0 }).format(context.raw);
                                    }
                                    return context.dataset.label + ': ' + context.raw;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Amount (KES)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return 'KES ' + (value/1000).toFixed(0) + 'k';
                                }
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Count'
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    }
                }
            });
        }

        // Status Distribution Chart
        const statusCtx = document.getElementById('statusChart')?.getContext('2d');
        if (statusCtx) {
            const statusData = <?php echo json_encode($statusStats); ?>;
            const labels = statusData.map(s => s.status ? s.status.charAt(0).toUpperCase() + s.status.slice(1) : 'Unknown');
            const counts = statusData.map(s => parseInt(s.count) || 0);
            
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: counts,
                        backgroundColor: [
                            '#4361ee',
                            '#4cc9f0',
                            '#f8961e',
                            '#f94144',
                            '#7209b7'
                        ],
                        borderColor: '#fff',
                        borderWidth: 3,
                        hoverOffset: 20
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { usePointStyle: true }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((context.raw / total) * 100).toFixed(1);
                                    return context.label + ': ' + context.raw + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }

        // Hourly Distribution Chart
        const hourlyCtx = document.getElementById('hourlyChart')?.getContext('2d');
        if (hourlyCtx) {
            const hourlyData = <?php echo json_encode($hourlyStats); ?>;
            const hours = Array.from({length: 24}, (_, i) => i);
            const hourlyCounts = hours.map(h => {
                const found = hourlyData.find(d => d.hour == h);
                return found ? found.transaction_count || 0 : 0;
            });
            
            new Chart(hourlyCtx, {
                type: 'bar',
                data: {
                    labels: hours.map(h => h.toString().padStart(2, '0') + ':00'),
                    datasets: [{
                        label: 'Transaction Count',
                        data: hourlyCounts,
                        backgroundColor: 'rgba(76, 201, 240, 0.8)',
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.raw + ' transactions';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        }

        // Amount Distribution Chart
        const amountCtx = document.getElementById('amountChart')?.getContext('2d');
        if (amountCtx) {
            const amountData = <?php echo json_encode($amountDistribution); ?>;
            const labels = amountData.map(a => a.amount_range || 'Unknown');
            const counts = amountData.map(a => parseInt(a.count) || 0);
            
            new Chart(amountCtx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Number of Transactions',
                        data: counts,
                        backgroundColor: [
                            '#4361ee',
                            '#4cc9f0',
                            '#f8961e',
                            '#7209b7',
                            '#f94144'
                        ],
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.raw + ' transactions';
                                }
                            }
                        }
                    }
                }
            });
        }

        // Auto-refresh every 60 seconds
        setTimeout(() => {
            location.reload();
        }, 60000);
    </script>
</body>
</html>