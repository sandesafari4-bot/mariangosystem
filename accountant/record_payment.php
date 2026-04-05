<?php
include '../config.php';
require_once '../finance_accounts_helpers.php';
checkAuth();
checkRole(['accountant', 'admin']);
financeEnsureSchema($pdo);

$page_title = 'Record Payment - ' . SCHOOL_NAME;

// Get parameters
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;

// Get students for dropdown
$students = $pdo->query("
    SELECT s.id, s.full_name, s.Admission_number, c.class_name
    FROM students s
    LEFT JOIN classes c ON s.class_id = c.id
    WHERE s.status = 'active'
    ORDER BY s.full_name
")->fetchAll();

// Get payment methods
$payment_methods = $pdo->query("
    SELECT id, code, label FROM payment_methods ORDER BY code
")->fetchAll();

$payment_method_code_map = [];
foreach ($payment_methods as $methodRow) {
    $payment_method_code_map[(int) $methodRow['id']] = $methodRow['code'];
}

// If no payment methods exist, create defaults
if (empty($payment_methods)) {
    $pdo->exec("
        INSERT INTO payment_methods (code, label) VALUES
        ('cash', 'Cash'),
        ('mpesa', 'M-Pesa'),
        ('bank_transfer', 'Bank Transfer'),
        ('cheque', 'Cheque')
    ");
    $payment_methods = $pdo->query("SELECT id, code, label FROM payment_methods ORDER BY code")->fetchAll();
    $payment_method_code_map = [];
    foreach ($payment_methods as $methodRow) {
        $payment_method_code_map[(int) $methodRow['id']] = $methodRow['code'];
    }
}

// Get student details if selected
$selected_student = null;
$unpaid_invoices = [];
if ($student_id) {
    $stmt = $pdo->prepare("
        SELECT s.*, c.class_name 
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE s.id = ?
    ");
    $stmt->execute([$student_id]);
    $selected_student = $stmt->fetch();
    
    // Normalize admission_number column name
    if ($selected_student && isset($selected_student['Admission_number'])) {
        $selected_student['admission_number'] = $selected_student['Admission_number'];
    }
    
    // Get unpaid invoices for this student
    $stmt = $pdo->prepare("
        SELECT 
            i.*,
            fs.structure_name,
            DATEDIFF(CURDATE(), i.due_date) as days_overdue
        FROM invoices i
        LEFT JOIN fee_structures fs ON i.fee_structure_id = fs.id
        WHERE i.student_id = ? AND i.status IN ('unpaid', 'partial')
        ORDER BY i.due_date ASC
    ");
    $stmt->execute([$student_id]);
    $unpaid_invoices = $stmt->fetchAll();
}

// Get invoice details if selected
$selected_invoice = null;
if ($invoice_id) {
    $stmt = $pdo->prepare("
        SELECT i.*, s.full_name, s.Admission_number, s.class_id, c.class_name
        FROM invoices i
        JOIN students s ON i.student_id = s.id
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE i.id = ?
    ");
    $stmt->execute([$invoice_id]);
    $selected_invoice = $stmt->fetch();
    
    // Normalize admission_number column name
    if ($selected_invoice && isset($selected_invoice['Admission_number'])) {
        $selected_invoice['admission_number'] = $selected_invoice['Admission_number'];
    }
    
    if ($selected_invoice) {
        $student_id = $selected_invoice['student_id'];
        // Refresh unpaid invoices
        $stmt = $pdo->prepare("
            SELECT * FROM invoices 
            WHERE student_id = ? AND status IN ('unpaid', 'partial')
            ORDER BY due_date ASC
        ");
        $stmt->execute([$student_id]);
        $unpaid_invoices = $stmt->fetchAll();
    }
}

$mpesa_shortcode = getSystemSetting('mpesa_shortcode', MPESA_SHORTCODE);
$mpesa_account_reference_hint = getSystemSetting('mpesa_account_reference', '{admission_number}');

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'get_student_invoices' && isset($_GET['student_id'])) {
        $sid = intval($_GET['student_id']);
        
        $stmt = $pdo->prepare("
            SELECT 
                i.*,
                fs.structure_name,
                DATEDIFF(CURDATE(), i.due_date) as days_overdue
            FROM invoices i
            LEFT JOIN fee_structures fs ON i.fee_structure_id = fs.id
            WHERE i.student_id = ? AND i.status IN ('unpaid', 'partial')
            ORDER BY i.due_date ASC
        ");
        $stmt->execute([$sid]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get student info
        $stmt = $pdo->prepare("SELECT full_name, Admission_number FROM students WHERE id = ?");
        $stmt->execute([$sid]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($student) {
            $student['admission_number'] = $student['Admission_number'];
        }
        
        echo json_encode([
            'success' => true,
            'student' => $student,
            'invoices' => $invoices
        ]);
        exit;
    }
    
    if ($_GET['ajax'] === 'get_invoice_details' && isset($_GET['invoice_id'])) {
        $iid = intval($_GET['invoice_id']);
        
        $stmt = $pdo->prepare("
            SELECT i.*, fs.structure_name
            FROM invoices i
            LEFT JOIN fee_structures fs ON i.fee_structure_id = fs.id
            WHERE i.id = ?
        ");
        $stmt->execute([$iid]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($invoice) {
            $result = [
                'id' => $invoice['id'],
                'invoice_number' => $invoice['invoice_number'],
                'amount_due' => $invoice['amount_due'],
                'amount_paid' => $invoice['amount_paid'],
                'balance' => $invoice['balance'],
                'due_date' => $invoice['due_date'],
                'structure_name' => $invoice['structure_name'],
                'items' => []
            ];
            
            echo json_encode(['success' => true, 'invoice' => $result]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invoice not found']);
        }
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    $response = ['success' => false, 'message' => ''];
    
    try {
        $pdo->beginTransaction();
        
        $student_id = intval($_POST['student_id']);
        $invoice_id = intval($_POST['invoice_id']);
        $amount = floatval($_POST['amount']);
        $payment_date = $_POST['payment_date'];
        $payment_method_id = intval($_POST['payment_method_id']);
        $reference = trim($_POST['reference'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        // Validation
        if ($amount <= 0) {
            throw new Exception('Amount must be greater than zero');
        }
        
        // Get invoice details
        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND student_id = ? FOR UPDATE");
        $stmt->execute([$invoice_id, $student_id]);
        $invoice = $stmt->fetch();
        
        if (!$invoice) {
            throw new Exception('Invoice not found');
        }
        
        if ($amount > $invoice['balance']) {
            throw new Exception('Payment amount exceeds remaining balance of KES ' . number_format($invoice['balance'], 2));
        }
        
        // Generate a human-readable transaction reference when supported by the schema
        $payment_id_unique = 'PAY' . date('YmdHis') . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);

        // Map payment_method_id to payment_method enum value for schemas that store a code instead of a foreign key
        $payment_method = $payment_method_code_map[$payment_method_id] ?? 'other';

        // Support both legacy and newer payment table variants.
        $payment_columns = $pdo->query("SHOW COLUMNS FROM payments")->fetchAll(PDO::FETCH_COLUMN);
        $payment_column_lookup = array_fill_keys($payment_columns, true);
        $insert_columns = [];
        $insert_placeholders = [];
        $insert_values = [];

        $set_payment_value = function ($column, $value) use (&$insert_columns, &$insert_placeholders, &$insert_values, $payment_column_lookup) {
            if (isset($payment_column_lookup[$column])) {
                $insert_columns[] = $column;
                $insert_placeholders[] = '?';
                $insert_values[] = $value;
            }
        };

        $set_payment_value('payment_id', $payment_id_unique);
        $set_payment_value('transaction_id', $payment_id_unique);
        $set_payment_value('invoice_id', $invoice_id);
        $set_payment_value('student_id', $student_id);
        $set_payment_value('amount', $amount);
        $set_payment_value('payment_method_id', $payment_method_id);
        $set_payment_value('payment_method', $payment_method);
        $set_payment_value('transaction_ref', $reference);
        $set_payment_value('reference_no', $reference);
        $set_payment_value('reference', $reference);
        $set_payment_value('mpesa_receipt', $payment_method === 'mpesa' ? $reference : null);
        $set_payment_value('notes', $notes);
        $set_payment_value('status', 'completed');
        $set_payment_value('recorded_by', $_SESSION['user_id']);
        $set_payment_value('created_by', $_SESSION['user_id']);
        $set_payment_value('payment_date', $payment_date);
        $set_payment_value('paid_at', $payment_date . ' ' . date('H:i:s'));

        $stmt = $pdo->prepare("
            INSERT INTO payments (" . implode(', ', $insert_columns) . ")
            VALUES (" . implode(', ', $insert_placeholders) . ")
        ");

        $stmt->execute($insert_values);
        
        $payment_db_id = $pdo->lastInsertId();
        
        // Update invoice
        $new_paid = $invoice['amount_paid'] + $amount;
        $invoice_total = $invoice['amount_due'] ?? $invoice['total_amount'] ?? 0;
        $new_balance = $invoice_total - $new_paid;
        $new_status = $new_balance <= 0 ? 'paid' : 'partial';
        
        $stmt = $pdo->prepare("
            UPDATE invoices 
            SET amount_paid = ?, balance = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([$new_paid, $new_balance, $new_status, $invoice_id]);
        
        // Log the transaction
        $payment_logs_exists = (bool) $pdo->query("SHOW TABLES LIKE 'payment_logs'")->fetchColumn();
        if ($payment_logs_exists) {
            $stmt = $pdo->prepare("
                INSERT INTO payment_logs (payment_id, action, details, created_at)
                VALUES (?, 'recorded', ?, NOW())
            ");
            $stmt->execute([$payment_db_id, "Payment of KES " . number_format($amount, 2) . " recorded"]);
        }

        financeRecordStudentCollection(
            $pdo,
            $amount,
            $payment_method,
            $reference !== '' ? $reference : $payment_id_unique,
            (int) $payment_db_id,
            $student_id,
            (int) $_SESSION['user_id']
        );
        
        $pdo->commit();
        
        $_SESSION['success'] = "Payment of KES " . number_format($amount, 2) . " recorded successfully!";
        $_SESSION['payment_id'] = $payment_db_id;
        $_SESSION['payment_ref'] = $payment_id_unique;
        
        $response['success'] = true;
        $response['message'] = "Payment recorded successfully!";
        $response['payment_id'] = $payment_db_id;
        $response['payment_ref'] = $payment_id_unique;
        
        if (isset($_POST['ajax'])) {
            echo json_encode($response);
            exit;
        } else {
            header("Location: payment_receipt.php?id=" . $payment_db_id);
            exit;
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
        
        $response['message'] = $e->getMessage();
        
        if (isset($_POST['ajax'])) {
            echo json_encode($response);
            exit;
        } else {
            header("Location: record_payment.php" . ($student_id ? "?student_id=$student_id" : ""));
            exit;
        }
    }
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

        /* Payment Layout */
        .payment-layout {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 2rem;
        }

        @media (max-width: 1024px) {
            .payment-layout {
                grid-template-columns: 1fr;
            }
        }

        /* Payment Form Card */
        .payment-card {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }

        .card-header {
            padding: 1.5rem;
            background: var(--gradient-1);
            color: white;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header h2 {
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0;
        }

        .card-body {
            padding: 2rem;
        }

        /* Student Selection */
        .student-selector {
            margin-bottom: 2rem;
        }

        .selector-label {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .student-search {
            position: relative;
        }

        .student-search i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        .student-search select {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-md);
            font-size: 1rem;
            appearance: none;
            background: white;
            cursor: pointer;
        }

        .student-search select:focus {
            border-color: var(--primary);
            outline: none;
        }

        /* Selected Student Info */
        .student-info-card {
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.05), rgba(114, 9, 183, 0.05));
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border-left: 4px solid var(--primary);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .student-details h3 {
            font-size: 1.2rem;
            color: var(--dark);
            margin-bottom: 0.3rem;
        }

        .student-details p {
            color: var(--gray);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0.2rem 0;
        }

        .student-details i {
            width: 18px;
            color: var(--primary);
        }

        .balance-summary {
            text-align: right;
        }

        .balance-label {
            font-size: 0.85rem;
            color: var(--gray);
            text-transform: uppercase;
        }

        .balance-amount {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--danger);
        }

        /* Invoice Selection */
        .invoice-list {
            margin: 1.5rem 0;
            max-height: 300px;
            overflow-y: auto;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-lg);
            padding: 0.5rem;
        }

        .invoice-item {
            padding: 1rem;
            border-bottom: 1px solid var(--light);
            cursor: pointer;
            transition: var(--transition);
            border-radius: var(--border-radius-md);
            margin-bottom: 0.3rem;
        }

        .invoice-item:hover {
            background: rgba(67, 97, 238, 0.05);
        }

        .invoice-item.selected {
            background: rgba(76, 201, 240, 0.1);
            border: 2px solid var(--success);
        }

        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .invoice-no {
            font-weight: 700;
            color: var(--primary);
        }

        .invoice-status {
            font-size: 0.75rem;
            padding: 0.2rem 0.8rem;
            border-radius: 20px;
        }

        .status-unpaid {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        .status-partial {
            background: rgba(114, 9, 183, 0.15);
            color: var(--purple);
        }

        .invoice-details {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.9rem;
        }

        .invoice-amount {
            font-weight: 600;
            color: var(--dark);
        }

        .invoice-due {
            color: var(--gray);
        }

        .invoice-due.overdue {
            color: var(--danger);
        }

        .invoice-balance {
            font-weight: 700;
            color: var(--danger);
        }

        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin: 1.5rem 0;
        }

        .form-group {
            margin-bottom: 1rem;
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
            border-radius: var(--border-radius-md);
            font-size: 1rem;
            transition: var(--transition);
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .form-control[readonly] {
            background: var(--light);
            cursor: not-allowed;
        }

        .input-group {
            display: flex;
            align-items: center;
        }

        .input-group-text {
            padding: 0.75rem 1rem;
            background: var(--light);
            border: 2px solid var(--light);
            border-right: none;
            border-radius: var(--border-radius-md) 0 0 var(--border-radius-md);
            font-weight: 600;
            color: var(--dark);
        }

        .input-group .form-control {
            border-left: none;
            border-radius: 0 var(--border-radius-md) var(--border-radius-md) 0;
        }

        /* Payment Summary */
        .payment-summary {
            background: var(--light);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin: 1.5rem 0;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px dashed var(--gray-light);
        }

        .summary-row:last-child {
            border-bottom: none;
        }

        .summary-label {
            color: var(--gray);
        }

        .summary-value {
            font-weight: 600;
            color: var(--dark);
        }

        .summary-total {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--success);
            margin-top: 0.5rem;
            padding-top: 0.5rem;
            border-top: 2px solid var(--gray-light);
        }

        /* Invoice Details Sidebar */
        .invoice-details-card {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            position: sticky;
            top: 90px;
        }

        .details-header {
            padding: 1.5rem;
            background: var(--gradient-1);
            color: white;
        }

        .details-header h3 {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
        }

        .details-body {
            padding: 1.5rem;
        }

        .invoice-meta {
            margin-bottom: 1.5rem;
        }

        .meta-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--light);
        }

        .meta-label {
            color: var(--gray);
        }

        .meta-value {
            font-weight: 600;
            color: var(--dark);
        }

        .items-list {
            max-height: 300px;
            overflow-y: auto;
            margin: 1rem 0;
        }

        .item-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px dashed var(--light);
            font-size: 0.9rem;
        }

        .item-name {
            color: var(--dark);
        }

        .item-name i {
            color: var(--warning);
            font-size: 0.7rem;
            margin-left: 0.3rem;
        }

        .item-amount {
            font-weight: 600;
            color: var(--primary);
        }

        .no-selection {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
        }

        .no-selection i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius-md);
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
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

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        .btn-lg {
            padding: 1rem 2rem;
            font-size: 1.1rem;
        }

        .btn-block {
            width: 100%;
            justify-content: center;
        }

        /* M-Pesa Section */
        .mpesa-section {
            background: linear-gradient(135deg, rgba(114, 9, 183, 0.05), rgba(155, 89, 182, 0.05));
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin: 1.5rem 0;
            border-left: 4px solid var(--purple);
        }

        .mpesa-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .mpesa-icon {
            width: 40px;
            height: 40px;
            background: var(--purple);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }

        .mpesa-title {
            font-weight: 600;
            color: var(--purple);
        }

        .mpesa-instruction {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 1rem;
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

        /* Loading Overlay */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.8);
            backdrop-filter: blur(3px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        .loading-overlay.active {
            display: flex;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 3px solid var(--light);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .student-info-card {
                flex-direction: column;
                text-align: center;
            }
            
            .balance-summary {
                text-align: center;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
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
            <h1><i class="fas fa-credit-card"></i> Record Payment</h1>
            <p>Process student fee payments and generate receipts</p>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success animate">
            <div>
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; ?>
                <?php if (isset($_SESSION['transaction_id'])): ?>
                <br><small>Transaction ID: <?php echo $_SESSION['transaction_id']; ?></small>
                <?php unset($_SESSION['transaction_id']); endif; ?>
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

        <!-- Payment Layout -->
        <div class="payment-layout">
            <!-- Payment Form -->
            <div class="payment-card animate">
                <div class="card-header">
                    <i class="fas fa-money-bill-wave"></i>
                    <h2>Payment Details</h2>
                </div>
                
                <div class="card-body">
                    <form id="paymentForm" method="POST">
                        <input type="hidden" name="record_payment" value="1">
                        
                        <!-- Student Selection -->
                        <div class="student-selector">
                            <div class="selector-label">
                                <i class="fas fa-user-graduate"></i>
                                Select Student
                            </div>
                            <div class="student-search">
                                <i class="fas fa-search"></i>
                                <select name="student_id" id="studentSelect" required onchange="loadStudentInvoices()">
                                    <option value="">-- Choose Student --</option>
                                    <?php foreach ($students as $student): ?>
                                    <option value="<?php echo $student['id']; ?>" 
                                            <?php echo $student_id == $student['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($student['full_name'] . ' (' . $student['Admission_number'] . ') - ' . $student['class_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <?php if ($selected_student): ?>
                        <!-- Selected Student Info -->
                        <div class="student-info-card">
                            <div class="student-details">
                                <h3><?php echo htmlspecialchars($selected_student['full_name']); ?></h3>
                                <p><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($selected_student['Admission_number']); ?></p>
                                <p><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($selected_student['class_name']); ?></p>
                                <?php if ($selected_student['parent_name']): ?>
                                <p><i class="fas fa-user"></i> <?php echo htmlspecialchars($selected_student['parent_name']); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="balance-summary">
                                <div class="balance-label">Total Outstanding</div>
                                <div class="balance-amount" id="totalOutstanding">
                                    KES <?php echo number_format(array_sum(array_column($unpaid_invoices, 'balance')), 2); ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Invoice Selection -->
                        <div class="form-group">
                            <label class="required">Select Invoice</label>
                            <div id="invoiceList" class="invoice-list">
                                <?php if (!empty($unpaid_invoices)): ?>
                                    <?php foreach ($unpaid_invoices as $invoice): 
                                        $is_overdue = strtotime($invoice['due_date']) < time();
                                    ?>
                                    <div class="invoice-item <?php echo $invoice_id == $invoice['id'] ? 'selected' : ''; ?>" 
                                         onclick="selectInvoice(<?php echo $invoice['id']; ?>)"
                                         data-id="<?php echo $invoice['id']; ?>"
                                         data-balance="<?php echo $invoice['balance']; ?>"
                                         data-total="<?php echo $invoice['total_amount']; ?>"
                                         data-paid="<?php echo $invoice['amount_paid']; ?>">
                                        <div class="invoice-header">
                                            <span class="invoice-no">#<?php echo htmlspecialchars($invoice['invoice_no']); ?></span>
                                            <span class="invoice-status status-<?php echo $invoice['status']; ?>">
                                                <?php echo ucfirst($invoice['status']); ?>
                                            </span>
                                        </div>
                                        <div class="invoice-details">
                                            <span class="invoice-amount">KES <?php echo number_format($invoice['total_amount'], 2); ?></span>
                                            <span class="invoice-due <?php echo $is_overdue ? 'overdue' : ''; ?>">
                                                <i class="fas fa-calendar"></i> Due: <?php echo date('d M Y', strtotime($invoice['due_date'])); ?>
                                            </span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; margin-top: 0.5rem;">
                                            <span>Paid: KES <?php echo number_format($invoice['amount_paid'], 2); ?></span>
                                            <span class="invoice-balance">Balance: KES <?php echo number_format($invoice['balance'], 2); ?></span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="text-align: center; padding: 2rem; color: var(--gray);">
                                        <i class="fas fa-check-circle fa-2x" style="margin-bottom: 0.5rem;"></i>
                                        <p>No unpaid invoices for this student</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="invoice_id" id="selectedInvoiceId" value="<?php echo $invoice_id; ?>" required>
                        </div>

                        <!-- Payment Form Grid -->
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="required">Payment Date</label>
                                <input type="date" name="payment_date" class="form-control" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="required">Payment Method</label>
                                <select name="payment_method_id" id="paymentMethod" class="form-control" required onchange="toggleMpesa()">
                                    <?php foreach ($payment_methods as $method): ?>
                                    <option value="<?php echo $method['id']; ?>" data-code="<?php echo $method['code']; ?>">
                                        <?php echo htmlspecialchars($method['label']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group full-width">
                                <label class="required">Amount</label>
                                <div class="input-group">
                                    <span class="input-group-text">KES</span>
                                    <input type="number" name="amount" id="paymentAmount" class="form-control" 
                                           step="0.01" min="0.01" required oninput="updateSummary()">
                                </div>
                                <small id="maxAmountHint" style="color: var(--gray);">Maximum: KES <span id="maxAmount">0.00</span></small>
                            </div>
                            
                            <div class="form-group">
                                <label>Reference Number</label>
                                <input type="text" name="reference" class="form-control" 
                                       placeholder="Transaction ID / Cheque No.">
                            </div>
                            
                            <div class="form-group full-width">
                                <label>Notes</label>
                                <textarea name="notes" class="form-control" rows="2" 
                                          placeholder="Additional payment notes..."></textarea>
                            </div>
                        </div>

                        <!-- M-Pesa Section (hidden by default) -->
                        <div id="mpesaSection" class="mpesa-section" style="display: none;">
                            <div class="mpesa-header">
                                <div class="mpesa-icon">
                                    <i class="fas fa-mobile-alt"></i>
                                </div>
                                <div class="mpesa-title">M-Pesa Payment</div>
                            </div>
                            <div class="mpesa-instruction">
                                Use STK Push to prompt the phone directly, or record a completed paybill transaction using the M-Pesa receipt number.
                            </div>
                            <div class="form-group">
                                <label>Paybill / Shortcode</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($mpesa_shortcode); ?>" readonly>
                                <small>Configured from system settings.</small>
                            </div>
                            <div class="form-group">
                                <label>Account Reference Hint</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($mpesa_account_reference_hint); ?>" readonly>
                                <small>Typical values include admission number or invoice number depending on your settings.</small>
                            </div>
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="text" id="mpesaPhone" class="form-control" 
                                       placeholder="254XXXXXXXXX" maxlength="12">
                                <small>Format: 254 followed by 9 digits</small>
                            </div>
                            <div class="form-group">
                                <label>M-Pesa Receipt Number</label>
                                <input type="text" id="mpesaReceipt" class="form-control" 
                                       placeholder="e.g. SBX3ABC123">
                                <small>Use this when the parent pays through paybill and you want to post the payment manually.</small>
                            </div>
                            <div class="action-buttons" style="margin-top: 1rem;">
                                <button type="button" class="btn btn-primary btn-lg" onclick="processMpesa()">
                                    <i class="fas fa-paper-plane"></i> Send STK Push
                                </button>
                                <button type="button" class="btn btn-outline btn-lg" onclick="recordMpesaPaybill()">
                                    <i class="fas fa-receipt"></i> Record Paybill Payment
                                </button>
                            </div>
                        </div>

                        <!-- Payment Summary -->
                        <div class="payment-summary">
                            <div class="summary-row">
                                <span class="summary-label">Invoice Total:</span>
                                <span class="summary-value" id="summaryTotal">KES 0.00</span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Already Paid:</span>
                                <span class="summary-value" id="summaryPaid">KES 0.00</span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Remaining Balance:</span>
                                <span class="summary-value" id="summaryBalance">KES 0.00</span>
                            </div>
                            <div class="summary-total">
                                <span>Payment Amount:</span>
                                <span id="summaryPayment">KES 0.00</span>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="action-buttons">
                            <button type="button" class="btn btn-outline btn-lg" onclick="window.history.back()">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button type="submit" class="btn btn-success btn-lg" id="submitBtn">
                                <i class="fas fa-check"></i> Record Payment
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Invoice Details Sidebar -->
            <div class="invoice-details-card animate">
                <div class="details-header">
                    <h3><i class="fas fa-file-invoice"></i> Invoice Details</h3>
                </div>
                <div class="details-body" id="invoiceDetails">
                    <?php if ($selected_invoice): ?>
                        <div class="invoice-meta">
                            <div class="meta-item">
                                <span class="meta-label">Invoice #:</span>
                                <span class="meta-value"><?php echo htmlspecialchars($selected_invoice['invoice_no']); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Due Date:</span>
                                <span class="meta-value"><?php echo date('d M Y', strtotime($selected_invoice['due_date'])); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Total Amount:</span>
                                <span class="meta-value">KES <?php echo number_format($selected_invoice['total_amount'], 2); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Paid Amount:</span>
                                <span class="meta-value">KES <?php echo number_format($selected_invoice['amount_paid'], 2); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Balance:</span>
                                <span class="meta-value" style="color: var(--danger);">KES <?php echo number_format($selected_invoice['balance'], 2); ?></span>
                            </div>
                        </div>
                        
                        <h4 style="margin: 1rem 0 0.5rem; color: var(--dark);">Fee Items</h4>
                        <div class="items-list">
                            <?php
                            $stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
                            $stmt->execute([$invoice_id]);
                            $items = $stmt->fetchAll();
                            ?>
                            <?php foreach ($items as $item): ?>
                            <div class="item-row">
                                <span class="item-name">
                                    <?php echo htmlspecialchars($item['item_name']); ?>
                                    <?php if ($item['is_mandatory']): ?>
                                    <i class="fas fa-star" title="Mandatory"></i>
                                    <?php endif; ?>
                                </span>
                                <span class="item-amount">KES <?php echo number_format($item['amount'], 2); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-selection">
                            <i class="fas fa-file-invoice"></i>
                            <h4>No Invoice Selected</h4>
                            <p>Select a student and invoice to view details</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-spinner"></div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        let selectedInvoiceBalance = <?php echo $selected_invoice['balance'] ?? 0; ?>;
        let selectedInvoiceTotal = <?php echo $selected_invoice['total_amount'] ?? 0; ?>;
        let selectedInvoicePaid = <?php echo $selected_invoice['amount_paid'] ?? 0; ?>;

        // Load student invoices via AJAX
        function loadStudentInvoices() {
            const studentId = document.getElementById('studentSelect').value;
            
            if (!studentId) {
                window.location.href = 'record_payment.php';
                return;
            }
            
            window.location.href = 'record_payment.php?student_id=' + studentId;
        }

        // Select invoice
        function selectInvoice(invoiceId) {
            // Update UI
            document.querySelectorAll('.invoice-item').forEach(item => {
                item.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
            
            document.getElementById('selectedInvoiceId').value = invoiceId;
            
            // Get invoice data
            const balance = parseFloat(event.currentTarget.dataset.balance);
            const total = parseFloat(event.currentTarget.dataset.total);
            const paid = parseFloat(event.currentTarget.dataset.paid);
            
            selectedInvoiceBalance = balance;
            selectedInvoiceTotal = total;
            selectedInvoicePaid = paid;
            
            // Update max amount
            document.getElementById('maxAmount').textContent = balance.toFixed(2);
            document.getElementById('paymentAmount').max = balance;
            document.getElementById('paymentAmount').value = balance;
            
            // Update summary
            updateSummary();
            
            // Load invoice details via AJAX
            loadInvoiceDetails(invoiceId);
        }

        // Load invoice details
        function loadInvoiceDetails(invoiceId) {
            document.getElementById('loadingOverlay').classList.add('active');
            
            fetch(`record_payment.php?ajax=get_invoice_details&invoice_id=${invoiceId}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('loadingOverlay').classList.remove('active');
                    
                    if (data.success) {
                        displayInvoiceDetails(data.invoice);
                    } else {
                        Swal.fire('Error', data.message, 'error');
                    }
                })
                .catch(error => {
                    document.getElementById('loadingOverlay').classList.remove('active');
                    Swal.fire('Error', 'Failed to load invoice details', 'error');
                });
        }

        // Display invoice details in sidebar
        function displayInvoiceDetails(invoice) {
            const detailsDiv = document.getElementById('invoiceDetails');
            
            let itemsHtml = '';
            invoice.items.forEach(item => {
                itemsHtml += `
                    <div class="item-row">
                        <span class="item-name">
                            ${escapeHtml(item.item_name)}
                            ${item.is_mandatory ? '<i class="fas fa-star" title="Mandatory"></i>' : ''}
                        </span>
                        <span class="item-amount">KES ${formatNumber(item.amount)}</span>
                    </div>
                `;
            });
            
            detailsDiv.innerHTML = `
                <div class="invoice-meta">
                    <div class="meta-item">
                        <span class="meta-label">Invoice #:</span>
                        <span class="meta-value">${escapeHtml(invoice.invoice_no)}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Due Date:</span>
                        <span class="meta-value">${new Date(invoice.due_date).toLocaleDateString('en-US', {day: 'numeric', month: 'short', year: 'numeric'})}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Total Amount:</span>
                        <span class="meta-value">KES ${formatNumber(invoice.total_amount)}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Paid Amount:</span>
                        <span class="meta-value">KES ${formatNumber(invoice.amount_paid)}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Balance:</span>
                        <span class="meta-value" style="color: var(--danger);">KES ${formatNumber(invoice.balance)}</span>
                    </div>
                </div>
                <h4 style="margin: 1rem 0 0.5rem; color: var(--dark);">Fee Items</h4>
                <div class="items-list">
                    ${itemsHtml}
                </div>
            `;
        }

        // Update payment summary
        function updateSummary() {
            const amount = parseFloat(document.getElementById('paymentAmount').value) || 0;
            const maxAmount = selectedInvoiceBalance;
            
            document.getElementById('summaryTotal').textContent = 'KES ' + formatNumber(selectedInvoiceTotal);
            document.getElementById('summaryPaid').textContent = 'KES ' + formatNumber(selectedInvoicePaid);
            document.getElementById('summaryBalance').textContent = 'KES ' + formatNumber(selectedInvoiceBalance);
            document.getElementById('summaryPayment').textContent = 'KES ' + formatNumber(amount);
            
            // Validate amount
            if (amount > maxAmount) {
                document.getElementById('paymentAmount').style.borderColor = 'var(--danger)';
                document.getElementById('submitBtn').disabled = true;
            } else {
                document.getElementById('paymentAmount').style.borderColor = '';
                document.getElementById('submitBtn').disabled = false;
            }
        }

        // Toggle M-Pesa section
        function toggleMpesa() {
            const select = document.getElementById('paymentMethod');
            const selectedOption = select.options[select.selectedIndex];
            const code = selectedOption.dataset.code;
            
            const mpesaSection = document.getElementById('mpesaSection');
            const submitBtn = document.getElementById('submitBtn');
            
            if (code === 'mpesa') {
                mpesaSection.style.display = 'block';
                submitBtn.style.display = 'none';
            } else {
                mpesaSection.style.display = 'none';
                submitBtn.style.display = 'inline-flex';
            }
        }

        // Process M-Pesa payment
        function processMpesa() {
            const studentId = document.getElementById('studentSelect').value;
            const invoiceId = document.getElementById('selectedInvoiceId').value;
            const phone = document.getElementById('mpesaPhone').value.trim();
            const amount = document.getElementById('paymentAmount').value;
            
            if (!studentId || !invoiceId) {
                Swal.fire('Error', 'Please select student and invoice', 'error');
                return;
            }
            
            if (!phone || !/^254\d{9}$/.test(phone)) {
                Swal.fire('Error', 'Invalid phone number. Use format 254XXXXXXXXX', 'error');
                return;
            }
            
            if (!amount || amount <= 0 || amount > selectedInvoiceBalance) {
                Swal.fire('Error', 'Please enter a valid amount', 'error');
                return;
            }
            
            Swal.fire({
                title: 'Processing M-Pesa Payment',
                html: 'Sending STK push to ' + phone + '...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const formData = new URLSearchParams();
            formData.append('action', 'initiate_stk');
            formData.append('student_id', studentId);
            formData.append('invoice_id', invoiceId);
            formData.append('phone', phone);
            formData.append('amount', amount);

            fetch('mpesa_processor.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: formData.toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'STK Push Sent',
                        html: `${data.message}<br><small>Checkout ID: ${data.checkout_request_id || 'Pending'}</small>`
                    });
                } else {
                    Swal.fire('STK Push Failed', data.message || 'Unable to send STK push', 'error');
                }
            })
            .catch(() => {
                Swal.fire('Error', 'Could not contact the M-Pesa processor', 'error');
            });
        }

        function recordMpesaPaybill() {
            const studentId = document.getElementById('studentSelect').value;
            const invoiceId = document.getElementById('selectedInvoiceId').value;
            const phone = document.getElementById('mpesaPhone').value.trim();
            const receipt = document.getElementById('mpesaReceipt').value.trim();
            const amount = document.getElementById('paymentAmount').value;

            if (!studentId || !invoiceId) {
                Swal.fire('Error', 'Please select student and invoice', 'error');
                return;
            }

            if (!receipt) {
                Swal.fire('Error', 'Enter the M-Pesa receipt number', 'error');
                return;
            }

            if (!amount || amount <= 0 || amount > selectedInvoiceBalance) {
                Swal.fire('Error', 'Please enter a valid amount', 'error');
                return;
            }

            Swal.fire({
                title: 'Record Paybill Payment?',
                text: 'This will immediately post the paybill transaction to the invoice balance.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Record Payment'
            }).then(result => {
                if (!result.isConfirmed) {
                    return;
                }

                Swal.fire({
                    title: 'Recording Payment',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                const formData = new URLSearchParams();
                formData.append('action', 'record_paybill');
                formData.append('student_id', studentId);
                formData.append('invoice_id', invoiceId);
                formData.append('phone', phone);
                formData.append('receipt_number', receipt);
                formData.append('amount', amount);

                fetch('mpesa_processor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: formData.toString()
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Payment Recorded',
                            text: data.message
                        }).then(() => {
                            if (data.payment_id) {
                                window.location.href = 'payment_receipt.php?id=' + data.payment_id;
                            } else {
                                window.location.reload();
                            }
                        });
                    } else {
                        Swal.fire('Unable to Record Payment', data.message || 'The paybill payment could not be posted.', 'error');
                    }
                })
                .catch(() => {
                    Swal.fire('Error', 'Could not contact the M-Pesa processor', 'error');
                });
            });
        }

        // Form submission
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const studentId = document.getElementById('studentSelect').value;
            const invoiceId = document.getElementById('selectedInvoiceId').value;
            const amount = parseFloat(document.getElementById('paymentAmount').value);
            
            if (!studentId) {
                Swal.fire('Error', 'Please select a student', 'error');
                return;
            }
            
            if (!invoiceId) {
                Swal.fire('Error', 'Please select an invoice', 'error');
                return;
            }
            
            if (!amount || amount <= 0) {
                Swal.fire('Error', 'Please enter a valid amount', 'error');
                return;
            }
            
            if (amount > selectedInvoiceBalance) {
                Swal.fire('Error', 'Amount exceeds remaining balance', 'error');
                return;
            }
            
            Swal.fire({
                title: 'Confirm Payment',
                html: `Record payment of <strong>KES ${formatNumber(amount)}</strong>?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#4cc9f0',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, record payment'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('loadingOverlay').classList.add('active');
                    
                    const formData = new FormData(this);
                    formData.append('ajax', '1');
                    
                    fetch('record_payment.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        document.getElementById('loadingOverlay').classList.remove('active');
                        
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Payment Recorded!',
                                html: `Transaction ID: ${data.transaction_id}<br>Receipt will be generated.`,
                                showConfirmButton: true
                            }).then(() => {
                                window.location.href = 'payment_receipt.php?id=' + data.payment_id;
                            });
                        } else {
                            Swal.fire('Error', data.message, 'error');
                        }
                    })
                    .catch(error => {
                        document.getElementById('loadingOverlay').classList.remove('active');
                        Swal.fire('Error', 'Network error occurred', 'error');
                    });
                }
            });
        });

        // Helper functions
        function formatNumber(num) {
            return parseFloat(num).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            toggleMpesa();
            updateSummary();
            
            // Set max amount if invoice pre-selected
            <?php if ($selected_invoice): ?>
            document.getElementById('maxAmount').textContent = selectedInvoiceBalance.toFixed(2);
            document.getElementById('paymentAmount').max = selectedInvoiceBalance;
            document.getElementById('paymentAmount').value = selectedInvoiceBalance;
            <?php endif; ?>
        });
    </script>
</body>
</html>
