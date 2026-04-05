<?php
include '../config.php';
checkAuth();
checkRole(['teacher', 'admin']);

$teacher_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT DISTINCT c.id, c.class_name
    FROM classes c
    LEFT JOIN subjects s ON s.class_id = c.id
    LEFT JOIN exam_schedules es ON es.class_id = c.id AND (es.teacher_id = ? OR es.subject_id = s.id)
    WHERE c.class_teacher_id = ? OR s.teacher_id = ? OR es.teacher_id = ?
    ORDER BY c.class_name
");
$stmt->execute([$teacher_id, $teacher_id, $teacher_id, $teacher_id]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT 
        es.*,
        e.exam_name,
        e.exam_code,
        c.class_name,
        s.subject_name,
        COUNT(em.id) as marks_entered
    FROM exam_schedules es
    JOIN exams e ON es.exam_id = e.id
    JOIN classes c ON es.class_id = c.id
    LEFT JOIN subjects s ON es.subject_id = s.id
    LEFT JOIN exam_marks em ON es.id = em.exam_schedule_id AND em.submission_status = 'submitted'
    WHERE (
        es.teacher_id = ?
        OR s.teacher_id = ?
        OR c.class_teacher_id = ?
    )
    AND (es.status IN ('open') OR NOW() BETWEEN es.portal_open_date AND es.portal_close_date)
    GROUP BY es.id
    ORDER BY es.portal_close_date ASC
");
$stmt->execute([$teacher_id, $teacher_id, $teacher_id]);
$open_exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Exam Marks Entry - ' . SCHOOL_NAME;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            background: #f8f9fa;
            min-height: calc(100vh - 70px);
            position: relative;
            z-index: 1;
            width: calc(100% - 280px);
        }
        
        .card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .exam-item {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border-left: 4px solid #3498db;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .exam-info h3 {
            margin: 0 0 0.5rem;
            color: #2c3e50;
        }
        
        .exam-info p {
            margin: 0.25rem 0;
            color: #7f8c8d;
            font-size: 0.9rem;
        }
        
        .time-remaining {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }
        
        .time-remaining.critical {
            background: #f8d7da;
            color: #721c24;
        }
        
        .time-remaining.normal {
            background: #d4edda;
            color: #155724;
        }
        
        .btn {
            padding: 0.6rem 1.5rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: 8px;
        }
        
        .empty-state i {
            font-size: 3rem;
            color: #bdc3c7;
            margin-bottom: 1rem;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
                width: 100%;
            }
            
            .exam-item {
                flex-direction: column;
                align-items: flex-start;
                padding: 1rem;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
                margin-top: 1rem;
            }
            
            .empty-state {
                padding: 2rem 1rem;
            }
        }
        
        /* SweetAlert2 z-index fix */
        .swal2-container {
            z-index: 2000 !important;
        }
        
        .swal2-modal {
            z-index: 2001 !important;
        }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container" style="max-width: 1000px; margin: 0 auto;">
            <div style="margin-bottom: 2rem;">
                <h1 style="color: #2c3e50; margin: 0 0 0.5rem;">Exam Marks Entry</h1>
                <p style="color: #7f8c8d;">Enter student marks for open exam portals. Portal closes on the deadline date.</p>
                <div style="margin-top: 1rem;">
                    <a href="report_forms.php" class="btn btn-primary">
                        <i class="fas fa-print"></i> View Ready Report Forms
                    </a>
                </div>
            </div>
            
            <?php if (empty($classes)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p style="color: #7f8c8d; font-size: 1.1rem;">
                        You are not assigned as a class teacher for any classes.
                    </p>
                </div>
            <?php elseif (empty($open_exams)): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-check"></i>
                    <p style="color: #7f8c8d; font-size: 1.1rem;">
                        No open exam portals at the moment. Portal will be available during the scheduled dates.
                    </p>
                </div>
            <?php else: ?>
                <?php foreach ($open_exams as $exam): ?>
                    <?php
                    // Calculate time remaining
                    $close_time = strtotime($exam['portal_close_date']);
                    $now = time();
                    $time_remaining = $close_time - $now;
                    $hours_remaining = floor($time_remaining / 3600);
                    $minutes_remaining = floor(($time_remaining % 3600) / 60);
                    
                    $is_critical = $hours_remaining < 24; // Less than 24 hours
                    ?>
                    <div class="exam-item">
                        <div class="exam-info">
                            <h3>
                                <?php echo htmlspecialchars($exam['exam_name']); ?>
                                <small style="color: #95a5a6; font-weight: normal;">
                                    (<?php echo htmlspecialchars($exam['exam_code']); ?>)
                                </small>
                            </h3>
                            <p>
                                <i class="fas fa-graduation-cap"></i>
                                <?php echo htmlspecialchars($exam['class_name']); ?>
                                <?php if ($exam['subject_name']): ?>
                                    - <?php echo htmlspecialchars($exam['subject_name']); ?>
                                <?php endif; ?>
                            </p>
                            <p>
                                <i class="fas fa-pencil-alt"></i>
                                Marks Entered: <strong><?php echo $exam['marks_entered']; ?></strong>
                            </p>
                            <span class="time-remaining <?php echo $is_critical ? 'critical' : 'normal'; ?>">
                                <i class="fas fa-hourglass-end"></i>
                                Time Remaining: <?php echo $hours_remaining . 'h ' . $minutes_remaining . 'min'; ?>
                            </span>
                        </div>
                        <a href="enter_marks.php?schedule_id=<?php echo $exam['id']; ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Enter Marks
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
</body>
</html>
