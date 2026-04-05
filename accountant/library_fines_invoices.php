<?php
include '../config.php';
require_once '../library_fines_workflow_helpers.php';
checkAuth();
checkRole(['accountant', 'admin']);

ensureLibraryFineWorkflowSchema($pdo);

$page_title = 'Library Fines Invoices - ' . SCHOOL_NAME;

// Handle invoice generation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['generate_invoice'])) {
            $student_id = intval($_POST['student_id']);
            $invoice = libraryCreateInvoiceForStudent($pdo, $student_id, (int) $_SESSION['user_id']);
            $_SESSION['success'] = "Invoice #{$invoice['invoice_no']} generated successfully!";
            
            header("Location: invoice_details.php?id={$invoice['invoice_id']}");
            exit();
        }

        if (isset($_POST['generate_bulk_invoices'])) {
            $student_ids = $_POST['student_ids'] ?? [];
            if (!is_array($student_ids)) {
                $decoded = json_decode((string) $student_ids, true);
                $student_ids = is_array($decoded) ? $decoded : [];
            }
            
            if (empty($student_ids)) {
                throw new Exception('No students selected');
            }

            $generated_count = 0;
            
            foreach ($student_ids as $student_id) {
                try {
                    libraryCreateInvoiceForStudent($pdo, (int) $student_id, (int) $_SESSION['user_id']);
                    $generated_count++;
                } catch (RuntimeException $e) {
                    // Skip students who no longer have invoiceable charges.
                }
            }

            $_SESSION['success'] = "$generated_count invoices generated successfully!";
        }

    } catch (Exception $e) {
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }

    header('Location: library_fines_invoices.php');
    exit();
}

$chargeRows = array_values(array_filter(
    libraryFetchChargeRows($pdo, ['approved', 'sent_to_accountant', 'verified']),
    fn($row) => empty($row['invoice_id'])
));

$pending_fines = array_values(array_filter($chargeRows, fn($row) => ($row['source_type'] ?? '') === 'fine'));
$pending_lost = array_values(array_filter($chargeRows, fn($row) => ($row['source_type'] ?? '') === 'lost_book'));

$students_items = [];
foreach ($chargeRows as $row) {
    $sid = (int) ($row['student_id'] ?? 0);
    if ($sid <= 0) {
        continue;
    }

    if (!isset($students_items[$sid])) {
        $students_items[$sid] = [
            'student_id' => $sid,
            'student_name' => $row['student_name'] ?? 'Student',
            'admission' => $row['admission_number'] ?? '',
            'class' => $row['class_name'] ?? '',
            'fines' => [],
            'lost' => [],
            'total' => 0,
        ];
    }

    $item = [
        'id' => (int) ($row['charge_id'] ?? 0),
        'book_title' => $row['book_title'] ?? 'Library charge',
        'days_overdue' => (int) ($row['days_overdue'] ?? 0),
        'total_amount' => (float) ($row['amount'] ?? 0),
        'final_amount' => (float) ($row['amount'] ?? 0),
        'amount' => (float) ($row['amount'] ?? 0),
        'status' => $row['status'] ?? '',
        'charge_type' => $row['charge_type'] ?? '',
    ];

    if (($row['source_type'] ?? '') === 'fine') {
        $students_items[$sid]['fines'][] = $item;
    } else {
        $students_items[$sid]['lost'][] = $item;
    }

    $students_items[$sid]['total'] += (float) ($row['amount'] ?? 0);
}

