<?php
include '../config.php';
require_once '../finance_accounts_helpers.php';
checkAuth();
checkRole(['accountant', 'admin']);
financeEnsureSchema($pdo);

$page_title = 'Expense Management - ' . SCHOOL_NAME;

try {
    $vendorColumnExists = (bool) $pdo->query("SHOW COLUMNS FROM expenses LIKE 'vendor'")->fetch();
    if (!$vendorColumnExists) {
        $pdo->exec("ALTER TABLE expenses ADD COLUMN vendor VARCHAR(150) NULL AFTER description");
    }
} catch (Exception $e) {
    $vendorColumnExists = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_expense'])) {
    try {
        $expenseId = intval($_POST['expense_id'] ?? 0);
        $categoryName = trim($_POST['category_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $vendor = trim($_POST['vendor'] ?? '');
        $amount = (float) ($_POST['amount'] ?? 0);
        $expenseDate = $_POST['expense_date'] ?? '';
        $paymentMethod = trim($_POST['payment_method'] ?? '');
        $referenceNumber = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($expenseId <= 0) {
            throw new Exception('Invalid expense selected.');
        }
        if ($categoryName === '' || $description === '' || $amount <= 0 || $expenseDate === '' || $paymentMethod === '') {
            throw new Exception('Please complete all required expense fields.');
        }

        $expenseStmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ?");
        $expenseStmt->execute([$expenseId]);
        $existingExpense = $expenseStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existingExpense) {
            throw new Exception('Expense not found.');
        }

        $currentRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? '';
        if ($existingExpense['status'] !== 'pending') {
            throw new Exception('Only pending expenses can be edited.');
        }
        if ((int) $existingExpense['created_by'] !== (int) $_SESSION['user_id'] && $currentRole !== 'admin') {
            throw new Exception('You do not have permission to edit this expense.');
        }

        $categoryLookup = $pdo->prepare("SELECT id FROM expense_categories WHERE name = ? LIMIT 1");
        $categoryLookup->execute([$categoryName]);
        $categoryId = (int) ($categoryLookup->fetchColumn() ?: 0);

        if ($categoryId <= 0) {
            $createCategory = $pdo->prepare("
                INSERT INTO expense_categories (name, description, budget, status, created_at, updated_at)
                VALUES (?, ?, 0, 'active', NOW(), NOW())
            ");
            $createCategory->execute([$categoryName, 'Auto-created from expense editing']);
            $categoryId = (int) $pdo->lastInsertId();
        }

        $updateStmt = $pdo->prepare("
            UPDATE expenses
            SET category_id = ?, description = ?, vendor = ?, amount = ?, expense_date = ?,
                payment_method = ?, reference_number = ?, notes = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([
            $categoryId,
            $description,
            $vendor !== '' ? $vendor : null,
            $amount,
            $expenseDate,
            $paymentMethod,
            $referenceNumber !== '' ? $referenceNumber : null,
            $notes !== '' ? $notes : null,
            $expenseId
        ]);

        $_SESSION['success'] = 'Expense updated successfully.';
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }

    header('Location: expenses.php');
    exit();
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$category_filter = $_GET['category_id'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Build query
$params = [];
$query = "SELECT e.*, COALESCE(ec.name, CONCAT('Category #', e.category_id)) as category, u.full_name as created_by_name, u2.full_name as approved_by_name,
                 COALESCE(e.payment_status, 'unpaid') as financial_status,
                 sa.account_name as paid_from_account_name
          FROM expenses e 
          LEFT JOIN expense_categories ec ON e.category_id = ec.id
          LEFT JOIN users u ON e.created_by = u.id 
          LEFT JOIN users u2 ON e.approved_by = u2.id
          LEFT JOIN school_accounts sa ON e.paid_from_account_id = sa.id
          WHERE 1=1";

if ($search) {
    $query .= " AND (e.description LIKE ? OR COALESCE(ec.name, '') LIKE ? OR COALESCE(e.vendor, '') LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if ($status_filter) {
    $query .= " AND e.status = ?";
    $params[] = $status_filter;
}

if ($category_filter) {
    $query .= " AND e.category_id = ?";
    $params[] = $category_filter;
}

if ($start_date) {
    $query .= " AND DATE(e.expense_date) >= ?";
    $params[] = $start_date;
}

if ($end_date) {
    $query .= " AND DATE(e.expense_date) <= ?";
    $params[] = $end_date;
}

$query .= " ORDER BY e.expense_date DESC, e.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

// Get categories for filter
$categories = $pdo->query("SELECT id, name FROM expense_categories ORDER BY name")->fetchAll();

// Get statistics
$stats_query = "SELECT 
                    COUNT(*) as total_count,
                    COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) as total_approved,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as total_pending,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) as total_paid,
                    COALESCE(SUM(amount), 0) as total_all
                FROM expenses";
$stats = $pdo->query($stats_query)->fetch();

// Get category summary
$category_summary = $pdo->query("
    SELECT 
        COALESCE(ec.name, CONCAT('Category #', e.category_id)) as category,
        COALESCE(SUM(amount), 0) as total,
        COUNT(*) as count
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    WHERE e.status IN ('approved', 'paid')
    GROUP BY e.category_id, ec.name
    ORDER BY total DESC
    LIMIT 5
")->fetchAll();

$expenseLookup = [];
foreach ($expenses as $expense) {
    $expenseLookup[$expense['id']] = $expense;
}
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

        .status-approved {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .status-pending {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        .status-paid {
            background: rgba(114, 9, 183, 0.15);
            color: var(--purple);
        }

        .status-unpaid {
            background: rgba(148, 163, 184, 0.18);
            color: var(--dark-light);
        }

        .status-rejected {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
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

        .action-btn.view { background: var(--primary); }
        .action-btn.edit { background: var(--warning); }
        .action-btn.delete { background: var(--danger); }

        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            z-index: 1200;
            padding: 1.5rem;
            overflow-y: auto;
        }

        .modal.active {
            display: block;
        }

        .modal-card {
            max-width: 760px;
            margin: 2rem auto;
            background: var(--white);
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-xl);
            overflow: hidden;
        }

        .modal-header {
            padding: 1.5rem 1.75rem;
            background: var(--gradient-1);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 1.5rem 1.75rem;
        }

        .modal-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .modal-field {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
        }

        .modal-field.full {
            grid-column: 1 / -1;
        }

        .modal-field input,
        .modal-field select,
        .modal-field textarea {
            width: 100%;
            padding: 0.85rem 1rem;
            border: 1px solid #dbe4ff;
            border-radius: var(--border-radius-md);
            font: inherit;
            background: #fdfefe;
        }

        .modal-field textarea {
            min-height: 110px;
            resize: vertical;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1.25rem;
        }

        .modal-close {
            background: rgba(255,255,255,0.14);
            border: none;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 999px;
            cursor: pointer;
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
            
            .action-buttons {
                flex-wrap: wrap;
            }

            .modal-grid {
                grid-template-columns: 1fr;
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
                <h1><i class="fas fa-receipt"></i> Expense Management</h1>
                <p>Track and manage all school expenses</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-success" onclick="window.location.href='add_expense.php'">
                    <i class="fas fa-plus"></i> Add Expense
                </button>
                <?php if (($_SESSION['user_role'] ?? $_SESSION['role'] ?? '') === 'admin'): ?>
                <a href="expense_approvals.php" class="btn btn-warning">
                    <i class="fas fa-check-double"></i> Approvals
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card primary stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Total Expenses</div>
                    <div class="kpi-value">KES <?php echo number_format($stats['total_all'], 2); ?></div>
                </div>
            </div>

            <div class="kpi-card success stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Approved</div>
                    <div class="kpi-value">KES <?php echo number_format($stats['total_approved'], 2); ?></div>
                </div>
            </div>

            <div class="kpi-card warning stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Pending</div>
                    <div class="kpi-value">KES <?php echo number_format($stats['total_pending'], 2); ?></div>
                </div>
            </div>

            <div class="kpi-card purple stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-credit-card"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Paid</div>
                    <div class="kpi-value">KES <?php echo number_format($stats['total_paid'], 2); ?></div>
                </div>
            </div>
        </div>

        <!-- Category Chart -->
        <?php if (!empty($category_summary)): ?>
        <div class="charts-grid">
            <div class="chart-card animate">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-pie"></i> Top Expense Categories</h3>
                </div>
                <div class="chart-container">
                    <canvas id="categoryChart"></canvas>
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
                        <input type="text" name="search" placeholder="Description, vendor..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Status</label>
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="paid" <?php echo $status_filter == 'paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-folder"></i> Category</label>
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
                        <input type="date" name="start_date" value="<?php echo $start_date; ?>">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar"></i> To Date</label>
                        <input type="date" name="end_date" value="<?php echo $end_date; ?>">
                    </div>
                    <div class="form-group" style="display: flex; gap: 0.5rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <a href="expenses.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Expenses Table -->
        <div class="data-card animate">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Expense Records</h3>
                <span>Total: <?php echo count($expenses); ?> expenses</span>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Vendor</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Financial Status</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($expenses)): ?>
                            <?php foreach ($expenses as $expense): ?>
                            <tr>
                                <td><?php echo date('d M Y', strtotime($expense['expense_date'])); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($expense['category']); ?></strong>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($expense['description']); ?>
                                    <?php if ($expense['notes']): ?>
                                    <i class="fas fa-sticky-note" style="color: var(--gray); margin-left: 0.3rem;" title="<?php echo htmlspecialchars($expense['notes']); ?>"></i>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($expense['vendor'] ?? 'N/A'); ?></td>
                                <td><strong style="color: var(--danger);">KES <?php echo number_format($expense['amount'], 2); ?></strong></td>
                                <td>
                                    <span class="status-badge status-<?php echo $expense['status']; ?>">
                                        <?php echo ucfirst($expense['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo htmlspecialchars($expense['financial_status']); ?>">
                                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $expense['financial_status']))); ?>
                                    </span>
                                    <?php if (!empty($expense['paid_from_account_name'])): ?>
                                    <div style="font-size: 0.75rem; color: var(--gray); margin-top: 0.25rem;">
                                        <?php echo htmlspecialchars($expense['paid_from_account_name']); ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($expense['payment_reference'])): ?>
                                    <div style="font-size: 0.75rem; color: var(--gray);">
                                        Ref: <?php echo htmlspecialchars($expense['payment_reference']); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($expense['created_by_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="action-btn view" onclick="viewExpense(<?php echo $expense['id']; ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($expense['status'] == 'pending' && ($_SESSION['user_id'] == $expense['created_by'] || (($_SESSION['user_role'] ?? $_SESSION['role'] ?? '') === 'admin'))): ?>
                                        <button class="action-btn edit" onclick="editExpense(<?php echo $expense['id']; ?>)" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php endif; ?>
                                        <?php if ((($_SESSION['user_role'] ?? $_SESSION['role'] ?? '') === 'admin') && $expense['status'] == 'pending'): ?>
                                        <button class="action-btn delete" onclick="deleteExpense(<?php echo $expense['id']; ?>)" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 3rem;">
                                    <i class="fas fa-receipt fa-3x" style="color: var(--gray); margin-bottom: 1rem;"></i>
                                    <h4>No Expenses Found</h4>
                                    <p style="color: var(--gray);">Try adjusting your filters or add a new expense</p>
                                    <button class="btn btn-success" onclick="window.location.href='add_expense.php'" style="margin-top: 1rem;">
                                        <i class="fas fa-plus"></i> Add Expense
                                    </button>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="modal" id="editExpenseModal" aria-hidden="true">
            <div class="modal-card">
                <div class="modal-header">
                    <div>
                        <h3 style="margin:0;">Edit Expense</h3>
                        <p style="margin:0.25rem 0 0; opacity:0.9;">Update the expense details without leaving this page.</p>
                    </div>
                    <button type="button" class="modal-close" onclick="closeExpenseModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <form method="POST">
                        <input type="hidden" name="expense_id" id="edit_expense_id">
                        <div class="modal-grid">
                            <div class="modal-field">
                                <label for="edit_category_name">Category</label>
                                <input type="text" name="category_name" id="edit_category_name" list="expense-category-list" required>
                            </div>
                            <div class="modal-field">
                                <label for="edit_amount">Amount</label>
                                <input type="number" name="amount" id="edit_amount" min="0.01" step="0.01" required>
                            </div>
                            <div class="modal-field full">
                                <label for="edit_description">Description</label>
                                <textarea name="description" id="edit_description" required></textarea>
                            </div>
                            <div class="modal-field">
                                <label for="edit_vendor">Vendor / Payee</label>
                                <input type="text" name="vendor" id="edit_vendor">
                            </div>
                            <div class="modal-field">
                                <label for="edit_expense_date">Expense Date</label>
                                <input type="date" name="expense_date" id="edit_expense_date" required>
                            </div>
                            <div class="modal-field">
                                <label for="edit_payment_method">Payment Method</label>
                                <select name="payment_method" id="edit_payment_method" required>
                                    <option value="">Choose method...</option>
                                    <option value="cash">Cash</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="mpesa">M-Pesa</option>
                                    <option value="cheque">Cheque</option>
                                    <option value="credit">Credit</option>
                                </select>
                            </div>
                            <div class="modal-field">
                                <label for="edit_reference_number">Reference Number</label>
                                <input type="text" name="reference_number" id="edit_reference_number">
                            </div>
                            <div class="modal-field full">
                                <label for="edit_notes">Notes</label>
                                <textarea name="notes" id="edit_notes"></textarea>
                            </div>
                        </div>
                        <div class="modal-actions">
                            <button type="button" class="btn btn-outline" onclick="closeExpenseModal()">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button type="submit" name="update_expense" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal" id="viewExpenseModal" aria-hidden="true">
            <div class="modal-card">
                <div class="modal-header">
                    <div>
                        <h3 style="margin:0;">Expense Details</h3>
                        <p style="margin:0.25rem 0 0; opacity:0.9;">Review the full expense information without leaving the page.</p>
                    </div>
                    <button type="button" class="modal-close" onclick="closeViewExpenseModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="modal-grid">
                        <div class="modal-field">
                            <label>Category</label>
                            <input type="text" id="view_category" readonly>
                        </div>
                        <div class="modal-field">
                            <label>Status</label>
                            <input type="text" id="view_status" readonly>
                        </div>
                        <div class="modal-field">
                            <label>Financial Status</label>
                            <input type="text" id="view_financial_status" readonly>
                        </div>
                        <div class="modal-field">
                            <label>Amount</label>
                            <input type="text" id="view_amount" readonly>
                        </div>
                        <div class="modal-field">
                            <label>Expense Date</label>
                            <input type="text" id="view_expense_date" readonly>
                        </div>
                        <div class="modal-field full">
                            <label>Description</label>
                            <textarea id="view_description" readonly></textarea>
                        </div>
                        <div class="modal-field">
                            <label>Vendor / Payee</label>
                            <input type="text" id="view_vendor" readonly>
                        </div>
                        <div class="modal-field">
                            <label>Payment Method</label>
                            <input type="text" id="view_payment_method" readonly>
                        </div>
                        <div class="modal-field">
                            <label>Reference Number</label>
                            <input type="text" id="view_reference_number" readonly>
                        </div>
                        <div class="modal-field">
                            <label>Created By</label>
                            <input type="text" id="view_created_by" readonly>
                        </div>
                        <div class="modal-field">
                            <label>Paid From Account</label>
                            <input type="text" id="view_paid_from_account" readonly>
                        </div>
                        <div class="modal-field">
                            <label>Finance Reference</label>
                            <input type="text" id="view_payment_reference" readonly>
                        </div>
                        <div class="modal-field full">
                            <label>Notes</label>
                            <textarea id="view_notes" readonly></textarea>
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-outline" onclick="closeViewExpenseModal()">
                            <i class="fas fa-times"></i> Close
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($categories)): ?>
        <datalist id="expense-category-list">
            <?php foreach ($categories as $category): ?>
            <option value="<?php echo htmlspecialchars($category['name']); ?>">
            <?php endforeach; ?>
        </datalist>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const expenseMap = <?php echo json_encode($expenseLookup, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        // Initialize Category Chart
        document.addEventListener('DOMContentLoaded', function() {
            const categoryCtx = document.getElementById('categoryChart');
            if (categoryCtx) {
                const categories = <?php echo json_encode(array_column($category_summary, 'category')); ?>;
                const amounts = <?php echo json_encode(array_column($category_summary, 'total')); ?>;
                
                new Chart(categoryCtx, {
                    type: 'doughnut',
                    data: {
                        labels: categories,
                        datasets: [{
                            data: amounts,
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
            }
        });

        // View Expense
        function viewExpense(expenseId) {
            const expense = expenseMap[expenseId];
            if (!expense) {
                Swal.fire({
                    icon: 'error',
                    title: 'Expense not found',
                    text: 'The selected expense details could not be loaded.'
                });
                return;
            }

            document.getElementById('view_category').value = expense.category || '';
            document.getElementById('view_status').value = expense.status ? expense.status.charAt(0).toUpperCase() + expense.status.slice(1) : '';
            document.getElementById('view_financial_status').value = (expense.financial_status || 'unpaid').replace(/_/g, ' ').replace(/\b\w/g, function(letter) { return letter.toUpperCase(); });
            document.getElementById('view_amount').value = 'KES ' + Number(expense.amount || 0).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('view_expense_date').value = expense.expense_date || '';
            document.getElementById('view_description').value = expense.description || '';
            document.getElementById('view_vendor').value = expense.vendor || 'N/A';
            document.getElementById('view_payment_method').value = expense.payment_method || 'N/A';
            document.getElementById('view_reference_number').value = expense.reference_number || 'N/A';
            document.getElementById('view_created_by').value = expense.created_by_name || 'N/A';
            document.getElementById('view_paid_from_account').value = expense.paid_from_account_name || 'Not yet paid';
            document.getElementById('view_payment_reference').value = expense.payment_reference || 'N/A';
            document.getElementById('view_notes').value = expense.notes || 'No notes';

            document.getElementById('viewExpenseModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        // Edit Expense
        function editExpense(expenseId) {
            const expense = expenseMap[expenseId];
            if (!expense) {
                Swal.fire({
                    icon: 'error',
                    title: 'Expense not found',
                    text: 'The selected expense details could not be loaded.'
                });
                return;
            }

            document.getElementById('edit_expense_id').value = expense.id || '';
            document.getElementById('edit_category_name').value = expense.category || '';
            document.getElementById('edit_amount').value = expense.amount || '';
            document.getElementById('edit_description').value = expense.description || '';
            document.getElementById('edit_vendor').value = expense.vendor || '';
            document.getElementById('edit_expense_date').value = expense.expense_date || '';
            document.getElementById('edit_payment_method').value = expense.payment_method || '';
            document.getElementById('edit_reference_number').value = expense.reference_number || '';
            document.getElementById('edit_notes').value = expense.notes || '';

            document.getElementById('editExpenseModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeExpenseModal() {
            document.getElementById('editExpenseModal').classList.remove('active');
            document.body.style.overflow = '';
        }

        function closeViewExpenseModal() {
            document.getElementById('viewExpenseModal').classList.remove('active');
            document.body.style.overflow = '';
        }

        document.getElementById('editExpenseModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeExpenseModal();
            }
        });

        document.getElementById('viewExpenseModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeViewExpenseModal();
            }
        });

        // Delete Expense
        function deleteExpense(expenseId) {
            Swal.fire({
                title: 'Delete Expense?',
                text: 'Are you sure you want to delete this expense? This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f94144',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'delete_expense.php';
                    form.innerHTML = `
                        <input type="hidden" name="expense_id" value="${expenseId}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
    </script>
</body>
</html>
