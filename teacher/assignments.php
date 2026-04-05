<?php
include '../config.php';
checkAuth();
checkRole(['teacher', 'admin']);

try {
    $pdo->exec("ALTER TABLE assignments MODIFY COLUMN student_id INT NULL");
} catch (Exception $e) {
    // Keep page usable if schema update is already applied or blocked.
}

$teacher_id = $_SESSION['user_id'];
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$assignment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_assignment']) || isset($_POST['update_assignment'])) {
        $is_update = isset($_POST['update_assignment']);
        $assignment_data = [
            'title' => trim($_POST['title'] ?? ''),
            'class_id' => intval($_POST['class_id'] ?? 0),
            'description' => trim($_POST['description'] ?? ''),
            'subject_id' => intval($_POST['subject_id'] ?? 0),
            'due_date' => $_POST['due_date'] ?? '',
            'total_marks' => intval($_POST['total_marks'] ?? 10)
        ];

        // Validate required fields
        $errors = [];
        if (empty($assignment_data['title'])) $errors[] = 'Title is required';
        if (empty($assignment_data['class_id'])) $errors[] = 'Class is required';
        if (empty($assignment_data['subject_id'])) $errors[] = 'Subject is required';
        if (empty($assignment_data['due_date'])) $errors[] = 'Due date is required';

        if (empty($errors)) {
            if ($is_update) {
                $assignment_id = intval($_POST['assignment_id']);
                $result = updateAssignment($assignment_id, $assignment_data);
                if ($result['success']) {
                    $message = 'Assignment updated successfully!';
                    $message_type = 'success';
                    $action = 'view';
                } else {
                    $message = 'Error updating assignment: ' . $result['message'];
                    $message_type = 'error';
                }
            } else {
                $result = createAssignment($assignment_data, $teacher_id);
                if ($result['success']) {
                    $message = 'Assignment created successfully!';
                    $message_type = 'success';
                    $assignment_id = $result['assignment_id'];
                    $action = 'view';
                } else {
                    $message = 'Error creating assignment: ' . $result['message'];
                    $message_type = 'error';
                }
            }
        } else {
            $message = implode('<br>', $errors);
            $message_type = 'error';
        }
    } elseif (isset($_POST['delete_assignment'])) {
        $assignment_id = intval($_POST['assignment_id']);
        $result = deleteAssignment($assignment_id, $teacher_id);
        if ($result['success']) {
            $message = 'Assignment deleted successfully!';
            $message_type = 'success';
            $action = 'list';
        } else {
            $message = 'Error deleting assignment: ' . $result['message'];
            $message_type = 'error';
        }
    } elseif (isset($_POST['grade_submission'])) {
        $submission_id = intval($_POST['submission_id']);
        $marks = intval($_POST['marks']);
        $feedback = trim($_POST['feedback'] ?? '');
        
        $result = gradeSubmission($submission_id, $marks, $feedback, $teacher_id);
        if ($result['success']) {
            $message = 'Submission graded successfully!';
            $message_type = 'success';
        } else {
            $message = 'Error grading submission: ' . $result['message'];
            $message_type = 'error';
        }
    } elseif (isset($_POST['record_manual_submission'])) {
        $assignment_id = intval($_POST['assignment_id'] ?? 0);
        $student_id = intval($_POST['student_id'] ?? 0);
        $submission_text = trim($_POST['submission_text'] ?? '');

        $result = recordManualSubmission($assignment_id, $student_id, $submission_text, $teacher_id);
        if ($result['success']) {
            $message = 'Student submission recorded successfully!';
            $message_type = 'success';
            $action = 'view';
        } else {
            $message = 'Error recording submission: ' . $result['message'];
            $message_type = 'error';
            $action = 'view';
        }
    }
}

// Get data based on action
$assignment = null;
$submissions = [];
$classes = getTeacherClasses($teacher_id);
$subjects = getTeacherSubjects($teacher_id);

if ($action === 'view' || $action === 'edit' || $action === 'grade') {
    $assignment = getAssignmentById($assignment_id, $teacher_id);
    if (!$assignment) {
        $message = 'Assignment not found or you don\'t have permission to view it.';
        $message_type = 'error';
        $action = 'list';
    } else {
        if ($action === 'view' || $action === 'grade') {
            $submissions = getAssignmentSubmissions($assignment_id);
        }
    }
}

if ($action === 'list') {
    $assignments = getTeacherAssignments($teacher_id);
}

$page_title = $action === 'create' ? "Create Assignment - " . SCHOOL_NAME : 
             ($action === 'edit' ? "Edit Assignment - " . SCHOOL_NAME : 
             ($action === 'view' ? "View Assignment - " . SCHOOL_NAME : 
             ($action === 'grade' ? "Grade Submissions - " . SCHOOL_NAME : 
             "Assignments - " . SCHOOL_NAME)));

/**
 * Upload assignment file
 */
function uploadAssignmentFile($file) {
    $target_dir = "../uploads/assignments/";
    
    // Create directory if it doesn't exist
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $file_extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $allowed_extensions = ['pdf', 'doc', 'docx', 'txt', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
    
    if (!in_array($file_extension, $allowed_extensions)) {
        return ['success' => false, 'message' => 'File type not allowed. Allowed types: ' . implode(', ', $allowed_extensions)];
    }
    
    if ($file["size"] > 10 * 1024 * 1024) { // 10MB limit
        return ['success' => false, 'message' => 'File size too large. Maximum size: 10MB'];
    }
    
    $new_filename = uniqid() . '_' . time() . '.' . $file_extension;
    $target_file = $target_dir . $new_filename;
    
    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        return ['success' => true, 'path' => 'uploads/assignments/' . $new_filename];
    } else {
        return ['success' => false, 'message' => 'Error uploading file.'];
    }
}

/**
 * Get teacher's classes for dropdown
 */
