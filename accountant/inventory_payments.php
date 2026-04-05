<?php
include '../config.php';
require_once '../inventory_payment_helpers.php';
require_once '../finance_accounts_helpers.php';
checkAuth();
checkRole(['accountant']);
financeEnsureSchema($pdo);

function ensureInventoryPaymentColumns(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventory_items (
            id INT PRIMARY KEY AUTO_INCREMENT,
            item_code VARCHAR(50) UNIQUE NOT NULL,
            item_name VARCHAR(150) NOT NULL,
            category VARCHAR(100) NOT NULL,
            description TEXT,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            quantity_in_stock INT DEFAULT 0,
            reorder_level INT DEFAULT 10,
            reorder_quantity INT DEFAULT 20,
            supplier_id INT NULL,
            last_restock_date TIMESTAMP NULL,
            status ENUM('active', 'inactive', 'discontinued') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY category (category),
            KEY status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $requiredColumns = [
        'approval_status' => "ALTER TABLE inventory_items ADD COLUMN approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER status",
        'requested_by' => "ALTER TABLE inventory_items ADD COLUMN requested_by INT NULL AFTER approval_status",
        'approved_by' => "ALTER TABLE inventory_items ADD COLUMN approved_by INT NULL AFTER requested_by",
        'approved_at' => "ALTER TABLE inventory_items ADD COLUMN approved_at DATETIME NULL AFTER approved_by",
        'approval_notes' => "ALTER TABLE inventory_items ADD COLUMN approval_notes TEXT NULL AFTER approved_at",
        'payment_status' => "ALTER TABLE inventory_items ADD COLUMN payment_status ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending' AFTER approval_notes",
        'payment_reference' => "ALTER TABLE inventory_items ADD COLUMN payment_reference VARCHAR(120) NULL AFTER payment_status",
        'payment_notes' => "ALTER TABLE inventory_items ADD COLUMN payment_notes TEXT NULL AFTER payment_reference",
        'paid_by' => "ALTER TABLE inventory_items ADD COLUMN paid_by INT NULL AFTER payment_notes",
        'paid_at' => "ALTER TABLE inventory_items ADD COLUMN paid_at DATETIME NULL AFTER paid_by"
    ];

    foreach ($requiredColumns as $column => $sql) {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM inventory_items LIKE ?");
        $stmt->execute([$column]);
        if (!$stmt->fetch()) {
            $pdo->exec($sql);
        }
    }
}

ensureInventoryPaymentColumns($pdo);
ensureInventoryPaymentWorkflow($pdo);

$accountant_id = $_SESSION['user_id'];
$gateway = new InventoryPaymentGateway($pdo, $accountant_id);
$school_accounts = financeGetActiveAccounts($pdo);
$defaultExpenseAccount = financeGetDefaultAccount($pdo, 'expense');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_action'], $_POST['item_id'])) {
    $item_id = (int) $_POST['item_id'];
    $payment_reference = trim((string) ($_POST['payment_reference'] ?? ''));
    $payment_notes = trim((string) ($_POST['payment_notes'] ?? ''));
    $payment_action = $_POST['payment_action'];

    try {
        if ($payment_action === 'process_payment') {
            $result = $gateway->process($item_id, trim((string) ($_POST['payment_method'] ?? '')), [
                'payment_reference' => $payment_reference,
                'payment_notes' => $payment_notes,
                'amount' => (float) ($_POST['payment_amount'] ?? 0),
                'payee_name' => trim((string) ($_POST['payee_name'] ?? '')),
                'bank_name' => trim((string) ($_POST['bank_name'] ?? '')),
                'bank_account_name' => trim((string) ($_POST['bank_account_name'] ?? '')),
                'bank_account_number' => trim((string) ($_POST['bank_account_number'] ?? '')),
                'bank_branch' => trim((string) ($_POST['bank_branch'] ?? '')),
                'mpesa_number' => trim((string) ($_POST['mpesa_number'] ?? '')),
                'fund_account_id' => (int) ($_POST['fund_account_id'] ?? 0),
            ]);

            if (!$result['success']) {
                throw new RuntimeException($result['message']);
            }

            $_SESSION['success'] = $result['message'];
        } elseif ($payment_action === 'cancel_payment') {
            $stmt = $pdo->prepare("
                UPDATE inventory_items
                SET payment_status = 'cancelled',
                    payment_reference = ?,
                    payment_notes = ?,
                    paid_by = ?,
                    paid_at = NOW(),
                    payment_gateway_status = 'cancelled'
                WHERE id = ? AND approval_status = 'approved'
            ");
            $stmt->execute([
                $payment_reference !== '' ? $payment_reference : null,
                $payment_notes !== '' ? $payment_notes : null,
                $accountant_id,
                $item_id
            ]);
            $_SESSION['success'] = 'Inventory payment cancelled.';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Inventory payment error: ' . $e->getMessage();
    }

    header('Location: inventory_payments.php');
    exit();
}

$query = "
    SELECT i.*,
           requester.full_name AS requested_by_name,
           approver.full_name AS approved_by_name,
           payer.full_name AS paid_by_name,
           sa.account_name AS paid_from_account_name
    FROM inventory_items i
    LEFT JOIN users requester ON i.requested_by = requester.id
    LEFT JOIN users approver ON i.approved_by = approver.id
    LEFT JOIN users payer ON i.paid_by = payer.id
    LEFT JOIN school_accounts sa ON i.paid_from_account_id = sa.id
    WHERE i.approval_status = 'approved'
";
$params = [];

if (!empty($_GET['payment_status'])) {
    $query .= " AND i.payment_status = ?";
    $params[] = $_GET['payment_status'];
}

if (!empty($_GET['search'])) {
    $query .= " AND (i.item_code LIKE ? OR i.item_name LIKE ? OR i.category LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$query .= " ORDER BY FIELD(i.payment_status, 'pending', 'cancelled', 'paid'), i.updated_at DESC LIMIT 500";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$inventory_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stats = $pdo->query("
    SELECT
        COUNT(CASE WHEN approval_status = 'approved' THEN 1 END) AS approved_items,
        COUNT(CASE WHEN approval_status = 'approved' AND payment_status = 'pending' THEN 1 END) AS pending_payments,
        COUNT(CASE WHEN approval_status = 'approved' AND payment_status = 'paid' THEN 1 END) AS paid_items,
        COUNT(CASE WHEN approval_status = 'approved' AND payment_status = 'cancelled' THEN 1 END) AS cancelled_items,
        COALESCE(SUM(CASE WHEN approval_status = 'approved' AND payment_status = 'pending' THEN COALESCE(requested_payment_amount, quantity_in_stock * unit_price) ELSE 0 END), 0) AS pending_amount,
        COALESCE(SUM(CASE WHEN approval_status = 'approved' AND payment_status = 'paid' THEN COALESCE(requested_payment_amount, quantity_in_stock * unit_price) ELSE 0 END), 0) AS paid_amount
    FROM inventory_items
")->fetch(PDO::FETCH_ASSOC);

$page_title = 'Inventory Payments - ' . SCHOOL_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <style>
        :root {
            --primary: #4361ee;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --gray: #6c757d;
            --dark: #2c3e50;
            --white: #ffffff;
        }
        body { margin: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%); }
        .main-content { margin-left: 280px; margin-top: 70px; padding: 2rem; min-height: calc(100vh - 70px); }
        .page-header, .filters-card, .table-card { background: var(--white); border-radius: 18px; box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
        .page-header { padding: 1.75rem; margin-bottom: 1.5rem; }
        .page-header h1 { margin: 0 0 0.4rem; color: var(--dark); }
        .page-header p { margin: 0; color: var(--gray); }
        .stats-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card { background: var(--white); border-radius: 16px; padding: 1.2rem; box-shadow: 0 6px 18px rgba(0,0,0,0.06); border-top: 4px solid var(--primary); }
        .stat-card.warning { border-top-color: var(--warning); }
        .stat-card.success { border-top-color: var(--success); }
        .stat-card.danger { border-top-color: var(--danger); }
        .stat-value { font-size: 1.45rem; font-weight: 700; color: var(--dark); margin-bottom: 0.35rem; }
        .stat-label { color: var(--gray); font-size: 0.92rem; }
        .filters-card { padding: 1rem; margin-bottom: 1.5rem; }
        .filters-form { display: grid; grid-template-columns: 2fr 1fr auto; gap: 1rem; align-items: end; }
        .filter-group label { display: block; margin-bottom: 0.45rem; font-weight: 600; color: var(--dark); }
        .filter-group input, .filter-group select, .notes-input { width: 100%; padding: 0.75rem; border-radius: 10px; border: 1px solid #d7dce2; font-size: 0.95rem; }
        .btn { border: 0; border-radius: 10px; padding: 0.8rem 1.1rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 0.45rem; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-success { background: var(--success); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .table-card { overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        th { background: var(--primary); color: white; text-align: left; padding: 1rem; font-size: 0.92rem; }
        td { padding: 1rem; border-bottom: 1px solid #eef2f6; vertical-align: top; }
        tr:hover { background: #f8fbff; }
        .badge { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.35rem 0.75rem; border-radius: 999px; font-size: 0.82rem; font-weight: 700; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-paid { background: #d4edda; color: #155724; }
        .badge-cancelled { background: #f1f3f5; color: #495057; }
        .meta { color: var(--gray); font-size: 0.88rem; line-height: 1.5; }
        .actions-cell form { display: grid; gap: 0.5rem; }
        .actions-row { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .alert { padding: 1rem; border-radius: 12px; margin-bottom: 1rem; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        @media (max-width: 1024px) { .main-content { margin-left: 0; } .stats-grid { grid-template-columns: repeat(2, 1fr); } .filters-form { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 640px) { .main-content { padding: 1rem; } .stats-grid, .filters-form { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>
    <?php include '../loader.php'; ?>
    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-money-bill-wave"></i> Inventory Payments</h1>
            <p>Only admin-approved inventory items appear here for accountant payment processing.</p>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']); ?></div>
        <?php unset($_SESSION['success']); endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($_SESSION['error']); ?></div>
        <?php unset($_SESSION['error']); endif; ?>

        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value"><?php echo number_format((int)($stats['approved_items'] ?? 0)); ?></div><div class="stat-label">Approved Items</div></div>
            <div class="stat-card warning"><div class="stat-value"><?php echo number_format((int)($stats['pending_payments'] ?? 0)); ?></div><div class="stat-label">Pending Payments</div></div>
            <div class="stat-card success"><div class="stat-value"><?php echo number_format((int)($stats['paid_items'] ?? 0)); ?></div><div class="stat-label">Paid Items</div></div>
            <div class="stat-card danger"><div class="stat-value"><?php echo number_format((int)($stats['cancelled_items'] ?? 0)); ?></div><div class="stat-label">Cancelled Payments</div></div>
            <div class="stat-card warning"><div class="stat-value">KES <?php echo number_format((float)($stats['pending_amount'] ?? 0), 0); ?></div><div class="stat-label">Pending Amount</div></div>
            <div class="stat-card success"><div class="stat-value">KES <?php echo number_format((float)($stats['paid_amount'] ?? 0), 0); ?></div><div class="stat-label">Paid Amount</div></div>
        </div>

        <div class="filters-card">
            <form method="GET" class="filters-form">
                <div class="filter-group">
                    <label>Search</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" placeholder="Item code, item name, category">
                </div>
                <div class="filter-group">
                    <label>Payment Status</label>
                    <select name="payment_status">
                        <option value="">All</option>
                        <option value="pending" <?php echo ($_GET['payment_status'] ?? '') === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="paid" <?php echo ($_GET['payment_status'] ?? '') === 'paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="cancelled" <?php echo ($_GET['payment_status'] ?? '') === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
            </form>
        </div>

        <div class="table-card table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Category</th>
                        <th>Amount</th>
                        <th>Preferred Method</th>
                        <th>Recipient Details</th>
                        <th>Requested By</th>
                        <th>Approved By</th>
                        <th>Payment Status</th>
                        <th>Payment Details</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($inventory_items)): ?>
                        <?php foreach ($inventory_items as $item): ?>
                        <?php $amount = inventoryPaymentAmount($item); ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($item['item_name']); ?></strong><br><span class="meta"><?php echo htmlspecialchars($item['item_code']); ?></span></td>
                            <td><?php echo htmlspecialchars($item['category']); ?></td>
                            <td>KES <?php echo number_format($amount, 2); ?><br><span class="meta"><?php echo (int)$item['quantity_in_stock']; ?> x <?php echo number_format((float)$item['unit_price'], 2); ?></span></td>
                            <td class="meta">
                                <?php echo htmlspecialchars(inventoryPaymentMethodLabel($item['requested_payment_method'] ?? 'bank_transfer')); ?><br>
                                <span><?php echo htmlspecialchars($item['payment_narration'] ?? '-'); ?></span>
                            </td>
                            <td class="meta">
                                <?php echo htmlspecialchars($item['payee_name'] ?? '-'); ?><br>
                                <?php if (!empty($item['mpesa_number'])): ?>
                                    M-Pesa: <?php echo htmlspecialchars($item['mpesa_number']); ?><br>
                                <?php endif; ?>
                                <?php if (!empty($item['bank_name']) || !empty($item['bank_account_number'])): ?>
                                    <?php echo htmlspecialchars($item['bank_name'] ?? 'Bank'); ?> - <?php echo htmlspecialchars($item['bank_account_number'] ?? '-'); ?>
                                <?php endif; ?>
                            </td>
                            <td class="meta"><?php echo htmlspecialchars($item['requested_by_name'] ?? 'System'); ?></td>
                            <td class="meta"><?php echo htmlspecialchars($item['approved_by_name'] ?? '-'); ?><br><?php if (!empty($item['approved_at'])) echo date('d M Y H:i', strtotime($item['approved_at'])); ?></td>
                            <td><span class="badge badge-<?php echo htmlspecialchars($item['payment_status'] ?? 'pending'); ?>"><?php echo ucfirst($item['payment_status'] ?? 'pending'); ?></span></td>
                            <td class="meta">
                                Gateway: <?php echo htmlspecialchars($item['payment_gateway_status'] ?? '-'); ?><br>
                                Account: <?php echo htmlspecialchars($item['paid_from_account_name'] ?? '-'); ?><br>
                                Ref: <?php echo htmlspecialchars($item['payment_reference'] ?? '-'); ?><br>
                                Notes: <?php echo htmlspecialchars($item['payment_notes'] ?? '-'); ?><br>
                                <?php if (!empty($item['paid_by_name'])): ?>
                                By <?php echo htmlspecialchars($item['paid_by_name']); ?><?php if (!empty($item['paid_at'])) echo ' on ' . date('d M Y H:i', strtotime($item['paid_at'])); ?>
                                <?php endif; ?>
                            </td>
                            <td class="actions-cell">
                                <?php if (($item['payment_status'] ?? 'pending') === 'pending'): ?>
                                <form method="POST">
                                    <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>">
                                    <select name="fund_account_id" class="notes-input">
                                        <option value="">Auto-select school account</option>
                                        <?php foreach ($school_accounts as $account): ?>
                                            <option value="<?php echo (int) $account['id']; ?>" <?php echo $defaultExpenseAccount && (int) $defaultExpenseAccount['id'] === (int) $account['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($account['account_name'] . ' (' . strtoupper($account['account_type']) . ') - KES ' . number_format((float) $account['current_balance'], 2)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select name="payment_method" class="notes-input">
                                        <option value="bank_transfer" <?php echo ($item['requested_payment_method'] ?? '') === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                        <option value="mpesa" <?php echo ($item['requested_payment_method'] ?? '') === 'mpesa' ? 'selected' : ''; ?>>M-Pesa</option>
                                        <option value="cash" <?php echo ($item['requested_payment_method'] ?? '') === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                        <option value="cheque" <?php echo ($item['requested_payment_method'] ?? '') === 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                                        <option value="manual">Manual</option>
                                    </select>
                                    <input type="number" step="0.01" min="0" name="payment_amount" class="notes-input" value="<?php echo htmlspecialchars((string) $amount); ?>" placeholder="Payment amount">
                                    <input type="text" name="payee_name" class="notes-input" value="<?php echo htmlspecialchars($item['payee_name'] ?? ''); ?>" placeholder="Payee name">
                                    <input type="text" name="bank_name" class="notes-input" value="<?php echo htmlspecialchars($item['bank_name'] ?? ''); ?>" placeholder="Bank name">
                                    <input type="text" name="bank_account_name" class="notes-input" value="<?php echo htmlspecialchars($item['bank_account_name'] ?? ''); ?>" placeholder="Bank account name">
                                    <input type="text" name="bank_account_number" class="notes-input" value="<?php echo htmlspecialchars($item['bank_account_number'] ?? ''); ?>" placeholder="Bank account number">
                                    <input type="text" name="bank_branch" class="notes-input" value="<?php echo htmlspecialchars($item['bank_branch'] ?? ''); ?>" placeholder="Bank branch">
                                    <input type="text" name="mpesa_number" class="notes-input" value="<?php echo htmlspecialchars($item['mpesa_number'] ?? ($item['payee_phone'] ?? '')); ?>" placeholder="M-Pesa number">
                                    <input type="text" name="payment_reference" class="notes-input" placeholder="Reference / voucher no.">
                                    <input type="text" name="payment_notes" class="notes-input" value="<?php echo htmlspecialchars($item['payment_narration'] ?? ''); ?>" placeholder="Payment notes">
                                    <div class="actions-row">
                                        <button type="submit" name="payment_action" value="process_payment" class="btn btn-success"><i class="fas fa-paper-plane"></i> Process Payment</button>
                                        <button type="submit" name="payment_action" value="cancel_payment" class="btn btn-danger"><i class="fas fa-ban"></i> Cancel</button>
                                    </div>
                                </form>
                                <?php else: ?>
                                <span class="meta">No pending action</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <tr><td colspan="10" style="text-align:center; padding:2rem; color:#6c757d;"><i class="fas fa-inbox"></i> No approved inventory items found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
