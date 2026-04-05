<?php
require_once '../config.php';
checkAuth();
checkRole(['admin', 'librarian']);

function reportsTableColumns(PDO $pdo, string $table): array {
    try {
        return array_fill_keys(
            $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN),
            true
        );
    } catch (Exception $e) {
        return [];
    }
}

function reportsTableExists(PDO $pdo, string $table): bool {
    try {
        return (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function reportsStudentNameExpression(PDO $pdo): string {
    $columns = reportsTableColumns($pdo, 'students');
    foreach (['full_name', 'name', 'student_name'] as $column) {
        if (isset($columns[$column])) {
            return "s.`{$column}`";
        }
    }

    return "CONCAT('Student #', s.id)";
}

function reportsAdmissionExpression(PDO $pdo): string {
    $columns = reportsTableColumns($pdo, 'students');
    foreach (['Admission_number', 'admission_number', 'admission_no'] as $column) {
        if (isset($columns[$column])) {
            return "s.`{$column}`";
        }
    }

    return "CAST(s.id AS CHAR)";
}

function reportsClassNameExpression(PDO $pdo): string {
    $columns = reportsTableColumns($pdo, 'classes');
    foreach (['class_name', 'name', 'class'] as $column) {
        if (isset($columns[$column])) {
            return "c.`{$column}`";
        }
    }

    return "'Unassigned'";
}

function reportsBookCategoryQuery(PDO $pdo): string {
    $bookColumns = array_fill_keys($pdo->query("SHOW COLUMNS FROM books")->fetchAll(PDO::FETCH_COLUMN), true);
    $hasCategoriesTable = (bool) $pdo->query("SHOW TABLES LIKE 'book_categories'")->fetchColumn();

    if (isset($bookColumns['category'])) {
        return "
            SELECT title, author, isbn, category, location,
                   total_copies, available_copies,
                   CASE WHEN available_copies > 0 THEN 'Available' ELSE 'Unavailable' END as status
            FROM books
            ORDER BY title
        ";
    }

    if (isset($bookColumns['category_id']) && $hasCategoriesTable) {
        $categoryColumns = array_fill_keys($pdo->query("SHOW COLUMNS FROM book_categories")->fetchAll(PDO::FETCH_COLUMN), true);
        $labelColumn = isset($categoryColumns['category_name']) ? 'category_name' : (isset($categoryColumns['name']) ? 'name' : null);
        if ($labelColumn) {
            return "
                SELECT b.title, b.author, b.isbn, COALESCE(bc.{$labelColumn}, CONCAT('Category #', b.category_id)) as category, b.location,
                       b.total_copies, b.available_copies,
                       CASE WHEN b.available_copies > 0 THEN 'Available' ELSE 'Unavailable' END as status
                FROM books b
                LEFT JOIN book_categories bc ON b.category_id = bc.id
                ORDER BY b.title
            ";
        }
    }

    return "
        SELECT title, author, isbn, 'Uncategorized' as category, location,
               total_copies, available_copies,
               CASE WHEN available_copies > 0 THEN 'Available' ELSE 'Unavailable' END as status
        FROM books
        ORDER BY title
    ";
}

function reportsFetchValue(PDO $pdo, string $sql, $default = 0) {
    try {
        $value = $pdo->query($sql)->fetchColumn();
        return $value !== false && $value !== null ? $value : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function reportsFetchAll(PDO $pdo, string $sql): array {
    try {
        return $pdo->query($sql)->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// Handle report generation
if (isset($_GET['generate'])) {
    $report_type = $_GET['type'] ?? 'circulation';
    $date_from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $date_to = $_GET['to'] ?? date('Y-m-d');
    $format = $_GET['format'] ?? 'html';
    
    if ($format === 'csv') {
        generateCSVReport($pdo, $report_type, $date_from, $date_to);
        exit();
    }
}

function generateCSVReport($pdo, $type, $from, $to) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="library_report_' . date('Ymd') . '.csv"');
    
    $output = fopen('php://output', 'w');
    $studentNameExpr = reportsStudentNameExpression($pdo);
    $admissionExpr = reportsAdmissionExpression($pdo);
    $classNameExpr = reportsClassNameExpression($pdo);
    
    if ($type === 'circulation') {
        fputcsv($output, ['Date', 'Book Title', 'Student', 'Admission No.', 'Issue Date', 'Due Date', 'Return Date', 'Status']);
        
        $stmt = $pdo->prepare("
            SELECT bi.issue_date, b.title,
                   {$studentNameExpr} as student_name,
                   {$admissionExpr} as admission_number,
                   bi.issue_date, bi.due_date, bi.return_date,
                   CASE 
                       WHEN bi.return_date IS NOT NULL THEN 'Returned'
                       WHEN bi.due_date < CURDATE() THEN 'Overdue'
                       ELSE 'Issued'
                   END as status
            FROM book_issues bi
            JOIN books b ON bi.book_id = b.id
            JOIN students s ON bi.student_id = s.id
            WHERE bi.issue_date BETWEEN ? AND ?
            ORDER BY bi.issue_date DESC
        ");
        $stmt->execute([$from, $to]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['issue_date'] ?? '',
                $row['title'] ?? '',
                $row['student_name'] ?? '',
                $row['admission_number'] ?? '',
                $row['issue_date'] ?? '',
                $row['due_date'] ?? '',
                $row['return_date'] ?? '',
                $row['status'] ?? '',
            ]);
        }
    } elseif ($type === 'books') {
        fputcsv($output, ['Title', 'Author', 'ISBN', 'Category', 'Location', 'Total Copies', 'Available', 'Status']);
        
        $stmt = $pdo->query(reportsBookCategoryQuery($pdo));
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($type === 'overdue') {
        fputcsv($output, ['Book Title', 'Student', 'Admission No.', 'Class', 'Issue Date', 'Due Date', 'Days Overdue']);
        
        $stmt = $pdo->query("
            SELECT b.title,
                   {$studentNameExpr} as student_name,
                   {$admissionExpr} as admission_number,
                   {$classNameExpr} as class_name,
                   bi.issue_date, bi.due_date,
                   DATEDIFF(CURDATE(), bi.due_date) as days_overdue
            FROM book_issues bi
            JOIN books b ON bi.book_id = b.id
            JOIN students s ON bi.student_id = s.id
            LEFT JOIN classes c ON s.class_id = c.id
            WHERE bi.return_date IS NULL AND bi.due_date < CURDATE()
            ORDER BY days_overdue DESC
        ");
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['title'] ?? '',
                $row['student_name'] ?? '',
                $row['admission_number'] ?? '',
                $row['class_name'] ?? '',
                $row['issue_date'] ?? '',
                $row['due_date'] ?? '',
                $row['days_overdue'] ?? '',
            ]);
        }
    }
    
    fclose($output);
}

// Get statistics for reports
$studentNameExpr = reportsStudentNameExpression($pdo);
$admissionExpr = reportsAdmissionExpression($pdo);
$classNameExpr = reportsClassNameExpression($pdo);

$total_issues = reportsFetchValue($pdo, "SELECT COUNT(*) FROM book_issues", 0);
$active_issues = reportsFetchValue($pdo, "SELECT COUNT(*) FROM book_issues WHERE return_date IS NULL", 0);
$total_returns = reportsFetchValue($pdo, "SELECT COUNT(*) FROM book_issues WHERE return_date IS NOT NULL", 0);
$overdue_count = reportsFetchValue($pdo, "SELECT COUNT(*) FROM book_issues WHERE return_date IS NULL AND due_date < CURDATE()", 0);

// Monthly stats
$monthly_stats = reportsFetchAll($pdo, "
    SELECT 
        DATE_FORMAT(issue_date, '%Y-%m') as month,
        COUNT(*) as issues,
        SUM(CASE WHEN return_date IS NOT NULL THEN 1 ELSE 0 END) as returns,
        COUNT(DISTINCT student_id) as unique_students
    FROM book_issues
    WHERE issue_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(issue_date, '%Y-%m')
    ORDER BY month DESC
");

// Top books
$top_books = reportsFetchAll($pdo, "
    SELECT b.title, b.author, COUNT(bi.id) as issue_count
    FROM book_issues bi
    JOIN books b ON bi.book_id = b.id
    GROUP BY bi.book_id
    ORDER BY issue_count DESC
    LIMIT 10
");

// Top readers
$top_readers = reportsFetchAll($pdo, "
    SELECT {$studentNameExpr} as full_name,
           {$admissionExpr} as Admission_number,
           {$classNameExpr} as class_name,
           COUNT(bi.id) as book_count
    FROM book_issues bi
    JOIN students s ON bi.student_id = s.id
    LEFT JOIN classes c ON s.class_id = c.id
    GROUP BY bi.student_id
    ORDER BY book_count DESC
    LIMIT 10
");

$page_title = "Library Reports";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="assets/css/librarian.css">
    <style>
        .page-header {
            margin-bottom: 1.5rem;
            padding: 1.5rem 1.75rem;
            border-radius: 18px;
            background: linear-gradient(135deg, #ffffff 0%, #eef4ff 100%);
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
            border: 1px solid rgba(102, 126, 234, 0.12);
        }

        .page-header h1 {
            margin: 0;
            color: #1f2937;
            font-size: 1.9rem;
        }

        .page-header p {
            margin: 0.45rem 0 0;
            color: #64748b;
        }

        .report-generator,
        .chart-container,
        .top-list-card {
            border: 1px solid rgba(148, 163, 184, 0.16);
            box-shadow: 0 16px 32px rgba(15, 23, 42, 0.07);
        }

        .report-form .form-group label {
            display: block;
            font-weight: 600;
            color: #334155;
            margin-bottom: 0.45rem;
        }

        .report-form select,
        .report-form input[type="date"] {
            width: 100%;
            padding: 0.85rem 0.95rem;
            border-radius: 12px;
            border: 1px solid #dbe4f0;
            background: #fff;
            color: #1f2937;
            outline: none;
        }

        .report-form select:focus,
        .report-form input[type="date"]:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.12);
        }

        .empty-state {
            padding: 1.25rem;
            border-radius: 14px;
            background: #f8fafc;
            color: #64748b;
            text-align: center;
            border: 1px dashed #cbd5e1;
        }

        @media (max-width: 768px) {
            .page-header {
                padding: 1.1rem 1rem;
                border-radius: 14px;
            }

            .page-header h1 {
                font-size: 1.45rem;
            }
        }
    </style>
</head>
<body>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h1>Library Reports</h1>
            <p>Track circulation, overdue books, top readers, and downloadable library summaries.</p>
        </div>

        <!-- Summary Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(52, 152, 219, 0.1); color: #3498db;">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="stat-details">
                    <div class="stat-value"><?php echo $total_issues; ?></div>
                    <div class="stat-label">Total Transactions</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(46, 204, 113, 0.1); color: #27ae60;">
                    <i class="fas fa-book-open"></i>
                </div>
                <div class="stat-details">
                    <div class="stat-value"><?php echo $active_issues; ?></div>
                    <div class="stat-label">Active Issues</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(155, 89, 182, 0.1); color: #9b59b6;">
                    <i class="fas fa-undo-alt"></i>
                </div>
                <div class="stat-details">
                    <div class="stat-value"><?php echo $total_returns; ?></div>
                    <div class="stat-label">Total Returns</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(231, 76, 60, 0.1); color: #e74c3c;">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-details">
                    <div class="stat-value"><?php echo $overdue_count; ?></div>
                    <div class="stat-label">Overdue Books</div>
                </div>
            </div>
        </div>

        <!-- Report Generator -->
        <div class="report-generator">
            <h3>Generate Report</h3>
            <form method="GET" class="report-form" id="reportForm">
                <input type="hidden" name="generate" value="1">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Report Type</label>
                        <select name="type" id="reportType">
                            <option value="circulation">Circulation Report</option>
                            <option value="books">Books Inventory</option>
                            <option value="overdue">Overdue Books</option>
                            <option value="popular">Popular Books</option>
                            <option value="readers">Top Readers</option>
                        </select>
                    </div>
                    
                    <div class="form-group date-range" id="dateRange">
                        <div>
                            <label>From Date</label>
                            <input type="date" name="from" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>">
                        </div>
                        <div>
                            <label>To Date</label>
                            <input type="date" name="to" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Format</label>
                        <select name="format">
                            <option value="html">HTML (Preview)</option>
                            <option value="csv">CSV (Download)</option>
                        </select>
                    </div>
                    
                    <div class="form-group report-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-file-alt"></i> Generate
                        </button>
                        <button type="button" class="btn btn-success" onclick="printReport()">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Monthly Statistics Chart -->
        <div class="chart-container">
            <h3>Monthly Statistics</h3>
            <canvas id="monthlyChart"></canvas>
        </div>

        <!-- Top Books and Readers -->
        <div class="dashboard-row">
            <!-- Top Books -->
            <div class="top-list-card">
                <div class="card-header">
                    <h3><i class="fas fa-star" style="color: #f1c40f;"></i> Most Popular Books</h3>
                </div>
                <div class="list-container">
                    <?php if (!empty($top_books)): ?>
                        <?php foreach ($top_books as $index => $book): ?>
                        <div class="list-item">
                            <div class="rank">#<?php echo $index + 1; ?></div>
                            <div class="item-content">
                                <strong><?php echo htmlspecialchars($book['title']); ?></strong>
                                <br><small><?php echo htmlspecialchars($book['author']); ?></small>
                            </div>
                            <div class="item-stat">
                                <span class="badge badge-primary"><?php echo $book['issue_count']; ?> issues</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">No borrowing activity has been recorded yet.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top Readers -->
            <div class="top-list-card">
                <div class="card-header">
                    <h3><i class="fas fa-user-graduate" style="color: #3498db;"></i> Top Readers</h3>
                </div>
                <div class="list-container">
                    <?php if (!empty($top_readers)): ?>
                        <?php foreach ($top_readers as $index => $reader): ?>
                        <div class="list-item">
                            <div class="rank">#<?php echo $index + 1; ?></div>
                            <div class="item-content">
                                <strong><?php echo htmlspecialchars($reader['full_name']); ?></strong>
                                <br><small><?php echo htmlspecialchars($reader['Admission_number']); ?> | <?php echo htmlspecialchars($reader['class_name']); ?></small>
                            </div>
                            <div class="item-stat">
                                <span class="badge badge-success"><?php echo $reader['book_count']; ?> books</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">No reader activity is available for the selected data yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Monthly Chart
        const ctx = document.getElementById('monthlyChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column(array_reverse($monthly_stats), 'month')); ?>,
                datasets: [{
                    label: 'Issues',
                    data: <?php echo json_encode(array_column(array_reverse($monthly_stats), 'issues')); ?>,
                    backgroundColor: '#3498db'
                }, {
                    label: 'Returns',
                    data: <?php echo json_encode(array_column(array_reverse($monthly_stats), 'returns')); ?>,
                    backgroundColor: '#27ae60'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Show/hide date range based on report type
        document.getElementById('reportType').addEventListener('change', function() {
            const dateRange = document.getElementById('dateRange');
            const types = ['circulation', 'overdue'];
            dateRange.style.display = types.includes(this.value) ? 'flex' : 'none';
        });

        function printReport() {
            const form = document.getElementById('reportForm');
            const formData = new FormData(form);
            formData.set('format', 'html');
            
            // Open in new window for printing
            const params = new URLSearchParams(formData).toString();
            window.open('reports.php?' + params, '_blank');
        }
    </script>
</body>
</html>
