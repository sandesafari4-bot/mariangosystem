<?php
include '../config.php';
checkAuth();
checkRole(['admin', 'accountant']);

function paymentReceiptTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        $cache[$table] = (bool) $stmt->fetchColumn();
    }
    return $cache[$table];
}

function paymentReceiptColumns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $cache[$table] = [];
        if (paymentReceiptTableExists($pdo, $table)) {
            $cache[$table] = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        }
    }
    return $cache[$table];
}

function paymentReceiptHasColumn(PDO $pdo, string $table, string $column): bool
{
    return in_array($column, paymentReceiptColumns($pdo, $table), true);
}

// Get payment ID from URL
$payment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$payment_id) {
    $_SESSION['error'] = 'Invalid payment ID';
    header('Location: payments.php');
    exit();
}

$payment_columns = paymentReceiptColumns($pdo, 'payments');
$payment_column_lookup = array_fill_keys($payment_columns, true);
$student_columns = paymentReceiptColumns($pdo, 'students');
$student_column_lookup = array_fill_keys($student_columns, true);
$invoice_item_columns = paymentReceiptColumns($pdo, 'invoice_items');
$invoice_item_lookup = array_fill_keys($invoice_item_columns, true);

$payment_date_column = isset($payment_column_lookup['payment_date'])
    ? 'payment_date'
    : (isset($payment_column_lookup['paid_at'])
        ? 'paid_at'
        : (isset($payment_column_lookup['created_at']) ? 'created_at' : 'id'));
$payment_user_column = isset($payment_column_lookup['recorded_by']) ? 'recorded_by' : 'created_by';
$payment_method_join = isset($payment_column_lookup['payment_method_id'])
    ? 'LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id'
    : '';
$payment_method_label_expr = $payment_method_join !== '' ? 'pm.label' : "'Cash'";
$payment_method_code_expr = $payment_method_join !== '' ? 'pm.code' : "'cash'";
$parent_join = '';
$parent_name_expr = "s.parent_name";
$parent_phone_expr = "s.parent_phone";
$parent_email_expr = "NULL";

if (
    paymentReceiptTableExists($pdo, 'parents') &&
    isset($student_column_lookup['parent_id']) &&
    paymentReceiptHasColumn($pdo, 'parents', 'id')
) {
    $parent_join = 'LEFT JOIN parents pr ON s.parent_id = pr.id';
    $parent_name_expr = paymentReceiptHasColumn($pdo, 'parents', 'full_name')
        ? "COALESCE(pr.full_name, s.parent_name)"
        : "s.parent_name";
    $parent_phone_expr = paymentReceiptHasColumn($pdo, 'parents', 'phone')
        ? "COALESCE(pr.phone, s.parent_phone)"
        : "s.parent_phone";
    $parent_email_expr = paymentReceiptHasColumn($pdo, 'parents', 'email') ? 'pr.email' : 'NULL';
}

$admission_column = isset($student_column_lookup['admission_number'])
    ? 'admission_number'
    : (isset($student_column_lookup['Admission_number']) ? 'Admission_number' : null);
$admission_expr = $admission_column ? "s.`{$admission_column}`" : "''";

