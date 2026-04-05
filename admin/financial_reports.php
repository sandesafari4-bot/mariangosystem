<?php
include '../config.php';
checkAuth();
checkRole(['admin', 'accountant']);

function getTableColumnsForFinancialReports(string $table): array {
    global $pdo;

    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $cache[$table] = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $cache[$table] = [];
    }

    return $cache[$table];
}

function financialReportHasColumn(string $table, string $column): bool {
    return in_array($column, getTableColumnsForFinancialReports($table), true);
}

function financialPaymentDateExpression(): string {
    if (financialReportHasColumn('payments', 'payment_date')) {
        return 'p.payment_date';
    }
    if (financialReportHasColumn('payments', 'paid_at')) {
        return 'p.paid_at';
    }
    return 'p.created_at';
}

function financialPaymentMethodExpression(): string {
    if (financialReportHasColumn('payment_methods', 'label') && financialReportHasColumn('payments', 'payment_method_id')) {
        return 'COALESCE(pm.label, pm.code, "Other")';
    }
    if (financialReportHasColumn('payments', 'payment_method')) {
        return 'COALESCE(p.payment_method, "Other")';
    }
    return '"Other"';
}

function financialPaymentReferenceExpression(): string {
    if (financialReportHasColumn('payments', 'transaction_ref')) {
        return 'COALESCE(p.transaction_ref, p.id)';
    }
    if (financialReportHasColumn('payments', 'reference')) {
        return 'COALESCE(p.reference, p.id)';
    }
    if (financialReportHasColumn('payments', 'reference_no')) {
        return 'COALESCE(p.reference_no, p.id)';
    }
    return 'p.id';
}

function financialSuccessfulPaymentCondition(string $alias = ''): string {
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    if (!financialReportHasColumn('payments', 'status')) {
        return '1=1';
    }

    return "LOWER(COALESCE({$prefix}status, '')) IN ('verified', 'completed', 'processed', 'paid')";
}

// Handle Export
if (isset($_GET['export_action'])) {
    $action = $_GET['export_action'];
    $report_type = $_GET['report_type'] ?? 'revenue';
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-t');
    $month = $_GET['month'] ?? date('Y-m');
    
    if ($action === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=financial_' . $report_type . '_' . date('Y-m-d_His') . '.csv');
        
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
        
        if ($report_type === 'revenue') {
            fputcsv($out, ['Date', 'Source', 'Category', 'Description', 'Amount (KES)', 'Payment Method', 'Reference', 'Status']);
            $data = getRevenueData($start_date, $end_date);
            foreach ($data as $row) {
                fputcsv($out, [
                    $row['transaction_date'],
                    $row['source'],
                    $row['category'],
                    $row['description'],
                    $row['amount'],
                    $row['payment_method'],
                    $row['reference'],
                    $row['status']
                ]);
            }
        } elseif ($report_type === 'expenses') {
            fputcsv($out, ['Date', 'Category', 'Description', 'Amount (KES)', 'Vendor', 'Status', 'Approved By', 'Reference']);
            $data = getExpensesData($start_date, $end_date);
            foreach ($data as $row) {
                fputcsv($out, [
                    $row['transaction_date'],
                    $row['category'],
                    $row['description'],
                    $row['amount'],
                    $row['vendor'],
                    $row['status'],
                    $row['approved_by'],
                    $row['reference']
                ]);
            }
        }
        
        fclose($out);
        exit();
    }
}

