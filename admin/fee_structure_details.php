<?php
include '../config.php';
checkAuth();

$page_title = 'Fee Structure Details';

// Get structure ID from URL
$structure_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$structure_id) {
    $_SESSION['error'] = 'Invalid fee structure ID';
    header('Location: fee_structures.php');
    exit;
}

// Determine user role for permissions
$user_role = $_SESSION['user_role']; // Assuming this is stored in session

// Fetch fee structure details with related information
$stmt = $pdo->prepare("
    SELECT 
        fs.*, 
        c.class_name,
        creator.full_name as created_by_name,
        creator.email as created_by_email,
        approver.full_name as approved_by_name,
        (SELECT COUNT(*) FROM fee_structure_items WHERE fee_structure_id = fs.id) as total_items,
        (SELECT SUM(amount) FROM fee_structure_items WHERE fee_structure_id = fs.id) as total_amount,
        (SELECT SUM(amount) FROM fee_structure_items WHERE fee_structure_id = fs.id AND is_mandatory = 1) as mandatory_total,
        (SELECT COUNT(*) FROM fee_structure_items WHERE fee_structure_id = fs.id AND is_mandatory = 1) as mandatory_count,
        (SELECT COUNT(*) FROM invoices WHERE fee_structure_id = fs.id) as invoice_count,
        (SELECT COUNT(*) FROM invoices WHERE fee_structure_id = fs.id AND status = 'paid') as paid_count,
        (SELECT COUNT(*) FROM students WHERE class_id = fs.class_id AND status = 'active') as total_students
    FROM fee_structures fs
    LEFT JOIN classes c ON fs.class_id = c.id
    LEFT JOIN users creator ON fs.created_by = creator.id
    LEFT JOIN users approver ON fs.approved_by = approver.id
    WHERE fs.id = ?
");
$stmt->execute([$structure_id]);
$structure = $stmt->fetch();

if (!$structure) {
    $_SESSION['error'] = 'Fee structure not found';
    header('Location: fee_structures.php');
    exit;
}

// Check permission based on role
$can_edit = ($user_role === 'accountant' && $structure['status'] === 'draft' && $structure['created_by'] == $_SESSION['user_id']);
$can_approve = ($user_role === 'admin' && $structure['status'] === 'pending');
$can_delete = ($user_role === 'accountant' && $structure['status'] === 'draft' && $structure['created_by'] == $_SESSION['user_id']);
$can_view_all = in_array($user_role, ['admin', 'accountant']);

// Fetch fee items
$items = $pdo->prepare("
    SELECT * FROM fee_structure_items 
    WHERE fee_structure_id = ? 
    ORDER BY is_mandatory DESC, item_name ASC
");
$items->execute([$structure_id]);
$fee_items = $items->fetchAll();

// Fetch recent invoices if any
if ($structure['status'] === 'approved') {
    $invoices = $pdo->prepare("
        SELECT i.*, s.full_name as student_name, s.admission_number
        FROM invoices i
        LEFT JOIN students s ON i.student_id = s.id
        WHERE i.fee_structure_id = ?
        ORDER BY i.created_at DESC
        LIMIT 10
    ");
    $invoices->execute([$structure_id]);
    $recent_invoices = $invoices->fetchAll();
}

// Handle AJAX request for quick actions
if (isset($_GET['action']) && $_GET['action'] === 'get_items') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'items' => $fee_items]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo htmlspecialchars($structure['structure_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
        }

        .swal2-container {
            z-index: 1060 !important;
        }

        .swal2-popup {
            z-index: 1061 !important;
        }

        .content-wrapper {
            margin-left: 250px;
            margin-top: 70px;
            padding: 1.5rem;
            min-height: calc(100vh - 70px);
            background: #f1f5f9;
            transition: margin-left 0.3s;
        }

        @media (max-width: 768px) {
            .content-wrapper {
                margin-left: 0;
                padding: 1rem;
            }
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            background: white;
            padding: 1.5rem 2rem;
            border-radius: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .header-left h1 {
            font-size: 1.875rem;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .header-left p {
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-badge {
            display: inline-block;
            padding: 0.35rem 1rem;
            border-radius: 2rem;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .status-draft {
            background: #e2e8f0;
            color: #475569;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
        }

        .btn-primary {
            background: #3b82f6;
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid #e2e8f0;
            color: #475569;
        }

        .btn-outline:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-icon {
            width: 3rem;
            height: 3rem;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .stat-icon.amount { background: #dbeafe; color: #1e40af; }
        .stat-icon.items { background: #e2e8f0; color: #475569; }
        .stat-icon.mandatory { background: #fef3c7; color: #92400e; }
        .stat-icon.students { background: #d1fae5; color: #065f46; }

        .stat-info h3 {
            font-size: 0.875rem;
            color: #64748b;
            margin-bottom: 0.25rem;
        }

        .stat-info p {
            font-size: 1.25rem;
            font-weight: 600;
            color: #0f172a;
        }

        .stat-info small {
            font-size: 0.75rem;
            color: #64748b;
        }

        /* Info Cards */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .info-card h3 {
            font-size: 1rem;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: #64748b;
            font-size: 0.875rem;
        }

        .info-value {
            font-weight: 500;
            color: #0f172a;
        }

        .description-box {
            background: #f8fafc;
            border-radius: 0.5rem;
            padding: 1rem;
            color: #334155;
            line-height: 1.6;
            font-size: 0.95rem;
        }

        /* Items Table */
        .items-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .items-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .items-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .items-header .item-count {
            background: #e2e8f0;
            padding: 0.25rem 1rem;
            border-radius: 2rem;
            font-size: 0.875rem;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
        }

        .items-table th {
            text-align: left;
            padding: 1rem 1.5rem;
            background: #f8fafc;
            color: #475569;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .items-table td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .items-table tbody tr:hover {
            background: #f8fafc;
        }

        .mandatory-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            background: #fef3c7;
            color: #92400e;
            border-radius: 2rem;
            font-size: 0.75rem;
        }

        .optional-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            background: #e2e8f0;
            color: #475569;
            border-radius: 2rem;
            font-size: 0.75rem;
        }

        .amount-cell {
            font-weight: 600;
            color: #0f172a;
        }

        /* Invoices Table */
        .invoices-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .invoices-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .invoices-table {
            width: 100%;
            border-collapse: collapse;
        }

        .invoices-table th {
            text-align: left;
            padding: 1rem 1.5rem;
            background: #f8fafc;
            color: #475569;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .invoices-table td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .invoice-status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-issued { background: #e2e8f0; color: #475569; }
        .status-paid { background: #d1fae5; color: #065f46; }
        .status-overdue { background: #fee2e2; color: #991b1b; }
        .status-partial { background: #fef3c7; color: #92400e; }

        /* Audit Trail */
        .audit-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .audit-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .audit-item:last-child {
            border-bottom: none;
        }

        .audit-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.5rem;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
        }

        .audit-content {
            flex: 1;
        }

        .audit-title {
            font-weight: 500;
            color: #0f172a;
            margin-bottom: 0.25rem;
        }

        .audit-meta {
            font-size: 0.75rem;
            color: #94a3b8;
            display: flex;
            gap: 1rem;
        }

        .rejection-reason {
            margin-top: 0.5rem;
            padding: 0.75rem;
            background: #fee2e2;
            border-radius: 0.5rem;
            color: #991b1b;
            font-size: 0.875rem;
        }

        /* Action Buttons */
        .action-bar {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .alert {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .header-left h1 {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .action-bar {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="content-wrapper">
        <!-- Page Header -->
        <div class="page-header">
            <div class="header-left">
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                    <a href="<?php echo $user_role === 'admin' ? 'fee_structure_approvals.php' : 'fee_structures.php'; ?>" class="btn btn-outline btn-sm">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                    <h1>
                        <?php echo htmlspecialchars($structure['structure_name']); ?>
                        <span class="status-badge status-<?php echo $structure['status']; ?>">
                            <?php echo ucfirst($structure['status']); ?>
                        </span>
                    </h1>
                </div>
                <p>
                    <i class="fas fa-calendar-alt"></i>
                    Created on <?php echo date('F j, Y', strtotime($structure['created_at'])); ?> 
                    by <?php echo htmlspecialchars($structure['created_by_name'] ?? 'Unknown'); ?>
                </p>
            </div>
            
            <?php if ($can_edit): ?>
                <div class="action-bar">
                    <button class="btn btn-warning" onclick="submitForApproval(<?php echo $structure['id']; ?>)">
                        <i class="fas fa-paper-plane"></i> Submit for Approval
                    </button>
                    <button class="btn btn-danger" onclick="deleteStructure(<?php echo $structure['id']; ?>)">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </div>
            <?php endif; ?>
            
            <?php if ($can_approve): ?>
                <div class="action-bar">
                    <button class="btn btn-success" onclick="approveStructure(<?php echo $structure['id']; ?>)">
                        <i class="fas fa-check"></i> Approve Structure
                    </button>
                    <button class="btn btn-danger" onclick="showRejectModal(<?php echo $structure['id']; ?>)">
                        <i class="fas fa-times"></i> Reject
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <!-- Status Alert -->
        <?php if ($structure['status'] === 'pending'): ?>
            <div class="alert alert-warning">
                <i class="fas fa-clock"></i>
                <div>
                    <strong>Pending Approval</strong> - This structure has been submitted and is waiting for admin review.
                </div>
            </div>
        <?php elseif ($structure['status'] === 'approved'): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Approved</strong> - This structure was approved on <?php echo date('F j, Y', strtotime($structure['approved_at'])); ?> 
                    by <?php echo htmlspecialchars($structure['approved_by_name'] ?? 'Admin'); ?>.
                    <?php echo $structure['invoice_count']; ?> invoices have been generated.
                </div>
            </div>
        <?php elseif ($structure['status'] === 'rejected' && !empty($structure['rejection_reason'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>Rejected</strong> - Reason: <?php echo htmlspecialchars($structure['rejection_reason']); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon amount">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="stat-info">
                    <h3>Total Amount</h3>
                    <p>Ksh <?php echo number_format($structure['total_amount'] ?? 0, 2); ?></p>
                    <small>Mandatory: Ksh <?php echo number_format($structure['mandatory_total'] ?? 0, 2); ?></small>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon items">
                    <i class="fas fa-list"></i>
                </div>
                <div class="stat-info">
                    <h3>Total Items</h3>
                    <p><?php echo $structure['total_items']; ?></p>
                    <small><?php echo $structure['mandatory_count']; ?> mandatory</small>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon students">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3>Students</h3>
                    <p><?php echo $structure['total_students']; ?></p>
                    <small>Active in class</small>
                </div>
            </div>
            
            <?php if ($structure['status'] === 'approved'): ?>
            <div class="stat-card">
                <div class="stat-icon mandatory">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="stat-info">
                    <h3>Invoices</h3>
                    <p><?php echo $structure['invoice_count']; ?></p>
                    <small><?php echo $structure['paid_count']; ?> paid</small>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Information Grid -->
        <div class="info-grid">
            <!-- Basic Information -->
            <div class="info-card">
                <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                <div class="info-row">
                    <span class="info-label">Class</span>
                    <span class="info-value"><?php echo htmlspecialchars($structure['class_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Academic Year</span>
                    <span class="info-value"><?php echo $structure['academic_year']; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Term</span>
                    <span class="info-value">Term <?php echo $structure['term']; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Created By</span>
                    <span class="info-value"><?php echo htmlspecialchars($structure['created_by_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Created Date</span>
                    <span class="info-value"><?php echo date('d M Y, h:i A', strtotime($structure['created_at'])); ?></span>
                </div>
            </div>

            <!-- Status Information -->
            <div class="info-card">
                <h3><i class="fas fa-clock"></i> Status Information</h3>
                <div class="info-row">
                    <span class="info-label">Current Status</span>
                    <span class="info-value">
                        <span class="status-badge status-<?php echo $structure['status']; ?>" style="font-size: 0.75rem;">
                            <?php echo ucfirst($structure['status']); ?>
                        </span>
                    </span>
                </div>
                
                <?php if ($structure['status'] === 'pending' && !empty($structure['submitted_at'])): ?>
                <div class="info-row">
                    <span class="info-label">Submitted At</span>
                    <span class="info-value"><?php echo date('d M Y, h:i A', strtotime($structure['submitted_at'])); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if ($structure['status'] === 'approved'): ?>
                <div class="info-row">
                    <span class="info-label">Approved By</span>
                    <span class="info-value"><?php echo htmlspecialchars($structure['approved_by_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Approved At</span>
                    <span class="info-value"><?php echo date('d M Y, h:i A', strtotime($structure['approved_at'])); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if ($structure['status'] === 'rejected'): ?>
                <div class="info-row">
                    <span class="info-label">Rejected At</span>
                    <span class="info-value"><?php echo date('d M Y, h:i A', strtotime($structure['rejected_at'])); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Fee Summary -->
            <div class="info-card">
                <h3><i class="fas fa-calculator"></i> Fee Summary</h3>
                <div class="info-row">
                    <span class="info-label">Total Items</span>
                    <span class="info-value"><?php echo $structure['total_items']; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Mandatory Items</span>
                    <span class="info-value"><?php echo $structure['mandatory_count']; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Optional Items</span>
                    <span class="info-value"><?php echo $structure['total_items'] - $structure['mandatory_count']; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Mandatory Total</span>
                    <span class="info-value">Ksh <?php echo number_format($structure['mandatory_total'] ?? 0, 2); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Optional Total</span>
                    <span class="info-value">Ksh <?php echo number_format(($structure['total_amount'] ?? 0) - ($structure['mandatory_total'] ?? 0), 2); ?></span>
                </div>
            </div>
        </div>

        <!-- Description Section -->
        <?php if (!empty($structure['description'])): ?>
        <div class="info-card" style="margin-bottom: 2rem;">
            <h3><i class="fas fa-align-left"></i> Description</h3>
            <div class="description-box">
                <?php echo nl2br(htmlspecialchars($structure['description'])); ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Fee Items Table -->
        <div class="items-card">
            <div class="items-header">
                <h3>
                    <i class="fas fa-list-ul"></i>
                    Fee Items
                </h3>
                <span class="item-count"><?php echo count($fee_items); ?> items</span>
            </div>
            
            <div class="table-responsive">
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Description</th>
                            <th>Type</th>
                            <th>Amount (Ksh)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($fee_items) > 0): ?>
                            <?php foreach ($fee_items as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                    </td>
                                    <td>
                                        <?php echo !empty($item['description']) ? htmlspecialchars($item['description']) : '<span style="color: #94a3b8;">No description</span>'; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['is_mandatory']): ?>
                                            <span class="mandatory-badge">
                                                <i class="fas fa-star"></i> Mandatory
                                            </span>
                                        <?php else: ?>
                                            <span class="optional-badge">
                                                <i class="fas fa-circle"></i> Optional
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="amount-cell">
                                        Ksh <?php echo number_format($item['amount'], 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 3rem;">
                                    <i class="fas fa-receipt" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem;"></i>
                                    <p style="color: #64748b;">No fee items found in this structure.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Invoices (if approved) -->
        <?php if ($structure['status'] === 'approved' && !empty($recent_invoices)): ?>
        <div class="invoices-card">
            <div class="invoices-header">
                <h3>
                    <i class="fas fa-file-invoice"></i>
                    Recent Invoices
                </h3>
                <a href="invoices.php?structure_id=<?php echo $structure['id']; ?>" class="btn btn-outline btn-sm">
                    View All <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            
            <div class="table-responsive">
                <table class="invoices-table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Student</th>
                            <th>Admission No.</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Due Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_invoices as $invoice): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($invoice['student_name']); ?></td>
                                <td><?php echo htmlspecialchars($invoice['admission_number']); ?></td>
                                <td>Ksh <?php echo number_format($invoice['amount_due'], 2); ?></td>
                                <td>
                                    <span class="invoice-status status-<?php echo $invoice['status']; ?>">
                                        <?php echo ucfirst($invoice['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('d M Y', strtotime($invoice['due_date'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Audit Trail -->
        <div class="audit-card">
            <h3 style="margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-history"></i>
                Audit Trail
            </h3>
            
            <!-- Creation -->
            <div class="audit-item">
                <div class="audit-icon">
                    <i class="fas fa-plus"></i>
                </div>
                <div class="audit-content">
                    <div class="audit-title">Structure Created</div>
                    <div class="audit-meta">
                        <span><i class="far fa-calendar"></i> <?php echo date('F j, Y', strtotime($structure['created_at'])); ?></span>
                        <span><i class="far fa-clock"></i> <?php echo date('h:i A', strtotime($structure['created_at'])); ?></span>
                        <span><i class="far fa-user"></i> <?php echo htmlspecialchars($structure['created_by_name'] ?? 'Unknown'); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Submission (if pending or beyond) -->
            <?php if ($structure['status'] !== 'draft' && !empty($structure['submitted_at'])): ?>
            <div class="audit-item">
                <div class="audit-icon">
                    <i class="fas fa-paper-plane"></i>
                </div>
                <div class="audit-content">
                    <div class="audit-title">Submitted for Approval</div>
                    <div class="audit-meta">
                        <span><i class="far fa-calendar"></i> <?php echo date('F j, Y', strtotime($structure['submitted_at'])); ?></span>
                        <span><i class="far fa-clock"></i> <?php echo date('h:i A', strtotime($structure['submitted_at'])); ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Approval -->
            <?php if ($structure['status'] === 'approved'): ?>
            <div class="audit-item">
                <div class="audit-icon" style="background: #d1fae5; color: #065f46;">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="audit-content">
                    <div class="audit-title">Approved</div>
                    <div class="audit-meta">
                        <span><i class="far fa-calendar"></i> <?php echo date('F j, Y', strtotime($structure['approved_at'])); ?></span>
                        <span><i class="far fa-clock"></i> <?php echo date('h:i A', strtotime($structure['approved_at'])); ?></span>
                        <span><i class="far fa-user"></i> <?php echo htmlspecialchars($structure['approved_by_name'] ?? 'Admin'); ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Rejection -->
            <?php if ($structure['status'] === 'rejected'): ?>
            <div class="audit-item">
                <div class="audit-icon" style="background: #fee2e2; color: #991b1b;">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="audit-content">
                    <div class="audit-title">Rejected</div>
                    <div class="audit-meta">
                        <span><i class="far fa-calendar"></i> <?php echo date('F j, Y', strtotime($structure['rejected_at'])); ?></span>
                        <span><i class="far fa-clock"></i> <?php echo date('h:i A', strtotime($structure['rejected_at'])); ?></span>
                    </div>
                    <?php if (!empty($structure['rejection_reason'])): ?>
                        <div class="rejection-reason">
                            <strong>Reason:</strong> <?php echo htmlspecialchars($structure['rejection_reason']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Invoice Generation (if approved) -->
            <?php if ($structure['status'] === 'approved' && $structure['invoice_count'] > 0): ?>
            <div class="audit-item">
                <div class="audit-icon" style="background: #dbeafe; color: #1e40af;">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="audit-content">
                    <div class="audit-title">Invoices Generated</div>
                    <div class="audit-meta">
                        <span><?php echo $structure['invoice_count']; ?> invoices created</span>
                        <span><?php echo $structure['paid_count']; ?> paid</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Reject Modal (for admin) -->
    <div id="rejectModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1050;">
        <div style="background: white; border-radius: 1rem; width: 90%; max-width: 500px; padding: 2rem;">
            <h3 style="margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-times-circle" style="color: #ef4444;"></i>
                Reject Fee Structure
            </h3>
            
            <form id="rejectForm" method="POST" action="fee_structure_approvals.php">
                <input type="hidden" name="reject_structure" value="1">
                <input type="hidden" name="structure_id" id="rejectStructureId" value="<?php echo $structure['id']; ?>">
                
                <div style="margin-bottom: 1.5rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Reason for Rejection</label>
                    <textarea name="rejection_reason" rows="4" style="width: 100%; padding: 0.75rem; border: 1px solid #e2e8f0; border-radius: 0.5rem;" required></textarea>
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" class="btn btn-outline" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Structure</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Show session messages
        <?php if (isset($_SESSION['success'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: '<?php echo $_SESSION['success']; ?>',
                    timer: 3000,
                    showConfirmButton: false
                });
            });
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: '<?php echo $_SESSION['error']; ?>'
                });
            });
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        // Reject Modal Functions
        function showRejectModal(structureId) {
            document.getElementById('rejectStructureId').value = structureId;
            document.getElementById('rejectModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('rejectModal');
            if (event.target === modal) {
                closeRejectModal();
            }
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeRejectModal();
            }
        });

        // Accountant Functions
        function submitForApproval(id) {
            Swal.fire({
                title: 'Submit for Approval?',
                text: 'Are you sure you want to submit this fee structure for admin approval?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#f59e0b',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, submit it'
            }).then((result) => {
                if (result.isConfirmed) {
                    const loader = document.querySelector('.loader-container');
                    if (loader) loader.style.display = 'flex';
                    
                    const formData = new FormData();
                    formData.append('submit_for_approval', '1');
                    formData.append('structure_id', id);
                    
                    fetch('fee_structures.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (loader) loader.style.display = 'none';
                        
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Submitted!',
                                text: data.message,
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                window.location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: data.message
                            });
                        }
                    })
                    .catch(error => {
                        if (loader) loader.style.display = 'none';
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: 'Failed to submit: ' + error.message
                        });
                    });
                }
            });
        }

        function deleteStructure(id) {
            Swal.fire({
                title: 'Delete Structure?',
                text: 'Are you sure you want to delete this fee structure? This action cannot be undone!',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, delete it'
            }).then((result) => {
                if (result.isConfirmed) {
                    const loader = document.querySelector('.loader-container');
                    if (loader) loader.style.display = 'flex';
                    
                    const formData = new FormData();
                    formData.append('delete_structure', '1');
                    formData.append('structure_id', id);
                    
                    fetch('fee_structures.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (loader) loader.style.display = 'none';
                        
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Deleted!',
                                text: data.message,
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                window.location.href = 'fee_structures.php';
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: data.message
                            });
                        }
                    })
                    .catch(error => {
                        if (loader) loader.style.display = 'none';
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: 'Failed to delete: ' + error.message
                        });
                    });
                }
            });
        }

        // Admin Functions
        function approveStructure(id) {
            Swal.fire({
                title: 'Approve Fee Structure?',
                html: `
                    <p style="margin-bottom: 1rem;">This will:</p>
                    <ul style="text-align: left; margin-bottom: 1rem;">
                        <li>✓ Mark the structure as approved</li>
                        <li>✓ Generate invoices for all active students</li>
                        <li>✓ Create invoice items for each fee</li>
                    </ul>
                    <p><strong>Are you sure you want to proceed?</strong></p>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, approve',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const loader = document.querySelector('.loader-container');
                    if (loader) loader.style.display = 'flex';
                    
                    const formData = new FormData();
                    formData.append('approve_structure', '1');
                    formData.append('structure_id', id);
                    
                    fetch('fee_structure_approvals.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (loader) loader.style.display = 'none';
                        window.location.reload();
                    })
                    .catch(error => {
                        if (loader) loader.style.display = 'none';
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: 'Failed to approve: ' + error.message
                        });
                    });
                }
            });
        }

        // Handle reject form submission
        document.getElementById('rejectForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const reason = this.querySelector('textarea[name="rejection_reason"]').value.trim();
            if (!reason) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Please provide a reason for rejection'
                });
                return;
            }
            
            const loader = document.querySelector('.loader-container');
            if (loader) loader.style.display = 'flex';
            this.submit();
        });

        // Print functionality (optional)
        function printDetails() {
            window.print();
        }
    </script>
</body>
</html>