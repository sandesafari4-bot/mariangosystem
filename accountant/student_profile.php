<?php
include '../config.php';
checkAuth();
checkRole(['admin', 'accountant', 'teacher']);

// Get student ID from URL
$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$student_id) {
    $_SESSION['error'] = 'Invalid student ID';
    header('Location: students.php');
    exit();
}

// Get student details with comprehensive information
$stmt = $pdo->prepare("
    SELECT 
        s.*,
        c.id as class_id,
        c.class_name,
        c.class_teacher_id,
        ct.full_name as class_teacher_name,
        COALESCE(p_id.full_name, p_name.full_name, s.parent_name) as parent_name,
        COALESCE(p_id.phone, p_name.phone, s.parent_phone) as parent_phone,
        COALESCE(p_id.email, p_name.email) as parent_email,
        COALESCE(p_id.occupation, p_name.occupation) as parent_occupation,
        (SELECT COUNT(*) FROM invoices WHERE student_id = s.id) as total_invoices,
        (SELECT COUNT(*) FROM invoices WHERE student_id = s.id AND status = 'paid') as paid_invoices,
        (SELECT COUNT(*) FROM invoices WHERE student_id = s.id AND status = 'unpaid') as unpaid_invoices,
        (SELECT COUNT(*) FROM invoices WHERE student_id = s.id AND status = 'partial') as partial_invoices,
        (SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE student_id = s.id) as total_invoiced,
        (SELECT COALESCE(SUM(amount_paid), 0) FROM invoices WHERE student_id = s.id) as total_paid,
        (SELECT COALESCE(SUM(balance), 0) FROM invoices WHERE student_id = s.id) as current_balance,
        (SELECT COUNT(*) FROM payments WHERE student_id = s.id) as total_payments,
        (SELECT MAX(payment_date) FROM payments WHERE student_id = s.id) as last_payment_date,
        (SELECT COUNT(*) FROM attendance WHERE student_id = s.id AND status = 'Present') as total_present,
        (SELECT COUNT(*) FROM attendance WHERE student_id = s.id) as total_attendance,
        (SELECT COUNT(*) FROM student_notes WHERE student_id = s.id) as total_notes,
        (SELECT COUNT(*) FROM fee_assignments WHERE student_id = s.id) as total_fee_assignments
    FROM students s
    LEFT JOIN classes c ON s.class_id = c.id
    LEFT JOIN users ct ON c.class_teacher_id = ct.id
    LEFT JOIN parents p_id ON s.parent_id = p_id.id
    LEFT JOIN parents p_name ON s.parent_id IS NULL AND TRIM(COALESCE(s.parent_name, '')) <> '' AND p_name.full_name = s.parent_name
    WHERE s.id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    $_SESSION['error'] = 'Student not found';
    header('Location: students.php');
    exit();
}

// Calculate attendance rate
$attendance_rate = $student['total_attendance'] > 0 
    ? round(($student['total_present'] / $student['total_attendance']) * 100, 1) 
    : 0;

