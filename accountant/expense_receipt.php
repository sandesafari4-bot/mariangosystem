<?php
include '../config.php';
require_once '../finance_accounts_helpers.php';

checkAuth();
checkRole(['accountant', 'admin']);
financeEnsureSchema($pdo);

$expenseId = (int) ($_GET['id'] ?? 0);
if ($expenseId <= 0) {
    die('Invalid expense receipt request.');
}

$schoolName = getSystemSetting('school_name', SCHOOL_NAME);
$schoolAddress = getSystemSetting('school_address', SCHOOL_LOCATION);
$schoolPhone = getSystemSetting('school_phone', '');
$schoolEmail = getSystemSetting('school_email', '');
$schoolLogo = getSystemSetting('school_logo', SCHOOL_LOGO);

$logoPath = '';
if ($schoolLogo !== '') {
    $candidate = dirname(__DIR__) . '/uploads/logos/' . basename($schoolLogo);
    if (is_file($candidate)) {
        $logoPath = '../uploads/logos/' . basename($schoolLogo);
    }
}

$stmt = $pdo->prepare("
    SELECT
        e.*,
        COALESCE(ec.name, CONCAT('Category #', e.category_id)) as category_name,
        u.full_name as created_by_name,
        a.full_name as approved_by_name,
        p.full_name as paid_by_name,
        sa.account_name as paid_from_account_name
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    LEFT JOIN users u ON e.created_by = u.id
    LEFT JOIN users a ON e.approved_by = a.id
    LEFT JOIN users p ON e.paid_by = p.id
    LEFT JOIN school_accounts sa ON e.paid_from_account_id = sa.id
    WHERE e.id = ?
");
$stmt->execute([$expenseId]);
$expense = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$expense) {
    die('Expense not found.');
}

$receiptNumber = 'EXP-' . str_pad((string) $expenseId, 6, '0', STR_PAD_LEFT) . '/' . date('Y', strtotime($expense['created_at'] ?? 'now'));
if (!empty($expense['notes']) && preg_match('/Expense receipt no:\s*(.+)/i', $expense['notes'], $matches)) {
    $receiptNumber = trim($matches[1]);
}

