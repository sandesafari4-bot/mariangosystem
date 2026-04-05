<?php
// exam_schedules.php
include '../config.php';
checkAuth();
checkRole(['admin']);

$exam_id = $_GET['exam_id'] ?? null;
if (!$exam_id) {
    header("Location: exams.php");
    exit();
}

// Get exam details
$stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
$stmt->execute([$exam_id]);
$exam = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$exam) {
    header("Location: exams.php");
    exit();
}

// Auto-sync schedule statuses based on current date/time
function syncScheduleStatuses($pdo, $exam_id) {
    try {
        $now = new DateTime();
        $now_str = $now->format('Y-m-d H:i:s');
        
        // Update to 'open' IFF we're within the portal dates (regardless of previous status if it was scheduled)
        $pdo->prepare("
            UPDATE exam_schedules 
            SET status = 'open' 
            WHERE exam_id = ? 
            AND status IN ('scheduled', 'open')
            AND NOW() >= portal_open_date
            AND NOW() <= portal_close_date
        ")->execute([$exam_id]);
        
        // Update to 'closed' if we're past the close date and status was open
        $pdo->prepare("
            UPDATE exam_schedules 
            SET status = 'closed' 
            WHERE exam_id = ? 
            AND status = 'open' 
            AND NOW() > portal_close_date
        ")->execute([$exam_id]);
    } catch (Exception $e) {
        // Silently fail - this is auto-sync, not critical
        error_log("Auto-sync failed: " . $e->getMessage());
    }
}

// Run auto-sync on page load
syncScheduleStatuses($pdo, $exam_id);

$error = '';
$success = '';

// Handle bulk portal date update for all generated schedules
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_set_dates'])) {
    $portal_open_date = $_POST['bulk_portal_open_date'] ?? '';
    $portal_close_date = $_POST['bulk_portal_close_date'] ?? '';
    $instructions = trim($_POST['bulk_instructions'] ?? '');

    $errors = [];
    if (empty($portal_open_date)) $errors[] = 'Bulk portal opening date is required';
    if (empty($portal_close_date)) $errors[] = 'Bulk portal closing date is required';
    if (strtotime($portal_close_date) <= strtotime($portal_open_date)) {
        $errors[] = 'Bulk close date must be after the open date';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE exam_schedules
                SET portal_open_date = ?, portal_close_date = ?, instructions = ?
                WHERE exam_id = ?
            ");
            $stmt->execute([$portal_open_date, $portal_close_date, $instructions, $exam_id]);
            $success = 'All exam portals were updated successfully.';
        } catch (PDOException $e) {
            $error = 'Error updating exam portals: ' . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// Handle adding schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_schedule'])) {
    $class_id = $_POST['class_id'] ?? '';
    $subject_id = $_POST['subject_id'] ?? '';
    $portal_open_date = $_POST['portal_open_date'] ?? '';
    $portal_close_date = $_POST['portal_close_date'] ?? '';
    $instructions = $_POST['instructions'] ?? '';
    
    $errors = [];
    if (empty($class_id)) $errors[] = 'Class is required';
    if (empty($portal_open_date)) $errors[] = 'Portal opening date is required';
    if (empty($portal_close_date)) $errors[] = 'Portal closing date is required';
    if (strtotime($portal_close_date) <= strtotime($portal_open_date)) {
        $errors[] = 'Close date must be after open date';
    }
    
    // Validate at least 7 days difference
    $date_diff = (strtotime($portal_close_date) - strtotime($portal_open_date)) / (60 * 60 * 24);
    if ($date_diff < 7) {
        $errors[] = 'Portal must remain open for at least 7 days';
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("
                INSERT INTO exam_schedules 
                (exam_id, subject_id, class_id, portal_open_date, portal_close_date, instructions, status)
                VALUES (?, ?, ?, ?, ?, ?, 'scheduled')
            ");
            
            $stmt->execute([
                $exam_id,
                $subject_id ?: null,
                $class_id,
                $portal_open_date,
                $portal_close_date,
                $instructions
            ]);
            
            $pdo->commit();
            $success = 'Exam schedule created successfully!';
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Error creating schedule: ' . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        $error = implode('<br>', $errors);
    }
}

// Handle opening portal
if (isset($_GET['open']) && $_GET['open']) {
    $schedule_id = $_GET['open'];
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE exam_schedules SET status = 'open' WHERE id = ? AND exam_id = ?");
        $stmt->execute([$schedule_id, $exam_id]);
        $pdo->commit();
        notifyExamPortalStatusChange($exam_id, $schedule_id, 'open');
        $success = 'Portal opened successfully!';
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = 'Error opening portal: ' . $e->getMessage();
    }
}

// Handle closing portal
if (isset($_GET['close']) && $_GET['close']) {
    $schedule_id = $_GET['close'];
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE exam_schedules SET status = 'closed' WHERE id = ? AND exam_id = ?");
        $stmt->execute([$schedule_id, $exam_id]);
        $pdo->commit();
        notifyExamPortalStatusChange($exam_id, $schedule_id, 'closed');
        $success = 'Portal closed successfully!';
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = 'Error closing portal: ' . $e->getMessage();
    }
}

// Handle reopening portal
if (isset($_GET['reopen']) && $_GET['reopen']) {
    $schedule_id = $_GET['reopen'];
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE exam_schedules SET status = 'open' WHERE id = ? AND exam_id = ?");
        $stmt->execute([$schedule_id, $exam_id]);
        $pdo->commit();
        notifyExamPortalStatusChange($exam_id, $schedule_id, 'reopened');
        $success = 'Portal reopened successfully!';
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = 'Error reopening portal: ' . $e->getMessage();
    }
}

