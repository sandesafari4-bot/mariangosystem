<?php
include '../config.php';
checkAuth();
checkRole(['accountant', 'admin']);

$page_title = 'Student Balances - ' . SCHOOL_NAME;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['update_balance'])) {
            $student_id = intval($_POST['student_id']);
            $adjustment = floatval($_POST['adjustment']);
            $reason = trim($_POST['reason']);
            $type = $_POST['adjustment_type']; // 'credit' or 'debit'
            
            $pdo->beginTransaction();
            
            // Get current balance
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(total_amount), 0) as total_invoiced,
                    COALESCE(SUM(amount_paid), 0) as total_paid,
                    COALESCE(SUM(balance), 0) as current_balance
                FROM invoices 
                WHERE student_id = ?
            ");
            $stmt->execute([$student_id]);
            $current = $stmt->fetch();
            
            $new_balance = $type === 'credit' 
                ? $current['current_balance'] - $adjustment  // Reduce balance (credit)
                : $current['current_balance'] + $adjustment; // Increase balance (debit)
            
            // Log the adjustment
            $stmt = $pdo->prepare("
                INSERT INTO balance_adjustments (
                    student_id, previous_balance, adjustment_amount, 
                    new_balance, adjustment_type, reason, adjusted_by, adjusted_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $student_id,
                $current['current_balance'],
                $adjustment,
                $new_balance,
                $type,
                $reason,
                $_SESSION['user_id']
            ]);
            
            // Create a manual adjustment invoice item if needed
            if ($type === 'debit') {
                // Create a manual charge
                $invoice_no = 'ADJ-' . date('Ymd') . '-' . str_pad($student_id, 4, '0', STR_PAD_LEFT);
                
                $stmt = $pdo->prepare("
                    INSERT INTO invoices (
                        invoice_no, student_id, student_name, admission_number,
                        class_id, total_amount, amount_paid, balance, status,
                        due_date, notes, created_by, created_at
                    ) VALUES (
                        ?, ?, 
                        (SELECT full_name FROM students WHERE id = ?),
                        (SELECT admission_number FROM students WHERE id = ?),
                        (SELECT class_id FROM students WHERE id = ?),
                        ?, 0, ?, 'unpaid', DATE_ADD(NOW(), INTERVAL 30 DAY),
                        ?, ?, NOW()
                    )
                ");
                
                $stmt->execute([
                    $invoice_no,
                    $student_id,
                    $student_id,
                    $student_id,
                    $student_id,
                    $adjustment,
                    $adjustment,
                    "Manual adjustment: " . $reason,
                    $_SESSION['user_id']
                ]);
            }
            
            $pdo->commit();
            $_SESSION['success'] = "Balance updated successfully! New balance: KES " . number_format($new_balance, 2);
            
        } elseif (isset($_POST['send_reminder'])) {
            $student_id = intval($_POST['student_id']);
            $parent_phone = trim($_POST['parent_phone']);
            
            // Get student balance info
            $stmt = $pdo->prepare("
                SELECT 
                    s.full_name, s.admission_number, s.parent_name, s.parent_phone,
                    COALESCE(SUM(i.balance), 0) as total_balance,
                    COUNT(CASE WHEN i.balance > 0 THEN 1 END) as unpaid_invoices
                FROM students s
                LEFT JOIN invoices i ON s.id = i.student_id AND i.status IN ('unpaid', 'partial')
                WHERE s.id = ?
                GROUP BY s.id
            ");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch();
            
            // Send SMS (implement your SMS gateway here)
            $sms_sent = false;
            if ($parent_phone) {
                $message = "Dear parent, {$student['full_name']} has an outstanding balance of KES " . 
                          number_format($student['total_balance'], 2) . ". Please clear at your earliest convenience.";
                // send_sms($parent_phone, $message);
                $sms_sent = true;
            }
            
            // Log reminder
            $stmt = $pdo->prepare("
                INSERT INTO payment_reminders (student_id, sent_via, sent_to, sent_at, sent_by)
                VALUES (?, ?, ?, NOW(), ?)
            ");
            $stmt->execute([
                $student_id,
                $parent_phone ? 'sms' : 'other',
                $parent_phone ?: 'phone',
                $_SESSION['user_id']
            ]);
            
            $_SESSION['success'] = "Reminder sent successfully!";
        }
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }
    
    header("Location: student_balances.php");
    exit();
}