// Functions to fetch financial data
function getRevenueData($start_date, $end_date) {
    global $pdo;

    $paymentDateExpression = financialPaymentDateExpression();
    $paymentMethodExpression = financialPaymentMethodExpression();
    $paymentReferenceExpression = financialPaymentReferenceExpression();
    $paymentStatusCondition = financialSuccessfulPaymentCondition('p');

    $query = "
        SELECT 
            {$paymentDateExpression} as transaction_date,
            'Fees Payment' as source,
            'Student Fees' as category,
            CONCAT('Payment from ', COALESCE(s.full_name, 'N/A')) as description,
            COALESCE(p.amount, 0) as amount,
            {$paymentMethodExpression} as payment_method,
            {$paymentReferenceExpression} as reference,
            p.status
        FROM payments p
        LEFT JOIN students s ON p.student_id = s.id
        " . (financialReportHasColumn('payments', 'payment_method_id') ? "LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id" : "") . "
        WHERE DATE({$paymentDateExpression}) BETWEEN ? AND ?
          AND {$paymentStatusCondition}
        ORDER BY {$paymentDateExpression} DESC
    ";
    
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute([$start_date, $end_date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Error fetching revenue data: ' . $e->getMessage());
        return [];
    }
}

function getExpensesData($start_date, $end_date) {
    global $pdo;
    
    try {
        $query = "
            SELECT 
                DATE(e.expense_date) as transaction_date,
                COALESCE(ec.name, 'General') as category,
                COALESCE(e.description, 'N/A') as description,
                COALESCE(e.amount, 0) as amount,
                COALESCE(e.reference_number, 'N/A') as vendor,
                COALESCE(e.status, 'Pending') as status,
                COALESCE(u.full_name, 'N/A') as approved_by,
                COALESCE(e.id, 'N/A') as reference
            FROM expenses e
            LEFT JOIN expense_categories ec ON e.category_id = ec.id
            LEFT JOIN users u ON e.approved_by = u.id
            WHERE DATE(e.expense_date) BETWEEN ? AND ?
            ORDER BY e.expense_date DESC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$start_date, $end_date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Error fetching expenses data: ' . $e->getMessage());
        return [];
    }
}

function getFinancialSummary($start_date, $end_date) {
    global $pdo;
    
    try {
        // Total Revenue
        $revenue = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM payments
            WHERE DATE(" . str_replace('p.', '', financialPaymentDateExpression()) . ") BETWEEN ? AND ?
              AND " . financialSuccessfulPaymentCondition() . "
        ");
        $revenue->execute([$start_date, $end_date]);
        $total_revenue = $revenue->fetchColumn() ?? 0;
        
        // Total Expenses
        $expenses = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM expenses
            WHERE DATE(expense_date) BETWEEN ? AND ? AND status IN ('approved', 'paid')
        ");
        $expenses->execute([$start_date, $end_date]);
        $total_expenses = $expenses->fetchColumn() ?? 0;
        
        // Pending Expenses
        $pending_exp = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM expenses
            WHERE DATE(expense_date) BETWEEN ? AND ? AND status IN ('pending', 'approved')
        ");
        $pending_exp->execute([$start_date, $end_date]);
        $pending_expenses = $pending_exp->fetchColumn() ?? 0;
        
        // Pending Invoices
        $pending_inv = $pdo->prepare("
            SELECT COALESCE(SUM(GREATEST(COALESCE(balance, total_amount - COALESCE(amount_paid, 0)), 0)), 0) as total
            FROM invoices
            WHERE DATE(created_at) BETWEEN ? AND ?
        ");
        $pending_inv->execute([$start_date, $end_date]);
        $pending_invoices = $pending_inv->fetchColumn() ?? 0;
    } catch (PDOException $e) {
        error_log('Error calculating financial summary: ' . $e->getMessage());
        // Return safe defaults if query fails
        return [
            'total_revenue' => 0,
            'total_income' => 0,
            'total_income_all' => 0,
            'total_expenses' => 0,
            'pending_expenses' => 0,
            'pending_invoices' => 0,
            'net_balance' => 0,
            'net_status' => 'positive'
        ];
    }
    
    $total_income_all = $total_revenue;
    $net_balance = $total_income_all - $total_expenses;
    
    return [
        'total_revenue' => $total_revenue,
        'total_income' => 0,
        'total_income_all' => $total_income_all,
        'total_expenses' => $total_expenses,
        'pending_expenses' => $pending_expenses,
        'pending_invoices' => $pending_invoices,
        'net_balance' => $net_balance,
        'net_status' => $net_balance >= 0 ? 'positive' : 'negative'
    ];
}

function getExpensesByCategory($start_date, $end_date) {
    global $pdo;
    
    try {
        $query = "
            SELECT 
                COALESCE(ec.name, 'Other') as category,
                COUNT(*) as count,
                COALESCE(SUM(e.amount), 0) as total
            FROM expenses e
            LEFT JOIN expense_categories ec ON e.category_id = ec.id
            WHERE DATE(e.expense_date) BETWEEN ? AND ? AND e.status IN ('approved', 'paid')
            GROUP BY e.category_id, ec.name
            ORDER BY total DESC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$start_date, $end_date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Error fetching expenses by category: ' . $e->getMessage());
        return [];
    }
}

function getRevenueByMethod($start_date, $end_date) {
    global $pdo;

    try {
        $paymentDateExpression = str_replace('p.', '', financialPaymentDateExpression());
        $paymentMethodExpression = str_replace('p.', '', financialPaymentMethodExpression());
        $query = "
            SELECT 
                {$paymentMethodExpression} as method,
                COUNT(*) as count,
                COALESCE(SUM(amount), 0) as total
            FROM payments p
            " . (financialReportHasColumn('payments', 'payment_method_id') ? "LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id" : "") . "
            WHERE DATE({$paymentDateExpression}) BETWEEN ? AND ?
              AND " . financialSuccessfulPaymentCondition('p') . "
            GROUP BY method
            ORDER BY total DESC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$start_date, $end_date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Error fetching revenue by method: ' . $e->getMessage());
        return [];
    }
}

function getMonthlyTrend($year = null) {
    global $pdo;
    
    $year = $year ?? date('Y');
    
    try {
        $query = "
            SELECT 
                MONTH(created_at) as month,
                COALESCE(SUM(CASE WHEN status IN ('completed', 'processed') THEN amount ELSE 0 END), 0) as revenue,
                0 as expenses
            FROM payments
            WHERE YEAR(created_at) = ?
            GROUP BY MONTH(created_at)
            UNION ALL
            SELECT 
                MONTH(expense_date) as month,
                0 as revenue,
                COALESCE(SUM(CASE WHEN status IN ('approved', 'paid') THEN amount ELSE 0 END), 0) as expenses
            FROM expenses
            WHERE YEAR(expense_date) = ?
            GROUP BY MONTH(expense_date)
            ORDER BY month ASC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$year, $year]);
        
        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $month = $row['month'];
            if (!isset($result[$month])) {
                $result[$month] = ['revenue' => 0, 'expenses' => 0];
            }
            $result[$month]['revenue'] += $row['revenue'];
            $result[$month]['expenses'] += $row['expenses'];
        }
        
        return $result;
    } catch (PDOException $e) {
        error_log('Error fetching monthly trend: ' . $e->getMessage());
        return [];
    }
}

