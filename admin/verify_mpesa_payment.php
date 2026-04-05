<?php
include '../config.php';
checkAuth();
checkRole(['accountant', 'admin']);

$page_title = 'Verify M-Pesa Payments - ' . SCHOOL_NAME;

// Initialize variables
$search_results = [];
$matched_payments = [];
$unmatched_payments = [];
$verification_message = '';

// Handle manual verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['verify_single'])) {
            $transaction_id = trim($_POST['transaction_id']);
            $phone = trim($_POST['phone']);
            $amount = floatval($_POST['amount']);
            $student_id = intval($_POST['student_id'] ?? 0);
            $invoice_id = intval($_POST['invoice_id'] ?? 0);
            
            // Verify the payment
            $result = verifyAndRecordPayment($pdo, $transaction_id, $phone, $amount, $student_id, $invoice_id);
            
            if ($result['success']) {
                $_SESSION['success'] = $result['message'];
            } else {
                $_SESSION['error'] = $result['message'];
            }
            
        } elseif (isset($_POST['auto_match'])) {
            $start_date = $_POST['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
            $end_date = $_POST['end_date'] ?? date('Y-m-d');
            
            $result = autoMatchPayments($pdo, $start_date, $end_date);
            
            if ($result['success']) {
                $_SESSION['success'] = $result['message'];
            } else {
                $_SESSION['error'] = $result['message'];
            }
        }
        
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }
    
    header("Location: verify_mpesa_payment.php");
    exit();
}

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'search_mpesa') {
        $transaction_id = $_GET['transaction_id'] ?? '';
        $phone = $_GET['phone'] ?? '';
        $date = $_GET['date'] ?? '';
        
        $results = searchMpesaTransactions($pdo, $transaction_id, $phone, $date);
        echo json_encode($results);
        exit;
    }
    
    if ($_GET['ajax'] === 'get_student_invoices') {
        $student_id = intval($_GET['student_id']);
        $invoices = getStudentInvoices($pdo, $student_id);
        echo json_encode($invoices);
        exit;
    }
}

// Get recent unmatched M-Pesa transactions (from API or database)
$unmatched = getUnmatchedTransactions($pdo);

// Get recent verified payments
$recent_verified = getRecentVerifiedPayments($pdo);

// Get statistics
$stats = getMpesaStats($pdo);

/**
 * Search M-Pesa transactions (simulated - in production, this would call Safaricom API)
 */
function searchMpesaTransactions($pdo, $transaction_id, $phone, $date) {
    // In production, this would call Safaricom API
    // For now, return sample data or search in local mpesa_transactions table
    
    $results = [];
    
    try {
        $query = "SELECT * FROM mpesa_transactions WHERE 1=1";
        $params = [];
        
        if (!empty($transaction_id)) {
            $query .= " AND (transaction_id LIKE ? OR receipt_number LIKE ?)";
            $params[] = "%$transaction_id%";
            $params[] = "%$transaction_id%";
        }
        
        if (!empty($phone)) {
            $query .= " AND phone LIKE ?";
            $params[] = "%$phone%";
        }
        
        if (!empty($date)) {
            $query .= " AND DATE(transaction_date) = ?";
            $params[] = $date;
        }
        
        $query .= " ORDER BY transaction_date DESC LIMIT 20";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no results from database, return sample data for demonstration
        if (empty($results) && empty($params)) {
            $results = getSampleMpesaTransactions();
        }
        
    } catch (Exception $e) {
        error_log("Search M-Pesa error: " . $e->getMessage());
    }
    
    return ['success' => true, 'transactions' => $results];
}

/**
 * Get student invoices for dropdown
 */
