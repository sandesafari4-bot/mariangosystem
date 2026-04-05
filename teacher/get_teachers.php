<?php
include '../config.php';
checkAuth();

$user_id = $_SESSION['user_id'];

// Get current teacher
$teacher_stmt = $pdo->prepare("SELECT id FROM teachers WHERE user_id = ?");
$teacher_stmt->execute([$user_id]);
$current_teacher = $teacher_stmt->fetch();

if (!$current_teacher) {
    echo json_encode(['success' => false, 'message' => 'Teacher not found']);
    exit;
}

// Get all teachers except current one
$stmt = $pdo->query("SELECT id, full_name, email, subject_specialization FROM teachers WHERE id != ? ORDER BY full_name");
$stmt->execute([$current_teacher['id']]);
$teachers = $stmt->fetchAll();

echo json_encode([
    'success' => true,
    'teachers' => $teachers
]);
?>