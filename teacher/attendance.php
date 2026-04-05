<?php
include '../config.php';
checkAuth();
checkRole(['admin', 'teacher']);

// Get current date or selected date
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_class = $_GET['class_id'] ?? '';
$view_type = $_GET['view'] ?? 'daily'; // daily, monthly, summary

// Handle attendance submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['mark_attendance'])) {
        $class_id = $_POST['class_id'];
        $date = $_POST['date'];
        $attendances = $_POST['attendance'];
        
        foreach ($attendances as $student_id => $status) {
            // Check if attendance already exists
            $check_stmt = $pdo->prepare("SELECT id FROM attendance WHERE student_id = ? AND date = ?");
            $check_stmt->execute([$student_id, $date]);
        }
            
     if ($check_stmt->fetch()) {
    // Update existing attendance
    $stmt = $pdo->prepare("UPDATE attendance 
        SET status = ?, recorded_by = ? 
        WHERE student_id = ? AND date = ?");
    $stmt->execute([$status, $_SESSION['user_id'], $student_id, $date]);
} else {
    // Insert new attendance
    $stmt = $pdo->prepare("INSERT INTO attendance 
        (student_id, class_id, date, status, recorded_by) 
        VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$student_id, $class_id, $date, $status, $_SESSION['user_id']]);
}

        
        $success = "Attendance marked successfully!";
        header("Location: attendance.php?success=" . urlencode($success) . "&date=$date&class_id=$class_id&view=$view_type");
        exit();
    }
    
    if (isset($_POST['bulk_attendance'])) {
        $class_id = $_POST['class_id'];
        $date = $_POST['date'];
        $bulk_status = $_POST['bulk_status'];
        
        // Get all students in the class
        $students = $pdo->prepare("SELECT id FROM students WHERE class_id = ? AND status = 'active'");
        $students->execute([$class_id]);
        $student_ids = $students->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($student_ids as $student_id) {
            // Check if attendance already exists
            $check_stmt = $pdo->prepare("SELECT id FROM attendance WHERE Admission_number = ? AND date = ?");
            $check_stmt->execute([$student_id, $date]);
            
            if ($check_stmt->fetch()) {
                // Update existing attendance
                $stmt = $pdo->prepare("UPDATE attendance SET status = ?, recorded_by = ? WHERE Admission_number = ? AND date = ?");
            } else {
                // Insert new attendance
                $stmt = $pdo->prepare("INSERT INTO attendance (student_id, class_id, date, status, recorded_by) VALUES (?, ?, ?, ?, ?)");
            }
            $stmt->execute([$bulk_status, $_SESSION['user_id'], $student_id, $date]);
        }
        
        $success = "Bulk attendance marked successfully!";
        header("Location: attendance.php?success=" . urlencode($success) . "&date=$date&class_id=$class_id&view=$view_type");
        exit();
    }
}

