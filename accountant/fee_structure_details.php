<?php
include '../config.php';
checkAuth();
checkRole(['admin', 'accountant', 'teacher']);

$page_title = 'Fee Structure Details - ' . SCHOOL_NAME;

// Get structure ID from URL
$structure_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$structure_id) {
    $_SESSION['error'] = 'Invalid fee structure ID';
    header('Location: fee_structures_manage.php');
    exit();
}

// Fetch fee structure details with comprehensive information
$stmt = $pdo->prepare("
    SELECT 
        fs.*,
        c.id as class_id,
        c.class_name,
        c.class_teacher_id,
        ct.full_name as class_teacher_name,
        u.full_name as created_by_name,
        u.email as created_by_email,
        a.full_name as approved_by_name,
        s.full_name as submitted_by_name,
        (SELECT COUNT(*) FROM fee_structure_items WHERE fee_structure_id = fs.id) as item_count,
        (SELECT SUM(amount) FROM fee_structure_items WHERE fee_structure_id = fs.id) as total_amount,
        (SELECT SUM(amount) FROM fee_structure_items WHERE fee_structure_id = fs.id AND is_mandatory = 1) as mandatory_total,
        (SELECT COUNT(*) FROM fee_structure_items WHERE fee_structure_id = fs.id AND is_mandatory = 1) as mandatory_count,
        (SELECT COUNT(*) FROM invoices WHERE fee_structure_id = fs.id) as invoice_count,
        (SELECT COUNT(*) FROM invoices WHERE fee_structure_id = fs.id AND status = 'paid') as paid_invoice_count,
        (SELECT COUNT(*) FROM invoices WHERE fee_structure_id = fs.id AND status = 'unpaid') as unpaid_invoice_count,
        (SELECT COUNT(*) FROM invoices WHERE fee_structure_id = fs.id AND status = 'partial') as partial_invoice_count,
        (SELECT COUNT(*) FROM students WHERE class_id = fs.class_id AND status = 'active') as total_students,
        (SELECT SUM(balance) FROM invoices WHERE fee_structure_id = fs.id) as total_outstanding,
        (SELECT SUM(amount_paid) FROM invoices WHERE fee_structure_id = fs.id) as total_collected
    FROM fee_structures fs
    LEFT JOIN classes c ON fs.class_id = c.id
    LEFT JOIN users ct ON c.class_teacher_id = ct.id
    LEFT JOIN users u ON fs.created_by = u.id
    LEFT JOIN users a ON fs.approved_by = a.id
    LEFT JOIN users s ON fs.submitted_by = s.id
    WHERE fs.id = ?
");
$stmt->execute([$structure_id]);
$structure = $stmt->fetch();

if (!$structure) {
    $_SESSION['error'] = 'Fee structure not found';
    header('Location: fee_structures_manage.php');
    exit();
}

// Fetch fee items with categories
$items = $pdo->prepare("
    SELECT * FROM fee_structure_items 
    WHERE fee_structure_id = ? 
    ORDER BY is_mandatory DESC, amount DESC
");
$items->execute([$structure_id]);
$fee_items = $items->fetchAll();

// Fetch recent invoices generated from this structure
$invoices = $pdo->prepare("
    SELECT 
        i.*,
        s.full_name as student_name,
        s.admission_number,
        DATEDIFF(CURDATE(), i.due_date) as days_overdue,
        CASE 
            WHEN i.status = 'paid' THEN 'Paid'
            WHEN i.status = 'unpaid' AND i.due_date < CURDATE() THEN 'Overdue'
            WHEN i.status = 'partial' AND i.due_date < CURDATE() THEN 'Overdue'
            ELSE i.status
        END as display_status
    FROM invoices i
    JOIN students s ON i.student_id = s.id
    WHERE i.fee_structure_id = ?
    ORDER BY i.created_at DESC
    LIMIT 20
");
$invoices->execute([$structure_id]);
$recent_invoices = $invoices->fetchAll();

// Fetch payment statistics for this structure
$payment_stats = $pdo->prepare("
    SELECT 
        DATE_FORMAT(p.payment_date, '%Y-%m') as month,
        COUNT(*) as payment_count,
        SUM(p.amount) as total_collected
    FROM payments p
    JOIN invoices i ON p.invoice_id = i.id
    WHERE i.fee_structure_id = ?
    GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
    ORDER BY month DESC
    LIMIT 12
");
$payment_stats->execute([$structure_id]);
$monthly_payments = $payment_stats->fetchAll();

// Calculate collection rate
$collection_rate = $structure['total_amount'] > 0 
    ? ($structure['total_collected'] / ($structure['total_amount'] * $structure['total_students'])) * 100 
    : 0;

// Determine status color and icon
$status_colors = [
    'draft' => ['bg' => 'rgba(108, 117, 125, 0.15)', 'text' => '#6c757d', 'icon' => 'fa-pen'],
    'pending' => ['bg' => 'rgba(248, 150, 30, 0.15)', 'text' => '#f8961e', 'icon' => 'fa-clock'],
    'approved' => ['bg' => 'rgba(76, 201, 240, 0.15)', 'text' => '#4cc9f0', 'icon' => 'fa-check-circle'],
    'rejected' => ['bg' => 'rgba(249, 65, 68, 0.15)', 'text' => '#f94144', 'icon' => 'fa-times-circle']
];
$status = $structure['status'] ?? 'draft';
$status_color = $status_colors[$status] ?? $status_colors['draft'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo htmlspecialchars($structure['structure_name']); ?></title>
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

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .header-title h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1.2rem;
            border-radius: 30px;
            font-size: 0.9rem;
            font-weight: 600;
            background: <?php echo $status_color['bg']; ?>;
            color: <?php echo $status_color['text']; ?>;
            border: 1px solid <?php echo $status_color['text']; ?>20;
        }

        .header-meta {
            display: flex;
            gap: 2rem;
            margin-top: 0.5rem;
            color: var(--gray);
            font-size: 0.95rem;
        }

        .header-meta i {
            color: var(--primary);
            width: 18px;
        }

        .header-actions {
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius-md);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
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

        .action-btn.outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        .action-btn.outline:hover {
            background: var(--primary);
            color: white;
        }

        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .kpi-card {
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

        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .kpi-card.total { border-left-color: var(--primary); }
        .kpi-card.items { border-left-color: var(--success); }
        .kpi-card.mandatory { border-left-color: var(--warning); }
        .kpi-card.students { border-left-color: var(--purple); }
        .kpi-card.invoices { border-left-color: var(--info); }
        .kpi-card.collected { border-left-color: var(--success-dark); }

        .kpi-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .kpi-card.total .kpi-icon { background: var(--gradient-1); }
        .kpi-card.items .kpi-icon { background: var(--gradient-3); }
        .kpi-card.mandatory .kpi-icon { background: var(--gradient-5); }
        .kpi-card.students .kpi-icon { background: var(--gradient-2); }
        .kpi-card.invoices .kpi-icon { background: var(--gradient-4); }
        .kpi-card.collected .kpi-icon { background: var(--gradient-3); }

        .kpi-content {
            flex: 1;
        }

        .kpi-label {
            font-size: 0.85rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .kpi-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            line-height: 1.2;
        }

        .kpi-trend {
            font-size: 0.85rem;
            color: var(--gray);
            margin-top: 0.3rem;
        }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .chart-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
        }

        .chart-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-xl);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light);
        }

        .chart-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-container {
            height: 300px;
            position: relative;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Details Card */
        .details-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
        }

        .card-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h2 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.2rem;
            font-weight: 600;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .info-item {
            background: var(--light);
            border-radius: var(--border-radius-md);
            padding: 1rem;
        }

        .info-label {
            font-size: 0.75rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.3rem;
        }

        .info-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
        }

        .info-value small {
            font-size: 0.85rem;
            color: var(--gray);
            font-weight: normal;
        }

        .description-box {
            background: var(--light);
            border-radius: var(--border-radius-md);
            padding: 1rem;
            color: var(--dark);
            line-height: 1.6;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--primary);
        }

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }

        .items-table th {
            padding: 1rem;
            text-align: left;
            background: var(--light);
            color: var(--dark);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
        }

        .items-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--light);
        }

        .items-table tfoot td {
            padding: 1rem;
            background: var(--light);
            font-weight: 700;
        }

        .mandatory-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        /* Status Timeline */
        .timeline {
            margin-top: 1.5rem;
        }

        .timeline-item {
            display: flex;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid var(--light);
        }

        .timeline-item:last-child {
            border-bottom: none;
        }

        .timeline-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
        }

        .timeline-content {
            flex: 1;
        }

        .timeline-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .timeline-meta {
            font-size: 0.85rem;
            color: var(--gray);
        }

        /* Invoice List */
        .invoice-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: var(--light);
            border-radius: var(--border-radius-md);
            margin-bottom: 0.5rem;
            transition: var(--transition);
        }

        .invoice-item:hover {
            transform: translateX(5px);
            background: rgba(67, 97, 238, 0.1);
        }

        .invoice-info {
            flex: 1;
        }

        .invoice-number {
            font-weight: 600;
            color: var(--dark);
        }

        .invoice-student {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .invoice-amount {
            font-weight: 600;
            color: var(--primary);
            margin-right: 1rem;
        }

        .invoice-status {
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
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

        /* Progress Bar */
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
            background: linear-gradient(90deg, var(--success), var(--success-dark));
            border-radius: 4px;
            transition: width 0.3s;
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

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .header-content {
                flex-direction: column;
            }
            
            .header-meta {
                flex-wrap: wrap;
                gap: 1rem;
            }
            
            .kpi-grid {
                grid-template-columns: 1fr;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
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
        .stagger-item:nth-child(2) { animation-delay: 0.15s; }
        .stagger-item:nth-child(3) { animation-delay: 0.2s; }
        .stagger-item:nth-child(4) { animation-delay: 0.25s; }
        .stagger-item:nth-child(5) { animation-delay: 0.3s; }
        .stagger-item:nth-child(6) { animation-delay: 0.35s; }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        <!-- Back Button -->
        <div class="back-button animate">
            <a href="fee_structures_manage.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Fee Structures
            </a>
        </div>

        <!-- Page Header -->
        <div class="page-header animate">
            <div class="header-content">
                <div>
                    <div class="header-title">
                        <h1>
                            <?php echo htmlspecialchars($structure['structure_name']); ?>
                            <span class="status-badge">
                                <i class="fas <?php echo $status_color['icon']; ?>"></i>
                                <?php echo ucfirst($structure['status']); ?>
                            </span>
                        </h1>
                    </div>
                    <div class="header-meta">
                        <span><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($structure['class_name']); ?></span>
                        <span><i class="fas fa-calendar"></i> Term <?php echo $structure['term']; ?></span>
                        <span><i class="fas fa-book"></i> <?php echo $structure['academic_year_id']; ?></span>
                        <span><i class="fas fa-user"></i> Created by <?php echo htmlspecialchars($structure['created_by_name'] ?? 'System'); ?></span>
                    </div>
                </div>
                
                <div class="header-actions">
                    <?php if ($structure['status'] == 'draft' && ((($_SESSION['user_role'] ?? $_SESSION['role'] ?? '') === 'admin') || $structure['created_by'] == $_SESSION['user_id'])): ?>
                        <a href="fee_structures_manage.php?edit=<?php echo $structure['id']; ?>" class="action-btn warning">
                            <i class="fas fa-edit"></i> Edit Structure
                        </a>
                        <button class="action-btn success" onclick="submitForApproval(<?php echo $structure['id']; ?>)">
                            <i class="fas fa-paper-plane"></i> Submit for Approval
                        </button>
                    <?php endif; ?>
                    
                    <?php if ($structure['status'] == 'approved'): ?>
                        <a href="generate_invoices.php?structure_id=<?php echo $structure['id']; ?>" class="action-btn success">
                            <i class="fas fa-file-invoice"></i> Generate Invoices
                        </a>
                    <?php endif; ?>
                    
                    <?php if (($_SESSION['user_role'] ?? $_SESSION['role'] ?? '') === 'admin' && $structure['status'] == 'pending'): ?>
                        <a href="fee_structure_approvals.php?id=<?php echo $structure['id']; ?>" class="action-btn primary">
                            <i class="fas fa-check-double"></i> Review
                        </a>
                    <?php endif; ?>
                    
                    <button class="action-btn outline" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card total stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-calculator"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Total Amount</div>
                    <div class="kpi-value">KES <?php echo number_format($structure['total_amount'] ?? 0, 2); ?></div>
                    <div class="kpi-trend">Per student</div>
                </div>
            </div>
            
            <div class="kpi-card items stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-list"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Fee Items</div>
                    <div class="kpi-value"><?php echo $structure['item_count']; ?></div>
                    <div class="kpi-trend"><?php echo $structure['mandatory_count']; ?> mandatory items</div>
                </div>
            </div>
            
            <div class="kpi-card mandatory stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-star"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Mandatory Total</div>
                    <div class="kpi-value">KES <?php echo number_format($structure['mandatory_total'] ?? 0, 2); ?></div>
                    <div class="kpi-trend">Required fees</div>
                </div>
            </div>
            
            <div class="kpi-card students stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Affected Students</div>
                    <div class="kpi-value"><?php echo $structure['total_students']; ?></div>
                    <div class="kpi-trend">Active in class</div>
                </div>
            </div>
            
            <div class="kpi-card invoices stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Invoices Generated</div>
                    <div class="kpi-value"><?php echo $structure['invoice_count']; ?></div>
                    <div class="kpi-trend">
                        <?php echo $structure['paid_invoice_count']; ?> paid, <?php echo $structure['unpaid_invoice_count']; ?> unpaid
                    </div>
                </div>
            </div>
            
            <div class="kpi-card collected stagger-item">
                <div class="kpi-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Total Collected</div>
                    <div class="kpi-value">KES <?php echo number_format($structure['total_collected'] ?? 0, 2); ?></div>
                    <div class="kpi-trend">
                        <?php echo number_format($collection_rate, 1); ?>% collection rate
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Grid -->
        <?php if ($structure['status'] == 'approved' && !empty($monthly_payments)): ?>
        <div class="charts-grid">
            <!-- Collection Trend Chart -->
            <div class="chart-card animate">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-line" style="color: var(--primary);"></i> Monthly Collection Trend</h3>
                    <span class="badge">Last 12 months</span>
                </div>
                <div class="chart-container">
                    <canvas id="collectionChart"></canvas>
                </div>
            </div>

            <!-- Invoice Status Distribution -->
            <div class="chart-card animate">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-pie" style="color: var(--purple);"></i> Invoice Status Distribution</h3>
                    <span class="badge">By count</span>
                </div>
                <div class="chart-container">
                    <canvas id="invoiceStatusChart"></canvas>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Left Column - Details & Items -->
            <div class="stack">
                <!-- Structure Details -->
                <div class="details-card animate">
                    <div class="card-header">
                        <h2><i class="fas fa-info-circle"></i> Structure Details</h2>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($structure['description'])): ?>
                        <div class="description-box">
                            <?php echo nl2br(htmlspecialchars($structure['description'])); ?>
                        </div>
                        <?php endif; ?>

                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Class</div>
                                <div class="info-value"><?php echo htmlspecialchars($structure['class_name']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Term</div>
                                <div class="info-value"><?php echo $structure['term']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Academic Year</div>
                                <div class="info-value"><?php echo $structure['academic_year_id']; ?></div>
                            </div>
                            <?php if ($structure['class_teacher_name']): ?>
                            <div class="info-item">
                                <div class="info-label">Class Teacher</div>
                                <div class="info-value"><?php echo htmlspecialchars($structure['class_teacher_name']); ?></div>
                            </div>
                            <?php endif; ?>
                            <div class="info-item">
                                <div class="info-label">Created On</div>
                                <div class="info-value"><?php echo date('d M Y', strtotime($structure['created_at'])); ?></div>
                            </div>
                            <?php if ($structure['approved_at']): ?>
                            <div class="info-item">
                                <div class="info-label">Approved On</div>
                                <div class="info-value"><?php echo date('d M Y', strtotime($structure['approved_at'])); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Progress Bar for Collection -->
                        <?php if ($structure['status'] == 'approved' && $structure['total_students'] > 0): ?>
                        <div style="margin-top: 1rem;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                <span>Overall Collection Progress</span>
                                <span><?php echo number_format($collection_rate, 1); ?>%</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $collection_rate; ?>%;"></div>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-top: 0.3rem; font-size: 0.85rem; color: var(--gray);">
                                <span>Collected: KES <?php echo number_format($structure['total_collected'] ?? 0, 2); ?></span>
                                <span>Outstanding: KES <?php echo number_format($structure['total_outstanding'] ?? 0, 2); ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Fee Items -->
                <div class="details-card animate">
                    <div class="card-header">
                        <h2><i class="fas fa-list-ul"></i> Fee Items</h2>
                        <span class="badge"><?php echo $structure['item_count']; ?> items</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($fee_items)): ?>
                            <table class="items-table">
                                <thead>
                                    <tr>
                                        <th>Item Name</th>
                                        <th>Description</th>
                                        <th>Category</th>
                                        <th style="text-align: right;">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($fee_items as $item): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                            <?php if ($item['is_mandatory']): ?>
                                            <span class="mandatory-badge">Required</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($item['description'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($item['category'] ?? 'General'); ?></td>
                                        <td style="text-align: right; font-weight: 600; color: var(--primary);">
                                            KES <?php echo number_format($item['amount'], 2); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="3" style="text-align: right; font-weight: 700;">Total per Student:</td>
                                        <td style="text-align: right; font-weight: 700; color: var(--success);">
                                            KES <?php echo number_format($structure['total_amount'] ?? 0, 2); ?>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-list"></i>
                                <p>No fee items found in this structure</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column - Status & Invoices -->
            <div class="stack">
                <!-- Status Timeline -->
                <div class="details-card animate">
                    <div class="card-header">
                        <h2><i class="fas fa-history"></i> Status Timeline</h2>
                    </div>
                    <div class="card-body">
                        <div class="timeline">
                            <div class="timeline-item">
                                <div class="timeline-icon">
                                    <i class="fas fa-plus"></i>
                                </div>
                                <div class="timeline-content">
                                    <div class="timeline-title">Structure Created</div>
                                    <div class="timeline-meta">
                                        <?php echo date('d M Y H:i', strtotime($structure['created_at'])); ?> 
                                        by <?php echo htmlspecialchars($structure['created_by_name'] ?? 'System'); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($structure['submitted_at']): ?>
                            <div class="timeline-item">
                                <div class="timeline-icon">
                                    <i class="fas fa-paper-plane"></i>
                                </div>
                                <div class="timeline-content">
                                    <div class="timeline-title">Submitted for Approval</div>
                                    <div class="timeline-meta">
                                        <?php echo date('d M Y H:i', strtotime($structure['submitted_at'])); ?>
                                        by <?php echo htmlspecialchars($structure['submitted_by_name'] ?? 'System'); ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($structure['approved_at']): ?>
                            <div class="timeline-item">
                                <div class="timeline-icon" style="color: var(--success);">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="timeline-content">
                                    <div class="timeline-title">Approved</div>
                                    <div class="timeline-meta">
                                        <?php echo date('d M Y H:i', strtotime($structure['approved_at'])); ?>
                                        by <?php echo htmlspecialchars($structure['approved_by_name'] ?? 'Admin'); ?>
                                    </div>
                                    <?php if (!empty($structure['approval_remarks'])): ?>
                                    <div style="margin-top: 0.3rem; padding: 0.5rem; background: var(--light); border-radius: var(--border-radius-sm);">
                                        <i class="fas fa-comment"></i> <?php echo htmlspecialchars($structure['approval_remarks']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($structure['rejected_at']): ?>
                            <div class="timeline-item">
                                <div class="timeline-icon" style="color: var(--danger);">
                                    <i class="fas fa-times-circle"></i>
                                </div>
                                <div class="timeline-content">
                                    <div class="timeline-title">Rejected</div>
                                    <div class="timeline-meta">
                                        <?php echo date('d M Y H:i', strtotime($structure['rejected_at'])); ?>
                                        by <?php echo htmlspecialchars($structure['approved_by_name'] ?? 'Admin'); ?>
                                    </div>
                                    <?php if (!empty($structure['rejection_reason'])): ?>
                                    <div style="margin-top: 0.3rem; padding: 0.5rem; background: var(--light); border-radius: var(--border-radius-sm); color: var(--danger);">
                                        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($structure['rejection_reason']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Invoices -->
                <div class="details-card animate">
                    <div class="card-header">
                        <h2><i class="fas fa-file-invoice"></i> Recent Invoices</h2>
                        <?php if ($structure['invoice_count'] > 0): ?>
                        <a href="invoices.php?structure_id=<?php echo $structure['id']; ?>" style="color: white; text-decoration: underline;">View All</a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recent_invoices)): ?>
                            <?php foreach ($recent_invoices as $invoice): ?>
                            <a href="invoice_details.php?id=<?php echo $invoice['id']; ?>" style="text-decoration: none;">
                                <div class="invoice-item">
                                    <div class="invoice-info">
                                        <span class="invoice-number">#<?php echo htmlspecialchars($invoice['invoice_no']); ?></span>
                                        <div class="invoice-student">
                                            <?php echo htmlspecialchars($invoice['student_name']); ?> (<?php echo $invoice['admission_number']; ?>)
                                        </div>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 1rem;">
                                        <span class="invoice-amount">KES <?php echo number_format($invoice['total_amount'], 2); ?></span>
                                        <span class="invoice-status status-<?php echo $invoice['display_status']; ?>">
                                            <?php echo ucfirst($invoice['display_status']); ?>
                                        </span>
                                    </div>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-file-invoice"></i>
                                <p>No invoices have been generated yet</p>
                                <?php if ($structure['status'] == 'approved'): ?>
                                <a href="generate_invoices.php?structure_id=<?php echo $structure['id']; ?>" class="action-btn success" style="margin-top: 1rem; display: inline-block;">
                                    <i class="fas fa-plus"></i> Generate Invoices
                                </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="details-card animate">
                    <div class="card-header">
                        <h2><i class="fas fa-chart-simple"></i> Quick Stats</h2>
                    </div>
                    <div class="card-body">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div style="background: var(--light); padding: 1rem; border-radius: var(--border-radius-md); text-align: center;">
                                <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary);"><?php echo $structure['item_count']; ?></div>
                                <div style="font-size: 0.85rem; color: var(--gray);">Total Items</div>
                            </div>
                            <div style="background: var(--light); padding: 1rem; border-radius: var(--border-radius-md); text-align: center;">
                                <div style="font-size: 1.5rem; font-weight: 700; color: var(--warning);"><?php echo $structure['mandatory_count']; ?></div>
                                <div style="font-size: 0.85rem; color: var(--gray);">Mandatory</div>
                            </div>
                            <div style="background: var(--light); padding: 1rem; border-radius: var(--border-radius-md); text-align: center;">
                                <div style="font-size: 1.5rem; font-weight: 700; color: var(--success);"><?php echo $structure['total_students']; ?></div>
                                <div style="font-size: 0.85rem; color: var(--gray);">Students</div>
                            </div>
                            <div style="background: var(--light); padding: 1rem; border-radius: var(--border-radius-md); text-align: center;">
                                <div style="font-size: 1.5rem; font-weight: 700; color: var(--danger);"><?php echo $structure['invoice_count']; ?></div>
                                <div style="font-size: 0.85rem; color: var(--gray);">Invoices</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Collection Trend Chart
        <?php if (!empty($monthly_payments)): ?>
        const collectionCtx = document.getElementById('collectionChart')?.getContext('2d');
        if (collectionCtx) {
            const months = <?php echo json_encode(array_column(array_reverse($monthly_payments), 'month')); ?>;
            const amounts = <?php echo json_encode(array_column(array_reverse($monthly_payments), 'total_collected')); ?>;
            
            new Chart(collectionCtx, {
                type: 'line',
                data: {
                    labels: months.map(m => {
                        const [year, month] = m.split('-');
                        return new Date(year, month-1).toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
                    }),
                    datasets: [{
                        label: 'Monthly Collection',
                        data: amounts,
                        borderColor: '#4cc9f0',
                        backgroundColor: 'rgba(76, 201, 240, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#4cc9f0',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'KES ' + context.parsed.y.toLocaleString('en-KE', {maximumFractionDigits: 2});
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return 'KES ' + (value/1000).toFixed(0) + 'k';
                                }
                            }
                        }
                    }
                }
            });
        }
        <?php endif; ?>

        // Invoice Status Chart
        <?php if ($structure['invoice_count'] > 0): ?>
        const statusCtx = document.getElementById('invoiceStatusChart')?.getContext('2d');
        if (statusCtx) {
            const statusData = {
                labels: ['Paid', 'Unpaid', 'Partial'],
                data: [
                    <?php echo $structure['paid_invoice_count']; ?>,
                    <?php echo $structure['unpaid_invoice_count']; ?>,
                    <?php echo $structure['partial_invoice_count']; ?>
                ]
            };
            
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: statusData.labels,
                    datasets: [{
                        data: statusData.data,
                        backgroundColor: [
                            '#4cc9f0',
                            '#f8961e',
                            '#7209b7'
                        ],
                        borderColor: '#fff',
                        borderWidth: 3,
                        hoverOffset: 20
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { usePointStyle: true }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((context.raw / total) * 100).toFixed(1);
                                    return context.label + ': ' + context.raw + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }
        <?php endif; ?>

        // Submit for Approval
        function submitForApproval(structureId) {
            Swal.fire({
                title: 'Submit for Approval?',
                text: 'This fee structure will be sent to admin for review.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#4cc9f0',
                confirmButtonText: 'Yes, submit',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'fee_structures_manage.php';
                    form.innerHTML = `
                        <input type="hidden" name="submit_for_approval" value="1">
                        <input type="hidden" name="structure_id" value="${structureId}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
    </script>
</body>
</html>
