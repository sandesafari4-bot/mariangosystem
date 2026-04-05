<?php
include '../config.php';
checkAuth();
checkRole(['teacher', 'admin']);

$role = $_SESSION['role'] ?? 'teacher';
$userId = (int) ($_SESSION['user_id'] ?? 0);
$isAdmin = ($role === 'admin');
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

$type = $_GET['type'] ?? 'excel';
$view = $_GET['view'] ?? 'teacher';
$selectedTeacherId = $isAdmin ? (int) ($_GET['teacher_id'] ?? 0) : $userId;
$selectedClassId = (int) ($_GET['class_id'] ?? 0);
$download = isset($_GET['download']);

$publishedPlan = $pdo->query("
    SELECT *
    FROM timetable_plans
    WHERE status = 'published'
    ORDER BY published_at DESC, id DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

$teachers = $pdo->query("
    SELECT id, full_name
    FROM users
    WHERE role = 'teacher' AND status = 'active'
    ORDER BY full_name
")->fetchAll(PDO::FETCH_ASSOC);

$classes = $pdo->query("
    SELECT id, class_name
    FROM classes
    WHERE COALESCE(is_active, 1) = 1
    ORDER BY class_name
")->fetchAll(PDO::FETCH_ASSOC);

if (!$selectedTeacherId && !$isAdmin) {
    $selectedTeacherId = $userId;
}

$selectedTeacherName = '';
foreach ($teachers as $teacherOption) {
    if ((int) $teacherOption['id'] === $selectedTeacherId) {
        $selectedTeacherName = (string) $teacherOption['full_name'];
        break;
    }
}

$selectedClassName = '';
foreach ($classes as $classOption) {
    if ((int) $classOption['id'] === $selectedClassId) {
        $selectedClassName = (string) $classOption['class_name'];
        break;
    }
}

$periods = [];
$lessons = [];
$title = 'Published Timetable Export';

if ($publishedPlan) {
    $periodStmt = $pdo->prepare("
        SELECT *
        FROM timetable_periods
        WHERE plan_id = ?
        ORDER BY sort_order, start_time
    ");
    $periodStmt->execute([(int) $publishedPlan['id']]);
    $periods = $periodStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($view === 'class' && $selectedClassId) {
        $lessonStmt = $pdo->prepare("
            SELECT tl.*, c.class_name, s.subject_name, u.full_name AS teacher_name, r.room_name, r.room_number
            FROM timetable_lessons tl
            JOIN classes c ON c.id = tl.class_id
            JOIN subjects s ON s.id = tl.subject_id
            LEFT JOIN users u ON u.id = tl.teacher_id
            LEFT JOIN rooms r ON r.id = tl.room_id
            WHERE tl.plan_id = ? AND tl.status = 'published' AND tl.class_id = ?
            ORDER BY FIELD(tl.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'), tl.start_time
        ");
        $lessonStmt->execute([(int) $publishedPlan['id'], $selectedClassId]);

        $classNameStmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = ? LIMIT 1");
        $classNameStmt->execute([$selectedClassId]);
        $selectedClassName = (string) ($classNameStmt->fetchColumn() ?: $selectedClassName);
        $title = (($selectedClassName ?: 'Class') . ' Timetable');
    } else {
        $lessonStmt = $pdo->prepare("
            SELECT tl.*, c.class_name, s.subject_name, u.full_name AS teacher_name, r.room_name, r.room_number
            FROM timetable_lessons tl
            JOIN classes c ON c.id = tl.class_id
            JOIN subjects s ON s.id = tl.subject_id
            LEFT JOIN users u ON u.id = tl.teacher_id
            LEFT JOIN rooms r ON r.id = tl.room_id
            WHERE tl.plan_id = ? AND tl.status = 'published' AND tl.teacher_id = ?
            ORDER BY FIELD(tl.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'), tl.start_time
        ");
        $lessonStmt->execute([(int) $publishedPlan['id'], $selectedTeacherId]);

        $teacherNameStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        $teacherNameStmt->execute([$selectedTeacherId]);
        $selectedTeacherName = (string) ($teacherNameStmt->fetchColumn() ?: $selectedTeacherName);
        $title = (($selectedTeacherName ?: 'Teacher') . ' Timetable');
    }

    $lessons = $lessonStmt->fetchAll(PDO::FETCH_ASSOC);
}

function renderExportTable(array $periods, array $lessons, array $days, string $view): string
{
    $lessonMap = [];
    foreach ($lessons as $lesson) {
        $lessonMap[$lesson['day_of_week']][$lesson['period_id']] = $lesson;
    }

    ob_start();
    ?>
    <table border="1" cellspacing="0" cellpadding="8">
        <thead>
            <tr>
                <th>Day</th>
                <?php foreach ($periods as $period): ?>
                    <th>
                        <?php echo htmlspecialchars($period['label']); ?><br>
                        <?php echo htmlspecialchars(substr($period['start_time'], 0, 5) . ' - ' . substr($period['end_time'], 0, 5)); ?>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($days as $day): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($day); ?></strong></td>
                    <?php foreach ($periods as $period): ?>
                        <?php $cell = $lessonMap[$day][$period['id']] ?? null; ?>
                        <td>
                            <?php if ($period['period_type'] !== 'lesson'): ?>
                                <strong><?php echo htmlspecialchars($period['label']); ?></strong><br>
                                <?php echo htmlspecialchars(ucfirst($period['period_type'])); ?>
                            <?php elseif ($cell): ?>
                                <strong><?php echo htmlspecialchars($cell['subject_name']); ?></strong><br>
                                <?php echo htmlspecialchars($view === 'class' ? ($cell['teacher_name'] ?: 'Teacher not assigned') : $cell['class_name']); ?><br>
                                <?php echo htmlspecialchars($cell['room_name'] ?: 'No room assigned'); ?>
                                <?php if (!empty($cell['room_number'])): ?><br><?php echo htmlspecialchars($cell['room_number']); ?><?php endif; ?>
                            <?php else: ?>
                                Free Slot
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    return (string) ob_get_clean();
}

if ($download) {
    if ($type === 'pdf') {
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: attachment; filename="timetable_' . date('Y-m-d') . '.html"');
        echo '<html><head><meta charset="UTF-8"><title>' . htmlspecialchars($title) . '</title></head><body>';
        echo '<h1>' . htmlspecialchars($title) . '</h1>';
        echo '<p>' . htmlspecialchars($view === 'class' ? ('Class: ' . ($selectedClassName ?: 'Selected Class')) : ('Teacher: ' . ($selectedTeacherName ?: 'Selected Teacher'))) . '</p>';
        if ($publishedPlan) {
            echo '<p>' . htmlspecialchars($publishedPlan['title'] . ' • ' . $publishedPlan['term'] . ' • ' . $publishedPlan['academic_year']) . '</p>';
        }
        echo renderExportTable($periods, $lessons, $days, $view);
        echo '</body></html>';
    } else {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="timetable_' . date('Y-m-d') . '.xls"');
        echo renderExportTable($periods, $lessons, $days, $view);
    }
    exit;
}

$pageTitle = 'Export Timetable - ' . SCHOOL_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f3f4f6;
            color: #111827;
        }
        .main {
            max-width: 980px;
            margin: 30px auto;
            background: #fff;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.08);
        }
        .toolbar, .filters {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filters {
            margin: 18px 0 24px;
        }
        select {
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid #d1d5db;
            min-width: 220px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            border: none;
            border-radius: 10px;
            padding: 11px 16px;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-primary { background: #1f2937; color: #fff; }
        .btn-soft { background: #e5e7eb; color: #111827; }
        .preview {
            overflow-x: auto;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }
        th, td {
            border: 1px solid #e5e7eb;
            padding: 10px;
            vertical-align: top;
        }
        th { background: #f9fafb; }
    </style>
</head>
<body>
    <div class="main">
        <div class="toolbar">
            <div>
                <h1 style="margin:0;">Export Timetable</h1>
                <p style="margin:8px 0 0;color:#6b7280;">Download the currently published teacher or class timetable.</p>
            </div>
        </div>

        <form method="get" class="filters">
            <select name="view" onchange="this.form.submit()">
                <option value="teacher" <?php echo $view === 'teacher' ? 'selected' : ''; ?>>Teacher timetable</option>
                <option value="class" <?php echo $view === 'class' ? 'selected' : ''; ?>>Class timetable</option>
            </select>

            <?php if ($view === 'teacher'): ?>
                <select name="teacher_id" <?php echo $isAdmin ? '' : 'disabled'; ?>>
                    <?php foreach ($teachers as $teacher): ?>
                        <option value="<?php echo (int) $teacher['id']; ?>" <?php echo (int) $teacher['id'] === $selectedTeacherId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($teacher['full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <select name="class_id">
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo (int) $class['id']; ?>" <?php echo (int) $class['id'] === $selectedClassId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($class['class_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <select name="type">
                <option value="excel" <?php echo $type === 'excel' ? 'selected' : ''; ?>>Excel</option>
                <option value="pdf" <?php echo $type === 'pdf' ? 'selected' : ''; ?>>Printable HTML</option>
            </select>

            <button class="btn btn-soft" type="submit"><i class="fas fa-sync-alt"></i> Refresh</button>
            <button class="btn btn-primary" type="submit" name="download" value="1"><i class="fas fa-download"></i> Download</button>
        </form>

        <?php if ($publishedPlan): ?>
            <p style="color:#6b7280;"><?php echo htmlspecialchars($publishedPlan['title'] . ' • ' . $publishedPlan['term'] . ' • ' . $publishedPlan['academic_year']); ?></p>
            <p style="color:#6b7280;"><?php echo htmlspecialchars($view === 'class' ? ('Class: ' . ($selectedClassName ?: 'Selected Class')) : ('Teacher: ' . ($selectedTeacherName ?: 'Selected Teacher'))); ?></p>
        <?php else: ?>
            <p style="color:#b91c1c;">No published timetable found.</p>
        <?php endif; ?>

        <div class="preview">
            <?php if (!$publishedPlan || empty($periods)): ?>
                <p>No export preview is available yet.</p>
            <?php else: ?>
                <?php echo renderExportTable($periods, $lessons, $days, $view); ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
