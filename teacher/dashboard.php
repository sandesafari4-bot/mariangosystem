<?php
include '../config.php';
checkAuth();
checkRole(['teacher', 'admin']);

$teacher_id = $_SESSION['user_id'];

// Get teacher-specific statistics with proper error handling
$stats = [
    'total_students' => getTeacherStudents($teacher_id),
    'attendance_taken' => getTodayAttendance($teacher_id),
    'pending_assignments' => getPendingAssignments($teacher_id),
    'total_classes' => getTeacherClasses($teacher_id)
];

// Get today's timetable
$today_schedule = getTodayTimetable($teacher_id);

// Get weekly schedule
$weekly_schedule = getWeeklySchedule($teacher_id);

// Recent assignments
$assignments = getRecentAssignments($teacher_id);

// Subject distribution
$subject_distribution = getSubjectDistribution($teacher_id);

// Performance metrics
$attendance_rate = getAttendanceRate($teacher_id);
$assignment_completion = getAssignmentCompletion($teacher_id);
$exam_results_summary = getExamResultsSummary($teacher_id);

// Get upcoming events (only relevant for teachers)
$events = getUpcomingEvents($teacher_id);

$page_title = "Teacher Dashboard - " . SCHOOL_NAME;

/**
 * Get teacher's students count
 */
