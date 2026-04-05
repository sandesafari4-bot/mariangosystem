<?php
include '../config.php';
require_once '../library_fines_workflow_helpers.php';
checkAuth();
checkRole(['librarian', 'admin']);

$page_title = 'Library Fines Management - ' . SCHOOL_NAME;

function finesTableColumns(PDO $pdo, string $table): array {
    try {
        return array_fill_keys(
            $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN),
            true
        );
    } catch (Exception $e) {
        return [];
    }
}

function finesStudentNameExpression(PDO $pdo, string $alias = 's'): string {
    $columns = finesTableColumns($pdo, 'students');
    foreach (['full_name', 'name', 'student_name'] as $column) {
        if (isset($columns[$column])) {
            return "{$alias}.`{$column}`";
        }
    }

    return "CONCAT('Student #', {$alias}.id)";
}

function finesAdmissionExpression(PDO $pdo, string $alias = 's'): string {
    $columns = finesTableColumns($pdo, 'students');
    foreach (['Admission_number', 'admission_number', 'admission_no'] as $column) {
        if (isset($columns[$column])) {
            return "{$alias}.`{$column}`";
        }
    }

    return "CAST({$alias}.id AS CHAR)";
}

function finesUserNameExpression(PDO $pdo, string $alias = 'u'): string {
    $columns = finesTableColumns($pdo, 'users');
    foreach (['full_name', 'name', 'username', 'email'] as $column) {
        if (isset($columns[$column])) {
            return "{$alias}.`{$column}`";
        }
    }

    return "CONCAT('User #', {$alias}.id)";
}

function finesDateColumn(PDO $pdo, string $table, array $candidates, string $fallback): string {
    foreach ($candidates as $column) {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        if ($stmt->fetch()) {
            return $column;
        }
    }

    return $fallback;
}

