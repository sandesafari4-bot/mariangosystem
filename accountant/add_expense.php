<?php
include '../config.php';
require_once '../finance_accounts_helpers.php';

checkAuth();
checkRole(['accountant', 'admin']);
financeEnsureSchema($pdo);

$page_title = 'Add Expense - ' . SCHOOL_NAME;
$categories = [];
$error = null;

try {
    $vendorColumnExists = (bool) $pdo->query("SHOW COLUMNS FROM expenses LIKE 'vendor'")->fetch();
    if (!$vendorColumnExists) {
        $pdo->exec("ALTER TABLE expenses ADD COLUMN vendor VARCHAR(150) NULL AFTER description");
    }

    financeEnsureColumn($pdo, 'expenses', 'payee_phone', "VARCHAR(30) NULL AFTER `vendor`");
    financeEnsureColumn($pdo, 'expenses', 'payee_bank_name', "VARCHAR(120) NULL AFTER `payee_phone`");
    financeEnsureColumn($pdo, 'expenses', 'payee_account_name', "VARCHAR(150) NULL AFTER `payee_bank_name`");
    financeEnsureColumn($pdo, 'expenses', 'payee_account_number', "VARCHAR(120) NULL AFTER `payee_account_name`");
    financeEnsureColumn($pdo, 'expenses', 'payee_bank_branch', "VARCHAR(120) NULL AFTER `payee_account_number`");
    financeEnsureColumn($pdo, 'expenses', 'transaction_environment', "VARCHAR(20) NOT NULL DEFAULT 'live' AFTER `payment_method`");
    financeEnsureColumn($pdo, 'expenses', 'sandbox_reference', "VARCHAR(100) NULL AFTER `transaction_environment`");

    $categories = $pdo->query("
        SELECT id, name, description, budget, status
        FROM expense_categories
        WHERE status = 'active' OR status IS NULL
        ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $categories = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_expense'])) {
    try {
        $categoryId = intval($_POST['category_id'] ?? 0);
        $categoryNameInput = trim($_POST['category_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $vendor = trim($_POST['vendor'] ?? '');
        $amount = (float) ($_POST['amount'] ?? 0);
        $expenseDate = $_POST['expense_date'] ?? date('Y-m-d');
        $paymentMethod = trim($_POST['payment_method'] ?? '');
        $transactionEnvironment = trim($_POST['transaction_environment'] ?? 'live');
        $referenceNumber = trim($_POST['reference_number'] ?? '');
        $payeePhone = trim($_POST['payee_phone'] ?? '');
        $payeeBankName = trim($_POST['payee_bank_name'] ?? '');
        $payeeAccountName = trim($_POST['payee_account_name'] ?? '');
        $payeeAccountNumber = trim($_POST['payee_account_number'] ?? '');
        $payeeBankBranch = trim($_POST['payee_bank_branch'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($categoryNameInput === '') {
            throw new Exception('Please enter an expense category.');
        }
        if ($description === '') {
            throw new Exception('Please enter an expense description.');
        }
        if ($amount <= 0) {
            throw new Exception('Please enter a valid amount.');
        }
        if ($paymentMethod === '') {
            throw new Exception('Please choose a payment method.');
        }

        if (!in_array($transactionEnvironment, ['live', 'sandbox'], true)) {
            $transactionEnvironment = 'live';
        }

        if ($paymentMethod === 'mpesa' && $payeePhone === '') {
            throw new Exception('Please enter the M-Pesa number that will be used during the transaction.');
        }

        if ($paymentMethod === 'bank_transfer' && ($payeeBankName === '' || $payeeAccountName === '' || $payeeAccountNumber === '')) {
            throw new Exception('Please enter the bank details that will be used during the transaction.');
        }

        if ($paymentMethod !== 'mpesa') {
            $transactionEnvironment = 'live';
        }

        if ($categoryId <= 0) {
            $categoryLookup = $pdo->prepare("SELECT id FROM expense_categories WHERE name = ? LIMIT 1");
            $categoryLookup->execute([$categoryNameInput]);
            $categoryId = (int) ($categoryLookup->fetchColumn() ?: 0);
        }

        if ($categoryId <= 0) {
            $createCategory = $pdo->prepare("
                INSERT INTO expense_categories (name, description, budget, status, created_at, updated_at)
                VALUES (?, ?, 0, 'active', NOW(), NOW())
            ");
            $createCategory->execute([
                $categoryNameInput,
                'Auto-created from accountant expense entry'
            ]);
            $categoryId = (int) $pdo->lastInsertId();
        }

        $sandboxReference = null;
        if ($paymentMethod === 'mpesa' && $transactionEnvironment === 'sandbox') {
            $sandboxReference = financeGenerateReference('SBOX');
        }

        $receiptNumber = 'EXP-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        $compiledNotes = [];
        if ($notes !== '') {
            $compiledNotes[] = $notes;
        }
        $compiledNotes[] = 'Expense receipt no: ' . $receiptNumber;
        if ($paymentMethod === 'mpesa' && $payeePhone !== '') {
            $compiledNotes[] = 'Payout M-Pesa number: ' . $payeePhone;
        }
        if ($paymentMethod === 'bank_transfer') {
            $bankParts = array_filter([$payeeBankName, $payeeAccountName, $payeeAccountNumber, $payeeBankBranch]);
            if ($bankParts) {
                $compiledNotes[] = 'Bank payout details: ' . implode(' | ', $bankParts);
            }
        }
        if ($sandboxReference) {
            $compiledNotes[] = 'Sandbox M-Pesa test reference: ' . $sandboxReference;
        }

        $stmt = $pdo->prepare("
            INSERT INTO expenses (
                category_id, description, vendor, payee_phone, payee_bank_name, payee_account_name,
                payee_account_number, payee_bank_branch, amount, expense_date, payment_method,
                transaction_environment, sandbox_reference, reference_number, status, payment_status,
                requested_by, created_by, recorded_by, notes, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'unpaid', ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $categoryId,
            $description,
            $vendor !== '' ? $vendor : null,
            $payeePhone !== '' ? $payeePhone : null,
            $payeeBankName !== '' ? $payeeBankName : null,
            $payeeAccountName !== '' ? $payeeAccountName : null,
            $payeeAccountNumber !== '' ? $payeeAccountNumber : null,
            $payeeBankBranch !== '' ? $payeeBankBranch : null,
            $amount,
            $expenseDate,
            $paymentMethod,
            $transactionEnvironment,
            $sandboxReference,
            $referenceNumber !== '' ? $referenceNumber : null,
            $_SESSION['user_id'],
            $_SESSION['user_id'],
            $_SESSION['user_id'],
            implode("\n", array_filter($compiledNotes)),
        ]);

        $expenseId = (int) $pdo->lastInsertId();
        $categoryName = $categoryNameInput;
        if ($categoryId > 0) {
            $categoryStmt = $pdo->prepare("SELECT name FROM expense_categories WHERE id = ?");
            $categoryStmt->execute([$categoryId]);
            $categoryName = $categoryStmt->fetchColumn() ?: $categoryNameInput;
        }

        notifyApprovalRequestSubmitted(
            'Expense Approval Request',
            "A new expense request for " . formatCurrency($amount) . " under {$categoryName} has been submitted.",
            $expenseId,
            'expense'
        );

        $_SESSION['success'] = 'Expense submitted successfully and sent for approval.';
        header('Location: expense_receipt.php?id=' . $expenseId);
        exit();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600;700&display=swap');
        :root{--ink:#0f172a;--brand:#0f766e;--brand2:#14b8a6;--danger:#dc2626;--muted:#64748b;--line:rgba(15,118,110,.14);--card:#fff;--shadow:0 18px 42px rgba(15,23,42,.08);--radius:24px}
        *{box-sizing:border-box}body{margin:0;font-family:Inter,sans-serif;background:radial-gradient(circle at top left,rgba(20,184,166,.12),transparent 28%),linear-gradient(135deg,#f7fbfb,#e8eef5);color:var(--ink)}
        .main-content{margin-left:280px;margin-top:70px;padding:2rem}
        .hero,.card{background:rgba(255,255,255,.96);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow)}
        .hero{padding:2rem;display:grid;grid-template-columns:1.4fr 1fr;gap:1.25rem;margin-bottom:1.5rem}
        h1,h2{font-family:"Space Grotesk",sans-serif;margin:0}.sub{color:var(--muted);line-height:1.6}
        .mini-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.9rem}.mini{padding:1rem;border-radius:18px;background:linear-gradient(135deg,#10314e,#155e75);color:#fff}.mini small{display:block;color:rgba(255,255,255,.72);margin-bottom:.3rem}
        .card{padding:1.5rem}.grid{display:grid;grid-template-columns:1.3fr .9fr;gap:1.4rem}
        .fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.95rem}.field{display:flex;flex-direction:column;gap:.4rem}.field.full{grid-column:1/-1}
        label{font-weight:700;font-size:.92rem}input,select,textarea{width:100%;padding:.88rem .95rem;border:1px solid rgba(15,118,110,.16);border-radius:15px;font:inherit;background:#fff}textarea{min-height:110px;resize:vertical}
        .btns{display:flex;gap:.8rem;flex-wrap:wrap;margin-top:1rem}.btn{border:0;border-radius:14px;padding:.9rem 1.15rem;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}.btn-primary{background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff}.btn-light{background:#eef8f6;color:var(--brand)}
        .alert{padding:1rem 1.1rem;border-radius:16px;margin-bottom:1rem;font-weight:600}.alert-error{background:rgba(220,38,38,.1);color:var(--danger)}
        .category-list{display:grid;gap:.8rem}.category{padding:1rem;border-radius:18px;background:#f7fbfb;border:1px solid var(--line)}.category small{color:var(--muted);display:block;margin-top:.25rem}
        @media (max-width:1100px){.main-content{margin-left:0;padding:1rem}.hero,.grid,.mini-grid,.fields{grid-template-columns:1fr}}
    </style>
</head>
<body>
<?php include '../loader.php'; ?>
<?php include '../navigation.php'; ?>
<?php include '../sidebar.php'; ?>
<main class="main-content">
    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <section class="hero">
        <div>
            <h1>Add Expense Request</h1>
            <p class="sub">Capture a new expense cleanly, route it for approval, and keep the finance trail complete. This version stores the M-Pesa number or bank details that will be used during payout, supports a sandbox M-Pesa test setup, and generates an expense receipt after submission.</p>
        </div>
        <div class="mini-grid">
            <div class="mini"><small>Default workflow</small><strong>Submitted as Pending</strong></div>
            <div class="mini"><small>Approver</small><strong>Admin</strong></div>
            <div class="mini"><small>Sandbox support</small><strong>M-Pesa Test Reference</strong></div>
            <div class="mini"><small>Tracked fields</small><strong>Payout details + receipt</strong></div>
        </div>
    </section>

    <section class="grid">
        <div class="card">
            <h2>Expense Form</h2>
            <p class="sub">Enter the expense details below. Once saved, the request goes into the approval queue, admins get notified, and the system opens a printable expense receipt.</p>
            <form method="post">
                <div class="fields">
                    <div class="field">
                        <label for="category_name">Category</label>
                        <input id="category_name" type="text" name="category_name" list="expense-category-list" value="<?php echo htmlspecialchars($_POST['category_name'] ?? ''); ?>" placeholder="e.g. Transport, Utilities, Repairs" required>
                        <input type="hidden" name="category_id" value="<?php echo htmlspecialchars($_POST['category_id'] ?? ''); ?>">
                        <?php if (!empty($categories)): ?>
                            <datalist id="expense-category-list">
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo htmlspecialchars($category['name']); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        <?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="amount">Amount</label>
                        <input id="amount" type="number" step="0.01" min="0.01" name="amount" value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>" required>
                    </div>
                    <div class="field">
                        <label for="expense_date">Expense Date</label>
                        <input id="expense_date" type="date" name="expense_date" value="<?php echo htmlspecialchars($_POST['expense_date'] ?? date('Y-m-d')); ?>" required>
                    </div>
                    <div class="field">
                        <label for="payment_method">Payment Method</label>
                        <select id="payment_method" name="payment_method" required>
                            <option value="">Choose method...</option>
                            <?php foreach (['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'mpesa' => 'M-Pesa', 'cheque' => 'Cheque', 'credit' => 'Credit'] as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (($_POST['payment_method'] ?? '') === $value) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="transaction_environment">Transaction Environment</label>
                        <select id="transaction_environment" name="transaction_environment">
                            <option value="live" <?php echo (($_POST['transaction_environment'] ?? 'live') === 'live') ? 'selected' : ''; ?>>Live</option>
                            <option value="sandbox" <?php echo (($_POST['transaction_environment'] ?? '') === 'sandbox') ? 'selected' : ''; ?>>Sandbox Test</option>
                        </select>
                    </div>
                    <div class="field full">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" required placeholder="Describe what the expense is for..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>
                    <div class="field">
                        <label for="vendor">Vendor / Payee</label>
                        <input id="vendor" type="text" name="vendor" value="<?php echo htmlspecialchars($_POST['vendor'] ?? ''); ?>" placeholder="Supplier, staff member, or service provider">
                    </div>
                    <div class="field">
                        <label for="reference_number">Reference Number</label>
                        <input id="reference_number" type="text" name="reference_number" value="<?php echo htmlspecialchars($_POST['reference_number'] ?? ''); ?>" placeholder="Receipt, cheque, transfer, or invoice reference">
                    </div>
                    <div class="field" id="mpesa_phone_field">
                        <label for="payee_phone">M-Pesa Number</label>
                        <input id="payee_phone" type="text" name="payee_phone" value="<?php echo htmlspecialchars($_POST['payee_phone'] ?? ''); ?>" placeholder="2547XXXXXXXX">
                    </div>
                    <div class="field" id="bank_name_field">
                        <label for="payee_bank_name">Bank Name</label>
                        <input id="payee_bank_name" type="text" name="payee_bank_name" value="<?php echo htmlspecialchars($_POST['payee_bank_name'] ?? ''); ?>" placeholder="Equity Bank">
                    </div>
                    <div class="field" id="account_name_field">
                        <label for="payee_account_name">Bank Account Name</label>
                        <input id="payee_account_name" type="text" name="payee_account_name" value="<?php echo htmlspecialchars($_POST['payee_account_name'] ?? ''); ?>" placeholder="Account holder name">
                    </div>
                    <div class="field" id="account_number_field">
                        <label for="payee_account_number">Bank Account Number</label>
                        <input id="payee_account_number" type="text" name="payee_account_number" value="<?php echo htmlspecialchars($_POST['payee_account_number'] ?? ''); ?>" placeholder="0123456789">
                    </div>
                    <div class="field" id="bank_branch_field">
                        <label for="payee_bank_branch">Bank Branch</label>
                        <input id="payee_bank_branch" type="text" name="payee_bank_branch" value="<?php echo htmlspecialchars($_POST['payee_bank_branch'] ?? ''); ?>" placeholder="Kilifi Branch">
                    </div>
                    <div class="field full">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" placeholder="Any approval note, supplier context, or supporting comment"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="btns">
                    <button class="btn btn-primary" type="submit" name="save_expense">Submit Expense</button>
                    <a class="btn btn-light" href="expenses.php">Back to Expenses</a>
                </div>
            </form>
        </div>

        <div class="card">
            <h2>Category And Transaction Guide</h2>
            <p class="sub">Use the available category list to keep approval and reporting consistent. For M-Pesa sandbox testing, the system stores a sandbox reference on the expense receipt without moving real money.</p>
            <div class="category-list">
                <?php if (!$categories): ?>
                    <div class="category">No active expense categories found.</div>
                <?php endif; ?>
                <?php foreach ($categories as $category): ?>
                    <div class="category">
                        <strong><?php echo htmlspecialchars($category['name']); ?></strong>
                        <small><?php echo htmlspecialchars($category['description'] ?: 'No description provided.'); ?></small>
                        <small>Budget: <?php echo formatCurrency((float) ($category['budget'] ?? 0)); ?></small>
                    </div>
                <?php endforeach; ?>
                <div class="category">
                    <strong>M-Pesa Sandbox</strong>
                    <small>Choose `M-Pesa` and set `Transaction Environment` to `Sandbox Test`. The request stores the payee phone and generates a sandbox test reference that appears on the expense receipt.</small>
                </div>
                <div class="category">
                    <strong>Bank Transfer Setup</strong>
                    <small>Choose `Bank Transfer` and fill in bank name, account name, account number, and branch so the payout details are already ready when the expense is approved.</small>
                </div>
            </div>
        </div>
    </section>
</main>
<script>
    function syncExpensePayoutFields() {
        const method = document.getElementById('payment_method').value;
        const environment = document.getElementById('transaction_environment');
        const mpesaField = document.getElementById('mpesa_phone_field');
        const bankFields = [
            document.getElementById('bank_name_field'),
            document.getElementById('account_name_field'),
            document.getElementById('account_number_field'),
            document.getElementById('bank_branch_field')
        ];

        mpesaField.style.display = method === 'mpesa' ? 'flex' : 'none';
        bankFields.forEach(function(field) {
            field.style.display = method === 'bank_transfer' ? 'flex' : 'none';
        });

        if (method !== 'mpesa') {
            environment.value = 'live';
            environment.disabled = true;
        } else {
            environment.disabled = false;
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        syncExpensePayoutFields();
        document.getElementById('payment_method').addEventListener('change', syncExpensePayoutFields);
    });
</script>
</body>
</html>
