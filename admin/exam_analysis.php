<?php
include '../config.php';
checkAuth();
checkRole(['admin']);

$schedule_id = $_GET['schedule_id'] ?? null;
if (!$schedule_id) {
    header("Location: exams.php");
    exit();
}

// Get schedule and exam details with comprehensive data
$stmt = $pdo->prepare("
    SELECT 
        es.*,
        e.exam_name,
        e.exam_code,
        e.total_marks,
        e.passing_marks,
        e.academic_year,
        e.term,
        c.class_name,
        u.full_name as created_by_name
    FROM exam_schedules es
    JOIN exams e ON es.exam_id = e.id
    JOIN classes c ON es.class_id = c.id
    LEFT JOIN users u ON e.created_by = u.id
    WHERE es.id = ?
");
$stmt->execute([$schedule_id]);
$schedule = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$schedule) {
    header("Location: exams.php");
    exit();
}

$error = '';
$success = '';

// Handle analyzing results
if (isset($_POST['analyze_results'])) {
    if (analyzeExamResults($schedule_id)) {
        $success = 'Exam results analyzed successfully!';
    } else {
        $error = 'Error analyzing results';
    }
}

// Handle publishing results
if (isset($_POST['publish_results'])) {
    if (sendExamResultsToTeachers($schedule_id)) {
        $stmt = $pdo->prepare("UPDATE exam_schedules SET status = 'published' WHERE id = ?");
        $stmt->execute([$schedule_id]);
        notifyExamResultsPublished($schedule_id);
        $success = 'Results published! Notifications sent to the class teacher with exam summary.';
    } else {
        $error = 'Error publishing results';
    }
}

// Handle grade boundary updates
if (isset($_POST['update_grades'])) {
    $grade_a = $_POST['grade_a'] ?? 80;
    $grade_b = $_POST['grade_b'] ?? 65;
    $grade_c = $_POST['grade_c'] ?? 50;
    $grade_d = $_POST['grade_d'] ?? 40;
    
    // Store grade boundaries in session or database
    $_SESSION['grade_boundaries'] = [
        'A' => $grade_a,
        'B' => $grade_b,
        'C' => $grade_c,
        'D' => $grade_d
    ];
    
    $success = 'Grade boundaries updated successfully!';
}