function finesFetchAll(PDO $pdo, string $sql): array {
    try {
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function finesFetchOne(PDO $pdo, string $sql): array {
    try {
        $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        return $row ?: [];
    } catch (Exception $e) {
        return [];
    }
}

function ensureFinesTables(PDO $pdo): void {
    ensureLibraryFineWorkflowSchema($pdo);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fine_payments (
            id INT PRIMARY KEY AUTO_INCREMENT,
            payment_code VARCHAR(50) UNIQUE NOT NULL,
            fine_type VARCHAR(50) NOT NULL,
            fine_id INT NOT NULL,
            student_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_method VARCHAR(50) NOT NULL,
            transaction_id VARCHAR(100),
            payment_date DATETIME NOT NULL,
            received_by INT NOT NULL,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fine (fine_type, fine_id),
            INDEX idx_student (student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

ensureFinesTables($pdo);

$studentNameExpr = finesStudentNameExpression($pdo);
$admissionExpr = finesAdmissionExpression($pdo);
$userNameExpr = finesUserNameExpression($pdo);
$lostBooksDateColumn = finesDateColumn($pdo, 'lost_books', ['date_lost', 'loss_date', 'report_date', 'created_at'], 'created_at');
$bookPriceExpr = "0";
$bookColumns = finesTableColumns($pdo, 'books');
foreach (['price', 'cost_price', 'unit_price'] as $bookPriceColumn) {
    if (isset($bookColumns[$bookPriceColumn])) {
        $bookPriceExpr = "COALESCE(b.`{$bookPriceColumn}`, 0)";
        break;
    }
}

$user_id = $_SESSION['user_id'];
$activeLostStatusesSql = "'reported', 'pending', 'submitted_for_approval', 'approved', 'verified', 'sent_to_accountant', 'invoiced', 'paid'";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['add_fine'])) {
            $pdo->beginTransaction();

            $loan_id = intval($_POST['book_loan_id']);
            $stmt = $pdo->prepare("
                SELECT bi.*, b.title as book_title, {$bookPriceExpr} as book_price, {$studentNameExpr} as student_name
                FROM book_issues bi
                JOIN books b ON bi.book_id = b.id
                JOIN students s ON bi.student_id = s.id
                WHERE bi.id = ?
            ");
            $stmt->execute([$loan_id]);
            $loan = $stmt->fetch();

            if (!$loan) {
                throw new Exception('Book loan not found');
            }

            $days_overdue = max(0, intval($_POST['days_overdue']));
            $fine_per_day = floatval($_POST['fine_per_day'] ?? 20);
            $fine_amount = $days_overdue * $fine_per_day;
            
            // Add damage fee if applicable
            if (isset($_POST['damage_fee']) && $_POST['damage_fee'] > 0) {
                $fine_amount += floatval($_POST['damage_fee']);
            }

            $stmt = $pdo->prepare("
                INSERT INTO book_fines (
                    issue_id, student_id, book_id, fine_type, amount,
                    days_overdue, status, created_by, notes
                ) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)
            ");

            $stmt->execute([
                $loan_id,
                $loan['student_id'],
                $loan['book_id'],
                $_POST['fine_type'],
                $fine_amount,
                $days_overdue,
                $user_id
                ,
                $_POST['reason'] ?? null
            ]);

            $pdo->commit();
            $_SESSION['success'] = 'Fine added successfully and pending approval.';
        }

        if (isset($_POST['report_lost_book'])) {
            $pdo->beginTransaction();

            $issueId = intval($_POST['issue_id'] ?? 0);
            if ($issueId <= 0) {
                throw new Exception('Please select an active issued book.');
            }

            $issueStmt = $pdo->prepare("
                SELECT bi.id, bi.book_id, bi.student_id, b.title AS book_title, b.isbn,
                       {$bookPriceExpr} AS book_price
                FROM book_issues bi
                JOIN books b ON bi.book_id = b.id
                WHERE bi.id = ? AND bi.return_date IS NULL
                LIMIT 1
            ");
            $issueStmt->execute([$issueId]);
            $issue = $issueStmt->fetch(PDO::FETCH_ASSOC);

            if (!$issue) {
                throw new Exception('The selected issue is no longer active.');
            }

            $existingLostStmt = $pdo->prepare("
                SELECT id
                FROM lost_books
                WHERE issue_id = ?
                  AND status IN ($activeLostStatusesSql)
                LIMIT 1
            ");
            $existingLostStmt->execute([$issueId]);

            if ($existingLostStmt->fetch()) {
                throw new Exception('This book issue has already been reported lost.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO lost_books (
                    book_id, student_id, book_title, book_isbn, original_price, isbn, title,
                    issue_id, loss_date, report_date, replacement_cost, fine_amount, total_amount,
                    reported_by, created_by, status, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'reported', ?)
            ");

            $replacementCost = floatval($_POST['replacement_cost'] ?? 0);
            $processingFee = floatval($_POST['processing_fee'] ?? 0);
            $bookPrice = floatval($_POST['book_price'] ?? 0);
            $dateLost = $_POST['date_lost'] ?? date('Y-m-d');

            $stmt->execute([
                $issue['book_id'],
                $issue['student_id'],
                $issue['book_title'],
                $issue['isbn'] ?? null,
                $bookPrice ?: (float) ($issue['book_price'] ?? 0),
                $issue['isbn'] ?? null,
                $issue['book_title'],
                $issueId,
                $dateLost,
                $dateLost,
                $replacementCost,
                $processingFee,
                $replacementCost + $processingFee,
                $user_id,
                $user_id
                ,
                $_POST['reason']
            ]);

            $pdo->commit();
            $_SESSION['success'] = 'Lost book reported successfully and pending approval.';
        }

        if (isset($_POST['submit_lost_for_approval'])) {
            $lostId = (int) ($_POST['lost_id'] ?? 0);

            $stmt = $pdo->prepare("
                UPDATE lost_books
                SET status = 'pending',
                    submitted_by = ?,
                    submitted_at = NOW()
                WHERE id = ?
                  AND status IN ('reported', 'rejected')
            ");
            $stmt->execute([$user_id, $lostId]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('This lost book report is not available for submission.');
            }

            $_SESSION['success'] = 'Lost book report submitted for approval.';
        }

        if (isset($_POST['waive_fine'])) {
            $stmt = $pdo->prepare("
                UPDATE book_fines 
                SET status = 'waived',
                    approval_notes = CONCAT(COALESCE(approval_notes, ''), IF(COALESCE(approval_notes, '') = '', '', '\n'), 'Waived: ', ?)
                WHERE id = ? AND status = 'pending'
            ");
            $stmt->execute([$_POST['waive_reason'], $_POST['fine_id']]);
            $_SESSION['success'] = 'Fine waived successfully.';
        }

        if (isset($_POST['record_payment'])) {
            $pdo->beginTransaction();

            $payment_code = 'PAY' . date('Ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare("
                INSERT INTO fine_payments (
                    payment_code, fine_type, fine_id, student_id, amount,
                    payment_method, transaction_id, payment_date, received_by, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
            ");

            $stmt->execute([
                $payment_code,
                $_POST['fine_type'],
                $_POST['fine_id'],
                $_POST['student_id'],
                $_POST['amount'],
                $_POST['payment_method'],
                $_POST['transaction_id'] ?? null,
                $user_id,
                $_POST['notes'] ?? null
            ]);

            // Update fine status
            if ($_POST['fine_type'] == 'overdue') {
                $stmt = $pdo->prepare("UPDATE book_fines SET status = 'paid' WHERE id = ?");
            } else {
                $stmt = $pdo->prepare("UPDATE lost_books SET status = 'paid' WHERE id = ?");
            }
            $stmt->execute([$_POST['fine_id']]);

            $pdo->commit();
            $_SESSION['success'] = 'Payment recorded successfully.';
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }

    header('Location: fines.php');
    exit();
}

// Get active book loans for dropdown
$active_loans = finesFetchAll($pdo, "
    SELECT bi.*,
           bi.issue_date as borrow_date,
           CASE WHEN bi.return_date IS NULL AND bi.due_date < CURDATE() THEN 'overdue' ELSE COALESCE(bi.status, 'issued') END as status,
           b.title,
           b.isbn,
           {$bookPriceExpr} as book_price,
           {$studentNameExpr} as full_name,
           {$admissionExpr} as admission_number,
           DATEDIFF(CURDATE(), bi.due_date) as days_overdue
    FROM book_issues bi
    JOIN books b ON bi.book_id = b.id
    JOIN students s ON bi.student_id = s.id
    LEFT JOIN lost_books lb
        ON lb.issue_id = bi.id
       AND lb.status IN ($activeLostStatusesSql)
    WHERE bi.return_date IS NULL
      AND lb.id IS NULL
    ORDER BY bi.due_date ASC
");

// Get pending fines
$pending_fines = finesFetchAll($pdo, "
    SELECT bf.*,
           b.title as book_title,
           {$studentNameExpr} as full_name,
           {$admissionExpr} as admission_number,
           {$userNameExpr} as created_by_name,
           COALESCE(bf.amount, 0) as fine_amount,
           COALESCE(bf.amount, 0) as final_amount,
           COALESCE(bf.notes, '') as reason
    FROM book_fines bf
    JOIN books b ON bf.book_id = b.id
    JOIN students s ON bf.student_id = s.id
    LEFT JOIN users u ON bf.created_by = u.id
    WHERE bf.status = 'pending'
    ORDER BY bf.created_at DESC
");

// Get approved fines
$approved_fines = finesFetchAll($pdo, "
    SELECT bf.*,
           b.title as book_title,
           {$studentNameExpr} as full_name,
           {$admissionExpr} as admission_number,
           {$userNameExpr} as approved_by_name,
           COALESCE(bf.amount, 0) as fine_amount,
           COALESCE(bf.amount, 0) as final_amount,
           COALESCE(bf.notes, '') as reason
    FROM book_fines bf
    JOIN books b ON bf.book_id = b.id
    JOIN students s ON bf.student_id = s.id
    LEFT JOIN users u ON bf.approved_by = u.id
    WHERE bf.status IN ('approved', 'waived', 'paid', 'invoiced')
    ORDER BY COALESCE(bf.approved_at, bf.created_at) DESC
    LIMIT 50
");

// Get lost books
$lost_books = finesFetchAll($pdo, "
    SELECT lb.*,
           CONCAT('LB-', LPAD(lb.id, 5, '0')) as lost_code,
           COALESCE(lb.book_title, lb.title, b.title) as title,
           {$studentNameExpr} as full_name,
           {$admissionExpr} as admission_number,
           {$userNameExpr} as created_by_name,
           COALESCE(lb.original_price, 0) as book_price,
           COALESCE(lb.fine_amount, 0) as processing_fee,
           {$lostBooksDateColumn} as date_lost
    FROM lost_books lb
    LEFT JOIN books b ON lb.book_id = b.id
    JOIN students s ON lb.student_id = s.id
    LEFT JOIN users u ON lb.created_by = u.id
    WHERE lb.status IN ('reported', 'pending', 'submitted_for_approval', 'approved', 'rejected')
    ORDER BY COALESCE(lb.{$lostBooksDateColumn}, lb.created_at) DESC
");

// Get statistics
$stats = finesFetchOne($pdo, "
    SELECT 
        (SELECT COUNT(*) FROM book_fines WHERE status = 'pending') as pending_fines,
        (SELECT COUNT(*) FROM lost_books WHERE status IN ('reported', 'pending', 'submitted_for_approval')) as pending_lost,
        (SELECT COALESCE(SUM(amount), 0) FROM book_fines WHERE status = 'pending') as pending_amount,
        (SELECT COALESCE(SUM(total_amount), 0) FROM lost_books WHERE status IN ('reported', 'pending', 'submitted_for_approval')) as pending_lost_amount,
        (SELECT COUNT(*) FROM book_fines WHERE status = 'paid') as paid_fines,
        (SELECT COALESCE(SUM(amount), 0) FROM book_fines WHERE status = 'paid') as collected_amount
");
$stats = array_merge([
    'pending_fines' => 0,
    'pending_lost' => 0,
    'pending_amount' => 0,
    'pending_lost_amount' => 0,
    'paid_fines' => 0,
    'collected_amount' => 0,
], $stats);

$page_title = 'Library Fines Management - ' . SCHOOL_NAME;
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
            --gradient-fines: linear-gradient(135deg, #f94144 0%, #d93235 100%);
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
            background: var(--gradient-fines);
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
            flex-wrap: wrap;
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

        .btn-light {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        .btn-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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

        .stat-card.pending { border-left-color: var(--warning); }
        .stat-card.amount { border-left-color: var(--danger); }
        .stat-card.collected { border-left-color: var(--success); }
        .stat-card.lost { border-left-color: var(--purple); }

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

        /* Data Cards */
        .data-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .card-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.1rem;
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
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-pending {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        .status-approved {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .status-paid {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .status-waived {
            background: rgba(108, 117, 125, 0.15);
            color: var(--gray);
        }

        .status-cancelled {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        .status-lost {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.3rem;
        }

        .action-btn {
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            text-decoration: none;
            color: white;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .action-btn.primary { background: var(--primary); }
        .action-btn.success { background: var(--success); }
        .action-btn.warning { background: var(--warning); }
        .action-btn.danger { background: var(--danger); }
        .action-btn.info { background: var(--info); }

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
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
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
        }

        .modal-close:hover {
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
            transition: var(--transition);
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

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

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .form-row {
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
                <h1><i class="fas fa-coins"></i> Library Fines Management</h1>
                <p>Manage overdue fines, lost books, and payments</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-light" onclick="openAddFineModal()">
                    <i class="fas fa-plus"></i> Add Fine
                </button>
                <button class="btn btn-light" onclick="openReportLostModal()">
                    <i class="fas fa-book"></i> Report Lost Book
                </button>
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
            <div class="stat-card pending stagger-item">
                <div class="stat-number"><?php echo $stats['pending_fines']; ?></div>
                <div class="stat-label">Pending Fines</div>
                <div class="stat-detail">KES <?php echo number_format($stats['pending_amount'], 2); ?></div>
            </div>
            <div class="stat-card lost stagger-item">
                <div class="stat-number"><?php echo $stats['pending_lost']; ?></div>
                <div class="stat-label">Lost Books</div>
                <div class="stat-detail">KES <?php echo number_format($stats['pending_lost_amount'], 2); ?></div>
            </div>
            <div class="stat-card collected stagger-item">
                <div class="stat-number">KES <?php echo number_format($stats['collected_amount'], 2); ?></div>
                <div class="stat-label">Total Collected</div>
                <div class="stat-detail"><?php echo $stats['paid_fines']; ?> payments</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs animate">
            <div class="tab active" onclick="switchTab('overdue')">Overdue Fines</div>
            <div class="tab" onclick="switchTab('lost')">Lost Books</div>
            <div class="tab" onclick="switchTab('approved')">Approved Fines</div>
            <div class="tab" onclick="switchTab('active_loans')">Active Loans</div>
        </div>

        <!-- Overdue Fines Tab -->
        <div id="overdueTab" class="tab-content active">
            <div class="data-card">
                <div class="card-header">
                    <h3><i class="fas fa-clock"></i> Pending Overdue Fines</h3>
                    <span class="badge"><?php echo count($pending_fines); ?> pending</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Fine Code</th>
                                <th>Student</th>
                                <th>Book</th>
                                <th>Days Overdue</th>
                                <th>Fine Amount</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($pending_fines)): ?>
                                <?php foreach ($pending_fines as $fine): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($fine['fine_code']); ?></strong></td>
                                    <td>
                                        <?php echo htmlspecialchars($fine['full_name']); ?>
                                        <br><small><?php echo $fine['admission_number']; ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($fine['book_title']); ?></td>
                                    <td><span class="status-badge status-pending"><?php echo $fine['days_overdue']; ?> days</span></td>
                                    <td><strong>KES <?php echo number_format($fine['fine_amount'], 2); ?></strong></td>
                                    <td><?php echo date('d M Y', strtotime($fine['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn primary" onclick="viewFineDetails(<?php echo $fine['id']; ?>)" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="action-btn warning" onclick="waiveFine(<?php echo $fine['id']; ?>)" title="Waive Fine">
                                                <i class="fas fa-hand-holding-heart"></i>
                                            </button>
                                            <button class="action-btn success" onclick="recordPayment(<?php echo $fine['id']; ?>, 'overdue', <?php echo $fine['final_amount']; ?>, '<?php echo addslashes($fine['full_name']); ?>')" title="Record Payment">
                                                <i class="fas fa-credit-card"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 2rem; color: var(--gray);">
                                        <i class="fas fa-check-circle fa-2x" style="color: var(--success); margin-bottom: 0.5rem;"></i>
                                        <p>No pending fines</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Lost Books Tab -->
        <div id="lostTab" class="tab-content">
            <div class="data-card">
                <div class="card-header">
                    <h3><i class="fas fa-book"></i> Pending Lost Books</h3>
                    <span class="badge"><?php echo count($lost_books); ?> pending</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Lost Code</th>
                                <th>Student</th>
                                <th>Book</th>
                                <th>Replacement Cost</th>
                                <th>Processing Fee</th>
                                <th>Total</th>
                                <th>Date Lost</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($lost_books)): ?>
                                <?php foreach ($lost_books as $lost): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($lost['lost_code']); ?></strong></td>
                                    <td>
                                        <?php echo htmlspecialchars($lost['full_name']); ?>
                                        <br><small><?php echo $lost['admission_number']; ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($lost['title']); ?></td>
                                    <td>KES <?php echo number_format($lost['replacement_cost'], 2); ?></td>
                                    <td>KES <?php echo number_format($lost['processing_fee'], 2); ?></td>
                                    <td><strong>KES <?php echo number_format($lost['total_amount'], 2); ?></strong></td>
                                    <td><?php echo date('d M Y', strtotime($lost['date_lost'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn primary" onclick="viewLostDetails(<?php echo $lost['id']; ?>)" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if (in_array($lost['status'], ['reported', 'rejected'], true)): ?>
                                            <button class="action-btn success" onclick="submitLostForApproval(<?php echo $lost['id']; ?>, '<?php echo addslashes($lost['lost_code']); ?>')" title="Submit for Approval">
                                                <i class="fas fa-paper-plane"></i>
                                            </button>
                                            <?php else: ?>
                                            <span class="status-badge status-<?php echo htmlspecialchars($lost['status']); ?>">
                                                <?php echo ucwords(str_replace('_', ' ', (string) $lost['status'])); ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 2rem; color: var(--gray);">
                                        <i class="fas fa-check-circle fa-2x" style="color: var(--success); margin-bottom: 0.5rem;"></i>
                                        <p>No lost books reported</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Approved Fines Tab -->
        <div id="approvedTab" class="tab-content">
            <div class="data-card">
                <div class="card-header">
                    <h3><i class="fas fa-check-circle"></i> Approved Fines</h3>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Fine Code</th>
                                <th>Student</th>
                                <th>Book</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Approved By</th>
                                <th>Approved At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($approved_fines)): ?>
                                <?php foreach ($approved_fines as $fine): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($fine['fine_code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($fine['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($fine['book_title']); ?></td>
                                    <td>KES <?php echo number_format($fine['final_amount'], 2); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $fine['status']; ?>">
                                            <?php echo ucfirst($fine['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($fine['approved_by_name']); ?></td>
                                    <td><?php echo date('d M Y', strtotime($fine['approved_at'])); ?></td>
                                    <td>
                                        <button class="action-btn primary" onclick="viewFineDetails(<?php echo $fine['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 2rem; color: var(--gray);">
                                        <i class="fas fa-info-circle fa-2x"></i>
                                        <p>No approved fines found</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Active Loans Tab -->
        <div id="activeLoansTab" class="tab-content">
            <div class="data-card">
                <div class="card-header">
                    <h3><i class="fas fa-book-open"></i> Active Book Loans</h3>
                    <button class="btn btn-sm btn-primary" onclick="refreshLoans()">
                        <i class="fas fa-sync-alt"></i> Check Overdue
                    </button>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Book</th>
                                <th>Borrowed Date</th>
                                <th>Due Date</th>
                                <th>Days Overdue</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($active_loans)): ?>
                                <?php foreach ($active_loans as $loan): 
                                    $days_overdue = $loan['days_overdue'] > 0 ? $loan['days_overdue'] : 0;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($loan['full_name']); ?></strong>
                                        <br><small><?php echo $loan['admission_number']; ?></small>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($loan['title']); ?>
                                        <br><small><?php echo $loan['isbn']; ?></small>
                                    </td>
                                    <td><?php echo date('d M Y', strtotime($loan['borrow_date'])); ?></td>
                                    <td><?php echo date('d M Y', strtotime($loan['due_date'])); ?></td>
                                    <td>
                                        <?php if ($days_overdue > 0): ?>
                                            <span class="status-badge status-pending"><?php echo $days_overdue; ?> days</span>
                                        <?php else: ?>
                                            <span class="status-badge status-approved">On time</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $loan['status']; ?>">
                                            <?php echo ucfirst($loan['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($days_overdue > 0): ?>
                                            <button class="action-btn warning" onclick="addFineFromLoan(<?php echo $loan['id']; ?>, <?php echo $days_overdue; ?>, '<?php echo addslashes($loan['full_name']); ?>', '<?php echo addslashes($loan['title']); ?>')">
                                                <i class="fas fa-coins"></i> Add Fine
                                            </button>
                                            <?php endif; ?>
                                            <button class="action-btn primary" onclick="viewLoanDetails(<?php echo $loan['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 2rem; color: var(--gray);">
                                        <i class="fas fa-book-open fa-2x" style="margin-bottom: 0.5rem;"></i>
                                        <p>No active loans</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Fine Modal -->
    <div id="addFineModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-coins"></i> Add Overdue Fine</h3>
                <button class="modal-close" onclick="closeModal('addFineModal')">&times;</button>
            </div>
            <form method="POST" id="addFineForm">
                <input type="hidden" name="add_fine" value="1">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Select Loan</label>
                        <select name="book_loan_id" id="loanSelect" class="form-control" required onchange="updateLoanDetails()">
                            <option value="">Select a loan</option>
                            <?php foreach ($active_loans as $loan): 
                                $days_overdue = max(0, $loan['days_overdue']);
                                if ($days_overdue > 0):
                            ?>
                            <option value="<?php echo $loan['id']; ?>" 
                                    data-student="<?php echo htmlspecialchars($loan['full_name']); ?>"
                                    data-book="<?php echo htmlspecialchars($loan['title']); ?>"
                                    data-days="<?php echo $days_overdue; ?>">
                                <?php echo htmlspecialchars($loan['full_name']); ?> - 
                                <?php echo htmlspecialchars($loan['title']); ?> 
                                (<?php echo $days_overdue; ?> days overdue)
                            </option>
                            <?php endif; endforeach; ?>
                        </select>
                    </div>

                    <div id="loanDetails" style="display: none; background: var(--light); padding: 1rem; border-radius: var(--border-radius-md); margin-bottom: 1rem;">
                        <p><strong>Student:</strong> <span id="loanStudent"></span></p>
                        <p><strong>Book:</strong> <span id="loanBook"></span></p>
                        <p><strong>Days Overdue:</strong> <span id="loanDays"></span></p>
                    </div>

                    <div class="form-group">
                        <label>Fine Type</label>
                        <select name="fine_type" id="fineType" class="form-control" required>
                            <option value="" disabled>Select fine type</option>
                            <option value="overdue" selected>Overdue Fine</option>
                            <option value="damage">Damage Fine</option>
                            <option value="processing">Processing Fee</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Fine per Day (KES)</label>
                            <input type="number" name="fine_per_day" class="form-control" value="20" min="0" step="5">
                        </div>
                        <div class="form-group">
                            <label>Days Overdue</label>
                            <input type="number" name="days_overdue" id="daysOverdue" class="form-control" readonly>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Additional Damage Fee (KES)</label>
                        <input type="number" name="damage_fee" class="form-control" value="0" min="0" step="50">
                    </div>

                    <div class="form-group">
                        <label>Reason/Notes</label>
                        <textarea name="reason" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addFineModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Fine</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Report Lost Book Modal -->
    <div id="reportLostModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-book"></i> Report Lost Book</h3>
                <button class="modal-close" onclick="closeModal('reportLostModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="report_lost_book" value="1">
                <input type="hidden" name="issue_id" id="lostIssueId">
                <input type="hidden" name="book_id" id="lostBookId">
                <input type="hidden" name="student_id" id="lostStudentId">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Active Issued Book</label>
                        <select id="lostLoanSelect" class="form-control" required onchange="loadLostLoanDetails(this)">
                            <option value="">Select an active issued book</option>
                            <?php foreach ($active_loans as $loan): ?>
                            <option value="<?php echo (int) $loan['id']; ?>"
                                    data-book-id="<?php echo (int) $loan['book_id']; ?>"
                                    data-student-id="<?php echo (int) $loan['student_id']; ?>"
                                    data-title="<?php echo htmlspecialchars($loan['title'], ENT_QUOTES); ?>"
                                    data-isbn="<?php echo htmlspecialchars($loan['isbn'] ?? '', ENT_QUOTES); ?>"
                                    data-price="<?php echo htmlspecialchars($loan['book_price'] ?? 0, ENT_QUOTES); ?>"
                                    data-student="<?php echo htmlspecialchars($loan['full_name'], ENT_QUOTES); ?>"
                                    data-admission="<?php echo htmlspecialchars($loan['admission_number'], ENT_QUOTES); ?>"
                                    data-issued="<?php echo htmlspecialchars($loan['borrow_date'] ?? $loan['issue_date'] ?? '', ENT_QUOTES); ?>"
                                    data-due="<?php echo htmlspecialchars($loan['due_date'] ?? '', ENT_QUOTES); ?>">
                                <?php echo htmlspecialchars($loan['title']); ?> - <?php echo htmlspecialchars($loan['full_name']); ?> (<?php echo htmlspecialchars($loan['admission_number']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="lostLoanDetails" class="student-info" style="display: none; background: var(--light); padding: 1rem; border-radius: var(--border-radius-md); margin-bottom: 1rem;">
                        <p><strong>Student:</strong> <span id="lostLoanStudent"></span></p>
                        <p><strong>Book:</strong> <span id="lostLoanBook"></span></p>
                        <p><strong>Issued:</strong> <span id="lostLoanIssued"></span></p>
                        <p><strong>Due Date:</strong> <span id="lostLoanDue"></span></p>
                    </div>

                    <input type="hidden" name="book_title" id="bookTitle">
                    <input type="hidden" name="isbn" id="bookIsbn">
                    <input type="hidden" name="book_price" id="bookPrice">

                    <div class="form-row">
                        <div class="form-group">
                            <label>Replacement Cost (KES)</label>
                            <input type="number" name="replacement_cost" id="replacementCost" class="form-control" required min="0" step="50">
                        </div>
                        <div class="form-group">
                            <label>Processing Fee (KES)</label>
                            <input type="number" name="processing_fee" class="form-control" value="200" min="0" step="50">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Date Lost</label>
                        <input type="date" name="date_lost" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Reason</label>
                        <textarea name="reason" class="form-control" rows="2" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('reportLostModal')">Cancel</button>
                    <button type="submit" class="btn btn-warning">Report Lost Book</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Payment Modal -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-credit-card"></i> Record Payment</h3>
                <button class="modal-close" onclick="closeModal('paymentModal')">&times;</button>
            </div>
            <form method="POST" id="paymentForm">
                <input type="hidden" name="record_payment" value="1">
                <input type="hidden" name="fine_type" id="paymentFineType">
                <input type="hidden" name="fine_id" id="paymentFineId">
                <input type="hidden" name="student_id" id="paymentStudentId">
                
                <div class="modal-body">
                    <div class="student-info" style="background: var(--light); padding: 1rem; border-radius: var(--border-radius-md); margin-bottom: 1rem;">
                        <p><strong>Student:</strong> <span id="paymentStudentName"></span></p>
                        <p><strong>Amount Due:</strong> KES <span id="paymentAmountDue"></span></p>
                    </div>

                    <div class="form-group">
                        <label>Amount (KES)</label>
                        <input type="number" name="amount" id="paymentAmount" class="form-control" required min="0" step="0.01">
                    </div>

                    <div class="form-group">
                        <label>Payment Method</label>
                        <select name="payment_method" class="form-control" required>
                            <option value="cash">Cash</option>
                            <option value="mpesa">M-Pesa</option>
                            <option value="card">Card</option>
                            <option value="bank">Bank Transfer</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Transaction ID (Optional)</label>
                        <input type="text" name="transaction_id" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('paymentModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Record Payment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Waive Fine Modal -->
    <div id="waiveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-hand-holding-heart"></i> Waive Fine</h3>
                <button class="modal-close" onclick="closeModal('waiveModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="waive_fine" value="1">
                <input type="hidden" name="fine_id" id="waiveFineId">
                <div class="modal-body">
                    <p>Are you sure you want to waive this fine?</p>
                    <div class="form-group">
                        <label>Reason for waiving</label>
                        <textarea name="waive_reason" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('waiveModal')">Cancel</button>
                    <button type="submit" class="btn btn-warning">Waive Fine</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            if (tabName === 'overdue') {
                document.querySelectorAll('.tab')[0].classList.add('active');
                document.getElementById('overdueTab').classList.add('active');
            } else if (tabName === 'lost') {
                document.querySelectorAll('.tab')[1].classList.add('active');
                document.getElementById('lostTab').classList.add('active');
            } else if (tabName === 'approved') {
                document.querySelectorAll('.tab')[2].classList.add('active');
                document.getElementById('approvedTab').classList.add('active');
            } else if (tabName === 'active_loans') {
                document.querySelectorAll('.tab')[3].classList.add('active');
                document.getElementById('activeLoansTab').classList.add('active');
            }
        }

        function openAddFineModal() {
            document.getElementById('addFineModal').classList.add('active');
        }

        function openReportLostModal() {
            document.getElementById('reportLostModal').classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        function updateLoanDetails() {
            const select = document.getElementById('loanSelect');
            const option = select.options[select.selectedIndex];
            
            if (option.value) {
                document.getElementById('loanStudent').textContent = option.dataset.student;
                document.getElementById('loanBook').textContent = option.dataset.book;
                document.getElementById('loanDays').textContent = option.dataset.days;
                document.getElementById('daysOverdue').value = option.dataset.days;
                document.getElementById('loanDetails').style.display = 'block';
            } else {
                document.getElementById('loanDetails').style.display = 'none';
            }
        }

        function loadLostLoanDetails(select) {
            const option = select.options[select.selectedIndex];
            const hasValue = !!option.value;

            document.getElementById('lostIssueId').value = hasValue ? option.value : '';
            document.getElementById('lostBookId').value = hasValue ? option.dataset.bookId : '';
            document.getElementById('lostStudentId').value = hasValue ? option.dataset.studentId : '';
            document.getElementById('bookTitle').value = hasValue ? option.dataset.title : '';
            document.getElementById('bookIsbn').value = hasValue ? (option.dataset.isbn || '') : '';
            document.getElementById('bookPrice').value = hasValue ? (option.dataset.price || 0) : '';
            document.getElementById('replacementCost').value = hasValue ? (option.dataset.price || 0) : '';

            const details = document.getElementById('lostLoanDetails');
            if (!hasValue) {
                details.style.display = 'none';
                document.getElementById('lostLoanStudent').textContent = '';
                document.getElementById('lostLoanBook').textContent = '';
                document.getElementById('lostLoanIssued').textContent = '';
                document.getElementById('lostLoanDue').textContent = '';
                return;
            }

            document.getElementById('lostLoanStudent').textContent = `${option.dataset.student} (${option.dataset.admission || 'N/A'})`;
            document.getElementById('lostLoanBook').textContent = option.dataset.title;
            document.getElementById('lostLoanIssued').textContent = option.dataset.issued || 'N/A';
            document.getElementById('lostLoanDue').textContent = option.dataset.due || 'N/A';
            details.style.display = 'block';
        }

        function addFineFromLoan(loanId, daysOverdue, studentName, bookTitle) {
            // First, set the loan select
            const loanSelect = document.getElementById('loanSelect');
            const fineType = document.getElementById('fineType');
            for (let i = 0; i < loanSelect.options.length; i++) {
                if (loanSelect.options[i].value == loanId) {
                    loanSelect.selectedIndex = i;
                    updateLoanDetails();
                    break;
                }
            }
            if (fineType) {
                fineType.value = 'overdue';
            }
            // Open the modal
            openAddFineModal();
        }

        function submitLostForApproval(lostId, lostCode) {
            Swal.fire({
                title: 'Submit for Approval?',
                text: `Send lost book report ${lostCode} to admin for approval?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Submit',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#4361ee'
            }).then((result) => {
                if (!result.isConfirmed) {
                    return;
                }

                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="submit_lost_for_approval" value="1">
                    <input type="hidden" name="lost_id" value="${lostId}">
                `;
                document.body.appendChild(form);
                form.submit();
            });
        }

        function recordPayment(fineId, fineType, amount, studentName) {
            document.getElementById('paymentFineId').value = fineId;
            document.getElementById('paymentFineType').value = fineType;
            document.getElementById('paymentStudentName').textContent = studentName;
            document.getElementById('paymentAmount').value = amount;
            document.getElementById('paymentAmountDue').textContent = parseFloat(amount).toFixed(2);
            
            // Extract student ID from the table row if needed, set to 0 for now
            document.getElementById('paymentStudentId').value = 0;
            
            document.getElementById('paymentModal').classList.add('active');
        }

        function waiveFine(fineId) {
            document.getElementById('waiveFineId').value = fineId;
            Swal.fire({
                title: 'Waive Fine?',
                text: 'Please provide a reason for waiving this fine.',
                icon: 'question',
                html: `
                    <textarea id="waiveReasonInput" class="form-control" rows="3" placeholder="Enter reason..."></textarea>
                `,
                showCancelButton: true,
                confirmButtonText: 'Waive',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#f8961e',
                didOpen: () => {
                    document.getElementById('waiveReasonInput').focus();
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const reason = document.getElementById('waiveReasonInput').value;
                    if (!reason.trim()) {
                        Swal.fire('Error', 'Please provide a reason', 'error');
                        return;
                    }
                    
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="waive_fine" value="1">
                        <input type="hidden" name="fine_id" value="${fineId}">
                        <input type="hidden" name="waive_reason" value="${reason.replace(/"/g, '&quot;')}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        function viewFineDetails(fineId) {
            Swal.fire({
                title: 'Fine Details',
                html: `<p>Fine ID: ${fineId}</p><p>Loading details...</p>`,
                icon: 'info'
            });
        }

        function viewLostDetails(lostId) {
            Swal.fire({
                title: 'Lost Book Details',
                html: `<p>Lost Book ID: ${lostId}</p><p>Loading details...</p>`,
                icon: 'info'
            });
        }

        function viewLoanDetails(loanId) {
            Swal.fire({
                title: 'Loan Details',
                html: `<p>Loan ID: ${loanId}</p><p>Loading details...</p>`,
                icon: 'info'
            });
        }

        function refreshLoans() {
            Swal.fire({
                title: 'Checking Overdue Loans',
                html: '<p>Refreshing loan data...</p>',
                icon: 'info',
                allowOutsideClick: false,
                didOpen: () => {
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                }
            });
        }

        // Close modal when clicking outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>

                    <div class="form-group">
                        <label>Reason</label>
                        <textarea name="reason" class="form-control" rows="2" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('reportLostModal')">Cancel</button>
                    <button type="submit" class="btn btn-warning">Report Lost Book</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Payment Modal -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-credit-card"></i> Record Payment</h3>
                <button class="modal-close" onclick="closeModal('paymentModal')">&times;</button>
            </div>
            <form method="POST" id="paymentForm">
                <input type="hidden" name="record_payment" value="1">
                <input type="hidden" name="fine_type" id="paymentFineType">
                <input type="hidden" name="fine_id" id="paymentFineId">
                <input type="hidden" name="student_id" id="paymentStudentId">
                
                <div class="modal-body">
                    <div class="student-info" style="background: var(--light); padding: 1rem; border-radius: var(--border-radius-md); margin-bottom: 1rem;">
                        <p><strong>Student:</strong> <span id="paymentStudentName"></span></p>
                        <p><strong>Amount Due:</strong> KES <span id="paymentAmountDue"></span></p>
                    </div>

                    <div class="form-group">
                        <label>Amount (KES)</label>
                        <input type="number" name="amount" id="paymentAmount" class="form-control" required min="0" step="0.01">
                    </div>

                    <div class="form-group">
                        <label>Payment Method</label>
                        <select name="payment_method" class="form-control" required>
                            <option value="cash">Cash</option>
                            <option value="mpesa">M-Pesa</option>
                            <option value="card">Card</option>
                            <option value="bank">Bank Transfer</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Transaction ID (Optional)</label>
                        <input type="text" name="transaction_id" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('paymentModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Record Payment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Waive Fine Modal -->
    <div id="waiveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-hand-holding-heart"></i> Waive Fine</h3>
                <button class="modal-close" onclick="closeModal('waiveModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="waive_fine" value="1">
                <input type="hidden" name="fine_id" id="waiveFineId">
                <div class="modal-body">
                    <p>Are you sure you want to waive this fine?</p>
                    <div class="form-group">
                        <label>Reason for waiving</label>
                        <textarea name="waive_reason" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('waiveModal')">Cancel</button>
                    <button type="submit" class="btn btn-warning">Waive Fine</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            if (tabName === 'overdue') {
                document.querySelectorAll('.tab')[0].classList.add('active');
                document.getElementById('overdueTab').classList.add('active');
            } else if (tabName === 'lost') {
                document.querySelectorAll('.tab')[1].classList.add('active');
                document.getElementById('lostTab').classList.add('active');
            } else if (tabName === 'approved') {
                document.querySelectorAll('.tab')[2].classList.add('active');
                document.getElementById('approvedTab').classList.add('active');
            } else if (tabName === 'active_loans') {
                document.querySelectorAll('.tab')[3].classList.add('active');
                document.getElementById('activeLoansTab').classList.add('active');
            }
        }

        function openAddFineModal() {
            document.getElementById('addFineModal').classList.add('active');
        }

        function openReportLostModal() {
            document.getElementById('reportLostModal').classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        function updateLoanDetails() {
            const select = document.getElementById('loanSelect');
            const option = select.options[select.selectedIndex];
            
            if (option.value) {
                document.getElementById('loanStudent').textContent = option.dataset.student;
                document.getElementById('loanBook').textContent = option.dataset.book;
                document.getElementById('loanDays').textContent = option.dataset.days;
                document.getElementById('daysOverdue').value = option.dataset.days;
                document.getElementById('loanDetails').style.display = 'block';
            } else {
                document.getElementById('loanDetails').style.display = 'none';
            }
        }

        function loadBookDetails(bookId) {
            if (!bookId) return;
            
            const select = document.querySelector('select[name="book_id"]');
            const option = select.options[select.selectedIndex];
            
            document.getElementById('bookTitle').value = option.dataset.title;
            document.getElementById('bookIsbn').value = option.dataset.isbn || '';
            document.getElementById('bookPrice').value = option.dataset.price;
            document.getElementById('replacementCost').value = option.dataset.price;
        }

        function addFineFromLoan(loanId, daysOverdue, studentName, bookTitle) {
            // First, set the loan select
            const loanSelect = document.getElementById('loanSelect');
            for (let i = 0; i < loanSelect.options.length; i++) {
                if (loanSelect.options[i].value == loanId) {
                    loanSelect.selectedIndex = i;
                    updateLoanDetails();
                    break;
           


                    <div class="form-group">
                        <label>Reason</label>
                        <textarea name="reason" class="form-control" rows="2" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('reportLostModal')">Cancel</button>
                    <button type="submit" class="btn btn-warning">Report Lost Book</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Payment Modal -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-credit-card"></i> Record Payment</h3>
                <button class="modal-close" onclick="closeModal('paymentModal')">&times;</button>
            </div>
            <form method="POST" id="paymentForm">
                <input type="hidden" name="record_payment" value="1">
                <input type="hidden" name="fine_type" id="paymentFineType">
                <input type="hidden" name="fine_id" id="paymentFineId">
                <input type="hidden" name="student_id" id="paymentStudentId">
                
                <div class="modal-body">
                    <div class="student-info" style="background: var(--light); padding: 1rem; border-radius: var(--border-radius-md); margin-bottom: 1rem;">
                        <p><strong>Student:</strong> <span id="paymentStudentName"></span></p>
                        <p><strong>Amount Due:</strong> KES <span id="paymentAmountDue"></span></p>
                    </div>

                    <div class="form-group">
                        <label>Amount (KES)</label>
                        <input type="number" name="amount" id="paymentAmount" class="form-control" required min="0" step="0.01">
                    </div>

                    <div class="form-group">
                        <label>Payment Method</label>
                        <select name="payment_method" class="form-control" required>
                            <option value="cash">Cash</option>
                            <option value="mpesa">M-Pesa</option>
                            <option value="card">Card</option>
                            <option value="bank">Bank Transfer</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Transaction ID (Optional)</label>
                        <input type="text" name="transaction_id" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('paymentModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Record Payment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Waive Fine Modal -->
    <div id="waiveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-hand-holding-heart"></i> Waive Fine</h3>
                <button class="modal-close" onclick="closeModal('waiveModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="waive_fine" value="1">
                <input type="hidden" name="fine_id" id="waiveFineId">
                <div class="modal-body">
                    <p>Are you sure you want to waive this fine?</p>
                    <div class="form-group">
                        <label>Reason for waiving</label>
                        <textarea name="waive_reason" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('waiveModal')">Cancel</button>
                    <button type="submit" class="btn btn-warning">Waive Fine</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"><\/script>
    <script>
        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            if (tabName === 'overdue') {
                document.querySelectorAll('.tab')[0].classList.add('active');
                document.getElementById('overdueTab').classList.add('active');
            } else if (tabName === 'lost') {
                document.querySelectorAll('.tab')[1].classList.add('active');
                document.getElementById('lostTab').classList.add('active');
            } else if (tabName === 'approved') {
                document.querySelectorAll('.tab')[2].classList.add('active');
                document.getElementById('approvedTab').classList.add('active');
            } else if (tabName === 'active_loans') {
                document.querySelectorAll('.tab')[3].classList.add('active');
                document.getElementById('activeLoansTab').classList.add('active');
            }
        }

        function openAddFineModal() {
            document.getElementById('addFineModal').classList.add('active');
        }

        function openReportLostModal() {
            document.getElementById('reportLostModal').classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        function updateLoanDetails() {
            const select = document.getElementById('loanSelect');
            const option = select.options[select.selectedIndex];
            
            if (option.value) {
                document.getElementById('loanStudent').textContent = option.dataset.student;
                document.getElementById('loanBook').textContent = option.dataset.book;
                document.getElementById('loanDays').textContent = option.dataset.days;
                document.getElementById('daysOverdue').value = option.dataset.days;
                document.getElementById('loanDetails').style.display = 'block';
            } else {
                document.getElementById('loanDetails').style.display = 'none';
            }
        }

        function loadBookDetails(bookId) {
            if (!bookId) return;
            
            const select = document.querySelector('select[name="book_id"]');
            const option = select.options[select.selectedIndex];
            
            document.getElementById('bookTitle').value = option.dataset.title;
            document.getElementById('bookIsbn').value = option.dataset.isbn || '';
            document.getElementById('bookPrice').value = option.dataset.price;
            document.getElementById('replacementCost').value = option.dataset.price;
        }

        function addFineFromLoan(loanId, daysOverdue, studentName, bookTitle) {
            // First, set the loan select
            const loanSelect = document.getElementById('loanSelect');
            for (let i = 0; i < loanSelect.options.length; i++) {
                if (loanSelect.options[i].value == loanId) {
                    loanSelect.selectedIndex = i;
                    updateLoanDetails();
                    break;
                }
            }
            // Open the modal
            openAddFineModal();
        }

        function recordPayment(fineId, fineType, amount, studentName) {
            document.getElementById('paymentFineId').value = fineId;
            document.getElementById('paymentFineType').value = fineType;
            document.getElementById('paymentStudentName').textContent = studentName;
            document.getElementById('paymentAmount').value = amount;
            document.getElementById('paymentAmountDue').textContent = parseFloat(amount).toFixed(2);
            
            // Extract student ID from the table row if needed, set to 0 for now
            document.getElementById('paymentStudentId').value = 0;
            
            document.getElementById('paymentModal').classList.add('active');
        }

        function waiveFine(fineId) {
            document.getElementById('waiveFineId').value = fineId;
            Swal.fire({
                title: 'Waive Fine?',
                text: 'Please provide a reason for waiving this fine.',
                icon: 'question',
                html: \
                    <textarea id="waiveReasonInput" class="form-control" rows="3" placeholder="Enter reason..."><\/textarea>
                \,
                showCancelButton: true,
                confirmButtonText: 'Waive',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#f8961e',
                didOpen: () => {
                    document.getElementById('waiveReasonInput').focus();
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const reason = document.getElementById('waiveReasonInput').value;
                    if (!reason.trim()) {
                        Swal.fire('Error', 'Please provide a reason', 'error');
                        return;
                    }
                    
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = \
                        <input type="hidden" name="waive_fine" value="1">
                        <input type="hidden" name="fine_id" value="\">
                        <input type="hidden" name="waive_reason" value="\">
                    \;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        function viewFineDetails(fineId) {
            Swal.fire({
                title: 'Fine Details',
                html: \<p>Fine ID: \</p><p>Loading details...</p>\,
                icon: 'info'
            });
        }

        function viewLostDetails(lostId) {
            Swal.fire({
                title: 'Lost Book Details',
                html: \<p>Lost Book ID: \</p><p>Loading details...</p>\,
                icon: 'info'
            });
        }

        function viewLoanDetails(loanId) {
            Swal.fire({
                title: 'Loan Details',
                html: \<p>Loan ID: \</p><p>Loading details...</p>\,
                icon: 'info'
            });
        }

        function refreshLoans() {
            Swal.fire({
                title: 'Checking Overdue Loans',
                html: '<p>Refreshing loan data...</p>',
                icon: 'info',
                allowOutsideClick: false,
                didOpen: () => {
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                }
            });
        }

        // Close modal when clicking outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>