function getTeacherStudents($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT s.id) 
                              FROM students s 
                              JOIN classes c ON s.class_id = c.id 
                              LEFT JOIN subjects sub ON c.id = sub.class_id 
                              WHERE (sub.teacher_id = ? OR c.class_teacher_id = ?)
                              AND s.status = 'active'");
        $stmt->execute([$teacher_id, $teacher_id]);
        return $stmt->fetchColumn() ?: 0;
    } catch (PDOException $e) {
        error_log("Error getting teacher students: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get today's attendance count
 */
function getTodayAttendance($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance 
                              WHERE date = CURDATE() AND recorded_by = ?");
        $stmt->execute([$teacher_id]);
        return $stmt->fetchColumn() ?: 0;
    } catch (PDOException $e) {
        error_log("Error getting today attendance: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get pending assignments count
 */
function getPendingAssignments($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM assignments 
                             WHERE due_date >= CURDATE() AND teacher_id = ?");
        $stmt->execute([$teacher_id]);
        return $stmt->fetchColumn() ?: 0;
    } catch (PDOException $e) {
        error_log("Error getting pending assignments: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get teacher's classes count
 */
function getTeacherClasses($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT c.id) 
                              FROM classes c 
                              LEFT JOIN subjects s ON c.id = s.class_id 
                              WHERE s.teacher_id = ? OR c.class_teacher_id = ?");
        $stmt->execute([$teacher_id, $teacher_id]);
        return $stmt->fetchColumn() ?: 0;
    } catch (PDOException $e) {
        error_log("Error getting teacher classes: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get today's timetable
 */
function getTodayTimetable($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT s.subject_name, c.class_name, t.start_time, t.end_time,
                              TIMEDIFF(t.end_time, t.start_time) as duration
                              FROM timetable_lessons t 
                              JOIN subjects s ON t.subject_id = s.id 
                              JOIN classes c ON s.class_id = c.id 
                              WHERE s.teacher_id = ? AND t.day_of_week = DAYNAME(CURDATE()) 
                              ORDER BY t.start_time");
        $stmt->execute([$teacher_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting today timetable: " . $e->getMessage());
        return [];
    }
}

/**
 * Get weekly schedule
 */
function getWeeklySchedule($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT t.day_of_week, t.start_time, t.end_time,
                              s.subject_name, c.class_name
                              FROM timetable_lessons t 
                              JOIN subjects s ON t.subject_id = s.id 
                              JOIN classes c ON s.class_id = c.id 
                              WHERE s.teacher_id = ? 
                              ORDER BY FIELD(t.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
                              t.start_time");
        $stmt->execute([$teacher_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting weekly schedule: " . $e->getMessage());
        return [];
    }
}

/**
 * Get recent assignments
 */
function getRecentAssignments($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT a.*, c.class_name,
                              (SELECT COUNT(*) FROM submissions WHERE assignment_id = a.id) as total_submissions,
                              (SELECT COUNT(*) FROM students WHERE class_id = a.class_id AND status = 'active') as total_students
                              FROM assignments a 
                              JOIN classes c ON a.class_id = c.id 
                              WHERE a.teacher_id = ? 
                              ORDER BY a.created_at DESC LIMIT 5");
        $stmt->execute([$teacher_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting recent assignments: " . $e->getMessage());
        return [];
    }
}

/**
 * Get subject distribution
 */
function getSubjectDistribution($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT s.subject_name, COUNT(DISTINCT c.id) as class_count,
                              COUNT(DISTINCT st.id) as student_count
                              FROM subjects s
                              LEFT JOIN classes c ON s.class_id = c.id
                              LEFT JOIN students st ON c.id = st.class_id AND st.status='active'
                              WHERE s.teacher_id = ?
                              GROUP BY s.id, s.subject_name");
        $stmt->execute([$teacher_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting subject distribution: " . $e->getMessage());
        return [];
    }
}

/**
 * Get attendance rate
 */
function getAttendanceRate($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT 
                              ROUND(
                                (SUM(CASE WHEN status='Present' THEN 1 ELSE 0 END) * 100.0) / COUNT(*), 1
                              ) as rate
                              FROM attendance 
                              WHERE recorded_by = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
        $stmt->execute([$teacher_id]);
        $result = $stmt->fetchColumn();
        return $result ?: 0;
    } catch (PDOException $e) {
        error_log("Error getting attendance rate: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get assignment completion rate
 */
function getAssignmentCompletion($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT 
                              ROUND(
                                COALESCE(
                                  (SELECT COUNT(*) FROM submissions s 
                                   JOIN assignments a ON s.assignment_id = a.id 
                                   WHERE a.teacher_id = ?) * 100.0 / 
                                  NULLIF((SELECT COUNT(*) FROM assignments a2 
                                   WHERE a2.teacher_id = ? AND a2.due_date <= CURDATE()), 0), 0
                                ), 1
                              ) as rate");
        $stmt->execute([$teacher_id, $teacher_id]);
        $result = $stmt->fetchColumn();
        return $result ?: 0;
    } catch (PDOException $e) {
        error_log("Error getting assignment completion: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get teacher exam results summary
 */
function getExamResultsSummary($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT 
                              COUNT(*) as total_results,
                              SUM(CASE WHEN er.result_pdf_url IS NOT NULL AND er.result_pdf_url != '' THEN 1 ELSE 0 END) as with_result_slips,
                              SUM(CASE WHEN er.analysis_pdf_url IS NOT NULL AND er.analysis_pdf_url != '' THEN 1 ELSE 0 END) as with_analysis,
                              SUM(CASE WHEN er.email_sent = 1 THEN 1 ELSE 0 END) as emailed,
                              MAX(es.exam_date) as latest_exam_date
                              FROM exam_results er
                              LEFT JOIN exam_schedules es ON er.exam_schedule_id = es.id
                              WHERE er.teacher_id = ?");
        $stmt->execute([$teacher_id]);
        $result = $stmt->fetch();
        return $result ?: [
            'total_results' => 0,
            'with_result_slips' => 0,
            'with_analysis' => 0,
            'emailed' => 0,
            'latest_exam_date' => null
        ];
    } catch (PDOException $e) {
        error_log("Error getting exam results summary: " . $e->getMessage());
        return [
            'total_results' => 0,
            'with_result_slips' => 0,
            'with_analysis' => 0,
            'emailed' => 0,
            'latest_exam_date' => null
        ];
    }
}

/**
 * Get upcoming events (only events that are relevant to the teacher)
 * Events are filtered to show only:
 * 1. Events targeted to all teachers
 * 2. Events created by this teacher
 * 3. Events for classes this teacher teaches
 * 4. Only future events (today and beyond)
 */
function getUpcomingEvents($teacher_id) {
    global $pdo;
    
    try {
        // Get current date for comparison
        $today = date('Y-m-d');
        
        $stmt = $pdo->prepare("SELECT e.*, c.class_name, 
                              CASE 
                                  WHEN e.event_type = 'meeting' THEN 'Staff Meeting'
                                  WHEN e.event_type = 'conference' THEN 'Parent-Teacher Conference'
                                  WHEN e.event_type = 'school_event' THEN 'School Event'
                                  WHEN e.event_type = 'holiday' THEN 'Holiday'
                                  WHEN e.event_type = 'exam' THEN 'Exam Schedule'
                                  WHEN e.event_type = 'workshop' THEN 'Workshop'
                                  ELSE e.event_type 
                              END as event_type_label,
                              u.full_name as created_by_name
                              FROM events e
                              LEFT JOIN classes c ON e.class_id = c.id
                              LEFT JOIN users u ON e.created_by = u.id
                              WHERE e.event_date >= CURDATE()  -- Only future events
                              AND (
                                  e.target_audience = 'all_teachers'  -- All teacher events
                                  OR e.created_by = ?                 -- Events created by this teacher
                                  OR e.class_id IN (                  -- Events for classes they teach
                                      SELECT DISTINCT c.id 
                                      FROM classes c 
                                      LEFT JOIN subjects s ON c.id = s.class_id 
                                      WHERE s.teacher_id = ? OR c.class_teacher_id = ?
                                  )
                                  OR e.target_audience LIKE '%teacher%'  -- Events mentioning teachers
                                  OR e.target_audience LIKE '%all%'     -- Events for everyone
                              )
                              ORDER BY e.event_date ASC, e.start_time ASC
                              LIMIT 8");  // Limit to 8 events for better display
        
        $stmt->execute([$teacher_id, $teacher_id, $teacher_id]);
        $events = $stmt->fetchAll();
        
        // Sort events by date (closest first) and ensure no duplicates
        $unique_events = [];
        $seen_ids = [];
        
        foreach ($events as $event) {
            if (!in_array($event['id'], $seen_ids)) {
                $unique_events[] = $event;
                $seen_ids[] = $event['id'];
            }
        }
        
        return $unique_events;
        
    } catch (PDOException $e) {
        error_log("Error getting upcoming events: " . $e->getMessage());
        return [];
    }
}

/**
 * Get days until event
 */
function getDaysUntilEvent($event_date) {
    $today = new DateTime();
    $event = new DateTime($event_date);
    $interval = $today->diff($event);
    
    if ($interval->days == 0) {
        return 'Today';
    } elseif ($interval->days == 1) {
        return 'Tomorrow';
    } else {
        return $interval->days . ' days';
    }
}

// Helper function to get event icon based on type
function getEventIcon($event_type) {
    switch ($event_type) {
        case 'meeting':
            return 'fas fa-users';
        case 'conference':
            return 'fas fa-chalkboard-teacher';
        case 'school_event':
            return 'fas fa-flag';
        case 'exam':
            return 'fas fa-pencil-alt';
        case 'workshop':
            return 'fas fa-tools';
        case 'holiday':
            return 'fas fa-umbrella-beach';
        default:
            return 'fas fa-calendar-alt';
    }
}

// Helper function to get event color based on type
function getEventColor($event_type) {
    switch ($event_type) {
        case 'meeting':
            return '#f39c12';
        case 'conference':
            return '#17a2b8';
        case 'school_event':
            return '#28a745';
        case 'exam':
            return '#9b59b6';
        case 'workshop':
            return '#3498db';
        case 'holiday':
            return '#dc3545';
        default:
            return '#95a5a6';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- SweetAlert2 for better notifications -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            --gradient-teacher: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-teacher-alt: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
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

        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            min-height: calc(100vh - 70px);
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
            background: var(--gradient-teacher);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
        }

        /* Success Message */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius-md);
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            animation: slideIn 0.3s ease;
            background: var(--white);
            box-shadow: var(--shadow-md);
            border-left: 4px solid var(--success);
        }

        .alert-success {
            border-left-color: var(--success);
        }

        .alert i {
            font-size: 1.2rem;
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
            background: var(--gradient-teacher);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .welcome-section h1 {
            font-size: 2.2rem;
            font-weight: 700;
            background: var(--gradient-teacher);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .welcome-section p {
            color: var(--gray);
            font-size: 1rem;
        }

        .date-time {
            text-align: right;
            background: rgba(102, 126, 234, 0.1);
            padding: 1rem 2rem;
            border-radius: 50px;
            border: 1px solid rgba(102, 126, 234, 0.2);
        }

        .current-date {
            font-size: 1rem;
            font-weight: 500;
            color: var(--gray);
            margin-bottom: 0.3rem;
        }

        .current-time {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            font-family: 'Courier New', monospace;
            letter-spacing: 2px;
        }

        /* Page Actions */
        .page-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
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

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }

        .btn-primary {
            background: var(--gradient-teacher);
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

        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .kpi-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
            cursor: pointer;
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            transition: var(--transition);
        }

        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .kpi-card:hover::before {
            width: 6px;
        }

        .kpi-card.students::before { background: var(--gradient-2); }
        .kpi-card.attendance::before { background: var(--gradient-1); }
        .kpi-card.classes::before { background: var(--gradient-5); }
        .kpi-card.assignments::before { background: var(--gradient-3); }

        .kpi-content {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .kpi-icon {
            width: 60px;
            height: 60px;
            border-radius: var(--border-radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            flex-shrink: 0;
            transition: var(--transition);
        }

        .kpi-card:hover .kpi-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .kpi-card.students .kpi-icon { background: var(--gradient-2); }
        .kpi-card.attendance .kpi-icon { background: var(--gradient-1); }
        .kpi-card.classes .kpi-icon { background: var(--gradient-5); }
        .kpi-card.assignments .kpi-icon { background: var(--gradient-3); }

        .kpi-info h3 {
            font-size: 0.9rem;
            color: var(--gray);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .kpi-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .kpi-change {
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            background: rgba(76, 201, 240, 0.1);
            width: fit-content;
        }

        .kpi-change.positive { color: var(--success); }
        .kpi-change.negative { color: var(--danger); }

        /* Dashboard Layout */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        @media (max-width: 1200px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Dashboard Cards */
        .dashboard-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            margin-bottom: 1.5rem;
            transition: var(--transition);
            border: 1px solid rgba(255,255,255,0.3);
        }

        .dashboard-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-xl);
        }

        .dashboard-card:last-child {
            margin-bottom: 0;
        }

        .card-header {
            padding: 1.5rem;
            border-bottom: 2px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(248, 249, 250, 0.5);
        }

        .card-header h3 {
            color: var(--dark);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.1rem;
            font-weight: 600;
        }

        .card-header h3 i {
            color: var(--primary);
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Schedule Items */
        .schedule-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: var(--light);
            border-radius: var(--border-radius-md);
            margin-bottom: 0.8rem;
            border-left: 4px solid var(--primary);
            transition: var(--transition);
        }

        .schedule-item:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow-md);
            background: white;
        }

        .schedule-time {
            font-weight: 600;
            color: var(--primary);
            min-width: 100px;
        }

        .schedule-details {
            flex: 1;
            margin-left: 1rem;
        }

        .schedule-subject {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.2rem;
        }

        .schedule-class {
            font-size: 0.85rem;
            color: var(--gray);
        }

        /* Assignment Items */
        .assignment-item {
            padding: 1rem;
            border-radius: var(--border-radius-md);
            margin-bottom: 0.8rem;
            background: var(--light);
            transition: var(--transition);
            border: 1px solid transparent;
        }

        .assignment-item:hover {
            background: white;
            border-color: var(--primary);
            transform: translateX(5px);
            box-shadow: var(--shadow-md);
        }

        .assignment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }

        .assignment-title {
            font-weight: 600;
            color: var(--dark);
        }

        .assignment-class {
            font-size: 0.8rem;
            padding: 0.2rem 0.5rem;
            background: rgba(67, 97, 238, 0.1);
            border-radius: 4px;
            color: var(--primary);
        }

        .assignment-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
            color: var(--gray);
        }

        .deadline {
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .deadline.urgent {
            color: var(--danger);
        }

        .submission-progress {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .progress-bar {
            width: 100px;
            height: 6px;
            background: rgba(0,0,0,0.1);
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--gradient-3);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        /* Events Section */
        .events-container {
            max-height: 400px;
            overflow-y: auto;
            padding-right: 5px;
        }

        .event-item {
            padding: 1rem;
            border-radius: var(--border-radius-md);
            margin-bottom: 1rem;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid var(--light);
            cursor: pointer;
        }

        .event-item:hover {
            transform: translateX(5px) translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .event-item.today { 
            border-left: 4px solid var(--danger);
            background: linear-gradient(135deg, #fff5f5 0%, #ffe9e9 100%);
        }
        
        .event-item.tomorrow { 
            border-left: 4px solid var(--warning);
            background: linear-gradient(135deg, #fff4e5 0%, #ffead4 100%);
        }
        
        .event-item.meeting { 
            border-left: 4px solid #f39c12;
            background: linear-gradient(135deg, #fff3cd 0%, #fff8e1 100%);
        }
        
        .event-item.conference { 
            border-left: 4px solid #17a2b8;
            background: linear-gradient(135deg, #d1ecf1 0%, #e3f2fd 100%);
        }
        
        .event-item.school_event { 
            border-left: 4px solid #28a745;
            background: linear-gradient(135deg, #d4edda 0%, #e8f5e9 100%);
        }
        
        .event-item.holiday { 
            border-left: 4px solid #dc3545;
            background: linear-gradient(135deg, #f8d7da 0%, #ffebee 100%);
        }
        
        .event-item.exam { 
            border-left: 4px solid #9b59b6;
            background: linear-gradient(135deg, #e8daef 0%, #f3e5f5 100%);
        }
        
        .event-item.workshop { 
            border-left: 4px solid #3498db;
            background: linear-gradient(135deg, #d6eaf8 0%, #e3f2fd 100%);
        }
        
        .event-item.other { 
            border-left: 4px solid #95a5a6;
            background: linear-gradient(135deg, #ecf0f1 0%, #f5f7fa 100%);
        }

        .event-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            background: rgba(255,255,255,0.5);
            font-weight: 600;
        }

        .event-badge.today { background: var(--danger); color: white; }
        .event-badge.tomorrow { background: var(--warning); color: white; }

        .event-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.8rem;
        }

        .event-icon {
            width: 35px;
            height: 35px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
        }

        .event-title {
            font-weight: 600;
            color: var(--dark);
            font-size: 1rem;
        }

        .event-type {
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            background: rgba(255,255,255,0.5);
            border-radius: 4px;
            font-weight: 600;
        }

        .event-details {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.85rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
            margin-left: 2.7rem;
        }

        .event-date, .event-time, .event-location {
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .event-date i, .event-time i, .event-location i {
            width: 16px;
            color: var(--primary);
        }

        .event-audience {
            font-size: 0.8rem;
            color: var(--dark);
            padding-top: 0.5rem;
            border-top: 1px dashed var(--gray-light);
            margin-left: 2.7rem;
        }

        .event-audience i {
            color: var(--primary);
            margin-right: 0.3rem;
        }

        .days-until {
            font-size: 0.7rem;
            font-weight: 600;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            background: var(--primary);
            color: white;
            display: inline-block;
            margin-left: 0.5rem;
        }

        /* Quick Actions */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.8rem;
        }

        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 1rem;
            background: var(--light);
            border: none;
            border-radius: var(--border-radius-md);
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            font-size: 0.8rem;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--gradient-teacher);
            opacity: 0;
            transition: var(--transition);
            z-index: 1;
        }

        .action-btn:hover::before {
            opacity: 1;
        }

        .action-btn i, .action-btn span {
            position: relative;
            z-index: 2;
            transition: var(--transition);
        }

        .action-btn:hover i,
        .action-btn:hover span {
            color: white;
        }

        .action-btn i {
            font-size: 1.5rem;
            color: var(--primary);
        }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .chart-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            border: 1px solid rgba(255,255,255,0.3);
        }

        .chart-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-xl);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light);
        }

        .chart-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-container {
            height: 250px;
            position: relative;
        }

        /* Mini Stats */
        .mini-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.8rem;
            margin-top: 1rem;
        }

        .mini-stat {
            text-align: center;
            padding: 0.8rem;
            background: var(--light);
            border-radius: var(--border-radius-sm);
            transition: var(--transition);
        }

        .mini-stat:hover {
            transform: translateY(-2px);
            background: rgba(67, 97, 238, 0.1);
        }

        .mini-stat-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary);
        }

        .mini-stat-label {
            font-size: 0.7rem;
            color: var(--gray);
        }

        /* Resource Links */
        .resource-links {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .resource-link {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.8rem;
            color: var(--dark);
            text-decoration: none;
            border-radius: var(--border-radius-md);
            transition: var(--transition);
            background: var(--light);
        }

        .resource-link:hover {
            background: rgba(67, 97, 238, 0.1);
            transform: translateX(5px);
        }

        .resource-link i {
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--gradient-teacher);
            color: white;
            border-radius: var(--border-radius-sm);
            font-size: 1rem;
        }

        /* Weekly Schedule */
        .weekly-schedule {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
        }

        .schedule-day {
            background: var(--light);
            border-radius: var(--border-radius-md);
            overflow: hidden;
        }

        .day-header {
            padding: 0.8rem;
            background: rgba(67, 97, 238, 0.1);
            font-weight: 600;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .day-lessons {
            padding: 0.8rem;
        }

        .lesson-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }

        .lesson-item:last-child {
            border-bottom: none;
        }

        .lesson-time {
            font-size: 0.85rem;
            color: var(--gray);
            min-width: 80px;
        }

        .lesson-info {
            flex: 1;
            margin-left: 0.5rem;
        }

        .lesson-subject {
            font-weight: 500;
            color: var(--dark);
        }

        .lesson-class {
            font-size: 0.8rem;
            color: var(--gray);
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        /* Event Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            backdrop-filter: blur(5px);
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: var(--border-radius-xl);
            width: 100%;
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
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
        }

        .modal-title {
            margin: 0;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.2rem;
        }

        .btn-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
            transition: var(--transition);
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .btn-close:hover {
            background: var(--light);
            color: var(--danger);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .event-detail-item {
            display: flex;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--light);
        }

        .event-detail-item:last-child {
            border-bottom: none;
        }

        .detail-label {
            width: 100px;
            font-weight: 600;
            color: var(--gray);
        }

        .detail-value {
            flex: 1;
            color: var(--dark);
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

        .stagger-item {
            opacity: 0;
            animation: fadeInUp 0.5s ease-out forwards;
        }

        .stagger-item:nth-child(1) { animation-delay: 0.1s; }
        .stagger-item:nth-child(2) { animation-delay: 0.2s; }
        .stagger-item:nth-child(3) { animation-delay: 0.3s; }
        .stagger-item:nth-child(4) { animation-delay: 0.4s; }

        /* Responsive */
        @media (max-width: 1400px) {
            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .kpi-grid {
                grid-template-columns: 1fr;
            }
            
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .date-time {
                text-align: center;
            }
            
            .quick-actions-grid {
                grid-template-columns: 1fr;
            }
            
            .chart-container {
                height: 200px;
            }
        }
    </style>
</head>
<body>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>
    <?php include '../loader.php'; ?>

    <div class="main-content">
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
        
        <!-- Page Header -->
        <div class="page-header animate-fade-up">
            <div class="header-content">
                <div class="welcome-section">
                    <h1>Teacher Dashboard</h1>
                    <p>Welcome back, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Teacher'); ?>! Ready for today's classes?</p>
                </div>
                <div class="date-time">
                    <div class="current-date"><?php echo date('l, F j, Y'); ?></div>
                    <div class="current-time" id="currentTime"><?php echo date('H:i:s'); ?></div>
                </div>
            </div>
            <div class="page-actions" style="margin-top: 1rem;">
                <button class="btn btn-primary" onclick="window.location.href='attendance.php'">
                    <i class="fas fa-clipboard-check"></i>
                    Take Attendance
                </button>
                <button class="btn btn-success" onclick="window.location.href='assignments.php?action=create'">
                    <i class="fas fa-plus-circle"></i>
                    Create Assignment
                </button>
            </div>
        </div>
        
        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card students stagger-item" onclick="window.location.href='students.php'">
                <div class="kpi-content">
                    <div class="kpi-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="kpi-info">
                        <h3>My Students</h3>
                        <div class="kpi-value"><?php echo number_format($stats['total_students']); ?></div>
                        <div class="kpi-change positive">
                            <i class="fas fa-arrow-up"></i> Active students
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="kpi-card attendance stagger-item" onclick="window.location.href='attendance.php'">
                <div class="kpi-content">
                    <div class="kpi-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div class="kpi-info">
                        <h3>Today's Attendance</h3>
                        <div class="kpi-value"><?php echo number_format($stats['attendance_taken']); ?></div>
                        <div class="kpi-change positive">
                            <i class="fas fa-check"></i> Taken
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="kpi-card classes stagger-item" onclick="window.location.href='classes.php'">
                <div class="kpi-content">
                    <div class="kpi-icon">
                        <i class="fas fa-door-open"></i>
                    </div>
                    <div class="kpi-info">
                        <h3>My Classes</h3>
                        <div class="kpi-value"><?php echo number_format($stats['total_classes']); ?></div>
                        <div class="kpi-change positive">
                            <i class="fas fa-arrow-up"></i> This term
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="kpi-card assignments stagger-item" onclick="window.location.href='assignments.php'">
                <div class="kpi-content">
                    <div class="kpi-icon">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="kpi-info">
                        <h3>Pending Assignments</h3>
                        <div class="kpi-value"><?php echo number_format($stats['pending_assignments']); ?></div>
                        <div class="kpi-change <?php echo $stats['pending_assignments'] > 0 ? 'negative' : 'positive'; ?>">
                            <i class="fas fa-<?php echo $stats['pending_assignments'] > 0 ? 'exclamation' : 'check'; ?>"></i>
                            <?php echo $stats['pending_assignments'] > 0 ? 'Need grading' : 'All graded'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Left Column -->
            <div class="left-column">
                <!-- Today's Schedule -->
                <div class="dashboard-card animate-fade-up">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-calendar-day" style="color: var(--primary);"></i>
                            Today's Schedule
                        </h3>
                        <span class="analytics-badge"><?php echo count($today_schedule); ?> classes</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($today_schedule)): ?>
                            <?php foreach($today_schedule as $class): ?>
                            <div class="schedule-item">
                                <div class="schedule-time">
                                    <?php echo date('h:i A', strtotime($class['start_time'])); ?>
                                </div>
                                <div class="schedule-details">
                                    <div class="schedule-subject"><?php echo htmlspecialchars($class['subject_name']); ?></div>
                                    <div class="schedule-class"><?php echo htmlspecialchars($class['class_name']); ?></div>
                                </div>
                                <button class="btn btn-primary btn-sm"
                                        onclick="startClass('<?php echo htmlspecialchars($class['subject_name']); ?>', '<?php echo htmlspecialchars($class['class_name']); ?>')">
                                    <i class="fas fa-play"></i> Start
                                </button>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-times"></i>
                                <p>No classes scheduled for today</p>
                                <small>Enjoy your day off!</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Recent Assignments -->
                <div class="dashboard-card animate-fade-up">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-tasks" style="color: var(--warning);"></i>
                            Recent Assignments
                        </h3>
                        <a href="assignments.php" class="btn btn-outline btn-sm">
                            View All <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($assignments)): ?>
                            <?php foreach($assignments as $assignment): ?>
                            <div class="assignment-item" onclick="viewAssignment(<?php echo $assignment['id']; ?>)">
                                <div class="assignment-header">
                                    <span class="assignment-title"><?php echo htmlspecialchars($assignment['title']); ?></span>
                                    <span class="assignment-class"><?php echo htmlspecialchars($assignment['class_name']); ?></span>
                                </div>
                                <div class="assignment-meta">
                                    <span class="deadline <?php echo (strtotime($assignment['due_date']) < time()) ? 'urgent' : ''; ?>">
                                        <i class="fas fa-clock"></i>
                                        Due: <?php echo date('M j, Y', strtotime($assignment['due_date'])); ?>
                                    </span>
                                    <div class="submission-progress">
                                        <span><?php echo $assignment['total_submissions']; ?>/<?php echo $assignment['total_students'] ?: 0; ?></span>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $assignment['total_students'] > 0 ? ($assignment['total_submissions'] / $assignment['total_students']) * 100 : 0; ?>%;"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-tasks"></i>
                                <p>No assignments created yet</p>
                                <small>Create your first assignment</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Weekly Schedule Preview -->
                <div class="dashboard-card animate-fade-up">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-calendar-week" style="color: var(--info);"></i>
                            Weekly Schedule
                        </h3>
                    </div>
                    <div class="card-body">
                        <?php 
                        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                        $schedule_by_day = [];
                        foreach ($weekly_schedule as $lesson) {
                            $schedule_by_day[$lesson['day_of_week']][] = $lesson;
                        }
                        ?>
                        
                        <div class="weekly-schedule">
                            <?php foreach ($days as $day): ?>
                                <?php if (isset($schedule_by_day[$day])): ?>
                                <div class="schedule-day">
                                    <div class="day-header">
                                        <i class="fas fa-<?php 
                                            echo $day == 'Monday' ? 'sun' : 
                                                ($day == 'Tuesday' ? 'cloud-sun' : 
                                                ($day == 'Wednesday' ? 'cloud' : 
                                                ($day == 'Thursday' ? 'cloud-rain' : 
                                                ($day == 'Friday' ? 'smile' : 'moon')))); 
                                        ?>"></i>
                                        <?php echo $day; ?>
                                    </div>
                                    <div class="day-lessons">
                                        <?php foreach ($schedule_by_day[$day] as $lesson): ?>
                                        <div class="lesson-item">
                                            <div class="lesson-time">
                                                <?php echo date('h:i A', strtotime($lesson['start_time'])); ?>
                                            </div>
                                            <div class="lesson-info">
                                                <div class="lesson-subject"><?php echo htmlspecialchars($lesson['subject_name']); ?></div>
                                                <div class="lesson-class"><?php echo htmlspecialchars($lesson['class_name']); ?></div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            
                            <?php if (empty($weekly_schedule)): ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-times"></i>
                                <p>No schedule available</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Column -->
            <div class="right-column">
                <!-- Performance Metrics -->
                <div class="dashboard-card animate-fade-up">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-chart-line" style="color: var(--success);"></i>
                            Performance Metrics
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="mini-stats" style="grid-template-columns: repeat(2, 1fr);">
                            <div class="mini-stat">
                                <div class="mini-stat-value" style="color: var(--success);"><?php echo $attendance_rate; ?>%</div>
                                <div class="mini-stat-label">Attendance Rate</div>
                            </div>
                            <div class="mini-stat">
                                <div class="mini-stat-value" style="color: var(--primary);"><?php echo $assignment_completion; ?>%</div>
                                <div class="mini-stat-label">Assignment Completion</div>
                            </div>
                        </div>
                        
                        <div style="margin-top: 1.5rem;">
                            <div class="analytics-header" style="margin-bottom: 1rem;">
                                <h4 style="font-size: 0.9rem;">
                                    <i class="fas fa-chart-pie" style="color: var(--warning);"></i>
                                    Exam Results
                                </h4>
                            </div>
                            <div class="class-list">
                                <?php if (!empty($exam_results_summary['total_results'])): ?>
                                <div class="class-item">
                                    <div class="class-rank" style="background: var(--gradient-3);"><i class="fas fa-poll"></i></div>
                                    <div class="class-info">
                                        <div class="class-name">Generated Results</div>
                                        <div class="class-stats"><?php echo (int) ($exam_results_summary['total_results'] ?? 0); ?> exam result sets</div>
                                        <div class="class-progress">
                                            <div class="class-progress-fill" style="width: 100%; background: var(--gradient-3);"></div>
                                        </div>
                                    </div>
                                    <div class="class-count"><?php echo (int) ($exam_results_summary['total_results'] ?? 0); ?></div>
                                </div>
                                
                                <div class="class-item">
                                    <div class="class-rank" style="background: var(--gradient-1);"><i class="fas fa-file-pdf"></i></div>
                                    <div class="class-info">
                                        <div class="class-name">Result Slips Ready</div>
                                        <div class="class-stats"><?php echo (int) ($exam_results_summary['with_result_slips'] ?? 0); ?> files generated</div>
                                        <div class="class-progress">
                                            <div class="class-progress-fill" style="width: <?php echo !empty($exam_results_summary['total_results']) ? min(((int) $exam_results_summary['with_result_slips'] / (int) $exam_results_summary['total_results']) * 100, 100) : 0; ?>%; background: var(--gradient-1);"></div>
                                        </div>
                                    </div>
                                    <div class="class-count"><?php echo (int) ($exam_results_summary['with_result_slips'] ?? 0); ?></div>
                                </div>
                                
                                <div class="class-item">
                                    <div class="class-rank" style="background: var(--gradient-5);"><i class="fas fa-chart-column"></i></div>
                                    <div class="class-info">
                                        <div class="class-name">Analysis Reports</div>
                                        <div class="class-stats"><?php echo (int) ($exam_results_summary['with_analysis'] ?? 0); ?> analysis files ready</div>
                                        <div class="class-progress">
                                            <div class="class-progress-fill" style="width: <?php echo !empty($exam_results_summary['total_results']) ? min(((int) $exam_results_summary['with_analysis'] / (int) $exam_results_summary['total_results']) * 100, 100) : 0; ?>%; background: var(--gradient-5);"></div>
                                        </div>
                                    </div>
                                    <div class="class-count"><?php echo (int) ($exam_results_summary['with_analysis'] ?? 0); ?></div>
                                </div>
                                
                                <div class="class-item">
                                    <div class="class-rank" style="background: var(--gradient-2);"><i class="fas fa-paper-plane"></i></div>
                                    <div class="class-info">
                                        <div class="class-name">Emailed Results</div>
                                        <div class="class-stats">
                                            <?php echo (int) ($exam_results_summary['emailed'] ?? 0); ?> sent
                                            <?php if (!empty($exam_results_summary['latest_exam_date'])): ?>
                                                • Latest: <?php echo date('M j, Y', strtotime($exam_results_summary['latest_exam_date'])); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="class-progress">
                                            <div class="class-progress-fill" style="width: <?php echo !empty($exam_results_summary['total_results']) ? min(((int) $exam_results_summary['emailed'] / (int) $exam_results_summary['total_results']) * 100, 100) : 0; ?>%; background: var(--gradient-2);"></div>
                                        </div>
                                    </div>
                                    <div class="class-count"><?php echo (int) ($exam_results_summary['emailed'] ?? 0); ?></div>
                                </div>
                                <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-square-poll-horizontal"></i>
                                    <p>No exam results available</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="dashboard-card animate-fade-up">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-bolt" style="color: var(--warning);"></i>
                            Teaching Tools
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="quick-actions-grid">
                            <a href="attendance.php" class="action-btn">
                                <i class="fas fa-clipboard-check"></i>
                                <span>Take Attendance</span>
                            </a>
                            <a href="grades.php" class="action-btn">
                                <i class="fas fa-chart-bar"></i>
                                <span>Enter Grades</span>
                            </a>
                            <a href="assignments.php" class="action-btn">
                                <i class="fas fa-tasks"></i>
                                <span>Create Assignment</span>
                            </a>
                            <a href="reports.php" class="action-btn">
                                <i class="fas fa-file-alt"></i>
                                <span>Student Reports</span>
                            </a>
                            <a href="lesson_plans.php" class="action-btn">
                                <i class="fas fa-book-open"></i>
                                <span>Lesson Plans</span>
                            </a>
                            <a href="messages.php" class="action-btn">
                                <i class="fas fa-envelope"></i>
                                <span>Messages</span>
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Upcoming Events (Filtered for Teachers) -->
                <div class="dashboard-card animate-fade-up">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-bell" style="color: var(--danger);"></i>
                            Upcoming Events
                        </h3>
                        <span class="analytics-badge"><?php echo count($events); ?> events</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($events)): ?>
                            <div class="events-container">
                                <?php foreach($events as $event): 
                                    $event_date = new DateTime($event['event_date']);
                                    $today = new DateTime();
                                    $interval = $today->diff($event_date);
                                    $days_until = $interval->days;
                                    
                                    $is_today = $event['event_date'] == date('Y-m-d');
                                    $is_tomorrow = $event['event_date'] == date('Y-m-d', strtotime('+1 day'));
                                ?>
                                <div class="event-item <?php 
                                    echo $is_today ? 'today' : ($is_tomorrow ? 'tomorrow' : $event['event_type']); 
                                ?>" onclick="viewEventDetails(<?php echo htmlspecialchars(json_encode($event)); ?>)">
                                    
                                    <?php if ($is_today): ?>
                                    <span class="event-badge today">Today</span>
                                    <?php elseif ($is_tomorrow): ?>
                                    <span class="event-badge tomorrow">Tomorrow</span>
                                    <?php else: ?>
                                    <span class="days-until"><?php echo $days_until; ?> days</span>
                                    <?php endif; ?>
                                    
                                    <div class="event-header">
                                        <div class="event-icon" style="background: <?php echo getEventColor($event['event_type']); ?>">
                                            <i class="<?php echo getEventIcon($event['event_type']); ?>"></i>
                                        </div>
                                        <div>
                                            <span class="event-title"><?php echo htmlspecialchars($event['title']); ?></span>
                                            <span class="event-type"><?php echo htmlspecialchars($event['event_type_label']); ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="event-details">
                                        <span class="event-date">
                                            <i class="fas fa-calendar"></i>
                                            <?php echo date('M j, Y', strtotime($event['event_date'])); ?>
                                        </span>
                                        <?php if (!empty($event['start_time'])): ?>
                                        <span class="event-time">
                                            <i class="fas fa-clock"></i>
                                            <?php echo date('h:i A', strtotime($event['start_time'])); ?>
                                        </span>
                                        <?php endif; ?>
                                        <?php if (!empty($event['location'])): ?>
                                        <span class="event-location">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <?php echo htmlspecialchars($event['location']); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if (!empty($event['target_audience'])): ?>
                                    <div class="event-audience">
                                        <i class="fas fa-user-friends"></i>
                                        <?php echo htmlspecialchars($event['target_audience']); ?>
                                        <?php if (!empty($event['class_name'])): ?>
                                        <span style="color: var(--primary);">(<?php echo htmlspecialchars($event['class_name']); ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-check"></i>
                                <p>No upcoming events</p>
                                <small>You're all caught up!</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Teaching Resources -->
                <div class="dashboard-card animate-fade-up">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-book-open" style="color: var(--purple);"></i>
                            Teaching Resources
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="resource-links">
                            <a href="lesson_plans.php" class="resource-link">
                                <i class="fas fa-download"></i>
                                <span>Download Lesson Plans</span>
                            </a>
                            <a href="curriculum.php" class="resource-link">
                                <i class="fas fa-chart-line"></i>
                                <span>Curriculum Guidelines</span>
                            </a>
                            <a href="reports.php?type=performance" class="resource-link">
                                <i class="fas fa-user-friends"></i>
                                <span>Student Performance Data</span>
                            </a>
                            <a href="resources.php" class="resource-link">
                                <i class="fas fa-video"></i>
                                <span>Teaching Videos</span>
                            </a>
                            <a href="worksheets.php" class="resource-link">
                                <i class="fas fa-file-pdf"></i>
                                <span>Worksheets & Templates</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Charts Grid -->
        <div class="charts-grid">
            <!-- Subject Distribution Chart -->
            <div class="chart-card animate-fade-up">
                <div class="chart-header">
                    <h3>
                        <i class="fas fa-chart-pie" style="color: var(--primary);"></i>
                        Subject Distribution
                    </h3>
                    <span class="analytics-badge">By Class</span>
                </div>
                <div class="chart-container">
                    <canvas id="subjectChart"></canvas>
                </div>
                <div class="mini-stats">
                    <?php foreach(array_slice($subject_distribution, 0, 3) as $subject): ?>
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?php echo $subject['class_count'] ?? 0; ?></div>
                        <div class="mini-stat-label"><?php echo htmlspecialchars($subject['subject_name'] ?? 'N/A'); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Performance Trend Chart -->
            <div class="chart-card animate-fade-up">
                <div class="chart-header">
                    <h3>
                        <i class="fas fa-chart-line" style="color: var(--warning);"></i>
                        Performance Trend
                    </h3>
                    <span class="analytics-badge">Last 30 Days</span>
                </div>
                <div class="chart-container">
                    <canvas id="performanceChart"></canvas>
                </div>
                <div class="mini-stats">
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?php echo $attendance_rate; ?>%</div>
                        <div class="mini-stat-label">Attendance</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?php echo $assignment_completion; ?>%</div>
                        <div class="mini-stat-label">Assignments</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?php echo count($assignments); ?></div>
                        <div class="mini-stat-label">Assignments</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Event Details Modal -->
    <div id="eventModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-calendar-alt"></i>
                    Event Details
                </h3>
                <button class="btn-close" onclick="closeEventModal()">&times;</button>
            </div>
            <div class="modal-body" id="eventModalBody">
                <!-- Event details will be populated here -->
            </div>
        </div>
    </div>

    <script>
        // Update time
        function updateTime() {
            const now = new Date();
            document.getElementById('currentTime').textContent = now.toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit',
                hour12: true 
            });
        }
        updateTime();
        setInterval(updateTime, 1000);

        // Subject Distribution Chart
        const subjectCtx = document.getElementById('subjectChart')?.getContext('2d');
        if (subjectCtx) {
            const subjectData = <?php echo json_encode($subject_distribution ?: []); ?>;
            const subjectLabels = subjectData.map(s => s.subject_name || 'Unknown');
            const subjectValues = subjectData.map(s => parseInt(s.class_count) || 0);
            
            new Chart(subjectCtx, {
                type: 'doughnut',
                data: {
                    labels: subjectLabels.length ? subjectLabels : ['No Data'],
                    datasets: [{
                        data: subjectValues.length ? subjectValues : [1],
                        backgroundColor: [
                            '#667eea',
                            '#764ba2',
                            '#f093fb',
                            '#f5576c',
                            '#4facfe'
                        ],
                        borderColor: '#fff',
                        borderWidth: 3,
                        hoverOffset: 15
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { padding: 20, usePointStyle: true }
                        }
                    },
                    animation: {
                        animateRotate: true,
                        animateScale: true,
                        duration: 2000
                    }
                }
            });
        }

        // Performance Chart
        const perfCtx = document.getElementById('performanceChart')?.getContext('2d');
        if (perfCtx) {
            // Generate some sample trend data based on actual rates
            const attendanceRate = <?php echo $attendance_rate; ?>;
            const assignmentRate = <?php echo $assignment_completion; ?>;
            
            new Chart(perfCtx, {
                type: 'line',
                data: {
                    labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                    datasets: [
                        {
                            label: 'Attendance Rate',
                            data: [
                                Math.max(0, attendanceRate - 5),
                                attendanceRate,
                                Math.min(100, attendanceRate + 3),
                                Math.max(0, attendanceRate - 2)
                            ],
                            borderColor: '#4cc9f0',
                            backgroundColor: 'rgba(76, 201, 240, 0.1)',
                            borderWidth: 3,
                            tension: 0.4,
                            fill: true
                        },
                        {
                            label: 'Assignment Completion',
                            data: [
                                Math.max(0, assignmentRate - 8),
                                assignmentRate,
                                Math.min(100, assignmentRate + 5),
                                Math.max(0, assignmentRate - 3)
                            ],
                            borderColor: '#f8961e',
                            backgroundColor: 'rgba(248, 150, 30, 0.1)',
                            borderWidth: 3,
                            tension: 0.4,
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { usePointStyle: true }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            grid: { color: 'rgba(0,0,0,0.05)' },
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            }
                        },
                        x: { grid: { display: false } }
                    },
                    animation: {
                        duration: 2000,
                        easing: 'easeInOutQuart'
                    }
                }
            });
        }

        // Function to start a class
        function startClass(subject, className) {
            Swal.fire({
                title: 'Start Class?',
                html: `<p>You are about to start <strong>${subject}</strong> for <strong>${className}</strong></p>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#4361ee',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, start class',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'attendance.php?subject=' + encodeURIComponent(subject) + '&class=' + encodeURIComponent(className);
                }
            });
        }

        // Function to view assignment
        function viewAssignment(assignmentId) {
            window.location.href = 'assignments.php?id=' + assignmentId;
        }

        // Function to view event details
        function viewEventDetails(event) {
            const modal = document.getElementById('eventModal');
            const modalBody = document.getElementById('eventModalBody');
            
            const eventDate = new Date(event.event_date);
            const today = new Date();
            const daysUntil = Math.ceil((eventDate - today) / (1000 * 60 * 60 * 24));
            
            let daysText = '';
            if (daysUntil === 0) {
                daysText = '<span style="color: var(--danger); font-weight: 600;">Today</span>';
            } else if (daysUntil === 1) {
                daysText = '<span style="color: var(--warning); font-weight: 600;">Tomorrow</span>';
            } else {
                daysText = `<span style="color: var(--primary);">${daysUntil} days from now</span>`;
            }
            
            modalBody.innerHTML = `
                <div style="text-align: center; margin-bottom: 1.5rem;">
                    <div style="font-size: 3rem; color: ${getEventColor(event.event_type)}; margin-bottom: 0.5rem;">
                        <i class="${getEventIcon(event.event_type)}"></i>
                    </div>
                    <h2 style="color: var(--dark); margin-bottom: 0.5rem;">${escapeHtml(event.title)}</h2>
                    <span style="background: ${getEventColor(event.event_type)}20; color: ${getEventColor(event.event_type)}; padding: 0.3rem 1rem; border-radius: 20px; font-size: 0.9rem;">
                        ${escapeHtml(event.event_type_label || event.event_type)}
                    </span>
                </div>
                
                <div class="event-detail-item">
                    <div class="detail-label">When:</div>
                    <div class="detail-value">
                        <strong>${new Date(event.event_date).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</strong><br>
                        ${daysText}
                        ${event.start_time ? `<br><i class="fas fa-clock"></i> ${formatTime(event.start_time)}` : ''}
                        ${event.end_time ? ` - ${formatTime(event.end_time)}` : ''}
                    </div>
                </div>
                
                ${event.location ? `
                <div class="event-detail-item">
                    <div class="detail-label">Location:</div>
                    <div class="detail-value"><i class="fas fa-map-marker-alt" style="color: var(--primary);"></i> ${escapeHtml(event.location)}</div>
                </div>
                ` : ''}
                
                <div class="event-detail-item">
                    <div class="detail-label">Audience:</div>
                    <div class="detail-value">
                        <i class="fas fa-user-friends" style="color: var(--primary);"></i> 
                        ${escapeHtml(event.target_audience || 'All Teachers')}
                        ${event.class_name ? `<br><small style="color: var(--primary);">Class: ${escapeHtml(event.class_name)}</small>` : ''}
                    </div>
                </div>
                
                ${event.description ? `
                <div class="event-detail-item">
                    <div class="detail-label">Description:</div>
                    <div class="detail-value">${escapeHtml(event.description).replace(/\n/g, '<br>')}</div>
                </div>
                ` : ''}
                
                <div class="event-detail-item">
                    <div class="detail-label">Created By:</div>
                    <div class="detail-value">${escapeHtml(event.created_by_name || 'System')}</div>
                </div>
            `;
            
            modal.classList.add('active');
        }

        // Helper function to escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Helper function to format time
        function formatTime(timeString) {
            if (!timeString) return '';
            const [hours, minutes] = timeString.split(':');
            const date = new Date();
            date.setHours(parseInt(hours), parseInt(minutes));
            return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        }

        // Helper function to get event color
        function getEventColor(eventType) {
            const colors = {
                'meeting': '#f39c12',
                'conference': '#17a2b8',
                'school_event': '#28a745',
                'exam': '#9b59b6',
                'workshop': '#3498db',
                'holiday': '#dc3545',
                'other': '#95a5a6'
            };
            return colors[eventType] || '#95a5a6';
        }

        // Helper function to get event icon
        function getEventIcon(eventType) {
            const icons = {
                'meeting': 'fas fa-users',
                'conference': 'fas fa-chalkboard-teacher',
                'school_event': 'fas fa-flag',
                'exam': 'fas fa-pencil-alt',
                'workshop': 'fas fa-tools',
                'holiday': 'fas fa-umbrella-beach',
                'other': 'fas fa-calendar-alt'
            };
            return icons[eventType] || 'fas fa-calendar-alt';
        }

        // Close event modal
        function closeEventModal() {
            document.getElementById('eventModal').classList.remove('active');
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(e) {
            const modal = document.getElementById('eventModal');
            if (e.target === modal) {
                closeEventModal();
            }
        });

        // Close modal on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeEventModal();
            }
        });
    </script>
</body>
</html>