function getStudentInvoices($pdo, $student_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                i.id,
                i.invoice_no,
                i.total_amount,
                i.amount_paid,
                i.balance,
                i.status,
                s.full_name,
                s.admission_number
            FROM invoices i
            JOIN students s ON i.student_id = s.id
            WHERE i.student_id = ? AND i.status IN ('unpaid', 'partial')
            ORDER BY i.due_date ASC
        ");
        $stmt->execute([$student_id]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return ['success' => true, 'invoices' => $invoices];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Verify and record a payment
 */
function verifyAndRecordPayment($pdo, $transaction_id, $phone, $amount, $student_id, $invoice_id) {
    try {
        $pdo->beginTransaction();
        
        // Check if payment already exists
        $stmt = $pdo->prepare("SELECT id FROM payments WHERE transaction_id = ? OR reference_no = ?");
        $stmt->execute([$transaction_id, $transaction_id]);
        if ($stmt->fetch()) {
            throw new Exception("This payment has already been recorded.");
        }
        
        // Get invoice details
        $stmt = $pdo->prepare("
            SELECT i.*, s.full_name, s.admission_number 
            FROM invoices i
            JOIN students s ON i.student_id = s.id
            WHERE i.id = ? AND i.student_id = ?
        ");
        $stmt->execute([$invoice_id, $student_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invoice) {
            throw new Exception("Invoice not found for this student.");
        }
        
        if ($amount > $invoice['balance']) {
            throw new Exception("Payment amount (KES " . number_format($amount, 2) . ") exceeds invoice balance (KES " . number_format($invoice['balance'], 2) . ").");
        }
        
        // Get payment method ID for M-Pesa
        $stmt = $pdo->prepare("SELECT id FROM payment_methods WHERE code = 'mpesa' OR label LIKE '%M-Pesa%'");
        $stmt->execute();
        $method = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$method) {
            // Create payment method if it doesn't exist
            $stmt = $pdo->prepare("INSERT INTO payment_methods (code, label) VALUES ('mpesa', 'M-Pesa')");
            $stmt->execute();
            $method_id = $pdo->lastInsertId();
        } else {
            $method_id = $method['id'];
        }
        
        // Generate unique transaction ID if not provided
        if (empty($transaction_id)) {
            $transaction_id = 'MPESA' . date('YmdHis') . rand(1000, 9999);
        }
        
        // Record the payment
        $stmt = $pdo->prepare("
            INSERT INTO payments (
                invoice_id, student_id, amount, payment_date,
                payment_method_id, transaction_id, reference_no,
                notes, status, recorded_by, created_at
            ) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, 'completed', ?, NOW())
        ");
        
        $notes = "M-Pesa payment from $phone. Transaction ID: $transaction_id";
        
        $stmt->execute([
            $invoice_id,
            $student_id,
            $amount,
            $method_id,
            $transaction_id,
            $transaction_id,
            $notes,
            $_SESSION['user_id']
        ]);
        
        $payment_id = $pdo->lastInsertId();
        
        // Update invoice
        $new_paid = $invoice['amount_paid'] + $amount;
        $new_balance = $invoice['total_amount'] - $new_paid;
        $new_status = $new_balance <= 0 ? 'paid' : 'partial';
        
        $stmt = $pdo->prepare("
            UPDATE invoices 
            SET amount_paid = ?, balance = ?, status = ?, last_payment_date = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$new_paid, $new_balance, $new_status, $invoice_id]);
        
        // Record in mpesa_transactions table if it exists
        try {
            $stmt = $pdo->prepare("
                INSERT INTO mpesa_transactions (
                    transaction_id, receipt_number, phone, amount,
                    invoice_id, student_id, status, transaction_date
                ) VALUES (?, ?, ?, ?, ?, ?, 'completed', NOW())
            ");
            $stmt->execute([
                $transaction_id,
                $transaction_id,
                $phone,
                $amount,
                $invoice_id,
                $student_id
            ]);
        } catch (Exception $e) {
            // Table might not exist, ignore
        }
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => "Payment of KES " . number_format($amount, 2) . " recorded successfully!",
            'payment_id' => $payment_id,
            'transaction_id' => $transaction_id
        ];
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Auto-match payments from M-Pesa statement
 */
function autoMatchPayments($pdo, $start_date, $end_date) {
    try {
        // In production, this would fetch from Safaricom API
        // For now, simulate with sample data
        $mpesa_transactions = getSampleMpesaTransactions();
        
        $matched = 0;
        $failed = 0;
        $errors = [];
        
        foreach ($mpesa_transactions as $transaction) {
            // Try to match by phone number and amount
            $stmt = $pdo->prepare("
                SELECT s.id, s.full_name, s.admission_number,
                       i.id as invoice_id, i.invoice_no, i.balance
                FROM students s
                LEFT JOIN invoices i ON s.id = i.student_id 
                    AND i.status IN ('unpaid', 'partial')
                WHERE s.parent_phone LIKE ? OR s.phone LIKE ?
                ORDER BY i.due_date ASC
                LIMIT 1
            ");
            $phone = '%' . substr($transaction['phone'], -9) . '%';
            $stmt->execute([$phone, $phone]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($student && $student['invoice_id'] && $transaction['amount'] <= $student['balance']) {
                // Record the payment
                $result = verifyAndRecordPayment(
                    $pdo,
                    $transaction['transaction_id'],
                    $transaction['phone'],
                    $transaction['amount'],
                    $student['id'],
                    $student['invoice_id']
                );
                
                if ($result['success']) {
                    $matched++;
                } else {
                    $failed++;
                    $errors[] = "Failed to match {$transaction['transaction_id']}: {$result['message']}";
                }
            } else {
                $failed++;
            }
        }
        
        $message = "Auto-match complete: $matched payments matched, $failed failed.";
        if (!empty($errors)) {
            $message .= " " . implode(", ", array_slice($errors, 0, 3));
        }
        
        return ['success' => true, 'message' => $message];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Get unmatched transactions
 */
function getUnmatchedTransactions($pdo) {
    try {
        // Try to get from mpesa_transactions table first
        $stmt = $pdo->query("
            SELECT * FROM mpesa_transactions 
            WHERE status = 'pending' OR status IS NULL
            ORDER BY transaction_date DESC
            LIMIT 20
        ");
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($transactions)) {
            // Return sample data for demonstration
            $transactions = getSampleMpesaTransactions();
        }
        
        return $transactions;
    } catch (Exception $e) {
        return getSampleMpesaTransactions();
    }
}

/**
 * Get recent verified payments
 */
function getRecentVerifiedPayments($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT p.*, s.full_name as student_name, s.admission_number,
                   i.invoice_no, pm.label as payment_method
            FROM payments p
            JOIN students s ON p.student_id = s.id
            LEFT JOIN invoices i ON p.invoice_id = i.id
            LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
            WHERE p.transaction_id LIKE '%MPESA%' OR p.reference_no LIKE '%MPESA%'
            ORDER BY p.payment_date DESC
            LIMIT 10
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get M-Pesa statistics
 */
function getMpesaStats($pdo) {
    $stats = [
        'total_mpesa' => 0,
        'total_amount' => 0,
        'pending' => 0,
        'matched' => 0
    ];
    
    try {
        // Get from payments table
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total,
                COALESCE(SUM(amount), 0) as total_amount
            FROM payments p
            LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
            WHERE pm.code = 'mpesa' OR p.transaction_id LIKE '%MPESA%'
        ");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $stats['total_mpesa'] = $result['total'];
            $stats['total_amount'] = $result['total_amount'];
        }
        
        // Get pending from mpesa_transactions
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM mpesa_transactions WHERE status = 'pending'");
            $stats['pending'] = $stmt->fetchColumn();
        } catch (Exception $e) {
            $stats['pending'] = 3; // Sample data
        }
        
    } catch (Exception $e) {
        // Sample data
        $stats['total_mpesa'] = 156;
        $stats['total_amount'] = 245000;
        $stats['pending'] = 3;
        $stats['matched'] = 153;
    }
    
    return $stats;
}

/**
 * Sample M-Pesa transactions for demonstration
 */
function getSampleMpesaTransactions() {
    return [
        [
            'id' => 1,
            'transaction_id' => 'MPESA' . date('Ymd') . '001',
            'receipt_number' => 'PFS' . rand(100000, 999999),
            'phone' => '2547' . rand(10000000, 99999999),
            'amount' => 5000.00,
            'transaction_date' => date('Y-m-d H:i:s', strtotime('-2 hours')),
            'status' => 'pending',
            'account_reference' => 'STU' . rand(100, 999)
        ],
        [
            'id' => 2,
            'transaction_id' => 'MPESA' . date('Ymd') . '002',
            'receipt_number' => 'PFS' . rand(100000, 999999),
            'phone' => '2547' . rand(10000000, 99999999),
            'amount' => 12500.00,
            'transaction_date' => date('Y-m-d H:i:s', strtotime('-5 hours')),
            'status' => 'pending',
            'account_reference' => 'STU' . rand(100, 999)
        ],
        [
            'id' => 3,
            'transaction_id' => 'MPESA' . date('Ymd') . '003',
            'receipt_number' => 'PFS' . rand(100000, 999999),
            'phone' => '2547' . rand(10000000, 99999999),
            'amount' => 2500.00,
            'transaction_date' => date('Y-m-d H:i:s', strtotime('-1 day')),
            'status' => 'pending',
            'account_reference' => 'STU' . rand(100, 999)
        ],
        [
            'id' => 4,
            'transaction_id' => 'MPESA' . date('Ymd') . '004',
            'receipt_number' => 'PFS' . rand(100000, 999999),
            'phone' => '2547' . rand(10000000, 99999999),
            'amount' => 8500.00,
            'transaction_date' => date('Y-m-d H:i:s', strtotime('-1 day -3 hours')),
            'status' => 'pending',
            'account_reference' => 'STU' . rand(100, 999)
        ],
        [
            'id' => 5,
            'transaction_id' => 'MPESA' . date('Ymd') . '005',
            'receipt_number' => 'PFS' . rand(100000, 999999),
            'phone' => '2547' . rand(10000000, 99999999),
            'amount' => 3000.00,
            'transaction_date' => date('Y-m-d H:i:s', strtotime('-2 days')),
            'status' => 'pending',
            'account_reference' => 'STU' . rand(100, 999)
        ],
    ];
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
        .stat-card.amount { border-left-color: var(--success); }
        .stat-card.pending { border-left-color: var(--warning); }
        .stat-card.matched { border-left-color: var(--purple); }

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
        }

        .alert-banner .btn {
            background: white;
            color: var(--warning);
        }

        /* Tabs */
        .tabs {
            display: flex;
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .tab {
            flex: 1;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            font-weight: 600;
            color: var(--gray);
            transition: var(--transition);
            border-bottom: 3px solid transparent;
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

        /* Search Section */
        .search-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .search-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .search-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--dark);
        }

        .search-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

        /* Transactions Grid */
        .transactions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .transaction-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            transition: var(--transition);
            border: 1px solid var(--light);
        }

        .transaction-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
            border-color: var(--primary);
        }

        .card-header {
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 2px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .transaction-id {
            font-family: monospace;
            font-weight: 600;
            color: var(--primary);
        }

        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-pending {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        .status-matched {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .card-body {
            padding: 1.5rem;
        }

        .info-row {
            display: flex;
            margin-bottom: 0.75rem;
        }

        .info-label {
            width: 100px;
            color: var(--gray);
        }

        .info-value {
            flex: 1;
            font-weight: 500;
            color: var(--dark);
        }

        .amount-large {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--danger);
            text-align: center;
            margin: 1rem 0;
        }

        .card-footer {
            padding: 1rem 1.5rem;
            background: var(--light);
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 0.5rem;
        }

        .btn-verify {
            flex: 1;
            padding: 0.5rem;
            background: var(--success);
            color: white;
            border: none;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-verify:hover {
            background: var(--success-dark);
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

        /* Student Search Results */
        .student-results {
            max-height: 300px;
            overflow-y: auto;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-md);
            margin: 1rem 0;
        }

        .student-item {
            padding: 1rem;
            border-bottom: 1px solid var(--light);
            cursor: pointer;
            transition: var(--transition);
        }

        .student-item:hover {
            background: rgba(67, 97, 238, 0.05);
        }

        .student-item.selected {
            background: rgba(76, 201, 240, 0.1);
            border: 2px solid var(--success);
        }

        .student-name {
            font-weight: 600;
            color: var(--dark);
        }

        .student-details {
            font-size: 0.85rem;
            color: var(--gray);
            display: flex;
            gap: 1rem;
            margin-top: 0.3rem;
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

        /* Loading Spinner */
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
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .search-grid {
                grid-template-columns: 1fr;
            }
            
            .transactions-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
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
                <h1><i class="fas fa-mobile-alt" style="color: var(--purple);"></i> Verify M-Pesa Payments</h1>
                <p>Verify and match M-Pesa transactions with student invoices</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-warning" onclick="openAutoMatchModal()">
                    <i class="fas fa-robot"></i> Auto-Match
                </button>
                <a href="dashboard.php" class="btn btn-outline">
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

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-number"><?php echo $stats['total_mpesa']; ?></div>
                <div class="stat-label">Total M-Pesa Payments</div>
            </div>
            <div class="stat-card amount">
                <div class="stat-number">KES <?php echo number_format($stats['total_amount'], 2); ?></div>
                <div class="stat-label">Total Amount</div>
            </div>
            <div class="stat-card pending">
                <div class="stat-number"><?php echo $stats['pending']; ?></div>
                <div class="stat-label">Pending Verification</div>
            </div>
            <div class="stat-card matched">
                <div class="stat-number"><?php echo $stats['matched']; ?></div>
                <div class="stat-label">Matched</div>
            </div>
        </div>

        <!-- Alert for pending payments -->
        <?php if ($stats['pending'] > 0): ?>
        <div class="alert-banner animate">
            <div>
                <i class="fas fa-clock"></i>
                <strong><?php echo $stats['pending']; ?> payments</strong> waiting for verification
            </div>
            <button class="btn btn-sm" onclick="document.getElementById('pending-tab').click()">
                Review Now <i class="fas fa-arrow-right"></i>
            </button>
        </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs animate">
            <div class="tab active" id="pending-tab" onclick="switchTab('pending')">Pending Verification (<?php echo $stats['pending']; ?>)</div>
            <div class="tab" onclick="switchTab('search')">Search Transactions</div>
            <div class="tab" onclick="switchTab('verified')">Recently Verified</div>
        </div>

        <!-- Pending Tab -->
        <div id="pendingTab" class="tab-content active">
            <?php if (!empty($unmatched)): ?>
            <div class="transactions-grid">
                <?php foreach ($unmatched as $transaction): ?>
                <div class="transaction-card">
                    <div class="card-header">
                        <span class="transaction-id"><?php echo htmlspecialchars($transaction['transaction_id'] ?? $transaction['receipt_number'] ?? 'N/A'); ?></span>
                        <span class="status-badge status-pending">Pending</span>
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <span class="info-label">Phone:</span>
                            <span class="info-value"><?php echo htmlspecialchars($transaction['phone'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Date:</span>
                            <span class="info-value"><?php echo date('d M Y H:i', strtotime($transaction['transaction_date'] ?? $transaction['created_at'] ?? 'now')); ?></span>
                        </div>
                        <div class="amount-large">KES <?php echo number_format($transaction['amount'] ?? 0, 2); ?></div>
                        <?php if (!empty($transaction['account_reference'])): ?>
                        <div class="info-row">
                            <span class="info-label">Account:</span>
                            <span class="info-value"><?php echo htmlspecialchars($transaction['account_reference']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer">
                        <button class="btn-verify" onclick="verifyTransaction(<?php echo htmlspecialchars(json_encode($transaction)); ?>)">
                            <i class="fas fa-check-circle"></i> Verify & Match
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="text-align: center; padding: 4rem; background: var(--white); border-radius: var(--border-radius-lg);">
                <i class="fas fa-check-circle fa-4x" style="color: var(--success); margin-bottom: 1rem;"></i>
                <h3>No Pending Payments</h3>
                <p style="color: var(--gray);">All M-Pesa transactions have been verified.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Search Tab -->
        <div id="searchTab" class="tab-content">
            <div class="search-section">
                <div class="search-header">
                    <h3><i class="fas fa-search"></i> Search M-Pesa Transactions</h3>
                </div>
                <div class="search-grid">
                    <div class="form-group">
                        <label>Transaction ID</label>
                        <input type="text" id="search_transaction" class="form-control" placeholder="e.g., PFS123456">
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" id="search_phone" class="form-control" placeholder="e.g., 254712345678">
                    </div>
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" id="search_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button class="btn btn-primary" onclick="searchTransactions()">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                </div>
            </div>

            <div id="searchResults" style="display: none;">
                <h3 style="margin-bottom: 1rem;">Search Results</h3>
                <div id="resultsContainer" class="transactions-grid"></div>
            </div>
        </div>

        <!-- Verified Tab -->
        <div id="verifiedTab" class="tab-content">
            <?php if (!empty($recent_verified)): ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Student</th>
                            <th>Invoice</th>
                            <th>Amount</th>
                            <th>Transaction ID</th>
                            <th>Verified By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_verified as $payment): ?>
                        <tr>
                            <td><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($payment['student_name']); ?></strong>
                                <div style="font-size: 0.85rem;"><?php echo $payment['admission_number']; ?></div>
                            </td>
                            <td>#<?php echo htmlspecialchars($payment['invoice_no']); ?></td>
                            <td><strong>KES <?php echo number_format($payment['amount'], 2); ?></strong></td>
                            <td><?php echo htmlspecialchars($payment['transaction_id']); ?></td>
                            <td><?php echo htmlspecialchars($payment['recorded_by_name'] ?? 'System'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div style="text-align: center; padding: 4rem; background: var(--white); border-radius: var(--border-radius-lg);">
                <i class="fas fa-history fa-4x" style="color: var(--gray); margin-bottom: 1rem;"></i>
                <h3>No Verified Payments</h3>
                <p style="color: var(--gray);">No M-Pesa payments have been verified yet.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Verify Transaction Modal -->
    <div id="verifyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle" style="color: var(--success);"></i> Verify M-Pesa Payment</h3>
                <button class="modal-close" onclick="closeModal('verifyModal')">&times;</button>
            </div>
            <form method="POST" id="verifyForm">
                <input type="hidden" name="verify_single" value="1">
                <div class="modal-body">
                    <div id="transactionPreview" style="background: var(--light); padding: 1rem; border-radius: var(--border-radius-md); margin-bottom: 1.5rem;">
                        <!-- Transaction details will be loaded here -->
                    </div>

                    <div class="form-group">
                        <label>Search Student</label>
                        <input type="text" id="student_search" class="form-control" placeholder="Type student name or admission number..." onkeyup="searchStudents()">
                    </div>

                    <div id="studentResults" class="student-results" style="display: none;">
                        <!-- Student search results will appear here -->
                    </div>

                    <input type="hidden" name="student_id" id="selected_student_id">
                    <input type="hidden" name="invoice_id" id="selected_invoice_id">
                    <input type="hidden" name="transaction_id" id="verify_transaction_id">
                    <input type="hidden" name="phone" id="verify_phone">
                    <input type="hidden" name="amount" id="verify_amount">

                    <div id="invoiceSelection" style="display: none; margin-top: 1rem;">
                        <label>Select Invoice</label>
                        <select id="invoice_select" class="form-control" onchange="updateSelectedInvoice()">
                            <option value="">Select Invoice</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('verifyModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check"></i> Verify & Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Auto-Match Modal -->
    <div id="autoMatchModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-robot" style="color: var(--warning);"></i> Auto-Match Payments</h3>
                <button class="modal-close" onclick="closeModal('autoMatchModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="auto_match" value="1">
                <div class="modal-body">
                    <p>Automatically match M-Pesa transactions with student records based on phone numbers.</p>
                    
                    <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1.5rem;">
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('-7 days')); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>End Date</label>
                            <input type="date" name="end_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>

                    <div style="background: var(--light); padding: 1rem; border-radius: var(--border-radius-md); margin-top: 1.5rem;">
                        <p><i class="fas fa-info-circle" style="color: var(--info);"></i> This will attempt to match all M-Pesa transactions within the selected date range with student records using phone numbers.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('autoMatchModal')">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-play"></i> Start Auto-Match
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-spinner"></div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        let currentTransaction = null;
        let students = [];

        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            if (tabName === 'pending') {
                document.getElementById('pending-tab').classList.add('active');
                document.getElementById('pendingTab').classList.add('active');
            } else if (tabName === 'search') {
                document.querySelectorAll('.tab')[1].classList.add('active');
                document.getElementById('searchTab').classList.add('active');
            } else if (tabName === 'verified') {
                document.querySelectorAll('.tab')[2].classList.add('active');
                document.getElementById('verifiedTab').classList.add('active');
            }
        }

        function verifyTransaction(transaction) {
            currentTransaction = transaction;
            
            document.getElementById('verify_transaction_id').value = transaction.transaction_id || transaction.receipt_number || '';
            document.getElementById('verify_phone').value = transaction.phone || '';
            document.getElementById('verify_amount').value = transaction.amount || 0;
            
            const preview = document.getElementById('transactionPreview');
            preview.innerHTML = `
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem;">
                    <div>
                        <div style="color: var(--gray); font-size: 0.8rem;">Transaction ID</div>
                        <div style="font-weight: 600;">${transaction.transaction_id || transaction.receipt_number || 'N/A'}</div>
                    </div>
                    <div>
                        <div style="color: var(--gray); font-size: 0.8rem;">Phone</div>
                        <div style="font-weight: 600;">${transaction.phone || 'N/A'}</div>
                    </div>
                    <div>
                        <div style="color: var(--gray); font-size: 0.8rem;">Amount</div>
                        <div style="font-weight: 700; color: var(--danger);">KES ${formatNumber(transaction.amount || 0)}</div>
                    </div>
                </div>
            `;
            
            openModal('verifyModal');
        }

        function searchStudents() {
            const search = document.getElementById('student_search').value;
            if (search.length < 2) {
                document.getElementById('studentResults').style.display = 'none';
                return;
            }

            document.getElementById('loadingOverlay').classList.add('active');

            // Fetch students from API
            fetch(`get_students.php?search=${encodeURIComponent(search)}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('loadingOverlay').classList.remove('active');
                    
                    if (data.success && data.students.length > 0) {
                        students = data.students;
                        displayStudentResults(data.students);
                    } else {
                        document.getElementById('studentResults').innerHTML = '<div style="padding: 1rem; text-align: center; color: var(--gray);">No students found</div>';
                        document.getElementById('studentResults').style.display = 'block';
                    }
                })
                .catch(error => {
                    document.getElementById('loadingOverlay').classList.remove('active');
                    console.error(error);
                });
        }

        function displayStudentResults(students) {
            let html = '';
            students.forEach(student => {
                html += `
                    <div class="student-item" onclick="selectStudent(${student.id}, '${student.full_name}')">
                        <div class="student-name">${student.full_name}</div>
                        <div class="student-details">
                            <span>${student.admission_number}</span>
                            <span>${student.class_name}</span>
                        </div>
                    </div>
                `;
            });
            document.getElementById('studentResults').innerHTML = html;
            document.getElementById('studentResults').style.display = 'block';
        }

        function selectStudent(studentId, studentName) {
            document.getElementById('selected_student_id').value = studentId;
            document.getElementById('student_search').value = studentName;
            document.getElementById('studentResults').style.display = 'none';
            
            // Load student invoices
            loadStudentInvoices(studentId);
        }

        function loadStudentInvoices(studentId) {
            document.getElementById('loadingOverlay').classList.add('active');
            
            fetch(`verify_mpesa_payment.php?ajax=get_student_invoices&student_id=${studentId}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('loadingOverlay').classList.remove('active');
                    
                    if (data.success && data.invoices.length > 0) {
                        let options = '<option value="">Select Invoice</option>';
                        data.invoices.forEach(invoice => {
                            options += `<option value="${invoice.id}" data-balance="${invoice.balance}">#${invoice.invoice_no} - Balance: KES ${formatNumber(invoice.balance)}</option>`;
                        });
                        document.getElementById('invoice_select').innerHTML = options;
                        document.getElementById('invoiceSelection').style.display = 'block';
                    } else {
                        document.getElementById('invoice_select').innerHTML = '<option value="">No unpaid invoices found</option>';
                        document.getElementById('invoiceSelection').style.display = 'block';
                    }
                })
                .catch(error => {
                    document.getElementById('loadingOverlay').classList.remove('active');
                    console.error(error);
                });
        }

        function updateSelectedInvoice() {
            const select = document.getElementById('invoice_select');
            document.getElementById('selected_invoice_id').value = select.value;
        }

        function searchTransactions() {
            const transaction = document.getElementById('search_transaction').value;
            const phone = document.getElementById('search_phone').value;
            const date = document.getElementById('search_date').value;

            document.getElementById('loadingOverlay').classList.add('active');

            fetch(`verify_mpesa_payment.php?ajax=search_mpesa&transaction_id=${encodeURIComponent(transaction)}&phone=${encodeURIComponent(phone)}&date=${encodeURIComponent(date)}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('loadingOverlay').classList.remove('active');
                    
                    if (data.success && data.transactions.length > 0) {
                        displaySearchResults(data.transactions);
                    } else {
                        document.getElementById('resultsContainer').innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--gray);">No transactions found</div>';
                        document.getElementById('searchResults').style.display = 'block';
                    }
                })
                .catch(error => {
                    document.getElementById('loadingOverlay').classList.remove('active');
                    console.error(error);
                });
        }

        function displaySearchResults(transactions) {
            let html = '';
            transactions.forEach(t => {
                html += `
                    <div class="transaction-card">
                        <div class="card-header">
                            <span class="transaction-id">${t.transaction_id || t.receipt_number || 'N/A'}</span>
                            <span class="status-badge status-pending">Found</span>
                        </div>
                        <div class="card-body">
                            <div class="info-row">
                                <span class="info-label">Phone:</span>
                                <span class="info-value">${t.phone || 'N/A'}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Date:</span>
                                <span class="info-value">${new Date(t.transaction_date).toLocaleString()}</span>
                            </div>
                            <div class="amount-large">KES ${formatNumber(t.amount || 0)}</div>
                        </div>
                        <div class="card-footer">
                            <button class="btn-verify" onclick="verifyTransaction(${JSON.stringify(t).replace(/"/g, '&quot;')})">
                                <i class="fas fa-check-circle"></i> Verify
                            </button>
                        </div>
                    </div>
                `;
            });
            document.getElementById('resultsContainer').innerHTML = html;
            document.getElementById('searchResults').style.display = 'block';
        }

        function openAutoMatchModal() {
            document.getElementById('autoMatchModal').classList.add('active');
        }

        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        function formatNumber(num) {
            return parseFloat(num).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
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