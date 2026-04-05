<?php
include '../config.php';
checkAuth();
checkRole(['teacher']);

$teacher_id = $_SESSION['user_id'];

// Get filter parameters
$class_id = $_GET['class_id'] ?? '';
$student_id = $_GET['Admission_number'] ?? '';
$report_type = $_GET['report_type'] ?? 'progress';
$term = $_GET['term'] ?? 'Term 1';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Get teacher's classes and students
$teacher_classes = $pdo->prepare("
    SELECT DISTINCT c.* 
    FROM classes c 
    LEFT JOIN subjects s ON c.id = s.class_id 
    WHERE s.teacher_id = ? OR c.class_teacher_id = ?
    ORDER BY c.class_name
");
$teacher_classes->execute([$teacher_id, $teacher_id]);
$classes = $teacher_classes->fetchAll();

$students = [];
if ($class_id) {
    $students_stmt = $pdo->prepare("
        SELECT s.* 
        FROM students s 
        WHERE s.class_id = ? AND s.status = 'active'
        ORDER BY s.full_name
    ");
    $students_stmt->execute([$class_id]);
    $students = $students_stmt->fetchAll();
}

// Get report data based on type
$report_data = [];
$chart_data = [];

if ($report_type === 'progress' && $class_id) {
    // Progress report - class averages per subject
    $progress_stmt = $pdo->prepare("
        SELECT 
            sub.subject_name,
            AVG(CASE WHEN g.term = 'Term 1' THEN g.marks END) as term1_avg,
            AVG(CASE WHEN g.term = 'Term 2' THEN g.marks END) as term2_avg,
            AVG(CASE WHEN g.term = 'Term 3' THEN g.marks END) as term3_avg,
            COUNT(DISTINCT g.student_id) as students_graded
        FROM subjects sub
        LEFT JOIN grades g ON sub.id = g.subject_id
        LEFT JOIN students s ON g.student_id = s.id
        WHERE sub.teacher_id = ? AND s.class_id = ?
        GROUP BY sub.id, sub.subject_name
        ORDER BY sub.subject_name
    ");
    $progress_stmt->execute([$teacher_id, $class_id]);
    $report_data = $progress_stmt->fetchAll();
    
    // Prepare chart data
    $chart_labels = [];
    $term1_data = [];
    $term2_data = [];
    $term3_data = [];
    
    foreach ($report_data as $row) {
        $chart_labels[] = $row['subject_name'];
        $term1_data[] = $row['term1_avg'] ? round($row['term1_avg'], 1) : 0;
        $term2_data[] = $row['term2_avg'] ? round($row['term2_avg'], 1) : 0;
        $term3_data[] = $row['term3_avg'] ? round($row['term3_avg'], 1) : 0;
    }
    
    $chart_data = [
        'labels' => $chart_labels,
        'term1' => $term1_data,
        'term2' => $term2_data,
        'term3' => $term3_data
    ];
} elseif ($report_type === 'attendance' && $class_id) {
    // Attendance report
    $attendance_stmt = $pdo->prepare("
        SELECT 
            s.full_name,
            s.Admission_number,
            COUNT(CASE WHEN a.status = 'Present' THEN 1 END) as present_days,
            COUNT(CASE WHEN a.status = 'Absent' THEN 1 END) as absent_days,
            COUNT(CASE WHEN a.status = 'Late' THEN 1 END) as late_days,
            COUNT(CASE WHEN a.status = 'Excused' THEN 1 END) as excused_days,
            COUNT(*) as total_days,
            ROUND((COUNT(CASE WHEN a.status = 'Present' THEN 1 END) / COUNT(*)) * 100, 1) as attendance_rate
        FROM students s
        LEFT JOIN attendance a ON s.id = a.student_id
        WHERE s.class_id = ? AND a.date BETWEEN ? AND ?
        GROUP BY s.id, s.full_name, s.Admission_number
        ORDER BY s.full_name
    ");
    $attendance_stmt->execute([$class_id, $date_from, $date_to]);
    $report_data = $attendance_stmt->fetchAll();
} elseif ($report_type === 'student' && $student_id) {
    // Individual student report
    $student_stmt = $pdo->prepare("
        SELECT s.*, c.class_name 
        FROM students s 
        JOIN classes c ON s.class_id = c.id 
        WHERE s.id = ?
    ");
    $student_stmt->execute([$student_id]);
    $student_info = $student_stmt->fetch();
    
    // Get student grades
    $grades_stmt = $pdo->prepare("
        SELECT 
            sub.subject_name,
            g.term,
            g.marks,
            g.remarks,
            g.created_at
        FROM grades g
        JOIN subjects sub ON g.subject_id = sub.id
        WHERE g.Admission_number = ?
        ORDER BY sub.subject_name, g.term
    ");
    $grades_stmt->execute([$student_id]);
    $student_grades = $grades_stmt->fetchAll();
    
    // Get student attendance
    $attendance_stmt = $pdo->prepare("
        SELECT 
            date,
            status,
            remarks
        FROM attendance
        WHERE Admission_number = ? AND date BETWEEN ? AND ?
        ORDER BY date DESC
    ");
    $attendance_stmt->execute([$student_id, $date_from, $date_to]);
    $student_attendance = $attendance_stmt->fetchAll();
    
    $report_data = [
        'student_info' => $student_info,
        'grades' => $student_grades,
        'attendance' => $student_attendance
    ];
}

// Get overall statistics
$overall_stats = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT s.id) as total_students,
        AVG(g.marks) as overall_average,
        COUNT(DISTINCT a.id) as total_assignments,
        COUNT(DISTINCT sub.id) as total_subjects
    FROM students s
    JOIN classes c ON s.class_id = c.id
    LEFT JOIN subjects sub ON c.id = sub.class_id
    LEFT JOIN grades g ON s.id = g.student_id
    LEFT JOIN assignments a ON sub.id = a.subject_id
    WHERE (sub.teacher_id = ? OR c.class_teacher_id = ?) AND s.status = 'active'
");
$overall_stats->execute([$teacher_id, $teacher_id]);
$stats = $overall_stats->fetch();

$page_title = "Reports & Analytics - " . SCHOOL_NAME;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        /* Header Styles */
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
        
        .page-actions {
            display: flex;
            gap: 1rem;
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
        
        /* Stats Grid */
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
        
        .stat-card.students { border-left-color: #3498db; }
        .stat-card.average { border-left-color: #27ae60; }
        .stat-card.assignments { border-left-color: #f39c12; }
        .stat-card.subjects { border-left-color: #9b59b6; }
        
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
        
        /* Content Layout */
        .content-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
        }
        
        .main-content-area {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .sidebar-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        
        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid #ecf0f1;
            background: #f8f9fa;
        }
        
        .card-header h3 {
            color: #2c3e50;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.2rem;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        /* Filters */
        .filters {
            padding: 1.5rem;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr auto;
            gap: 1rem;
            align-items: end;
        }
        
        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .form-group {
            margin-bottom: 0;
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
        
        /* Report Content */
        .report-content {
            padding: 1.5rem;
        }
        
        .chart-container {
            height: 400px;
            margin-bottom: 2rem;
        }
        
        .report-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.5rem;
        }
        
        .report-table th,
        .report-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .report-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .report-table tr:hover {
            background: #f8f9fa;
        }
        
        .grade-badge {
            padding: 0.3rem 0.6rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .grade-a { background: rgba(39, 174, 96, 0.1); color: #27ae60; }
        .grade-b { background: rgba(52, 152, 219, 0.1); color: #3498db; }
        .grade-c { background: rgba(243, 156, 18, 0.1); color: #f39c12; }
        .grade-d { background: rgba(231, 76, 60, 0.1); color: #e74c3c; }
        .grade-f { background: rgba(231, 76, 60, 0.2); color: #c0392b; }
        
        .attendance-badge {
            padding: 0.3rem 0.6rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .present { background: rgba(39, 174, 96, 0.1); color: #27ae60; }
        .absent { background: rgba(231, 76, 60, 0.1); color: #e74c3c; }
        .late { background: rgba(243, 156, 18, 0.1); color: #f39c12; }
        .excused { background: rgba(52, 152, 219, 0.1); color: #3498db; }
        
        .student-profile {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .student-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3498db, #2980b9);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            font-weight: bold;
            flex-shrink: 0;
        }
        
        .student-details {
            flex: 1;
        }
        
        .student-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        
        .student-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            color: #7f8c8d;
        }
        
        .info-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .progress-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .progress-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-left: 4px solid #3498db;
        }
        
        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .progress-title {
            font-weight: 600;
            color: #2c3e50;
        }
        
        .progress-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .progress-bar {
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.5rem;
        }
        
        .progress-fill {
            height: 100%;
            background: #27ae60;
            border-radius: 4px;
        }
        
        /* No Data State */
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
        
        /* Export Options */
        .export-options {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #ecf0f1;
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
                <h1>Reports & Analytics</h1>
                <p>Generate detailed reports and analyze student performance</p>
            </div>
            <div class="page-actions">
                <button class="btn btn-warning" onclick="printReport()">
                    <i class="fas fa-print"></i> Print Report
                </button>
                <button class="btn btn-primary" onclick="exportReport()">
                    <i class="fas fa-download"></i> Export PDF
                </button>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card students">
                <div class="stat-number"><?php echo $stats['total_students'] ?? 0; ?></div>
                <div class="stat-label">Total Students</div>
            </div>
            <div class="stat-card average">
                <div class="stat-number"><?php echo isset($stats['overall_average']) ? round($stats['overall_average'], 1) : '0.0'; ?></div>
                <div class="stat-label">Overall Average</div>
            </div>
            <div class="stat-card assignments">
                <div class="stat-number"><?php echo $stats['total_assignments'] ?? 0; ?></div>
                <div class="stat-label">Total Assignments</div>
            </div>
            <div class="stat-card subjects">
                <div class="stat-number"><?php echo $stats['total_subjects'] ?? 0; ?></div>
                <div class="stat-label">Subjects</div>
            </div>
        </div>
        
        <div class="content-layout">
            <div class="main-content-area">
                <!-- Filters -->
                <div class="filters">
                    <form method="GET" id="reportFilter">
                        <div class="filter-grid">
                            <div class="form-group">
                                <label for="report_type">Report Type</label>
                                <select id="report_type" name="report_type" onchange="updateReportFilters()">
                                    <option value="progress" <?php echo $report_type == 'progress' ? 'selected' : ''; ?>>Progress Report</option>
                                    <option value="attendance" <?php echo $report_type == 'attendance' ? 'selected' : ''; ?>>Attendance Report</option>
                                    <option value="student" <?php echo $report_type == 'student' ? 'selected' : ''; ?>>Student Report</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="class_id">Class</label>
                                <select id="class_id" name="class_id" onchange="updateStudentList()">
                                    <option value="">Select Class</option>
                                    <?php foreach($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $class_id == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" id="student_field" style="display: <?php echo $report_type == 'student' ? 'block' : 'none'; ?>">
                                <label for="student_id">Student</label>
                                <select id="student_id" name="student_id">
                                    <option value="">Select Student</option>
                                    <?php foreach($students as $student): ?>
                                    <option value="<?php echo $student['id']; ?>" <?php echo $student_id == $student['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($student['full_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="term">Term</label>
                                <select id="term" name="term">
                                    <option value="Term 1" <?php echo $term == 'Term 1' ? 'selected' : ''; ?>>Term 1</option>
                                    <option value="Term 2" <?php echo $term == 'Term 2' ? 'selected' : ''; ?>>Term 2</option>
                                    <option value="Term 3" <?php echo $term == 'Term 3' ? 'selected' : ''; ?>>Term 3</option>
                                </select>
                            </div>
                            <div class="form-group" id="date_range_field" style="display: <?php echo in_array($report_type, ['attendance', 'student']) ? 'block' : 'none'; ?>">
                                <label for="date_from">Date Range</label>
                                <div style="display: flex; gap: 0.5rem;">
                                    <input type="date" id="date_from" name="date_from" value="<?php echo $date_from; ?>" style="flex: 1;">
                                    <input type="date" id="date_to" name="date_to" value="<?php echo $date_to; ?>" style="flex: 1;">
                                </div>
                            </div>
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-chart-bar"></i> Generate Report
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Report Content -->
                <div class="report-content">
                    <?php if ($report_type === 'progress' && $class_id): ?>
                        <!-- Progress Report -->
                        <h3 style="margin-bottom: 1.5rem; color: #2c3e50;">Class Progress Report - Term Comparison</h3>
                        
                        <?php if (!empty($chart_data['labels'])): ?>
                            <div class="chart-container">
                                <canvas id="progressChart"></canvas>
                            </div>
                            
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Term 1 Average</th>
                                        <th>Term 2 Average</th>
                                        <th>Term 3 Average</th>
                                        <th>Students Graded</th>
                                        <th>Trend</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($report_data as $row): 
                                        $term1_avg = $row['term1_avg'] ? round($row['term1_avg'], 1) : 'N/A';
                                        $term2_avg = $row['term2_avg'] ? round($row['term2_avg'], 1) : 'N/A';
                                        $term3_avg = $row['term3_avg'] ? round($row['term3_avg'], 1) : 'N/A';
                                        
                                        // Calculate trend
                                        $trend = 'stable';
                                        if ($term1_avg !== 'N/A' && $term3_avg !== 'N/A') {
                                            if ($term3_avg > $term1_avg) $trend = 'improving';
                                            elseif ($term3_avg < $term1_avg) $trend = 'declining';
                                        }
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row['subject_name']); ?></strong></td>
                                        <td><?php echo $term1_avg; ?></td>
                                        <td><?php echo $term2_avg; ?></td>
                                        <td><?php echo $term3_avg; ?></td>
                                        <td><?php echo $row['students_graded']; ?></td>
                                        <td>
                                            <span class="grade-badge <?php 
                                                if ($trend === 'improving') echo 'grade-a';
                                                elseif ($trend === 'declining') echo 'grade-f';
                                                else echo 'grade-c';
                                            ?>">
                                                <i class="fas fa-<?php 
                                                    if ($trend === 'improving') echo 'arrow-up';
                                                    elseif ($trend === 'declining') echo 'arrow-down';
                                                    else echo 'minus';
                                                ?>"></i>
                                                <?php echo ucfirst($trend); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-chart-line"></i>
                                <h3>No Data Available</h3>
                                <p>No grade data found for the selected class and terms.</p>
                            </div>
                        <?php endif; ?>
                        
                    <?php elseif ($report_type === 'attendance' && $class_id): ?>
                        <!-- Attendance Report -->
                        <h3 style="margin-bottom: 1.5rem; color: #2c3e50;">Attendance Report - <?php echo date('M j, Y', strtotime($date_from)); ?> to <?php echo date('M j, Y', strtotime($date_to)); ?></h3>
                        
                        <?php if (!empty($report_data)): ?>
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Student ID</th>
                                        <th>Present</th>
                                        <th>Absent</th>
                                        <th>Late</th>
                                        <th>Excused</th>
                                        <th>Total Days</th>
                                        <th>Attendance Rate</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($report_data as $row): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row['full_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['Admission_number'] ?? $row['Admission_number']); ?></td>
                                        <td><span class="attendance-badge present"><?php echo $row['present_days']; ?></span></td>
                                        <td><span class="attendance-badge absent"><?php echo $row['absent_days']; ?></span></td>
                                        <td><span class="attendance-badge late"><?php echo $row['late_days']; ?></span></td>
                                        <td><span class="attendance-badge excused"><?php echo $row['excused_days']; ?></span></td>
                                        <td><?php echo $row['total_days']; ?></td>
                                        <td>
                                            <div class="progress-card">
                                                <div class="progress-header">
                                                    <span class="progress-title">Rate</span>
                                                    <span class="progress-value"><?php echo $row['attendance_rate']; ?>%</span>
                                                </div>
                                                <div class="progress-bar">
                                                    <div class="progress-fill" style="width: <?php echo $row['attendance_rate']; ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-calendar-check"></i>
                                <h3>No Attendance Data</h3>
                                <p>No attendance records found for the selected class and date range.</p>
                            </div>
                        <?php endif; ?>
                        
                    <?php elseif ($report_type === 'student' && $student_id && isset($report_data['student_info'])): ?>
                        <!-- Student Report -->
                        <?php $student = $report_data['student_info']; ?>
                        <div class="student-profile">
                            <div class="student-avatar">
                                <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
                            </div>
                            <div class="student-details">
                                <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                <div class="student-info">
                                    <div class="info-item">
                                        <i class="fas fa-id-card"></i>
                                        <span>ID: <?php echo htmlspecialchars($student['Admission_number'] ?? $student['Admission_number']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-chalkboard"></i>
                                        <span>Class: <?php echo htmlspecialchars($student['class_name']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-phone"></i>
                                        <span>Phone: <?php echo htmlspecialchars($student['phone'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-envelope"></i>
                                        <span>Email: <?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="progress-grid">
                            <div class="progress-card">
                                <div class="progress-header">
                                    <span class="progress-title">Overall Average</span>
                                    <span class="progress-value">
                                        <?php 
                                            $grades = $report_data['grades'];
                                            $total_marks = 0;
                                            $count = 0;
                                            foreach ($grades as $grade) {
                                                if ($grade['marks'] !== null) {
                                                    $total_marks += $grade['marks'];
                                                    $count++;
                                                }
                                            }
                                            echo $count > 0 ? round($total_marks / $count, 1) : 'N/A';
                                        ?>
                                    </span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $count > 0 ? ($total_marks / $count) : 0; ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="progress-card">
                                <div class="progress-header">
                                    <span class="progress-title">Subjects Taken</span>
                                    <span class="progress-value"><?php echo count(array_unique(array_column($grades, 'subject_name'))); ?></span>
                                </div>
                            </div>
                            
                            <div class="progress-card">
                                <div class="progress-header">
                                    <span class="progress-title">Assignments Submitted</span>
                                    <span class="progress-value"><?php echo count($grades); ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <h4 style="margin-bottom: 1rem; color: #2c3e50;">Academic Performance</h4>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>Term</th>
                                    <th>Marks</th>
                                    <th>Grade</th>
                                    <th>Remarks</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($report_data['grades'] as $grade): 
                                    $marks = $grade['marks'];
                                    $grade_letter = 'N/A';
                                    if ($marks !== null) {
                                        if ($marks >= 80) $grade_letter = 'A';
                                        elseif ($marks >= 70) $grade_letter = 'B';
                                        elseif ($marks >= 60) $grade_letter = 'C';
                                        elseif ($marks >= 50) $grade_letter = 'D';
                                        else $grade_letter = 'F';
                                    }
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($grade['subject_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($grade['term']); ?></td>
                                    <td><?php echo $marks !== null ? $marks : 'N/A'; ?></td>
                                    <td>
                                        <?php if ($grade_letter !== 'N/A'): ?>
                                        <span class="grade-badge grade-<?php echo strtolower($grade_letter); ?>">
                                            <?php echo $grade_letter; ?>
                                        </span>
                                        <?php else: ?>
                                        N/A
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($grade['remarks'] ?? 'No remarks'); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($grade['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <h4 style="margin: 2rem 0 1rem 0; color: #2c3e50;">Attendance Record</h4>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($report_data['attendance'] as $attendance): ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime($attendance['date'])); ?></td>
                                    <td>
                                        <span class="attendance-badge <?php echo strtolower($attendance['status']); ?>">
                                            <?php echo htmlspecialchars($attendance['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($attendance['remarks'] ?? 'No remarks'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-chart-bar"></i>
                            <h3>Select Report Parameters</h3>
                            <p>Please select a report type and required filters to generate the report.</p>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($report_data) || ($report_type === 'student' && $student_id)): ?>
                    <div class="export-options">
                        <button class="btn btn-outline" onclick="printReport()">
                            <i class="fas fa-print"></i> Print Report
                        </button>
                        <button class="btn btn-primary" onclick="exportReport()">
                            <i class="fas fa-download"></i> Export as PDF
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Update report filters based on report type
        function updateReportFilters() {
            const reportType = document.getElementById('report_type').value;
            const studentField = document.getElementById('student_field');
            const dateRangeField = document.getElementById('date_range_field');
            
            if (reportType === 'student') {
                studentField.style.display = 'block';
                dateRangeField.style.display = 'block';
                updateStudentList();
            } else if (reportType === 'attendance') {
                studentField.style.display = 'none';
                dateRangeField.style.display = 'block';
            } else {
                studentField.style.display = 'none';
                dateRangeField.style.display = 'none';
            }
        }
        
        // Update student list based on selected class
        function updateStudentList() {
            const classEl = document.getElementById('class_id');
            const studentSelect = document.getElementById('student_id');
            if (!studentSelect || !classEl) return; // nothing to do

            const classId = classEl.value;
            if (!classId) {
                studentSelect.innerHTML = '<option value="">Select Student</option>';
                studentSelect.disabled = true;
                return;
            }

            // Load students for the selected class via existing endpoint
            studentSelect.disabled = true;
            studentSelect.innerHTML = '<option value="">Loading students...</option>';
            fetch('../admin/fees.php?action=students&class_id=' + encodeURIComponent(classId))
                .then(r => r.json())
                .then(data => {
                    studentSelect.innerHTML = '<option value="">Select Student</option>';
                    if (Array.isArray(data) && data.length) {
                        data.forEach(s => {
                            const opt = document.createElement('option');
                            opt.value = s.id || s.id;
                            opt.textContent = (s.full_name ? s.full_name : s.name) + (s.Admission_number ? (' (' + s.Admission_number + ')') : s.Admission_number ? (' (' + s.Admission_number + ')') : '');
                            studentSelect.appendChild(opt);
                        });
                        studentSelect.disabled = false;
                    } else {
                        studentSelect.innerHTML = '<option value="">No students found</option>';
                        studentSelect.disabled = true;
                    }
                })
                .catch(err => {
                    console.error(err);
                    studentSelect.innerHTML = '<option value="">Failed to load</option>';
                    studentSelect.disabled = true;
                });
        }
        
        // Print report
        function printReport() {
            window.print();
        }
        
        // Export report as PDF
        function exportReport() {
            // Open a printable version of the current report in a new tab which the user can save as PDF
            const params = new URLSearchParams(window.location.search);
            // Indicate printable view
            params.set('print', '1');
            const url = window.location.pathname + '?' + params.toString();
            window.open(url, '_blank');
        }
        
        // Initialize Chart.js for progress report
        <?php if ($report_type === 'progress' && !empty($chart_data)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('progressChart').getContext('2d');
            const progressChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($chart_data['labels']); ?>,
                    datasets: [
                        {
                            label: 'Term 1',
                            data: <?php echo json_encode($chart_data['term1']); ?>,
                            backgroundColor: 'rgba(52, 152, 219, 0.7)',
                            borderColor: 'rgba(52, 152, 219, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Term 2',
                            data: <?php echo json_encode($chart_data['term2']); ?>,
                            backgroundColor: 'rgba(243, 156, 18, 0.7)',
                            borderColor: 'rgba(243, 156, 18, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Term 3',
                            data: <?php echo json_encode($chart_data['term3']); ?>,
                            backgroundColor: 'rgba(39, 174, 96, 0.7)',
                            borderColor: 'rgba(39, 174, 96, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            title: {
                                display: true,
                                text: 'Average Marks'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Subjects'
                            }
                        }
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'Class Performance Across Terms'
                        },
                        legend: {
                            position: 'top',
                        }
                    }
                }
            });
        });
        <?php endif; ?>
        
        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            updateReportFilters();
            
            // Set default date range to last 30 days if not set
            const dateFrom = document.getElementById('date_from');
            const dateTo = document.getElementById('date_to');
            
            if (!dateFrom.value) {
                const thirtyDaysAgo = new Date();
                thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
                dateFrom.valueAsDate = thirtyDaysAgo;
            }
            
            if (!dateTo.value) {
                dateTo.valueAsDate = new Date();
            }
            // If opened with ?print=1, automatically print (useful for export/print flow)
            try {
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.get('print') === '1') {
                    // small timeout to ensure rendering complete
                    setTimeout(() => window.print(), 400);
                }
            } catch (e) {
                // ignore
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + P to print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printReport();
            }
            
            // Ctrl + E to export
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                exportReport();
            }
        });
    </script>
</body>
</html>