// Get filter parameters
$class_filter = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$status_filter = $_GET['status'] ?? 'all'; // all, positive, zero, negative
$search = $_GET['search'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'balance_desc';

// Build query for student balances
$params = [];
$query = "
    SELECT 
        s.id,
        s.full_name,
        s.admission_number,
        s.parent_name,
        s.parent_phone,
        c.id as class_id,
        c.class_name,
        COALESCE(SUM(i.total_amount), 0) as total_invoiced,
        COALESCE(SUM(i.amount_paid), 0) as total_paid,
        COALESCE(SUM(i.balance), 0) as current_balance,
        COUNT(DISTINCT i.id) as invoice_count,
        COUNT(CASE WHEN i.status = 'unpaid' THEN 1 END) as unpaid_count,
        COUNT(CASE WHEN i.status = 'partial' THEN 1 END) as partial_count,
        COUNT(CASE WHEN i.status = 'paid' THEN 1 END) as paid_count,
        MIN(i.due_date) as earliest_due,
        MAX(i.due_date) as latest_due,
        COUNT(CASE WHEN i.due_date < CURDATE() AND i.balance > 0 THEN 1 END) as overdue_count,
        MAX(p.payment_date) as last_payment_date,
        MAX(ba.adjusted_at) as last_adjustment_date
    FROM students s
    LEFT JOIN classes c ON s.class_id = c.id
    LEFT JOIN invoices i ON s.id = i.student_id
    LEFT JOIN payments p ON s.id = p.student_id
    LEFT JOIN balance_adjustments ba ON s.id = ba.student_id
    WHERE s.status = 'active'
";

if ($class_filter) {
    $query .= " AND s.class_id = ?";
    $params[] = $class_filter;
}

if ($search) {
    $query .= " AND (s.full_name LIKE ? OR s.admission_number LIKE ? OR s.parent_name LIKE ? OR s.parent_phone LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$query .= " GROUP BY s.id, s.full_name, s.admission_number, s.parent_name, s.parent_phone, c.id, c.class_name";

// Apply status filter
if ($status_filter !== 'all') {
    if ($status_filter === 'positive') {
        $query .= " HAVING current_balance > 0";
    } elseif ($status_filter === 'zero') {
        $query .= " HAVING current_balance = 0";
    } elseif ($status_filter === 'negative') {
        $query .= " HAVING current_balance < 0";
    } elseif ($status_filter === 'overdue') {
        $query .= " HAVING overdue_count > 0";
    }
}

// Apply sorting
switch ($sort_by) {
    case 'balance_asc':
        $query .= " ORDER BY current_balance ASC";
        break;
    case 'balance_desc':
        $query .= " ORDER BY current_balance DESC";
        break;
    case 'name_asc':
        $query .= " ORDER BY s.full_name ASC";
        break;
    case 'name_desc':
        $query .= " ORDER BY s.full_name DESC";
        break;
    case 'due_asc':
        $query .= " ORDER BY earliest_due ASC";
        break;
    case 'payment_desc':
        $query .= " ORDER BY last_payment_date DESC";
        break;
    default:
        $query .= " ORDER BY current_balance DESC";
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Get classes for filter
$classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll();

// Get summary statistics
$summary = $pdo->query("
    SELECT 
        COUNT(DISTINCT s.id) as total_students,
        COALESCE(SUM(CASE WHEN i.balance > 0 THEN i.balance ELSE 0 END), 0) as total_outstanding,
        COALESCE(SUM(CASE WHEN i.balance < 0 THEN ABS(i.balance) ELSE 0 END), 0) as total_credit,
        COUNT(DISTINCT CASE WHEN i.balance > 0 THEN s.id END) as students_with_balance,
        COUNT(DISTINCT CASE WHEN i.balance = 0 THEN s.id END) as students_cleared,
        COUNT(DISTINCT CASE WHEN i.balance < 0 THEN s.id END) as students_with_credit,
        COUNT(DISTINCT CASE WHEN i.due_date < CURDATE() AND i.balance > 0 THEN s.id END) as students_overdue,
        COALESCE(SUM(CASE WHEN i.due_date < CURDATE() AND i.balance > 0 THEN i.balance ELSE 0 END), 0) as overdue_amount
    FROM students s
    LEFT JOIN invoices i ON s.id = i.student_id AND i.status IN ('unpaid', 'partial')
    WHERE s.status = 'active'
")->fetch();

// Get recent balance adjustments
$recent_adjustments = $pdo->query("
    SELECT 
        ba.*,
        s.full_name as student_name,
        s.admission_number,
        u.full_name as adjusted_by_name
    FROM balance_adjustments ba
    JOIN students s ON ba.student_id = s.id
    LEFT JOIN users u ON ba.adjusted_by = u.id
    ORDER BY ba.adjusted_at DESC
    LIMIT 20
")->fetchAll();

// Get students with highest balances
$top_balances = $pdo->query("
    SELECT 
        s.id,
        s.full_name,
        s.admission_number,
        c.class_name,
        COALESCE(SUM(i.balance), 0) as balance
    FROM students s
    LEFT JOIN classes c ON s.class_id = c.id
    LEFT JOIN invoices i ON s.id = i.student_id AND i.status IN ('unpaid', 'partial')
    WHERE s.status = 'active'
    GROUP BY s.id, s.full_name, s.admission_number, c.class_name
    HAVING balance > 0
    ORDER BY balance DESC
    LIMIT 10
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
            border-left: 4px solid;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card.total { border-left-color: var(--primary); }
        .stat-card.outstanding { border-left-color: var(--danger); }
        .stat-card.cleared { border-left-color: var(--success); }
        .stat-card.overdue { border-left-color: var(--warning); }
        .stat-card.credit { border-left-color: var(--purple); }

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

        /* Top Balances */
        .top-balances {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .top-balances h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--dark);
            margin-bottom: 1rem;
        }

        .balance-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .balance-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: var(--light);
            border-radius: var(--border-radius-md);
            transition: var(--transition);
        }

        .balance-item:hover {
            transform: translateX(5px);
            background: rgba(67, 97, 238, 0.1);
        }

        .balance-student {
            flex: 1;
        }

        .balance-student strong {
            color: var(--dark);
        }

        .balance-student small {
            color: var(--gray);
            margin-left: 0.5rem;
        }

        .balance-amount {
            font-weight: 700;
            color: var(--danger);
            font-size: 1.1rem;
        }

        /* Student Cards Grid */
        .students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .student-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            transition: var(--transition);
            border: 1px solid var(--light);
            position: relative;
        }

        .student-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
            border-color: var(--primary);
        }

        .student-header {
            padding: 1.5rem 1.5rem 0 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .student-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--gradient-1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            font-weight: 600;
        }

        .student-info {
            flex: 1;
            margin-left: 1rem;
        }

        .student-name {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .student-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.85rem;
            color: var(--gray);
        }

        .student-meta i {
            width: 14px;
            color: var(--primary);
        }

        .balance-badge {
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .balance-positive {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        .balance-zero {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .balance-negative {
            background: rgba(114, 9, 183, 0.15);
            color: var(--purple);
        }

        .card-body {
            padding: 1.5rem;
        }

        .balance-details {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .detail-item {
            text-align: center;
            padding: 0.5rem;
            background: var(--light);
            border-radius: var(--border-radius-md);
        }

        .detail-label {
            font-size: 0.7rem;
            color: var(--gray);
            text-transform: uppercase;
            margin-bottom: 0.25rem;
        }

        .detail-value {
            font-size: 1.1rem;
            font-weight: 700;
        }

        .invoices-list {
            margin: 1rem 0;
            max-height: 150px;
            overflow-y: auto;
        }

        .invoice-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem;
            border-bottom: 1px dashed var(--light);
            font-size: 0.9rem;
        }

        .invoice-item:last-child {
            border-bottom: none;
        }

        .invoice-no {
            font-weight: 600;
            color: var(--primary);
        }

        .invoice-due {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .invoice-amount {
            font-weight: 600;
        }

        .invoice-amount.overdue {
            color: var(--danger);
        }

        .parent-info {
            padding: 0.75rem;
            background: var(--light);
            border-radius: var(--border-radius-md);
            margin: 1rem 0;
            font-size: 0.9rem;
        }

        .parent-info i {
            width: 18px;
            color: var(--primary);
        }

        .parent-contact {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.3rem;
            flex-wrap: wrap;
        }

        .contact-badge {
            padding: 0.2rem 0.5rem;
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
            border-radius: 4px;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .card-footer {
            padding: 1rem 1.5rem;
            background: var(--light);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            width: 36px;
            height: 36px;
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

        .action-btn.adjust { background: var(--warning); }
        .action-btn.remind { background: var(--info); }
        .action-btn.view { background: var(--primary); }
        .action-btn.pay { background: var(--success); }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .pagination a,
        .pagination span {
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius-sm);
            background: var(--white);
            color: var(--dark);
            text-decoration: none;
            transition: var(--transition);
            border: 1px solid var(--light);
        }

        .pagination a:hover {
            background: var(--gradient-1);
            color: white;
        }

        .pagination .active {
            background: var(--gradient-1);
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
            max-width: 500px;
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
        .form-group {
            margin-bottom: 1rem;
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

        .radio-group {
            display: flex;
            gap: 1.5rem;
            margin: 0.5rem 0;
        }

        .radio-group label {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-weight: normal;
            cursor: pointer;
        }

        /* Recent Adjustments */
        .adjustments-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-md);
            margin-top: 2rem;
        }

        .section-header {
            padding: 1rem 1.5rem;
            background: var(--gradient-1);
            color: white;
        }

        .adjustments-table {
            width: 100%;
            border-collapse: collapse;
        }

        .adjustments-table th {
            padding: 1rem;
            text-align: left;
            background: var(--light);
            color: var(--dark);
            font-size: 0.85rem;
            text-transform: uppercase;
        }

        .adjustments-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--light);
        }

        .adjustments-table tr:hover {
            background: rgba(67, 97, 238, 0.05);
        }

        .badge-credit {
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .badge-debit {
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .students-grid {
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
            
            .student-header {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            
            .student-info {
                margin-left: 0;
                margin-top: 0.5rem;
            }
            
            .balance-details {
                grid-template-columns: 1fr;
            }
            
            .card-footer {
                flex-direction: column;
                gap: 0.5rem;
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
                <h1><i class="fas fa-balance-scale"></i> Student Balances</h1>
                <p>Track and manage student fee balances</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="exportToExcel()">
                    <i class="fas fa-download"></i> Export
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

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card total stagger-item">
                <div class="stat-number"><?php echo $summary['total_students']; ?></div>
                <div class="stat-label">Active Students</div>
            </div>
            <div class="stat-card outstanding stagger-item">
                <div class="stat-number">KES <?php echo number_format($summary['total_outstanding'], 2); ?></div>
                <div class="stat-label">Total Outstanding</div>
                <div class="stat-detail"><?php echo $summary['students_with_balance']; ?> students</div>
            </div>
            <div class="stat-card overdue stagger-item">
                <div class="stat-number">KES <?php echo number_format($summary['overdue_amount'], 2); ?></div>
                <div class="stat-label">Overdue Amount</div>
                <div class="stat-detail"><?php echo $summary['students_overdue']; ?> students overdue</div>
            </div>
            <div class="stat-card cleared stagger-item">
                <div class="stat-number"><?php echo $summary['students_cleared']; ?></div>
                <div class="stat-label">Fully Cleared</div>
                <div class="stat-detail"><?php echo $summary['students_with_credit']; ?> have credit</div>
            </div>
        </div>

        <!-- Top Balances -->
        <?php if (!empty($top_balances)): ?>
        <div class="top-balances animate">
            <h3><i class="fas fa-trophy" style="color: var(--warning);"></i> Highest Outstanding Balances</h3>
            <div class="balance-list">
                <?php foreach ($top_balances as $top): ?>
                <div class="balance-item">
                    <div class="balance-student">
                        <strong><?php echo htmlspecialchars($top['full_name']); ?></strong>
                        <small><?php echo htmlspecialchars($top['class_name']); ?> - <?php echo $top['admission_number']; ?></small>
                    </div>
                    <div class="balance-amount">KES <?php echo number_format($top['balance'], 2); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filter Section -->
        <div class="filter-section animate">
            <form method="GET" id="filterForm">
                <div class="filter-grid">
                    <div class="form-group">
                        <label><i class="fas fa-search"></i> Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Student name, admission, parent..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-graduation-cap"></i> Class</label>
                        <select name="class_id" class="form-control">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-filter"></i> Balance Status</label>
                        <select name="status" class="form-control">
                            <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Students</option>
                            <option value="positive" <?php echo $status_filter == 'positive' ? 'selected' : ''; ?>>Owning (Balance > 0)</option>
                            <option value="zero" <?php echo $status_filter == 'zero' ? 'selected' : ''; ?>>Cleared (Balance = 0)</option>
                            <option value="negative" <?php echo $status_filter == 'negative' ? 'selected' : ''; ?>>In Credit (Balance < 0)</option>
                            <option value="overdue" <?php echo $status_filter == 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-sort"></i> Sort By</label>
                        <select name="sort_by" class="form-control">
                            <option value="balance_desc" <?php echo $sort_by == 'balance_desc' ? 'selected' : ''; ?>>Highest Balance</option>
                            <option value="balance_asc" <?php echo $sort_by == 'balance_asc' ? 'selected' : ''; ?>>Lowest Balance</option>
                            <option value="name_asc" <?php echo $sort_by == 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
                            <option value="name_desc" <?php echo $sort_by == 'name_desc' ? 'selected' : ''; ?>>Name Z-A</option>
                            <option value="due_asc" <?php echo $sort_by == 'due_asc' ? 'selected' : ''; ?>>Earliest Due</option>
                            <option value="payment_desc" <?php echo $sort_by == 'payment_desc' ? 'selected' : ''; ?>>Last Payment</option>
                        </select>
                    </div>
                    <div class="form-group" style="display: flex; gap: 0.5rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <a href="student_balances.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Students Grid -->
        <?php if (!empty($students)): ?>
        <div class="students-grid">
            <?php foreach ($students as $student): 
                $balance_class = $student['current_balance'] > 0 ? 'positive' : ($student['current_balance'] < 0 ? 'negative' : 'zero');
            ?>
            <div class="student-card animate">
                <div class="student-header">
                    <div class="student-avatar">
                        <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
                    </div>
                    <div class="student-info">
                        <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                        <div class="student-meta">
                            <span><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($student['admission_number']); ?></span>
                            <span><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($student['class_name']); ?></span>
                        </div>
                    </div>
                    <span class="balance-badge balance-<?php echo $balance_class; ?>">
                        <?php echo $student['current_balance'] > 0 ? 'Owning' : ($student['current_balance'] < 0 ? 'In Credit' : 'Cleared'); ?>
                    </span>
                </div>

                <div class="card-body">
                    <!-- Balance Details -->
                    <div class="balance-details">
                        <div class="detail-item">
                            <div class="detail-label">Invoiced</div>
                            <div class="detail-value">KES <?php echo number_format($student['total_invoiced'], 0); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Paid</div>
                            <div class="detail-value">KES <?php echo number_format($student['total_paid'], 0); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Balance</div>
                            <div class="detail-value" style="color: <?php echo $student['current_balance'] > 0 ? 'var(--danger)' : ($student['current_balance'] < 0 ? 'var(--purple)' : 'var(--success)'); ?>;">
                                KES <?php echo number_format(abs($student['current_balance']), 0); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Invoice Summary -->
                    <div style="display: flex; gap: 0.5rem; justify-content: space-around; margin-bottom: 1rem;">
                        <div style="text-align: center;">
                            <div style="font-size: 1.1rem; font-weight: 700; color: var(--primary);"><?php echo $student['invoice_count']; ?></div>
                            <div style="font-size: 0.7rem; color: var(--gray);">Total</div>
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 1.1rem; font-weight: 700; color: var(--warning);"><?php echo $student['unpaid_count']; ?></div>
                            <div style="font-size: 0.7rem; color: var(--gray);">Unpaid</div>
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 1.1rem; font-weight: 700; color: var(--success);"><?php echo $student['paid_count']; ?></div>
                            <div style="font-size: 0.7rem; color: var(--gray);">Paid</div>
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 1.1rem; font-weight: 700; color: var(--danger);"><?php echo $student['overdue_count']; ?></div>
                            <div style="font-size: 0.7rem; color: var(--gray);">Overdue</div>
                        </div>
                    </div>

                    <!-- Parent Information -->
                    <?php if ($student['parent_name'] || $student['parent_phone']): ?>
                    <div class="parent-info">
                        <?php if ($student['parent_name']): ?>
                        <div><i class="fas fa-user"></i> <?php echo htmlspecialchars($student['parent_name']); ?></div>
                        <?php endif; ?>
                        
                        <div class="parent-contact">
                            <?php if ($student['parent_phone']): ?>
                            <span class="contact-badge"><i class="fas fa-phone"></i> <?php echo $student['parent_phone']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Last Activity -->
                    <div style="font-size: 0.8rem; color: var(--gray);">
                        <?php if ($student['last_payment_date']): ?>
                        <i class="fas fa-credit-card"></i> Last payment: <?php echo date('d M Y', strtotime($student['last_payment_date'])); ?>
                        <?php else: ?>
                        <i class="fas fa-credit-card"></i> No payments yet
                        <?php endif; ?>
                        <?php if ($student['last_adjustment_date']): ?>
                        <br><i class="fas fa-edit"></i> Last adjustment: <?php echo date('d M Y', strtotime($student['last_adjustment_date'])); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-footer">
                    <div style="font-size: 0.85rem; color: var(--gray);">
                        <?php if ($student['earliest_due']): ?>
                        <i class="fas fa-calendar"></i> Due: <?php echo date('d M', strtotime($student['earliest_due'])); ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="action-buttons">
                        <button class="action-btn view" onclick="viewStudent(<?php echo $student['id']; ?>)" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="action-btn pay" onclick="recordPayment(<?php echo $student['id']; ?>)" title="Record Payment">
                            <i class="fas fa-credit-card"></i>
                        </button>
                        <?php if ($student['current_balance'] != 0): ?>
                        <button class="action-btn adjust" onclick="adjustBalance(<?php echo $student['id']; ?>, <?php echo $student['current_balance']; ?>)" title="Adjust Balance">
                            <i class="fas fa-edit"></i>
                        </button>
                        <?php endif; ?>
                        <?php if ($student['current_balance'] > 0 && $student['parent_phone']): ?>
                        <button class="action-btn remind" onclick="sendReminder(<?php echo $student['id']; ?>)" title="Send Reminder">
                            <i class="fas fa-bell"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination would go here if needed -->

        <?php else: ?>
        <div class="empty-state animate" style="text-align: center; padding: 4rem; background: var(--white); border-radius: var(--border-radius-lg);">
            <i class="fas fa-users fa-4x" style="color: var(--gray); margin-bottom: 1rem;"></i>
            <h3 style="color: var(--dark);">No Students Found</h3>
            <p style="color: var(--gray);">No students match your current filters.</p>
        </div>
        <?php endif; ?>

        <!-- Recent Balance Adjustments -->
        <?php if (!empty($recent_adjustments)): ?>
        <div class="adjustments-section animate">
            <div class="section-header">
                <h3 style="color: white;"><i class="fas fa-history"></i> Recent Balance Adjustments</h3>
            </div>
            <div class="table-responsive">
                <table class="adjustments-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Student</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Previous</th>
                            <th>New</th>
                            <th>Reason</th>
                            <th>Adjusted By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_adjustments as $adj): ?>
                        <tr>
                            <td><?php echo date('d M H:i', strtotime($adj['adjusted_at'])); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($adj['student_name']); ?></strong>
                                <div style="font-size: 0.8rem;"><?php echo $adj['admission_number']; ?></div>
                            </td>
                            <td>
                                <span class="<?php echo $adj['adjustment_type'] == 'credit' ? 'badge-credit' : 'badge-debit'; ?>">
                                    <?php echo ucfirst($adj['adjustment_type']); ?>
                                </span>
                            </td>
                            <td><strong>KES <?php echo number_format($adj['adjustment_amount'], 2); ?></strong></td>
                            <td>KES <?php echo number_format($adj['previous_balance'], 2); ?></td>
                            <td>KES <?php echo number_format($adj['new_balance'], 2); ?></td>
                            <td><?php echo htmlspecialchars($adj['reason']); ?></td>
                            <td><?php echo htmlspecialchars($adj['adjusted_by_name']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Adjust Balance Modal -->
    <div id="adjustModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Adjust Student Balance</h3>
                <button class="modal-close" onclick="closeModal('adjustModal')">&times;</button>
            </div>
            <form method="POST" id="adjustForm">
                <input type="hidden" name="student_id" id="adjust_student_id">
                <div class="modal-body">
                    <div id="currentBalanceDisplay" style="padding: 1rem; background: var(--light); border-radius: var(--border-radius-md); margin-bottom: 1rem;">
                        <strong>Current Balance:</strong> KES <span id="currentBalance">0.00</span>
                    </div>
                    
                    <div class="form-group">
                        <label class="required">Adjustment Type</label>
                        <div class="radio-group">
                            <label>
                                <input type="radio" name="adjustment_type" value="debit" checked> 
                                <i class="fas fa-plus-circle" style="color: var(--danger);"></i> Debit (Add to balance)
                            </label>
                            <label>
                                <input type="radio" name="adjustment_type" value="credit"> 
                                <i class="fas fa-minus-circle" style="color: var(--success);"></i> Credit (Reduce balance)
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="required">Amount</label>
                        <input type="number" name="adjustment" id="adjust_amount" class="form-control" step="0.01" min="0.01" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="required">Reason</label>
                        <textarea name="reason" id="adjust_reason" class="form-control" rows="3" required 
                                  placeholder="e.g., Scholarship, Fine, Correction, etc."></textarea>
                    </div>
                    
                    <div id="newBalancePreview" style="padding: 0.5rem; background: var(--light); border-radius: var(--border-radius-sm); font-size: 0.9rem;">
                        New balance will be: KES <span id="newBalance">0.00</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('adjustModal')">Cancel</button>
                    <button type="submit" name="update_balance" class="btn btn-warning">
                        <i class="fas fa-save"></i> Update Balance
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Send Reminder Modal -->
    <div id="reminderModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-bell"></i> Send Payment Reminder</h3>
                <button class="modal-close" onclick="closeModal('reminderModal')">&times;</button>
            </div>
            <form method="POST" id="reminderForm">
                <input type="hidden" name="student_id" id="reminder_student_id">
                <div class="modal-body">
                    <div id="studentBalanceInfo" style="margin-bottom: 1rem; padding: 1rem; background: var(--light); border-radius: var(--border-radius-md);">
                        Loading...
                    </div>
                    
                    <div class="form-group">
                        <label>Parent Phone</label>
                        <input type="text" name="parent_phone" id="reminder_phone" class="form-control" placeholder="Phone number">
                    </div>
                    
                    <p style="font-size: 0.85rem; color: var(--gray); margin-top: 0.5rem;">
                        <i class="fas fa-info-circle"></i> A reminder will be sent via SMS with the current balance.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('reminderModal')">Cancel</button>
                    <button type="submit" name="send_reminder" class="btn btn-info">
                        <i class="fas fa-paper-plane"></i> Send Reminder
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Adjust Balance Functions
        function adjustBalance(studentId, currentBalance) {
            document.getElementById('adjust_student_id').value = studentId;
            document.getElementById('currentBalance').textContent = currentBalance.toFixed(2);
            document.getElementById('adjust_amount').value = '';
            document.getElementById('adjust_reason').value = '';
            updateNewBalance();
            openModal('adjustModal');
        }

        function updateNewBalance() {
            const current = parseFloat(document.getElementById('currentBalance').textContent) || 0;
            const amount = parseFloat(document.getElementById('adjust_amount').value) || 0;
            const type = document.querySelector('input[name="adjustment_type"]:checked').value;
            
            let newBalance = type === 'debit' ? current + amount : current - amount;
            document.getElementById('newBalance').textContent = newBalance.toFixed(2);
        }

        document.querySelectorAll('input[name="adjustment_type"]').forEach(radio => {
            radio.addEventListener('change', updateNewBalance);
        });
        document.getElementById('adjust_amount').addEventListener('input', updateNewBalance);

        // Send Reminder Functions
        function sendReminder(studentId) {
            document.getElementById('reminder_student_id').value = studentId;
            
            // Load student info
            fetch(`get_student_balance.php?id=${studentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('studentBalanceInfo').innerHTML = `
                            <strong>${data.student.full_name}</strong> (${data.student.admission_number})<br>
                            <span style="color: var(--danger);">Balance: KES ${data.student.current_balance.toFixed(2)}</span>
                        `;
                        document.getElementById('reminder_phone').value = data.student.parent_phone || '';
                    }
                });
            
            openModal('reminderModal');
        }

        // Record Payment
        function recordPayment(studentId) {
            window.location.href = 'record_payment.php?student_id=' + studentId;
        }

        // View Student
        function viewStudent(studentId) {
            window.location.href = 'student_profile.php?id=' + studentId;
        }

        // Modal Functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // Export to Excel
        function exportToExcel() {
            // Get current filters
            const urlParams = new URLSearchParams(window.location.search);
            window.location.href = 'export_student_balances.php?' + urlParams.toString();
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