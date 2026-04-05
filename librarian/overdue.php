<?php
require_once '../config.php';
require_once '../library_fines_workflow_helpers.php';
checkAuth();
checkRole(['admin', 'librarian']);

ensureLibraryFineWorkflowSchema($pdo);

function overdueTableColumns(PDO $pdo, string $table): array
{
    try {
        return array_fill_keys(
            $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN),
            true
        );
    } catch (Exception $e) {
        return [];
    }
}

function overdueStudentNameExpression(PDO $pdo, string $alias = 's'): string
{
    $columns = overdueTableColumns($pdo, 'students');
    foreach (['full_name', 'name', 'student_name'] as $column) {
        if (isset($columns[$column])) {
            return "{$alias}.`{$column}`";
        }
    }

    return "CONCAT('Student #', {$alias}.id)";
}

function overdueAdmissionExpression(PDO $pdo, string $alias = 's'): string
{
    $columns = overdueTableColumns($pdo, 'students');
    foreach (['Admission_number', 'admission_number', 'admission_no'] as $column) {
        if (isset($columns[$column])) {
            return "{$alias}.`{$column}`";
        }
    }

    return "CAST({$alias}.id AS CHAR)";
}

function overdueClassExpression(PDO $pdo, string $alias = 'c'): string
{
    $columns = overdueTableColumns($pdo, 'classes');
    foreach (['class_name', 'name', 'class'] as $column) {
        if (isset($columns[$column])) {
            return "{$alias}.`{$column}`";
        }
    }

    return "'Unassigned'";
}

function overdueStatusTone(int $days): string
{
    if ($days >= 30) {
        return 'critical';
    }
    if ($days >= 14) {
        return 'high';
    }
    return 'moderate';
}

$studentNameExpr = overdueStudentNameExpression($pdo);
$admissionExpr = overdueAdmissionExpression($pdo);
$classExpr = overdueClassExpression($pdo);
$defaultFinePerDay = 20;

$search = trim($_GET['search'] ?? '');
$classFilter = (int) ($_GET['class_id'] ?? 0);
$severityFilter = trim($_GET['severity'] ?? '');

