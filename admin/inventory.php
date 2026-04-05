<?php
include '../config.php';
require_once '../inventory_payment_helpers.php';
checkAuth();
checkRole(['admin']);

// Database schema management
function ensureInventoryApprovalColumns(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventory_items (
            id INT PRIMARY KEY AUTO_INCREMENT,
            item_code VARCHAR(50) UNIQUE NOT NULL,
            item_name VARCHAR(150) NOT NULL,
            category VARCHAR(100) NOT NULL,
            description TEXT,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            purchase_price DECIMAL(10,2) DEFAULT 0.00,
            quantity_in_stock INT DEFAULT 0,
            reserved_quantity INT DEFAULT 0,
            reorder_level INT DEFAULT 10,
            reorder_quantity INT DEFAULT 20,
            supplier_id INT NULL,
            supplier_name VARCHAR(150),
            location VARCHAR(100),
            expiry_date DATE NULL,
            last_restock_date TIMESTAMP NULL,
            status ENUM('active', 'inactive', 'discontinued') DEFAULT 'active',
            approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            requested_by INT NULL,
            approved_by INT NULL,
            approved_at DATETIME NULL,
            approval_notes TEXT NULL,
            payment_status ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
            payment_reference VARCHAR(120) NULL,
            payment_notes TEXT NULL,
            paid_by INT NULL,
            paid_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY category (category),
            KEY status (status),
            KEY approval_status (approval_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Add missing columns if they don't exist
    $additionalColumns = [
        'purchase_price' => "ALTER TABLE inventory_items ADD COLUMN purchase_price DECIMAL(10,2) DEFAULT 0.00 AFTER unit_price",
        'reserved_quantity' => "ALTER TABLE inventory_items ADD COLUMN reserved_quantity INT DEFAULT 0 AFTER quantity_in_stock",
        'supplier_name' => "ALTER TABLE inventory_items ADD COLUMN supplier_name VARCHAR(150) AFTER supplier_id",
        'location' => "ALTER TABLE inventory_items ADD COLUMN location VARCHAR(100) AFTER supplier_name",
        'expiry_date' => "ALTER TABLE inventory_items ADD COLUMN expiry_date DATE NULL AFTER location"
    ];

    foreach ($additionalColumns as $column => $sql) {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM inventory_items LIKE ?");
        $stmt->execute([$column]);
        if (!$stmt->fetch()) {
            $pdo->exec($sql);
        }
    }
}

ensureInventoryApprovalColumns($pdo);
ensureInventoryPaymentWorkflow($pdo);

// Create transaction history table if not exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS inventory_transactions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        item_id INT NOT NULL,
        transaction_type ENUM('purchase','sale','adjustment','return','damage','restock') NOT NULL,
        quantity INT NOT NULL,
        previous_quantity INT,
        new_quantity INT,
        unit_price DECIMAL(10,2),
        total_amount DECIMAL(10,2),
        reference_number VARCHAR(100),
        notes TEXT,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
        INDEX (item_id),
        INDEX (transaction_type),
        INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create categories table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS inventory_categories (
        id INT PRIMARY KEY AUTO_INCREMENT,
        category_name VARCHAR(100) UNIQUE NOT NULL,
        description TEXT,
        parent_category_id INT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (parent_category_id) REFERENCES inventory_categories(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$admin_id = $_SESSION['user_id'];

// Handle inventory actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['inventory_action'], $_POST['item_id'])) {
        handleInventoryAction($pdo, $admin_id);
    } elseif (isset($_POST['batch_action'])) {
        handleBatchAction($pdo, $admin_id);
    } elseif (isset($_POST['adjust_stock'])) {
        handleStockAdjustment($pdo, $admin_id);
    }
}

// Handle AJAX requests for charts and real-time data
if (isset($_GET['ajax']) && $_GET['ajax'] === 'true') {
    handleAjaxRequest($pdo);
    exit();
}

// Get filter parameters
$filters = [
    'approval_status' => $_GET['approval_status'] ?? '',
    'status' => $_GET['status'] ?? '',
    'category' => $_GET['category'] ?? '',
    'search' => $_GET['search'] ?? '',
    'payment_status' => $_GET['payment_status'] ?? '',
    'date_from' => $_GET['date_from'] ?? date('Y-m-01'),
    'date_to' => $_GET['date_to'] ?? date('Y-m-t'),
    'sort_by' => $_GET['sort_by'] ?? 'updated_at',
    'sort_order' => $_GET['sort_order'] ?? 'DESC'
];

// Get inventory items with filters
$inventory_items = getInventoryItems($pdo, $filters);

// Get comprehensive statistics
$stats = getInventoryStatistics($pdo, $filters);

// Get categories for filter
$categories = $pdo->query("SELECT DISTINCT category FROM inventory_items WHERE category IS NOT NULL ORDER BY category")->fetchAll();

// Get recent transactions
$recentTransactions = getRecentTransactions($pdo, 10);

// Get top moving items
$topItems = getTopMovingItems($pdo, 10);

// Calculate KPIs
$kpis = calculateKPIs($pdo, $stats);

$page_title = "Inventory Management System - " . SCHOOL_NAME;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0/dist/chartjs-plugin-datalabels.min.js"></script>
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --info: #3498db;
            --dark: #2c3e50;
            --light: #f8f9fa;
            --gray: #6c757d;
            --white: #ffffff;
            --gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --card-shadow: 0 10px 40px rgba(0,0,0,0.08);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #eef2f7 100%);
            color: var(--dark);
        }

        /* Main Layout */
        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            min-height: calc(100vh - 70px);
            transition: var(--transition);
        }

        /* Cards and Containers */
        .card {
            background: var(--white);
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            transition: var(--transition);
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 50px rgba(0,0,0,0.12);
        }

        .card-header {
            padding: 1.5rem;
            border-bottom: 2px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 600;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
            transition: var(--transition);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient);
        }

        .stat-card.success::before { background: var(--success); }
        .stat-card.warning::before { background: var(--warning); }
        .stat-card.danger::before { background: var(--danger); }
        .stat-card.info::before { background: var(--info); }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.875rem;
        }

        .stat-change {
            font-size: 0.75rem;
            margin-top: 0.5rem;
        }

        .stat-change.positive { color: var(--success); }
        .stat-change.negative { color: var(--danger); }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .chart-container {
            position: relative;
            height: 350px;
            width: 100%;
        }

        /* Filters Section */
        .filters-section {
            background: var(--white);
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .filter-group input,
        .filter-group select {
            padding: 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.25rem;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
        }

        .btn-primary {
            background: var(--gradient);
            color: white;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        /* Table Styles */
        .table-container {
            overflow-x: auto;
            background: var(--white);
            border-radius: 20px;
            box-shadow: var(--card-shadow);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 1rem;
            font-weight: 600;
            font-size: 0.875rem;
            text-align: left;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #f0f2f5;
            vertical-align: top;
        }

        tr:hover {
            background: #f8fafc;
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-approved { background: #d1ecf1; color: #0c5460; }
        .badge-rejected { background: #f8d7da; color: #721c24; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #d1ecf1; color: #0c5460; }

        /* Progress Bar */
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .progress-fill {
            height: 100%;
            background: var(--success);
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .progress-fill.warning { background: var(--warning); }
        .progress-fill.danger { background: var(--danger); }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 2px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 2px solid #f0f2f5;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filters-form {
                grid-template-columns: 1fr;
            }
        }

        /* Animations */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-in {
            animation: slideIn 0.5s ease forwards;
        }

        /* Tooltips */
        .tooltip {
            position: relative;
            cursor: help;
        }

        .tooltip:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: var(--dark);
            color: white;
            padding: 0.5rem;
            border-radius: 8px;
            font-size: 0.75rem;
            white-space: nowrap;
            z-index: 10;
        }
    </style>
</head>
<body>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>
    <?php include '../loader.php'; ?>

    <div class="main-content">
        <!-- Page Header -->
        <div class="card animate-in" style="margin-bottom: 2rem;">
            <div class="card-header">
                <div>
                    <h1><i class="fas fa-warehouse"></i> Inventory Management System</h1>
                    <p style="margin: 0.5rem 0 0; color: var(--gray);">Comprehensive inventory tracking, approvals, and analytics dashboard</p>
                </div>
                <div>
                    <button class="btn btn-primary" onclick="exportReport()">
                        <i class="fas fa-download"></i> Export Report
                    </button>
                    <button class="btn btn-outline" onclick="refreshData()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success animate-in">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($_SESSION['success']); ?>
        </div>
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-error animate-in">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($_SESSION['error']); ?>
        </div>
        <?php unset($_SESSION['error']); endif; ?>

        <!-- KPI Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon"><i class="fas fa-boxes"></i></div>
                    <div class="stat-change <?php echo $kpis['total_items_change'] >= 0 ? 'positive' : 'negative'; ?>">
                        <i class="fas fa-arrow-<?php echo $kpis['total_items_change'] >= 0 ? 'up' : 'down'; ?>"></i>
                        <?php echo abs($kpis['total_items_change']); ?>%
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($stats['total_items']); ?></div>
                <div class="stat-label">Total Inventory Items</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                </div>
                <div class="stat-value">KES <?php echo number_format($stats['total_value'], 2); ?></div>
                <div class="stat-label">Total Inventory Value</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo min(100, ($stats['total_value'] / 1000000) * 100); ?>%"></div>
                </div>
            </div>

            <div class="stat-card warning">
                <div class="stat-header">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                </div>
                <div class="stat-value"><?php echo number_format($stats['pending_approvals']); ?></div>
                <div class="stat-label">Pending Approvals</div>
                <div class="stat-change">
                    <i class="fas fa-hourglass-half"></i> Awaiting review
                </div>
            </div>

            <div class="stat-card danger">
                <div class="stat-header">
                    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                </div>
                <div class="stat-value"><?php echo number_format($stats['low_stock_items']); ?></div>
                <div class="stat-label">Low Stock Alert</div>
                <div class="stat-change">
                    <i class="fas fa-truck"></i> Reorder needed
                </div>
            </div>

            <div class="stat-card success">
                <div class="stat-header">
                    <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                </div>
                <div class="stat-value">KES <?php echo number_format($stats['monthly_spend'], 2); ?></div>
                <div class="stat-label">Monthly Spend</div>
                <div class="stat-change positive">
                    <i class="fas fa-chart-line"></i> This month
                </div>
            </div>

            <div class="stat-card info">
                <div class="stat-header">
                    <div class="stat-icon"><i class="fas fa-tachometer-alt"></i></div>
                </div>
                <div class="stat-value"><?php echo number_format($stats['turnover_rate']); ?>x</div>
                <div class="stat-label">Inventory Turnover</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo min(100, ($stats['turnover_rate'] / 12) * 100); ?>%"></div>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="charts-grid">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-pie"></i> Stock Distribution by Category</h3>
                    <button class="btn-icon" onclick="downloadChart('categoryChart')">
                        <i class="fas fa-download"></i>
                    </button>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-line"></i> Stock Movement Trends</h3>
                    <button class="btn-icon" onclick="downloadChart('trendsChart')">
                        <i class="fas fa-download"></i>
                    </button>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="trendsChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-bar"></i> Top 10 Moving Items</h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="topItemsChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-pie"></i> Approval Status Distribution</h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="approvalChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="card" style="margin-bottom: 2rem;">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Recent Inventory Transactions</h3>
                <button class="btn btn-outline" onclick="viewAllTransactions()">
                    View All <i class="fas fa-arrow-right"></i>
                </button>
            </div>
            <div class="card-body">
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Item</th>
                                <th>Type</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Total</th>
                                <th>Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentTransactions as $transaction): ?>
                            <tr>
                                <td><?php echo date('M j, Y H:i', strtotime($transaction['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($transaction['item_name']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $transaction['transaction_type']; ?>">
                                        <?php echo ucfirst($transaction['transaction_type']); ?>
                                    </span>
                                </td>
                                <td class="<?php echo $transaction['quantity'] > 0 ? 'positive' : 'negative'; ?>">
                                    <?php echo ($transaction['quantity'] > 0 ? '+' : '') . $transaction['quantity']; ?>
                                </td>
                                <td>KES <?php echo number_format($transaction['unit_price'], 2); ?></td>
                                <td>KES <?php echo number_format($transaction['total_amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($transaction['reference_number'] ?: '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filters-section">
            <form method="GET" id="filterForm" class="filters-form">
                <div class="filter-group">
                    <label><i class="fas fa-search"></i> Search</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($filters['search']); ?>" 
                           placeholder="Item name, code, or category...">
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-tag"></i> Category</label>
                    <select name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                        <option value="<?php echo htmlspecialchars($category['category']); ?>" 
                                <?php echo $filters['category'] == $category['category'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($category['category']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-check-circle"></i> Approval Status</label>
                    <select name="approval_status">
                        <option value="">All</option>
                        <option value="pending" <?php echo $filters['approval_status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $filters['approval_status'] === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $filters['approval_status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-dollar-sign"></i> Payment Status</label>
                    <select name="payment_status">
                        <option value="">All</option>
                        <option value="pending" <?php echo $filters['payment_status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="paid" <?php echo $filters['payment_status'] === 'paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="cancelled" <?php echo $filters['payment_status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> Date From</label>
                    <input type="date" name="date_from" value="<?php echo $filters['date_from']; ?>">
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> Date To</label>
                    <input type="date" name="date_to" value="<?php echo $filters['date_to']; ?>">
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-sort"></i> Sort By</label>
                    <select name="sort_by">
                        <option value="updated_at" <?php echo $filters['sort_by'] === 'updated_at' ? 'selected' : ''; ?>>Last Updated</option>
                        <option value="item_name" <?php echo $filters['sort_by'] === 'item_name' ? 'selected' : ''; ?>>Item Name</option>
                        <option value="quantity_in_stock" <?php echo $filters['sort_by'] === 'quantity_in_stock' ? 'selected' : ''; ?>>Stock Level</option>
                        <option value="unit_price" <?php echo $filters['sort_by'] === 'unit_price' ? 'selected' : ''; ?>>Unit Price</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-arrow-up"></i> Sort Order</label>
                    <select name="sort_order">
                        <option value="DESC" <?php echo $filters['sort_order'] === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                        <option value="ASC" <?php echo $filters['sort_order'] === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <button type="button" class="btn btn-outline" onclick="resetFilters()">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                </div>
            </form>
        </div>

        <!-- Inventory Table -->
        <div class="table-container">
            <table id="inventoryTable">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll" onclick="toggleSelectAll()"></th>
                        <th>Item Details</th>
                        <th>Category</th>
                        <th>Stock Status</th>
                        <th>Value</th>
                        <th>Approval</th>
                        <th>Payment</th>
                        <th>Request Info</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($inventory_items)): ?>
                        <?php foreach ($inventory_items as $item): ?>
                        <tr>
                            <td><input type="checkbox" class="item-checkbox" value="<?php echo $item['id']; ?>"></td>
                            <td>
                                <strong><?php echo htmlspecialchars($item['item_name']); ?></strong><br>
                                <small class="text-muted">Code: <?php echo htmlspecialchars($item['item_code']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($item['category']); ?></td>
                            <td>
                                <div class="tooltip" data-tooltip="Reorder level: <?php echo $item['reorder_level']; ?>">
                                    <?php 
                                    $stockPercent = ($item['quantity_in_stock'] / max(1, $item['reorder_level'])) * 100;
                                    $stockClass = $stockPercent <= 50 ? ($stockPercent <= 25 ? 'danger' : 'warning') : 'success';
                                    ?>
                                    <span class="badge badge-<?php echo $stockClass; ?>">
                                        <?php echo (int) $item['quantity_in_stock']; ?> units
                                    </span>
                                    <div class="progress-bar">
                                        <div class="progress-fill <?php echo $stockClass; ?>" 
                                             style="width: <?php echo min(100, $stockPercent); ?>%"></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                KES <?php echo number_format($item['unit_price'] * $item['quantity_in_stock'], 2); ?><br>
                                <small>@ KES <?php echo number_format($item['unit_price'], 2); ?>/unit</small>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $item['approval_status']; ?>">
                                    <?php echo ucfirst($item['approval_status']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $item['payment_status']; ?>">
                                    <?php echo ucfirst($item['payment_status']); ?>
                                </span>
                                <?php if ($item['payment_reference']): ?>
                                    <br><small>Ref: <?php echo htmlspecialchars($item['payment_reference']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small>
                                    <strong>Requested:</strong> <?php echo htmlspecialchars($item['requested_by_name'] ?? 'System'); ?><br>
                                    <?php if ($item['approved_by_name']): ?>
                                    <strong>Approved:</strong> <?php echo htmlspecialchars($item['approved_by_name']); ?>
                                    <?php endif; ?>
                                </small>
                            </td>
                            <td class="actions-cell">
                                <?php if ($item['approval_status'] === 'pending'): ?>
                                <button class="btn btn-success btn-sm" onclick="approveItem(<?php echo $item['id']; ?>)">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button class="btn btn-danger btn-sm" onclick="rejectItem(<?php echo $item['id']; ?>)">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                                <?php endif; ?>
                                
                                <button class="btn btn-info btn-sm" onclick="viewItemDetails(<?php echo $item['id']; ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                
                                <?php if ($item['approval_status'] === 'approved' && $item['payment_status'] === 'pending'): ?>
                                <button class="btn btn-primary btn-sm" onclick="processPayment(<?php echo $item['id']; ?>)">
                                    <i class="fas fa-credit-card"></i> Pay
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 3rem;">
                            <i class="fas fa-box-open" style="font-size: 3rem; color: var(--gray);"></i>
                            <h3>No inventory items found</h3>
                            <p>Try adjusting your filters or add new inventory items.</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($stats['total_pages'] > 1): ?>
        <div class="pagination" style="margin-top: 2rem; display: flex; justify-content: center; gap: 0.5rem;">
            <?php for ($i = 1; $i <= $stats['total_pages']; $i++): ?>
            <button class="btn <?php echo $i == ($_GET['page'] ?? 1) ? 'btn-primary' : 'btn-outline'; ?>" 
                    onclick="goToPage(<?php echo $i; ?>)">
                <?php echo $i; ?>
            </button>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modals -->
    <div id="itemModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Item Details</h3>
                <button class="btn-icon" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Dynamic content -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal()">Close</button>
            </div>
        </div>
    </div>

    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Process Payment</h3>
                <button class="btn-icon" onclick="closePaymentModal()">&times;</button>
            </div>
            <form method="POST" id="paymentForm">
                <div class="modal-body">
                    <input type="hidden" name="item_id" id="paymentItemId">
                    <div class="form-group">
                        <label>Payment Method</label>
                        <select name="payment_method" required>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="mpesa">M-Pesa</option>
                            <option value="cash">Cash</option>
                            <option value="cheque">Cheque</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Payment Reference</label>
                        <input type="text" name="payment_reference" placeholder="Transaction ID / Reference Number">
                    </div>
                    <div class="form-group">
                        <label>Payment Notes</label>
                        <textarea name="payment_notes" rows="3" placeholder="Additional notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closePaymentModal()">Cancel</button>
                    <button type="submit" name="process_payment" class="btn btn-primary">Process Payment</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let charts = {};

        // Initialize all charts
        document.addEventListener('DOMContentLoaded', function() {
            initializeCharts();
            setupEventListeners();
        });

        function initializeCharts() {
            // Category Distribution Chart
            const categoryCtx = document.getElementById('categoryChart').getContext('2d');
            charts.categoryChart = new Chart(categoryCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode(array_column(getCategoryStats($pdo), 'category')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column(getCategoryStats($pdo), 'count')); ?>,
                        backgroundColor: [
                            '#4361ee', '#7209b7', '#f72585', '#4cc9f0',
                            '#f39c12', '#27ae60', '#e74c3c', '#3498db'
                        ],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: { font: { size: 12 } }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return `${label}: ${value} items (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });

            // Stock Trends Chart
            const trendsCtx = document.getElementById('trendsChart').getContext('2d');
            const trendData = <?php echo json_encode(getStockTrends($pdo)); ?>;
            charts.trendsChart = new Chart(trendsCtx, {
                type: 'line',
                data: {
                    labels: trendData.labels,
                    datasets: [{
                        label: 'Stock Value (KES)',
                        data: trendData.values,
                        borderColor: '#4361ee',
                        backgroundColor: 'rgba(67, 97, 238, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `KES ${context.parsed.y.toLocaleString()}`;
                                }
                            }
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

            // Top Items Chart
            const topItemsCtx = document.getElementById('topItemsChart').getContext('2d');
            const topItems = <?php echo json_encode($topItems); ?>;
            charts.topItemsChart = new Chart(topItemsCtx, {
                type: 'bar',
                data: {
                    labels: topItems.map(item => item.item_name),
                    datasets: [{
                        label: 'Quantity Sold/Moved',
                        data: topItems.map(item => item.movement_count),
                        backgroundColor: '#27ae60',
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: {
                        legend: { position: 'top' }
                    }
                }
            });

            // Approval Status Chart
            const approvalCtx = document.getElementById('approvalChart').getContext('2d');
            charts.approvalChart = new Chart(approvalCtx, {
                type: 'pie',
                data: {
                    labels: ['Pending', 'Approved', 'Rejected'],
                    datasets: [{
                        data: [
                            <?php echo $stats['pending_approvals']; ?>,
                            <?php echo $stats['approved_items']; ?>,
                            <?php echo $stats['rejected_items']; ?>
                        ],
                        backgroundColor: ['#f39c12', '#27ae60', '#e74c3c']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        }

        function setupEventListeners() {
            // Real-time search
            const searchInput = document.querySelector('input[name="search"]');
            let searchTimeout;
            searchInput?.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    document.getElementById('filterForm').submit();
                }, 500);
            });

            // Auto-refresh data every 30 seconds
            setInterval(refreshData, 30000);
        }

        function refreshData() {
            fetch(window.location.href + '&ajax=true')
                .then(response => response.json())
                .then(data => {
                    updateCharts(data);
                    updateStats(data.stats);
                })
                .catch(error => console.error('Error refreshing data:', error));
        }

        function updateCharts(data) {
            if (charts.categoryChart && data.categoryData) {
                charts.categoryChart.data.datasets[0].data = data.categoryData.values;
                charts.categoryChart.update();
            }
            
            if (charts.trendsChart && data.trendData) {
                charts.trendsChart.data.datasets[0].data = data.trendData.values;
                charts.trendsChart.update();
            }
        }

        function updateStats(stats) {
            document.querySelectorAll('.stat-value').forEach((el, index) => {
                // Update stats dynamically
            });
        }

        function approveItem(itemId) {
            const notes = prompt('Enter approval notes (optional):');
            submitAction(itemId, 'approve', notes);
        }

        function rejectItem(itemId) {
            const notes = prompt('Enter rejection reason:');
            if (notes) {
                submitAction(itemId, 'reject', notes);
            }
        }

        function submitAction(itemId, action, notes) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = window.location.href;
            
            const itemIdInput = document.createElement('input');
            itemIdInput.type = 'hidden';
            itemIdInput.name = 'item_id';
            itemIdInput.value = itemId;
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'inventory_action';
            actionInput.value = action;
            
            const notesInput = document.createElement('input');
            notesInput.type = 'hidden';
            notesInput.name = 'approval_notes';
            notesInput.value = notes || '';
            
            form.appendChild(itemIdInput);
            form.appendChild(actionInput);
            form.appendChild(notesInput);
            document.body.appendChild(form);
            form.submit();
        }

        function viewItemDetails(itemId) {
            fetch(`get_item_details.php?id=${itemId}`)
                .then(response => response.json())
                .then(data => {
                    const modalBody = document.getElementById('modalBody');
                    modalBody.innerHTML = `
                        <div class="item-details">
                            <h4>${data.item_name}</h4>
                            <p><strong>Code:</strong> ${data.item_code}</p>
                            <p><strong>Category:</strong> ${data.category}</p>
                            <p><strong>Description:</strong> ${data.description || 'N/A'}</p>
                            <p><strong>Stock:</strong> ${data.quantity_in_stock} units</p>
                            <p><strong>Price:</strong> KES ${parseFloat(data.unit_price).toLocaleString()}</p>
                            <p><strong>Total Value:</strong> KES ${(data.quantity_in_stock * data.unit_price).toLocaleString()}</p>
                            <p><strong>Status:</strong> ${data.status}</p>
                            <p><strong>Approval Status:</strong> ${data.approval_status}</p>
                            <p><strong>Payment Status:</strong> ${data.payment_status}</p>
                            ${data.payment_reference ? `<p><strong>Payment Ref:</strong> ${data.payment_reference}</p>` : ''}
                        </div>
                    `;
                    document.getElementById('itemModal').classList.add('active');
                });
        }

        function processPayment(itemId) {
            document.getElementById('paymentItemId').value = itemId;
            document.getElementById('paymentModal').classList.add('active');
        }

        function exportReport() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'true');
            window.open(window.location.pathname + '?' + params.toString(), '_blank');
        }

        function downloadChart(chartId) {
            const canvas = document.getElementById(chartId);
            const link = document.createElement('a');
            link.download = `${chartId}_${Date.now()}.png`;
            link.href = canvas.toDataURL('image/png');
            link.click();
        }

        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.item-checkbox');
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
        }

        function resetFilters() {
            window.location.href = window.location.pathname;
        }

        function goToPage(page) {
            const url = new URL(window.location.href);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }

        function closeModal() {
            document.getElementById('itemModal').classList.remove('active');
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').classList.remove('active');
        }

        function viewAllTransactions() {
            window.location.href = 'inventory_transactions.php';
        }
    </script>
</body>
</html>

<?php
/**
 * Helper Functions for Inventory Management
 */

function handleInventoryAction($pdo, $admin_id) {
    $item_id = (int) $_POST['item_id'];
    $approval_notes = trim($_POST['approval_notes'] ?? '');
    $action = $_POST['inventory_action'];

    try {
        $pdo->beginTransaction();
        
        if ($action === 'approve') {
            $stmt = $pdo->prepare("
                UPDATE inventory_items
                SET approval_status = 'approved',
                    approved_by = ?,
                    approved_at = NOW(),
                    approval_notes = ?,
                    payment_status = 'pending'
                WHERE id = ?
            ");
            $stmt->execute([$admin_id, $approval_notes ?: null, $item_id]);
            
            // Log transaction
            logInventoryTransaction($pdo, $item_id, 'approval', $admin_id, 'Item approved');
            
            $_SESSION['success'] = 'Inventory item approved successfully.';
        } elseif ($action === 'reject') {
            $stmt = $pdo->prepare("
                UPDATE inventory_items
                SET approval_status = 'rejected',
                    approved_by = ?,
                    approved_at = NOW(),
                    approval_notes = ?,
                    payment_status = 'cancelled'
                WHERE id = ?
            ");
            $stmt->execute([$admin_id, $approval_notes ?: null, $item_id]);
            
            logInventoryTransaction($pdo, $item_id, 'rejection', $admin_id, $approval_notes);
            
            $_SESSION['success'] = 'Inventory item rejected.';
        }
        
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Inventory approval error: ' . $e->getMessage();
    }
}

function handleBatchAction($pdo, $admin_id) {
    // Handle batch operations
}

function handleStockAdjustment($pdo, $admin_id) {
    // Handle stock adjustments
}

function getInventoryItems($pdo, $filters) {
    $query = "
        SELECT i.*,
               requester.full_name AS requested_by_name,
               approver.full_name AS approved_by_name
        FROM inventory_items i
        LEFT JOIN users requester ON i.requested_by = requester.id
        LEFT JOIN users approver ON i.approved_by = approver.id
        WHERE 1=1
    ";
    $params = [];

    if (!empty($filters['approval_status'])) {
        $query .= " AND i.approval_status = ?";
        $params[] = $filters['approval_status'];
    }

    if (!empty($filters['status'])) {
        $query .= " AND i.status = ?";
        $params[] = $filters['status'];
    }

    if (!empty($filters['category'])) {
        $query .= " AND i.category = ?";
        $params[] = $filters['category'];
    }

    if (!empty($filters['payment_status'])) {
        $query .= " AND i.payment_status = ?";
        $params[] = $filters['payment_status'];
    }

    if (!empty($filters['search'])) {
        $query .= " AND (i.item_code LIKE ? OR i.item_name LIKE ? OR i.category LIKE ?)";
        $search = '%' . $filters['search'] . '%';
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $query .= " AND DATE(i.created_at) BETWEEN ? AND ?";
        $params[] = $filters['date_from'];
        $params[] = $filters['date_to'];
    }

    $query .= " ORDER BY i.{$filters['sort_by']} {$filters['sort_order']}";
    
    // Add pagination
    $page = (int)($_GET['page'] ?? 1);
    $per_page = 20;
    $offset = ($page - 1) * $per_page;
    // LIMIT and OFFSET must be integers in the SQL, not parameterized
    $query .= " LIMIT " . intval($per_page) . " OFFSET " . intval($offset);

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getInventoryStatistics($pdo, $filters = []) {
    $query = "
        SELECT
            COUNT(*) AS total_items,
            COUNT(CASE WHEN approval_status = 'pending' THEN 1 END) AS pending_approvals,
            COUNT(CASE WHEN approval_status = 'approved' THEN 1 END) AS approved_items,
            COUNT(CASE WHEN approval_status = 'rejected' THEN 1 END) AS rejected_items,
            COUNT(CASE WHEN quantity_in_stock <= reorder_level THEN 1 END) AS low_stock_items,
            COUNT(CASE WHEN approval_status = 'approved' AND payment_status = 'pending' THEN 1 END) AS awaiting_payment,
            COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) AS paid_items,
            COALESCE(SUM(quantity_in_stock * unit_price), 0) AS total_value,
            COALESCE(SUM(CASE WHEN MONTH(created_at) = MONTH(CURDATE()) 
                AND YEAR(created_at) = YEAR(CURDATE()) 
                THEN quantity_in_stock * purchase_price ELSE 0 END), 0) AS monthly_spend,
            COALESCE(SUM(quantity_in_stock * unit_price) / NULLIF(SUM(purchase_price * quantity_in_stock), 0), 0) AS turnover_rate
        FROM inventory_items
    ";
    
    $stmt = $pdo->query($query);
    $stats = $stmt->fetch();
    
    // Calculate total pages
    $total_items = $pdo->query("SELECT COUNT(*) FROM inventory_items")->fetchColumn();
    $stats['total_pages'] = ceil($total_items / 20);
    
    return $stats;
}

function calculateKPIs($pdo, $stats) {
    // Calculate KPI changes from previous month
    $last_month = $pdo->query("
        SELECT COALESCE(SUM(quantity_in_stock * unit_price), 0) as total_value
        FROM inventory_items
        WHERE MONTH(created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH)
    ")->fetchColumn();
    
    $total_items_change = $last_month > 0 
        ? round((($stats['total_items'] - $last_month) / $last_month) * 100, 1)
        : 0;
    
    return [
        'total_items_change' => $total_items_change
    ];
}

function getRecentTransactions($pdo, $limit = 10) {
    $limit = intval($limit);
    $stmt = $pdo->query("
        SELECT t.*, i.item_name, i.item_code
        FROM inventory_transactions t
        JOIN inventory_items i ON t.item_id = i.id
        ORDER BY t.created_at DESC
        LIMIT $limit
    ");
    return $stmt->fetchAll();
}

function getTopMovingItems($pdo, $limit = 10) {
    $limit = intval($limit);
    $stmt = $pdo->query("
        SELECT i.id, i.item_name, i.item_code, 
               COUNT(t.id) as movement_count,
               SUM(t.quantity) as total_moved
        FROM inventory_items i
        LEFT JOIN inventory_transactions t ON i.id = t.item_id
        WHERE t.transaction_type IN ('sale', 'purchase')
        GROUP BY i.id
        ORDER BY movement_count DESC
        LIMIT $limit
    ");
    return $stmt->fetchAll();
}

function getCategoryStats($pdo) {
    $stmt = $pdo->query("
        SELECT category, COUNT(*) as count
        FROM inventory_items
        WHERE category IS NOT NULL
        GROUP BY category
        ORDER BY count DESC
    ");
    return $stmt->fetchAll();
}

function getStockTrends($pdo) {
    $stmt = $pdo->query("
        SELECT 
            DATE(created_at) as date,
            SUM(quantity_in_stock * unit_price) as total_value
        FROM inventory_items
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ");
    
    $data = $stmt->fetchAll();
    return [
        'labels' => array_column($data, 'date'),
        'values' => array_column($data, 'total_value')
    ];
}

function logInventoryTransaction($pdo, $item_id, $type, $user_id, $notes = null) {
    $stmt = $pdo->prepare("
        INSERT INTO inventory_transactions 
        (item_id, transaction_type, notes, created_by, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$item_id, $type, $notes, $user_id]);
}

function handleAjaxRequest($pdo) {
    header('Content-Type: application/json');
    
    $response = [
        'categoryData' => [
            'labels' => array_column(getCategoryStats($pdo), 'category'),
            'values' => array_column(getCategoryStats($pdo), 'count')
        ],
        'trendData' => getStockTrends($pdo),
        'stats' => getInventoryStatistics($pdo)
    ];
    
    echo json_encode($response);
}
?>