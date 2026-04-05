<?php
include '../config.php';
checkAuth();
checkRole(['admin', 'teacher', 'accountant']);

// Handle all report exports
if (isset($_GET['export'])) {
    handleReportExport($pdo);
    exit();
}

// Get filter parameters with defaults
$filters = [
    'report_type' => $_GET['report_type'] ?? 'attendance',
    'class_id' => $_GET['class_id'] ?? '',
    'student_id' => $_GET['student_id'] ?? '',
    'term' => $_GET['term'] ?? date('Y') . ' Term 1',
    'start_date' => $_GET['start_date'] ?? date('Y-m-01'),
    'end_date' => $_GET['end_date'] ?? date('Y-m-t')
];

// Load required data for filters
$classes = $pdo->query("SELECT * FROM classes ORDER BY class_name")->fetchAll();
$students = $pdo->query("SELECT id, full_name, admission_number FROM students WHERE status = 'active' ORDER BY full_name")->fetchAll();
$terms = getAcademicTerms();

// Generate report data based on type
$reportGenerator = new ReportGenerator($pdo, $filters);
$reportData = $reportGenerator->generate();
$reportMeta = $reportGenerator->getMetadata();

$page_title = getPageTitle($filters['report_type']) . " - " . SCHOOL_NAME;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="../assets/css/reports.css">
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="main-content">
        <!-- Header Section -->
        <div class="page-header">
            <div class="header-content">
                <h1><i class="fas fa-chart-pie"></i> Reports & Analytics</h1>
                <p>Generate comprehensive reports and analyze school performance</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-outline" onclick="window.location.reload()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>

        <!-- Quick Stats Dashboard -->
        <?php if (empty($filters['report_type'])): ?>
        <div class="quick-stats">
            <div class="stat-card" onclick="setReportType('attendance')">
                <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                <div class="stat-details">
                    <h3>Attendance</h3>
                    <p>Track student attendance patterns</p>
                </div>
            </div>
            <div class="stat-card" onclick="setReportType('academic')">
                <div class="stat-icon"><i class="fas fa-graduation-cap"></i></div>
                <div class="stat-details">
                    <h3>Academic</h3>
                    <p>Monitor student performance</p>
                </div>
            </div>
            <div class="stat-card" onclick="setReportType('financial')">
                <div class="stat-icon"><i class="fas fa-coins"></i></div>
                <div class="stat-details">
                    <h3>Financial</h3>
                    <p>Track fees and transactions</p>
                </div>
            </div>
            <div class="stat-card" onclick="setReportType('inventory')">
                <div class="stat-icon"><i class="fas fa-boxes"></i></div>
                <div class="stat-details">
                    <h3>Inventory</h3>
                    <p>Manage library resources</p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Advanced Filters Panel -->
        <div class="filters-panel">
            <div class="panel-header" onclick="toggleFilters()">
                <h3><i class="fas fa-sliders-h"></i> Report Filters</h3>
                <i class="fas fa-chevron-down toggle-icon"></i>
            </div>
            <div class="panel-body" id="filterPanel">
                <form method="GET" id="reportForm" class="filters-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-chart-bar"></i> Report Type</label>
                            <select name="report_type" id="report_type" onchange="updateFilters()">
                                <option value="attendance" <?php echo selected('attendance', $filters['report_type']); ?>>📊 Attendance Report</option>
                                <option value="academic" <?php echo selected('academic', $filters['report_type']); ?>>📚 Academic Performance</option>
                                <option value="financial" <?php echo selected('financial', $filters['report_type']); ?>>💰 Financial Report</option>
                                <option value="student" <?php echo selected('student', $filters['report_type']); ?>>👤 Student Progress</option>
                                <option value="inventory" <?php echo selected('inventory', $filters['report_type']); ?>>📖 Library Inventory</option>
                                <option value="summary" <?php echo selected('summary', $filters['report_type']); ?>>📈 Summary Dashboard</option>
                            </select>
                        </div>

                        <div class="form-group filter-item" data-types="attendance,academic,student">
                            <label><i class="fas fa-users"></i> Class</label>
                            <select name="class_id" id="class_id">
                                <option value="">All Classes</option>
                                <?php foreach($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo selected($class['id'], $filters['class_id']); ?>>
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group filter-item" data-types="student">
                            <label><i class="fas fa-user"></i> Student</label>
                            <select name="student_id" id="student_id" class="select-search">
                                <option value="">Select Student</option>
                                <?php foreach($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>" <?php echo selected($student['id'], $filters['student_id']); ?>>
                                    <?php echo htmlspecialchars($student['full_name'] . ' (' . $student['admission_number'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group filter-item" data-types="academic,student">
                            <label><i class="fas fa-calendar-alt"></i> Term</label>
                            <select name="term" id="term">
                                <?php foreach($terms as $term): ?>
                                <option value="<?php echo $term; ?>" <?php echo selected($term, $filters['term']); ?>>
                                    <?php echo $term; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group filter-item" data-types="attendance,financial">
                            <label><i class="fas fa-calendar-start"></i> Start Date</label>
                            <input type="date" name="start_date" value="<?php echo $filters['start_date']; ?>">
                        </div>

                        <div class="form-group filter-item" data-types="attendance,financial">
                            <label><i class="fas fa-calendar-end"></i> End Date</label>
                            <input type="date" name="end_date" value="<?php echo $filters['end_date']; ?>">
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-chart-bar"></i> Generate Report
                            </button>
                            <button type="button" class="btn btn-success" onclick="exportReport('csv')">
                                <i class="fas fa-file-csv"></i> CSV
                            </button>
                            <button type="button" class="btn btn-danger" onclick="exportReport('pdf')">
                                <i class="fas fa-file-pdf"></i> PDF
                            </button>
                            <button type="button" class="btn btn-info" onclick="printReport()">
                                <i class="fas fa-print"></i> Print
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Report Content -->
        <div class="report-content">
            <!-- Report Header -->
            <div class="report-header">
                <div class="title-section">
                    <h2><?php echo $reportMeta['title']; ?></h2>
                    <div class="meta-info">
                        <span><i class="fas fa-calendar"></i> Generated: <?php echo date('F j, Y H:i:s'); ?></span>
                        <?php if (!empty($filters['class_id'])): ?>
                        <span><i class="fas fa-users"></i> Class: <?php echo getClassName($pdo, $filters['class_id']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($filters['term'])): ?>
                        <span><i class="fas fa-calendar-alt"></i> <?php echo $filters['term']; ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="report-badge">
                    <span class="badge badge-<?php echo $filters['report_type']; ?>">
                        <?php echo ucfirst($filters['report_type']); ?> Report
                    </span>
                </div>
            </div>

            <!-- Summary Cards -->
            <?php if (!empty($reportMeta['summary'])): ?>
            <div class="summary-cards">
                <?php foreach($reportMeta['summary'] as $card): ?>
                <div class="card <?php echo $card['type'] ?? 'info'; ?>">
                    <div class="card-icon">
                        <i class="<?php echo $card['icon']; ?>"></i>
                    </div>
                    <div class="card-content">
                        <h4><?php echo $card['label']; ?></h4>
                        <p class="card-value"><?php echo $card['value']; ?></p>
                        <?php if (isset($card['change'])): ?>
                        <span class="card-change <?php echo $card['change']['direction']; ?>">
                            <i class="fas fa-arrow-<?php echo $card['change']['direction']; ?>"></i>
                            <?php echo $card['change']['value']; ?>%
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Charts Section -->
            <?php if (!empty($reportMeta['charts'])): ?>
            <div class="charts-grid">
                <?php foreach($reportMeta['charts'] as $chartId => $chartConfig): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <h4><?php echo $chartConfig['title']; ?></h4>
                        <div class="chart-actions">
                            <button class="btn-icon" onclick="exportChart('<?php echo $chartId; ?>')">
                                <i class="fas fa-download"></i>
                            </button>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="<?php echo $chartId; ?>"></canvas>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Data Tables -->
            <?php if (!empty($reportData)): ?>
            <div class="data-tables">
                <?php foreach($reportData as $tableId => $table): ?>
                <div class="table-card">
                    <div class="table-header">
                        <h4><?php echo $table['title']; ?></h4>
                        <div class="table-actions">
                            <span class="record-count"><?php echo count($table['data']); ?> records</span>
                            <button class="btn-icon" onclick="copyTable('<?php echo $tableId; ?>')">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table" id="table-<?php echo $tableId; ?>">
                            <thead>
                                <tr>
                                    <?php foreach($table['headers'] as $header): ?>
                                    <th><?php echo $header; ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($table['data'] as $row): ?>
                                <tr>
                                    <?php foreach($table['headers'] as $key => $header): ?>
                                    <td>
                                        <?php 
                                        $value = $row[$key] ?? 'N/A';
                                        if (isset($table['formatters'][$key])) {
                                            $value = $table['formatters'][$key]($value, $row);
                                        }
                                        echo $value;
                                        ?>
                                    </td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <?php if (!empty($table['footer'])): ?>
                            <tfoot>
                                <tr>
                                    <?php foreach($table['footer'] as $footer): ?>
                                    <td><?php echo $footer; ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <!-- No Data State -->
            <div class="no-data-state">
                <i class="fas fa-chart-line"></i>
                <h3>No Data Available</h3>
                <p>Try adjusting your filters or selecting a different date range.</p>
                <button class="btn btn-primary" onclick="resetFilters()">
                    <i class="fas fa-undo"></i> Reset Filters
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Report configuration and initialization
        const reportConfig = {
            type: '<?php echo $filters['report_type']; ?>',
            charts: <?php echo json_encode($reportMeta['charts'] ?? []); ?>,
            data: <?php echo json_encode($reportData); ?>
        };

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateFilters();
            initializeCharts();
            initializeSearchableSelects();
            setupEventListeners();
        });

        function updateFilters() {
            const reportType = document.getElementById('report_type').value;
            
            // Show/hide filters based on report type
            document.querySelectorAll('.filter-item').forEach(item => {
                const types = item.dataset.types.split(',');
                if (types.includes(reportType) || types.includes('all')) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        function toggleFilters() {
            const panel = document.getElementById('filterPanel');
            const icon = document.querySelector('.toggle-icon');
            
            if (panel.style.display === 'none') {
                panel.style.display = 'block';
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            } else {
                panel.style.display = 'none';
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
            }
        }

        function initializeCharts() {
            if (reportConfig.charts) {
                Object.entries(reportConfig.charts).forEach(([id, config]) => {
                    createChart(id, config);
                });
            }
        }

        function createChart(chartId, config) {
            const ctx = document.getElementById(chartId)?.getContext('2d');
            if (!ctx) return;

            new Chart(ctx, {
                type: config.type || 'bar',
                data: {
                    labels: config.labels || [],
                    datasets: config.datasets || []
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            enabled: true
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    },
                    animation: {
                        duration: 1000,
                        easing: 'easeInOutQuart'
                    }
                }
            });
        }

        function exportReport(format) {
            const form = document.getElementById('reportForm');
            const url = new URL(window.location.href);
            
            // Add export parameter
            url.searchParams.set('export', format);
            
            if (format === 'pdf') {
                window.open(url.toString(), '_blank');
            } else {
                window.location.href = url.toString();
            }
        }

        function exportChart(chartId) {
            const canvas = document.getElementById(chartId);
            if (!canvas) return;
            
            const link = document.createElement('a');
            link.download = `chart-${chartId}-${Date.now()}.png`;
            link.href = canvas.toDataURL('image/png');
            link.click();
        }

        function copyTable(tableId) {
            const table = document.getElementById(`table-${tableId}`);
            if (!table) return;
            
            const range = document.createRange();
            range.selectNode(table);
            window.getSelection().removeAllRanges();
            window.getSelection().addRange(range);
            
            try {
                document.execCommand('copy');
                showNotification('Table copied to clipboard!', 'success');
            } catch (err) {
                showNotification('Failed to copy table', 'error');
            }
            
            window.getSelection().removeAllRanges();
        }

        function printReport() {
            window.print();
        }

        function resetFilters() {
            document.getElementById('reportForm').reset();
            document.querySelector('input[name="start_date"]').value = '<?php echo date('Y-m-01'); ?>';
            document.querySelector('input[name="end_date"]').value = '<?php echo date('Y-m-t'); ?>';
            document.getElementById('reportForm').submit();
        }

        function setReportType(type) {
            document.getElementById('report_type').value = type;
            updateFilters();
            document.getElementById('reportForm').submit();
        }

        function initializeSearchableSelects() {
            document.querySelectorAll('.select-search').forEach(select => {
                // Simple search enhancement for selects
                // In production, you might want to use a library like Select2
            });
        }

        function setupEventListeners() {
            // Auto-submit when student is selected
            document.getElementById('student_id')?.addEventListener('change', function() {
                if (this.value && document.getElementById('report_type').value === 'student') {
                    document.getElementById('reportForm').submit();
                }
            });

            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl+P for print
                if (e.ctrlKey && e.key === 'p') {
                    e.preventDefault();
                    printReport();
                }
                // Ctrl+E for export
                if (e.ctrlKey && e.key === 'e') {
                    e.preventDefault();
                    exportReport('csv');
                }
            });
        }

        function showNotification(message, type = 'info') {
            // Simple notification system
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                <span>${message}</span>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);
            
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }
    </script>

    <style>
        /* Additional inline styles for critical components */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 10px;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            z-index: 9999;
        }
        
        .notification.show {
            transform: translateX(0);
        }
        
        .notification-success {
            border-left: 4px solid #27ae60;
        }
        
        .notification-error {
            border-left: 4px solid #e74c3c;
        }
        
        .notification-info {
            border-left: 4px solid #3498db;
        }
    </style>
