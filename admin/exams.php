<?php
include '../config.php';
checkAuth();
checkRole(['admin']);

// Handle exam deletion
if (isset($_POST['delete_exam'])) {
    $exam_id = $_POST['exam_id'];
    
    try {
        $pdo->beginTransaction();
        
        // Check if exam has schedules
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM exam_schedules WHERE exam_id = ?");
        $check_stmt->execute([$exam_id]);
        $schedule_count = $check_stmt->fetchColumn();
        
        if ($schedule_count > 0) {
            // First delete all marks associated with schedules
            $delete_marks = $pdo->prepare("
                DELETE em FROM exam_marks em 
                INNER JOIN exam_schedules es ON em.exam_schedule_id = es.id 
                WHERE es.exam_id = ?
            ");
            $delete_marks->execute([$exam_id]);
            
            // Then delete schedules
            $delete_schedules = $pdo->prepare("DELETE FROM exam_schedules WHERE exam_id = ?");
            $delete_schedules->execute([$exam_id]);
        }
        
        // Finally delete the exam
        $delete_exam = $pdo->prepare("DELETE FROM exams WHERE id = ?");
        $delete_exam->execute([$exam_id]);
        
        $pdo->commit();
        
        $_SESSION['success_message'] = "Exam deleted successfully!";
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Error deleting exam: " . $e->getMessage();
        error_log("Exam deletion error: " . $e->getMessage());
    }
    
    header("Location: exams.php");
    exit();
}

// Handle publish exam
if (isset($_POST['publish_exam'])) {
    $exam_id = $_POST['exam_id'];
    
    try {
        $stmt = $pdo->prepare("UPDATE exams SET status = 'published' WHERE id = ?");
        $stmt->execute([$exam_id]);
        
        $_SESSION['success_message'] = "Exam published successfully!";
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error publishing exam: " . $e->getMessage();
    }
    
    header("Location: exams.php");
    exit();
}

// Handle unpublish exam
if (isset($_POST['unpublish_exam'])) {
    $exam_id = $_POST['exam_id'];
    
    try {
        $stmt = $pdo->prepare("UPDATE exams SET status = 'draft' WHERE id = ?");
        $stmt->execute([$exam_id]);
        
        $_SESSION['success_message'] = "Exam unpublished successfully!";
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error unpublishing exam: " . $e->getMessage();
    }
    
    header("Location: exams.php");
    exit();
}

// Get all exams with statistics
$stmt = $pdo->prepare("
    SELECT 
        e.*,
        u.full_name as created_by_name,
        COUNT(DISTINCT es.id) as schedule_count,
        COUNT(DISTINCT em.id) as total_marks_entered,
        AVG(em.marks_obtained) as average_marks,
        MAX(em.marks_obtained) as highest_marks,
        MIN(em.marks_obtained) as lowest_marks
    FROM exams e
    LEFT JOIN exam_schedules es ON e.id = es.exam_id
    LEFT JOIN exam_marks em ON es.id = em.exam_schedule_id
    LEFT JOIN users u ON e.created_by = u.id
    GROUP BY e.id
    ORDER BY e.created_at DESC
");
$stmt->execute();
$exams_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate open/closed/scheduled counts with hybrid logic for each exam
$exams = [];
foreach ($exams_raw as $exam) {
    // Get schedules for this exam
    $sched_stmt = $pdo->prepare("SELECT * FROM exam_schedules WHERE exam_id = ?");
    $sched_stmt->execute([$exam['id']]);
    $exam_schedules = $sched_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $open_count = 0;
    $closed_count = 0;
    $now = new DateTime();
    
    foreach ($exam_schedules as $sched) {
        $open_date = new DateTime($sched['portal_open_date']);
        $close_date = new DateTime($sched['portal_close_date']);
        
        // Use hybrid logic: status column takes precedence if explicitly set
        $db_status = strtolower(trim($sched['status'] ?? ''));
        if (in_array($db_status, ['open', 'closed'])) {
            if ($db_status === 'open') {
                $open_count++;
            } elseif ($db_status === 'closed') {
                $closed_count++;
            }
        } else {
            // Fall back to date-based calculation
            if ($now > $close_date) {
                $closed_count++;
            } elseif ($now >= $open_date && $now <= $close_date) {
                $open_count++;
            }
        }
    }
    
    $exam['open_schedules'] = $open_count;
    $exam['closed_schedules'] = $closed_count;
    $exam['scheduled_schedules'] = $exam['schedule_count'] - $open_count - $closed_count;
    $exams[] = $exam;
}

// Get filter options
$academic_years = $pdo->query("SELECT DISTINCT academic_year FROM exams ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_COLUMN);
$terms = ['Term 1', 'Term 2', 'Term 3'];

// Filter logic
$filter_year = $_GET['year'] ?? '';
$filter_term = $_GET['term'] ?? '';
$filter_status = $_GET['status'] ?? '';

if ($filter_year || $filter_term || $filter_status) {
    $filtered_exams = array_filter($exams, function($exam) use ($filter_year, $filter_term, $filter_status) {
        $match = true;
        if ($filter_year && $exam['academic_year'] != $filter_year) $match = false;
        if ($filter_term && $exam['term'] != $filter_term) $match = false;
        if ($filter_status) {
            if ($filter_status == 'active' && $exam['open_schedules'] == 0) $match = false;
            if ($filter_status == 'completed' && $exam['closed_schedules'] == 0) $match = false;
            if ($filter_status == 'draft' && $exam['status'] != 'draft') $match = false;
            if ($filter_status == 'published' && $exam['status'] != 'published') $match = false;
        }
        return $match;
    });
    $exams = $filtered_exams;
}

// Calculate overall statistics
$total_exams = count($exams);
$total_schedules = array_sum(array_column($exams, 'schedule_count'));
$total_marks = array_sum(array_column($exams, 'total_marks_entered'));
$active_exams = count(array_filter($exams, fn($e) => $e['open_schedules'] > 0));
$published_exams = count(array_filter($exams, fn($e) => $e['status'] == 'published'));

$page_title = 'Exam Management - ' . SCHOOL_NAME;
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

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header-content h1 {
            font-size: 2.2rem;
            font-weight: 700;
            background: var(--gradient-1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .header-content p {
            color: var(--gray);
            font-size: 1rem;
        }

        .header-actions {
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

        .btn-danger {
            background: var(--gradient-2);
            color: white;
            box-shadow: 0 4px 15px rgba(240, 147, 251, 0.4);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(240, 147, 251, 0.5);
        }

        .btn-info {
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            box-shadow: 0 4px 15px rgba(23, 162, 184, 0.4);
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(23, 162, 184, 0.5);
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

        /* Statistics Cards */
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
            transition: var(--transition);
            border-left: 4px solid;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card.total { border-left-color: var(--primary); }
        .stat-card.active { border-left-color: var(--success); }
        .stat-card.published { border-left-color: var(--info); }
        .stat-card.schedules { border-left-color: var(--warning); }
        .stat-card.marks { border-left-color: var(--purple); }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .stat-card.total .stat-icon { background: var(--gradient-1); }
        .stat-card.active .stat-icon { background: var(--gradient-3); }
        .stat-card.published .stat-icon { background: linear-gradient(135deg, #17a2b8, #138496); }
        .stat-card.schedules .stat-icon { background: var(--gradient-5); }
        .stat-card.marks .stat-icon { background: linear-gradient(135deg, #7209b7, #9b59b6); }

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

        /* Filter Section */
        .filter-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(255,255,255,0.3);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .filter-header h3 {
            font-size: 1.1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
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
            border-radius: var(--border-radius-md);
            font-size: 0.95rem;
            transition: var(--transition);
            font-family: 'Inter', sans-serif;
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        /* Exams Grid */
        .exams-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 1.5rem;
        }

        .exam-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            border: 1px solid rgba(255,255,255,0.3);
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .exam-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .exam-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--light), #ffffff);
            border-bottom: 2px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .exam-title h3 {
            font-size: 1.2rem;
            color: var(--dark);
            margin-bottom: 0.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .exam-code {
            font-size: 0.8rem;
            padding: 0.2rem 0.5rem;
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
            border-radius: 4px;
            font-family: monospace;
        }

        .exam-meta {
            display: flex;
            gap: 1rem;
            color: var(--gray);
            font-size: 0.9rem;
        }

        .exam-meta i {
            color: var(--primary);
        }

        .status-badge {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .status-draft {
            background: rgba(108, 117, 125, 0.1);
            color: var(--gray);
        }

        .status-published {
            background: rgba(23, 162, 184, 0.1);
            color: #17a2b8;
        }

        .status-active {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .status-completed {
            background: rgba(102, 126, 234, 0.1);
            color: var(--primary);
        }

        .exam-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.5rem;
            padding: 1.5rem;
            background: var(--white);
            border-bottom: 1px solid var(--light);
        }

        .exam-stat {
            text-align: center;
        }

        .exam-stat-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.2rem;
        }

        .exam-stat-label {
            font-size: 0.7rem;
            color: var(--gray);
            text-transform: uppercase;
        }

        .exam-details {
            padding: 1.5rem;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            background: var(--light);
        }

        .exam-detail-item {
            display: flex;
            flex-direction: column;
        }

        .exam-detail-label {
            font-size: 0.8rem;
            color: var(--gray);
            margin-bottom: 0.3rem;
        }

        .exam-detail-value {
            font-weight: 600;
            color: var(--dark);
        }

        .exam-actions {
            padding: 1.5rem;
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
            border-top: 1px solid var(--light);
        }

        /* Progress Bar */
        .progress-bar {
            height: 6px;
            background: var(--light);
            border-radius: 3px;
            overflow: hidden;
            margin: 0.5rem 0;
        }

        .progress-fill {
            height: 100%;
            background: var(--gradient-1);
            border-radius: 3px;
            transition: width 0.3s;
        }

        /* No Data */
        .no-data {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            border: 2px dashed var(--light);
            grid-column: 1 / -1;
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

        /* Animations */
        .animate-fade-up {
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

        .stagger-item {
            opacity: 0;
            animation: fadeInUp 0.5s ease-out forwards;
        }

        .stagger-item:nth-child(1) { animation-delay: 0.1s; }
        .stagger-item:nth-child(2) { animation-delay: 0.2s; }
        .stagger-item:nth-child(3) { animation-delay: 0.3s; }
        .stagger-item:nth-child(4) { animation-delay: 0.4s; }
        .stagger-item:nth-child(5) { animation-delay: 0.5s; }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .exams-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .page-header {
                padding: 1.5rem;
            }
            
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .exam-header {
                flex-direction: column;
            }
            
            .exam-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .exam-details {
                grid-template-columns: 1fr;
            }
            
            .exam-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
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
        <div class="page-header animate-fade-up">
            <div class="header-content">
                <div>
                    <h1><i class="fas fa-pencil-alt" style="margin-right: 0.5rem;"></i>Exam Management</h1>
                    <p>Create and manage exams, set schedules, and track results</p>
                </div>
                <div class="header-actions">
                    <a href="create_exam.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Exam
                    </a>
                    <a href="exam_analytics.php" class="btn btn-outline">
                        <i class="fas fa-chart-bar"></i> Analytics
                    </a>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid animate-fade-up">
            <div class="stat-card total stagger-item">
                <div class="stat-header">
                    <span class="stat-label">Total Exams</span>
                    <div class="stat-icon"><i class="fas fa-pencil-alt"></i></div>
                </div>
                <div class="stat-value"><?php echo $total_exams; ?></div>
                <div class="stat-label">Created</div>
            </div>
            <div class="stat-card published stagger-item">
                <div class="stat-header">
                    <span class="stat-label">Published</span>
                    <div class="stat-icon"><i class="fas fa-globe"></i></div>
                </div>
                <div class="stat-value"><?php echo $published_exams; ?></div>
                <div class="stat-label">Visible</div>
            </div>
            <div class="stat-card active stagger-item">
                <div class="stat-header">
                    <span class="stat-label">Active</span>
                    <div class="stat-icon"><i class="fas fa-door-open"></i></div>
                </div>
                <div class="stat-value"><?php echo $active_exams; ?></div>
                <div class="stat-label">Open Portals</div>
            </div>
            <div class="stat-card schedules stagger-item">
                <div class="stat-header">
                    <span class="stat-label">Schedules</span>
                    <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
                </div>
                <div class="stat-value"><?php echo $total_schedules; ?></div>
                <div class="stat-label">Created</div>
            </div>
            <div class="stat-card marks stagger-item">
                <div class="stat-header">
                    <span class="stat-label">Marks Entered</span>
                    <div class="stat-icon"><i class="fas fa-pencil-alt"></i></div>
                </div>
                <div class="stat-value"><?php echo $total_marks; ?></div>
                <div class="stat-label">Entries</div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section animate-fade-up">
            <div class="filter-header">
                <h3><i class="fas fa-filter" style="color: var(--primary);"></i> Filter Exams</h3>
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
                        <label>Status</label>
                        <select name="status" class="form-control" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="draft" <?php echo $filter_status == 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="published" <?php echo $filter_status == 'published' ? 'selected' : ''; ?>>Published</option>
                            <option value="active" <?php echo $filter_status == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="completed" <?php echo $filter_status == 'completed' ? 'selected' : ''; ?>>Completed</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>

        <!-- Exams Grid -->
        <div class="exams-grid">
            <?php if (empty($exams)): ?>
                <div class="no-data animate-fade-up">
                    <i class="fas fa-inbox"></i>
                    <h3>No Exams Found</h3>
                    <p>Get started by creating your first exam.</p>
                    <a href="create_exam.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Exam
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($exams as $exam): 
                    $completion_rate = $exam['schedule_count'] > 0 
                        ? round(($exam['closed_schedules'] / $exam['schedule_count']) * 100, 1) 
                        : 0;
                    
                    // Determine status color
                    if ($exam['open_schedules'] > 0) {
                        $status_class = 'active';
                        $status_text = 'Active';
                    } elseif ($exam['status'] == 'published') {
                        $status_class = 'published';
                        $status_text = 'Published';
                    } elseif ($exam['closed_schedules'] > 0) {
                        $status_class = 'completed';
                        $status_text = 'Completed';
                    } else {
                        $status_class = 'draft';
                        $status_text = 'Draft';
                    }
                ?>
                <div class="exam-card animate-fade-up" id="exam-<?php echo $exam['id']; ?>">
                    <div class="exam-header">
                        <div class="exam-title">
                            <h3>
                                <?php echo htmlspecialchars($exam['exam_name']); ?>
                                <span class="exam-code"><?php echo htmlspecialchars($exam['exam_code']); ?></span>
                            </h3>
                            <div class="exam-meta">
                                <span><i class="fas fa-calendar"></i> <?php echo $exam['academic_year']; ?></span>
                                <span><i class="fas fa-tag"></i> <?php echo $exam['term']; ?></span>
                            </div>
                        </div>
                        <span class="status-badge status-<?php echo $status_class; ?>">
                            <i class="fas fa-<?php 
                                echo $status_class == 'active' ? 'door-open' : 
                                    ($status_class == 'published' ? 'globe' : 
                                    ($status_class == 'completed' ? 'check-circle' : 'clock')); 
                            ?>"></i>
                            <?php echo $status_text; ?>
                        </span>
                    </div>

                    <div class="exam-stats">
                        <div class="exam-stat">
                            <div class="exam-stat-value"><?php echo $exam['schedule_count']; ?></div>
                            <div class="exam-stat-label">Schedules</div>
                        </div>
                        <div class="exam-stat">
                            <div class="exam-stat-value"><?php echo $exam['open_schedules']; ?></div>
                            <div class="exam-stat-label">Open</div>
                        </div>
                        <div class="exam-stat">
                            <div class="exam-stat-value"><?php echo $exam['closed_schedules']; ?></div>
                            <div class="exam-stat-label">Closed</div>
                        </div>
                        <div class="exam-stat">
                            <div class="exam-stat-value"><?php echo $exam['total_marks_entered']; ?></div>
                            <div class="exam-stat-label">Marks</div>
                        </div>
                    </div>

                    <?php if ($exam['schedule_count'] > 0): ?>
                    <div style="padding: 0 1.5rem;">
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $completion_rate; ?>%;"></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="exam-details">
                        <div class="exam-detail-item">
                            <span class="exam-detail-label">Total Marks</span>
                            <span class="exam-detail-value"><?php echo $exam['total_marks']; ?></span>
                        </div>
                        <div class="exam-detail-item">
                            <span class="exam-detail-label">Passing Marks</span>
                            <span class="exam-detail-value"><?php echo $exam['passing_marks']; ?></span>
                        </div>
                        <?php if ($exam['average_marks']): ?>
                        <div class="exam-detail-item">
                            <span class="exam-detail-label">Average</span>
                            <span class="exam-detail-value"><?php echo round($exam['average_marks'], 1); ?></span>
                        </div>
                        <div class="exam-detail-item">
                            <span class="exam-detail-label">Highest</span>
                            <span class="exam-detail-value"><?php echo $exam['highest_marks']; ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="exam-detail-item">
                            <span class="exam-detail-label">Created By</span>
                            <span class="exam-detail-value"><?php echo htmlspecialchars($exam['created_by_name']); ?></span>
                        </div>
                    </div>

                    <div class="exam-actions">
                        <a href="exam_schedules.php?exam_id=<?php echo $exam['id']; ?>" class="btn btn-info btn-sm">
                            <i class="fas fa-calendar-alt"></i> Schedules
                        </a>

                        <?php if ($exam['status'] == 'draft'): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="exam_id" value="<?php echo $exam['id']; ?>">
                                <button type="submit" name="publish_exam" class="btn btn-success btn-sm" onclick="return confirmPublish()">
                                    <i class="fas fa-globe"></i> Publish
                                </button>
                            </form>
                            <form method="POST" style="display: inline;" onsubmit="return confirmDelete(<?php echo $exam['id']; ?>, '<?php echo htmlspecialchars($exam['exam_name']); ?>')">
                                <input type="hidden" name="exam_id" value="<?php echo $exam['id']; ?>">
                                <button type="submit" name="delete_exam" class="btn btn-danger btn-sm">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </form>
                        <?php elseif ($exam['status'] == 'published'): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="exam_id" value="<?php echo $exam['id']; ?>">
                                <button type="submit" name="unpublish_exam" class="btn btn-warning btn-sm" onclick="return confirmUnpublish()">
                                    <i class="fas fa-eye-slash"></i> Unpublish
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Confirm delete
        function confirmDelete(examId, examName) {
            Swal.fire({
                title: 'Delete Exam?',
                html: `Are you sure you want to delete "<strong>${examName}</strong>"?<br><br>This action cannot be undone!`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: 'var(--danger)',
                cancelButtonColor: 'var(--gray)',
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    return true;
                }
                return false;
            });
        }

        // Confirm publish
        function confirmPublish() {
            Swal.fire({
                title: 'Publish Exam?',
                text: 'This exam will become visible to teachers and schedules can be created.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: 'var(--success)',
                cancelButtonColor: 'var(--gray)',
                confirmButtonText: 'Yes, publish',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    return true;
                }
                return false;
            });
        }

        // Confirm unpublish
        function confirmUnpublish() {
            Swal.fire({
                title: 'Unpublish Exam?',
                text: 'This exam will be hidden from teachers.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: 'var(--warning)',
                cancelButtonColor: 'var(--gray)',
                confirmButtonText: 'Yes, unpublish',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    return true;
                }
                return false;
            });
        }

        // Reset filters
        function resetFilters() {
            window.location.href = 'exams.php';
        }

        // Show success/error messages
        <?php if (isset($_SESSION['success_message'])): ?>
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: '<?php echo $_SESSION['success_message']; ?>',
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000
        });
        <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: '<?php echo $_SESSION['error_message']; ?>',
            confirmButtonColor: 'var(--danger)'
        });
        <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
    </script>
</body>
</html>