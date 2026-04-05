<?php
include '../config.php';
require_once '../library_fines_workflow_helpers.php';
checkAuth();
checkRole(['admin']);

$page_title = 'Library Fines Approval - ' . SCHOOL_NAME;

function approvalTableColumns(PDO $pdo, string $table): array {
    try {
        return array_fill_keys(
            $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN),
            true
        );
    } catch (Exception $e) {
        return [];
    }
}

function approvalStudentNameExpression(PDO $pdo, string $alias = 's'): string {
    $columns = approvalTableColumns($pdo, 'students');
    foreach (['full_name', 'name', 'student_name'] as $column) {
        if (isset($columns[$column])) {
            return "{$alias}.`{$column}`";
        }
    }

    return "CONCAT('Student #', {$alias}.id)";
}

function approvalAdmissionExpression(PDO $pdo, string $alias = 's'): string {
    $columns = approvalTableColumns($pdo, 'students');
    foreach (['admission_number', 'Admission_number', 'admission_no'] as $column) {
        if (isset($columns[$column])) {
            return "{$alias}.`{$column}`";
        }
    }

    return "CAST({$alias}.id AS CHAR)";
}

function approvalUserNameExpression(PDO $pdo, string $alias = 'u'): string {
    $columns = approvalTableColumns($pdo, 'users');
    foreach (['full_name', 'name', 'username', 'email'] as $column) {
        if (isset($columns[$column])) {
            return "{$alias}.`{$column}`";
        }
    }

    return "CONCAT('User #', {$alias}.id)";
}

ensureLibraryFineWorkflowSchema($pdo);

