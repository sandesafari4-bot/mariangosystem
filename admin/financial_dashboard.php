<?php
include '../config.php';
checkAuth();
checkRole(['admin']);

$page_title = 'Financial Dashboard - ' . SCHOOL_NAME;

// Get current date info
$current_month = date('m');
$current_year = date('Y');
$today = date('Y-m-d');

// Overall Financial Summary
$financial_summary = $pdo->query("
    SELECT 
        (SELECT COALESCE(SUM(total_amount), 0) FROM invoices) as total_invoiced,
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'completed') as total_collected,
        (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE status = 'approved') as total_expenses,
        (SELECT COALESCE(SUM(balance), 0) FROM invoices WHERE status IN ('unpaid', 'partial')) as outstanding,
        (SELECT COUNT(*) FROM invoices WHERE status IN ('unpaid', 'partial')) as unpaid_invoices,
        (SELECT COUNT(*) FROM invoices WHERE status = 'overdue' OR (status IN ('unpaid', 'partial') AND due_date < CURDATE())) as overdue_invoices
")->fetch();

// Monthly comparison (current vs previous month)
$monthly_comparison = $pdo->query("
    SELECT 
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'completed' 
         AND MONTH(payment_date) = $current_month AND YEAR(payment_date) = $current_year) as current_month_collected,
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'completed' 
         AND MONTH(payment_date) = $current_month - 1 AND YEAR(payment_date) = $current_year) as prev_month_collected,
        (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE status = 'approved' 
         AND MONTH(expense_date) = $current_month AND YEAR(expense_date) = $current_year) as current_month_expenses,
        (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE status = 'approved' 
         AND MONTH(expense_date) = $current_month - 1 AND YEAR(expense_date) = $current_year) as prev_month_expenses
")->fetch();

