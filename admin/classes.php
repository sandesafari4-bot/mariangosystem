<?php
include '../config.php';
checkAuth();
checkRole(['admin']);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_class'])) {
        $class_name = $_POST['class_name'];
        $class_teacher_id = $_POST['class_teacher_id'] ?: null;
        $capacity = $_POST['capacity'];
        
        $stmt = $pdo->prepare("INSERT INTO classes (class_name, class_teacher_id, capacity) VALUES (?, ?, ?)");
        if ($stmt->execute([$class_name, $class_teacher_id, $capacity])) {
            $_SESSION['success'] = "Class added successfully!";
        } else {
            $_SESSION['error'] = "Failed to add class. Please try again.";
        }
        header("Location: classes.php");
        exit();
    }
    
    if (isset($_POST['update_class'])) {
        $class_id = $_POST['class_id'];
        $class_name = $_POST['class_name'];
        $class_teacher_id = $_POST['class_teacher_id'] ?: null;
        $capacity = $_POST['capacity'];
        
        $stmt = $pdo->prepare("UPDATE classes SET class_name = ?, class_teacher_id = ?, capacity = ? WHERE id = ?");
        if ($stmt->execute([$class_name, $class_teacher_id, $capacity, $class_id])) {
            $_SESSION['success'] = "Class updated successfully!";
        } else {
            $_SESSION['error'] = "Failed to update class. Please try again.";
        }
        header("Location: classes.php");
        exit();
    }
    
    if (isset($_POST['delete_class'])) {
        $class_id = $_POST['class_id'];
        
        // Check if class has students
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE class_id = ? AND status = 'active'");
        $check_stmt->execute([$class_id]);
        $student_count = $check_stmt->fetchColumn();
        
        if ($student_count > 0) {
            $_SESSION['error'] = "Cannot delete class with active students. Please transfer students first.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM classes WHERE id = ?");
            if ($stmt->execute([$class_id])) {
                $_SESSION['success'] = "Class deleted successfully!";
            } else {
                $_SESSION['error'] = "Failed to delete class. Please try again.";
            }
        }
        header("Location: classes.php");
        exit();
    }
    
    if (isset($_POST['add_subject'])) {
        $subject_name = $_POST['subject_name'];
        $class_id = $_POST['class_id'];
        $teacher_id = $_POST['teacher_id'] ?: null;
        
        $stmt = $pdo->prepare("INSERT INTO subjects (subject_name, class_id, teacher_id) VALUES (?, ?, ?)");
        if ($stmt->execute([$subject_name, $class_id, $teacher_id])) {
            $_SESSION['success'] = "Subject added successfully!";
        } else {
            $_SESSION['error'] = "Failed to add subject. Please try again.";
        }
        header("Location: classes.php");
        exit();
    }

    if (isset($_POST['update_subject'])) {
        $subject_id = $_POST['subject_id'];
        $subject_name = $_POST['subject_name'];
        $class_id = $_POST['class_id'];
        $teacher_id = $_POST['teacher_id'] ?: null;

        $stmt = $pdo->prepare("UPDATE subjects SET subject_name = ?, class_id = ?, teacher_id = ? WHERE id = ?");
        if($stmt->execute([$subject_name, $class_id, $teacher_id, $subject_id])) {
            $_SESSION['success'] = "Subject updated successfully!";
        } else {
            $_SESSION['error'] = "Failed to update subject. Please try again.";
        }
        header("Location: classes.php");
        exit();
    }

    if (isset($_POST['delete_subject'])) {
        $subject_id = $_POST['subject_id'];

        $stmt = $pdo->prepare("DELETE FROM subjects WHERE id = ?");
        if ($stmt->execute([$subject_id])) {
            $_SESSION['success'] = "Subject deleted successfully!";
        } else {
            $_SESSION['error'] = "Failed to delete subject. Please try again.";
        }
        header("Location: classes.php");
        exit();
    }
}

