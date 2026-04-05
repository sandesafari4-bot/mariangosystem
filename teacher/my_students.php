<?php
include '../config.php';
checkAuth();
checkRole(['teacher']);

// Get current teacher's ID
$teacher_id = $_SESSION['user_id'];

// Helper function for letter grade
function getLetterGrade($marks) {
    if ($marks === null || $marks === '' || $marks === 'N/A') return 'N/A';
    $marks = floatval($marks);
    if ($marks >= 90) return 'A';
    if ($marks >= 80) return 'B';
    if ($marks >= 70) return 'C';
    if ($marks >= 60) return 'D';
    return 'F';
}

// Helper function to format time
function formatTime($time) {
    return $time ? date('h:i A', strtotime($time)) : '';
}

function teacherMyStudentsGetTableColumns(PDO $pdo, string $table): array {
    static $cache = [];

    if (!isset($cache[$table])) {
        $cache[$table] = [];
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $cache[$table][] = $column['Field'];
            }
        } catch (Throwable $e) {
            error_log("Failed to read columns for {$table}: " . $e->getMessage());
        }
    }

    return $cache[$table];
}

function teacherMyStudentsHasColumn(PDO $pdo, string $table, string $column): bool {
    return in_array($column, teacherMyStudentsGetTableColumns($pdo, $table), true);
}

function teacherMyStudentsLatestExamAverageExpression(PDO $pdo, string $studentAlias = 's'): string {
    $valueColumn = teacherMyStudentsHasColumn($pdo, 'exam_grades', 'average_marks') ? 'average_marks' : 'percentage';
    return "(SELECT eg.{$valueColumn}
        FROM exam_grades eg
        LEFT JOIN exams e ON e.id = eg.exam_id
        WHERE eg.student_id = {$studentAlias}.id
        ORDER BY COALESCE(e.created_at, eg.id) DESC, eg.id DESC
        LIMIT 1)";
}

function teacherMyStudentsLatestExamGradeExpression(string $studentAlias = 's'): string {
    return "(SELECT eg.grade
        FROM exam_grades eg
        LEFT JOIN exams e ON e.id = eg.exam_id
        WHERE eg.student_id = {$studentAlias}.id
        ORDER BY COALESCE(e.created_at, eg.id) DESC, eg.id DESC
        LIMIT 1)";
}

function teacherMyStudentsLatestExamNameExpression(string $studentAlias = 's'): string {
    return "(SELECT e.exam_name
        FROM exam_grades eg
        LEFT JOIN exams e ON e.id = eg.exam_id
        WHERE eg.student_id = {$studentAlias}.id
        ORDER BY COALESCE(e.created_at, eg.id) DESC, eg.id DESC
        LIMIT 1)";
}

function formatExamAverageGradeDisplay($average, $grade): string {
    if ($average === null || $average === '' || (float) $average <= 0) {
        return 'N/A';
    }

    $display = round((float) $average, 1) . '%';
    if (!empty($grade) && $grade !== 'N/A') {
        $display .= ' (' . strtoupper((string) $grade) . ')';
    }

    return $display;
}

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['add_assignment'])) {
            $title = trim($_POST['title']);
            $description = trim($_POST['description']);
            $class_id = intval($_POST['class_id']);
            $due_date = $_POST['due_date'];
            $max_marks = intval($_POST['max_marks'] ?? 100);
            $assignment_type = $_POST['assignment_type'] ?? 'homework';
            
            $stmt = $pdo->prepare("INSERT INTO assignments (title, description, class_id, teacher_id, due_date, max_marks, assignment_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$title, $description, $class_id, $teacher_id, $due_date, $max_marks, $assignment_type])) {
                $assignment_id = $pdo->lastInsertId();
                
                // Create an event for this assignment deadline
                $event_title = "Assignment Due: " . $title;
                $event_description = $description . "\n\nAssignment Due Date";
                $event_stmt = $pdo->prepare("INSERT INTO events (title, description, event_date, event_type, target_audience, class_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $event_stmt->execute([
                    $event_title,
                    $event_description,
                    $due_date,
                    'assignment_due',
                    'class_' . $class_id,
                    $class_id,
                    $teacher_id
                ]);
                
                $message = "Assignment created successfully!";
                $message_type = 'success';
            } else {
                $message = "Failed to create assignment. Please try again.";
                $message_type = 'error';
            }
        }
        
        if (isset($_POST['mark_attendance'])) {
            if (isset($_POST['bulk_attendance'])) {
                // Bulk attendance marking
                $class_id = intval($_POST['class_id']);
                $date = $_POST['date'];
                $attendance_data = $_POST['attendance'] ?? [];
                
                if (empty($attendance_data)) {
                    throw new Exception("No attendance data provided");
                }
                
                $pdo->beginTransaction();
                try {
                    $success_count = 0;
                    foreach ($attendance_data as $student_id => $status_data) {
                        if (empty($status_data['status'])) continue;
                        
                        $status = $status_data['status'];
                        $remarks = $status_data['remarks'] ?? '';
                        
                        $check_stmt = $pdo->prepare("SELECT id FROM attendance WHERE student_id = ? AND date = ?");
                        $check_stmt->execute([$student_id, $date]);
                        
                        if ($check_stmt->rowCount() > 0) {
                            $update_stmt = $pdo->prepare("UPDATE attendance SET status = ?, remarks = ?, recorded_by = ? WHERE student_id = ? AND date = ?");
                            $update_stmt->execute([$status, $remarks, $teacher_id, $student_id, $date]);
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO attendance (student_id, date, status, remarks, recorded_by) VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([$student_id, $date, $status, $remarks, $teacher_id]);
                        }
                        $success_count++;
                    }
                    $pdo->commit();
                    $message = "Bulk attendance marked successfully for $success_count students!";
                    $message_type = 'success';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            } else {
                // Single attendance marking
                $student_id = intval($_POST['student_id']);
                $date = $_POST['date'];
                $status = $_POST['status'];
                $remarks = $_POST['remarks'] ?? '';
                
                $check_stmt = $pdo->prepare("SELECT id FROM attendance WHERE student_id = ? AND date = ?");
                $check_stmt->execute([$student_id, $date]);
                
                if ($check_stmt->rowCount() > 0) {
                    $update_stmt = $pdo->prepare("UPDATE attendance SET status = ?, remarks = ?, recorded_by = ? WHERE student_id = ? AND date = ?");
                    if ($update_stmt->execute([$status, $remarks, $teacher_id, $student_id, $date])) {
                        $message = "Attendance updated successfully!";
                        $message_type = 'success';
                    }
                } else {
                    $stmt = $pdo->prepare("INSERT INTO attendance (student_id, date, status, remarks, recorded_by) VALUES (?, ?, ?, ?, ?)");
                    if ($stmt->execute([$student_id, $date, $status, $remarks, $teacher_id])) {
                        $message = "Attendance marked successfully!";
                        $message_type = 'success';
                    }
                }
            }
        }
        
        if (isset($_POST['record_grade'])) {
            $student_id = intval($_POST['student_id']);
            $subject_id = intval($_POST['subject_id']);
            $term = $_POST['term'];
            $marks = floatval($_POST['marks']);
            $remarks = $_POST['remarks'] ?? '';
            
            if ($marks < 0 || $marks > 100) {
                throw new Exception("Marks must be between 0 and 100");
            }
            
            $stmt = $pdo->prepare("INSERT INTO grades (student_id, subject_id, term, marks, remarks) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE marks = ?, remarks = ?, updated_at = NOW()");
            if ($stmt->execute([$student_id, $subject_id, $term, $marks, $remarks, $marks, $remarks])) {
                $message = "Grade recorded successfully!";
                $message_type = 'success';
            } else {
                throw new Exception("Failed to record grade");
            }
        }
        
        if (isset($_POST['add_note'])) {
            $student_id = intval($_POST['student_id']);
            $note = trim($_POST['note']);
            $note_type = $_POST['note_type'];
            
            if (empty($note)) {
                throw new Exception("Note cannot be empty");
            }
            
            $stmt = $pdo->prepare("INSERT INTO student_notes (student_id, teacher_id, note, note_type) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$student_id, $teacher_id, $note, $note_type])) {
                $message = "Note added successfully!";
                $message_type = 'success';
            } else {
                throw new Exception("Failed to add note");
            }
        }
        
        if (isset($_POST['create_event'])) {
            $title = trim($_POST['title']);
            $description = trim($_POST['description']);
            $event_date = $_POST['event_date'];
            $start_time = $_POST['start_time'] ?: null;
            $end_time = $_POST['end_time'] ?: null;
            $location = trim($_POST['location'] ?? '');
            $event_type = $_POST['event_type'];
            $target_audience = trim($_POST['target_audience']);
            
            if (empty($title) || empty($event_date) || empty($target_audience)) {
                throw new Exception("Required fields are missing");
            }
            
            $class_id = null;
            // Parse audience to extract class if applicable
            if (preg_match('/class\s*(\d+[a-zA-Z]*)/i', $target_audience, $matches)) {
                $stmt = $pdo->prepare("SELECT id FROM classes WHERE class_name LIKE ?");
                $stmt->execute(['%' . $matches[1] . '%']);
                $class_id = $stmt->fetchColumn();
            }
            
            $stmt = $pdo->prepare("INSERT INTO events (title, description, event_date, start_time, end_time, location, event_type, target_audience, class_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$title, $description, $event_date, $start_time, $end_time, $location, $event_type, $target_audience, $class_id, $teacher_id])) {
                $message = "Event created successfully!";
                $message_type = 'success';
            } else {
                throw new Exception("Failed to create event");
            }
        }
        
        // Redirect to prevent form resubmission
        header("Location: my_students.php?" . ($message ? "msg=" . urlencode($message) . "&type=" . $message_type : ""));
        exit();
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = 'error';
    }
}

