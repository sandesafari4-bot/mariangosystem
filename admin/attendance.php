<?php
include '../config.php';
checkAuth();
checkRole(['admin', 'teacher']);

// Get current date or selected date
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_class = $_GET['class_id'] ?? '';
$view_type = $_GET['view'] ?? 'daily'; // daily, weekly, monthly, summary
$week_offset = isset($_GET['week']) ? (int)$_GET['week'] : 0;

// Calculate week dates
$monday = date('Y-m-d', strtotime($selected_date . ' -' . (date('N', strtotime($selected_date)) - 1) . ' days'));
$monday = date('Y-m-d', strtotime($monday . ' + ' . ($week_offset * 7) . ' days'));
$week_dates = [];
for ($i = 0; $i < 5; $i++) {
    $week_dates[] = date('Y-m-d', strtotime($monday . ' + ' . $i . ' days'));
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    $export_class = $_GET['class_id'] ?? '';
    $export_date = $_GET['date'] ?? date('Y-m-d');
    $export_view = $_GET['view'] ?? 'daily';
    
    if (!$export_class) {
        die('Please select a class to export');
    }
    
    // Get class info
    $class_stmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = ?");
    $class_stmt->execute([$export_class]);
    $class_data = $class_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get students and attendance data
    if ($export_view == 'daily') {
        $students_stmt = $pdo->prepare("
            SELECT s.*, c.class_name,
                   a.status as attendance_status,
                   a.remarks
            FROM students s
            JOIN classes c ON s.class_id = c.id
            LEFT JOIN attendance a ON s.id = a.student_id AND a.date = ?
            WHERE s.class_id = ? AND s.status = 'active'
            ORDER BY s.full_name
        ");
        $students_stmt->execute([$export_date, $export_class]);
        $students_data = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Generate CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=daily_attendance_' . date('Y-m-d') . '.csv');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
        
        fputcsv($output, ['DAILY ATTENDANCE REPORT']);
        fputcsv($output, ['Class:', $class_data['class_name']]);
        fputcsv($output, ['Date:', date('F j, Y', strtotime($export_date))]);
        fputcsv($output, ['Generated:', date('F j, Y H:i:s')]);
        fputcsv($output, []);
        fputcsv($output, ['Student ID', 'Full Name', 'Attendance Status', 'Remarks']);
        
        foreach ($students_data as $student) {
            fputcsv($output, [
                $student['Admission_number'],
                $student['full_name'],
                $student['attendance_status'] ?? 'Not Marked',
                $student['remarks'] ?? ''
            ]);
        }
        
        // Add summary
        $present = count(array_filter($students_data, fn($s) => ($s['attendance_status'] ?? '') == 'Present'));
        $absent = count(array_filter($students_data, fn($s) => ($s['attendance_status'] ?? '') == 'Absent'));
        $late = count(array_filter($students_data, fn($s) => ($s['attendance_status'] ?? '') == 'Late'));
        $not_marked = count(array_filter($students_data, fn($s) => empty($s['attendance_status'])));
        
        fputcsv($output, []);
        fputcsv($output, ['SUMMARY']);
        fputcsv($output, ['Total Students:', count($students_data)]);
        fputcsv($output, ['Present:', $present]);
        fputcsv($output, ['Absent:', $absent]);
        fputcsv($output, ['Late:', $late]);
        fputcsv($output, ['Not Marked:', $not_marked]);
        fputcsv($output, ['Attendance Rate:', round(($present / max(1, count($students_data) - $not_marked)) * 100, 1) . '%']);
        
    } elseif ($export_view == 'weekly') {
        // Weekly export
        $week_start = $_GET['week_start'] ?? $monday;
        $week_end = date('Y-m-d', strtotime($week_start . ' +4 days'));
        
        $students_stmt = $pdo->prepare("
            SELECT s.*, c.class_name
            FROM students s
            JOIN classes c ON s.class_id = c.id
            WHERE s.class_id = ? AND s.status = 'active'
            ORDER BY s.full_name
        ");
        $students_stmt->execute([$export_class]);
        $students_data = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=weekly_attendance_' . date('Y-m-d') . '.csv');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
        
        fputcsv($output, ['WEEKLY ATTENDANCE REPORT']);
        fputcsv($output, ['Class:', $class_data['class_name']]);
        fputcsv($output, ['Week:', date('M j', strtotime($week_start)) . ' - ' . date('M j, Y', strtotime($week_end))]);
        fputcsv($output, ['Generated:', date('F j, Y H:i:s')]);
        fputcsv($output, []);
        
        // Headers
        $headers = ['Student ID', 'Full Name'];
        foreach ($week_dates as $date) {
            $headers[] = date('D', strtotime($date)) . ' ' . date('m/d', strtotime($date));
        }
        $headers[] = 'Present %';
        fputcsv($output, $headers);
        
        // Data rows
        foreach ($students_data as $student) {
            $row = [
                $student['Admission_number'],
                $student['full_name']
            ];
            
            $present_count = 0;
            $total_days = 0;
            
            foreach ($week_dates as $date) {
                $att_stmt = $pdo->prepare("SELECT status FROM attendance WHERE student_id = ? AND date = ?");
                $att_stmt->execute([$student['id'], $date]);
                $status = $att_stmt->fetchColumn();
                
                $status_display = $status ? substr($status, 0, 1) : '-';
                if ($status == 'Present') {
                    $present_count++;
                    $total_days++;
                } elseif ($status == 'Late') {
                    $present_count += 0.5; // Count late as half present
                    $total_days++;
                } elseif ($status == 'Absent') {
                    $total_days++;
                }
                
                $row[] = $status_display;
            }
            
            $percentage = $total_days > 0 ? round(($present_count / $total_days) * 100, 1) : 0;
            $row[] = $percentage . '%';
            
            fputcsv($output, $row);
        }
        
    } else {
        // Monthly summary
        $month = date('m', strtotime($export_date));
        $year = date('Y', strtotime($export_date));
        
        $students_stmt = $pdo->prepare("
            SELECT s.*, c.class_name
            FROM students s
            JOIN classes c ON s.class_id = c.id
            WHERE s.class_id = ? AND s.status = 'active'
            ORDER BY s.full_name
        ");
        $students_stmt->execute([$export_class]);
        $students_data = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=monthly_summary_' . date('Y-m') . '.csv');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
        
        fputcsv($output, ['MONTHLY ATTENDANCE SUMMARY']);
        fputcsv($output, ['Class:', $class_data['class_name']]);
        fputcsv($output, ['Month:', date('F Y', strtotime($export_date))]);
        fputcsv($output, ['Generated:', date('F j, Y H:i:s')]);
        fputcsv($output, []);
        fputcsv($output, ['Student ID', 'Full Name', 'Present', 'Absent', 'Late', 'Total Days', 'Attendance Rate']);
        
        foreach ($students_data as $student) {
            $present = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ? AND MONTH(date) = ? AND YEAR(date) = ? AND status = 'Present'");
            $present->execute([$student['id'], $month, $year]);
            $present_count = $present->fetchColumn();
            
            $absent = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ? AND MONTH(date) = ? AND YEAR(date) = ? AND status = 'Absent'");
            $absent->execute([$student['id'], $month, $year]);
            $absent_count = $absent->fetchColumn();
            
            $late = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ? AND MONTH(date) = ? AND YEAR(date) = ? AND status = 'Late'");
            $late->execute([$student['id'], $month, $year]);
            $late_count = $late->fetchColumn();
            
            $total = $present_count + $absent_count + $late_count;
            $rate = $total > 0 ? round(($present_count / $total) * 100, 1) : 0;
            
            fputcsv($output, [
                $student['Admission_number'],
                $student['full_name'],
                $present_count,
                $absent_count,
                $late_count,
                $total,
                $rate . '%'
            ]);
        }
    }
    
    fclose($output);
    exit();
}

// Handle attendance submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['mark_attendance'])) {
        $class_id = $_POST['class_id'];
        $date = $_POST['date'];
        $attendances = $_POST['attendance'] ?? [];
        $remarks = $_POST['remarks'] ?? [];
        
        $pdo->beginTransaction();
        try {
            foreach ($attendances as $student_id => $status) {
                if (empty($status)) continue;
                
                // Check if attendance already exists
                $check_stmt = $pdo->prepare("SELECT id FROM attendance WHERE student_id = ? AND date = ?");
                $check_stmt->execute([$student_id, $date]);
                $existing = $check_stmt->fetch();
                
                $remark = $remarks[$student_id] ?? null;
                
                if ($existing) {
                    // Update existing attendance
                    $stmt = $pdo->prepare("UPDATE attendance SET status = ?, remarks = ?, recorded_by = ? WHERE student_id = ? AND date = ?");
                    $stmt->execute([$status, $remark, $_SESSION['user_id'], $student_id, $date]);
                } else {
                    // Insert new attendance
                    $stmt = $pdo->prepare("INSERT INTO attendance (student_id, class_id, date, status, remarks, recorded_by) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$student_id, $class_id, $date, $status, $remark, $_SESSION['user_id']]);
                }
            }
            $pdo->commit();
            $success = "Attendance marked successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to mark attendance: " . $e->getMessage();
        }
        
        header("Location: attendance.php?success=" . urlencode($success ?? $error) . "&date=$date&class_id=$class_id&view=$view_type" . ($view_type == 'weekly' ? "&week=$week_offset" : ""));
        exit();
    }
    
    if (isset($_POST['bulk_attendance'])) {
        $class_id = $_POST['class_id'];
        $date = $_POST['date'];
        $bulk_status = $_POST['bulk_status'];
        
        if (!$bulk_status) {
            $error = "Please select a status for bulk action";
        } else {
            // Get all students in the class
            $students = $pdo->prepare("SELECT id FROM students WHERE class_id = ? AND status = 'active'");
            $students->execute([$class_id]);
            $student_ids = $students->fetchAll(PDO::FETCH_COLUMN);
            
            $pdo->beginTransaction();
            try {
                foreach ($student_ids as $student_id) {
                    // Check if attendance already exists
                    $check_stmt = $pdo->prepare("SELECT id FROM attendance WHERE student_id = ? AND date = ?");
                    $check_stmt->execute([$student_id, $date]);
                    
                    if ($check_stmt->fetch()) {
                        // Update existing attendance
                        $stmt = $pdo->prepare("UPDATE attendance SET status = ?, recorded_by = ? WHERE student_id = ? AND date = ?");
                        $stmt->execute([$bulk_status, $_SESSION['user_id'], $student_id, $date]);
                    } else {
                        // Insert new attendance
                        $stmt = $pdo->prepare("INSERT INTO attendance (student_id, class_id, date, status, recorded_by) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$student_id, $class_id, $date, $bulk_status, $_SESSION['user_id']]);
                    }
                }
                $pdo->commit();
                $success = "Bulk attendance marked successfully!";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Failed to mark bulk attendance: " . $e->getMessage();
            }
        }
        
        header("Location: attendance.php?" . ($success ? "success=" . urlencode($success) : "error=" . urlencode($error)) . "&date=$date&class_id=$class_id&view=$view_type" . ($view_type == 'weekly' ? "&week=$week_offset" : ""));
        exit();
    }
}

// Get classes (teachers only see their classes)
if ($_SESSION['user_role'] == 'teacher') {
    $teacher_id = $_SESSION['user_id'];
    $classes = $pdo->prepare("
        SELECT DISTINCT c.* 
        FROM classes c 
        LEFT JOIN subjects s ON c.id = s.class_id 
        WHERE s.teacher_id = ? OR c.class_teacher_id = ?
        ORDER BY c.class_name
    ");
    $classes->execute([$teacher_id, $teacher_id]);
    $classes = $classes->fetchAll();
} else {
    $classes = $pdo->query("SELECT * FROM classes ORDER BY class_name")->fetchAll();
}

// Get students for selected class
$students = [];
$class_info = null;
if ($selected_class) {
    $students = $pdo->prepare("
        SELECT s.*, c.class_name 
        FROM students s 
        JOIN classes c ON s.class_id = c.id 
        WHERE s.class_id = ? AND s.status = 'active' 
        ORDER BY s.full_name
    ");
    $students->execute([$selected_class]);
    $students = $students->fetchAll();
    
    // Get class info
    $class_info = $pdo->prepare("SELECT * FROM classes WHERE id = ?");
    $class_info->execute([$selected_class]);
    $class_info = $class_info->fetch();
}

// Get existing attendance for selected date and class
$existing_attendance = [];
$attendance_remarks = [];
if ($selected_class && $selected_date && $view_type == 'daily') {
    $attendance_stmt = $pdo->prepare("
        SELECT student_id, status, remarks 
        FROM attendance 
        WHERE date = ? AND class_id = ?
    ");
    $attendance_stmt->execute([$selected_date, $selected_class]);
    $attendance_data = $attendance_stmt->fetchAll();
    foreach ($attendance_data as $data) {
        $existing_attendance[$data['student_id']] = $data['status'];
        $attendance_remarks[$data['student_id']] = $data['remarks'];
    }
}

// Get weekly attendance
$weekly_attendance = [];
if ($selected_class && $view_type == 'weekly') {
    foreach ($week_dates as $date) {
        $att_stmt = $pdo->prepare("
            SELECT student_id, status 
            FROM attendance 
            WHERE date = ? AND class_id = ?
        ");
        $att_stmt->execute([$date, $selected_class]);
        $att_data = $att_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $weekly_attendance[$date] = $att_data;
    }
}

// Get monthly attendance summary
$monthly_summary = [];
if ($selected_class && $view_type == 'monthly') {
    $month = date('Y-m', strtotime($selected_date));
    $summary_stmt = $pdo->prepare("
        SELECT s.id, s.full_name, s.Admission_number,
               COUNT(a.id) as total_days,
               SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present_days,
               SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) as absent_days,
               SUM(CASE WHEN a.status = 'Late' THEN 1 ELSE 0 END) as late_days
        FROM students s
        LEFT JOIN attendance a ON s.id = a.student_id AND DATE_FORMAT(a.date, '%Y-%m') = ?
        WHERE s.class_id = ? AND s.status = 'active'
        GROUP BY s.id, s.full_name, s.Admission_number
        ORDER BY s.full_name
    ");
    $summary_stmt->execute([$month, $selected_class]);
    $monthly_summary = $summary_stmt->fetchAll();
}

// Get attendance statistics
$attendance_stats = [
    'present' => 0,
    'absent' => 0,
    'late' => 0,
    'total' => 0
];

if ($selected_class && $selected_date && $view_type == 'daily') {
    foreach ($existing_attendance as $status) {
        $status_key = strtolower($status);
        if (isset($attendance_stats[$status_key])) {
            $attendance_stats[$status_key]++;
            $attendance_stats['total']++;
        }
    }
} elseif ($selected_class && $view_type == 'weekly') {
    // Calculate weekly stats
    $all_statuses = [];
    foreach ($weekly_attendance as $date => $att_data) {
        foreach ($att_data as $status) {
            $all_statuses[] = $status;
        }
    }
    foreach ($all_statuses as $status) {
        $status_key = strtolower($status);
        if (isset($attendance_stats[$status_key])) {
            $attendance_stats[$status_key]++;
            $attendance_stats['total']++;
        }
    }
}

// Get overall class statistics
$class_stats = [];
if ($selected_class) {
    $total_students = count($students);
    $total_classes = $pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn();
    $avg_class_size = $total_students > 0 ? $total_students : 0;
}

$page_title = "Attendance Management - " . SCHOOL_NAME;
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
        :root {
            --primary: #2c3e50;
            --primary-light: #34495e;
            --secondary: #3498db;
            --success: #27ae60;
            --success-light: #2ecc71;
            --danger: #e74c3c;
            --danger-light: #c0392b;
            --warning: #f39c12;
            --warning-light: #f1c40f;
            --info: #17a2b8;
            --purple: #9b59b6;
            --purple-light: #8e44ad;
            --dark: #2c3e50;
            --dark-light: #34495e;
            --gray: #7f8c8d;
            --gray-light: #95a5a6;
            --light: #ecf0f1;
            --white: #ffffff;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.1);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
            --shadow-xl: 0 20px 25px rgba(0,0,0,0.1);
            --border-radius: 12px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            min-height: calc(100vh - 70px);
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }

        /* Page Header */
        .page-header {
            background: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            border-left: 5px solid var(--secondary);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: var(--gray);
            font-size: 1rem;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary), var(--purple));
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), var(--success-light));
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), var(--warning-light));
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), var(--danger-light));
            color: white;
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info), #138496);
            color: white;
        }

        .btn-outline {
            background: var(--light);
            color: var(--dark);
            border: 2px solid transparent;
        }

        .btn-outline:hover {
            background: #d5dbdb;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        .btn-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .filter-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--dark);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--light);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--secondary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        /* View Tabs */
        .view-tabs {
            display: flex;
            background: var(--light);
            border-radius: 8px;
            padding: 0.3rem;
            margin-top: 1.5rem;
        }

        .view-tab {
            flex: 1;
            padding: 0.8rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            color: var(--gray);
            transition: all 0.3s;
            background: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .view-tab.active {
            background: white;
            color: var(--secondary);
            box-shadow: var(--shadow-sm);
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border-left: 4px solid;
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card.total { border-left-color: var(--secondary); }
        .stat-card.present { border-left-color: var(--success); }
        .stat-card.absent { border-left-color: var(--danger); }
        .stat-card.late { border-left-color: var(--warning); }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .stat-card.total .stat-icon { background: linear-gradient(135deg, var(--secondary), var(--purple)); }
        .stat-card.present .stat-icon { background: linear-gradient(135deg, var(--success), var(--success-light)); }
        .stat-card.absent .stat-icon { background: linear-gradient(135deg, var(--danger), var(--danger-light)); }
        .stat-card.late .stat-icon { background: linear-gradient(135deg, var(--warning), var(--warning-light)); }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Bulk Actions */
        .bulk-actions {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
            display: flex;
            align-items: center;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        .bulk-label {
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .bulk-status {
            padding: 0.75rem;
            border: 2px solid var(--light);
            border-radius: 8px;
            min-width: 200px;
        }

        /* Attendance Table */
        .attendance-table {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            margin-bottom: 2rem;
        }

        .table-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h3 {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            padding: 1.25rem 1rem;
            text-align: left;
            background: var(--light);
            color: var(--dark);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        td {
            padding: 1.25rem 1rem;
            border-bottom: 1px solid var(--light);
            color: var(--dark);
        }

        tr {
            transition: all 0.2s;
        }

        tr:hover {
            background: rgba(52, 152, 219, 0.05);
        }

        .student-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .student-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary), var(--purple));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .student-details {
            flex: 1;
        }

        .student-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.2rem;
        }

        .student-id {
            font-size: 0.8rem;
            color: var(--gray);
        }

        /* Attendance Radio Buttons */
        .attendance-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .attendance-radio {
            display: none;
        }

        .attendance-label {
            padding: 0.6rem 1rem;
            border: 2px solid var(--light);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
            font-size: 0.85rem;
            min-width: 90px;
            text-align: center;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .attendance-radio:checked + .attendance-label {
            border-color: transparent;
            color: white;
        }

        .attendance-radio[value="Present"]:checked + .attendance-label {
            background: linear-gradient(135deg, var(--success), var(--success-light));
        }

        .attendance-radio[value="Absent"]:checked + .attendance-label {
            background: linear-gradient(135deg, var(--danger), var(--danger-light));
        }

        .attendance-radio[value="Late"]:checked + .attendance-label {
            background: linear-gradient(135deg, var(--warning), var(--warning-light));
        }

        .attendance-label.present:hover {
            border-color: var(--success);
            color: var(--success);
        }

        .attendance-label.absent:hover {
            border-color: var(--danger);
            color: var(--danger);
        }

        .attendance-label.late:hover {
            border-color: var(--warning);
            color: var(--warning);
        }

        /* Remarks Input */
        .remarks-input {
            padding: 0.5rem;
            border: 2px solid var(--light);
            border-radius: 6px;
            font-size: 0.85rem;
            width: 100%;
            transition: all 0.3s;
        }

        .remarks-input:focus {
            border-color: var(--secondary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        /* Status Badge */
        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-present {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
        }

        .status-absent {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }

        .status-late {
            background: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }

        /* Weekly View */
        .weekly-grid {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            margin-bottom: 1.5rem;
        }

        .week-nav {
            display: flex;
            align-items: center;
            gap: 1rem;
            background: white;
            padding: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            margin-bottom: 1.5rem;
        }

        .week-date {
            font-weight: 600;
            color: var(--dark);
            font-size: 1.1rem;
        }

        .week-range {
            color: var(--secondary);
            font-size: 0.9rem;
        }

        .attendance-cell {
            text-align: center;
            font-weight: 600;
        }

        .attendance-cell.present {
            color: var(--success);
        }

        .attendance-cell.absent {
            color: var(--danger);
        }

        .attendance-cell.late {
            color: var(--warning);
        }

        /* Monthly View */
        .monthly-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .monthly-student-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border-left: 4px solid var(--secondary);
            transition: all 0.3s;
        }

        .monthly-student-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .student-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--light);
        }

        .student-name {
            font-weight: 600;
            color: var(--dark);
            font-size: 1.1rem;
        }

        .student-id {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .attendance-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .stat-box {
            text-align: center;
            padding: 1rem;
            border-radius: 8px;
            background: var(--light);
        }

        .stat-box.present .stat-value { color: var(--success); }
        .stat-box.absent .stat-value { color: var(--danger); }
        .stat-box.late .stat-value { color: var(--warning); }
        .stat-box.total .stat-value { color: var(--secondary); }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.2rem;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--gray);
            text-transform: uppercase;
        }

        .attendance-percentage {
            text-align: center;
            padding: 1rem;
            background: linear-gradient(135deg, var(--secondary), var(--purple));
            border-radius: 8px;
            color: white;
        }

        .percentage-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.2rem;
        }

        /* Alert */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background: rgba(39, 174, 96, 0.1);
            border-color: var(--success);
            color: var(--success);
        }

        .alert-danger {
            background: rgba(231, 76, 60, 0.1);
            border-color: var(--danger);
            color: var(--danger);
        }

        /* No Data */
        .no-data {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            border: 2px dashed var(--light);
        }

        .no-data i {
            font-size: 4rem;
            color: var(--gray-light);
            margin-bottom: 1.5rem;
        }

        .no-data h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .no-data p {
            color: var(--gray);
            margin-bottom: 2rem;
        }

        /* Loading Spinner */
        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--light);
            border-top-color: var(--secondary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Animations */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate {
            animation: slideIn 0.5s ease-out;
        }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 0.5rem;
            margin-left: auto;
        }

        .quick-action-btn {
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.2s;
            color: white;
        }

        .quick-action-btn.present { background: var(--success); }
        .quick-action-btn.absent { background: var(--danger); }
        .quick-action-btn.late { background: var(--warning); }

        .quick-action-btn:hover {
            transform: translateY(-2px);
            filter: brightness(1.1);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .bulk-actions {
                flex-direction: column;
                align-items: stretch;
            }
            
            .attendance-actions {
                flex-direction: column;
            }
            
            .attendance-label {
                width: 100%;
            }
            
            .student-info {
                flex-direction: column;
                text-align: center;
            }
            
            .weekly-grid {
                flex-wrap: wrap;
            }
            
            .monthly-grid {
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
        <!-- Page Header -->
        <div class="page-header animate">
            <div>
                <h1><i class="fas fa-calendar-check" style="color: var(--secondary); margin-right: 0.5rem;"></i>Attendance Management</h1>
                <p>Track and manage student attendance records</p>
            </div>
            <div class="header-actions">
                <?php if ($selected_class): ?>
                <div class="btn-group">
                    <button class="btn btn-outline" onclick="exportAttendance()" title="Export as CSV">
                        <i class="fas fa-download"></i> Export
                    </button>
                    <button class="btn btn-outline" onclick="printAttendance()" title="Print">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success animate">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['success']); ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger animate">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_GET['error']); ?>
        </div>
        <?php endif; ?>

        <!-- Filter Section -->
        <div class="filter-section animate">
            <div class="filter-header">
                <h3><i class="fas fa-filter" style="color: var(--secondary);"></i> Filter Attendance</h3>
            </div>
            <form method="GET" id="attendanceFilter">
                <div class="filter-grid">
                    <div class="form-group">
                        <label class="required">Class</label>
                        <select id="class_id" name="class_id" required onchange="this.form.submit()">
                            <option value="">Select Class</option>
                            <?php foreach($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $selected_class == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Date / Month</label>
                        <input type="date" id="date" name="date" value="<?php echo $selected_date; ?>" onchange="this.form.submit()">
                    </div>
                    <input type="hidden" name="view" id="view_input" value="<?php echo $view_type; ?>">
                    <input type="hidden" name="week" id="week_input" value="<?php echo $week_offset; ?>">
                </div>

                <!-- View Tabs -->
                <div class="view-tabs">
                    <button type="button" class="view-tab <?php echo $view_type == 'daily' ? 'active' : ''; ?>" onclick="changeView('daily')">
                        <i class="fas fa-calendar-day"></i> Daily
                    </button>
                    <button type="button" class="view-tab <?php echo $view_type == 'weekly' ? 'active' : ''; ?>" onclick="changeView('weekly')">
                        <i class="fas fa-calendar-week"></i> Weekly
                    </button>
                    <button type="button" class="view-tab <?php echo $view_type == 'monthly' ? 'active' : ''; ?>" onclick="changeView('monthly')">
                        <i class="fas fa-calendar-alt"></i> Monthly
                    </button>
                    <button type="button" class="view-tab <?php echo $view_type == 'summary' ? 'active' : ''; ?>" onclick="changeView('summary')">
                        <i class="fas fa-chart-bar"></i> Reports
                    </button>
                </div>
            </form>
        </div>

        <?php if ($selected_class): ?>
            <!-- Statistics Cards -->
            <div class="stats-grid animate">
                <div class="stat-card total">
                    <div class="stat-header">
                        <span class="stat-label">Total Students</span>
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                    </div>
                    <div class="stat-value"><?php echo count($students); ?></div>
                    <div class="stat-label">Enrolled in class</div>
                </div>
                <div class="stat-card present">
                    <div class="stat-header">
                        <span class="stat-label">Present</span>
                        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    </div>
                    <div class="stat-value"><?php echo $attendance_stats['present']; ?></div>
                    <div class="stat-label"><?php echo $attendance_stats['total'] > 0 ? round(($attendance_stats['present'] / $attendance_stats['total']) * 100, 1) : 0; ?>% of marked</div>
                </div>
                <div class="stat-card absent">
                    <div class="stat-header">
                        <span class="stat-label">Absent</span>
                        <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                    </div>
                    <div class="stat-value"><?php echo $attendance_stats['absent']; ?></div>
                    <div class="stat-label"><?php echo $attendance_stats['total'] > 0 ? round(($attendance_stats['absent'] / $attendance_stats['total']) * 100, 1) : 0; ?>% of marked</div>
                </div>
                <div class="stat-card late">
                    <div class="stat-header">
                        <span class="stat-label">Late</span>
                        <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    </div>
                    <div class="stat-value"><?php echo $attendance_stats['late']; ?></div>
                    <div class="stat-label"><?php echo $attendance_stats['total'] > 0 ? round(($attendance_stats['late'] / $attendance_stats['total']) * 100, 1) : 0; ?>% of marked</div>
                </div>
            </div>

            <?php if ($view_type == 'daily'): ?>
            <!-- Daily Attendance View -->
            <form method="POST" id="attendanceForm">
                <input type="hidden" name="class_id" value="<?php echo $selected_class; ?>">
                <input type="hidden" name="date" value="<?php echo $selected_date; ?>">
                
                <!-- Bulk Actions -->
                <div class="bulk-actions animate">
                    <div class="bulk-label">
                        <i class="fas fa-bolt" style="color: var(--warning);"></i>
                        Bulk Actions:
                    </div>
                    <select name="bulk_status" class="bulk-status">
                        <option value="">Select Status</option>
                        <option value="Present">Mark All Present</option>
                        <option value="Absent">Mark All Absent</option>
                        <option value="Late">Mark All Late</option>
                    </select>
                    <button type="submit" name="bulk_attendance" class="btn btn-warning btn-sm" onclick="return confirmBulkAction()">
                        <i class="fas fa-bolt"></i> Apply to All
                    </button>
                    <div style="flex: 1;"></div>
                    <button type="submit" name="mark_attendance" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Attendance
                    </button>
                </div>

                <!-- Attendance Table -->
                <div class="attendance-table animate">
                    <div class="table-header">
                        <h3>
                            <i class="fas fa-calendar-day"></i>
                            Daily Attendance - <?php echo date('l, F j, Y', strtotime($selected_date)); ?>
                        </h3>
                        <div>
                            <span class="badge">Class: <?php echo htmlspecialchars($class_info['class_name']); ?></span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Student ID</th>
                                    <th>Attendance Status</th>
                                    <th>Remarks</th>
                                    <th>Quick Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($students as $student): 
                                    $current_status = $existing_attendance[$student['id']] ?? '';
                                    $initials = strtoupper(substr($student['full_name'], 0, 1));
                                ?>
                                <tr>
                                    <td>
                                        <div class="student-info">
                                            <div class="student-avatar"><?php echo $initials; ?></div>
                                            <div class="student-details">
                                                <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                                <div class="student-id"><?php echo htmlspecialchars($student['class_name']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($student['Admission_number']); ?></td>
                                    <td>
                                        <div class="attendance-actions">
                                            <input type="radio" name="attendance[<?php echo $student['id']; ?>]" value="Present" 
                                                   id="present_<?php echo $student['id']; ?>" 
                                                   <?php echo $current_status == 'Present' ? 'checked' : ''; ?>
                                                   class="attendance-radio">
                                            <label for="present_<?php echo $student['id']; ?>" class="attendance-label present">
                                                <i class="fas fa-check"></i> Present
                                            </label>
                                            
                                            <input type="radio" name="attendance[<?php echo $student['id']; ?>]" value="Absent" 
                                                   id="absent_<?php echo $student['id']; ?>"
                                                   <?php echo $current_status == 'Absent' ? 'checked' : ''; ?>
                                                   class="attendance-radio">
                                            <label for="absent_<?php echo $student['id']; ?>" class="attendance-label absent">
                                                <i class="fas fa-times"></i> Absent
                                            </label>
                                            
                                            <input type="radio" name="attendance[<?php echo $student['id']; ?>]" value="Late" 
                                                   id="late_<?php echo $student['id']; ?>"
                                                   <?php echo $current_status == 'Late' ? 'checked' : ''; ?>
                                                   class="attendance-radio">
                                            <label for="late_<?php echo $student['id']; ?>" class="attendance-label late">
                                                <i class="fas fa-clock"></i> Late
                                            </label>
                                        </div>
                                    </td>
                                    <td>
                                        <input type="text" name="remarks[<?php echo $student['id']; ?>]" 
                                               class="remarks-input" 
                                               placeholder="Optional remarks"
                                               value="<?php echo htmlspecialchars($attendance_remarks[$student['id']] ?? ''); ?>">
                                    </td>
                                    <td>
                                        <div class="quick-actions">
                                            <button type="button" class="quick-action-btn present" onclick="setStatus(<?php echo $student['id']; ?>, 'Present')">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button type="button" class="quick-action-btn absent" onclick="setStatus(<?php echo $student['id']; ?>, 'Absent')">
                                                <i class="fas fa-times"></i>
                                            </button>
                                            <button type="button" class="quick-action-btn late" onclick="setStatus(<?php echo $student['id']; ?>, 'Late')">
                                                <i class="fas fa-clock"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>

            <?php elseif ($view_type == 'weekly'): ?>
            <!-- Weekly Attendance View -->
            <div class="week-nav animate">
                <button class="btn btn-outline btn-sm" onclick="changeWeek(<?php echo $week_offset - 1; ?>)">
                    <i class="fas fa-chevron-left"></i> Previous Week
                </button>
                <div>
                    <div class="week-date">
                        <?php echo date('M j', strtotime($week_dates[0])); ?> - <?php echo date('M j, Y', strtotime($week_dates[4])); ?>
                    </div>
                    <div class="week-range">Week <?php echo $week_offset + 1; ?></div>
                </div>
                <button class="btn btn-outline btn-sm" onclick="changeWeek(<?php echo $week_offset + 1; ?>)">
                    Next Week <i class="fas fa-chevron-right"></i>
                </button>
            </div>

            <form method="POST" id="weeklyAttendanceForm">
                <input type="hidden" name="class_id" value="<?php echo $selected_class; ?>">
                <input type="hidden" name="date" value="<?php echo $selected_date; ?>">
                
                <div class="attendance-table animate">
                    <div class="table-header">
                        <h3>
                            <i class="fas fa-calendar-week"></i>
                            Weekly Attendance - <?php echo date('M j', strtotime($week_dates[0])); ?> to <?php echo date('M j, Y', strtotime($week_dates[4])); ?>
                        </h3>
                        <div>
                            <span class="badge">Class: <?php echo htmlspecialchars($class_info['class_name']); ?></span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>ID</th>
                                    <?php foreach($week_dates as $date): ?>
                                    <th><?php echo date('D', strtotime($date)); ?><br><small><?php echo date('m/d', strtotime($date)); ?></small></th>
                                    <?php endforeach; ?>
                                    <th>Present %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($students as $student): 
                                    $initials = strtoupper(substr($student['full_name'], 0, 1));
                                    $present_count = 0;
                                    $total_days = 0;
                                ?>
                                <tr>
                                    <td>
                                        <div class="student-info">
                                            <div class="student-avatar"><?php echo $initials; ?></div>
                                            <div><?php echo htmlspecialchars($student['full_name']); ?></div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($student['Admission_number']); ?></td>
                                    <?php foreach($week_dates as $date): 
                                        $status = $weekly_attendance[$date][$student['id']] ?? '';
                                        if ($status == 'Present') {
                                            $present_count += 1;
                                            $total_days++;
                                        } elseif ($status == 'Late') {
                                            $present_count += 0.5;
                                            $total_days++;
                                        } elseif ($status == 'Absent') {
                                            $total_days++;
                                        }
                                    ?>
                                    <td class="attendance-cell <?php echo strtolower($status); ?>">
                                        <?php if ($status): ?>
                                            <span class="status-badge status-<?php echo strtolower($status); ?>">
                                                <?php echo substr($status, 0, 1); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--gray);">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endforeach; ?>
                                    <td>
                                        <strong><?php echo $total_days > 0 ? round(($present_count / $total_days) * 100, 1) : 0; ?>%</strong>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>

            <?php elseif ($view_type == 'monthly' && !empty($monthly_summary)): ?>
            <!-- Monthly Summary View -->
            <div class="monthly-grid animate">
                <?php foreach($monthly_summary as $student): 
                    $attendance_rate = $student['total_days'] > 0 ? round(($student['present_days'] / $student['total_days']) * 100, 1) : 0;
                    $initials = strtoupper(substr($student['full_name'], 0, 1));
                ?>
                <div class="monthly-student-card">
                    <div class="student-header">
                        <div>
                            <div class="student-name">
                                <i class="fas fa-user-graduate"></i> <?php echo htmlspecialchars($student['full_name']); ?>
                            </div>
                            <div class="student-id">ID: <?php echo htmlspecialchars($student['Admission_number']); ?></div>
                        </div>
                        <div class="student-avatar"><?php echo $initials; ?></div>
                    </div>
                    
                    <div class="attendance-stats">
                        <div class="stat-box present">
                            <div class="stat-value"><?php echo $student['present_days']; ?></div>
                            <div class="stat-label">Present</div>
                        </div>
                        <div class="stat-box absent">
                            <div class="stat-value"><?php echo $student['absent_days']; ?></div>
                            <div class="stat-label">Absent</div>
                        </div>
                        <div class="stat-box late">
                            <div class="stat-value"><?php echo $student['late_days']; ?></div>
                            <div class="stat-label">Late</div>
                        </div>
                        <div class="stat-box total">
                            <div class="stat-value"><?php echo $student['total_days']; ?></div>
                            <div class="stat-label">Total Days</div>
                        </div>
                    </div>
                    
                    <div class="attendance-percentage">
                        <div class="percentage-value"><?php echo $attendance_rate; ?>%</div>
                        <div class="percentage-label">Attendance Rate</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php elseif ($view_type == 'summary'): ?>
            <!-- Reports View -->
            <div class="no-data animate">
                <i class="fas fa-chart-bar"></i>
                <h3>Attendance Reports</h3>
                <p>Generate comprehensive attendance reports and analytics.</p>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; max-width: 600px; margin: 2rem auto;">
                    <button class="btn btn-primary" onclick="generateReport('daily')">
                        <i class="fas fa-calendar-day"></i> Daily Report
                    </button>
                    <button class="btn btn-success" onclick="generateReport('weekly')">
                        <i class="fas fa-calendar-week"></i> Weekly Report
                    </button>
                    <button class="btn btn-warning" onclick="generateReport('monthly')">
                        <i class="fas fa-calendar-alt"></i> Monthly Report
                    </button>
                </div>
            </div>

            <?php elseif (empty($students)): ?>
            <!-- No Students -->
            <div class="no-data animate">
                <i class="fas fa-users-slash"></i>
                <h3>No Students Found</h3>
                <p>There are no active students in this class.</p>
            </div>
            <?php endif; ?>

        <?php else: ?>
        <!-- No Class Selected -->
        <div class="no-data animate">
            <i class="fas fa-door-open"></i>
            <h3>Select a Class</h3>
            <p>Please select a class to view and manage attendance.</p>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Change view type
        function changeView(viewType) {
            document.getElementById('view_input').value = viewType;
            document.getElementById('attendanceFilter').submit();
        }

        // Change week
        function changeWeek(weekOffset) {
            document.getElementById('week_input').value = weekOffset;
            document.getElementById('view_input').value = 'weekly';
            document.getElementById('attendanceFilter').submit();
        }

        // Set status for a student
        function setStatus(studentId, status) {
            const radio = document.getElementById(`${status.toLowerCase()}_${studentId}`);
            if (radio) {
                radio.checked = true;
                showNotification(`${status} status set`, 'success');
            }
        }

        // Confirm bulk action
        function confirmBulkAction() {
            const status = document.querySelector('.bulk-status').value;
            if (!status) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No Status Selected',
                    text: 'Please select a status for the bulk action.'
                });
                return false;
            }
            
            Swal.fire({
                title: 'Apply to All Students?',
                text: `All students will be marked as ${status}. This will override existing attendance.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: 'var(--success)',
                cancelButtonColor: 'var(--gray)',
                confirmButtonText: 'Yes, apply'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.querySelector('button[name="bulk_attendance"]').closest('form').submit();
                }
            });
            
            return false;
        }

        // Export attendance
        function exportAttendance() {
            const classId = document.getElementById('class_id').value;
            const date = document.getElementById('date').value;
            const view = document.getElementById('view_input').value;
            const weekOffset = document.getElementById('week_input').value;
            
            if (!classId) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Select Class',
                    text: 'Please select a class first'
                });
                return;
            }
            
            let url = `attendance.php?export=csv&class_id=${classId}&date=${date}&view=${view}`;
            if (view === 'weekly') {
                const weekStart = '<?php echo $monday; ?>';
                url += `&week_start=${weekStart}`;
            }
            
            window.location.href = url;
        }

        // Print attendance
        function printAttendance() {
            window.print();
        }

        // Generate report
        function generateReport(type) {
            Swal.fire({
                title: 'Generating Report...',
                text: `Preparing ${type} attendance report`,
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // Simulate report generation
            setTimeout(() => {
                Swal.fire({
                    icon: 'success',
                    title: 'Report Ready',
                    text: `The ${type} attendance report has been generated successfully.`,
                    showConfirmButton: true
                });
            }, 2000);
        }

        // Show notification
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 100px;
                right: 20px;
                background: ${type === 'success' ? 'var(--success)' : 'var(--danger)'};
                color: white;
                padding: 1rem 1.5rem;
                border-radius: 8px;
                box-shadow: var(--shadow-lg);
                z-index: 10000;
                animation: slideInRight 0.3s ease;
            `;
            notification.innerHTML = `<i class="fas fa-${type === 'success' ? 'check' : 'exclamation'}"></i> ${message}`;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                const saveBtn = document.querySelector('button[name="mark_attendance"]');
                if (saveBtn) {
                    saveBtn.click();
                }
            }
            
            // Ctrl + P to print
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                printAttendance();
            }
            
            // Ctrl + E to export
            if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
                e.preventDefault();
                exportAttendance();
            }
        });

        // Auto-save indicator
        let autoSaveTimeout;
        document.querySelectorAll('.attendance-radio').forEach(radio => {
            radio.addEventListener('change', function() {
                clearTimeout(autoSaveTimeout);
                autoSaveTimeout = setTimeout(() => {
                    const saveBtn = document.querySelector('button[name="mark_attendance"]');
                    if (saveBtn) {
                        const originalText = saveBtn.innerHTML;
                        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                        saveBtn.disabled = true;
                        
                        setTimeout(() => {
                            saveBtn.innerHTML = originalText;
                            saveBtn.disabled = false;
                            showNotification('Attendance updated automatically', 'success');
                        }, 1000);
                    }
                }, 2000);
            });
        });

        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            // Set today's date if not set
            const dateInput = document.getElementById('date');
            if (dateInput && !dateInput.value) {
                const today = new Date().toISOString().split('T')[0];
                dateInput.value = today;
            }
        });
    </script>
</body>
</html>