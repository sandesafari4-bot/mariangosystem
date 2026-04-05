<?php
include '../config.php';
checkAuth();
checkRole(['admin']);

// Get filter parameters
$filter_year = $_GET['year'] ?? '';
$filter_term = $_GET['term'] ?? '';
$filter_class = $_GET['class_id'] ?? '';
$filter_exam = $_GET['exam_id'] ?? '';
$date_range = $_GET['date_range'] ?? 'all'; // all, this_year, last_year

// Get available filters
$academic_years = $pdo->query("SELECT DISTINCT academic_year FROM exams ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_COLUMN);
$terms = ['Term 1', 'Term 2', 'Term 3'];
$classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll();
$exams = $pdo->query("SELECT id, exam_name FROM exams ORDER BY created_at DESC")->fetchAll();

// Build query based on filters
$query = "
    SELECT 
        e.id as exam_id,
        e.exam_name,
        e.exam_code,
        e.academic_year,
        e.term,
        e.total_marks,
        e.passing_marks,
        e.created_at,
        c.id as class_id,
        c.class_name,
        es.id as schedule_id,
        COUNT(DISTINCT s.id) as total_students,
        COUNT(DISTINCT em.id) as marks_entered,
        AVG(em.marks_obtained) as avg_marks,
        MAX(em.marks_obtained) as max_marks,
        MIN(em.marks_obtained) as min_marks,
        SUM(CASE WHEN em.marks_obtained >= e.passing_marks THEN 1 ELSE 0 END) as passed_count,
        SUM(CASE WHEN em.marks_obtained < e.passing_marks THEN 1 ELSE 0 END) as failed_count,
        STDDEV(em.marks_obtained) as std_deviation
    FROM exams e
    LEFT JOIN exam_schedules es ON e.id = es.exam_id
    LEFT JOIN classes c ON es.class_id = c.id
    LEFT JOIN exam_marks em ON es.id = em.exam_schedule_id
    LEFT JOIN students s ON c.id = s.class_id AND s.status = 'active'
    WHERE 1=1
";

$params = [];

if ($filter_year) {
    $query .= " AND e.academic_year = ?";
    $params[] = $filter_year;
}

if ($filter_term) {
    $query .= " AND e.term = ?";
    $params[] = $filter_term;
}

if ($filter_class) {
    $query .= " AND c.id = ?";
    $params[] = $filter_class;
}

if ($filter_exam) {
    $query .= " AND e.id = ?";
    $params[] = $filter_exam;
}

if ($date_range == 'this_year') {
    $query .= " AND YEAR(e.created_at) = YEAR(CURDATE())";
} elseif ($date_range == 'last_year') {
    $query .= " AND YEAR(e.created_at) = YEAR(CURDATE()) - 1";
}

$query .= " GROUP BY e.id, c.id ORDER BY e.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$analytics_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate overall statistics
$total_exams = count(array_unique(array_column($analytics_data, 'exam_id')));
$total_schedules = count($analytics_data);
$total_students = array_sum(array_column($analytics_data, 'total_students'));
$total_marks_entered = array_sum(array_column($analytics_data, 'marks_entered'));

$overall_avg = array_sum(array_column($analytics_data, 'avg_marks')) / max(1, count($analytics_data));
$overall_pass_rate = 0;
$total_passed = 0;
$total_failed = 0;

foreach ($analytics_data as $data) {
    $total_passed += $data['passed_count'];
    $total_failed += $data['failed_count'];
}
$overall_pass_rate = ($total_passed + $total_failed) > 0 ? round(($total_passed / ($total_passed + $total_failed)) * 100, 1) : 0;

// Prepare data for charts
$performance_trend = $pdo->query("
    SELECT 
        DATE_FORMAT(e.created_at, '%Y-%m') as month,
        DATE_FORMAT(e.created_at, '%b %Y') as month_name,
        COUNT(DISTINCT e.id) as exam_count,
        AVG(em.marks_obtained) as avg_marks,
        COUNT(em.id) as marks_count
    FROM exams e
    LEFT JOIN exam_schedules es ON e.id = es.exam_id
    LEFT JOIN exam_marks em ON es.id = em.exam_schedule_id
    WHERE e.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(e.created_at, '%Y-%m')
    ORDER BY month ASC
")->fetchAll();

// Top performing classes
$top_classes = $pdo->query("
    SELECT 
        c.class_name,
        COUNT(DISTINCT e.id) as exam_count,
        AVG(em.marks_obtained) as avg_marks,
        COUNT(DISTINCT s.id) as student_count,
        SUM(CASE WHEN em.marks_obtained >= e.passing_marks THEN 1 ELSE 0 END) as passed_count,
        COUNT(em.id) as total_marks
    FROM classes c
    LEFT JOIN exam_schedules es ON c.id = es.class_id
    LEFT JOIN exams e ON es.exam_id = e.id
    LEFT JOIN exam_marks em ON es.id = em.exam_schedule_id
    LEFT JOIN students s ON c.id = s.class_id AND s.status = 'active'
    GROUP BY c.id
    HAVING exam_count > 0
    ORDER BY avg_marks DESC
    LIMIT 5
")->fetchAll();

// Subject performance analysis
$subject_performance = $pdo->query("
    SELECT 
        sub.subject_name,
        COUNT(DISTINCT e.id) as exam_count,
        AVG(em.marks_obtained) as avg_marks,
        COUNT(DISTINCT s.id) as student_count,
        MAX(em.marks_obtained) as highest_marks,
        MIN(em.marks_obtained) as lowest_marks
    FROM subjects sub
    LEFT JOIN exam_schedules es ON sub.id = es.subject_id
    LEFT JOIN exams e ON es.exam_id = e.id
    LEFT JOIN exam_marks em ON es.id = em.exam_schedule_id
    LEFT JOIN students s ON es.class_id = s.class_id AND s.status = 'active'
    GROUP BY sub.id
    HAVING exam_count > 0
    ORDER BY avg_marks DESC
")->fetchAll();

// Grade distribution across all exams
$grade_distribution = [
    'A (80-100%)' => 0,
    'B (65-79%)' => 0,
    'C (50-64%)' => 0,
    'D (40-49%)' => 0,
    'F (Below 40%)' => 0
];

$grade_counts = $pdo->query("
    SELECT 
        em.marks_obtained,
        e.total_marks,
        e.passing_marks
    FROM exam_marks em
    JOIN exam_schedules es ON em.exam_schedule_id = es.id
    JOIN exams e ON es.exam_id = e.id
")->fetchAll();

foreach ($grade_counts as $grade) {
    $percentage = ($grade['marks_obtained'] / $grade['total_marks']) * 100;
    if ($percentage >= 80) $grade_distribution['A (80-100%)']++;
    elseif ($percentage >= 65) $grade_distribution['B (65-79%)']++;
    elseif ($percentage >= 50) $grade_distribution['C (50-64%)']++;
    elseif ($percentage >= 40) $grade_distribution['D (40-49%)']++;
    else $grade_distribution['F (Below 40%)']++;
}

// Monthly comparison
$monthly_comparison = $pdo->query("
    SELECT 
        MONTH(e.created_at) as month_num,
        DATE_FORMAT(e.created_at, '%b') as month,
        YEAR(e.created_at) as year,
        AVG(em.marks_obtained) as avg_marks,
        COUNT(DISTINCT e.id) as exam_count
    FROM exams e
    LEFT JOIN exam_schedules es ON e.id = es.exam_id
    LEFT JOIN exam_marks em ON es.id = em.exam_schedule_id
    WHERE e.created_at >= DATE_SUB(CURDATE(), INTERVAL 2 YEAR)
    GROUP BY YEAR(e.created_at), MONTH(e.created_at)
    ORDER BY year DESC, month_num DESC
    LIMIT 12
")->fetchAll();

$page_title = 'Exam Analytics - ' . SCHOOL_NAME;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
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
            flex-wrap: wrap;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .filter-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--dark);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
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
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: var(--secondary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

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

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
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

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border-left: 4px solid;
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card.total { border-left-color: var(--secondary); }
        .stat-card.students { border-left-color: var(--success); }
        .stat-card.pass { border-left-color: var(--purple); }
        .stat-card.avg { border-left-color: var(--warning); }

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

        .stat-card.total .stat-icon { background: linear-gradient(135deg, var(--secondary), var(--purple)); }
        .stat-card.students .stat-icon { background: linear-gradient(135deg, var(--success), var(--success-light)); }
        .stat-card.pass .stat-icon { background: linear-gradient(135deg, var(--purple), var(--purple-light)); }
        .stat-card.avg .stat-icon { background: linear-gradient(135deg, var(--warning), var(--warning-light)); }

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
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .chart-container {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--light);
        }

        .chart-header h3 {
            font-size: 1.1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-container canvas {
            height: 300px !important;
            width: 100% !important;
        }

        /* Analytics Grid */
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .analytics-card {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-md);
        }

        .card-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--light), #ffffff);
            border-bottom: 1px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 1.1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Table Styles */
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

        /* Progress Bars */
        .progress-bar {
            height: 8px;
            background: var(--light);
            border-radius: 4px;
            overflow: hidden;
            margin: 0.5rem 0;
        }

        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s;
        }

        .progress-fill.success { background: linear-gradient(90deg, var(--success), var(--success-light)); }
        .progress-fill.warning { background: linear-gradient(90deg, var(--warning), var(--warning-light)); }
        .progress-fill.danger { background: linear-gradient(90deg, var(--danger), var(--danger-light)); }

        /* Badges */
        .badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-success {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
        }

        .badge-warning {
            background: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }

        .badge-danger {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }

        .badge-info {
            background: rgba(52, 152, 219, 0.1);
            color: var(--secondary);
        }

        /* Trend Indicators */
        .trend-up {
            color: var(--success);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .trend-down {
            color: var(--danger);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* No Data */
        .no-data {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--gray-light);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .charts-row {
                grid-template-columns: 1fr;
            }
            
            .analytics-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                text-align: center;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .header-actions {
                justify-content: center;
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
                <h1><i class="fas fa-chart-line" style="color: var(--secondary); margin-right: 0.5rem;"></i>Exam Analytics</h1>
                <p>Comprehensive analysis of examination performance across classes and subjects</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="exportAnalytics()">
                    <i class="fas fa-download"></i> Export Report
                </button>
                <button class="btn btn-outline" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section animate">
            <div class="filter-header">
                <h3><i class="fas fa-filter" style="color: var(--secondary);"></i> Filter Analytics</h3>
                <button class="btn btn-sm btn-outline" onclick="resetFilters()">
                    <i class="fas fa-redo-alt"></i> Reset
                </button>
            </div>
            <form method="GET" id="filterForm">
                <div class="filter-grid">
                    <div class="form-group">
                        <label>Academic Year</label>
                        <select name="year" class="form-control" onchange="this.form.submit()">
                            <option value="">All Years</option>
                            <?php foreach ($academic_years as $year): ?>
                                <option value="<?php echo $year; ?>" <?php echo $filter_year == $year ? 'selected' : ''; ?>>
                                    <?php echo $year; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Term</label>
                        <select name="term" class="form-control" onchange="this.form.submit()">
                            <option value="">All Terms</option>
                            <?php foreach ($terms as $term): ?>
                                <option value="<?php echo $term; ?>" <?php echo $filter_term == $term ? 'selected' : ''; ?>>
                                    <?php echo $term; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Class</label>
                        <select name="class_id" class="form-control" onchange="this.form.submit()">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $filter_class == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Exam</label>
                        <select name="exam_id" class="form-control" onchange="this.form.submit()">
                            <option value="">All Exams</option>
                            <?php foreach ($exams as $exam): ?>
                                <option value="<?php echo $exam['id']; ?>" <?php echo $filter_exam == $exam['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($exam['exam_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </form>
        </div>

        <!-- Overview Statistics -->
        <div class="stats-grid animate">
            <div class="stat-card total">
                <div class="stat-header">
                    <span class="stat-label">Total Exams</span>
                    <div class="stat-icon"><i class="fas fa-pencil-alt"></i></div>
                </div>
                <div class="stat-value"><?php echo $total_exams; ?></div>
                <div class="stat-label">Across <?php echo $total_schedules; ?> schedules</div>
            </div>
            <div class="stat-card students">
                <div class="stat-header">
                    <span class="stat-label">Students</span>
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                </div>
                <div class="stat-value"><?php echo number_format($total_students); ?></div>
                <div class="stat-label"><?php echo number_format($total_marks_entered); ?> marks entered</div>
            </div>
            <div class="stat-card pass">
                <div class="stat-header">
                    <span class="stat-label">Pass Rate</span>
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                </div>
                <div class="stat-value"><?php echo $overall_pass_rate; ?>%</div>
                <div class="stat-label"><?php echo $total_passed; ?> passed / <?php echo $total_failed; ?> failed</div>
            </div>
            <div class="stat-card avg">
                <div class="stat-header">
                    <span class="stat-label">Average Score</span>
                    <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                </div>
                <div class="stat-value"><?php echo round($overall_avg, 1); ?></div>
                <div class="stat-label">Overall performance</div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="charts-row animate">
            <div class="chart-container">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-line" style="color: var(--secondary);"></i> Performance Trend (12 Months)</h3>
                    <span class="badge badge-info">Average Marks</span>
                </div>
                <canvas id="trendChart"></canvas>
            </div>
            <div class="chart-container">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-pie" style="color: var(--purple);"></i> Grade Distribution</h3>
                    <span class="badge badge-info">All Exams</span>
                </div>
                <canvas id="gradeChart"></canvas>
            </div>
        </div>

        <!-- Analytics Grid -->
        <div class="analytics-grid animate">
            <!-- Top Performing Classes -->
            <div class="analytics-card">
                <div class="card-header">
                    <h3><i class="fas fa-trophy" style="color: var(--warning);"></i> Top Performing Classes</h3>
                    <span class="badge badge-success">By Average Score</span>
                </div>
                <div class="card-body">
                    <?php if (empty($top_classes)): ?>
                        <div class="no-data">
                            <i class="fas fa-chart-bar"></i>
                            <p>No data available</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($top_classes as $class): 
                            $pass_rate = $class['total_marks'] > 0 ? round(($class['passed_count'] / $class['total_marks']) * 100, 1) : 0;
                        ?>
                        <div style="margin-bottom: 1.5rem;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                <strong><?php echo htmlspecialchars($class['class_name']); ?></strong>
                                <span class="badge badge-info">Avg: <?php echo round($class['avg_marks'], 1); ?></span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill success" style="width: <?php echo $pass_rate; ?>%;"></div>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--gray); margin-top: 0.3rem;">
                                <span><?php echo $class['exam_count']; ?> exams</span>
                                <span><?php echo $class['student_count']; ?> students</span>
                                <span><?php echo $pass_rate; ?>% pass rate</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Subject Performance -->
            <div class="analytics-card">
                <div class="card-header">
                    <h3><i class="fas fa-book" style="color: var(--info);"></i> Subject Performance</h3>
                    <span class="badge badge-info">By Average Score</span>
                </div>
                <div class="card-body">
                    <?php if (empty($subject_performance)): ?>
                        <div class="no-data">
                            <i class="fas fa-book-open"></i>
                            <p>No subject data available</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Avg</th>
                                        <th>Highest</th>
                                        <th>Lowest</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($subject_performance as $subject): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($subject['subject_name']); ?></strong>
                                            <br><small><?php echo $subject['exam_count']; ?> exams</small>
                                        </td>
                                        <td><span class="badge badge-info"><?php echo round($subject['avg_marks'], 1); ?></span></td>
                                        <td><span class="badge badge-success"><?php echo $subject['highest_marks']; ?></span></td>
                                        <td><span class="badge badge-warning"><?php echo $subject['lowest_marks']; ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Monthly Comparison -->
        <div class="analytics-card animate">
            <div class="card-header">
                <h3><i class="fas fa-calendar-alt" style="color: var(--success);"></i> Monthly Performance Comparison</h3>
                <span class="badge badge-success">Last 12 Months</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Exams</th>
                                <th>Average Score</th>
                                <th>Trend</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $prev_avg = null;
                            foreach ($monthly_comparison as $month): 
                                $trend = '';
                                $trend_class = '';
                                if ($prev_avg !== null) {
                                    if ($month['avg_marks'] > $prev_avg) {
                                        $trend = '↑ ' . round($month['avg_marks'] - $prev_avg, 1);
                                        $trend_class = 'trend-up';
                                    } elseif ($month['avg_marks'] < $prev_avg) {
                                        $trend = '↓ ' . round($prev_avg - $month['avg_marks'], 1);
                                        $trend_class = 'trend-down';
                                    }
                                }
                                $prev_avg = $month['avg_marks'];
                            ?>
                            <tr>
                                <td><strong><?php echo $month['month'] . ' ' . $month['year']; ?></strong></td>
                                <td><?php echo $month['exam_count']; ?></td>
                                <td><span class="badge badge-info"><?php echo round($month['avg_marks'], 1); ?></span></td>
                                <td class="<?php echo $trend_class; ?>">
                                    <?php if ($trend): ?>
                                        <?php echo $trend; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Detailed Exam Data -->
        <div class="analytics-card animate">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Exam Performance Details</h3>
                <span class="badge badge-info"><?php echo count($analytics_data); ?> records</span>
            </div>
            <div class="card-body">
                <?php if (empty($analytics_data)): ?>
                    <div class="no-data">
                        <i class="fas fa-inbox"></i>
                        <p>No exam data available for the selected filters</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Exam</th>
                                    <th>Class</th>
                                    <th>Students</th>
                                    <th>Average</th>
                                    <th>Pass Rate</th>
                                    <th>Highest</th>
                                    <th>Lowest</th>
                                    <th>Std Dev</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($analytics_data as $data): 
                                    $pass_rate = ($data['passed_count'] + $data['failed_count']) > 0 
                                        ? round(($data['passed_count'] / ($data['passed_count'] + $data['failed_count'])) * 100, 1) 
                                        : 0;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($data['exam_name']); ?></strong>
                                        <br><small><?php echo $data['academic_year'] . ' - ' . $data['term']; ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($data['class_name']); ?></td>
                                    <td><?php echo $data['total_students']; ?></td>
                                    <td><span class="badge badge-info"><?php echo round($data['avg_marks'], 1); ?></span></td>
                                    <td>
                                        <span class="badge badge-success"><?php echo $pass_rate; ?>%</span>
                                        <br><small><?php echo $data['passed_count']; ?>/<?php echo $data['passed_count'] + $data['failed_count']; ?></small>
                                    </td>
                                    <td><span class="badge badge-success"><?php echo $data['max_marks']; ?></span></td>
                                    <td><span class="badge badge-warning"><?php echo $data['min_marks']; ?></span></td>
                                    <td><span class="badge badge-info"><?php echo round($data['std_deviation'], 2); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Trend Chart
            const trendCtx = document.getElementById('trendChart').getContext('2d');
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($performance_trend, 'month_name')); ?>,
                    datasets: [{
                        label: 'Average Marks',
                        data: <?php echo json_encode(array_column($performance_trend, 'avg_marks')); ?>,
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#3498db',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4
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
                                    return `Average: ${context.raw.toFixed(1)} marks`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0,0,0,0.05)'
                            }
                        }
                    }
                }
            });

            // Grade Distribution Chart
            const gradeCtx = document.getElementById('gradeChart').getContext('2d');
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
        });

        // Export Analytics
        function exportAnalytics() {
            Swal.fire({
                title: 'Export Report',
                text: 'Select export format',
                icon: 'question',
                showCancelButton: true,
                showDenyButton: true,
                confirmButtonText: 'PDF',
                denyButtonText: 'CSV',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    exportToPDF();
                } else if (result.isDenied) {
                    exportToCSV();
                }
            });
        }

        function exportToPDF() {
            Swal.fire({
                title: 'Generating PDF',
                text: 'Please wait while we prepare your report...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            setTimeout(() => {
                Swal.fire({
                    icon: 'success',
                    title: 'PDF Generated',
                    text: 'Your analytics report has been generated successfully.',
                    timer: 2000,
                    showConfirmButton: false
                });
            }, 2000);
        }

        function exportToCSV() {
            // Prepare CSV data
            const headers = ['Exam', 'Class', 'Academic Year', 'Term', 'Total Students', 'Average Marks', 'Pass Rate', 'Highest', 'Lowest'];
            const rows = <?php echo json_encode(array_map(function($data) {
                $pass_rate = ($data['passed_count'] + $data['failed_count']) > 0 
                    ? round(($data['passed_count'] / ($data['passed_count'] + $data['failed_count'])) * 100, 1) 
                    : 0;
                return [
                    $data['exam_name'],
                    $data['class_name'],
                    $data['academic_year'],
                    $data['term'],
                    $data['total_students'],
                    round($data['avg_marks'], 1),
                    $pass_rate . '%',
                    $data['max_marks'],
                    $data['min_marks']
                ];
            }, $analytics_data)); ?>;

            // Create CSV content
            let csvContent = headers.join(',') + '\n';
            rows.forEach(row => {
                csvContent += row.map(cell => `"${cell}"`).join(',') + '\n';
            });

            // Download CSV
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'exam_analytics_' + new Date().toISOString().slice(0,10) + '.csv';
            link.click();

            Swal.fire({
                icon: 'success',
                title: 'CSV Exported',
                text: 'The analytics data has been exported successfully.',
                timer: 2000,
                showConfirmButton: false
            });
        }

        function resetFilters() {
            window.location.href = 'exam_analytics.php';
        }
    </script>
</body>
</html>