// Yearly trend (last 12 months)
$yearly_trend = $pdo->query("
    SELECT 
        DATE_FORMAT(p.payment_date, '%Y-%m') as month,
        DATE_FORMAT(p.payment_date, '%b %Y') as month_name,
        COALESCE(SUM(p.amount), 0) as collected,
        COALESCE((SELECT SUM(amount) FROM expenses e WHERE e.status = 'approved' 
                  AND DATE_FORMAT(e.expense_date, '%Y-%m') = DATE_FORMAT(p.payment_date, '%Y-%m')), 0) as expenses
    FROM payments p
    WHERE p.status = 'completed' 
    AND p.payment_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
    ORDER BY month ASC
")->fetchAll();

// Fee collection by type
$fee_collection = $pdo->query("
    SELECT 
        COALESCE(NULLIF(fs.fee_type, ''), NULLIF(fs.name, ''), NULLIF(fs.structure_name, ''), 'Uncategorized') as fee_type,
        COUNT(DISTINCT p.id) as payment_count,
        COALESCE(SUM(p.amount), 0) as total_collected,
        COUNT(DISTINCT p.student_id) as student_count
    FROM payments p
    LEFT JOIN invoices i ON p.invoice_id = i.id
    LEFT JOIN fee_structures fs ON i.fee_structure_id = fs.id
    WHERE p.status = 'completed'
    AND p.payment_date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
    GROUP BY fee_type
    ORDER BY total_collected DESC
")->fetchAll();

// Top paying students
$top_students = $pdo->query("
    SELECT 
        s.id,
        s.full_name,
        s.admission_number,
        c.class_name,
        COUNT(p.id) as payment_count,
        COALESCE(SUM(p.amount), 0) as total_paid
    FROM payments p
    JOIN students s ON p.student_id = s.id
    JOIN classes c ON s.class_id = c.id
    WHERE p.status = 'completed'
    GROUP BY s.id, s.full_name, s.admission_number, c.class_name
    ORDER BY total_paid DESC
    LIMIT 10
")->fetchAll();

// Expense by category
$expense_categories = $pdo->query("
    SELECT 
        category_id as category,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as total,
        COALESCE(SUM(CASE WHEN MONTH(expense_date) = $current_month THEN amount ELSE 0 END), 0) as monthly_total
    FROM expenses
    WHERE status = 'approved'
    GROUP BY category
    ORDER BY total DESC
")->fetchAll();

// Daily collection for current month
$daily_collection = $pdo->query("
    SELECT 
        DAY(payment_date) as day,
        COALESCE(SUM(amount), 0) as total
    FROM payments
    WHERE status = 'completed' 
    AND MONTH(payment_date) = $current_month 
    AND YEAR(payment_date) = $current_year
    GROUP BY DAY(payment_date)
    ORDER BY day
")->fetchAll();

// Pending approvals count
$pending_approvals = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM expenses WHERE status = 'pending') as pending_expenses,
        (SELECT COUNT(*) FROM fee_structures WHERE status = 'pending') as pending_fees
")->fetch();

// Financial ratios and metrics
$total_assets = $financial_summary['total_collected'] - $financial_summary['total_expenses'];
$collection_rate = $financial_summary['total_invoiced'] > 0 
    ? ($financial_summary['total_collected'] / $financial_summary['total_invoiced']) * 100 
    : 0;
$expense_rate = $financial_summary['total_collected'] > 0 
    ? ($financial_summary['total_expenses'] / $financial_summary['total_collected']) * 100 
    : 0;

// Monthly growth
$monthly_growth = $monthly_comparison['prev_month_collected'] > 0 
    ? (($monthly_comparison['current_month_collected'] - $monthly_comparison['prev_month_collected']) / $monthly_comparison['prev_month_collected']) * 100 
    : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            background: var(--white);
            border-radius: var(--border-radius-xl);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: var(--gradient-1);
        }

        .page-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            background: var(--gradient-1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: var(--gray);
            font-size: 1rem;
        }

        .date-display {
            background: rgba(67, 97, 238, 0.1);
            padding: 1rem 2rem;
            border-radius: var(--border-radius-md);
            text-align: center;
        }

        .date-display .day {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            line-height: 1;
        }

        .date-display .month-year {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .kpi-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            transition: var(--transition);
        }

        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .kpi-card:hover::before {
            width: 6px;
        }

        .kpi-card.primary::before { background: var(--gradient-1); }
        .kpi-card.success::before { background: var(--gradient-3); }
        .kpi-card.warning::before { background: var(--gradient-5); }
        .kpi-card.danger::before { background: var(--gradient-2); }

        .kpi-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

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

        .kpi-card.primary .kpi-icon { background: var(--gradient-1); }
        .kpi-card.success .kpi-icon { background: var(--gradient-3); }
        .kpi-card.warning .kpi-icon { background: var(--gradient-5); }
        .kpi-card.danger .kpi-icon { background: var(--gradient-2); }

        .kpi-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            line-height: 1.2;
            margin-bottom: 0.25rem;
        }

        .kpi-label {
            font-size: 0.9rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .kpi-trend {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
            font-size: 0.85rem;
        }

        .trend-up { color: var(--success); }
        .trend-down { color: var(--danger); }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
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
            font-size: 1.2rem;
            color: var(--dark);
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-container {
            height: 300px;
            position: relative;
        }

        /* Metric Cards */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .metric-card {
            background: var(--white);
            border-radius: var(--border-radius-md);
            padding: 1rem;
            text-align: center;
            box-shadow: var(--shadow-sm);
        }

        .metric-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.25rem;
        }

        .metric-label {
            font-size: 0.8rem;
            color: var(--gray);
        }

        /* Tables Grid */
        .tables-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .table-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-md);
        }

        .table-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.1rem;
            font-weight: 600;
        }

        .table-header a {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.3rem 1rem;
            background: rgba(255,255,255,0.2);
            border-radius: 20px;
            transition: var(--transition);
        }

        .table-header a:hover {
            background: rgba(255,255,255,0.3);
        }

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

        /* Status Badges */
        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-success {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .status-warning {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        .status-danger {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        /* Pending Alerts */
        .pending-alerts {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .alert-card {
            flex: 1;
            min-width: 200px;
            padding: 1.2rem;
            border-radius: var(--border-radius-md);
            display: flex;
            align-items: center;
            gap: 1rem;
            color: white;
            box-shadow: var(--shadow-lg);
            transition: var(--transition);
        }

        .alert-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-xl);
        }

        .alert-card.warning {
            background: linear-gradient(135deg, var(--warning), #e07c1a);
        }

        .alert-card.danger {
            background: linear-gradient(135deg, var(--danger), #c0392b);
        }

        .alert-icon {
            font-size: 2rem;
        }

        .alert-content h4 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .alert-content p {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .tables-grid {
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
            
            .page-header {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
            
            .pending-alerts {
                flex-direction: column;
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

        .animate {
            animation: fadeInUp 0.6s ease-out;
        }

        .stagger-item {
            opacity: 0;
            animation: fadeInUp 0.5s ease-out forwards;
        }

        .stagger-item:nth-child(1) { animation-delay: 0.1s; }
        .stagger-item:nth-child(2) { animation-delay: 0.2s; }
        .stagger-item:nth-child(3) { animation-delay: 0.3s; }
        .stagger-item:nth-child(4) { animation-delay: 0.4s; }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header animate">
            <div>
                <h1><i class="fas fa-chart-line"></i> Financial Dashboard</h1>
                <p>Complete financial overview and analytics</p>
            </div>
            <div class="date-display">
                <div class="day"><?php echo date('d'); ?></div>
                <div class="month-year"><?php echo date('F Y'); ?></div>
            </div>
        </div>

        <!-- Pending Approvals Alerts -->
        <?php if ($pending_approvals['pending_expenses'] > 0 || $pending_approvals['pending_fees'] > 0): ?>
        <div class="pending-alerts animate">
            <?php if ($pending_approvals['pending_expenses'] > 0): ?>
            <div class="alert-card warning">
                <div class="alert-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="alert-content">
                    <h4><?php echo $pending_approvals['pending_expenses']; ?></h4>
                    <p>Pending Expense Approvals</p>
                    <a href="expense_approvals.php" style="color: white; text-decoration: underline; font-size: 0.85rem;">Review Now →</a>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($pending_approvals['pending_fees'] > 0): ?>
            <div class="alert-card danger">
                <div class="alert-icon">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="alert-content">
                    <h4><?php echo $pending_approvals['pending_fees']; ?></h4>
                    <p>Pending Fee Structures</p>
                    <a href="fee_structure_approvals.php" style="color: white; text-decoration: underline; font-size: 0.85rem;">Review Now →</a>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card primary stagger-item">
                <div class="kpi-header">
                    <div class="kpi-label">Total Revenue</div>
                    <div class="kpi-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
                <div class="kpi-value">KES <?php echo number_format($financial_summary['total_collected'], 2); ?></div>
                <div class="kpi-trend">
                    <span class="<?php echo $monthly_growth >= 0 ? 'trend-up' : 'trend-down'; ?>">
                        <i class="fas fa-arrow-<?php echo $monthly_growth >= 0 ? 'up' : 'down'; ?>"></i>
                        <?php echo abs($monthly_growth); ?>% vs last month
                    </span>
                </div>
            </div>

            <div class="kpi-card success stagger-item">
                <div class="kpi-header">
                    <div class="kpi-label">Total Expenses</div>
                    <div class="kpi-icon">
                        <i class="fas fa-receipt"></i>
                    </div>
                </div>
                <div class="kpi-value">KES <?php echo number_format($financial_summary['total_expenses'], 2); ?></div>
                <div class="kpi-trend">
                    <span><?php echo number_format($expense_rate, 1); ?>% of revenue</span>
                </div>
            </div>

            <div class="kpi-card warning stagger-item">
                <div class="kpi-header">
                    <div class="kpi-label">Outstanding</div>
                    <div class="kpi-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
                <div class="kpi-value">KES <?php echo number_format($financial_summary['outstanding'], 2); ?></div>
                <div class="kpi-trend">
                    <span><?php echo $financial_summary['unpaid_invoices']; ?> unpaid invoices</span>
                </div>
            </div>

            <div class="kpi-card danger stagger-item">
                <div class="kpi-header">
                    <div class="kpi-label">Overdue</div>
                    <div class="kpi-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
                <div class="kpi-value"><?php echo $financial_summary['overdue_invoices']; ?></div>
                <div class="kpi-trend">
                    <span>invoices overdue</span>
                </div>
            </div>
        </div>

        <!-- Financial Metrics -->
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-value"><?php echo number_format($collection_rate, 1); ?>%</div>
                <div class="metric-label">Collection Rate</div>
            </div>
            <div class="metric-card">
                <div class="metric-value">KES <?php echo number_format($total_assets, 2); ?></div>
                <div class="metric-label">Net Assets</div>
            </div>
            <div class="metric-card">
                <div class="metric-value">KES <?php echo number_format($monthly_comparison['current_month_collected'], 2); ?></div>
                <div class="metric-label">This Month Revenue</div>
            </div>
            <div class="metric-card">
                <div class="metric-value">KES <?php echo number_format($monthly_comparison['current_month_expenses'], 2); ?></div>
                <div class="metric-label">This Month Expenses</div>
            </div>
        </div>

        <!-- Charts Grid -->
        <div class="charts-grid">
            <!-- Revenue vs Expenses Trend -->
            <div class="chart-card animate">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-line"></i> Revenue vs Expenses Trend</h3>
                    <span class="badge">Last 12 months</span>
                </div>
                <div class="chart-container">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>

            <!-- Fee Collection by Type -->
            <div class="chart-card animate">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-pie"></i> Fee Collection by Type</h3>
                    <span class="badge">Last 3 months</span>
                </div>
                <div class="chart-container">
                    <canvas id="feeTypeChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Tables Grid -->
        <div class="tables-grid">
            <!-- Top Paying Students -->
            <div class="table-card animate">
                <div class="table-header">
                    <h3><i class="fas fa-trophy"></i> Top Paying Students</h3>
                    <a href="students.php">View All <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Class</th>
                                <th>Payments</th>
                                <th>Total Paid</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_students as $student): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($student['full_name']); ?></strong>
                                    <div style="font-size: 0.8rem; color: var(--gray);">
                                        <?php echo htmlspecialchars($student['admission_number']); ?>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($student['class_name']); ?></td>
                                <td><?php echo $student['payment_count']; ?></td>
                                <td><strong style="color: var(--success);">KES <?php echo number_format($student['total_paid'], 2); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Expense Categories -->
            <div class="table-card animate">
                <div class="table-header">
                    <h3><i class="fas fa-tags"></i> Expense Categories</h3>
                    <a href="expenses.php">View All <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Count</th>
                                <th>Total</th>
                                <th>This Month</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expense_categories as $category): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($category['category']); ?></strong></td>
                                <td><?php echo $category['count']; ?></td>
                                <td><strong style="color: var(--danger);">KES <?php echo number_format($category['total'], 2); ?></strong></td>
                                <td>KES <?php echo number_format($category['monthly_total'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Daily Collection for Current Month -->
        <div class="chart-card animate" style="margin-top: 1rem;">
            <div class="chart-header">
                <h3><i class="fas fa-calendar-alt"></i> Daily Collection - <?php echo date('F Y'); ?></h3>
                <span class="badge">Total: KES <?php echo number_format(array_sum(array_column($daily_collection, 'total')), 2); ?></span>
            </div>
            <div class="chart-container" style="height: 200px;">
                <canvas id="dailyChart"></canvas>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Revenue vs Expenses Trend Chart
        const trendCtx = document.getElementById('trendChart').getContext('2d');
        const months = <?php echo json_encode(array_column($yearly_trend, 'month_name')); ?>;
        const collected = <?php echo json_encode(array_column($yearly_trend, 'collected')); ?>;
        const expenses = <?php echo json_encode(array_column($yearly_trend, 'expenses')); ?>;

        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: months,
                datasets: [
                    {
                        label: 'Revenue',
                        data: collected,
                        borderColor: '#4cc9f0',
                        backgroundColor: 'rgba(76, 201, 240, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#4cc9f0',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7
                    },
                    {
                        label: 'Expenses',
                        data: expenses,
                        borderColor: '#f94144',
                        backgroundColor: 'rgba(249, 65, 68, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#f94144',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7
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
                                return context.dataset.label + ': KES ' + context.parsed.y.toLocaleString('en-KE', {maximumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'KES ' + (value/1000).toFixed(0) + 'k';
                            }
                        }
                    }
                }
            }
        });

        // Fee Type Chart
        const feeCtx = document.getElementById('feeTypeChart').getContext('2d');
        const feeLabels = <?php echo json_encode(array_column($fee_collection, 'fee_type')); ?>;
        const feeAmounts = <?php echo json_encode(array_column($fee_collection, 'total_collected')); ?>;

        new Chart(feeCtx, {
            type: 'doughnut',
            data: {
                labels: feeLabels,
                datasets: [{
                    data: feeAmounts,
                    backgroundColor: [
                        '#4361ee',
                        '#f8961e',
                        '#4cc9f0',
                        '#7209b7',
                        '#f94144'
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
                                return context.label + ': KES ' + context.raw.toLocaleString('en-KE', {maximumFractionDigits: 2}) + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });

        // Daily Collection Chart
        const dailyCtx = document.getElementById('dailyChart').getContext('2d');
        const days = Array.from({length: <?php echo date('t'); ?>}, (_, i) => i + 1);
        const dailyData = days.map(day => {
            const found = <?php echo json_encode($daily_collection); ?>.find(d => d.day == day);
            return found ? found.total : 0;
        });

        new Chart(dailyCtx, {
            type: 'bar',
            data: {
                labels: days,
                datasets: [{
                    label: 'Collection (KES)',
                    data: dailyData,
                    backgroundColor: '#4361ee',
                    borderRadius: 6
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
                                return 'KES ' + context.parsed.y.toLocaleString('en-KE', {maximumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'KES ' + (value/1000).toFixed(0) + 'k';
                            }
                        }
                    }
                }
            }
        });

        // Auto-refresh every 5 minutes
        setTimeout(() => {
            location.reload();
        }, 5 * 60 * 1000);
    </script>
</body>
</html>
