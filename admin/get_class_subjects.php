<?php
session_start();
include '../config.php';
checkAuth();
checkRole(['admin']);

header('Content-Type: application/json');

$class_id = $_GET['class_id'] ?? 0;

if (!$class_id) {
    echo json_encode(['success' => false, 'message' => 'Class ID is required']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT s.*, t.full_name as teacher_name 
        FROM subjects s 
        LEFT JOIN teachers t ON s.teacher_id = t.id 
        WHERE s.class_id = ?
        ORDER BY s.subject_name
    ");
    $stmt->execute([$class_id]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'subjects' => $subjects
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>