// AJAX endpoint for subject data
if (isset($_GET['ajax']) && $_GET['ajax'] === 'subject' && isset($_GET['id'])) {
    $subject_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM subjects WHERE id = ? LIMIT 1");
    $stmt->execute([$subject_id]);
    $subject = $stmt->fetch(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($subject);
    exit();
}

// AJAX endpoint for class details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'class' && isset($_GET['id'])) {
    $class_id = (int)$_GET['id'];
    $class_stmt = $pdo->prepare("SELECT c.*, u.full_name as teacher_name FROM classes c LEFT JOIN users u ON c.class_teacher_id = u.id WHERE c.id = ? LIMIT 1");
    $class_stmt->execute([$class_id]);
    $class_info = $class_stmt->fetch(PDO::FETCH_ASSOC);

    $subjects_stmt = $pdo->prepare("SELECT s.*, u.full_name as teacher_name FROM subjects s LEFT JOIN users u ON s.teacher_id = u.id WHERE s.class_id = ? ORDER BY s.subject_name");
    $subjects_stmt->execute([$class_id]);
    $subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);

    $students_stmt = $pdo->prepare("SELECT id, full_name, Admission_number, status FROM students WHERE class_id = ? ORDER BY full_name");
    $students_stmt->execute([$class_id]);
    $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode(['class' => $class_info, 'subjects' => $subjects, 'students' => $students]);
    exit();
}

