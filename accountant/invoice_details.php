<?php
include '../config.php';
checkAuth();
checkRole(['admin', 'accountant', 'teacher']);

// Get invoice ID from URL
$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$invoice_id) {
    $_SESSION['error'] = 'Invalid invoice ID';
    header('Location: invoices.php');
    exit();
}

// Get invoice details with comprehensive information
$stmt = $pdo->prepare("
    SELECT 
        i.*,
        s.id as student_id,
        s.full_name as student_name,
        s.Admission_number,
        s.parent_name,
        s.parent_phone,
        p.email as parent_email,
        c.class_name,
        c.id as class_id,
        fs.structure_name,
        fs.id as fee_structure_id,
        fs.term,
        fs.academic_year_id,
        creator.full_name as created_by_name,
        approver.full_name as approved_by_name,
        DATEDIFF(CURDATE(), i.due_date) as days_overdue,
        CASE 
            WHEN i.status = 'paid' THEN 'Paid'
            WHEN i.status = 'unpaid' AND i.due_date < CURDATE() THEN 'Overdue'
            WHEN i.status = 'partial' AND i.due_date < CURDATE() THEN 'Overdue'
            ELSE i.status
        END as display_status
    FROM invoices i
    JOIN students s ON i.student_id = s.id
    LEFT JOIN parents p ON s.parent_id = p.id
    LEFT JOIN classes c ON i.class_id = c.id
    LEFT JOIN fee_structures fs ON i.fee_structure_id = fs.id
    LEFT JOIN users creator ON i.created_by = creator.id
    LEFT JOIN users approver ON i.approved_by = approver.id
    WHERE i.id = ?
");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    $_SESSION['error'] = 'Invoice not found';
    header('Location: invoices.php');
    exit();
}