$studentNameExpr = approvalStudentNameExpression($pdo);
$admissionExpr = approvalAdmissionExpression($pdo);
$userNameExpr = approvalUserNameExpression($pdo);

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        if (isset($_POST['approve_fine'])) {
            $fine_id = intval($_POST['fine_id']);
            $notes = trim($_POST['approval_notes'] ?? '');
            
            $stmt = $pdo->prepare("
                UPDATE book_fines 
                SET status = 'approved', 
                    approved_by = ?, 
                    approved_at = NOW(),
                    approval_notes = CONCAT(IFNULL(approval_notes, ''), '\n', ?)
                WHERE id = ? AND status = 'pending'
            ");
            $stmt->execute([$_SESSION['user_id'], $notes, $fine_id]);

            // Create notification for librarian
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, message, reference_id, created_at)
                SELECT created_by, 'fine_approved', ?, ?, NOW()
                FROM book_fines WHERE id = ?
            ");
            $stmt->execute(["Fine #$fine_id has been approved", $fine_id, $fine_id]);

            $_SESSION['success'] = 'Fine approved successfully.';
        }

        if (isset($_POST['reject_fine'])) {
            $fine_id = intval($_POST['fine_id']);
            $reason = trim($_POST['rejection_reason']);
            
            if (empty($reason)) {
                throw new Exception('Please provide a reason for rejection');
            }

            $stmt = $pdo->prepare("
                UPDATE book_fines 
                SET status = 'rejected', 
                    approved_by = ?, 
                    approved_at = NOW(),
                    approval_notes = CONCAT(IFNULL(approval_notes, ''), '\nRejected: ', ?)
                WHERE id = ? AND status = 'pending'
            ");
            $stmt->execute([$_SESSION['user_id'], $reason, $fine_id]);

            // Create notification for librarian
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, message, reference_id, created_at)
                SELECT created_by, 'fine_rejected', ?, ?, NOW()
                FROM book_fines WHERE id = ?
            ");
            $stmt->execute(["Fine #$fine_id was rejected: $reason", $fine_id, $fine_id]);

            $_SESSION['success'] = 'Fine rejected successfully.';
        }

        if (isset($_POST['approve_lost_book'])) {
            $lost_id = intval($_POST['lost_id']);
            $notes = trim($_POST['approval_notes'] ?? '');
            
            $stmt = $pdo->prepare("
                UPDATE lost_books 
                SET status = 'sent_to_accountant', 
                    approved_by = ?, 
                    approved_at = NOW(),
                    approval_notes = CONCAT(IFNULL(approval_notes, ''), '\n', ?)
                WHERE id = ? AND status = 'pending'
            ");
            $stmt->execute([$_SESSION['user_id'], $notes, $lost_id]);

            // Update book status to lost permanently
            $stmt = $pdo->prepare("
                UPDATE books b
                JOIN lost_books lb ON b.id = lb.book_id
                SET b.status = 'lost', b.notes = CONCAT(IFNULL(b.notes, ''), '\nMarked as lost: ', IFNULL(lb.notes, 'Lost book report approved'))
                WHERE lb.id = ?
            ");
            $stmt->execute([$lost_id]);

            $_SESSION['success'] = 'Lost book report approved and sent to accountant for invoicing.';
        }

        if (isset($_POST['reject_lost_book'])) {
            $lost_id = intval($_POST['lost_id']);
            $reason = trim($_POST['rejection_reason']);
            
            if (empty($reason)) {
                throw new Exception('Please provide a reason for rejection');
            }

            $stmt = $pdo->prepare("
                UPDATE lost_books 
                SET status = 'rejected', 
                    approved_by = ?, 
                    approved_at = NOW(),
                    approval_notes = CONCAT(IFNULL(approval_notes, ''), '\nRejected: ', ?)
                WHERE id = ? AND status = 'pending'
            ");
            $stmt->execute([$_SESSION['user_id'], $reason, $lost_id]);

            // Restore book status
            $stmt = $pdo->prepare("
                UPDATE books b
                JOIN lost_books lb ON b.id = lb.book_id
                SET b.status = 'available'
                WHERE lb.id = ?
            ");
            $stmt->execute([$lost_id]);

            $_SESSION['success'] = 'Lost book report rejected.';
        }

        $pdo->commit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }

    header('Location: library_fines_approvals.php');
    exit();
}

// Get pending fines
$pending_fines = $pdo->query("
    SELECT bf.*,
           CONCAT('FINE-', LPAD(bf.id, 5, '0')) AS fine_code,
           b.title as book_title,
           {$studentNameExpr} as student_name,
           {$admissionExpr} as admission_number,
           {$userNameExpr} as librarian_name,
           COALESCE(bf.amount, 0) AS fine_amount,
           COALESCE(bf.notes, '') AS reason,
           DATEDIFF(NOW(), bf.created_at) as days_pending
    FROM book_fines bf
    JOIN books b ON bf.book_id = b.id
    JOIN students s ON bf.student_id = s.id
    LEFT JOIN users u ON bf.created_by = u.id
    WHERE bf.status = 'pending'
    ORDER BY bf.created_at ASC
")->fetchAll();

// Get pending lost books
$pending_lost = $pdo->query("
    SELECT lb.*,
           CONCAT('LB-', LPAD(lb.id, 5, '0')) as lost_code,
           COALESCE(lb.book_title, lb.title, b.title) as title,
           COALESCE(lb.book_isbn, lb.isbn, b.isbn) as isbn,
           {$studentNameExpr} as student_name,
           {$admissionExpr} as admission_number,
           {$userNameExpr} as librarian_name,
           COALESCE(lb.original_price, 0) as book_price,
           COALESCE(lb.notes, '') as reason,
           COALESCE(lb.loss_date, lb.report_date, lb.created_at) as date_lost
    FROM lost_books lb
    LEFT JOIN books b ON lb.book_id = b.id
    JOIN students s ON lb.student_id = s.id
    LEFT JOIN users u ON lb.created_by = u.id
    WHERE lb.status = 'pending'
    ORDER BY lb.created_at ASC
")->fetchAll();

// Get approval history
$approval_history = $pdo->query("
    (SELECT 
        'fine' as type,
        bf.id,
        CONCAT('FINE-', LPAD(bf.id, 5, '0')) as code,
        {$studentNameExpr} as student_name,
        COALESCE(bf.amount, 0) as amount,
        bf.status,
        {$userNameExpr} as approved_by_name,
        bf.approved_at,
        bf.approval_notes,
        NULL as rejection_reason
    FROM book_fines bf
    JOIN students s ON bf.student_id = s.id
    LEFT JOIN users u ON bf.approved_by = u.id
    WHERE bf.status IN ('approved', 'rejected')
    LIMIT 20)
    UNION ALL
    (SELECT 
        'lost' as type,
        lb.id,
        CONCAT('LB-', LPAD(lb.id, 5, '0')) as code,
        {$studentNameExpr} as student_name,
        lb.total_amount as amount,
        lb.status,
        {$userNameExpr} as approved_by_name,
        lb.approved_at,
        lb.approval_notes,
        NULL as rejection_reason
    FROM lost_books lb
    JOIN students s ON lb.student_id = s.id
    LEFT JOIN users u ON lb.approved_by = u.id
    WHERE lb.status IN ('approved', 'rejected')
    LIMIT 20)
    ORDER BY approved_at DESC
    LIMIT 30
")->fetchAll();

// Get statistics
$stats = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM book_fines WHERE status = 'pending') as pending_fines,
        (SELECT COUNT(*) FROM lost_books WHERE status = 'pending') as pending_lost,
        (SELECT COALESCE(SUM(amount), 0) FROM book_fines WHERE status = 'pending') as pending_fines_amount,
        (SELECT COALESCE(SUM(total_amount), 0) FROM lost_books WHERE status = 'pending') as pending_lost_amount,
        (SELECT COUNT(*) FROM book_fines WHERE approved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as approved_week,
        (SELECT COUNT(*) FROM lost_books WHERE approved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as lost_approved_week
")->fetch();
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
            --gradient-approval: linear-gradient(135deg, #4361ee 0%, #3f37c9 100%);
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
            background: var(--gradient-approval);
            border-radius: var(--border-radius-xl);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
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

        .page-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .page-header p {
            opacity: 0.9;
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
            border-left: 4px solid;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card.fines { border-left-color: var(--warning); }
        .stat-card.lost { border-left-color: var(--danger); }
        .stat-card.amount { border-left-color: var(--primary); }
        .stat-card.approved { border-left-color: var(--success); }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-detail {
            font-size: 0.8rem;
            color: var(--gray);
            margin-top: 0.3rem;
        }

        /* Tabs */
        .tabs {
            display: flex;
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow-x: auto;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .tab {
            padding: 1rem 1.5rem;
            cursor: pointer;
            font-weight: 600;
            color: var(--gray);
            transition: var(--transition);
            border-bottom: 3px solid transparent;
            white-space: nowrap;
            flex: 1;
            text-align: center;
        }

        .tab:hover {
            color: var(--primary);
            background: rgba(67, 97, 238, 0.05);
        }

        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: rgba(67, 97, 238, 0.05);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Cards Grid */
        .approval-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .approval-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            transition: var(--transition);
            border: 1px solid var(--light);
        }

        .approval-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-xl);
            border-color: var(--primary);
        }

        .card-header {
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, var(--light), #fff);
            border-bottom: 2px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-code {
            font-weight: 700;
            color: var(--primary);
        }

        .card-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .card-badge.pending {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        .card-body {
            padding: 1.5rem;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--light);
        }

        .info-label {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .info-value {
            font-weight: 600;
            color: var(--dark);
        }

        .amount-large {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--danger);
            text-align: center;
            padding: 1rem;
            background: var(--light);
            border-radius: var(--border-radius-md);
            margin: 1rem 0;
        }

        .card-footer {
            padding: 1rem 1.5rem;
            background: var(--light);
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-approved {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .status-rejected {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        /* History Table */
        .data-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            margin-top: 2rem;
        }

        .card-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid var(--light);
        }

        tr:hover {
            background: rgba(67, 97, 238, 0.05);
        }

        /* Alert */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius-md);
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            animation: slideIn 0.3s ease;
            border-left: 4px solid;
        }

        .alert-success {
            background: rgba(76, 201, 240, 0.1);
            border-color: var(--success);
            color: var(--success);
        }

        .alert-danger {
            background: rgba(249, 65, 68, 0.1);
            border-color: var(--danger);
            color: var(--danger);
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
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
        }

        .modal-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
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

        .form-group {
            margin-bottom: 1rem;
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
            border-radius: var(--border-radius-sm);
            font-size: 0.95rem;
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .approval-grid {
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
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .tabs {
                flex-wrap: wrap;
            }
        }

        .animate {
            animation: fadeInUp 0.6s ease-out;
        }

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
                <h1><i class="fas fa-check-double"></i> Library Fines Approval</h1>
                <p>Review and approve overdue fines and lost book reports</p>
            </div>
            <div class="header-actions">
                <a href="dashboard.php" class="btn btn-outline" style="color: white; border-color: white;">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success animate">
            <div>
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; ?>
            </div>
            <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: inherit; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger animate">
            <div>
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; ?>
            </div>
            <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: inherit; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['error']); endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card fines stagger-item">
                <div class="stat-number"><?php echo $stats['pending_fines']; ?></div>
                <div class="stat-label">Pending Fines</div>
                <div class="stat-detail">KES <?php echo number_format($stats['pending_fines_amount'], 2); ?></div>
            </div>
            <div class="stat-card lost stagger-item">
                <div class="stat-number"><?php echo $stats['pending_lost']; ?></div>
                <div class="stat-label">Lost Books</div>
                <div class="stat-detail">KES <?php echo number_format($stats['pending_lost_amount'], 2); ?></div>
            </div>
            <div class="stat-card approved stagger-item">
                <div class="stat-number"><?php echo $stats['approved_week'] + $stats['lost_approved_week']; ?></div>
                <div class="stat-label">Approved (7 days)</div>
            </div>
            <div class="stat-card amount stagger-item">
                <div class="stat-number">KES <?php echo number_format($stats['pending_fines_amount'] + $stats['pending_lost_amount'], 2); ?></div>
                <div class="stat-label">Total Pending</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs animate">
            <div class="tab active" onclick="switchTab('fines')">Overdue Fines (<?php echo count($pending_fines); ?>)</div>
            <div class="tab" onclick="switchTab('lost')">Lost Books (<?php echo count($pending_lost); ?>)</div>
            <div class="tab" onclick="switchTab('history')">Approval History</div>
        </div>

        <!-- Fines Tab -->
        <div id="finesTab" class="tab-content active">
            <?php if (!empty($pending_fines)): ?>
                <div class="approval-grid">
                    <?php foreach ($pending_fines as $fine): ?>
                    <div class="approval-card">
                        <div class="card-header">
                            <span class="card-code"><?php echo htmlspecialchars($fine['fine_code']); ?></span>
                            <span class="card-badge pending">Pending</span>
                        </div>
                        <div class="card-body">
                            <div class="info-row">
                                <span class="info-label">Student</span>
                                <span class="info-value"><?php echo htmlspecialchars($fine['student_name']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Admission</span>
                                <span class="info-value"><?php echo $fine['admission_number']; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Book</span>
                                <span class="info-value"><?php echo htmlspecialchars($fine['book_title']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Days Overdue</span>
                                <span class="info-value"><?php echo $fine['days_overdue']; ?> days</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Reported By</span>
                                <span class="info-value"><?php echo htmlspecialchars($fine['librarian_name']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Reported</span>
                                <span class="info-value"><?php echo date('d M Y', strtotime($fine['created_at'])); ?></span>
                            </div>
                            <div class="amount-large">
                                KES <?php echo number_format($fine['fine_amount'], 2); ?>
                            </div>
                            <?php if ($fine['reason']): ?>
                            <div style="background: var(--light); padding: 0.8rem; border-radius: var(--border-radius-sm); font-size: 0.9rem;">
                                <strong>Reason:</strong> <?php echo htmlspecialchars($fine['reason']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer">
                            <button class="btn btn-success btn-sm" onclick="approveFine(<?php echo $fine['id']; ?>)">
                                <i class="fas fa-check"></i> Approve
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="rejectFine(<?php echo $fine['id']; ?>)">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 3rem; background: var(--white); border-radius: var(--border-radius-lg);">
                    <i class="fas fa-check-circle fa-3x" style="color: var(--success); margin-bottom: 1rem;"></i>
                    <h3>No Pending Fines</h3>
                    <p style="color: var(--gray);">All fines have been processed.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Lost Books Tab -->
        <div id="lostTab" class="tab-content">
            <?php if (!empty($pending_lost)): ?>
                <div class="approval-grid">
                    <?php foreach ($pending_lost as $lost): ?>
                    <div class="approval-card">
                        <div class="card-header">
                            <span class="card-code"><?php echo htmlspecialchars($lost['lost_code']); ?></span>
                            <span class="card-badge pending">Pending</span>
                        </div>
                        <div class="card-body">
                            <div class="info-row">
                                <span class="info-label">Student</span>
                                <span class="info-value"><?php echo htmlspecialchars($lost['student_name']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Admission</span>
                                <span class="info-value"><?php echo $lost['admission_number']; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Book</span>
                                <span class="info-value"><?php echo htmlspecialchars($lost['title']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">ISBN</span>
                                <span class="info-value"><?php echo $lost['isbn'] ?? 'N/A'; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Book Price</span>
                                <span class="info-value">KES <?php echo number_format($lost['book_price'], 2); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Date Lost</span>
                                <span class="info-value"><?php echo date('d M Y', strtotime($lost['date_lost'])); ?></span>
                            </div>
                            <div class="amount-large">
                                KES <?php echo number_format($lost['total_amount'], 2); ?>
                            </div>
                            <?php if ($lost['reason']): ?>
                            <div style="background: var(--light); padding: 0.8rem; border-radius: var(--border-radius-sm); font-size: 0.9rem;">
                                <strong>Reason:</strong> <?php echo htmlspecialchars($lost['reason']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer">
                            <button class="btn btn-success btn-sm" onclick="approveLostBook(<?php echo $lost['id']; ?>)">
                                <i class="fas fa-check"></i> Approve
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="rejectLostBook(<?php echo $lost['id']; ?>)">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 3rem; background: var(--white); border-radius: var(--border-radius-lg);">
                    <i class="fas fa-check-circle fa-3x" style="color: var(--success); margin-bottom: 1rem;"></i>
                    <h3>No Lost Book Reports</h3>
                    <p style="color: var(--gray);">All lost book reports have been processed.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- History Tab -->
        <div id="historyTab" class="tab-content">
            <div class="data-card">
                <div class="card-header">
                    <h3><i class="fas fa-history"></i> Recent Approval History</h3>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Code</th>
                                <th>Student</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Approved By</th>
                                <th>Date</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($approval_history as $item): ?>
                            <tr>
                                <td>
                                    <span class="status-badge <?php echo $item['type'] == 'fine' ? 'status-pending' : 'status-lost'; ?>">
                                        <?php echo ucfirst($item['type']); ?>
                                    </span>
                                </td>
                                <td><strong><?php echo htmlspecialchars($item['code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($item['student_name']); ?></td>
                                <td>KES <?php echo number_format($item['amount'], 2); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $item['status']; ?>">
                                        <?php echo ucfirst($item['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($item['approved_by_name'] ?? 'N/A'); ?></td>
                                <td><?php echo $item['approved_at'] ? date('d M Y', strtotime($item['approved_at'])) : '-'; ?></td>
                                <td>
                                    <?php if ($item['status'] == 'rejected'): ?>
                                        <span title="<?php echo htmlspecialchars($item['approval_notes'] ?? 'Rejected'); ?>">
                                            <i class="fas fa-comment"></i>
                                        </span>
                                    <?php elseif ($item['approval_notes']): ?>
                                        <span title="<?php echo htmlspecialchars($item['approval_notes']); ?>">
                                            <i class="fas fa-sticky-note"></i>
                                        </span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Approve Modal -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle"></i> Approve Item</h3>
                <button class="modal-close" onclick="closeModal('approveModal')">&times;</button>
            </div>
            <form method="POST" id="approveForm">
                <input type="hidden" name="approve_fine" id="approveType">
                <input type="hidden" name="fine_id" id="approveId">
                <input type="hidden" name="lost_id" id="approveLostId">
                
                <div class="modal-body">
                    <p>Are you sure you want to approve this item?</p>
                    <div class="form-group">
                        <label>Approval Notes (Optional)</label>
                        <textarea name="approval_notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('approveModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Approve</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-times-circle"></i> Reject Item</h3>
                <button class="modal-close" onclick="closeModal('rejectModal')">&times;</button>
            </div>
            <form method="POST" id="rejectForm">
                <input type="hidden" name="reject_fine" id="rejectType">
                <input type="hidden" name="fine_id" id="rejectFineId">
                <input type="hidden" name="lost_id" id="rejectLostId">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Reason for Rejection</label>
                        <textarea name="rejection_reason" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('rejectModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            if (tabName === 'fines') {
                document.querySelectorAll('.tab')[0].classList.add('active');
                document.getElementById('finesTab').classList.add('active');
            } else if (tabName === 'lost') {
                document.querySelectorAll('.tab')[1].classList.add('active');
                document.getElementById('lostTab').classList.add('active');
            } else if (tabName === 'history') {
                document.querySelectorAll('.tab')[2].classList.add('active');
                document.getElementById('historyTab').classList.add('active');
            }
        }

        function approveFine(fineId) {
            document.getElementById('approveType').name = 'approve_fine';
            document.getElementById('approveId').value = fineId;
            document.getElementById('approveLostId').disabled = true;
            document.getElementById('approveModal').classList.add('active');
        }

        function approveLostBook(lostId) {
            document.getElementById('approveType').name = 'approve_lost_book';
            document.getElementById('approveLostId').value = lostId;
            document.getElementById('approveId').disabled = true;
            document.getElementById('approveModal').classList.add('active');
        }

        function rejectFine(fineId) {
            document.getElementById('rejectType').name = 'reject_fine';
            document.getElementById('rejectFineId').value = fineId;
            document.getElementById('rejectLostId').disabled = true;
            document.getElementById('rejectModal').classList.add('active');
        }

        function rejectLostBook(lostId) {
            document.getElementById('rejectType').name = 'reject_lost_book';
            document.getElementById('rejectLostId').value = lostId;
            document.getElementById('rejectFineId').disabled = true;
            document.getElementById('rejectModal').classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
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