// Get all classes with teacher information and student counts
$classes = $pdo->query("
    SELECT c.*, u.full_name as teacher_name, 
           (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND s.status = 'active') as student_count
    FROM classes c 
    LEFT JOIN users u ON c.class_teacher_id = u.id 
    ORDER BY c.class_name
")->fetchAll();

// Get teachers for dropdown
$teachers = $pdo->query("SELECT * FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY full_name")->fetchAll();

// Get subjects for each class
$subjects_by_class = [];
foreach ($classes as $class) {
    $subjects_stmt = $pdo->prepare("
        SELECT s.*, u.full_name as teacher_name 
        FROM subjects s 
        LEFT JOIN users u ON s.teacher_id = u.id 
        WHERE s.class_id = ? 
        ORDER BY s.subject_name
    ");
    $subjects_stmt->execute([$class['id']]);
    $subjects_by_class[$class['id']] = $subjects_stmt->fetchAll();
}

$page_title = "Class Management - " . SCHOOL_NAME;
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
        :root {
            --primary: #2c3e50;
            --primary-light: #34495e;
            --secondary: #3498db;
            --success: #27ae60;
            --success-light: #2ecc71;
            --danger: #e74c3c;
            --danger-light: #c0392b;
            --warning: #f39c12;
            --warning-light: #f1c40f;
            --info: #17a2b8;
            --purple: #9b59b6;
            --purple-light: #8e44ad;
            --dark: #2c3e50;
            --dark-light: #34495e;
            --gray: #7f8c8d;
            --gray-light: #95a5a6;
            --light: #ecf0f1;
            --white: #ffffff;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.1);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
            --shadow-xl: 0 20px 25px rgba(0,0,0,0.1);
            --border-radius: 12px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            min-height: calc(100vh - 70px);
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }

        /* Page Header */
        .page-header {
            background: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            border-left: 5px solid var(--secondary);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h1 {
            font-size: 2rem;
            color: var(--dark);
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
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary), var(--purple));
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), var(--success-light));
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), var(--warning-light));
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), var(--danger-light));
            color: white;
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info), #138496);
            color: white;
        }

        .btn-outline {
            background: var(--light);
            color: var(--dark);
            border: 2px solid transparent;
        }

        .btn-outline:hover {
            background: #d5dbdb;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        .btn-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.8rem;
            box-shadow: var(--shadow-md);
            border-left: 4px solid;
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card.classes { border-left-color: var(--secondary); }
        .stat-card.students { border-left-color: var(--success); }
        .stat-card.capacity { border-left-color: var(--warning); }
        .stat-card.utilization { border-left-color: var(--danger); }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .stat-card.classes .stat-icon { background: linear-gradient(135deg, var(--secondary), var(--purple)); }
        .stat-card.students .stat-icon { background: linear-gradient(135deg, var(--success), var(--success-light)); }
        .stat-card.capacity .stat-icon { background: linear-gradient(135deg, var(--warning), var(--warning-light)); }
        .stat-card.utilization .stat-icon { background: linear-gradient(135deg, var(--danger), var(--danger-light)); }

        .stat-value {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Classes Grid */
        .classes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 1.8rem;
            margin-bottom: 2rem;
        }

        .class-card {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-md);
            transition: all 0.3s;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .class-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .class-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--light), #ffffff);
            border-bottom: 1px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .class-info h3 {
            font-size: 1.3rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .class-teacher {
            color: var(--gray);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .class-teacher i {
            color: var(--secondary);
        }

        .class-actions {
            display: flex;
            gap: 0.5rem;
        }

        .class-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            padding: 1.5rem;
            background: var(--white);
            border-bottom: 1px solid var(--light);
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.3rem;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--gray);
            text-transform: uppercase;
        }

        .progress-bar {
            height: 10px;
            background: var(--light);
            border-radius: 5px;
            margin: 1rem 1.5rem;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 5px;
            transition: width 0.3s ease;
        }

        /* Subjects Section */
        .subjects-section {
            padding: 0 1.5rem 1.5rem 1.5rem;
        }

        .subjects-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .subjects-header h4 {
            font-size: 1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .subjects-list {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid var(--light);
            border-radius: 8px;
        }

        .subject-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.8rem 1rem;
            border-bottom: 1px solid var(--light);
            transition: all 0.2s;
        }

        .subject-item:last-child {
            border-bottom: none;
        }

        .subject-item:hover {
            background: rgba(52, 152, 219, 0.05);
        }

        .subject-info {
            flex: 1;
        }

        .subject-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.2rem;
        }

        .subject-teacher {
            font-size: 0.8rem;
            color: var(--gray);
        }

        .subject-actions {
            display: flex;
            gap: 0.3rem;
        }

        .no-subjects {
            text-align: center;
            padding: 2rem 1rem;
            background: var(--light);
            border-radius: 8px;
            color: var(--gray);
            font-style: italic;
        }

        .class-footer {
            padding: 1rem 1.5rem 1.5rem 1.5rem;
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
            border-top: 1px solid var(--light);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1050;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
            z-index: 1052;
        }

        .modal-header h2 {
            font-size: 1.5rem;
            color: var(--dark);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .modal-close:hover {
            background: var(--light);
            color: var(--dark);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--light);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            position: sticky;
            bottom: 0;
            background: white;
            z-index: 1052;
        }

        /* Form Styles */
        .form-section {
            background: var(--light);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-section h3 {
            font-size: 1rem;
            color: var(--dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
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
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
            font-family: inherit;
        }

        .form-control:focus {
            border-color: var(--secondary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        /* Tabs */
        .tabs {
            display: flex;
            background: var(--light);
            border-radius: 8px;
            padding: 0.3rem;
            margin-bottom: 1.5rem;
        }

        .tab {
            flex: 1;
            padding: 0.8rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            color: var(--gray);
            transition: all 0.3s;
        }

        .tab.active {
            background: white;
            color: var(--secondary);
            box-shadow: var(--shadow-sm);
        }

        .tab-content {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Data Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            border-radius: 8px;
            overflow: hidden;
        }

        .data-table th {
            background: var(--light);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.85rem;
            text-transform: uppercase;
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--light);
        }

        .data-table tr:hover {
            background: rgba(52, 152, 219, 0.05);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
        }

        .status-inactive {
            background: rgba(127, 140, 141, 0.1);
            color: var(--gray);
        }

        /* Alert */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background: rgba(39, 174, 96, 0.1);
            border-color: var(--success);
            color: var(--success);
        }

        .alert-danger {
            background: rgba(231, 76, 60, 0.1);
            border-color: var(--danger);
            color: var(--danger);
        }

        /* No Data */
        .no-data {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            border: 2px dashed var(--light);
        }

        .no-data i {
            font-size: 4rem;
            color: var(--gray-light);
            margin-bottom: 1.5rem;
        }

        .no-data h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .no-data p {
            color: var(--gray);
            margin-bottom: 2rem;
        }

        /* Loading Spinner */
        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--light);
            border-top-color: var(--secondary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Animations */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate {
            animation: slideIn 0.5s ease-out;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .classes-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .class-stats {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .class-header {
                flex-direction: column;
                gap: 1rem;
            }
            
            .class-actions {
                align-self: flex-end;
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
                <h1><i class="fas fa-school" style="color: var(--secondary); margin-right: 0.5rem;"></i>Class Management</h1>
                <p>Manage classes, assign teachers, and organize subjects</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="openModal('addClassModal')">
                    <i class="fas fa-plus"></i> Add Class
                </button>
                <button class="btn btn-success" onclick="openModal('addSubjectModal')">
                    <i class="fas fa-book"></i> Add Subject
                </button>
            </div>
        </div>

        <!-- Statistics Cards -->
        <?php
        $total_students = $pdo->query("SELECT COUNT(*) FROM students WHERE status = 'active'")->fetchColumn();
        $total_capacity = $pdo->query("SELECT SUM(capacity) FROM classes")->fetchColumn();
        $utilization = $total_capacity > 0 ? round(($total_students / $total_capacity) * 100, 1) : 0;
        ?>
        <div class="stats-grid animate">
            <div class="stat-card classes">
                <div class="stat-header">
                    <span class="stat-label">Total Classes</span>
                    <div class="stat-icon"><i class="fas fa-school"></i></div>
                </div>
                <div class="stat-value"><?php echo count($classes); ?></div>
                <div class="stat-label">Active classes</div>
            </div>
            <div class="stat-card students">
                <div class="stat-header">
                    <span class="stat-label">Total Students</span>
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                </div>
                <div class="stat-value"><?php echo $total_students; ?></div>
                <div class="stat-label">Enrolled students</div>
            </div>
            <div class="stat-card capacity">
                <div class="stat-header">
                    <span class="stat-label">Total Capacity</span>
                    <div class="stat-icon"><i class="fas fa-chair"></i></div>
                </div>
                <div class="stat-value"><?php echo $total_capacity; ?></div>
                <div class="stat-label">Available seats</div>
            </div>
            <div class="stat-card utilization">
                <div class="stat-header">
                    <span class="stat-label">Utilization</span>
                    <div class="stat-icon"><i class="fas fa-chart-pie"></i></div>
                </div>
                <div class="stat-value"><?php echo $utilization; ?>%</div>
                <div class="stat-label">Capacity filled</div>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success animate">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger animate">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
        </div>
        <?php endif; ?>

        <!-- Classes Grid -->
        <div class="classes-grid animate">
            <?php foreach($classes as $class): 
                $utilization = $class['capacity'] > 0 ? round(($class['student_count'] / $class['capacity']) * 100, 1) : 0;
                $progress_color = $utilization < 70 ? 'var(--success)' : ($utilization < 90 ? 'var(--warning)' : 'var(--danger)');
                $subjects = $subjects_by_class[$class['id']] ?? [];
            ?>
            <div class="class-card" data-class-id="<?php echo $class['id']; ?>">
                <div class="class-header">
                    <div class="class-info">
                        <h3><?php echo htmlspecialchars($class['class_name']); ?></h3>
                        <div class="class-teacher">
                            <i class="fas fa-chalkboard-teacher"></i>
                            <?php echo $class['teacher_name'] ? htmlspecialchars($class['teacher_name']) : '<span style="color: var(--gray);">No teacher assigned</span>'; ?>
                        </div>
                    </div>
                    <div class="class-actions">
                        <button class="btn btn-sm btn-outline" onclick="viewClass(<?php echo $class['id']; ?>)" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-sm btn-warning" onclick="editClass(<?php echo $class['id']; ?>)" title="Edit Class">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="deleteClass(<?php echo $class['id']; ?>)" title="Delete Class">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>

                <div class="class-stats">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $class['student_count']; ?></div>
                        <div class="stat-label">Students</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $class['capacity']; ?></div>
                        <div class="stat-label">Capacity</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number" style="color: <?php echo $progress_color; ?>"><?php echo $utilization; ?>%</div>
                        <div class="stat-label">Full</div>
                    </div>
                </div>

                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo min($utilization, 100); ?>%; background: <?php echo $progress_color; ?>;"></div>
                </div>

                <div class="subjects-section">
                    <div class="subjects-header">
                        <h4><i class="fas fa-book"></i> Subjects (<?php echo count($subjects); ?>)</h4>
                        <button class="btn btn-sm btn-success" onclick="addSubjectToClass(<?php echo $class['id']; ?>)" title="Add Subject">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>

                    <?php if (!empty($subjects)): ?>
                    <div class="subjects-list">
                        <?php foreach($subjects as $subject): ?>
                        <div class="subject-item">
                            <div class="subject-info">
                                <div class="subject-name"><?php echo htmlspecialchars($subject['subject_name']); ?></div>
                                <div class="subject-teacher">
                                    <i class="fas fa-user"></i> 
                                    <?php echo $subject['teacher_name'] ? htmlspecialchars($subject['teacher_name']) : 'No teacher'; ?>
                                </div>
                            </div>
                            <div class="subject-actions">
                                <button class="btn btn-sm btn-outline" onclick="editSubject(<?php echo $subject['id']; ?>)" title="Edit Subject">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="deleteSubject(<?php echo $subject['id']; ?>)" title="Delete Subject">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="no-subjects">
                        <i class="fas fa-book-open"></i> No subjects assigned
                    </div>
                    <?php endif; ?>
                </div>

                <div class="class-footer">
                    <button class="btn btn-sm btn-outline" onclick="viewStudents(<?php echo $class['id']; ?>)" title="View Students">
                        <i class="fas fa-users"></i> Students
                    </button>
                    <button class="btn btn-sm btn-outline" onclick="viewTimetable(<?php echo $class['id']; ?>)" title="View Timetable">
                        <i class="fas fa-calendar-alt"></i> Timetable
                    </button>
                    <button class="btn btn-sm btn-outline" onclick="viewReports(<?php echo $class['id']; ?>)" title="View Reports">
                        <i class="fas fa-chart-bar"></i> Reports
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($classes)): ?>
        <div class="no-data animate">
            <i class="fas fa-door-open"></i>
            <h3>No Classes Found</h3>
            <p>Get started by creating your first class.</p>
            <button class="btn btn-primary" onclick="openModal('addClassModal')">
                <i class="fas fa-plus"></i> Create First Class
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Add Class Modal -->
    <div id="addClassModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-plus-circle" style="color: var(--success);"></i> Add New Class</h2>
                <button class="modal-close" onclick="closeModal('addClassModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addClassForm">
                    <div class="form-section">
                        <h3><i class="fas fa-info-circle"></i> Class Information</h3>
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label class="required">Class Name</label>
                                <input type="text" name="class_name" class="form-control" placeholder="e.g., Grade 1 A" required>
                            </div>
                            <div class="form-group">
                                <label>Class Teacher</label>
                                <select name="class_teacher_id" class="form-control">
                                    <option value="">Select Teacher</option>
                                    <?php foreach($teachers as $teacher): ?>
                                    <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="required">Class Capacity</label>
                                <input type="number" name="capacity" class="form-control" min="1" max="50" value="30" required>
                                <small style="color: var(--gray);">Maximum 50 students</small>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="add_class" value="1">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addClassModal')">Cancel</button>
                <button type="button" class="btn btn-success" onclick="submitAddClass()">
                    <i class="fas fa-save"></i> Add Class
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Class Modal -->
    <div id="editClassModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-edit" style="color: var(--warning);"></i> Edit Class</h2>
                <button class="modal-close" onclick="closeModal('editClassModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editClassForm">
                    <input type="hidden" name="class_id" id="edit_class_id">
                    <div class="form-section">
                        <h3><i class="fas fa-info-circle"></i> Class Information</h3>
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label class="required">Class Name</label>
                                <input type="text" name="class_name" id="edit_class_name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Class Teacher</label>
                                <select name="class_teacher_id" id="edit_class_teacher_id" class="form-control">
                                    <option value="">Select Teacher</option>
                                    <?php foreach($teachers as $teacher): ?>
                                    <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="required">Class Capacity</label>
                                <input type="number" name="capacity" id="edit_capacity" class="form-control" min="1" max="50" required>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="update_class" value="1">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editClassModal')">Cancel</button>
                <button type="button" class="btn btn-warning" onclick="submitEditClass()">
                    <i class="fas fa-save"></i> Update Class
                </button>
            </div>
        </div>
    </div>

    <!-- Add Subject Modal -->
    <div id="addSubjectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-book" style="color: var(--success);"></i> Add New Subject</h2>
                <button class="modal-close" onclick="closeModal('addSubjectModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addSubjectForm">
                    <div class="form-section">
                        <h3><i class="fas fa-info-circle"></i> Subject Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="required">Subject Name</label>
                                <input type="text" name="subject_name" class="form-control" placeholder="e.g., Mathematics" required>
                            </div>
                            <div class="form-group">
                                <label class="required">Class</label>
                                <select name="class_id" id="subject_class_id" class="form-control" required>
                                    <option value="">Select Class</option>
                                    <?php foreach($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group full-width">
                                <label>Subject Teacher</label>
                                <select name="teacher_id" class="form-control">
                                    <option value="">Select Teacher</option>
                                    <?php foreach($teachers as $teacher): ?>
                                    <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="add_subject" value="1">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addSubjectModal')">Cancel</button>
                <button type="button" class="btn btn-success" onclick="submitAddSubject()">
                    <i class="fas fa-save"></i> Add Subject
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Subject Modal -->
    <div id="editSubjectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-edit" style="color: var(--warning);"></i> Edit Subject</h2>
                <button class="modal-close" onclick="closeModal('editSubjectModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editSubjectForm">
                    <input type="hidden" name="subject_id" id="edit_subject_id">
                    <div class="form-section">
                        <h3><i class="fas fa-info-circle"></i> Subject Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="required">Subject Name</label>
                                <input type="text" name="subject_name" id="edit_subject_name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label class="required">Class</label>
                                <select name="class_id" id="edit_subject_class_id" class="form-control" required>
                                    <option value="">Select Class</option>
                                    <?php foreach($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group full-width">
                                <label>Subject Teacher</label>
                                <select name="teacher_id" id="edit_subject_teacher_id" class="form-control">
                                    <option value="">Select Teacher</option>
                                    <?php foreach($teachers as $teacher): ?>
                                    <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="update_subject" value="1">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editSubjectModal')">Cancel</button>
                <button type="button" class="btn btn-warning" onclick="submitEditSubject()">
                    <i class="fas fa-save"></i> Update Subject
                </button>
            </div>
        </div>
    </div>

    <!-- View Class Modal -->
    <div id="viewClassModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="viewClassTitle"><i class="fas fa-eye" style="color: var(--secondary);"></i> Class Details</h2>
                <button class="modal-close" onclick="closeModal('viewClassModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="tabs">
                    <button class="tab active" onclick="switchTab('classInfo')">Class Info</button>
                    <button class="tab" onclick="switchTab('subjects')">Subjects</button>
                    <button class="tab" onclick="switchTab('students')">Students</button>
                </div>
                
                <div id="classInfo" class="tab-content active">
                    <div class="loading-spinner" style="margin: 2rem auto;"></div>
                </div>
                
                <div id="subjects" class="tab-content">
                    <div class="loading-spinner" style="margin: 2rem auto;"></div>
                </div>
                
                <div id="students" class="tab-content">
                    <div class="loading-spinner" style="margin: 2rem auto;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('viewClassModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Timetable Modal -->
    <div id="timetableModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="timetableTitle"><i class="fas fa-calendar-alt" style="color: var(--info);"></i> Class Timetable</h2>
                <button class="modal-close" onclick="closeModal('timetableModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div style="text-align: center; padding: 3rem;">
                    <i class="fas fa-calendar-alt fa-4x" style="color: var(--secondary); margin-bottom: 1rem;"></i>
                    <h3 style="color: var(--dark); margin-bottom: 0.5rem;">Timetable Feature</h3>
                    <p style="color: var(--gray); margin-bottom: 2rem;">This feature will display the class timetable. It's currently under development.</p>
                    <div style="background: var(--light); padding: 1rem; border-radius: 8px;">
                        <p style="margin: 0; color: var(--info);"><i class="fas fa-info-circle"></i> Coming soon in the next update</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('timetableModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Reports Modal -->
    <div id="reportsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="reportsTitle"><i class="fas fa-chart-bar" style="color: var(--success);"></i> Class Reports</h2>
                <button class="modal-close" onclick="closeModal('reportsModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
                    <div style="background: linear-gradient(135deg, var(--secondary), var(--purple)); padding: 1.5rem; border-radius: 10px; color: white; text-align: center;">
                        <div style="font-size: 2rem; font-weight: bold;">85%</div>
                        <div style="font-size: 0.9rem;">Average Attendance</div>
                    </div>
                    <div style="background: linear-gradient(135deg, var(--success), var(--success-light)); padding: 1.5rem; border-radius: 10px; color: white; text-align: center;">
                        <div style="font-size: 2rem; font-weight: bold;">3.8</div>
                        <div style="font-size: 0.9rem;">Average GPA</div>
                    </div>
                    <div style="background: linear-gradient(135deg, var(--warning), var(--warning-light)); padding: 1.5rem; border-radius: 10px; color: white; text-align: center;">
                        <div style="font-size: 2rem; font-weight: bold;">92%</div>
                        <div style="font-size: 0.9rem;">Pass Rate</div>
                    </div>
                </div>
                
                <div style="text-align: center; padding: 2rem; background: var(--light); border-radius: 10px;">
                    <i class="fas fa-chart-line fa-3x" style="color: var(--secondary); margin-bottom: 1rem;"></i>
                    <h4 style="color: var(--dark); margin-bottom: 0.5rem;">Advanced Analytics</h4>
                    <p style="color: var(--gray);">Detailed reports and analytics will be available here.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('reportsModal')">Close</button>
                <button type="button" class="btn btn-primary">Generate Report</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Modal Functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        }

        // Tab switching
        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            event.target.classList.add('active');
            document.getElementById(tabName).classList.add('active');
        }

        // Submit Add Class
        function submitAddClass() {
            const form = document.getElementById('addClassForm');
            
            // Validate required fields
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.style.borderColor = 'var(--danger)';
                    isValid = false;
                } else {
                    field.style.borderColor = 'var(--light)';
                }
            });

            if (!isValid) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Please fill all required fields'
                });
                return;
            }

            Swal.fire({
                title: 'Adding Class...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            form.submit();
        }

        // Edit Class
        function editClass(classId) {
            const classData = <?php echo json_encode(array_column($classes, null, 'id')); ?>;
            const classInfo = classData[classId];
            
            if (classInfo) {
                document.getElementById('edit_class_id').value = classInfo.id;
                document.getElementById('edit_class_name').value = classInfo.class_name;
                document.getElementById('edit_class_teacher_id').value = classInfo.class_teacher_id || '';
                document.getElementById('edit_capacity').value = classInfo.capacity;
                openModal('editClassModal');
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Class not found'
                });
            }
        }

        // Submit Edit Class
        function submitEditClass() {
            const form = document.getElementById('editClassForm');
            
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.style.borderColor = 'var(--danger)';
                    isValid = false;
                } else {
                    field.style.borderColor = 'var(--light)';
                }
            });

            if (!isValid) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Please fill all required fields'
                });
                return;
            }

            Swal.fire({
                title: 'Updating Class...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            form.submit();
        }

        // Delete Class
        function deleteClass(classId) {
            Swal.fire({
                title: 'Delete Class?',
                text: 'This action cannot be undone. Students must be transferred first.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: 'var(--danger)',
                cancelButtonColor: 'var(--gray)',
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="class_id" value="${classId}">
                        <input type="hidden" name="delete_class" value="1">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // View Class
        function viewClass(classId) {
            openModal('viewClassModal');
            
            fetch(`classes.php?ajax=class&id=${classId}`, { credentials: 'same-origin' })
                .then(response => response.json())
                .then(data => {
                    if (data.class) {
                        displayClassDetails(data);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Class not found'
                        });
                        closeModal('viewClassModal');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to load class details'
                    });
                    closeModal('viewClassModal');
                });
        }

        // Display Class Details
        function displayClassDetails(data) {
            const cls = data.class;
            const subjects = data.subjects || [];
            const students = data.students || [];

            document.getElementById('viewClassTitle').innerHTML = `<i class="fas fa-eye" style="color: var(--secondary);"></i> ${escapeHtml(cls.class_name)} - Details`;

            // Class Info Tab
            let classInfoHtml = `
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
                    <div style="background: var(--light); padding: 1.5rem; border-radius: 8px;">
                        <div style="color: var(--gray); margin-bottom: 0.5rem;">Class Name</div>
                        <div style="font-size: 1.2rem; font-weight: 600; color: var(--dark);">${escapeHtml(cls.class_name)}</div>
                    </div>
                    <div style="background: var(--light); padding: 1.5rem; border-radius: 8px;">
                        <div style="color: var(--gray); margin-bottom: 0.5rem;">Class Teacher</div>
                        <div style="font-size: 1.2rem; font-weight: 600; color: var(--dark);">${cls.teacher_name ? escapeHtml(cls.teacher_name) : 'Not Assigned'}</div>
                    </div>
                    <div style="background: var(--light); padding: 1.5rem; border-radius: 8px;">
                        <div style="color: var(--gray); margin-bottom: 0.5rem;">Capacity</div>
                        <div style="font-size: 1.2rem; font-weight: 600; color: var(--dark);">${escapeHtml(cls.capacity)} Students</div>
                    </div>
                    <div style="background: var(--light); padding: 1.5rem; border-radius: 8px;">
                        <div style="color: var(--gray); margin-bottom: 0.5rem;">Current Students</div>
                        <div style="font-size: 1.2rem; font-weight: 600; color: var(--dark);">${students.length}</div>
                    </div>
                </div>
            `;

            // Subjects Tab
            let subjectsHtml = '';
            if (subjects.length > 0) {
                subjectsHtml = `
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Subject Name</th>
                                <th>Teacher</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${subjects.map(subject => `
                                <tr>
                                    <td>${escapeHtml(subject.subject_name)}</td>
                                    <td>${subject.teacher_name ? escapeHtml(subject.teacher_name) : 'Not Assigned'}</td>
                                    <td>
                                        <button class="btn btn-sm btn-outline" onclick="editSubject(${subject.id}); closeModal('viewClassModal')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteSubject(${subject.id}); closeModal('viewClassModal')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `;
            } else {
                subjectsHtml = `
                    <div class="no-subjects">
                        <i class="fas fa-book-open fa-3x" style="margin-bottom: 1rem;"></i>
                        <h4>No Subjects Found</h4>
                        <p>This class doesn't have any subjects assigned yet.</p>
                        <button class="btn btn-primary" onclick="addSubjectToClass(${cls.id}); closeModal('viewClassModal')">
                            <i class="fas fa-plus"></i> Add Subject
                        </button>
                    </div>
                `;
            }

            // Students Tab
            let studentsHtml = '';
            if (students.length > 0) {
                studentsHtml = `
                    <div style="margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
                        <span>Showing ${students.length} students</span>
                        <button class="btn btn-primary btn-sm" onclick="viewStudents(${cls.id})">
                            <i class="fas fa-external-link-alt"></i> View All
                        </button>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Admission No.</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${students.slice(0, 5).map(student => `
                                <tr>
                                    <td>${escapeHtml(student.full_name)}</td>
                                    <td>${escapeHtml(student.Admission_number || 'N/A')}</td>
                                    <td><span class="status-badge status-active">${escapeHtml(student.status)}</span></td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                    ${students.length > 5 ? `<p style="text-align: center; margin-top: 1rem;">... and ${students.length - 5} more students</p>` : ''}
                `;
            } else {
                studentsHtml = `
                    <div class="no-subjects">
                        <i class="fas fa-users fa-3x" style="margin-bottom: 1rem;"></i>
                        <h4>No Students Found</h4>
                        <p>This class doesn't have any students enrolled yet.</p>
                        <button class="btn btn-primary" onclick="viewStudents(${cls.id})">
                            <i class="fas fa-user-plus"></i> Add Students
                        </button>
                    </div>
                `;
            }

            document.getElementById('classInfo').innerHTML = classInfoHtml;
            document.getElementById('subjects').innerHTML = subjectsHtml;
            document.getElementById('students').innerHTML = studentsHtml;

            // Reset to first tab
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.querySelector('.tab').classList.add('active');
            document.getElementById('classInfo').classList.add('active');
        }

        // Add Subject to Class
        function addSubjectToClass(classId) {
            document.getElementById('subject_class_id').value = classId;
            openModal('addSubjectModal');
        }

        // Edit Subject
        async function editSubject(subjectId) {
            try {
                const response = await fetch(`classes.php?ajax=subject&id=${subjectId}`, {
                    credentials: 'same-origin'
                });
                
                if (!response.ok) throw new Error('Network response was not ok');
                
                const subject = await response.json();
                
                if (subject && subject.id) {
                    document.getElementById('edit_subject_id').value = subject.id;
                    document.getElementById('edit_subject_name').value = subject.subject_name;
                    document.getElementById('edit_subject_class_id').value = subject.class_id;
                    document.getElementById('edit_subject_teacher_id').value = subject.teacher_id || '';
                    openModal('editSubjectModal');
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Subject not found'
                    });
                }
            } catch (error) {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to load subject data'
                });
            }
        }

        // Submit Add Subject
        function submitAddSubject() {
            const form = document.getElementById('addSubjectForm');
            
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.style.borderColor = 'var(--danger)';
                    isValid = false;
                } else {
                    field.style.borderColor = 'var(--light)';
                }
            });

            if (!isValid) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Please fill all required fields'
                });
                return;
            }

            Swal.fire({
                title: 'Adding Subject...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            form.submit();
        }

        // Submit Edit Subject
        function submitEditSubject() {
            const form = document.getElementById('editSubjectForm');
            
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.style.borderColor = 'var(--danger)';
                    isValid = false;
                } else {
                    field.style.borderColor = 'var(--light)';
                }
            });

            if (!isValid) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Please fill all required fields'
                });
                return;
            }

            Swal.fire({
                title: 'Updating Subject...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            form.submit();
        }

        // Delete Subject
        function deleteSubject(subjectId) {
            Swal.fire({
                title: 'Delete Subject?',
                text: 'This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: 'var(--danger)',
                cancelButtonColor: 'var(--gray)',
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="subject_id" value="${subjectId}">
                        <input type="hidden" name="delete_subject" value="1">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // View Students
        function viewStudents(classId) {
            window.location.href = 'students.php?class_id=' + classId;
        }

        // View Timetable
        function viewTimetable(classId) {
            const classData = <?php echo json_encode(array_column($classes, null, 'id')); ?>;
            const classInfo = classData[classId];
            
            if (classInfo) {
                document.getElementById('timetableTitle').innerHTML = `<i class="fas fa-calendar-alt" style="color: var(--info);"></i> ${escapeHtml(classInfo.class_name)} - Timetable`;
                openModal('timetableModal');
            }
        }

        // View Reports
        function viewReports(classId) {
            const classData = <?php echo json_encode(array_column($classes, null, 'id')); ?>;
            const classInfo = classData[classId];
            
            if (classInfo) {
                document.getElementById('reportsTitle').innerHTML = `<i class="fas fa-chart-bar" style="color: var(--success);"></i> ${escapeHtml(classInfo.class_name)} - Reports`;
                openModal('reportsModal');
            }
        }

        // Helper function to escape HTML
        function escapeHtml(str) {
            if (!str && str !== 0) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(modal => {
                    modal.classList.remove('active');
                });
                document.body.style.overflow = 'auto';
            }
        });

        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>