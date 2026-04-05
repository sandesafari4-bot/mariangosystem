<?php
include '../config.php';
checkAuth();
checkRole(['accountant', 'admin']);

$page_title = 'Bulk Invoice Generation - ' . SCHOOL_NAME;

// Get filter parameters
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$structure_id = isset($_GET['structure_id']) ? intval($_GET['structure_id']) : 0;

// Get classes for dropdown
$classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll();

// Get approved fee structures
$fee_structures = $pdo->query("
    SELECT fs.*, c.class_name 
    FROM fee_structures fs
    JOIN classes c ON fs.class_id = c.id
    WHERE fs.status = 'approved' 
    ORDER BY fs.created_at DESC
")->fetchAll();

// Get students if class selected
$students = [];
if ($class_id) {
    $stmt = $pdo->prepare("
        SELECT s.*, c.class_name 
        FROM students s
        JOIN classes c ON s.class_id = c.id
        WHERE s.class_id = ? AND s.status = 'active'
        ORDER BY s.full_name
    ");
    $stmt->execute([$class_id]);
    $students = $stmt->fetchAll();
}

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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_bulk'])) {
    try {
        $pdo->beginTransaction();
        
        $structure_id = intval($_POST['structure_id']);
        $class_id = intval($_POST['class_id']);
        $due_date = $_POST['due_date'];
        $selected_students = $_POST['selected_students'] ?? [];
        
        if (empty($selected_students)) {
            throw new Exception('Please select at least one student');
        }
        
        // Get fee structure items
        $stmt = $pdo->prepare("SELECT * FROM fee_structure_items WHERE fee_structure_id = ?");
        $stmt->execute([$structure_id]);
        $items = $stmt->fetchAll();
        
        if (empty($items)) {
            throw new Exception('No items found in fee structure');
        }
        
        $total_amount = array_sum(array_column($items, 'amount'));
        $generated_count = 0;
        $skipped_count = 0;
        
        foreach ($selected_students as $student_id) {
            // Check if invoice already exists
            $stmt = $pdo->prepare("SELECT id FROM invoices WHERE student_id = ? AND fee_structure_id = ?");
            $stmt->execute([$student_id, $structure_id]);
            
            if (!$stmt->fetch()) {
                // Get student details
                $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
                $stmt->execute([$student_id]);
                $student = $stmt->fetch();
                
                // Generate invoice number
                $year = date('Y');
                $month = date('m');
                $count_stmt = $pdo->query("SELECT COUNT(*) FROM invoices WHERE invoice_no LIKE 'INV-{$year}{$month}%'");
                $count = $count_stmt->fetchColumn() + 1;
                $invoice_no = 'INV-' . $year . $month . str_pad($count, 4, '0', STR_PAD_LEFT);
                
                // Create invoice
                $stmt = $pdo->prepare("
                    INSERT INTO invoices (
                        invoice_no, student_id, fee_structure_id,
                        Admission_number, class_id, total_amount, amount_paid,
                        balance, due_date, status, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 'unpaid', ?, NOW())
                ");
                
                $stmt->execute([
                    $invoice_no,
                    $student_id,
                    $structure_id,
                    $student['Admission_number'],
                    $student['class_id'],
                    $total_amount,
                    $total_amount,
                    $due_date,
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
                
                $generated_count++;
            } else {
                $skipped_count++;
            }
        }
        
        $pdo->commit();
        
        $_SESSION['success'] = "Generated {$generated_count} invoices successfully!" . 
                              ($skipped_count > 0 ? " Skipped {$skipped_count} existing invoices." : "");
        
        header("Location: invoices.php");
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
        header("Location: generate_invoices.php?class_id={$class_id}&structure_id={$structure_id}");
        exit();
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

        /* Info Cards */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border-left: 4px solid var(--primary);
        }

        .info-card .value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .info-card .label {
            font-size: 0.9rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Selection Steps */
        .steps {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 2rem;
        }

        .step {
            flex: 1;
            text-align: center;
            position: relative;
        }

        .step:not(:last-child)::after {
            content: '→';
            position: absolute;
            right: -0.5rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
            font-size: 1.5rem;
        }

        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--light);
            color: var(--gray);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-weight: 700;
            transition: var(--transition);
        }

        .step.active .step-number {
            background: var(--gradient-1);
            color: white;
        }

        .step.completed .step-number {
            background: var(--gradient-3);
            color: white;
        }

        .step-label {
            font-size: 0.9rem;
            color: var(--gray);
        }

        .step.active .step-label {
            color: var(--primary);
            font-weight: 600;
        }

        /* Selection Cards */
        .selection-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 2rem;
            box-shadow: var(--shadow-lg);
            margin-bottom: 2rem;
        }

        .selection-card h2 {
            font-size: 1.3rem;
            color: var(--dark);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
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

        /* Student List */
        .student-list {
            max-height: 400px;
            overflow-y: auto;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-md);
            padding: 0.5rem;
            margin: 1rem 0;
        }

        .student-item {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            border-bottom: 1px solid var(--light);
            transition: var(--transition);
        }

        .student-item:hover {
            background: rgba(67, 97, 238, 0.05);
        }

        .student-item:last-child {
            border-bottom: none;
        }

        .student-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-right: 1rem;
            cursor: pointer;
        }

        .student-info {
            flex: 1;
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
            margin-top: 0.25rem;
        }

        .student-status {
            font-size: 0.75rem;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        /* Selection Controls */
        .selection-controls {
            display: flex;
            gap: 0.5rem;
            margin: 1rem 0;
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

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        .btn-lg {
            padding: 1rem 2rem;
            font-size: 1rem;
        }

        .btn-block {
            width: 100%;
            justify-content: center;
        }

        /* Summary Section */
        .summary-section {
            background: var(--light);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin: 2rem 0;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
        }

        .summary-item {
            text-align: center;
            padding: 1rem;
            background: white;
            border-radius: var(--border-radius-md);
        }

        .summary-label {
            font-size: 0.85rem;
            color: var(--gray);
            margin-bottom: 0.25rem;
        }

        .summary-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
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
            
            .steps {
                flex-direction: column;
                gap: 1rem;
            }
            
            .step:not(:last-child)::after {
                content: '↓';
                right: auto;
                bottom: -1rem;
                top: auto;
                left: 50%;
                transform: translateX(-50%);
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
            
            .selection-controls {
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
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header animate">
            <h1><i class="fas fa-layer-group"></i> Bulk Invoice Generation</h1>
            <p>Generate invoices for multiple students at once</p>
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

        <!-- Selection Steps -->
        <div class="steps animate">
            <div class="step <?php echo $class_id ? 'completed' : 'active'; ?>">
                <div class="step-number">1</div>
                <div class="step-label">Select Class</div>
            </div>
            <div class="step <?php echo $structure_id ? 'active' : ''; ?>">
                <div class="step-number">2</div>
                <div class="step-label">Choose Fee Structure</div>
            </div>
            <div class="step <?php echo ($class_id && $structure_id) ? 'active' : ''; ?>">
                <div class="step-number">3</div>
                <div class="step-label">Select Students</div>
            </div>
        </div>

        <!-- Selection Form -->
        <form id="selectionForm" method="GET" class="animate">
            <div class="selection-card">
                <h2><i class="fas fa-graduation-cap" style="color: var(--primary);"></i> Step 1: Select Class</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="required">Class</label>
                        <select name="class_id" id="class_id" class="form-control" required onchange="this.form.submit()">
                            <option value="">-- Choose Class --</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $class_id == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <?php if ($class_id): ?>
            <div class="selection-card">
                <h2><i class="fas fa-calculator" style="color: var(--primary);"></i> Step 2: Choose Fee Structure</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="required">Fee Structure</label>
                        <select name="structure_id" id="structure_id" class="form-control" required onchange="this.form.submit()">
                            <option value="">-- Choose Fee Structure --</option>
                            <?php foreach ($fee_structures as $fs): ?>
                            <?php if ($fs['class_id'] == $class_id): ?>
                            <option value="<?php echo $fs['id']; ?>" <?php echo $structure_id == $fs['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($fs['structure_name']); ?> - 
                                Term <?php echo $fs['term']; ?> <?php echo $fs['academic_year_id']; ?> 
                                (KES <?php echo number_format($fs['total_amount'] ?? 0, 2); ?>)
                            </option>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </form>

        <?php if ($class_id && $structure_id && !empty($students)): ?>
        <!-- Student Selection Form -->
        <form id="bulkForm" method="POST" class="animate">
            <input type="hidden" name="generate_bulk" value="1">
            <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
            <input type="hidden" name="structure_id" value="<?php echo $structure_id; ?>">
            
            <div class="selection-card">
                <h2><i class="fas fa-users" style="color: var(--primary);"></i> Step 3: Select Students</h2>
                
                <div class="form-grid" style="margin-bottom: 1.5rem;">
                    <div class="form-group">
                        <label class="required">Due Date</label>
                        <input type="date" name="due_date" class="form-control" 
                               value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                    </div>
                </div>

                <div class="selection-controls">
                    <button type="button" class="btn btn-sm btn-outline" onclick="selectAll()">
                        <i class="fas fa-check-double"></i> Select All
                    </button>
                    <button type="button" class="btn btn-sm btn-outline" onclick="deselectAll()">
                        <i class="fas fa-times"></i> Deselect All
                    </button>
                </div>

                <div class="student-list">
                    <?php foreach ($students as $student): ?>
                    <?php
                    // Check if invoice already exists
                    $stmt = $pdo->prepare("SELECT id FROM invoices WHERE student_id = ? AND fee_structure_id = ?");
                    $stmt->execute([$student['id'], $structure_id]);
                    $has_invoice = $stmt->fetch();
                    ?>
                    <div class="student-item">
                        <input type="checkbox" name="selected_students[]" value="<?php echo $student['id']; ?>" 
                               class="student-checkbox" <?php echo !$has_invoice ? '' : 'disabled'; ?>
                               onchange="updateSummary()">
                        <div class="student-info">
                            <div class="student-name">
                                <?php echo htmlspecialchars($student['full_name']); ?>
                                <?php if ($has_invoice): ?>
                                <span class="student-status">Invoice Already Exists</span>
                                <?php endif; ?>
                            </div>
                            <div class="student-details">
                                <span><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($student['Admission_number']); ?></span>
                                <span><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($student['class_name']); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Summary Section -->
                <div class="summary-section">
                    <div class="summary-grid">
                        <div class="summary-item">
                            <div class="summary-label">Total Students</div>
                            <div class="summary-value" id="totalStudents"><?php echo count($students); ?></div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">Selected</div>
                            <div class="summary-value" id="selectedCount">0</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">Amount per Student</div>
                            <div class="summary-value" id="perStudent">KES <?php echo number_format(array_sum(array_column($structure_items, 'amount')), 2); ?></div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">Total Amount</div>
                            <div class="summary-value" id="totalAmount">KES 0.00</div>
                        </div>
                    </div>
                </div>

                <!-- Fee Structure Preview -->
                <details style="margin: 1.5rem 0;">
                    <summary style="cursor: pointer; color: var(--primary); font-weight: 600;">
                        <i class="fas fa-eye"></i> Preview Fee Items
                    </summary>
                    <div style="margin-top: 1rem; padding: 1rem; background: var(--light); border-radius: var(--border-radius-md);">
                        <?php foreach ($structure_items as $item): ?>
                        <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px dashed var(--gray-light);">
                            <span>
                                <?php echo htmlspecialchars($item['item_name']); ?>
                                <?php if ($item['is_mandatory']): ?>
                                <span style="color: var(--success); font-size: 0.75rem; margin-left: 0.5rem;">(Required)</span>
                                <?php endif; ?>
                            </span>
                            <span style="font-weight: 600; color: var(--primary);">KES <?php echo number_format($item['amount'], 2); ?></span>
                        </div>
                        <?php endforeach; ?>
                        <div style="display: flex; justify-content: space-between; padding: 1rem 0 0; font-weight: 700;">
                            <span>Total</span>
                            <span style="color: var(--success);">KES <?php echo number_format(array_sum(array_column($structure_items, 'amount')), 2); ?></span>
                        </div>
                    </div>
                </details>
            </div>

            <!-- Action Bar -->
            <div class="action-bar">
                <div class="action-bar-total">
                    Selected: <span id="actionBarSelected">0</span> students | 
                    Total: <span id="actionBarTotal">KES 0.00</span>
                </div>
                <div style="display: flex; gap: 1rem;">
                    <a href="invoices.php" class="btn btn-outline">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-success btn-lg" id="submitBtn">
                        <i class="fas fa-file-invoice"></i> Generate Invoices
                    </button>
                </div>
            </div>
        </form>
        <?php elseif ($class_id && $structure_id && empty($students)): ?>
        <div class="selection-card animate" style="text-align: center; padding: 4rem;">
            <i class="fas fa-users-slash fa-4x" style="color: var(--gray); margin-bottom: 1rem;"></i>
            <h3 style="color: var(--dark); margin-bottom: 0.5rem;">No Students Found</h3>
            <p style="color: var(--gray);">There are no active students in this class.</p>
            <a href="generate_invoices.php" class="btn btn-primary" style="margin-top: 1rem;">
                <i class="fas fa-arrow-left"></i> Start Over
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-spinner"></div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        let perStudentAmount = <?php echo array_sum(array_column($structure_items, 'amount')) ?: 0; ?>;

        function updateSummary() {
            const checkboxes = document.querySelectorAll('.student-checkbox:checked:not(:disabled)');
            const count = checkboxes.length;
            const total = count * perStudentAmount;
            
            document.getElementById('selectedCount').textContent = count;
            document.getElementById('totalAmount').textContent = 'KES ' + formatNumber(total);
            document.getElementById('actionBarSelected').textContent = count;
            document.getElementById('actionBarTotal').textContent = 'KES ' + formatNumber(total);
        }

        function selectAll() {
            const checkboxes = document.querySelectorAll('.student-checkbox:not(:disabled)');
            checkboxes.forEach(cb => cb.checked = true);
            updateSummary();
        }

        function deselectAll() {
            const checkboxes = document.querySelectorAll('.student-checkbox:not(:disabled)');
            checkboxes.forEach(cb => cb.checked = false);
            updateSummary();
        }

        function formatNumber(num) {
            return num.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
        }

        // Initialize summary
        document.addEventListener('DOMContentLoaded', function() {
            updateSummary();
        });

        // Form submission
        document.getElementById('bulkForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const selected = document.querySelectorAll('.student-checkbox:checked:not(:disabled)');
            
            if (selected.length === 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'No Students Selected',
                    text: 'Please select at least one student'
                });
                return;
            }
            
            Swal.fire({
                title: 'Generate Invoices?',
                html: `This will create invoices for <strong>${selected.length}</strong> students.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#4cc9f0',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, generate'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('loadingOverlay').classList.add('active');
                    this.submit();
                }
            });
        });
    </script>
</body>
</html>