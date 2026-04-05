<?php
include '../config.php';
checkAuth();
checkRole(['admin']);

ensureAcademicCalendarSchema($pdo);
ensureAcademicLifecycle($pdo);

$today = date('Y-m-d');
$calendarStatus = fetchAcademicTermStatus($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_calendar'])) {
    $academicYear = trim((string) ($_POST['academic_year'] ?? ''));
    $termRows = $_POST['terms'] ?? [];

    try {
        if ($academicYear === '') {
            throw new RuntimeException('Academic year is required.');
        }

        $validTerms = [];
        foreach ($termRows as $row) {
            $termName = trim((string) ($row['name'] ?? ''));
            $startDate = trim((string) ($row['start_date'] ?? ''));
            $endDate = trim((string) ($row['end_date'] ?? ''));

            if ($termName === '' && $startDate === '' && $endDate === '') {
                continue;
            }
            if ($termName === '' || $startDate === '' || $endDate === '') {
                throw new RuntimeException('Each configured term must have a name, start date, and end date.');
            }
            if ($startDate > $endDate) {
                throw new RuntimeException($termName . ' has an end date earlier than its start date.');
            }

            $validTerms[] = [
                'name' => $termName,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ];
        }

        if (empty($validTerms)) {
            throw new RuntimeException('Add at least one term date range.');
        }

        usort($validTerms, fn($a, $b) => strcmp($a['start_date'], $b['start_date']));

        $pdo->beginTransaction();
        $pdo->exec("UPDATE academic_years SET is_active = 0");

        $yearStmt = $pdo->prepare("
            INSERT INTO academic_years (year, is_active)
            VALUES (?, 1)
            ON DUPLICATE KEY UPDATE is_active = 1, updated_at = CURRENT_TIMESTAMP
        ");
        $yearStmt->execute([$academicYear]);

        $yearIdStmt = $pdo->prepare("SELECT id FROM academic_years WHERE year = ? LIMIT 1");
        $yearIdStmt->execute([$academicYear]);
        $academicYearId = (int) $yearIdStmt->fetchColumn();

        $pdo->prepare("DELETE FROM academic_terms WHERE academic_year_label = ?")->execute([$academicYear]);

        $insertStmt = $pdo->prepare("
            INSERT INTO academic_terms (
                academic_year_id, academic_year_label, term_name, start_date, end_date,
                status, progress_percent, created_by, updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $activeAssigned = false;
        $currentTermName = '';

        foreach ($validTerms as $term) {
            if (!$activeAssigned && $today >= $term['start_date'] && $today <= $term['end_date']) {
                $status = 'active';
                $currentTermName = $term['name'];
                $activeAssigned = true;
            } elseif ($today > $term['end_date']) {
                $status = 'closed';
            } else {
                $status = 'upcoming';
            }

            $progress = 0;
            if ($status === 'closed') {
                $progress = 100;
            } elseif ($status === 'active') {
                $startTs = strtotime($term['start_date']);
                $endTs = strtotime($term['end_date']);
                $totalDays = max(1, (int) floor(($endTs - $startTs) / 86400) + 1);
                $elapsedDays = min($totalDays, max(0, (int) floor((strtotime($today) - $startTs) / 86400) + 1));
                $progress = round(($elapsedDays / $totalDays) * 100, 2);
            }

            $insertStmt->execute([
                $academicYearId ?: null,
                $academicYear,
                $term['name'],
                $term['start_date'],
                $term['end_date'],
                $status,
                $progress,
                (int) ($_SESSION['user_id'] ?? 0),
                (int) ($_SESSION['user_id'] ?? 0),
            ]);
        }

        saveSystemSetting('academic_year', $academicYear);
        saveSystemSetting('current_term_name', $currentTermName);
        saveSystemSetting('term_setup_required', $activeAssigned ? '0' : '1');

        $pdo->commit();
        ensureAcademicLifecycle($pdo);

        createRoleNotification(
            'Academic Calendar Updated',
            'The admin updated the academic year and term calendar for ' . $academicYear . '.',
            'system',
            ['admin', 'teacher', 'accountant', 'librarian'],
            'high'
        );

        $_SESSION['success'] = 'Academic calendar saved successfully.';
        header('Location: academic_calendar.php');
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = 'Academic calendar update failed: ' . $e->getMessage();
        header('Location: academic_calendar.php');
        exit();
    }
}

$years = $pdo->query("SELECT * FROM academic_years ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);
$terms = $pdo->query("SELECT * FROM academic_terms ORDER BY academic_year_label DESC, start_date ASC")->fetchAll(PDO::FETCH_ASSOC);
$activeYear = getSystemSetting('academic_year', date('Y'));
$currentTerm = $calendarStatus['current_term'] ?? null;
$nextTerm = $calendarStatus['next_term'] ?? null;
$termSetupRequired = (bool) ($calendarStatus['term_setup_required'] ?? false);
$page_title = 'Academic Calendar - ' . SCHOOL_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { margin:0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#f4f7fb; color:#1f2937; }
        .main-content { margin-left:280px; margin-top:70px; padding:2rem; min-height:calc(100vh - 70px); }
        .card { background:#fff; border-radius:20px; box-shadow:0 12px 30px rgba(15,23,42,0.08); padding:1.5rem; margin-bottom:1.5rem; }
        .hero { background:linear-gradient(135deg,#123c69,#2a6f97); color:#fff; }
        .hero h1 { margin:0 0 .5rem; }
        .hero p { margin:0; opacity:.9; }
        .grid { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; }
        .metric { background:#f8fafc; border-radius:16px; padding:1rem; }
        .metric small { color:#64748b; display:block; margin-bottom:.35rem; }
        .metric strong { font-size:1.7rem; }
        .progress-track { height:14px; background:#dbeafe; border-radius:999px; overflow:hidden; margin-top:.7rem; }
        .progress-bar { height:100%; background:linear-gradient(90deg,#10b981,#3b82f6); }
        .form-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; }
        .term-grid { display:grid; grid-template-columns:1.2fr 1fr 1fr; gap:1rem; margin-top:1rem; }
        label { display:block; font-weight:600; margin-bottom:.45rem; }
        input { width:100%; padding:.85rem 1rem; border:1px solid #cbd5e1; border-radius:12px; font:inherit; }
        .btn { border:0; border-radius:12px; padding:.9rem 1.25rem; cursor:pointer; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:.55rem; }
        .btn-primary { background:#0f766e; color:#fff; }
        .btn-soft { background:#e2e8f0; color:#0f172a; }
        .alert { padding:1rem 1.1rem; border-radius:14px; margin-bottom:1rem; }
        .alert-success { background:#dcfce7; color:#166534; }
        .alert-error { background:#fee2e2; color:#991b1b; }
        .alert-info { background:#e0f2fe; color:#0f4c81; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:.9rem; border-bottom:1px solid #e5e7eb; text-align:left; }
        th { color:#475569; font-size:.85rem; text-transform:uppercase; letter-spacing:.05em; }
        .badge { display:inline-block; padding:.35rem .8rem; border-radius:999px; font-size:.78rem; font-weight:700; text-transform:uppercase; }
        .badge-active { background:#d1fae5; color:#065f46; }
        .badge-upcoming { background:#dbeafe; color:#1d4ed8; }
        .badge-closed { background:#e5e7eb; color:#475569; }
        .subtle { color:#64748b; margin-top:.35rem; line-height:1.6; }
        @media (max-width: 1100px) { .main-content { margin-left:0; } .grid, .form-grid, .term-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<?php include '../navigation.php'; ?>
<?php include '../sidebar.php'; ?>
    <div class="main-content">
        <div class="card hero">
            <h1><i class="fas fa-calendar-days"></i> Academic Calendar</h1>
            <p>The admin controls the academic year, term dates, and the automatic term progression lifecycle from here.</p>
        </div>

        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if ($termSetupRequired): ?>
            <div class="alert alert-info">No active academic term is running right now. Add or update the next term dates below so the system can resume automatic progress tracking and user notifications.</div>
        <?php endif; ?>

        <div class="grid">
            <div class="card metric">
                <small>Academic Year</small>
                <strong><?php echo htmlspecialchars($currentTerm['academic_year_label'] ?? $activeYear ?: 'Not set'); ?></strong>
            </div>
            <div class="card metric">
                <small>Current Term</small>
                <strong><?php echo htmlspecialchars($currentTerm['term_name'] ?? 'Waiting for admin'); ?></strong>
                <div class="subtle">
                    <?php if ($currentTerm): ?>
                        <?php echo htmlspecialchars(date('d M Y', strtotime($currentTerm['start_date'])) . ' - ' . date('d M Y', strtotime($currentTerm['end_date']))); ?>
                    <?php else: ?>
                        The system is waiting for the admin to open the next term.
                    <?php endif; ?>
                </div>
            </div>
            <div class="card metric">
                <small>Progress</small>
                <strong><?php echo isset($currentTerm['progress_percent']) ? number_format((float) $currentTerm['progress_percent'], 1) . '%' : '0%'; ?></strong>
                <div class="progress-track"><div class="progress-bar" style="width: <?php echo isset($currentTerm['progress_percent']) ? min(100, (float) $currentTerm['progress_percent']) : 0; ?>%;"></div></div>
            </div>
            <div class="card metric">
                <small>Next Term / Waiting State</small>
                <strong><?php echo htmlspecialchars($nextTerm['term_name'] ?? 'Awaiting new dates'); ?></strong>
                <div class="subtle">
                    <?php if ($nextTerm): ?>
                        Starts <?php echo htmlspecialchars(date('d M Y', strtotime($nextTerm['start_date']))); ?>
                    <?php else: ?>
                        When progress hits 100%, the system closes active terms and waits here until the admin enters the next dates.
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($currentTerm): ?>
        <div class="card">
            <h2 style="margin-top:0;">Current Term Progress Report</h2>
            <p style="color:#64748b;">This progress report updates automatically from the saved term dates and closes the term once it reaches 100%.</p>
            <div class="grid">
                <div class="metric">
                    <small>Days Elapsed</small>
                    <strong><?php echo number_format((int) ($currentTerm['days_elapsed'] ?? 0)); ?></strong>
                </div>
                <div class="metric">
                    <small>Days Remaining</small>
                    <strong><?php echo number_format((int) ($currentTerm['days_remaining'] ?? 0)); ?></strong>
                </div>
                <div class="metric">
                    <small>Total Term Days</small>
                    <strong><?php echo number_format((int) ($currentTerm['days_total'] ?? 0)); ?></strong>
                </div>
                <div class="metric">
                    <small>User Notification State</small>
                    <strong><?php echo (($currentTerm['progress_percent'] ?? 0) >= 100) ? 'Closing' : 'Running'; ?></strong>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <h2 style="margin-top:0;">Set Academic Year and Term Dates</h2>
            <p style="color:#64748b;">Once a term reaches 100%, the system closes it automatically and waits for the next term dates from the admin.</p>
            <form method="POST">
                <div class="form-grid">
                    <div>
                        <label>Academic Year</label>
                        <input type="text" name="academic_year" value="<?php echo htmlspecialchars($activeYear); ?>" placeholder="2026/2027" required>
                    </div>
                </div>

                <?php
                $defaultTerms = [
                    ['name' => 'Term 1', 'start_date' => '', 'end_date' => ''],
                    ['name' => 'Term 2', 'start_date' => '', 'end_date' => ''],
                    ['name' => 'Term 3', 'start_date' => '', 'end_date' => ''],
                ];
                $currentYearTerms = array_values(array_filter($terms, fn($term) => ($term['academic_year_label'] ?? '') === $activeYear));
                if (!empty($currentYearTerms)) {
                    $defaultTerms = array_map(fn($term) => [
                        'name' => $term['term_name'],
                        'start_date' => $term['start_date'],
                        'end_date' => $term['end_date'],
                    ], $currentYearTerms);
                }
                foreach ($defaultTerms as $index => $termRow): ?>
                    <div class="term-grid">
                        <div>
                            <label>Term Name</label>
                            <input type="text" name="terms[<?php echo $index; ?>][name]" value="<?php echo htmlspecialchars($termRow['name']); ?>" required>
                        </div>
                        <div>
                            <label>Start Date</label>
                            <input type="date" name="terms[<?php echo $index; ?>][start_date]" value="<?php echo htmlspecialchars($termRow['start_date']); ?>" required>
                        </div>
                        <div>
                            <label>End Date</label>
                            <input type="date" name="terms[<?php echo $index; ?>][end_date]" value="<?php echo htmlspecialchars($termRow['end_date']); ?>" required>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div style="margin-top:1.25rem;">
                    <button class="btn btn-primary" type="submit" name="save_calendar"><i class="fas fa-save"></i> Save Academic Calendar</button>
                </div>
            </form>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Configured Terms</h2>
            <table>
                <thead>
                    <tr>
                        <th>Academic Year</th>
                        <th>Term</th>
                        <th>Dates</th>
                        <th>Status</th>
                        <th>Progress</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($terms)): ?>
                        <?php foreach ($terms as $term): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($term['academic_year_label']); ?></td>
                                <td><?php echo htmlspecialchars($term['term_name']); ?></td>
                                <td><?php echo htmlspecialchars(date('d M Y', strtotime($term['start_date'])) . ' - ' . date('d M Y', strtotime($term['end_date']))); ?></td>
                                <td><span class="badge badge-<?php echo htmlspecialchars($term['status']); ?>"><?php echo htmlspecialchars($term['status']); ?></span></td>
                                <td><?php echo number_format((float) ($term['progress_percent'] ?? 0), 1); ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align:center; color:#64748b;">No academic calendar has been configured yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
