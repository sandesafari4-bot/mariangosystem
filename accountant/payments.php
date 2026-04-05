<?php
include '../config.php';
checkAuth();
checkRole(['accountant', 'admin']);

// Get filter parameters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$payment_method = $_GET['payment_method'] ?? '';
$class_filter = $_GET['class'] ?? '';

// Get payments with pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

$payment_columns = $pdo->query("SHOW COLUMNS FROM payments")->fetchAll(PDO::FETCH_COLUMN);
$payment_column_lookup = array_fill_keys($payment_columns, true);
$payment_date_column = isset($payment_column_lookup['payment_date'])
    ? 'payment_date'
    : (isset($payment_column_lookup['paid_at'])
        ? 'paid_at'
        : (isset($payment_column_lookup['created_at']) ? 'created_at' : 'id'));
$payment_user_column = isset($payment_column_lookup['recorded_by']) ? 'recorded_by' : 'created_by';
$completed_statuses = ['completed', 'paid', 'verified'];
$completed_placeholders = implode(', ', array_fill(0, count($completed_statuses), '?'));
$successfulPaymentCondition = "
    (
        COALESCE(NULLIF(TRIM(p.status), ''), 'completed') IN ($completed_placeholders)
    )
";

$buildPaymentCoalesce = function (array $candidates, $fallback = "''") use ($payment_column_lookup) {
    $parts = [];
    foreach ($candidates as $column) {
        if (isset($payment_column_lookup[$column])) {
            $parts[] = "p.`$column`";
        }
    }
    $parts[] = $fallback;
    return 'COALESCE(' . implode(', ', $parts) . ')';
};

$transaction_expr = $buildPaymentCoalesce(
    ['transaction_id', 'payment_id', 'transaction_ref', 'reference_no', 'reference', 'mpesa_receipt'],
    "CONCAT('PAY-', LPAD(CAST(p.id AS CHAR), 6, '0'))"
);
$reference_expr = $buildPaymentCoalesce(
    ['reference_no', 'transaction_ref', 'reference', 'mpesa_receipt'],
    "''"
);

$params = [];
$query = "SELECT p.*, i.invoice_no, s.full_name as student_name, s.admission_number, 
                 fs.structure_name, u.full_name as recorded_by, c.class_name, 
                 pm.label as payment_method_label, pm.code as payment_method_code,
                 $transaction_expr as transaction_display,
                 $reference_expr as reference_display
          FROM payments p 
          LEFT JOIN invoices i ON p.invoice_id = i.id
          LEFT JOIN students s ON p.student_id = s.id
          LEFT JOIN fee_structures fs ON i.fee_structure_id = fs.id
          LEFT JOIN users u ON p.$payment_user_column = u.id
          LEFT JOIN classes c ON s.class_id = c.id
          LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
          WHERE 1=1";

$count_query = "SELECT COUNT(*) as total FROM payments p WHERE 1=1";
$count_params = [];

if ($search) {
    $query .= " AND (s.full_name LIKE ? OR s.admission_number LIKE ? OR $transaction_expr LIKE ? OR $reference_expr LIKE ? OR i.invoice_no LIKE ?)";
    $count_query .= " AND (EXISTS(SELECT 1 FROM students s WHERE s.id = p.student_id AND (s.full_name LIKE ? OR s.admission_number LIKE ?)) OR $transaction_expr LIKE ? OR $reference_expr LIKE ? OR EXISTS(SELECT 1 FROM invoices i WHERE i.id = p.invoice_id AND i.invoice_no LIKE ?))";
    $search_param = "%$search%";
    $params = array_fill(0, 5, $search_param);
    $count_params = array_fill(0, 5, $search_param);
}

if ($status_filter) {
    $query .= " AND p.status = ?";
    $count_query .= " AND p.status = ?";
    $params[] = $status_filter;
    $count_params[] = $status_filter;
}

if ($payment_method) {
    $query .= " AND (pm.code = ? OR pm.label = ? OR pm.id = ?)";
    $count_query .= " AND (EXISTS(SELECT 1 FROM payment_methods pm WHERE pm.id = p.payment_method_id AND (pm.code = ? OR pm.label = ? OR pm.id = ?)))";
    $params[] = $payment_method;
    $params[] = $payment_method;
    $params[] = $payment_method;
    $count_params[] = $payment_method;
    $count_params[] = $payment_method;
    $count_params[] = $payment_method;
}

