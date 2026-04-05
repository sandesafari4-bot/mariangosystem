<?php
include '../config.php';
checkAuth();
checkRole(['accountant', 'admin']);

$page_title = 'Fee Assignments - ' . SCHOOL_NAME;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['assign_fee'])) {
            $student_id = intval($_POST['student_id']);
            $fee_id = intval($_POST['fee_id']);
            $custom_amount = !empty($_POST['custom_amount']) ? floatval($_POST['custom_amount']) : null;
            $academic_year = trim($_POST['academic_year']);
            $term = intval($_POST['term']);
            $notes = trim($_POST['notes'] ?? '');
            
            // Check if already assigned
            $stmt = $pdo->prepare("
                SELECT id FROM fee_assignments 
                WHERE student_id = ? AND fee_id = ? AND academic_year = ? AND term = ?
            ");
            $stmt->execute([$student_id, $fee_id, $academic_year, $term]);
            
            if ($stmt->fetch()) {
                throw new Exception('This fee is already assigned to the student for the selected term');
            }
            
            // Get fee details
            $stmt = $pdo->prepare("SELECT * FROM fees WHERE id = ? AND is_active = 1");
            $stmt->execute([$fee_id]);
            $fee = $stmt->fetch();
            
            if (!$fee) {
                throw new Exception('Fee not found');
            }
            
            $amount = $custom_amount ?? $fee['amount'];
            
            // Insert assignment
            $stmt = $pdo->prepare("
                INSERT INTO fee_assignments (
                    student_id, fee_id, fee_name, amount, custom_amount,
                    academic_year, term, notes, assigned_by, assigned_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $student_id,
                $fee_id,
                $fee['fee_name'],
                $amount,
                $custom_amount ?: null,
                $academic_year,
                $term,
                $notes,
                $_SESSION['user_id']
            ]);
            
            $_SESSION['success'] = 'Fee assigned successfully!';
            
        } elseif (isset($_POST['update_assignment'])) {
            $assignment_id = intval($_POST['assignment_id']);
            $custom_amount = !empty($_POST['custom_amount']) ? floatval($_POST['custom_amount']) : null;
            $notes = trim($_POST['notes'] ?? '');
            
            // Get current assignment
            $stmt = $pdo->prepare("SELECT * FROM fee_assignments WHERE id = ?");
            $stmt->execute([$assignment_id]);
            $assignment = $stmt->fetch();
            
            if (!$assignment) {
                throw new Exception('Assignment not found');
            }
            
            // Get original fee amount
            $stmt = $pdo->prepare("SELECT amount FROM fees WHERE id = ?");
            $stmt->execute([$assignment['fee_id']]);
            $fee = $stmt->fetch();
            
            $amount = $custom_amount ?? $fee['amount'];
            
            // Update assignment
            $stmt = $pdo->prepare("
                UPDATE fee_assignments 
                SET amount = ?, custom_amount = ?, notes = ?, updated_by = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$amount, $custom_amount ?: null, $notes, $_SESSION['user_id'], $assignment_id]);
            
            $_SESSION['success'] = 'Fee assignment updated successfully!';
            
        } elseif (isset($_POST['delete_assignment'])) {
            $assignment_id = intval($_POST['assignment_id']);
            
            $stmt = $pdo->prepare("DELETE FROM fee_assignments WHERE id = ?");
            $stmt->execute([$assignment_id]);
            
            $_SESSION['success'] = 'Fee assignment removed successfully!';
        }
        
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }
    
    header("Location: fee_assignments.php");
    exit();
}

// Get filter parameters
$student_filter = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$class_filter = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$term_filter = isset($_GET['term']) ? intval($_GET['term']) : 0;
$year_filter = $_GET['academic_year'] ?? date('Y');

// Get classes for filter
$classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll();

