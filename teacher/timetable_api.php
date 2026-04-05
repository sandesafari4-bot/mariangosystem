<?php
include '../config.php';
header('Content-Type: application/json');

// Simple API key authentication (in production, use proper authentication)
$api_key = $_GET['api_key'] ?? '';
if ($api_key !== 'your_secret_api_key_here') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['path'] ?? '';

switch ($path) {
    case 'lessons':
        handleLessons($method);
        break;
    case 'exams':
        handleExams($method);
        break;
    case 'conflicts':
        handleConflicts($method);
        break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
}

function handleLessons($method) {
    global $pdo;
    
    switch ($method) {
        case 'GET':
            $class_id = $_GET['class_id'] ?? '';
            $teacher_id = $_GET['teacher_id'] ?? '';
            $day = $_GET['day'] ?? '';
            
            $query = "SELECT tl.*, c.class_name, s.subject_name, t.full_name as teacher_name 
                     FROM timetable_lessons tl
                     JOIN classes c ON tl.class_id = c.id
                     JOIN subjects s ON tl.subject_id = s.id
                     JOIN teachers t ON tl.teacher_id = t.id
                     WHERE 1=1";
            $params = [];
            
            if ($class_id) {
                $query .= " AND tl.class_id = ?";
                $params[] = $class_id;
            }
            
            if ($teacher_id) {
                $query .= " AND tl.teacher_id = ?";
                $params[] = $teacher_id;
            }
            
            if ($day) {
                $query .= " AND tl.day_of_week = ?";
                $params[] = $day;
            }
            
            $query .= " ORDER BY tl.day_of_week, tl.start_time";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['lessons' => $lessons]);
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            
            // Validate required fields
            $required = ['class_id', 'subject_id', 'teacher_id', 'day_of_week', 'start_time', 'end_time'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    http_response_code(400);
                    echo json_encode(['error' => "Missing required field: $field"]);
                    return;
                }
            }
            
            // Check conflicts
            $conflicts = checkLessonConflicts(
                $input['class_id'],
                $input['teacher_id'],
                $input['room_number'] ?? 'Room 101',
                $input['day_of_week'],
                $input['start_time'],
                $input['end_time']
            );
            
            if (!empty($conflicts)) {
                http_response_code(409);
                echo json_encode(['error' => 'Scheduling conflict', 'conflicts' => $conflicts]);
                return;
            }
            
            $stmt = $pdo->prepare("INSERT INTO timetable_lessons (class_id, subject_id, teacher_id, day_of_week, start_time, end_time, room_number) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([
                $input['class_id'],
                $input['subject_id'],
                $input['teacher_id'],
                $input['day_of_week'],
                $input['start_time'],
                $input['end_time'],
                $input['room_number'] ?? 'Room 101'
            ])) {
                echo json_encode(['message' => 'Lesson scheduled successfully', 'id' => $pdo->lastInsertId()]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to schedule lesson']);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function handleExams($method) {
    global $pdo;
    
    switch ($method) {
        case 'GET':
            $class_id = $_GET['class_id'] ?? '';
            $date = $_GET['date'] ?? '';
            
            $query = "SELECT te.*, c.class_name, s.subject_name, t.full_name as invigilator_name 
                     FROM timetable_exams te
                     JOIN classes c ON te.class_id = c.id
                     JOIN subjects s ON te.subject_id = s.id
                     JOIN teachers t ON te.invigilator_id = t.id
                     WHERE 1=1";
            $params = [];
            
            if ($class_id) {
                $query .= " AND te.class_id = ?";
                $params[] = $class_id;
            }
            
            if ($date) {
                $query .= " AND te.exam_date = ?";
                $params[] = $date;
            }
            
            $query .= " ORDER BY te.exam_date, te.start_time";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['exams' => $exams]);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function handleConflicts($method) {
    global $pdo;
    
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['type']) || empty($input['data'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing type or data']);
        return;
    }
    
    $conflicts = [];
    
    if ($input['type'] === 'lesson') {
        $data = $input['data'];
        $conflicts = checkLessonConflicts(
            $data['class_id'] ?? '',
            $data['teacher_id'] ?? '',
            $data['room_number'] ?? '',
            $data['day_of_week'] ?? '',
            $data['start_time'] ?? '',
            $data['end_time'] ?? '',
            $data['exclude_id'] ?? null
        );
    } elseif ($input['type'] === 'exam') {
        $data = $input['data'];
        $conflicts = checkExamConflicts(
            $data['class_id'] ?? '',
            $data['invigilator_id'] ?? '',
            $data['room_number'] ?? '',
            $data['exam_date'] ?? '',
            $data['start_time'] ?? '',
            $data['end_time'] ?? '',
            $data['exclude_id'] ?? null
        );
    }
    
    echo json_encode(['has_conflicts' => !empty($conflicts), 'conflicts' => $conflicts]);
}

// Include the conflict checking functions from the main timetable file
function checkLessonConflicts($class_id, $teacher_id, $room_number, $day, $start_time, $end_time, $exclude_id = null) {
    global $pdo;
    $conflicts = [];
    
    // Implementation same as in main timetable file
    // ... (copy the function implementation from timetable.php)
    
    return $conflicts;
}

function checkExamConflicts($class_id, $invigilator_id, $room_number, $exam_date, $start_time, $end_time, $exclude_id = null) {
    global $pdo;
    $conflicts = [];
    
    // Implementation same as in exams_timetable.php
    // ... (copy the function implementation from exams_timetable.php)
    
    return $conflicts;
}
?>