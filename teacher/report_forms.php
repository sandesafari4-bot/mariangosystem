<?php
include '../config.php';
checkAuth();
checkRole(['teacher', 'admin']);

$teacher_id = $_SESSION['user_id'];
$selected_exam_id = isset($_GET['exam_id']) ? (int) $_GET['exam_id'] : 0;
$selected_class_id = isset($_GET['class_id']) ? (int) $_GET['class_id'] : 0;
$view_mode = $_GET['view_mode'] ?? 'class';
$selected_student_id = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;

$availableStmt = $pdo->prepare("
    SELECT DISTINCT
        e.id AS exam_id,
        e.exam_name,
        e.term,
        e.academic_year,
        c.id AS class_id,
        c.class_name
    FROM exam_schedules es
    JOIN exams e ON e.id = es.exam_id
    JOIN classes c ON c.id = es.class_id
    LEFT JOIN subjects s ON s.id = es.subject_id
    WHERE es.status = 'published'
      AND (
          es.teacher_id = ?
          OR s.teacher_id = ?
          OR c.class_teacher_id = ?
      )
    ORDER BY e.created_at DESC, c.class_name ASC
");
$availableStmt->execute([$teacher_id, $teacher_id, $teacher_id]);
$availableReports = $availableStmt->fetchAll(PDO::FETCH_ASSOC);

if ((!$selected_exam_id || !$selected_class_id) && !empty($availableReports)) {
    $selected_exam_id = (int) $availableReports[0]['exam_id'];
    $selected_class_id = (int) $availableReports[0]['class_id'];
}

$examInfo = null;
$students = [];
$subjectMeans = [];
$subjectNames = [];
$marksByStudent = [];
$classMean = 0;
$selectedStudent = null;
$classTeacherName = 'Not Assigned';
$schoolName = getSystemSetting('school_name', SCHOOL_NAME);
$schoolAddress = getSystemSetting('school_address', defined('SCHOOL_LOCATION') ? SCHOOL_LOCATION : '');
$schoolPhone = getSystemSetting('school_phone', '');
$schoolEmail = getSystemSetting('school_email', '');
$schoolMotto = getSystemSetting('school_motto', '');
$schoolPrincipal = getSystemSetting('school_principal', '');
$schoolLogo = getSystemSetting('school_logo', defined('SCHOOL_LOGO') ? SCHOOL_LOGO : '');
$previousExamComparison = null;

if ($selected_exam_id && $selected_class_id) {
    $examInfoStmt = $pdo->prepare("
        SELECT DISTINCT
            e.id,
            e.exam_name,
            e.exam_code,
            e.term,
            e.academic_year,
            e.total_marks,
            e.passing_marks,
            c.class_name,
            c.id AS class_id,
            u.full_name AS class_teacher_name,
            e.created_at
        FROM exams e
        JOIN exam_schedules es ON es.exam_id = e.id
        JOIN classes c ON c.id = es.class_id
        LEFT JOIN users u ON c.class_teacher_id = u.id
        LEFT JOIN subjects s ON s.id = es.subject_id
        WHERE e.id = ?
          AND c.id = ?
          AND es.status = 'published'
          AND (
              es.teacher_id = ?
              OR s.teacher_id = ?
              OR c.class_teacher_id = ?
          )
        LIMIT 1
    ");
        $examInfoStmt->execute([$selected_exam_id, $selected_class_id, $teacher_id, $teacher_id, $teacher_id]);
        $examInfo = $examInfoStmt->fetch(PDO::FETCH_ASSOC);

        if ($examInfo) {
            $classTeacherName = $examInfo['class_teacher_name'] ?: 'Not Assigned';
            $studentsStmt = $pdo->prepare("
            SELECT
                st.id,
                st.full_name,
                st.Admission_number,
                st.gender,
                st.admission_date,
                eg.total_marks,
                eg.average_marks,
                eg.grade,
                eg.rank,
                eg.pass_status
            FROM exam_grades eg
            JOIN students st ON st.id = eg.student_id
            WHERE eg.exam_id = ?
              AND st.class_id = ?
              AND st.status = 'active'
            ORDER BY eg.rank ASC, st.full_name ASC
        ");
        $studentsStmt->execute([$selected_exam_id, $selected_class_id]);
        $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

        $subjectMeansStmt = $pdo->prepare("
            SELECT
                sub.subject_name,
                ROUND(AVG(COALESCE(em.marks_obtained, em.marks, 0)), 2) AS subject_mean
            FROM exam_schedules es
            JOIN subjects sub ON sub.id = es.subject_id
            LEFT JOIN exam_marks em ON em.exam_schedule_id = es.id
            WHERE es.exam_id = ?
              AND es.class_id = ?
            GROUP BY es.subject_id, sub.subject_name
            ORDER BY sub.subject_name ASC
        ");
        $subjectMeansStmt->execute([$selected_exam_id, $selected_class_id]);
        $subjectMeans = $subjectMeansStmt->fetchAll(PDO::FETCH_ASSOC);
        $subjectNames = array_column($subjectMeans, 'subject_name');

        $marksStmt = $pdo->prepare("
            SELECT
                em.student_id,
                sub.subject_name,
                COALESCE(em.marks_obtained, em.marks, 0) AS marks
            FROM exam_marks em
            JOIN exam_schedules es ON es.id = em.exam_schedule_id
            JOIN subjects sub ON sub.id = es.subject_id
            WHERE es.exam_id = ?
              AND es.class_id = ?
            ORDER BY sub.subject_name ASC
        ");
        $marksStmt->execute([$selected_exam_id, $selected_class_id]);
        foreach ($marksStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $marksByStudent[$row['student_id']][$row['subject_name']] = $row['marks'];
        }

        if (!empty($students)) {
            $classMean = round(array_sum(array_column($students, 'average_marks')) / count($students), 2);
        }

        if ($view_mode === 'individual' && $selected_student_id > 0) {
            foreach ($students as $student) {
                if ((int) $student['id'] === $selected_student_id) {
                    $selectedStudent = $student;
                    break;
                }
            }
        } elseif ($view_mode === 'individual' && !empty($students)) {
            $selectedStudent = $students[0];
            $selected_student_id = (int) $selectedStudent['id'];
        }

        if ($selectedStudent && $examInfo) {
            $previousExamStmt = $pdo->prepare("
                SELECT
                    e.id,
                    e.exam_name,
                    e.term,
                    e.academic_year,
                    e.created_at,
                    eg.average_marks,
                    eg.grade,
                    eg.rank
                FROM exams e
                JOIN exam_grades eg ON eg.exam_id = e.id
                JOIN students st ON st.id = eg.student_id
                JOIN exam_schedules es ON es.exam_id = e.id AND es.class_id = st.class_id
                WHERE eg.student_id = ?
                  AND st.class_id = ?
                  AND e.id <> ?
                  AND es.status = 'published'
                  AND e.created_at < ?
                ORDER BY e.created_at DESC
                LIMIT 1
            ");
            $previousExamStmt->execute([
                $selectedStudent['id'],
                $selected_class_id,
                $selected_exam_id,
                $examInfo['created_at'],
            ]);
            $previousExamComparison = $previousExamStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
}

function reportFormRemark($grade, $passStatus) {
    if ($passStatus !== 'pass') {
        return 'Needs improvement';
    }

    return match ($grade) {
        'A' => 'Excellent',
        'B' => 'Very good',
        'C' => 'Good progress',
        'D', 'E' => 'Fair effort',
        default => 'Keep working',
    };
}

function reportGradePoint($grade) {
    return match (strtoupper((string) $grade)) {
        'A' => 12,
        'B' => 9,
        'C' => 6,
        'D' => 3,
        'E' => 2,
        default => 1,
    };
}

function reportTrendLabel($current, $previous) {
    $delta = round((float) $current - (float) $previous, 2);
    if ($delta > 0) {
        return ['text' => '+' . number_format($delta, 2) . ' points', 'class' => 'trend-up'];
    }
    if ($delta < 0) {
        return ['text' => number_format($delta, 2) . ' points', 'class' => 'trend-down'];
    }
    return ['text' => 'No change', 'class' => 'trend-flat'];
}

function reportOriginalityCode($examId, $studentId) {
    return strtoupper(substr(sha1('mariango-report-' . $examId . '-' . $studentId), 0, 12));
}

function reportLogoUrl($logo) {
    $logoFile = basename((string) $logo);
    if ($logoFile !== '' && is_file(__DIR__ . '/../uploads/logos/' . $logoFile)) {
        return '../uploads/logos/' . rawurlencode($logoFile);
    }
    return '../logo.png';
}

$page_title = 'Report Forms - ' . SCHOOL_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            background: #f3f4f6;
            color: #1f2937;
        }
        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(15, 23, 42, 0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            align-items: end;
        }
        label {
            display: block;
            margin-bottom: .4rem;
            font-weight: 600;
        }
        select, button {
            width: 100%;
            padding: .8rem 1rem;
            border-radius: 10px;
            border: 1px solid #d1d5db;
            font: inherit;
        }
        .btn {
            background: #2563eb;
            color: #fff;
            border: none;
            cursor: pointer;
            font-weight: 600;
        }
        .btn-secondary {
            background: #111827;
        }
        .header-row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .summary .item {
            padding: 1rem;
            border-radius: 12px;
            background: #eff6ff;
        }
        .student-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .student-card {
            padding: 1rem;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
        }
        .report-shell {
            border: 2px solid #dbe4f0;
            border-radius: 18px;
            overflow: hidden;
        }
        .report-header {
            background: linear-gradient(135deg, #0f172a, #1d4ed8);
            color: #fff;
            padding: 1.5rem;
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 1rem;
            align-items: center;
        }
        .report-header img {
            width: 72px;
            height: 72px;
            object-fit: cover;
            border-radius: 14px;
            background: #fff;
            padding: .35rem;
        }
        .report-title {
            text-transform: uppercase;
            letter-spacing: .08em;
            font-size: .8rem;
            opacity: .85;
        }
        .report-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            padding: 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
        }
        .report-grid .block {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 1rem;
        }
        .report-grid .block strong {
            display: block;
            margin-bottom: .45rem;
            color: #111827;
        }
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            padding: 1.5rem;
        }
        .kpi {
            background: #eff6ff;
            border-radius: 14px;
            padding: 1rem;
            text-align: center;
        }
        .kpi .value {
            font-size: 1.4rem;
            font-weight: 800;
            margin-top: .25rem;
        }
        .trend-up { color: #166534; font-weight: 700; }
        .trend-down { color: #b91c1c; font-weight: 700; }
        .trend-flat { color: #475569; font-weight: 700; }
        .originality-box {
            margin: 1.5rem;
            padding: 1rem 1.2rem;
            border: 1px dashed #94a3b8;
            border-radius: 14px;
            background: #f8fafc;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: .92rem;
        }
        th, td {
            border: 1px solid #e5e7eb;
            padding: .75rem;
            text-align: left;
        }
        th {
            background: #f9fafb;
            white-space: nowrap;
        }
        .table-wrap {
            overflow-x: auto;
        }
        .muted {
            color: #6b7280;
        }
        @media print {
            body {
                background: #fff;
            }
            .main-content {
                margin: 0;
                padding: 0;
            }
            .no-print {
                display: none !important;
            }
            .card {
                box-shadow: none;
                border-radius: 0;
                padding: 0;
            }
            .report-shell {
                border: 1px solid #cbd5e1;
            }
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            .report-header {
                grid-template-columns: 1fr;
                text-align: center;
            }
        }
    </style>
</head>
<body>
<?php include '../loader.php'; ?>
<?php include '../navigation.php'; ?>
<?php include '../sidebar.php'; ?>

<div class="main-content">
    <div class="card no-print">
        <div class="header-row">
            <div>
                <h1 style="margin: 0;">Report Form Generator</h1>
                <p class="muted" style="margin: .35rem 0 0;">Print finalized report forms for exams whose results are already ready.</p>
            </div>
            <?php if ($examInfo): ?>
                <button type="button" class="btn-secondary btn" style="width: auto;" onclick="window.print()">
                    <i class="fas fa-print"></i> Print This Report
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="card no-print">
            <form method="GET" class="filters">
            <div>
                <label for="view_mode">View</label>
                <select name="view_mode" id="view_mode">
                    <option value="class" <?php echo $view_mode === 'class' ? 'selected' : ''; ?>>Class Report Form</option>
                    <option value="individual" <?php echo $view_mode === 'individual' ? 'selected' : ''; ?>>Individual Result Form</option>
                </select>
            </div>
            <div>
                <label for="exam_id">Exam</label>
                <select name="exam_id" id="exam_id" required>
                    <option value="">Select Exam</option>
                    <?php
                    $printedExams = [];
                    foreach ($availableReports as $option):
                        if (isset($printedExams[$option['exam_id']])) continue;
                        $printedExams[$option['exam_id']] = true;
                    ?>
                        <option value="<?php echo (int) $option['exam_id']; ?>" <?php echo $selected_exam_id === (int) $option['exam_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($option['exam_name'] . ' - ' . $option['term'] . ' (' . $option['academic_year'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="class_id">Class</label>
                <select name="class_id" id="class_id" required>
                    <option value="">Select Class</option>
                    <?php foreach ($availableReports as $option): ?>
                        <?php if ($selected_exam_id && (int) $option['exam_id'] !== $selected_exam_id) continue; ?>
                        <option value="<?php echo (int) $option['class_id']; ?>" <?php echo $selected_class_id === (int) $option['class_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($option['class_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="student_id">Student</label>
                <select name="student_id" id="student_id" <?php echo $view_mode === 'individual' ? 'required' : ''; ?>>
                    <option value="">Select Student</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?php echo (int) $student['id']; ?>" <?php echo $selected_student_id === (int) $student['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($student['full_name'] . ' - ' . $student['Admission_number']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="btn">
                    <i class="fas fa-file-alt"></i> Generate Report
                </button>
            </div>
        </form>
    </div>

    <?php if (!$examInfo): ?>
        <div class="card">
            <p class="muted" style="margin: 0;">No finalized exam report forms are available for your classes or subjects yet.</p>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="header-row">
                <div>
                    <h2 style="margin: 0;"><?php echo htmlspecialchars($examInfo['exam_name']); ?></h2>
                    <p class="muted" style="margin: .35rem 0 0;">
                        <?php echo htmlspecialchars($examInfo['class_name']); ?> •
                        <?php echo htmlspecialchars($examInfo['term']); ?> •
                        <?php echo htmlspecialchars($examInfo['academic_year']); ?> •
                        Code: <?php echo htmlspecialchars($examInfo['exam_code']); ?>
                    </p>
                </div>
                <div class="muted">Generated on <?php echo date('d M Y H:i'); ?></div>
            </div>

            <div class="summary">
                <div class="item">
                    <strong>Students</strong>
                    <div><?php echo count($students); ?></div>
                </div>
                <div class="item">
                    <strong>Class Mean</strong>
                    <div><?php echo number_format($classMean, 2); ?></div>
                </div>
                <div class="item">
                    <strong>Subjects</strong>
                    <div><?php echo count($subjectNames); ?></div>
                </div>
                <div class="item">
                    <strong>Pass Mark</strong>
                    <div><?php echo number_format((float) $examInfo['passing_marks'], 2); ?></div>
                </div>
            </div>
        </div>

        <?php if ($view_mode === 'individual' && $selectedStudent): ?>
            <div class="card">
                <?php
                $agp = reportGradePoint($selectedStudent['grade']);
                $trend = $previousExamComparison ? reportTrendLabel($selectedStudent['average_marks'], $previousExamComparison['average_marks']) : null;
                $rankMovement = null;
                if ($previousExamComparison && isset($previousExamComparison['rank'])) {
                    $rankDiff = (int) $previousExamComparison['rank'] - (int) $selectedStudent['rank'];
                    if ($rankDiff > 0) {
                        $rankMovement = '+' . $rankDiff . ' places';
                    } elseif ($rankDiff < 0) {
                        $rankMovement = $rankDiff . ' places';
                    } else {
                        $rankMovement = 'No movement';
                    }
                }
                ?>
                <div class="report-shell">
                    <div class="report-header">
                        <img src="<?php echo htmlspecialchars(reportLogoUrl($schoolLogo)); ?>" alt="School Logo">
                        <div>
                            <div class="report-title">Official Student Report Form</div>
                            <h2 style="margin:.2rem 0;"><?php echo htmlspecialchars($schoolName); ?></h2>
                            <div style="opacity:.92;">
                                <?php echo htmlspecialchars($schoolAddress !== '' ? $schoolAddress : SCHOOL_LOCATION); ?>
                                <?php if ($schoolPhone !== ''): ?> | <?php echo htmlspecialchars($schoolPhone); ?><?php endif; ?>
                                <?php if ($schoolEmail !== ''): ?> | <?php echo htmlspecialchars($schoolEmail); ?><?php endif; ?>
                            </div>
                            <?php if ($schoolMotto !== ''): ?>
                                <div style="margin-top:.4rem; font-style: italic; opacity:.9;"><?php echo htmlspecialchars($schoolMotto); ?></div>
                            <?php endif; ?>
                        </div>
                        <div style="text-align:right;">
                            <strong><?php echo htmlspecialchars($examInfo['exam_name']); ?></strong><br>
                            <span><?php echo htmlspecialchars($examInfo['term'] . ' / ' . $examInfo['academic_year']); ?></span><br>
                            <span>Code: <?php echo htmlspecialchars($examInfo['exam_code']); ?></span>
                        </div>
                    </div>

                    <div class="report-grid">
                        <div class="block">
                            <strong>Student Details</strong>
                            Name: <?php echo htmlspecialchars($selectedStudent['full_name']); ?><br>
                            Admission No: <?php echo htmlspecialchars($selectedStudent['Admission_number']); ?><br>
                            Gender: <?php echo htmlspecialchars($selectedStudent['gender'] ?: '-'); ?><br>
                            Admission Date:
                            <?php echo !empty($selectedStudent['admission_date']) ? date('d M Y', strtotime($selectedStudent['admission_date'])) : '-'; ?>
                        </div>
                        <div class="block">
                            <strong>Class Details</strong>
                            Class: <?php echo htmlspecialchars($examInfo['class_name']); ?><br>
                            Class Teacher: <?php echo htmlspecialchars($classTeacherName); ?><br>
                            Class Size: <?php echo count($students); ?><br>
                            Pass Mark: <?php echo number_format((float) $examInfo['passing_marks'], 2); ?>
                        </div>
                        <div class="block">
                            <strong>School Leadership</strong>
                            Principal: <?php echo htmlspecialchars($schoolPrincipal !== '' ? $schoolPrincipal : 'Not Set'); ?><br>
                            Generated: <?php echo date('d M Y H:i'); ?><br>
                            Status: <?php echo htmlspecialchars(ucfirst($selectedStudent['pass_status'])); ?><br>
                            Originality Code: <?php echo htmlspecialchars(reportOriginalityCode($selected_exam_id, $selectedStudent['id'])); ?>
                        </div>
                    </div>

                    <div class="kpi-row">
                        <div class="kpi">
                            <div>Average</div>
                            <div class="value"><?php echo number_format((float) $selectedStudent['average_marks'], 2); ?></div>
                        </div>
                        <div class="kpi">
                            <div>AGP</div>
                            <div class="value"><?php echo $agp; ?></div>
                        </div>
                        <div class="kpi">
                            <div>Grade</div>
                            <div class="value"><?php echo htmlspecialchars($selectedStudent['grade']); ?></div>
                        </div>
                        <div class="kpi">
                            <div>Class Position</div>
                            <div class="value"><?php echo (int) $selectedStudent['rank']; ?></div>
                        </div>
                        <div class="kpi">
                            <div>Class Mean</div>
                            <div class="value"><?php echo number_format($classMean, 2); ?></div>
                        </div>
                        <div class="kpi">
                            <div>Remark</div>
                            <div class="value" style="font-size:1rem;"><?php echo htmlspecialchars(reportFormRemark($selectedStudent['grade'], $selectedStudent['pass_status'])); ?></div>
                        </div>
                    </div>

                    <?php if ($previousExamComparison): ?>
                        <div class="report-grid" style="background:#fff;">
                            <div class="block">
                                <strong>Previous Exam Comparison</strong>
                                Previous Exam: <?php echo htmlspecialchars($previousExamComparison['exam_name']); ?><br>
                                Previous Mean: <?php echo number_format((float) $previousExamComparison['average_marks'], 2); ?><br>
                                Previous Grade: <?php echo htmlspecialchars($previousExamComparison['grade']); ?><br>
                                Previous Rank: <?php echo (int) $previousExamComparison['rank']; ?>
                            </div>
                            <div class="block">
                                <strong>Movement</strong>
                                Mean Change:
                                <span class="<?php echo htmlspecialchars($trend['class']); ?>"><?php echo htmlspecialchars($trend['text']); ?></span><br>
                                Rank Movement:
                                <span class="<?php echo $rankMovement !== null && str_starts_with($rankMovement, '+') ? 'trend-up' : (is_string($rankMovement) && str_starts_with($rankMovement, '-') ? 'trend-down' : 'trend-flat'); ?>">
                                    <?php echo htmlspecialchars($rankMovement ?? 'No previous exam'); ?>
                                </span><br>
                                Performance Direction:
                                <span class="<?php echo htmlspecialchars($trend['class']); ?>">
                                    <?php echo str_contains($trend['text'], '+') ? 'Improved' : (str_contains($trend['text'], '-') ? 'Dropped' : 'Stable'); ?>
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div style="padding: 0 1.5rem 1.5rem;">
                        <h3 style="margin-top: 0;">Subject Performance</h3>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Marks</th>
                                        <th>Class Mean</th>
                                        <th>Difference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($subjectMeans as $subjectMean): ?>
                                    <?php
                                    $subjectScore = (float) ($marksByStudent[$selectedStudent['id']][$subjectMean['subject_name']] ?? 0);
                                    $subjectDiff = round($subjectScore - (float) $subjectMean['subject_mean'], 2);
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($subjectMean['subject_name']); ?></td>
                                        <td><?php echo number_format($subjectScore, 1); ?></td>
                                        <td><?php echo number_format((float) $subjectMean['subject_mean'], 2); ?></td>
                                        <td class="<?php echo $subjectDiff > 0 ? 'trend-up' : ($subjectDiff < 0 ? 'trend-down' : 'trend-flat'); ?>">
                                            <?php echo $subjectDiff > 0 ? '+' : ''; ?><?php echo number_format($subjectDiff, 2); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="originality-box">
                        <strong>Originality & Verification</strong><br>
                        This report form is an original system-generated academic record from <?php echo htmlspecialchars($schoolName); ?>.
                        Verification Ref: <strong><?php echo htmlspecialchars(reportOriginalityCode($selected_exam_id, $selectedStudent['id'])); ?></strong>.
                        Generated on <?php echo date('d M Y H:i'); ?> for <?php echo htmlspecialchars($selectedStudent['full_name']); ?>.
                    </div>
                </div>
            </div>

            <div class="card">
                <h3 style="margin-top: 0;">Subject Performance Summary</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Marks</th>
                                <th>Class Mean</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjectMeans as $subjectMean): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($subjectMean['subject_name']); ?></td>
                                    <td><?php echo number_format((float) ($marksByStudent[$selectedStudent['id']][$subjectMean['subject_name']] ?? 0), 1); ?></td>
                                    <td><?php echo number_format((float) $subjectMean['subject_mean'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <h3 style="margin-top: 0;">Subject Means</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Mean Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjectMeans as $subjectMean): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($subjectMean['subject_name']); ?></td>
                                    <td><?php echo number_format((float) $subjectMean['subject_mean'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <h3 style="margin-top: 0;">Class Report Form</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Admission No.</th>
                                <th>Student</th>
                                <?php foreach ($subjectNames as $subjectName): ?>
                                    <th><?php echo htmlspecialchars($subjectName); ?></th>
                                <?php endforeach; ?>
                                <th>Total</th>
                                <th>Mean</th>
                                <th>Grade</th>
                                <th>Status</th>
                                <th>Remark</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td><?php echo (int) $student['rank']; ?></td>
                                    <td><?php echo htmlspecialchars($student['Admission_number']); ?></td>
                                    <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                    <?php foreach ($subjectNames as $subjectName): ?>
                                        <td><?php echo number_format((float) ($marksByStudent[$student['id']][$subjectName] ?? 0), 1); ?></td>
                                    <?php endforeach; ?>
                                    <td><?php echo number_format((float) $student['total_marks'], 1); ?></td>
                                    <td><?php echo number_format((float) $student['average_marks'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($student['grade']); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($student['pass_status'])); ?></td>
                                    <td><?php echo htmlspecialchars(reportFormRemark($student['grade'], $student['pass_status'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
