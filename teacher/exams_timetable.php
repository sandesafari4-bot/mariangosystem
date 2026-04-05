<?php
include '../config.php';
checkAuth();
checkRole(['teacher', 'admin']);

$role = $_SESSION['role'] ?? 'teacher';
$teacher_id = $_SESSION['user_id'];
$is_admin = ($role === 'admin');

// Handle exam timetable operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Add Exam Entry
    if (isset($_POST['add_exam_entry'])) {
        $class_id = $_POST['class_id'];
        $subject_id = $_POST['subject_id'];
        $exam_date = $_POST['exam_date'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $room_number = $_POST['room_number'];
        $invigilator_id = $_POST['invigilator_id'];
        $max_students = $_POST['max_students'];
        $exam_type = $_POST['exam_type'];
        
        // Check for conflicts
        $conflicts = checkExamConflicts($class_id, $invigilator_id, $room_number, $exam_date, $start_time, $end_time);
        
        if (!empty($conflicts)) {
            $error = "Exam conflicts detected: " . implode(", ", $conflicts);
        } else {
            // Check room capacity
            $class_size = $pdo->prepare("SELECT student_count FROM classes WHERE id = ?");
            $class_size->execute([$class_id]);
            $class = $class_size->fetch();
            
            if ($max_students && $class['student_count'] > $max_students) {
                $error = "Class has {$class['student_count']} students but room capacity is $max_students";
            } else {
                $stmt = $pdo->prepare("INSERT INTO timetable_exams (class_id, subject_id, exam_date, start_time, end_time, room_number, invigilator_id, max_students, exam_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt->execute([$class_id, $subject_id, $exam_date, $start_time, $end_time, $room_number, $invigilator_id, $max_students, $exam_type])) {
                    $success = "Exam scheduled successfully!";
                } else {
                    $error = "Failed to schedule exam. Please try again.";
                }
            }
        }
    }
    
    // Update Exam Entry
    if (isset($_POST['update_exam_entry'])) {
        $exam_id = $_POST['exam_id'];
        $class_id = $_POST['class_id'];
        $subject_id = $_POST['subject_id'];
        $exam_date = $_POST['exam_date'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $room_number = $_POST['room_number'];
        $invigilator_id = $_POST['invigilator_id'];
        $max_students = $_POST['max_students'];
        $exam_type = $_POST['exam_type'];
        
        $conflicts = checkExamConflicts($class_id, $invigilator_id, $room_number, $exam_date, $start_time, $end_time, $exam_id);
        
        if (!empty($conflicts)) {
            $error = "Exam conflicts detected: " . implode(", ", $conflicts);
        } else {
            $stmt = $pdo->prepare("UPDATE timetable_exams SET class_id = ?, subject_id = ?, exam_date = ?, start_time = ?, end_time = ?, room_number = ?, invigilator_id = ?, max_students = ?, exam_type = ? WHERE id = ?");
            if ($stmt->execute([$class_id, $subject_id, $exam_date, $start_time, $end_time, $room_number, $invigilator_id, $max_students, $exam_type, $exam_id])) {
                $success = "Exam updated successfully!";
            } else {
                $error = "Failed to update exam. Please try again.";
            }
        }
    }
    
    // Delete Exam Entry
    if (isset($_POST['delete_exam_entry'])) {
        $exam_id = $_POST['exam_id'];
        $stmt = $pdo->prepare("DELETE FROM timetable_exams WHERE id = ?");
        if ($stmt->execute([$exam_id])) {
            $success = "Exam deleted successfully!";
        } else {
            $error = "Failed to delete exam. Please try again.";
        }
    }
    
    // Bulk Schedule Exams
    if (isset($_POST['bulk_schedule_exams'])) {
        $exam_period = $_POST['exam_period'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $exam_duration = $_POST['exam_duration'];
        
        $result = bulkScheduleExams($exam_period, $start_date, $end_date, $exam_duration);
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
}

function checkExamConflicts($class_id, $invigilator_id, $room_number, $exam_date, $start_time, $end_time, $exclude_id = null) {
    global $pdo;
    $conflicts = [];
    
    $exclude_clause = $exclude_id ? "AND id != ?" : "";
    $params = $exclude_id ? [$class_id, $exam_date, $exclude_id, $end_time, $start_time, $start_time, $end_time, $start_time, $end_time] 
                          : [$class_id, $exam_date, $end_time, $start_time, $start_time, $end_time, $start_time, $end_time];
    
    // Check class conflict
    $class_check = $pdo->prepare("
        SELECT * FROM timetable_exams 
        WHERE class_id = ? AND exam_date = ? $exclude_clause AND (
            (start_time < ? AND end_time > ?) OR 
            (start_time < ? AND end_time > ?) OR
            (start_time >= ? AND start_time < ?)
        )
    ");
    $class_check->execute($params);
    if ($class_check->rowCount() > 0) {
        $conflicts[] = "Class already has an exam at this time";
    }
    
    // Check invigilator conflict
    $invigilator_params = $exclude_id ? [$invigilator_id, $exam_date, $exclude_id, $end_time, $start_time, $start_time, $end_time, $start_time, $end_time] 
                                     : [$invigilator_id, $exam_date, $end_time, $start_time, $start_time, $end_time, $start_time, $end_time];
    
    $invigilator_check = $pdo->prepare("
        SELECT * FROM timetable_exams 
        WHERE invigilator_id = ? AND exam_date = ? $exclude_clause AND (
            (start_time < ? AND end_time > ?) OR 
            (start_time < ? AND end_time > ?) OR
            (start_time >= ? AND start_time < ?)
        )
    ");
    $invigilator_check->execute($invigilator_params);
    if ($invigilator_check->rowCount() > 0) {
        $conflicts[] = "Invigilator is already assigned to another exam";
    }
    
    // Check room conflict
    $room_params = $exclude_id ? [$room_number, $exam_date, $exclude_id, $end_time, $start_time, $start_time, $end_time, $start_time, $end_time] 
                              : [$room_number, $exam_date, $end_time, $start_time, $start_time, $end_time, $start_time, $end_time];
    
    $room_check = $pdo->prepare("
        SELECT * FROM timetable_exams 
        WHERE room_number = ? AND exam_date = ? $exclude_clause AND (
            (start_time < ? AND end_time > ?) OR 
            (start_time < ? AND end_time > ?) OR
            (start_time >= ? AND start_time < ?)
        )
    ");
    $room_check->execute($room_params);
    if ($room_check->rowCount() > 0) {
        $conflicts[] = "Room is already booked for another exam";
    }
    
    return $conflicts;
}

function bulkScheduleExams($exam_period, $start_date, $end_date, $exam_duration) {
    global $pdo;
    
    // Get all classes and their subjects
    $classes_stmt = $pdo->query("SELECT * FROM classes ORDER BY class_name");
    $classes = $classes_stmt->fetchAll();
    
    $scheduled_count = 0;
    $current_date = $start_date;
    $time_slots = [
        ['start' => '09:00', 'end' => '11:00'],
        ['start' => '11:30', 'end' => '13:30'],
        ['start' => '14:00', 'end' => '16:00']
    ];
    
    foreach ($classes as $class) {
        $subjects_stmt = $pdo->prepare("SELECT * FROM subjects WHERE class_id = ?");
        $subjects_stmt->execute([$class['id']]);
        $subjects = $subjects_stmt->fetchAll();
        
        $slot_index = 0;
        $current_date = $start_date;
        
        foreach ($subjects as $subject) {
            if (strtotime($current_date) > strtotime($end_date)) {
                break; // Stop if we've exceeded the end date
            }
            
            $time_slot = $time_slots[$slot_index % count($time_slots)];
            $invigilators_stmt = $pdo->query("SELECT id FROM teachers ORDER BY RAND() LIMIT 1");
            $invigilator = $invigilators_stmt->fetch();
            
            // Adjust end time based on duration
            $end_time = date('H:i', strtotime($time_slot['start'] . ' + ' . $exam_duration . ' hours'));
            
            $conflicts = checkExamConflicts(
                $class['id'],
                $invigilator['id'],
                'Hall ' . (($slot_index % 3) + 1),
                $current_date,
                $time_slot['start'],
                $end_time
            );
            
            if (empty($conflicts)) {
                $stmt = $pdo->prepare("INSERT INTO timetable_exams (class_id, subject_id, exam_date, start_time, end_time, room_number, invigilator_id, exam_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt->execute([$class['id'], $subject['id'], $current_date, $time_slot['start'], $end_time, 'Hall ' . (($slot_index % 3) + 1), $invigilator['id'], $exam_period])) {
                    $scheduled_count++;
                }
            }
            
            $slot_index++;
            if ($slot_index % count($time_slots) == 0) {
                $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
            }
        }
    }
    
    return [
        'success' => true,
        'message' => "Bulk scheduled $scheduled_count exams for the $exam_period period"
    ];
}

// Get filter parameters
$class_filter = $_GET['class_id'] ?? '';
$date_filter = $_GET['exam_date'] ?? '';
$period_filter = $_GET['exam_period'] ?? '';

// Get classes, subjects, and teachers - USING THE SAME STRUCTURE AS TIMETABLE
$classes_stmt = $pdo->query("SELECT * FROM classes ORDER BY class_name");
$subjects_stmt = $pdo->query("SELECT s.*, c.class_name FROM subjects s JOIN classes c ON s.class_id = c.id ORDER BY s.subject_name");
$teachers_stmt = $pdo->query("SELECT * FROM teachers ORDER BY full_name");

$classes = $classes_stmt->fetchAll();
$subjects = $subjects_stmt->fetchAll();
$teachers = $teachers_stmt->fetchAll();

// Get exam timetable data
$exams_query = "
    SELECT te.*, c.class_name, s.subject_name, t.full_name as invigilator_name
    FROM timetable_exams te
    JOIN classes c ON te.class_id = c.id
    JOIN subjects s ON te.subject_id = s.id
    JOIN teachers t ON te.invigilator_id = t.id
    WHERE 1=1
";

$params = [];

if ($class_filter) {
    $exams_query .= " AND te.class_id = ?";
    $params[] = $class_filter;
}

if ($date_filter) {
    $exams_query .= " AND te.exam_date = ?";
    $params[] = $date_filter;
}

if ($period_filter) {
    $exams_query .= " AND te.exam_type = ?";
    $params[] = $period_filter;
}

if (!$is_admin) {
    $exams_query .= " AND (te.invigilator_id = ? OR EXISTS (SELECT 1 FROM subjects s WHERE s.id = te.subject_id AND s.teacher_id = ?))";
    $params[] = $teacher_id;
    $params[] = $teacher_id;
}

$exams_query .= " ORDER BY te.exam_date, te.start_time, c.class_name";

$exams_stmt = $pdo->prepare($exams_query);
$exams_stmt->execute($params);
$exam_entries = $exams_stmt->fetchAll();

// Organize exams by date for calendar view
$calendar_exams = [];
foreach ($exam_entries as $exam) {
    $calendar_exams[$exam['exam_date']][] = $exam;
}

$page_title = "Exam Timetable Management - " . SCHOOL_NAME;
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
        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            transition: margin-left 0.3s ease;
            background: #f5f6fa;
            min-height: calc(100vh - 70px);
        }
        
        .sidebar.collapsed ~ .main-content {
            margin-left: 70px;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .page-title h1 {
            color: #2c3e50;
            margin-bottom: 0.5rem;
            font-size: 1.8rem;
        }
        
        .page-title p {
            color: #7f8c8d;
            margin: 0;
        }
        
        .btn {
            padding: 0.7rem 1.5rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }
        
        .btn-primary { 
            background: #3498db; 
            color: white; 
        }
        
        .btn-primary:hover { 
            background: #2980b9; 
            transform: translateY(-2px); 
        }
        
        .btn-success { 
            background: #27ae60; 
            color: white; 
        }
        
        .btn-success:hover { 
            background: #219a52; 
            transform: translateY(-2px); 
        }
        
        .btn-warning { 
            background: #f39c12; 
            color: white; 
        }
        
        .btn-warning:hover { 
            background: #e67e22; 
            transform: translateY(-2px); 
        }
        
        .btn-danger { 
            background: #e74c3c; 
            color: white; 
        }
        
        .btn-danger:hover { 
            background: #c0392b; 
            transform: translateY(-2px); 
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid #3498db;
            color: #3498db;
        }
        
        .btn-outline:hover {
            background: #3498db;
            color: white;
        }
        
        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }
        
        .admin-badge {
            background: #e74c3c;
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 1rem;
        }
        
        .exam-calendar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }
        
        .exam-day {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .exam-day:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        
        .exam-day-header {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
            padding: 1.2rem;
            font-weight: 600;
            text-align: center;
            font-size: 1.1rem;
        }
        
        .exam-day-content {
            padding: 1.2rem;
            min-height: 200px;
        }
        
        .exam-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid #3498db;
            transition: all 0.3s ease;
        }
        
        .exam-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        
        .exam-time {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .exam-details {
            margin-bottom: 0.8rem;
        }
        
        .exam-subject {
            font-weight: 600;
            color: #2c3e50;
            font-size: 1.1rem;
            margin-bottom: 0.3rem;
        }
        
        .exam-class {
            color: #7f8c8d;
            font-size: 0.9rem;
        }
        
        .exam-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: #7f8c8d;
            margin-bottom: 0.8rem;
        }
        
        .exam-type {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .exam-type-midterm { background: #ffeaa7; color: #e17055; }
        .exam-type-final { background: #fab1a0; color: #d63031; }
        .exam-type-quiz { background: #a29bfe; color: #2d3436; }
        
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }
        
        .alert-success {
            background: rgba(39, 174, 96, 0.1);
            color: #155724;
            border-left-color: #27ae60;
        }
        
        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            color: #721c24;
            border-left-color: #e74c3c;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(5px);
        }
        
        .modal-content {
            background: white;
            border-radius: 10px;
            width: 90%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid #ecf0f1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8f9fa;
            border-radius: 10px 10px 0 0;
        }
        
        .modal-header h3 {
            margin: 0;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #7f8c8d;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }
        
        .close:hover {
            background: #e9ecef;
            color: #e74c3c;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group.full {
            grid-column: 1 / -1;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.9rem;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }
        
        input:focus, select:focus, textarea:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.1);
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr auto;
            gap: 1rem;
            align-items: end;
        }
        
        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .exam-calendar {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .no-data {
            text-align: center;
            padding: 3rem 1.5rem;
            color: #7f8c8d;
        }
        
        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border-left: 4px solid #3498db;
            text-align: center;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #7f8c8d;
            font-size: 0.9rem;
        }
        
        .exam-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.8rem;
        }
        
        .exam-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            background: #e9ecef;
            padding: 0.3rem 0.6rem;
            border-radius: 15px;
            font-size: 0.8rem;
            color: #495057;
        }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1>Exam Timetable Management <?php echo $is_admin ? '<span class="admin-badge">ADMIN</span>' : ''; ?></h1>
                <p>Schedule and manage examination timetables</p>
            </div>
            <div class="page-actions">
                <?php if ($is_admin): ?>
                <button class="btn btn-warning" onclick="openBulkModal()">
                    <i class="fas fa-layer-group"></i> Bulk Schedule
                </button>
                <?php endif; ?>
                <button class="btn btn-primary" onclick="openAddExamModal()">
                    <i class="fas fa-plus"></i> Add Exam
                </button>
            </div>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($exam_entries); ?></div>
                <div class="stat-label">Total Exams</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count($calendar_exams); ?></div>
                <div class="stat-label">Exam Days</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count(array_unique(array_column($exam_entries, 'class_id'))); ?></div>
                <div class="stat-label">Classes with Exams</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count(array_unique(array_column($exam_entries, 'subject_id'))); ?></div>
                <div class="stat-label">Subjects</div>
            </div>
        </div>
        
        <!-- Filters -->
        <div style="background: white; padding: 1.5rem; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 2rem;">
            <form method="GET" class="filter-grid">
                <div class="form-group">
                    <label for="class_id">Class</label>
                    <select id="class_id" name="class_id">
                        <option value="">All Classes</option>
                        <?php foreach($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($class['class_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="exam_date">Exam Date</label>
                    <input type="date" id="exam_date" name="exam_date" value="<?php echo $date_filter; ?>">
                </div>
                <div class="form-group">
                    <label for="exam_period">Exam Period</label>
                    <select id="exam_period" name="exam_period">
                        <option value="">All Periods</option>
                        <option value="Midterm" <?php echo $period_filter == 'Midterm' ? 'selected' : ''; ?>>Midterm</option>
                        <option value="Final" <?php echo $period_filter == 'Final' ? 'selected' : ''; ?>>Final</option>
                        <option value="Quiz" <?php echo $period_filter == 'Quiz' ? 'selected' : ''; ?>>Quiz</option>
                    </select>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Exam Calendar View -->
        <div class="exam-calendar">
            <?php if (!empty($calendar_exams)): ?>
                <?php 
                $dates = array_keys($calendar_exams);
                sort($dates);
                foreach ($dates as $date): 
                ?>
                <div class="exam-day">
                    <div class="exam-day-header">
                        <?php echo date('l, F j, Y', strtotime($date)); ?>
                    </div>
                    <div class="exam-day-content">
                        <?php foreach ($calendar_exams[$date] as $exam): ?>
                        <div class="exam-item">
                            <div class="exam-time">
                                <i class="fas fa-clock"></i>
                                <?php echo date('g:i A', strtotime($exam['start_time'])); ?> - <?php echo date('g:i A', strtotime($exam['end_time'])); ?>
                            </div>
                            <div class="exam-details">
                                <div class="exam-subject"><?php echo htmlspecialchars($exam['subject_name']); ?></div>
                                <div class="exam-class"><?php echo htmlspecialchars($exam['class_name']); ?></div>
                            </div>
                            <div class="exam-meta">
                                <span class="exam-badge">
                                    <i class="fas fa-door-open"></i> <?php echo htmlspecialchars($exam['room_number']); ?>
                                </span>
                                <span class="exam-badge">
                                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($exam['invigilator_name']); ?>
                                </span>
                            </div>
                            <div class="exam-type exam-type-<?php echo strtolower($exam['exam_type']); ?>">
                                <?php echo $exam['exam_type']; ?>
                            </div>
                            <?php if ($is_admin): ?>
                            <div class="exam-actions">
                                <button class="btn btn-outline btn-sm" onclick="openEditExamModal(<?php echo $exam['id']; ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="btn btn-outline btn-sm" onclick="deleteExam(<?php echo $exam['id']; ?>)">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="grid-column: 1 / -1; text-align: center; padding: 3rem; background: white; border-radius: 10px;">
                    <i class="fas fa-calendar-times" style="font-size: 3rem; color: #bdc3c7; margin-bottom: 1rem;"></i>
                    <h3>No Exams Scheduled</h3>
                    <p>No exam entries found matching your filters.</p>
                    <button class="btn btn-primary" onclick="openAddExamModal()" style="margin-top: 1rem;">
                        <i class="fas fa-plus"></i> Schedule First Exam
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Exam Modal -->
    <div id="addExamModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Schedule New Exam</h3>
                <button class="close" onclick="closeModal('addExamModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addExamForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="add_class_id">Class *</label>
                            <select id="add_class_id" name="class_id" required>
                                <option value="">Select Class</option>
                                <?php foreach($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="add_subject_id">Subject *</label>
                            <select id="add_subject_id" name="subject_id" required>
                                <option value="">Select Subject</option>
                                <?php foreach($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>">
                                    <?php echo htmlspecialchars($subject['subject_name']); ?> (<?php echo htmlspecialchars($subject['class_name']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="add_exam_date">Exam Date *</label>
                            <input type="date" id="add_exam_date" name="exam_date" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="add_exam_type">Exam Type *</label>
                            <select id="add_exam_type" name="exam_type" required>
                                <option value="Midterm">Midterm</option>
                                <option value="Final">Final</option>
                                <option value="Quiz">Quiz</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="add_start_time">Start Time *</label>
                            <input type="time" id="add_start_time" name="start_time" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="add_end_time">End Time *</label>
                            <input type="time" id="add_end_time" name="end_time" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="add_room_number">Room Number *</label>
                            <input type="text" id="add_room_number" name="room_number" placeholder="e.g., Room 101" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="add_invigilator_id">Invigilator *</label>
                            <select id="add_invigilator_id" name="invigilator_id" required>
                                <option value="">Select Invigilator</option>
                                <?php foreach($teachers as $teacher): ?>
                                <option value="<?php echo $teacher['id']; ?>">
                                    <?php echo htmlspecialchars($teacher['full_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group full">
                            <label for="add_max_students">Maximum Students (Optional)</label>
                            <input type="number" id="add_max_students" name="max_students" placeholder="Leave empty for no limit" min="1">
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 15px;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addExamModal')">Cancel</button>
                        <button type="submit" name="add_exam_entry" class="btn btn-primary">
                            <i class="fas fa-calendar-plus"></i> Schedule Exam
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Schedule Modal -->
    <div id="bulkModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-layer-group"></i> Bulk Schedule Exams</h3>
                <button class="close" onclick="closeModal('bulkModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="bulkForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="exam_period">Exam Period *</label>
                            <select id="exam_period" name="exam_period" required>
                                <option value="Midterm">Midterm Exams</option>
                                <option value="Final">Final Exams</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="exam_duration">Exam Duration (hours) *</label>
                            <select id="exam_duration" name="exam_duration" required>
                                <option value="2">2 hours</option>
                                <option value="3">3 hours</option>
                                <option value="4">4 hours</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="start_date">Start Date *</label>
                            <input type="date" id="start_date" name="start_date" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="end_date">End Date *</label>
                            <input type="date" id="end_date" name="end_date" required>
                        </div>
                    </div>
                    
                    <div style="background: #f8f9fa; padding: 1rem; border-radius: 6px; margin-top: 1rem;">
                        <h4 style="margin: 0 0 0.5rem 0; color: #2c3e50;">Bulk Scheduling Information</h4>
                        <p style="margin: 0; color: #7f8c8d; font-size: 0.9rem;">
                            This will automatically schedule exams for all classes and subjects within the specified date range.
                            The system will attempt to avoid conflicts and distribute exams evenly.
                        </p>
                    </div>
                    
                    <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 15px;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('bulkModal')">Cancel</button>
                        <button type="submit" name="bulk_schedule_exams" class="btn btn-primary">
                            <i class="fas fa-magic"></i> Generate Schedule
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function openAddExamModal() {
            // Set default date to tomorrow
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            document.getElementById('add_exam_date').value = tomorrow.toISOString().split('T')[0];
            
            // Set default times
            document.getElementById('add_start_time').value = '09:00';
            document.getElementById('add_end_time').value = '11:00';
            
            openModal('addExamModal');
        }
        
        function openBulkModal() {
            // Set default date range (next week)
            const nextWeek = new Date();
            nextWeek.setDate(nextWeek.getDate() + 7);
            const endWeek = new Date(nextWeek);
            endWeek.setDate(nextWeek.getDate() + 5);
            
            document.getElementById('start_date').value = nextWeek.toISOString().split('T')[0];
            document.getElementById('end_date').value = endWeek.toISOString().split('T')[0];
            
            openModal('bulkModal');
        }
        
        function openEditExamModal(examId) {
            // In real implementation, fetch exam data via AJAX and populate edit modal
            alert('Edit functionality for exam ID: ' + examId + ' would open here.');
        }
        
        function deleteExam(examId) {
            if (confirm('Are you sure you want to delete this exam?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'exam_id';
                input.value = examId;
                form.appendChild(input);
                
                const deleteInput = document.createElement('input');
                deleteInput.type = 'hidden';
                deleteInput.name = 'delete_exam_entry';
                deleteInput.value = '1';
                form.appendChild(deleteInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
        
        // Form validation
        document.getElementById('addExamForm')?.addEventListener('submit', function(e) {
            const startTime = document.getElementById('add_start_time').value;
            const endTime = document.getElementById('add_end_time').value;
            const examDate = document.getElementById('add_exam_date').value;
            const today = new Date().toISOString().split('T')[0];
            
            if (startTime >= endTime) {
                e.preventDefault();
                alert('End time must be after start time.');
                return false;
            }
            
            if (examDate < today) {
                e.preventDefault();
                alert('Exam date cannot be in the past.');
                return false;
            }
        });
        
        // Auto-close alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                if (alert.style.display !== 'none') {
                    alert.style.opacity = '0';
                    alert.style.transition = 'opacity 0.5s';
                    setTimeout(() => alert.style.display = 'none', 500);
                }
            });
        }, 5000);
        
        // Initialize form defaults
        document.addEventListener('DOMContentLoaded', function() {
            // Set default exam date to tomorrow if not set
            const examDateInput = document.getElementById('add_exam_date');
            if (examDateInput && !examDateInput.value) {
                const tomorrow = new Date();
                tomorrow.setDate(tomorrow.getDate() + 1);
                examDateInput.value = tomorrow.toISOString().split('T')[0];
            }
            
            // Set default times
            const startTimeInput = document.getElementById('add_start_time');
            const endTimeInput = document.getElementById('add_end_time');
            if (startTimeInput && !startTimeInput.value) {
                startTimeInput.value = '09:00';
            }
            if (endTimeInput && !endTimeInput.value) {
                endTimeInput.value = '11:00';
            }
        });
    </script>
</body>
</html>