function getTeacherClasses($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT DISTINCT c.id, c.class_name 
                              FROM classes c 
                              LEFT JOIN subjects s ON c.id = s.class_id 
                              WHERE s.teacher_id = ? OR c.class_teacher_id = ?
                              ORDER BY c.class_name");
        $stmt->execute([$teacher_id, $teacher_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting teacher classes: " . $e->getMessage());
        return [];
    }
}

/**
 * Get teacher's subjects for dropdown
 */
function getTeacherSubjects($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT s.id, s.subject_name, s.class_id, c.class_name 
                              FROM subjects s
                              JOIN classes c ON s.class_id = c.id
                              WHERE s.teacher_id = ?
                              ORDER BY c.class_name, s.subject_name");
        $stmt->execute([$teacher_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting teacher subjects: " . $e->getMessage());
        return [];
    }
}

/**
 * Get all assignments for a teacher
 */
function getTeacherAssignments($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT a.*, c.class_name, s.subject_name,
                              (SELECT COUNT(*) FROM submissions WHERE assignment_id = a.id) as total_submissions,
                              (SELECT COUNT(*) FROM students WHERE class_id = a.class_id AND status = 'active') as total_students,
                              (SELECT COUNT(*) FROM submissions WHERE assignment_id = a.id AND status='graded') as graded_submissions,
                              DATEDIFF(a.due_date, CURDATE()) as days_remaining
                              FROM assignments a
                              JOIN classes c ON a.class_id = c.id
                              JOIN subjects s ON a.subject_id = s.id
                              WHERE a.teacher_id = ?
                              ORDER BY 
                                  CASE WHEN a.due_date >= CURDATE() THEN 0 ELSE 1 END,
                                  a.due_date ASC,
                                  a.created_at DESC");
        $stmt->execute([$teacher_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting teacher assignments: " . $e->getMessage());
        return [];
    }
}

/**
 * Get assignment by ID
 */
function getAssignmentById($assignment_id, $teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT a.*, c.class_name, s.subject_name,
                              (SELECT COUNT(*) FROM submissions WHERE assignment_id = a.id) as total_submissions,
                              (SELECT COUNT(*) FROM students WHERE class_id = a.class_id AND status = 'active') as total_students,
                              (SELECT COUNT(*) FROM submissions WHERE assignment_id = a.id AND status='graded') as graded_submissions
                              FROM assignments a
                              JOIN classes c ON a.class_id = c.id
                              JOIN subjects s ON a.subject_id = s.id
                              WHERE a.id = ? AND a.teacher_id = ?");
        $stmt->execute([$assignment_id, $teacher_id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error getting assignment: " . $e->getMessage());
        return false;
    }
}

/**
 * Create new assignment
 */
function createAssignment($data, $teacher_id) {
    global $pdo;
    try {
        $subjectStmt = $pdo->prepare("
            SELECT s.id, s.class_id
            FROM subjects s
            JOIN classes c ON s.class_id = c.id
            WHERE s.id = ?
              AND s.class_id = ?
              AND (
                    s.teacher_id = ?
                    OR c.class_teacher_id = ?
                  )
            LIMIT 1
        ");
        $subjectStmt->execute([$data['subject_id'], $data['class_id'], $teacher_id, $teacher_id]);
        $subject = $subjectStmt->fetch(PDO::FETCH_ASSOC);

        if (!$subject) {
            return ['success' => false, 'message' => 'Selected subject does not belong to the selected class or you do not have access to it.'];
        }

        $stmt = $pdo->prepare("INSERT INTO assignments 
                              (title, description, instructions, class_id, subject_id, teacher_id, student_id, due_date, marks, total_marks, created_at) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        
        $result = $stmt->execute([
            $data['title'],
            $data['description'],
            $data['description'] ?: '',
            (int) $subject['class_id'],
            $data['subject_id'],
            $teacher_id,
            null,
            $data['due_date'],
            $data['total_marks'] ?? 10,
            $data['total_marks'] ?? 10
        ]);

        if ($result) {
            error_log("Assignment created successfully. ID: " . $pdo->lastInsertId());
            return ['success' => true, 'assignment_id' => $pdo->lastInsertId()];
        } else {
            error_log("Assignment creation failed. Error: " . implode(", ", $stmt->errorInfo()));
            return ['success' => false, 'message' => 'Failed to create assignment'];
        }
    } catch (PDOException $e) {
        error_log("Error creating assignment: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Update assignment
 */
function updateAssignment($assignment_id, $data) {
    global $pdo;
    try {
        // Check if assignment exists
        $check = $pdo->prepare("SELECT id FROM assignments WHERE id = ?");
        $check->execute([$assignment_id]);
        if (!$check->fetch()) {
            return ['success' => false, 'message' => 'Assignment not found'];
        }

        $subjectStmt = $pdo->prepare("
            SELECT s.id, s.class_id
            FROM subjects s
            JOIN classes c ON s.class_id = c.id
            JOIN assignments a ON a.id = ?
            WHERE s.id = ?
              AND s.class_id = ?
              AND (
                    s.teacher_id = a.teacher_id
                    OR c.class_teacher_id = a.teacher_id
                  )
            LIMIT 1
        ");
        $subjectStmt->execute([$assignment_id, $data['subject_id'], $data['class_id']]);
        $subject = $subjectStmt->fetch(PDO::FETCH_ASSOC);

        if (!$subject) {
            return ['success' => false, 'message' => 'Selected subject does not belong to the selected class or you do not have access to it.'];
        }

        $sql = "UPDATE assignments SET 
                title = ?, description = ?, instructions = ?, class_id = ?, subject_id = ?, student_id = NULL, due_date = ?, marks = ?, total_marks = ?
                WHERE id = ?";
        
        $params = [
            $data['title'],
            $data['description'],
            $data['description'] ?: '',
            (int) $subject['class_id'],
            $data['subject_id'],
            $data['due_date'],
            $data['total_marks'] ?? 10,
            $data['total_marks'] ?? 10,
            $assignment_id
        ];

        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute($params);

        if ($result) {
            return ['success' => true];
        } else {
            return ['success' => false, 'message' => 'No changes made'];
        }
    } catch (PDOException $e) {
        error_log("Error updating assignment: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Delete assignment
 */
function deleteAssignment($assignment_id, $teacher_id) {
    global $pdo;
    try {
        // First delete all submissions
        $stmt = $pdo->prepare("DELETE FROM submissions WHERE assignment_id = ?");
        $stmt->execute([$assignment_id]);

        // Then delete the assignment
        $stmt = $pdo->prepare("DELETE FROM assignments WHERE id = ? AND teacher_id = ?");
        $result = $stmt->execute([$assignment_id, $teacher_id]);

        if ($result && $stmt->rowCount() > 0) {
            return ['success' => true];
        } else {
            return ['success' => false, 'message' => 'Assignment not found or permission denied'];
        }
    } catch (PDOException $e) {
        error_log("Error deleting assignment: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Get assignment submissions
 */
function getAssignmentSubmissions($assignment_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT s.*, st.full_name as student_name, st.admission_number,
                              st.id as student_id,
                              TIMESTAMPDIFF(HOUR, s.submitted_at, a.due_date) as hours_early,
                              CASE 
                                  WHEN s.submitted_at <= a.due_date THEN 'on_time'
                                  ELSE 'late'
                              END as submission_status
                              FROM submissions s
                              JOIN students st ON s.student_id = st.id
                              JOIN assignments a ON s.assignment_id = a.id
                              WHERE s.assignment_id = ?
                              ORDER BY 
                                  CASE WHEN s.status = 'pending' THEN 0 ELSE 1 END,
                                  s.submitted_at DESC");
        $stmt->execute([$assignment_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting submissions: " . $e->getMessage());
        return [];
    }
}

/**
 * Grade a submission
 */
function gradeSubmission($submission_id, $marks, $feedback, $teacher_id) {
    global $pdo;
    try {
        // Verify teacher owns this assignment
        $stmt = $pdo->prepare("SELECT a.teacher_id FROM submissions s
                              JOIN assignments a ON s.assignment_id = a.id
                              WHERE s.id = ?");
        $stmt->execute([$submission_id]);
        $result = $stmt->fetch();
        
        if (!$result || $result['teacher_id'] != $teacher_id) {
            return ['success' => false, 'message' => 'Permission denied'];
        }

        $stmt = $pdo->prepare("UPDATE submissions 
                              SET marks = ?, feedback = ?, status = 'graded', graded_at = NOW() 
                              WHERE id = ?");
        $result = $stmt->execute([$marks, $feedback, $submission_id]);

        if ($result) {
            return ['success' => true];
        } else {
            return ['success' => false, 'message' => 'Failed to grade submission'];
        }
    } catch (PDOException $e) {
        error_log("Error grading submission: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Record manual submission for offline students
 */
function recordManualSubmission($assignment_id, $student_id, $submission_text, $teacher_id) {
    global $pdo;
    try {
        $assignmentStmt = $pdo->prepare("
            SELECT id, class_id, teacher_id
            FROM assignments
            WHERE id = ? AND teacher_id = ?
            LIMIT 1
        ");
        $assignmentStmt->execute([$assignment_id, $teacher_id]);
        $assignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$assignment) {
            return ['success' => false, 'message' => 'Assignment not found or permission denied'];
        }

        $studentStmt = $pdo->prepare("
            SELECT id
            FROM students
            WHERE id = ? AND class_id = ? AND status = 'active'
            LIMIT 1
        ");
        $studentStmt->execute([$student_id, $assignment['class_id']]);
        if (!$studentStmt->fetch(PDO::FETCH_ASSOC)) {
            return ['success' => false, 'message' => 'Selected student does not belong to this assignment class'];
        }

        $duplicateStmt = $pdo->prepare("SELECT id FROM submissions WHERE assignment_id = ? AND student_id = ? LIMIT 1");
        $duplicateStmt->execute([$assignment_id, $student_id]);
        if ($duplicateStmt->fetch(PDO::FETCH_ASSOC)) {
            return ['success' => false, 'message' => 'This student already has a recorded submission'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO submissions (assignment_id, student_id, submission_text, submitted_at, status)
            VALUES (?, ?, ?, NOW(), 'pending')
        ");
        $stmt->execute([$assignment_id, $student_id, $submission_text !== '' ? $submission_text : null]);

        return ['success' => true];
    } catch (PDOException $e) {
        error_log("Error recording manual submission: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Get unsubmitted students
 */
function getUnsubmittedStudents($assignment_id, $class_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT st.id, st.full_name, st.admission_number
                              FROM students st
                              WHERE st.class_id = ? 
                              AND st.status = 'active'
                              AND st.id NOT IN (
                                  SELECT student_id FROM submissions 
                                  WHERE assignment_id = ?
                              )
                              ORDER BY st.full_name");
        $stmt->execute([$class_id, $assignment_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting unsubmitted students: " . $e->getMessage());
        return [];
    }
}

/**
 * Send reminder to unsubmitted students
 */
function sendReminder($assignment_id, $student_id) {
    // This would integrate with your messaging system
    // For now, return true
    return true;
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
            --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-success: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --gradient-warning: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --gradient-danger: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
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
            background: var(--gradient-primary);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
        }

        /* Alerts */
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
        }

        .alert-success {
            border-left: 4px solid var(--success);
        }

        .alert-error {
            border-left: 4px solid var(--danger);
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
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .page-header p {
            color: var(--gray);
            margin-top: 0.3rem;
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
            padding: 0.4rem 1rem;
            font-size: 0.8rem;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }

        .btn-success {
            background: var(--gradient-success);
            color: white;
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.4);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79, 172, 254, 0.5);
        }

        .btn-warning {
            background: var(--gradient-warning);
            color: white;
            box-shadow: 0 4px 15px rgba(250, 112, 154, 0.4);
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(250, 112, 154, 0.5);
        }

        .btn-danger {
            background: var(--gradient-danger);
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
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        /* Filters */
        .filters-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-group label {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .filter-select, .filter-input {
            padding: 0.6rem;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-md);
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary);
        }

        /* Assignment Grid */
        .assignments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .assignment-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            transition: var(--transition);
            position: relative;
        }

        .assignment-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .assignment-status {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-active {
            background: rgba(76, 201, 240, 0.2);
            color: var(--success);
        }

        .status-overdue {
            background: rgba(249, 65, 68, 0.2);
            color: var(--danger);
        }

        .status-closed {
            background: rgba(108, 117, 125, 0.2);
            color: var(--gray);
        }

        .assignment-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border-bottom: 2px solid var(--light);
        }

        .assignment-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
            padding-right: 80px;
        }

        .assignment-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.85rem;
            color: var(--gray);
        }

        .assignment-meta i {
            width: 16px;
            color: var(--primary);
        }

        .assignment-body {
            padding: 1.5rem;
        }

        .assignment-description {
            color: var(--gray);
            font-size: 0.9rem;
            margin-bottom: 1rem;
            line-height: 1.6;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .assignment-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
            margin: 1rem 0;
            padding: 0.8rem;
            background: var(--light);
            border-radius: var(--border-radius-md);
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--gray);
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: rgba(0,0,0,0.1);
            border-radius: 3px;
            overflow: hidden;
            margin: 1rem 0;
        }

        .progress-fill {
            height: 100%;
            background: var(--gradient-success);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .assignment-footer {
            padding: 1rem 1.5rem;
            border-top: 2px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--light);
        }

        .due-date {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.85rem;
        }

        .due-date.urgent {
            color: var(--danger);
        }

        .assignment-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Form Styles */
        .form-container {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            padding: 2rem;
            box-shadow: var(--shadow-lg);
            max-width: 800px;
            margin: 0 auto;
        }

        .form-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-md);
            font-size: 0.95rem;
            transition: var(--transition);
            font-family: 'Inter', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        .form-text {
            font-size: 0.8rem;
            color: var(--gray);
            margin-top: 0.3rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid var(--light);
        }

        /* Assignment View */
        .assignment-view {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            padding: 2rem;
            box-shadow: var(--shadow-lg);
        }

        .assignment-header-large {
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid var(--light);
        }

        .assignment-header-large h2 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .assignment-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin: 1.5rem 0;
            padding: 1.5rem;
            background: var(--light);
            border-radius: var(--border-radius-lg);
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .info-label {
            font-size: 0.8rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--dark);
        }

        .info-value small {
            font-size: 0.8rem;
            color: var(--gray);
            font-weight: normal;
        }

        .attachment-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: var(--light);
            border-radius: var(--border-radius-md);
            color: var(--primary);
            text-decoration: none;
            transition: var(--transition);
        }

        .attachment-link:hover {
            background: var(--primary);
            color: white;
        }

        /* Submissions Table */
        .submissions-section {
            margin-top: 2rem;
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .submissions-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-md);
        }

        .submissions-table th {
            background: var(--light);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .submissions-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--light);
            vertical-align: middle;
        }

        .submissions-table tr:hover td {
            background: rgba(67, 97, 238, 0.05);
        }

        .student-info {
            display: flex;
            flex-direction: column;
        }

        .student-name {
            font-weight: 600;
            color: var(--dark);
        }

        .student-adm {
            font-size: 0.8rem;
            color: var(--gray);
        }

        .badge {
            padding: 0.3rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-success {
            background: rgba(76, 201, 240, 0.2);
            color: var(--success);
        }

        .badge-warning {
            background: rgba(248, 150, 30, 0.2);
            color: var(--warning);
        }

        .badge-danger {
            background: rgba(249, 65, 68, 0.2);
            color: var(--danger);
        }

        .badge-info {
            background: rgba(67, 97, 238, 0.2);
            color: var(--primary);
        }

        .grade-input {
            width: 80px;
            padding: 0.4rem;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-sm);
            text-align: center;
        }

        .grade-input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .feedback-text {
            max-width: 200px;
            font-size: 0.85rem;
            color: var(--gray);
        }

        .action-buttons {
            display: flex;
            gap: 0.3rem;
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--border-radius-sm);
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--gray-light);
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--gray);
            margin-bottom: 1.5rem;
        }

        /* Modal */
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
            max-width: 600px;
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

        .modal-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--dark);
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

        .modal-footer {
            padding: 1.5rem;
            border-top: 2px solid var(--light);
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .assignments-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .assignment-info-grid {
                grid-template-columns: 1fr;
            }
            
            .submissions-table {
                display: block;
                overflow-x: auto;
            }
            
            .page-header {
                flex-direction: column;
                text-align: center;
            }
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
    </style>
</head>
<body>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>
    <?php include '../loader.php'; ?>

    <div class="main-content">
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> animate-fade-up">
            <div>
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo $message; ?>
            </div>
            <button onclick="this.parentElement.style.display='none'" style="background:none; border:none; cursor:pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header animate-fade-up">
            <div>
                <h1>
                    <?php if ($action === 'create'): ?>
                        <i class="fas fa-plus-circle"></i> Create New Assignment
                    <?php elseif ($action === 'edit'): ?>
                        <i class="fas fa-edit"></i> Edit Assignment
                    <?php elseif ($action === 'view'): ?>
                        <i class="fas fa-eye"></i> View Assignment
                    <?php elseif ($action === 'grade'): ?>
                        <i class="fas fa-check-double"></i> Grade Submissions
                    <?php else: ?>
                        <i class="fas fa-tasks"></i> Assignments
                    <?php endif; ?>
                </h1>
                <p>
                    <?php if ($action === 'list'): ?>
                        Manage and track all your class assignments
                    <?php elseif ($action === 'create'): ?>
                        Create a new assignment for your students
                    <?php elseif ($action === 'edit'): ?>
                        Edit assignment details
                    <?php elseif ($action === 'view'): ?>
                        View assignment details and submissions
                    <?php elseif ($action === 'grade'): ?>
                        Grade student submissions
                    <?php endif; ?>
                </p>
            </div>
            <?php if ($action === 'list'): ?>
            <a href="?action=create" class="btn btn-primary">
                <i class="fas fa-plus"></i> Create Assignment
            </a>
            <?php elseif ($action !== 'create' && $action !== 'list' && $assignment): ?>
            <div>
                <a href="?action=list" class="btn btn-outline btn-sm">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($action === 'list'): ?>
            <!-- Filters -->
            <div class="filters-section animate-fade-up">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label><i class="fas fa-filter"></i> Filter by Class</label>
                        <select id="classFilter" class="filter-select" onchange="filterAssignments()">
                            <option value="all">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-tag"></i> Filter by Status</label>
                        <select id="statusFilter" class="filter-select" onchange="filterAssignments()">
                            <option value="all">All Status</option>
                            <option value="active">Active</option>
                            <option value="overdue">Overdue</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-search"></i> Search</label>
                        <input type="text" id="searchFilter" class="filter-input" placeholder="Search assignments..." onkeyup="filterAssignments()">
                    </div>
                </div>
            </div>

            <!-- Assignments Grid -->
            <?php if (!empty($assignments)): ?>
            <div class="assignments-grid" id="assignmentsGrid">
                <?php foreach ($assignments as $assignment): 
                    $is_overdue = strtotime($assignment['due_date']) < time();
                    $is_closed = $assignment['due_date'] < date('Y-m-d');
                    $submission_rate = $assignment['total_students'] > 0 ? 
                        round(($assignment['total_submissions'] / $assignment['total_students']) * 100) : 0;
                ?>
                <div class="assignment-card animate-fade-up" 
                     data-class="<?php echo $assignment['class_id']; ?>"
                     data-status="<?php echo $is_closed ? 'closed' : ($is_overdue ? 'overdue' : 'active'); ?>"
                     data-title="<?php echo strtolower($assignment['title']); ?>">
                    <div class="assignment-status status-<?php echo $is_closed ? 'closed' : ($is_overdue ? 'overdue' : 'active'); ?>">
                        <?php echo $is_closed ? 'Closed' : ($is_overdue ? 'Overdue' : 'Active'); ?>
                    </div>
                    
                    <div class="assignment-header">
                        <div class="assignment-title"><?php echo htmlspecialchars($assignment['title']); ?></div>
                        <div class="assignment-meta">
                            <span><i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($assignment['class_name']); ?></span>
                            <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($assignment['subject_name']); ?></span>
                        </div>
                    </div>
                    
                    <div class="assignment-body">
                        <div class="assignment-description">
                            <?php echo nl2br(htmlspecialchars(substr($assignment['description'] ?? '', 0, 150))); ?>
                            <?php if (strlen($assignment['description'] ?? '') > 150) echo '...'; ?>
                        </div>
                        
                        <div class="assignment-stats">
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $assignment['total_submissions']; ?></div>
                                <div class="stat-label">Submitted</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $assignment['total_students']; ?></div>
                                <div class="stat-label">Students</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $assignment['graded_submissions']; ?></div>
                                <div class="stat-label">Graded</div>
                            </div>
                        </div>
                        
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $submission_rate; ?>%;"></div>
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; font-size: 0.8rem;">
                            <span>Submission Rate</span>
                            <span><?php echo $submission_rate; ?>%</span>
                        </div>
                    </div>
                    
                    <div class="assignment-footer">
                        <div class="due-date <?php echo $is_overdue && !$is_closed ? 'urgent' : ''; ?>">
                            <i class="fas fa-calendar-alt"></i>
                            Due: <?php echo date('M j, Y', strtotime($assignment['due_date'])); ?>
                            <?php if ($assignment['days_remaining'] > 0 && !$is_closed): ?>
                                <span class="badge badge-info"><?php echo $assignment['days_remaining']; ?> days left</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="assignment-actions">
                            <a href="?action=view&id=<?php echo $assignment['id']; ?>" class="btn btn-primary btn-sm" title="View Details">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="?action=edit&id=<?php echo $assignment['id']; ?>" class="btn btn-warning btn-sm" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <button onclick="confirmDelete(<?php echo $assignment['id']; ?>, '<?php echo htmlspecialchars(addslashes($assignment['title'])); ?>')" 
                                    class="btn btn-danger btn-sm" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state animate-fade-up">
                <i class="fas fa-tasks"></i>
                <h3>No Assignments Yet</h3>
                <p>Create your first assignment to get started</p>
                <a href="?action=create" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Create Assignment
                </a>
            </div>
            <?php endif; ?>

        <?php elseif ($action === 'create' || $action === 'edit'): ?>
            <!-- Create/Edit Assignment Form -->
            <div class="form-container animate-fade-up">
                <h2 class="form-title">
                    <i class="fas fa-<?php echo $action === 'create' ? 'plus-circle' : 'edit'; ?>"></i>
                    <?php echo $action === 'create' ? 'Create New Assignment' : 'Edit Assignment'; ?>
                </h2>
                
                <form method="POST" enctype="multipart/form-data" onsubmit="return validateForm()">
                    <?php if ($action === 'edit' && $assignment): ?>
                        <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                        <input type="hidden" name="update_assignment" value="1">
                    <?php else: ?>
                        <input type="hidden" name="create_assignment" value="1">
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label for="title">Assignment Title *</label>
                        <input type="text" id="title" name="title" class="form-control" required
                               value="<?php echo $assignment ? htmlspecialchars($assignment['title']) : ''; ?>"
                               placeholder="e.g., Mathematics Chapter 5 Exercise">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="class_id">Class *</label>
                            <select id="class_id" name="class_id" class="form-control" required onchange="loadSubjects()">
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" 
                                        <?php echo ($assignment && $assignment['class_id'] == $class['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="subject_id">Subject *</label>
                            <select id="subject_id" name="subject_id" class="form-control" required>
                                <option value="">Select Subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>" 
                                        data-class="<?php echo $subject['class_id'] ?? ''; ?>"
                                        <?php echo ($assignment && $assignment['subject_id'] == $subject['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['subject_name'] . ' - ' . $subject['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="due_date">Due Date *</label>
                            <input type="date" id="due_date" name="due_date" class="form-control" required
                                   value="<?php echo $assignment ? $assignment['due_date'] : ''; ?>"
                                   min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="total_marks">Total Marks</label>
                            <input type="number" id="total_marks" name="total_marks" class="form-control"
                                   value="<?php echo $assignment ? $assignment['total_marks'] : 100; ?>"
                                   min="1" max="500">
                        </div>
                        
                        <div class="form-group">
                            <label for="passing_marks">Passing Marks</label>
                            <input type="number" id="passing_marks" name="passing_marks" class="form-control"
                                   value="<?php echo $assignment ? $assignment['passing_marks'] : 40; ?>"
                                   min="0" max="<?php echo $assignment ? $assignment['total_marks'] : 100; ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" class="form-control" 
                                  placeholder="Provide a brief description of the assignment"><?php echo $assignment ? htmlspecialchars($assignment['description'] ?? '') : ''; ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="instructions">Instructions</label>
                        <textarea id="instructions" name="instructions" class="form-control" 
                                  placeholder="Provide detailed instructions for students"><?php echo $assignment ? htmlspecialchars($assignment['instructions'] ?? '') : ''; ?></textarea>
                        <div class="form-text">Include step-by-step instructions, resources, or guidelines</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="attachment">Attachment (Optional)</label>
                        <input type="file" id="attachment" name="attachment" class="form-control">
                        <div class="form-text">Allowed files: PDF, DOC, DOCX, TXT, PPT, PPTX, XLS, XLSX, JPG, PNG (Max: 10MB)</div>
                        <?php if ($assignment && $assignment['attachment_path']): ?>
                            <div style="margin-top: 0.5rem;">
                                <i class="fas fa-paperclip"></i> Current: 
                                <a href="../<?php echo $assignment['attachment_path']; ?>" target="_blank" class="attachment-link">
                                    View Attachment
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-actions">
                        <a href="?action=list" class="btn btn-outline">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> 
                            <?php echo $action === 'create' ? 'Create Assignment' : 'Update Assignment'; ?>
                        </button>
                    </div>
                </form>
            </div>

        <?php elseif ($action === 'view' && $assignment): ?>
            <!-- Assignment View -->
            <div class="assignment-view animate-fade-up">
                <div class="assignment-header-large">
                    <h2><?php echo htmlspecialchars($assignment['title']); ?></h2>
                    <div class="assignment-meta">
                        <span><i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($assignment['class_name']); ?></span>
                        <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($assignment['subject_name']); ?></span>
                    </div>
                </div>
                
                <div class="assignment-info-grid">
                    <div class="info-item">
                        <span class="info-label">Due Date</span>
                        <span class="info-value">
                            <?php echo date('F j, Y', strtotime($assignment['due_date'])); ?>
                            <?php if (strtotime($assignment['due_date']) >= time()): ?>
                                <small>(<?php echo ceil((strtotime($assignment['due_date']) - time()) / (60 * 60 * 24)); ?> days left)</small>
                            <?php else: ?>
                                <small style="color: var(--danger);">(Overdue)</small>
                            <?php endif; ?>
                        </span>
                    </div>
                    
                    <div class="info-item">
                        <span class="info-label">Total Marks</span>
                        <span class="info-value"><?php echo $assignment['total_marks']; ?></span>
                    </div>
                    
                    <div class="info-item">
                        <span class="info-label">Passing Marks</span>
                        <span class="info-value"><?php echo $assignment['passing_marks']; ?></span>
                    </div>
                    
                    <div class="info-item">
                        <span class="info-label">Submissions</span>
                        <span class="info-value">
                            <?php echo $assignment['total_submissions']; ?>/<?php echo $assignment['total_students']; ?>
                            <small>(<?php echo $assignment['total_students'] > 0 ? round(($assignment['total_submissions'] / $assignment['total_students']) * 100) : 0; ?>%)</small>
                        </span>
                    </div>
                    
                    <div class="info-item">
                        <span class="info-label">Graded</span>
                        <span class="info-value">
                            <?php echo $assignment['graded_submissions']; ?>/<?php echo $assignment['total_submissions']; ?>
                            <small>(<?php echo $assignment['total_submissions'] > 0 ? round(($assignment['graded_submissions'] / $assignment['total_submissions']) * 100) : 0; ?>%)</small>
                        </span>
                    </div>
                    
                    <?php if ($assignment['attachment_path']): ?>
                    <div class="info-item">
                        <span class="info-label">Attachment</span>
                        <span class="info-value">
                            <a href="../<?php echo $assignment['attachment_path']; ?>" target="_blank" class="attachment-link">
                                <i class="fas fa-download"></i> Download
                            </a>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($assignment['description']): ?>
                <div style="margin-bottom: 2rem;">
                    <h3 style="margin-bottom: 1rem; color: var(--dark);">Description</h3>
                    <div style="background: var(--light); padding: 1.5rem; border-radius: var(--border-radius-lg); line-height: 1.8;">
                        <?php echo nl2br(htmlspecialchars($assignment['description'])); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($assignment['instructions']): ?>
                <div style="margin-bottom: 2rem;">
                    <h3 style="margin-bottom: 1rem; color: var(--dark);">Instructions</h3>
                    <div style="background: var(--light); padding: 1.5rem; border-radius: var(--border-radius-lg); line-height: 1.8;">
                        <?php echo nl2br(htmlspecialchars($assignment['instructions'])); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Submissions Section -->
                <div class="submissions-section">
                    <div class="section-title">
                        <i class="fas fa-users"></i>
                        Student Submissions
                        <span class="badge badge-info"><?php echo count($submissions); ?> submitted</span>
                    </div>
                    
                    <?php if (!empty($submissions)): ?>
                    <table class="submissions-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Submitted</th>
                                <th>Status</th>
                                <th>Marks</th>
                                <th>Feedback</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($submissions as $submission): ?>
                            <tr>
                                <td>
                                    <div class="student-info">
                                        <span class="student-name"><?php echo htmlspecialchars($submission['student_name']); ?></span>
                                        <span class="student-adm"><?php echo htmlspecialchars($submission['admission_number']); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <?php echo date('M j, Y H:i', strtotime($submission['submitted_at'])); ?>
                                    <?php if ($submission['submission_status'] === 'late'): ?>
                                        <span class="badge badge-danger">Late</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($submission['status'] === 'graded'): ?>
                                        <span class="badge badge-success">Graded</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($submission['status'] === 'graded'): ?>
                                        <strong><?php echo $submission['marks']; ?></strong> / <?php echo $assignment['total_marks']; ?>
                                    <?php else: ?>
                                        <form method="POST" style="display: inline;" onsubmit="return validateGrade(this)">
                                            <input type="hidden" name="submission_id" value="<?php echo $submission['id']; ?>">
                                            <input type="hidden" name="grade_submission" value="1">
                                            <input type="number" name="marks" class="grade-input" 
                                                   placeholder="Marks" min="0" max="<?php echo $assignment['total_marks']; ?>" required>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($submission['status'] === 'graded'): ?>
                                        <div class="feedback-text">
                                            <?php echo htmlspecialchars($submission['feedback'] ?? 'No feedback'); ?>
                                        </div>
                                    <?php else: ?>
                                            <input type="text" name="feedback" class="form-control" style="width: 150px;" 
                                                   placeholder="Feedback (optional)">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($submission['status'] === 'graded'): ?>
                                        <div class="action-buttons">
                                            <button onclick="editGrade(<?php echo $submission['id']; ?>, <?php echo $submission['marks']; ?>, '<?php echo htmlspecialchars(addslashes($submission['feedback'] ?? '')); ?>')" 
                                                    class="btn btn-warning btn-icon" title="Edit Grade">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div class="action-buttons">
                                            <button type="submit" class="btn btn-success btn-icon" title="Submit Grade">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </div>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state" style="padding: 2rem;">
                        <i class="fas fa-inbox"></i>
                        <p>No submissions yet</p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Unsubmitted Students -->
                <?php 
                $unsubmitted = getUnsubmittedStudents($assignment['id'], $assignment['class_id']);
                if (!empty($unsubmitted)): 
                ?>
                <div style="margin-top: 2rem;">
                    <div class="section-title">
                        <i class="fas fa-user-clock"></i>
                        Not Submitted Yet
                        <span class="badge badge-warning"><?php echo count($unsubmitted); ?> students</span>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 0.5rem;">
                        <?php foreach ($unsubmitted as $student): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; 
                                    padding: 0.5rem; background: var(--light); border-radius: var(--border-radius-sm);">
                            <span>
                                <strong><?php echo htmlspecialchars($student['full_name']); ?></strong>
                                <small style="color: var(--gray); display: block;"><?php echo $student['admission_number']; ?></small>
                            </span>
                            <div style="display:flex; gap:0.5rem;">
                                <button type="button"
                                        onclick="openSubmissionModal(<?php echo $assignment['id']; ?>, <?php echo $student['id']; ?>, '<?php echo htmlspecialchars(addslashes($student['full_name'])); ?>', '<?php echo htmlspecialchars(addslashes($student['admission_number'])); ?>')"
                                        class="btn btn-success btn-sm" title="Record Submission">
                                    <i class="fas fa-file-circle-check"></i>
                                </button>
                                <button onclick="sendReminder(<?php echo $assignment['id']; ?>, <?php echo $student['id']; ?>)" 
                                        class="btn btn-warning btn-sm" title="Send Reminder">
                                    <i class="fas fa-bell"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div style="margin-top: 2rem; display: flex; gap: 1rem; justify-content: flex-end;">
                    <a href="?action=edit&id=<?php echo $assignment['id']; ?>" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit Assignment
                    </a>
                    <a href="?action=grade&id=<?php echo $assignment['id']; ?>" class="btn btn-success">
                        <i class="fas fa-check-double"></i> Grade All
                    </a>
                    <a href="?action=list" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>

        <?php elseif ($action === 'grade' && $assignment): ?>
            <!-- Grade All Submissions -->
            <div class="assignment-view animate-fade-up">
                <h2 class="form-title">
                    <i class="fas fa-check-double"></i>
                    Grade Submissions: <?php echo htmlspecialchars($assignment['title']); ?>
                </h2>
                
                <div class="assignment-info-grid" style="grid-template-columns: repeat(3, 1fr);">
                    <div class="info-item">
                        <span class="info-label">Class</span>
                        <span class="info-value"><?php echo htmlspecialchars($assignment['class_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Subject</span>
                        <span class="info-value"><?php echo htmlspecialchars($assignment['subject_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Total Marks</span>
                        <span class="info-value"><?php echo $assignment['total_marks']; ?></span>
                    </div>
                </div>
                
                <?php if (!empty($submissions)): ?>
                <form method="POST" id="bulkGradeForm">
                    <table class="submissions-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Submitted</th>
                                <th>Status</th>
                                <th>Marks</th>
                                <th>Feedback</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($submissions as $submission): ?>
                            <tr>
                                <td>
                                    <div class="student-info">
                                        <span class="student-name"><?php echo htmlspecialchars($submission['student_name']); ?></span>
                                        <span class="student-adm"><?php echo htmlspecialchars($submission['admission_number']); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <?php echo date('M j, Y H:i', strtotime($submission['submitted_at'])); ?>
                                    <?php if ($submission['submission_status'] === 'late'): ?>
                                        <span class="badge badge-danger">Late</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($submission['status'] === 'graded'): ?>
                                        <span class="badge badge-success">Graded</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($submission['status'] === 'graded'): ?>
                                        <strong><?php echo $submission['marks']; ?></strong> / <?php echo $assignment['total_marks']; ?>
                                    <?php else: ?>
                                        <input type="number" name="marks[<?php echo $submission['id']; ?>]" 
                                               class="grade-input" placeholder="Marks" 
                                               min="0" max="<?php echo $assignment['total_marks']; ?>">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($submission['status'] === 'graded'): ?>
                                        <div class="feedback-text">
                                            <?php echo htmlspecialchars($submission['feedback'] ?? 'No feedback'); ?>
                                        </div>
                                    <?php else: ?>
                                        <input type="text" name="feedback[<?php echo $submission['id']; ?>]" 
                                               class="form-control" style="width: 150px;" 
                                               placeholder="Feedback (optional)">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($submission['status'] === 'graded'): ?>
                                        <button type="button" onclick="editGrade(<?php echo $submission['id']; ?>, <?php echo $submission['marks']; ?>, '<?php echo htmlspecialchars(addslashes($submission['feedback'] ?? '')); ?>')" 
                                                class="btn btn-warning btn-icon" title="Edit Grade">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" onclick="gradeSingle(<?php echo $submission['id']; ?>)" 
                                                class="btn btn-success btn-icon" title="Grade">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div style="margin-top: 2rem; display: flex; gap: 1rem; justify-content: flex-end;">
                        <a href="?action=view&id=<?php echo $assignment['id']; ?>" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                    </div>
                </form>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No submissions to grade</p>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i>
                    Confirm Deletion
                </h3>
                <button class="btn-close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p id="deleteMessage">Are you sure you want to delete this assignment? This action cannot be undone and will also delete all student submissions.</p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteForm">
                    <input type="hidden" name="assignment_id" id="deleteAssignmentId">
                    <input type="hidden" name="delete_assignment" value="1">
                    <button type="button" class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Assignment
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Grade Single Modal -->
    <div id="gradeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class="fas fa-check-circle" style="color: var(--success);"></i>
                    Grade Submission
                </h3>
                <button class="btn-close" onclick="closeGradeModal()">&times;</button>
            </div>
            <form method="POST" id="gradeForm">
                <div class="modal-body">
                    <input type="hidden" name="submission_id" id="gradeSubmissionId">
                    <input type="hidden" name="grade_submission" value="1">
                    
                    <div class="form-group">
                        <label for="gradeMarks">Marks *</label>
                        <input type="number" id="gradeMarks" name="marks" class="form-control" 
                               required min="0" max="<?php echo $assignment['total_marks'] ?? 100; ?>">
                        <div class="form-text">Maximum marks: <?php echo $assignment['total_marks'] ?? 100; ?></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="gradeFeedback">Feedback</label>
                        <textarea id="gradeFeedback" name="feedback" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeGradeModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Grade
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Manual Submission Modal -->
    <div id="submissionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class="fas fa-file-circle-check" style="color: var(--success);"></i>
                    Record Student Submission
                </h3>
                <button class="btn-close" onclick="closeSubmissionModal()">&times;</button>
            </div>
            <form method="POST" id="submissionForm">
                <div class="modal-body">
                    <input type="hidden" name="assignment_id" id="submissionAssignmentId">
                    <input type="hidden" name="student_id" id="submissionStudentId">
                    <input type="hidden" name="record_manual_submission" value="1">

                    <div class="form-group">
                        <label>Student</label>
                        <input type="text" id="submissionStudentName" class="form-control" readonly>
                    </div>

                    <div class="form-group">
                        <label>Admission Number</label>
                        <input type="text" id="submissionStudentAdm" class="form-control" readonly>
                    </div>

                    <div class="form-group">
                        <label for="submissionText">Submission Notes</label>
                        <textarea id="submissionText" name="submission_text" class="form-control" rows="4"
                                  placeholder="Optional note about the physical submission, workbook handed in, printed paper received, or remarks from the student."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeSubmissionModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Submission
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Update time if element exists
        function updateTime() {
            const timeEl = document.getElementById('currentTime');
            if (timeEl) {
                const now = new Date();
                timeEl.textContent = now.toLocaleTimeString('en-US', { 
                    hour: '2-digit', 
                    minute: '2-digit', 
                    second: '2-digit',
                    hour12: true 
                });
            }
        }
        updateTime();
        setInterval(updateTime, 1000);

        // Filter assignments
        function filterAssignments() {
            const classFilter = document.getElementById('classFilter')?.value;
            const statusFilter = document.getElementById('statusFilter')?.value;
            const searchFilter = document.getElementById('searchFilter')?.value.toLowerCase();
            const cards = document.querySelectorAll('.assignment-card');
            
            cards.forEach(card => {
                const cardClass = card.dataset.class;
                const cardStatus = card.dataset.status;
                const cardTitle = card.dataset.title;
                
                let classMatch = classFilter === 'all' || cardClass == classFilter;
                let statusMatch = statusFilter === 'all' || cardStatus === statusFilter;
                let searchMatch = !searchFilter || cardTitle.includes(searchFilter);
                
                if (classMatch && statusMatch && searchMatch) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // Load subjects based on selected class
        function loadSubjects() {
            const classId = document.getElementById('class_id')?.value;
            const subjectSelect = document.getElementById('subject_id');
            if (!subjectSelect) return;
            
            const options = subjectSelect.options;
            for (let option of options) {
                if (option.value === '') continue;
                if (classId === 'all' || option.dataset.class == classId || !classId) {
                    option.style.display = 'block';
                } else {
                    option.style.display = 'none';
                    if (option.selected) option.selected = false;
                }
            }
        }

        // Form validation
        function validateForm() {
            const title = document.getElementById('title')?.value.trim();
            const classId = document.getElementById('class_id')?.value;
            const subjectId = document.getElementById('subject_id')?.value;
            const dueDate = document.getElementById('due_date')?.value;
            const totalMarks = document.getElementById('total_marks')?.value;
            const passingMarks = document.getElementById('passing_marks')?.value;
            
            if (!title) {
                Swal.fire('Error', 'Please enter an assignment title', 'error');
                return false;
            }
            
            if (!classId) {
                Swal.fire('Error', 'Please select a class', 'error');
                return false;
            }
            
            if (!subjectId) {
                Swal.fire('Error', 'Please select a subject', 'error');
                return false;
            }
            
            if (!dueDate) {
                Swal.fire('Error', 'Please select a due date', 'error');
                return false;
            }
            
            if (parseInt(passingMarks) > parseInt(totalMarks)) {
                Swal.fire('Error', 'Passing marks cannot exceed total marks', 'error');
                return false;
            }
            
            return true;
        }

        // Confirm delete
        function confirmDelete(assignmentId, title) {
            document.getElementById('deleteAssignmentId').value = assignmentId;
            document.getElementById('deleteMessage').innerHTML = 
                `Are you sure you want to delete "<strong>${title}</strong>"? This will also delete all student submissions.`;
            document.getElementById('deleteModal').classList.add('active');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }

        // Grade functions
        function gradeSingle(submissionId) {
            document.getElementById('gradeSubmissionId').value = submissionId;
            document.getElementById('gradeMarks').value = '';
            document.getElementById('gradeFeedback').value = '';
            document.getElementById('gradeModal').classList.add('active');
        }

        function editGrade(submissionId, marks, feedback) {
            document.getElementById('gradeSubmissionId').value = submissionId;
            document.getElementById('gradeMarks').value = marks;
            document.getElementById('gradeFeedback').value = feedback;
            document.getElementById('gradeModal').classList.add('active');
        }

        function closeGradeModal() {
            document.getElementById('gradeModal').classList.remove('active');
        }

        function openSubmissionModal(assignmentId, studentId, studentName, admissionNumber) {
            document.getElementById('submissionAssignmentId').value = assignmentId;
            document.getElementById('submissionStudentId').value = studentId;
            document.getElementById('submissionStudentName').value = studentName;
            document.getElementById('submissionStudentAdm').value = admissionNumber;
            document.getElementById('submissionText').value = '';
            document.getElementById('submissionModal').classList.add('active');
        }

        function closeSubmissionModal() {
            document.getElementById('submissionModal').classList.remove('active');
        }

        // Validate grade
        function validateGrade(form) {
            const marks = form.querySelector('input[name="marks"]').value;
            const maxMarks = <?php echo $assignment['total_marks'] ?? 100; ?>;
            
            if (marks < 0 || marks > maxMarks) {
                Swal.fire('Error', `Marks must be between 0 and ${maxMarks}`, 'error');
                return false;
            }
            return true;
        }

        // Send reminder
        function sendReminder(assignmentId, studentId) {
            Swal.fire({
                title: 'Send Reminder?',
                text: 'This will send a reminder to the student about the pending assignment.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#4361ee',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, send reminder'
            }).then((result) => {
                if (result.isConfirmed) {
                    // In a real app, you would make an AJAX call here
                    Swal.fire('Success', 'Reminder sent successfully!', 'success');
                }
            });
        }

        // Close modals when clicking outside
        document.addEventListener('click', function(e) {
            const deleteModal = document.getElementById('deleteModal');
            const gradeModal = document.getElementById('gradeModal');
            const submissionModal = document.getElementById('submissionModal');
            
            if (e.target === deleteModal) {
                closeDeleteModal();
            }
            if (e.target === gradeModal) {
                closeGradeModal();
            }
            if (e.target === submissionModal) {
                closeSubmissionModal();
            }
        });

        // Close modals on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDeleteModal();
                closeGradeModal();
            }
        });

        // Initialize subject filter on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadSubjects();
        });
    </script>
</body>
</html>