$buildPaymentCoalesce = function (array $candidates, string $fallback = "''") use ($payment_column_lookup): string {
    $parts = [];
    foreach ($candidates as $column) {
        if (isset($payment_column_lookup[$column])) {
            $parts[] = "p.`{$column}`";
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

// Get payment details with comprehensive information
$stmt = $pdo->prepare("
    SELECT 
        p.*,
        s.id as student_id,
        s.full_name as student_name,
        {$admission_expr} as admission_number,
        s.address,
        {$parent_name_expr} as parent_name,
        {$parent_phone_expr} as parent_phone,
        {$parent_email_expr} as parent_email,
        c.class_name,
        c.id as class_id,
        i.invoice_no,
        i.total_amount as invoice_total,
        i.balance as invoice_balance,
        i.due_date as invoice_due_date,
        i.status as invoice_status,
        fs.structure_name,
        fs.term,
        fs.academic_year_id,
        {$payment_method_label_expr} as payment_method_label,
        {$payment_method_code_expr} as payment_method_code,
        u.full_name as received_by_name,
        u.email as received_by_email,
        {$transaction_expr} as transaction_id,
        {$reference_expr} as reference_no,
        p.`{$payment_date_column}` as payment_display_date
    FROM payments p
    JOIN students s ON p.student_id = s.id
    {$parent_join}
    LEFT JOIN classes c ON s.class_id = c.id
    LEFT JOIN invoices i ON p.invoice_id = i.id
    LEFT JOIN fee_structures fs ON i.fee_structure_id = fs.id
    {$payment_method_join}
    LEFT JOIN users u ON p.`{$payment_user_column}` = u.id
    WHERE p.id = ?
");
$stmt->execute([$payment_id]);
$payment = $stmt->fetch();

if (!$payment) {
    $_SESSION['error'] = 'Payment not found';
    header('Location: payments.php');
    exit();
}

$payment['transaction_id'] = $payment['transaction_id'] ?? ('PAY-' . str_pad($payment_id, 6, '0', STR_PAD_LEFT));
$payment['reference_no'] = $payment['reference_no'] ?? '';
$payment['payment_display_date'] = $payment['payment_display_date'] ?? ($payment['created_at'] ?? 'now');
$payment['status'] = $payment['status'] ?? 'completed';

// Get invoice items if available
$items = [];
if ($payment['invoice_id']) {
    $invoiceItemOrder = [];
    if (isset($invoice_item_lookup['is_mandatory'])) {
        $invoiceItemOrder[] = 'is_mandatory DESC';
    }
    if (isset($invoice_item_lookup['amount'])) {
        $invoiceItemOrder[] = 'amount DESC';
    } elseif (isset($invoice_item_lookup['created_at'])) {
        $invoiceItemOrder[] = 'created_at DESC';
    } else {
        $invoiceItemOrder[] = 'id DESC';
    }
    $stmt = $pdo->prepare("
        SELECT * FROM invoice_items 
        WHERE invoice_id = ? 
        ORDER BY " . implode(', ', $invoiceItemOrder) . "
    ");
    $stmt->execute([$payment['invoice_id']]);
    $items = $stmt->fetchAll();
}

// Get school settings from the saved system settings first
$school_name = getSystemSetting('school_name', SCHOOL_NAME);
$school_address = getSystemSetting('school_address', defined('SCHOOL_LOCATION') ? SCHOOL_LOCATION : 'Nairobi, Kenya');
$school_phone = getSystemSetting('school_phone', '+254 700 000 000');
$school_email = getSystemSetting('school_email', 'info@school.edu');
$school_logo_setting = getSystemSetting('school_logo', defined('SCHOOL_LOGO') ? SCHOOL_LOGO : '');
$school_logo = '../logo.png';
if (!empty($school_logo_setting)) {
    $school_logo = str_contains($school_logo_setting, '/')
        ? '../' . ltrim($school_logo_setting, '/')
        : '../uploads/logos/' . ltrim($school_logo_setting, '/');
}

// Generate receipt number
$receipt_no = 'RCT' . str_pad($payment_id, 6, '0', STR_PAD_LEFT) . '/' . date('Y');

// Format amounts
$amount_in_words = numberToWords($payment['amount']);

/**
 * Convert number to words
 */
function numberToWords($number) {
    $amount = (float) $number;
    $whole = (int) floor($amount);
    $cents = (int) round(($amount - $whole) * 100);

    if (class_exists('NumberFormatter')) {
        $formatter = new NumberFormatter('en', NumberFormatter::SPELLOUT);
        $words = $formatter->format($whole);
        if ($cents > 0) {
            $words .= ' point ' . $formatter->format($cents);
        }
        return $words;
    }

    $words = convertIntegerToWords($whole);
    if ($cents > 0) {
        $words .= ' point ' . convertIntegerToWords($cents);
    }

    return $words;
}

function convertIntegerToWords($number) {
    $number = (int) $number;

    if ($number === 0) {
        return 'zero';
    }

    $ones = [
        0 => '',
        1 => 'one',
        2 => 'two',
        3 => 'three',
        4 => 'four',
        5 => 'five',
        6 => 'six',
        7 => 'seven',
        8 => 'eight',
        9 => 'nine',
        10 => 'ten',
        11 => 'eleven',
        12 => 'twelve',
        13 => 'thirteen',
        14 => 'fourteen',
        15 => 'fifteen',
        16 => 'sixteen',
        17 => 'seventeen',
        18 => 'eighteen',
        19 => 'nineteen'
    ];

    $tens = [
        2 => 'twenty',
        3 => 'thirty',
        4 => 'forty',
        5 => 'fifty',
        6 => 'sixty',
        7 => 'seventy',
        8 => 'eighty',
        9 => 'ninety'
    ];

    $scales = [
        1000000000 => 'billion',
        1000000 => 'million',
        1000 => 'thousand',
        100 => 'hundred'
    ];

    foreach ($scales as $value => $label) {
        if ($number >= $value) {
            $major = intdiv($number, $value);
            $remainder = $number % $value;
            $words = convertIntegerToWords($major) . ' ' . $label;
            if ($remainder > 0) {
                $words .= $remainder < 100 ? ' and ' : ' ';
                $words .= convertIntegerToWords($remainder);
            }
            return $words;
        }
    }

    if ($number < 20) {
        return $ones[$number];
    }

    $tenValue = intdiv($number, 10);
    $remainder = $number % 10;
    $words = $tens[$tenValue];

    if ($remainder > 0) {
        $words .= '-' . $ones[$remainder];
    }

    return $words;
}

// Handle print request
$print_view = isset($_GET['print']) && $_GET['print'] == '1';

$page_title = 'Payment Receipt #' . $receipt_no . ' - ' . $school_name;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="<?php echo $school_logo; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem;
        }

        .print-content {
            max-width: 900px;
            margin: 0 auto;
        }

        /* Receipt Card */
        .receipt-card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
            overflow: hidden;
            position: relative;
        }

        .receipt-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 2.5rem;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .receipt-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }

        .school-info {
            display: flex;
            align-items: center;
            gap: 2rem;
            position: relative;
            z-index: 1;
        }

        .school-logo {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: 700;
            color: white;
            border: 3px solid rgba(255,255,255,0.5);
        }

        .school-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            display: block;
        }

        .school-details h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
        }

        .school-details p {
            opacity: 0.9;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0.2rem 0;
        }

        .receipt-badge {
            position: absolute;
            top: 2rem;
            right: 2rem;
            background: rgba(255,255,255,0.2);
            padding: 0.5rem 1.5rem;
            border-radius: 30px;
            font-size: 0.9rem;
            font-weight: 600;
            letter-spacing: 1px;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .receipt-body {
            padding: 2.5rem;
        }

        /* Receipt Title */
        .receipt-title {
            text-align: center;
            margin-bottom: 2rem;
        }

        .receipt-title h2 {
            font-size: 1.8rem;
            color: #2b2d42;
            margin-bottom: 0.5rem;
        }

        .receipt-title p {
            color: #6c757d;
            font-size: 0.95rem;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 16px;
        }

        .info-group {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .info-label {
            font-size: 0.8rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2b2d42;
        }

        .info-value small {
            font-size: 0.85rem;
            color: #6c757d;
            font-weight: normal;
        }

        /* Payment Details */
        .payment-details {
            margin-bottom: 2rem;
        }

        .amount-box {
            background: linear-gradient(135deg, #4cc9f0 0%, #4361ee 100%);
            color: white;
            padding: 2rem;
            border-radius: 16px;
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .amount-label {
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.9;
            margin-bottom: 0.5rem;
        }

        .amount-value {
            font-size: 3.5rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .amount-words {
            font-size: 1rem;
            opacity: 0.9;
            font-style: italic;
        }

        /* Details Table */
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2rem;
        }

        .details-table tr {
            border-bottom: 1px dashed #e9ecef;
        }

        .details-table tr:last-child {
            border-bottom: none;
        }

        .details-table td {
            padding: 1rem;
            color: #2b2d42;
        }

        .details-table td:first-child {
            font-weight: 600;
            color: #6c757d;
            width: 150px;
        }

        .details-table td:last-child {
            font-weight: 500;
        }

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 1.5rem 0;
            background: #f8f9fa;
            border-radius: 12px;
            overflow: hidden;
        }

        .items-table th {
            background: #4361ee;
            color: white;
            padding: 1rem;
            text-align: left;
            font-size: 0.85rem;
            text-transform: uppercase;
        }

        .items-table td {
            padding: 1rem;
            border-bottom: 1px solid #e9ecef;
        }

        .items-table tfoot td {
            background: #e9ecef;
            font-weight: 700;
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .status-completed {
            background: rgba(76, 201, 240, 0.15);
            color: #4cc9f0;
        }

        .status-pending {
            background: rgba(248, 150, 30, 0.15);
            color: #f8961e;
        }

        /* Footer */
        .receipt-footer {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 2px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .footer-note {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .footer-note i {
            color: #4361ee;
        }

        .signature-area {
            text-align: right;
        }

        .signature-line {
            margin-top: 2rem;
            padding-top: 0.5rem;
            border-top: 2px solid #2b2d42;
            min-width: 200px;
            font-size: 0.85rem;
            color: #6c757d;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
        }

        .btn {
            padding: 0.75rem 2rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4361ee, #3f37c9);
            color: white;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(67, 97, 238, 0.4);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid #4361ee;
            color: #4361ee;
        }

        .btn-outline:hover {
            background: #4361ee;
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, #4cc9f0, #3aa8d8);
            color: white;
        }

        /* Print Styles */
        @media print {
            body {
                background: white;
                padding: 0;
            }

            .action-buttons,
            .no-print {
                display: none !important;
            }

            .receipt-card {
                box-shadow: none;
                border: 1px solid #ddd;
            }

            .receipt-header {
                background: #f8f9fa !important;
                color: #2b2d42;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .receipt-header .school-logo {
                background: #e9ecef !important;
                color: #2b2d42;
            }

            .amount-box {
                background: #f8f9fa !important;
                color: #2b2d42;
                border: 1px solid #ddd;
            }

            .items-table th {
                background: #e9ecef !important;
                color: #2b2d42;
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .school-info {
                flex-direction: column;
                text-align: center;
            }

            .receipt-badge {
                position: static;
                margin-top: 1rem;
                text-align: center;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .amount-value {
                font-size: 2.5rem;
            }

            .details-table td:first-child {
                width: 120px;
            }

            .receipt-footer {
                flex-direction: column;
                text-align: center;
            }

            .signature-area {
                text-align: center;
            }

            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="print-content">
        <!-- Receipt Card -->
        <div class="receipt-card">
            <!-- Header -->
            <div class="receipt-header">
                <div class="school-info">
                    <div class="school-logo">
                        <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="<?php echo htmlspecialchars($school_name); ?> logo" onerror="this.style.display='none'; this.parentNode.textContent='<?php echo htmlspecialchars(substr($school_name, 0, 1)); ?>';">
                    </div>
                    <div class="school-details">
                        <h1><?php echo htmlspecialchars($school_name); ?></h1>
                        <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($school_address); ?></p>
                        <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($school_phone); ?> | <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($school_email); ?></p>
                    </div>
                </div>
                <div class="receipt-badge">
                    OFFICIAL RECEIPT
                </div>
            </div>

            <!-- Body -->
            <div class="receipt-body">
                <!-- Receipt Title -->
                <div class="receipt-title">
                    <h2>PAYMENT RECEIPT</h2>
                    <p>Receipt No: <strong><?php echo $receipt_no; ?></strong> | Date: <strong><?php echo date('d F Y', strtotime($payment['payment_display_date'])); ?></strong></p>
                </div>

                <!-- Student & Payment Info Grid -->
                <div class="info-grid">
                    <div class="info-group">
                        <span class="info-label">Student Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($payment['student_name']); ?></span>
                    </div>
                    <div class="info-group">
                        <span class="info-label">Admission Number</span>
                        <span class="info-value"><?php echo htmlspecialchars($payment['admission_number']); ?></span>
                    </div>
                    <div class="info-group">
                        <span class="info-label">Class</span>
                        <span class="info-value"><?php echo htmlspecialchars($payment['class_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-group">
                        <span class="info-label">Parent/Guardian</span>
                        <span class="info-value"><?php echo htmlspecialchars($payment['parent_name'] ?? 'N/A'); ?></span>
                    </div>
                </div>

                <!-- Amount Box -->
                <div class="amount-box">
                    <div class="amount-label">Amount Paid</div>
                    <div class="amount-value">KES <?php echo number_format($payment['amount'], 2); ?></div>
                    <div class="amount-words"><?php echo ucwords($amount_in_words); ?> shillings only</div>
                </div>

                <!-- Payment Details Table -->
                <table class="details-table">
                    <tr>
                        <td>Transaction ID:</td>
                        <td><strong><?php echo htmlspecialchars($payment['transaction_id']); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Payment Method:</td>
                        <td>
                            <strong>
                                <?php if ($payment['payment_method_code'] == 'mpesa'): ?>
                                    <i class="fas fa-mobile-alt"></i> 
                                <?php elseif ($payment['payment_method_code'] == 'bank_transfer'): ?>
                                    <i class="fas fa-university"></i> 
                                <?php elseif ($payment['payment_method_code'] == 'cheque'): ?>
                                    <i class="fas fa-money-check"></i> 
                                <?php else: ?>
                                    <i class="fas fa-money-bill"></i> 
                                <?php endif; ?>
                                <?php echo htmlspecialchars($payment['payment_method_label'] ?? 'Cash'); ?>
                            </strong>
                        </td>
                    </tr>
                    <?php if ($payment['reference_no']): ?>
                    <tr>
                        <td>Reference Number:</td>
                        <td><strong><?php echo htmlspecialchars($payment['reference_no']); ?></strong></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td>Invoice Number:</td>
                        <td><strong>#<?php echo htmlspecialchars($payment['invoice_no'] ?? 'N/A'); ?></strong></td>
                    </tr>
                    <?php if ($payment['structure_name']): ?>
                    <tr>
                        <td>Fee Structure:</td>
                        <td><?php echo htmlspecialchars($payment['structure_name']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td>Payment Status:</td>
                        <td>
                            <span class="status-badge status-<?php echo $payment['status']; ?>">
                                <?php echo ucfirst($payment['status']); ?>
                            </span>
                        </td>
                    </tr>
                </table>

                <!-- Invoice Items (if available) -->
                <?php if (!empty($items)): ?>
                <h3 style="margin: 2rem 0 1rem; color: #2b2d42;">Invoice Items</h3>
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Description</th>
                            <th style="text-align: right;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($item['item_name']); ?>
                                <?php if ($item['is_mandatory']): ?>
                                <span style="font-size: 0.7rem; color: #4cc9f0;"> (Required)</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($item['description'] ?? '-'); ?></td>
                            <td style="text-align: right;">KES <?php echo number_format($item['amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" style="text-align: right; font-weight: 700;">Invoice Total:</td>
                            <td style="text-align: right; font-weight: 700; color: #4361ee;">KES <?php echo number_format($payment['invoice_total'], 2); ?></td>
                        </tr>
                        <tr>
                            <td colspan="2" style="text-align: right;">Previous Balance:</td>
                            <td style="text-align: right;">KES <?php echo number_format($payment['invoice_total'] - $payment['amount'], 2); ?></td>
                        </tr>
                        <tr>
                            <td colspan="2" style="text-align: right;">Amount Paid:</td>
                            <td style="text-align: right; color: #4cc9f0;">KES <?php echo number_format($payment['amount'], 2); ?></td>
                        </tr>
                        <tr>
                            <td colspan="2" style="text-align: right; font-weight: 700;">New Balance:</td>
                            <td style="text-align: right; font-weight: 700; color: <?php echo $payment['invoice_balance'] > 0 ? '#f94144' : '#4cc9f0'; ?>;">
                                KES <?php echo number_format($payment['invoice_balance'], 2); ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <?php endif; ?>

                <!-- Notes -->
                <?php if ($payment['notes']): ?>
                <div style="margin: 1.5rem 0; padding: 1rem; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #4361ee;">
                    <p style="color: #2b2d42; font-style: italic;">
                        <i class="fas fa-sticky-note" style="color: #4361ee; margin-right: 0.5rem;"></i>
                        <?php echo nl2br(htmlspecialchars($payment['notes'])); ?>
                    </p>
                </div>
                <?php endif; ?>

                <!-- Footer -->
                <div class="receipt-footer">
                    <div class="footer-note">
                        <i class="fas fa-check-circle" style="color: #4cc9f0;"></i>
                        This is a computer-generated receipt. No signature required.
                    </div>
                    <div class="signature-area">
                        <div class="signature-line">
                            Received by: <?php echo htmlspecialchars($payment['received_by_name'] ?? 'System'); ?>
                        </div>
                        <p style="font-size: 0.8rem; color: #6c757d; margin-top: 0.3rem;">
                            <?php echo date('d F Y h:i A', strtotime($payment['payment_display_date'])); ?>
                        </p>
                    </div>
                </div>

                <!-- Thank You Note -->
                <div style="text-align: center; margin-top: 2rem; padding: 1rem; background: #f8f9fa; border-radius: 8px;">
                    <p style="color: #2b2d42; font-weight: 500;">
                        <i class="fas fa-heart" style="color: #f94144;"></i>
                        Thank you for your payment!
                        <i class="fas fa-heart" style="color: #f94144;"></i>
                    </p>
                </div>
            </div>
        </div>

        <!-- Action Buttons (Hidden when printing) -->
        <div class="action-buttons no-print">
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print"></i> Print Receipt
            </button>
            <a href="payments.php" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Back to Payments
            </a>
            <?php if ($payment['parent_email']): ?>
            <button class="btn btn-success" onclick="emailReceipt()">
                <i class="fas fa-envelope"></i> Email Receipt
            </button>
            <?php endif; ?>
            <a href="download_receipt.php?id=<?php echo $payment_id; ?>" class="btn btn-outline">
                <i class="fas fa-download"></i> Download PDF
            </a>
        </div>
    </div>

    <!-- Email Modal -->
    <div id="emailModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-envelope"></i> Email Receipt</h3>
                <button class="modal-close" onclick="closeEmailModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="emailForm">
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" id="email_address" class="form-control" 
                               value="<?php echo htmlspecialchars($payment['parent_email']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" id="email_subject" class="form-control" 
                               value="Payment Receipt - <?php echo htmlspecialchars($payment['student_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Message (Optional)</label>
                        <textarea id="email_message" class="form-control" rows="4">Dear <?php echo htmlspecialchars($payment['parent_name'] ?? 'Parent'); ?>,

Please find attached the payment receipt for <?php echo htmlspecialchars($payment['student_name']); ?>.

Payment Details:
- Amount: KES <?php echo number_format($payment['amount'], 2); ?>
- Date: <?php echo date('d F Y', strtotime($payment['payment_display_date'])); ?>
- Transaction ID: <?php echo $payment['transaction_id']; ?>
- Receipt No: <?php echo $receipt_no; ?>

Thank you for your payment.

Regards,
<?php echo htmlspecialchars($school_name); ?> Administration</textarea>
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
            background: white;
            border-radius: 24px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
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
            border-bottom: 2px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
        }

        .modal-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #2b2d42;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6c757d;
            transition: all 0.3s;
        }

        .modal-close:hover {
            color: #f94144;
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 2px solid #e9ecef;
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
            color: #2b2d42;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: #4361ee;
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
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
            border: 3px solid #e9ecef;
            border-top-color: #4361ee;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function emailReceipt() {
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
            formData.append('payment_id', <?php echo $payment_id; ?>);

            fetch('send_receipt_email.php', {
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
                        timer: 2000,
                        showConfirmButton: false
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

        // Auto-print if print parameter is set
        <?php if ($print_view): ?>
        window.onload = function() {
            window.print();
        }
        <?php endif; ?>
    </script>
</body>
</html>
