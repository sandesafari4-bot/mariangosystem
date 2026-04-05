<?php
// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration
require_once '../config.php';

// Authentication checks
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$userId = (int) $_SESSION['user_id'];

// Check role (assuming this function exists)
if (function_exists('checkRole')) {
    checkRole(['teacher', 'admin']);
}

// Get parameters with validation
$type = isset($_GET['type']) && in_array($_GET['type'], ['teacher', 'class']) ? $_GET['type'] : 'teacher';
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

// Initialize variables
$classTeacherClasses = [];
$selectedClassId = 0;
$selectedClassName = '';
$publishedPlan = null;
$periods = [];
$lessons = [];
$lessonMap = [];
$title = 'Published Timetable';
$selectedTeacherName = '';
$error = null;

try {
    // Check if PDO connection exists
    if (!isset($pdo)) {
        throw new Exception('Database connection not established');
    }

    // Get classes where user is class teacher
    $classTeacherStmt = $pdo->prepare("
        SELECT id, class_name
        FROM classes
        WHERE class_teacher_id = ? AND COALESCE(is_active, 1) = 1
        ORDER BY class_name
    ");
    $classTeacherStmt->execute([$userId]);
    $classTeacherClasses = $classTeacherStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get selected class ID
    $selectedClassId = isset($_GET['class_id']) ? (int) $_GET['class_id'] : 0;
    if ($selectedClassId === 0 && !empty($classTeacherClasses)) {
        $selectedClassId = (int) $classTeacherClasses[0]['id'];
    }

    // Find selected class name
    foreach ($classTeacherClasses as $classTeacherClass) {
        if ((int) $classTeacherClass['id'] === $selectedClassId) {
            $selectedClassName = (string) $classTeacherClass['class_name'];
            break;
        }
    }

    // Get published plan
    $publishedPlanStmt = $pdo->query("
        SELECT *
        FROM timetable_plans
        WHERE status = 'published'
        ORDER BY published_at DESC, id DESC
        LIMIT 1
    ");
    $publishedPlan = $publishedPlanStmt->fetch(PDO::FETCH_ASSOC);

    if ($publishedPlan) {
        // Get periods for the plan
        $periodStmt = $pdo->prepare("
            SELECT *
            FROM timetable_periods
            WHERE plan_id = ?
            ORDER BY sort_order, start_time
        ");
        $periodStmt->execute([(int) $publishedPlan['id']]);
        $periods = $periodStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($type === 'class' && $selectedClassId) {
            // Get lessons for class
            $lessonStmt = $pdo->prepare("
                SELECT tl.*, c.class_name, s.subject_name, u.full_name AS teacher_name, 
                       r.room_name, r.room_number
                FROM timetable_lessons tl
                JOIN classes c ON c.id = tl.class_id
                JOIN subjects s ON s.id = tl.subject_id
                LEFT JOIN users u ON u.id = tl.teacher_id
                LEFT JOIN rooms r ON r.id = tl.room_id
                WHERE tl.plan_id = ? AND tl.status = 'published' AND tl.class_id = ?
                ORDER BY FIELD(tl.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'), 
                         tl.start_time
            ");
            $lessonStmt->execute([(int) $publishedPlan['id'], $selectedClassId]);
            
            // Get class name if not already set
            if (empty($selectedClassName)) {
                $classNameStmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = ?");
                $classNameStmt->execute([$selectedClassId]);
                $selectedClassName = (string) $classNameStmt->fetchColumn();
            }
            
            $title = ($selectedClassName ?: 'Class') . ' Timetable';
        } else {
            // Get lessons for teacher
            $lessonStmt = $pdo->prepare("
                SELECT tl.*, c.class_name, s.subject_name, u.full_name AS teacher_name, 
                       r.room_name, r.room_number
                FROM timetable_lessons tl
                JOIN classes c ON c.id = tl.class_id
                JOIN subjects s ON s.id = tl.subject_id
                LEFT JOIN users u ON u.id = tl.teacher_id
                LEFT JOIN rooms r ON r.id = tl.room_id
                WHERE tl.plan_id = ? AND tl.status = 'published' AND tl.teacher_id = ?
                ORDER BY FIELD(tl.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'), 
                         tl.start_time
            ");
            $lessonStmt->execute([(int) $publishedPlan['id'], $userId]);
            
            // Get teacher name
            $teacherNameStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
            $teacherNameStmt->execute([$userId]);
            $selectedTeacherName = (string) ($teacherNameStmt->fetchColumn() ?: '');
            $title = ($selectedTeacherName ?: 'Teacher') . ' Timetable';
        }

        $lessons = $lessonStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Build lesson map
        foreach ($lessons as $lesson) {
            if (!isset($lessonMap[$lesson['day_of_week']])) {
                $lessonMap[$lesson['day_of_week']] = [];
            }
            $lessonMap[$lesson['day_of_week']][$lesson['period_id']] = $lesson;
        }
    }
} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
    // Log error
    error_log('Timetable print error: ' . $e->getMessage());
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
    error_log('Timetable print error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?></title>
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            color: #1f2937;
            line-height: 1.5;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background: #f8fafc;
            border-radius: 8px;
        }
        .header h1 {
            margin: 0 0 10px 0;
            color: #0f172a;
            font-size: 28px;
        }
        .header h2 {
            margin: 0 0 10px 0;
            color: #334155;
            font-size: 22px;
        }
        .header p {
            margin: 5px 0;
            color: #475569;
        }
        .error-message {
            background: #fee2e2;
            color: #991b1b;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border: 1px solid #fecaca;
        }
        .actions {
            text-align: center;
            margin-bottom: 25px;
        }
        .actions button {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            background: #2563eb;
            color: #fff;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .actions button:hover {
            background: #1d4ed8;
        }
        .table-container {
            overflow-x: auto;
            margin-top: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            min-width: 800px;
        }
        th, td {
            border: 1px solid #e2e8f0;
            padding: 12px;
            vertical-align: top;
        }
        th {
            background: #f1f5f9;
            font-weight: 600;
            color: #334155;
        }
        td {
            min-width: 150px;
        }
        .special {
            background: #fef9c3;
        }
        .lesson {
            background: #e0f2fe;
        }
        .free {
            background: #f8fafc;
            color: #64748b;
        }
        .meta {
            color: #64748b;
            font-size: 12px;
            margin-top: 4px;
        }
        .lesson-content {
            font-size: 14px;
        }
        .lesson-content strong {
            color: #0f172a;
            display: block;
            margin-bottom: 4px;
        }
        .info-text {
            text-align: center;
            color: #64748b;
            padding: 40px;
            background: #f8fafc;
            border-radius: 8px;
        }
        .debug-info {
            background: #f1f5f9;
            padding: 10px;
            margin-top: 20px;
            font-size: 12px;
            color: #334155;
            border-radius: 4px;
        }
        @media print {
            .actions, .debug-info, .error-message {
                display: none;
            }
            body {
                margin: 0;
                padding: 15px;
            }
            .header {
                background: none;
                padding: 0;
            }
            table {
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($error): ?>
            <div class="error-message">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="header">
            <h1><?php echo defined('SCHOOL_NAME') ? htmlspecialchars(SCHOOL_NAME) : 'School Timetable'; ?></h1>
            <h2><?php echo htmlspecialchars($title); ?></h2>
            <?php if ($type === 'class' && $selectedClassName): ?>
                <p><strong>Class:</strong> <?php echo htmlspecialchars($selectedClassName); ?></p>
            <?php elseif ($type === 'teacher' && $selectedTeacherName): ?>
                <p><strong>Teacher:</strong> <?php echo htmlspecialchars($selectedTeacherName); ?></p>
            <?php endif; ?>
            
            <?php if ($publishedPlan): ?>
                <p>
                    <?php echo htmlspecialchars($publishedPlan['title']); ?> | 
                    <?php echo htmlspecialchars($publishedPlan['term'] ?? 'N/A'); ?> | 
                    <?php echo htmlspecialchars($publishedPlan['academic_year'] ?? 'N/A'); ?>
                </p>
            <?php else: ?>
                <p class="info-text">No published timetable found.</p>
            <?php endif; ?>
            
            <p><small>Generated on <?php echo date('F j, Y g:i A'); ?></small></p>
        </div>

        <div class="actions">
            <button type="button" onclick="window.print()">🖨️ Print / Save as PDF</button>
            <?php if ($type === 'class' && !empty($classTeacherClasses)): ?>
                <select onchange="window.location.href='?type=class&class_id=' + this.value" style="margin-left: 10px; padding: 12px; border-radius: 6px; border: 1px solid #cbd5e1;">
                    <?php foreach ($classTeacherClasses as $class): ?>
                        <option value="<?php echo $class['id']; ?>" <?php echo $class['id'] == $selectedClassId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($class['class_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>

        <?php if (!$publishedPlan): ?>
            <div class="info-text">
                <p>No published timetable is available yet.</p>
                <p>Please check back later or contact the administrator.</p>
            </div>
        <?php elseif (empty($periods)): ?>
            <div class="info-text">
                <p>No periods have been set up for this timetable.</p>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Day</th>
                            <?php foreach ($periods as $period): ?>
                                <th>
                                    <?php echo htmlspecialchars($period['label']); ?><br>
                                    <span class="meta">
                                        <?php 
                                        $start = substr($period['start_time'] ?? '', 0, 5);
                                        $end = substr($period['end_time'] ?? '', 0, 5);
                                        echo htmlspecialchars($start . ' - ' . $end); 
                                        ?>
                                    </span>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($days as $day): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($day); ?></strong></td>
                                <?php foreach ($periods as $period): ?>
                                    <?php 
                                    $cell = isset($lessonMap[$day][$period['id']]) ? $lessonMap[$day][$period['id']] : null;
                                    $cellClass = '';
                                    
                                    if ($period['period_type'] !== 'lesson') {
                                        $cellClass = 'special';
                                    } elseif ($cell) {
                                        $cellClass = 'lesson';
                                    } else {
                                        $cellClass = 'free';
                                    }
                                    ?>
                                    <td class="<?php echo $cellClass; ?>">
                                        <div class="lesson-content">
                                            <?php if ($period['period_type'] !== 'lesson'): ?>
                                                <strong><?php echo htmlspecialchars($period['label']); ?></strong>
                                                <div class="meta"><?php echo ucfirst($period['period_type'] ?? 'special'); ?></div>
                                            <?php elseif ($cell): ?>
                                                <strong><?php echo htmlspecialchars($cell['subject_name'] ?? 'Subject'); ?></strong>
                                                <div>
                                                    <?php 
                                                    if ($type === 'class') {
                                                        echo htmlspecialchars($cell['teacher_name'] ?? 'Not assigned');
                                                    } else {
                                                        echo htmlspecialchars($cell['class_name'] ?? 'Class');
                                                    }
                                                    ?>
                                                </div>
                                                <div class="meta">
                                                    <?php 
                                                    $room = $cell['room_name'] ?? '';
                                                    if (!empty($cell['room_number'])) {
                                                        $room .= ($room ? ' - ' : '') . $cell['room_number'];
                                                    }
                                                    echo htmlspecialchars($room ?: 'No room assigned');
                                                    ?>
                                                </div>
                                            <?php else: ?>
                                                <em>Free Period</em>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Debug info - remove in production -->
        <?php if (isset($_GET['debug'])): ?>
            <div class="debug-info">
                <h4>Debug Information:</h4>
                <p>User ID: <?php echo $userId; ?></p>
                <p>Type: <?php echo htmlspecialchars($type); ?></p>
                <p>Selected Class ID: <?php echo $selectedClassId; ?></p>
                <p>Published Plan: <?php echo $publishedPlan ? 'Yes' : 'No'; ?></p>
                <p>Periods Count: <?php echo count($periods); ?></p>
                <p>Lessons Count: <?php echo count($lessons); ?></p>
                <p>Class Teacher Classes: <?php echo count($classTeacherClasses); ?></p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-print if requested
        if (window.location.search.indexOf('autoprint=1') !== -1) {
            window.addEventListener('load', function() {
                setTimeout(function() {
                    window.print();
                }, 500);
            });
        }
    </script>
</body>
</html>