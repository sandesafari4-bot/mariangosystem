<?php
include '../config.php';
checkAuth();
checkRole(['accountant', 'admin']);

$page_title = 'Invoice Management - ' . SCHOOL_NAME;

$invoiceStatusExpression = "
    CASE
        WHEN GREATEST(COALESCE(i.balance, COALESCE(i.total_amount, 0) - COALESCE(i.amount_paid, 0)), 0) <= 0
            AND COALESCE(i.amount_paid, 0) > 0 THEN 'paid'
        WHEN COALESCE(i.amount_paid, 0) > 0 THEN 'partial'
        ELSE 'unpaid'
    END
";

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$class_filter = $_GET['class_id'] ?? '';

// Build main query with proper joins
$query = "
    SELECT 
        i.*, 
        s.full_name, 
        s.admission_number,
        s.class_id as student_class_id,
        c.class_name,
        {$invoiceStatusExpression} as computed_status,
        (SELECT COUNT(*) FROM payments WHERE invoice_id = i.id AND status = 'completed') as payment_count,
        (SELECT SUM(amount) FROM payments WHERE invoice_id = i.id AND status = 'completed') as actual_paid,
        DATEDIFF(CURDATE(), i.due_date) as days_overdue
    FROM invoices i
    JOIN students s ON i.student_id = s.id
    LEFT JOIN classes c ON i.class_id = c.id
    WHERE 1=1
";

$params = [];

if ($status_filter) {
    if ($status_filter === 'overdue') {
        $query .= " AND {$invoiceStatusExpression} IN ('unpaid', 'partial') AND i.due_date < CURDATE()";
    } else {
        $query .= " AND {$invoiceStatusExpression} = ?";
        $params[] = $status_filter;
    }
}

if ($search) {
    $query .= " AND (s.full_name LIKE ? OR i.invoice_no LIKE ? OR s.admission_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($date_from) {
    $query .= " AND DATE(i.created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $query .= " AND DATE(i.created_at) <= ?";
    $params[] = $date_to;
}

if ($class_filter) {
    $query .= " AND s.class_id = ?";
    $params[] = $class_filter;
}

