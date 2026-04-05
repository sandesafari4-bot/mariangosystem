<?php
include '../config.php';
checkAuth();
checkRole(['admin', 'teacher', 'accountant']);

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $report_type = $_GET['type'] ?? 'attendance';
    $class_id = $_GET['class'] ?? '';
    $student_id = $_GET['student'] ?? '';
    $term = $_GET['term'] ?? '';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=report_' . $report_type . '_' . date('Y-m-d_His') . '.csv');
    
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-t');
    
    if ($report_type === 'attendance') {
        fputcsv($out, ['Student Name', 'Class', 'Total Days', 'Present', 'Absent', 'Attendance Rate', 'Status']);
        $data = generateAttendanceReport($class_id, $start_date, $end_date);
        foreach ($data as $row) {
            fputcsv($out, [
                $row['full_name'],
                $row['class_name'],
                $row['total_days'],
                $row['present_days'],
                $row['absent_days'],
                $row['attendance_rate'] . '%',
                $row['attendance_rate'] >= 75 ? 'Good' : 'Poor'
            ]);
        }
    } elseif ($report_type === 'academic') {
        fputcsv($out, ['Student Name', 'Class', 'Subject', 'Marks', 'Grade', 'Remarks']);
        $data = generateAcademicReport($class_id, $term);
        foreach ($data as $row) {
            fputcsv($out, [
                $row['full_name'],
                $row['class_name'],
                $row['subject_name'] ?? 'N/A',
                $row['marks'] ?? 'N/A',
                $row['grade'] ?? 'N/A',
                $row['remarks'] ?? ''
            ]);
        }
    } elseif ($report_type === 'financial') {
        fputcsv($out, ['Date', 'Description', 'Type', 'Amount', 'Category', 'Reference']);
        $data = generateFinancialReport($start_date, $end_date);
        foreach (($data['transactions'] ?? []) as $row) {
            fputcsv($out, [
                $row['transaction_date'] ?? '',
                $row['description'] ?? '',
                $row['type'] ?? '',
                $row['amount'] ?? 0,
                $row['category'] ?? 'N/A',
                $row['reference'] ?? 'N/A'
            ]);
        }
    }
    
    fclose($out);
    exit();
}

// Handle PDF Export (simple printable HTML)
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $report_type = $_GET['type'] ?? 'attendance';
    $class_id = $_GET['class'] ?? '';
    $student_id = $_GET['student'] ?? '';
    $term = $_GET['term'] ?? '';
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-t');
    
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Report - $report_type</title>";
    echo "<style>body{font-family:Arial,sans-serif;margin:20px;}table{width:100%;border-collapse:collapse;}th,td{border:1px solid #ddd;padding:8px;text-align:left;}th{background:#667eea;color:white;}h2{color:#2c3e50;}</style>";
    echo "</head><body>";
    echo "<h2>" . ucfirst(str_replace('_', ' ', $report_type)) . " Report</h2>";
    echo "<p>Generated on: " . date('Y-m-d H:i:s') . "</p>";
    
    if ($report_type === 'attendance') {
        echo "<table><thead><tr><th>Student Name</th><th>Class</th><th>Total Days</th><th>Present</th><th>Absent</th><th>Attendance Rate</th></tr></thead><tbody>";
        $data = generateAttendanceReport($class_id, $start_date, $end_date);
        foreach ($data as $row) {
            echo "<tr><td>" . htmlspecialchars($row['full_name']) . "</td><td>" . htmlspecialchars($row['class_name']) . "</td><td>" . $row['total_days'] . "</td><td>" . $row['present_days'] . "</td><td>" . $row['absent_days'] . "</td><td>" . $row['attendance_rate'] . "%</td></tr>";
        }
    } elseif ($report_type === 'academic') {
        echo "<table><thead><tr><th>Student Name</th><th>Class</th><th>Subject</th><th>Marks</th><th>Grade</th></tr></thead><tbody>";
        $data = generateAcademicReport($class_id, $term);
        foreach ($data as $row) {
            echo "<tr><td>" . htmlspecialchars($row['full_name']) . "</td><td>" . htmlspecialchars($row['class_name']) . "</td><td>" . ($row['subject_name'] ?? 'N/A') . "</td><td>" . ($row['marks'] ?? 'N/A') . "</td><td>" . ($row['grade'] ?? 'N/A') . "</td></tr>";
        }
    } elseif ($report_type === 'financial') {
        echo "<table><thead><tr><th>Date</th><th>Description</th><th>Type</th><th>Amount</th></tr></thead><tbody>";
        $data = generateFinancialReport($start_date, $end_date);
        foreach (($data['transactions'] ?? []) as $row) {
            echo "<tr><td>" . htmlspecialchars($row['transaction_date'] ?? '') . "</td><td>" . htmlspecialchars($row['description'] ?? '') . "</td><td>" . htmlspecialchars($row['type'] ?? '') . "</td><td>Ksh " . number_format((float) ($row['amount'] ?? 0), 2) . "</td></tr>";
        }
    }
    
    echo "</tbody></table>";
    echo "<script>window.print();</script>";
    echo "</body></html>";
    exit();
}

