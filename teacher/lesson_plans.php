<?php
include '../config.php';
checkAuth();

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'teacher';

// Get teacher information
$teacher_stmt = $pdo->prepare("SELECT * FROM teachers WHERE user_id = ?");
$teacher_stmt->execute([$user_id]);
$teacher = $teacher_stmt->fetch();

// Check if user is a teacher
if (!$teacher) {
    $_SESSION['error'] = "Access denied. Teacher profile not found.";
    header("Location: ../dashboard.php");
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Add new lesson plan
    if (isset($_POST['add_lesson_plan'])) {
        $subject_id = $_POST['subject_id'];
        $class_id = $_POST['class_id'];
        $topic = $_POST['topic'];
        $sub_topic = $_POST['sub_topic'];
        $objectives = $_POST['objectives'];
        $materials = $_POST['materials'];
        $activities = $_POST['activities'];
        $assessment = $_POST['assessment'];
        $homework = $_POST['homework'];
        $duration = $_POST['duration'];
        $date_taught = $_POST['date_taught'] ?? date('Y-m-d');
        $status = $_POST['status'] ?? 'draft';
        
        $stmt = $pdo->prepare("
            INSERT INTO lesson_plans 
            (teacher_id, subject_id, class_id, topic, sub_topic, objectives, materials, 
             activities, assessment, homework, duration, date_taught, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        if ($stmt->execute([
            $teacher['id'], $subject_id, $class_id, $topic, $sub_topic, $objectives,
            $materials, $activities, $assessment, $homework, $duration, $date_taught, $status
        ])) {
            $lesson_plan_id = $pdo->lastInsertId();
            $_SESSION['success'] = "Lesson plan created successfully!";
            
            // Upload attachments if any
            if (!empty($_FILES['attachments']['name'][0])) {
                uploadAttachments($lesson_plan_id, $_FILES['attachments']);
            }
            
            header("Location: lesson_plans.php");
            exit;
        } else {
            $_SESSION['error'] = "Failed to create lesson plan. Please try again.";
        }
    }
    
    // Update lesson plan
    if (isset($_POST['update_lesson_plan'])) {
        $lesson_plan_id = $_POST['lesson_plan_id'];
        $subject_id = $_POST['subject_id'];
        $class_id = $_POST['class_id'];
        $topic = $_POST['topic'];
        $sub_topic = $_POST['sub_topic'];
        $objectives = $_POST['objectives'];
        $materials = $_POST['materials'];
        $activities = $_POST['activities'];
        $assessment = $_POST['assessment'];
        $homework = $_POST['homework'];
        $duration = $_POST['duration'];
        $date_taught = $_POST['date_taught'];
        $status = $_POST['status'];
        
        $stmt = $pdo->prepare("
            UPDATE lesson_plans SET 
            subject_id = ?, class_id = ?, topic = ?, sub_topic = ?, objectives = ?, 
            materials = ?, activities = ?, assessment = ?, homework = ?, duration = ?, 
            date_taught = ?, status = ?, updated_at = NOW()
            WHERE id = ? AND teacher_id = ?
        ");
        
        if ($stmt->execute([
            $subject_id, $class_id, $topic, $sub_topic, $objectives, $materials,
            $activities, $assessment, $homework, $duration, $date_taught, $status,
            $lesson_plan_id, $teacher['id']
        ])) {
            $_SESSION['success'] = "Lesson plan updated successfully!";
            
            // Upload new attachments if any
            if (!empty($_FILES['attachments']['name'][0])) {
                uploadAttachments($lesson_plan_id, $_FILES['attachments']);
            }
            
            header("Location: lesson_plans.php");
            exit;
        } else {
            $_SESSION['error'] = "Failed to update lesson plan. Please try again.";
        }
    }
    
    // Delete lesson plan
    if (isset($_POST['delete_lesson_plan'])) {
        $lesson_plan_id = $_POST['lesson_plan_id'];
        
        // Delete attachments first
        $attachments_stmt = $pdo->prepare("SELECT * FROM lesson_plan_attachments WHERE lesson_plan_id = ?");
        $attachments_stmt->execute([$lesson_plan_id]);
        $attachments = $attachments_stmt->fetchAll();
        
        foreach ($attachments as $attachment) {
            $file_path = "../uploads/lesson_plans/" . $attachment['file_name'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
        
        // Delete attachment records
        $delete_attachments = $pdo->prepare("DELETE FROM lesson_plan_attachments WHERE lesson_plan_id = ?");
        $delete_attachments->execute([$lesson_plan_id]);
        
        // Delete lesson plan
        $stmt = $pdo->prepare("DELETE FROM lesson_plans WHERE id = ? AND teacher_id = ?");
        if ($stmt->execute([$lesson_plan_id, $teacher['id']])) {
            $_SESSION['success'] = "Lesson plan deleted successfully!";
        } else {
            $_SESSION['error'] = "Failed to delete lesson plan. Please try again.";
        }
        
        header("Location: lesson_plans.php");
        exit;
    }
    
    // Delete attachment
    if (isset($_POST['delete_attachment'])) {
        $attachment_id = $_POST['attachment_id'];
        $lesson_plan_id = $_POST['lesson_plan_id'];
        
        // Get attachment info
        $stmt = $pdo->prepare("SELECT * FROM lesson_plan_attachments WHERE id = ? AND lesson_plan_id = ?");
        $stmt->execute([$attachment_id, $lesson_plan_id]);
        $attachment = $stmt->fetch();
        
        if ($attachment) {
            // Delete file
            $file_path = "../uploads/lesson_plans/" . $attachment['file_name'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            
            // Delete record
            $delete_stmt = $pdo->prepare("DELETE FROM lesson_plan_attachments WHERE id = ?");
            if ($delete_stmt->execute([$attachment_id])) {
                $_SESSION['success'] = "Attachment deleted successfully!";
            } else {
                $_SESSION['error'] = "Failed to delete attachment.";
            }
        }
        
        header("Location: lesson_plans.php?edit=" . $lesson_plan_id);
        exit;
    }
    
    // Copy lesson plan
    if (isset($_POST['copy_lesson_plan'])) {
        $lesson_plan_id = $_POST['lesson_plan_id'];
        
        // Get original lesson plan
        $stmt = $pdo->prepare("SELECT * FROM lesson_plans WHERE id = ?");
        $stmt->execute([$lesson_plan_id]);
        $original = $stmt->fetch();
        
        if ($original) {
            // Create copy with modified title
            $new_topic = $original['topic'] . " (Copy)";
            
            $copy_stmt = $pdo->prepare("
                INSERT INTO lesson_plans 
                (teacher_id, subject_id, class_id, topic, sub_topic, objectives, materials, 
                 activities, assessment, homework, duration, date_taught, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            if ($copy_stmt->execute([
                $teacher['id'], $original['subject_id'], $original['class_id'], $new_topic,
                $original['sub_topic'], $original['objectives'], $original['materials'],
                $original['activities'], $original['assessment'], $original['homework'],
                $original['duration'], date('Y-m-d'), 'draft'
            ])) {
                $new_id = $pdo->lastInsertId();
                $_SESSION['success'] = "Lesson plan copied successfully!";
                
                // Redirect to edit the copied lesson plan
                header("Location: lesson_plans.php?edit=" . $new_id);
                exit;
            }
        }
    }
}

// Function to upload attachments
function uploadAttachments($lesson_plan_id, $files) {
    global $pdo;
    
    $upload_dir = "../uploads/lesson_plans/";
    
    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] == 0) {
            $file_name = time() . '_' . basename($files['name'][$i]);
            $file_tmp = $files['tmp_name'][$i];
            $file_size = $files['size'][$i];
            $file_type = $files['type'][$i];
            
            // Validate file type
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 
                            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
            
            if (in_array($file_type, $allowed_types) && $file_size <= 10 * 1024 * 1024) { // 10MB max
                if (move_uploaded_file($file_tmp, $upload_dir . $file_name)) {
                    // Save to database
                    $stmt = $pdo->prepare("
                        INSERT INTO lesson_plan_attachments (lesson_plan_id, file_name, file_type, file_size, uploaded_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$lesson_plan_id, $file_name, $file_type, $file_size]);
                }
            }
        }
    }
}

// Get filter parameters
$filter_subject = $_GET['subject_id'] ?? '';
$filter_class = $_GET['class_id'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';
$view = $_GET['view'] ?? 'list';
$edit_id = $_GET['edit'] ?? null;
$view_id = $_GET['view_id'] ?? null;

// Get teacher's subjects and classes
$teacher_subjects = [];
$teacher_classes = [];

// Get subjects taught by this teacher
$subjects_stmt = $pdo->prepare("
    SELECT DISTINCT s.* 
    FROM subjects s 
    LEFT JOIN timetable_lessons tl ON s.id = tl.subject_id 
    WHERE tl.teacher_id = ? OR s.id IN (SELECT subject_id FROM lesson_plans WHERE teacher_id = ?)
    ORDER BY s.subject_name
");
$subjects_stmt->execute([$teacher['id'], $teacher['id']]);
$teacher_subjects = $subjects_stmt->fetchAll();

// Get classes taught by this teacher
$classes_stmt = $pdo->prepare("
    SELECT DISTINCT c.* 
    FROM classes c 
    LEFT JOIN timetable_lessons tl ON c.id = tl.class_id 
    WHERE tl.teacher_id = ? OR c.id IN (SELECT class_id FROM lesson_plans WHERE teacher_id = ?)
    ORDER BY c.class_name
");
$classes_stmt->execute([$teacher['id'], $teacher['id']]);
$teacher_classes = $classes_stmt->fetchAll();

// If still empty, get all subjects/classes
if (empty($teacher_subjects)) {
    $teacher_subjects = $pdo->query("SELECT * FROM subjects ORDER BY subject_name")->fetchAll();
}

if (empty($teacher_classes)) {
    $teacher_classes = $pdo->query("SELECT * FROM classes ORDER BY class_name")->fetchAll();
}

// Build query for lesson plans
$query = "
    SELECT lp.*, s.subject_name, c.class_name, t.full_name as teacher_name,
           (SELECT COUNT(*) FROM lesson_plan_attachments WHERE lesson_plan_id = lp.id) as attachment_count
    FROM lesson_plans lp
    LEFT JOIN subjects s ON lp.subject_id = s.id
    LEFT JOIN classes c ON lp.class_id = c.id
    LEFT JOIN teachers t ON lp.teacher_id = t.id
    WHERE lp.teacher_id = ?
";

$params = [$teacher['id']];

// Apply filters
if ($filter_subject) {
    $query .= " AND lp.subject_id = ?";
    $params[] = $filter_subject;
}

if ($filter_class) {
    $query .= " AND lp.class_id = ?";
    $params[] = $filter_class;
}

if ($filter_status) {
    $query .= " AND lp.status = ?";
    $params[] = $filter_status;
}

if ($filter_date_from) {
    $query .= " AND lp.date_taught >= ?";
    $params[] = $filter_date_from;
}

if ($filter_date_to) {
    $query .= " AND lp.date_taught <= ?";
    $params[] = $filter_date_to;
}

if ($search) {
    $query .= " AND (lp.topic LIKE ? OR lp.sub_topic LIKE ? OR lp.objectives LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

// Order by
$query .= " ORDER BY lp.date_taught DESC, lp.created_at DESC";

// Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$lesson_plans = $stmt->fetchAll();

// Get shared lesson plans
$shared_query = "
    SELECT lp.*, s.subject_name, c.class_name, t.full_name as teacher_name
    FROM lesson_plans lp
    LEFT JOIN subjects s ON lp.subject_id = s.id
    LEFT JOIN classes c ON lp.class_id = c.id
    LEFT JOIN teachers t ON lp.teacher_id = t.id
    WHERE lp.teacher_id != ? AND lp.shared_with IS NOT NULL
    ORDER BY lp.created_at DESC
    LIMIT 10
";
$shared_stmt = $pdo->prepare($shared_query);
$shared_stmt->execute([$teacher['id']]);
$shared_plans = $shared_stmt->fetchAll();

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_plans,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_plans,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_plans,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_plans,
        COUNT(DISTINCT subject_id) as subjects_covered,
        COUNT(DISTINCT class_id) as classes_covered
    FROM lesson_plans 
    WHERE teacher_id = ?
";
$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute([$teacher['id']]);
$stats = $stats_stmt->fetch();

// Get lesson plan for editing/viewing
$editing_plan = null;
if ($edit_id || $view_id) {
    $plan_id = $edit_id ?: $view_id;
    $plan_stmt = $pdo->prepare("
        SELECT lp.*, s.subject_name, c.class_name, t.full_name as teacher_name
        FROM lesson_plans lp
        LEFT JOIN subjects s ON lp.subject_id = s.id
        LEFT JOIN classes c ON lp.class_id = c.id
        LEFT JOIN teachers t ON lp.teacher_id = t.id
        WHERE lp.id = ? AND lp.teacher_id = ?
    ");
    $plan_stmt->execute([$plan_id, $teacher['id']]);
    $editing_plan = $plan_stmt->fetch();
    
    // Get attachments
    if ($editing_plan) {
        $attachments_stmt = $pdo->prepare("SELECT * FROM lesson_plan_attachments WHERE lesson_plan_id = ? ORDER BY uploaded_at DESC");
        $attachments_stmt->execute([$plan_id]);
        $editing_plan['attachments'] = $attachments_stmt->fetchAll();
    }
}

$page_title = "Lesson Plans - " . SCHOOL_NAME;

// Check for session messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success']);
unset($_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        /* YOUR ORIGINAL CSS STYLE - KEPT EXACTLY AS YOU PROVIDED */
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
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-info:hover {
            background: #138496;
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
        
        /* Content Layout */
        .content-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }
        
        @media (max-width: 1200px) {
            .content-layout {
                grid-template-columns: 1fr;
            }
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
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border-left: 4px solid #3498db;
            text-align: center;
        }
        
        .stat-card.total { border-left-color: #3498db; }
        .stat-card.completed { border-left-color: #27ae60; }
        .stat-card.draft { border-left-color: #f39c12; }
        .stat-card.progress { border-left-color: #17a2b8; }
        .stat-card.subjects { border-left-color: #9b59b6; }
        .stat-card.classes { border-left-color: #1abc9c; }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 0.3rem;
        }
        
        .stat-label {
            color: #7f8c8d;
            font-size: 0.8rem;
        }
        
        /* Filters */
        .filters {
            padding: 1.5rem;
            border-bottom: 1px solid #ecf0f1;
            background: #f8f9fa;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
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
        
        /* Lesson Plans List */
        .lesson-plans-container {
            padding: 1.5rem;
        }
        
        .view-toggle {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            background: #f8f9fa;
            padding: 0.5rem;
            border-radius: 8px;
            display: inline-flex;
        }
        
        .view-btn {
            padding: 0.5rem 1rem;
            border: 1px solid #ddd;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .view-btn.active {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }
        
        /* Lesson Plan Card */
        .lesson-plan-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border-left: 4px solid #3498db;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }
        
        .lesson-plan-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        
        .lesson-plan-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .lesson-plan-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        
        .lesson-plan-meta {
            display: flex;
            gap: 1rem;
            color: #7f8c8d;
            font-size: 0.8rem;
            margin-bottom: 1rem;
        }
        
        .lesson-plan-meta span {
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        
        .lesson-plan-content {
            margin-bottom: 1rem;
            line-height: 1.6;
        }
        
        .lesson-plan-content p {
            margin-bottom: 0.5rem;
        }
        
        .lesson-plan-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #ecf0f1;
        }
        
        .lesson-plan-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .status-draft { background: #fdebd0; color: #f39c12; }
        .status-in_progress { background: #d6eaf8; color: #3498db; }
        .status-completed { background: #d5f4e6; color: #27ae60; }
        .status-approved { background: #e8daef; color: #9b59b6; }
        
        /* Form Styles */
        .form-section {
            margin-bottom: 2rem;
        }
        
        .form-section h4 {
            color: #2c3e50;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #ecf0f1;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .form-group-modal {
            margin-bottom: 1rem;
        }
        
        .form-group-modal.full {
            grid-column: 1 / -1;
        }
        
        /* Attachments */
        .attachments-list {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .attachment-item {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 1rem;
            min-width: 150px;
        }
        
        .attachment-icon {
            font-size: 2rem;
            text-align: center;
            margin-bottom: 0.5rem;
        }
        
        .attachment-name {
            font-size: 0.8rem;
            text-align: center;
            word-break: break-all;
        }
        
        /* Modal Styles */
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
            overflow-y: auto;
            padding: 1rem;
        }
        
        .modal-content {
            background: white;
            border-radius: 10px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid #ecf0f1;
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        }
        
        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
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
        
        .alert-info {
            background: rgba(52, 152, 219, 0.1);
            color: #0c5460;
            border-left-color: #3498db;
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
        
        /* Teacher Badge */
        .teacher-badge {
            background: #3498db;
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 1rem;
        }
        
        /* Calendar View */
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: #e9ecef;
            border: 1px solid #e9ecef;
        }
        
        .calendar-header {
            background: #2c3e50;
            color: white;
            padding: 1rem;
            text-align: center;
            font-weight: 600;
        }
        
        .calendar-cell {
            background: white;
            padding: 0.5rem;
            min-height: 100px;
            border-bottom: 1px solid #e9ecef;
            border-right: 1px solid #e9ecef;
        }
        
        .calendar-day {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        
        /* Grid View */
        .grid-view {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
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
                <h1>Lesson Plans <span class="teacher-badge">TEACHER</span></h1>
                <p>Create, manage, and share your lesson plans</p>
            </div>
            <div class="page-actions">
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> New Lesson Plan
                </button>
                <a href="?export=1" class="btn btn-success">
                    <i class="fas fa-download"></i> Export
                </a>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-number"><?php echo $stats['total_plans'] ?? 0; ?></div>
                <div class="stat-label">Total Plans</div>
            </div>
            <div class="stat-card completed">
                <div class="stat-number"><?php echo $stats['completed_plans'] ?? 0; ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card draft">
                <div class="stat-number"><?php echo $stats['draft_plans'] ?? 0; ?></div>
                <div class="stat-label">Drafts</div>
            </div>
            <div class="stat-card progress">
                <div class="stat-number"><?php echo $stats['in_progress_plans'] ?? 0; ?></div>
                <div class="stat-label">In Progress</div>
            </div>
        </div>
        
        <div class="content-layout">
            <div class="main-content-area">
                <!-- Filters -->
                <div class="filters">
                    <form method="GET" id="filterForm">
                        <div class="filter-grid">
                            <div class="form-group">
                                <label for="search">Search</label>
                                <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search topics...">
                            </div>
                            
                            <div class="form-group">
                                <label for="subject_id">Subject</label>
                                <select id="subject_id" name="subject_id">
                                    <option value="">All Subjects</option>
                                    <?php foreach($teacher_subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>" <?php echo $filter_subject == $subject['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="class_id">Class</label>
                                <select id="class_id" name="class_id">
                                    <option value="">All Classes</option>
                                    <?php foreach($teacher_classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $filter_class == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="status">Status</label>
                                <select id="status" name="status">
                                    <option value="">All Status</option>
                                    <option value="draft" <?php echo $filter_status == 'draft' ? 'selected' : ''; ?>>Draft</option>
                                    <option value="in_progress" <?php echo $filter_status == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="completed" <?php echo $filter_status == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                                <button type="button" class="btn btn-outline" onclick="resetFilters()">
                                    <i class="fas fa-times"></i> Clear
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- View Toggle -->
                <div class="lesson-plans-container">
                    <div class="view-toggle">
                        <button class="view-btn <?php echo $view == 'list' ? 'active' : ''; ?>" onclick="changeView('list')">
                            <i class="fas fa-list"></i> List View
                        </button>
                        <button class="view-btn <?php echo $view == 'grid' ? 'active' : ''; ?>" onclick="changeView('grid')">
                            <i class="fas fa-th-large"></i> Grid View
                        </button>
                    </div>
                    
                    <!-- Lesson Plans Content -->
                    <div class="lesson-plans-list">
                        <?php if (!empty($lesson_plans)): ?>
                            <?php foreach($lesson_plans as $plan): ?>
                            <div class="lesson-plan-card">
                                <div class="lesson-plan-header">
                                    <div>
                                        <div class="lesson-plan-title"><?php echo htmlspecialchars($plan['topic']); ?></div>
                                        <div class="lesson-plan-meta">
                                            <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($plan['subject_name'] ?? 'N/A'); ?></span>
                                            <span><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($plan['class_name'] ?? 'N/A'); ?></span>
                                            <span><i class="fas fa-clock"></i> <?php echo $plan['duration']; ?> minutes</span>
                                            <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($plan['date_taught'])); ?></span>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="status-badge status-<?php echo $plan['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $plan['status'])); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="lesson-plan-content">
                                    <p><strong>Sub-topic:</strong> <?php echo htmlspecialchars($plan['sub_topic']); ?></p>
                                    <p><strong>Objectives:</strong> <?php echo nl2br(htmlspecialchars(substr($plan['objectives'], 0, 200))); ?>...</p>
                                </div>
                                
                                <div class="lesson-plan-footer">
                                    <div>
                                        <?php if ($plan['attachment_count'] > 0): ?>
                                        <span><i class="fas fa-paperclip"></i> <?php echo $plan['attachment_count']; ?> attachments</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="lesson-plan-actions">
                                        <button class="btn btn-outline btn-sm" onclick="viewPlan(<?php echo $plan['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <button class="btn btn-outline btn-sm" onclick="editPlan(<?php echo $plan['id']; ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this lesson plan?');">
                                            <input type="hidden" name="lesson_plan_id" value="<?php echo $plan['id']; ?>">
                                            <button type="submit" name="delete_lesson_plan" class="btn btn-outline btn-sm">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-book"></i>
                                <h3>No Lesson Plans Found</h3>
                                <p>No lesson plans found matching your filters. Create your first lesson plan to get started.</p>
                                <button class="btn btn-primary" onclick="openAddModal()" style="margin-top: 1rem;">
                                    <i class="fas fa-plus"></i> Create Lesson Plan
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="sidebar-content">
                <!-- Quick Stats -->
                <div class="sidebar-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> Quick Stats</h3>
                    </div>
                    <div class="card-body">
                        <div style="display: grid; gap: 0.8rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span>This Week:</span>
                                <strong>
                                    <?php 
                                    $week_start = date('Y-m-d', strtotime('monday this week'));
                                    $week_end = date('Y-m-d', strtotime('sunday this week'));
                                    $week_stmt = $pdo->prepare("SELECT COUNT(*) FROM lesson_plans WHERE teacher_id = ? AND date_taught BETWEEN ? AND ?");
                                    $week_stmt->execute([$teacher['id'], $week_start, $week_end]);
                                    echo $week_stmt->fetchColumn();
                                    ?>
                                </strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span>This Month:</span>
                                <strong>
                                    <?php 
                                    $month_start = date('Y-m-01');
                                    $month_end = date('Y-m-t');
                                    $month_stmt = $pdo->prepare("SELECT COUNT(*) FROM lesson_plans WHERE teacher_id = ? AND date_taught BETWEEN ? AND ?");
                                    $month_stmt->execute([$teacher['id'], $month_start, $month_end]);
                                    echo $month_stmt->fetchColumn();
                                    ?>
                                </strong>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="sidebar-card">
                    <div class="card-header">
                        <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div style="display: grid; gap: 0.5rem;">
                            <button class="btn btn-outline btn-sm" onclick="openAddModal()">
                                <i class="fas fa-plus"></i> New Lesson Plan
                            </button>
                            <button class="btn btn-outline btn-sm" onclick="printLessonPlans()">
                                <i class="fas fa-print"></i> Print Summary
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Lesson Plan Modal -->
    <div id="lessonPlanModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 style="margin: 0;" id="modalTitle">New Lesson Plan</h3>
                <button class="close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="lessonPlanForm" enctype="multipart/form-data">
                    <input type="hidden" id="lesson_plan_id" name="lesson_plan_id" value="<?php echo $edit_id ?? ''; ?>">
                    
                    <div class="form-section">
                        <h4>Basic Information</h4>
                        <div class="form-grid">
                            <div class="form-group-modal">
                                <label for="modal_subject_id">Subject *</label>
                                <select id="modal_subject_id" name="subject_id" required>
                                    <option value="">Select Subject</option>
                                    <?php foreach($teacher_subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>" <?php echo ($edit_id && $editing_plan && $editing_plan['subject_id'] == $subject['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group-modal">
                                <label for="modal_class_id">Class *</label>
                                <select id="modal_class_id" name="class_id" required>
                                    <option value="">Select Class</option>
                                    <?php foreach($teacher_classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo ($edit_id && $editing_plan && $editing_plan['class_id'] == $class['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group-modal">
                                <label for="modal_topic">Topic *</label>
                                <input type="text" id="modal_topic" name="topic" required placeholder="e.g., Introduction to Algebra" value="<?php echo $edit_id && $editing_plan ? htmlspecialchars($editing_plan['topic']) : ''; ?>">
                            </div>
                            
                            <div class="form-group-modal">
                                <label for="modal_sub_topic">Sub-topic</label>
                                <input type="text" id="modal_sub_topic" name="sub_topic" placeholder="e.g., Solving Linear Equations" value="<?php echo $edit_id && $editing_plan ? htmlspecialchars($editing_plan['sub_topic']) : ''; ?>">
                            </div>
                            
                            <div class="form-group-modal">
                                <label for="modal_duration">Duration (minutes) *</label>
                                <input type="number" id="modal_duration" name="duration" required min="5" max="240" value="<?php echo $edit_id && $editing_plan ? $editing_plan['duration'] : '40'; ?>">
                            </div>
                            
                            <div class="form-group-modal">
                                <label for="modal_date_taught">Date to be Taught *</label>
                                <input type="date" id="modal_date_taught" name="date_taught" required value="<?php echo $edit_id && $editing_plan ? $editing_plan['date_taught'] : date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="form-group-modal">
                                <label for="modal_status">Status *</label>
                                <select id="modal_status" name="status" required>
                                    <option value="draft" <?php echo ($edit_id && $editing_plan && $editing_plan['status'] == 'draft') ? 'selected' : ''; ?>>Draft</option>
                                    <option value="in_progress" <?php echo ($edit_id && $editing_plan && $editing_plan['status'] == 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="completed" <?php echo ($edit_id && $editing_plan && $editing_plan['status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h4>Lesson Details</h4>
                        
                        <div class="form-group-modal full">
                            <label for="modal_objectives">Learning Objectives *</label>
                            <textarea id="modal_objectives" name="objectives" rows="4" required placeholder="What should students be able to do by the end of this lesson?"><?php echo $edit_id && $editing_plan ? htmlspecialchars($editing_plan['objectives']) : ''; ?></textarea>
                        </div>
                        
                        <div class="form-group-modal full">
                            <label for="modal_materials">Materials/Resources</label>
                            <textarea id="modal_materials" name="materials" rows="3" placeholder="Textbooks, worksheets, multimedia, etc."><?php echo $edit_id && $editing_plan ? htmlspecialchars($editing_plan['materials']) : ''; ?></textarea>
                        </div>
                        
                        <div class="form-group-modal full">
                            <label for="modal_activities">Teaching Activities *</label>
                            <textarea id="modal_activities" name="activities" rows="5" required placeholder="Step-by-step teaching procedure"><?php echo $edit_id && $editing_plan ? htmlspecialchars($editing_plan['activities']) : ''; ?></textarea>
                        </div>
                        
                        <div class="form-group-modal full">
                            <label for="modal_assessment">Assessment Methods</label>
                            <textarea id="modal_assessment" name="assessment" rows="3" placeholder="How will you assess student learning?"><?php echo $edit_id && $editing_plan ? htmlspecialchars($editing_plan['assessment']) : ''; ?></textarea>
                        </div>
                        
                        <div class="form-group-modal full">
                            <label for="modal_homework">Homework/Assignments</label>
                            <textarea id="modal_homework" name="homework" rows="2" placeholder="Follow-up work for students"><?php echo $edit_id && $editing_plan ? htmlspecialchars($editing_plan['homework']) : ''; ?></textarea>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h4>Attachments</h4>
                        <div class="form-group-modal full">
                            <label for="attachments">Upload Files (PDF, Word, PowerPoint, Images)</label>
                            <input type="file" id="attachments" name="attachments[]" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png,.gif">
                            <small style="color: #7f8c8d;">Max file size: 10MB each</small>
                        </div>
                        
                        <?php if ($edit_id && $editing_plan && !empty($editing_plan['attachments'])): ?>
                        <div style="margin-top: 1rem;">
                            <h5>Current Attachments:</h5>
                            <div class="attachments-list">
                                <?php foreach($editing_plan['attachments'] as $attachment): ?>
                                <div class="attachment-item">
                                    <div class="attachment-icon"><i class="fas fa-file"></i></div>
                                    <div class="attachment-name"><?php echo htmlspecialchars($attachment['file_name']); ?></div>
                                    <div style="text-align: center; margin-top: 0.5rem;">
                                        <a href="../uploads/lesson_plans/<?php echo htmlspecialchars($attachment['file_name']); ?>" target="_blank" class="btn btn-sm btn-outline">
                                            <i class="fas fa-download"></i>
                                        </a>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this attachment?');">
                                            <input type="hidden" name="attachment_id" value="<?php echo $attachment['id']; ?>">
                                            <input type="hidden" name="lesson_plan_id" value="<?php echo $editing_plan['id']; ?>">
                                            <button type="submit" name="delete_attachment" class="btn btn-sm btn-outline">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 15px;">
                        <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                        <?php if ($edit_id): ?>
                        <button type="submit" name="update_lesson_plan" class="btn btn-primary">Update Lesson Plan</button>
                        <?php else: ?>
                        <button type="submit" name="add_lesson_plan" class="btn btn-primary">Save Lesson Plan</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Simple JavaScript functions for buttons
        function openAddModal() {
            document.getElementById('lessonPlanModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal() {
            document.getElementById('lessonPlanModal').style.display = 'none';
            document.body.style.overflow = 'auto';
            // Redirect to clear edit/view parameters
            window.location.href = 'lesson_plans.php';
        }
        
        function editPlan(planId) {
            window.location.href = 'lesson_plans.php?edit=' + planId;
        }
        
        function viewPlan(planId) {
            window.location.href = 'lesson_plans.php?view_id=' + planId;
        }
        
        function changeView(viewType) {
            const url = new URL(window.location.href);
            url.searchParams.set('view', viewType);
            window.location.href = url.toString();
        }
        
        function resetFilters() {
            window.location.href = 'lesson_plans.php';
        }
        
        function printLessonPlans() {
            window.print();
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                closeModal();
            }
        }
        
        // Escape key to close modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
        
        // Auto-close alerts after 5 seconds
        setTimeout(function() {
            const messages = document.querySelectorAll('.alert');
            messages.forEach(function(message) {
                if (message.style.display !== 'none') {
                    message.style.opacity = '0';
                    message.style.transition = 'opacity 0.5s';
                    setTimeout(() => message.style.display = 'none', 500);
                }
            });
        }, 5000);
        
        // Open modal if edit_id is set
        <?php if ($edit_id && $editing_plan): ?>
        document.addEventListener('DOMContentLoaded', function() {
            openAddModal();
            document.getElementById('modalTitle').textContent = 'Edit Lesson Plan';
        });
        <?php endif; ?>
        
        // View plan if view_id is set
        <?php if ($view_id && $editing_plan): ?>
        document.addEventListener('DOMContentLoaded', function() {
            alert('View mode: Displaying lesson plan ID <?php echo $view_id; ?>');
            // For view mode, you might want to create a separate view modal or page
        });
        <?php endif; ?>
    </script>
</body>
</html>