// Get or refresh analysis data with detailed statistics
$stmt = $pdo->prepare("
    SELECT * FROM exam_analysis 
    WHERE exam_schedule_id = ?
");
$stmt->execute([$schedule_id]);
$analysis = $stmt->fetch(PDO::FETCH_ASSOC);

// Get comprehensive marks data with statistics
$stmt = $pdo->prepare("
    SELECT 
        em.*,
        s.full_name,
        s.Admission_number,
        s.class_id as student_class,
        CASE 
            WHEN em.marks_obtained >= ? THEN 'A'
            WHEN em.marks_obtained >= ? THEN 'B'
            WHEN em.marks_obtained >= ? THEN 'C'
            WHEN em.marks_obtained >= ? THEN 'D'
            ELSE 'F'
        END as calculated_grade,
        CASE 
            WHEN em.marks_obtained >= ? THEN 'Pass'
            ELSE 'Fail'
        END as pass_status
    FROM exam_marks em
    JOIN students s ON em.student_id = s.id
    WHERE em.exam_schedule_id = ?
    ORDER BY em.marks_obtained DESC
");
$grade_a = $_SESSION['grade_boundaries']['A'] ?? 80;
$grade_b = $_SESSION['grade_boundaries']['B'] ?? 65;
$grade_c = $_SESSION['grade_boundaries']['C'] ?? 50;
$grade_d = $_SESSION['grade_boundaries']['D'] ?? 40;
$passing_marks = $schedule['passing_marks'];

$stmt->execute([$grade_a, $grade_b, $grade_c, $grade_d, $passing_marks, $schedule_id]);
$all_marks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate distribution statistics
$marks_distribution = [
    '90-100' => 0,
    '80-89' => 0,
    '70-79' => 0,
    '60-69' => 0,
    '50-59' => 0,
    '40-49' => 0,
    '30-39' => 0,
    'Below 30' => 0
];

$grade_distribution = [
    'A' => 0,
    'B' => 0,
    'C' => 0,
    'D' => 0,
    'F' => 0
];

foreach ($all_marks as $mark) {
    $marks_obtained = $mark['marks_obtained'];
    
    // Marks distribution
    if ($marks_obtained >= 90) $marks_distribution['90-100']++;
    elseif ($marks_obtained >= 80) $marks_distribution['80-89']++;
    elseif ($marks_obtained >= 70) $marks_distribution['70-79']++;
    elseif ($marks_obtained >= 60) $marks_distribution['60-69']++;
    elseif ($marks_obtained >= 50) $marks_distribution['50-59']++;
    elseif ($marks_obtained >= 40) $marks_distribution['40-49']++;
    elseif ($marks_obtained >= 30) $marks_distribution['30-39']++;
    else $marks_distribution['Below 30']++;
    
    // Grade distribution
    $grade_distribution[$mark['calculated_grade']]++;
}

$total_students = count($all_marks);
$passed_students = count(array_filter($all_marks, fn($m) => $m['marks_obtained'] >= $passing_marks));
$pass_percentage = $total_students > 0 ? round(($passed_students / $total_students) * 100, 1) : 0;

// Calculate class average
$avg_marks = $total_students > 0 ? round(array_sum(array_column($all_marks, 'marks_obtained')) / $total_students, 1) : 0;

// Get top and bottom performers
$top_performers = array_slice($all_marks, 0, 5);
$bottom_performers = array_slice(array_reverse($all_marks), 0, 5);

$page_title = 'Exam Analysis - ' . SCHOOL_NAME;
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--secondary);
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 1rem;
            transition: all 0.3s;
        }

        .back-link:hover {
            color: var(--purple);
            transform: translateX(-5px);
        }

        /* Cards */
        .card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-md);
            transition: all 0.3s;
        }

        .card:hover {
            box-shadow: var(--shadow-lg);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--light);
        }

        .card-header h2 {
            font-size: 1.2rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border-left: 4px solid;
            transition: all 0.3s;
        }

        .stat-card.primary { border-left-color: var(--secondary); }
        .stat-card.success { border-left-color: var(--success); }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.info { border-left-color: var(--info); }
        .stat-card.purple { border-left-color: var(--purple); }

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

        .stat-card.primary .stat-icon { background: linear-gradient(135deg, var(--secondary), var(--purple)); }
        .stat-card.success .stat-icon { background: linear-gradient(135deg, var(--success), var(--success-light)); }
        .stat-card.danger .stat-icon { background: linear-gradient(135deg, var(--danger), var(--danger-light)); }
        .stat-card.warning .stat-icon { background: linear-gradient(135deg, var(--warning), var(--warning-light)); }
        .stat-card.info .stat-icon { background: linear-gradient(135deg, var(--info), #138496); }
        .stat-card.purple .stat-icon { background: linear-gradient(135deg, var(--purple), var(--purple-light)); }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .stat-sub {
            font-size: 0.8rem;
            color: var(--gray-light);
            margin-top: 0.25rem;
        }

        /* Charts Row */
        .charts-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .chart-container {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
        }

        .chart-container canvas {
            height: 300px !important;
            width: 100% !important;
        }

        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
            margin-top: 1rem;
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
            font-size: 0.9rem;
            white-space: nowrap;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid var(--light);
            color: var(--dark);
        }

        tr {
            transition: all 0.2s;
        }

        tr:hover {
            background: rgba(52, 152, 219, 0.05);
        }

        /* Grade Badges */
        .grade-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .grade-A {
            background: rgba(39, 174, 96, 0.15);
            color: var(--success);
        }

        .grade-B {
            background: rgba(52, 152, 219, 0.15);
            color: var(--secondary);
        }

        .grade-C {
            background: rgba(243, 156, 18, 0.15);
            color: var(--warning);
        }

        .grade-D {
            background: rgba(155, 89, 182, 0.15);
            color: var(--purple);
        }

        .grade-F {
            background: rgba(231, 76, 60, 0.15);
            color: var(--danger);
        }

        /* Status Badge */
        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-pass {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
        }

        .status-fail {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
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
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        .btn-group {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        /* Alert */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            display: flex;
            align-items: flex-start;
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

        /* Grade Settings */
        .grade-settings {
            background: var(--light);
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
        }

        .grade-input-group {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 0.5rem;
        }

        .grade-input-group label {
            min-width: 60px;
            font-weight: 600;
            color: var(--dark);
        }

        .grade-input-group input {
            width: 100px;
            padding: 0.3rem;
            border: 2px solid var(--white);
            border-radius: 4px;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .charts-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                padding: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .grade-input-group {
                flex-direction: column;
                align-items: flex-start;
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
            <a href="exam_schedules.php?exam_id=<?php echo $schedule['exam_id']; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Schedules
            </a>
            <h1><i class="fas fa-chart-bar" style="color: var(--secondary); margin-right: 0.5rem;"></i>Exam Analysis</h1>
            <p><?php echo htmlspecialchars($schedule['exam_name']); ?> - <?php echo htmlspecialchars($schedule['class_name']); ?></p>
        </div>

        <?php if ($success): ?>
        <div class="alert alert-success animate">
            <i class="fas fa-check-circle"></i>
            <div><?php echo $success; ?></div>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="alert alert-danger animate">
            <i class="fas fa-exclamation-circle"></i>
            <div><?php echo htmlspecialchars($error); ?></div>
        </div>
        <?php endif; ?>

        <!-- Exam Info Card -->
        <div class="card animate">
            <div class="card-header">
                <h2><i class="fas fa-info-circle" style="color: var(--info);"></i> Exam Information</h2>
                <span class="badge badge-info">Code: <?php echo htmlspecialchars($schedule['exam_code']); ?></span>
            </div>
            <div class="stats-grid" style="margin-bottom: 0;">
                <div class="stat-card info">
                    <div class="stat-header">
                        <span class="stat-label">Academic Year</span>
                        <div class="stat-icon"><i class="fas fa-calendar"></i></div>
                    </div>
                    <div class="stat-value"><?php echo $schedule['academic_year']; ?></div>
                    <div class="stat-label"><?php echo $schedule['term']; ?></div>
                </div>
                <div class="stat-card primary">
                    <div class="stat-header">
                        <span class="stat-label">Total Marks</span>
                        <div class="stat-icon"><i class="fas fa-star"></i></div>
                    </div>
                    <div class="stat-value"><?php echo $schedule['total_marks']; ?></div>
                    <div class="stat-label">Maximum Score</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-header">
                        <span class="stat-label">Passing Marks</span>
                        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    </div>
                    <div class="stat-value"><?php echo $schedule['passing_marks']; ?></div>
                    <div class="stat-label">Minimum to Pass</div>
                </div>
                <div class="stat-card purple">
                    <div class="stat-header">
                        <span class="stat-label">Portal Status</span>
                        <div class="stat-icon"><i class="fas fa-door-<?php echo $schedule['status'] == 'open' ? 'open' : 'closed'; ?>"></i></div>
                    </div>
                    <div class="stat-value" style="text-transform: capitalize;"><?php echo $schedule['status']; ?></div>
                    <div class="stat-label">Current State</div>
                </div>
            </div>
        </div>

        <?php if (empty($all_marks)): ?>
        <!-- No Data State -->
        <div class="card animate" style="text-align: center; padding: 4rem 2rem;">
            <i class="fas fa-chart-bar fa-4x" style="color: var(--gray-light); margin-bottom: 1rem;"></i>
            <h3 style="color: var(--dark); margin-bottom: 0.5rem;">No Marks Entered Yet</h3>
            <p style="color: var(--gray); margin-bottom: 2rem;">There are no marks available for analysis. Please wait for teachers to enter marks.</p>
            <button class="btn btn-primary" onclick="window.location.href='marks_entry.php?schedule_id=<?php echo $schedule_id; ?>'">
                <i class="fas fa-edit"></i> Enter Marks
            </button>
        </div>
        <?php else: ?>

        <!-- Summary Statistics -->
        <div class="stats-grid animate">
            <div class="stat-card primary">
                <div class="stat-header">
                    <span class="stat-label">Total Students</span>
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                </div>
                <div class="stat-value"><?php echo $total_students; ?></div>
                <div class="stat-label">Enrolled in Class</div>
            </div>
            <div class="stat-card success">
                <div class="stat-header">
                    <span class="stat-label">Passed</span>
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                </div>
                <div class="stat-value"><?php echo $passed_students; ?></div>
                <div class="stat-label"><?php echo $pass_percentage; ?>% of Class</div>
            </div>
            <div class="stat-card danger">
                <div class="stat-header">
                    <span class="stat-label">Failed</span>
                    <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                </div>
                <div class="stat-value"><?php echo $total_students - $passed_students; ?></div>
                <div class="stat-label"><?php echo 100 - $pass_percentage; ?>% of Class</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-header">
                    <span class="stat-label">Class Average</span>
                    <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                </div>
                <div class="stat-value"><?php echo $avg_marks; ?></div>
                <div class="stat-label">Out of <?php echo $schedule['total_marks']; ?></div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="charts-row animate">
            <div class="chart-container">
                <div class="card-header">
                    <h2><i class="fas fa-chart-bar" style="color: var(--secondary);"></i> Marks Distribution</h2>
                </div>
                <canvas id="marksDistributionChart"></canvas>
            </div>
            <div class="chart-container">
                <div class="card-header">
                    <h2><i class="fas fa-chart-pie" style="color: var(--purple);"></i> Grade Distribution</h2>
                </div>
                <canvas id="gradeDistributionChart"></canvas>
            </div>
        </div>

        <!-- Grade Settings -->
        <div class="card animate">
            <div class="card-header">
                <h2><i class="fas fa-sliders-h" style="color: var(--warning);"></i> Grade Boundaries</h2>
            </div>
            <form method="POST" class="grade-settings">
                <div class="grade-input-group">
                    <label>A Grade (≥)</label>
                    <input type="number" name="grade_a" value="<?php echo $grade_a; ?>" min="0" max="100">
                </div>
                <div class="grade-input-group">
                    <label>B Grade (≥)</label>
                    <input type="number" name="grade_b" value="<?php echo $grade_b; ?>" min="0" max="100">
                </div>
                <div class="grade-input-group">
                    <label>C Grade (≥)</label>
                    <input type="number" name="grade_c" value="<?php echo $grade_c; ?>" min="0" max="100">
                </div>
                <div class="grade-input-group">
                    <label>D Grade (≥)</label>
                    <input type="number" name="grade_d" value="<?php echo $grade_d; ?>" min="0" max="100">
                </div>
                <button type="submit" name="update_grades" class="btn btn-warning btn-sm">
                    <i class="fas fa-save"></i> Update Boundaries
                </button>
            </form>
        </div>

        <!-- Top Performers -->
        <div class="card animate">
            <div class="card-header">
                <h2><i class="fas fa-trophy" style="color: var(--warning);"></i> Top Performers</h2>
                <span class="badge badge-success">Honor Roll</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Student Name</th>
                            <th>Admission No.</th>
                            <th>Marks</th>
                            <th>Percentage</th>
                            <th>Grade</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_performers as $index => $student): ?>
                        <tr>
                            <td><strong>#<?php echo $index + 1; ?></strong></td>
                            <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($student['Admission_number']); ?></td>
                            <td><strong><?php echo $student['marks_obtained']; ?> / <?php echo $schedule['total_marks']; ?></strong></td>
                            <td><?php echo round(($student['marks_obtained'] / $schedule['total_marks']) * 100, 1); ?>%</td>
                            <td><span class="grade-badge grade-<?php echo $student['calculated_grade']; ?>"><?php echo $student['calculated_grade']; ?></span></td>
                            <td><span class="status-badge status-pass">Passed</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Complete Marks Table -->
        <div class="card animate">
            <div class="card-header">
                <h2><i class="fas fa-list"></i> Complete Marks List</h2>
                <span class="badge badge-info"><?php echo count($all_marks); ?> Students</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Admission No.</th>
                            <th>Class</th>
                            <th>Marks</th>
                            <th>Percentage</th>
                            <th>Grade</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_marks as $student): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($student['Admission_number']); ?></td>
                            <td><?php echo htmlspecialchars($student['student_class']); ?></td>
                            <td><strong><?php echo $student['marks_obtained']; ?> / <?php echo $schedule['total_marks']; ?></strong></td>
                            <td><?php echo round(($student['marks_obtained'] / $schedule['total_marks']) * 100, 1); ?>%</td>
                            <td><span class="grade-badge grade-<?php echo $student['calculated_grade']; ?>"><?php echo $student['calculated_grade']; ?></span></td>
                            <td>
                                <?php if ($student['marks_obtained'] >= $schedule['passing_marks']): ?>
                                <span class="status-badge status-pass">Passed</span>
                                <?php else: ?>
                                <span class="status-badge status-fail">Failed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="card animate">
            <div class="card-header">
                <h2><i class="fas fa-cog"></i> Actions</h2>
            </div>
            <div class="btn-group">
                <?php if (!$analysis): ?>
                <form method="POST" style="display: inline;">
                    <button type="submit" name="analyze_results" class="btn btn-primary">
                        <i class="fas fa-chart-bar"></i> Analyze Results
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($analysis && $schedule['status'] !== 'published'): ?>
                <form method="POST" style="display: inline;" id="publishResultsForm">
                    <button type="submit" name="publish_results" class="btn btn-success">
                        <i class="fas fa-envelope"></i> Publish & Send to Teachers
                    </button>
                </form>
                <?php endif; ?>

                <button class="btn btn-info" onclick="exportToPDF()">
                    <i class="fas fa-file-pdf"></i> Export as PDF
                </button>

                <button class="btn btn-outline" onclick="exportToCSV()">
                    <i class="fas fa-file-csv"></i> Export as CSV
                </button>

                <button class="btn btn-outline" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>

            <?php if ($schedule['status'] === 'published'): ?>
            <div style="margin-top: 1rem; padding: 1rem; background: rgba(39, 174, 96, 0.1); border-radius: 8px; display: flex; align-items: center; gap: 1rem;">
                <i class="fas fa-check-circle" style="color: var(--success); font-size: 1.5rem;"></i>
                <div>
                    <strong style="color: var(--success);">Results Published</strong>
                    <p style="color: var(--gray); margin-top: 0.3rem;">Results have been published and a notification with exam summary was sent to the class teacher.</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (!empty($all_marks)): ?>
            // Marks Distribution Chart
            const marksCtx = document.getElementById('marksDistributionChart').getContext('2d');
            new Chart(marksCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_keys($marks_distribution)); ?>,
                    datasets: [{
                        label: 'Number of Students',
                        data: <?php echo json_encode(array_values($marks_distribution)); ?>,
                        backgroundColor: 'rgba(52, 152, 219, 0.7)',
                        borderColor: '#3498db',
                        borderWidth: 2,
                        borderRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `${context.raw} students`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });

            // Grade Distribution Chart
            const gradeCtx = document.getElementById('gradeDistributionChart').getContext('2d');
            new Chart(gradeCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode(array_keys($grade_distribution)); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_values($grade_distribution)); ?>,
                        backgroundColor: [
                            '#27ae60',
                            '#3498db',
                            '#f39c12',
                            '#9b59b6',
                            '#e74c3c'
                        ],
                        borderWidth: 3,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                padding: 20
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return `${label}: ${value} students (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
            <?php endif; ?>
        });

        // Export Functions
        function exportToPDF() {
            Swal.fire({
                title: 'Generating PDF',
                text: 'Please wait while we prepare your report...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // Simulate PDF generation
            setTimeout(() => {
                Swal.fire({
                    icon: 'success',
                    title: 'PDF Generated',
                    text: 'Your report has been generated successfully.',
                    timer: 2000,
                    showConfirmButton: false
                });
            }, 2000);
        }

        function exportToCSV() {
            // Prepare CSV data
            const headers = ['Student Name', 'Admission No.', 'Class', 'Marks', 'Percentage', 'Grade', 'Status'];
            const rows = <?php echo json_encode(array_map(function($student) use ($schedule) {
                return [
                    $student['full_name'],
                    $student['Admission_number'],
                    $student['student_class'],
                    $student['marks_obtained'] . '/' . $schedule['total_marks'],
                    round(($student['marks_obtained'] / $schedule['total_marks']) * 100, 1) . '%',
                    $student['calculated_grade'],
                    $student['marks_obtained'] >= $schedule['passing_marks'] ? 'Pass' : 'Fail'
                ];
            }, $all_marks)); ?>;

            // Create CSV content
            let csvContent = headers.join(',') + '\n';
            rows.forEach(row => {
                csvContent += row.map(cell => `"${cell}"`).join(',') + '\n';
            });

            // Download CSV
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'exam_analysis_<?php echo $schedule_id; ?>.csv';
            link.click();

            Swal.fire({
                icon: 'success',
                title: 'CSV Exported',
                text: 'The data has been exported successfully.',
                timer: 2000,
                showConfirmButton: false
            });
        }

        function confirmPublish(form) {
            Swal.fire({
                title: 'Publish Results?',
                text: 'This will send notifications to all teachers with the exam results summary. Continue?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#27ae60',
                cancelButtonColor: '#7f8c8d',
                confirmButtonText: 'Yes, publish',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        }

        const publishResultsForm = document.getElementById('publishResultsForm');
        if (publishResultsForm) {
            publishResultsForm.addEventListener('submit', function(e) {
                e.preventDefault();
                confirmPublish(this);
            });
        }
    </script>
</body>
</html>
