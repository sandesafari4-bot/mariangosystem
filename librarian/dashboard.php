<?php
require_once '../config.php';
checkAuth();
checkRole(['admin', 'librarian']);
require_once '../inventory_payment_helpers.php';

function dashboardColumnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetch();
}

function ensureInventoryDashboardSchema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventory_items (
            id INT PRIMARY KEY AUTO_INCREMENT,
            item_code VARCHAR(50) UNIQUE NOT NULL,
            item_name VARCHAR(150) NOT NULL,
            category VARCHAR(100) NOT NULL,
            description TEXT,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            quantity_in_stock INT DEFAULT 0,
            reorder_level INT DEFAULT 10,
            reorder_quantity INT DEFAULT 20,
            supplier_id INT NULL,
            last_restock_date TIMESTAMP NULL,
            status ENUM('active', 'inactive', 'discontinued') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY category (category),
            KEY status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    ensureInventoryPaymentWorkflow($pdo);
}

ensureInventoryDashboardSchema($pdo);

// Get comprehensive statistics
$total_books = $pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();
$total_copies = $pdo->query("SELECT SUM(total_copies) FROM books")->fetchColumn();
$available_copies = $pdo->query("SELECT SUM(available_copies) FROM books")->fetchColumn();
$issued_books = $pdo->query("SELECT COUNT(*) FROM book_issues WHERE return_date IS NULL")->fetchColumn();
$overdue_books = $pdo->query("SELECT COUNT(*) FROM book_issues WHERE return_date IS NULL AND due_date < CURDATE()")->fetchColumn();
$total_students = $pdo->query("SELECT COUNT(*) FROM students WHERE status = 'active'")->fetchColumn();

// Get detailed statistics
$total_authors = $pdo->query("SELECT COUNT(DISTINCT author) FROM books")->fetchColumn();
$total_publishers = $pdo->query("SELECT COUNT(DISTINCT publisher) FROM books")->fetchColumn();
$avg_books_per_student = $total_students > 0 ? round($issued_books / $total_students, 1) : 0;

