<?php
include '../config.php';
checkAuth();
// Allow teachers and admins to export grades
checkRole(['teacher','admin']);

$exam_id = $_GET['exam_id'] ?? '';
$class_id = $_GET['class_id'] ?? '';
$subject_id = $_GET['subject_id'] ?? '';
$format = $_GET['format'] ?? 'csv'; // csv or html

// If exam_id is provided but class/subject are missing, infer from exams table
if ($exam_id && (!$class_id || !$subject_id)) {
    $eStmt = $pdo->prepare("SELECT * FROM exams WHERE id = ? LIMIT 1");
    $eStmt->execute([$exam_id]);
    $examRow = $eStmt->fetch(PDO::FETCH_ASSOC);
    if ($examRow) {
        if (!$class_id) $class_id = $examRow['class_id'];
        if (!$subject_id) $subject_id = $examRow['subject_id'];
    }
}

// If still missing required params, show a friendly HTML help page
if (!$exam_id || !$class_id || !$subject_id) {
    http_response_code(400);
    echo "<!doctype html><html><head><meta charset='utf-8'><title>Export Grades - Missing Parameters</title>";
    echo "<style>body{font-family:Arial,Helvetica,sans-serif;padding:20px;color:#2c3e50}a{color:#3498db}</style>";
    echo "</head><body>";
    echo "<h2>Missing parameters</h2>";
    echo "<p>The export request is missing required parameters. Please open the <a href='grades.php'>Grades page</a>, select Class, Subject and Exam, then click <strong>Export Results</strong>.</p>";
    echo "<p>If you intended to export by <em>exam</em> only, provide <code>exam_id</code> in the query string. Example: <code>export_grades.php?exam_id=123&format=csv</code></p>";
    echo "</body></html>";
    exit();
}

// Verify exam exists and creator (basic permission check)
$examStmt = $pdo->prepare("SELECT * FROM exams WHERE id = ? LIMIT 1");
$examStmt->execute([$exam_id]);
$exam = $examStmt->fetch(PDO::FETCH_ASSOC);
if (!$exam) {
    http_response_code(404);
    echo "Exam not found.";
    exit();
}

// If current user is a teacher, ensure they created the exam (admins bypass)
if ($_SESSION['user_role'] === 'teacher' && (int)$exam['created_by'] !== (int)$_SESSION['user_id']) {
    http_response_code(403);
    echo "You don't have permission to export this exam's grades.";
    exit();
}

// Fetch grades
$q = "
    SELECT eg.*, s.full_name, s.Admission_number, c.class_name, sub.subject_name, e.exam_name, e.max_marks
    FROM exam_grades eg
    JOIN students s ON eg.student_id = s.id
    JOIN classes c ON eg.class_id = c.id
    LEFT JOIN subjects sub ON eg.subject_id = sub.id
    LEFT JOIN exams e ON eg.exam_id = e.id
    WHERE eg.exam_id = ? AND eg.class_id = ? AND eg.subject_id = ?
    ORDER BY s.full_name
";
$stmt = $pdo->prepare($q);
$stmt->execute([$exam_id, $class_id, $subject_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$safeExam = preg_replace('/[^A-Za-z0-9-_]/', '_', $exam['exam_name'] ?? 'exam');
$safeSubject = preg_replace('/[^A-Za-z0-9-_]/', '_', $rows[0]['subject_name'] ?? 'subject');
$safeClass = preg_replace('/[^A-Za-z0-9-_]/', '_', $rows[0]['class_name'] ?? 'class');

if (strtolower($format) === 'html') {
    // Printable HTML
    echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Grades Export</title>";
    echo "<style>body{font-family:Arial,Helvetica,sans-serif;font-size:13px}table{width:100%;border-collapse:collapse}th,td{padding:6px;border:1px solid #ccc;text-align:left}th{background:#f8f9fa}</style>";
    echo "</head><body>";
    echo "<h2>" . htmlspecialchars($exam['exam_name']) . " - " . htmlspecialchars($rows[0]['subject_name'] ?? '') . "</h2>";
    echo "<table><thead><tr><th>#</th><th>Student</th><th>Student ID</th><th>Class</th><th>Marks</th><th>Max Marks</th><th>Percentage</th><th>Grade</th><th>Remarks</th><th>Ranking</th></tr></thead><tbody>";
    $i = 1;
    foreach ($rows as $r) {
        echo '<tr>';
        echo '<td>' . $i . '</td>';
        echo '<td>' . htmlspecialchars($r['full_name']) . '</td>';
        echo '<td>' . htmlspecialchars($r['Admission_number']) . '</td>';
        echo '<td>' . htmlspecialchars($r['class_name']) . '</td>';
        echo '<td>' . htmlspecialchars($r['marks_obtained']) . '</td>';
        echo '<td>' . htmlspecialchars($r['max_marks']) . '</td>';
        echo '<td>' . htmlspecialchars($r['percentage']) . '</td>';
        echo '<td>' . htmlspecialchars($r['grade']) . '</td>';
        echo '<td>' . htmlspecialchars($r['remarks']) . '</td>';
        echo '<td>' . htmlspecialchars($r['ranking']) . '</td>';
        echo '</tr>';
        $i++;
    }
    echo "</tbody></table><script>window.print();</script></body></html>";
    exit();
}

// Default: CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=grades_' . $safeExam . '_' . $safeClass . '_' . $safeSubject . '_' . date('Y-m-d_His') . '.csv');
$out = fopen('php://output', 'w');
// BOM for Excel
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
fputcsv($out, ['#','Student ID','Full Name','Class','Marks Obtained','Max Marks','Percentage','Grade','Remarks','Ranking']);
$i = 1;
foreach ($rows as $r) {
    fputcsv($out, [$i, $r['student_id'], $r['full_name'], $r['class_name'], $r['marks_obtained'], $r['max_marks'], $r['percentage'], $r['grade'], $r['remarks'], $r['ranking']]);
    $i++;
}
fclose($out);
exit();
