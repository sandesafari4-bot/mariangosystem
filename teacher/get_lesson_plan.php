<?php
include '../config.php';
checkAuth();

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'No ID provided']);
    exit;
}

$plan_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

// Get teacher information
$teacher_stmt = $pdo->prepare("SELECT id FROM teachers WHERE user_id = ?");
$teacher_stmt->execute([$user_id]);
$teacher = $teacher_stmt->fetch();

if (!$teacher) {
    echo json_encode(['success' => false, 'message' => 'Teacher not found']);
    exit;
}

// Get lesson plan
$stmt = $pdo->prepare("
    SELECT lp.*, s.subject_name, c.class_name, t.full_name as teacher_name
    FROM lesson_plans lp
    JOIN subjects s ON lp.subject_id = s.id
    JOIN classes c ON lp.class_id = c.id
    JOIN teachers t ON lp.teacher_id = t.id
    WHERE lp.id = ? AND lp.teacher_id = ?
");
$stmt->execute([$plan_id, $teacher['id']]);
$plan = $stmt->fetch();

if (!$plan) {
    echo json_encode(['success' => false, 'message' => 'Lesson plan not found']);
    exit;
}

// Get attachments
$attachments_stmt = $pdo->prepare("SELECT * FROM lesson_plan_attachments WHERE lesson_plan_id = ? ORDER BY uploaded_at DESC");
$attachments_stmt->execute([$plan_id]);
$attachments = $attachments_stmt->fetchAll();

$plan['attachments'] = $attachments;

echo json_encode([
    'success' => true,
    'plan' => $plan
]);
?>