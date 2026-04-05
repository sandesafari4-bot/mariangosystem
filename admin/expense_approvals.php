<?php
include '../config.php';
checkAuth();
checkRole(['admin']);

$page_title = 'Expense Approvals - ' . SCHOOL_NAME;

function expenseApprovalColumnExists(PDO $pdo, string $column): bool {
    static $columns = null;

    if ($columns === null) {
        $columns = [];
        foreach ($pdo->query("SHOW COLUMNS FROM expenses")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[$row['Field']] = true;
        }
    }

    return isset($columns[$column]);
}

$hasApprovalRemarksColumn = expenseApprovalColumnExists($pdo, 'approval_remarks');
$hasRejectionReasonColumn = expenseApprovalColumnExists($pdo, 'rejection_reason');
$approvalDateColumn = expenseApprovalColumnExists($pdo, 'approved_at') ? 'approved_at' : (expenseApprovalColumnExists($pdo, 'approval_date') ? 'approval_date' : null);

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => ''];
    
    try {
        if (isset($_POST['approve_expense'])) {
            $expense_id = intval($_POST['expense_id']);
            $remarks = trim($_POST['remarks'] ?? '');
            
            $pdo->beginTransaction();
            
            // Get expense details for notification
            $stmt = $pdo->prepare("SELECT e.*, u.full_name as requester_name FROM expenses e 
                                   LEFT JOIN users u ON e.created_by = u.id WHERE e.id = ?");
            $stmt->execute([$expense_id]);
            $expense = $stmt->fetch();
            
            if (!$expense) {
                throw new Exception('Expense not found');
            }
            
            // Update expense status
            $updateParts = [
                "status = 'approved'",
                "approved_by = ?"
            ];
            $updateParams = [$_SESSION['user_id']];

            if ($approvalDateColumn) {
                $updateParts[] = "$approvalDateColumn = NOW()";
            }

            if ($hasApprovalRemarksColumn) {
                $updateParts[] = "approval_remarks = ?";
                $updateParams[] = $remarks;
            }

            if ($hasRejectionReasonColumn) {
                $updateParts[] = "rejection_reason = NULL";
            }

            $updateParams[] = $expense_id;
            $stmt = $pdo->prepare("UPDATE expenses SET " . implode(', ', $updateParts) . " WHERE id = ?");
            $stmt->execute($updateParams);
            
            // Create notification for requester
            if (!empty($expense['created_by'])) {
                $notification = "Your expense request for KES " . number_format($expense['amount'], 2) .
                               " has been approved.\nRemarks: " . ($remarks ?: 'No remarks');
                createNotification(
                    'Expense Approved',
                    $notification,
                    'approval',
                    (int) $expense['created_by'],
                    null,
                    'high',
                    null,
                    null,
                    $expense_id,
                    'expense'
                );
            }
            
            $pdo->commit();
            
            $_SESSION['success'] = 'Expense approved successfully!';
            $response = ['success' => true, 'message' => $_SESSION['success']];
            
        } elseif (isset($_POST['reject_expense'])) {
            $expense_id = intval($_POST['expense_id']);
            $reason = trim($_POST['reason'] ?? '');
            
            if (empty($reason)) {
                throw new Exception('Please provide a reason for rejection');
            }
            
            $pdo->beginTransaction();
            
            // Get expense details for notification
            $stmt = $pdo->prepare("SELECT e.*, u.full_name as requester_name FROM expenses e 
                                   LEFT JOIN users u ON e.created_by = u.id WHERE e.id = ?");
            $stmt->execute([$expense_id]);
            $expense = $stmt->fetch();
            
            if (!$expense) {
                throw new Exception('Expense not found');
            }
            
            // Update expense status
            $updateParts = [
                "status = 'rejected'",
                "approved_by = ?"
            ];
            $updateParams = [$_SESSION['user_id']];

            if ($approvalDateColumn) {
                $updateParts[] = "$approvalDateColumn = NOW()";
            }

            if ($hasRejectionReasonColumn) {
                $updateParts[] = "rejection_reason = ?";
                $updateParams[] = $reason;
            }

            if ($hasApprovalRemarksColumn) {
                $updateParts[] = "approval_remarks = NULL";
            }

            $updateParams[] = $expense_id;
            $stmt = $pdo->prepare("UPDATE expenses SET " . implode(', ', $updateParts) . " WHERE id = ?");
            $stmt->execute($updateParams);
            
            // Create notification for requester
            if (!empty($expense['created_by'])) {
                $notification = "Your expense request for KES " . number_format($expense['amount'], 2) .
                               " has been rejected.\nReason: " . $reason;
                createNotification(
                    'Expense Rejected',
                    $notification,
                    'approval',
                    (int) $expense['created_by'],
                    null,
                    'high',
                    null,
                    null,
                    $expense_id,
                    'expense'
                );
            }
            
            $pdo->commit();
            
            $_SESSION['success'] = 'Expense rejected successfully!';
            $response = ['success' => true, 'message' => $_SESSION['success']];
            
        } elseif (isset($_POST['bulk_action'])) {
            $action = $_POST['bulk_action'];
            $selected_ids = $_POST['selected_ids'] ?? [];

            if (is_string($selected_ids)) {
                $decoded = json_decode($selected_ids, true);
                if (is_array($decoded)) {
                    $selected_ids = $decoded;
                } else {
                    $selected_ids = [$selected_ids];
                }
            }

            $selected_ids = array_values(array_filter(array_map('intval', (array) $selected_ids)));
            
            if (empty($selected_ids)) {
                throw new Exception('No expenses selected');
            }
            
            $pdo->beginTransaction();
            
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            if ($action === 'approve') {
                $stmt = $pdo->prepare("
                    UPDATE expenses 
                    SET status = 'approved', 
                        approved_by = ?, 
                        approved_at = NOW() 
                    WHERE id IN ($placeholders)
                ");
                $params = array_merge([$_SESSION['user_id']], $selected_ids);
                $stmt->execute($params);
                
                $_SESSION['success'] = count($selected_ids) . ' expenses approved successfully!';
                $response = ['success' => true, 'message' => $_SESSION['success']];
                
            } elseif ($action === 'reject') {
                $stmt = $pdo->prepare("
                    UPDATE expenses 
                    SET status = 'rejected', 
                        approved_by = ?, 
                        approved_at = NOW() 
                    WHERE id IN ($placeholders)
                ");
                $params = array_merge([$_SESSION['user_id']], $selected_ids);
                $stmt->execute($params);
                
                $_SESSION['success'] = count($selected_ids) . ' expenses rejected successfully!';
                $response = ['success' => true, 'message' => $_SESSION['success']];
            } else {
                throw new Exception('Invalid bulk action selected');
            }
            
            $pdo->commit();
        }
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
        $response = ['success' => false, 'message' => $_SESSION['error']];
    }
    
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    header("Location: expense_approvals.php");
    exit();
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'pending';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$category_filter = $_GET['category'] ?? '';

// Get pending expenses with details
$pending_query = "
    SELECT e.*, 
           COALESCE(ec.name, CONCAT('Category #', e.category_id)) as category,
           u.full_name as requester_name,
           u.email as requester_email,
           u.role as requester_role
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    LEFT JOIN users u ON e.created_by = u.id
    WHERE e.status = 'pending'
    ORDER BY e.created_at ASC
";
$pending_expenses = $pdo->query($pending_query)->fetchAll();

// Get approved/rejected expenses history
$history_query = "
    SELECT e.*, 
           COALESCE(ec.name, CONCAT('Category #', e.category_id)) as category,
           u.full_name as requester_name,
           a.full_name as approver_name
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    LEFT JOIN users u ON e.created_by = u.id
    LEFT JOIN users a ON e.approved_by = a.id
    WHERE e.status IN ('approved', 'rejected')
";

$params = [];

if ($status_filter && $status_filter !== 'pending') {
    $history_query .= " AND e.status = ?";
    $params[] = $status_filter;
}

if ($date_from) {
    $history_query .= " AND DATE(e.approved_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $history_query .= " AND DATE(e.approved_at) <= ?";
    $params[] = $date_to;
}

if ($category_filter) {
    $history_query .= " AND ec.name = ?";
    $params[] = $category_filter;
}

$history_query .= " ORDER BY e.approved_at DESC LIMIT 50";

$stmt = $pdo->prepare($history_query);
$stmt->execute($params);
$history_expenses = $stmt->fetchAll();

// Get categories for filter
$categories = $pdo->query("
    SELECT DISTINCT ec.name
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    WHERE ec.name IS NOT NULL AND ec.name <> ''
    ORDER BY ec.name
")->fetchAll(PDO::FETCH_COLUMN);

// Get statistics
$stats = $pdo->query("
    SELECT 
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_amount,
        COUNT(CASE WHEN status = 'approved' AND approved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as approved_30d,
        COALESCE(SUM(CASE WHEN status = 'approved' AND approved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN amount ELSE 0 END), 0) as approved_amount_30d,
        COUNT(CASE WHEN status = 'rejected' AND approved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as rejected_30d,
        AVG(CASE WHEN status = 'approved' THEN amount ELSE NULL END) as avg_approved,
        MAX(CASE WHEN status = 'approved' THEN amount ELSE 0 END) as max_approved
    FROM expenses
")->fetch();

// Get approver performance
$approver_stats = $pdo->query("
    SELECT 
        a.full_name as approver_name,
        COUNT(*) as total_processed,
        SUM(CASE WHEN e.status = 'approved' THEN 1 ELSE 0 END) as approved_count,
        SUM(CASE WHEN e.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
        COALESCE(SUM(CASE WHEN e.status = 'approved' THEN e.amount ELSE 0 END), 0) as total_approved_amount
    FROM expenses e
    JOIN users a ON e.approved_by = a.id
    WHERE e.approved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY a.id, a.full_name
    ORDER BY total_processed DESC
    LIMIT 5
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

        .btn-group {
            display: flex;
            gap: 0.5rem;
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
            position: relative;
            overflow: hidden;
            border-left: 4px solid;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card.pending { border-left-color: var(--warning); }
        .stat-card.approved { border-left-color: var(--success); }
        .stat-card.rejected { border-left-color: var(--danger); }
        .stat-card.amount { border-left-color: var(--primary); }

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

        /* Alert Banner */
        .alert-banner {
            background: linear-gradient(135deg, var(--warning), #e07c1a);
            border-radius: var(--border-radius-lg);
            padding: 1rem 2rem;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-lg);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }

        .alert-banner .btn {
            background: white;
            color: var(--warning);
            border: none;
        }

        .alert-banner .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Pending Items Section */
        .pending-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
        }

        .section-header {
            padding: 1.5rem;
            background: var(--gradient-1);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-header h2 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.3rem;
            font-weight: 600;
        }

        .bulk-actions {
            display: flex;
            gap: 0.5rem;
        }

        .bulk-actions select {
            padding: 0.5rem;
            border: none;
            border-radius: var(--border-radius-sm);
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .bulk-actions select option {
            background: var(--white);
            color: var(--dark);
        }

        /* Expense Cards Grid */
        .expenses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            padding: 1.5rem;
        }

        .expense-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            transition: var(--transition);
            border: 1px solid var(--light);
            position: relative;
        }

        .expense-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
            border-color: var(--primary);
        }

        .expense-header {
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 2px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .expense-category {
            font-weight: 700;
            color: var(--primary);
            background: rgba(67, 97, 238, 0.1);
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
        }

        .expense-amount {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--danger);
        }

        .expense-body {
            padding: 1.5rem;
        }

        .expense-description {
            color: var(--dark);
            margin-bottom: 1rem;
            line-height: 1.6;
        }

        .expense-meta {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin: 1rem 0;
            padding: 1rem;
            background: var(--light);
            border-radius: var(--border-radius-md);
        }

        .meta-item {
            text-align: center;
        }

        .meta-label {
            font-size: 0.75rem;
            color: var(--gray);
            text-transform: uppercase;
            margin-bottom: 0.25rem;
        }

        .meta-value {
            font-weight: 600;
            color: var(--dark);
        }

        .expense-footer {
            padding: 1rem 1.5rem;
            background: var(--light);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .requester-info {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .requester-info strong {
            color: var(--dark);
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .checkbox-wrapper {
            padding: 1rem 1.5rem 0 1.5rem;
        }

        /* History Table */
        .history-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }

        .history-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .filter-grid input,
        .filter-grid select {
            padding: 0.5rem;
            border: none;
            border-radius: var(--border-radius-sm);
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .filter-grid input::placeholder {
            color: rgba(255,255,255,0.7);
        }

        .filter-grid option {
            background: var(--white);
            color: var(--dark);
        }

        .filter-btn {
            background: white;
            color: var(--primary);
            border: none;
            padding: 0.5rem;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
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
            font-size: 0.85rem;
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

        .status-badge {
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-approved {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .status-rejected {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        .status-pending {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        /* Approver Stats */
        .approver-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .approver-card {
            background: var(--light);
            border-radius: var(--border-radius-md);
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .approver-info {
            flex: 1;
        }

        .approver-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .approver-stats-detail {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .approver-badge {
            background: var(--success);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .expenses-grid {
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
                text-align: center;
                gap: 1rem;
            }
            
            .header-actions {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .bulk-actions {
                flex-direction: column;
            }
            
            .action-buttons {
                flex-direction: column;
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
                <h1><i class="fas fa-check-double"></i> Expense Approvals</h1>
                <p>Review and approve expense requests</p>
            </div>
            <div class="header-actions">
                <a href="dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
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
            <div class="stat-card pending stagger-item">
                <div class="stat-number"><?php echo $stats['pending_count']; ?></div>
                <div class="stat-label">Pending Approval</div>
                <div class="stat-detail">KES <?php echo number_format($stats['pending_amount'], 2); ?></div>
            </div>
            
            <div class="stat-card approved stagger-item">
                <div class="stat-number"><?php echo $stats['approved_30d']; ?></div>
                <div class="stat-label">Approved (30d)</div>
                <div class="stat-detail">KES <?php echo number_format($stats['approved_amount_30d'], 2); ?></div>
            </div>
            
            <div class="stat-card rejected stagger-item">
                <div class="stat-number"><?php echo $stats['rejected_30d']; ?></div>
                <div class="stat-label">Rejected (30d)</div>
            </div>
            
            <div class="stat-card amount stagger-item">
                <div class="stat-number">KES <?php echo number_format($stats['max_approved'], 0); ?></div>
                <div class="stat-label">Largest Approval</div>
                <div class="stat-detail">Avg: KES <?php echo number_format($stats['avg_approved'], 0); ?></div>
            </div>
        </div>

        <!-- Alert if pending items -->
        <?php if (count($pending_expenses) > 0): ?>
        <div class="alert-banner animate">
            <div>
                <i class="fas fa-clock"></i>
                <strong><?php echo count($pending_expenses); ?> expense requests</strong> waiting for your review totaling KES <?php echo number_format($stats['pending_amount'], 2); ?>
            </div>
            <button class="btn btn-sm" onclick="document.getElementById('pending-section').scrollIntoView({behavior: 'smooth'})">
                Review Now <i class="fas fa-arrow-down"></i>
            </button>
        </div>
        <?php endif; ?>

        <!-- Pending Approvals Section -->
        <div id="pending-section" class="pending-section animate">
            <div class="section-header">
                <h2><i class="fas fa-clock"></i> Pending Approval (<?php echo count($pending_expenses); ?>)</h2>
                <?php if (count($pending_expenses) > 0): ?>
                <div class="bulk-actions">
                    <select id="bulkAction">
                        <option value="">Bulk Actions</option>
                        <option value="approve">Approve Selected</option>
                        <option value="reject">Reject Selected</option>
                    </select>
                    <button class="btn btn-sm" onclick="executeBulkAction()">Apply</button>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($pending_expenses)): ?>
            <div class="expenses-grid">
                <?php foreach ($pending_expenses as $expense): ?>
                <div class="expense-card" id="expense-<?php echo $expense['id']; ?>">
                    <div class="checkbox-wrapper">
                        <input type="checkbox" class="expense-checkbox" value="<?php echo $expense['id']; ?>" 
                               onchange="updateSelection()">
                    </div>
                    
                    <div class="expense-header">
                        <span class="expense-category">
                            <i class="fas fa-tag"></i> <?php echo htmlspecialchars($expense['category']); ?>
                        </span>
                        <span class="expense-amount">KES <?php echo number_format($expense['amount'], 2); ?></span>
                    </div>
                    
                    <div class="expense-body">
                        <div class="expense-description">
                            <?php echo nl2br(htmlspecialchars($expense['description'])); ?>
                        </div>
                        
                        <div class="expense-meta">
                            <div class="meta-item">
                                <div class="meta-label">Date</div>
                                <div class="meta-value"><?php echo date('d M Y', strtotime($expense['expense_date'])); ?></div>
                            </div>
                            <div class="meta-item">
                                <div class="meta-label">Payment Method</div>
                                <div class="meta-value"><?php echo ucfirst($expense['payment_method'] ?? 'N/A'); ?></div>
                            </div>
                            <?php if (!empty($expense['vendor'])): ?>
                            <div class="meta-item">
                                <div class="meta-label">Vendor</div>
                                <div class="meta-value"><?php echo htmlspecialchars($expense['vendor']); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($expense['reference_no'])): ?>
                            <div class="meta-item">
                                <div class="meta-label">Reference</div>
                                <div class="meta-value"><?php echo htmlspecialchars($expense['reference_no']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="requester-info">
                            Requested by: <strong><?php echo htmlspecialchars($expense['requester_name'] ?? 'Unknown'); ?></strong>
                            <div style="font-size: 0.75rem;"><?php echo $expense['requester_role'] ?? ''; ?></div>
                        </div>
                    </div>
                    
                    <div class="expense-footer">
                        <div class="requester-info">
                            <i class="far fa-clock"></i> <?php echo date('d M H:i', strtotime($expense['created_at'])); ?>
                        </div>
                        <div class="action-buttons">
                            <button class="btn btn-sm btn-success" onclick="approveExpense(<?php echo $expense['id']; ?>)">
                                <i class="fas fa-check"></i> Approve
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="rejectExpense(<?php echo $expense['id']; ?>)">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="text-align: center; padding: 4rem;">
                <i class="fas fa-check-circle fa-4x" style="color: var(--success); margin-bottom: 1rem;"></i>
                <h3 style="color: var(--dark);">All Caught Up!</h3>
                <p style="color: var(--gray);">No pending expenses to review.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Approval History Section -->
        <div class="history-section animate">
            <div class="history-header">
                <h2 style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem;">
                    <i class="fas fa-history"></i> Approval History
                </h2>
                
                <form method="GET" class="filter-grid">
                    <input type="hidden" name="tab" value="history">
                    <select name="status">
                        <option value="">All Status</option>
                        <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                    <input type="date" name="date_from" value="<?php echo $date_from; ?>">
                    <input type="date" name="date_to" value="<?php echo $date_to; ?>">
                    <select name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat; ?>" <?php echo $category_filter == $cat ? 'selected' : ''; ?>>
                            <?php echo $cat; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="filter-btn">Apply Filters</button>
                </form>

                <!-- Approver Performance -->
                <?php if (!empty($approver_stats)): ?>
                <div style="margin-top: 1.5rem;">
                    <h3 style="color: white; margin-bottom: 1rem;">Top Approvers (30 days)</h3>
                    <div class="approver-stats">
                        <?php foreach ($approver_stats as $approver): ?>
                        <div class="approver-card">
                            <div class="approver-info">
                                <div class="approver-name"><?php echo htmlspecialchars($approver['approver_name']); ?></div>
                                <div class="approver-stats-detail">
                                    <?php echo $approver['approved_count']; ?> approved / 
                                    <?php echo $approver['rejected_count']; ?> rejected
                                </div>
                            </div>
                            <div class="approver-badge">
                                KES <?php echo number_format($approver['total_approved_amount'], 0); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Requester</th>
                            <th>Status</th>
                            <th>Approver</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($history_expenses)): ?>
                            <?php foreach ($history_expenses as $expense): ?>
                            <tr>
                                <td><?php echo date('d M Y', strtotime($expense['approved_at'] ?? $expense['created_at'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($expense['category']); ?></strong></td>
                                <td><?php echo htmlspecialchars(substr($expense['description'], 0, 50)) . '...'; ?></td>
                                <td><strong style="color: <?php echo $expense['status'] == 'approved' ? 'var(--success)' : 'var(--danger)'; ?>;">
                                    KES <?php echo number_format($expense['amount'], 2); ?>
                                </strong></td>
                                <td><?php echo htmlspecialchars($expense['requester_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $expense['status']; ?>">
                                        <?php echo ucfirst($expense['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($expense['approver_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php if ($expense['status'] == 'rejected' && !empty($expense['rejection_reason'])): ?>
                                    <i class="fas fa-comment" title="<?php echo htmlspecialchars($expense['rejection_reason']); ?>"></i>
                                    <?php elseif (!empty($expense['approval_remarks'])): ?>
                                    <i class="fas fa-sticky-note" title="<?php echo htmlspecialchars($expense['approval_remarks']); ?>"></i>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 3rem;">
                                    <i class="fas fa-history fa-3x" style="color: var(--gray); margin-bottom: 1rem;"></i>
                                    <h4>No Approval History Found</h4>
                                    <p style="color: var(--gray);">No expenses match your filters.</p>
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
        let selectedExpenses = [];

        function updateSelection() {
            selectedExpenses = [];
            document.querySelectorAll('.expense-checkbox:checked').forEach(cb => {
                selectedExpenses.push(cb.value);
            });
        }

        function executeBulkAction() {
            const action = document.getElementById('bulkAction').value;
            
            if (!action) {
                Swal.fire('Error', 'Please select an action', 'error');
                return;
            }
            
            if (selectedExpenses.length === 0) {
                Swal.fire('Error', 'Please select at least one expense', 'error');
                return;
            }
            
            let title = action === 'approve' ? 'Approve Selected?' : 'Reject Selected?';
            let text = `This will ${action} ${selectedExpenses.length} expense(s).`;
            
            Swal.fire({
                title: title,
                text: text,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: action === 'approve' ? '#4cc9f0' : '#f94144',
                confirmButtonText: action === 'approve' ? 'Yes, approve' : 'Yes, reject'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('bulk_action', action);
                    selectedExpenses.forEach(id => formData.append('selected_ids[]', id));
                    formData.append('ajax', '1');
                    
                    fetch('expense_approvals.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.message || 'Failed to process selected expenses');
                        }
                        Swal.fire('Success!', data.message || `${selectedExpenses.length} expenses ${action}d`, 'success')
                            .then(() => location.reload());
                    })
                    .catch(error => {
                        Swal.fire('Error', error.message || 'Failed to process selected expenses', 'error');
                    });
                }
            });
        }

        function approveExpense(expenseId) {
            Swal.fire({
                title: 'Approve Expense',
                input: 'textarea',
                inputLabel: 'Remarks (optional)',
                inputPlaceholder: 'Add any approval remarks...',
                showCancelButton: true,
                confirmButtonColor: '#4cc9f0',
                confirmButtonText: 'Approve',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('approve_expense', '1');
                    formData.append('expense_id', expenseId);
                    formData.append('remarks', result.value || '');
                    formData.append('ajax', '1');
                    
                    Swal.fire({
                        title: 'Processing...',
                        allowOutsideClick: false,
                        didOpen: () => Swal.showLoading()
                    });
                    
                    fetch('expense_approvals.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.message || 'Failed to approve expense');
                        }
                        Swal.fire({
                            icon: 'success',
                            title: 'Approved!',
                            text: data.message || 'Expense has been approved',
                            timer: 1500
                        }).then(() => location.reload());
                    })
                    .catch(error => {
                        Swal.fire('Error', error.message || 'Failed to approve expense', 'error');
                    });
                }
            });
        }

        function rejectExpense(expenseId) {
            Swal.fire({
                title: 'Reject Expense',
                input: 'textarea',
                inputLabel: 'Reason for rejection',
                inputPlaceholder: 'Please provide a reason...',
                inputAttributes: {
                    required: true
                },
                showCancelButton: true,
                confirmButtonColor: '#f94144',
                confirmButtonText: 'Reject',
                cancelButtonText: 'Cancel',
                preConfirm: (reason) => {
                    if (!reason) {
                        Swal.showValidationMessage('Please provide a reason');
                        return false;
                    }
                    return reason;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('reject_expense', '1');
                    formData.append('expense_id', expenseId);
                    formData.append('reason', result.value);
                    formData.append('ajax', '1');
                    
                    Swal.fire({
                        title: 'Processing...',
                        allowOutsideClick: false,
                        didOpen: () => Swal.showLoading()
                    });
                    
                    fetch('expense_approvals.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.message || 'Failed to reject expense');
                        }
                        Swal.fire({
                            icon: 'success',
                            title: 'Rejected!',
                            text: data.message || 'Expense has been rejected',
                            timer: 1500
                        }).then(() => location.reload());
                    })
                    .catch(error => {
                        Swal.fire('Error', error.message || 'Failed to reject expense', 'error');
                    });
                }
            });
        }
    </script>
</body>
</html>