// Get classes (teachers only see their classes)
if ($_SESSION['user_role'] == 'teacher') {
    $teacher_id = $_SESSION['user_id'];
    $classes = $pdo->prepare("
        SELECT DISTINCT c.* 
        FROM classes c 
        LEFT JOIN subjects s ON c.id = s.class_id 
        WHERE s.teacher_id = ? OR c.class_teacher_id = ?
        ORDER BY c.class_name
    ");
    $classes->execute([$teacher_id, $teacher_id]);
} else {
    $classes = $pdo->query("SELECT * FROM classes ORDER BY class_name")->fetchAll();
}

// Get students for selected class
$students = [];
$class_info = null;
if ($selected_class) {
    $students = $pdo->prepare("
        SELECT s.*, c.class_name 
        FROM students s 
        JOIN classes c ON s.class_id = c.id 
        WHERE s.class_id = ? AND s.status = 'active' 
        ORDER BY s.full_name
    ");
    $students->execute([$selected_class]);
    $students = $students->fetchAll();
    
    // Get class info
    $class_info = $pdo->prepare("SELECT * FROM classes WHERE id = ?");
    $class_info->execute([$selected_class]);
    $class_info = $class_info->fetch();
}

// Get existing attendance for selected date and class
$existing_attendance = [];
if ($selected_class && $selected_date) {
    $attendance_stmt = $pdo->prepare("
        SELECT student_id, status 
        FROM attendance 
        WHERE date = ? AND class_id = ?
    ");
    $attendance_stmt->execute([$selected_date, $selected_class]);
    $existing_attendance = $attendance_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Get monthly attendance summary
$monthly_summary = [];
if ($selected_class && $view_type == 'monthly') {
    $month = date('Y-m', strtotime($selected_date));
    $summary_stmt = $pdo->prepare("
        SELECT s.id, s.full_name, s.Admission_number,
               COUNT(a.id) as total_days,
               SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present_days,
               SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) as absent_days,
               SUM(CASE WHEN a.status = 'Late' THEN 1 ELSE 0 END) as late_days
        FROM students s
        LEFT JOIN attendance a ON s.id = a.student_id AND DATE_FORMAT(a.date, '%Y-%m') = ?
        WHERE s.class_id = ? AND s.status = 'active'
        GROUP BY s.id, s.full_name, s.Admission_number
        ORDER BY s.full_name
    ");
    $summary_stmt->execute([$month, $selected_class]);
    $monthly_summary = $summary_stmt->fetchAll();
}

// Get attendance statistics
$attendance_stats = [
    'Present' => 0,
    'Absent' => 0,
    'Late' => 0,
    'total' => 0
];

if ($selected_class && $selected_date) {
    foreach ($existing_attendance as $status) {
        $attendance_stats[$status]++;
        $attendance_stats['total']++;
    }
}

$page_title = "Attendance Management - " . SCHOOL_NAME;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            --gradient-present: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            --gradient-absent: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --gradient-late: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
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
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            min-height: 100vh;
        }

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

        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            min-height: calc(100vh - 70px);
            transition: var(--transition);
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

        .management-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .management-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            background: var(--gradient-1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .management-header p {
            color: var(--gray);
            font-size: 1rem;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
        }

        /* Buttons */
        .btn {
            padding: 0.7rem 1.5rem;
            border: none;
            border-radius: var(--border-radius-md);
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
            font-size: 0.9rem;
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

        .btn-primary {
            background: var(--gradient-1);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }

        .btn-success {
            background: var(--gradient-3);
            color: white;
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.4);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79, 172, 254, 0.5);
        }

        .btn-warning {
            background: var(--gradient-5);
            color: white;
            box-shadow: 0 4px 15px rgba(250, 112, 154, 0.4);
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(250, 112, 154, 0.5);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
            box-shadow: none;
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }

        /* Filter Card */
        .attendance-header {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(255,255,255,0.3);
        }

        .attendance-filters {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr auto;
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }

        input, select {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-md);
            font-size: 0.9rem;
            transition: var(--transition);
            font-family: 'Inter', sans-serif;
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        /* View Tabs */
        .view-tabs {
            display: flex;
            gap: 0.5rem;
            margin-top: 1.5rem;
            background: var(--light);
            padding: 0.3rem;
            border-radius: var(--border-radius-lg);
        }

        .view-tab {
            flex: 1;
            padding: 0.7rem 1rem;
            background: none;
            border: none;
            border-radius: var(--border-radius-md);
            cursor: pointer;
            font-weight: 600;
            color: var(--gray);
            transition: var(--transition);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .view-tab:hover {
            color: var(--primary);
            background: rgba(67, 97, 238, 0.1);
        }

        .view-tab.active {
            background: var(--white);
            color: var(--primary);
            box-shadow: var(--shadow-sm);
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
            border-left-color: var(--success);
            color: var(--dark);
        }

        .alert-error {
            background: rgba(249, 65, 68, 0.1);
            border-left-color: var(--danger);
            color: var(--dark);
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

        /* Stats Grid */
        .attendance-summary {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .summary-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.2rem;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            border-left: 4px solid;
            position: relative;
            overflow: hidden;
        }

        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .summary-card.total { border-left-color: var(--primary); }
        .summary-card.present { border-left-color: var(--success); }
        .summary-card.absent { border-left-color: var(--danger); }
        .summary-card.late { border-left-color: var(--warning); }

        .summary-number {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
        }

        .summary-card.total .summary-number { color: var(--primary); }
        .summary-card.present .summary-number { color: var(--success); }
        .summary-card.absent .summary-number { color: var(--danger); }
        .summary-card.late .summary-number { color: var(--warning); }

        .summary-label {
            font-size: 0.8rem;
            color: var(--gray);
            font-weight: 500;
        }

        /* Bulk Actions */
        .bulk-actions {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.2rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(255,255,255,0.3);
        }

        .bulk-label {
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .bulk-label i {
            color: var(--primary);
        }

        .bulk-status {
            min-width: 200px;
            padding: 0.6rem;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-md);
            font-size: 0.9rem;
        }

        /* Attendance Table */
        .attendance-table {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.3);
            margin-bottom: 2rem;
        }

        .table-header {
            padding: 1.2rem 1.5rem;
            border-bottom: 2px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(248, 249, 250, 0.5);
        }

        .table-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            padding: 1rem 1.5rem;
            text-align: left;
            background: var(--light);
            font-weight: 600;
            color: var(--dark);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--light);
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background: rgba(67, 97, 238, 0.02);
        }

        .student-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .student-avatar {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            background: var(--gradient-1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .student-details {
            flex: 1;
        }

        .student-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.2rem;
        }

        .student-meta {
            font-size: 0.8rem;
            color: var(--gray);
        }

        .attendance-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: center;
        }

        .attendance-radio {
            display: none;
        }

        .attendance-label {
            padding: 0.5rem 1rem;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-md);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            font-size: 0.8rem;
            min-width: 80px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
        }

        .attendance-label i {
            font-size: 0.8rem;
        }

        .attendance-radio:checked + .attendance-label {
            border-color: transparent;
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .attendance-radio[value="Present"]:checked + .attendance-label {
            background: var(--gradient-present);
        }

        .attendance-radio[value="Absent"]:checked + .attendance-label {
            background: var(--gradient-absent);
        }

        .attendance-radio[value="Late"]:checked + .attendance-label {
            background: var(--gradient-late);
        }

        .attendance-label.present:hover {
            border-color: var(--success);
            color: var(--success);
        }

        .attendance-label.absent:hover {
            border-color: var(--danger);
            color: var(--danger);
        }

        .attendance-label.late:hover {
            border-color: var(--warning);
            color: var(--warning);
        }

        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* Monthly Grid */
        .monthly-grid {
            display: grid;
            gap: 1rem;
        }

        .monthly-student-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            border: 1px solid rgba(255,255,255,0.3);
        }

        .monthly-student-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .student-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light);
        }

        .student-name {
            font-weight: 600;
            color: var(--dark);
            font-size: 1.1rem;
            margin-bottom: 0.2rem;
        }

        .student-id {
            font-size: 0.8rem;
            color: var(--gray);
        }

        .attendance-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .stat-box {
            text-align: center;
            padding: 0.8rem;
            background: var(--light);
            border-radius: var(--border-radius-md);
        }

        .stat-box.stat-present .stat-value { color: var(--success); }
        .stat-box.stat-absent .stat-value { color: var(--danger); }
        .stat-box.stat-late .stat-value { color: var(--warning); }
        .stat-box.stat-total .stat-value { color: var(--primary); }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.2rem;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--gray);
            text-transform: uppercase;
            font-weight: 600;
        }

        .attendance-percentage {
            text-align: right;
        }

        .percentage-value {
            font-size: 1.6rem;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 0.2rem;
        }

        .percentage-label {
            font-size: 0.8rem;
            color: var(--gray);
        }

        /* No Data State */
        .no-data {
            text-align: center;
            padding: 3rem;
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
        }

        .no-data i {
            font-size: 4rem;
            color: var(--gray-light);
            margin-bottom: 1rem;
        }

        .no-data h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .no-data p {
            color: var(--gray);
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

        .animate-fade-up {
            animation: fadeInUp 0.6s ease-out;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .attendance-filters {
                grid-template-columns: 1fr;
            }
            
            .attendance-summary {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .management-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .management-header h1 {
                font-size: 1.8rem;
            }
            
            .attendance-summary {
                grid-template-columns: 1fr;
            }
            
            .bulk-actions {
                flex-direction: column;
                align-items: stretch;
            }
            
            .attendance-actions {
                flex-direction: column;
            }
            
            .attendance-label {
                min-width: auto;
            }
            
            .student-info {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .attendance-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .student-header {
                flex-direction: column;
            }
            
            .attendance-percentage {
                text-align: left;
                margin-top: 1rem;
            }
        }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="main-content">
        <div class="page-header animate-fade-up">
            <div class="management-header">
                <div>
                    <h1>Attendance Management</h1>
                    <p>Track and manage student attendance records efficiently</p>
                </div>
                <div class="action-buttons">
                    <button class="btn btn-outline" onclick="exportAttendance()">
                        <i class="fas fa-download"></i>
                        Export
                    </button>
                    <button class="btn btn-primary" onclick="printAttendance()">
                        <i class="fas fa-print"></i>
                        Print
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Success/Error Messages -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success animate-fade-up">
                <div>
                    <i class="fas fa-check-circle" style="color: var(--success);"></i>
                    <?php echo htmlspecialchars($_GET['success']); ?>
                </div>
                <button onclick="this.parentElement.style.display='none'" 
                        style="background:none; border:none; cursor:pointer; color: inherit;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-error animate-fade-up">
                <div>
                    <i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i>
                    <?php echo htmlspecialchars($_GET['error']); ?>
                </div>
                <button onclick="this.parentElement.style.display='none'" 
                        style="background:none; border:none; cursor:pointer; color: inherit;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>
        <div class="attendance-header animate-fade-up">
            <form method="GET" id="attendanceFilter">
                <div class="attendance-filters">
                    <div class="form-group">
                        <label for="class_id">
                            <i class="fas fa-door-open" style="color: var(--primary);"></i>
                            Select Class
                        </label>
                        <select id="class_id" name="class_id" class="form-control" required onchange="this.form.submit()">
                            <option value="">Choose a class</option>
                            <?php foreach($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $selected_class == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="date">
                            <i class="fas fa-calendar" style="color: var(--primary);"></i>
                            Select Date
                        </label>
                        <input type="date" id="date" name="date" class="form-control" value="<?php echo $selected_date; ?>" onchange="this.form.submit()">
                    </div>
                    <input type="hidden" name="view" value="<?php echo $view_type; ?>">
                    <div>
                        <button type="submit" class="btn btn-primary" style="width: 100%;">
                            <i class="fas fa-filter"></i>
                            Apply Filters
                        </button>
                    </div>
                </div>
                
                <div class="view-tabs">
                    <button type="button" class="view-tab <?php echo $view_type == 'daily' ? 'active' : ''; ?>" onclick="changeView('daily')">
                        <i class="fas fa-calendar-day"></i>
                        Daily View
                    </button>
                    <button type="button" class="view-tab <?php echo $view_type == 'monthly' ? 'active' : ''; ?>" onclick="changeView('monthly')">
                        <i class="fas fa-calendar-alt"></i>
                        Monthly Summary
                    </button>
                    <button type="button" class="view-tab <?php echo $view_type == 'summary' ? 'active' : ''; ?>" onclick="changeView('summary')">
                        <i class="fas fa-chart-bar"></i>
                        Reports
                    </button>
                </div>
            </form>
        </div>
        
        <?php if ($selected_class): ?>
            <!-- Attendance Summary -->
            <div class="attendance-summary">
                <div class="summary-card total">
                    <div class="summary-number"><?php echo count($students); ?></div>
                    <div class="summary-label">Total Students</div>
                </div>
                <div class="summary-card present">
                    <div class="summary-number"><?php echo $attendance_stats['Present']; ?></div>
                    <div class="summary-label">Present</div>
                </div>
                <div class="summary-card absent">
                    <div class="summary-number"><?php echo $attendance_stats['Absent']; ?></div>
                    <div class="summary-label">Absent</div>
                </div>
                <div class="summary-card late">
                    <div class="summary-number"><?php echo $attendance_stats['Late']; ?></div>
                    <div class="summary-label">Late</div>
                </div>
            </div>
            
            <?php if ($view_type == 'daily' && $students): ?>
            <!-- Daily Attendance View -->
            <form method="POST" id="attendanceForm" onsubmit="return validateAttendance()">
                <input type="hidden" name="class_id" value="<?php echo $selected_class; ?>">
                <input type="hidden" name="date" value="<?php echo $selected_date; ?>">
                <input type="hidden" name="mark_attendance" value="1">
                
                <!-- Bulk Actions -->
                <div class="bulk-actions">
                    <div class="bulk-label">
                        <i class="fas fa-bolt"></i>
                        Bulk Actions:
                    </div>
                    <select name="bulk_status" id="bulkStatus" class="bulk-status">
                        <option value="">Select Status</option>
                        <option value="Present">✅ Mark All Present</option>
                        <option value="Absent">❌ Mark All Absent</option>
                        <option value="Late">⏰ Mark All Late</option>
                    </select>
                    <button type="submit" name="bulk_attendance" class="btn btn-outline" onclick="return confirmBulkAction()">
                        <i class="fas fa-bolt"></i>
                        Apply to All
                    </button>
                    
                    <div style="flex: 1;"></div>
                    
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="button" class="btn btn-sm" onclick="selectAll('Present')" style="background: var(--success); color: white;">
                            <i class="fas fa-check"></i> All Present
                        </button>
                        <button type="button" class="btn btn-sm" onclick="selectAll('Absent')" style="background: var(--danger); color: white;">
                            <i class="fas fa-times"></i> All Absent
                        </button>
                        <button type="button" class="btn btn-sm" onclick="selectAll('Late')" style="background: var(--warning); color: white;">
                            <i class="fas fa-clock"></i> All Late
                        </button>
                    </div>
                </div>
                
                <!-- Attendance Table -->
                <div class="attendance-table">
                    <div class="table-header">
                        <h3>
                            <i class="fas fa-calendar-check" style="color: var(--primary);"></i>
                            Attendance for <?php echo date('F j, Y', strtotime($selected_date)); ?>
                        </h3>
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <span style="background: var(--gradient-1); color: white; padding: 0.3rem 1rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600;">
                                <i class="fas fa-door-open"></i>
                                <?php echo htmlspecialchars($class_info['class_name']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Admission No.</th>
                                    <th>Attendance Status</th>
                                    <th>Current Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($students as $student): 
                                    $current_status = $existing_attendance[$student['id']] ?? 'Not Marked';
                                ?>
                                <tr>
                                    <td>
                                        <div class="student-info">
                                            <div class="student-avatar">
                                                <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
                                            </div>
                                            <div class="student-details">
                                                <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                                <div class="student-meta">
                                                    <span><i class="fas fa-door-open"></i> <?php echo htmlspecialchars($student['class_name']); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-family: monospace; font-weight: 600;">
                                            <?php echo htmlspecialchars($student['Admission_number']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="attendance-actions">
                                            <input type="radio" name="attendance[<?php echo $student['id']; ?>]" value="Present" 
                                                   id="present_<?php echo $student['id']; ?>" 
                                                   <?php echo ($existing_attendance[$student['id']] ?? '') == 'Present' ? 'checked' : ''; ?>
                                                   class="attendance-radio">
                                            <label for="present_<?php echo $student['id']; ?>" class="attendance-label present">
                                                <i class="fas fa-check"></i> Present
                                            </label>
                                            
                                            <input type="radio" name="attendance[<?php echo $student['id']; ?>]" value="Absent" 
                                                   id="absent_<?php echo $student['id']; ?>"
                                                   <?php echo ($existing_attendance[$student['id']] ?? '') == 'Absent' ? 'checked' : ''; ?>
                                                   class="attendance-radio">
                                            <label for="absent_<?php echo $student['id']; ?>" class="attendance-label absent">
                                                <i class="fas fa-times"></i> Absent
                                            </label>
                                            
                                            <input type="radio" name="attendance[<?php echo $student['id']; ?>]" value="Late" 
                                                   id="late_<?php echo $student['id']; ?>"
                                                   <?php echo ($existing_attendance[$student['id']] ?? '') == 'Late' ? 'checked' : ''; ?>
                                                   class="attendance-radio">
                                            <label for="late_<?php echo $student['id']; ?>" class="attendance-label late">
                                                <i class="fas fa-clock"></i> Late
                                            </label>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($current_status): ?>
                                        <span class="status-badge" style="
                                            background: <?php 
                                                echo $current_status == 'Present' ? 'rgba(76, 201, 240, 0.1)' : 
                                                       ($current_status == 'Absent' ? 'rgba(249, 65, 68, 0.1)' : 
                                                       'rgba(248, 150, 30, 0.1)'); 
                                            ?>;
                                            color: <?php 
                                                echo $current_status == 'Present' ? 'var(--success)' : 
                                                       ($current_status == 'Absent' ? 'var(--danger)' : 
                                                       'var(--warning)'); 
                                            ?>;
                                        ">
                                            <i class="fas fa-<?php 
                                                echo $current_status == 'Present' ? 'check-circle' : 
                                                       ($current_status == 'Absent' ? 'times-circle' : 
                                                       'clock');
                                            ?>"></i>
                                            <?php echo $current_status; ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="status-badge" style="background: rgba(108, 117, 125, 0.1); color: var(--gray);">
                                            <i class="fas fa-question-circle"></i> Not Marked
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Save Button -->
                <div style="margin-top: 1.5rem; text-align: right;">
                    <button type="submit" class="btn btn-success" style="padding: 0.8rem 2rem;">
                        <i class="fas fa-save"></i>
                        Save Attendance
                    </button>
                </div>
            </form>
            
            <?php elseif ($view_type == 'monthly' && $monthly_summary): ?>
            <!-- Monthly Summary View -->
            <div class="monthly-grid">
                <?php foreach($monthly_summary as $student): 
                    $attendance_rate = $student['total_days'] > 0 ? ($student['present_days'] / $student['total_days']) * 100 : 0;
                ?>
                <div class="monthly-student-card">
                    <div class="student-header">
                        <div>
                            <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                            <div class="student-id"><?php echo htmlspecialchars($student['Admission_number']); ?></div>
                        </div>
                        <div class="attendance-percentage">
                            <div class="percentage-value"><?php echo number_format($attendance_rate, 1); ?>%</div>
                            <div class="percentage-label">Attendance Rate</div>
                        </div>
                    </div>
                    
                    <div class="attendance-stats">
                        <div class="stat-box stat-present">
                            <div class="stat-value"><?php echo $student['present_days']; ?></div>
                            <div class="stat-label">Present</div>
                        </div>
                        <div class="stat-box stat-absent">
                            <div class="stat-value"><?php echo $student['absent_days']; ?></div>
                            <div class="stat-label">Absent</div>
                        </div>
                        <div class="stat-box stat-late">
                            <div class="stat-value"><?php echo $student['late_days']; ?></div>
                            <div class="stat-label">Late</div>
                        </div>
                        <div class="stat-box stat-total">
                            <div class="stat-value"><?php echo $student['total_days']; ?></div>
                            <div class="stat-label">Total Days</div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <?php elseif ($view_type == 'summary'): ?>
            <!-- Reports View -->
            <div class="no-data">
                <i class="fas fa-chart-bar"></i>
                <h3>Attendance Reports</h3>
                <p>Detailed attendance reports and analytics will be displayed here.</p>
                <button class="btn btn-primary" onclick="generateReport()">
                    <i class="fas fa-chart-line"></i> Generate Report
                </button>
            </div>
            
            <?php else: ?>
            <!-- No Data State -->
            <div class="no-data">
                <i class="fas fa-users"></i>
                <h3>No Students Found</h3>
                <p>There are no active students in this class or no attendance data for the selected period.</p>
            </div>
            <?php endif; ?>
            
        <?php else: ?>
        <!-- No Class Selected -->
        <div class="no-data">
            <i class="fas fa-door-open"></i>
            <h3>Select a Class</h3>
            <p>Please select a class to view and manage attendance.</p>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // View type switching
        function changeView(viewType) {
            const url = new URL(window.location);
            url.searchParams.set('view', viewType);
            window.location.href = url.toString();
        }
        
        // Bulk select functionality
        function selectAll(status) {
            const radios = document.querySelectorAll(`input[value="${status}"]`);
            radios.forEach(radio => {
                radio.checked = true;
                radio.dispatchEvent(new Event('change'));
            });
            
            showNotification(`All students marked as ${status}`, 'success');
        }
        
        // Confirm bulk action
        function confirmBulkAction() {
            const bulkStatus = document.getElementById('bulkStatus').value;
            if (!bulkStatus) {
                alert('Please select a status for bulk action');
                return false;
            }
            return confirm(`Are you sure you want to mark all students as ${bulkStatus}?`);
        }
        
        // Validate attendance form
        function validateAttendance() {
            const radios = document.querySelectorAll('input[type="radio"]');
            let markedCount = 0;
            let totalStudents = <?php echo count($students); ?>;
            
            // Group radios by student
            const students = {};
            radios.forEach(radio => {
                const name = radio.name;
                if (!students[name]) {
                    students[name] = false;
                }
            });
            
            // Check if each student has at least one radio checked
            Object.keys(students).forEach(student => {
                const checked = document.querySelector(`input[name="${student}"]:checked`);
                if (checked) {
                    markedCount++;
                }
            });
            
            if (markedCount < totalStudents) {
                return confirm(`${totalStudents - markedCount} student(s) have not been marked. Continue anyway?`);
            }
            return true;
        }
        
        // Add quick select buttons
        document.addEventListener('DOMContentLoaded', function() {
            // Set today's date as default if no date selected
            const dateInput = document.getElementById('date');
            if (dateInput && !dateInput.value) {
                dateInput.valueAsDate = new Date();
            }
        });
        
        // Export functionality
        function exportAttendance() {
            const classId = document.getElementById('class_id')?.value;
            const date = document.getElementById('date')?.value;
            
            if (!classId) {
                alert('Please select a class first');
                return;
            }
            
            window.location.href = `export_attendance.php?class_id=${classId}&date=${date}&view=<?php echo $view_type; ?>`;
        }
        
        // Print functionality
        function printAttendance() {
            window.print();
        }
        
        // Generate report
        function generateReport() {
            const classId = document.getElementById('class_id')?.value;
            if (!classId) {
                alert('Please select a class first');
                return;
            }
            
            window.location.href = `attendance_report.php?class_id=${classId}&month=<?php echo date('Y-m', strtotime($selected_date)); ?>`;
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                const saveBtn = document.querySelector('button[type="submit"]');
                if (saveBtn) {
                    saveBtn.click();
                }
            }
            
            // Alt + P for present
            if (e.altKey && e.key === 'p') {
                e.preventDefault();
                selectAll('Present');
            }
            
            // Alt + A for absent
            if (e.altKey && e.key === 'a') {
                e.preventDefault();
                selectAll('Absent');
            }
            
            // Alt + L for late
            if (e.altKey && e.key === 'l') {
                e.preventDefault();
                selectAll('Late');
            }
        });
        
        // Add animation styles
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInRight {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
            
            @keyframes slideOutRight {
                from {
                    transform: translateX(0);
                    opacity: 1;
                }
                to {
                    transform: translateX(100%);
                    opacity: 0;
                }
            }
            
            .btn-lg {
                padding: 1rem 2.5rem;
                font-size: 1rem;
            }
        `;
        document.head.appendChild(style);
        // Auto-save indicator
        let autoSaveTimeout;
        document.querySelectorAll('.attendance-radio').forEach(radio => {
            radio.addEventListener('change', function() {
                clearTimeout(autoSaveTimeout);
                autoSaveTimeout = setTimeout(() => {
                    // Show saving indicator
                    const saveBtn = document.querySelector('button[name="mark_attendance"]');
                    const originalText = saveBtn.innerHTML;
                    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                    saveBtn.disabled = true;
                    
                    setTimeout(() => {
                        saveBtn.innerHTML = originalText;
                        saveBtn.disabled = false;
                        // Show saved notification
                        showNotification('Attendance updated automatically', 'success');
                    }, 1000);
                }, 2000); // Auto-save after 2 seconds
            });
        });
        
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 100px;
                right: 20px;
                background: ${type === 'success' ? 'var(--success)' : 'var(--danger)'};
                color: white;
                padding: 1rem 1.5rem;
                border-radius: var(--border-radius-md);
                box-shadow: var(--shadow-lg);
                z-index: 10000;
                animation: slideInRight 0.3s ease;
                display: flex;
                align-items: center;
                gap: 0.5rem;
            `;
            notification.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }
    </script>
</body>
</html>