</body>
</html>

<?php
/**
 * Report Generator Class
 * Handles all report generation logic
 */
class ReportGenerator {
    private $pdo;
    private $filters;
    private $data = [];
    private $metadata = [];

    public function __construct($pdo, $filters) {
        $this->pdo = $pdo;
        $this->filters = $filters;
    }

    public function generate() {
        $method = 'generate' . ucfirst($this->filters['report_type']) . 'Report';
        
        if (method_exists($this, $method)) {
            return $this->$method();
        }
        
        return [];
    }

    public function getMetadata() {
        return $this->metadata;
    }

    private function generateAttendanceReport() {
        $query = "
            SELECT 
                s.id,
                s.full_name,
                s.admission_number,
                c.class_name,
                COUNT(a.id) as total_days,
                SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) as absent_days,
                SUM(CASE WHEN a.status = 'Late' THEN 1 ELSE 0 END) as late_days,
                ROUND((SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) / COUNT(a.id)) * 100, 2) as attendance_rate
            FROM students s
            JOIN classes c ON s.class_id = c.id
            LEFT JOIN attendance a ON s.id = a.student_id 
                AND a.date BETWEEN ? AND ?
            WHERE s.status = 'active'
        ";
        
        $params = [$this->filters['start_date'], $this->filters['end_date']];
        
        if (!empty($this->filters['class_id'])) {
            $query .= " AND s.class_id = ?";
            $params[] = $this->filters['class_id'];
        }
        
        $query .= " GROUP BY s.id ORDER BY c.class_name, s.full_name";
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        
        // Calculate summary statistics
        $totalStudents = count($data);
        $avgAttendance = $totalStudents > 0 
            ? round(array_sum(array_column($data, 'attendance_rate')) / $totalStudents, 2)
            : 0;
        $totalPresent = array_sum(array_column($data, 'present_days'));
        $totalAbsent = array_sum(array_column($data, 'absent_days'));
        
        $this->metadata = [
            'title' => 'Attendance Report',
            'summary' => [
                ['label' => 'Total Students', 'value' => $totalStudents, 'icon' => 'fas fa-users', 'type' => 'info'],
                ['label' => 'Avg Attendance', 'value' => $avgAttendance . '%', 'icon' => 'fas fa-chart-line', 'type' => 'success'],
                ['label' => 'Total Present', 'value' => $totalPresent, 'icon' => 'fas fa-check-circle', 'type' => 'success'],
                ['label' => 'Total Absent', 'value' => $totalAbsent, 'icon' => 'fas fa-times-circle', 'type' => 'danger']
            ],
            'charts' => [
                'attendanceChart' => [
                    'type' => 'bar',
                    'title' => 'Attendance Distribution',
                    'labels' => array_column(array_slice($data, 0, 10), 'full_name'),
                    'datasets' => [
                        [
                            'label' => 'Present Days',
                            'data' => array_column(array_slice($data, 0, 10), 'present_days'),
                            'backgroundColor' => '#27ae60'
                        ],
                        [
                            'label' => 'Absent Days',
                            'data' => array_column(array_slice($data, 0, 10), 'absent_days'),
                            'backgroundColor' => '#e74c3c'
                        ]
                    ]
                ]
            ]
        ];
        
        return [
            'attendance_data' => [
                'title' => 'Student Attendance Details',
                'headers' => ['Student Name', 'Admission No.', 'Class', 'Total Days', 'Present', 'Absent', 'Late', 'Rate', 'Status'],
                'data' => $data,
                'formatters' => [
                    'attendance_rate' => function($value) { return $value . '%'; },
                    'status' => function($value, $row) {
                        $rate = $row['attendance_rate'];
                        if ($rate >= 90) return '<span class="badge badge-success">Excellent</span>';
                        if ($rate >= 75) return '<span class="badge badge-info">Good</span>';
                        if ($rate >= 60) return '<span class="badge badge-warning">Fair</span>';
                        return '<span class="badge badge-danger">Poor</span>';
                    }
                ]
            ]
        ];
    }

    private function generateAcademicReport() {
        // Similar structure for academic report
        // ... implementation
        return [];
    }

    private function generateFinancialReport() {
        // Financial report implementation
        // ... implementation
        return [];
    }

    private function generateStudentReport() {
        // Student progress report
        // ... implementation
        return [];
    }

    private function generateInventoryReport() {
        // Library inventory report
        // ... implementation
        return [];
    }

    private function generateSummaryReport() {
        // Dashboard summary report
        // ... implementation
        return [];
    }
}