if ($class_filter) {
    $query .= " AND c.id = ?";
    $count_query .= " AND EXISTS(SELECT 1 FROM students s WHERE s.id = p.student_id AND s.class_id = ?)";
    $params[] = $class_filter;
    $count_params[] = $class_filter;
}

if ($start_date) {
    $query .= " AND DATE(p.$payment_date_column) >= ?";
    $count_query .= " AND DATE(p.$payment_date_column) >= ?";
    $params[] = $start_date;
    $count_params[] = $start_date;
}

if ($end_date) {
    $query .= " AND DATE(p.$payment_date_column) <= ?";
    $count_query .= " AND DATE(p.$payment_date_column) <= ?";
    $params[] = $end_date;
    $count_params[] = $end_date;
}

$query .= " ORDER BY p.$payment_date_column DESC LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll();

foreach ($payments as &$payment) {
    $payment['transaction_id'] = $payment['transaction_display']
        ?? $payment['transaction_id']
        ?? $payment['transaction_ref']
        ?? $payment['reference']
        ?? ('PAY-' . str_pad((string) ($payment['id'] ?? 0), 6, '0', STR_PAD_LEFT));

    $payment['reference_no'] = $payment['reference_display']
        ?? $payment['reference_no']
        ?? $payment['reference']
        ?? '';

    $payment['payment_date'] = $payment['payment_date'] ?? $payment['paid_at'] ?? $payment['created_at'] ?? null;
}
unset($payment);

// Get total count
$stmt = $pdo->prepare($count_query);
$stmt->execute($count_params);
$total_count = $stmt->fetchColumn();
$total_pages = ceil($total_count / $limit);

// Get summary stats
$summary_query = "SELECT 
                    COALESCE(SUM(CASE WHEN $successfulPaymentCondition THEN p.amount ELSE 0 END), 0) as total_paid,
                    COALESCE(SUM(CASE WHEN p.status = 'pending' THEN p.amount ELSE 0 END), 0) as total_pending,
                    COUNT(*) as total_count,
                    COUNT(DISTINCT p.student_id) as unique_students,
                    COALESCE(AVG(p.amount), 0) as avg_payment
                  FROM payments p";
$summary_stmt = $pdo->prepare($summary_query);
$summary_stmt->execute($completed_statuses);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