// Get filter parameters
$report_type = $_GET['report_type'] ?? 'revenue';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$category_filter = $_GET['category'] ?? '';
$payment_method_filter = $_GET['payment_method'] ?? '';

// Calculate date range label
$date_label = ($start_date === date('Y-m-01') && $end_date === date('Y-m-t')) 
    ? 'This Month'
    : ($start_date === date('Y-01-01') && $end_date === date('Y-12-31') ? 'This Year' : 'Custom Range');

// Get all data
$summary = getFinancialSummary($start_date, $end_date);
$monthly_trend = getMonthlyTrend();
$expense_categories = getExpensesByCategory($start_date, $end_date);
$revenue_methods = getRevenueByMethod($start_date, $end_date);

// Get detailed data for current report type
$report_data = [];
if ($report_type === 'revenue') {
    $report_data = getRevenueData($start_date, $end_date);
} elseif ($report_type === 'expenses') {
    $report_data = getExpensesData($start_date, $end_date);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Reports | <?php echo htmlspecialchars(SCHOOL_NAME); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.min.css">
    <style>
        :root {
            --primary: #667eea;
            --success: #27ae60;
            --danger: #e74c3c;
            --warning: #f39c12;
            --info: #3498db;
            --light: #ecf0f1;
            --dark: #2c3e50;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary) 0%, #5568d3 100%);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 0.8rem 0;
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.3rem;
        }

        .page-header {
            background: linear-gradient(135deg, var(--primary) 0%, #5568d3 100%);
            color: white;
            padding: 2.5rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .page-header h1 {
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .breadcrumb {
            background: transparent;
            padding: 0;
            margin-bottom: 0;
        }

        .breadcrumb-item.active {
            color: rgba(255,255,255,0.7);
        }

        .breadcrumb a {
            color: white;
        }

        .filter-section {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-left: 4px solid var(--primary);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }

        .stat-card.success {
            border-left-color: var(--success);
        }

        .stat-card.danger {
            border-left-color: var(--danger);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
        }

        .stat-label {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .stat-trend {
            font-size: 0.85rem;
            color: #999;
        }

        .stat-trend.positive {
            color: var(--success);
        }

        .stat-trend.negative {
            color: var(--danger);
        }

        .chart-container {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            position: relative;
            height: 400px;
        }

        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .report-table {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .report-table table {
            margin-bottom: 0;
        }

        .report-table thead {
            background-color: #f8f9fa;
            border-bottom: 2px solid var(--light);
        }

        .report-table thead th {
            color: var(--dark);
            font-weight: 600;
            padding: 1rem;
            border: none;
            font-size: 0.9rem;
        }

        .report-table tbody td {
            padding: 1rem;
            border-color: var(--light);
            vertical-align: middle;
        }

        .report-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .badge-status {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .badge-completed {
            background-color: #d4edda;
            color: #155724;
        }

        .badge-pending {
            background-color: #fff3cd;
            color: #856404;
        }

        .badge-approved {
            background-color: #d4edda;
            color: #155724;
        }

        .amount {
            font-weight: 600;
            color: var(--dark);
        }

        .amount.positive {
            color: var(--success);
        }

        .amount.negative {
            color: var(--danger);
        }

        .tab-nav {
            background: white;
            border-radius: 8px 8px 0 0;
            padding: 0;
            margin-bottom: 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .nav-link {
            color: #666;
            border: none;
            padding: 1rem 1.5rem;
            font-weight: 500;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }

        .nav-link:hover {
            color: var(--primary);
            background-color: #f8f9fa;
        }

        .nav-link.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background-color: #f8f9fa;
        }

        .export-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-export {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            border-radius: 6px;
            transition: all 0.3s;
        }

        .no-data {
            text-align: center;
            padding: 3rem 1rem;
            color: #999;
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        @media (max-width: 768px) {
            .stat-card {
                margin-bottom: 1rem;
            }

            .chart-container {
                height: 300px;
            }

            .export-buttons {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-dark">
        <div class="container-fluid">
            <span class="navbar-brand">
                <i class="fas fa-chart-line"></i> Financial Reports
            </span>
            <div class="d-flex align-items-center">
                <span class="text-white me-3"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></span>
                <a href="../logout.php" class="btn btn-light btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Page Header -->
    <div class="page-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col">
                    <h1><i class="fas fa-file-invoice-dollar me-2"></i>Financial Reports</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a></li>
                            <li class="breadcrumb-item active">Financial Reports</li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-calendar me-2"></i>Start Date</label>
                    <input type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-calendar me-2"></i>End Date</label>
                    <input type="date" class="form-control" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label"><i class="fas fa-filter me-2"></i>Report Type</label>
                    <select class="form-select" name="report_type" onchange="this.form.submit()">
                        <option value="revenue" <?php echo $report_type === 'revenue' ? 'selected' : ''; ?>>Revenue</option>
                        <option value="expenses" <?php echo $report_type === 'expenses' ? 'selected' : ''; ?>>Expenses</option>
                        <option value="summary" <?php echo $report_type === 'summary' ? 'selected' : ''; ?>>Summary</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end gap-2">
                    <button class="btn btn-primary flex-grow-1">
                        <i class="fas fa-filter me-2"></i>Filter
                    </button>
                    <a href="?report_type=revenue" class="btn btn-secondary">
                        <i class="fas fa-redo me-2"></i>Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Summary Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card success">
                    <div class="stat-label"><i class="fas fa-arrow-up me-2"></i>Total Income</div>
                    <div class="stat-value">KES <?php echo number_format($summary['total_income_all'], 2); ?></div>
                    <div class="stat-trend positive"><i class="fas fa-arrow-up me-1"></i><?php echo $date_label; ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card danger">
                    <div class="stat-label"><i class="fas fa-arrow-down me-2"></i>Total Expenses</div>
                    <div class="stat-value">KES <?php echo number_format($summary['total_expenses'], 2); ?></div>
                    <div class="stat-trend"><?php echo count($expense_categories); ?> categories</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card <?php echo $summary['net_status'] === 'positive' ? 'success' : 'danger'; ?>">
                    <div class="stat-label"><i class="fas fa-balance-scale me-2"></i>Net Balance</div>
                    <div class="stat-value <?php echo $summary['net_status'] === 'positive' ? 'positive' : 'negative'; ?>">
                        KES <?php echo number_format(abs($summary['net_balance']), 2); ?>
                    </div>
                    <div class="stat-trend <?php echo $summary['net_status']; ?>">
                        <i class="fas fa-<?php echo $summary['net_status'] === 'positive' ? 'arrow-up' : 'arrow-down'; ?> me-1"></i>
                        <?php echo $summary['net_status'] === 'positive' ? 'Surplus' : 'Deficit'; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="border-left-color: var(--warning);">
                    <div class="stat-label"><i class="fas fa-hourglass-end me-2"></i>Pending Items</div>
                    <div class="stat-value">KES <?php echo number_format($summary['pending_expenses'] + $summary['pending_invoices'], 2); ?></div>
                    <div class="stat-trend">
                        <small><?php echo $summary['pending_expenses'] > 0 ? $summary['pending_expenses'] . ' expenses, ' : ''; ?><?php echo $summary['pending_invoices'] > 0 ? $summary['pending_invoices'] . ' invoices' : ''; ?></small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="chart-container">
                    <div class="chart-title"><i class="fas fa-chart-pie me-2"></i>Expenses by Category</div>
                    <canvas id="expensesChart"></canvas>
                </div>
            </div>
            <div class="col-md-6">
                <div class="chart-container">
                    <div class="chart-title"><i class="fas fa-chart-pie me-2"></i>Revenue by Method</div>
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12">
                <div class="chart-container">
                    <div class="chart-title"><i class="fas fa-chart-line me-2"></i>Monthly Trend (<?php echo date('Y'); ?>)</div>
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Detailed Report Table -->
        <div class="report-table">
            <ul class="nav tab-nav">
                <li class="nav-item">
                    <a class="nav-link <?php echo $report_type === 'revenue' ? 'active' : ''; ?>" href="?report_type=revenue&start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>">
                        <i class="fas fa-arrow-up me-2"></i>Revenue Details
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $report_type === 'expenses' ? 'active' : ''; ?>" href="?report_type=expenses&start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>">
                        <i class="fas fa-arrow-down me-2"></i>Expenses Details
                    </a>
                </li>
                <li class="nav-item ms-auto">
                    <div class="export-buttons mt-2 mt-md-0">
                        <a href="?export_action=csv&report_type=<?php echo htmlspecialchars($report_type); ?>&start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>" class="btn btn-export btn-outline-primary btn-sm">
                            <i class="fas fa-download me-1"></i>Export CSV
                        </a>
                    </div>
                </li>
            </ul>

            <?php if (empty($report_data)): ?>
                <div class="no-data">
                    <i class="fas fa-inbox"></i>
                    <p><strong>No data available</strong></p>
                    <small>Try adjusting your filter dates or report type</small>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <?php if ($report_type === 'revenue'): ?>
                                <tr>
                                    <th>Date</th>
                                    <th>Source</th>
                                    <th>Category</th>
                                    <th>Description</th>
                                    <th class="text-end">Amount</th>
                                    <th>Method</th>
                                    <th>Reference</th>
                                    <th>Status</th>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <th>Date</th>
                                    <th>Category</th>
                                    <th>Description</th>
                                    <th class="text-end">Amount</th>
                                    <th>Vendor</th>
                                    <th>Status</th>
                                    <th>Approved By</th>
                                    <th>Reference</th>
                                </tr>
                            <?php endif; ?>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data as $row): ?>
                                <tr>
                                    <?php if ($report_type === 'revenue'): ?>
                                        <td><?php echo date('M d, Y', strtotime($row['transaction_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($row['source']); ?></td>
                                        <td><?php echo htmlspecialchars($row['category']); ?></td>
                                        <td><?php echo htmlspecialchars($row['description']); ?></td>
                                        <td class="text-end amount positive">KES <?php echo number_format($row['amount'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($row['payment_method']); ?></td>
                                        <td><small><?php echo htmlspecialchars($row['reference']); ?></small></td>
                                        <td>
                                            <span class="badge-status badge-<?php echo strtolower($row['status']); ?>">
                                                <?php echo ucfirst(htmlspecialchars($row['status'])); ?>
                                            </span>
                                        </td>
                                    <?php else: ?>
                                        <td><?php echo htmlspecialchars($row['transaction_date']); ?></td>
                                        <td><?php echo htmlspecialchars($row['category'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($row['description'] ?? 'N/A'); ?></td>
                                        <td class="text-end amount negative">KES <?php echo number_format($row['amount'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($row['vendor'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="badge-status badge-<?php echo strtolower($row['status']); ?>">
                                                <?php echo ucfirst(htmlspecialchars($row['status'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['approved_by'] ?? 'Pending'); ?></td>
                                        <td><small><?php echo htmlspecialchars($row['reference'] ?? 'N/A'); ?></small></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.min.js"></script>
    <script>
        // Expenses by Category Chart
        const expenseCategoriesData = <?php echo json_encode($expense_categories); ?>;
        if (expenseCategoriesData.length > 0) {
            new Chart(document.getElementById('expensesChart'), {
                type: 'doughnut',
                data: {
                    labels: expenseCategoriesData.map(item => item.category),
                    datasets: [{
                        data: expenseCategoriesData.map(item => item.total),
                        backgroundColor: [
                            '#667eea', '#27ae60', '#e74c3c', '#f39c12', '#3498db',
                            '#9b59b6', '#1abc9c', '#e67e22', '#95a5a6', '#34495e'
                        ],
                        borderColor: '#fff',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }

        // Revenue by Method Chart
        const revenueMethodsData = <?php echo json_encode($revenue_methods); ?>;
        if (revenueMethodsData.length > 0) {
            new Chart(document.getElementById('revenueChart'), {
                type: 'doughnut',
                data: {
                    labels: revenueMethodsData.map(item => item.method),
                    datasets: [{
                        data: revenueMethodsData.map(item => item.total),
                        backgroundColor: [
                            '#27ae60', '#3498db', '#f39c12', '#e74c3c', '#9b59b6',
                            '#1abc9c', '#667eea', '#e67e22', '#95a5a6', '#34495e'
                        ],
                        borderColor: '#fff',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }

        // Monthly Trend Chart
        const monthlyTrendData = <?php echo json_encode($monthly_trend); ?>;
        const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        
        const labels = [];
        const revenueData = [];
        const expenseData = [];
        
        for (let month = 1; month <= 12; month++) {
            labels.push(monthNames[month - 1]);
            if (monthlyTrendData[month]) {
                revenueData.push(monthlyTrendData[month].revenue);
                expenseData.push(monthlyTrendData[month].expenses);
            } else {
                revenueData.push(0);
                expenseData.push(0);
            }
        }

        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Revenue',
                        data: revenueData,
                        borderColor: '#27ae60',
                        backgroundColor: 'rgba(39, 174, 96, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 5,
                        pointBackgroundColor: '#27ae60',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    },
                    {
                        label: 'Expenses',
                        data: expenseData,
                        borderColor: '#e74c3c',
                        backgroundColor: 'rgba(231, 76, 60, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 5,
                        pointBackgroundColor: '#e74c3c',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'KES ' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