// Get available books for dropdown
$available_books = $pdo->query("
    SELECT id, title, author, isbn, available_copies 
    FROM books 
    WHERE available_copies > 0 
    ORDER BY title ASC
")->fetchAll();

// Get active students for dropdown
$active_students = $pdo->query("
    SELECT id, full_name, Admission_number, class_id 
    FROM students 
    WHERE status = 'active' 
    ORDER BY full_name ASC
")->fetchAll();

// Get issued books for return dropdown
$issued_books_list = $pdo->query("
    SELECT 
        bi.id as issue_id,
        b.id as book_id,
        b.title,
        b.author,
        b.isbn,
        s.id as student_id,
        s.full_name as student_name,
        s.Admission_number,
        bi.issue_date,
        bi.due_date,
        DATEDIFF(CURDATE(), bi.due_date) as days_overdue
    FROM book_issues bi
    JOIN books b ON bi.book_id = b.id
    JOIN students s ON bi.student_id = s.id
    WHERE bi.return_date IS NULL
    ORDER BY 
        CASE WHEN bi.due_date < CURDATE() THEN 0 ELSE 1 END,
        bi.due_date ASC
")->fetchAll();

// Get monthly circulation data for charts (last 12 months)
$monthly_data = $pdo->query("
    SELECT 
        DATE_FORMAT(issue_date, '%Y-%m') as month,
        DATE_FORMAT(issue_date, '%b %Y') as month_name,
        COUNT(*) as total_issues,
        SUM(CASE WHEN return_date IS NOT NULL THEN 1 ELSE 0 END) as total_returns,
        COUNT(DISTINCT student_id) as unique_students
    FROM book_issues 
    WHERE issue_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(issue_date, '%Y-%m')
    ORDER BY month ASC
")->fetchAll();

// Get category distribution with percentages across both library schema variants.
$has_book_category_text = dashboardColumnExists($pdo, 'books', 'category');
$has_book_category_id = dashboardColumnExists($pdo, 'books', 'category_id');
$has_book_categories_table = false;
$book_category_label_column = null;

try {
    $has_book_categories_table = (bool) $pdo->query("SHOW TABLES LIKE 'book_categories'")->fetchColumn();
} catch (PDOException $e) {
    $has_book_categories_table = false;
}

if ($has_book_categories_table) {
    if (dashboardColumnExists($pdo, 'book_categories', 'category_name')) {
        $book_category_label_column = 'category_name';
    } elseif (dashboardColumnExists($pdo, 'book_categories', 'name')) {
        $book_category_label_column = 'name';
    }
}

if ($has_book_category_text) {
    $category_stats = $pdo->query("
        SELECT 
            category, 
            COUNT(*) as count,
            ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 1) as percentage
        FROM books 
        WHERE category IS NOT NULL AND category != ''
        GROUP BY category 
        ORDER BY count DESC 
        LIMIT 10
    ")->fetchAll();
} elseif ($has_book_category_id && $has_book_categories_table && $book_category_label_column !== null) {
    $category_stats = $pdo->query("
        SELECT 
            COALESCE(bc.{$book_category_label_column}, CONCAT('Category #', b.category_id)) as category,
            COUNT(*) as count,
            ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 1) as percentage
        FROM books b
        LEFT JOIN book_categories bc ON b.category_id = bc.id
        WHERE b.category_id IS NOT NULL
        GROUP BY b.category_id, bc.{$book_category_label_column}
        ORDER BY count DESC
        LIMIT 10
    ")->fetchAll();
} else {
    $category_stats = [];
}

// Get top borrowed books
$top_books = $pdo->query("
    SELECT 
        b.id,
        b.title,
        b.author,
        b.isbn,
        COUNT(bi.id) as times_borrowed,
        SUM(CASE WHEN bi.return_date IS NULL THEN 1 ELSE 0 END) as currently_issued
    FROM books b
    LEFT JOIN book_issues bi ON b.id = bi.book_id
    GROUP BY b.id
    ORDER BY times_borrowed DESC
    LIMIT 5
")->fetchAll();

// Get recent activities
$recent_activities = $pdo->query("
    (SELECT 
        'issued' as type,
        b.title,
        b.author,
        s.full_name as student,
        s.Admission_number,
        bi.issue_date as date,
        bi.due_date,
        NULL as return_date,
        DATEDIFF(CURDATE(), bi.due_date) as days_overdue
    FROM book_issues bi
    JOIN books b ON bi.book_id = b.id
    JOIN students s ON bi.student_id = s.id
    WHERE bi.return_date IS NULL
    ORDER BY bi.issue_date DESC
    LIMIT 5)
    UNION ALL
    (SELECT 
        'returned' as type,
        b.title,
        b.author,
        s.full_name as student,
        s.Admission_number,
        bi.return_date as date,
        NULL as due_date,
        bi.return_date,
        NULL as days_overdue
    FROM book_issues bi
    JOIN books b ON bi.book_id = b.id
    JOIN students s ON bi.student_id = s.id
    WHERE bi.return_date IS NOT NULL
    ORDER BY bi.return_date DESC
    LIMIT 5)
    ORDER BY date DESC
    LIMIT 10
")->fetchAll();

// Get overdue books with detailed info
$overdue_list = $pdo->query("
    SELECT 
        bi.*, 
        b.title, 
        b.author, 
        b.isbn,
        s.full_name, 
        s.Admission_number,
        DATEDIFF(CURDATE(), bi.due_date) as days_overdue,
        DATEDIFF(CURDATE(), bi.issue_date) as days_held
    FROM book_issues bi
    JOIN books b ON bi.book_id = b.id
    JOIN students s ON bi.student_id = s.id
    WHERE bi.return_date IS NULL AND bi.due_date < CURDATE()
    ORDER BY bi.due_date ASC
")->fetchAll();

// Calculate fine amounts (assuming $1 per day overdue)
$total_fines = 0;
foreach ($overdue_list as &$overdue) {
    $overdue['fine_amount'] = $overdue['days_overdue'] * 1.00; // $1 per day
    $total_fines += $overdue['fine_amount'];
}

// Get daily circulation for today
$today_issues = $pdo->query("
    SELECT COUNT(*) FROM book_issues WHERE DATE(issue_date) = CURDATE()
")->fetchColumn();

$today_returns = $pdo->query("
    SELECT COUNT(*) FROM book_issues WHERE DATE(return_date) = CURDATE()
")->fetchColumn();

$inventory_stats = $pdo->query("
    SELECT
        COUNT(*) AS total_items,
        COUNT(CASE WHEN quantity_in_stock <= reorder_level THEN 1 END) AS low_stock_items,
        COUNT(CASE WHEN approval_status = 'pending' THEN 1 END) AS pending_approvals,
        COUNT(CASE WHEN approval_status = 'approved' AND payment_status = 'pending' THEN 1 END) AS awaiting_payment,
        COALESCE(SUM(quantity_in_stock * unit_price), 0) AS stock_value
    FROM inventory_items
")->fetch(PDO::FETCH_ASSOC) ?: [];

$inventory_category_stats = $pdo->query("
    SELECT category, COUNT(*) AS count
    FROM inventory_items
    WHERE category IS NOT NULL AND category != ''
    GROUP BY category
    ORDER BY count DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

$inventory_trend = $pdo->query("
    SELECT
        DATE_FORMAT(created_at, '%Y-%m') AS month_key,
        DATE_FORMAT(created_at, '%b') AS month_name,
        COUNT(*) AS items_added,
        COALESCE(SUM(CASE WHEN quantity_in_stock <= reorder_level THEN 1 ELSE 0 END), 0) AS low_stock_items,
        COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END), 0) AS paid_items
    FROM inventory_items
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%b')
    ORDER BY month_key ASC
")->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Librarian Dashboard - " . SCHOOL_NAME;
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
        :root {
            --primary: #2c3e50;
            --primary-light: #34495e;
            --secondary: #3498db;
            --success: #27ae60;
            --success-light: #2ecc71;
            --danger: #e74c3c;
            --danger-light: #c0392b;
            --warning: #f39c12;
            --warning-light: #f1c40f;
            --info: #17a2b8;
            --purple: #9b59b6;
            --purple-light: #8e44ad;
            --dark: #2c3e50;
            --dark-light: #34495e;
            --gray: #7f8c8d;
            --gray-light: #95a5a6;
            --light: #ecf0f1;
            --white: #ffffff;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.1);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
            --shadow-xl: 0 20px 25px rgba(0,0,0,0.1);
            --border-radius: 12px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            min-height: calc(100vh - 70px);
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }

        /* Page Header */
        .page-header {
            background: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            border-left: 5px solid var(--secondary);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-header h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: var(--gray);
            font-size: 1rem;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary), var(--purple));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), var(--success-light));
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), var(--warning-light));
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), var(--danger-light));
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info), #138496);
            color: white;
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-outline {
            background: var(--light);
            color: var(--dark);
            border: 2px solid transparent;
        }

        .btn-outline:hover {
            background: #d5dbdb;
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        /* Alert */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-warning {
            background: rgba(243, 156, 18, 0.1);
            border-color: var(--warning);
            color: var(--warning);
        }

        .close-alert {
            margin-left: auto;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: var(--warning);
            opacity: 0.7;
        }

        .close-alert:hover {
            opacity: 1;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border-left: 4px solid;
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            transform: translate(30px, -30px);
        }

        .stat-card.books { border-left-color: var(--secondary); }
        .stat-card.available { border-left-color: var(--success); }
        .stat-card.issued { border-left-color: var(--warning); }
        .stat-card.overdue { border-left-color: var(--danger); }
        .stat-card.students { border-left-color: var(--purple); }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            z-index: 1;
        }

        .stat-card.books .stat-icon { background: linear-gradient(135deg, var(--secondary), var(--purple)); }
        .stat-card.available .stat-icon { background: linear-gradient(135deg, var(--success), var(--success-light)); }
        .stat-card.issued .stat-icon { background: linear-gradient(135deg, var(--warning), var(--warning-light)); }
        .stat-card.overdue .stat-icon { background: linear-gradient(135deg, var(--danger), var(--danger-light)); }
        .stat-card.students .stat-icon { background: linear-gradient(135deg, var(--purple), var(--purple-light)); }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .stat-sub {
            font-size: 0.8rem;
            color: var(--gray-light);
            margin-top: 0.25rem;
        }

        /* Charts Row */
        .charts-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .chart-container {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--light);
        }

        .chart-header h3 {
            font-size: 1.1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-container canvas {
            height: 300px !important;
            width: 100% !important;
        }

        /* Dashboard Row */
        .dashboard-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        /* Activity Card */
        .activity-card {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-md);
        }

        .card-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--light), #ffffff);
            border-bottom: 1px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 1.1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .view-all {
            color: var(--secondary);
            text-decoration: none;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            transition: all 0.3s;
        }

        .view-all:hover {
            color: var(--purple);
            transform: translateX(3px);
        }

        .activity-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .activity-item {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--light);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s;
        }

        .activity-item:hover {
            background: rgba(52, 152, 219, 0.05);
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: white;
            flex-shrink: 0;
        }

        .activity-icon.issued {
            background: linear-gradient(135deg, var(--success), var(--success-light));
        }

        .activity-icon.returned {
            background: linear-gradient(135deg, var(--info), #138496);
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.3rem;
        }

        .activity-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--gray);
            flex-wrap: wrap;
        }

        .activity-meta i {
            margin-right: 0.3rem;
        }

        .activity-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .activity-badge.issued {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
        }

        .activity-badge.returned {
            background: rgba(23, 162, 184, 0.1);
            color: var(--info);
        }

        /* Quick Actions Card */
        .quick-actions-card {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-md);
        }

        .actions-grid {
            padding: 1.5rem;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .action-btn {
            padding: 1.5rem 1rem;
            background: var(--light);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            color: var(--dark);
            transition: all 0.3s;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
        }

        .action-btn:hover {
            background: linear-gradient(135deg, var(--secondary), var(--purple));
            color: white;
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .action-btn i {
            font-size: 2rem;
            color: var(--secondary);
        }

        .action-btn:hover i {
            color: white;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1050;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
            z-index: 1052;
        }

        .modal-header h3 {
            font-size: 1.3rem;
            color: var(--dark);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .modal-close:hover {
            background: var(--light);
            color: var(--dark);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--light);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            position: sticky;
            bottom: 0;
            background: white;
            z-index: 1052;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--light);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
            background: white;
        }

        .form-control:focus {
            border-color: var(--secondary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        select.form-control {
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%232c3e50' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 16px;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
        }

        .info-box {
            background: rgba(52, 152, 219, 0.05);
            border: 1px dashed var(--secondary);
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .info-box i {
            color: var(--secondary);
            font-size: 1.2rem;
        }

        .info-box-content {
            flex: 1;
        }

        .info-box-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.3rem;
        }

        .info-box-text {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Overdue Section */
        .overdue-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--light);
        }

        .section-header h3 {
            font-size: 1.1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
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
            font-size: 0.9rem;
            white-space: nowrap;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid var(--light);
            color: var(--dark);
        }

        tr:hover {
            background: rgba(52, 152, 219, 0.05);
        }

        .badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-danger {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }

        .badge-warning {
            background: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }

        .badge-success {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
        }

        .badge-info {
            background: rgba(23, 162, 184, 0.1);
            color: var(--info);
        }

        /* Mini Stats Row */
        .mini-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .mini-stat {
            background: white;
            border-radius: var(--border-radius);
            padding: 1rem;
            text-align: center;
            box-shadow: var(--shadow-sm);
        }

        .mini-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--secondary);
            margin-bottom: 0.3rem;
        }

        .mini-stat-label {
            font-size: 0.8rem;
            color: var(--gray);
        }

        /* Top Books Grid */
        .top-books {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .book-card {
            background: var(--light);
            border-radius: 8px;
            padding: 1rem;
            transition: all 0.3s;
            cursor: pointer;
        }

        .book-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .book-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.3rem;
        }

        .book-author {
            font-size: 0.8rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
        }

        .book-stats {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: var(--secondary);
        }

        /* No Data */
        .no-data {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--gray-light);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .dashboard-row {
                grid-template-columns: 1fr;
            }
            
            .charts-row {
                grid-template-columns: 1fr;
            }
            
            .mini-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                text-align: center;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .actions-grid {
                grid-template-columns: 1fr;
            }
            
            .top-books {
                grid-template-columns: 1fr;
            }
            
            .header-actions {
                justify-content: center;
            }
            
            .modal-content {
                width: 95%;
                margin: 1rem;
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
                <h1><i class="fas fa-book" style="color: var(--secondary); margin-right: 0.5rem;"></i>Library Dashboard</h1>
                <p>Welcome back, <?php echo htmlspecialchars($_SESSION['full_name']); ?>! Manage your library efficiently.</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-success" onclick="openModal('quickReturnModal')">
                    <i class="fas fa-undo-alt"></i> Quick Return
                </button>
                <a href="reports.php" class="btn btn-info">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>
            </div>
        </div>

        <!-- Overdue Alert -->
        <?php if (count($overdue_list) > 0): ?>
        <div class="alert alert-warning animate" id="overdueAlert">
            <i class="fas fa-exclamation-triangle"></i>
            <div>
                <strong><?php echo count($overdue_list); ?> books are overdue!</strong>
                <span style="margin-left: 1rem;">Total fines: $<?php echo number_format($total_fines, 2); ?></span>
            </div>
            <button class="btn btn-sm btn-warning" onclick="window.location.href='circulations.php?status=overdue'">View Details</button>
            <button class="close-alert" onclick="document.getElementById('overdueAlert').style.display='none'">&times;</button>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="stats-grid animate">
            <div class="stat-card books" onclick="window.location.href='books.php'">
                <div class="stat-header">
                    <span class="stat-label">Total Books</span>
                    <div class="stat-icon"><i class="fas fa-book"></i></div>
                </div>
                <div class="stat-value"><?php echo number_format($total_books); ?></div>
                <div class="stat-label">Titles</div>
                <div class="stat-sub"><?php echo number_format($total_copies); ?> copies</div>
            </div>

            <div class="stat-card available" onclick="window.location.href='books.php?status=available'">
                <div class="stat-header">
                    <span class="stat-label">Available</span>
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                </div>
                <div class="stat-value"><?php echo number_format($available_copies); ?></div>
                <div class="stat-label">Copies Available</div>
                <div class="stat-sub"><?php echo $total_copies - $available_copies; ?> issued</div>
            </div>

            <div class="stat-card issued" onclick="window.location.href='circulations.php?status=issued'">
                <div class="stat-header">
                    <span class="stat-label">Issued</span>
                    <div class="stat-icon"><i class="fas fa-book-open"></i></div>
                </div>
                <div class="stat-value"><?php echo number_format($issued_books); ?></div>
                <div class="stat-label">Currently Issued</div>
                <div class="stat-sub"><?php echo $avg_books_per_student; ?> per student</div>
            </div>

            <div class="stat-card overdue" onclick="window.location.href='circulations.php?status=overdue'">
                <div class="stat-header">
                    <span class="stat-label">Overdue</span>
                    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                </div>
                <div class="stat-value"><?php echo number_format($overdue_books); ?></div>
                <div class="stat-label">Overdue Books</div>
                <div class="stat-sub">$<?php echo number_format($total_fines, 2); ?> in fines</div>
            </div>

            <div class="stat-card students" onclick="window.location.href='../students/'">
                <div class="stat-header">
                    <span class="stat-label">Students</span>
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                </div>
                <div class="stat-value"><?php echo number_format($total_students); ?></div>
                <div class="stat-label">Active Students</div>
                <div class="stat-sub">Eligible to borrow</div>
            </div>
        </div>

        <!-- Mini Stats -->
        <div class="mini-stats animate">
            <div class="mini-stat">
                <div class="mini-stat-value"><?php echo $today_issues; ?></div>
                <div class="mini-stat-label">Today's Issues</div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-value"><?php echo $today_returns; ?></div>
                <div class="mini-stat-label">Today's Returns</div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-value"><?php echo $total_authors; ?></div>
                <div class="mini-stat-label">Authors</div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-value"><?php echo $total_publishers; ?></div>
                <div class="mini-stat-label">Publishers</div>
            </div>
        </div>

        <div class="mini-stats animate">
            <div class="mini-stat">
                <div class="mini-stat-value"><?php echo number_format((int)($inventory_stats['total_items'] ?? 0)); ?></div>
                <div class="mini-stat-label">Inventory Items</div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-value"><?php echo number_format((int)($inventory_stats['low_stock_items'] ?? 0)); ?></div>
                <div class="mini-stat-label">Low Stock</div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-value"><?php echo number_format((int)($inventory_stats['pending_approvals'] ?? 0)); ?></div>
                <div class="mini-stat-label">Pending Approvals</div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-value">KES <?php echo number_format(((float)($inventory_stats['stock_value'] ?? 0))/1000, 0); ?>k</div>
                <div class="mini-stat-label">Stock Value</div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="charts-row animate">
            <div class="chart-container">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-line" style="color: var(--secondary);"></i> 12-Month Circulation Trend</h3>
                    <span class="badge badge-info">Issues vs Returns</span>
                </div>
                <canvas id="circulationChart"></canvas>
            </div>
            
            <div class="chart-container">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-pie" style="color: var(--purple);"></i> Books by Category</h3>
                    <span class="badge badge-info">Top 10 Categories</span>
                </div>
                <canvas id="categoryChart"></canvas>
            </div>
        </div>

        <div class="charts-row animate">
            <div class="chart-container">
                <div class="chart-header">
                    <h3><i class="fas fa-boxes-stacked" style="color: var(--info);"></i> Inventory Trend</h3>
                    <span class="badge badge-info">Last 6 Months</span>
                </div>
                <canvas id="inventoryTrendChart"></canvas>
            </div>

            <div class="chart-container">
                <div class="chart-header">
                    <h3><i class="fas fa-tags" style="color: var(--success);"></i> Inventory by Category</h3>
                    <span class="badge badge-info">Top Categories</span>
                </div>
                <canvas id="inventoryCategoryChart"></canvas>
            </div>
        </div>

        <!-- Dashboard Row -->
        <div class="dashboard-row animate">
            <!-- Recent Activities -->
            <div class="activity-card">
                <div class="card-header">
                    <h3><i class="fas fa-history"></i> Recent Activities</h3>
                    <a href="reports.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="activity-list">
                    <?php if (empty($recent_activities)): ?>
                        <div class="no-data">
                            <i class="fas fa-inbox"></i>
                            <p>No recent activities</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recent_activities as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon <?php echo $activity['type']; ?>">
                                <i class="fas fa-<?php echo $activity['type'] == 'issued' ? 'book-open' : 'undo'; ?>"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">
                                    <strong><?php echo htmlspecialchars($activity['title']); ?></strong>
                                    <?php if ($activity['type'] == 'issued' && $activity['days_overdue'] > 0): ?>
                                    <span class="badge badge-danger" style="margin-left: 0.5rem;">Overdue</span>
                                    <?php endif; ?>
                                </div>
                                <div class="activity-meta">
                                    <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($activity['student']); ?></span>
                                    <span><i class="fas fa-clock"></i> <?php echo date('M d, H:i', strtotime($activity['date'])); ?></span>
                                    <?php if ($activity['type'] == 'issued' && $activity['due_date']): ?>
                                    <span><i class="fas fa-calendar"></i> Due: <?php echo date('M d', strtotime($activity['due_date'])); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="activity-badge <?php echo $activity['type']; ?>">
                                <?php echo ucfirst($activity['type']); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions-card">
                <div class="card-header">
                    <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                </div>
                <div class="actions-grid">
                    <button class="action-btn" onclick="window.location.href='books.php?action=add'">
                        <i class="fas fa-plus-circle"></i>
                        <span>Add Book</span>
                    </button>
                    <button class="action-btn" onclick="openModal('quickIssueModal')">
                        <i class="fas fa-book-open"></i>
                        <span>Issue Book</span>
                    </button>
                    <button class="action-btn" onclick="openModal('quickReturnModal')">
                        <i class="fas fa-undo-alt"></i>
                        <span>Return Book</span>
                    </button>
                    <button class="action-btn" onclick="window.location.href='books.php'">
                        <i class="fas fa-search"></i>
                        <span>Search Books</span>
                    </button>
                    <button class="action-btn" onclick="window.location.href='circulations.php'">
                        <i class="fas fa-list"></i>
                        <span>Circulations</span>
                    </button>
                    <button class="action-btn" onclick="window.location.href='reports.php'">
                        <i class="fas fa-file-pdf"></i>
                        <span>Reports</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Top Books Section -->
        <?php if (!empty($top_books)): ?>
        <div class="overdue-section animate">
            <div class="section-header">
                <h3><i class="fas fa-star" style="color: var(--warning);"></i> Most Borrowed Books</h3>
                <a href="reports.php?type=popular" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="top-books">
                <?php foreach ($top_books as $book): ?>
                <div class="book-card" onclick="window.location.href='book_details.php?id=<?php echo $book['id']; ?>'">
                    <div class="book-title"><?php echo htmlspecialchars($book['title']); ?></div>
                    <div class="book-author">by <?php echo htmlspecialchars($book['author']); ?></div>
                    <div class="book-stats">
                        <span><i class="fas fa-book-open"></i> <?php echo $book['times_borrowed']; ?> times</span>
                        <?php if ($book['currently_issued'] > 0): ?>
                        <span class="badge badge-warning"><?php echo $book['currently_issued']; ?> issued</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Overdue Books List -->
        <?php if (!empty($overdue_list)): ?>
        <div class="overdue-section animate">
            <div class="section-header">
                <h3><i class="fas fa-exclamation-circle" style="color: var(--danger);"></i> Overdue Books</h3>
                <a href="circulations.php?status=overdue" class="view-all">View All Overdue <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Book</th>
                            <th>Student</th>
                            <th>Due Date</th>
                            <th>Days Overdue</th>
                            <th>Fine</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($overdue_list as $overdue): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($overdue['title']); ?></strong>
                                <br><small>by <?php echo htmlspecialchars($overdue['author']); ?></small>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($overdue['full_name']); ?>
                                <br><small><?php echo htmlspecialchars($overdue['Admission_number']); ?></small>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($overdue['due_date'])); ?></td>
                            <td><span class="badge badge-danger"><?php echo $overdue['days_overdue']; ?> days</span></td>
                            <td><span class="badge badge-warning">$<?php echo number_format($overdue['fine_amount'], 2); ?></span></td>
                            <td>
                                <button class="btn btn-sm btn-success" onclick="returnBook(<?php echo $overdue['id']; ?>)">
                                    <i class="fas fa-undo"></i> Return
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Quick Issue Modal -->
    <div id="quickIssueModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-book-open" style="color: var(--success);"></i> Quick Issue Book</h3>
                <button class="modal-close" onclick="closeModal('quickIssueModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="quickIssueForm">
                    <div class="form-group">
                        <label for="issue_book_select">Select Book <span class="required" style="color: var(--danger);">*</span></label>
                        <select id="issue_book_select" class="form-control" required>
                            <option value="">-- Choose a book --</option>
                            <?php foreach ($available_books as $book): ?>
                            <option value="<?php echo $book['id']; ?>" data-available="<?php echo $book['available_copies']; ?>">
                                <?php echo htmlspecialchars($book['title']); ?> by <?php echo htmlspecialchars($book['author']); ?> (ISBN: <?php echo htmlspecialchars($book['isbn']); ?>) - Available: <?php echo $book['available_copies']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($available_books)): ?>
                        <div class="info-box">
                            <i class="fas fa-exclamation-circle"></i>
                            <div class="info-box-content">
                                <div class="info-box-title">No books available</div>
                                <div class="info-box-text">All books are currently issued. Add new books or wait for returns.</div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="issue_student_select">Select Student <span class="required" style="color: var(--danger);">*</span></label>
                        <select id="issue_student_select" class="form-control" required>
                            <option value="">-- Choose a student --</option>
                            <?php foreach ($active_students as $student): ?>
                            <option value="<?php echo $student['id']; ?>">
                                <?php echo htmlspecialchars($student['full_name']); ?> (<?php echo htmlspecialchars($student['Admission_number']); ?>) - <?php echo htmlspecialchars($student['class_id']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="issue_due_date">Due Date <span class="required" style="color: var(--danger);">*</span></label>
                        <input type="date" id="issue_due_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>" required>
                    </div>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <div class="info-box-content">
                            <div class="info-box-title">Borrowing Rules</div>
                            <div class="info-box-text">Standard loan period is 14 days. Overdue fines are $1 per day.</div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('quickIssueModal')">Cancel</button>
                <button class="btn btn-success" onclick="processQuickIssue()" id="processIssueBtn">Issue Book</button>
            </div>
        </div>
    </div>

    <!-- Quick Return Modal -->
    <div id="quickReturnModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-undo-alt" style="color: var(--info);"></i> Quick Return Book</h3>
                <button class="modal-close" onclick="closeModal('quickReturnModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="quickReturnForm">
                    <div class="form-group">
                        <label for="return_issue_select">Select Issued Book <span class="required" style="color: var(--danger);">*</span></label>
                        <select id="return_issue_select" class="form-control" required>
                            <option value="">-- Choose an issued book --</option>
                            <?php foreach ($issued_books_list as $issue): ?>
                            <option value="<?php echo $issue['issue_id']; ?>" 
                                    data-book="<?php echo htmlspecialchars($issue['title']); ?>"
                                    data-student="<?php echo htmlspecialchars($issue['student_name']); ?>"
                                    data-due="<?php echo $issue['due_date']; ?>"
                                    data-overdue="<?php echo $issue['days_overdue'] > 0 ? $issue['days_overdue'] : 0; ?>">
                                <?php echo htmlspecialchars($issue['title']); ?> - 
                                <?php echo htmlspecialchars($issue['student_name']); ?> 
                                (Due: <?php echo date('M d, Y', strtotime($issue['due_date'])); ?>)
                                <?php if ($issue['days_overdue'] > 0): ?>
                                - <span style="color: var(--danger);"><?php echo $issue['days_overdue']; ?> days overdue</span>
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($issued_books_list)): ?>
                        <div class="info-box">
                            <i class="fas fa-check-circle" style="color: var(--success);"></i>
                            <div class="info-box-content">
                                <div class="info-box-title">No books issued</div>
                                <div class="info-box-text">All books have been returned. No pending returns.</div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="return_condition">Book Condition</label>
                        <select id="return_condition" class="form-control">
                            <option value="Excellent">Excellent</option>
                            <option value="Good" selected>Good</option>
                            <option value="Fair">Fair</option>
                            <option value="Poor">Poor</option>
                            <option value="Damaged">Damaged</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="return_notes">Notes (Optional)</label>
                        <textarea id="return_notes" class="form-control" rows="2" placeholder="Any damage or additional notes..."></textarea>
                    </div>

                    <div id="overdueInfo" class="info-box" style="display: none;">
                        <i class="fas fa-exclamation-triangle" style="color: var(--warning);"></i>
                        <div class="info-box-content">
                            <div class="info-box-title" id="overdueTitle">Overdue Book</div>
                            <div class="info-box-text" id="overdueText">This book is overdue. Fine amount will be calculated.</div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('quickReturnModal')">Cancel</button>
                <button class="btn btn-info" onclick="processQuickReturn()" id="processReturnBtn">Process Return</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Circulation Chart
            const ctx1 = document.getElementById('circulationChart').getContext('2d');
            new Chart(ctx1, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($monthly_data, 'month_name')); ?>,
                    datasets: [{
                        label: 'Issued',
                        data: <?php echo json_encode(array_column($monthly_data, 'total_issues')); ?>,
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#3498db',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4
                    }, {
                        label: 'Returned',
                        data: <?php echo json_encode(array_column($monthly_data, 'total_returns')); ?>,
                        borderColor: '#27ae60',
                        backgroundColor: 'rgba(39, 174, 96, 0.1)',
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#27ae60',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                padding: 20
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0,0,0,0.05)'
                            }
                        }
                    }
                }
            });

            // Category Chart
            const ctx2 = document.getElementById('categoryChart').getContext('2d');
            new Chart(ctx2, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode(array_column($category_stats, 'category')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column($category_stats, 'count')); ?>,
                        backgroundColor: [
                            '#3498db', '#27ae60', '#e74c3c', '#f39c12', '#9b59b6',
                            '#1abc9c', '#34495e', '#16a085', '#27ae60', '#2980b9'
                        ],
                        borderWidth: 3,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                padding: 20
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return `${label}: ${value} books (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });

            const inventoryTrendCanvas = document.getElementById('inventoryTrendChart');
            if (inventoryTrendCanvas) {
                new Chart(inventoryTrendCanvas.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode(array_column($inventory_trend, 'month_name')); ?>,
                        datasets: [{
                            label: 'Items Added',
                            data: <?php echo json_encode(array_map('intval', array_column($inventory_trend, 'items_added'))); ?>,
                            borderColor: '#17a2b8',
                            backgroundColor: 'rgba(23, 162, 184, 0.12)',
                            tension: 0.35,
                            fill: true
                        }, {
                            label: 'Paid Items',
                            data: <?php echo json_encode(array_map('intval', array_column($inventory_trend, 'paid_items'))); ?>,
                            borderColor: '#27ae60',
                            backgroundColor: 'rgba(39, 174, 96, 0.08)',
                            tension: 0.35,
                            fill: false
                        }]
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
                                beginAtZero: true
                            }
                        }
                    }
                });
            }

            const inventoryCategoryCanvas = document.getElementById('inventoryCategoryChart');
            if (inventoryCategoryCanvas) {
                new Chart(inventoryCategoryCanvas.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode(array_column($inventory_category_stats, 'category')); ?>,
                        datasets: [{
                            label: 'Items',
                            data: <?php echo json_encode(array_map('intval', array_column($inventory_category_stats, 'count'))); ?>,
                            backgroundColor: ['#3498db', '#27ae60', '#f39c12', '#9b59b6', '#e74c3c', '#1abc9c', '#34495e', '#16a085'],
                            borderRadius: 8
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
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            }

            // Welcome message
            Swal.fire({
                icon: 'success',
                title: 'Welcome Back!',
                text: '<?php echo htmlspecialchars($_SESSION["full_name"]); ?>, ready to manage the library?',
                timer: 3000,
                showConfirmButton: false,
                toast: true,
                position: 'top-end'
            });

            // Overdue alert
            <?php if (count($overdue_list) > 0): ?>
            Swal.fire({
                icon: 'warning',
                title: 'Overdue Books Alert',
                html: 'There are <strong><?php echo count($overdue_list); ?></strong> overdue books with total fines of <strong>$<?php echo number_format($total_fines, 2); ?></strong>.',
                showCancelButton: true,
                confirmButtonText: 'View Overdue',
                cancelButtonText: 'Later'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'circulations.php?status=overdue';
                }
            });
            <?php endif; ?>

            // Setup return select change handler
            const returnSelect = document.getElementById('return_issue_select');
            if (returnSelect) {
                returnSelect.addEventListener('change', function() {
                    const selected = this.options[this.selectedIndex];
                    const overdueDays = selected.getAttribute('data-overdue');
                    const overdueInfo = document.getElementById('overdueInfo');
                    
                    if (overdueDays && parseInt(overdueDays) > 0) {
                        const fineAmount = (parseInt(overdueDays) * 1.00).toFixed(2);
                        document.getElementById('overdueTitle').innerHTML = 'Overdue Book';
                        document.getElementById('overdueText').innerHTML = `This book is ${overdueDays} days overdue. Fine amount: $${fineAmount}`;
                        overdueInfo.style.display = 'flex';
                    } else {
                        overdueInfo.style.display = 'none';
                    }
                });
            }
        });

        // Modal Functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = 'auto';
            
            // Reset forms
            if (modalId === 'quickIssueModal') {
                document.getElementById('quickIssueForm').reset();
            } else if (modalId === 'quickReturnModal') {
                document.getElementById('quickReturnForm').reset();
                document.getElementById('overdueInfo').style.display = 'none';
            }
        }

        // Process Quick Issue
        function processQuickIssue() {
            const bookSelect = document.getElementById('issue_book_select');
            const studentSelect = document.getElementById('issue_student_select');
            const dueDate = document.getElementById('issue_due_date').value;

            if (!bookSelect.value) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Select Book',
                    text: 'Please select a book to issue'
                });
                return;
            }

            if (!studentSelect.value) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Select Student',
                    text: 'Please select a student'
                });
                return;
            }

            if (!dueDate) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Select Due Date',
                    text: 'Please select a due date'
                });
                return;
            }

            Swal.fire({
                title: 'Processing Issue',
                html: 'Please wait...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            const formData = new FormData();
            formData.append('action', 'issue_book');
            formData.append('book_id', bookSelect.value);
            formData.append('student_id', studentSelect.value);
            formData.append('due_date', dueDate);

            fetch('ajax_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: 'Book issued successfully',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed',
                        text: data.error || 'Could not issue book'
                    });
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred'
                });
            });
        }

        // Process Quick Return
        function processQuickReturn() {
            const issueSelect = document.getElementById('return_issue_select');
            const condition = document.getElementById('return_condition').value;
            const notes = document.getElementById('return_notes').value;

            if (!issueSelect.value) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Select Book',
                    text: 'Please select a book to return'
                });
                return;
            }

            Swal.fire({
                title: 'Processing Return',
                html: 'Please wait...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            const formData = new FormData();
            formData.append('action', 'return_book');
            formData.append('issue_id', issueSelect.value);
            formData.append('condition', condition);
            formData.append('notes', notes);

            fetch('ajax_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: 'Book returned successfully',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed',
                        text: data.error || 'Could not return book'
                    });
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred'
                });
            });
        }

        // Return Book from Overdue List
        function returnBook(issueId) {
            // Find and select the issue in the return modal
            const returnSelect = document.getElementById('return_issue_select');
            
            // Set the value
            returnSelect.value = issueId;
            
            // Trigger change event to show overdue info
            const event = new Event('change', { bubbles: true });
            returnSelect.dispatchEvent(event);
            
            // Open the return modal
            openModal('quickReturnModal');
        }

        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(modal => {
                    modal.classList.remove('active');
                });
                document.body.style.overflow = 'auto';
            }
        });
    </script>
</body>
</html>