// Handle bulk opening portals for all classes
if (isset($_GET['bulk_open']) && $_GET['bulk_open'] === 'all') {
    try {
        $pdo->beginTransaction();
        
        $scheduleIdsStmt = $pdo->prepare("SELECT id FROM exam_schedules WHERE exam_id = ?");
        $scheduleIdsStmt->execute([$exam_id]);
        $scheduleIds = $scheduleIdsStmt->fetchAll(PDO::FETCH_COLUMN);

        // Update all schedules for this exam to 'open' status
        $stmt = $pdo->prepare("UPDATE exam_schedules SET status = 'open' WHERE exam_id = ?");
        $stmt->execute([$exam_id]);
        
        $pdo->commit();
        foreach ($scheduleIds as $scheduleId) {
            notifyExamPortalStatusChange($exam_id, (int) $scheduleId, 'open', 'bulk');
        }
        $success = 'All portals opened successfully for all classes and subjects!';
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = 'Error opening portals: ' . $e->getMessage();
    }
}

// Handle bulk closing portals for all schedules in this exam
if (isset($_GET['bulk_close']) && $_GET['bulk_close'] === 'all') {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE exam_schedules SET status = 'closed' WHERE exam_id = ?");
        $stmt->execute([$exam_id]);
        $pdo->commit();
        $success = 'All portals were closed successfully.';
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = 'Error closing all portals: ' . $e->getMessage();
    }
}

// Handle exam finalization
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalize_exam'])) {
    try {
        $summary = finalizeExamResults((int) $exam_id);
        $success = 'Exam finalized successfully. ' . $summary['student_count'] . ' students were ranked across ' . $summary['schedule_count'] . ' portals, and teachers have been notified that report forms are ready.';
    } catch (Exception $e) {
        $error = 'Error finalizing exam: ' . $e->getMessage();
    }
}

// Handle deleting schedule
if (isset($_POST['delete_schedule'])) {
    $schedule_id = $_POST['schedule_id'];
    
    try {
        $pdo->beginTransaction();
        
        // Check if marks have been entered
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM exam_marks WHERE exam_schedule_id = ?");
        $check_stmt->execute([$schedule_id]);
        $marks_count = $check_stmt->fetchColumn();
        
        if ($marks_count > 0) {
            throw new Exception("Cannot delete schedule with existing marks entries");
        }
        
        $stmt = $pdo->prepare("DELETE FROM exam_schedules WHERE id = ? AND exam_id = ?");
        $stmt->execute([$schedule_id, $exam_id]);
        
        $pdo->commit();
        $success = 'Schedule deleted successfully!';
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Error deleting schedule: ' . $e->getMessage();
    }
}