// Get daily summary for chart
$daily_summary_stmt = $pdo->prepare("
    SELECT 
        DATE($payment_date_column) as date, 
        COALESCE(SUM(amount), 0) as total, 
        COUNT(*) as count 
    FROM payments p
    WHERE $payment_date_column >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        AND $successfulPaymentCondition
    GROUP BY DATE($payment_date_column) 
    ORDER BY date
");
$daily_summary_stmt->execute($completed_statuses);
$daily_summary = $daily_summary_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment method breakdown
$method_breakdown = $pdo->query("
    SELECT 
        pm.label as method,
        COUNT(*) as count,
        COALESCE(SUM(p.amount), 0) as total
    FROM payments p
    LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
    WHERE p.$payment_date_column >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY pm.label
    ORDER BY total DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get students for filter
$students = $pdo->query("SELECT id, full_name, admission_number FROM students ORDER BY full_name")->fetchAll();

// Get classes for filtering
$classes = $pdo->query("SELECT id, class_name FROM classes WHERE is_active = 1 ORDER BY class_name")->fetchAll();

// Get payment methods
$payment_methods = $pdo->query("SELECT id, label, code FROM payment_methods ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);

// Get unpaid invoices for dropdown
$unpaid_invoices = $pdo->query("
    SELECT i.id, i.invoice_no, i.total_amount, i.amount_paid, i.balance, i.status,
           s.full_name, s.admission_number, fs.structure_name
    FROM invoices i
    LEFT JOIN students s ON i.student_id = s.id
    LEFT JOIN fee_structures fs ON i.fee_structure_id = fs.id
    WHERE i.status IN ('unpaid', 'partial')
    ORDER BY i.created_at DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Payment Management - " . SCHOOL_NAME;
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
        .kpi-card.purple::before { background: var(--gradient-2); }

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
        .kpi-card.purple .kpi-icon { background: var(--gradient-2); }

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

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
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

        /* Data Card */
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
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-completed {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .status-pending {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        .status-failed {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        /* Method Badges */
        .method-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .method-cash {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .method-mpesa {
            background: rgba(114, 9, 183, 0.15);
            color: var(--purple);
        }

        .method-bank {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            padding: 1.5rem;
            background: var(--light);
        }

        .pagination a,
        .pagination span {
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius-sm);
            text-decoration: none;
            color: var(--dark);
            background: white;
            border: 1px solid var(--light);
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

        /* Modal */
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

        /* Student Summary */
        .student-summary {
            background: var(--gradient-1);
            color: white;
            border-radius: var(--border-radius-md);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            display: none;
        }

        .summary-grid-small {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .summary-item {
            text-align: center;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.15);
            border-radius: var(--border-radius-sm);
        }

        .summary-value {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        /* Invoice Details */
        .invoice-details {
            background: var(--light);
            border-radius: var(--border-radius-md);
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--primary);
            display: none;
        }

        .invoice-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
        }

        .invoice-item {
            text-align: center;
        }

        .invoice-label {
            font-size: 0.8rem;
            color: var(--gray);
            margin-bottom: 0.25rem;
        }

        .invoice-value {
            font-size: 1.2rem;
            font-weight: 700;
        }

        /* M-Pesa Form */
        .mpesa-form,
        .bank-form {
            background: rgba(114, 9, 183, 0.05);
            border-radius: var(--border-radius-md);
            padding: 1rem;
            margin-top: 1rem;
            border-left: 4px solid var(--purple);
            display: none;
        }

        .bank-form {
            border-left-color: var(--warning);
            background: rgba(248, 150, 30, 0.05);
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
            
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header animate">
            <div>
                <h1><i class="fas fa-credit-card"></i> Payment Management</h1>
                <p>Record and track all payment transactions</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-outline" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card success stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Total Collected</div>
                    <div class="kpi-value">KES <?php echo number_format($summary['total_paid'] ?? 0, 2); ?></div>
                </div>
            </div>

            <div class="kpi-card primary stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Paying Students</div>
                    <div class="kpi-value"><?php echo number_format($summary['unique_students'] ?? 0); ?></div>
                </div>
            </div>

            <div class="kpi-card warning stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-calculator"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Average Payment</div>
                    <div class="kpi-value">KES <?php echo number_format($summary['avg_payment'] ?? 0, 2); ?></div>
                </div>
            </div>

            <div class="kpi-card purple stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Total Payments</div>
                    <div class="kpi-value"><?php echo number_format($summary['total_count'] ?? 0); ?></div>
                </div>
            </div>
        </div>

        <!-- Charts Grid -->
        <div class="charts-grid">
            <div class="chart-card animate">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-line"></i> Payment Trend (Last 30 Days)</h3>
                </div>
                <div class="chart-container">
                    <canvas id="paymentTrendChart"></canvas>
                </div>
            </div>

            <div class="chart-card animate">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-pie"></i> Payment Methods</h3>
                </div>
                <div class="chart-container">
                    <canvas id="paymentMethodChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section animate">
            <div class="filter-header">
                <h3><i class="fas fa-filter"></i> Filter Payments</h3>
                <button class="btn btn-sm btn-outline" onclick="resetFilters()">
                    <i class="fas fa-redo"></i> Reset
                </button>
            </div>
            <form method="GET">
                <div class="filter-grid">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" placeholder="Student, transaction..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="">All</option>
                            <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Method</label>
                        <select name="payment_method">
                            <option value="">All</option>
                            <?php foreach ($payment_methods as $method): ?>
                            <option value="<?php echo $method['code']; ?>" <?php echo $payment_method == $method['code'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($method['label']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Class</label>
                        <select name="class">
                            <option value="">All</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>From</label>
                        <input type="date" name="start_date" value="<?php echo $start_date; ?>">
                    </div>
                    <div class="form-group">
                        <label>To</label>
                        <input type="date" name="end_date" value="<?php echo $end_date; ?>">
                    </div>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Payments Table -->
        <div class="data-card animate">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Payment Records</h3>
                <span>Total: <?php echo number_format($total_count); ?> | Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Transaction ID</th>
                            <th>Student</th>
                            <th>Invoice</th>
                            <th>Amount</th>
                            <th>Date</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($payments)): ?>
                            <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($payment['transaction_id']); ?></strong>
                                    <?php if (!empty($payment['reference_no'])): ?>
                                    <div style="font-size: 0.8rem; color: var(--gray);">
                                        Ref: <?php echo htmlspecialchars($payment['reference_no']); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($payment['student_name']); ?></strong>
                                    <div style="font-size: 0.8rem; color: var(--gray);">
                                        <?php echo htmlspecialchars($payment['admission_number']); ?>
                                    </div>
                                </td>
                                <td>
                                    #<?php echo htmlspecialchars($payment['invoice_no']); ?>
                                </td>
                                <td>
                                    <strong style="color: var(--success);">KES <?php echo number_format($payment['amount'], 2); ?></strong>
                                </td>
                                <td>
                                    <?php echo date('d M Y', strtotime($payment['payment_date'])); ?>
                                </td>
                                <td>
                                    <span class="method-badge method-<?php echo $payment['payment_method_code']; ?>">
                                        <?php echo htmlspecialchars($payment['payment_method_label'] ?? 'N/A'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $payment['status']; ?>">
                                        <i class="fas fa-<?php echo $payment['status'] == 'completed' ? 'check-circle' : 'clock'; ?>"></i>
                                        <?php echo ucfirst($payment['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 0.5rem;">
                                        <button class="btn btn-sm btn-outline" onclick="generateReceipt(<?php echo $payment['id']; ?>)" title="Print Receipt">
                                            <i class="fas fa-receipt"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 3rem;">
                                    <i class="fas fa-inbox fa-3x" style="color: var(--gray); margin-bottom: 1rem;"></i>
                                    <h4>No Payments Found</h4>
                                    <p style="color: var(--gray);">Try adjusting your filters</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">
                        <i class="fas fa-angle-double-left"></i>
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                        <i class="fas fa-angle-left"></i>
                    </a>
                <?php endif; ?>

                <?php
                $start = max(1, $page - 2);
                $end = min($total_pages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <?php if ($i == $page): ?>
                        <span class="active"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                        <i class="fas fa-angle-right"></i>
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">
                        <i class="fas fa-angle-double-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment Modal -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Record New Payment</h3>
                <button class="modal-close" onclick="closeModal('paymentModal')">&times;</button>
            </div>
            
            <div class="modal-body">
                <div id="studentSummary" class="student-summary">
                    <h4><i class="fas fa-user-graduate"></i> <span id="summaryStudentName">Student</span></h4>
                    <div class="summary-grid-small">
                        <div class="summary-item">
                            <div class="summary-value" id="summaryTotalDue">0</div>
                            <div>Total Due</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value" id="summaryTotalPaid">0</div>
                            <div>Paid</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value" id="summaryBalance">0</div>
                            <div>Balance</div>
                        </div>
                    </div>
                </div>

                <div id="invoiceDetails" class="invoice-details">
                    <div class="invoice-grid">
                        <div class="invoice-item">
                            <div class="invoice-label">Total</div>
                            <div class="invoice-value" id="invoiceTotalAmount">0</div>
                        </div>
                        <div class="invoice-item">
                            <div class="invoice-label">Paid</div>
                            <div class="invoice-value" style="color: var(--success);" id="invoicePaidAmount">0</div>
                        </div>
                        <div class="invoice-item">
                            <div class="invoice-label">Balance</div>
                            <div class="invoice-value" style="color: var(--danger);" id="invoiceBalance">0</div>
                        </div>
                    </div>
                </div>

                <form id="paymentForm">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label class="required">Student</label>
                            <select id="student_id" class="form-control" required onchange="loadStudentInvoices()">
                                <option value="">Select Student</option>
                                <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>">
                                    <?php echo htmlspecialchars($student['full_name'] . ' (' . $student['admission_number'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group full-width">
                            <label class="required">Invoice</label>
                            <select id="invoice_id" class="form-control" required onchange="updateInvoiceDetails()">
                                <option value="">Select Invoice</option>
                                <?php foreach ($unpaid_invoices as $invoice): ?>
                                <option value="<?php echo $invoice['id']; ?>" 
                                        data-total="<?php echo $invoice['total_amount']; ?>"
                                        data-paid="<?php echo $invoice['amount_paid']; ?>"
                                        data-balance="<?php echo $invoice['balance']; ?>">
                                    #<?php echo $invoice['invoice_no']; ?> - 
                                    <?php echo $invoice['full_name']; ?> - 
                                    KES <?php echo number_format($invoice['balance'], 2); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="required">Amount</label>
                            <input type="number" id="amount" class="form-control" step="0.01" min="0" required>
                            <small>Max: KES <span id="maxAmount">0</span></small>
                        </div>

                        <div class="form-group">
                            <label class="required">Date</label>
                            <input type="datetime-local" id="payment_date" class="form-control" 
                                   value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Method</label>
                            <select id="payment_method" class="form-control" onchange="handlePaymentMethodChange()">
                                <option value="">Select Method</option>
                                <?php foreach ($payment_methods as $method): ?>
                                <option value="<?php echo $method['code']; ?>">
                                    <?php echo htmlspecialchars($method['label']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Reference</label>
                            <input type="text" id="reference_no" class="form-control" placeholder="Transaction ID">
                        </div>

                        <div class="form-group full-width">
                            <label>Notes</label>
                            <textarea id="notes" class="form-control" rows="3" placeholder="Additional notes..."></textarea>
                        </div>
                    </div>

                    <!-- M-PESA Form -->
                    <div id="mpesaForm" class="mpesa-form">
                        <h5 style="margin: 0 0 1rem 0; color: var(--purple);">
                            <i class="fas fa-mobile-alt"></i> M-PESA Payment
                        </h5>
                        <div class="form-group">
                            <label class="required">Phone Number</label>
                            <input type="text" id="mpesa_phone" class="form-control" 
                                   placeholder="254XXXXXXXXX" maxlength="12">
                            <small>Format: 254XXXXXXXXX</small>
                        </div>
                        <button type="button" class="btn btn-primary" onclick="processMPesaPayment()" style="width: 100%;">
                            <i class="fas fa-paper-plane"></i> Send STK Push
                        </button>
                    </div>

                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('paymentModal')">
                            Cancel
                        </button>
                        <button type="submit" id="submitPayment" class="btn btn-success">
                            <i class="fas fa-save"></i> Record Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Chart Initialization
        document.addEventListener('DOMContentLoaded', function() {
            // Payment Trend Chart
            const trendCtx = document.getElementById('paymentTrendChart').getContext('2d');
            const trendDates = <?php echo json_encode(array_column($daily_summary, 'date')); ?>;
            const trendAmounts = <?php echo json_encode(array_column($daily_summary, 'total')); ?>;
            
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: trendDates.map(date => {
                        const d = new Date(date);
                        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                    }),
                    datasets: [{
                        label: 'Amount (KES)',
                        data: trendAmounts,
                        borderColor: '#4cc9f0',
                        backgroundColor: 'rgba(76, 201, 240, 0.1)',
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

            // Payment Method Chart
            const methodCtx = document.getElementById('paymentMethodChart').getContext('2d');
            const methodLabels = <?php echo json_encode(array_column($method_breakdown, 'method')); ?>;
            const methodTotals = <?php echo json_encode(array_column($method_breakdown, 'total')); ?>;

            new Chart(methodCtx, {
                type: 'doughnut',
                data: {
                    labels: methodLabels,
                    datasets: [{
                        data: methodTotals,
                        backgroundColor: [
                            '#4cc9f0',
                            '#7209b7',
                            '#f8961e',
                            '#4361ee'
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
                        }
                    }
                }
            });
        });

        // Modal Functions
        function openPaymentModal() {
            document.getElementById('paymentModal').classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            resetPaymentForm();
        }

        function resetPaymentForm() {
            document.getElementById('paymentForm').reset();
            document.getElementById('studentSummary').style.display = 'none';
            document.getElementById('invoiceDetails').style.display = 'none';
            document.getElementById('mpesaForm').style.display = 'none';
        }

        // Load Student Invoices
        function loadStudentInvoices() {
            const studentId = document.getElementById('student_id').value;
            if (!studentId) {
                document.getElementById('studentSummary').style.display = 'none';
                return;
            }

            fetch('payments.php?ajax=get_student_invoices&student_id=' + studentId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('summaryStudentName').textContent = data.student.full_name;
                        document.getElementById('summaryTotalDue').textContent = 'KES ' + data.summary.total_due.toFixed(2);
                        document.getElementById('summaryTotalPaid').textContent = 'KES ' + data.summary.total_paid.toFixed(2);
                        document.getElementById('summaryBalance').textContent = 'KES ' + data.summary.balance.toFixed(2);
                        document.getElementById('studentSummary').style.display = 'block';
                    }
                });
        }

        // Update Invoice Details
        function updateInvoiceDetails() {
            const select = document.getElementById('invoice_id');
            const option = select.options[select.selectedIndex];

            if (!option.value) {
                document.getElementById('invoiceDetails').style.display = 'none';
                return;
            }

            const total = parseFloat(option.dataset.total);
            const paid = parseFloat(option.dataset.paid);
            const balance = parseFloat(option.dataset.balance);

            document.getElementById('invoiceTotalAmount').textContent = 'KES ' + total.toFixed(2);
            document.getElementById('invoicePaidAmount').textContent = 'KES ' + paid.toFixed(2);
            document.getElementById('invoiceBalance').textContent = 'KES ' + balance.toFixed(2);
            document.getElementById('maxAmount').textContent = balance.toFixed(2);
            document.getElementById('amount').max = balance;
            document.getElementById('amount').value = balance;
            
            document.getElementById('invoiceDetails').style.display = 'block';
        }

        // Handle Payment Method Change
        function handlePaymentMethodChange() {
            const method = document.getElementById('payment_method').value;
            document.getElementById('mpesaForm').style.display = method === 'mpesa' ? 'block' : 'none';
            document.getElementById('submitPayment').style.display = method === 'mpesa' ? 'none' : 'block';
        }

        // Process M-PESA Payment
        function processMPesaPayment() {
            const studentId = document.getElementById('student_id').value;
            const invoiceId = document.getElementById('invoice_id').value;
            const phone = document.getElementById('mpesa_phone').value;
            const amount = document.getElementById('amount').value;

            if (!studentId || !invoiceId) {
                Swal.fire('Error', 'Please select student and invoice', 'error');
                return;
            }

            if (!phone || !/^254\d{9}$/.test(phone)) {
                Swal.fire('Error', 'Invalid phone number. Use format 254XXXXXXXXX', 'error');
                return;
            }

            Swal.fire({
                title: 'Sending STK Push...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            fetch('mpesa_processor.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=initiate_stk&invoice_id=${invoiceId}&student_id=${studentId}&phone=${phone}&amount=${amount}`
            })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'STK Push Sent!',
                        text: 'Check your phone and enter M-PESA PIN',
                        timer: 5000
                    }).then(() => {
                        closeModal('paymentModal');
                        setTimeout(() => location.reload(), 2000);
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            });
        }

        // Generate Receipt
        function generateReceipt(paymentId) {
            window.open('payment_receipt.php?id=' + paymentId, '_blank', 'width=800,height=600');
        }

        // Reset Filters
        function resetFilters() {
            window.location.href = 'payments.php';
        }

        // Form Submission
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const studentId = document.getElementById('student_id').value;
            const invoiceId = document.getElementById('invoice_id').value;
            const amount = parseFloat(document.getElementById('amount').value);
            const maxAmount = parseFloat(document.getElementById('maxAmount').textContent);

            if (!studentId || !invoiceId) {
                Swal.fire('Error', 'Please select student and invoice', 'error');
                return;
            }

            if (!amount || amount < 1) {
                Swal.fire('Error', 'Please enter valid amount', 'error');
                return;
            }

            if (amount > maxAmount) {
                Swal.fire('Error', 'Amount exceeds remaining balance', 'error');
                return;
            }

            Swal.fire({
                title: 'Confirm Payment',
                html: `Record payment of <strong>KES ${amount.toFixed(2)}</strong>?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, record it'
            }).then((result) => {
                if (!result.isConfirmed) return;

                const formData = new FormData(this);
                formData.append('ajax_action', 'record_payment');

                fetch('payments.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: 'Payment recorded!',
                            timer: 3000
                        }).then(() => {
                            closeModal('paymentModal');
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', data.error, 'error');
                    }
                });
            });
        });
    </script>
</body>
</html>
