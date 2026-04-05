<?php
include '../config.php';
checkAuth();
checkRole(['admin', 'accountant']);

function printInvoiceTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        $cache[$table] = (bool) $stmt->fetchColumn();
    }
    return $cache[$table];
}

function printInvoiceColumns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $cache[$table] = [];
        if (printInvoiceTableExists($pdo, $table)) {
            $cache[$table] = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        }
    }
    return $cache[$table];
}

function printInvoiceHasColumn(PDO $pdo, string $table, string $column): bool
{
    return in_array($column, printInvoiceColumns($pdo, $table), true);
}

function printInvoiceNumberToWords($number): string
{
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

    if (!function_exists('convertIntegerToWords')) {
        function convertIntegerToWords($number) {
            $number = (int) $number;
            if ($number === 0) {
                return 'zero';
            }

            $ones = [
                0 => '', 1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four',
                5 => 'five', 6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine',
                10 => 'ten', 11 => 'eleven', 12 => 'twelve', 13 => 'thirteen', 14 => 'fourteen',
                15 => 'fifteen', 16 => 'sixteen', 17 => 'seventeen', 18 => 'eighteen', 19 => 'nineteen'
            ];
            $tens = [2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty', 6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety'];
            $scales = [1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand', 100 => 'hundred'];

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
            return $tens[$tenValue] . ($remainder > 0 ? '-' . $ones[$remainder] : '');
        }
    }

    $words = convertIntegerToWords($whole);
    if ($cents > 0) {
        $words .= ' point ' . convertIntegerToWords($cents);
    }
    return $words;
}

// Get invoice ID from URL
$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$invoice_id) {
    die('Invalid invoice ID');
}

$student_columns = printInvoiceColumns($pdo, 'students');
$student_column_lookup = array_fill_keys($student_columns, true);
$payment_columns = printInvoiceColumns($pdo, 'payments');
$payment_column_lookup = array_fill_keys($payment_columns, true);
$invoice_item_columns = printInvoiceColumns($pdo, 'invoice_items');
$invoice_item_lookup = array_fill_keys($invoice_item_columns, true);

$admission_column = isset($student_column_lookup['admission_number'])
    ? 'admission_number'
    : (isset($student_column_lookup['Admission_number']) ? 'Admission_number' : null);
$admission_expr = $admission_column ? "s.`{$admission_column}`" : "''";

$parent_join = '';
$parent_name_expr = "s.parent_name";
$parent_phone_expr = "s.parent_phone";
$parent_email_expr = "NULL";
if (
    printInvoiceTableExists($pdo, 'parents') &&
    isset($student_column_lookup['parent_id']) &&
    printInvoiceHasColumn($pdo, 'parents', 'id')
) {
    $parent_join = 'LEFT JOIN parents p ON s.parent_id = p.id';
    $parent_name_expr = printInvoiceHasColumn($pdo, 'parents', 'full_name')
        ? "COALESCE(p.full_name, s.parent_name)"
        : "s.parent_name";
    $parent_phone_expr = printInvoiceHasColumn($pdo, 'parents', 'phone')
        ? "COALESCE(p.phone, s.parent_phone)"
        : "s.parent_phone";
    $parent_email_expr = printInvoiceHasColumn($pdo, 'parents', 'email') ? 'p.email' : 'NULL';
}

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

// Get invoice details with comprehensive information
$stmt = $pdo->prepare("
    SELECT 
        i.*,
        s.id as student_id,
        s.full_name as student_name,
        {$admission_expr} as admission_number,
        s.address,
        {$parent_name_expr} as parent_name,
        {$parent_phone_expr} as parent_phone,
        {$parent_email_expr} as parent_email,
        c.class_name,
        c.id as class_id,
        fs.structure_name,
        fs.term,
        fs.academic_year_id as academic_year,
        u.full_name as created_by_name,
        DATEDIFF(CURDATE(), i.due_date) as days_overdue,
        CASE 
            WHEN i.status = 'paid' THEN 'Paid'
            WHEN i.status = 'unpaid' AND i.due_date < CURDATE() THEN 'Overdue'
            WHEN i.status = 'partial' AND i.due_date < CURDATE() THEN 'Overdue'
            ELSE i.status
        END as display_status
    FROM invoices i
    JOIN students s ON i.student_id = s.id
    {$parent_join}
    LEFT JOIN classes c ON i.class_id = c.id
    LEFT JOIN fee_structures fs ON i.fee_structure_id = fs.id
    LEFT JOIN users u ON i.created_by = u.id
    WHERE i.id = ?
");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    die('Invoice not found');
}