/**
 * Helper Functions
 */
function handleReportExport($pdo) {
    $format = $_GET['export'];
    $type = $_GET['report_type'] ?? 'attendance';
    
    $generator = new ReportGenerator($pdo, $_GET);
    $data = $generator->generate();
    
    if ($format === 'csv') {
        exportToCSV($data, $type);
    } elseif ($format === 'pdf') {
        exportToPDF($data, $type);
    }
}

function exportToCSV($data, $type) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=report_' . $type . '_' . date('Y-m-d_His') . '.csv');
    
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    
    foreach ($data as $table) {
        if (!empty($table['headers'])) {
            fputcsv($out, $table['headers']);
            foreach ($table['data'] as $row) {
                fputcsv($out, array_values($row));
            }
            fputcsv($out, []); // Empty line between tables
        }
    }
    
    fclose($out);
}

function exportToPDF($data, $type) {
    // Simple HTML/printable version for PDF
    echo "<!DOCTYPE html><html><head>";
    echo "<meta charset='utf-8'>";
    echo "<title>Report - " . ucfirst($type) . "</title>";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #667eea; color: white; }
        h2 { color: #2c3e50; }
        .page-break { page-break-after: always; }
        @media print {
            .no-print { display: none; }
        }
    </style>";
    echo "</head><body>";
    
    echo "<h2>" . ucfirst($type) . " Report</h2>";
    echo "<p>Generated on: " . date('Y-m-d H:i:s') . "</p>";
    
    foreach ($data as $table) {
        echo "<h3>" . $table['title'] . "</h3>";
        echo "<table>";
        echo "<thead><tr>";
        foreach ($table['headers'] as $header) {
            echo "<th>" . htmlspecialchars($header) . "</th>";
        }
        echo "</tr></thead><tbody>";
        
        foreach ($table['data'] as $row) {
            echo "<tr>";
            foreach ($table['headers'] as $key => $header) {
                $value = $row[$key] ?? 'N/A';
                if (isset($table['formatters'][$key])) {
                    $value = $table['formatters'][$key]($value, $row);
                }
                echo "<td>" . $value . "</td>";
            }
            echo "</tr>";
        }
        echo "</tbody></table>";
    }
    
    echo "<script>window.print();</script>";
    echo "</body></html>";
}

function selected($value, $selected) {
    return $value == $selected ? 'selected' : '';
}

function getAcademicTerms() {
    $currentYear = date('Y');
    return [
        $currentYear . ' Term 1',
        $currentYear . ' Term 2',
        $currentYear . ' Term 3',
        ($currentYear - 1) . ' Term 1',
        ($currentYear - 1) . ' Term 2',
        ($currentYear - 1) . ' Term 3'
    ];
}

function getClassName($pdo, $classId) {
    $stmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = ?");
    $stmt->execute([$classId]);
    $class = $stmt->fetch();
    return $class ? $class['class_name'] : 'N/A';
}

function getPageTitle($reportType) {
    $titles = [
        'attendance' => 'Attendance Report',
        'academic' => 'Academic Performance Report',
        'financial' => 'Financial Report',
        'student' => 'Student Progress Report',
        'inventory' => 'Library Inventory Report',
        'summary' => 'Summary Dashboard'
    ];
    
    return $titles[$reportType] ?? 'Reports';
}
?>