$query .= " ORDER BY i.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// Get comprehensive statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_invoices,
        SUM(CASE WHEN {$invoiceStatusExpression} = 'paid' THEN 1 ELSE 0 END) as paid_count,
        SUM(CASE WHEN {$invoiceStatusExpression} = 'unpaid' THEN 1 ELSE 0 END) as unpaid_count,
        SUM(CASE WHEN {$invoiceStatusExpression} = 'partial' THEN 1 ELSE 0 END) as partial_count,
        SUM(CASE WHEN {$invoiceStatusExpression} IN ('unpaid', 'partial') AND i.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue_count,
        SUM(i.total_amount) as total_billed,
        SUM(i.amount_paid) as total_collected,
        SUM(i.balance) as total_outstanding,
        AVG(i.total_amount) as avg_invoice_amount
    FROM invoices i
";
$stats = $pdo->query($stats_query)->fetch();

// Get monthly invoice trend
$monthly_trend = $pdo->query("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        DATE_FORMAT(created_at, '%b %Y') as month_name,
        COUNT(*) as invoice_count,
        SUM(total_amount) as total_amount,
        SUM(amount_paid) as total_paid
    FROM invoices
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC
")->fetchAll();

// Get status distribution
$status_distribution = $pdo->query("
    SELECT 
        {$invoiceStatusExpression} as status,
        COUNT(*) as count,
        SUM(total_amount) as total
    FROM invoices i
    GROUP BY status
")->fetchAll();

// Get classes for filter
$classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll();

// Calculate collection rate
$collection_rate = $stats['total_billed'] > 0 
    ? ($stats['total_collected'] / $stats['total_billed']) * 100 
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
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

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
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
            display: flex;
            align-items: center;
            gap: 1.5rem;
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

        .kpi-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: white;
            flex-shrink: 0;
        }

        .kpi-card.primary .kpi-icon { background: var(--gradient-1); }
        .kpi-card.success .kpi-icon { background: var(--gradient-3); }
        .kpi-card.warning .kpi-icon { background: var(--gradient-5); }
        .kpi-card.danger .kpi-icon { background: var(--gradient-2); }

        .kpi-content {
            flex: 1;
        }

        .kpi-label {
            font-size: 0.85rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .kpi-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        /* Collection Rate Banner */
        .collection-rate {
            background: var(--gradient-1);
            color: white;
            padding: 1.5rem;
            border-radius: var(--border-radius-lg);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow-lg);
        }

        .rate-value {
            font-size: 3rem;
            font-weight: 700;
            line-height: 1;
        }

        .rate-label {
            font-size: 1rem;
            opacity: 0.9;
        }

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

        /* Filter Section */
        .filter-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
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

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-sm);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .form-group input:focus,
        .form-group select:focus {
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

        /* Data Table */
        .data-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header {
            padding: 1.5rem;
            background: var(--gradient-1);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.2rem;
            font-weight: 600;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            padding: 1.25rem 1rem;
            text-align: left;
            background: var(--light);
            color: var(--dark);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 1.25rem 1rem;
            border-bottom: 1px solid var(--light);
            color: var(--dark);
        }

        tr:hover {
            background: rgba(67, 97, 238, 0.05);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-paid {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .status-unpaid {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        .status-partial {
            background: rgba(114, 9, 183, 0.15);
            color: var(--purple);
        }

        .status-overdue {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        /* Progress Bar */
        .progress-bar {
            width: 100%;
            height: 6px;
            background: var(--light);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .progress-fill {
            height: 100%;
            background: var(--gradient-3);
            border-radius: 3px;
            transition: width 0.3s;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: var(--border-radius-sm);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: var(--transition);
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .action-btn.view { background: var(--primary); }
        .action-btn.pay { background: var(--success); }
        .action-btn.print { background: var(--purple); }

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

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
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
            
            .page-header {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
            
            .header-actions {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .collection-rate {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
        }
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
                <h1><i class="fas fa-file-invoice"></i> Invoice Management</h1>
                <p>Track and manage all student fee invoices</p>
            </div>
            <div class="header-actions">
                <a href="create_invoice.php" class="btn btn-success">
                    <i class="fas fa-plus"></i> New Invoice
                </a>
                <a href="generate_invoices.php" class="btn btn-primary">
                    <i class="fas fa-layer-group"></i> Bulk Generate
                </a>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card primary stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Total Invoices</div>
                    <div class="kpi-value"><?php echo number_format($stats['total_invoices'] ?? 0); ?></div>
                </div>
            </div>

            <div class="kpi-card success stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Paid</div>
                    <div class="kpi-value"><?php echo number_format($stats['paid_count'] ?? 0); ?></div>
                </div>
            </div>

            <div class="kpi-card warning stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Unpaid/Partial</div>
                    <div class="kpi-value"><?php echo number_format(($stats['unpaid_count'] ?? 0) + ($stats['partial_count'] ?? 0)); ?></div>
                </div>
            </div>

            <div class="kpi-card danger stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Overdue</div>
                    <div class="kpi-value"><?php echo number_format($stats['overdue_count'] ?? 0); ?></div>
                </div>
            </div>
        </div>

        <!-- Collection Rate Banner -->
        <div class="collection-rate animate">
            <div>
                <div class="rate-label">Collection Rate</div>
                <div class="rate-value"><?php echo number_format($collection_rate, 1); ?>%</div>
            </div>
            <div style="text-align: right;">
                <div class="rate-label">Total Collected</div>
                <div class="rate-value" style="font-size: 2rem;">KES <?php echo number_format($stats['total_collected'] ?? 0, 0); ?></div>
                <div class="rate-label">of KES <?php echo number_format($stats['total_billed'] ?? 0, 0); ?> billed</div>
            </div>
        </div>

        <!-- Charts Grid -->
        <div class="charts-grid">
            <!-- Monthly Trend Chart -->
            <div class="chart-card animate">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-line"></i> Monthly Invoice Trend</h3>
                </div>
                <div class="chart-container">
                    <canvas id="monthlyTrendChart"></canvas>
                </div>
            </div>

            <!-- Status Distribution Chart -->
            <div class="chart-card animate">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-pie"></i> Status Distribution</h3>
                </div>
                <div class="chart-container">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section animate">
            <form method="GET" id="filterForm">
                <div class="filter-grid">
                    <div class="form-group">
                        <label><i class="fas fa-search"></i> Search</label>
                        <input type="text" name="search" placeholder="Student name, invoice #, admission..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Status</label>
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="paid" <?php echo $status_filter == 'paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="unpaid" <?php echo $status_filter == 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                            <option value="partial" <?php echo $status_filter == 'partial' ? 'selected' : ''; ?>>Partial</option>
                            <option value="overdue" <?php echo $status_filter == 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-graduation-cap"></i> Class</label>
                        <select name="class_id">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar"></i> From Date</label>
                        <input type="date" name="date_from" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar"></i> To Date</label>
                        <input type="date" name="date_to" value="<?php echo $date_to; ?>">
                    </div>
                    <div class="form-group" style="display: flex; gap: 0.5rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <a href="invoices.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Invoices Table -->
        <div class="data-card animate">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Invoice List</h3>
                <span>Total: <?php echo count($invoices); ?> invoices | Outstanding: KES <?php echo number_format($stats['total_outstanding'] ?? 0, 2); ?></span>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Student</th>
                            <th>Class</th>
                            <th>Issue Date</th>
                            <th>Due Date</th>
                            <th>Amount</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Progress</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($invoices)): ?>
                            <?php foreach ($invoices as $invoice): 
                                $payment_percentage = $invoice['total_amount'] > 0 
                                    ? ($invoice['amount_paid'] / $invoice['total_amount']) * 100 
                                    : 0;
                                $computed_status = $invoice['computed_status'] ?? 'unpaid';
                                $is_overdue = $invoice['days_overdue'] > 0 && $computed_status != 'paid';
                                $status = $is_overdue ? 'overdue' : $computed_status;
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($invoice['invoice_no']); ?></strong>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($invoice['full_name']); ?>
                                    <div style="font-size: 0.8rem; color: var(--gray);">
                                        <?php echo htmlspecialchars($invoice['admission_number']); ?>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($invoice['class_name'] ?? 'N/A'); ?></td>
                                <td><?php echo date('d M Y', strtotime($invoice['created_at'])); ?></td>
                                <td>
                                    <?php echo date('d M Y', strtotime($invoice['due_date'])); ?>
                                    <?php if ($is_overdue): ?>
                                        <div style="font-size: 0.8rem; color: var(--danger);">
                                            <?php echo $invoice['days_overdue']; ?> days overdue
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><strong>KES <?php echo number_format($invoice['total_amount'], 2); ?></strong></td>
                                <td style="color: var(--success);">KES <?php echo number_format($invoice['amount_paid'], 2); ?></td>
                                <td style="color: <?php echo $invoice['balance'] > 0 ? 'var(--danger)' : 'var(--success)'; ?>; font-weight: 600;">
                                    KES <?php echo number_format($invoice['balance'], 2); ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $status; ?>">
                                        <?php echo ucfirst($status); ?>
                                    </span>
                                </td>
                                <td style="min-width: 100px;">
                                    <div style="font-size: 0.8rem; margin-bottom: 0.25rem;">
                                        <?php echo number_format($payment_percentage, 1); ?>% paid
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $payment_percentage; ?>%;"></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="invoice_details.php?id=<?php echo $invoice['id']; ?>" class="action-btn view" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if (($invoice['computed_status'] ?? 'unpaid') != 'paid'): ?>
                                            <a href="record_payment.php?invoice_id=<?php echo $invoice['id']; ?>" class="action-btn pay" title="Record Payment">
                                                <i class="fas fa-credit-card"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="#" onclick="printInvoice(<?php echo $invoice['id']; ?>)" class="action-btn print" title="Print Invoice">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" style="text-align: center; padding: 3rem;">
                                    <i class="fas fa-file-invoice fa-3x" style="color: var(--gray); margin-bottom: 1rem;"></i>
                                    <h4>No Invoices Found</h4>
                                    <p style="color: var(--gray);">Try adjusting your filters or create a new invoice</p>
                                    <a href="create_invoice.php" class="btn btn-primary" style="margin-top: 1rem;">
                                        <i class="fas fa-plus"></i> Create Invoice
                                    </a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Monthly Trend Chart
            const monthlyCtx = document.getElementById('monthlyTrendChart').getContext('2d');
            const monthLabels = <?php echo json_encode(array_column($monthly_trend, 'month_name')); ?>;
            const invoiceAmounts = <?php echo json_encode(array_map(function($m) { return (float)$m['total_amount']; }, $monthly_trend)); ?>;
            
            new Chart(monthlyCtx, {
                type: 'line',
                data: {
                    labels: monthLabels,
                    datasets: [{
                        label: 'Amount (KES)',
                        data: invoiceAmounts,
                        borderColor: '#4361ee',
                        backgroundColor: 'rgba(67, 97, 238, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true
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

            // Status Distribution Chart
            const statusCtx = document.getElementById('statusChart').getContext('2d');
            const statusLabels = <?php echo json_encode(array_column($status_distribution, 'status')); ?>;
            const statusCounts = <?php echo json_encode(array_column($status_distribution, 'count')); ?>;
            
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: statusLabels.map(s => s.charAt(0).toUpperCase() + s.slice(1)),
                    datasets: [{
                        data: statusCounts,
                        backgroundColor: [
                            '#4cc9f0',  // paid
                            '#f8961e',  // unpaid
                            '#7209b7',  // partial
                            '#f94144'   // overdue
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
        });

        // Print Invoice
        function printInvoice(invoiceId) {
            window.open('print_invoice.php?id=' + invoiceId, '_blank', 'width=800,height=600');
        }

        // Quick filter by status
        function filterByStatus(status) {
            const statusSelect = document.querySelector('select[name="status"]');
            if (statusSelect) {
                statusSelect.value = status;
                document.getElementById('filterForm').submit();
            }
        }

        // Reset Filters
        function resetFilters() {
            window.location.href = 'invoices.php';
        }
    </script>
</body>
</html>
