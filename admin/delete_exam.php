<?php
include '../config.php';
checkAuth();
checkRole(['admin']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $exam_id = $data['exam_id'] ?? '';
    
    if (empty($exam_id)) {
        echo json_encode(['success' => false, 'message' => 'Exam ID is required']);
        exit;
    }
    
    try {
        // Check if exam has schedules
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM exam_schedules WHERE exam_id = ?");
        $stmt->execute([$exam_id]);
        $scheduleCount = $stmt->fetchColumn();
        
        if ($scheduleCount > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete exam with existing schedules. Please delete schedules first.']);
            exit;
        }
        
        // Delete the exam
        $stmt = $pdo->prepare("DELETE FROM exams WHERE id = ?");
        $stmt->execute([$exam_id]);
        
        echo json_encode(['success' => true, 'message' => 'Exam deleted successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>