// Get schedules with stats
$stmt = $pdo->prepare("
    SELECT 
        es.*,
        c.class_name,
        s.subject_name,
        COUNT(DISTINCT em.id) as marks_entered,
        COUNT(DISTINCT CASE WHEN em.submission_status = 'submitted' THEN em.id END) as submitted_count,
        COUNT(DISTINCT st.id) as total_students,
        AVG(em.marks_obtained) as average_marks,
        MAX(em.marks_obtained) as highest_marks,
        MIN(em.marks_obtained) as lowest_marks
    FROM exam_schedules es
    LEFT JOIN classes c ON es.class_id = c.id
    LEFT JOIN subjects s ON es.subject_id = s.id
    LEFT JOIN exam_marks em ON es.id = em.exam_schedule_id
    LEFT JOIN students st ON st.class_id = es.class_id AND st.status = 'active'
    WHERE es.exam_id = ?
    GROUP BY es.id
    ORDER BY es.portal_open_date ASC
");
$stmt->execute([$exam_id]);
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get classes and subjects
$classes = $pdo->query("SELECT * FROM classes ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);
$subjects = $pdo->query("SELECT * FROM subjects ORDER BY subject_name")->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics with HYBRID approach (status column + date fallback)
$total_schedules = count($schedules);
$open_schedules = 0;
$closed_schedules = 0;
$now = new DateTime();

foreach ($schedules as $schedule) {
    $open_date = new DateTime($schedule['portal_open_date']);
    $close_date = new DateTime($schedule['portal_close_date']);
    
    // Use hybrid logic: status column takes precedence if explicitly set
    $db_status = strtolower(trim($schedule['status'] ?? ''));
    if (in_array($db_status, ['open', 'closed'])) {
        // Use explicitly set status from database
        if ($db_status === 'open') {
            $open_schedules++;
        } elseif ($db_status === 'closed') {
            $closed_schedules++;
        }
    } else {
        // Fall back to date-based calculation
        if ($now > $close_date) {
            $closed_schedules++;
        } elseif ($now >= $open_date && $now <= $close_date) {
            $open_schedules++;
        }
        // else: scheduled (before open date)
    }
}

$total_marks_entered = array_sum(array_column($schedules, 'marks_entered'));

$page_title = 'Exam Schedules - ' . SCHOOL_NAME;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
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

        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 30px;
        }

        /* Header */
        .header {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 25px 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .header h1 {
            font-size: 28px;
            color: #333;
            margin-bottom: 10px;
        }

        .header h1 i {
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-right: 10px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 15px;
            transition: all 0.3s;
        }

        .back-link:hover {
            transform: translateX(-5px);
            color: #764ba2;
        }

        /* Exam Info Card */
        .exam-info-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            color: white;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .info-icon {
            width: 50px;
            height: 50px;
            background: rgba(255,255,255,0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .info-content h3 {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 5px;
            font-weight: normal;
        }

        .info-content p {
            font-size: 18px;
            font-weight: 600;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255,255,255,0.95);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s;
            border-left: 4px solid;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }

        .stat-card.total { border-left-color: #667eea; }
        .stat-card.open { border-left-color: #43e97b; }
        .stat-card.closed { border-left-color: #f5576c; }
        .stat-card.marks { border-left-color: #9b59b6; }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }

        .stat-card.total .stat-icon { background: linear-gradient(135deg, #667eea, #764ba2); }
        .stat-card.open .stat-icon { background: linear-gradient(135deg, #43e97b, #38f9d7); }
        .stat-card.closed .stat-icon { background: linear-gradient(135deg, #f5576c, #f093fb); }
        .stat-card.marks .stat-icon { background: linear-gradient(135deg, #9b59b6, #8e44ad); }

        .stat-content h3 {
            font-size: 14px;
            color: #999;
            margin-bottom: 5px;
        }

        .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: #333;
        }

        /* Form Card */
        .form-card {
            background: rgba(255,255,255,0.95);
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .form-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .form-header h2 {
            font-size: 20px;
            color: #333;
        }

        .form-header i {
            font-size: 24px;
            background: linear-gradient(135deg, #43e97b, #38f9d7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 600;
            font-size: 14px;
        }

        .required {
            color: #f5576c;
            margin-left: 3px;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }

        select.form-control {
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            appearance: none;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .form-helper {
            font-size: 12px;
            color: #999;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Schedule Cards */
        .schedules-grid {
            display: grid;
            gap: 20px;
        }

        .schedule-card {
            background: rgba(255,255,255,0.95);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            transition: all 0.3s;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .schedule-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }

        .schedule-header {
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .schedule-title h3 {
            font-size: 18px;
            color: #333;
            margin-bottom: 5px;
        }

        .schedule-subtitle {
            color: #999;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Status Badges */
        .status-badge {
            padding: 8px 15px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .status-scheduled {
            background: rgba(102,126,234,0.1);
            color: #667eea;
        }

        .status-open {
            background: rgba(67,233,123,0.1);
            color: #43e97b;
        }

        .status-closed {
            background: rgba(245,87,108,0.1);
            color: #f5576c;
        }

        .status-analyzed {
            background: rgba(155,89,182,0.1);
            color: #9b59b6;
        }

        /* Schedule Stats */
        .schedule-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            padding: 20px;
            background: white;
            border-bottom: 1px solid #f0f0f0;
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 20px;
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Progress Bar */
        .progress-container {
            padding: 0 20px 15px;
        }

        .progress-bar {
            height: 8px;
            background: #f0f0f0;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 4px;
            transition: width 0.3s;
        }

        .progress-stats {
            display: flex;
            justify-content: space-between;
            margin-top: 5px;
            font-size: 12px;
            color: #999;
        }

        /* Schedule Dates */
        .schedule-dates {
            padding: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            background: #f8f9fa;
        }

        .date-item {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .date-icon {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #667eea;
        }

        .date-content {
            flex: 1;
        }

        .date-label {
            font-size: 11px;
            color: #999;
            margin-bottom: 3px;
        }

        .date-value {
            font-size: 14px;
            font-weight: 600;
            color: #333;
        }

        /* Instructions */
        .schedule-instructions {
            padding: 20px;
            background: white;
        }

        .instructions-label {
            font-size: 13px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .instructions-text {
            color: #666;
            font-size: 13px;
            line-height: 1.6;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 3px solid #667eea;
        }

        /* Actions */
        .schedule-actions {
            padding: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            border-top: 1px solid #f0f0f0;
        }

        /* Buttons */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, #43e97b, #38f9d7);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, #f5576c, #f093fb);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, #f6d365, #fda085);
            color: white;
        }

        .btn-info {
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid #667eea;
            color: #667eea;
        }

        .btn-outline:hover {
            background: #667eea;
            color: white;
        }

        .btn-sm {
            padding: 8px 15px;
            font-size: 12px;
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
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
            background: rgba(67,233,123,0.1);
            border-left: 4px solid #43e97b;
            color: #43e97b;
        }

        .alert-danger {
            background: rgba(245,87,108,0.1);
            border-left: 4px solid #f5576c;
            color: #f5576c;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: rgba(255,255,255,0.95);
            border-radius: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }

        .empty-state i {
            font-size: 64px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            color: #333;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #999;
            margin-bottom: 20px;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
            
            .header {
                padding: 20px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .schedule-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .schedule-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* Timeline Indicator */
        .timeline-indicator {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-top: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .timeline-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .timeline-dot.scheduled { background: #667eea; }
        .timeline-dot.open { background: #43e97b; }
        .timeline-dot.closed { background: #f5576c; }

        .timeline-label {
            font-size: 12px;
            color: #666;
        }

        /* Floating Action Button */
        .fab {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            box-shadow: 0 5px 20px rgba(102,126,234,0.4);
            cursor: pointer;
            transition: all 0.3s;
            z-index: 100;
            border: none;
        }

        .fab:hover {
            transform: scale(1.1) rotate(90deg);
        }

        /* Countdown Timer */
        .countdown-timer {
            padding: 15px 20px;
            background: linear-gradient(135deg, rgba(67,233,123,0.1), rgba(56,249,215,0.1));
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .countdown-content {
            flex: 1;
        }

        .countdown-label {
            margin: 0;
            font-size: 12px;
            color: #999;
            font-weight: 600;
            text-transform: uppercase;
        }

        .countdown-value {
            margin: 5px 0 0 0;
            font-size: 16px;
            font-weight: 700;
            color: #43e97b;
        }

        .countdown-icon {
            font-size: 32px;
            color: #43e97b;
            opacity: 0.3;
            margin-left: 20px;
        }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <a href="exams.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Exams
            </a>
            <h1>
                <i class="fas fa-calendar-alt"></i>
                Exam Schedules
            </h1>
        </div>

        <!-- Exam Info Card -->
        <div class="exam-info-card">
            <div class="info-item">
                <div class="info-icon">
                    <i class="fas fa-pencil-alt"></i>
                </div>
                <div class="info-content">
                    <h3>Exam Name</h3>
                    <p><?php echo htmlspecialchars($exam['exam_name']); ?></p>
                </div>
            </div>
            <div class="info-item">
                <div class="info-icon">
                    <i class="fas fa-calendar"></i>
                </div>
                <div class="info-content">
                    <h3>Academic Year</h3>
                    <p><?php echo htmlspecialchars($exam['academic_year']); ?> - <?php echo htmlspecialchars($exam['term']); ?></p>
                </div>
            </div>
            <div class="info-item">
                <div class="info-icon">
                    <i class="fas fa-star"></i>
                </div>
                <div class="info-content">
                    <h3>Marks</h3>
                    <p><?php echo $exam['total_marks']; ?> Total / <?php echo $exam['passing_marks']; ?> Passing</p>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-content">
                    <h3>Total Schedules</h3>
                    <div class="stat-number"><?php echo $total_schedules; ?></div>
                </div>
            </div>
            <div class="stat-card open">
                <div class="stat-icon">
                    <i class="fas fa-door-open"></i>
                </div>
                <div class="stat-content">
                    <h3>Open Portals</h3>
                    <div class="stat-number"><?php echo $open_schedules; ?></div>
                </div>
            </div>
            <div class="stat-card closed">
                <div class="stat-icon">
                    <i class="fas fa-door-closed"></i>
                </div>
                <div class="stat-content">
                    <h3>Closed Portals</h3>
                    <div class="stat-number"><?php echo $closed_schedules; ?></div>
                </div>
            </div>
            <div class="stat-card marks">
                <div class="stat-icon">
                    <i class="fas fa-pencil-alt"></i>
                </div>
                <div class="stat-content">
                    <h3>Marks Entered</h3>
                    <div class="stat-number"><?php echo $total_marks_entered; ?></div>
                </div>
            </div>
        </div>

        <!-- Bulk Actions -->
        <?php if ($total_schedules > 0): ?>
        <div style="background: rgba(255,255,255,0.95); border-radius: 15px; padding: 20px; margin-bottom: 30px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); border: 2px solid rgba(67,233,123,0.2);">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap; margin-bottom: 20px;">
                <div>
                    <h3 style="margin: 0 0 5px 0; color: #333; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-rocket" style="color: #43e97b;"></i>
                        Exam Lifecycle Actions
                    </h3>
                    <p style="margin: 0; color: #999; font-size: 14px;">All active class-subject portals were generated automatically. Set one window, open in bulk, close in bulk, then finalize the full exam.</p>
                </div>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <?php if ($open_schedules < $total_schedules): ?>
                    <button class="btn btn-success" onclick="confirmBulkOpenAll()" style="padding: 12px 24px;">
                        <i class="fas fa-door-open"></i> Open All Portals
                    </button>
                    <?php endif; ?>
                    <?php if ($open_schedules > 0): ?>
                    <button class="btn btn-danger" onclick="confirmBulkCloseAll()" style="padding: 12px 24px;">
                        <i class="fas fa-door-closed"></i> Close All Portals
                    </button>
                    <?php endif; ?>
                    <button class="btn btn-primary" onclick="confirmFinalizeExam()" style="padding: 12px 24px;">
                        <i class="fas fa-chart-line"></i> Finalize Exam
                    </button>
                </div>
            </div>

            <form method="POST" id="bulkDatesForm">
                <input type="hidden" name="bulk_set_dates" value="1">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label"><span class="required">*</span> Portal Opens For All</label>
                        <input type="datetime-local" class="form-control" name="bulk_portal_open_date" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><span class="required">*</span> Portal Closes For All</label>
                        <input type="datetime-local" class="form-control" name="bulk_portal_close_date" required>
                    </div>
                    <div class="form-group full-width">
                        <label class="form-label">Shared Instructions For All Teachers</label>
                        <textarea class="form-control" name="bulk_instructions" rows="3" placeholder="Optional instructions that should apply to every generated portal in this exam..."></textarea>
                    </div>
                </div>
                <div style="display: flex; justify-content: flex-end;">
                    <button type="submit" class="btn btn-outline">
                        <i class="fas fa-calendar-check"></i> Apply Dates To All Portals
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Alerts -->
        <?php if ($success): ?>
        <div class="alert alert-success" id="successAlert">
            <i class="fas fa-check-circle"></i>
            <span><?php echo $success; ?></span>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="alert alert-danger" id="errorAlert">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo $error; ?></span>
        </div>
        <?php endif; ?>

        <!-- Create Schedule Card -->
        <div class="form-card" id="createForm">
            <div class="form-header">
                <i class="fas fa-plus-circle"></i>
                <h2>Add One Extra Schedule</h2>
            </div>
            
            <form method="POST" id="scheduleForm">
                <input type="hidden" name="add_schedule" value="1">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">
                            <span class="required">*</span> Class
                        </label>
                        <select class="form-control" name="class_id" required>
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Subject (Optional)</label>
                        <select class="form-control" name="subject_id">
                            <option value="">Select Subject</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>">
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <span class="required">*</span> Portal Opens
                        </label>
                        <input type="datetime-local" class="form-control" name="portal_open_date" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <span class="required">*</span> Portal Closes
                        </label>
                        <input type="datetime-local" class="form-control" name="portal_close_date" required>
                        <div class="form-helper">
                            <i class="fas fa-info-circle"></i>
                            Must be at least 7 days after opening
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Instructions for Teachers</label>
                        <textarea class="form-control" name="instructions" rows="4" 
                                  placeholder="Enter any special instructions, guidelines, or notes for teachers..."></textarea>
                    </div>
                </div>
                
                <div style="display: flex; gap: 15px; justify-content: flex-end;">
                    <button type="reset" class="btn btn-outline">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Schedule
                    </button>
                </div>
            </form>
        </div>

        <!-- Schedules List -->
        <div class="form-card">
            <div class="form-header">
                <i class="fas fa-list"></i>
                <h2>Exam Schedules (<?php echo count($schedules); ?>)</h2>
            </div>
            
            <?php if (empty($schedules)): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <h3>No Schedules Created</h3>
                    <p>Every active class-subject portal is usually generated automatically when the exam is created. Use the form above only if you need an extra manual schedule.</p>
                    <button class="btn btn-primary" onclick="document.getElementById('createForm').scrollIntoView({behavior: 'smooth'})">
                        <i class="fas fa-plus"></i> Create Schedule
                    </button>
                </div>
            <?php else: ?>
                <div class="schedules-grid">
                    <?php foreach ($schedules as $schedule): 
                        $marks_percentage = $schedule['total_students'] > 0 
                            ? round(($schedule['marks_entered'] / $schedule['total_students']) * 100, 1) 
                            : 0;
                        
                        // Calculate portal status with HYBRID approach:
                        // 1. If status column is explicitly set to 'open' or 'closed', use that (allows manual control)
                        // 2. Otherwise calculate from dates as fallback
                        $now = new DateTime();
                        $open = new DateTime($schedule['portal_open_date']);
                        $close = new DateTime($schedule['portal_close_date']);
                        
                        // Determine date-based status
                        if ($now > $close) {
                            $date_based_status = 'closed';
                        } elseif ($now >= $open && $now <= $close) {
                            $date_based_status = 'open';
                        } else {
                            $date_based_status = 'scheduled';
                        }
                        
                        // Use hybrid logic: status column takes precedence if explicitly set
                        $db_status = strtolower(trim($schedule['status'] ?? ''));
                        if (in_array($db_status, ['open', 'closed'])) {
                            // Use explicitly set status from database (allows manual override)
                            $display_status = $db_status;
                        } else {
                            // Fall back to date-based calculation
                            $display_status = $date_based_status;
                        }
                        
                        // Calculate time remaining for countdown
                        $time_remaining = '';
                        if ($display_status === 'open') {
                            $interval = $close->diff($now);
                            $time_remaining = $interval->format('%d days, %h hours, %i mins');
                        } elseif ($display_status === 'scheduled') {
                            $interval = $open->diff($now);
                            $time_remaining = $interval->format('%d days, %h hours, %i mins');
                        }
                    ?>
                    <div class="schedule-card">
                        <div class="schedule-header">
                            <div class="schedule-title">
                                <h3>
                                    <?php echo htmlspecialchars($schedule['class_name']); ?>
                                    <?php if ($schedule['subject_name']): ?>
                                        <span style="color: #999; font-size: 14px;">• <?php echo htmlspecialchars($schedule['subject_name']); ?></span>
                                    <?php endif; ?>
                                </h3>
                                <div class="schedule-subtitle">
                                    <i class="fas fa-users"></i> <?php echo $schedule['total_students'] ?? 0; ?> Students
                                </div>
                            </div>
                            <span class="status-badge status-<?php echo $display_status; ?>">
                                <i class="fas fa-<?php 
                                    echo $display_status == 'open' ? 'door-open' : 
                                        ($display_status == 'closed' ? 'door-closed' : 'clock'); 
                                ?>"></i>
                                <?php echo ucfirst($display_status); ?>
                            </span>
                        </div>

                        <?php if ($display_status === 'open' && !empty($time_remaining)): ?>
                        <div style="padding: 15px 20px; background: linear-gradient(135deg, rgba(67,233,123,0.1), rgba(56,249,215,0.1)); border-bottom: 1px solid #f0f0f0;">
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <div>
                                    <p style="margin: 0; font-size: 12px; color: #999; font-weight: 600; text-transform: uppercase;">Portal Closes In</p>
                                    <p style="margin: 5px 0 0 0; font-size: 16px; color: #43e97b; font-weight: 700;" class="countdown-<?php echo $schedule['id']; ?>">
                                        <?php echo $time_remaining; ?>
                                    </p>
                                </div>
                                <i class="fas fa-hourglass-end" style="font-size: 32px; color: #43e97b; opacity: 0.3;"></i>
                            </div>
                        </div>
                        <script>
                            (function() {
                                const scheduleId = <?php echo $schedule['id']; ?>;
                                const closeTime = new Date('<?php echo date('c', strtotime($schedule['portal_close_date'])); ?>').getTime();
                                
                                function updateCountdown() {
                                    const now = new Date().getTime();
                                    const remaining = closeTime - now;
                                    
                                    if (remaining <= 0) {
                                        document.querySelector('.countdown-' + scheduleId).textContent = 'Portal Closed';
                                        return;
                                    }
                                    
                                    const days = Math.floor(remaining / (1000 * 60 * 60 * 24));
                                    const hours = Math.floor((remaining % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                                    const mins = Math.floor((remaining % (1000 * 60 * 60)) / (1000 * 60));
                                    const secs = Math.floor((remaining % (1000 * 60)) / 1000);
                                    
                                    const timeStr = days > 0 
                                        ? `${days} day${days > 1 ? 's' : ''}, ${hours}h ${mins}m`
                                        : `${hours}h ${mins}m ${secs}s`;
                                    
                                    document.querySelector('.countdown-' + scheduleId).textContent = timeStr;
                                }
                                
                                updateCountdown();
                                setInterval(updateCountdown, 1000);
                            })();
                        </script>
                        <?php elseif ($display_status === 'scheduled' && !empty($time_remaining)): ?>
                        <div style="padding: 15px 20px; background: linear-gradient(135deg, rgba(102,126,234,0.1), rgba(118,75,162,0.1)); border-bottom: 1px solid #f0f0f0;">
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <div>
                                    <p style="margin: 0; font-size: 12px; color: #999; font-weight: 600; text-transform: uppercase;">Portal Opens In</p>
                                    <p style="margin: 5px 0 0 0; font-size: 16px; color: #667eea; font-weight: 700;" class="countdown-<?php echo $schedule['id']; ?>">
                                        <?php echo $time_remaining; ?>
                                    </p>
                                </div>
                                <i class="fas fa-hourglass-start" style="font-size: 32px; color: #667eea; opacity: 0.3;"></i>
                            </div>
                        </div>
                        <script>
                            (function() {
                                const scheduleId = <?php echo $schedule['id']; ?>;
                                const openTime = new Date('<?php echo date('c', strtotime($schedule['portal_open_date'])); ?>').getTime();
                                
                                function updateCountdown() {
                                    const now = new Date().getTime();
                                    const remaining = openTime - now;
                                    
                                    if (remaining <= 0) {
                                        document.querySelector('.countdown-' + scheduleId).textContent = 'Portal Opened';
                                        return;
                                    }
                                    
                                    const days = Math.floor(remaining / (1000 * 60 * 60 * 24));
                                    const hours = Math.floor((remaining % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                                    const mins = Math.floor((remaining % (1000 * 60 * 60)) / (1000 * 60));
                                    const secs = Math.floor((remaining % (1000 * 60)) / 1000);
                                    
                                    const timeStr = days > 0 
                                        ? `${days} day${days > 1 ? 's' : ''}, ${hours}h ${mins}m`
                                        : `${hours}h ${mins}m ${secs}s`;
                                    
                                    document.querySelector('.countdown-' + scheduleId).textContent = timeStr;
                                }
                                
                                updateCountdown();
                                setInterval(updateCountdown, 1000);
                            })();
                        </script>
                        <?php endif; ?>

                        <div class="schedule-stats">
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $schedule['marks_entered']; ?></div>
                                <div class="stat-label">Marks Entered</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $schedule['submitted_count']; ?></div>
                                <div class="stat-label">Submitted</div>
                            </div>
                            <?php if ($schedule['average_marks']): ?>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo round($schedule['average_marks'], 1); ?></div>
                                <div class="stat-label">Average</div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($marks_percentage > 0): ?>
                        <div class="progress-container">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $marks_percentage; ?>%;"></div>
                            </div>
                            <div class="progress-stats">
                                <span><?php echo $schedule['marks_entered']; ?> / <?php echo $schedule['total_students']; ?> students</span>
                                <span><?php echo $marks_percentage; ?>% complete</span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="schedule-dates">
                            <div class="date-item">
                                <div class="date-icon">
                                    <i class="fas fa-calendar-plus"></i>
                                </div>
                                <div class="date-content">
                                    <div class="date-label">Opens</div>
                                    <div class="date-value"><?php echo date('M d, Y H:i', strtotime($schedule['portal_open_date'])); ?></div>
                                </div>
                            </div>
                            <div class="date-item">
                                <div class="date-icon">
                                    <i class="fas fa-calendar-times"></i>
                                </div>
                                <div class="date-content">
                                    <div class="date-label">Closes</div>
                                    <div class="date-value"><?php echo date('M d, Y H:i', strtotime($schedule['portal_close_date'])); ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Timeline Indicator -->
                        <div class="timeline-indicator">
                            <span class="timeline-dot scheduled"></span>
                            <span class="timeline-label">Scheduled</span>
                            <i class="fas fa-arrow-right" style="color: #999; font-size: 12px;"></i>
                            <span class="timeline-dot open"></span>
                            <span class="timeline-label">Open</span>
                            <i class="fas fa-arrow-right" style="color: #999; font-size: 12px;"></i>
                            <span class="timeline-dot closed"></span>
                            <span class="timeline-label">Closed</span>
                        </div>

                        <?php if (!empty($schedule['instructions'])): ?>
                        <div class="schedule-instructions">
                            <div class="instructions-label">
                                <i class="fas fa-info-circle" style="color: #667eea;"></i>
                                Instructions
                            </div>
                            <div class="instructions-text">
                                <?php echo nl2br(htmlspecialchars($schedule['instructions'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="schedule-actions">
                            <?php if ($display_status === 'scheduled'): ?>
                                <button class="btn btn-success btn-sm" onclick="confirmOpenPortal(<?php echo $schedule['id']; ?>)">
                                    <i class="fas fa-door-open"></i> Open Portal Now
                                </button>
                            <?php endif; ?>
                            
                            <?php if ($display_status === 'open'): ?>
                                <button class="btn btn-danger btn-sm" onclick="confirmClosePortal(<?php echo $schedule['id']; ?>)">
                                    <i class="fas fa-door-closed"></i> Close Portal Early
                                </button>
                            <?php endif; ?>
                            
                            <?php if ($display_status === 'closed'): ?>
                                <button class="btn btn-warning btn-sm" onclick="confirmReopenPortal(<?php echo $schedule['id']; ?>)">
                                    <i class="fas fa-door-open"></i> Reopen Portal
                                </button>
                            <?php endif; ?>
                            
                            <?php if ($display_status === 'closed'): ?>
                                <a href="exam_analysis.php?schedule_id=<?php echo $schedule['id']; ?>" 
                                   class="btn btn-primary btn-sm">
                                    <i class="fas fa-chart-bar"></i> Analyze Results
                                </a>
                            <?php endif; ?>
                            
                            <a href="marks_entry.php?schedule_id=<?php echo $schedule['id']; ?>" 
                               class="btn btn-info btn-sm">
                                <i class="fas fa-edit"></i> Manage Marks
                            </a>
                            
                            <?php if ($schedule['marks_entered'] == 0 && $display_status === 'scheduled'): ?>
                                <button class="btn btn-outline btn-sm" style="color: #f5576c;" 
                                        onclick="confirmDelete(<?php echo $schedule['id']; ?>)">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Floating Action Button -->
    <button class="fab" onclick="document.getElementById('createForm').scrollIntoView({behavior: 'smooth'})">
        <i class="fas fa-plus"></i>
    </button>

    <script>
        // Confirm bulk open all portals
        function confirmBulkOpenAll() {
            Swal.fire({
                title: 'Open All Portals?',
                html: `
                    <div style="text-align: left;">
                        <p><i class="fas fa-exclamation-circle" style="color: #667eea;" style="margin-right: 8px;"></i> <strong>This will open the portal for:</strong></p>
                        <ul style="margin-top: 10px; color: #666; font-size: 15px;">
                            <li>✓ <strong>All classes</strong> in this exam</li>
                            <li>✓ <strong>All subjects</strong> (if subject-specific)</li>
                            <li>✓ Teachers in those classes will immediately be able to enter marks</li>
                            <li>✓ Portal will remain open until the scheduled close date</li>
                        </ul>
                        <p style="margin-top: 15px; color: #f5576c;"><i class="fas fa-info-circle"></i> <strong>Note:</strong> Individual portals can still be closed separately if needed.</p>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#43e97b',
                cancelButtonColor: '#999',
                confirmButtonText: 'Yes, open all portals',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `?exam_id=<?php echo $exam_id; ?>&bulk_open=all`;
                }
            });
        }

        function confirmBulkCloseAll() {
            Swal.fire({
                title: 'Close All Portals?',
                html: `
                    <div style="text-align: left;">
                        <p><strong>This will immediately close every portal in this exam.</strong></p>
                        <ul style="margin-top: 10px; color: #666; font-size: 15px;">
                            <li>✗ Teachers will stop entering marks</li>
                            <li>✓ The exam becomes ready for final analysis</li>
                            <li>✓ You can still reopen an individual portal later if needed</li>
                        </ul>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f5576c',
                cancelButtonColor: '#999',
                confirmButtonText: 'Yes, close all portals'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `?exam_id=<?php echo $exam_id; ?>&bulk_close=all`;
                }
            });
        }

        function confirmFinalizeExam() {
            Swal.fire({
                title: 'Finalize This Exam?',
                html: `
                    <div style="text-align: left;">
                        <p><strong>This will finalize the whole exam lifecycle.</strong></p>
                        <ul style="margin-top: 10px; color: #666; font-size: 15px;">
                            <li>✓ Close any remaining open portals</li>
                            <li>✓ Analyse every class-subject paper</li>
                            <li>✓ Rank students and compute means</li>
                            <li>✓ Notify teachers that report forms are ready</li>
                        </ul>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#667eea',
                cancelButtonColor: '#999',
                confirmButtonText: 'Yes, finalize exam'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = '<input type="hidden" name="finalize_exam" value="1">';
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Confirm open portal
        function confirmOpenPortal(scheduleId) {
            Swal.fire({
                title: 'Open Portal?',
                html: `
                    <div style="text-align: left;">
                        <p><i class="fas fa-info-circle" style="color: #667eea;"></i> Teachers will be able to:</p>
                        <ul style="margin-top: 10px; color: #666;">
                            <li>✓ Enter marks for students</li>
                            <li>✓ View student lists</li>
                            <li>✓ Submit marks for approval</li>
                        </ul>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#43e97b',
                cancelButtonColor: '#999',
                confirmButtonText: 'Yes, open portal',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `?exam_id=<?php echo $exam_id; ?>&open=${scheduleId}`;
                }
            });
        }

        // Confirm close portal
        function confirmClosePortal(scheduleId) {
            Swal.fire({
                title: 'Close Portal?',
                html: `
                    <div style="text-align: left;">
                        <p><i class="fas fa-exclamation-triangle" style="color: #f5576c;"></i> After closing:</p>
                        <ul style="margin-top: 10px; color: #666;">
                            <li>✗ Teachers cannot enter new marks</li>
                            <li>✗ Existing marks become read-only</li>
                            <li>✓ Analysis will be available</li>
                        </ul>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f5576c',
                cancelButtonColor: '#999',
                confirmButtonText: 'Yes, close portal',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `?exam_id=<?php echo $exam_id; ?>&close=${scheduleId}`;
                }
            });
        }
        
        // Confirm reopen portal
        function confirmReopenPortal(scheduleId) {
            Swal.fire({
                title: 'Reopen Portal?',
                text: 'Teachers will be able to enter or modify marks again.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#f6d365',
                cancelButtonColor: '#999',
                confirmButtonText: 'Yes, reopen portal',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `?exam_id=<?php echo $exam_id; ?>&reopen=${scheduleId}`;
                }
            });
        }

        // Confirm delete
        function confirmDelete(scheduleId) {
            Swal.fire({
                title: 'Delete Schedule?',
                html: `
                    <div style="text-align: left;">
                        <p><i class="fas fa-exclamation-triangle" style="color: #f5576c;"></i> This action:</p>
                        <ul style="margin-top: 10px; color: #666;">
                            <li>✗ Cannot be undone</li>
                            <li>✗ Will permanently remove this schedule</li>
                            <li>✓ Only possible when no marks are entered</li>
                        </ul>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f5576c',
                cancelButtonColor: '#999',
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Create and submit form
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="schedule_id" value="${scheduleId}">
                        <input type="hidden" name="delete_schedule" value="1">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Form validation
        document.getElementById('scheduleForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const openDate = new Date(this.portal_open_date.value);
            const closeDate = new Date(this.portal_close_date.value);
            
            if (closeDate <= openDate) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Dates',
                    text: 'Portal closing date must be after opening date'
                });
                return false;
            }
            
            // Check if difference is at least 7 days
            const daysDifference = Math.ceil((closeDate - openDate) / (1000 * 60 * 60 * 24));
            if (daysDifference < 7) {
                Swal.fire({
                    icon: 'error',
                    title: 'Date Range Too Short',
                    text: `Portal must remain open for at least 7 days. Currently set for ${daysDifference} days.`
                });
                return false;
            }
            
            // Confirm creation
            Swal.fire({
                title: 'Create Schedule?',
                text: 'Please verify all details before creating the schedule.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#667eea',
                cancelButtonColor: '#999',
                confirmButtonText: 'Yes, create it',
                cancelButtonText: 'Review'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Creating Schedule...',
                        text: 'Please wait while we create the schedule.',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                            e.target.submit();
                        }
                    });
                }
            });
        });

        const bulkDatesForm = document.getElementById('bulkDatesForm');
        if (bulkDatesForm) {
            bulkDatesForm.addEventListener('submit', function(e) {
                e.preventDefault();

                const openDate = new Date(this.bulk_portal_open_date.value);
                const closeDate = new Date(this.bulk_portal_close_date.value);

                if (closeDate <= openDate) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Invalid Dates',
                        text: 'Portal closing date must be after opening date'
                    });
                    return false;
                }

                Swal.fire({
                    title: 'Apply Dates To All Portals?',
                    text: 'Every generated class-subject portal in this exam will use the same portal window.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#667eea',
                    cancelButtonColor: '#999',
                    confirmButtonText: 'Yes, apply dates'
                }).then((result) => {
                    if (result.isConfirmed) {
                        e.target.submit();
                    }
                });
            });
        }

        // Auto-dismiss alerts
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Set minimum datetime to now
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const minDateTime = `${year}-${month}-${day}T${hours}:${minutes}`;
        
        document.querySelectorAll('input[type="datetime-local"]').forEach(input => {
            input.min = minDateTime;
        });

        // Smooth scroll to form when URL has #create
        if (window.location.hash === '#create') {
            document.getElementById('createForm').scrollIntoView({ behavior: 'smooth' });
        }

        // Live preview of date difference
        document.querySelectorAll('input[type="datetime-local"]').forEach(input => {
            input.addEventListener('change', function() {
                const open = document.querySelector('input[name="portal_open_date"]').value;
                const close = document.querySelector('input[name="portal_close_date"]').value;
                
                if (open && close) {
                    const openDate = new Date(open);
                    const closeDate = new Date(close);
                    const days = Math.ceil((closeDate - openDate) / (1000 * 60 * 60 * 24));
                    
                    const helper = document.querySelector('.form-helper');
                    if (days < 7) {
                        helper.innerHTML = `<i class="fas fa-exclamation-triangle" style="color: #f5576c;"></i> Only ${days} days - minimum 7 days required`;
                        helper.style.color = '#f5576c';
                    } else {
                        helper.innerHTML = `<i class="fas fa-check-circle" style="color: #43e97b;"></i> ${days} days - valid duration`;
                        helper.style.color = '#43e97b';
                    }
                }
            });
        });
    </script>
</body>
</html>