$classes = $pdo->query("
    SELECT id, {$classExpr} AS class_name
    FROM classes c
    ORDER BY class_name
")->fetchAll(PDO::FETCH_ASSOC);

$query = "
    SELECT
        bi.id,
        bi.book_id,
        bi.student_id,
        bi.issue_date,
        bi.due_date,
        DATEDIFF(CURDATE(), bi.due_date) AS days_overdue,
        b.title,
        b.author,
        b.isbn,
        {$studentNameExpr} AS student_name,
        {$admissionExpr} AS admission_number,
        {$classExpr} AS class_name,
        (
            SELECT bf.status
            FROM book_fines bf
            WHERE bf.issue_id = bi.id
            ORDER BY bf.created_at DESC, bf.id DESC
            LIMIT 1
        ) AS latest_fine_status,
        (
            SELECT bf.id
            FROM book_fines bf
            WHERE bf.issue_id = bi.id
            ORDER BY bf.created_at DESC, bf.id DESC
            LIMIT 1
        ) AS latest_fine_id,
        (
            SELECT lb.status
            FROM lost_books lb
            WHERE lb.issue_id = bi.id
              AND lb.status IN ('reported', 'pending', 'submitted_for_approval', 'approved', 'verified', 'sent_to_accountant', 'invoiced', 'paid')
            ORDER BY lb.created_at DESC, lb.id DESC
            LIMIT 1
        ) AS lost_workflow_status,
        (
            SELECT lb.id
            FROM lost_books lb
            WHERE lb.issue_id = bi.id
              AND lb.status IN ('reported', 'pending', 'submitted_for_approval', 'approved', 'verified', 'sent_to_accountant', 'invoiced', 'paid')
            ORDER BY lb.created_at DESC, lb.id DESC
            LIMIT 1
        ) AS lost_workflow_id
    FROM book_issues bi
    JOIN books b ON bi.book_id = b.id
    JOIN students s ON bi.student_id = s.id
    LEFT JOIN classes c ON s.class_id = c.id
    WHERE bi.return_date IS NULL
      AND bi.due_date < CURDATE()
";

$params = [];

if ($search !== '') {
    $query .= " AND (b.title LIKE ? OR b.author LIKE ? OR {$studentNameExpr} LIKE ? OR {$admissionExpr} LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($classFilter > 0) {
    $query .= " AND s.class_id = ?";
    $params[] = $classFilter;
}

$query .= " ORDER BY days_overdue DESC, bi.due_date ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$overdueItems = [];
foreach ($rows as $row) {
    $days = max(0, (int) ($row['days_overdue'] ?? 0));
    $severity = overdueStatusTone($days);
    if ($severityFilter !== '' && $severity !== $severityFilter) {
        continue;
    }

    $row['days_overdue'] = $days;
    $row['severity'] = $severity;
    $row['estimated_fine'] = $days * $defaultFinePerDay;
    $overdueItems[] = $row;
}

$stats = [
    'total' => count($overdueItems),
    'moderate' => 0,
    'high' => 0,
    'critical' => 0,
    'estimated_total' => 0,
    'with_fines' => 0,
    'lost_workflow' => 0,
];

foreach ($overdueItems as $item) {
    $stats[$item['severity']]++;
    $stats['estimated_total'] += (float) $item['estimated_fine'];
    if (!empty($item['latest_fine_id'])) {
        $stats['with_fines']++;
    }
    if (!empty($item['lost_workflow_id'])) {
        $stats['lost_workflow']++;
    }
}

$page_title = 'Overdue Books - ' . SCHOOL_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=Source+Sans+3:wght@400;600;700&display=swap');

        :root {
            --bg: #f4efe6;
            --panel: #fffdf8;
            --panel-alt: #f8f2e8;
            --ink: #1f2933;
            --muted: #64748b;
            --line: rgba(31, 41, 51, 0.08);
            --primary: #b45309;
            --primary-deep: #7c2d12;
            --accent: #0f766e;
            --danger: #b91c1c;
            --warning: #d97706;
            --success: #15803d;
            --shadow: 0 18px 40px rgba(71, 55, 24, 0.12);
            --radius-lg: 24px;
            --radius-md: 16px;
            --radius-sm: 12px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Source Sans 3', sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top right, rgba(180, 83, 9, 0.18), transparent 28%),
                radial-gradient(circle at bottom left, rgba(15, 118, 110, 0.14), transparent 24%),
                var(--bg);
        }

        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            min-height: calc(100vh - 70px);
            padding: 2rem;
        }

        .hero {
            display: grid;
            grid-template-columns: 1.6fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .hero-card,
        .summary-card,
        .filters,
        .table-card {
            background: var(--panel);
            border: 1px solid rgba(255,255,255,0.5);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
        }

        .hero-card {
            padding: 2rem;
            position: relative;
            overflow: hidden;
            background:
                linear-gradient(135deg, rgba(180, 83, 9, 0.92), rgba(124, 45, 18, 0.96)),
                var(--panel);
            color: #fff8ef;
        }

        .hero-card::after {
            content: '';
            position: absolute;
            right: -40px;
            top: -60px;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
        }

        .eyebrow {
            letter-spacing: 0.18em;
            text-transform: uppercase;
            font-size: 0.74rem;
            opacity: 0.8;
            margin-bottom: 0.8rem;
        }

        h1, h2, h3 {
            font-family: 'Space Grotesk', sans-serif;
            margin: 0;
        }

        .hero-card h1 {
            font-size: clamp(2rem, 4vw, 3rem);
            line-height: 1.02;
            max-width: 14ch;
            margin-bottom: 1rem;
        }

        .hero-card p {
            max-width: 58ch;
            margin: 0;
            color: rgba(255, 248, 239, 0.88);
            font-size: 1rem;
        }

        .hero-actions {
            margin-top: 1.4rem;
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            border-radius: 999px;
            padding: 0.85rem 1.2rem;
            text-decoration: none;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .btn-light {
            background: rgba(255,255,255,0.14);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.18);
        }

        .btn-dark {
            background: #fff8ef;
            color: var(--primary-deep);
        }

        .summary-card {
            padding: 1.5rem;
            display: grid;
            gap: 1rem;
            align-content: start;
            background:
                linear-gradient(180deg, rgba(255,255,255,0.94), rgba(248,242,232,0.98));
        }

        .summary-label {
            color: var(--muted);
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
        }

        .summary-amount {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 2.1rem;
            color: var(--primary-deep);
        }

        .mini-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.9rem;
        }

        .mini-stat {
            background: var(--panel-alt);
            border-radius: var(--radius-md);
            padding: 0.95rem 1rem;
            border: 1px solid var(--line);
        }

        .mini-stat strong {
            display: block;
            font-size: 1.25rem;
            font-family: 'Space Grotesk', sans-serif;
        }

        .mini-stat span {
            color: var(--muted);
            font-size: 0.88rem;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--panel);
            border-radius: var(--radius-md);
            padding: 1.15rem 1.2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--line);
        }

        .stat-title {
            color: var(--muted);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        .stat-value {
            margin-top: 0.45rem;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.7rem;
        }

        .filters {
            padding: 1.2rem;
            margin-bottom: 1.5rem;
        }

        .filters form {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto;
            gap: 0.9rem;
            align-items: end;
        }

        .field label {
            display: block;
            margin-bottom: 0.45rem;
            color: var(--muted);
            font-weight: 700;
            font-size: 0.88rem;
        }

        .field input,
        .field select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #fff;
            padding: 0.9rem 1rem;
            font: inherit;
            color: var(--ink);
        }

        .filters-actions {
            display: flex;
            gap: 0.65rem;
            flex-wrap: wrap;
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid rgba(31, 41, 51, 0.12);
            color: var(--ink);
        }

        .table-card {
            overflow: hidden;
        }

        .table-header {
            padding: 1.2rem 1.3rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            background: linear-gradient(180deg, rgba(248,242,232,0.85), rgba(255,253,248,1));
            border-bottom: 1px solid var(--line);
        }

        .table-header p {
            margin: 0.35rem 0 0;
            color: var(--muted);
        }

        .table-scroll {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 1rem 1.1rem;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
        }

        th {
            font-size: 0.77rem;
            letter-spacing: 0.09em;
            text-transform: uppercase;
            color: var(--muted);
            background: rgba(248,242,232,0.55);
        }

        tr:hover {
            background: rgba(180, 83, 9, 0.04);
        }

        .book-title {
            font-weight: 700;
        }

        .subtext {
            color: var(--muted);
            font-size: 0.9rem;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border-radius: 999px;
            padding: 0.38rem 0.7rem;
            font-size: 0.8rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .pill.moderate {
            background: rgba(217, 119, 6, 0.14);
            color: var(--warning);
        }

        .pill.high {
            background: rgba(185, 28, 28, 0.12);
            color: var(--danger);
        }

        .pill.critical {
            background: rgba(127, 29, 29, 0.14);
            color: #7f1d1d;
        }

        .pill.workflow {
            background: rgba(15, 118, 110, 0.14);
            color: var(--accent);
        }

        .pill.fine {
            background: rgba(21, 128, 61, 0.12);
            color: var(--success);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.55rem;
        }

        .link-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            border-radius: 999px;
            padding: 0.55rem 0.8rem;
            font-size: 0.82rem;
            text-decoration: none;
            font-weight: 700;
        }

        .link-btn.primary {
            background: rgba(180, 83, 9, 0.1);
            color: var(--primary-deep);
        }

        .link-btn.secondary {
            background: rgba(15, 118, 110, 0.1);
            color: var(--accent);
        }

        .link-btn.ghost {
            background: rgba(31, 41, 51, 0.06);
            color: var(--ink);
        }

        .empty-state {
            padding: 3rem 1.5rem;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 0.8rem;
        }

        @media (max-width: 1200px) {
            .hero,
            .stats-row {
                grid-template-columns: 1fr;
            }

            .filters form {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 900px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
        }

        @media (max-width: 700px) {
            .filters form,
            .mini-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        <section class="hero">
            <div class="hero-card">
                <div class="eyebrow">Library Recovery Desk</div>
                <h1>Track overdue books before they become lost-book cases.</h1>
                <p>This page gives the librarian a focused view of overdue circulation risk, fine readiness, and issues already moved into the lost-book workflow.</p>
                <div class="hero-actions">
                    <a class="btn btn-dark" href="circulations.php?status=overdue">
                        <i class="fas fa-exchange-alt"></i> Open Circulations
                    </a>
                    <a class="btn btn-light" href="fines.php">
                        <i class="fas fa-coins"></i> Manage Fines
                    </a>
                </div>
            </div>

            <div class="summary-card">
                <div>
                    <div class="summary-label">Estimated Overdue Exposure</div>
                    <div class="summary-amount">KES <?php echo number_format($stats['estimated_total'], 2); ?></div>
                </div>
                <div class="mini-grid">
                    <div class="mini-stat">
                        <strong><?php echo $stats['with_fines']; ?></strong>
                        <span>Issues with fine records</span>
                    </div>
                    <div class="mini-stat">
                        <strong><?php echo $stats['lost_workflow']; ?></strong>
                        <span>Already in lost workflow</span>
                    </div>
                    <div class="mini-stat">
                        <strong><?php echo $stats['high']; ?></strong>
                        <span>High risk overdue</span>
                    </div>
                    <div class="mini-stat">
                        <strong><?php echo $stats['critical']; ?></strong>
                        <span>Critical recovery cases</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="stats-row">
            <div class="stat-card">
                <div class="stat-title">Total Overdue</div>
                <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Moderate</div>
                <div class="stat-value"><?php echo number_format($stats['moderate']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-title">High</div>
                <div class="stat-value"><?php echo number_format($stats['high']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Critical</div>
                <div class="stat-value"><?php echo number_format($stats['critical']); ?></div>
            </div>
        </section>

        <section class="filters">
            <form method="GET">
                <div class="field">
                    <label>Search student or book</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Title, author, student, admission number...">
                </div>
                <div class="field">
                    <label>Class</label>
                    <select name="class_id">
                        <option value="0">All classes</option>
                        <?php foreach ($classes as $class): ?>
                        <option value="<?php echo (int) $class['id']; ?>" <?php echo $classFilter === (int) $class['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($class['class_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Severity</label>
                    <select name="severity">
                        <option value="">All levels</option>
                        <option value="moderate" <?php echo $severityFilter === 'moderate' ? 'selected' : ''; ?>>Moderate</option>
                        <option value="high" <?php echo $severityFilter === 'high' ? 'selected' : ''; ?>>High</option>
                        <option value="critical" <?php echo $severityFilter === 'critical' ? 'selected' : ''; ?>>Critical</option>
                    </select>
                </div>
                <div class="filters-actions">
                    <button class="btn btn-primary" type="submit">
                        <i class="fas fa-filter"></i> Apply
                    </button>
                    <a class="btn btn-outline" href="overdue.php">
                        <i class="fas fa-rotate-left"></i> Reset
                    </a>
                </div>
            </form>
        </section>

        <section class="table-card">
            <div class="table-header">
                <div>
                    <h2>Overdue Register</h2>
                    <p><?php echo number_format($stats['total']); ?> overdue issue<?php echo $stats['total'] === 1 ? '' : 's'; ?> currently match this view.</p>
                </div>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Book</th>
                            <th>Student</th>
                            <th>Due Date</th>
                            <th>Exposure</th>
                            <th>Workflow</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($overdueItems)): ?>
                            <?php foreach ($overdueItems as $item): ?>
                            <tr>
                                <td>
                                    <div class="book-title"><?php echo htmlspecialchars($item['title']); ?></div>
                                    <div class="subtext"><?php echo htmlspecialchars($item['author']); ?></div>
                                    <?php if (!empty($item['isbn'])): ?>
                                    <div class="subtext">ISBN: <?php echo htmlspecialchars($item['isbn']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="book-title"><?php echo htmlspecialchars($item['student_name']); ?></div>
                                    <div class="subtext"><?php echo htmlspecialchars($item['admission_number']); ?></div>
                                    <div class="subtext"><?php echo htmlspecialchars($item['class_name']); ?></div>
                                </td>
                                <td>
                                    <div class="book-title"><?php echo date('d M Y', strtotime($item['due_date'])); ?></div>
                                    <div class="subtext">Issued <?php echo date('d M Y', strtotime($item['issue_date'])); ?></div>
                                </td>
                                <td>
                                    <span class="pill <?php echo htmlspecialchars($item['severity']); ?>">
                                        <i class="fas fa-bolt"></i>
                                        <?php echo ucfirst($item['severity']); ?>: <?php echo (int) $item['days_overdue']; ?> days
                                    </span>
                                    <div class="subtext" style="margin-top: 0.5rem;">Est. fine: KES <?php echo number_format($item['estimated_fine'], 2); ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($item['lost_workflow_id'])): ?>
                                    <span class="pill workflow">
                                        <i class="fas fa-triangle-exclamation"></i>
                                        Lost: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $item['lost_workflow_status']))); ?>
                                    </span>
                                    <?php endif; ?>

                                    <?php if (!empty($item['latest_fine_id'])): ?>
                                    <div style="margin-top: 0.45rem;">
                                        <span class="pill fine">
                                            <i class="fas fa-receipt"></i>
                                            Fine: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $item['latest_fine_status']))); ?>
                                        </span>
                                    </div>
                                    <?php elseif (empty($item['lost_workflow_id'])): ?>
                                    <div class="subtext">No fine record yet</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="actions">
                                        <a class="link-btn primary" href="circulations.php?status=overdue&search=<?php echo urlencode((string) $item['admission_number']); ?>">
                                            <i class="fas fa-book-open"></i> Circulation
                                        </a>
                                        <a class="link-btn secondary" href="fines.php">
                                            <i class="fas fa-coins"></i> Fines
                                        </a>
                                        <?php if (!empty($item['lost_workflow_id'])): ?>
                                        <span class="link-btn ghost">
                                            <i class="fas fa-lock"></i> Lost workflow locked
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <i class="fas fa-check-circle"></i>
                                        <h3>No overdue books in this view</h3>
                                        <p>Try widening your filters or check the circulation page for newly issued items.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</body>
</html>