// Get invoice items
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
$stmt->execute([$invoice_id]);
$items = $stmt->fetchAll();

// Get payment history for this invoice
$stmt = $pdo->prepare("
    SELECT 
        p.*,
        {$payment_method_label_expr} as payment_method_label,
        u.full_name as recorded_by_name,
        p.`{$payment_date_column}` as payment_display_date
    FROM payments p
    {$payment_method_join}
    LEFT JOIN users u ON p.`{$payment_user_column}` = u.id
    WHERE p.invoice_id = ?
    ORDER BY p.`{$payment_date_column}` DESC
");
$stmt->execute([$invoice_id]);
$payments = $stmt->fetchAll();

// Calculate totals
$total_paid = array_sum(array_column($payments, 'amount'));
$payment_count = count($payments);
$progress_percentage = $invoice['total_amount'] > 0 
    ? ($invoice['amount_paid'] / $invoice['total_amount']) * 100 
    : 0;

// Get school settings from saved system settings first
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

$invoice['display_status'] = $invoice['display_status'] ?? ($invoice['status'] ?? 'unpaid');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #<?php echo htmlspecialchars($invoice['invoice_no']); ?> - <?php echo $school_name; ?></title>
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
            background: #f8f9fa;
            padding: 2rem;
            line-height: 1.6;
        }

        .invoice-container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        /* Header */
        .invoice-header {
            background: linear-gradient(135deg, #2b2d42 0%, #4361ee 100%);
            padding: 2.5rem;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .invoice-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            position: relative;
            z-index: 1;
            flex-wrap: wrap;
            gap: 2rem;
        }

        .school-info {
            display: flex;
            align-items: center;
            gap: 1.5rem;
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
            border: 3px solid rgba(255,255,255,0.3);
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
            font-size: 0.9rem;
            margin: 0.2rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .invoice-badge {
            background: rgba(255,255,255,0.2);
            padding: 0.75rem 2rem;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: 600;
            letter-spacing: 1px;
            border: 1px solid rgba(255,255,255,0.3);
        }

        /* Status Bar */
        .status-bar {
            background: #f8f9fa;
            padding: 1rem 2.5rem;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .status-badge {
            display: inline-block;
            padding: 0.5rem 1.5rem;
            border-radius: 30px;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-paid {
            background: rgba(76, 201, 240, 0.15);
            color: #4cc9f0;
            border: 1px solid rgba(76, 201, 240, 0.3);
        }

        .status-unpaid {
            background: rgba(248, 150, 30, 0.15);
            color: #f8961e;
            border: 1px solid rgba(248, 150, 30, 0.3);
        }

        .status-partial {
            background: rgba(114, 9, 183, 0.15);
            color: #7209b7;
            border: 1px solid rgba(114, 9, 183, 0.3);
        }

        .status-overdue {
            background: rgba(249, 65, 68, 0.15);
            color: #f94144;
            border: 1px solid rgba(249, 65, 68, 0.3);
        }

        .invoice-meta {
            display: flex;
            gap: 2rem;
            color: #6c757d;
            font-size: 0.9rem;
        }

        .invoice-meta i {
            margin-right: 0.3rem;
            color: #4361ee;
        }

        /* Body */
        .invoice-body {
            padding: 2.5rem;
        }

        /* Address Section */
        .address-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px dashed #e9ecef;
        }

        .bill-to h3,
        .ship-to h3 {
            font-size: 1rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .bill-to p,
        .ship-to p {
            color: #2b2d42;
            line-height: 1.6;
        }

        .bill-to strong,
        .ship-to strong {
            font-size: 1.1rem;
            color: #2b2d42;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 12px;
        }

        .info-item {
            text-align: center;
        }

        .info-label {
            font-size: 0.75rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.3rem;
        }

        .info-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2b2d42;
        }

        .info-value small {
            font-size: 0.8rem;
            color: #6c757d;
            font-weight: normal;
        }

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 2rem 0;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .items-table th {
            background: #4361ee;
            color: white;
            padding: 1rem;
            text-align: left;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .items-table th:last-child {
            text-align: right;
        }

        .items-table td {
            padding: 1rem;
            border-bottom: 1px solid #e9ecef;
            color: #2b2d42;
        }

        .items-table td:last-child {
            text-align: right;
            font-weight: 600;
        }

        .items-table tbody tr:hover {
            background: #f8f9fa;
        }

        .items-table tfoot td {
            padding: 1rem;
            background: #f8f9fa;
            font-weight: 600;
        }

        .items-table tfoot td:last-child {
            font-weight: 700;
            color: #4361ee;
        }

        .mandatory-badge {
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            background: rgba(76, 201, 240, 0.15);
            color: #4cc9f0;
            border-radius: 4px;
            margin-left: 0.5rem;
            font-weight: 500;
        }

        /* Payment Summary */
        .payment-summary {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin: 2rem 0;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 12px;
        }

        .payment-history h3,
        .amount-summary h3 {
            font-size: 1rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 1rem;
        }

        .payment-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px dashed #e9ecef;
        }

        .payment-item:last-child {
            border-bottom: none;
        }

        .payment-date {
            font-size: 0.9rem;
            color: #6c757d;
        }

        .payment-amount {
            font-weight: 600;
            color: #4cc9f0;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px dashed #e9ecef;
        }

        .summary-row:last-child {
            border-bottom: none;
            font-weight: 700;
            font-size: 1.1rem;
            color: #2b2d42;
        }

        .summary-label {
            color: #6c757d;
        }

        .summary-value {
            font-weight: 600;
        }

        .balance-due {
            color: #f94144;
        }

        /* Progress Bar */
        .progress-section {
            margin: 2rem 0;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            color: #6c757d;
            font-size: 0.9rem;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4cc9f0, #4361ee);
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        /* Notes */
        .notes-section {
            margin: 2rem 0;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 12px;
            border-left: 4px solid #4361ee;
        }

        .notes-section h3 {
            font-size: 1rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .notes-content {
            color: #2b2d42;
            line-height: 1.6;
            white-space: pre-line;
        }

        /* Footer */
        .invoice-footer {
            padding: 2rem 2.5rem;
            border-top: 2px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            background: #f8f9fa;
        }

        .footer-note {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .footer-note i {
            color: #4361ee;
            margin-right: 0.3rem;
        }

        .signature-area {
            text-align: right;
        }

        .signature-line {
            margin-top: 1rem;
            padding-top: 0.5rem;
            border-top: 2px solid #2b2d42;
            min-width: 200px;
            font-size: 0.85rem;
            color: #6c757d;
        }

        /* Terms */
        .terms {
            padding: 1.5rem 2.5rem;
            background: white;
            font-size: 0.85rem;
            color: #6c757d;
            border-top: 1px solid #e9ecef;
        }

        .terms p {
            margin: 0.3rem 0;
        }

        /* Print Styles */
        @media print {
            body {
                background: white;
                padding: 0.5in;
            }

            .invoice-container {
                box-shadow: none;
                border: 1px solid #ddd;
            }

            .invoice-header {
                background: #f8f9fa !important;
                color: #2b2d42;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .invoice-header .school-logo {
                background: #e9ecef !important;
                color: #2b2d42;
            }

            .status-bar {
                background: white;
                border-bottom: 1px solid #ddd;
            }

            .items-table th {
                background: #e9ecef !important;
                color: #2b2d42;
            }

            .progress-fill {
                background: #4cc9f0 !important;
            }

            .no-print {
                display: none !important;
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .header-content {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }

            .school-info {
                flex-direction: column;
                text-align: center;
            }

            .address-section {
                grid-template-columns: 1fr;
            }

            .info-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .payment-summary {
                grid-template-columns: 1fr;
            }

            .invoice-footer {
                flex-direction: column;
                text-align: center;
            }

            .signature-area {
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .info-grid {
                grid-template-columns: 1fr;
            }

            .items-table {
                font-size: 0.85rem;
            }

            .items-table th,
            .items-table td {
                padding: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <!-- Header -->
        <div class="invoice-header">
                <div class="header-content">
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
                <div class="invoice-badge">
                    INVOICE
                </div>
            </div>
        </div>

        <!-- Status Bar -->
        <div class="status-bar">
            <span class="status-badge status-<?php echo strtolower($invoice['display_status']); ?>">
                <?php echo $invoice['display_status']; ?>
            </span>
            <div class="invoice-meta">
                <span><i class="fas fa-hashtag"></i> Invoice #<?php echo htmlspecialchars($invoice['invoice_no']); ?></span>
                <span><i class="fas fa-calendar"></i> Date: <?php echo date('d M Y', strtotime($invoice['created_at'])); ?></span>
                <span><i class="fas fa-clock"></i> Due: <?php echo date('d M Y', strtotime($invoice['due_date'])); ?></span>
            </div>
        </div>

        <!-- Body -->
        <div class="invoice-body">
            <!-- Address Section -->
            <div class="address-section">
                <div class="bill-to">
                    <h3>Bill To:</h3>
                    <p>
                        <strong><?php echo htmlspecialchars($invoice['student_name']); ?></strong><br>
                        <?php echo htmlspecialchars($invoice['admission_number']); ?><br>
                        <?php echo htmlspecialchars($invoice['class_name'] ?? 'N/A'); ?><br>
                        <?php if ($invoice['parent_name']): ?>
                            Parent: <?php echo htmlspecialchars($invoice['parent_name']); ?><br>
                        <?php endif; ?>
                        <?php if ($invoice['parent_phone']): ?>
                            Phone: <?php echo htmlspecialchars($invoice['parent_phone']); ?><br>
                        <?php endif; ?>
                        <?php if ($invoice['parent_email']): ?>
                            Email: <?php echo htmlspecialchars($invoice['parent_email']); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="ship-to">
                    <h3>Invoice Details:</h3>
                    <p>
                        <strong>Invoice Date:</strong> <?php echo date('d F Y', strtotime($invoice['created_at'])); ?><br>
                        <strong>Due Date:</strong> <?php echo date('d F Y', strtotime($invoice['due_date'])); ?><br>
                        <?php if ($invoice['days_overdue'] > 0 && $invoice['status'] != 'paid'): ?>
                            <strong style="color: #f94144;">Overdue by <?php echo $invoice['days_overdue']; ?> days</strong><br>
                        <?php endif; ?>
                        <strong>Created By:</strong> <?php echo htmlspecialchars($invoice['created_by_name'] ?? 'System'); ?>
                    </p>
                </div>
            </div>

            <!-- Info Grid -->
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Total Amount</div>
                    <div class="info-value">KES <?php echo number_format($invoice['total_amount'], 2); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Amount Paid</div>
                    <div class="info-value" style="color: #4cc9f0;">KES <?php echo number_format($invoice['amount_paid'], 2); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Balance Due</div>
                    <div class="info-value" style="color: <?php echo $invoice['balance'] > 0 ? '#f94144' : '#4cc9f0'; ?>;">
                        KES <?php echo number_format($invoice['balance'], 2); ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Fee Structure</div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($invoice['structure_name'] ?? 'N/A'); ?>
                        <?php if ($invoice['term']): ?>
                        <br><small>Term <?php echo $invoice['term']; ?> <?php echo $invoice['academic_year']; ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Items Table -->
            <h3 style="margin-bottom: 1rem; color: #2b2d42;">Fee Items</h3>
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
                        <td><?php echo number_format($item['amount'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" style="text-align: right; font-weight: 700;">Subtotal:</td>
                        <td><?php echo number_format($invoice['total_amount'], 2); ?></td>
                    </tr>
                    <?php if ($invoice['amount_paid'] > 0): ?>
                    <tr>
                        <td colspan="2" style="text-align: right;">Less: Payments</td>
                        <td style="color: #4cc9f0;">- <?php echo number_format($invoice['amount_paid'], 2); ?></td>
                    </tr>
                    <tr>
                        <td colspan="2" style="text-align: right; font-weight: 700;">Balance Due:</td>
                        <td style="font-weight: 700; color: <?php echo $invoice['balance'] > 0 ? '#f94144' : '#4cc9f0'; ?>;">
                            <?php echo number_format($invoice['balance'], 2); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tfoot>
            </table>

            <!-- Amount in Words -->
            <?php if ($invoice['balance'] > 0): ?>
            <p style="margin: 1rem 0; font-style: italic; color: #6c757d;">
                <i class="fas fa-pencil-alt"></i> Amount in words: <strong><?php echo ucwords(printInvoiceNumberToWords($invoice['balance'])); ?> shillings only</strong>
            </p>
            <?php endif; ?>

            <!-- Payment Summary -->
            <?php if (!empty($payments)): ?>
            <div class="payment-summary">
                <div class="payment-history">
                    <h3><i class="fas fa-history"></i> Payment History</h3>
                    <?php foreach ($payments as $payment): ?>
                    <div class="payment-item">
                        <span class="payment-date">
                            <?php echo date('d M Y', strtotime($payment['payment_display_date'])); ?>
                            <small>(<?php echo htmlspecialchars($payment['payment_method_label'] ?? 'Cash'); ?>)</small>
                        </span>
                        <span class="payment-amount">KES <?php echo number_format($payment['amount'], 2); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="amount-summary">
                    <h3><i class="fas fa-calculator"></i> Summary</h3>
                    <div class="summary-row">
                        <span class="summary-label">Total Payments:</span>
                        <span class="summary-value"><?php echo $payment_count; ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Total Paid:</span>
                        <span class="summary-value" style="color: #4cc9f0;">KES <?php echo number_format($total_paid, 2); ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Remaining:</span>
                        <span class="summary-value balance-due">KES <?php echo number_format($invoice['balance'], 2); ?></span>
                    </div>
                </div>
            </div>

            <!-- Progress Bar -->
            <div class="progress-section">
                <div class="progress-label">
                    <span>Payment Progress</span>
                    <span><?php echo number_format($progress_percentage, 1); ?>%</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $progress_percentage; ?>%;"></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Notes -->
            <?php if (!empty($invoice['notes'])): ?>
            <div class="notes-section">
                <h3><i class="fas fa-sticky-note"></i> Notes</h3>
                <div class="notes-content">
                    <?php echo nl2br(htmlspecialchars($invoice['notes'])); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="invoice-footer">
            <div class="footer-note">
                <i class="fas fa-check-circle"></i>
                This is a computer-generated invoice. No signature required.
            </div>
            <div class="signature-area">
                <div class="signature-line">
                    Authorized Signature
                </div>
                <p style="font-size: 0.8rem; color: #6c757d; margin-top: 0.3rem;">
                    For <?php echo htmlspecialchars($school_name); ?>
                </p>
            </div>
        </div>

        <!-- Terms -->
        <div class="terms">
            <p><i class="fas fa-info-circle"></i> <strong>Terms & Conditions:</strong></p>
            <p>• Payment is due by the due date shown above.</p>
            <p>• Late payments may be subject to additional fees or penalties.</p>
            <p>• For any queries regarding this invoice, please contact the finance office.</p>
            <p>• This invoice is valid only for the academic year and term specified.</p>
        </div>
    </div>

    <!-- Print Button (hidden when printing) -->
    <div style="text-align: center; margin-top: 2rem;" class="no-print">
        <button onclick="window.print()" style="
            padding: 1rem 2rem;
            background: linear-gradient(135deg, #4361ee, #3f37c9);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
            transition: all 0.3s;
        ">
            <i class="fas fa-print"></i> Print Invoice
        </button>
        <button onclick="window.close()" style="
            padding: 1rem 2rem;
            background: white;
            color: #4361ee;
            border: 2px solid #4361ee;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-left: 1rem;
            transition: all 0.3s;
        ">
            <i class="fas fa-times"></i> Close
        </button>
    </div>

    <script>
        // Auto-print if print parameter is set
        <?php if (isset($_GET['print']) && $_GET['print'] == 1): ?>
        window.onload = function() {
            window.print();
        }
        <?php endif; ?>
    </script>
</body>
</html>
