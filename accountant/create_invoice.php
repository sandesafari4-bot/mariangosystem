<?php
include '../config.php';
checkAuth();
checkRole(['accountant', 'admin']);

$page_title = 'Create Invoice - ' . SCHOOL_NAME;

// Get parameters
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$structure_id = isset($_GET['structure_id']) ? intval($_GET['structure_id']) : 0;

// Get classes for filtering
$classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll();

// Get approved fee structures
$fee_structures = $pdo->query("
    SELECT fs.*, c.class_name 
    FROM fee_structures fs
    JOIN classes c ON fs.class_id = c.id
    WHERE fs.status = 'approved' 
    ORDER BY fs.created_at DESC
")->fetchAll();

// Get students for selection
$students = $pdo->query("
    SELECT s.id, s.full_name, s.admission_number, c.class_name 
    FROM students s
    JOIN classes c ON s.class_id = c.id
    WHERE s.status = 'active'
    ORDER BY s.full_name
")->fetchAll();

// Get structure details if selected
$structure = null;
$structure_items = [];
if ($structure_id) {
    $stmt = $pdo->prepare("
        SELECT fs.*, c.class_name 
        FROM fee_structures fs
        JOIN classes c ON fs.class_id = c.id
        WHERE fs.id = ? AND fs.status = 'approved'
    ");
    $stmt->execute([$structure_id]);
    $structure = $stmt->fetch();
    
    if ($structure) {
        $stmt = $pdo->prepare("
            SELECT * FROM fee_structure_items 
            WHERE fee_structure_id = ? 
            ORDER BY is_mandatory DESC, item_name ASC
        ");
        $stmt->execute([$structure_id]);
        $structure_items = $stmt->fetchAll();
    }
}

// Get student details if selected
$student = null;
if ($student_id) {
    $stmt = $pdo->prepare("
        SELECT s.*, c.class_name, c.id as class_id
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE s.id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => ''];
    
    try {
        $pdo->beginTransaction();
        
        if (isset($_POST['create_invoice'])) {
            // Create single invoice
            $student_id = intval($_POST['student_id']);
            $structure_id = intval($_POST['structure_id']);
            $due_date = $_POST['due_date'];
            $notes = trim($_POST['notes'] ?? '');
            
            // Get student details
            $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch();
            
            if (!$student) {
                throw new Exception('Student not found');
            }
            
            // Get fee structure items
            $stmt = $pdo->prepare("SELECT * FROM fee_structure_items WHERE fee_structure_id = ?");
            $stmt->execute([$structure_id]);
            $items = $stmt->fetchAll();
            
            if (empty($items)) {
                throw new Exception('No items found in fee structure');
            }
            
            // Calculate total amount
            $total_amount = array_sum(array_column($items, 'amount'));
            
            // Generate invoice number
            $year = date('Y');
            $month = date('m');
            $stmt = $pdo->query("SELECT COUNT(*) FROM invoices WHERE invoice_no LIKE 'INV-{$year}{$month}%'");
            $count = $stmt->fetchColumn() + 1;
            $invoice_no = 'INV-' . $year . $month . str_pad($count, 4, '0', STR_PAD_LEFT);
            
            // Check if invoice already exists for this student and structure
            $stmt = $pdo->prepare("SELECT id FROM invoices WHERE student_id = ? AND fee_structure_id = ?");
            $stmt->execute([$student_id, $structure_id]);
            if ($stmt->fetch()) {
                throw new Exception('An invoice already exists for this student and fee structure');
            }
            
            // Create invoice
            $stmt = $pdo->prepare("
                INSERT INTO invoices (
                    invoice_no, student_id, fee_structure_id, student_name,
                    admission_number, class_id, total_amount, amount_paid,
                    balance, due_date, notes, status, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 'unpaid', ?, NOW())
            ");
            
            $stmt->execute([
                $invoice_no,
                $student_id,
                $structure_id,
                $student['full_name'],
                $student['admission_number'],
                $student['class_id'],
                $total_amount,
                $total_amount,
                $due_date,
                $notes,
                $_SESSION['user_id']
            ]);
            
            $invoice_id = $pdo->lastInsertId();
            
            // Insert invoice items
            $item_stmt = $pdo->prepare("
                INSERT INTO invoice_items (
                    invoice_id, item_name, description, amount, is_mandatory
                ) VALUES (?, ?, ?, ?, ?)
            ");
            
            foreach ($items as $item) {
                $item_stmt->execute([
                    $invoice_id,
                    $item['item_name'],
                    $item['description'],
                    $item['amount'],
                    $item['is_mandatory']
                ]);
            }
            
            $pdo->commit();
            
            $_SESSION['success'] = 'Invoice created successfully!';
            $response['success'] = true;
            $response['message'] = 'Invoice created successfully!';
            $response['invoice_id'] = $invoice_id;
            $response['invoice_no'] = $invoice_no;
            
            header("Location: invoice_details.php?id=" . $invoice_id);
            exit();
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
        $response['message'] = $e->getMessage();
    }
    
    // For AJAX requests
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
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

        /* Invoice Type Selection */
        .invoice-types {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .invoice-type-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid transparent;
            box-shadow: var(--shadow-md);
        }

        .invoice-type-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
            border-color: var(--primary);
        }

        .invoice-type-card.active {
            border-color: var(--primary);
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.05), rgba(114, 9, 183, 0.05));
        }

        .invoice-type-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--gradient-1);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            color: white;
            font-size: 2rem;
        }

        .invoice-type-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .invoice-type-desc {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Form Sections */
        .form-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--light);
        }

        .section-title h3 {
            font-size: 1.2rem;
            color: var(--dark);
            font-weight: 600;
        }

        .section-title i {
            color: var(--primary);
        }

        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
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

        .required::after {
            content: " *";
            color: var(--danger);
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

        /* Fee Items */
        .fee-items-container {
            background: var(--light);
            border-radius: var(--border-radius-md);
            padding: 1rem;
            margin: 1rem 0;
        }

        .fee-item {
            background: white;
            border-radius: var(--border-radius-sm);
            padding: 1rem;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border: 1px solid var(--light);
        }

        .fee-item:last-child {
            margin-bottom: 0;
        }

        .fee-item-checkbox {
            width: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .fee-item-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .fee-item-details {
            flex: 1;
        }

        .fee-item-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .fee-item-description {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .fee-item-amount {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary);
            min-width: 120px;
            text-align: right;
        }

        .fee-item-mandatory {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        /* Total Amount */
        .total-amount {
            background: var(--gradient-1);
            color: white;
            padding: 1.5rem;
            border-radius: var(--border-radius-md);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 1.2rem;
            font-weight: 600;
            margin-top: 1rem;
        }

        .total-amount span:last-child {
            font-size: 1.8rem;
        }

        /* Student Info */
        .student-info {
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.05), rgba(114, 9, 183, 0.05));
            border-radius: var(--border-radius-md);
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary);
        }

        .student-info-row {
            display: flex;
            padding: 0.5rem 0;
            border-bottom: 1px dashed var(--light);
        }

        .student-info-row:last-child {
            border-bottom: none;
        }

        .student-info-label {
            width: 120px;
            color: var(--gray);
            font-weight: 500;
        }

        .student-info-value {
            flex: 1;
            font-weight: 600;
            color: var(--dark);
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

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        .btn-lg {
            padding: 1rem 2rem;
            font-size: 1rem;
        }

        .btn-block {
            width: 100%;
            justify-content: center;
        }

        /* Action Bar */
        .action-bar {
            position: sticky;
            bottom: 2rem;
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1rem 2rem;
            box-shadow: var(--shadow-xl);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 2rem;
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
        }

        .action-bar-total {
            font-size: 1.2rem;
            color: var(--dark);
        }

        .action-bar-total span {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--success);
            margin-left: 0.5rem;
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
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .action-bar {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .fee-item {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .fee-item-amount {
                text-align: left;
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
            <h1><i class="fas fa-file-invoice"></i> Create Invoice</h1>
            <p>Generate new invoice for student fees</p>
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

        <!-- Invoice Type Selection -->
        <div class="invoice-types animate">
            <div class="invoice-type-card <?php echo !$structure_id ? 'active' : ''; ?>" onclick="window.location.href='create_invoice.php'">
                <div class="invoice-type-icon">
                    <i class="fas fa-user"></i>
                </div>
                <div class="invoice-type-title">Single Invoice</div>
                <div class="invoice-type-desc">Create invoice for one student</div>
            </div>
            
            <div class="invoice-type-card" onclick="window.location.href='generate_invoices.php'">
                <div class="invoice-type-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="invoice-type-title">Bulk Invoices</div>
                <div class="invoice-type-desc">Generate invoices for multiple students</div>
            </div>
        </div>

        <!-- Invoice Form -->
        <form id="invoiceForm" method="POST" class="animate">
            <input type="hidden" name="create_invoice" value="1">
            
            <!-- Student Selection -->
            <div class="form-section">
                <div class="section-title">
                    <i class="fas fa-user-graduate"></i>
                    <h3>Student Information</h3>
                </div>
                
                <?php if ($student): ?>
                <!-- Selected Student Info -->
                <div class="student-info">
                    <div class="student-info-row">
                        <span class="student-info-label">Student Name</span>
                        <span class="student-info-value"><?php echo htmlspecialchars($student['full_name']); ?></span>
                    </div>
                    <div class="student-info-row">
                        <span class="student-info-label">Admission No.</span>
                        <span class="student-info-value"><?php echo htmlspecialchars($student['admission_number']); ?></span>
                    </div>
                    <div class="student-info-row">
                        <span class="student-info-label">Class</span>
                        <span class="student-info-value"><?php echo htmlspecialchars($student['class_name']); ?></span>
                    </div>
                </div>
                <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                <?php else: ?>
                <!-- Student Selection Dropdown -->
                <div class="form-group">
                    <label class="required">Select Student</label>
                    <select name="student_id" id="student_id" class="form-control" required onchange="loadStudentInvoices()">
                        <option value="">-- Choose Student --</option>
                        <?php foreach ($students as $s): ?>
                        <option value="<?php echo $s['id']; ?>" <?php echo $student_id == $s['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['full_name'] . ' (' . $s['admission_number'] . ') - ' . $s['class_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <!-- Fee Structure Selection -->
            <div class="form-section">
                <div class="section-title">
                    <i class="fas fa-calculator"></i>
                    <h3>Fee Structure</h3>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="required">Select Fee Structure</label>
                        <select name="structure_id" id="structure_id" class="form-control" required onchange="loadStructureItems()">
                            <option value="">-- Choose Fee Structure --</option>
                            <?php foreach ($fee_structures as $fs): ?>
                            <option value="<?php echo $fs['id']; ?>" <?php echo $structure_id == $fs['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($fs['structure_name']); ?> - 
                                <?php echo htmlspecialchars($fs['class_name']); ?> - 
                                Term <?php echo $fs['term']; ?> <?php echo $fs['academic_year_id']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="required">Due Date</label>
                        <input type="date" name="due_date" id="due_date" class="form-control" 
                               value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Notes (Optional)</label>
                    <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="Additional notes for this invoice..."></textarea>
                </div>
            </div>

            <!-- Fee Items -->
            <div class="form-section" id="feeItemsSection" style="<?php echo empty($structure_items) ? 'display: none;' : ''; ?>">
                <div class="section-title">
                    <i class="fas fa-list-ul"></i>
                    <h3>Fee Items</h3>
                </div>
                
                <div class="fee-items-container" id="feeItemsContainer">
                    <?php foreach ($structure_items as $item): ?>
                    <div class="fee-item">
                        <div class="fee-item-checkbox">
                            <input type="checkbox" name="items[<?php echo $item['id']; ?>]" value="1" checked disabled>
                        </div>
                        <div class="fee-item-details">
                            <div class="fee-item-name">
                                <?php echo htmlspecialchars($item['item_name']); ?>
                                <?php if ($item['is_mandatory']): ?>
                                <span class="fee-item-mandatory">REQUIRED</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($item['description'])): ?>
                            <div class="fee-item-description"><?php echo htmlspecialchars($item['description']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="fee-item-amount">KES <?php echo number_format($item['amount'], 2); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="total-amount" id="totalAmount">
                    <span>Total Amount:</span>
                    <span>KES <?php echo number_format(array_sum(array_column($structure_items, 'amount')), 2); ?></span>
                </div>
            </div>

            <!-- Action Bar -->
            <div class="action-bar">
                <div class="action-bar-total">
                    Total: <span id="actionBarTotal">KES <?php echo number_format(array_sum(array_column($structure_items, 'amount')), 2); ?></span>
                </div>
                <div style="display: flex; gap: 1rem;">
                    <a href="invoices.php" class="btn btn-outline">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-success btn-lg" id="submitBtn">
                        <i class="fas fa-file-invoice"></i> Create Invoice
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-spinner"></div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Load structure items via AJAX
        function loadStructureItems() {
            const structureId = document.getElementById('structure_id').value;
            const studentId = document.getElementById('student_id')?.value;
            
            if (!structureId) {
                document.getElementById('feeItemsSection').style.display = 'none';
                return;
            }
            
            document.getElementById('loadingOverlay').classList.add('active');
            
            fetch(`get_structure_items.php?id=${structureId}&student_id=${studentId || 0}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('loadingOverlay').classList.remove('active');
                    
                    if (data.success) {
                        displayFeeItems(data.items);
                        updateTotals(data.items);
                        document.getElementById('feeItemsSection').style.display = 'block';
                        
                        // Check for existing invoice
                        if (data.has_invoice) {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Invoice Already Exists',
                                text: 'An invoice already exists for this student and fee structure',
                                confirmButtonColor: '#4361ee'
                            });
                            
                            // Disable submit button
                            document.getElementById('submitBtn').disabled = true;
                        } else {
                            document.getElementById('submitBtn').disabled = false;
                        }
                    } else {
                        Swal.fire('Error', data.message, 'error');
                    }
                })
                .catch(error => {
                    document.getElementById('loadingOverlay').classList.remove('active');
                    Swal.fire('Error', 'Failed to load fee structure', 'error');
                });
        }

        function displayFeeItems(items) {
            const container = document.getElementById('feeItemsContainer');
            let html = '';
            let total = 0;
            
            items.forEach(item => {
                total += parseFloat(item.amount);
                html += `
                    <div class="fee-item">
                        <div class="fee-item-checkbox">
                            <input type="checkbox" name="items[${item.id}]" value="1" checked disabled>
                        </div>
                        <div class="fee-item-details">
                            <div class="fee-item-name">
                                ${escapeHtml(item.item_name)}
                                ${item.is_mandatory ? '<span class="fee-item-mandatory">REQUIRED</span>' : ''}
                            </div>
                            ${item.description ? `<div class="fee-item-description">${escapeHtml(item.description)}</div>` : ''}
                        </div>
                        <div class="fee-item-amount">KES ${formatNumber(item.amount)}</div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
            updateTotals(items);
        }

        function updateTotals(items) {
            const total = items.reduce((sum, item) => sum + parseFloat(item.amount), 0);
            
            document.getElementById('totalAmount').innerHTML = `
                <span>Total Amount:</span>
                <span>KES ${formatNumber(total)}</span>
            `;
            
            document.getElementById('actionBarTotal').textContent = `KES ${formatNumber(total)}`;
        }

        // Load student invoices (check for existing)
        function loadStudentInvoices() {
            const studentId = document.getElementById('student_id').value;
            const structureId = document.getElementById('structure_id').value;
            
            if (studentId && structureId) {
                loadStructureItems();
            }
        }

        // Form submission
        document.getElementById('invoiceForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const studentId = document.getElementById('student_id')?.value || <?php echo $student_id ?: 'null'; ?>;
            const structureId = document.getElementById('structure_id').value;
            
            if (!studentId) {
                Swal.fire('Error', 'Please select a student', 'error');
                return;
            }
            
            if (!structureId) {
                Swal.fire('Error', 'Please select a fee structure', 'error');
                return;
            }
            
            Swal.fire({
                title: 'Create Invoice?',
                text: 'Are you sure you want to create this invoice?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#4cc9f0',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, create it'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('loadingOverlay').classList.add('active');
                    this.submit();
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

        // Auto-load if structure is pre-selected
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($structure_id && !empty($structure_items)): ?>
            loadStructureItems();
            <?php endif; ?>
        });
    </script>
</body>
</html>