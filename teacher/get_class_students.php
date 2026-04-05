<?php
// api/get_class_students.php
include '../config.php';
checkAuth();
checkRole(['teacher']);

$teacher_id = $_SESSION['user_id'];
$class_id = $_GET['class_id'];

$stmt = $pdo->prepare("SELECT s.id, s.full_name, s.Admission_number 
                      FROM students s 
                      JOIN classes c ON s.class_id = c.id 
                      WHERE c.id = ? AND (c.class_teacher_id = ? OR EXISTS (
                          SELECT 1 FROM subjects sub WHERE sub.class_id = c.id AND sub.teacher_id = ?
                      )) 
                      ORDER BY s.full_name");
$stmt->execute([$class_id, $teacher_id, $teacher_id]);
$students = $stmt->fetchAll();

header('Content-Type: application/json');
echo json_encode($students);