$pageTitle = 'Expense Receipt ' . $receiptNumber . ' - ' . $schoolName;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600;700&display=swap');
        :root{--ink:#0f172a;--muted:#64748b;--line:rgba(15,23,42,.09);--brand:#0f766e;--warn:#f59e0b;--danger:#dc2626;--ok:#15803d;--card:#fff;--shadow:0 18px 45px rgba(15,23,42,.08);--radius:24px}
        *{box-sizing:border-box}body{margin:0;font-family:Inter,sans-serif;background:linear-gradient(135deg,#f8fafc,#eef6f4);color:var(--ink)}
        .shell{max-width:980px;margin:2rem auto;padding:0 1rem}.toolbar{display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1rem}
        .btn{border:0;border-radius:14px;padding:.85rem 1rem;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}.btn-primary{background:linear-gradient(135deg,#0f766e,#14b8a6);color:#fff}.btn-light{background:#fff;color:#0f766e;border:1px solid rgba(15,118,110,.18)}
        .receipt{background:rgba(255,255,255,.97);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
        .header{padding:2rem;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:1.5rem;flex-wrap:wrap}
        .brand{display:flex;gap:1rem;align-items:flex-start}.logo{width:72px;height:72px;border-radius:20px;background:linear-gradient(135deg,#0f766e,#14b8a6);display:flex;align-items:center;justify-content:center;color:#fff;font:700 1.6rem "Space Grotesk",sans-serif;overflow:hidden}
        .logo img{width:100%;height:100%;object-fit:cover}.title{font-family:"Space Grotesk",sans-serif;font-size:1.9rem;margin:0 0 .3rem}.sub{color:var(--muted);line-height:1.6}
        .badge{display:inline-flex;align-items:center;gap:.35rem;padding:.42rem .8rem;border-radius:999px;font-size:.78rem;font-weight:700}
        .pending{background:rgba(245,158,11,.15);color:#b45309}.approved{background:rgba(20,184,166,.12);color:#0f766e}.paid{background:rgba(21,128,61,.12);color:#15803d}.rejected{background:rgba(220,38,38,.12);color:#b91c1c}.unpaid{background:rgba(148,163,184,.18);color:#475569}.sandbox{background:rgba(59,130,246,.12);color:#1d4ed8}
        .body{padding:2rem}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem;margin-bottom:1.2rem}.card{border:1px solid var(--line);border-radius:18px;padding:1rem 1.1rem;background:#fff}
        .label{display:block;font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:.35rem}.value{font-weight:700}.full{grid-column:1/-1}
        .notes{white-space:pre-wrap;line-height:1.65}.amount{font-size:1.9rem;color:var(--danger);font-family:"Space Grotesk",sans-serif}
        @media print {.toolbar{display:none}body{background:#fff}.shell{max-width:none;margin:0;padding:0}.receipt{box-shadow:none;border:0}}
        @media (max-width:800px){.grid{grid-template-columns:1fr}.header{padding:1.25rem}.body{padding:1.25rem}}
    </style>
</head>
<body>
<div class="shell">
    <div class="toolbar">
        <a class="btn btn-light" href="add_expense.php">New Expense</a>
        <div style="display:flex;gap:.75rem;flex-wrap:wrap">
            <a class="btn btn-light" href="expenses.php">Back to Expenses</a>
            <button class="btn btn-primary" onclick="window.print()">Print Receipt</button>
        </div>
    </div>

    <div class="receipt">
        <div class="header">
            <div class="brand">
                <div class="logo">
                    <?php if ($logoPath): ?>
                        <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="School Logo">
                    <?php else: ?>
                        <?php echo htmlspecialchars(strtoupper(substr($schoolName, 0, 1))); ?>
                    <?php endif; ?>
                </div>
                <div>
                    <h1 class="title"><?php echo htmlspecialchars($schoolName); ?></h1>
                    <div class="sub">
                        <?php echo htmlspecialchars($schoolAddress); ?><br>
                        <?php if ($schoolPhone): ?>Tel: <?php echo htmlspecialchars($schoolPhone); ?><br><?php endif; ?>
                        <?php if ($schoolEmail): ?>Email: <?php echo htmlspecialchars($schoolEmail); ?><?php endif; ?>
                    </div>
                </div>
            </div>
            <div>
                <div class="badge <?php echo htmlspecialchars($expense['status'] ?? 'pending'); ?>"><?php echo htmlspecialchars(ucfirst($expense['status'] ?? 'pending')); ?></div>
                <div style="height:.5rem"></div>
                <div class="badge <?php echo htmlspecialchars($expense['payment_status'] ?? 'unpaid'); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $expense['payment_status'] ?? 'unpaid'))); ?></div>
                <?php if (($expense['transaction_environment'] ?? 'live') === 'sandbox'): ?>
                    <div style="height:.5rem"></div>
                    <div class="badge sandbox">Sandbox M-Pesa Test</div>
                <?php endif; ?>
                <div class="sub" style="margin-top:1rem">
                    Receipt No:<br><strong><?php echo htmlspecialchars($receiptNumber); ?></strong><br><br>
                    Submitted:<br><strong><?php echo date('d M Y H:i', strtotime($expense['created_at'])); ?></strong>
                </div>
            </div>
        </div>

        <div class="body">
            <div class="grid">
                <div class="card">
                    <span class="label">Expense Category</span>
                    <span class="value"><?php echo htmlspecialchars($expense['category_name']); ?></span>
                </div>
                <div class="card">
                    <span class="label">Amount</span>
                    <div class="amount">KES <?php echo number_format((float) $expense['amount'], 2); ?></div>
                </div>
                <div class="card full">
                    <span class="label">Description</span>
                    <span class="value"><?php echo htmlspecialchars($expense['description']); ?></span>
                </div>
                <div class="card">
                    <span class="label">Vendor / Payee</span>
                    <span class="value"><?php echo htmlspecialchars($expense['vendor'] ?? 'Not provided'); ?></span>
                </div>
                <div class="card">
                    <span class="label">Payment Method</span>
                    <span class="value"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $expense['payment_method']))); ?></span>
                </div>
                <div class="card">
                    <span class="label">Transaction Environment</span>
                    <span class="value"><?php echo htmlspecialchars(ucfirst($expense['transaction_environment'] ?? 'live')); ?></span>
                </div>
                <div class="card">
                    <span class="label">Reference Number</span>
                    <span class="value"><?php echo htmlspecialchars($expense['reference_number'] ?? 'N/A'); ?></span>
                </div>
                <div class="card">
                    <span class="label">M-Pesa Number</span>
                    <span class="value"><?php echo htmlspecialchars($expense['payee_phone'] ?? 'N/A'); ?></span>
                </div>
                <div class="card">
                    <span class="label">Bank Name</span>
                    <span class="value"><?php echo htmlspecialchars($expense['payee_bank_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="card">
                    <span class="label">Account Name</span>
                    <span class="value"><?php echo htmlspecialchars($expense['payee_account_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="card">
                    <span class="label">Account Number</span>
                    <span class="value"><?php echo htmlspecialchars($expense['payee_account_number'] ?? 'N/A'); ?></span>
                </div>
                <div class="card">
                    <span class="label">Bank Branch</span>
                    <span class="value"><?php echo htmlspecialchars($expense['payee_bank_branch'] ?? 'N/A'); ?></span>
                </div>
                <div class="card">
                    <span class="label">Sandbox Test Reference</span>
                    <span class="value"><?php echo htmlspecialchars($expense['sandbox_reference'] ?? 'N/A'); ?></span>
                </div>
                <div class="card">
                    <span class="label">Submitted By</span>
                    <span class="value"><?php echo htmlspecialchars($expense['created_by_name'] ?? 'System'); ?></span>
                </div>
                <div class="card">
                    <span class="label">Approved By</span>
                    <span class="value"><?php echo htmlspecialchars($expense['approved_by_name'] ?? 'Pending'); ?></span>
                </div>
                <div class="card">
                    <span class="label">Paid By</span>
                    <span class="value"><?php echo htmlspecialchars($expense['paid_by_name'] ?? 'Not yet paid'); ?></span>
                </div>
                <div class="card">
                    <span class="label">Paid From Account</span>
                    <span class="value"><?php echo htmlspecialchars($expense['paid_from_account_name'] ?? 'Not yet paid'); ?></span>
                </div>
                <div class="card">
                    <span class="label">Finance Payment Reference</span>
                    <span class="value"><?php echo htmlspecialchars($expense['payment_reference'] ?? 'N/A'); ?></span>
                </div>
                <div class="card full">
                    <span class="label">Notes / Audit Trail</span>
                    <div class="notes"><?php echo htmlspecialchars($expense['notes'] ?? 'No notes recorded.'); ?></div>
                </div>
            </div>

            <?php if (($expense['transaction_environment'] ?? 'live') === 'sandbox' && ($expense['payment_method'] ?? '') === 'mpesa'): ?>
                <div class="card" style="border-color:rgba(59,130,246,.22);background:rgba(239,246,255,.75)">
                    <span class="label">Sandbox Notice</span>
                    <div class="notes">This expense was prepared in sandbox test mode for M-Pesa payout setup. No live disbursement has been sent. Use the sandbox reference above for internal testing and approval review.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
