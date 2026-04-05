<?php
include '../config.php';
checkAuth();
checkRole(['accountant', 'admin']);

$page_title = 'Financial Dashboard - ' . SCHOOL_NAME;

// Initialize all variables with defaults
$total_invoiced = 0;
$total_paid = 0;
$total_expenses = 0;
$total_income = 0;
$today_income = 0;
$today_paid = 0;
$today_expenses = 0;
$pending_invoices = 0;
$pending_payments = 0;
$pending_expenses = 0;
$overdue_invoices = 0;
$overdue_payments = 0;
$unpaid_balance = 0;
$students_with_balance = 0;
$fully_paid_students = 0;
$monthly_collected = 0;
$monthly_income = 0;
$monthly_expenses_value = 0;
$monthly_invoiced = 0;
$financial_balance = 0;
$balance = 0;
$recent_payments = [];
$recent_expenses = [];
$monthly_payments = [];
$monthly_expenses_data = [];
$top_expense_categories = [];
$top_income_sources = [];
$recent_activity = [];
$payment_methods = [];
$error_message = null;

function dashboardTableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

$hasBookFines = dashboardTableExists($pdo, 'book_fines');
$hasLostBooks = dashboardTableExists($pdo, 'lost_books');

try {
    // Get comprehensive statistics from correct database tables
    $invoiceOpenCondition = "((status IN ('issued', 'partially_paid', 'overdue')) OR (COALESCE(status, '') = '' AND balance > 0))";
    $invoiceClosedCondition = "((status = 'paid') OR (COALESCE(status, '') = '' AND balance <= 0))";
    $paymentReceivedCondition = "(status IN ('completed', 'paid', 'verified') OR COALESCE(status, '') = '')";
    $expenseTrackedCondition = "(COALESCE(status, '') <> '' OR amount > 0)";
    $paymentReceivedConditionAliased = "(p.status IN ('completed', 'paid', 'verified') OR COALESCE(p.status, '') = '')";
    $expenseTrackedConditionAliased = "(COALESCE(e.status, '') <> '' OR e.amount > 0)";
    
    // Total invoiced amount
    $total_invoiced = (float) ($pdo->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM invoices")->fetchColumn() ?? 0);
    
    // Total payments received
    $total_paid = (float) ($pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE $paymentReceivedCondition")->fetchColumn() ?? 0);
    
    // Total expenses tracked
    $total_expenses = (float) ($pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE $expenseTrackedCondition")->fetchColumn() ?? 0);
    
    // Outstanding balance
    $unpaid_balance = (float) ($pdo->query("SELECT COALESCE(SUM(balance), 0) as total FROM invoices WHERE $invoiceOpenCondition")->fetchColumn() ?? 0);
    
    // Financial balance
    $financial_balance = $total_paid - $total_expenses;
    $balance = $financial_balance;

    // Get today's statistics
    $today = date('Y-m-d');
    $today_paid = (float) ($pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE $paymentReceivedCondition AND DATE(payment_date) = '$today'")->fetchColumn() ?? 0);
    $today_expenses = (float) ($pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE $expenseTrackedCondition AND DATE(expense_date) = '$today'")->fetchColumn() ?? 0);

    // Get monthly statistics
    $current_month = date('Y-m');
    $monthly_collected = (float) ($pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE $paymentReceivedCondition AND DATE_FORMAT(payment_date, '%Y-%m') = '$current_month'")->fetchColumn() ?? 0);
    $monthly_invoiced = (float) ($pdo->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM invoices WHERE DATE_FORMAT(created_at, '%Y-%m') = '$current_month'")->fetchColumn() ?? 0);
    $monthly_expenses_value = (float) ($pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE $expenseTrackedCondition AND DATE_FORMAT(expense_date, '%Y-%m') = '$current_month'")->fetchColumn() ?? 0);

    // Get student payment statistics
    $total_students = (int) ($pdo->query("SELECT COUNT(DISTINCT student_id) as count FROM invoices")->fetchColumn() ?? 0);
    $students_with_balance = (int) ($pdo->query("SELECT COUNT(DISTINCT student_id) as count FROM invoices WHERE $invoiceOpenCondition")->fetchColumn() ?? 0);
    $fully_paid_students = (int) ($pdo->query("SELECT COUNT(DISTINCT student_id) as count FROM invoices WHERE $invoiceClosedCondition")->fetchColumn() ?? 0);

    // Get pending records
    $pending_invoices = (int) ($pdo->query("SELECT COUNT(*) as count FROM invoices WHERE $invoiceOpenCondition")->fetchColumn() ?? 0);
    $pending_payments = (int) ($pdo->query("SELECT COUNT(*) as count FROM payments WHERE status IN ('pending', 'failed')")->fetchColumn() ?? 0);
    $pending_expenses = (int) ($pdo->query("SELECT COUNT(*) as count FROM expenses WHERE status IN ('pending', 'submitted')")->fetchColumn() ?? 0);
    $overdue_invoices = (int) ($pdo->query("SELECT COUNT(*) as count FROM invoices WHERE $invoiceOpenCondition AND due_date < CURDATE()")->fetchColumn() ?? 0);
    $overdue_payments = (int) ($pdo->query("SELECT COUNT(*) as count FROM payments WHERE status = 'pending' AND payment_date < DATE_SUB(CURDATE(), INTERVAL 3 DAY)")->fetchColumn() ?? 0);

    // Get library fines statistics
    $pending_library_fines = $hasBookFines ? (int) ($pdo->query("SELECT COUNT(*) as count FROM book_fines WHERE status = 'sent_to_accountant'")->fetchColumn() ?? 0) : 0;
    $pending_lost_books = $hasLostBooks ? (int) ($pdo->query("SELECT COUNT(*) as count FROM lost_books WHERE status = 'sent_to_accountant'")->fetchColumn() ?? 0) : 0;
    $total_pending_library_charges = $pending_library_fines + $pending_lost_books;
    
    // Get total amount for pending library charges
    $pending_library_amount = 0;
    if ($hasBookFines) {
        $pending_library_amount += (float) ($pdo->query("SELECT COALESCE(SUM(amount), 0) FROM book_fines WHERE status = 'sent_to_accountant'")->fetchColumn() ?? 0);
    }
    if ($hasLostBooks) {
        $pending_library_amount += (float) ($pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM lost_books WHERE status = 'sent_to_accountant'")->fetchColumn() ?? 0);
    }

    // Get recent payments with student info
    $recent_payments = $pdo->query("SELECT p.id, p.amount, p.payment_date, 
                                           COALESCE(pm.label,
                                               CASE 
                                                   WHEN p.mpesa_receipt IS NOT NULL AND p.mpesa_receipt <> '' THEN 'M-Pesa'
                                                   WHEN p.transaction_ref IS NOT NULL AND p.transaction_ref <> '' THEN 'Reference'
                                                   ELSE 'Recorded Payment'
                                               END
                                           ) as payment_method,
                                           p.status,
                                           COALESCE(s.full_name, si.full_name, CONCAT('Student #', COALESCE(p.student_id, i.student_id))) as student_name,
                                           COALESCE(s.Admission_number, si.Admission_number) as admission_number,
                                           i.invoice_no,
                                           p.transaction_ref,
                                           p.mpesa_receipt
                                    FROM payments p 
                                    LEFT JOIN invoices i ON p.invoice_id = i.id 
                                    LEFT JOIN students s ON p.student_id = s.id 
                                    LEFT JOIN students si ON i.student_id = si.id
                                    LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
                                    WHERE $paymentReceivedConditionAliased
                                    ORDER BY p.payment_date DESC LIMIT 8")->fetchAll() ?? [];

    // Get recent expenses
    $recent_expenses = $pdo->query("SELECT e.id, e.description, e.amount, e.status, e.expense_date, e.created_at,
                                           COALESCE(ec.name, CONCAT('Category #', e.category_id)) as category,
                                           u.full_name as created_by_name
                                    FROM expenses e
                                    LEFT JOIN expense_categories ec ON e.category_id = ec.id
                                    LEFT JOIN users u ON COALESCE(e.created_by, e.recorded_by, e.requested_by) = u.id
                                    ORDER BY expense_date DESC LIMIT 8")->fetchAll() ?? [];

    // Get payment summary by month (last 12 months)
    $monthly_payments = $pdo->query("SELECT 
                                        DATE_FORMAT(payment_date, '%Y-%m') as month,
                                        DATE_FORMAT(payment_date, '%b %Y') as month_name,
                                        COALESCE(SUM(amount), 0) as total,
                                        COUNT(*) as transaction_count
                                      FROM payments 
                                      WHERE $paymentReceivedCondition AND payment_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                                      GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
                                      ORDER BY month ASC")->fetchAll(PDO::FETCH_ASSOC) ?? [];

    // Get expense summary by month (last 12 months)
    $monthly_expenses_data = $pdo->query("SELECT 
                                        DATE_FORMAT(expense_date, '%Y-%m') as month,
                                        DATE_FORMAT(expense_date, '%b %Y') as month_name,
                                        COALESCE(SUM(amount), 0) as total,
                                        COUNT(*) as expense_count
                                      FROM expenses 
                                      WHERE $expenseTrackedCondition AND expense_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                                      GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
                                      ORDER BY month ASC")->fetchAll(PDO::FETCH_ASSOC) ?? [];

    // Get top expense categories
    $top_expense_categories = $pdo->query("SELECT COALESCE(ec.name, CONCAT('Category #', e.category_id)) as category, COALESCE(SUM(e.amount), 0) as total, COUNT(*) as count
                                          FROM expenses e
                                          LEFT JOIN expense_categories ec ON e.category_id = ec.id
                                          WHERE $expenseTrackedConditionAliased AND e.expense_date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
                                          GROUP BY e.category_id, ec.name
                                          ORDER BY total DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC) ?? [];

    // Get payment methods distribution
    $payment_methods = $pdo->query("SELECT pm.label as payment_method, COUNT(*) as count, COALESCE(SUM(p.amount), 0) as total
                                   FROM payments p
                                   LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
                                   WHERE $paymentReceivedConditionAliased AND p.payment_date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
                                   GROUP BY p.payment_method_id, pm.label
                                   ORDER BY total DESC")->fetchAll(PDO::FETCH_ASSOC) ?? [];
    
    // Get recent activity (combined from various tables)
    $recent_activity = $pdo->query("(SELECT 'payment' as type, CONCAT('Payment of KES ', amount, ' received') as description, created_at, 'System' as user_name FROM payments ORDER BY created_at DESC LIMIT 5)
                                    UNION ALL
                                    (SELECT 'expense' as type, CONCAT('Expense of KES ', e.amount, ' for ', COALESCE(ec.name, CONCAT('Category #', e.category_id))) as description, e.created_at, 'System' as user_name
                                     FROM expenses e
                                     LEFT JOIN expense_categories ec ON e.category_id = ec.id
                                     ORDER BY e.created_at DESC LIMIT 5)
                                    ORDER BY created_at DESC LIMIT 10")->fetchAll() ?? [];
    
} catch (PDOException $e) {
    error_log("Dashboard Error: " . $e->getMessage());
    $error_message = "Error loading financial data. Please try again.";
}

// Calculate collection rate
$collection_rate = $total_invoiced > 0 ? round(($total_paid / $total_invoiced) * 100, 1) : 0;
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
            --gradient-finance: linear-gradient(135deg, #072b31 0%, #0f766e 60%, #14b8a6 100%);
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
            background: var(--gradient-finance);
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

        .date-display {
            background: rgba(255,255,255,0.15);
            padding: 1rem 2rem;
            border-radius: 50px;
            text-align: center;
            border: 1px solid rgba(255,255,255,0.2);
            backdrop-filter: blur(5px);
        }

        .date-display .day {
            font-size: 1.8rem;
            font-weight: 700;
            line-height: 1;
        }

        .date-display .month-year {
            font-size: 0.9rem;
            opacity: 0.9;
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
            display: flex;
            align-items: center;
            gap: 1.5rem;
            transition: var(--transition);
            border-left: 4px solid;
        }

        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .kpi-card.revenue { border-left-color: var(--success); }
        .kpi-card.expenses { border-left-color: var(--danger); }
        .kpi-card.balance { border-left-color: var(--primary); }
        .kpi-card.pending { border-left-color: var(--warning); }

        .kpi-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
        }

        .kpi-card.revenue .kpi-icon { background: var(--gradient-3); }
        .kpi-card.expenses .kpi-icon { background: var(--gradient-2); }
        .kpi-card.balance .kpi-icon { background: var(--gradient-1); }
        .kpi-card.pending .kpi-icon { background: var(--gradient-5); }

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
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            line-height: 1.2;
        }

        .kpi-trend {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            margin-top: 0.3rem;
        }

        .trend-up { color: var(--success); }
        .trend-down { color: var(--danger); }

        /* Collection Rate Card */
        .collection-card {
            background: var(--gradient-1);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-lg);
        }

        .collection-info h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.3rem;
            opacity: 0.9;
        }

        .collection-rate {
            font-size: 2.5rem;
            font-weight: 700;
        }

        .collection-stats {
            display: flex;
            gap: 2rem;
        }

        .stat-item {
            text-align: center;
        }

        .stat-item .value {
            font-size: 1.3rem;
            font-weight: 700;
        }

        .stat-item .label {
            font-size: 0.8rem;
            opacity: 0.9;
        }

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

        /* Tables Grid */
        .tables-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
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
            font-size: 0.85rem;
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
            font-size: 0.8rem;
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
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-completed {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .status-pending {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        .status-approved {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .status-rejected {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .action-btn {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.2rem 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            color: var(--dark);
            transition: var(--transition);
            border: 1px solid var(--light);
            box-shadow: var(--shadow-sm);
        }

        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-xl);
            border-color: var(--primary);
            background: linear-gradient(135deg, #fff, #f8f9fa);
        }

        .action-btn i {
            font-size: 2rem;
            color: var(--primary);
        }

        .action-btn span {
            font-weight: 600;
            font-size: 0.9rem;
        }

        /* Alert Banner */
        .alert-banner {
            background: linear-gradient(135deg, var(--warning), #e07c1a);
            border-radius: var(--border-radius-lg);
            padding: 1.2rem 2rem;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-lg);
        }

        .alert-banner .btn {
            background: white;
            color: var(--warning);
            border: none;
            padding: 0.6rem 1.5rem;
            border-radius: var(--border-radius-md);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
        }

        .alert-banner .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Additional Stats Grid */
        .stats-mini-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .mini-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.2rem;
            box-shadow: var(--shadow-md);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: var(--transition);
        }

        .mini-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .mini-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: white;
        }

        .mini-icon.purple { background: var(--gradient-2); }
        .mini-icon.orange { background: var(--gradient-5); }
        .mini-icon.blue { background: var(--gradient-1); }
        .mini-icon.green { background: var(--gradient-4); }

        .mini-content {
            flex: 1;
        }

        .mini-value {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--dark);
            line-height: 1.2;
        }

        .mini-label {
            font-size: 0.75rem;
            color: var(--gray);
            text-transform: uppercase;
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
        .stagger-item:nth-child(2) { animation-delay: 0.15s; }
        .stagger-item:nth-child(3) { animation-delay: 0.2s; }
        .stagger-item:nth-child(4) { animation-delay: 0.25s; }

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
            
            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .kpi-grid {
                grid-template-columns: 1fr;
            }
            
            .collection-card {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
            
            .collection-stats {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .stats-mini-grid {
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
            <div class="header-content">
                <div>
                    <div class="header-badge">
                        <i class="fas fa-chart-line"></i>
                        <span>Financial Overview</span>
                    </div>
                    <h1>Financial Dashboard</h1>
                    <p>Welcome back, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?>. Here's your complete financial picture.</p>
                </div>
                <div class="date-display">
                    <div class="day"><?php echo date('d'); ?></div>
                    <div class="month-year"><?php echo date('F Y'); ?></div>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($error_message): ?>
        <div class="alert alert-danger animate" style="padding: 1rem 1.5rem; border-radius: var(--border-radius-md); margin-bottom: 1.5rem; background: rgba(249, 65, 68, 0.1); border-left: 4px solid var(--danger); color: var(--danger); display: flex; justify-content: space-between; align-items: center;">
            <div>
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
            </div>
            <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: inherit; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php endif; ?>

        <!-- Library Fines Alert -->
        <?php if ($total_pending_library_charges > 0): ?>
        <div class="alert-banner animate">
            <div>
                <i class="fas fa-book"></i>
                <strong><?php echo $pending_library_fines; ?> overdue fine(s)</strong> and 
                <strong><?php echo $pending_lost_books; ?> lost book charge(s)</strong> totaling 
                <strong>KES <?php echo number_format($pending_library_amount, 2); ?></strong> ready to be invoiced.
            </div>
            <a href="library_fines_invoices.php" class="btn">
                <i class="fas fa-file-invoice"></i> Create Invoices
            </a>
        </div>
        <?php endif; ?>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card revenue stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Total Revenue</div>
                    <div class="kpi-value">KES <?php echo number_format($total_paid, 2); ?></div>
                    <div class="kpi-trend">
                        <span class="trend-up"><i class="fas fa-arrow-up"></i> KES <?php echo number_format($today_paid, 2); ?> today</span>
                    </div>
                </div>
            </div>

            <div class="kpi-card expenses stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-credit-card"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Total Expenses</div>
                    <div class="kpi-value">KES <?php echo number_format($total_expenses, 2); ?></div>
                    <div class="kpi-trend">
                        <span class="trend-down"><i class="fas fa-arrow-down"></i> KES <?php echo number_format($today_expenses, 2); ?> today</span>
                    </div>
                </div>
            </div>

            <div class="kpi-card balance stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-balance-scale"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Net Balance</div>
                    <div class="kpi-value">KES <?php echo number_format($financial_balance, 2); ?></div>
                    <div class="kpi-trend">
                        <span class="<?php echo $financial_balance >= 0 ? 'trend-up' : 'trend-down'; ?>">
                            <i class="fas fa-<?php echo $financial_balance >= 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                            <?php echo $financial_balance >= 0 ? 'Positive' : 'Negative'; ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="kpi-card pending stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Pending Items</div>
                    <div class="kpi-value"><?php echo $pending_invoices + $pending_payments + $pending_expenses; ?></div>
                    <div class="kpi-trend">
                        <span class="trend-down"><?php echo $overdue_invoices; ?> overdue invoices</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Collection Rate Card -->
        <div class="collection-card animate">
            <div class="collection-info">
                <h3>Overall Collection Rate</h3>
                <div class="collection-rate"><?php echo $collection_rate; ?>%</div>
            </div>
            <div class="collection-stats">
                <div class="stat-item">
                    <div class="value">KES <?php echo number_format($total_paid, 0); ?></div>
                    <div class="label">Collected</div>
                </div>
                <div class="stat-item">
                    <div class="value">KES <?php echo number_format($total_invoiced, 0); ?></div>
                    <div class="label">Invoiced</div>
                </div>
                <div class="stat-item">
                    <div class="value">KES <?php echo number_format($unpaid_balance, 0); ?></div>
                    <div class="label">Outstanding</div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions animate">
            <a href="payments.php?action=add" class="action-btn">
                <i class="fas fa-plus-circle"></i>
                <span>Record Payment</span>
            </a>
            <a href="add_expense.php" class="action-btn">
                <i class="fas fa-receipt"></i>
                <span>Add Expense</span>
            </a>
            <a href="invoices.php?action=create" class="action-btn">
                <i class="fas fa-file-invoice"></i>
                <span>Create Invoice</span>
            </a>
            <a href="reports.php" class="action-btn">
                <i class="fas fa-chart-bar"></i>
                <span>Generate Report</span>
            </a>
            <a href="students.php?view=balances" class="action-btn">
                <i class="fas fa-users"></i>
                <span>Check Balances</span>
            </a>
            <a href="payments.php" class="action-btn">
                <i class="fas fa-list"></i>
                <span>View All</span>
            </a>
        </div>

        <!-- Charts Section -->
        <div class="charts-grid">
            <!-- Revenue vs Expenses Chart -->
            <div class="chart-card animate">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-line" style="color: var(--primary);"></i> Revenue vs Expenses</h3>
                    <span class="badge">Last 12 months</span>
                </div>
                <div class="chart-container">
                    <canvas id="revenueExpenseChart"></canvas>
                </div>
            </div>

            <!-- Expense Categories Chart -->
            <div class="chart-card animate">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-pie" style="color: var(--purple);"></i> Expense Distribution</h3>
                    <span class="badge">Top 5 categories</span>
                </div>
                <div class="chart-container">
                    <canvas id="expenseCategoryChart"></canvas>
                </div>
            </div>

            <!-- Payment Methods Chart -->
            <div class="chart-card animate">
                <div class="chart-header">
                    <h3><i class="fas fa-credit-card" style="color: var(--success);"></i> Payment Methods</h3>
                    <span class="badge">Last 3 months</span>
                </div>
                <div class="chart-container">
                    <canvas id="paymentMethodChart"></canvas>
                </div>
            </div>

            <!-- Monthly Collection Chart -->
            <div class="chart-card animate">
                <div class="chart-header">
                    <h3><i class="fas fa-calendar-alt" style="color: var(--warning);"></i> Monthly Collection</h3>
                    <span class="badge">Last 6 months</span>
                </div>
                <div class="chart-container">
                    <canvas id="monthlyCollectionChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Tables Section -->
        <div class="tables-grid">
            <!-- Recent Payments -->
            <div class="table-card animate">
                <div class="table-header">
                    <h3><i class="fas fa-money-bill-wave"></i> Recent Payments</h3>
                    <a href="payments.php">View All <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recent_payments)): ?>
                                <?php foreach($recent_payments as $payment): ?>
                                <tr onclick="window.location.href='payments.php'" style="cursor: pointer;">
                                    <td><?php echo date('d M', strtotime($payment['payment_date'])); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($payment['student_name'] ?? 'N/A'); ?>
                                        <?php if (!empty($payment['admission_number'])): ?>
                                            <br><small style="color: var(--gray);"><?php echo htmlspecialchars($payment['admission_number']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong>KES <?php echo number_format($payment['amount'], 2); ?></strong></td>
                                    <td>
                                        <?php echo htmlspecialchars($payment['payment_method'] ?? 'N/A'); ?>
                                        <?php if (!empty($payment['mpesa_receipt'])): ?>
                                            <br><small style="color: var(--gray);"><?php echo htmlspecialchars($payment['mpesa_receipt']); ?></small>
                                        <?php elseif (!empty($payment['transaction_ref'])): ?>
                                            <br><small style="color: var(--gray);"><?php echo htmlspecialchars($payment['transaction_ref']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($payment['status']); ?>">
                                            <?php echo ucfirst($payment['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 2rem; color: var(--gray);">
                                        <i class="fas fa-money-bill-wave fa-2x" style="margin-bottom: 0.5rem; opacity: 0.3;"></i>
                                        <p>No recent payments</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Expenses -->
            <div class="table-card animate">
                <div class="table-header">
                    <h3><i class="fas fa-receipt"></i> Recent Expenses</h3>
                    <a href="expenses.php">View All <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recent_expenses)): ?>
                                <?php foreach($recent_expenses as $expense): ?>
                                <tr onclick="window.location.href='expenses.php'" style="cursor: pointer;">
                                    <td><?php echo date('d M', strtotime($expense['expense_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($expense['category'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars(strlen($expense['description']) > 32 ? substr($expense['description'], 0, 32) . '...' : $expense['description']); ?>
                                        <?php if (!empty($expense['created_by_name'])): ?>
                                            <br><small style="color: var(--gray);">By <?php echo htmlspecialchars($expense['created_by_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong>KES <?php echo number_format($expense['amount'], 2); ?></strong></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($expense['status']); ?>">
                                            <?php echo ucfirst($expense['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 2rem; color: var(--gray);">
                                        <i class="fas fa-receipt fa-2x" style="margin-bottom: 0.5rem; opacity: 0.3;"></i>
                                        <p>No recent expenses</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Additional Stats -->
        <div class="stats-mini-grid">
            <div class="mini-card">
                <div class="mini-icon purple">
                    <i class="fas fa-users"></i>
                </div>
                <div class="mini-content">
                    <div class="mini-value"><?php echo $students_with_balance; ?> / <?php echo $total_students; ?></div>
                    <div class="mini-label">Students with Balance</div>
                </div>
            </div>

            <div class="mini-card">
                <div class="mini-icon orange">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <div class="mini-content">
                    <div class="mini-value"><?php echo $overdue_invoices; ?></div>
                    <div class="mini-label">Overdue Invoices</div>
                    <div class="mini-trend">KES <?php echo number_format($unpaid_balance, 2); ?> outstanding</div>
                </div>
            </div>

            <div class="mini-card">
                <div class="mini-icon blue">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="mini-content">
                    <div class="mini-value">KES <?php echo number_format($today_paid - $today_expenses, 2); ?></div>
                    <div class="mini-label">Today's Net</div>
                    <div class="mini-trend <?php echo ($today_paid - $today_expenses) >= 0 ? 'trend-up' : 'trend-down'; ?>">
                        <i class="fas fa-<?php echo ($today_paid - $today_expenses) >= 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                        <?php echo ($today_paid - $today_expenses) >= 0 ? 'Profit' : 'Loss'; ?>
                    </div>
                </div>
            </div>

            <div class="mini-card">
                <div class="mini-icon green">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="mini-content">
                    <div class="mini-value">KES <?php echo number_format($monthly_collected - $monthly_expenses_value, 2); ?></div>
                    <div class="mini-label">Monthly Net</div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Revenue vs Expenses Chart
            const revenueCtx = document.getElementById('revenueExpenseChart').getContext('2d');
            const monthLabels = <?php echo json_encode(array_column($monthly_payments, 'month_name')); ?>;
            const revenueData = <?php echo json_encode(array_map(function($v) { return (float)$v['total']; }, $monthly_payments)); ?>;
            
            // Align expense data
            const expenseData = <?php 
                $expObj = [];
                foreach($monthly_payments as $p) {
                    $found = false;
                    foreach($monthly_expenses_data as $e) {
                        if($e['month'] === $p['month']) {
                            $expObj[] = (float)$e['total'];
                            $found = true;
                            break;
                        }
                    }
                    if(!$found) $expObj[] = 0;
                }
                echo json_encode($expObj);
            ?>;

            new Chart(revenueCtx, {
                type: 'line',
                data: {
                    labels: monthLabels,
                    datasets: [
                        {
                            label: 'Revenue',
                            data: revenueData,
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
                            data: expenseData,
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
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top', labels: { usePointStyle: true } },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': KES ' + 
                                           context.parsed.y.toLocaleString('en-KE', {maximumFractionDigits: 2});
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

            // Expense Category Chart
            const expenseCtx = document.getElementById('expenseCategoryChart').getContext('2d');
            const expenseLabels = <?php echo json_encode(array_column($top_expense_categories, 'category')); ?>;
            const expenseValues = <?php echo json_encode(array_map(function($v) { return (float)$v['total']; }, $top_expense_categories)); ?>;
            
            new Chart(expenseCtx, {
                type: 'doughnut',
                data: {
                    labels: expenseLabels,
                    datasets: [{
                        data: expenseValues,
                        backgroundColor: [
                            '#f94144',
                            '#f8961e',
                            '#4361ee',
                            '#7209b7',
                            '#4cc9f0'
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
                        legend: { position: 'bottom', labels: { usePointStyle: true } },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((context.raw / total) * 100).toFixed(1);
                                    return context.label + ': KES ' + 
                                           context.raw.toLocaleString('en-KE', {maximumFractionDigits: 2}) + 
                                           ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });

            // Payment Methods Chart
            const methodCtx = document.getElementById('paymentMethodChart').getContext('2d');
            const methodLabels = <?php echo json_encode(array_column($payment_methods, 'payment_method')); ?>;
            const methodValues = <?php echo json_encode(array_map(function($v) { return (float)$v['total']; }, $payment_methods)); ?>;
            
            new Chart(methodCtx, {
                type: 'bar',
                data: {
                    labels: methodLabels,
                    datasets: [{
                        label: 'Amount (KES)',
                        data: methodValues,
                        backgroundColor: [
                            '#4361ee',
                            '#4cc9f0',
                            '#7209b7',
                            '#f8961e'
                        ],
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

            // Monthly Collection Chart
            const monthlyCtx = document.getElementById('monthlyCollectionChart').getContext('2d');
            new Chart(monthlyCtx, {
                type: 'bar',
                data: {
                    labels: monthLabels.slice(-6),
                    datasets: [
                        {
                            label: 'Payments',
                            data: revenueData.slice(-6),
                            backgroundColor: '#4cc9f0',
                            borderRadius: 8
                        },
                        {
                            label: 'Expenses',
                            data: expenseData.slice(-6),
                            backgroundColor: '#f94144',
                            borderRadius: 8
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': KES ' + 
                                           context.parsed.y.toLocaleString('en-KE', {maximumFractionDigits: 2});
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
        });

        // Auto-refresh every 5 minutes
        setTimeout(() => {
            location.reload();
        }, 5 * 60 * 1000);

        // SweetAlert for library fines
        <?php if ($total_pending_library_charges > 0): ?>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: 'info',
                title: 'Library Fines Ready',
                text: '<?php echo $pending_library_fines; ?> fines and <?php echo $pending_lost_books; ?> lost books await invoicing',
                showConfirmButton: true,
                confirmButtonText: 'View Now',
                showCancelButton: true,
                confirmButtonColor: '#4361ee'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'library_fines_invoices.php';
                }
            });
        });
        <?php endif; ?>
    </script>
</body>
</html>
