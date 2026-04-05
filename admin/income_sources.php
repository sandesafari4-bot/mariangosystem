<?php
include '../config.php';
checkAuth();
checkRole(['admin', 'accountant']);

$page_title = 'Income Sources - ' . SCHOOL_NAME;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['add_income'])) {
            $source_name = trim($_POST['source_name']);
            $category = trim($_POST['category']);
            $amount = floatval($_POST['amount']);
            $income_date = $_POST['income_date'];
            $payment_method = $_POST['payment_method'];
            $reference = trim($_POST['reference'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $received_from = trim($_POST['received_from'] ?? '');
            
            $stmt = $pdo->prepare("
                INSERT INTO income_sources (
                    source_name, category, amount, income_date, payment_method,
                    reference, description, received_from, recorded_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $source_name,
                $category,
                $amount,
                $income_date,
                $payment_method,
                $reference,
                $description,
                $received_from,
                $_SESSION['user_id']
            ]);
            
            $_SESSION['success'] = 'Income source added successfully!';
            
        } elseif (isset($_POST['update_income'])) {
            $id = intval($_POST['id']);
            $source_name = trim($_POST['source_name']);
            $category = trim($_POST['category']);
            $amount = floatval($_POST['amount']);
            $income_date = $_POST['income_date'];
            $payment_method = $_POST['payment_method'];
            $reference = trim($_POST['reference'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $received_from = trim($_POST['received_from'] ?? '');
            
            $stmt = $pdo->prepare("
                UPDATE income_sources 
                SET source_name = ?, category = ?, amount = ?, income_date = ?,
                    payment_method = ?, reference = ?, description = ?, received_from = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $source_name,
                $category,
                $amount,
                $income_date,
                $payment_method,
                $reference,
                $description,
                $received_from,
                $id
            ]);
            
            $_SESSION['success'] = 'Income source updated successfully!';
            
        } elseif (isset($_POST['delete_income'])) {
            $id = intval($_POST['id']);
            
            $stmt = $pdo->prepare("DELETE FROM income_sources WHERE id = ?");
            $stmt->execute([$id]);
            
            $_SESSION['success'] = 'Income source deleted successfully!';
        }
        
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }
    
    header("Location: income_sources.php");
    exit();
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Build query
$params = [];
$query = "
    SELECT i.*, u.full_name as recorded_by_name
    FROM income_sources i
    LEFT JOIN users u ON i.recorded_by = u.id
    WHERE 1=1
";

if ($search) {
    $query .= " AND (i.source_name LIKE ? OR i.category LIKE ? OR i.received_from LIKE ? OR i.reference LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if ($category_filter) {
    $query .= " AND i.category = ?";
    $params[] = $category_filter;
}

if ($date_from) {
    $query .= " AND i.income_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $query .= " AND i.income_date <= ?";
    $params[] = $date_to;
}

$query .= " ORDER BY i.income_date DESC, i.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$income_sources = $stmt->fetchAll();

// Get categories for filter
$categories = $pdo->query("SELECT DISTINCT category FROM income_sources ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

// Get summary statistics
$summary = $pdo->query("
    SELECT 
        COUNT(*) as total_count,
        COALESCE(SUM(amount), 0) as total_amount,
        COALESCE(SUM(CASE WHEN MONTH(income_date) = MONTH(CURDATE()) AND YEAR(income_date) = YEAR(CURDATE()) THEN amount ELSE 0 END), 0) as monthly_amount,
        COALESCE(AVG(amount), 0) as avg_amount,
        MAX(amount) as max_amount
    FROM income_sources
")->fetch();

// Get monthly trend
$monthly_trend = $pdo->query("
    SELECT 
        DATE_FORMAT(income_date, '%Y-%m') as month,
        DATE_FORMAT(income_date, '%b %Y') as month_name,
        COALESCE(SUM(amount), 0) as total,
        COUNT(*) as count
    FROM income_sources
    WHERE income_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(income_date, '%Y-%m')
    ORDER BY month ASC
")->fetchAll();
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

        .btn-danger {
            background: var(--gradient-2);
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border-left: 4px solid var(--success);
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-detail {
            font-size: 0.85rem;
            color: var(--gray);
            margin-top: 0.5rem;
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

        /* Data Table */
        .data-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
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

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
            z-index: 1050;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease;
            box-shadow: var(--shadow-xl);
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 2px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.05) 0%, rgba(63, 55, 201, 0.05) 100%);
        }

        .modal-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--dark);
            font-size: 1.2rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
            transition: var(--transition);
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .modal-close:hover {
            background: var(--light);
            color: var(--danger);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 2px solid var(--light);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
        }

        .required::after {
            content: " *";
            color: var(--danger);
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
            border: none;
            cursor: pointer;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .action-btn.edit { background: var(--warning); }
        .action-btn.delete { background: var(--danger); }

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
            
            .stats-grid {
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
            
            .form-grid {
                grid-template-columns: 1fr;
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
                <h1><i class="fas fa-coins"></i> Income Sources</h1>
                <p>Manage other income sources (donations, grants, investments, etc.)</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-success" onclick="openModal('addModal')">
                    <i class="fas fa-plus"></i> Add Income
                </button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success animate" style="padding: 1rem 1.5rem; border-radius: var(--border-radius-md); margin-bottom: 1.5rem; background: rgba(76, 201, 240, 0.1); border-left: 4px solid var(--success); color: var(--success); display: flex; justify-content: space-between; align-items: center;">
            <div>
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; ?>
            </div>
            <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: inherit; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger animate" style="padding: 1rem 1.5rem; border-radius: var(--border-radius-md); margin-bottom: 1.5rem; background: rgba(249, 65, 68, 0.1); border-left: 4px solid var(--danger); color: var(--danger); display: flex; justify-content: space-between; align-items: center;">
            <div>
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; ?>
            </div>
            <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: inherit; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['error']); endif; ?>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card stagger-item">
                <div class="stat-number">KES <?php echo number_format($summary['total_amount'], 2); ?></div>
                <div class="stat-label">Total Income</div>
                <div class="stat-detail"><?php echo $summary['total_count']; ?> records</div>
            </div>
            
            <div class="stat-card stagger-item">
                <div class="stat-number">KES <?php echo number_format($summary['monthly_amount'], 2); ?></div>
                <div class="stat-label">This Month</div>
            </div>
            
            <div class="stat-card stagger-item">
                <div class="stat-number">KES <?php echo number_format($summary['avg_amount'], 2); ?></div>
                <div class="stat-label">Average Amount</div>
            </div>
            
            <div class="stat-card stagger-item">
                <div class="stat-number">KES <?php echo number_format($summary['max_amount'], 2); ?></div>
                <div class="stat-label">Largest Income</div>
            </div>
        </div>

        <!-- Monthly Trend Chart -->
        <?php if (!empty($monthly_trend)): ?>
        <div class="charts-grid">
            <div class="chart-card animate">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-line"></i> Monthly Income Trend</h3>
                    <span class="badge">Last 12 months</span>
                </div>
                <div class="chart-container">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filter Section -->
        <div class="filter-section animate">
            <form method="GET" id="filterForm">
                <div class="filter-grid">
                    <div class="form-group">
                        <label><i class="fas fa-search"></i> Search</label>
                        <input type="text" name="search" placeholder="Source, category, received from..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Category</label>
                        <select name="category">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat; ?>" <?php echo $category_filter == $cat ? 'selected' : ''; ?>>
                                <?php echo $cat; ?>
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
                        <a href="income_sources.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Income Sources Table -->
        <div class="data-card animate">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Income Records</h3>
                <span>Total: <?php echo count($income_sources); ?> records | KES <?php echo number_format(array_sum(array_column($income_sources, 'amount')), 2); ?></span>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Source</th>
                            <th>Category</th>
                            <th>Amount</th>
                            <th>Received From</th>
                            <th>Payment Method</th>
                            <th>Reference</th>
                            <th>Recorded By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($income_sources)): ?>
                            <?php foreach ($income_sources as $income): ?>
                            <tr>
                                <td><?php echo date('d M Y', strtotime($income['income_date'])); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($income['source_name']); ?></strong>
                                    <?php if (!empty($income['description'])): ?>
                                    <div style="font-size: 0.8rem; color: var(--gray);">
                                        <?php echo htmlspecialchars(substr($income['description'], 0, 30)) . '...'; ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($income['category']); ?></td>
                                <td><strong style="color: var(--success);">KES <?php echo number_format($income['amount'], 2); ?></strong></td>
                                <td><?php echo htmlspecialchars($income['received_from'] ?? '-'); ?></td>
                                <td><?php echo ucfirst($income['payment_method']); ?></td>
                                <td>
                                    <?php if (!empty($income['reference'])): ?>
                                    <span style="font-family: monospace;"><?php echo htmlspecialchars($income['reference']); ?></span>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($income['recorded_by_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="action-btn edit" onclick="editIncome(<?php echo $income['id']; ?>)" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="action-btn delete" onclick="deleteIncome(<?php echo $income['id']; ?>)" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 3rem;">
                                    <i class="fas fa-coins fa-3x" style="color: var(--gray); margin-bottom: 1rem;"></i>
                                    <h4>No Income Records Found</h4>
                                    <p style="color: var(--gray);">Add your first income source to get started</p>
                                    <button class="btn btn-success" onclick="openModal('addModal')" style="margin-top: 1rem;">
                                        <i class="fas fa-plus"></i> Add Income
                                    </button>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add Income Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Add Income Source</h3>
                <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label class="required">Source Name</label>
                            <input type="text" name="source_name" class="form-control" required placeholder="e.g., Donation, Grant, Investment">
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Category</label>
                            <input type="text" name="category" class="form-control" required placeholder="e.g., Donation, Government Grant">
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Amount</label>
                            <input type="number" name="amount" class="form-control" step="0.01" min="0" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Income Date</label>
                            <input type="date" name="income_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Payment Method</label>
                            <select name="payment_method" class="form-control" required>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                                <option value="mpesa">M-Pesa</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Reference</label>
                            <input type="text" name="reference" class="form-control" placeholder="Receipt/Transaction No.">
                        </div>
                        
                        <div class="form-group full-width">
                            <label>Received From</label>
                            <input type="text" name="received_from" class="form-control" placeholder="Name of donor/source">
                        </div>
                        
                        <div class="form-group full-width">
                            <label>Description</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="Additional details..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addModal')">Cancel</button>
                    <button type="submit" name="add_income" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Income
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Income Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Income Source</h3>
                <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label class="required">Source Name</label>
                            <input type="text" name="source_name" id="edit_source_name" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Category</label>
                            <input type="text" name="category" id="edit_category" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Amount</label>
                            <input type="number" name="amount" id="edit_amount" class="form-control" step="0.01" min="0" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Income Date</label>
                            <input type="date" name="income_date" id="edit_income_date" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Payment Method</label>
                            <select name="payment_method" id="edit_payment_method" class="form-control" required>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                                <option value="mpesa">M-Pesa</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Reference</label>
                            <input type="text" name="reference" id="edit_reference" class="form-control">
                        </div>
                        
                        <div class="form-group full-width">
                            <label>Received From</label>
                            <input type="text" name="received_from" id="edit_received_from" class="form-control">
                        </div>
                        
                        <div class="form-group full-width">
                            <label>Description</label>
                            <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" name="update_income" class="btn btn-warning">
                        <i class="fas fa-save"></i> Update Income
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Initialize Chart
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('trendChart');
            if (ctx) {
                const months = <?php echo json_encode(array_column($monthly_trend, 'month_name')); ?>;
                const totals = <?php echo json_encode(array_column($monthly_trend, 'total')); ?>;

                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: months,
                        datasets: [{
                            label: 'Income (KES)',
                            data: totals,
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
            }
        });

        // Modal Functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // Edit Income
        function editIncome(id) {
            fetch('get_income.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const i = data.data;
                        document.getElementById('edit_id').value = i.id;
                        document.getElementById('edit_source_name').value = i.source_name;
                        document.getElementById('edit_category').value = i.category;
                        document.getElementById('edit_amount').value = i.amount;
                        document.getElementById('edit_income_date').value = i.income_date;
                        document.getElementById('edit_payment_method').value = i.payment_method;
                        document.getElementById('edit_reference').value = i.reference || '';
                        document.getElementById('edit_received_from').value = i.received_from || '';
                        document.getElementById('edit_description').value = i.description || '';
                        
                        openModal('editModal');
                    } else {
                        Swal.fire('Error', 'Failed to load income data', 'error');
                    }
                });
        }

        // Delete Income
        function deleteIncome(id) {
            Swal.fire({
                title: 'Delete Record?',
                text: 'Are you sure you want to delete this income record?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f94144',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="delete_income" value="1">
                        <input type="hidden" name="id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</body>
</html>