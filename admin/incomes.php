<?php
include '../config.php';
checkAuth();
checkRole(['admin']);

// Handle new income entry
if ($_POST['action'] === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $income_id = 'INC-' . date('Ymd') . '-' . rand(1000, 9999);
        
        $stmt = $pdo->prepare("
            INSERT INTO income_sources (income_id, source_name, category, amount, income_date, 
                                       description, payment_method, transaction_ref, recorded_by, status)
            VALUES (? , ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        
        $result = $stmt->execute([
            $income_id,
            $_POST['source_name'],
            $_POST['category'],
            $_POST['amount'],
            $_POST['income_date'],
            $_POST['description'],
            $_POST['payment_method'],
            $_POST['transaction_ref'],
            $_SESSION['user_id']
        ]);
        
        if ($result) {
            $_SESSION['success'] = 'Income source added successfully and pending verification';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Error adding income: ' . $e->getMessage();
    }
}

// Handle verification
if ($_POST['action'] === 'verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stmt = $pdo->prepare("
            UPDATE income_sources 
            SET status = 'verified', verified_by = ?, verified_at = NOW()
            WHERE id = ?
        ");
        
        $result = $stmt->execute([$_SESSION['user_id'], $_POST['income_id']]);
        
        if ($result) {
            $_SESSION['success'] = 'Income source verified successfully';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Error verifying income: ' . $e->getMessage();
    }
}

// Get all income sources with filters
$query = "SELECT ins.*, u.full_name as recorded_by_name, v.full_name as verified_by_name
          FROM income_sources ins
          LEFT JOIN users u ON ins.recorded_by = u.id
          LEFT JOIN users v ON ins.verified_by = v.id
          WHERE 1=1";

$params = [];

if (!empty($_GET['status'])) {
    $query .= " AND ins.status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['category'])) {
    $query .= " AND ins.category = ?";
    $params[] = $_GET['category'];
}

if (!empty($_GET['search'])) {
    $query .= " AND (ins.source_name LIKE ? OR ins.income_id LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
}

$query .= " ORDER BY ins.income_date DESC LIMIT 100";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$income_sources = $stmt->fetchAll();

// Get statistics
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total_records,
        COUNT(CASE WHEN status='verified' THEN 1 END) as verified_count,
        COUNT(CASE WHEN status='pending' THEN 1 END) as pending_count,
        SUM(CASE WHEN status='verified' THEN amount ELSE 0 END) as total_verified,
        SUM(CASE WHEN status='pending' THEN amount ELSE 0 END) as total_pending
    FROM income_sources
")->fetch();

// Get categories
$categories = $pdo->query("
    SELECT DISTINCT category FROM income_sources WHERE category IS NOT NULL ORDER BY category
")->fetchAll(PDO::FETCH_COLUMN);

$page_title = "Income Sources - " . SCHOOL_NAME;
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
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <style>
        :root {
            --primary: #3498db;
            --success: #27ae60;
            --danger: #e74c3c;
            --warning: #f39c12;
            --gray: #6c757d;
            --light: #f8f9fa;
            --dark: #2c3e50;
        }

        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            min-height: calc(100vh - 70px);
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .page-header h1 {
            margin: 0;
            color: var(--dark);
            font-size: 2rem;
            flex: 1;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border-left: 5px solid var(--primary);
        }

        .stat-card.success { border-left-color: var(--success); }
        .stat-card.pending { border-left-color: var(--warning); }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--gray);
            text-transform: uppercase;
            font-weight: 600;
        }

        .filters-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            flex: 1;
        }

        .filter-group label {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .filter-group input,
        .filter-group select {
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
        }

        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: var(--primary);
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.95rem;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid var(--light);
        }

        tr:hover {
            background: var(--light);
        }

        .status-badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-verified {
            background: #d4edda;
            color: #155724;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .amount-cell {
            text-align: right;
            font-weight: 600;
            color: var(--primary);
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-small {
            padding: 0.5rem 0.75rem;
            font-size: 0.85rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-small:hover {
            background: #2980b9;
        }

        .btn-approve {
            background: var(--success);
        }

        .btn-approve:hover {
            background: #229954;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid var(--light);
            padding-bottom: 1rem;
        }

        .modal-header h2 {
            margin: 0;
            color: var(--dark);
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .button-group {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        .btn-submit {
            background: var(--success);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-cancel {
            background: var(--gray);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1><i class="fas fa-money-bill-alt"></i> Income Sources</h1>
            <button onclick="openAddIncomeModal()" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Income
            </button>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; ?>
        </div>
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; ?>
        </div>
        <?php unset($_SESSION['error']); endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_records'] ?? 0; ?></div>
                <div class="stat-label">Total Records</div>
            </div>
            <div class="stat-card success">
                <div class="stat-value">KES <?php echo number_format($stats['total_verified'] ?? 0, 2); ?></div>
                <div class="stat-label">Verified Income</div>
            </div>
            <div class="stat-card pending">
                <div class="stat-value">KES <?php echo number_format($stats['total_pending'] ?? 0, 2); ?></div>
                <div class="stat-label">Pending Verification</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <form method="GET" style="display: flex; gap: 1rem; width: 100%;">
                <div class="filter-group" style="flex: 2;">
                    <label>Search</label>
                    <input type="text" name="search" placeholder="Source name or ID..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="pending" <?php echo ($_GET['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="verified" <?php echo ($_GET['status'] ?? '') === 'verified' ? 'selected' : ''; ?>>Verified</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Category</label>
                    <select name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($_GET['category'] ?? '') === $cat ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="align-self: flex-end;">
                    <i class="fas fa-search"></i> Filter
                </button>
            </form>
        </div>

        <!-- Table -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Income ID</th>
                        <th>Source Name</th>
                        <th>Category</th>
                        <th>Amount (KES)</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($income_sources)): ?>
                        <?php foreach ($income_sources as $income): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($income['income_id']); ?></strong></td>
                            <td><?php echo htmlspecialchars($income['source_name']); ?></td>
                            <td><?php echo htmlspecialchars($income['category']); ?></td>
                            <td class="amount-cell">KES <?php echo number_format($income['amount'], 2); ?></td>
                            <td><?php echo date('M d, Y', strtotime($income['income_date'])); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $income['status']; ?>">
                                    <?php echo ucfirst($income['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <?php if ($income['status'] === 'pending'): ?>
                                    <button onclick="verifyIncome(<?php echo $income['id']; ?>)" class="btn-small btn-approve">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <?php endif; ?>
                                    <button onclick="viewIncomeDetails(<?php echo $income['id']; ?>)" class="btn-small">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 2rem; color: var(--gray);">
                            <i class="fas fa-inbox"></i> No income sources found
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add Income Modal -->
    <div id="addIncomeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add Income Source</h2>
                <button class="close-btn" onclick="closeModal('addIncomeModal')">×</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                
                <div class="form-group">
                    <label>Source Name</label>
                    <input type="text" name="source_name" required placeholder="e.g., Donation, Grants, etc.">
                </div>

                <div class="form-group">
                    <label>Category</label>
                    <input type="text" name="category" required placeholder="e.g., Donation, Government Grant">
                </div>

                <div class="form-group">
                    <label>Amount (KES)</label>
                    <input type="number" name="amount" required min="0" step="0.01">
                </div>

                <div class="form-group">
                    <label>Income Date</label>
                    <input type="date" name="income_date" required value="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="form-group">
                    <label>Payment Method</label>
                    <select name="payment_method">
                        <option value="">Select method</option>
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cheque">Cheque</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Transaction Reference</label>
                    <input type="text" name="transaction_ref" placeholder="Optional reference number">
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" placeholder="Additional details..."></textarea>
                </div>

                <div class="button-group">
                    <button type="button" class="btn-cancel" onclick="closeModal('addIncomeModal')">Cancel</button>
                    <button type="submit" class="btn-submit">Add Income</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.js"></script>
    <script>
        function openAddIncomeModal() {
            document.getElementById('addIncomeModal').classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        function verifyIncome(incomeId) {
            Swal.fire({
                title: 'Verify Income?',
                text: 'Mark this income as verified',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Verify',
                cancelButtonText: 'Cancel'
            }).then(result => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="verify">
                        <input type="hidden" name="income_id" value="${incomeId}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        function viewIncomeDetails(incomeId) {
            // Could open a modal with details or redirect
            alert('Income ID: ' + incomeId);
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('addIncomeModal');
            if (event.target === modal) {
                modal.classList.remove('active');
            }
        }
    </script>
</body>
</html>