// Get recent invoices
$invoices = $pdo->prepare("
    SELECT 
        i.*,
        fs.structure_name,
        DATEDIFF(CURDATE(), i.due_date) as days_overdue,
        (SELECT COUNT(*) FROM payments WHERE invoice_id = i.id) as payment_count
    FROM invoices i
    LEFT JOIN fee_structures fs ON i.fee_structure_id = fs.id
    WHERE i.student_id = ?
    ORDER BY i.created_at DESC
    LIMIT 10
");
$invoices->execute([$student_id]);
$recent_invoices = $invoices->fetchAll();

// Get recent payments
$payments = $pdo->prepare("
    SELECT 
        p.*,
        pm.label as payment_method_label,
        i.invoice_no,
        u.full_name as recorded_by_name
    FROM payments p
    LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
    LEFT JOIN invoices i ON p.invoice_id = i.id
    LEFT JOIN users u ON p.recorded_by = u.id
    WHERE p.student_id = ?
    ORDER BY p.payment_date DESC
    LIMIT 10
");
$payments->execute([$student_id]);
$recent_payments = $payments->fetchAll();

// Get attendance history
$attendance = $pdo->prepare("
    SELECT 
        a.*,
        u.full_name as recorded_by_name
    FROM attendance a
    LEFT JOIN users u ON a.recorded_by = u.id
    WHERE a.student_id = ?
    ORDER BY a.date DESC
    LIMIT 30
");
$attendance->execute([$student_id]);
$attendance_history = $attendance->fetchAll();

// Get teacher notes
$notes = $pdo->prepare("
    SELECT 
        n.*,
        u.full_name as teacher_name,
        u.role as teacher_role
    FROM student_notes n
    LEFT JOIN users u ON n.teacher_id = u.id
    WHERE n.student_id = ?
    ORDER BY n.created_at DESC
");
$notes->execute([$student_id]);
$student_notes = $notes->fetchAll();

// Get fee assignments
$assignments = $pdo->prepare("
    SELECT 
        fa.*,
        f.fee_name,
        ft.name as fee_type_name,
        u.full_name as assigned_by_name
    FROM fee_assignments fa
    LEFT JOIN fees f ON fa.fee_id = f.id
    LEFT JOIN fee_types ft ON f.fee_type_id = ft.id
    LEFT JOIN users u ON fa.assigned_by = u.id
    WHERE fa.student_id = ?
    ORDER BY fa.assigned_at DESC
");
$assignments->execute([$student_id]);
$fee_assignments = $assignments->fetchAll();

// Get class subjects and teachers
$subjects = $pdo->prepare("
    SELECT 
        s.subject_name,
        s.code as subject_code,
        u.full_name as teacher_name
    FROM subjects s
    LEFT JOIN users u ON s.teacher_id = u.id
    WHERE s.class_id = ?
    ORDER BY s.subject_name
");
$subjects->execute([$student['class_id']]);
$class_subjects = $subjects->fetchAll();

// Get academic history (grades)
$grades = $pdo->prepare("
    SELECT 
        g.*,
        s.subject_name,
        ay.year as academic_year
    FROM grades g
    LEFT JOIN subjects s ON g.subject_id = s.id
    LEFT JOIN academic_years ay ON g.academic_year = ay.id
    WHERE g.student_id = ?
    ORDER BY g.created_at DESC
    LIMIT 20
");
$grades->execute([$student_id]);
$grade_history = $grades->fetchAll();

// Calculate grade averages
$grade_averages = [];
foreach ($grade_history as $grade) {
    if (!isset($grade_averages[$grade['subject_name']])) {
        $grade_averages[$grade['subject_name']] = ['total' => 0, 'count' => 0];
    }
    $grade_averages[$grade['subject_name']]['total'] += $grade['marks'];
    $grade_averages[$grade['subject_name']]['count']++;
}
foreach ($grade_averages as $subject => &$data) {
    $data['average'] = round($data['total'] / $data['count'], 1);
}

// Handle POST requests (add note, etc.)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['add_note'])) {
            $note = trim($_POST['note']);
            $note_type = $_POST['note_type'];
            
            if (empty($note)) {
                throw new Exception('Note cannot be empty');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO student_notes (student_id, teacher_id, note, note_type, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$student_id, $_SESSION['user_id'], $note, $note_type]);
            
            $_SESSION['success'] = 'Note added successfully!';
            
        } elseif (isset($_POST['update_status'])) {
            $new_status = $_POST['status'];
            
            $stmt = $pdo->prepare("UPDATE students SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $student_id]);
            
            $_SESSION['success'] = 'Student status updated successfully!';
            
        } elseif (isset($_POST['delete_note'])) {
            $note_id = intval($_POST['note_id']);
            
            $stmt = $pdo->prepare("DELETE FROM student_notes WHERE id = ? AND student_id = ?");
            $stmt->execute([$note_id, $student_id]);
            
            $_SESSION['success'] = 'Note deleted successfully!';
        }
        
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }
    
    header("Location: student_profile.php?id=" . $student_id);
    exit();
}

$page_title = $student['full_name'] . ' - Student Profile - ' . SCHOOL_NAME;
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
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --primary-light: #4895ef;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --success-dark: #3aa8d8;
            --info: #4895ef;
            --warning: #f8961e;
            --warning-dark: #e07c1a;
            --danger: #f94144;
            --danger-dark: #d93235;
            --purple: #7209b7;
            --purple-light: #9b59b6;
            --dark: #2b2d42;
            --dark-light: #34495e;
            --gray: #6c757d;
            --gray-light: #95a5a6;
            --light: #f8f9fa;
            --white: #ffffff;
            --gradient-1: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-2: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --gradient-3: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --gradient-4: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            --gradient-5: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 24px rgba(0,0,0,0.12);
            --shadow-xl: 0 20px 40px rgba(0,0,0,0.15);
            --border-radius-sm: 8px;
            --border-radius-md: 12px;
            --border-radius-lg: 16px;
            --border-radius-xl: 24px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            min-height: calc(100vh - 70px);
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            transition: var(--transition);
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        ::-webkit-scrollbar-track {
            background: var(--light);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--gradient-1);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
        }

        /* Back Button */
        .back-button {
            margin-bottom: 1.5rem;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--white);
            color: var(--dark);
            text-decoration: none;
            border-radius: var(--border-radius-md);
            font-weight: 600;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
        }

        .back-btn:hover {
            transform: translateX(-5px);
            box-shadow: var(--shadow-lg);
        }

        /* Profile Header */
        .profile-header {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
        }

        .profile-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: var(--gradient-1);
        }

        .profile-header-content {
            display: flex;
            gap: 2rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: var(--gradient-1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            font-weight: 600;
            box-shadow: var(--shadow-lg);
        }

        .profile-info {
            flex: 1;
        }

        .profile-name {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .status-badge {
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-active {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .status-inactive {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        .profile-meta {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray);
        }

        .meta-item i {
            width: 20px;
            color: var(--primary);
        }

        .profile-actions {
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
            margin-left: auto;
        }

        .action-btn {
            padding: 0.6rem 1.2rem;
            border-radius: var(--border-radius-md);
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
            border: none;
            cursor: pointer;
        }

        .action-btn.primary {
            background: var(--gradient-1);
            color: white;
        }

        .action-btn.success {
            background: var(--gradient-3);
            color: white;
        }

        .action-btn.warning {
            background: var(--gradient-5);
            color: white;
        }

        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            display: flex;
            align-items: center;
            gap: 1.5rem;
            transition: var(--transition);
            border-left: 4px solid;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card.financial { border-left-color: var(--success); }
        .stat-card.academic { border-left-color: var(--primary); }
        .stat-card.attendance { border-left-color: var(--warning); }
        .stat-card.behavior { border-left-color: var(--purple); }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: white;
        }

        .stat-card.financial .stat-icon { background: var(--gradient-3); }
        .stat-card.academic .stat-icon { background: var(--gradient-1); }
        .stat-card.attendance .stat-icon { background: var(--gradient-5); }
        .stat-card.behavior .stat-icon { background: var(--gradient-2); }

        .stat-content {
            flex: 1;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-trend {
            font-size: 0.8rem;
            margin-top: 0.3rem;
        }

        /* Tabs */
        .tabs {
            display: flex;
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow-x: auto;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .tab {
            padding: 1rem 2rem;
            cursor: pointer;
            font-weight: 600;
            color: var(--gray);
            transition: var(--transition);
            border-bottom: 3px solid transparent;
            white-space: nowrap;
        }

        .tab:hover {
            color: var(--primary);
            background: rgba(67, 97, 238, 0.05);
        }

        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: rgba(67, 97, 238, 0.05);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Section Cards */
        .section-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .section-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 2px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--dark);
            font-size: 1.2rem;
            font-weight: 600;
        }

        .section-header h3 i {
            color: var(--primary);
        }

        .section-body {
            padding: 1.5rem;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .info-group {
            background: var(--light);
            border-radius: var(--border-radius-md);
            padding: 1.2rem;
        }

        .info-title {
            font-size: 0.9rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 1rem;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px dashed var(--gray-light);
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: var(--dark);
            font-weight: 500;
        }

        .info-value {
            font-weight: 600;
            color: var(--primary);
        }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            padding: 1rem;
            text-align: left;
            background: var(--light);
            color: var(--dark);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid var(--light);
            color: var(--dark);
        }

        tr:hover {
            background: rgba(67, 97, 238, 0.05);
        }

        /* Invoice Status Badges */
        .invoice-status {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-paid {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .status-unpaid {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        .status-partial {
            background: rgba(114, 9, 183, 0.15);
            color: var(--purple);
        }

        .status-overdue {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        /* Attendance Badges */
        .attendance-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .attendance-present {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .attendance-absent {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        .attendance-late {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        /* Notes */
        .notes-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .note-item {
            background: var(--light);
            border-radius: var(--border-radius-md);
            padding: 1.2rem;
            border-left: 4px solid var(--primary);
        }

        .note-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.8rem;
        }

        .note-type {
            padding: 0.2rem 0.8rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--primary);
            color: white;
        }

        .note-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--gray);
        }

        .note-content {
            color: var(--dark);
            line-height: 1.6;
            margin-bottom: 0.5rem;
        }

        .note-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
            color: var(--gray);
        }

        .btn-delete-note {
            background: rgba(249, 65, 68, 0.1);
            color: var(--danger);
            border: none;
            padding: 0.3rem 0.8rem;
            border-radius: 4px;
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-delete-note:hover {
            background: var(--danger);
            color: white;
        }

        /* Progress Bars */
        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--light);
            border-radius: 4px;
            overflow: hidden;
            margin: 0.5rem 0;
        }

        .progress-fill {
            height: 100%;
            background: var(--gradient-3);
            border-radius: 4px;
            transition: width 0.3s;
        }

        /* Subjects List */
        .subjects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }

        .subject-card {
            background: var(--light);
            border-radius: var(--border-radius-md);
            padding: 1rem;
            text-align: center;
        }

        .subject-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.3rem;
        }

        .subject-teacher {
            font-size: 0.8rem;
            color: var(--gray);
        }

        /* Grade Averages */
        .averages-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .average-card {
            background: var(--light);
            border-radius: var(--border-radius-md);
            padding: 1rem;
            text-align: center;
        }

        .average-subject {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.3rem;
        }

        .average-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }

        /* Fee Assignment Items */
        .assignment-item {
            background: var(--light);
            border-radius: var(--border-radius-md);
            padding: 1rem;
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .assignment-details {
            flex: 1;
        }

        .assignment-name {
            font-weight: 600;
            color: var(--dark);
        }

        .assignment-meta {
            font-size: 0.8rem;
            color: var(--gray);
        }

        .assignment-amount {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--danger);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
            z-index: 1050;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease;
            box-shadow: var(--shadow-xl);
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 2px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.05) 0%, rgba(63, 55, 201, 0.05) 100%);
        }

        .modal-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--dark);
            font-size: 1.2rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
            transition: var(--transition);
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .modal-close:hover {
            background: var(--light);
            color: var(--danger);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 2px solid var(--light);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-sm);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .profile-header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-name {
                justify-content: center;
            }
            
            .profile-meta {
                justify-content: center;
            }
            
            .profile-actions {
                margin-left: 0;
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-wrap: wrap;
            }
            
            .tab {
                flex: 1;
                text-align: center;
                padding: 1rem;
            }
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate {
            animation: fadeInUp 0.6s ease-out;
        }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        <!-- Back Button -->
        <div class="back-button animate">
            <a href="students.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Students
            </a>
        </div>

        <!-- Profile Header -->
        <div class="profile-header animate">
            <div class="profile-header-content">
                <div class="profile-avatar">
                    <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
                </div>
                
                <div class="profile-info">
                    <div class="profile-name">
                        <?php echo htmlspecialchars($student['full_name']); ?>
                        <span class="status-badge status-<?php echo $student['status']; ?>">
                            <?php echo ucfirst($student['status']); ?>
                        </span>
                    </div>
                    
                    <div class="profile-meta">
                        <span class="meta-item">
                            <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($student['Admission_number']); ?>
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($student['class_name']); ?>
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-calendar-alt"></i> Joined: <?php echo date('M Y', strtotime($student['admission_date'])); ?>
                        </span>
                    </div>
                    
                    <?php if ($student['class_teacher_name']): ?>
                    <div class="meta-item">
                        <i class="fas fa-user-tie"></i> Class Teacher: <?php echo htmlspecialchars($student['class_teacher_name']); ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="profile-actions">
                    <button class="action-btn success" onclick="recordPayment(<?php echo $student['id']; ?>)">
                        <i class="fas fa-credit-card"></i> Record Payment
                    </button>
                    <button class="action-btn primary" onclick="addNote()">
                        <i class="fas fa-sticky-note"></i> Add Note
                    </button>
                    <?php if (($_SESSION['user_role'] ?? $_SESSION['role'] ?? '') === 'admin'): ?>
                    <button class="action-btn warning" onclick="editStudent(<?php echo $student['id']; ?>)">
                        <i class="fas fa-edit"></i> Edit
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid animate">
            <div class="stat-card financial">
                <div class="stat-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value">KES <?php echo number_format($student['current_balance'], 2); ?></div>
                    <div class="stat-label">Current Balance</div>
                    <div class="stat-trend">
                        <?php if ($student['current_balance'] > 0): ?>
                        <span style="color: var(--danger);">Outstanding balance</span>
                        <?php elseif ($student['current_balance'] < 0): ?>
                        <span style="color: var(--purple);">In credit</span>
                        <?php else: ?>
                        <span style="color: var(--success);">Fully paid</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="stat-card academic">
                <div class="stat-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo count($grade_history); ?></div>
                    <div class="stat-label">Grades Recorded</div>
                    <div class="stat-trend">
                        <?php 
                        $avg_grade = 0;
                        if (!empty($grade_history)) {
                            $total = array_sum(array_column($grade_history, 'marks'));
                            $avg_grade = round($total / count($grade_history), 1);
                        }
                        ?>
                        <span>Average: <?php echo $avg_grade; ?>%</span>
                    </div>
                </div>
            </div>

            <div class="stat-card attendance">
                <div class="stat-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $attendance_rate; ?>%</div>
                    <div class="stat-label">Attendance Rate</div>
                    <div class="stat-trend">
                        <span><?php echo $student['total_present']; ?>/<?php echo $student['total_attendance']; ?> days present</span>
                    </div>
                </div>
            </div>

            <div class="stat-card behavior">
                <div class="stat-icon">
                    <i class="fas fa-sticky-note"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $student['total_notes']; ?></div>
                    <div class="stat-label">Teacher Notes</div>
                    <div class="stat-trend">
                        <span>Last: <?php echo !empty($student_notes) ? date('d M', strtotime($student_notes[0]['created_at'])) : 'Never'; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs Navigation -->
        <div class="tabs animate">
            <div class="tab active" onclick="switchTab('overview')">Overview</div>
            <div class="tab" onclick="switchTab('financial')">Financial</div>
            <div class="tab" onclick="switchTab('attendance')">Attendance</div>
            <div class="tab" onclick="switchTab('academic')">Academic</div>
            <div class="tab" onclick="switchTab('notes')">Notes</div>
            <div class="tab" onclick="switchTab('assignments')">Fee Assignments</div>
        </div>

        <!-- Overview Tab -->
        <div id="overviewTab" class="tab-content active">
            <!-- Personal Information -->
            <div class="section-card">
                <div class="section-header">
                    <h3><i class="fas fa-user"></i> Personal Information</h3>
                </div>
                <div class="section-body">
                    <div class="info-grid">
                        <div class="info-group">
                            <div class="info-title">Basic Details</div>
                            <div class="info-row">
                                <span class="info-label">Full Name</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['full_name']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Admission Number</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['Admission_number']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Date of Birth</span>
                                <span class="info-value"><?php echo $student['date_of_birth'] ? date('d M Y', strtotime($student['date_of_birth'])) : 'N/A'; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Gender</span>
                                <span class="info-value"><?php echo ucfirst($student['gender'] ?? 'N/A'); ?></span>
                            </div>
                        </div>
                        
                        <div class="info-group">
                            <div class="info-title">Contact Information</div>
                            <div class="info-row">
                                <span class="info-label">Phone</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['phone'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Email</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Address</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['address'] ?? 'N/A'); ?></span>
                            </div>
                        </div>
                        
                        <div class="info-group">
                            <div class="info-title">Parent/Guardian Information</div>
                            <div class="info-row">
                                <span class="info-label">Name</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['parent_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Phone</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['parent_phone'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Email</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['parent_email'] ?? 'N/A'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Class Subjects -->
            <div class="section-card">
                <div class="section-header">
                    <h3><i class="fas fa-book"></i> Class Subjects</h3>
                </div>
                <div class="section-body">
                    <?php if (!empty($class_subjects)): ?>
                    <div class="subjects-grid">
                        <?php foreach ($class_subjects as $subject): ?>
                        <div class="subject-card">
                            <div class="subject-name"><?php echo htmlspecialchars($subject['subject_name']); ?></div>
                            <?php if ($subject['teacher_name']): ?>
                            <div class="subject-teacher"><?php echo htmlspecialchars($subject['teacher_name']); ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-book"></i>
                        <p>No subjects assigned for this class</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="section-card">
                <div class="section-header">
                    <h3><i class="fas fa-history"></i> Recent Activity</h3>
                </div>
                <div class="section-body">
                    <div style="display: grid; gap: 1rem;">
                        <?php if (!empty($recent_payments)): ?>
                        <div>
                            <h4 style="margin-bottom: 0.5rem; color: var(--dark);">Last Payment</h4>
                            <div style="background: var(--light); padding: 1rem; border-radius: var(--border-radius-md);">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <strong><?php echo date('d M Y', strtotime($recent_payments[0]['payment_date'])); ?></strong>
                                        <div style="color: var(--gray);">KES <?php echo number_format($recent_payments[0]['amount'], 2); ?></div>
                                    </div>
                                    <span class="invoice-status status-<?php echo $recent_payments[0]['status']; ?>">
                                        <?php echo ucfirst($recent_payments[0]['status']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($attendance_history)): ?>
                        <div>
                            <h4 style="margin-bottom: 0.5rem; color: var(--dark);">Last Attendance</h4>
                            <div style="background: var(--light); padding: 1rem; border-radius: var(--border-radius-md);">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <strong><?php echo date('d M Y', strtotime($attendance_history[0]['date'])); ?></strong>
                                    </div>
                                    <span class="attendance-badge attendance-<?php echo strtolower($attendance_history[0]['status']); ?>">
                                        <?php echo $attendance_history[0]['status']; ?>
                                    </span>
                                </div>
                                <?php if ($attendance_history[0]['remarks']): ?>
                                <div style="font-size: 0.85rem; color: var(--gray); margin-top: 0.5rem;">
                                    <i class="fas fa-comment"></i> <?php echo htmlspecialchars($attendance_history[0]['remarks']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Financial Tab -->
        <div id="financialTab" class="tab-content">
            <!-- Financial Summary -->
            <div class="section-card">
                <div class="section-header">
                    <h3><i class="fas fa-chart-pie"></i> Financial Summary</h3>
                    <div>
                        <a href="record_payment.php?student_id=<?php echo $student['id']; ?>" class="action-btn success" style="font-size: 0.9rem;">
                            <i class="fas fa-plus"></i> Record Payment
                        </a>
                    </div>
                </div>
                <div class="section-body">
                    <div class="info-grid">
                        <div class="info-group">
                            <div class="info-title">Invoice Summary</div>
                            <div class="info-row">
                                <span class="info-label">Total Invoices</span>
                                <span class="info-value"><?php echo $student['total_invoices']; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Paid Invoices</span>
                                <span class="info-value"><?php echo $student['paid_invoices']; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Unpaid Invoices</span>
                                <span class="info-value"><?php echo $student['unpaid_invoices']; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Partial Payments</span>
                                <span class="info-value"><?php echo $student['partial_invoices']; ?></span>
                            </div>
                        </div>
                        
                        <div class="info-group">
                            <div class="info-title">Amount Summary</div>
                            <div class="info-row">
                                <span class="info-label">Total Invoiced</span>
                                <span class="info-value">KES <?php echo number_format($student['total_invoiced'], 2); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Total Paid</span>
                                <span class="info-value">KES <?php echo number_format($student['total_paid'], 2); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Current Balance</span>
                                <span class="info-value" style="color: <?php echo $student['current_balance'] > 0 ? 'var(--danger)' : 'var(--success)'; ?>;">
                                    KES <?php echo number_format(abs($student['current_balance']), 2); ?>
                                    <?php if ($student['current_balance'] < 0): ?> (Credit)<?php endif; ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="info-group">
                            <div class="info-title">Payment Summary</div>
                            <div class="info-row">
                                <span class="info-label">Total Payments</span>
                                <span class="info-value"><?php echo $student['total_payments']; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Last Payment</span>
                                <span class="info-value"><?php echo $student['last_payment_date'] ? date('d M Y', strtotime($student['last_payment_date'])) : 'Never'; ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Progress Bar -->
                    <?php if ($student['total_invoiced'] > 0): ?>
                    <?php $payment_percentage = ($student['total_paid'] / $student['total_invoiced']) * 100; ?>
                    <div style="margin-top: 2rem;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span>Payment Progress</span>
                            <span><?php echo number_format($payment_percentage, 1); ?>%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $payment_percentage; ?>%;"></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Invoices -->
            <div class="section-card">
                <div class="section-header">
                    <h3><i class="fas fa-file-invoice"></i> Recent Invoices</h3>
                    <a href="invoices.php?student_id=<?php echo $student['id']; ?>" style="color: var(--primary);">View All</a>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Date</th>
                                <th>Due Date</th>
                                <th>Total</th>
                                <th>Paid</th>
                                <th>Balance</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recent_invoices)): ?>
                                <?php foreach ($recent_invoices as $invoice): 
                                    $is_overdue = strtotime($invoice['due_date']) < time() && $invoice['status'] != 'paid';
                                    $status = $is_overdue ? 'overdue' : $invoice['status'];
                                ?>
                                <tr>
                                    <td><strong>#<?php echo htmlspecialchars($invoice['invoice_no']); ?></strong></td>
                                    <td><?php echo date('d M Y', strtotime($invoice['created_at'])); ?></td>
                                    <td>
                                        <?php echo date('d M Y', strtotime($invoice['due_date'])); ?>
                                        <?php if ($is_overdue): ?>
                                        <br><small style="color: var(--danger);"><?php echo $invoice['days_overdue']; ?> days overdue</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>KES <?php echo number_format($invoice['total_amount'], 2); ?></td>
                                    <td>KES <?php echo number_format($invoice['amount_paid'], 2); ?></td>
                                    <td><strong>KES <?php echo number_format($invoice['balance'], 2); ?></strong></td>
                                    <td>
                                        <span class="invoice-status status-<?php echo $status; ?>">
                                            <?php echo ucfirst($status); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="invoice_details.php?id=<?php echo $invoice['id']; ?>" class="action-btn primary" style="padding: 0.3rem 0.8rem; font-size: 0.8rem;">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="empty-state">
                                        <i class="fas fa-file-invoice"></i>
                                        <p>No invoices found</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Payments -->
            <div class="section-card">
                <div class="section-header">
                    <h3><i class="fas fa-credit-card"></i> Recent Payments</h3>
                    <a href="payments.php?student_id=<?php echo $student['id']; ?>" style="color: var(--primary);">View All</a>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Invoice #</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recent_payments)): ?>
                                <?php foreach ($recent_payments as $payment): ?>
                                <tr>
                                    <td><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></td>
                                    <td>#<?php echo htmlspecialchars($payment['invoice_no']); ?></td>
                                    <td><strong>KES <?php echo number_format($payment['amount'], 2); ?></strong></td>
                                    <td><?php echo htmlspecialchars($payment['payment_method_label']); ?></td>
                                    <td><?php echo htmlspecialchars($payment['reference_no'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($payment['recorded_by_name']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="empty-state">
                                        <i class="fas fa-credit-card"></i>
                                        <p>No payments recorded</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Attendance Tab -->
        <div id="attendanceTab" class="tab-content">
            <div class="section-card">
                <div class="section-header">
                    <h3><i class="fas fa-calendar-check"></i> Attendance History</h3>
                    <div>
                        <span style="color: var(--gray);">Last 30 days</span>
                    </div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Remarks</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($attendance_history)): ?>
                                <?php foreach ($attendance_history as $attendance): ?>
                                <tr>
                                    <td><?php echo date('d M Y', strtotime($attendance['date'])); ?></td>
                                    <td>
                                        <span class="attendance-badge attendance-<?php echo strtolower($attendance['status']); ?>">
                                            <?php echo $attendance['status']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($attendance['remarks'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($attendance['recorded_by_name']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="empty-state">
                                        <i class="fas fa-calendar-times"></i>
                                        <p>No attendance records found</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Academic Tab -->
        <div id="academicTab" class="tab-content">
            <!-- Grade Averages -->
            <?php if (!empty($grade_averages)): ?>
            <div class="section-card">
                <div class="section-header">
                    <h3><i class="fas fa-chart-line"></i> Subject Averages</h3>
                </div>
                <div class="section-body">
                    <div class="averages-grid">
                        <?php foreach ($grade_averages as $subject => $data): ?>
                        <div class="average-card">
                            <div class="average-subject"><?php echo htmlspecialchars($subject); ?></div>
                            <div class="average-value"><?php echo $data['average']; ?>%</div>
                            <div style="font-size: 0.8rem; color: var(--gray);"><?php echo $data['count']; ?> assessments</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Grade History -->
            <div class="section-card">
                <div class="section-header">
                    <h3><i class="fas fa-history"></i> Grade History</h3>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Subject</th>
                                <th>Assessment</th>
                                <th>Marks</th>
                                <th>Grade</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($grade_history)): ?>
                                <?php foreach ($grade_history as $grade): ?>
                                <tr>
                                    <td><?php echo date('d M Y', strtotime($grade['created_at'])); ?></td>
                                    <td><strong><?php echo htmlspecialchars($grade['subject_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($grade['assessment_name'] ?? 'General'); ?></td>
                                    <td><?php echo $grade['marks']; ?>%</td>
                                    <td>
                                        <?php
                                        $letter = 'F';
                                        if ($grade['marks'] >= 80) $letter = 'A';
                                        elseif ($grade['marks'] >= 70) $letter = 'B';
                                        elseif ($grade['marks'] >= 60) $letter = 'C';
                                        elseif ($grade['marks'] >= 50) $letter = 'D';
                                        ?>
                                        <span style="font-weight: 700; color: <?php 
                                            echo $letter == 'A' ? 'var(--success)' : 
                                                ($letter == 'B' ? 'var(--primary)' : 
                                                ($letter == 'C' ? 'var(--warning)' : 'var(--danger)')); 
                                        ?>;"><?php echo $letter; ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($grade['remarks'] ?? '-'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="empty-state">
                                        <i class="fas fa-graduation-cap"></i>
                                        <p>No grades recorded</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Notes Tab -->
        <div id="notesTab" class="tab-content">
            <div class="section-card">
                <div class="section-header">
                    <h3><i class="fas fa-sticky-note"></i> Teacher Notes</h3>
                    <button class="action-btn success" onclick="addNote()">
                        <i class="fas fa-plus"></i> Add Note
                    </button>
                </div>
                <div class="section-body">
                    <?php if (!empty($student_notes)): ?>
                    <div class="notes-list">
                        <?php foreach ($student_notes as $note): ?>
                        <div class="note-item">
                            <div class="note-header">
                                <span class="note-type"><?php echo ucfirst($note['note_type']); ?></span>
                                <div class="note-meta">
                                    <span><i class="far fa-user"></i> <?php echo htmlspecialchars($note['teacher_name']); ?></span>
                                    <span><i class="far fa-clock"></i> <?php echo date('d M Y H:i', strtotime($note['created_at'])); ?></span>
                                </div>
                            </div>
                            <div class="note-content">
                                <?php echo nl2br(htmlspecialchars($note['note'])); ?>
                            </div>
                            <div class="note-footer">
                                <span class="teacher-role"><?php echo ucfirst($note['teacher_role']); ?></span>
                                <?php if ($_SESSION['user_id'] == $note['teacher_id'] || (($_SESSION['user_role'] ?? $_SESSION['role'] ?? '') === 'admin')): ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirmDeleteNote(event)">
                                    <input type="hidden" name="note_id" value="<?php echo $note['id']; ?>">
                                    <button type="submit" name="delete_note" class="btn-delete-note">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-sticky-note"></i>
                        <p>No notes for this student</p>
                        <button class="action-btn success" onclick="addNote()" style="margin-top: 1rem;">
                            <i class="fas fa-plus"></i> Add First Note
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Fee Assignments Tab -->
        <div id="assignmentsTab" class="tab-content">
            <div class="section-card">
                <div class="section-header">
                    <h3><i class="fas fa-tasks"></i> Fee Assignments</h3>
                    <a href="fee_assignments.php?student_id=<?php echo $student['id']; ?>" class="action-btn success">
                        <i class="fas fa-plus"></i> Assign Fee
                    </a>
                </div>
                <div class="section-body">
                    <?php if (!empty($fee_assignments)): ?>
                        <?php foreach ($fee_assignments as $assignment): ?>
                        <div class="assignment-item">
                            <div class="assignment-details">
                                <div class="assignment-name"><?php echo htmlspecialchars($assignment['fee_name']); ?></div>
                                <div class="assignment-meta">
                                    <span>Term <?php echo $assignment['term']; ?> - <?php echo $assignment['academic_year']; ?></span>
                                    <?php if ($assignment['custom_amount']): ?>
                                    <span style="color: var(--warning);"> (Custom Amount)</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($assignment['notes']): ?>
                                <div style="font-size: 0.8rem; color: var(--gray); margin-top: 0.3rem;">
                                    <i class="fas fa-comment"></i> <?php echo htmlspecialchars($assignment['notes']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="assignment-amount">
                                KES <?php echo number_format($assignment['amount'], 2); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-tasks"></i>
                        <p>No fee assignments found</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Note Modal -->
    <div id="noteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-sticky-note"></i> Add Note</h3>
                <button class="modal-close" onclick="closeModal('noteModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Note Type</label>
                        <select name="note_type" class="form-control" required>
                            <option value="general">General</option>
                            <option value="academic">Academic</option>
                            <option value="behavior">Behavior</option>
                            <option value="parent_contact">Parent Contact</option>
                            <option value="achievement">Achievement</option>
                            <option value="concern">Concern</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Note</label>
                        <textarea name="note" class="form-control" rows="5" required placeholder="Enter your note here..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('noteModal')">Cancel</button>
                    <button type="submit" name="add_note" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Note
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Tab switching
        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            event.target.classList.add('active');
            document.getElementById(tabName + 'Tab').classList.add('active');
        }

        // Modal functions
        function addNote() {
            document.getElementById('noteModal').classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // Action functions
        function recordPayment(studentId) {
            window.location.href = 'record_payment.php?student_id=' + studentId;
        }

        function editStudent(studentId) {
            window.location.href = 'edit_student.php?id=' + studentId;
        }

        // Confirm delete note
        function confirmDeleteNote(event) {
            event.preventDefault();
            
            Swal.fire({
                title: 'Delete Note?',
                text: 'Are you sure you want to delete this note?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f94144',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it'
            }).then((result) => {
                if (result.isConfirmed) {
                    event.target.submit();
                }
            });
            
            return false;
        }

        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</body>
</html>
