<?php
include '../config.php';
require_once '../finance_accounts_helpers.php';

checkAuth();
checkRole(['accountant', 'admin']);
financeEnsureSchema($pdo);

$page_title = 'School Funds - ' . SCHOOL_NAME;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['create_account'])) {
            $accountName = trim($_POST['account_name'] ?? '');
            $accountType = trim($_POST['account_type'] ?? 'bank');
            $providerName = trim($_POST['provider_name'] ?? '');
            $accountNumber = trim($_POST['account_number'] ?? '');
            $phoneNumber = trim($_POST['phone_number'] ?? '');
            $openingBalance = (float) ($_POST['opening_balance'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');

            if ($accountName === '') {
                throw new Exception('Account name is required.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO school_accounts (
                    account_name, account_type, provider_name, account_number, phone_number,
                    current_balance, notes, created_by, updated_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $accountName,
                $accountType,
                $providerName !== '' ? $providerName : null,
                $accountNumber !== '' ? $accountNumber : null,
                $phoneNumber !== '' ? $phoneNumber : null,
                0,
                $notes !== '' ? $notes : null,
                $_SESSION['user_id'],
                $_SESSION['user_id'],
            ]);

            $accountId = (int) $pdo->lastInsertId();
            if ($openingBalance > 0) {
                $pdo->beginTransaction();
                financeRecordAccountTransaction($pdo, [
                    'account_id' => $accountId,
                    'transaction_type' => 'opening_balance',
                    'direction' => 'credit',
                    'amount' => $openingBalance,
                    'reference_no' => financeGenerateReference('OPEN'),
                    'description' => 'Opening balance for new school account',
                    'created_by' => $_SESSION['user_id'],
                ]);
                $pdo->commit();
            }

            if (!empty($_POST['set_default_salary'])) {
                financeSetDefaultAccount($pdo, $accountId, 'salary', $_SESSION['user_id']);
            }
            if (!empty($_POST['set_default_expense'])) {
                financeSetDefaultAccount($pdo, $accountId, 'expense', $_SESSION['user_id']);
            }

            $_SESSION['success'] = 'School account created successfully.';
        } elseif (isset($_POST['top_up_account'])) {
            $pdo->beginTransaction();
            $reference = financeRecordAccountTransaction($pdo, [
                'account_id' => (int) ($_POST['account_id'] ?? 0),
                'transaction_type' => 'top_up',
                'direction' => 'credit',
                'amount' => (float) ($_POST['amount'] ?? 0),
                'reference_no' => trim($_POST['reference_no'] ?? '') ?: financeGenerateReference('TOP'),
                'counterparty_name' => trim($_POST['source_name'] ?? ''),
                'description' => trim($_POST['description'] ?? 'School account top up'),
                'created_by' => $_SESSION['user_id'],
            ]);
            $pdo->commit();
            $_SESSION['success'] = 'Funds received successfully. Ref: ' . $reference;
        } elseif (isset($_POST['pay_expense'])) {
            $pdo->beginTransaction();
            $reference = financePayExpense(
                $pdo,
                (int) ($_POST['expense_id'] ?? 0),
                (int) ($_POST['account_id'] ?? 0),
                (int) $_SESSION['user_id'],
                trim($_POST['payment_note'] ?? '')
            );
            $pdo->commit();
            $_SESSION['success'] = 'Expense paid successfully. Ref: ' . $reference;
        } elseif (isset($_POST['set_default_account'])) {
            financeSetDefaultAccount(
                $pdo,
                (int) ($_POST['account_id'] ?? 0),
                trim($_POST['purpose'] ?? 'expense'),
                (int) $_SESSION['user_id']
            );
            $_SESSION['success'] = 'Default account updated.';
        } elseif (isset($_POST['sync_existing_collections'])) {
            $pdo->beginTransaction();
            $syncResult = financeSyncHistoricalCollections($pdo, (int) $_SESSION['user_id']);
            $pdo->commit();

            if ($syncResult['payment_count'] > 0) {
                $_SESSION['success'] = 'Synced ' . number_format($syncResult['payment_count']) . ' existing student payment(s) worth KES ' . number_format($syncResult['total_amount'], 2) . ' into School Funds.';
            } else {
                $_SESSION['success'] = 'All existing student payments are already reflected in School Funds.';
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }

    header('Location: school_funds.php');
    exit();
}

$accounts = financeGetActiveAccounts($pdo);
$defaultExpenseAccount = financeGetDefaultAccount($pdo, 'expense');
$defaultSalaryAccount = financeGetDefaultAccount($pdo, 'salary');
$unreconciledCollections = financeGetUnreconciledCollectionSummary($pdo);

$accountStats = $pdo->query("
    SELECT
        COUNT(*) as account_count,
        COALESCE(SUM(current_balance), 0) as total_balance,
        COALESCE(SUM(CASE WHEN account_type = 'bank' THEN current_balance ELSE 0 END), 0) as bank_balance,
        COALESCE(SUM(CASE WHEN account_type = 'mpesa' THEN current_balance ELSE 0 END), 0) as mpesa_balance
    FROM school_accounts
    WHERE is_active = 1
")->fetch(PDO::FETCH_ASSOC);

$approvedExpenses = $pdo->query("
    SELECT
        e.*,
        COALESCE(ec.name, CONCAT('Category #', e.category_id)) as category_name,
        u.full_name as requester_name
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    LEFT JOIN users u ON COALESCE(e.created_by, e.recorded_by, e.requested_by) = u.id
    WHERE e.status = 'approved' AND COALESCE(e.payment_status, 'unpaid') <> 'paid'
    ORDER BY e.expense_date ASC, e.created_at ASC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

$recentTransactions = $pdo->query("
    SELECT sat.*, sa.account_name
    FROM school_account_transactions sat
    LEFT JOIN school_accounts sa ON sat.account_id = sa.id
    ORDER BY sat.created_at DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);
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
        :root{--ink:#0f172a;--muted:#64748b;--line:rgba(15,23,42,.08);--brand:#0f766e;--brand2:#14b8a6;--danger:#dc2626;--warn:#f59e0b;--ok:#15803d;--card:#fff;--bg:#ecfeff;--shadow:0 20px 45px rgba(15,23,42,.08);--radius:22px}
        *{box-sizing:border-box}body{margin:0;font-family:Inter,sans-serif;background:radial-gradient(circle at top right,rgba(20,184,166,.18),transparent 28%),linear-gradient(135deg,#f8fffe,#e8f7f5);color:var(--ink)}
        .main-content{margin-left:280px;margin-top:70px;padding:2rem}.hero,.panel,.stat,.account-card,.expense-card,.txn-table{background:rgba(255,255,255,.96);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow)}
        .hero{padding:2rem;display:grid;grid-template-columns:1.5fr 1fr;gap:1rem;margin-bottom:1.5rem}.hero h1,.panel h2,.panel h3{font-family:"Space Grotesk",sans-serif;margin:0}.sub{color:var(--muted);line-height:1.7}
        .stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem}.stat{padding:1rem 1.1rem}.stat small{display:block;color:var(--muted);margin-bottom:.4rem}.stat strong{font-size:1.3rem}
        .grid{display:grid;grid-template-columns:1.2fr .8fr;gap:1.5rem;margin-bottom:1.5rem}.panel{padding:1.4rem}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem}
        label{display:block;font-weight:700;margin-bottom:.4rem}input,select,textarea{width:100%;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:.85rem .95rem;font:inherit;background:#fff}textarea{min-height:88px}
        .btns{display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1rem}.btn{border:0;border-radius:14px;padding:.85rem 1rem;font:inherit;font-weight:700;cursor:pointer}.btn-primary{background:linear-gradient(135deg,#0f766e,#14b8a6);color:#fff}.btn-soft{background:#ecfeff;color:#0f766e;border:1px solid rgba(15,118,110,.18)}
        .alert{padding:1rem 1.1rem;border-radius:16px;margin-bottom:1rem;font-weight:600}.ok{background:rgba(21,128,61,.12);color:var(--ok)}.err{background:rgba(220,38,38,.1);color:var(--danger)}
        .account-list,.expense-list{display:grid;gap:1rem}.account-card,.expense-card{padding:1.1rem}.account-meta,.expense-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.8rem;margin-top:.9rem}.pill{display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .7rem;border-radius:999px;font-size:.78rem;font-weight:700}
        .bank{background:rgba(37,99,235,.12);color:#1d4ed8}.mpesa{background:rgba(21,128,61,.12);color:#15803d}.cash{background:rgba(245,158,11,.14);color:#b45309}.default{background:rgba(15,118,110,.12);color:#0f766e}
        table{width:100%;border-collapse:collapse}th,td{padding:.8rem .65rem;border-bottom:1px solid rgba(148,163,184,.18);text-align:left;vertical-align:top}th{font-size:.8rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
        .credit{color:var(--ok);font-weight:700}.debit{color:var(--danger);font-weight:700}
        @media (max-width:1100px){.main-content{margin-left:0;padding:1rem}.hero,.grid,.stats,.form-grid,.account-meta,.expense-meta{grid-template-columns:1fr}}
    </style>
</head>
<body>
<?php include '../loader.php'; ?>
<?php include '../navigation.php'; ?>
<?php include '../sidebar.php'; ?>
<main class="main-content">
    <?php if (isset($_SESSION['success'])): ?><div class="alert ok"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div><?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?><div class="alert err"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div><?php endif; ?>

    <section class="hero">
        <div>
            <h1>School Funds Control</h1>
            <p class="sub">Track real school balances for bank, M-Pesa, and cash accounts. Use these balances when payroll is approved and when approved expenses are paid out, so the finance side behaves more like a real school accounting desk.</p>
        </div>
        <div class="stats">
            <div class="stat"><small>Total school balance</small><strong>KES <?php echo number_format((float)($accountStats['total_balance'] ?? 0), 2); ?></strong></div>
            <div class="stat"><small>Bank balance</small><strong>KES <?php echo number_format((float)($accountStats['bank_balance'] ?? 0), 2); ?></strong></div>
            <div class="stat"><small>M-Pesa balance</small><strong>KES <?php echo number_format((float)($accountStats['mpesa_balance'] ?? 0), 2); ?></strong></div>
            <div class="stat"><small>Active accounts</small><strong><?php echo number_format((int)($accountStats['account_count'] ?? 0)); ?></strong></div>
        </div>
    </section>

    <?php if (($unreconciledCollections['payment_count'] ?? 0) > 0): ?>
    <section class="panel" style="margin-bottom:1.5rem;border-color:rgba(245,158,11,.24);background:linear-gradient(135deg,rgba(255,251,235,.95),rgba(255,255,255,.98));">
        <div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap">
            <div>
                <h2>Existing Student Collections Need Sync</h2>
                <p class="sub"><?php echo number_format((int) $unreconciledCollections['payment_count']); ?> historical payment(s) worth KES <?php echo number_format((float) $unreconciledCollections['total_amount'], 2); ?> are in `payments` but not yet reflected in the School Funds ledger.</p>
            </div>
            <form method="post">
                <button class="btn btn-primary" type="submit" name="sync_existing_collections">
                    <i class="fas fa-rotate"></i> Sync Existing Collections
                </button>
            </form>
        </div>
    </section>
    <?php endif; ?>

    <section class="grid">
        <div class="panel">
            <h2>Add School Account</h2>
            <p class="sub">Create the school bank, M-Pesa paybill/store, or petty cash account that will fund salaries and expenses.</p>
            <form method="post">
                <div class="form-grid">
                    <div>
                        <label>Account Name</label>
                        <input type="text" name="account_name" placeholder="Equity Bank Operations Account" required>
                    </div>
                    <div>
                        <label>Account Type</label>
                        <select name="account_type" required>
                            <option value="bank">Bank</option>
                            <option value="mpesa">M-Pesa</option>
                            <option value="cash">Cash</option>
                        </select>
                    </div>
                    <div>
                        <label>Provider / Bank</label>
                        <input type="text" name="provider_name" placeholder="KCB, Equity, Safaricom">
                    </div>
                    <div>
                        <label>Account / Till / Paybill Number</label>
                        <input type="text" name="account_number" placeholder="1234567890">
                    </div>
                    <div>
                        <label>Phone Number</label>
                        <input type="text" name="phone_number" placeholder="2547XXXXXXXX">
                    </div>
                    <div>
                        <label>Opening Balance</label>
                        <input type="number" step="0.01" min="0" name="opening_balance" value="0">
                    </div>
                </div>
                <div style="margin-top:1rem">
                    <label>Notes</label>
                    <textarea name="notes" placeholder="Internal note for the finance team."></textarea>
                </div>
                <div class="btns">
                    <label><input type="checkbox" name="set_default_salary" value="1" style="width:auto;margin-right:.5rem"> Default salary account</label>
                    <label><input type="checkbox" name="set_default_expense" value="1" style="width:auto;margin-right:.5rem"> Default expense account</label>
                </div>
                <div class="btns">
                    <button class="btn btn-primary" type="submit" name="create_account">Create Account</button>
                </div>
            </form>
        </div>

        <div class="panel">
            <h2>Receive / Top Up Funds</h2>
            <p class="sub">Use this when the school receives money into bank or M-Pesa and you want the balance reflected in the system.</p>
            <form method="post">
                <div>
                    <label>School Account</label>
                    <select name="account_id" required>
                        <option value="">Select account</option>
                        <?php foreach ($accounts as $account): ?>
                            <option value="<?php echo (int)$account['id']; ?>"><?php echo htmlspecialchars($account['account_name'] . ' - KES ' . number_format((float)$account['current_balance'], 2)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-grid" style="margin-top:1rem">
                    <div>
                        <label>Amount</label>
                        <input type="number" step="0.01" min="0.01" name="amount" required>
                    </div>
                    <div>
                        <label>Reference</label>
                        <input type="text" name="reference_no" placeholder="Bank slip / M-Pesa ref">
                    </div>
                    <div>
                        <label>Source</label>
                        <input type="text" name="source_name" placeholder="School collection, sponsor, transfer">
                    </div>
                    <div>
                        <label>Description</label>
                        <input type="text" name="description" placeholder="Funds received into school account">
                    </div>
                </div>
                <div class="btns">
                    <button class="btn btn-primary" type="submit" name="top_up_account">Post Funds</button>
                </div>
            </form>
        </div>
    </section>

    <section class="panel" style="margin-bottom:1.5rem">
        <h2>School Accounts</h2>
        <div class="account-list">
            <?php if (!$accounts): ?><p class="sub">No school accounts created yet.</p><?php endif; ?>
            <?php foreach ($accounts as $account): ?>
                <article class="account-card">
                    <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start">
                        <div>
                            <h3><?php echo htmlspecialchars($account['account_name']); ?></h3>
                            <p class="sub"><?php echo htmlspecialchars($account['provider_name'] ?? ''); ?> <?php echo !empty($account['account_number']) ? '| ' . htmlspecialchars($account['account_number']) : ''; ?> <?php echo !empty($account['phone_number']) ? '| ' . htmlspecialchars($account['phone_number']) : ''; ?></p>
                        </div>
                        <div style="text-align:right">
                            <span class="pill <?php echo htmlspecialchars($account['account_type']); ?>"><?php echo strtoupper(htmlspecialchars($account['account_type'])); ?></span>
                            <div style="font-weight:700;font-size:1.25rem;margin-top:.5rem">KES <?php echo number_format((float)$account['current_balance'], 2); ?></div>
                        </div>
                    </div>
                    <div class="account-meta">
                        <div>
                            <small class="sub">Salary Default</small><br>
                            <?php if ((int)$account['is_default_salary'] === 1): ?><span class="pill default">Salary Default</span><?php else: ?>
                                <form method="post"><input type="hidden" name="account_id" value="<?php echo (int)$account['id']; ?>"><input type="hidden" name="purpose" value="salary"><button class="btn btn-soft" type="submit" name="set_default_account">Make Salary Default</button></form>
                            <?php endif; ?>
                        </div>
                        <div>
                            <small class="sub">Expense Default</small><br>
                            <?php if ((int)$account['is_default_expense'] === 1): ?><span class="pill default">Expense Default</span><?php else: ?>
                                <form method="post"><input type="hidden" name="account_id" value="<?php echo (int)$account['id']; ?>"><input type="hidden" name="purpose" value="expense"><button class="btn btn-soft" type="submit" name="set_default_account">Make Expense Default</button></form>
                            <?php endif; ?>
                        </div>
                        <div>
                            <small class="sub">Notes</small><br>
                            <span><?php echo htmlspecialchars($account['notes'] ?? 'No notes'); ?></span>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="grid">
        <div class="panel">
            <h2>Approved Expenses Ready For Payment</h2>
            <p class="sub">Once an expense is approved, the accountant can release the money from a selected school account here.</p>
            <div class="expense-list">
                <?php if (!$approvedExpenses): ?><p class="sub">No approved unpaid expenses right now.</p><?php endif; ?>
                <?php foreach ($approvedExpenses as $expense): ?>
                    <article class="expense-card">
                        <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap">
                            <div>
                                <h3><?php echo htmlspecialchars($expense['description']); ?></h3>
                                <p class="sub"><?php echo htmlspecialchars($expense['category_name']); ?> | <?php echo htmlspecialchars($expense['vendor'] ?? $expense['requester_name'] ?? 'Internal'); ?></p>
                            </div>
                            <div style="font-weight:700;font-size:1.2rem">KES <?php echo number_format((float)$expense['amount'], 2); ?></div>
                        </div>
                        <div class="expense-meta">
                            <div><small class="sub">Date</small><br><?php echo date('d M Y', strtotime($expense['expense_date'])); ?></div>
                            <div><small class="sub">Requested By</small><br><?php echo htmlspecialchars($expense['requester_name'] ?? 'N/A'); ?></div>
                            <div><small class="sub">Status</small><br><?php echo htmlspecialchars(ucfirst($expense['status'])); ?></div>
                        </div>
                        <form method="post" style="margin-top:1rem">
                            <input type="hidden" name="expense_id" value="<?php echo (int)$expense['id']; ?>">
                            <div class="form-grid">
                                <div>
                                    <label>Pay From</label>
                                    <select name="account_id" required>
                                        <option value="">Select account</option>
                                        <?php foreach ($accounts as $account): ?>
                                            <option value="<?php echo (int)$account['id']; ?>" <?php echo !empty($defaultExpenseAccount) && (int)$defaultExpenseAccount['id'] === (int)$account['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($account['account_name'] . ' - KES ' . number_format((float)$account['current_balance'], 2)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label>Payment Note</label>
                                    <input type="text" name="payment_note" placeholder="Optional disbursement note">
                                </div>
                            </div>
                            <div class="btns">
                                <button class="btn btn-primary" type="submit" name="pay_expense">Pay Expense</button>
                            </div>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="panel txn-table">
            <h2>Recent Fund Transactions</h2>
            <p class="sub">Every top-up and payout leaves an audit trail here.</p>
            <div style="overflow:auto">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Account</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$recentTransactions): ?>
                            <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:2rem">No fund transactions yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($recentTransactions as $transaction): ?>
                            <tr>
                                <td><?php echo date('d M Y H:i', strtotime($transaction['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($transaction['account_name'] ?? 'Unknown'); ?></td>
                                <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $transaction['transaction_type']))); ?></td>
                                <td class="<?php echo $transaction['direction'] === 'credit' ? 'credit' : 'debit'; ?>">
                                    <?php echo $transaction['direction'] === 'credit' ? '+' : '-'; ?>KES <?php echo number_format((float)$transaction['amount'], 2); ?>
                                </td>
                                <td><?php echo htmlspecialchars($transaction['reference_no'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</main>
</body>
</html>