// Get students for dropdown
$students = $pdo->query("
    SELECT s.id, s.full_name, s.admission_number, c.class_name 
    FROM students s
    JOIN classes c ON s.class_id = c.id
    WHERE s.status = 'active'
    ORDER BY s.full_name
")->fetchAll();

// Get active fees
$fees = $pdo->query("
    SELECT f.*, ft.name as fee_type_name 
    FROM fees f
    LEFT JOIN fee_types ft ON f.fee_type_id = ft.id
    WHERE f.is_active = 1
    ORDER BY f.fee_name
")->fetchAll();

// Build assignments query
$query = "
    SELECT 
        fa.*,
        s.full_name as student_name,
        s.admission_number,
        c.class_name,
        c.id as class_id,
        u.full_name as assigned_by_name,
        u2.full_name as updated_by_name
    FROM fee_assignments fa
    JOIN students s ON fa.student_id = s.id
    JOIN classes c ON s.class_id = c.id
    LEFT JOIN users u ON fa.assigned_by = u.id
    LEFT JOIN users u2 ON fa.updated_by = u2.id
    WHERE 1=1
";

$params = [];

if ($student_filter) {
    $query .= " AND fa.student_id = ?";
    $params[] = $student_filter;
}

if ($class_filter) {
    $query .= " AND s.class_id = ?";
    $params[] = $class_filter;
}

if ($term_filter) {
    $query .= " AND fa.term = ?";
    $params[] = $term_filter;
}

if ($year_filter) {
    $query .= " AND fa.academic_year = ?";
    $params[] = $year_filter;
}

$query .= " ORDER BY fa.assigned_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$assignments = $stmt->fetchAll();

// Get summary statistics
$summary = $pdo->query("
    SELECT 
        COUNT(*) as total_assignments,
        COUNT(DISTINCT student_id) as total_students,
        SUM(amount) as total_amount,
        AVG(amount) as avg_amount
    FROM fee_assignments
    WHERE academic_year = '$year_filter'
")->fetch();

// Get academic years
$academic_years = $pdo->query("SELECT DISTINCT academic_year FROM fee_assignments ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_COLUMN);
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

        .btn-warning {
            background: var(--gradient-5);
            color: white;
        }

        .btn-danger {
            background: var(--gradient-2);
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
            border-left: 4px solid var(--primary);
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

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

        /* Filter Section */
        .filter-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
            font-size: 0.9rem;
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

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        /* Quick Assign Section */
        .quick-assign {
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.05), rgba(114, 9, 183, 0.05));
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(67, 97, 238, 0.2);
        }

        .quick-assign h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--dark);
            margin-bottom: 1.5rem;
        }

        /* Data Table */
        .data-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }

        .card-header {
            padding: 1.5rem;
            background: var(--gradient-1);
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

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            padding: 1.25rem 1rem;
            text-align: left;
            background: var(--light);
            color: var(--dark);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 1.25rem 1rem;
            border-bottom: 1px solid var(--light);
            color: var(--dark);
        }

        tr:hover {
            background: rgba(67, 97, 238, 0.05);
        }

        /* Amount Styles */
        .amount-original {
            text-decoration: line-through;
            color: var(--gray);
            font-size: 0.85rem;
            margin-right: 0.3rem;
        }

        .amount-custom {
            color: var(--warning);
            font-weight: 600;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: var(--border-radius-sm);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: var(--transition);
            border: none;
            cursor: pointer;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .action-btn.edit { background: var(--warning); }
        .action-btn.delete { background: var(--danger); }

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

        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
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
            border-radius: var(--border-radius-sm);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
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
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .filter-grid {
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
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-wrap: wrap;
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
                <h1><i class="fas fa-tasks"></i> Fee Assignments</h1>
                <p>Assign specific fees to individual students</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-success" onclick="openAssignModal()">
                    <i class="fas fa-plus"></i> Assign Fee
                </button>
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

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card stagger-item">
                <div class="stat-number"><?php echo number_format($summary['total_assignments'] ?? 0); ?></div>
                <div class="stat-label">Total Assignments</div>
            </div>
            <div class="stat-card stagger-item">
                <div class="stat-number"><?php echo number_format($summary['total_students'] ?? 0); ?></div>
                <div class="stat-label">Students with Fees</div>
            </div>
            <div class="stat-card stagger-item">
                <div class="stat-number">KES <?php echo number_format($summary['total_amount'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Amount Assigned</div>
            </div>
            <div class="stat-card stagger-item">
                <div class="stat-number">KES <?php echo number_format($summary['avg_amount'] ?? 0, 2); ?></div>
                <div class="stat-label">Average per Student</div>
            </div>
        </div>

        <!-- Quick Assign Info -->
        <div class="quick-assign animate">
            <h3><i class="fas fa-info-circle" style="color: var(--primary);"></i> About Fee Assignments</h3>
            <p style="color: var(--dark); margin-bottom: 0.5rem;">
                Use this page to assign specific fees to individual students. This is different from generating invoices:
            </p>
            <ul style="color: var(--gray); margin-left: 1.5rem;">
                <li>Assign one-time fees, penalties, or custom charges</li>
                <li>Set custom amounts for specific students</li>
                <li>Track fees by academic year and term</li>
                <li>These fees will be included when generating invoices</li>
            </ul>
        </div>

        <!-- Filter Section -->
        <div class="filter-section animate">
            <form method="GET" id="filterForm">
                <div class="filter-grid">
                    <div class="form-group">
                        <label><i class="fas fa-user-graduate"></i> Student</label>
                        <select name="student_id" class="form-control">
                            <option value="">All Students</option>
                            <?php foreach ($students as $s): ?>
                            <option value="<?php echo $s['id']; ?>" <?php echo $student_filter == $s['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['full_name'] . ' (' . $s['admission_number'] . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-graduation-cap"></i> Class</label>
                        <select name="class_id" class="form-control">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar"></i> Academic Year</label>
                        <select name="academic_year" class="form-control">
                            <option value="">All Years</option>
                            <?php foreach ($academic_years as $year): ?>
                            <option value="<?php echo $year; ?>" <?php echo $year_filter == $year ? 'selected' : ''; ?>>
                                <?php echo $year; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-list-ol"></i> Term</label>
                        <select name="term" class="form-control">
                            <option value="">All Terms</option>
                            <option value="1" <?php echo $term_filter == 1 ? 'selected' : ''; ?>>Term 1</option>
                            <option value="2" <?php echo $term_filter == 2 ? 'selected' : ''; ?>>Term 2</option>
                            <option value="3" <?php echo $term_filter == 3 ? 'selected' : ''; ?>>Term 3</option>
                        </select>
                    </div>
                    <div class="form-group" style="display: flex; gap: 0.5rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <a href="fee_assignments.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Assignments Table -->
        <div class="data-card animate">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Fee Assignments</h3>
                <span>Total: <?php echo count($assignments); ?> assignments</span>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Class</th>
                            <th>Fee</th>
                            <th>Amount</th>
                            <th>Year/Term</th>
                            <th>Notes</th>
                            <th>Assigned By</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($assignments)): ?>
                            <?php foreach ($assignments as $assignment): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($assignment['student_name']); ?></strong>
                                    <div style="font-size: 0.85rem; color: var(--gray);">
                                        <?php echo htmlspecialchars($assignment['admission_number']); ?>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($assignment['class_name']); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($assignment['fee_name']); ?></strong>
                                    <?php if ($assignment['custom_amount']): ?>
                                    <div style="font-size: 0.8rem;">
                                        <span class="amount-original">KES <?php echo number_format($assignment['amount'], 2); ?></span>
                                        <span class="amount-custom">(Adjusted)</span>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-weight: 600; color: <?php echo $assignment['custom_amount'] ? 'var(--warning)' : 'var(--primary)'; ?>;">
                                        KES <?php echo number_format($assignment['amount'], 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo $assignment['academic_year']; ?> - Term <?php echo $assignment['term']; ?>
                                </td>
                                <td>
                                    <?php if ($assignment['notes']): ?>
                                    <i class="fas fa-sticky-note" style="color: var(--gray);" title="<?php echo htmlspecialchars($assignment['notes']); ?>"></i>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($assignment['assigned_by_name'] ?? 'N/A'); ?>
                                    <?php if ($assignment['updated_by_name']): ?>
                                    <div style="font-size: 0.75rem; color: var(--gray);">
                                        Updated: <?php echo date('d M Y', strtotime($assignment['updated_at'])); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('d M Y', strtotime($assignment['assigned_at'])); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="action-btn edit" onclick="editAssignment(<?php echo $assignment['id']; ?>, <?php echo $assignment['amount']; ?>, '<?php echo htmlspecialchars(addslashes($assignment['notes'] ?? '')); ?>')" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="action-btn delete" onclick="deleteAssignment(<?php echo $assignment['id']; ?>)" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 3rem;">
                                    <i class="fas fa-tasks fa-3x" style="color: var(--gray); margin-bottom: 1rem;"></i>
                                    <h4>No Fee Assignments Found</h4>
                                    <p style="color: var(--gray);">Assign fees to students to get started</p>
                                    <button class="btn btn-success" onclick="openAssignModal()" style="margin-top: 1rem;">
                                        <i class="fas fa-plus"></i> Assign Fee
                                    </button>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Assign Fee Modal -->
    <div id="assignModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle" style="color: var(--success);"></i> Assign Fee to Student</h3>
                <button class="modal-close" onclick="closeModal('assignModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label class="required">Student</label>
                            <select name="student_id" class="form-control" required>
                                <option value="">-- Select Student --</option>
                                <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>">
                                    <?php echo htmlspecialchars($student['full_name'] . ' (' . $student['admission_number'] . ') - ' . $student['class_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group full-width">
                            <label class="required">Fee</label>
                            <select name="fee_id" id="assign_fee_id" class="form-control" required onchange="updateFeeAmount()">
                                <option value="">-- Select Fee --</option>
                                <?php foreach ($fees as $fee): ?>
                                <option value="<?php echo $fee['id']; ?>" data-amount="<?php echo $fee['amount']; ?>">
                                    <?php echo htmlspecialchars($fee['fee_name']); ?> - KES <?php echo number_format($fee['amount'], 2); ?>
                                    <?php if ($fee['fee_type_name']): ?> (<?php echo $fee['fee_type_name']; ?>)<?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Custom Amount (Optional)</label>
                            <input type="number" name="custom_amount" id="custom_amount" class="form-control" 
                                   step="0.01" min="0" placeholder="Leave empty for default">
                            <small style="color: var(--gray);">Default: KES <span id="default_amount">0.00</span></small>
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Academic Year</label>
                            <input type="text" name="academic_year" class="form-control" 
                                   value="<?php echo date('Y'); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Term</label>
                            <select name="term" class="form-control" required>
                                <option value="">Select Term</option>
                                <option value="1">Term 1</option>
                                <option value="2">Term 2</option>
                                <option value="3">Term 3</option>
                            </select>
                        </div>
                        
                        <div class="form-group full-width">
                            <label>Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('assignModal')">Cancel</button>
                    <button type="submit" name="assign_fee" class="btn btn-success">
                        <i class="fas fa-save"></i> Assign Fee
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Assignment Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit" style="color: var(--warning);"></i> Edit Fee Assignment</h3>
                <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="assignment_id" id="edit_assignment_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Custom Amount</label>
                        <input type="number" name="custom_amount" id="edit_custom_amount" class="form-control" 
                               step="0.01" min="0" placeholder="Leave empty for default">
                        <small style="color: var(--gray);">Leave empty to use the original fee amount</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" id="edit_notes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" name="update_assignment" class="btn btn-warning">
                        <i class="fas fa-save"></i> Update Assignment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Modal Functions
        function openAssignModal() {
            document.getElementById('assignModal').classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // Update default amount display
        function updateFeeAmount() {
            const select = document.getElementById('assign_fee_id');
            const option = select.options[select.selectedIndex];
            const amount = option ? option.dataset.amount : 0;
            document.getElementById('default_amount').textContent = parseFloat(amount).toFixed(2);
        }

        // Edit assignment
        function editAssignment(id, currentAmount, notes) {
            document.getElementById('edit_assignment_id').value = id;
            document.getElementById('edit_custom_amount').value = '';
            document.getElementById('edit_notes').value = notes;
            document.getElementById('editModal').classList.add('active');
        }

        // Delete assignment
        function deleteAssignment(id) {
            Swal.fire({
                title: 'Delete Assignment?',
                text: 'Are you sure you want to remove this fee assignment?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f94144',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="delete_assignment" value="1">
                        <input type="hidden" name="assignment_id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
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