// Handle GET actions
if (isset($_GET['action'])) {
    try {
        switch ($_GET['action']) {
            case 'delete_note':
                $note_id = intval($_GET['id']);
                $stmt = $pdo->prepare("DELETE FROM student_notes WHERE id = ? AND teacher_id = ?");
                if ($stmt->execute([$note_id, $teacher_id])) {
                    $message = "Note deleted successfully!";
                    $message_type = 'success';
                }
                break;
                
            case 'export_students':
                $class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
                exportStudentsToCSV($teacher_id, $class_id);
                break;
        }
        
        if ($message) {
            header("Location: my_students.php?msg=" . urlencode($message) . "&type=" . $message_type);
            exit();
        }
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = 'error';
    }
}

// Check if we're viewing a specific student
$view_student_id = isset($_GET['view_student']) ? intval($_GET['view_student']) : 0;
$current_student = null;
$student_notes = [];
$student_attendance = [];
$student_grades = [];
$student_assignments = [];

if ($view_student_id) {
    // Get student details
    $studentExamAverageExpr = teacherMyStudentsLatestExamAverageExpression($pdo, 's');
    $studentExamGradeExpr = teacherMyStudentsLatestExamGradeExpression('s');
    $studentExamNameExpr = teacherMyStudentsLatestExamNameExpression('s');
    $stmt = $pdo->prepare("SELECT s.*, c.class_name, '' as class_section,
                                  {$studentExamAverageExpr} as avg_grade,
                                  {$studentExamGradeExpr} as avg_grade_letter,
                                  {$studentExamNameExpr} as avg_grade_exam_name
                          FROM students s 
                          JOIN classes c ON s.class_id = c.id 
                          WHERE s.id = ? AND (c.class_teacher_id = ? OR EXISTS (
                              SELECT 1 FROM subjects sub WHERE sub.class_id = c.id AND sub.teacher_id = ?
                          ))");
    $stmt->execute([$view_student_id, $teacher_id, $teacher_id]);
    $current_student = $stmt->fetch();
    
    if ($current_student) {
        // Get student notes
        $notes_stmt = $pdo->prepare("SELECT n.*, u.full_name as teacher_name 
                                   FROM student_notes n 
                                   JOIN users u ON n.teacher_id = u.id 
                                   WHERE n.student_id = ? 
                                   ORDER BY n.created_at DESC");
        $notes_stmt->execute([$view_student_id]);
        $student_notes = $notes_stmt->fetchAll();
        
        // Get student attendance for last 30 days
        $attendance_stmt = $pdo->prepare("SELECT * FROM attendance 
                                         WHERE student_id = ? 
                                         AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) 
                                         ORDER BY date DESC");
        $attendance_stmt->execute([$view_student_id]);
        $student_attendance = $attendance_stmt->fetchAll();
        
        // Get student grades
        $grades_stmt = $pdo->prepare("SELECT g.*, s.subject_name 
                                    FROM grades g 
                                    JOIN subjects s ON g.subject_id = s.id 
                                    WHERE g.student_id = ? 
                                    ORDER BY g.term, s.subject_name");
        $grades_stmt->execute([$view_student_id]);
        $student_grades = $grades_stmt->fetchAll();
        
        // Get student assignments
        $assignments_stmt = $pdo->prepare("SELECT a.*, c.class_name, sub.subject_name,
                                                 sa.status as submission_status, sa.submitted_at,
                                                 {$studentAssignmentMarksColumn} as obtained_marks,
                                                 {$studentAssignmentFeedbackColumn} as feedback
                                          FROM assignments a 
                                          JOIN classes c ON a.class_id = c.id 
                                          LEFT JOIN subjects sub ON a.subject_id = sub.id
                                          LEFT JOIN student_assignments sa ON a.id = sa.assignment_id AND sa.student_id = ? 
                                          WHERE a.class_id = ? 
                                          ORDER BY a.due_date DESC");
        $assignments_stmt->execute([$view_student_id, $current_student['class_id']]);
        $student_assignments = $assignments_stmt->fetchAll();
    }
}

// Get teacher-specific statistics
function getTeacherStudentsCount($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT s.id) 
                              FROM students s 
                              JOIN classes c ON s.class_id = c.id 
                              LEFT JOIN subjects sub ON c.id = sub.class_id 
                              WHERE (sub.teacher_id = ? OR c.class_teacher_id = ?) AND s.status = 'active'");
        $stmt->execute([$teacher_id, $teacher_id]);
        return $stmt->fetchColumn() ?: 0;
    } catch (PDOException $e) {
        error_log("Error getting teacher students count: " . $e->getMessage());
        return 0;
    }
}

function getTodayAttendanceCount($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT a.id) 
                              FROM attendance a 
                              JOIN students s ON a.student_id = s.id 
                              JOIN classes c ON s.class_id = c.id 
                              LEFT JOIN subjects sub ON c.id = sub.class_id 
                              WHERE a.date = CURDATE() 
                              AND a.recorded_by = ? 
                              AND (sub.teacher_id = ? OR c.class_teacher_id = ?)");
        $stmt->execute([$teacher_id, $teacher_id, $teacher_id]);
        return $stmt->fetchColumn() ?: 0;
    } catch (PDOException $e) {
        error_log("Error getting today attendance count: " . $e->getMessage());
        return 0;
    }
}

function getPendingAssignmentsCount($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) 
                              FROM assignments a 
                              WHERE a.teacher_id = ? 
                              AND a.due_date >= CURDATE()");
        $stmt->execute([$teacher_id]);
        return $stmt->fetchColumn() ?: 0;
    } catch (PDOException $e) {
        error_log("Error getting pending assignments: " . $e->getMessage());
        return 0;
    }
}

function getTeacherClassesCount($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT c.id) 
                              FROM classes c 
                              LEFT JOIN subjects s ON c.id = s.class_id 
                              WHERE s.teacher_id = ? OR c.class_teacher_id = ?");
        $stmt->execute([$teacher_id, $teacher_id]);
        return $stmt->fetchColumn() ?: 0;
    } catch (PDOException $e) {
        error_log("Error getting teacher classes count: " . $e->getMessage());
        return 0;
    }
}

function getTotalSubmissionsCount($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(sa.id) 
                              FROM student_assignments sa 
                              JOIN assignments a ON sa.assignment_id = a.id 
                              WHERE a.teacher_id = ? 
                              AND sa.submitted_at IS NOT NULL");
        $stmt->execute([$teacher_id]);
        return $stmt->fetchColumn() ?: 0;
    } catch (PDOException $e) {
        error_log("Error getting total submissions: " . $e->getMessage());
        return 0;
    }
}

$stats = [
    'total_students' => getTeacherStudentsCount($teacher_id),
    'attendance_taken' => getTodayAttendanceCount($teacher_id),
    'pending_assignments' => getPendingAssignmentsCount($teacher_id),
    'total_classes' => getTeacherClassesCount($teacher_id),
    'total_submissions' => getTotalSubmissionsCount($teacher_id)
];

// Get filter parameters
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : '';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'active';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'name';
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'grid';

