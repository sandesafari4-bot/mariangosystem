<?php
include '../config.php';
checkAuth();
checkRole(['accountant', 'admin']);

$page_title = 'M-Pesa Transactions - ' . SCHOOL_NAME;

// Helper functions
function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function columnsFor(PDO $pdo, string $table): array {
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        return array_fill_keys($columns, true);
    } catch (Exception $e) {
        return [];
    }
}

function money($amount): string {
    return 'KES ' . number_format((float) $amount, 2);
}

function readRecentCallbackLog(): array {
    $logDir = dirname(__DIR__) . '/logs';
    $files = glob($logDir . '/mpesa_callback_*.log');
    if (!$files) {
        return ['file' => null, 'lines' => []];
    }

    rsort($files);
    $file = $files[0];
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return ['file' => basename($file), 'lines' => array_slice($lines, -12)];
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? '';
$paymentSearch = trim($_GET['search'] ?? '');
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Check for tables
$hasMpesaTransactions = tableExists($pdo, 'mpesa_transactions');
$paymentMethods = columnsFor($pdo, 'payment_methods');
$paymentsColumns = columnsFor($pdo, 'payments');
$mpesaColumns = columnsFor($pdo, 'mpesa_transactions');

// Get M-Pesa transactions
$mpesaTransactions = [];
if ($hasMpesaTransactions) {
    $query = "SELECT * FROM mpesa_transactions";
    $params = [];
    $conditions = [];

    if ($statusFilter !== '' && isset($mpesaColumns['status'])) {
        $conditions[] = "status = ?";
        $params[] = $statusFilter;
    }

    if ($paymentSearch !== '') {
        $searchClauses = [];
        foreach (['receipt', 'phone', 'accountref', 'transaction_id'] as $column) {
            if (isset($mpesaColumns[$column])) {
                $searchClauses[] = "$column LIKE ?";
                $params[] = '%' . $paymentSearch . '%';
            }
        }
        if ($searchClauses) {
            $conditions[] = '(' . implode(' OR ', $searchClauses) . ')';
        }
    }

    if ($date_from && isset($mpesaColumns['transaction_time'])) {
        $conditions[] = "DATE(transaction_time) >= ?";
        $params[] = $date_from;
    }
    
    if ($date_to && isset($mpesaColumns['transaction_time'])) {
        $conditions[] = "DATE(transaction_time) <= ?";
        $params[] = $date_to;
    }

    if ($conditions) {
        $query .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $orderColumn = isset($mpesaColumns['transaction_time']) ? 'transaction_time' : (isset($mpesaColumns['created_at']) ? 'created_at' : 'id');
    $query .= " ORDER BY $orderColumn DESC LIMIT 100";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $mpesaTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get posted M-Pesa payments
$mpesaPayments = [];
if ($paymentsColumns) {
    $dateColumn = isset($paymentsColumns['payment_date']) ? 'payment_date' : (isset($paymentsColumns['paid_at']) ? 'paid_at' : 'created_at');
    $methodJoin = tableExists($pdo, 'payment_methods');
    $methodLabelSelect = $methodJoin ? ', pm.label as payment_method_label, pm.code as payment_method_code' : '';
    $methodJoinSql = $methodJoin && isset($paymentsColumns['payment_method_id']) ? ' LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id ' : '';

    $mpesaFilterSql = [];
    foreach (['mpesa_receipt', 'reference_no', 'reference', 'transaction_ref', 'transaction_id'] as $column) {
        if (isset($paymentsColumns[$column])) {
            $mpesaFilterSql[] = "p.$column IS NOT NULL AND p.$column <> ''";
        }
    }
    if ($methodJoin) {
        $mpesaFilterSql[] = "(pm.code = 'mpesa')";
    }

    if ($mpesaFilterSql) {
        $query = "
            SELECT p.*, s.full_name as student_name, s.admission_number, i.invoice_no
            $methodLabelSelect
            FROM payments p
            LEFT JOIN students s ON p.student_id = s.id
            LEFT JOIN invoices i ON p.invoice_id = i.id
            $methodJoinSql
            WHERE (" . implode(' OR ', $mpesaFilterSql) . ")
            ORDER BY p.$dateColumn DESC
            LIMIT 50
        ";
        $mpesaPayments = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Calculate summary statistics
$summary = [
    'transactions' => count($mpesaTransactions),
    'received_total' => array_sum(array_map(fn($row) => (float) ($row['amount'] ?? 0), $mpesaTransactions)),
    'completed_count' => count(array_filter($mpesaTransactions, fn($row) => ($row['status'] ?? '') === 'processed' || ($row['status'] ?? '') === 'completed')),
    'failed_count' => count(array_filter($mpesaTransactions, fn($row) => ($row['status'] ?? '') === 'failed')),
    'payments_count' => count($mpesaPayments),
];

// Get pending files
$pendingDir = dirname(__DIR__) . '/logs/mpesa_pending';
$pendingFiles = is_dir($pendingDir) ? glob($pendingDir . '/*.json') : [];

// Get recent callback log
$recentLog = readRecentCallbackLog();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
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
            --gradient-mpesa: linear-gradient(135deg, #072b31 0%, #0f766e 60%, #14b8a6 100%);
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
            background: var(--gradient-mpesa);
            border-radius: var(--border-radius-xl);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
            color: white;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }

        .page-header::after {
            content: '';
            position: absolute;
            bottom: -50%;
            left: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.08);
            border-radius: 50%;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            z-index: 1;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255,255,255,0.15);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1rem;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .page-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .page-header p {
            opacity: 0.9;
            font-size: 1rem;
            max-width: 600px;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
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

        .btn-warning {
            background: var(--gradient-5);
            color: white;
        }

        .btn-light {
            background: white;
            color: var(--dark);
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

        .btn-glass {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(5px);
        }

        .btn-glass:hover {
            background: rgba(255,255,255,0.25);
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

        .stat-card.total { border-left-color: var(--primary); }
        .stat-card.completed { border-left-color: var(--success); }
        .stat-card.failed { border-left-color: var(--danger); }
        .stat-card.payments { border-left-color: var(--purple); }
        .stat-card.pending { border-left-color: var(--warning); }

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

        /* Filter Section */
        .filter-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .filter-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--dark);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        /* Layout Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .stack {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        /* Cards */
        .card {
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
            flex-wrap: wrap;
            gap: 1rem;
        }

        .card-header h2 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.2rem;
            font-weight: 600;
        }

        .card-header .meta {
            font-size: 0.85rem;
            opacity: 0.9;
        }

        .card-body {
            padding: 1.5rem;
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

        /* Status Pills */
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .pill-pending {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        .pill-ok {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .pill-fail {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        .pill-info {
            background: rgba(67, 97, 238, 0.15);
            color: var(--primary);
        }

        /* Mini Grid */
        .mini-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .status-item {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: var(--light);
            border-radius: 20px;
            font-size: 0.85rem;
            color: var(--dark);
        }

        /* Log Box */
        .log-box {
            background: #1e293b;
            color: #e2e8f0;
            border-radius: var(--border-radius-md);
            padding: 1rem;
            font-family: 'Consolas', monospace;
            font-size: 0.85rem;
            line-height: 1.5;
            overflow-x: auto;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
            background: var(--light);
            border-radius: var(--border-radius-md);
            border: 1px dashed var(--gray-light);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        /* Guide Section */
        .guide-card {
            background: var(--light);
            border-radius: var(--border-radius-md);
            padding: 1rem;
        }

        .guide-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .guide-item:last-child {
            border-bottom: none;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .card-header {
                flex-direction: column;
                align-items: flex-start;
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
        .stagger-item:nth-child(5) { animation-delay: 0.5s; }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header animate">
            <div class="header-content">
                <div>
                    <div class="header-badge">
                        <i class="fas fa-mobile-alt"></i>
                        <span>M-Pesa Operations</span>
                    </div>
                    <h1>M-Pesa Transaction Monitor</h1>
                    <p>Track STK prompts, callbacks, and posted payments in real-time. Monitor the complete lifecycle of mobile money transactions.</p>
                </div>
                <div class="header-actions">
                    <a href="dashboard.php" class="btn btn-light">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>

                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card total stagger-item">
                <div class="stat-number"><?php echo $summary['transactions']; ?></div>
                <div class="stat-label">Total Transactions</div>
                <div class="stat-detail"><?php echo money($summary['received_total']); ?> total value</div>
            </div>
            <div class="stat-card completed stagger-item">
                <div class="stat-number"><?php echo $summary['completed_count']; ?></div>
                <div class="stat-label">Completed</div>
                <div class="stat-detail">Successfully processed</div>
            </div>
            <div class="stat-card failed stagger-item">
                <div class="stat-number"><?php echo $summary['failed_count']; ?></div>
                <div class="stat-label">Failed</div>
                <div class="stat-detail">Cancelled or errored</div>
            </div>
            <div class="stat-card payments stagger-item">
                <div class="stat-number"><?php echo $summary['payments_count']; ?></div>
                <div class="stat-label">Posted Payments</div>
                <div class="stat-detail">In finance ledger</div>
            </div>
            <div class="stat-card pending stagger-item">
                <div class="stat-number"><?php echo count($pendingFiles); ?></div>
                <div class="stat-label">Pending Callbacks</div>
                <div class="stat-detail">Awaiting response</div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section animate">
            <div class="filter-header">
                <h3><i class="fas fa-filter"></i> Filter Transactions</h3>
                <span class="badge"><?php echo $summary['transactions']; ?> records</span>
            </div>
            <form method="GET" id="filterForm">
                <div class="filter-grid">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Phone, receipt, reference..." 
                               value="<?php echo htmlspecialchars($paymentSearch); ?>">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="">All Statuses</option>
                            <option value="received" <?php echo $statusFilter == 'received' ? 'selected' : ''; ?>>Received</option>
                            <option value="matched" <?php echo $statusFilter == 'matched' ? 'selected' : ''; ?>>Matched</option>
                            <option value="processed" <?php echo $statusFilter == 'processed' ? 'selected' : ''; ?>>Processed</option>
                            <option value="completed" <?php echo $statusFilter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="failed" <?php echo $statusFilter == 'failed' ? 'selected' : ''; ?>>Failed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="form-group">
                        <label>To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                    </div>
                    <div class="form-group" style="display: flex; gap: 0.5rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <a href="mpesa_transactions.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Main Content Grid -->
        <div class="content-grid">
            <!-- Left Column - Transaction Tables -->
            <div class="stack">
                <!-- M-Pesa Transactions Table -->
                <div class="card animate">
                    <div class="card-header">
                        <h2><i class="fas fa-exchange-alt"></i> M-Pesa Transaction Audit Trail</h2>
                        <span class="meta">From mpesa_transactions table</span>
                    </div>
                    <div class="card-body">
                        <?php if ($hasMpesaTransactions && !empty($mpesaTransactions)): ?>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Receipt</th>
                                            <th>Phone</th>
                                            <th>Amount</th>
                                            <th>Account Ref</th>
                                            <th>Time</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($mpesaTransactions as $row): ?>
                                            <?php
                                            $status = strtolower((string) ($row['status'] ?? 'received'));
                                            $pillClass = $status === 'failed' ? 'pill-fail' : 
                                                        (($status === 'processed' || $status === 'completed') ? 'pill-ok' : 
                                                        (($status === 'matched') ? 'pill-info' : 'pill-pending'));
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($row['receipt'] ?? $row['transaction_id'] ?? 'Pending'); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($row['phone'] ?? '-'); ?></td>
                                                <td><strong style="color: var(--primary);"><?php echo money($row['amount'] ?? 0); ?></strong></td>
                                                <td><?php echo htmlspecialchars($row['accountref'] ?? '-'); ?></td>
                                                <td>
                                                    <?php 
                                                    $time = $row['transaction_time'] ?? $row['created_at'] ?? null;
                                                    echo $time ? date('d M H:i', strtotime($time)) : '-';
                                                    ?>
                                                </td>
                                                <td>
                                                    <span class="pill <?php echo $pillClass; ?>">
                                                        <i class="fas fa-<?php 
                                                            echo $status === 'failed' ? 'times-circle' : 
                                                                ($status === 'processed' || $status === 'completed' ? 'check-circle' : 
                                                                ($status === 'matched' ? 'link' : 'clock')); 
                                                        ?>"></i>
                                                        <?php echo ucfirst($status); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-exchange-alt"></i>
                                <h3>No Transactions Found</h3>
                                <p>No M-Pesa transaction records are available yet. STK requests and callbacks will appear here once they are logged.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Posted Payments Table -->
                <div class="card animate">
                    <div class="card-header">
                        <h2><i class="fas fa-credit-card"></i> Posted M-Pesa Payments</h2>
                        <span class="meta">In finance ledger</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($mpesaPayments)): ?>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>Invoice</th>
                                            <th>Amount</th>
                                            <th>Reference</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($mpesaPayments as $payment): ?>
                                            <?php
                                            $reference = $payment['mpesa_receipt'] ?? $payment['reference_no'] ?? $payment['reference'] ?? $payment['transaction_ref'] ?? $payment['transaction_id'] ?? '-';
                                            $dateValue = $payment['payment_date'] ?? $payment['paid_at'] ?? $payment['created_at'] ?? '';
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($payment['student_name'] ?? 'Unknown'); ?></strong>
                                                    <?php if (!empty($payment['admission_number'])): ?>
                                                    <br><small><?php echo htmlspecialchars($payment['admission_number']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($payment['invoice_id'])): ?>
                                                        <a href="invoice_details.php?id=<?php echo (int) $payment['invoice_id']; ?>" style="color: var(--primary);">
                                                            #<?php echo htmlspecialchars($payment['invoice_no'] ?? $payment['invoice_id']); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td><strong style="color: var(--success);"><?php echo money($payment['amount'] ?? 0); ?></strong></td>
                                                <td><span class="pill pill-info"><?php echo htmlspecialchars($reference); ?></span></td>
                                                <td><?php echo $dateValue ? date('d M Y', strtotime($dateValue)) : '-'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-credit-card"></i>
                                <h3>No Payments Found</h3>
                                <p>No M-Pesa payments have been posted to invoices yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column - Status & Logs -->
            <div class="stack">
                <!-- Pending Callbacks -->
                <div class="card animate">
                    <div class="card-header">
                        <h2><i class="fas fa-clock"></i> Pending Callback Queue</h2>
                        <span class="meta"><?php echo count($pendingFiles); ?> pending</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($pendingFiles)): ?>
                            <div class="mini-grid">
                                <?php foreach (array_slice($pendingFiles, 0, 8) as $file): ?>
                                    <span class="status-item">
                                        <i class="fas fa-file"></i>
                                        <?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if (count($pendingFiles) > 8): ?>
                                    <span class="status-item">
                                        <i class="fas fa-ellipsis-h"></i>
                                        +<?php echo count($pendingFiles) - 8; ?> more
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state" style="padding: 1.5rem;">
                                <i class="fas fa-check-circle" style="color: var(--success);"></i>
                                <p>No pending STK requests in queue</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Latest Callback Log -->
                <div class="card animate">
                    <div class="card-header">
                        <h2><i class="fas fa-history"></i> Latest Callback Log</h2>
                        <span class="meta"><?php echo $recentLog['file'] ? htmlspecialchars($recentLog['file']) : 'No logs'; ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recentLog['lines'])): ?>
                            <div class="log-box">
                                <?php foreach ($recentLog['lines'] as $line): ?>
                                    <?php echo htmlspecialchars($line) . "\n"; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state" style="padding: 1.5rem;">
                                <i class="fas fa-file"></i>
                                <p>No callback log content available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Status Guide -->
                <div class="card animate">
                    <div class="card-header">
                        <h2><i class="fas fa-question-circle"></i> Status Guide</h2>
                    </div>
                    <div class="card-body">
                        <div class="guide-card">
                            <div class="guide-item">
                                <span class="pill pill-pending"><i class="fas fa-hourglass-half"></i> received</span>
                                <span>STK was sent, callback still pending</span>
                            </div>
                            <div class="guide-item">
                                <span class="pill pill-info"><i class="fas fa-link"></i> matched</span>
                                <span>Callback arrived, payment needs review</span>
                            </div>
                            <div class="guide-item">
                                <span class="pill pill-ok"><i class="fas fa-check-circle"></i> processed</span>
                                <span>Callback posted to ledger successfully</span>
                            </div>
                            <div class="guide-item">
                                <span class="pill pill-ok"><i class="fas fa-check-double"></i> completed</span>
                                <span>Transaction fully completed</span>
                            </div>
                            <div class="guide-item">
                                <span class="pill pill-fail"><i class="fas fa-times-circle"></i> failed</span>
                                <span>User cancelled, timeout, or error</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card animate">
                    <div class="card-header">
                        <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                    </div>
                    <div class="card-body">
                        <div style="display: grid; gap: 0.5rem;">
                            <a href="verify_mpesa_payment.php" class="btn btn-primary">
                                <i class="fas fa-check-circle"></i> Verify Pending Payments
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Auto-refresh every 30 seconds
        setTimeout(() => {
            location.reload();
        }, 30000);
    </script>
</body>
</html>