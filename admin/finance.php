<?php
include '../config.php';
checkAuth();
checkRole(['admin']);

// AJAX endpoints
if (isset($_GET['ajax']) && $_GET['ajax'] === 'details' && isset($_GET['type'])) {
    $type = $_GET['type'];
    $id = (int)$_GET['id'];
    
    if ($type === 'fee') {
        $stmt = $pdo->prepare("SELECT * FROM fees WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($type === 'expense') {
        $stmt = $pdo->prepare("SELECT e.*, u.name as approved_by_name FROM expenses e LEFT JOIN users u ON e.approved_by = u.id WHERE e.id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if ($data) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'data' => $data]);
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Record not found']);
    }
    exit();
}

// AJAX: Save fee
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_fee') {
    $id = (int)($_POST['id'] ?? 0);
    $fee_name = trim($_POST['fee_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $academic_year = trim($_POST['academic_year'] ?? '');
    $due_date = trim($_POST['due_date'] ?? '') ?: null;
    $fee_type = trim($_POST['fee_type'] ?? 'general');
    $class_id = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
    $is_published = isset($_POST['is_published']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($fee_name === '' || $amount <= 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Invalid input: Fee name and amount are required']);
        exit();
    }

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE fees SET fee_name = ?, description = ?, amount = ?, academic_year = ?, due_date = ?, fee_type = ?, class_id = ?, is_published = ?, is_active = ? WHERE id = ?");
            $ok = $stmt->execute([$fee_name, $description, $amount, $academic_year, $due_date, $fee_type, $class_id, $is_published, $is_active, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO fees (fee_name, description, amount, academic_year, due_date, fee_type, class_id, is_published, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $ok = $stmt->execute([$fee_name, $description, $amount, $academic_year, $due_date, $fee_type, $class_id, $is_published, $is_active]);
        }
        
        header('Content-Type: application/json; charset=utf-8');
        if ($ok) {
            echo json_encode(['success' => true, 'message' => 'Fee saved successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save fee']);
        }
    } catch (Exception $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// AJAX: Assign fee to student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'assign_student_fee') {
    $student_id = (int)($_POST['student_id'] ?? 0);
    $fee_id = (int)($_POST['fee_id'] ?? 0);
    $custom_amount = !empty($_POST['custom_amount']) ? (float)$_POST['custom_amount'] : null;
    $notes = trim($_POST['notes'] ?? '');
    
    if ($student_id <= 0 || $fee_id <= 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Invalid student or fee selection']);
        exit();
    }
    
    try {
        // Get fee details
        $stmt = $pdo->prepare("SELECT amount FROM fees WHERE id = ?");
        $stmt->execute([$fee_id]);
        $fee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$fee) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Fee not found']);
            exit();
        }
        
        // Check if already assigned
        $check = $pdo->prepare("SELECT id FROM student_fees WHERE student_id = ? AND fee_id = ?");
        $check->execute([$student_id, $fee_id]);
        
        if ($check->fetch()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Fee already assigned to this student']);
            exit();
        }
        
        // Assign fee to student
        $stmt = $pdo->prepare("INSERT INTO student_fees (student_id, fee_id, amount, custom_amount, notes, assigned_by, assigned_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $amount = $custom_amount ?? $fee['amount'];
        $ok = $stmt->execute([$student_id, $fee_id, $fee['amount'], $custom_amount, $notes, $_SESSION['user_id']]);
        
        header('Content-Type: application/json; charset=utf-8');
        if ($ok) {
            echo json_encode(['success' => true, 'message' => 'Fee assigned to student successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to assign fee']);
        }
    } catch (Exception $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// AJAX: Save expense
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_expense') {
    $id = (int)($_POST['id'] ?? 0);
    $expense_type = trim($_POST['expense_type'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $expense_date = trim($_POST['expense_date'] ?? '');
    $paid_to = trim($_POST['paid_to'] ?? '');
    $payment_method = trim($_POST['payment_method'] ?? 'cash');
    $reference_no = trim($_POST['reference_no'] ?? '');
    $status = 'approved';

    if ($expense_type === '' || $amount <= 0 || $expense_date === '') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Invalid input: Expense type, amount and date are required']);
        exit();
    }

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE expenses SET expense_type = ?, description = ?, amount = ?, expense_date = ?, paid_to = ?, payment_method = ?, reference_no = ? WHERE id = ?");
            $ok = $stmt->execute([$expense_type, $description, $amount, $expense_date, $paid_to, $payment_method, $reference_no, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO expenses (expense_type, description, amount, expense_date, paid_to, payment_method, reference_no, approved_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $ok = $stmt->execute([$expense_type, $description, $amount, $expense_date, $paid_to, $payment_method, $reference_no, $_SESSION['user_id'], $status]);
        }
        
        header('Content-Type: application/json; charset=utf-8');
        if ($ok) {
            echo json_encode(['success' => true, 'message' => 'Expense saved successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save expense']);
        }
    } catch (Exception $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Delete fee
    if (isset($_POST['delete_fee'])) {
        $fee_id = (int)$_POST['fee_id'];
        
        // Check if fee is in use
        $check = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE fee_id = ?");
        $check->execute([$fee_id]);
        $in_use = $check->fetchColumn();
        
        if ($in_use > 0) {
            $error = "Cannot delete fee: It is being used by payments!";
        } else {
            $stmt = $pdo->prepare("DELETE FROM fees WHERE id = ?");
            if ($stmt->execute([$fee_id])) {
                $success = "Fee deleted successfully!";
            } else {
                $error = "Failed to delete fee.";
            }
        }
    }
    
    // Delete student fee assignment
    if (isset($_POST['delete_student_fee'])) {
        $assignment_id = (int)$_POST['assignment_id'];
        
        $stmt = $pdo->prepare("DELETE FROM student_fees WHERE id = ?");
        if ($stmt->execute([$assignment_id])) {
            $success = "Student fee assignment removed successfully!";
        } else {
            $error = "Failed to remove student fee assignment.";
        }
    }
    
    // Delete expense
    if (isset($_POST['delete_expense'])) {
        $expense_id = (int)$_POST['expense_id'];
        
        $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ?");
        if ($stmt->execute([$expense_id])) {
            $success = "Expense deleted successfully!";
        } else {
            $error = "Failed to delete expense.";
        }
    }
    
    // Update expense status
    if (isset($_POST['update_expense_status'])) {
        $expense_id = (int)$_POST['expense_id'];
        $status = $_POST['status'];
        
        $stmt = $pdo->prepare("UPDATE expenses SET status = ? WHERE id = ?");
        if ($stmt->execute([$status, $expense_id])) {
            $success = "Expense status updated successfully!";
        } else {
            $error = "Failed to update expense status.";
        }
    }
    
    // Publish/Unpublish fee
    if (isset($_POST['toggle_publish_fee'])) {
        $fee_id = (int)$_POST['fee_id'];
        $action = $_POST['publish_action'];
        
        $stmt = $pdo->prepare("UPDATE fees SET is_published = ? WHERE id = ?");
        if ($stmt->execute([$action === 'publish' ? 1 : 0, $fee_id])) {
            $success = "Fee " . ($action === 'publish' ? 'published' : 'unpublished') . " successfully!";
        } else {
            $error = "Failed to update fee status.";
        }
    }
    
    // Refresh data after form submission
    header("Location: finance.php?" . (isset($success) ? "success=" . urlencode($success) : "error=" . urlencode($error)));
    exit();
}

// Get classes for dropdown
$classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_order")->fetchAll();

// Get students for fee assignment
$students = $pdo->query("SELECT id, full_name, admission_number, class_id FROM students WHERE status = 'active' ORDER BY full_name")->fetchAll();

// Get filter parameters
$selected_type = $_GET['type'] ?? '';
$selected_status = $_GET['status'] ?? '';
$search_query = $_GET['search'] ?? '';

// Get statistics
$total_fees = $pdo->query("SELECT SUM(amount) FROM fees WHERE is_active = 1 AND is_published = 1")->fetchColumn() ?? 0;
$total_payments = $pdo->query("SELECT SUM(amount) FROM payments WHERE status = 'paid'")->fetchColumn() ?? 0;
$total_expenses = $pdo->query("SELECT SUM(amount) FROM expenses WHERE status = 'approved'")->fetchColumn() ?? 0;
$pending_payments = $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn() ?? 0;
$balance = $total_payments - $total_expenses;

// Get fees list with class names
$fee_search = $_GET['fee_search'] ?? '';
$fee_status_filter = $_GET['fee_status'] ?? '';
$fee_type_filter = $_GET['fee_type'] ?? '';
$fee_params = [];
$fee_query = "SELECT f.*, c.class_name FROM fees f LEFT JOIN classes c ON f.class_id = c.id WHERE 1=1";

if ($fee_search) {
    $fee_query .= " AND (f.fee_name LIKE ? OR f.description LIKE ?)";
    $fee_params[] = "%$fee_search%";
    $fee_params[] = "%$fee_search%";
}

if ($fee_status_filter === 'active') {
    $fee_query .= " AND f.is_active = 1";
} elseif ($fee_status_filter === 'inactive') {
    $fee_query .= " AND f.is_active = 0";
}

if ($fee_type_filter && $fee_type_filter !== 'all') {
    $fee_query .= " AND f.fee_type = ?";
    $fee_params[] = $fee_type_filter;
}

$fee_query .= " ORDER BY f.is_published DESC, f.created_at DESC";
$fee_stmt = $pdo->prepare($fee_query);
$fee_stmt->execute($fee_params);
$fees = $fee_stmt->fetchAll();

// Get student fee assignments
$student_fees_query = "SELECT sf.*, s.full_name as student_name, s.admission_number, f.fee_name, u.full_name as assigned_by_name 
                      FROM student_fees sf
                      LEFT JOIN students s ON sf.student_id = s.id
                      LEFT JOIN fees f ON sf.fee_id = f.id
                      LEFT JOIN users u ON sf.assigned_by = u.id
                      ORDER BY sf.assigned_at DESC";
$student_fees = $pdo->query($student_fees_query)->fetchAll();

// Get expenses list
$expense_search = $_GET['expense_search'] ?? '';
$expense_params = [];
$expense_query = "SELECT e.*, u.full_name as approved_by_name FROM expenses e LEFT JOIN users u ON e.approved_by = u.id WHERE 1=1";

if ($expense_search) {
    $expense_query .= " AND (expense_type LIKE ? OR description LIKE ? OR paid_to LIKE ?)";
    $expense_params[] = "%$expense_search%";
    $expense_params[] = "%$expense_search%";
    $expense_params[] = "%$expense_search%";
}

if (isset($_GET['expense_status']) && $_GET['expense_status'] !== '') {
    $expense_query .= " AND e.status = ?";
    $expense_params[] = $_GET['expense_status'];
}

$expense_query .= " ORDER BY e.created_at DESC";
$expense_stmt = $pdo->prepare($expense_query);
$expense_stmt->execute($expense_params);
$expenses = $expense_stmt->fetchAll();

// Get payments list
$payment_search = $_GET['payment_search'] ?? '';
$payment_params = [];
$payment_query = "SELECT p.*, s.full_name as student_name, f.fee_name FROM payments p 
                  LEFT JOIN students s ON p.student_id = s.id 
                  LEFT JOIN fees f ON p.fee_id = f.id 
                  WHERE 1=1";

if ($payment_search) {
    $payment_query .= " AND (s.full_name LIKE ? OR f.fee_name LIKE ? OR p.transaction_id LIKE ?)";
    $payment_params[] = "%$payment_search%";
    $payment_params[] = "%$payment_search%";
    $payment_params[] = "%$payment_search%";
}

if (isset($_GET['payment_status']) && $_GET['payment_status'] !== '') {
    $payment_query .= " AND p.status = ?";
    $payment_params[] = $_GET['payment_status'];
}

$payment_query .= " ORDER BY p.created_at DESC";
$payment_stmt = $pdo->prepare($payment_query);
$payment_stmt->execute($payment_params);
$payments = $payment_stmt->fetchAll();

// Printable report
if (isset($_GET['report']) && $_GET['report'] == '1') {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Finance Report</title>";
    echo "<style>body{font-family:Arial,Helvetica,sans-serif;font-size:13px;}table{width:100%;border-collapse:collapse;}th,td{padding:6px;border:1px solid #ccc;text-align:left}th{background:#f8f9fa}</style>";
    echo "</head><body>";
    echo "<h2>Finance Report - " . date('F j, Y') . "</h2>";
    
    echo "<h3>Financial Summary</h3>";
    echo "<table><tbody>";
    echo "<tr><th>Total Published Fees</th><td>KES" . number_format($total_fees, 2) . "</td></tr>";
    echo "<tr><th>Total Payments Received</th><td>KES" . number_format($total_payments, 2) . "</td></tr>";
    echo "<tr><th>Total Expenses</th><td>KES" . number_format($total_expenses, 2) . "</td></tr>";
    echo "<tr><th>Pending Payments</th><td>" . $pending_payments . "</td></tr>";
    echo "<tr class='table-success'><th><strong>Current Balance</strong></th><td><strong>KES" . number_format($balance, 2) . "</strong></td></tr>";
    echo "</tbody></table>";
    
    echo "<script>window.print();</script></body></html>";
    exit();
}

$page_title = "Finance Management - " . SCHOOL_NAME;
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
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <style>
        /* Modal z-index fix */
        .swal2-container {
            z-index: 99999 !important;
        }
        
        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            transition: margin-left 0.3s ease;
            background: #f8f9fa;
            min-height: calc(100vh - 70px);
        }
        
        .management-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e9ecef;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 0.7rem 1.5rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            white-space: nowrap;
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-1px);
        }
        
        .btn-success {
            background: #27ae60;
            color: white;
        }
        
        .btn-warning {
            background: #f39c12;
            color: white;
        }
        
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid #3498db;
            color: #3498db;
        }
        
        .btn-outline:hover {
            background: #3498db;
            color: white;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 10px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            z-index: 10000;
        }
        
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8f9fa;
            border-radius: 10px 10px 0 0;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6c757d;
        }
        
        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .form-hint {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 0.3rem;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        
        input:focus, select:focus, textarea:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        /* Statistics Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border-left: 4px solid;
        }
        
        .stat-card.fees { border-left-color: #3498db; }
        .stat-card.payments { border-left-color: #27ae60; }
        .stat-card.expenses { border-left-color: #f39c12; }
        .stat-card.pending { border-left-color: #e74c3c; }
        .stat-card.balance { border-left-color: #9b59b6; }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        
        .stat-card.fees .stat-number { color: #3498db; }
        .stat-card.payments .stat-number { color: #27ae60; }
        .stat-card.expenses .stat-number { color: #f39c12; }
        .stat-card.pending .stat-number { color: #e74c3c; }
        .stat-card.balance .stat-number { color: #9b59b6; }
        
        .stat-description {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }
        
        /* Table Styles */
        .data-table {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        
        .table-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8f9fa;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }
        
        .status-active {
            background: rgba(39, 174, 96, 0.1);
            color: #27ae60;
        }
        
        .status-inactive {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
        }
        
        .status-approved {
            background: rgba(39, 174, 96, 0.1);
            color: #27ae60;
        }
        
        .status-pending {
            background: rgba(243, 156, 18, 0.1);
            color: #f39c12;
        }
        
        .status-rejected {
            background: rgba(231, 76, 60, 0.1);
            color: #e74c3c;
        }
        
        .status-paid {
            background: rgba(39, 174, 96, 0.1);
            color: #27ae60;
        }
        
        .status-published {
            background: rgba(52, 152, 219, 0.1);
            color: #3498db;
        }
        
        .status-unpublished {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
        }
        
        /* Action Buttons */
        .action-buttons-small {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        /* Alert Styles */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            position: relative;
            z-index: 1;
        }
        
        .alert-success {
            background: rgba(39, 174, 96, 0.1);
            border-color: #27ae60;
            color: #155724;
        }
        
        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            border-color: #e74c3c;
            color: #721c24;
        }
        
        /* Layout */
        .content-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }
        
        /* Tabs */
        .tabs {
            display: flex;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            flex-wrap: wrap;
        }
        
        .tab {
            padding: 1rem 1.5rem;
            cursor: pointer;
            font-weight: 600;
            color: #6c757d;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            white-space: nowrap;
        }
        
        .tab.active {
            color: #3498db;
            border-bottom-color: #3498db;
            background: #f8f9fa;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Fee type badges */
        .fee-type-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
            margin-right: 0.5rem;
        }
        
        .fee-type-general {
            background: rgba(52, 152, 219, 0.1);
            color: #3498db;
        }
        
        .fee-type-class {
            background: rgba(155, 89, 182, 0.1);
            color: #9b59b6;
        }
        
        .fee-type-additional {
            background: rgba(243, 156, 18, 0.1);
            color: #f39c12;
        }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .content-layout {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .management-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons-small {
                flex-direction: column;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab {
                border-bottom: 1px solid #e9ecef;
                border-left: 3px solid transparent;
            }
            
            .tab.active {
                border-left-color: #3498db;
                border-bottom-color: #e9ecef;
            }
        }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="main-content">
        <div class="management-header">
            <div>
                <h1>Finance Management</h1>
                <p>Manage fees, expenses, payments, and financial reports</p>
            </div>
            <div class="action-buttons">
                <button class="btn btn-success" onclick="openModal('addFeeModal')">
                    <i class="fas fa-plus"></i>
                    Add Fee
                </button>
                <button class="btn btn-warning" onclick="openModal('assignFeeModal')">
                    <i class="fas fa-user-plus"></i>
                    Assign to Student
                </button>
                <button class="btn btn-success" onclick="openModal('addExpenseModal')">
                    <i class="fas fa-plus"></i>
                    Add Expense
                </button>
                <button class="btn btn-info" onclick="generateFinanceReport()">
                    <i class="fas fa-chart-bar"></i>
                    Generate Report
                </button>
            </div>
        </div>
        
        <!-- Success/Error Messages -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['success']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>
        
        <!-- Tabs Navigation -->
        <div class="tabs">
            <div class="tab active" onclick="openTab('dashboardTab')">Dashboard</div>
            <div class="tab" onclick="openTab('feesTab')">Fees (<?php echo count($fees); ?>)</div>
            <div class="tab" onclick="openTab('studentFeesTab')">Student Fees (<?php echo count($student_fees); ?>)</div>
            <div class="tab" onclick="openTab('expensesTab')">Expenses (<?php echo count($expenses); ?>)</div>
            <div class="tab" onclick="openTab('paymentsTab')">Payments (<?php echo count($payments); ?>)</div>
        </div>
        
        <!-- Dashboard Tab -->
        <div id="dashboardTab" class="tab-content active">
            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card fees">
                    <div class="stat-number">KES<?php echo number_format($total_fees, 2); ?></div>
                    <div class="stat-description">Published Fees Structure</div>
                </div>
                <div class="stat-card payments">
                    <div class="stat-number">KES<?php echo number_format($total_payments, 2); ?></div>
                    <div class="stat-description">Payments Received</div>
                </div>
                <div class="stat-card expenses">
                    <div class="stat-number">KES<?php echo number_format($total_expenses, 2); ?></div>
                    <div class="stat-description">Total Expenses</div>
                </div>
                <div class="stat-card pending">
                    <div class="stat-number"><?php echo $pending_payments; ?></div>
                    <div class="stat-description">Pending Payments</div>
                </div>
                <div class="stat-card balance">
                    <div class="stat-number">KES<?php echo number_format($balance, 2); ?></div>
                    <div class="stat-description">Current Balance</div>
                </div>
            </div>
            
            <div class="content-layout">
                <div>
                    <!-- Recent Fees -->
                    <div class="data-table">
                        <div class="table-header">
                            <h3>Recent Fees</h3>
                            <button class="btn btn-sm btn-outline" onclick="openTab('feesTab')">
                                View All
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Fee Name</th>
                                        <th>Amount</th>
                                        <th>Type</th>
                                        <th>Class</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $recent_fees = array_slice($fees, 0, 5);
                                    foreach($recent_fees as $fee): 
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($fee['fee_name']); ?></div>
                                            <?php if ($fee['description']): ?>
                                            <div style="font-size: 0.8rem; color: #6c757d;"><?php echo htmlspecialchars(substr($fee['description'], 0, 50)); ?>...</div>
                                            <?php endif; ?>
                                        </td>
                                        <td>KES<?php echo number_format($fee['amount'], 2); ?></td>
                                        <td>
                                            <span class="fee-type-badge fee-type-<?php echo $fee['fee_type']; ?>">
                                                <?php echo ucfirst($fee['fee_type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($fee['class_name'] ?? 'All'); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $fee['is_published'] ? 'status-published' : 'status-unpublished'; ?>">
                                                <?php echo $fee['is_published'] ? 'Published' : 'Draft'; ?>
                                            </span>
                                            <span class="status-badge <?php echo $fee['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                <?php echo $fee['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons-small">
                                                <button class="btn btn-sm btn-outline" onclick="viewFeeDetails(<?php echo $fee['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline" onclick="editFee(<?php echo $fee['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php if ($fee['is_published']): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="fee_id" value="<?php echo $fee['id']; ?>">
                                                    <input type="hidden" name="publish_action" value="unpublish">
                                                    <button type="submit" name="toggle_publish_fee" class="btn btn-sm btn-warning" title="Unpublish">
                                                        <i class="fas fa-eye-slash"></i>
                                                    </button>
                                                </form>
                                                <?php else: ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="fee_id" value="<?php echo $fee['id']; ?>">
                                                    <input type="hidden" name="publish_action" value="publish">
                                                    <button type="submit" name="toggle_publish_fee" class="btn btn-sm btn-success" title="Publish">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($recent_fees)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 2rem; color: #6c757d;">
                                            No fees found
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Recent Expenses -->
                    <div class="data-table">
                        <div class="table-header">
                            <h3>Recent Expenses</h3>
                            <button class="btn btn-sm btn-outline" onclick="openTab('expensesTab')">
                                View All
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Expense Type</th>
                                        <th>Amount</th>
                                        <th>Date</th>
                                        <th>Paid To</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $recent_expenses = array_slice($expenses, 0, 5);
                                    foreach($recent_expenses as $expense): 
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($expense['expense_type']); ?></div>
                                            <?php if ($expense['description']): ?>
                                            <div style="font-size: 0.8rem; color: #6c757d;"><?php echo htmlspecialchars(substr($expense['description'], 0, 50)); ?>...</div>
                                            <?php endif; ?>
                                        </td>
                                        <td>KES<?php echo number_format($expense['amount'], 2); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($expense['expense_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($expense['paid_to']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower($expense['status']); ?>">
                                                <?php echo $expense['status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons-small">
                                                <button class="btn btn-sm btn-outline" onclick="viewExpenseDetails(<?php echo $expense['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline" onclick="editExpense(<?php echo $expense['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($recent_expenses)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 2rem; color: #6c757d;">
                                            No expenses found
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Sidebar -->
                <div>
                    <!-- Quick Actions -->
                    <div class="data-table">
                        <div class="table-header">
                            <h3>Quick Actions</h3>
                        </div>
                        <div style="padding: 1.5rem; display: grid; gap: 0.75rem;">
                            <button class="btn btn-success" onclick="openModal('addFeeModal')">
                                <i class="fas fa-plus"></i> Add New Fee
                            </button>
                            <button class="btn btn-warning" onclick="openModal('assignFeeModal')">
                                <i class="fas fa-user-plus"></i> Assign Fee to Student
                            </button>
                            <button class="btn btn-success" onclick="openModal('addExpenseModal')">
                                <i class="fas fa-plus"></i> Add New Expense
                            </button>
                            <button class="btn btn-primary" onclick="openTab('feesTab')">
                                <i class="fas fa-list"></i> Manage Fees
                            </button>
                            <button class="btn btn-primary" onclick="openTab('studentFeesTab')">
                                <i class="fas fa-user-graduate"></i> Student Fees
                            </button>
                            <button class="btn btn-primary" onclick="openTab('expensesTab')">
                                <i class="fas fa-receipt"></i> Manage Expenses
                            </button>
                            <button class="btn btn-primary" onclick="openTab('paymentsTab')">
                                <i class="fas fa-cash-register"></i> View Payments
                            </button>
                            <button class="btn btn-info" onclick="generateFinanceReport()">
                                <i class="fas fa-chart-bar"></i> Generate Report
                            </button>
                        </div>
                    </div>
                    
                    <!-- Financial Summary -->
                    <div class="data-table">
                        <div class="table-header">
                            <h3>Financial Summary</h3>
                        </div>
                        <div style="padding: 1.5rem;">
                            <div style="margin-bottom: 1rem;">
                                <div style="font-weight: 600; color: #2c3e50; margin-bottom: 0.5rem;">Income vs Expenses</div>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                    <span>Total Payments:</span>
                                    <span style="font-weight: 600; color: #27ae60;">KES<?php echo number_format($total_payments, 2); ?></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                    <span>Total Expenses:</span>
                                    <span style="font-weight: 600; color: #f39c12;">KES<?php echo number_format($total_expenses, 2); ?></span>
                                </div>
                                <div style="height: 4px; background: #e9ecef; border-radius: 2px; margin: 1rem 0; overflow: hidden;">
                                    <?php 
                                    $total = $total_payments + $total_expenses;
                                    if ($total > 0):
                                        $income_percent = ($total_payments / $total) * 100;
                                        $expense_percent = ($total_expenses / $total) * 100;
                                    ?>
                                    <div style="height: 100%; width: <?php echo $income_percent; ?>%; background: #27ae60; float: left;"></div>
                                    <div style="height: 100%; width: <?php echo $expense_percent; ?>%; background: #f39c12; float: left;"></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="padding: 1rem; background: #f8f9fa; border-radius: 6px; text-align: center;">
                                <div style="font-size: 0.9rem; color: #6c757d; margin-bottom: 0.5rem;">Current Balance</div>
                                <div style="font-size: 1.5rem; font-weight: bold; color: <?php echo $balance >= 0 ? '#27ae60' : '#e74c3c'; ?>;">
                                    KES<?php echo number_format($balance, 2); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Fees Tab -->
        <div id="feesTab" class="tab-content">
            <div class="filter-section">
                <form method="GET" id="feeFilter">
                    <input type="hidden" name="tab" value="fees">
                    <div class="filter-grid">
                        <div class="form-group">
                            <label for="fee_search">Search Fees</label>
                            <input type="text" id="fee_search" name="fee_search" value="<?php echo htmlspecialchars($fee_search); ?>" placeholder="Search by fee name or description...">
                        </div>
                        <div class="form-group">
                            <label for="fee_status">Status</label>
                            <select id="fee_status" name="fee_status">
                                <option value="">All Status</option>
                                <option value="active" <?php echo isset($_GET['fee_status']) && $_GET['fee_status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo isset($_GET['fee_status']) && $_GET['fee_status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="fee_type">Fee Type</label>
                            <select id="fee_type" name="fee_type">
                                <option value="all">All Types</option>
                                <option value="general" <?php echo isset($_GET['fee_type']) && $_GET['fee_type'] == 'general' ? 'selected' : ''; ?>>General</option>
                                <option value="class" <?php echo isset($_GET['fee_type']) && $_GET['fee_type'] == 'class' ? 'selected' : ''; ?>>Class Specific</option>
                                <option value="additional" <?php echo isset($_GET['fee_type']) && $_GET['fee_type'] == 'additional' ? 'selected' : ''; ?>>Additional</option>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                            <button type="button" class="btn btn-outline" onclick="window.location.href='finance.php'">
                                <i class="fas fa-times"></i> Clear
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="data-table">
                <div class="table-header">
                    <h3>Fees Management (<?php echo count($fees); ?>)</h3>
                    <div>
                        <button class="btn btn-sm btn-outline" onclick="printFeesReport()">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <button class="btn btn-sm btn-success" onclick="openModal('addFeeModal')">
                            <i class="fas fa-plus"></i> Add New
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Fee Name</th>
                                <th>Amount</th>
                                <th>Type</th>
                                <th>Class</th>
                                <th>Academic Year</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($fees as $fee): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($fee['fee_name']); ?></div>
                                    <?php if ($fee['description']): ?>
                                    <div style="font-size: 0.8rem; color: #6c757d;"><?php echo htmlspecialchars(substr($fee['description'], 0, 100)); ?>...</div>
                                    <?php endif; ?>
                                </td>
                                <td>KES<?php echo number_format($fee['amount'], 2); ?></td>
                                <td>
                                    <span class="fee-type-badge fee-type-<?php echo $fee['fee_type']; ?>">
                                        <?php echo ucfirst($fee['fee_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($fee['class_name'] ?? 'All'); ?></td>
                                <td><?php echo htmlspecialchars($fee['academic_year']); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $fee['is_published'] ? 'status-published' : 'status-unpublished'; ?>">
                                        <?php echo $fee['is_published'] ? 'Published' : 'Draft'; ?>
                                    </span>
                                    <span class="status-badge <?php echo $fee['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $fee['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons-small">
                                        <button class="btn btn-sm btn-outline" onclick="viewFeeDetails(<?php echo $fee['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline" onclick="editFee(<?php echo $fee['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($fee['is_published']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="fee_id" value="<?php echo $fee['id']; ?>">
                                            <input type="hidden" name="publish_action" value="unpublish">
                                            <button type="submit" name="toggle_publish_fee" class="btn btn-sm btn-warning" title="Unpublish">
                                                <i class="fas fa-eye-slash"></i>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="fee_id" value="<?php echo $fee['id']; ?>">
                                            <input type="hidden" name="publish_action" value="publish">
                                            <button type="submit" name="toggle_publish_fee" class="btn btn-sm btn-success" title="Publish">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirmDeleteFee(this);">
                                            <input type="hidden" name="fee_id" value="<?php echo $fee['id']; ?>">
                                            <button type="submit" name="delete_fee" class="btn btn-sm btn-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($fees)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 3rem; color: #6c757d;">
                                    <i class="fas fa-money-bill-wave fa-2x" style="margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <h4>No Fees Found</h4>
                                    <p>No fees match your current filters.</p>
                                    <button class="btn btn-success" onclick="openModal('addFeeModal')">
                                        <i class="fas fa-plus"></i> Add First Fee
                                    </button>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Student Fees Tab -->
        <div id="studentFeesTab" class="tab-content">
            <div class="data-table">
                <div class="table-header">
                    <h3>Student Fee Assignments (<?php echo count($student_fees); ?>)</h3>
                    <div>
                        <button class="btn btn-sm btn-outline" onclick="printStudentFeesReport()">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <button class="btn btn-sm btn-warning" onclick="openModal('assignFeeModal')">
                            <i class="fas fa-user-plus"></i> Assign Fee
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Fee</th>
                                <th>Amount</th>
                                <th>Custom Amount</th>
                                <th>Assigned By</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($student_fees as $assignment): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($assignment['student_name']); ?></div>
                                    <div style="font-size: 0.8rem; color: #6c757d;"><?php echo htmlspecialchars($assignment['admission_number']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($assignment['fee_name']); ?></td>
                                <td>KES<?php echo number_format($assignment['amount'], 2); ?></td>
                                <td>
                                    <?php if ($assignment['custom_amount']): ?>
                                    KES<?php echo number_format($assignment['custom_amount'], 2); ?>
                                    <?php else: ?>
                                    <span style="color: #6c757d;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($assignment['assigned_by_name']); ?></td>
                                <td><?php echo date('M j, Y', strtotime($assignment['assigned_at'])); ?></td>
                                <td>
                                    <div class="action-buttons-small">
                                        <form method="POST" style="display: inline;" onsubmit="return confirmDeleteStudentFee(this);">
                                            <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                            <button type="submit" name="delete_student_fee" class="btn btn-sm btn-danger">
                                                <i class="fas fa-trash"></i> Remove
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($student_fees)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 3rem; color: #6c757d;">
                                    <i class="fas fa-user-graduate fa-2x" style="margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <h4>No Student Fee Assignments</h4>
                                    <p>No fee assignments found. Assign fees to students to get started.</p>
                                    <button class="btn btn-warning" onclick="openModal('assignFeeModal')">
                                        <i class="fas fa-user-plus"></i> Assign First Fee
                                    </button>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Expenses Tab -->
        <div id="expensesTab" class="tab-content">
            <div class="filter-section">
                <form method="GET" id="expenseFilter">
                    <input type="hidden" name="tab" value="expenses">
                    <div class="filter-grid">
                        <div class="form-group">
                            <label for="expense_search">Search Expenses</label>
                            <input type="text" id="expense_search" name="expense_search" value="<?php echo htmlspecialchars($expense_search); ?>" placeholder="Search by type, description, or paid to...">
                        </div>
                        <div class="form-group">
                            <label for="expense_status">Status</label>
                            <select id="expense_status" name="expense_status">
                                <option value="">All Status</option>
                                <option value="approved" <?php echo isset($_GET['expense_status']) && $_GET['expense_status'] == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="pending" <?php echo isset($_GET['expense_status']) && $_GET['expense_status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="rejected" <?php echo isset($_GET['expense_status']) && $_GET['expense_status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                            <button type="button" class="btn btn-outline" onclick="window.location.href='finance.php'">
                                <i class="fas fa-times"></i> Clear
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="data-table">
                <div class="table-header">
                    <h3>Expenses Management (<?php echo count($expenses); ?>)</h3>
                    <div>
                        <button class="btn btn-sm btn-outline" onclick="printExpensesReport()">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <button class="btn btn-sm btn-success" onclick="openModal('addExpenseModal')">
                            <i class="fas fa-plus"></i> Add New
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Expense Type</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Paid To</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($expenses as $expense): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($expense['expense_type']); ?></div>
                                    <?php if ($expense['description']): ?>
                                    <div style="font-size: 0.8rem; color: #6c757d;"><?php echo htmlspecialchars(substr($expense['description'], 0, 100)); ?>...</div>
                                    <?php endif; ?>
                                </td>
                                <td>KES<?php echo number_format($expense['amount'], 2); ?></td>
                                <td><?php echo date('M j, Y', strtotime($expense['expense_date'])); ?></td>
                                <td><?php echo htmlspecialchars($expense['paid_to']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower($expense['status']); ?>">
                                        <?php echo $expense['status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons-small">
                                        <button class="btn btn-sm btn-outline" onclick="viewExpenseDetails(<?php echo $expense['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline" onclick="editExpense(<?php echo $expense['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirmDeleteExpense(this);">
                                            <input type="hidden" name="expense_id" value="<?php echo $expense['id']; ?>">
                                            <button type="submit" name="delete_expense" class="btn btn-sm btn-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($expenses)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 3rem; color: #6c757d;">
                                    <i class="fas fa-credit-card fa-2x" style="margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <h4>No Expenses Found</h4>
                                    <p>No expenses match your current filters.</p>
                                    <button class="btn btn-success" onclick="openModal('addExpenseModal')">
                                        <i class="fas fa-plus"></i> Add First Expense
                                    </button>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Payments Tab -->
        <div id="paymentsTab" class="tab-content">
            <div class="filter-section">
                <form method="GET" id="paymentFilter">
                    <input type="hidden" name="tab" value="payments">
                    <div class="filter-grid">
                        <div class="form-group">
                            <label for="payment_search">Search Payments</label>
                            <input type="text" id="payment_search" name="payment_search" value="<?php echo htmlspecialchars($payment_search); ?>" placeholder="Search by student, fee, or transaction...">
                        </div>
                        <div class="form-group">
                            <label for="payment_status">Status</label>
                            <select id="payment_status" name="payment_status">
                                <option value="">All Status</option>
                                <option value="paid" <?php echo isset($_GET['payment_status']) && $_GET['payment_status'] == 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="pending" <?php echo isset($_GET['payment_status']) && $_GET['payment_status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="partial" <?php echo isset($_GET['payment_status']) && $_GET['payment_status'] == 'partial' ? 'selected' : ''; ?>>Partial</option>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                            <button type="button" class="btn btn-outline" onclick="window.location.href='finance.php'">
                                <i class="fas fa-times"></i> Clear
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="data-table">
                <div class="table-header">
                    <h3>Payments History (<?php echo count($payments); ?>)</h3>
                    <div>
                        <button class="btn btn-sm btn-outline" onclick="printPaymentsReport()">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Fee</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Method</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($payments as $payment): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($payment['student_name']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($payment['fee_name']); ?></td>
                                <td>KES<?php echo number_format($payment['amount'], 2); ?></td>
                                <td><?php echo date('M j, Y', strtotime($payment['payment_date'])); ?></td>
                                <td><?php echo ucfirst($payment['payment_method']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower($payment['status']); ?>">
                                        <?php echo ucfirst($payment['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($payments)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 3rem; color: #6c757d;">
                                    <i class="fas fa-cash-register fa-2x" style="margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <h4>No Payments Found</h4>
                                    <p>No payments match your current filters.</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add/Edit Fee Modal -->
    <div id="addFeeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Fee</h3>
                <button class="close" onclick="closeModal('addFeeModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addFeeForm">
                    <input type="hidden" id="fee_id" name="id" value="0">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label for="fee_name">Fee Name *</label>
                            <input type="text" id="fee_name" name="fee_name" required>
                        </div>
                        <div class="form-group">
                            <label for="amount">Amount (KES) *</label>
                            <input type="number" id="amount" name="amount" step="0.01" min="0" required>
                        </div>
                        <div class="form-group">
                            <label for="fee_type">Fee Type *</label>
                            <select id="fee_type" name="fee_type" required onchange="toggleClassField()">
                                <option value="general">General (All Students)</option>
                                <option value="class">Class Specific</option>
                                <option value="additional">Additional (Individual)</option>
                            </select>
                        </div>
                        <div class="form-group" id="classFieldContainer" style="display: none;">
                            <label for="class_id">Class</label>
                            <select id="class_id" name="class_id">
                                <option value="">Select Class</option>
                                <?php foreach($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="academic_year">Academic Year</label>
                            <input type="text" id="academic_year" name="academic_year" placeholder="e.g., 2024-2025">
                        </div>
                        <div class="form-group">
                            <label for="due_date">Due Date</label>
                            <input type="date" id="due_date" name="due_date">
                        </div>
                        <div class="form-group">
                            <label for="is_published">Publish Status</label>
                            <select id="is_published" name="is_published">
                                <option value="0">Draft (Not visible to students)</option>
                                <option value="1">Published (Visible to students)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="is_active">Active Status</label>
                            <select id="is_active" name="is_active">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                        <div class="form-group full-width">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" rows="3" placeholder="Description of this fee..."></textarea>
                        </div>
                    </div>
                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addFeeModal')">Cancel</button>
                        <button type="submit" class="btn btn-success">Save Fee</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Assign Fee to Student Modal -->
    <div id="assignFeeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Assign Fee to Student</h3>
                <button class="close" onclick="closeModal('assignFeeModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="assignFeeForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="student_id">Student *</label>
                            <select id="student_id" name="student_id" required>
                                <option value="">Select Student</option>
                                <?php foreach($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>">
                                    <?php echo htmlspecialchars($student['full_name'] . ' (' . $student['admission_number'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="assign_fee_id">Fee *</label>
                            <select id="assign_fee_id" name="fee_id" required>
                                <option value="">Select Fee</option>
                                <?php foreach($fees as $fee): ?>
                                <?php if ($fee['is_active'] && $fee['fee_type'] === 'additional'): ?>
                                <option value="<?php echo $fee['id']; ?>" data-amount="<?php echo $fee['amount']; ?>">
                                    <?php echo htmlspecialchars($fee['fee_name'] . ' - KES ' . number_format($fee['amount'], 2)); ?>
                                </option>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="custom_amount">Custom Amount (Optional)</label>
                            <input type="number" id="custom_amount" name="custom_amount" step="0.01" min="0" placeholder="Leave empty to use default">
                            <div class="form-hint">Default amount: <span id="defaultAmount">KES 0.00</span></div>
                        </div>
                        <div class="form-group full-width">
                            <label for="assign_notes">Notes (Optional)</label>
                            <textarea id="assign_notes" name="notes" rows="2" placeholder="Notes about this assignment..."></textarea>
                        </div>
                    </div>
                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('assignFeeModal')">Cancel</button>
                        <button type="submit" class="btn btn-warning">Assign Fee</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Add/Edit Expense Modal -->
    <div id="addExpenseModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Expense</h3>
                <button class="close" onclick="closeModal('addExpenseModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addExpenseForm">
                    <input type="hidden" id="expense_id" name="id" value="0">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label for="expense_type">Expense Type *</label>
                            <input type="text" id="expense_type" name="expense_type" required placeholder="e.g., Stationery, Electricity, Maintenance">
                        </div>
                        <div class="form-group">
                            <label for="expense_amount">Amount (KES) *</label>
                            <input type="number" id="expense_amount" name="amount" step="0.01" min="0" required>
                        </div>
                        <div class="form-group">
                            <label for="expense_date">Date *</label>
                            <input type="date" id="expense_date" name="expense_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="payment_method">Payment Method</label>
                            <select id="payment_method" name="payment_method">
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="reference_no">Reference No</label>
                            <input type="text" id="reference_no" name="reference_no" placeholder="Transaction/Cheque number">
                        </div>
                        <div class="form-group">
                            <label for="paid_to">Paid To</label>
                            <input type="text" id="paid_to" name="paid_to" placeholder="Recipient name">
                        </div>
                        <div class="form-group full-width">
                            <label for="expense_description">Description</label>
                            <textarea id="expense_description" name="description" rows="3" placeholder="Description of this expense..."></textarea>
                        </div>
                    </div>
                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addExpenseModal')">Cancel</button>
                        <button type="submit" class="btn btn-success">Save Expense</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- View Fee Modal -->
    <div id="viewFeeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Fee Details</h3>
                <button class="close" onclick="closeModal('viewFeeModal')">&times;</button>
            </div>
            <div class="modal-body" id="viewFeeBody">
                <!-- Populated via AJAX -->
            </div>
            <div style="padding: 1.5rem; border-top: 1px solid #e9ecef; display: flex; gap: 1rem; justify-content: flex-end;">
                <button class="btn btn-outline" onclick="closeModal('viewFeeModal')">Close</button>
                <button class="btn btn-primary" id="viewFeeToEditBtn">Edit</button>
            </div>
        </div>
    </div>
    
    <!-- View Expense Modal -->
    <div id="viewExpenseModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Expense Details</h3>
                <button class="close" onclick="closeModal('viewExpenseModal')">&times;</button>
            </div>
            <div class="modal-body" id="viewExpenseBody">
                <!-- Populated via AJAX -->
            </div>
            <div style="padding: 1.5rem; border-top: 1px solid #e9ecef; display: flex; gap: 1rem; justify-content: flex-end;">
                <button class="btn btn-outline" onclick="closeModal('viewExpenseModal')">Close</button>
                <button class="btn btn-primary" id="viewExpenseToEditBtn">Edit</button>
            </div>
        </div>
    </div>

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        const Swal = window.Swal;
        
        // Tab functions
        function openTab(tabId) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabId).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }
        
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
        
        // Toggle class field based on fee type
        function toggleClassField() {
            const feeType = document.getElementById('fee_type').value;
            const classField = document.getElementById('classFieldContainer');
            
            if (feeType === 'class') {
                classField.style.display = 'block';
                document.getElementById('class_id').required = true;
            } else {
                classField.style.display = 'none';
                document.getElementById('class_id').required = false;
                document.getElementById('class_id').value = '';
            }
        }
        
        // Update default amount display
        function updateDefaultAmount() {
            const feeSelect = document.getElementById('assign_fee_id');
            const selectedOption = feeSelect.options[feeSelect.selectedIndex];
            const defaultAmount = selectedOption ? selectedOption.getAttribute('data-amount') : 0;
            document.getElementById('defaultAmount').textContent = 'KES ' + parseFloat(defaultAmount || 0).toFixed(2);
        }
        
        // View fee details
        function viewFeeDetails(feeId) {
            fetch(window.location.pathname + '?ajax=details&type=fee&id=' + encodeURIComponent(feeId), {
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.error || 'Failed to load fee details'
                    });
                    return;
                }
                const fee = data.data;
                const body = document.getElementById('viewFeeBody');
                body.innerHTML = `
                    <div style="font-weight:600;font-size:1.05rem;">${escapeHtml(fee.fee_name)}</div>
                    <div style="margin-bottom:12px;">
                        <span class="fee-type-badge fee-type-${escapeHtml(fee.fee_type)}">${escapeHtml(fee.fee_type)}</span>
                        <span class="status-badge ${fee.is_published ? 'status-published' : 'status-unpublished'}">${fee.is_published ? 'Published' : 'Draft'}</span>
                        <span class="status-badge ${fee.is_active ? 'status-active' : 'status-inactive'}">${fee.is_active ? 'Active' : 'Inactive'}</span>
                    </div>
                    <div><strong>Amount:</strong> KES${escapeHtml(fee.amount.toFixed(2))}</div>
                    <div><strong>Academic Year:</strong> ${escapeHtml(fee.academic_year || 'N/A')}</div>
                    ${fee.class_name ? `<div><strong>Class:</strong> ${escapeHtml(fee.class_name)}</div>` : ''}
                    ${fee.due_date ? `<div><strong>Due Date:</strong> ${new Date(fee.due_date).toLocaleDateString()}</div>` : ''}
                    <div style="margin-top:12px;"><strong>Description:</strong><div style="margin-top:6px;color:#333;">${escapeHtml(fee.description || 'No description')}</div></div>
                    <div style="margin-top:12px;"><strong>Created:</strong> ${new Date(fee.created_at).toLocaleDateString()}</div>
                `;

                const editBtn = document.getElementById('viewFeeToEditBtn');
                editBtn.onclick = function() { closeModal('viewFeeModal'); editFee(feeId); };

                openModal('viewFeeModal');
            })
            .catch(err => {
                console.error(err);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Error loading fee details'
                });
            });
        }

        // Edit fee
        function editFee(feeId) {
            fetch(window.location.pathname + '?ajax=details&type=fee&id=' + encodeURIComponent(feeId), { 
                credentials: 'same-origin' 
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.error || 'Failed to load fee'
                    });
                    return;
                }
                const fee = data.data;
                document.getElementById('fee_id').value = fee.id;
                document.getElementById('fee_name').value = fee.fee_name || '';
                document.getElementById('amount').value = fee.amount || '';
                document.getElementById('fee_type').value = fee.fee_type || 'general';
                document.getElementById('class_id').value = fee.class_id || '';
                document.getElementById('academic_year').value = fee.academic_year || '';
                document.getElementById('due_date').value = fee.due_date || '';
                document.getElementById('is_published').value = fee.is_published || '0';
                document.getElementById('is_active').value = fee.is_active || '1';
                document.getElementById('description').value = fee.description || '';
                
                // Toggle class field
                toggleClassField();
                
                document.querySelector('#addFeeModal h3').textContent = 'Edit Fee';
                openModal('addFeeModal');
            })
            .catch(err => {
                console.error(err);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Error loading fee for edit'
                });
            });
        }

        // View expense details
        function viewExpenseDetails(expenseId) {
            fetch(window.location.pathname + '?ajax=details&type=expense&id=' + encodeURIComponent(expenseId), {
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.error || 'Failed to load expense details'
                    });
                    return;
                }
                const expense = data.data;
                const body = document.getElementById('viewExpenseBody');
                body.innerHTML = `
                    <div style="font-weight:600;font-size:1.05rem;">${escapeHtml(expense.expense_type)}</div>
                    <div style="margin-bottom:8px;">
                        <span class="status-badge status-${expense.status.toLowerCase()}">${escapeHtml(expense.status)}</span>
                    </div>
                    <div><strong>Amount:</strong> KES${escapeHtml(expense.amount.toFixed(2))}</div>
                    <div><strong>Date:</strong> ${new Date(expense.expense_date).toLocaleDateString()}</div>
                    <div><strong>Paid To:</strong> ${escapeHtml(expense.paid_to || 'N/A')}</div>
                    <div><strong>Payment Method:</strong> ${escapeHtml(expense.payment_method)}</div>
                    ${expense.reference_no ? `<div><strong>Reference No:</strong> ${escapeHtml(expense.reference_no)}</div>` : ''}
                    ${expense.approved_by_name ? `<div><strong>Approved By:</strong> ${escapeHtml(expense.approved_by_name)}</div>` : ''}
                    <div style="margin-top:8px;"><strong>Description:</strong><div style="margin-top:6px;color:#333;">${escapeHtml(expense.description || 'No description')}</div></div>
                    <div style="margin-top:8px;"><strong>Created:</strong> ${new Date(expense.created_at).toLocaleDateString()}</div>
                `;

                const editBtn = document.getElementById('viewExpenseToEditBtn');
                editBtn.onclick = function() { closeModal('viewExpenseModal'); editExpense(expenseId); };

                openModal('viewExpenseModal');
            })
            .catch(err => {
                console.error(err);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Error loading expense details'
                });
            });
        }

        // Edit expense
        function editExpense(expenseId) {
            fetch(window.location.pathname + '?ajax=details&type=expense&id=' + encodeURIComponent(expenseId), { 
                credentials: 'same-origin' 
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.error || 'Failed to load expense'
                    });
                    return;
                }
                const expense = data.data;
                document.getElementById('expense_id').value = expense.id;
                document.getElementById('expense_type').value = expense.expense_type || '';
                document.getElementById('expense_amount').value = expense.amount || '';
                document.getElementById('expense_date').value = expense.expense_date || '';
                document.getElementById('paid_to').value = expense.paid_to || '';
                document.getElementById('payment_method').value = expense.payment_method || 'cash';
                document.getElementById('reference_no').value = expense.reference_no || '';
                document.getElementById('expense_description').value = expense.description || '';
                
                document.querySelector('#addExpenseModal h3').textContent = 'Edit Expense';
                openModal('addExpenseModal');
            })
            .catch(err => {
                console.error(err);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Error loading expense for edit'
                });
            });
        }

        // Simple HTML escaper
        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/[&<>\"']/g, function (s) {
                return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"})[s];
            });
        }

        // Handle fee form submit via AJAX
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize toggle
            toggleClassField();
            
            const feeForm = document.getElementById('addFeeForm');
            if (feeForm) {
                feeForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    Swal.fire({
                        title: 'Save Fee',
                        text: 'Are you sure you want to save this fee?',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: '#3085d6',
                        cancelButtonColor: '#d33',
                        confirmButtonText: 'Yes, save it!',
                        cancelButtonText: 'Cancel'
                    }).then((result) => {
                        if (!result.isConfirmed) return;
                        
                        const formData = new FormData(feeForm);
                        formData.append('ajax_action', 'save_fee');

                        fetch(window.location.pathname, {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin'
                        })
                        .then(r => r.json())
                        .then(resp => {
                            if (resp.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Success',
                                    text: resp.message || 'Fee saved successfully!',
                                    timer: 1500,
                                    showConfirmButton: false
                                }).then(() => {
                                    closeModal('addFeeModal');
                                    window.location.reload();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: resp.error || 'Failed to save fee'
                                });
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Error saving fee'
                            });
                        });
                    });
                });
            }

            // Handle assign fee form submit via AJAX
            const assignFeeForm = document.getElementById('assignFeeForm');
            if (assignFeeForm) {
                assignFeeForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    Swal.fire({
                        title: 'Assign Fee',
                        text: 'Are you sure you want to assign this fee to the student?',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: '#3085d6',
                        cancelButtonColor: '#d33',
                        confirmButtonText: 'Yes, assign it!',
                        cancelButtonText: 'Cancel'
                    }).then((result) => {
                        if (!result.isConfirmed) return;
                        
                        const formData = new FormData(assignFeeForm);
                        formData.append('ajax_action', 'assign_student_fee');

                        fetch(window.location.pathname, {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin'
                        })
                        .then(r => r.json())
                        .then(resp => {
                            if (resp.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Success',
                                    text: resp.message || 'Fee assigned successfully!',
                                    timer: 1500,
                                    showConfirmButton: false
                                }).then(() => {
                                    closeModal('assignFeeModal');
                                    window.location.reload();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: resp.error || 'Failed to assign fee'
                                });
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Error assigning fee'
                            });
                        });
                    });
                });
            }

            // Handle expense form submit via AJAX
            const expenseForm = document.getElementById('addExpenseForm');
            if (expenseForm) {
                expenseForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    Swal.fire({
                        title: 'Save Expense',
                        text: 'Are you sure you want to save this expense?',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: '#3085d6',
                        cancelButtonColor: '#d33',
                        confirmButtonText: 'Yes, save it!',
                        cancelButtonText: 'Cancel'
                    }).then((result) => {
                        if (!result.isConfirmed) return;
                        
                        const formData = new FormData(expenseForm);
                        formData.append('ajax_action', 'save_expense');

                        fetch(window.location.pathname, {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin'
                        })
                        .then(r => r.json())
                        .then(resp => {
                            if (resp.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Success',
                                    text: resp.message || 'Expense saved successfully!',
                                    timer: 1500,
                                    showConfirmButton: false
                                }).then(() => {
                                    closeModal('addExpenseModal');
                                    window.location.reload();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: resp.error || 'Failed to save expense'
                                });
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Error saving expense'
                            });
                        });
                    });
                });
            }
            
            // Update default amount when fee selection changes
            const feeSelect = document.getElementById('assign_fee_id');
            if (feeSelect) {
                feeSelect.addEventListener('change', updateDefaultAmount);
            }
        });

        // Reset form when opening modal for adding new
        document.getElementById('addFeeModal').addEventListener('show', function() {
            if (document.getElementById('fee_id').value === '0') {
                document.getElementById('addFeeForm').reset();
                document.querySelector('#addFeeModal h3').textContent = 'Add Fee';
                document.getElementById('fee_id').value = '0';
                document.getElementById('is_published').value = '0';
                document.getElementById('is_active').value = '1';
                document.getElementById('fee_type').value = 'general';
                toggleClassField();
                const today = new Date();
                const nextMonth = new Date(today.getFullYear(), today.getMonth() + 1, today.getDate());
                document.getElementById('due_date').valueAsDate = nextMonth;
            }
        });

        document.getElementById('assignFeeModal').addEventListener('show', function() {
            document.getElementById('assignFeeForm').reset();
            updateDefaultAmount();
        });

        document.getElementById('addExpenseModal').addEventListener('show', function() {
            if (document.getElementById('expense_id').value === '0') {
                document.getElementById('addExpenseForm').reset();
                document.querySelector('#addExpenseModal h3').textContent = 'Add Expense';
                document.getElementById('expense_id').value = '0';
                document.getElementById('expense_date').valueAsDate = new Date();
                document.getElementById('payment_method').value = 'cash';
            }
        });

        // Delete confirmation functions
        function confirmDeleteFee(form) {
            event.preventDefault();
            Swal.fire({
                title: 'Delete Fee',
                text: 'Are you sure you want to delete this fee? This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
            return false;
        }

        function confirmDeleteStudentFee(form) {
            event.preventDefault();
            Swal.fire({
                title: 'Remove Fee Assignment',
                text: 'Are you sure you want to remove this fee from the student?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, remove it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
            return false;
        }

        function confirmDeleteExpense(form) {
            event.preventDefault();
            Swal.fire({
                title: 'Delete Expense',
                text: 'Are you sure you want to delete this expense? This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
            return false;
        }

        // Report functions
        function generateFinanceReport() {
            const params = new URLSearchParams(window.location.search);
            params.set('report', '1');
            const url = window.location.pathname + '?' + params.toString();
            window.open(url, '_blank');
        }

        function printFeesReport() {
            const params = new URLSearchParams(window.location.search);
            params.set('tab', 'fees');
            params.set('report', '1');
            const url = window.location.pathname + '?' + params.toString();
            window.open(url, '_blank');
        }

        function printStudentFeesReport() {
            const params = new URLSearchParams(window.location.search);
            params.set('tab', 'studentFees');
            params.set('report', '1');
            const url = window.location.pathname + '?' + params.toString();
            window.open(url, '_blank');
        }

        function printExpensesReport() {
            const params = new URLSearchParams(window.location.search);
            params.set('tab', 'expenses');
            params.set('report', '1');
            const url = window.location.pathname + '?' + params.toString();
            window.open(url, '_blank');
        }

        function printPaymentsReport() {
            const params = new URLSearchParams(window.location.search);
            params.set('tab', 'payments');
            params.set('report', '1');
            const url = window.location.pathname + '?' + params.toString();
            window.open(url, '_blank');
        }
        
        // Initialize date fields
        document.addEventListener('DOMContentLoaded', function() {
            // Set today's date as default for expense date
            if (document.getElementById('expense_date')) {
                document.getElementById('expense_date').valueAsDate = new Date();
            }
            
            // Set due date to next month by default
            if (document.getElementById('due_date')) {
                const today = new Date();
                const nextMonth = new Date(today.getFullYear(), today.getMonth() + 1, today.getDate());
                document.getElementById('due_date').valueAsDate = nextMonth;
            }
        });
    </script>
</body>
</html>