// Get teacher's assigned classes
function getTeacherClasses($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT c.*, u.full_name as class_teacher_name 
                              FROM classes c 
                              LEFT JOIN users u ON c.class_teacher_id = u.id 
                              WHERE c.class_teacher_id = ? OR EXISTS (
                                  SELECT 1 FROM subjects s WHERE s.class_id = c.id AND s.teacher_id = ?
                              )
                              ORDER BY c.class_name");
        $stmt->execute([$teacher_id, $teacher_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting teacher classes: " . $e->getMessage());
        return [];
    }
}

// Get teacher's subjects
function getTeacherSubjects($teacher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT s.*, c.class_name 
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

// Get upcoming events
function getTeacherEvents($teacher_id, $limit = 5) {
    global $pdo;
    try {
        $sql = "SELECT e.*, c.class_name 
                FROM events e 
                LEFT JOIN classes c ON e.class_id = c.id 
                WHERE (
                    e.created_by = :teacher_id 
                    OR e.target_audience LIKE '%all_teachers%' 
                    OR e.class_id IN (
                        SELECT DISTINCT c.id 
                        FROM classes c 
                        LEFT JOIN subjects s ON c.id = s.class_id 
                        WHERE s.teacher_id = :teacher_id2 
                           OR c.class_teacher_id = :teacher_id3
                    )
                )
                AND e.event_date >= CURDATE()
                ORDER BY e.event_date ASC 
                LIMIT :limit";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':teacher_id', $teacher_id, PDO::PARAM_INT);
        $stmt->bindValue(':teacher_id2', $teacher_id, PDO::PARAM_INT);
        $stmt->bindValue(':teacher_id3', $teacher_id, PDO::PARAM_INT);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting teacher events: " . $e->getMessage());
        return [];
    }
}

$teacher_classes = getTeacherClasses($teacher_id);
$subjects = getTeacherSubjects($teacher_id);
$events = getTeacherEvents($teacher_id, 5);
$latestExamAverageExpr = teacherMyStudentsLatestExamAverageExpression($pdo, 's');
$latestExamGradeExpr = teacherMyStudentsLatestExamGradeExpression('s');
$latestExamNameExpr = teacherMyStudentsLatestExamNameExpression('s');
$studentAssignmentMarksColumn = teacherMyStudentsHasColumn($pdo, 'student_assignments', 'marks_obtained')
    ? 'sa.marks_obtained'
    : (teacherMyStudentsHasColumn($pdo, 'student_assignments', 'marks') ? 'sa.marks' : 'NULL');
$studentAssignmentFeedbackColumn = teacherMyStudentsHasColumn($pdo, 'student_assignments', 'feedback')
    ? 'sa.feedback'
    : 'NULL';

// Build query for teacher's students with enhanced filtering
$query = "
    SELECT s.*, c.class_name, '' as class_section, c.id as class_id,
           (SELECT COUNT(*) FROM attendance a WHERE a.student_id = s.id AND a.status = 'Present' AND a.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as present_count,
           (SELECT COUNT(*) FROM attendance a WHERE a.student_id = s.id AND a.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as total_attendance,
           {$latestExamAverageExpr} as avg_grade,
           {$latestExamGradeExpr} as avg_grade_letter,
           {$latestExamNameExpr} as avg_grade_exam_name,
           (SELECT COUNT(*) FROM assignments a WHERE a.class_id = s.class_id AND a.due_date >= CURDATE()) as pending_assignments,
           (SELECT COUNT(*) FROM student_assignments sa WHERE sa.student_id = s.id AND sa.status IN ('submitted', 'graded')) as submitted_assignments,
           (SELECT MAX(created_at) FROM student_notes WHERE student_id = s.id) as last_note_date
    FROM students s
    JOIN classes c ON s.class_id = c.id
    WHERE (c.class_teacher_id = ? OR EXISTS (
        SELECT 1 FROM subjects sub WHERE sub.class_id = c.id AND sub.teacher_id = ?
    ))
";

$params = [$teacher_id, $teacher_id];

if ($status_filter) {
    $query .= " AND s.status = ?";
    $params[] = $status_filter;
}

if ($class_id) {
    $query .= " AND s.class_id = ?";
    $params[] = $class_id;
}

if ($search_query) {
    $query .= " AND (s.full_name LIKE ? OR s.student_id LIKE ? OR s.Admission_number LIKE ? OR s.parent_name LIKE ? OR s.parent_phone LIKE ?)";
    $search_term = "%$search_query%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

// Add sorting
switch ($sort_by) {
    case 'attendance':
        $query .= " ORDER BY (CASE WHEN total_attendance > 0 THEN present_count/total_attendance ELSE 0 END) DESC, s.full_name";
        break;
    case 'grade':
        $query .= " ORDER BY avg_grade DESC, s.full_name";
        break;
    case 'class':
        $query .= " ORDER BY c.class_name, s.full_name";
        break;
    default:
        $query .= " ORDER BY s.full_name";
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Get recent assignments
$recent_assignments = $pdo->prepare("
    SELECT a.*, c.class_name, COUNT(sa.id) as submissions,
           (SELECT COUNT(DISTINCT student_id) FROM students WHERE class_id = a.class_id AND status='active') as total_students
    FROM assignments a
    JOIN classes c ON a.class_id = c.id
    LEFT JOIN student_assignments sa ON a.id = sa.assignment_id
    WHERE a.teacher_id = ?
    GROUP BY a.id
    ORDER BY a.due_date DESC
    LIMIT 5
");
$recent_assignments->execute([$teacher_id]);
$assignments = $recent_assignments->fetchAll();

// Export function
function exportStudentsToCSV($teacher_id, $class_id = 0) {
    global $pdo;
    
    try {
        $latestExamAverageExpr = teacherMyStudentsLatestExamAverageExpression($pdo, 's');
        $latestExamGradeExpr = teacherMyStudentsLatestExamGradeExpression('s');
        $query = "SELECT s.full_name, s.student_id, s.Admission_number, s.gender, s.date_of_birth,
                         s.parent_name, s.parent_phone, s.parent_email, s.address,
                         c.class_name,
                         {$latestExamAverageExpr} as avg_grade,
                         {$latestExamGradeExpr} as avg_grade_letter,
                         (SELECT COUNT(*) FROM attendance a WHERE a.student_id = s.id AND a.status = 'Present') as total_present
                  FROM students s
                  JOIN classes c ON s.class_id = c.id
                  WHERE (c.class_teacher_id = ? OR EXISTS (SELECT 1 FROM subjects sub WHERE sub.class_id = c.id AND sub.teacher_id = ?))";
        
        $params = [$teacher_id, $teacher_id];
        
        if ($class_id) {
            $query .= " AND s.class_id = ?";
            $params[] = $class_id;
        }
        
        $query .= " ORDER BY c.class_name, s.full_name";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $students = $stmt->fetchAll();
        
        if (empty($students)) {
            throw new Exception("No students found to export");
        }
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=students_' . date('Y-m-d') . '.csv');
        
        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM for proper Excel encoding
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Headers
        fputcsv($output, [
            'Full Name', 'Student ID', 'Admission Number', 'Gender', 'Date of Birth',
            'Parent Name', 'Parent Phone', 'Parent Email', 'Address',
            'Class', 'Average Grade (%)', 'Total Present Days'
        ]);
        
        // Data
        foreach ($students as $student) {
            fputcsv($output, [
                $student['full_name'],
                $student['student_id'],
                $student['Admission_number'],
                $student['gender'],
                $student['date_of_birth'],
                $student['parent_name'],
                $student['parent_phone'],
                $student['parent_email'],
                $student['address'],
                $student['class_name'],
                formatExamAverageGradeDisplay($student['avg_grade'] ?? null, $student['avg_grade_letter'] ?? null),
                $student['total_present']
            ]);
        }
        
        fclose($output);
        exit();
        
    } catch (Exception $e) {
        error_log("Export error: " . $e->getMessage());
        return false;
    }
}

$page_title = $current_student ? $current_student['full_name'] . " - Student Profile" : "My Students - " . SCHOOL_NAME;
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
            --gradient-info: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
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
            box-shadow: var(--shadow-lg);
            border-left: 4px solid;
        }

        .alert-success {
            border-left-color: var(--success);
            background: linear-gradient(135deg, #e8f5e9 0%, #f1f8e9 100%);
        }

        .alert-error {
            border-left-color: var(--danger);
            background: linear-gradient(135deg, #ffebee 0%, #ffefef 100%);
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

        .close-alert {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: inherit;
            opacity: 0.6;
            transition: var(--transition);
        }

        .close-alert:hover {
            opacity: 1;
            transform: rotate(90deg);
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
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
        }

        .page-header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.3rem;
        }

        .page-header p {
            color: var(--gray);
            font-size: 0.95rem;
        }

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
            gap: 0.6rem;
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
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        .btn-xs {
            padding: 0.3rem 0.8rem;
            font-size: 0.8rem;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }

        .btn-success {
            background: var(--gradient-success);
            color: white;
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.4);
        }

        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(79, 172, 254, 0.5);
        }

        .btn-warning {
            background: var(--gradient-warning);
            color: white;
            box-shadow: 0 4px 15px rgba(250, 112, 154, 0.4);
        }

        .btn-warning:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(250, 112, 154, 0.5);
        }

        .btn-danger {
            background: var(--gradient-danger);
            color: white;
            box-shadow: 0 4px 15px rgba(240, 147, 251, 0.4);
        }

        .btn-danger:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(240, 147, 251, 0.5);
        }

        .btn-info {
            background: var(--gradient-info);
            color: white;
            box-shadow: 0 4px 15px rgba(67, 233, 123, 0.4);
        }

        .btn-info:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(67, 233, 123, 0.5);
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

        .btn-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Stats Grid */
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
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card:hover::before {
            width: 6px;
        }

        .stat-card.students::before { background: var(--gradient-danger); }
        .stat-card.attendance::before { background: var(--gradient-primary); }
        .stat-card.classes::before { background: var(--gradient-warning); }
        .stat-card.assignments::before { background: var(--gradient-success); }
        .stat-card.submissions::before { background: var(--gradient-info); }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .stat-info h3 {
            font-size: 0.9rem;
            color: var(--gray);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            line-height: 1.2;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: var(--border-radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: white;
        }

        .stat-card.students .stat-icon { background: var(--gradient-danger); }
        .stat-card.attendance .stat-icon { background: var(--gradient-primary); }
        .stat-card.classes .stat-icon { background: var(--gradient-warning); }
        .stat-card.assignments .stat-icon { background: var(--gradient-success); }
        .stat-card.submissions .stat-icon { background: var(--gradient-info); }

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
            background: var(--white);
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }

        .sidebar-column {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .sidebar-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            transition: var(--transition);
        }

        .sidebar-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-xl);
        }

        .card-header {
            padding: 1.2rem 1.5rem;
            border-bottom: 2px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }

        .card-header h3 {
            color: var(--dark);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
            font-weight: 600;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Filters Section */
        .filters-section {
            padding: 1.5rem;
            border-bottom: 2px solid var(--light);
            background: linear-gradient(135deg, #f8f9fa 0%, #f1f3f5 100%);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .filter-group label {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.85rem;
        }

        .filter-input, .filter-select {
            padding: 0.6rem 1rem;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-md);
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .filter-input:focus, .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            align-items: flex-end;
        }

        .view-toggle {
            display: flex;
            background: var(--light);
            border-radius: var(--border-radius-md);
            padding: 0.3rem;
        }

        .view-btn {
            padding: 0.5rem 1rem;
            border: none;
            background: none;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            color: var(--gray);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-weight: 500;
        }

        .view-btn.active {
            background: var(--white);
            color: var(--primary);
            box-shadow: var(--shadow-sm);
        }

        /* Students Grid */
        .students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            padding: 1.5rem;
        }

        .students-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            padding: 1.5rem;
        }

        .student-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
            border: 1px solid var(--light);
            position: relative;
        }

        .student-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary);
        }

        .student-card.featured {
            border: 2px solid var(--warning);
            background: linear-gradient(135deg, #fff9e6 0%, #fff3cd 100%);
        }

        .student-card.featured::after {
            content: '⭐ Top Performer';
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: var(--warning);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            box-shadow: 0 3px 10px rgba(248, 150, 30, 0.3);
        }

        .student-header {
            padding: 1.5rem;
            display: flex;
            gap: 1rem;
            align-items: center;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }

        .student-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            font-weight: 600;
            flex-shrink: 0;
            border: 3px solid white;
            box-shadow: var(--shadow-md);
        }

        .student-info {
            flex: 1;
            min-width: 0;
        }

        .student-name {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .inactive-badge {
            background: var(--danger);
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .student-id {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .student-class {
            display: inline-block;
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }

        .student-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
            padding: 1rem 1.5rem;
            background: var(--light);
        }

        .student-stat {
            text-align: center;
        }

        .stat-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .progress-bar {
            height: 4px;
            background: rgba(0,0,0,0.1);
            border-radius: 2px;
            overflow: hidden;
            margin-top: 0.3rem;
        }

        .progress-fill {
            height: 100%;
            background: var(--gradient-success);
            border-radius: 2px;
            transition: width 0.3s ease;
        }

        .student-actions {
            padding: 1rem 1.5rem;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            border-top: 1px solid var(--light);
        }

        /* List View */
        .student-row {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            background: var(--white);
            border-radius: var(--border-radius-md);
            transition: var(--transition);
            border: 1px solid var(--light);
        }

        .student-row:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary);
        }

        .row-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            flex-shrink: 0;
        }

        .row-info {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .row-name {
            font-weight: 600;
            color: var(--dark);
            min-width: 200px;
        }

        .row-class {
            color: var(--primary);
            font-weight: 500;
            min-width: 100px;
        }

        .row-stats {
            display: flex;
            gap: 1.5rem;
            color: var(--gray);
            font-size: 0.9rem;
        }

        .row-stats i {
            margin-right: 0.3rem;
            color: var(--primary);
        }

        .row-actions {
            display: flex;
            gap: 0.3rem;
            margin-left: auto;
        }

        /* Quick Actions Grid */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .action-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1.2rem;
            background: var(--light);
            border-radius: var(--border-radius-md);
            text-decoration: none;
            color: var(--dark);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .action-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--gradient-primary);
            opacity: 0;
            transition: var(--transition);
            z-index: 1;
        }

        .action-item:hover::before {
            opacity: 1;
        }

        .action-item i, .action-item span {
            position: relative;
            z-index: 2;
            transition: var(--transition);
        }

        .action-item:hover i,
        .action-item:hover span {
            color: white;
        }

        .action-item i {
            font-size: 1.5rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .action-item span {
            font-weight: 600;
            font-size: 0.85rem;
        }

        /* Assignment List */
        .assignment-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .assignment-item {
            padding: 1rem;
            background: var(--light);
            border-radius: var(--border-radius-md);
            border-left: 4px solid var(--primary);
            transition: var(--transition);
        }

        .assignment-item:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow-md);
            background: white;
        }

        .assignment-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.3rem;
            font-size: 0.95rem;
        }

        .assignment-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: var(--gray);
        }

        .assignment-class {
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .submission-badge {
            background: var(--success);
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* Event List */
        .event-list {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
        }

        .event-item {
            padding: 1rem;
            border-radius: var(--border-radius-md);
            border-left: 4px solid;
            background: var(--light);
            transition: var(--transition);
        }

        .event-item:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow-md);
            background: white;
        }

        .event-item.meeting { border-left-color: var(--warning); }
        .event-item.conference { border-left-color: var(--info); }
        .event-item.school_event { border-left-color: var(--success); }
        .event-item.exam { border-left-color: var(--purple); }
        .event-item.holiday { border-left-color: var(--danger); }
        .event-item.assignment_due { border-left-color: var(--primary); }

        .event-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.3rem;
            font-size: 0.95rem;
        }

        .event-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--gray);
        }

        .event-meta i {
            margin-right: 0.2rem;
            color: var(--primary);
        }

        /* Quick Stats Grid */
        .quick-stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .quick-stat {
            text-align: center;
            padding: 1rem;
            background: var(--light);
            border-radius: var(--border-radius-md);
            transition: var(--transition);
        }

        .quick-stat:hover {
            transform: translateY(-3px);
            background: var(--white);
            box-shadow: var(--shadow-md);
        }

        .quick-stat-value {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary);
        }

        .quick-stat-label {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 0.3rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            backdrop-filter: blur(5px);
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--white);
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
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .modal-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--dark);
            margin: 0;
        }

        .btn-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
            transition: var(--transition);
            width: 35px;
            height: 35px;
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
            justify-content: flex-end;
            gap: 1rem;
            background: var(--light);
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1.2rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.4rem;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 0.7rem 1rem;
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
            min-height: 80px;
            resize: vertical;
        }

        .form-hint {
            font-size: 0.8rem;
            color: var(--gray);
            margin-top: 0.3rem;
        }

        .student-badge {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: white;
            padding: 0.8rem;
            border-radius: var(--border-radius-md);
            margin-bottom: 1.5rem;
        }

        /* Student Profile Styles */
        .profile-container {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            overflow: hidden;
            box-shadow: var(--shadow-xl);
        }

        .profile-header {
            background: var(--gradient-primary);
            padding: 2rem;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .profile-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }

        .profile-top {
            display: flex;
            align-items: center;
            gap: 2rem;
            position: relative;
            z-index: 1;
            flex-wrap: wrap;
        }

        .profile-avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            border: 4px solid rgba(255,255,255,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: 700;
            color: white;
        }

        .profile-info h2 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .profile-info p {
            opacity: 0.9;
            font-size: 1.1rem;
        }

        .profile-actions {
            margin-left: auto;
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
        }

        .profile-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 2rem;
            position: relative;
            z-index: 1;
        }

        .profile-stat-item {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            padding: 1rem;
            border-radius: var(--border-radius-md);
            text-align: center;
            border: 1px solid rgba(255,255,255,0.2);
        }

        .profile-stat-item .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            margin-bottom: 0.3rem;
        }

        .profile-stat-item .stat-label {
            font-size: 0.85rem;
            opacity: 0.9;
        }

        .profile-tabs {
            padding: 1.5rem;
            border-bottom: 2px solid var(--light);
            background: var(--light);
        }

        .tab-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 0.7rem 1.5rem;
            border: none;
            background: white;
            border-radius: var(--border-radius-md);
            cursor: pointer;
            font-weight: 600;
            color: var(--gray);
            transition: var(--transition);
        }

        .tab-btn:hover {
            color: var(--primary);
            background: #f0f3f8;
        }

        .tab-btn.active {
            background: var(--primary);
            color: white;
        }

        .tab-content {
            padding: 1.5rem;
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            background: var(--light);
            padding: 0.8rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
        }

        .data-table td {
            padding: 0.8rem;
            border-bottom: 1px solid var(--light);
        }

        .data-table tr:hover td {
            background: rgba(67, 97, 238, 0.05);
        }

        .notes-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .note-item {
            padding: 1rem;
            background: var(--light);
            border-radius: var(--border-radius-md);
            border-left: 4px solid var(--primary);
        }

        .note-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
            color: var(--gray);
        }

        .note-type {
            background: var(--primary);
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .note-content {
            color: var(--dark);
            line-height: 1.6;
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .empty-state h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            margin-bottom: 1rem;
        }

        /* Back Button */
        .back-button {
            margin-bottom: 1rem;
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
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .students-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                padding: 1.5rem;
            }
            
            .page-actions {
                width: 100%;
                justify-content: flex-start;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                grid-column: span 1;
            }
            
            .student-header {
                flex-direction: column;
                text-align: center;
            }
            
            .student-avatar {
                margin: 0 auto;
            }
            
            .student-actions {
                justify-content: center;
            }
            
            .profile-top {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-actions {
                margin-left: 0;
                justify-content: center;
            }
            
            .row-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .row-actions {
                margin-left: 0;
                width: 100%;
                justify-content: flex-end;
            }
            
            .modal-content {
                margin: 1rem;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="main-content">
        <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-<?php echo isset($_GET['type']) ? $_GET['type'] : 'success'; ?> animate-fade-up">
            <div style="display: flex; align-items: center; gap: 0.8rem;">
                <i class="fas fa-<?php echo isset($_GET['type']) && $_GET['type'] === 'error' ? 'exclamation-circle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars(urldecode($_GET['msg'])); ?>
            </div>
            <button class="close-alert" onclick="this.parentElement.style.display='none'">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php endif; ?>
        
        <?php if ($current_student): ?>
        <!-- Student Profile View -->
        <div class="back-button">
            <a href="my_students.php" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Back to Students
            </a>
        </div>
        
        <div class="profile-container animate-fade-up">
            <div class="profile-header">
                <div class="profile-top">
                    <div class="profile-avatar-large">
                        <?php echo strtoupper(substr($current_student['full_name'], 0, 1)); ?>
                    </div>
                    <div class="profile-info">
                        <h2><?php echo htmlspecialchars($current_student['full_name']); ?></h2>
                        <p>
                            <i class="fas fa-id-card"></i> 
                            <?php echo htmlspecialchars($current_student['Admission_number'] ?? $current_student['student_id']); ?> • 
                            <i class="fas fa-graduation-cap"></i> 
                            <?php echo htmlspecialchars($current_student['class_name']); ?>
                        </p>
                    </div>
                    <div class="profile-actions">
                        <button class="btn btn-primary" onclick="openGradeModal(<?php echo $current_student['id']; ?>, '<?php echo htmlspecialchars(addslashes($current_student['full_name'])); ?>')">
                            <i class="fas fa-edit"></i> Add Grade
                        </button>
                        <button class="btn btn-success" onclick="openAttendanceModal(<?php echo $current_student['id']; ?>, '<?php echo htmlspecialchars(addslashes($current_student['full_name'])); ?>')">
                            <i class="fas fa-calendar-check"></i> Mark Attendance
                        </button>
                        <button class="btn btn-warning" onclick="addNote(<?php echo $current_student['id']; ?>, '<?php echo htmlspecialchars(addslashes($current_student['full_name'])); ?>')">
                            <i class="fas fa-sticky-note"></i> Add Note
                        </button>
                    </div>
                </div>
                
                <div class="profile-stats">
                    <?php 
                    $present_count = count(array_filter($student_attendance, function($a) { return $a['status'] === 'Present'; }));
                    $total_days = count($student_attendance) ?: 1;
                    $attendance_rate = round(($present_count / $total_days) * 100, 1);
                    
                    $avg_grade_display = formatExamAverageGradeDisplay(
                        $current_student['avg_grade'] ?? null,
                        $current_student['avg_grade_letter'] ?? null
                    );
                    
                    $completed = count(array_filter($student_assignments, function($a) { return in_array($a['submission_status'], ['submitted', 'graded'], true); }));
                    $total_assignments = count($student_assignments) ?: 1;
                    ?>
                    <div class="profile-stat-item">
                        <div class="stat-number"><?php echo $attendance_rate; ?>%</div>
                        <div class="stat-label">Attendance Rate</div>
                    </div>
                    <div class="profile-stat-item">
                        <div class="stat-number"><?php echo htmlspecialchars($avg_grade_display); ?></div>
                        <div class="stat-label">Average Grade</div>
                    </div>
                    <div class="profile-stat-item">
                        <div class="stat-number"><?php echo $completed; ?>/<?php echo $total_assignments; ?></div>
                        <div class="stat-label">Assignments</div>
                    </div>
                    <div class="profile-stat-item">
                        <div class="stat-number"><?php echo count($student_notes); ?></div>
                        <div class="stat-label">Notes</div>
                    </div>
                </div>
            </div>
            
            <div class="profile-tabs">
                <div class="tab-buttons">
                    <button class="tab-btn active" onclick="switchTab('overview')">Overview</button>
                    <button class="tab-btn" onclick="switchTab('grades')">Grades</button>
                    <button class="tab-btn" onclick="switchTab('attendance')">Attendance</button>
                    <button class="tab-btn" onclick="switchTab('assignments')">Assignments</button>
                    <button class="tab-btn" onclick="switchTab('notes')">Notes</button>
                </div>
            </div>
            
            <div class="tab-content">
                <!-- Overview Tab -->
                <div class="tab-pane active" id="tab-overview">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                        <div>
                            <h3 style="margin-bottom: 1rem; color: var(--dark);">Personal Information</h3>
                            <table class="data-table">
                                <tr>
                                    <th>Full Name</th>
                                    <td><?php echo htmlspecialchars($current_student['full_name']); ?></td>
                                </tr>
                                <tr>
                                    <th>Student ID</th>
                                    <td><?php echo htmlspecialchars($current_student['student_id']); ?></td>
                                </tr>
                                <tr>
                                    <th>Admission Number</th>
                                    <td><?php echo htmlspecialchars($current_student['Admission_number'] ?? 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <th>Gender</th>
                                    <td><?php echo htmlspecialchars($current_student['gender'] ?? 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <th>Date of Birth</th>
                                    <td><?php echo $current_student['date_of_birth'] ? date('M j, Y', strtotime($current_student['date_of_birth'])) : 'N/A'; ?></td>
                                </tr>
                            </table>
                        </div>
                        
                        <div>
                            <h3 style="margin-bottom: 1rem; color: var(--dark);">Parent Information</h3>
                            <table class="data-table">
                                <tr>
                                    <th>Parent Name</th>
                                    <td><?php echo htmlspecialchars($current_student['parent_name'] ?? 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <th>Parent Phone</th>
                                    <td><?php echo htmlspecialchars($current_student['parent_phone'] ?? 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <th>Parent Email</th>
                                    <td><?php echo htmlspecialchars($current_student['parent_email'] ?? 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <th>Address</th>
                                    <td><?php echo htmlspecialchars($current_student['address'] ?? 'N/A'); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <div style="margin-top: 2rem;">
                        <h3 style="margin-bottom: 1rem; color: var(--dark);">Recent Activity</h3>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                            <div style="padding: 1rem; background: var(--light); border-radius: var(--border-radius-md); text-align: center;">
                                <div style="font-size: 2rem; color: var(--primary); margin-bottom: 0.5rem;">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <div style="font-weight: 600; color: var(--dark);">Last Attendance</div>
                                <div style="font-size: 0.9rem; color: var(--gray);">
                                    <?php 
                                    $last_attendance = $student_attendance[0] ?? null;
                                    echo $last_attendance ? date('M j, Y', strtotime($last_attendance['date'])) : 'N/A';
                                    ?>
                                </div>
                            </div>
                            
                            <div style="padding: 1rem; background: var(--light); border-radius: var(--border-radius-md); text-align: center;">
                                <div style="font-size: 2rem; color: var(--success); margin-bottom: 0.5rem;">
                                    <i class="fas fa-edit"></i>
                                </div>
                                <div style="font-weight: 600; color: var(--dark);">Last Grade</div>
                                <div style="font-size: 0.9rem; color: var(--gray);">
                                    <?php 
                                    if (!empty($current_student['avg_grade'])) {
                                        echo htmlspecialchars(formatExamAverageGradeDisplay(
                                            $current_student['avg_grade'],
                                            $current_student['avg_grade_letter'] ?? null
                                        ) . (!empty($current_student['avg_grade_exam_name']) ? ' in ' . $current_student['avg_grade_exam_name'] : ''));
                                    } else {
                                        $last_grade = $student_grades[0] ?? null;
                                        echo $last_grade ? $last_grade['marks'] . '% in ' . $last_grade['subject_name'] : 'N/A';
                                    }
                                    ?>
                                </div>
                            </div>
                            
                            <div style="padding: 1rem; background: var(--light); border-radius: var(--border-radius-md); text-align: center;">
                                <div style="font-size: 2rem; color: var(--warning); margin-bottom: 0.5rem;">
                                    <i class="fas fa-sticky-note"></i>
                                </div>
                                <div style="font-weight: 600; color: var(--dark);">Last Note</div>
                                <div style="font-size: 0.9rem; color: var(--gray);">
                                    <?php 
                                    $last_note = $student_notes[0] ?? null;
                                    echo $last_note ? date('M j, Y', strtotime($last_note['created_at'])) : 'N/A';
                                    ?>
                                </div>
                            </div>
                            
                            <div style="padding: 1rem; background: var(--light); border-radius: var(--border-radius-md); text-align: center;">
                                <div style="font-size: 2rem; color: var(--danger); margin-bottom: 0.5rem;">
                                    <i class="fas fa-tasks"></i>
                                </div>
                                <div style="font-weight: 600; color: var(--dark);">Pending Assignments</div>
                                <div style="font-size: 0.9rem; color: var(--gray);">
                                    <?php echo $total_assignments - $completed; ?> pending
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Grades Tab -->
                <div class="tab-pane" id="tab-grades">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                        <h3 style="color: var(--dark);">Academic Performance</h3>
                        <button class="btn btn-primary btn-sm" onclick="openGradeModal(<?php echo $current_student['id']; ?>, '<?php echo htmlspecialchars(addslashes($current_student['full_name'])); ?>')">
                            <i class="fas fa-plus"></i> Add Grade
                        </button>
                    </div>
                    
                    <?php if (!empty($student_grades)): ?>
                    <table class="data-table">
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
                            <?php foreach ($student_grades as $grade): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($grade['subject_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($grade['term']); ?></td>
                                <td><?php echo $grade['marks']; ?>%</td>
                                <td>
                                    <span style="display: inline-block; padding: 0.3rem 0.6rem; background: <?php 
                                        echo $grade['marks'] >= 80 ? 'var(--success)' : 
                                            ($grade['marks'] >= 70 ? 'var(--primary)' : 
                                            ($grade['marks'] >= 60 ? 'var(--warning)' : 'var(--danger)')); 
                                    ?>; color: white; border-radius: 4px; font-weight: 600;">
                                        <?php echo getLetterGrade($grade['marks']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($grade['remarks'] ?? 'No remarks'); ?></td>
                                <td><?php echo date('M j, Y', strtotime($grade['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-chart-bar"></i>
                        <h3>No Grades Yet</h3>
                        <p>Add grades to track student performance</p>
                        <button class="btn btn-primary" onclick="openGradeModal(<?php echo $current_student['id']; ?>, '<?php echo htmlspecialchars(addslashes($current_student['full_name'])); ?>')">
                            <i class="fas fa-plus"></i> Add First Grade
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Attendance Tab -->
                <div class="tab-pane" id="tab-attendance">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                        <h3 style="color: var(--dark);">Attendance History</h3>
                        <button class="btn btn-success btn-sm" onclick="openAttendanceModal(<?php echo $current_student['id']; ?>, '<?php echo htmlspecialchars(addslashes($current_student['full_name'])); ?>')">
                            <i class="fas fa-plus"></i> Mark Attendance
                        </button>
                    </div>
                    
                    <?php if (!empty($student_attendance)): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Remarks</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($student_attendance as $attendance): ?>
                            <tr>
                                <td><?php echo date('M j, Y', strtotime($attendance['date'])); ?></td>
                                <td>
                                    <span style="display: inline-block; padding: 0.3rem 0.8rem; background: <?php 
                                        echo $attendance['status'] === 'Present' ? 'var(--success)' : 
                                            ($attendance['status'] === 'Late' ? 'var(--warning)' : 
                                            ($attendance['status'] === 'Excused' ? 'var(--info)' : 'var(--danger)')); 
                                    ?>; color: white; border-radius: 20px; font-size: 0.8rem; font-weight: 600;">
                                        <?php echo htmlspecialchars($attendance['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($attendance['remarks'] ?? '-'); ?></td>
                                <td>Teacher</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-check"></i>
                        <h3>No Attendance Records</h3>
                        <p>Start tracking attendance for this student</p>
                        <button class="btn btn-success" onclick="openAttendanceModal(<?php echo $current_student['id']; ?>, '<?php echo htmlspecialchars(addslashes($current_student['full_name'])); ?>')">
                            <i class="fas fa-plus"></i> Mark Attendance
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Assignments Tab -->
                <div class="tab-pane" id="tab-assignments">
                    <h3 style="margin-bottom: 1.5rem; color: var(--dark);">Assignments</h3>
                    
                    <?php if (!empty($student_assignments)): ?>
                    <div style="display: grid; gap: 1rem;">
                        <?php foreach ($student_assignments as $assignment): 
                            $is_submitted = in_array($assignment['submission_status'], ['submitted', 'graded'], true);
                            $is_overdue = strtotime($assignment['due_date']) < time() && !$is_submitted;
                        ?>
                        <div style="padding: 1.2rem; background: var(--light); border-radius: var(--border-radius-md); border-left: 4px solid <?php 
                            echo $is_submitted ? 'var(--success)' : ($is_overdue ? 'var(--danger)' : 'var(--primary)'); 
                        ?>;">
                            <div style="display: flex; justify-content: space-between; align-items: start; gap: 1rem; margin-bottom: 0.5rem;">
                                <div>
                                    <h4 style="font-weight: 600; color: var(--dark); margin: 0 0 0.35rem;"><?php echo htmlspecialchars($assignment['title']); ?></h4>
                                    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; font-size: 0.88rem; color: var(--gray);">
                                        <span><i class="fas fa-layer-group"></i> <?php echo htmlspecialchars(ucfirst($assignment['assignment_type'] ?? 'Assignment')); ?></span>
                                        <?php if (!empty($assignment['subject_name'])): ?>
                                        <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($assignment['subject_name']); ?></span>
                                        <?php endif; ?>
                                        <span><i class="fas fa-school"></i> <?php echo htmlspecialchars($assignment['class_name']); ?></span>
                                    </div>
                                </div>
                                <span style="padding: 0.2rem 0.6rem; background: <?php 
                                    echo $is_submitted ? 'var(--success)' : ($is_overdue ? 'var(--danger)' : 'var(--primary)'); 
                                ?>; color: white; border-radius: 4px; font-size: 0.8rem; font-weight: 600;">
                                    <?php echo $is_submitted ? 'Submitted' : ($is_overdue ? 'Overdue' : 'Pending'); ?>
                                </span>
                            </div>
                            
                            <div style="display: flex; gap: 1.5rem; font-size: 0.9rem; color: var(--gray); margin-bottom: 0.8rem;">
                                <span><i class="fas fa-calendar"></i> Due: <?php echo date('M j, Y', strtotime($assignment['due_date'])); ?></span>
                                <?php if ($is_submitted): ?>
                                <span><i class="fas fa-clock"></i> Submitted: <?php echo date('M j, Y', strtotime($assignment['submitted_at'])); ?></span>
                                <?php if ($assignment['obtained_marks'] !== null && $assignment['obtained_marks'] !== ''): ?>
                                <span><i class="fas fa-star"></i> Marks: <?php echo $assignment['obtained_marks']; ?>/<?php echo $assignment['max_marks']; ?></span>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($assignment['description']): ?>
                            <p style="color: var(--dark); font-size: 0.95rem;"><?php echo nl2br(htmlspecialchars($assignment['description'])); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($assignment['feedback'])): ?>
                            <div style="margin-top: 0.75rem; padding: 0.8rem 1rem; background: white; border-radius: 12px; color: var(--dark);">
                                <strong style="display:block; margin-bottom:0.25rem;">Teacher Feedback</strong>
                                <span><?php echo nl2br(htmlspecialchars($assignment['feedback'])); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-tasks"></i>
                        <h3>No Assignments</h3>
                        <p>No assignments have been created for this class yet</p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Notes Tab -->
                <div class="tab-pane" id="tab-notes">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                        <h3 style="color: var(--dark);">Teacher Notes</h3>
                        <button class="btn btn-warning btn-sm" onclick="addNote(<?php echo $current_student['id']; ?>, '<?php echo htmlspecialchars(addslashes($current_student['full_name'])); ?>')">
                            <i class="fas fa-plus"></i> Add Note
                        </button>
                    </div>
                    
                    <?php if (!empty($student_notes)): ?>
                    <div class="notes-list">
                        <?php foreach ($student_notes as $note): ?>
                        <div class="note-item">
                            <div class="note-header">
                                <span class="note-type"><?php echo ucfirst($note['note_type']); ?></span>
                                <span><i class="far fa-clock"></i> <?php echo date('M j, Y H:i', strtotime($note['created_at'])); ?></span>
                            </div>
                            <div class="note-content">
                                <?php echo nl2br(htmlspecialchars($note['note'])); ?>
                            </div>
                            <div style="margin-top: 0.8rem; font-size: 0.85rem; color: var(--gray); display: flex; justify-content: space-between;">
                                <span>By: <?php echo htmlspecialchars($note['teacher_name']); ?></span>
                                <?php if ($note['teacher_id'] == $teacher_id): ?>
                                <a href="?action=delete_note&id=<?php echo $note['id']; ?>" 
                                   onclick="return confirm('Delete this note?')" 
                                   style="color: var(--danger);">
                                    <i class="fas fa-trash"></i> Delete
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-sticky-note"></i>
                        <h3>No Notes</h3>
                        <p>Add notes to track student progress and observations</p>
                        <button class="btn btn-warning" onclick="addNote(<?php echo $current_student['id']; ?>, '<?php echo htmlspecialchars(addslashes($current_student['full_name'])); ?>')">
                            <i class="fas fa-plus"></i> Add First Note
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Main Students List View -->
        <div class="page-header animate-fade-up">
            <div>
                <h1><i class="fas fa-users"></i> My Students</h1>
                <p>Manage and monitor your assigned students</p>
            </div>
            <div class="page-actions">
                <button class="btn btn-primary" onclick="openAssignmentModal()">
                    <i class="fas fa-plus-circle"></i> New Assignment
                </button>
                <button class="btn btn-success" onclick="window.location.href='attendance.php'">
                    <i class="fas fa-clipboard-check"></i> Bulk Attendance
                </button>
                <div class="btn-group">
                    <a href="?action=export_students<?php echo $class_id ? '&class_id=' . $class_id : ''; ?>" 
                       class="btn btn-info">
                        <i class="fas fa-download"></i> Export
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card students stagger-item">
                <div class="stat-header">
                    <div class="stat-info">
                        <h3>My Students</h3>
                        <div class="stat-number"><?php echo number_format($stats['total_students']); ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card attendance stagger-item">
                <div class="stat-header">
                    <div class="stat-info">
                        <h3>Today's Attendance</h3>
                        <div class="stat-number"><?php echo number_format($stats['attendance_taken']); ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card classes stagger-item">
                <div class="stat-header">
                    <div class="stat-info">
                        <h3>My Classes</h3>
                        <div class="stat-number"><?php echo number_format($stats['total_classes']); ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-door-open"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card assignments stagger-item">
                <div class="stat-header">
                    <div class="stat-info">
                        <h3>Pending Assignments</h3>
                        <div class="stat-number"><?php echo number_format($stats['pending_assignments']); ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-tasks"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card submissions stagger-item">
                <div class="stat-header">
                    <div class="stat-info">
                        <h3>Total Submissions</h3>
                        <div class="stat-number"><?php echo number_format($stats['total_submissions']); ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-file-upload"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="content-layout">
            <div class="main-content-area animate-fade-up">
                <!-- Filters -->
                <div class="filters-section">
                    <form method="GET" id="filterForm">
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label for="search">Search</label>
                                <input type="text" id="search" name="search" class="filter-input" 
                                       value="<?php echo htmlspecialchars($search_query); ?>" 
                                       placeholder="Name, ID, parent...">
                            </div>
                            
                            <div class="filter-group">
                                <label for="class_id">Class</label>
                                <select id="class_id" name="class_id" class="filter-select">
                                    <option value="">All Classes</option>
                                    <?php foreach($teacher_classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $class_id == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="status">Status</label>
                                <select id="status" name="status" class="filter-select">
                                    <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="" <?php echo $status_filter == '' ? 'selected' : ''; ?>>All</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="sort_by">Sort By</label>
                                <select id="sort_by" name="sort_by" class="filter-select">
                                    <option value="name" <?php echo $sort_by == 'name' ? 'selected' : ''; ?>>Name</option>
                                    <option value="class" <?php echo $sort_by == 'class' ? 'selected' : ''; ?>>Class</option>
                                    <option value="attendance" <?php echo $sort_by == 'attendance' ? 'selected' : ''; ?>>Attendance</option>
                                    <option value="grade" <?php echo $sort_by == 'grade' ? 'selected' : ''; ?>>Grade</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label>View Mode</label>
                                <div class="view-toggle">
                                    <button type="button" class="view-btn <?php echo $view_mode == 'grid' ? 'active' : ''; ?>" 
                                            onclick="setViewMode('grid')">
                                        <i class="fas fa-th-large"></i> Grid
                                    </button>
                                    <button type="button" class="view-btn <?php echo $view_mode == 'list' ? 'active' : ''; ?>" 
                                            onclick="setViewMode('list')">
                                        <i class="fas fa-list"></i> List
                                    </button>
                                    <input type="hidden" name="view" id="viewMode" value="<?php echo $view_mode; ?>">
                                </div>
                            </div>
                            
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Apply
                                </button>
                                <button type="button" class="btn btn-outline" onclick="resetFilters()">
                                    <i class="fas fa-redo"></i> Reset
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Students Display -->
                <?php if (!empty($students)): ?>
                    <?php if ($view_mode === 'grid'): ?>
                    <div class="students-grid">
                        <?php foreach($students as $student): 
                            $attendance_rate = $student['total_attendance'] > 0 ? round(($student['present_count'] / $student['total_attendance']) * 100, 1) : 0;
                            $avg_grade_value = isset($student['avg_grade']) ? (float) $student['avg_grade'] : 0;
                            $avg_grade = formatExamAverageGradeDisplay($student['avg_grade'] ?? null, $student['avg_grade_letter'] ?? null);
                            $is_featured = $attendance_rate > 90 && $avg_grade_value > 80;
                        ?>
                        <div class="student-card <?php echo $is_featured ? 'featured' : ''; ?>">
                            <div class="student-header">
                                <div class="student-avatar">
                                    <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
                                </div>
                                <div class="student-info">
                                    <div class="student-name">
                                        <?php echo htmlspecialchars($student['full_name']); ?>
                                        <?php if ($student['status'] === 'inactive'): ?>
                                        <span class="inactive-badge">Inactive</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="student-id">
                                        <?php echo htmlspecialchars($student['Admission_number'] ?? $student['student_id']); ?>
                                    </div>
                                    <div class="student-class">
                                        <?php echo htmlspecialchars($student['class_name']); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="student-stats">
                                <div class="student-stat">
                                    <div class="stat-value"><?php echo $attendance_rate; ?>%</div>
                                    <div class="stat-label">Attendance</div>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo min($attendance_rate, 100); ?>%"></div>
                                    </div>
                                </div>
                                <div class="student-stat">
                                    <div class="stat-value"><?php echo htmlspecialchars($avg_grade); ?></div>
                                    <div class="stat-label">Avg Grade</div>
                                </div>
                                <div class="student-stat">
                                    <div class="stat-value"><?php echo $student['submitted_assignments']; ?>/<?php echo $student['pending_assignments']; ?></div>
                                    <div class="stat-label">Assignments</div>
                                </div>
                            </div>
                            
                            <div class="student-actions">
                                <button class="btn btn-outline btn-sm" onclick="viewStudent(<?php echo $student['id']; ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button class="btn btn-outline btn-sm" onclick="openGradeModal(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars(addslashes($student['full_name'])); ?>')">
                                    <i class="fas fa-edit"></i> Grade
                                </button>
                                <button class="btn btn-outline btn-sm" onclick="openAttendanceModal(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars(addslashes($student['full_name'])); ?>')">
                                    <i class="fas fa-calendar-check"></i> Attend
                                </button>
                                <button class="btn btn-outline btn-sm" onclick="addNote(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars(addslashes($student['full_name'])); ?>')">
                                    <i class="fas fa-sticky-note"></i> Note
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="students-list">
                        <?php foreach($students as $student): 
                            $attendance_rate = $student['total_attendance'] > 0 ? round(($student['present_count'] / $student['total_attendance']) * 100, 1) : 0;
                            $avg_grade = formatExamAverageGradeDisplay($student['avg_grade'] ?? null, $student['avg_grade_letter'] ?? null);
                        ?>
                        <div class="student-row">
                            <div class="row-avatar">
                                <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
                            </div>
                            <div class="row-info">
                                <div class="row-name">
                                    <?php echo htmlspecialchars($student['full_name']); ?>
                                    <?php if ($student['status'] === 'inactive'): ?>
                                    <span class="inactive-badge">Inactive</span>
                                    <?php endif; ?>
                                </div>
                                <div class="row-class">
                                    <?php echo htmlspecialchars($student['class_name']); ?>
                                </div>
                                <div class="row-stats">
                                    <span><i class="fas fa-calendar-check"></i> <?php echo $attendance_rate; ?>%</span>
                                    <span><i class="fas fa-star"></i> <?php echo htmlspecialchars($avg_grade); ?></span>
                                    <span><i class="fas fa-tasks"></i> <?php echo $student['submitted_assignments']; ?>/<?php echo $student['pending_assignments']; ?></span>
                                </div>
                            </div>
                            <div class="row-actions">
                                <button class="btn btn-outline btn-xs" onclick="viewStudent(<?php echo $student['id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn btn-outline btn-xs" onclick="openGradeModal(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars(addslashes($student['full_name'])); ?>')">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-outline btn-xs" onclick="openAttendanceModal(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars(addslashes($student['full_name'])); ?>')">
                                    <i class="fas fa-calendar-check"></i>
                                </button>
                                <button class="btn btn-outline btn-xs" onclick="addNote(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars(addslashes($student['full_name'])); ?>')">
                                    <i class="fas fa-sticky-note"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                <div class="empty-state" style="padding: 4rem 2rem;">
                    <i class="fas fa-user-graduate"></i>
                    <h3>No Students Found</h3>
                    <p>No students match your current filters.</p>
                    <button class="btn btn-primary" onclick="resetFilters()">
                        <i class="fas fa-redo"></i> Reset Filters
                    </button>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Sidebar -->
            <div class="sidebar-column">
                <!-- Teaching Tools -->
                <div class="sidebar-card animate-fade-up">
                    <div class="card-header">
                        <h3><i class="fas fa-bolt"></i> Teaching Tools</h3>
                    </div>
                    <div class="card-body">
                        <div class="quick-actions-grid">
                            <a href="attendance.php" class="action-item">
                                <i class="fas fa-clipboard-check"></i>
                                <span>Take Attendance</span>
                            </a>
                            <a href="grades.php" class="action-item">
                                <i class="fas fa-chart-bar"></i>
                                <span>Enter Grades</span>
                            </a>
                            <a href="assignments.php" class="action-item">
                                <i class="fas fa-tasks"></i>
                                <span>Assignments</span>
                            </a>
                            <a href="reports.php" class="action-item">
                                <i class="fas fa-file-alt"></i>
                                <span>Reports</span>
                            </a>
                            <a href="messages.php" class="action-item">
                                <i class="fas fa-envelope"></i>
                                <span>Messages</span>
                            </a>
                            <a href="resources.php" class="action-item">
                                <i class="fas fa-book-open"></i>
                                <span>Resources</span>
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Assignments -->
                <div class="sidebar-card animate-fade-up">
                    <div class="card-header">
                        <h3><i class="fas fa-tasks"></i> Recent Assignments</h3>
                        <a href="assignments.php" class="btn btn-primary btn-xs">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($assignments)): ?>
                        <div class="assignment-list">
                            <?php foreach($assignments as $assignment): ?>
                            <div class="assignment-item">
                                <div class="assignment-title">
                                    <?php echo htmlspecialchars($assignment['title']); ?>
                                </div>
                                <div class="assignment-meta">
                                    <span class="assignment-class">
                                        <?php echo htmlspecialchars($assignment['class_name']); ?>
                                    </span>
                                    <span>Due: <?php echo date('M j', strtotime($assignment['due_date'])); ?></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.5rem;">
                                    <span style="font-size: 0.8rem; color: var(--gray);">
                                        <i class="fas fa-upload"></i> <?php echo $assignment['submissions']; ?>/<?php echo $assignment['total_students'] ?: 0; ?> submitted
                                    </span>
                                    <span class="submission-badge">
                                        <?php echo $assignment['total_students'] > 0 ? round(($assignment['submissions'] / $assignment['total_students']) * 100) : 0; ?>%
                                    </span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="empty-state" style="padding: 2rem 1rem;">
                            <i class="fas fa-tasks"></i>
                            <p>No assignments yet</p>
                            <button class="btn btn-primary btn-sm" onclick="openAssignmentModal()">
                                <i class="fas fa-plus"></i> Create Assignment
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Upcoming Events -->
                <div class="sidebar-card animate-fade-up">
                    <div class="card-header">
                        <h3><i class="fas fa-bell"></i> Upcoming Events</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($events)): ?>
                        <div class="event-list">
                            <?php foreach($events as $event): 
                                $event_date = new DateTime($event['event_date']);
                                $today = new DateTime();
                                $days_until = $today->diff($event_date)->days;
                            ?>
                            <div class="event-item <?php echo $event['event_type']; ?>">
                                <div class="event-title">
                                    <?php echo htmlspecialchars($event['title']); ?>
                                </div>
                                <div class="event-meta">
                                    <span><i class="fas fa-calendar"></i> <?php echo date('M j', strtotime($event['event_date'])); ?></span>
                                    <?php if ($event['start_time']): ?>
                                    <span><i class="fas fa-clock"></i> <?php echo formatTime($event['start_time']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($event['class_name']): ?>
                                <div style="font-size: 0.75rem; color: var(--primary); margin-top: 0.3rem;">
                                    <i class="fas fa-users"></i> <?php echo htmlspecialchars($event['class_name']); ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($days_until == 0): ?>
                                <div style="font-size: 0.7rem; color: var(--danger); font-weight: 600; margin-top: 0.3rem;">
                                    <i class="fas fa-exclamation-circle"></i> Today!
                                </div>
                                <?php elseif ($days_until == 1): ?>
                                <div style="font-size: 0.7rem; color: var(--warning); font-weight: 600; margin-top: 0.3rem;">
                                    <i class="fas fa-clock"></i> Tomorrow
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="empty-state" style="padding: 2rem 1rem;">
                            <i class="fas fa-calendar-times"></i>
                            <p>No upcoming events</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="sidebar-card animate-fade-up">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-pie"></i> Quick Stats</h3>
                    </div>
                    <div class="card-body">
                        <div class="quick-stats-grid">
                            <div class="quick-stat">
                                <div class="quick-stat-value"><?php echo $stats['total_students']; ?></div>
                                <div class="quick-stat-label">Total Students</div>
                            </div>
                            <div class="quick-stat">
                                <div class="quick-stat-value"><?php echo $stats['attendance_taken']; ?></div>
                                <div class="quick-stat-label">Today's Attendance</div>
                            </div>
                            <div class="quick-stat">
                                <div class="quick-stat-value"><?php echo $stats['pending_assignments']; ?></div>
                                <div class="quick-stat-label">Pending Assignments</div>
                            </div>
                            <div class="quick-stat">
                                <div class="quick-stat-value"><?php echo $stats['total_classes']; ?></div>
                                <div class="quick-stat-label">Classes</div>
                            </div>
                        </div>
                        
                        <?php if (!empty($teacher_classes)): ?>
                        <div style="margin-top: 1.5rem;">
                            <h4 style="font-size: 0.9rem; color: var(--dark); margin-bottom: 1rem;">My Classes</h4>
                            <?php foreach(array_slice($teacher_classes, 0, 3) as $class): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--light);">
                                <span><?php echo htmlspecialchars($class['class_name']); ?></span>
                                <a href="?class_id=<?php echo $class['id']; ?>" class="btn btn-xs btn-outline">View</a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Add Assignment Modal -->
    <div id="assignmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-tasks"></i> Create New Assignment</h3>
                <button class="btn-close" onclick="closeModal('assignmentModal')">&times;</button>
            </div>
            <form method="POST" onsubmit="return validateAssignmentForm()">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="title">Assignment Title *</label>
                        <input type="text" id="title" name="title" class="form-control" required maxlength="255">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="modal_class_id">Class *</label>
                            <select id="modal_class_id" name="class_id" class="form-control" required>
                                <option value="">Select Class</option>
                                <?php foreach($teacher_classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="assignment_type">Type</label>
                            <select id="assignment_type" name="assignment_type" class="form-control">
                                <option value="homework">Homework</option>
                                <option value="quiz">Quiz</option>
                                <option value="project">Project</option>
                                <option value="exam">Exam</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="due_date">Due Date *</label>
                            <input type="date" id="due_date" name="due_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="max_marks">Max Marks</label>
                            <input type="number" id="max_marks" name="max_marks" class="form-control" value="100" min="1" max="500">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" class="form-control" rows="4" placeholder="Assignment instructions..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('assignmentModal')">Cancel</button>
                    <button type="submit" name="add_assignment" class="btn btn-primary">Create Assignment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Grade Modal -->
    <div id="gradeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Record Grade</h3>
                <button class="btn-close" onclick="closeModal('gradeModal')">&times;</button>
            </div>
            <form method="POST" onsubmit="return validateGradeForm()">
                <div class="modal-body">
                    <input type="hidden" id="grade_student_id" name="student_id">
                    
                    <div class="student-badge" id="grade_student_display">
                        Student: <span id="grade_student_name"></span>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="subject_id">Subject *</label>
                            <select id="subject_id" name="subject_id" class="form-control" required>
                                <option value="">Select Subject</option>
                                <?php foreach($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>">
                                    <?php echo htmlspecialchars($subject['subject_name'] . ' - ' . $subject['class_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="term">Term *</label>
                            <select id="term" name="term" class="form-control" required>
                                <option value="Term 1">Term 1</option>
                                <option value="Term 2">Term 2</option>
                                <option value="Term 3">Term 3</option>
                                <option value="Final">Final</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="marks">Marks * (0-100)</label>
                            <input type="number" id="marks" name="marks" class="form-control" min="0" max="100" step="0.5" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="grade_remarks">Remarks</label>
                        <input type="text" id="grade_remarks" name="remarks" class="form-control" placeholder="Optional remarks...">
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('gradeModal')">Cancel</button>
                    <button type="submit" name="record_grade" class="btn btn-primary">Save Grade</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Attendance Modal -->
    <div id="attendanceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-check"></i> Mark Attendance</h3>
                <button class="btn-close" onclick="closeModal('attendanceModal')">&times;</button>
            </div>
            <form method="POST" onsubmit="return validateAttendanceForm()">
                <div class="modal-body">
                    <input type="hidden" id="attendance_student_id" name="student_id">
                    
                    <div class="student-badge" id="attendance_student_display">
                        Student: <span id="attendance_student_name"></span>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="attendance_date">Date *</label>
                            <input type="date" id="attendance_date" name="date" class="form-control" required max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="attendance_status">Status *</label>
                            <select id="attendance_status" name="status" class="form-control" required>
                                <option value="Present">Present</option>
                                <option value="Absent">Absent</option>
                                <option value="Late">Late</option>
                                <option value="Excused">Excused</option>
                                <option value="Sick">Sick</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="attendance_remarks">Remarks</label>
                        <input type="text" id="attendance_remarks" name="remarks" class="form-control" placeholder="Optional remarks...">
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('attendanceModal')">Cancel</button>
                    <button type="submit" name="mark_attendance" class="btn btn-primary">Save Attendance</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Note Modal -->
    <div id="noteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-sticky-note"></i> Add Note</h3>
                <button class="btn-close" onclick="closeModal('noteModal')">&times;</button>
            </div>
            <form method="POST" onsubmit="return validateNoteForm()">
                <div class="modal-body">
                    <input type="hidden" id="note_student_id" name="student_id">
                    
                    <div class="student-badge" id="note_student_display">
                        Student: <span id="note_student_name"></span>
                    </div>
                    
                    <div class="form-group">
                        <label for="note_type">Note Type *</label>
                        <select id="note_type" name="note_type" class="form-control" required>
                            <option value="general">General</option>
                            <option value="behavior">Behavior</option>
                            <option value="academic">Academic</option>
                            <option value="parent_contact">Parent Contact</option>
                            <option value="achievement">Achievement</option>
                            <option value="concern">Concern</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="note">Note *</label>
                        <textarea id="note" name="note" class="form-control" rows="5" placeholder="Enter your note here..." required></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('noteModal')">Cancel</button>
                    <button type="submit" name="add_note" class="btn btn-primary">Save Note</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Event Modal -->
    <div id="eventModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-plus"></i> Create Event</h3>
                <button class="btn-close" onclick="closeModal('eventModal')">&times;</button>
            </div>
            <form method="POST" onsubmit="return validateEventForm()">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="event_title">Event Title *</label>
                        <input type="text" id="event_title" name="title" class="form-control" required maxlength="255">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="event_date">Date *</label>
                            <input type="date" id="event_date" name="event_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="event_type">Type *</label>
                            <select id="event_type" name="event_type" class="form-control" required>
                                <option value="meeting">Staff Meeting</option>
                                <option value="conference">Parent-Teacher Conference</option>
                                <option value="school_event">School Event</option>
                                <option value="exam">Exam Schedule</option>
                                <option value="holiday">Holiday</option>
                                <option value="workshop">Workshop</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="start_time">Start Time</label>
                            <input type="time" id="start_time" name="start_time" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label for="end_time">End Time</label>
                            <input type="time" id="end_time" name="end_time" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="location">Location</label>
                        <input type="text" id="location" name="location" class="form-control" placeholder="e.g., Staff Room, Classroom 101">
                    </div>
                    
                    <div class="form-group">
                        <label for="target_audience">Audience *</label>
                        <input type="text" id="target_audience" name="target_audience" class="form-control" required 
                               placeholder="e.g., All Teachers, Class 10A, Science Department">
                        <div class="form-hint">
                            Examples: "All Teachers", "Class 10A", "Science Department"
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="event_description">Description</label>
                        <textarea id="event_description" name="description" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('eventModal')">Cancel</button>
                    <button type="submit" name="create_event" class="btn btn-primary">Create Event</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Update time display
        function updateTime() {
            const timeElement = document.getElementById('currentTime');
            if (timeElement) {
                const now = new Date();
                timeElement.textContent = now.toLocaleTimeString('en-US', { 
                    hour: '2-digit', 
                    minute: '2-digit', 
                    second: '2-digit',
                    hour12: true 
                });
            }
        }
        setInterval(updateTime, 1000);

        // Modal Functions
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        }

        // Assignment Modal
        function openAssignmentModal() {
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 7);
            document.getElementById('due_date').valueAsDate = tomorrow;
            openModal('assignmentModal');
        }

        function validateAssignmentForm() {
            const title = document.getElementById('title').value.trim();
            const classId = document.getElementById('modal_class_id').value;
            const dueDate = document.getElementById('due_date').value;
            
            if (!title) {
                Swal.fire('Error', 'Please enter an assignment title', 'error');
                return false;
            }
            
            if (!classId) {
                Swal.fire('Error', 'Please select a class', 'error');
                return false;
            }
            
            if (!dueDate) {
                Swal.fire('Error', 'Please select a due date', 'error');
                return false;
            }
            
            return true;
        }

        // Grade Modal
        function openGradeModal(studentId, studentName) {
            document.getElementById('grade_student_id').value = studentId;
            document.getElementById('grade_student_name').textContent = studentName;
            
            // Reset form
            document.getElementById('subject_id').value = '';
            document.getElementById('term').value = 'Term 1';
            document.getElementById('marks').value = '';
            document.getElementById('grade_remarks').value = '';
            
            openModal('gradeModal');
        }

        function validateGradeForm() {
            const subject = document.getElementById('subject_id').value;
            const marks = document.getElementById('marks').value;
            
            if (!subject) {
                Swal.fire('Error', 'Please select a subject', 'error');
                return false;
            }
            
            if (!marks || marks < 0 || marks > 100) {
                Swal.fire('Error', 'Please enter valid marks between 0 and 100', 'error');
                return false;
            }
            
            return true;
        }

        // Attendance Modal
        function openAttendanceModal(studentId, studentName) {
            document.getElementById('attendance_student_id').value = studentId;
            document.getElementById('attendance_student_name').textContent = studentName;
            document.getElementById('attendance_date').valueAsDate = new Date();
            document.getElementById('attendance_status').value = 'Present';
            document.getElementById('attendance_remarks').value = '';
            
            openModal('attendanceModal');
        }

        function validateAttendanceForm() {
            const date = document.getElementById('attendance_date').value;
            
            if (!date) {
                Swal.fire('Error', 'Please select a date', 'error');
                return false;
            }
            
            return true;
        }

        // Note Modal
        function addNote(studentId, studentName) {
            document.getElementById('note_student_id').value = studentId;
            document.getElementById('note_student_name').textContent = studentName;
            document.getElementById('note_type').value = 'general';
            document.getElementById('note').value = '';
            
            openModal('noteModal');
        }

        function validateNoteForm() {
            const note = document.getElementById('note').value.trim();
            
            if (!note) {
                Swal.fire('Error', 'Please enter a note', 'error');
                return false;
            }
            
            return true;
        }

        // Event Modal
        function openEventModal() {
            document.getElementById('event_date').valueAsDate = new Date();
            document.getElementById('event_type').value = 'meeting';
            document.getElementById('event_title').value = '';
            document.getElementById('start_time').value = '';
            document.getElementById('end_time').value = '';
            document.getElementById('location').value = '';
            document.getElementById('target_audience').value = '';
            document.getElementById('event_description').value = '';
            
            openModal('eventModal');
        }

        function validateEventForm() {
            const title = document.getElementById('event_title').value.trim();
            const date = document.getElementById('event_date').value;
            const audience = document.getElementById('target_audience').value.trim();
            
            if (!title) {
                Swal.fire('Error', 'Please enter an event title', 'error');
                return false;
            }
            
            if (!date) {
                Swal.fire('Error', 'Please select an event date', 'error');
                return false;
            }
            
            if (!audience) {
                Swal.fire('Error', 'Please specify the target audience', 'error');
                return false;
            }
            
            return true;
        }

        // View Student
        function viewStudent(studentId) {
            window.location.href = 'my_students.php?view_student=' + studentId;
        }

        // Filter Functions
        function setViewMode(mode) {
            document.getElementById('viewMode').value = mode;
            document.getElementById('filterForm').submit();
        }

        function resetFilters() {
            window.location.href = 'my_students.php';
        }

        // Tab Switching
        function switchTab(tabName) {
            // Remove active class from all tabs
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelectorAll('.tab-pane').forEach(pane => {
                pane.classList.remove('active');
            });
            
            // Add active class to selected tab
            document.querySelector(`.tab-btn[onclick="switchTab('${tabName}')"]`).classList.add('active');
            document.getElementById(`tab-${tabName}`).classList.add('active');
        }

        // Close modals when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        });

        // Close modals on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(modal => {
                    modal.classList.remove('active');
                    document.body.style.overflow = 'auto';
                });
            }
        });

        // Auto-close alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                });
            }, 5000);
        });

        // Initialize tooltips if needed
        document.addEventListener('DOMContentLoaded', function() {
            // Set default dates for forms
            const today = new Date().toISOString().split('T')[0];
            document.querySelectorAll('input[type="date"]').forEach(input => {
                if (!input.value && input.id !== 'due_date') {
                    input.value = today;
                }
            });
        });
    </script>
</body>
</html>
