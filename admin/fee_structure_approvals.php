<?php
include '../config.php';
checkAuth();
checkRole(['admin']);

$page_title = 'Fee Structure Approvals - ' . SCHOOL_NAME;
$fee_structure_columns = array_fill_keys(
    $pdo->query("SHOW COLUMNS FROM fee_structures")->fetchAll(PDO::FETCH_COLUMN),
    true
);
$invoice_columns = array_fill_keys(
    $pdo->query("SHOW COLUMNS FROM invoices")->fetchAll(PDO::FETCH_COLUMN),
    true
);
$notification_columns = array_fill_keys(
    $pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN),
    true
);
$is_ajax_request = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

function respondApprovalRequest(bool $success, string $message, array $extra = []): void
{
    global $is_ajax_request;

    if ($is_ajax_request) {
        header('Content-Type: application/json');
        echo json_encode(array_merge([
            'success' => $success,
            'message' => $message,
        ], $extra));
        exit();
    }

    $_SESSION[$success ? 'success' : 'error'] = $message;
    header("Location: fee_structure_approvals.php");
    exit();
}

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['approve_structure'])) {
            $structure_id = intval($_POST['structure_id']);
            $remarks = trim($_POST['remarks'] ?? '');
            
            $pdo->beginTransaction();
            
            // Get structure details
            $stmt = $pdo->prepare("
                SELECT fs.*, u.full_name as creator_name, u.email as creator_email
                FROM fee_structures fs
                LEFT JOIN users u ON fs.created_by = u.id
                WHERE fs.id = ?
            ");
            $stmt->execute([$structure_id]);
            $structure = $stmt->fetch();
            
            if (!$structure) {
                throw new Exception('Fee structure not found');
            }
            
            // Update structure status - verify approved_by user exists
            $user_check = $pdo->prepare("SELECT id FROM users WHERE id = ?");
            $user_check->execute([$_SESSION['user_id']]);
            if (!$user_check->fetch()) {
                throw new Exception("Admin user not found in database. Cannot approve.");
            }
            
            $approve_set_parts = [
                "status = 'approved'",
                "approved_by = ?",
                "approved_at = NOW()"
            ];
            $approve_params = [$_SESSION['user_id']];

            if (isset($fee_structure_columns['approval_remarks'])) {
                $approve_set_parts[] = "approval_remarks = ?";
                $approve_params[] = $remarks !== '' ? $remarks : null;
            }

            if (isset($fee_structure_columns['rejection_reason'])) {
                $approve_set_parts[] = "rejection_reason = NULL";
            }

            $approve_params[] = $structure_id;

            $stmt = $pdo->prepare("
                UPDATE fee_structures 
                SET " . implode(', ', $approve_set_parts) . "
                WHERE id = ? AND status = 'pending'
            ");
            $stmt->execute($approve_params);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Structure not found or is not in pending status');
            }
            
            // Get structure items
            $stmt = $pdo->prepare("SELECT * FROM fee_structure_items WHERE fee_structure_id = ?");
            $stmt->execute([$structure_id]);
            $items = $stmt->fetchAll();
            
            if (empty($items)) {
                throw new Exception('No items found in fee structure');
            }
            
            $total_amount = array_sum(array_column($items, 'amount'));
            
            // Generate invoices for active students in the selected class using the live invoice schema.
            $stmt = $pdo->prepare("
                SELECT s.id, s.full_name, s.Admission_number as admission_number
                FROM students s
                WHERE s.class_id = ? AND s.status = 'active'
                ORDER BY s.full_name ASC
            ");
            $stmt->execute([$structure['class_id']]);
            $students = $stmt->fetchAll();
            
            $invoice_count = 0;
            
            // Generate invoices for all active students
            if (!empty($students)) {
                foreach ($students as $student) {
                    // Check if invoice already exists
                    $check = $pdo->prepare("
                        SELECT id FROM invoices 
                        WHERE student_id = ? AND fee_structure_id = ?
                    ");
                    $check->execute([$student['id'], $structure_id]);
                    
                    if (!$check->fetch()) {
                        // Generate invoice number
                        $year = date('Y');
                        $month = date('m');
                        $count_stmt = $pdo->query("SELECT COUNT(*) FROM invoices WHERE invoice_no LIKE 'INV-{$year}{$month}%'");
                        $count = $count_stmt->fetchColumn() + 1;
                        $invoice_number = 'INV-' . $year . $month . str_pad((string) $count, 4, '0', STR_PAD_LEFT);

                        $invoice_data = [
                            'invoice_no' => $invoice_number,
                            'student_id' => $student['id'],
                            'class_id' => $structure['class_id'],
                            'fee_structure_id' => $structure_id,
                            'admission_number' => $student['admission_number'] ?? null,
                            'term' => $structure['term'] ?? null,
                            'total_amount' => $total_amount,
                            'amount_paid' => 0,
                            'balance' => $total_amount,
                            'due_date' => !empty($structure['due_date']) ? $structure['due_date'] : date('Y-m-d', strtotime('+30 days')),
                            'issued_date' => date('Y-m-d'),
                            'status' => 'issued',
                            'invoice_type' => 'fee_structure',
                            'notes' => 'Generated from approved fee structure: ' . ($structure['structure_name'] ?? 'Fee Structure'),
                            'created_by' => $_SESSION['user_id'],
                        ];

                        $invoice_fields = [];
                        $invoice_placeholders = [];
                        $invoice_values = [];

                        foreach ($invoice_data as $column => $value) {
                            if (isset($invoice_columns[$column])) {
                                $invoice_fields[] = $column;
                                $invoice_placeholders[] = '?';
                                $invoice_values[] = $value;
                            }
                        }

                        if (empty($invoice_fields)) {
                            throw new Exception('Invoices table schema is missing the required columns.');
                        }

                        $invoice_stmt = $pdo->prepare("
                            INSERT INTO invoices (" . implode(', ', $invoice_fields) . ")
                            VALUES (" . implode(', ', $invoice_placeholders) . ")
                        ");

                        $invoice_stmt->execute($invoice_values);
                        
                        $invoice_count++;
                    }
                }
            }
            
            // Create notification for creator
            if (!empty($structure['created_by'])) {
                createNotification(
                    'Fee Structure Approved',
                    "Your fee structure '{$structure['structure_name']}' has been approved. {$invoice_count} invoices were generated.",
                    'approval',
                    (int) $structure['created_by'],
                    null,
                    'high',
                    null,
                    null,
                    $structure_id,
                    'fee_structure'
                );
            }
            
            $pdo->commit();
            respondApprovalRequest(true, "Fee structure approved successfully! Generated {$invoice_count} invoices.", [
                'invoice_count' => $invoice_count,
                'status' => 'approved',
            ]);
            
        } elseif (isset($_POST['reject_structure'])) {
            $structure_id = intval($_POST['structure_id']);
            $reason = trim($_POST['reason'] ?? '');
            
            if (empty($reason)) {
                throw new Exception('Please provide a reason for rejection');
            }
            
            $pdo->beginTransaction();
            
            // Get structure details
            $stmt = $pdo->prepare("
                SELECT fs.*, u.full_name as creator_name
                FROM fee_structures fs
                LEFT JOIN users u ON fs.created_by = u.id
                WHERE fs.id = ?
            ");
            $stmt->execute([$structure_id]);
            $structure = $stmt->fetch();
            
            if (!$structure) {
                throw new Exception('Fee structure not found');
            }
            
            // Update structure status
            // Update structure status - verify admin user exists
            $user_check = $pdo->prepare("SELECT id FROM users WHERE id = ?");
            $user_check->execute([$_SESSION['user_id']]);
            if (!$user_check->fetch()) {
                throw new Exception("Admin user not found in database. Cannot reject.");
            }
            
            $reject_set_parts = [
                "status = 'rejected'",
                "approved_by = ?",
                (isset($fee_structure_columns['rejected_at']) ? "rejected_at = NOW()" : "approved_at = NOW()")
            ];
            $reject_params = [$_SESSION['user_id']];

            if (isset($fee_structure_columns['rejection_reason'])) {
                $reject_set_parts[] = "rejection_reason = ?";
                $reject_params[] = $reason;
            }

            if (isset($fee_structure_columns['approval_remarks'])) {
                $reject_set_parts[] = "approval_remarks = NULL";
            }

            $reject_params[] = $structure_id;

            $stmt = $pdo->prepare("
                UPDATE fee_structures 
                SET " . implode(', ', $reject_set_parts) . "
                WHERE id = ? AND status = 'pending'
            ");
            $stmt->execute($reject_params);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Structure not found or is not in pending status');
            }
            
            // Create notification for creator
            if (!empty($structure['created_by'])) {
                createNotification(
                    'Fee Structure Rejected',
                    "Your fee structure '{$structure['structure_name']}' has been rejected.\n\nReason: {$reason}",
                    'approval',
                    (int) $structure['created_by'],
                    null,
                    'high',
                    null,
                    null,
                    $structure_id,
                    'fee_structure'
                );
            }
            
            $pdo->commit();
            respondApprovalRequest(true, "Fee structure rejected successfully!", [
                'status' => 'rejected',
            ]);
        }
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        respondApprovalRequest(false, 'Error: ' . $e->getMessage());
    }
}

// Get pending fee structures
$pending_query = "
    SELECT 
        fs.*,
        c.class_name,
        u.full_name as creator_name,
        u.email as creator_email,
        (SELECT COUNT(*) FROM fee_structure_items WHERE fee_structure_id = fs.id) as item_count,
        (SELECT SUM(amount) FROM fee_structure_items WHERE fee_structure_id = fs.id) as total_amount,
        (SELECT COUNT(*) FROM students WHERE class_id = fs.class_id AND status = 'active') as student_count
    FROM fee_structures fs
    LEFT JOIN classes c ON fs.class_id = c.id
    LEFT JOIN users u ON fs.created_by = u.id
    WHERE fs.status = 'pending'
    ORDER BY fs.created_at ASC
";
$pending_structures = $pdo->query($pending_query)->fetchAll();

// Get approved/rejected history
$history_query = "
    SELECT 
        fs.*,
        c.class_name,
        u.full_name as creator_name,
        a.full_name as approver_name,
        (SELECT COUNT(*) FROM fee_structure_items WHERE fee_structure_id = fs.id) as item_count,
        (SELECT SUM(amount) FROM fee_structure_items WHERE fee_structure_id = fs.id) as total_amount
    FROM fee_structures fs
    LEFT JOIN classes c ON fs.class_id = c.id
    LEFT JOIN users u ON fs.created_by = u.id
    LEFT JOIN users a ON fs.approved_by = a.id
    WHERE fs.status IN ('approved', 'rejected')
    ORDER BY fs.approved_at DESC
    LIMIT 20
";
$history_structures = $pdo->query($history_query)->fetchAll();

// Get statistics
$stats = $pdo->query("
    SELECT 
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
        COUNT(CASE WHEN status = 'approved' AND approved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as approved_30d,
        COUNT(CASE WHEN status = 'rejected' AND approved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as rejected_30d,
        COALESCE(SUM(CASE WHEN status = 'approved' THEN (SELECT SUM(amount) FROM fee_structure_items WHERE fee_structure_id = fs.id) ELSE 0 END), 0) as total_approved_amount
    FROM fee_structures fs
")->fetch();
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

        /* Page Header */
        .page-header {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: var(--gradient-1);
        }

        .page-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            background: var(--gradient-1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: var(--gray);
            font-size: 1rem;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius-md);
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-xl);
        }

        .btn-primary {
            background: var(--gradient-1);
            color: white;
        }

        .btn-success {
            background: var(--gradient-3);
            color: white;
        }

        .btn-danger {
            background: var(--gradient-2);
            color: white;
        }

        .btn-warning {
            background: var(--gradient-5);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        /* Stats Grid */
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
            border-left: 4px solid;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card.pending { border-left-color: var(--warning); }
        .stat-card.approved { border-left-color: var(--success); }
        .stat-card.rejected { border-left-color: var(--danger); }
        .stat-card.amount { border-left-color: var(--primary); }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-detail {
            font-size: 0.85rem;
            color: var(--gray);
            margin-top: 0.5rem;
        }

        /* Alert Banner */
        .alert-banner {
            background: linear-gradient(135deg, var(--warning), #e07c1a);
            border-radius: var(--border-radius-lg);
            padding: 1rem 2rem;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-lg);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }

        /* Structure Cards */
        .structures-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .structure-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            transition: var(--transition);
            border: 1px solid var(--light);
        }

        .structure-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
            border-color: var(--primary);
        }

        .card-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 2px solid var(--light);
            position: relative;
        }

        .structure-name {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
            padding-right: 100px;
        }

        .structure-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.9rem;
            color: var(--gray);
            flex-wrap: wrap;
        }

        .structure-meta i {
            width: 16px;
            color: var(--primary);
        }

        .badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--warning);
            color: white;
        }

        .card-body {
            padding: 1.5rem;
        }

        .description {
            color: var(--dark);
            margin-bottom: 1rem;
            line-height: 1.6;
            padding-bottom: 1rem;
            border-bottom: 1px dashed var(--light);
        }

        .items-preview {
            margin: 1rem 0;
        }

        .item-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px dashed var(--light);
        }

        .item-name {
            font-weight: 500;
            color: var(--dark);
        }

        .item-amount {
            font-weight: 600;
            color: var(--primary);
        }

        .item-mandatory {
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
            border-radius: 4px;
            margin-left: 0.5rem;
        }

        .total-amount {
            display: flex;
            justify-content: space-between;
            padding: 1rem 0;
            font-weight: 700;
            color: var(--dark);
            border-top: 2px solid var(--light);
            margin-top: 0.5rem;
        }

        .total-amount span:last-child {
            color: var(--success);
            font-size: 1.2rem;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin: 1rem 0;
            padding: 1rem;
            background: var(--light);
            border-radius: var(--border-radius-md);
        }

        .stat-item {
            text-align: center;
        }

        .stat-item .value {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
        }

        .stat-item .label {
            font-size: 0.75rem;
            color: var(--gray);
            text-transform: uppercase;
        }

        .card-footer {
            padding: 1rem 1.5rem;
            background: var(--light);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .creator-info {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .creator-info strong {
            color: var(--dark);
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        /* History Table */
        .history-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }

        .history-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .history-header h2 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

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

        .status-badge {
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-approved {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .status-rejected {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        .status-pending {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .structures-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
            
            .structure-meta {
                flex-direction: column;
                gap: 0.3rem;
            }
            
            .stats-row {
                grid-template-columns: 1fr;
            }
            
            .card-footer {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
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

        .stagger-item {
            opacity: 0;
            animation: fadeInUp 0.5s ease-out forwards;
        }

        .stagger-item:nth-child(1) { animation-delay: 0.1s; }
        .stagger-item:nth-child(2) { animation-delay: 0.2s; }
        .stagger-item:nth-child(3) { animation-delay: 0.3s; }
        .stagger-item:nth-child(4) { animation-delay: 0.4s; }
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
                <h1><i class="fas fa-check-double"></i> Fee Structure Approvals</h1>
                <p>Review and approve fee structures submitted by accountants</p>
            </div>
            <div class="header-actions">
                <a href="dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success animate" style="padding: 1rem 1.5rem; border-radius: var(--border-radius-md); margin-bottom: 1.5rem; background: rgba(76, 201, 240, 0.1); border-left: 4px solid var(--success); color: var(--success); display: flex; justify-content: space-between; align-items: center;">
            <div>
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; ?>
            </div>
            <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: inherit; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger animate" style="padding: 1rem 1.5rem; border-radius: var(--border-radius-md); margin-bottom: 1.5rem; background: rgba(249, 65, 68, 0.1); border-left: 4px solid var(--danger); color: var(--danger); display: flex; justify-content: space-between; align-items: center;">
            <div>
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; ?>
            </div>
            <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: inherit; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['error']); endif; ?>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card pending stagger-item">
                <div class="stat-number"><?php echo $stats['pending_count']; ?></div>
                <div class="stat-label">Pending Approval</div>
            </div>
            
            <div class="stat-card approved stagger-item">
                <div class="stat-number"><?php echo $stats['approved_30d']; ?></div>
                <div class="stat-label">Approved (30d)</div>
            </div>
            
            <div class="stat-card rejected stagger-item">
                <div class="stat-number"><?php echo $stats['rejected_30d']; ?></div>
                <div class="stat-label">Rejected (30d)</div>
            </div>
            
            <div class="stat-card amount stagger-item">
                <div class="stat-number">KES <?php echo number_format($stats['total_approved_amount'] / 1000, 0); ?>k</div>
                <div class="stat-label">Total Approved Value</div>
            </div>
        </div>

        <!-- Alert if pending items -->
        <?php if (count($pending_structures) > 0): ?>
        <div class="alert-banner animate">
            <div>
                <i class="fas fa-clock"></i>
                <strong><?php echo count($pending_structures); ?> fee structures</strong> waiting for your review
            </div>
            <button class="btn btn-sm" onclick="document.getElementById('pending-section').scrollIntoView({behavior: 'smooth'})">
                Review Now <i class="fas fa-arrow-down"></i>
            </button>
        </div>
        <?php endif; ?>

        <!-- Pending Structures Section -->
        <div id="pending-section" class="animate">
            <h2 style="margin-bottom: 1.5rem; color: var(--dark);">
                <i class="fas fa-clock" style="color: var(--warning);"></i> 
                Pending Fee Structures (<?php echo count($pending_structures); ?>)
            </h2>

            <?php if (!empty($pending_structures)): ?>
            <div class="structures-grid">
                <?php foreach ($pending_structures as $structure): ?>
                <div class="structure-card">
                    <div class="card-header">
                        <div class="badge">Pending Review</div>
                        <div class="structure-name"><?php echo htmlspecialchars($structure['structure_name']); ?></div>
                        <div class="structure-meta">
                            <span><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($structure['class_name']); ?></span>
                            <span><i class="fas fa-calendar"></i> Term <?php echo $structure['term']; ?></span>
                            <span><i class="fas fa-book"></i> <?php echo $structure['academic_year_id']; ?></span>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <?php if (!empty($structure['description'])): ?>
                        <div class="description">
                            <?php echo nl2br(htmlspecialchars($structure['description'])); ?>
                        </div>
                        <?php endif; ?>

                        <!-- Stats Row -->
                        <div class="stats-row">
                            <div class="stat-item">
                                <div class="value"><?php echo $structure['item_count']; ?></div>
                                <div class="label">Items</div>
                            </div>
                            <div class="stat-item">
                                <div class="value"><?php echo $structure['student_count']; ?></div>
                                <div class="label">Students</div>
                            </div>
                            <div class="stat-item">
                                <div class="value">KES <?php echo number_format($structure['total_amount'], 0); ?></div>
                                <div class="label">Total/Student</div>
                            </div>
                        </div>

                        <!-- Items Preview -->
                        <div class="items-preview">
                            <?php
                            // Get items for this structure
                            $item_stmt = $pdo->prepare("SELECT * FROM fee_structure_items WHERE fee_structure_id = ? LIMIT 3");
                            $item_stmt->execute([$structure['id']]);
                            $items = $item_stmt->fetchAll();
                            ?>
                            <?php foreach ($items as $item): ?>
                            <div class="item-row">
                                <span class="item-name">
                                    <?php echo htmlspecialchars($item['item_name']); ?>
                                    <?php if ($item['is_mandatory']): ?>
                                    <span class="item-mandatory">REQUIRED</span>
                                    <?php endif; ?>
                                </span>
                                <span class="item-amount">KES <?php echo number_format($item['amount'], 2); ?></span>
                            </div>
                            <?php endforeach; ?>
                            <?php if ($structure['item_count'] > 3): ?>
                            <div class="item-row" style="color: var(--gray); font-style: italic;">
                                <span>+ <?php echo $structure['item_count'] - 3; ?> more items</span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="total-amount">
                            <span>Total Per Student</span>
                            <span>KES <?php echo number_format($structure['total_amount'], 2); ?></span>
                        </div>

                        <div class="creator-info">
                            <i class="far fa-user"></i> Submitted by: <strong><?php echo htmlspecialchars($structure['creator_name']); ?></strong>
                            <div style="font-size: 0.75rem;"><?php echo $structure['creator_email']; ?></div>
                            <div style="font-size: 0.75rem; margin-top: 0.3rem;">
                                <i class="far fa-clock"></i> <?php echo date('d M Y H:i', strtotime($structure['created_at'])); ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-footer">
                        <div style="font-size: 0.85rem; color: var(--gray);">
                            <i class="fas fa-users"></i> Affects <?php echo $structure['student_count']; ?> students
                        </div>
                        <div class="action-buttons">
                            <button class="btn btn-sm btn-success" onclick="approveStructure(<?php echo $structure['id']; ?>)">
                                <i class="fas fa-check"></i> Approve
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="rejectStructure(<?php echo $structure['id']; ?>)">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="text-align: center; padding: 4rem; background: var(--white); border-radius: var(--border-radius-lg);">
                <i class="fas fa-check-circle fa-4x" style="color: var(--success); margin-bottom: 1rem;"></i>
                <h3 style="color: var(--dark);">All Caught Up!</h3>
                <p style="color: var(--gray);">No pending fee structures to review.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- History Section -->
        <div class="history-section animate" style="margin-top: 2rem;">
            <div class="history-header">
                <h2><i class="fas fa-history"></i> Recent Approvals & Rejections</h2>
                <p style="opacity: 0.9;">Last 20 actions</p>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Structure</th>
                            <th>Class</th>
                            <th>Term/Year</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Submitted By</th>
                            <th>Processed By</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($history_structures)): ?>
                            <?php foreach ($history_structures as $structure): ?>
                            <tr>
                                <td><?php echo date('d M Y', strtotime($structure['approved_at'] ?? $structure['created_at'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($structure['structure_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($structure['class_name']); ?></td>
                                <td>Term <?php echo $structure['term']; ?>, <?php echo $structure['academic_year_id']; ?></td>
                                <td><strong style="color: var(--primary);">KES <?php echo number_format($structure['total_amount'], 2); ?></strong></td>
                                <td>
                                    <span class="status-badge status-<?php echo $structure['status']; ?>">
                                        <?php echo ucfirst($structure['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($structure['creator_name']); ?></td>
                                <td><?php echo htmlspecialchars($structure['approver_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php if ($structure['status'] == 'rejected' && !empty($structure['rejection_reason'])): ?>
                                    <i class="fas fa-comment" title="<?php echo htmlspecialchars($structure['rejection_reason']); ?>"></i>
                                    <?php elseif (!empty($structure['approval_remarks'])): ?>
                                    <i class="fas fa-sticky-note" title="<?php echo htmlspecialchars($structure['approval_remarks']); ?>"></i>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 3rem;">
                                    <i class="fas fa-history fa-3x" style="color: var(--gray); margin-bottom: 1rem;"></i>
                                    <h4>No History Found</h4>
                                    <p style="color: var(--gray);">No fee structures have been processed yet.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function approveStructure(structureId) {
            Swal.fire({
                title: 'Approve Fee Structure?',
                html: `
                    <p style="margin-bottom: 1rem;">This will:</p>
                    <ul style="text-align: left; margin-bottom: 1rem;">
                        <li>✓ Mark the structure as approved</li>
                        <li>✓ Generate invoices for all active students</li>
                        <li>✓ Create invoice items for each fee</li>
                    </ul>
                `,
                input: 'textarea',
                inputLabel: 'Remarks (optional)',
                inputPlaceholder: 'Add any approval remarks...',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#4cc9f0',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, approve'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('approve_structure', '1');
                    formData.append('structure_id', structureId);
                    formData.append('remarks', result.value || '');
                    
                    Swal.fire({
                        title: 'Processing...',
                        allowOutsideClick: false,
                        didOpen: () => Swal.showLoading()
                    });
                    
                    fetch('fee_structure_approvals.php', {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    })
                    .then((response) => response.json())
                    .then((data) => {
                        if (!data.success) {
                            throw new Error(data.message || 'Approval failed');
                        }

                        Swal.fire({
                            icon: 'success',
                            title: 'Approved!',
                            text: data.message || 'Fee structure has been approved and invoices generated',
                            timer: 2000
                        }).then(() => location.reload());
                    })
                    .catch((error) => {
                        Swal.fire({
                            icon: 'error',
                            title: 'Approval failed',
                            text: error.message || 'Something went wrong while approving the fee structure'
                        });
                    });
                }
            });
        }

        function rejectStructure(structureId) {
            Swal.fire({
                title: 'Reject Fee Structure',
                input: 'textarea',
                inputLabel: 'Reason for rejection',
                inputPlaceholder: 'Please provide a reason...',
                inputAttributes: {
                    required: true
                },
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f94144',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Reject',
                cancelButtonText: 'Cancel',
                preConfirm: (reason) => {
                    if (!reason) {
                        Swal.showValidationMessage('Please provide a reason');
                        return false;
                    }
                    return reason;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('reject_structure', '1');
                    formData.append('structure_id', structureId);
                    formData.append('reason', result.value);
                    
                    Swal.fire({
                        title: 'Processing...',
                        allowOutsideClick: false,
                        didOpen: () => Swal.showLoading()
                    });
                    
                    fetch('fee_structure_approvals.php', {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    })
                    .then((response) => response.json())
                    .then((data) => {
                        if (!data.success) {
                            throw new Error(data.message || 'Rejection failed');
                        }

                        Swal.fire({
                            icon: 'success',
                            title: 'Rejected!',
                            text: data.message || 'Fee structure has been rejected',
                            timer: 1500
                        }).then(() => location.reload());
                    })
                    .catch((error) => {
                        Swal.fire({
                            icon: 'error',
                            title: 'Rejection failed',
                            text: error.message || 'Something went wrong while rejecting the fee structure'
                        });
                    });
                }
            });
        }
    </script>
</body>
</html>
