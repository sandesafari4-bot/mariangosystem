<?php
include '../config.php';
checkAuth();
checkRole(['teacher', 'admin']);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

$teacherStmt = $pdo->prepare("SELECT id, full_name, role FROM users WHERE id = ? LIMIT 1");
$teacherStmt->execute([$userId]);
$teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);

$classTeacherStmt = $pdo->prepare("
    SELECT id, class_name
    FROM classes
    WHERE class_teacher_id = ? AND COALESCE(is_active, 1) = 1
    ORDER BY class_name
");
$classTeacherStmt->execute([$userId]);
$classTeacherClasses = $classTeacherStmt->fetchAll(PDO::FETCH_ASSOC);
$isClassTeacher = !empty($classTeacherClasses);

$publishedPlan = $pdo->query("
    SELECT *
    FROM timetable_plans
    WHERE status = 'published'
    ORDER BY published_at DESC, id DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

function teacherTimetableMap(array $lessons): array
{
    $map = [];
    foreach ($lessons as $lesson) {
        $map[$lesson['day_of_week']][$lesson['period_id']] = $lesson;
    }
    return $map;
}

$viewType = $_GET['view'] ?? 'teacher';
if ($viewType === 'class' && !$isClassTeacher) {
    $viewType = 'teacher';
}

$selectedClassId = (int) ($_GET['class_id'] ?? ($classTeacherClasses[0]['id'] ?? 0));
$periods = [];
$lessons = [];
$lessonMap = [];
$pageTitle = 'My Timetable - ' . SCHOOL_NAME;
$selectedClassName = '';

foreach ($classTeacherClasses as $classTeacherClass) {
    if ((int) $classTeacherClass['id'] === $selectedClassId) {
        $selectedClassName = (string) $classTeacherClass['class_name'];
        break;
    }
}

if ($publishedPlan) {
    $periodStmt = $pdo->prepare("
        SELECT *
        FROM timetable_periods
        WHERE plan_id = ?
        ORDER BY sort_order, start_time
    ");
    $periodStmt->execute([(int) $publishedPlan['id']]);
    $periods = $periodStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($viewType === 'class' && $selectedClassId) {
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
        $lessonStmt->execute([(int) $publishedPlan['id'], $userId]);
    }

    $lessons = $lessonStmt->fetchAll(PDO::FETCH_ASSOC);
    $lessonMap = teacherTimetableMap($lessons);
}

$todayName = date('l');
$todayLessons = array_values(array_filter($lessons, fn($lesson) => $lesson['day_of_week'] === $todayName));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=DM+Sans:wght@400;500;700&display=swap');
        :root {
            --ink: #152238;
            --slate: #5b6b84;
            --line: rgba(21, 34, 56, 0.1);
            --sea: #0f766e;
            --amber: #f59e0b;
            --paper: rgba(255, 255, 255, 0.94);
            --sky: linear-gradient(135deg, #ecfeff 0%, #eef2ff 48%, #fef3c7 100%);
            --shadow: 0 18px 42px rgba(21, 34, 56, 0.12);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'DM Sans', sans-serif;
            background: var(--sky);
            color: var(--ink);
        }
        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 28px;
            min-height: calc(100vh - 70px);
        }
        .sidebar.collapsed ~ .main-content { margin-left: 70px; }
        .stack { display: grid; gap: 24px; }
        .hero, .panel {
            background: var(--paper);
            border-radius: 24px;
            box-shadow: var(--shadow);
            border: 1px solid rgba(255,255,255,0.7);
            backdrop-filter: blur(12px);
        }
        .hero {
            padding: 28px;
            display: grid;
            grid-template-columns: 1.3fr 0.9fr;
            gap: 24px;
            align-items: end;
        }
        h1, h2 {
            margin: 0;
            font-family: 'Space Grotesk', sans-serif;
        }
        .helper { color: var(--slate); }
        .actions, .pills, .toolbar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #eef2ff;
            font-weight: 700;
        }
        .btn {
            border: 0;
            border-radius: 14px;
            padding: 12px 18px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        .btn-primary { background: var(--ink); color: #fff; }
        .btn-soft { background: #eef2ff; color: var(--ink); }
        .panel { padding: 24px; }
        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
        }
        .stat {
            padding: 16px;
            border-radius: 18px;
            border: 1px solid var(--line);
            background: #fff;
        }
        .stat span {
            display: block;
            color: var(--slate);
            font-size: 0.85rem;
        }
        .stat strong {
            display: block;
            margin-top: 6px;
            font-size: 1.6rem;
        }
        .toolbar {
            justify-content: space-between;
            margin-bottom: 18px;
        }
        select {
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 11px 14px;
            font: inherit;
            min-width: 220px;
        }
        .table-wrap {
            overflow-x: auto;
            border-radius: 20px;
            border: 1px solid var(--line);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 940px;
            background: #fff;
        }
        th, td {
            border-bottom: 1px solid var(--line);
            border-right: 1px solid var(--line);
            padding: 14px;
            vertical-align: top;
        }
        thead th {
            background: #f8fafc;
            position: sticky;
            top: 0;
            z-index: 2;
        }
        th:first-child, td:first-child {
            position: sticky;
            left: 0;
            background: #f8fafc;
            z-index: 1;
        }
        .slot, .special-slot, .free-slot {
            min-height: 110px;
            border-radius: 18px;
            padding: 12px;
        }
        .slot {
            background: linear-gradient(180deg, #e0f2fe 0%, #ffffff 100%);
            border: 1px solid rgba(14, 116, 144, 0.18);
        }
        .special-slot {
            background: #fef3c7;
            border: 1px solid rgba(245, 158, 11, 0.28);
        }
        .free-slot {
            background: #f9fafb;
            border: 1px dashed rgba(91, 107, 132, 0.28);
            color: var(--slate);
        }
        .today-list {
            display: grid;
            gap: 12px;
        }
        .today-item {
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 14px;
            background: #fff;
        }
        @media print {
            .navigation, .sidebar, .hero .actions, .toolbar { display: none !important; }
            .main-content { margin: 0; padding: 0; }
            body { background: #fff; }
            .hero, .panel { box-shadow: none; border: 0; }
        }
        @media (max-width: 1100px) {
            .hero { grid-template-columns: 1fr; }
            .stats { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 16px; }
        }
    </style>
</head>
<body>
<?php include '../loader.php'; ?>
<?php include '../navigation.php'; ?>
<?php include '../sidebar.php'; ?>

<div class="main-content">
    <div class="stack">
        <section class="hero">
            <div>
                <div class="pills">
                    <span class="pill"><i class="fas fa-calendar-check"></i> Published Timetable</span>
                    <?php if ($publishedPlan): ?>
                        <span class="pill"><?php echo htmlspecialchars($publishedPlan['term'] . ' • ' . $publishedPlan['academic_year']); ?></span>
                    <?php endif; ?>
                </div>
                <h1 style="margin-top: 12px;">
                    <?php echo $viewType === 'class' ? 'Class timetable ready for review and printing.' : 'Your teaching timetable is ready.'; ?>
                </h1>
                <p class="helper">
                    <?php if ($publishedPlan): ?>
                        <?php echo htmlspecialchars($publishedPlan['title']); ?> was published by the admin. You can review it here and print the current school copy directly from this page.
                    <?php else: ?>
                        There is no published timetable yet. Once the admin publishes one, it will appear here automatically.
                    <?php endif; ?>
                </p>
                <div class="actions">
                    <button class="btn btn-primary" type="button" onclick="window.print()"><i class="fas fa-print"></i> Print Timetable</button>
                    <a class="btn btn-soft" href="timetable_printer.php?type=<?php echo $viewType === 'class' ? 'class' : 'teacher'; ?><?php echo $viewType === 'class' && $selectedClassId ? '&class_id=' . (int) $selectedClassId : ''; ?>" target="_blank" rel="noopener noreferrer">
                        <i class="fas fa-file-lines"></i> Print View
                    </a>
                    <a class="btn btn-soft" href="timetable_export.php?view=<?php echo $viewType === 'class' ? 'class' : 'teacher'; ?><?php echo $viewType === 'class' && $selectedClassId ? '&class_id=' . (int) $selectedClassId : ''; ?><?php echo $viewType !== 'class' ? '&teacher_id=' . (int) $userId : ''; ?>">
                        <i class="fas fa-download"></i> Export
                    </a>
                    <?php if ($isClassTeacher): ?>
                        <a class="btn btn-soft" href="timetable.php?view=<?php echo $viewType === 'class' ? 'teacher' : 'class'; ?>&class_id=<?php echo $selectedClassId; ?>">
                            <i class="fas fa-repeat"></i>
                            <?php echo $viewType === 'class' ? 'Switch to Teacher View' : 'Switch to Class View'; ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="stats">
                <div class="stat"><span>Published Slots</span><strong><?php echo count($periods); ?></strong></div>
                <div class="stat"><span>Allocated Lessons</span><strong><?php echo count($lessons); ?></strong></div>
                <div class="stat"><span>Today</span><strong><?php echo count($todayLessons); ?></strong></div>
            </div>
        </section>

        <section class="panel">
            <div class="toolbar">
                <div>
                    <h2>
                        <?php if ($viewType === 'class'): ?>
                            Class Timetable<?php echo $selectedClassName !== '' ? ' - ' . htmlspecialchars($selectedClassName) : ''; ?>
                        <?php else: ?>
                            Teacher Timetable<?php echo !empty($teacher['full_name']) ? ' - ' . htmlspecialchars($teacher['full_name']) : ''; ?>
                        <?php endif; ?>
                    </h2>
                    <p class="helper">
                        <?php if ($viewType === 'class' && $selectedClassId): ?>
                            You are viewing the published timetable for <?php echo htmlspecialchars($selectedClassName ?: 'your class'); ?>.
                        <?php else: ?>
                            You are viewing the published teaching timetable for <?php echo htmlspecialchars($teacher['full_name'] ?? 'this teacher'); ?>.
                        <?php endif; ?>
                    </p>
                </div>
                <?php if ($isClassTeacher): ?>
                    <form method="get" class="toolbar">
                        <input type="hidden" name="view" value="<?php echo htmlspecialchars($viewType); ?>">
                        <select name="class_id" onchange="this.form.submit()">
                            <?php foreach ($classTeacherClasses as $class): ?>
                                <option value="<?php echo (int) $class['id']; ?>" <?php echo (int) $class['id'] === $selectedClassId ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (!$publishedPlan): ?>
                <div class="today-item">
                    <strong>No published timetable yet.</strong>
                    <div class="helper">Check back after the admin completes review and publication.</div>
                </div>
            <?php elseif (empty($periods)): ?>
                <div class="today-item">
                    <strong>The published plan has no periods configured.</strong>
                    <div class="helper">Ask the admin to finish period setup before publishing again.</div>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Day</th>
                                <?php foreach ($periods as $period): ?>
                                    <th>
                                        <strong><?php echo htmlspecialchars($period['label']); ?></strong><br>
                                        <span class="helper"><?php echo htmlspecialchars(substr($period['start_time'], 0, 5) . ' - ' . substr($period['end_time'], 0, 5)); ?></span>
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
                                                <div class="special-slot">
                                                    <strong><?php echo htmlspecialchars($period['label']); ?></strong>
                                                    <div><?php echo ucfirst($period['period_type']); ?></div>
                                                </div>
                                            <?php elseif ($cell): ?>
                                                <div class="slot">
                                                    <strong><?php echo htmlspecialchars($cell['subject_name']); ?></strong>
                                                    <?php if ($viewType === 'class'): ?>
                                                        <div><?php echo htmlspecialchars($cell['teacher_name'] ?: 'Teacher not assigned'); ?></div>
                                                    <?php else: ?>
                                                        <div><?php echo htmlspecialchars($cell['class_name']); ?></div>
                                                    <?php endif; ?>
                                                    <div class="helper"><?php echo htmlspecialchars($cell['room_name'] ?: 'No room assigned'); ?></div>
                                                    <?php if (!empty($cell['room_number'])): ?><div class="helper"><?php echo htmlspecialchars($cell['room_number']); ?></div><?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="free-slot">
                                                    <strong>Free Slot</strong>
                                                    <div>No lesson assigned</div>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="toolbar">
                <div>
                    <h2>Today’s Schedule</h2>
                    <p class="helper">A quick rundown for <?php echo htmlspecialchars($todayName); ?>.</p>
                </div>
            </div>
            <div class="today-list">
                <?php if (empty($todayLessons)): ?>
                    <div class="today-item">
                        <strong>No lessons scheduled today.</strong>
                        <div class="helper">You have a clear timetable for this day or this view has no entries for today.</div>
                    </div>
                <?php endif; ?>
                <?php foreach ($todayLessons as $lesson): ?>
                    <div class="today-item">
                        <strong><?php echo htmlspecialchars($lesson['subject_name']); ?></strong>
                        <div class="helper">
                            <?php echo htmlspecialchars(substr($lesson['start_time'], 0, 5) . ' - ' . substr($lesson['end_time'], 0, 5)); ?>
                            •
                            <?php echo htmlspecialchars($viewType === 'class' ? ($lesson['teacher_name'] ?: 'Teacher not assigned') : $lesson['class_name']); ?>
                        </div>
                        <div class="helper"><?php echo htmlspecialchars($lesson['room_name'] ?: 'No room assigned'); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>
</body>
</html>