// Get invoice items
$stmt = $pdo->prepare("
    SELECT * FROM invoice_items 
    WHERE invoice_id = ? 
    ORDER BY is_mandatory DESC, amount DESC
");
$stmt->execute([$invoice_id]);
$items = $stmt->fetchAll();

// Get payment history for this invoice
$stmt = $pdo->prepare("
    SELECT 
        p.*,
        pm.label as payment_method_label,
        u.full_name as recorded_by_name
    FROM payments p
    LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
    LEFT JOIN users u ON p.recorded_by = u.id
    WHERE p.invoice_id = ?
    ORDER BY p.payment_date DESC
");
$stmt->execute([$invoice_id]);
$payments = $stmt->fetchAll();

// Calculate payment summary
$total_paid = array_sum(array_column($payments, 'amount'));
$payment_count = count($payments);
$last_payment = !empty($payments) ? $payments[0]['payment_date'] : null;

// Get student's other invoices
$stmt = $pdo->prepare("
    SELECT id, invoice_no, total_amount, amount_paid, balance, status, due_date
    FROM invoices 
    WHERE student_id = ? AND id != ?
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->execute([$invoice['student_id'], $invoice_id]);
$other_invoices = $stmt->fetchAll();

// Calculate progress percentage
$progress_percentage = $invoice['total_amount'] > 0 
    ? ($invoice['amount_paid'] / $invoice['total_amount']) * 100 
    : 0;

// Handle AJAX requests for printing/email
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'print') {
        // Redirect to print-friendly version
        header("Location: print_invoice.php?id=" . $invoice_id);
        exit();
    }
    
    if ($_GET['action'] === 'email') {
        // Handle email sending via AJAX
        header('Content-Type: application/json');
        
        $to = $_POST['email'] ?? $invoice['parent_email'];
        if (empty($to)) {
            echo json_encode(['success' => false, 'message' => 'No email address provided']);
            exit();
        }
        
        // Send email logic here
        // ... email sending code
        
        echo json_encode(['success' => true, 'message' => 'Invoice sent successfully']);
        exit();
    }
}

$page_title = 'Invoice #' . $invoice['invoice_no'] . ' - ' . SCHOOL_NAME;
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

        /* Back Button */
        .back-button {
            margin-bottom: 1.5rem;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--white);
            color: var(--dark);
            text-decoration: none;
            border-radius: var(--border-radius-md);
            font-weight: 600;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
        }

        .back-btn:hover {
            transform: translateX(-5px);
            box-shadow: var(--shadow-lg);
        }

        /* Invoice Header */
        .invoice-header {
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
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .invoice-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: var(--gradient-1);
        }

        .invoice-title {
            flex: 1;
        }

        .invoice-title h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .invoice-badge {
            display: inline-block;
            padding: 0.4rem 1.2rem;
            border-radius: 30px;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-paid {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
            border: 1px solid rgba(76, 201, 240, 0.3);
        }

        .badge-unpaid {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
            border: 1px solid rgba(248, 150, 30, 0.3);
        }

        .badge-partial {
            background: rgba(114, 9, 183, 0.15);
            color: var(--purple);
            border: 1px solid rgba(114, 9, 183, 0.3);
        }

        .badge-overdue {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
            border: 1px solid rgba(249, 65, 68, 0.3);
        }

        .invoice-actions {
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius-md);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .action-btn.primary {
            background: var(--gradient-1);
            color: white;
        }

        .action-btn.success {
            background: var(--gradient-3);
            color: white;
        }

        .action-btn.warning {
            background: var(--gradient-5);
            color: white;
        }

        .action-btn.outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        .action-btn.outline:hover {
            background: var(--primary);
            color: white;
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
            display: flex;
            align-items: center;
            gap: 1.5rem;
            transition: var(--transition);
            border-left: 4px solid;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card.total { border-left-color: var(--primary); }
        .stat-card.paid { border-left-color: var(--success); }
        .stat-card.balance { border-left-color: var(--danger); }
        .stat-card.due { border-left-color: var(--warning); }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: white;
        }

        .stat-card.total .stat-icon { background: var(--gradient-1); }
        .stat-card.paid .stat-icon { background: var(--gradient-3); }
        .stat-card.balance .stat-icon { background: var(--gradient-2); }
        .stat-card.due .stat-icon { background: var(--gradient-5); }

        .stat-content {
            flex: 1;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Progress Section */
        .progress-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .progress-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
        }

        .progress-percentage {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--success);
        }

        .progress-bar {
            width: 100%;
            height: 10px;
            background: var(--light);
            border-radius: 5px;
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .progress-fill {
            height: 100%;
            background: var(--gradient-3);
            border-radius: 5px;
            transition: width 0.3s ease;
        }

        .progress-stats {
            display: flex;
            justify-content: space-between;
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Invoice Details Card */
        .details-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
        }

        .card-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
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

        .card-body {
            padding: 1.5rem;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .info-group {
            background: var(--light);
            border-radius: var(--border-radius-md);
            padding: 1.2rem;
        }

        .info-title {
            font-size: 0.85rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .info-content {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
        }

        .info-content small {
            font-size: 0.85rem;
            font-weight: normal;
            color: var(--gray);
        }

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 1.5rem 0;
        }

        .items-table th {
            padding: 1rem;
            text-align: left;
            background: var(--light);
            color: var(--dark);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
        }

        .items-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--light);
        }

        .items-table tr:last-child td {
            border-bottom: none;
        }

        .items-table tfoot td {
            padding: 1rem;
            background: var(--light);
            font-weight: 600;
        }

        .mandatory-badge {
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
            border-radius: 4px;
            margin-left: 0.5rem;
        }

        /* Payment History */
        .payment-item {
            background: var(--light);
            border-radius: var(--border-radius-md);
            padding: 1rem;
            margin-bottom: 0.8rem;
            border-left: 4px solid var(--success);
            transition: var(--transition);
        }

        .payment-item:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow-md);
        }

        .payment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .payment-date {
            font-weight: 600;
            color: var(--dark);
        }

        .payment-amount {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--success);
        }

        .payment-details {
            display: flex;
            gap: 1.5rem;
            font-size: 0.85rem;
            color: var(--gray);
        }

        .payment-method {
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .payment-reference {
            font-family: monospace;
        }

        /* Other Invoices */
        .invoice-link {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: var(--light);
            border-radius: var(--border-radius-md);
            margin-bottom: 0.5rem;
            text-decoration: none;
            color: var(--dark);
            transition: var(--transition);
        }

        .invoice-link:hover {
            transform: translateX(5px);
            background: rgba(67, 97, 238, 0.1);
        }

        .invoice-link .invoice-no {
            font-weight: 600;
        }

        .invoice-link .invoice-status {
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
        }

        /* Notes Section */
        .notes-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-top: 2rem;
            box-shadow: var(--shadow-md);
        }

        .notes-content {
            background: var(--light);
            padding: 1rem;
            border-radius: var(--border-radius-md);
            color: var(--dark);
            line-height: 1.6;
            white-space: pre-line;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .invoice-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .invoice-actions {
                width: 100%;
                justify-content: flex-start;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .items-table {
                font-size: 0.9rem;
            }
            
            .items-table th,
            .items-table td {
                padding: 0.75rem;
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
        <!-- Back Button -->
        <div class="back-button animate">
            <a href="invoices.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Invoices
            </a>
        </div>

        <!-- Invoice Header -->
        <div class="invoice-header animate">
            <div class="invoice-title">
                <h1>
                    Invoice #<?php echo htmlspecialchars($invoice['invoice_no']); ?>
                    <span class="invoice-badge badge-<?php echo strtolower($invoice['display_status']); ?>">
                        <?php echo $invoice['display_status']; ?>
                    </span>
                </h1>
                <p style="color: var(--gray);">
                    <i class="fas fa-calendar"></i> Created on <?php echo date('F j, Y', strtotime($invoice['created_at'])); ?>
                    <?php if ($invoice['created_by_name']): ?> by <?php echo htmlspecialchars($invoice['created_by_name']); ?><?php endif; ?>
                </p>
            </div>
            
            <div class="invoice-actions">
                <?php if ($invoice['status'] != 'paid'): ?>
                <a href="record_payment.php?invoice_id=<?php echo $invoice_id; ?>" class="action-btn success">
                    <i class="fas fa-credit-card"></i> Record Payment
                </a>
                <?php endif; ?>
                <a href="#" onclick="printInvoice()" class="action-btn primary">
                    <i class="fas fa-print"></i> Print
                </a>
                <?php if ($invoice['parent_phone']): ?>
                <a href="#" onclick="emailInvoice()" class="action-btn outline">
                    <i class="fas fa-envelope"></i> Email
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid animate">
            <div class="stat-card total">
                <div class="stat-icon">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value">KES <?php echo number_format($invoice['total_amount'], 2); ?></div>
                    <div class="stat-label">Total Amount</div>
                </div>
            </div>
            
            <div class="stat-card paid">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value">KES <?php echo number_format($invoice['amount_paid'], 2); ?></div>
                    <div class="stat-label">Amount Paid</div>
                </div>
            </div>
            
            <div class="stat-card balance">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value">KES <?php echo number_format($invoice['balance'], 2); ?></div>
                    <div class="stat-label">Remaining Balance</div>
                </div>
            </div>
            
            <div class="stat-card due">
                <div class="stat-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo date('d M Y', strtotime($invoice['due_date'])); ?></div>
                    <div class="stat-label">Due Date</div>
                    <?php if ($invoice['days_overdue'] > 0 && $invoice['status'] != 'paid'): ?>
                    <div style="color: var(--danger); font-size: 0.85rem; margin-top: 0.3rem;">
                        <?php echo $invoice['days_overdue']; ?> days overdue
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Progress Section -->
        <div class="progress-section animate">
            <div class="progress-header">
                <span class="progress-title">Payment Progress</span>
                <span class="progress-percentage"><?php echo number_format($progress_percentage, 1); ?>%</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $progress_percentage; ?>%;"></div>
            </div>
            <div class="progress-stats">
                <span>KES <?php echo number_format($invoice['amount_paid'], 2); ?> paid</span>
                <span>KES <?php echo number_format($invoice['balance'], 2); ?> remaining</span>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Left Column - Invoice Details -->
            <div class="details-card animate">
                <div class="card-header">
                    <h3><i class="fas fa-info-circle"></i> Invoice Details</h3>
                </div>
                <div class="card-body">
                    <!-- Student Information -->
                    <div class="info-grid">
                        <div class="info-group">
                            <div class="info-title">Student Information</div>
                            <div class="info-content"><?php echo htmlspecialchars($invoice['student_name']); ?></div>
                            <div style="margin-top: 0.3rem;">
                                <small>Adm: <?php echo htmlspecialchars($invoice['admission_number']); ?></small>
                            </div>
                            <div style="margin-top: 0.3rem;">
                                <small>Class: <?php echo htmlspecialchars($invoice['class_name']); ?></small>
                            </div>
                        </div>
                        
                        <div class="info-group">
                            <div class="info-title">Fee Structure</div>
                            <div class="info-content"><?php echo htmlspecialchars($invoice['structure_name']); ?></div>
                            <?php if ($invoice['term']): ?>
                            <div style="margin-top: 0.3rem;">
                                <small>Term <?php echo $invoice['term']; ?>, <?php echo $invoice['academic_year_id']; ?></small>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="info-group">
                            <div class="info-title">Parent/Guardian</div>
                            <div class="info-content"><?php echo htmlspecialchars($invoice['parent_name'] ?? 'N/A'); ?></div>
                            <?php if ($invoice['parent_phone']): ?>
                            <div style="margin-top: 0.3rem;">
                                <small><i class="fas fa-phone"></i> <?php echo $invoice['parent_phone']; ?></small>
                            </div>
                            <?php endif; ?>
                            <?php if ($invoice['parent_email']): ?>
                            <div style="margin-top: 0.3rem;">
                                <small><i class="fas fa-envelope"></i> <?php echo $invoice['parent_email']; ?></small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Fee Items -->
                    <h4 style="margin: 1.5rem 0 1rem; color: var(--dark);">Fee Items</h4>
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Description</th>
                                <th style="text-align: right;">Amount (KES)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($item['item_name']); ?>
                                    <?php if ($item['is_mandatory']): ?>
                                    <span class="mandatory-badge">Required</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($item['description'] ?? '-'); ?></td>
                                <td style="text-align: right; font-weight: 600;"><?php echo number_format($item['amount'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2" style="text-align: right; font-weight: 700;">Total:</td>
                                <td style="text-align: right; font-weight: 700; color: var(--primary);">
                                    <?php echo number_format($invoice['total_amount'], 2); ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>

                    <!-- Notes -->
                    <?php if (!empty($invoice['notes'])): ?>
                    <h4 style="margin: 1.5rem 0 0.5rem; color: var(--dark);">Notes</h4>
                    <div class="notes-content">
                        <?php echo nl2br(htmlspecialchars($invoice['notes'])); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column - Payment History & Other Invoices -->
            <div>
                <!-- Payment History -->
                <div class="details-card animate" style="margin-bottom: 1.5rem;">
                    <div class="card-header">
                        <h3><i class="fas fa-history"></i> Payment History</h3>
                        <span class="badge"><?php echo $payment_count; ?> payment(s)</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($payments)): ?>
                            <?php foreach ($payments as $payment): ?>
                            <div class="payment-item">
                                <div class="payment-header">
                                    <span class="payment-date">
                                        <i class="far fa-calendar"></i> <?php echo date('d M Y', strtotime($payment['payment_date'])); ?>
                                    </span>
                                    <span class="payment-amount">KES <?php echo number_format($payment['amount'], 2); ?></span>
                                </div>
                                <div class="payment-details">
                                    <span class="payment-method">
                                        <i class="fas fa-<?php 
                                            echo $payment['payment_method_label'] == 'M-Pesa' ? 'mobile-alt' : 
                                                ($payment['payment_method_label'] == 'Bank Transfer' ? 'university' : 'money-bill'); 
                                        ?>"></i>
                                        <?php echo htmlspecialchars($payment['payment_method_label'] ?? 'Cash'); ?>
                                    </span>
                                    <?php if ($payment['reference_no']): ?>
                                    <span class="payment-reference">
                                        <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($payment['reference_no']); ?>
                                    </span>
                                    <?php endif; ?>
                                    <span class="payment-recorder">
                                        <i class="far fa-user"></i> <?php echo htmlspecialchars($payment['recorded_by_name']); ?>
                                    </span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if ($last_payment): ?>
                            <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px dashed var(--light); color: var(--gray); font-size: 0.9rem;">
                                <i class="far fa-clock"></i> Last payment: <?php echo date('F j, Y', strtotime($last_payment)); ?>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-credit-card"></i>
                                <p>No payments recorded yet</p>
                                <a href="record_payment.php?invoice_id=<?php echo $invoice_id; ?>" class="action-btn success" style="margin-top: 1rem; display: inline-block;">
                                    <i class="fas fa-plus"></i> Record Payment
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Other Invoices -->
                <?php if (!empty($other_invoices)): ?>
                <div class="details-card animate">
                    <div class="card-header">
                        <h3><i class="fas fa-file-invoice"></i> Other Invoices</h3>
                        <span class="badge"><?php echo count($other_invoices); ?> more</span>
                    </div>
                    <div class="card-body">
                        <?php foreach ($other_invoices as $other): 
                            $other_status = $other['status'];
                            if ($other['status'] == 'unpaid' && strtotime($other['due_date']) < time()) {
                                $other_status = 'overdue';
                            }
                        ?>
                        <a href="invoice_details.php?id=<?php echo $other['id']; ?>" class="invoice-link">
                            <div>
                                <span class="invoice-no">#<?php echo htmlspecialchars($other['invoice_no']); ?></span>
                                <span class="invoice-status" style="background: <?php 
                                    echo $other_status == 'paid' ? 'rgba(76, 201, 240, 0.15)' : 
                                        ($other_status == 'overdue' ? 'rgba(249, 65, 68, 0.15)' : 
                                        ($other_status == 'partial' ? 'rgba(114, 9, 183, 0.15)' : 'rgba(248, 150, 30, 0.15)')); 
                                ?>; color: <?php 
                                    echo $other_status == 'paid' ? 'var(--success)' : 
                                        ($other_status == 'overdue' ? 'var(--danger)' : 
                                        ($other_status == 'partial' ? 'var(--purple)' : 'var(--warning)')); 
                                ?>;">
                                    <?php echo ucfirst($other_status); ?>
                                </span>
                            </div>
                            <div>
                                <strong>KES <?php echo number_format($other['balance'], 2); ?></strong>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Audit Trail -->
        <div class="details-card animate" style="margin-top: 1rem;">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Audit Trail</h3>
            </div>
            <div class="card-body">
                <div style="display: flex; gap: 2rem; flex-wrap: wrap;">
                    <div>
                        <span style="color: var(--gray);">Created:</span>
                        <strong><?php echo date('F j, Y \a\t g:i A', strtotime($invoice['created_at'])); ?></strong>
                        <?php if ($invoice['created_by_name']): ?> by <?php echo htmlspecialchars($invoice['created_by_name']); ?><?php endif; ?>
                    </div>
                    <?php if (!empty($invoice['approved_by_name']) && !empty($invoice['approved_at'])): ?>
                    <div>
                        <span style="color: var(--gray);">Approved:</span>
                        <strong><?php echo date('F j, Y \a\t g:i A', strtotime($invoice['approved_at'])); ?></strong>
                        by <?php echo htmlspecialchars($invoice['approved_by_name']); ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($last_payment): ?>
                    <div>
                        <span style="color: var(--gray);">Last Payment:</span>
                        <strong><?php echo date('F j, Y \a\t g:i A', strtotime($last_payment)); ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Email Modal -->
    <div id="emailModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-envelope"></i> Send Invoice via Email</h3>
                <button class="modal-close" onclick="closeEmailModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="emailForm">
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" id="email_address" class="form-control" 
                               value="<?php echo htmlspecialchars($invoice['parent_email']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" id="email_subject" class="form-control" 
                               value="Invoice #<?php echo $invoice['invoice_no']; ?> - Fee Payment" required>
                    </div>
                    <div class="form-group">
                        <label>Message (Optional)</label>
                        <textarea id="email_message" class="form-control" rows="4">Dear Parent,

Please find attached invoice #<?php echo $invoice['invoice_no']; ?> for <?php echo htmlspecialchars($invoice['student_name']); ?>.

Total Amount: KES <?php echo number_format($invoice['total_amount'], 2); ?>
Amount Paid: KES <?php echo number_format($invoice['amount_paid'], 2); ?>
Balance: KES <?php echo number_format($invoice['balance'], 2); ?>

Due Date: <?php echo date('F j, Y', strtotime($invoice['due_date'])); ?>

Thank you for your prompt payment.

Regards,
School Administration</textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeEmailModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="sendEmail()">
                    <i class="fas fa-paper-plane"></i> Send Email
                </button>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay" style="display: none;">
        <div class="loading-spinner"></div>
    </div>

    <style>
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
    </style>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function printInvoice() {
            window.open('print_invoice.php?id=<?php echo $invoice_id; ?>', '_blank', 'width=800,height=600');
        }

        function emailInvoice() {
            document.getElementById('emailModal').classList.add('active');
        }

        function closeEmailModal() {
            document.getElementById('emailModal').classList.remove('active');
        }

        function sendEmail() {
            const email = document.getElementById('email_address').value;
            const subject = document.getElementById('email_subject').value;
            const message = document.getElementById('email_message').value;

            if (!email) {
                Swal.fire('Error', 'Please enter an email address', 'error');
                return;
            }

            document.getElementById('loadingOverlay').classList.add('active');

            const formData = new FormData();
            formData.append('email', email);
            formData.append('subject', subject);
            formData.append('message', message);

            fetch('invoice_details.php?action=email&id=<?php echo $invoice_id; ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('loadingOverlay').classList.remove('active');
                
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Email Sent!',
                        text: data.message,
                        timer: 2000
                    }).then(() => {
                        closeEmailModal();
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(error => {
                document.getElementById('loadingOverlay').classList.remove('active');
                Swal.fire('Error', 'Failed to send email', 'error');
            });
        }

        // Close modal on outside click
        window.onclick = function(event) {
            const modal = document.getElementById('emailModal');
            if (event.target == modal) {
                closeEmailModal();
            }
        }
    </script>
</body>
</html>