// Get filter parameters
$report_type = $_GET['report_type'] ?? 'attendance';
$class_id = $_GET['class_id'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$student_id = $_GET['Admission_number'] ?? '';
$term = $_GET['term'] ?? '';

// Get classes for filter
$classes = $pdo->query("SELECT * FROM classes ORDER BY class_name")->fetchAll();

// Get students for filter
$students = $pdo->query("SELECT id, full_name, student_id FROM students WHERE status = 'active' ORDER BY full_name")->fetchAll();

// Generate reports based on type
$report_data = [];
$report_title = '';

switch ($report_type) {
    case 'attendance':
        $report_title = 'Attendance Report';
        $report_data = generateAttendanceReport($class_id, $start_date, $end_date);
        break;
        
    case 'academic':
        $report_title = 'Academic Performance Report';
        $report_data = generateAcademicReport($class_id, $term);
        break;
        
    case 'financial':
        $report_title = 'Financial Report';
        $report_data = generateFinancialReport($start_date, $end_date);
        break;
        
    case 'student':
        $report_title = 'Student Progress Report';
        $report_data = generateStudentReport($student_id, $term);
        break;
        
    case 'inventory':
        $report_title = 'Library Inventory Report';
        $report_data = generateInventoryReport();
        break;
}

// Report generation functions
function generateAttendanceReport($class_id, $start_date, $end_date) {
    global $pdo;
    
    $query = "
        SELECT s.full_name, s.Admission_number, c.class_name,
               COUNT(a.id) as total_days,
               SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present_days,
               SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) as absent_days,
               ROUND((SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) / COUNT(a.id)) * 100, 2) as attendance_rate
        FROM students s
        JOIN classes c ON s.class_id = c.id
        LEFT JOIN attendance a ON s.id = a.student_id AND a.date BETWEEN ? AND ?
        WHERE s.status = 'active'
    ";
    
    $params = [$start_date, $end_date];
    
    if ($class_id) {
        $query .= " AND s.class_id = ?";
        $params[] = $class_id;
    }
    
    $query .= " GROUP BY s.id ORDER BY c.class_name, s.full_name";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function generateAcademicReport($class_id, $term) {
    global $pdo;
    
    $query = "
        SELECT s.full_name, s.Admission_number, c.class_name,
               AVG(g.marks) as average_marks,
               MAX(g.marks) as highest_marks,
               MIN(g.marks) as lowest_marks,
               COUNT(g.id) as subjects_count
        FROM students s
        JOIN classes c ON s.class_id = c.id
        LEFT JOIN grades g ON s.id = g.student_id AND g.term = ?
        WHERE s.status = 'active'
    ";
    
    $params = [$term ?: 'Term 1'];
    
    if ($class_id) {
        $query .= " AND s.class_id = ?";
        $params[] = $class_id;
    }
    
    $query .= " GROUP BY s.id ORDER BY average_marks DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function generateFinancialReport($start_date, $end_date) {
    global $pdo;
    
    $reports = [];
    
    $getTableColumns = function ($tableName) use ($pdo) {
        static $cache = [];
        
        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }
        
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}`");
            $cache[$tableName] = array_map(function ($column) {
                return $column['Field'];
            }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            $cache[$tableName] = [];
        }
        
        return $cache[$tableName];
    };
    
    $hasColumn = function ($tableName, $columnName) use ($getTableColumns) {
        return in_array($columnName, $getTableColumns($tableName), true);
    };
    
    // Invoice summary
    $invoiceDateColumn = $hasColumn('invoices', 'due_date')
        ? 'due_date'
        : ($hasColumn('invoices', 'issued_date') ? 'issued_date' : 'created_at');
    $invoiceStatusColumn = $hasColumn('invoices', 'status') ? 'status' : null;
    $paidStatuses = ["'paid'"];
    if ($invoiceStatusColumn !== null) {
        if ($hasColumn('invoices', 'status')) {
            $paidStatuses[] = "'Paid'";
        }
    }
    $pendingStatuses = ["'unpaid'", "'partial'", "'partially_paid'", "'issued'", "'draft'", "'overdue'", "'pending'", "'Unpaid'", "'Partial'", "'Pending'"];
    
    $fee_query = "
        SELECT 
            COALESCE(SUM(total_amount), 0) as total_fees,
            COALESCE(SUM(amount_paid), 0) as total_paid,
            COALESCE(SUM(" . ($hasColumn('invoices', 'balance') ? 'balance' : '(total_amount - amount_paid)') . "), 0) as total_balance,
            COUNT(*) as total_students,
            SUM(CASE 
                WHEN " . ($invoiceStatusColumn !== null ? "{$invoiceStatusColumn} IN (" . implode(', ', $paidStatuses) . ")" : "amount_paid >= total_amount") . " 
                THEN 1 ELSE 0 END) as paid_students,
            SUM(CASE 
                WHEN " . ($invoiceStatusColumn !== null ? "{$invoiceStatusColumn} IN (" . implode(', ', $pendingStatuses) . ")" : "amount_paid < total_amount") . " 
                THEN 1 ELSE 0 END) as pending_students
        FROM invoices
        WHERE DATE({$invoiceDateColumn}) BETWEEN ? AND ?
    ";
    
    $fee_stmt = $pdo->prepare($fee_query);
    $fee_stmt->execute([$start_date, $end_date]);
    $reports['fee_summary'] = $fee_stmt->fetch();
    
    // Transaction details
    $paymentDateExpression = $hasColumn('payments', 'payment_date')
        ? 'p.payment_date'
        : ($hasColumn('payments', 'paid_at') ? 'p.paid_at' : 'p.created_at');
    $paymentReferenceExpression = $hasColumn('payments', 'reference_no')
        ? 'p.reference_no'
        : ($hasColumn('payments', 'reference')
            ? 'p.reference'
            : ($hasColumn('payments', 'transaction_ref')
                ? 'p.transaction_ref'
                : ($hasColumn('payments', 'transaction_id')
                    ? 'p.transaction_id'
                    : ($hasColumn('payments', 'mpesa_receipt') ? 'p.mpesa_receipt' : "''"))));
    $paymentMethodExpression = $hasColumn('payment_methods', 'label')
        ? 'pm.label'
        : ($hasColumn('payment_methods', 'code')
            ? 'pm.code'
            : ($hasColumn('payments', 'payment_method') ? 'p.payment_method' : "'Payment'"));
    $recordedByColumn = $hasColumn('payments', 'recorded_by')
        ? 'recorded_by'
        : ($hasColumn('payments', 'created_by')
            ? 'created_by'
            : ($hasColumn('payments', 'verified_by') ? 'verified_by' : null));
    $userNameExpression = $hasColumn('users', 'full_name')
        ? 'u.full_name'
        : ($hasColumn('users', 'name') ? 'u.name' : "'System'");
    $studentIdColumn = $hasColumn('students', 'Admission_number')
        ? 's.Admission_number'
        : ($hasColumn('students', 'admission_number') ? 's.admission_number' : "''");
    $paymentStatusFilter = $hasColumn('payments', 'status')
        ? "AND LOWER(COALESCE(p.status, '')) NOT IN ('failed', 'cancelled')"
        : '';
    
    $trans_query = "
        SELECT 
            {$paymentDateExpression} as transaction_date,
            CONCAT('Payment from ', COALESCE(s.full_name, 'Unknown Student')) as description,
            'Payment' as type,
            p.amount,
            {$paymentMethodExpression} as category,
            {$paymentReferenceExpression} as reference,
            COALESCE(s.full_name, 'Unknown Student') as full_name,
            {$studentIdColumn} as Admission_number,
            {$paymentMethodExpression} as payment_method,
            COALESCE({$userNameExpression}, 'System') as recorded_by
        FROM payments p
        LEFT JOIN students s ON p.student_id = s.id
        " . ($recordedByColumn !== null ? "LEFT JOIN users u ON p.{$recordedByColumn} = u.id" : "LEFT JOIN users u ON 1 = 0") . "
        " . ($hasColumn('payments', 'payment_method_id') ? "LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id" : "LEFT JOIN payment_methods pm ON 1 = 0") . "
        WHERE DATE({$paymentDateExpression}) BETWEEN ? AND ?
        {$paymentStatusFilter}
        ORDER BY {$paymentDateExpression} DESC, p.id DESC
    ";
    
    $trans_stmt = $pdo->prepare($trans_query);
    $trans_stmt->execute([$start_date, $end_date]);
    $reports['transactions'] = $trans_stmt->fetchAll();
    
    return $reports;
}

function generateStudentReport($student_id, $term) {
    global $pdo;
    
    if (!$student_id) return [];
    
    $reports = [];
    
    // Student basic info
    $student_query = "
        SELECT s.*, c.class_name, u.full_name as class_teacher
        FROM students s
        JOIN classes c ON s.class_id = c.id
        LEFT JOIN users u ON c.class_teacher_id = u.id
        WHERE s.id = ?
    ";
    
    $student_stmt = $pdo->prepare($student_query);
    $student_stmt->execute([$student_id]);
    $reports['student_info'] = $student_stmt->fetch();
    
    // Academic performance
    $grades_query = "
        SELECT g.*, sub.subject_name, t.full_name as teacher_name
        FROM grades g
        JOIN subjects sub ON g.subject_id = sub.id
        LEFT JOIN users t ON sub.teacher_id = t.id
        WHERE g.Admission_number = ? AND g.term = ?
        ORDER BY sub.subject_name
    ";
    
    $grades_stmt = $pdo->prepare($grades_query);
    $grades_stmt->execute([$student_id, $term ?: 'Term 1']);
    $reports['grades'] = $grades_stmt->fetchAll();
    
    // Attendance
    $attendance_query = "
        SELECT date, status
        FROM attendance
        WHERE Admission_number = ? AND date BETWEEN ? AND ?
        ORDER BY date DESC
        LIMIT 30
    ";
    
    $attendance_stmt = $pdo->prepare($attendance_query);
    $month_start = date('Y-m-01');
    $month_end = date('Y-m-t');
    $attendance_stmt->execute([$student_id, $month_start, $month_end]);
    $reports['attendance'] = $attendance_stmt->fetchAll();
    
    return $reports;
}

function generateInventoryReport() {
    global $pdo;
    
    $reports = [];
    
    // Library books summary
    $books_query = "
        SELECT 
            COUNT(*) as total_books,
            SUM(total_copies) as total_copies,
            SUM(available_copies) as available_copies,
            COUNT(DISTINCT category) as categories_count
        FROM books
    ";
    
    $books_stmt = $pdo->prepare($books_query);
    $books_stmt->execute();
    $reports['books_summary'] = $books_stmt->fetch();
    
    // Currently issued books
    $issued_query = "
        SELECT bi.*, b.title, b.author, s.full_name, s.Admission_number, c.class_name
        FROM book_issues bi
        JOIN books b ON bi.book_id = b.id
        JOIN students s ON bi.student_id = s.id
        JOIN classes c ON s.class_id = c.id
        WHERE bi.status = 'Issued'
        ORDER BY bi.due_date
    ";
    
    $issued_stmt = $pdo->prepare($issued_query);
    $issued_stmt->execute();
    $reports['issued_books'] = $issued_stmt->fetchAll();
    
    // Overdue books
    $overdue_query = "
        SELECT bi.*, b.title, b.author, s.full_name, s.Admission_number, c.class_name,
               DATEDIFF(CURDATE(), bi.due_date) as days_overdue
        FROM book_issues bi
        JOIN books b ON bi.book_id = b.id
        JOIN students s ON bi.student_id = s.id
        JOIN classes c ON s.class_id = c.id
        WHERE bi.status = 'Issued' AND bi.due_date < CURDATE()
        ORDER BY bi.due_date
    ";
    
    $overdue_stmt = $pdo->prepare($overdue_query);
    $overdue_stmt->execute();
    $reports['overdue_books'] = $overdue_stmt->fetchAll();
    
    return $reports;
}

$page_title = "Reports - " . SCHOOL_NAME;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #3498db;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --info: #17a2b8;
            --dark: #2c3e50;
            --light: #f8f9fa;
        }
        
        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            background: #f5f6fa;
            min-height: calc(100vh - 70px);
            transition: margin-left 0.3s ease;
        }
        
        .sidebar.collapsed ~ .main-content {
            margin-left: 70px;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        .header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .filters {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }
        
        .form-group {
            margin-bottom: 0;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: var(--dark);
            font-size: 14px;
        }
        
        input, select {
            width: 100%;
            padding: 10px;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }
        
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .report-container {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .report-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light);
        }
        
        .report-title {
            color: var(--dark);
            margin: 0;
        }
        
        .report-actions {
            display: flex;
            gap: 10px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: var(--light);
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid var(--primary);
        }
        
        .stat-card.success { border-left-color: var(--success); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-card.info { border-left-color: var(--info); }
        
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-card.success .stat-number { color: var(--success); }
        .stat-card.warning .stat-number { color: var(--warning); }
        .stat-card.danger .stat-number { color: var(--danger); }
        .stat-card.info .stat-number { color: var(--info); }
        
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        
        .table-container {
            overflow-x: auto;
            margin-bottom: 25px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        th {
            background: var(--light);
            font-weight: 600;
            color: var(--dark);
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-success { background: rgba(39, 174, 96, 0.1); color: var(--success); }
        .badge-warning { background: rgba(243, 156, 18, 0.1); color: var(--warning); }
        .badge-danger { background: rgba(231, 76, 60, 0.1); color: var(--danger); }
        .badge-info { background: rgba(23, 162, 184, 0.1); color: var(--info); }
        
        .chart-container {
            height: 300px;
            margin: 25px 0;
        }
        
        .student-profile {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 20px;
            align-items: start;
            margin-bottom: 25px;
        }
        
        .profile-image {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: var(--light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2em;
            color: var(--primary);
        }
        
        .profile-info h3 {
            margin: 0 0 10px 0;
            color: var(--dark);
        }
        
        .profile-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .detail-label {
            font-weight: 600;
            color: var(--dark);
        }
        
        .detail-value {
            color: #666;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .no-data i {
            font-size: 3em;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .student-profile {
                grid-template-columns: 1fr;
                text-align: center;
            }
            
            .report-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="main-content">
        <div class="header">
            <h1>Reports & Analytics</h1>
            <p>Generate and analyze various school reports</p>
        </div>
        
        <!-- Report Filters -->
        <div class="filters">
            <form method="GET" id="reportForm">
                <div class="filter-grid">
                    <div class="form-group">
                        <label for="report_type">Report Type</label>
                        <select id="report_type" name="report_type" onchange="updateFilters()">
                            <option value="attendance" <?php echo $report_type == 'attendance' ? 'selected' : ''; ?>>Attendance Report</option>
                            <option value="academic" <?php echo $report_type == 'academic' ? 'selected' : ''; ?>>Academic Performance</option>
                            <option value="financial" <?php echo $report_type == 'financial' ? 'selected' : ''; ?>>Financial Report</option>
                            <option value="student" <?php echo $report_type == 'student' ? 'selected' : ''; ?>>Student Progress</option>
                            <option value="inventory" <?php echo $report_type == 'inventory' ? 'selected' : ''; ?>>Library Inventory</option>
                        </select>
                    </div>
                    
                    <div class="form-group" id="classFilter">
                        <label for="class_id">Class</label>
                        <select id="class_id" name="class_id">
                            <option value="">All Classes</option>
                            <?php foreach($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $class_id == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group" id="studentFilter">
                        <label for="student_id">Student</label>
                        <select id="student_id" name="student_id">
                            <option value="">Select Student</option>
                            <?php foreach($students as $student): ?>
                            <option value="<?php echo $student['id']; ?>" <?php echo $student_id == $student['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($student['full_name'] . ' (' . $student['Admission_number'] . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group" id="dateFilter">
                        <label for="start_date">Start Date</label>
                        <input type="date" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                    </div>
                    
                    <div class="form-group" id="endDateFilter">
                        <label for="end_date">End Date</label>
                        <input type="date" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                    </div>
                    
                    <div class="form-group" id="termFilter">
                        <label for="term">Term</label>
                        <select id="term" name="term">
                            <option value="Term 1" <?php echo $term == 'Term 1' ? 'selected' : ''; ?>>Term 1</option>
                            <option value="Term 2" <?php echo $term == 'Term 2' ? 'selected' : ''; ?>>Term 2</option>
                            <option value="Term 3" <?php echo $term == 'Term 3' ? 'selected' : ''; ?>>Term 3</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-chart-bar"></i>Generate Report
                        </button>
                        <button type="button" class="btn btn-outline" onclick="exportReport()">
                            <i class="fas fa-download"></i>Export
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Report Content -->
        <div class="report-container">
            <div class="report-header">
                <h2 class="report-title"><?php echo $report_title; ?></h2>
                <div class="report-actions">
                    <button class="btn btn-outline" onclick="printReport()">
                        <i class="fas fa-print"></i>Print
                    </button>
                    <button class="btn btn-success" onclick="exportPDF()">
                        <i class="fas fa-file-pdf"></i>PDF
                    </button>
                </div>
            </div>
            
            <?php if ($report_type == 'attendance'): ?>
                <!-- Attendance Report -->
                <?php if (!empty($report_data)): ?>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo count($report_data); ?></div>
                            <div class="stat-label">Total Students</div>
                        </div>
                        <div class="stat-card success">
                            <div class="stat-number">
                                <?php echo round(array_sum(array_column($report_data, 'attendance_rate')) / count($report_data), 2); ?>%
                            </div>
                            <div class="stat-label">Average Attendance</div>
                        </div>
                        <div class="stat-card info">
                            <div class="stat-number">
                                <?php echo array_sum(array_column($report_data, 'present_days')); ?>
                            </div>
                            <div class="stat-label">Total Present Days</div>
                        </div>
                        <div class="stat-card warning">
                            <div class="stat-number">
                                <?php echo array_sum(array_column($report_data, 'absent_days')); ?>
                            </div>
                            <div class="stat-label">Total Absent Days</div>
                        </div>
                    </div>
                    
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Class</th>
                                    <th>Total Days</th>
                                    <th>Present</th>
                                    <th>Absent</th>
                                    <th>Attendance Rate</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($report_data as $record): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($record['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($record['class_name']); ?></td>
                                    <td><?php echo $record['total_days']; ?></td>
                                    <td><?php echo $record['present_days']; ?></td>
                                    <td><?php echo $record['absent_days']; ?></td>
                                    <td><?php echo $record['attendance_rate']; ?>%</td>
                                    <td>
                                        <?php if ($record['attendance_rate'] >= 90): ?>
                                            <span class="badge badge-success">Excellent</span>
                                        <?php elseif ($record['attendance_rate'] >= 75): ?>
                                            <span class="badge badge-info">Good</span>
                                        <?php elseif ($record['attendance_rate'] >= 60): ?>
                                            <span class="badge badge-warning">Fair</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">Poor</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-chart-line"></i>
                        <h3>No Attendance Data Found</h3>
                        <p>Please adjust your filters and try again.</p>
                    </div>
                <?php endif; ?>
                
            <?php elseif ($report_type == 'academic'): ?>
                <!-- Academic Performance Report -->
                <?php if (!empty($report_data)): ?>
                    <div class="chart-container">
                        <canvas id="academicChart"></canvas>
                    </div>
                    
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Student Name</th>
                                    <th>Class</th>
                                    <th>Average Marks</th>
                                    <th>Highest</th>
                                    <th>Lowest</th>
                                    <th>Subjects</th>
                                    <th>Grade</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rank = 1; foreach($report_data as $record): ?>
                                <tr>
                                    <td><?php echo $rank++; ?></td>
                                    <td><?php echo htmlspecialchars($record['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($record['class_name']); ?></td>
                                    <td><?php echo round($record['average_marks'], 2); ?></td>
                                    <td><?php echo $record['highest_marks']; ?></td>
                                    <td><?php echo $record['lowest_marks']; ?></td>
                                    <td><?php echo $record['subjects_count']; ?></td>
                                    <td>
                                        <?php 
                                        $avg = $record['average_marks'];
                                        if ($avg >= 80) echo '<span class="badge badge-success">A</span>';
                                        elseif ($avg >= 70) echo '<span class="badge badge-info">B</span>';
                                        elseif ($avg >= 60) echo '<span class="badge badge-warning">C</span>';
                                        elseif ($avg >= 50) echo '<span class="badge badge-warning">D</span>';
                                        else echo '<span class="badge badge-danger">E</span>';
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-graduation-cap"></i>
                        <h3>No Academic Data Found</h3>
                        <p>Please adjust your filters and try again.</p>
                    </div>
                <?php endif; ?>
                
            <?php elseif ($report_type == 'financial'): ?>
                <!-- Financial Report -->
                <?php if (!empty($report_data['fee_summary'])): ?>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number">KES <?php echo number_format($report_data['fee_summary']['total_fees'], 2); ?></div>
                            <div class="stat-label">Total Fees Expected</div>
                        </div>
                        <div class="stat-card success">
                            <div class="stat-number">KES <?php echo number_format($report_data['fee_summary']['total_paid'], 2); ?></div>
                            <div class="stat-label">Total Collected</div>
                        </div>
                        <div class="stat-card warning">
                            <div class="stat-number">KES <?php echo number_format($report_data['fee_summary']['total_balance'], 2); ?></div>
                            <div class="stat-label">Outstanding Balance</div>
                        </div>
                        <div class="stat-card info">
                            <div class="stat-number">
                                <?php echo $report_data['fee_summary']['paid_students'] . '/' . $report_data['fee_summary']['total_students']; ?>
                            </div>
                            <div class="stat-label">Paid Students</div>
                        </div>
                    </div>
                    
                    <h3>Recent Transactions</h3>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Student</th>
                                    <th>Description</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Recorded By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($report_data['transactions'] as $transaction): ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime($transaction['transaction_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($transaction['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($transaction['description']); ?></td>
                                    <td>KES <?php echo number_format($transaction['amount'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($transaction['payment_method']); ?></td>
                                    <td><?php echo htmlspecialchars($transaction['recorded_by']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3>No Financial Data Found</h3>
                        <p>Please adjust your filters and try again.</p>
                    </div>
                <?php endif; ?>
                
            <?php elseif ($report_type == 'student'): ?>
                <!-- Student Progress Report -->
                <?php if (!empty($report_data['student_info'])): ?>
                    <div class="student-profile">
                        <div class="profile-image">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="profile-info">
                            <h3><?php echo htmlspecialchars($report_data['student_info']['full_name']); ?></h3>
                            <div class="profile-details">
                                <div class="detail-item">
                                    <span class="detail-label">Student ID:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($report_data['student_info']['Admission_number']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Class:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($report_data['student_info']['class_name']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Class Teacher:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($report_data['student_info']['class_teacher']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Term:</span>
                                    <span class="detail-value"><?php echo $term ?: 'Term 1'; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($report_data['grades'])): ?>
                        <h3>Academic Performance</h3>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Teacher</th>
                                        <th>Marks</th>
                                        <th>Grade</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_marks = 0;
                                    $subject_count = 0;
                                    foreach($report_data['grades'] as $grade): 
                                        $total_marks += $grade['marks'];
                                        $subject_count++;
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($grade['subject_name']); ?></td>
                                        <td><?php echo htmlspecialchars($grade['teacher_name']); ?></td>
                                        <td><?php echo $grade['marks']; ?></td>
                                        <td>
                                            <?php 
                                            $marks = $grade['marks'];
                                            if ($marks >= 80) echo '<span class="badge badge-success">A</span>';
                                            elseif ($marks >= 70) echo '<span class="badge badge-info">B</span>';
                                            elseif ($marks >= 60) echo '<span class="badge badge-warning">C</span>';
                                            elseif ($marks >= 50) echo '<span class="badge badge-warning">D</span>';
                                            else echo '<span class="badge badge-danger">E</span>';
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($grade['remarks']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-number"><?php echo $subject_count; ?></div>
                                <div class="stat-label">Subjects</div>
                            </div>
                            <div class="stat-card success">
                                <div class="stat-number"><?php echo round($total_marks / $subject_count, 2); ?></div>
                                <div class="stat-label">Average Marks</div>
                            </div>
                            <div class="stat-card info">
                                <div class="stat-number">
                                    <?php echo max(array_column($report_data['grades'], 'marks')); ?>
                                </div>
                                <div class="stat-label">Highest Score</div>
                            </div>
                            <div class="stat-card warning">
                                <div class="stat-number">
                                    <?php echo min(array_column($report_data['grades'], 'marks')); ?>
                                </div>
                                <div class="stat-label">Lowest Score</div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($report_data['attendance'])): ?>
                        <h3>Recent Attendance</h3>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($report_data['attendance'] as $attendance): ?>
                                    <tr>
                                        <td><?php echo date('M j, Y', strtotime($attendance['date'])); ?></td>
                                        <td>
                                            <?php if ($attendance['status'] == 'Present'): ?>
                                                <span class="badge badge-success">Present</span>
                                            <?php elseif ($attendance['status'] == 'Absent'): ?>
                                                <span class="badge badge-danger">Absent</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning"><?php echo $attendance['status']; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($attendance['remarks']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-user-graduate"></i>
                        <h3>No Student Selected</h3>
                        <p>Please select a student to view their progress report.</p>
                    </div>
                <?php endif; ?>
                
            <?php elseif ($report_type == 'inventory'): ?>
                <!-- Library Inventory Report -->
                <?php if (!empty($report_data['books_summary'])): ?>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $report_data['books_summary']['total_books']; ?></div>
                            <div class="stat-label">Total Books</div>
                        </div>
                        <div class="stat-card info">
                            <div class="stat-number"><?php echo $report_data['books_summary']['total_copies']; ?></div>
                            <div class="stat-label">Total Copies</div>
                        </div>
                        <div class="stat-card success">
                            <div class="stat-number"><?php echo $report_data['books_summary']['available_copies']; ?></div>
                            <div class="stat-label">Available Copies</div>
                        </div>
                        <div class="stat-card warning">
                            <div class="stat-number"><?php echo $report_data['books_summary']['categories_count']; ?></div>
                            <div class="stat-label">Categories</div>
                        </div>
                    </div>
                    
                    <?php if (!empty($report_data['issued_books'])): ?>
                        <h3>Currently Issued Books</h3>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Book Title</th>
                                        <th>Author</th>
                                        <th>Issued To</th>
                                        <th>Class</th>
                                        <th>Issue Date</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($report_data['issued_books'] as $book): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($book['title']); ?></td>
                                        <td><?php echo htmlspecialchars($book['author']); ?></td>
                                        <td><?php echo htmlspecialchars($book['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($book['class_name']); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($book['issue_date'])); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($book['due_date'])); ?></td>
                                        <td>
                                            <?php if (strtotime($book['due_date']) < time()): ?>
                                                <span class="badge badge-danger">Overdue</span>
                                            <?php else: ?>
                                                <span class="badge badge-success">Issued</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($report_data['overdue_books'])): ?>
                        <h3>Overdue Books</h3>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Book Title</th>
                                        <th>Author</th>
                                        <th>Issued To</th>
                                        <th>Class</th>
                                        <th>Due Date</th>
                                        <th>Days Overdue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($report_data['overdue_books'] as $book): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($book['title']); ?></td>
                                        <td><?php echo htmlspecialchars($book['author']); ?></td>
                                        <td><?php echo htmlspecialchars($book['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($book['class_name']); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($book['due_date'])); ?></td>
                                        <td>
                                            <span class="badge badge-danger">
                                                <?php echo $book['days_overdue']; ?> days
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-book"></i>
                        <h3>No Inventory Data Found</h3>
                        <p>There is no library inventory data available.</p>
                    </div>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Update filters based on report type
        function updateFilters() {
            const reportType = document.getElementById('report_type').value;
            
            // Hide all filters first
            document.getElementById('classFilter').style.display = 'none';
            document.getElementById('studentFilter').style.display = 'none';
            document.getElementById('dateFilter').style.display = 'none';
            document.getElementById('endDateFilter').style.display = 'none';
            document.getElementById('termFilter').style.display = 'none';
            
            // Show relevant filters based on report type
            switch(reportType) {
                case 'attendance':
                    document.getElementById('classFilter').style.display = 'block';
                    document.getElementById('dateFilter').style.display = 'block';
                    document.getElementById('endDateFilter').style.display = 'block';
                    break;
                case 'academic':
                    document.getElementById('classFilter').style.display = 'block';
                    document.getElementById('termFilter').style.display = 'block';
                    break;
                case 'financial':
                    document.getElementById('dateFilter').style.display = 'block';
                    document.getElementById('endDateFilter').style.display = 'block';
                    break;
                case 'student':
                    document.getElementById('studentFilter').style.display = 'block';
                    document.getElementById('termFilter').style.display = 'block';
                    break;
                case 'inventory':
                    // No additional filters needed
                    break;
            }
        }
        
        // Initialize filters on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateFilters();
            
            <?php if ($report_type == 'academic' && !empty($report_data)): ?>
            // Initialize academic performance chart
            const academicCtx = document.getElementById('academicChart').getContext('2d');
            const academicChart = new Chart(academicCtx, {
                type: 'bar',
                data: {
                    labels: [<?php echo implode(',', array_map(function($record) { return "'" . addslashes($record['full_name']) . "'"; }, array_slice($report_data, 0, 10))); ?>],
                    datasets: [{
                        label: 'Average Marks',
                        data: [<?php echo implode(',', array_map(function($record) { return $record['average_marks']; }, array_slice($report_data, 0, 10))); ?>],
                        backgroundColor: 'rgba(52, 152, 219, 0.8)',
                        borderColor: 'rgba(52, 152, 219, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            title: {
                                display: true,
                                text: 'Marks'
                            }
                        }
                    }
                }
            });
            <?php endif; ?>
        });
        
        function printReport() {
            window.print();
        }
        
        function exportReport() {
            const reportType = document.getElementById('report_type').value;
            const className = document.getElementById('class')?.value || '';
            const term = document.getElementById('term')?.value || '';
            const studentId = document.getElementById('student')?.value || '';
            
            // Build query string
            let queryParams = `type=${reportType}&export=csv`;
            if (className) queryParams += `&class=${className}`;
            if (term) queryParams += `&term=${term}`;
            if (studentId) queryParams += `&student=${studentId}`;
            
            // Trigger CSV download
            window.location.href = `reports.php?${queryParams}`;
        }
        
        function exportPDF() {
            const reportType = document.getElementById('report_type').value;
            const className = document.getElementById('class')?.value || '';
            const term = document.getElementById('term')?.value || '';
            const studentId = document.getElementById('student')?.value || '';
            
            // Build query string
            let queryParams = `type=${reportType}&export=pdf`;
            if (className) queryParams += `&class=${className}`;
            if (term) queryParams += `&term=${term}`;
            if (studentId) queryParams += `&student=${studentId}`;
            
            // Trigger PDF download (opens in new window for browser print to PDF)
            window.open(`reports.php?${queryParams}`, '_blank');
        }
        
        // Auto-submit form when student is selected for student report
        document.getElementById('student_id')?.addEventListener('change', function() {
            if (this.value && document.getElementById('report_type')?.value === 'student') {
                document.getElementById('reportForm')?.submit();
            }
        });
    </script>
</body>
</html>