// Get recent invoices
$recent_invoices = $pdo->query("
    SELECT i.*, s.full_name as student_name
    FROM invoices i
    JOIN students s ON i.student_id = s.id
    WHERE i.invoice_no LIKE 'LIB-%'
    ORDER BY i.created_at DESC
    LIMIT 10
")->fetchAll();

$page_title = 'Library Fines Invoicing - ' . SCHOOL_NAME;
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
            --gradient-invoice: linear-gradient(135deg, #4cc9f0 0%, #4361ee 100%);
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
            background: var(--gradient-invoice);
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

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        /* Stats Cards */
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

        .stat-card.students { border-left-color: var(--primary); }
        .stat-card.fines { border-left-color: var(--warning); }
        .stat-card.amount { border-left-color: var(--danger); }
        .stat-card.invoices { border-left-color: var(--success); }

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
        }

        .student-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-xl);
            border-color: var(--primary);
        }

        .card-header {
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .student-name {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.3rem;
        }

        .student-meta {
            font-size: 0.85rem;
            opacity: 0.9;
        }

        .card-body {
            padding: 1.5rem;
        }

        .items-list {
            margin-bottom: 1rem;
        }

        .item-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px dashed var(--light);
        }

        .item-info {
            flex: 1;
        }

        .item-name {
            font-weight: 500;
            color: var(--dark);
        }

        .item-desc {
            font-size: 0.8rem;
            color: var(--gray);
        }

        .item-amount {
            font-weight: 600;
            color: var(--danger);
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 1rem 0;
            font-weight: 700;
            border-top: 2px solid var(--light);
        }

        .total-amount {
            color: var(--success);
            font-size: 1.2rem;
        }

        .card-footer {
            padding: 1rem 1.5rem;
            background: var(--light);
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 0.5rem;
        }

        .checkbox-wrapper {
            padding: 0.5rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-wrapper input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }

        /* Recent Invoices */
        .invoices-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .invoice-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid var(--light);
            text-decoration: none;
            color: inherit;
        }

        .invoice-item:hover {
            background: rgba(67, 97, 238, 0.05);
        }

        .invoice-info {
            flex: 1;
        }

        .invoice-number {
            font-weight: 600;
            color: var(--primary);
        }

        .invoice-student {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .invoice-amount {
            font-weight: 600;
            color: var(--success);
        }

        /* Data Cards */
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
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .card-footer {
                flex-direction: column;
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
                <h1><i class="fas fa-file-invoice"></i> Library Fines Invoicing</h1>
                <p>Generate invoices for approved library fines and lost books</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-light" onclick="generateBulkInvoices()">
                    <i class="fas fa-layer-group"></i> Bulk Generate
                </button>
                <a href="fines.php" class="btn btn-outline" style="color: white; border-color: white;">
                    <i class="fas fa-arrow-left"></i> Back to Fines
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
            <div class="stat-card students stagger-item">
                <div class="stat-number"><?php echo count($students_items); ?></div>
                <div class="stat-label">Students with Charges</div>
            </div>
            <div class="stat-card fines stagger-item">
                <div class="stat-number"><?php echo count($pending_fines) + count($pending_lost); ?></div>
                <div class="stat-label">Pending Items</div>
            </div>
            <div class="stat-card amount stagger-item">
                <div class="stat-number">KES <?php echo number_format(array_sum(array_column($students_items, 'total')), 2); ?></div>
                <div class="stat-label">Total Amount</div>
            </div>
            <div class="stat-card invoices stagger-item">
                <div class="stat-number"><?php echo count($recent_invoices); ?></div>
                <div class="stat-label">Recent Invoices</div>
            </div>
        </div>

        <!-- Students with Pending Charges -->
        <?php if (!empty($students_items)): ?>
            <h2 style="margin-bottom: 1rem; color: var(--dark);">Pending Library Charges by Student</h2>
            <div class="students-grid">
                <?php foreach ($students_items as $student): ?>
                <div class="student-card" id="student-<?php echo $student['student_id']; ?>">
                    <div class="card-header">
                        <div class="student-name"><?php echo htmlspecialchars($student['student_name']); ?></div>
                        <div class="student-meta">
                            <?php echo $student['admission']; ?> • <?php echo $student['class']; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="items-list">
                            <?php foreach ($student['fines'] as $fine): ?>
                            <div class="item-row">
                                <div class="item-info">
                                    <div class="item-name">Overdue Fine</div>
                                    <div class="item-desc"><?php echo htmlspecialchars($fine['book_title']); ?> - <?php echo $fine['days_overdue']; ?> days</div>
                                </div>
                                <div class="item-amount">KES <?php echo number_format($fine['final_amount'], 2); ?></div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php foreach ($student['lost'] as $lost): ?>
                            <div class="item-row">
                                <div class="item-info">
                                    <div class="item-name">Lost Book Replacement</div>
                                    <div class="item-desc"><?php echo htmlspecialchars($lost['book_title']); ?></div>
                                </div>
                                <div class="item-amount">KES <?php echo number_format($lost['total_amount'], 2); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="total-row">
                            <span>Total Due:</span>
                            <span class="total-amount">KES <?php echo number_format($student['total'], 2); ?></span>
                        </div>
                        
                        <div class="checkbox-wrapper">
                            <input type="checkbox" id="select-<?php echo $student['student_id']; ?>" 
                                   class="student-select" value="<?php echo $student['student_id']; ?>"
                                   onchange="updateSelection()">
                            <label for="select-<?php echo $student['student_id']; ?>">Select for bulk invoicing</label>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-primary btn-sm" onclick="generateInvoice(<?php echo $student['student_id']; ?>)">
                            <i class="fas fa-file-invoice"></i> Generate Invoice
                        </button>
                        <button class="btn btn-outline btn-sm" onclick="viewItems(<?php echo $student['student_id']; ?>)">
                            <i class="fas fa-eye"></i> View Items
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Bulk Actions -->
            <div style="margin: 1rem 0; padding: 1rem; background: var(--light); border-radius: var(--border-radius-md); display: flex; gap: 1rem; align-items: center;">
                <span id="selectedCount">0 students selected</span>
                <button class="btn btn-primary" onclick="generateSelectedInvoices()" id="generateBulkBtn" disabled>
                    <i class="fas fa-layer-group"></i> Generate Invoices for Selected
                </button>
                <button class="btn btn-outline" onclick="selectAll()">Select All</button>
                <button class="btn btn-outline" onclick="deselectAll()">Deselect All</button>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 3rem; background: var(--white); border-radius: var(--border-radius-lg);">
                <i class="fas fa-check-circle fa-3x" style="color: var(--success); margin-bottom: 1rem;"></i>
                <h3>No Pending Charges</h3>
                <p style="color: var(--gray);">All approved library fines and lost books have been invoiced.</p>
            </div>
        <?php endif; ?>

        <!-- Recent Invoices -->
        <div class="data-card animate">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Recent Library Invoices</h3>
                <a href="invoices.php" class="btn btn-light btn-sm">View All</a>
            </div>
            <div class="invoices-list">
                <?php if (!empty($recent_invoices)): ?>
                    <?php foreach ($recent_invoices as $invoice): ?>
                    <a href="invoice_details.php?id=<?php echo $invoice['id']; ?>" class="invoice-item">
                        <div class="invoice-info">
                            <div class="invoice-number">#<?php echo htmlspecialchars($invoice['invoice_no']); ?></div>
                            <div class="invoice-student"><?php echo htmlspecialchars($invoice['student_name']); ?></div>
                        </div>
                        <div class="invoice-amount">KES <?php echo number_format($invoice['total_amount'], 2); ?></div>
                    </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 2rem; color: var(--gray);">
                        <p>No library invoices generated yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Hidden form for invoice generation -->
    <form id="invoiceForm" method="POST" style="display: none;">
        <input type="hidden" name="generate_invoice" value="1">
        <input type="hidden" name="student_id" id="formStudentId">
        <input type="hidden" name="items" id="formItems">
    </form>

    <!-- Hidden form for bulk generation -->
    <form id="bulkForm" method="POST" style="display: none;">
        <input type="hidden" name="generate_bulk_invoices" value="1">
        <input type="hidden" name="student_ids" id="formStudentIds">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const studentsData = <?php echo json_encode($students_items); ?>;
        let selectedStudents = new Set();

        function updateSelection() {
            selectedStudents.clear();
            document.querySelectorAll('.student-select:checked').forEach(cb => {
                selectedStudents.add(parseInt(cb.value));
            });
            
            document.getElementById('selectedCount').textContent = selectedStudents.size + ' student(s) selected';
            document.getElementById('generateBulkBtn').disabled = selectedStudents.size === 0;
        }

        function selectAll() {
            document.querySelectorAll('.student-select').forEach(cb => {
                cb.checked = true;
            });
            updateSelection();
        }

        function deselectAll() {
            document.querySelectorAll('.student-select').forEach(cb => {
                cb.checked = false;
            });
            updateSelection();
        }

        function generateInvoice(studentId) {
            const student = Object.values(studentsData).find(s => s.student_id == studentId);
            
            if (!student) return;
            
            const items = [];
            
            student.fines.forEach(fine => {
                items.push({
                    type: 'fine',
                    id: fine.id,
                    name: 'Library Overdue Fine',
                    description: `Fine for overdue book: ${fine.book_title} (${fine.days_overdue} days)`,
                    amount: fine.final_amount
                });
            });
            
            student.lost.forEach(lost => {
                items.push({
                    type: 'lost',
                    id: lost.id,
                    name: 'Lost Book Replacement',
                    description: `Replacement for lost book: ${lost.book_title}`,
                    amount: lost.total_amount
                });
            });
            
            Swal.fire({
                title: 'Generate Invoice?',
                html: `Create invoice for <strong>${student.student_name}</strong> with total of <strong>KES ${formatNumber(student.total)}</strong>?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#4361ee',
                confirmButtonText: 'Generate Invoice',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('formStudentId').value = studentId;
                    document.getElementById('formItems').value = JSON.stringify(items);
                    document.getElementById('invoiceForm').submit();
                }
            });
        }

        function generateSelectedInvoices() {
            if (selectedStudents.size === 0) return;
            
            Swal.fire({
                title: 'Generate Bulk Invoices?',
                html: `Create invoices for <strong>${selectedStudents.size} students</strong>?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#4361ee',
                confirmButtonText: 'Generate',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('formStudentIds').value = JSON.stringify(Array.from(selectedStudents));
                    document.getElementById('bulkForm').submit();
                }
            });
        }

        function generateBulkInvoices() {
            if (selectedStudents.size > 0) {
                generateSelectedInvoices();
            } else {
                selectAll();
                generateSelectedInvoices();
            }
        }

        function viewItems(studentId) {
            const student = Object.values(studentsData).find(s => s.student_id == studentId);
            
            if (!student) return;
            
            let itemsHtml = '';
            
            student.fines.forEach(fine => {
                itemsHtml += `
                    <tr>
                        <td>Overdue Fine</td>
                        <td>${escapeHtml(fine.book_title)} (${fine.days_overdue} days)</td>
                        <td style="text-align: right;">KES ${formatNumber(fine.final_amount)}</td>
                    </tr>
                `;
            });
            
            student.lost.forEach(lost => {
                itemsHtml += `
                    <tr>
                        <td>Lost Book</td>
                        <td>${escapeHtml(lost.book_title)}</td>
                        <td style="text-align: right;">KES ${formatNumber(lost.total_amount)}</td>
                    </tr>
                `;
            });
            
            Swal.fire({
                title: `Items for ${student.student_name}`,
                html: `
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 2px solid #ddd;">
                                <th style="padding: 0.5rem; text-align: left;">Type</th>
                                <th style="padding: 0.5rem; text-align: left;">Description</th>
                                <th style="padding: 0.5rem; text-align: right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${itemsHtml}
                        </tbody>
                        <tfoot>
                            <tr style="border-top: 2px solid #ddd; font-weight: 700;">
                                <td colspan="2" style="padding: 0.5rem;">Total</td>
                                <td style="padding: 0.5rem; text-align: right; color: #f94144;">KES ${formatNumber(student.total)}</td>
                            </tr>
                        </tfoot>
                    </table>
                `,
                width: '600px',
                confirmButtonColor: '#4361ee',
                confirmButtonText: 'Close'
            });
        }

        function formatNumber(num) {
            return parseFloat(num).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
