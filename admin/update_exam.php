<?php
include '../config.php';
checkAuth();
checkRole(['admin']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $exam_id = $_POST['exam_id'] ?? '';
    $exam_name = $_POST['exam_name'] ?? '';
    $exam_code = $_POST['exam_code'] ?? '';
    $academic_year = $_POST['academic_year'] ?? '';
    $term = $_POST['term'] ?? '';
    $total_marks = $_POST['total_marks'] ?? '';
    $passing_marks = $_POST['passing_marks'] ?? '';
    $description = $_POST['description'] ?? '';
    
    // Validate required fields
    if (empty($exam_id) || empty($exam_name) || empty($exam_code) || empty($academic_year) || empty($term) || empty($total_marks) || empty($passing_marks)) {
        echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE exams 
            SET exam_name = ?, exam_code = ?, academic_year = ?, term = ?, 
                total_marks = ?, passing_marks = ?, description = ?
            WHERE id = ?
        ");
        
        $stmt->execute([$exam_name, $exam_code, $academic_year, $term, $total_marks, $passing_marks, $description, $exam_id]);
        
        echo json_encode(['success' => true, 'message' => 'Exam updated successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>