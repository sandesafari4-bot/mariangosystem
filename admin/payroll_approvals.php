<?php
include '../config.php';
require_once '../payroll_helpers.php';
require_once '../finance_accounts_helpers.php';

checkAuth();
checkRole(['admin']);
payrollEnsureSchema($pdo);
financeEnsureSchema($pdo);

$page_title = 'Payroll Approvals - ' . SCHOOL_NAME;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['approve_run'])) {
            $runId = intval($_POST['run_id']);
            $remarks = trim($_POST['remarks'] ?? '');
            $accountId = intval($_POST['account_id'] ?? 0);

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM payroll_runs WHERE id = ? AND status = 'submitted'");
            $stmt->execute([$runId]);
            $run = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$run) {
                throw new Exception('Only submitted payroll runs can be approved.');
            }

            $itemsStmt = $pdo->prepare("SELECT * FROM payroll_run_items WHERE payroll_run_id = ?");
            $itemsStmt->execute([$runId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$items) {
                throw new Exception('This payroll run has no staff items.');
            }
            if ($accountId <= 0) {
                throw new Exception('Choose the school account that will fund this payroll run.');
            }

            $paymentReference = financeRecordAccountTransaction($pdo, [
                'account_id' => $accountId,
                'transaction_type' => 'salary_payment',
                'direction' => 'debit',
                'amount' => (float) $run['total_net'],
                'reference_no' => financeGenerateReference('PAY'),
                'counterparty_name' => $run['title'] ?? 'Payroll run',
                'description' => 'Payroll release for ' . ($run['title'] ?? $run['payroll_code']),
                'related_type' => 'payroll_run',
                'related_id' => $runId,
                'created_by' => $_SESSION['user_id'],
            ]);

            $payStmt = $pdo->prepare("
                UPDATE payroll_run_items
                SET payment_status = 'paid',
                    payment_method = 'school account',
                    payment_reference = ?,
                    paid_at = NOW(),
                    paid_by = ?,
                    remarks = CONCAT(IFNULL(remarks, ''), IF(IFNULL(remarks, '') = '', '', '\n'), ?)
                WHERE payroll_run_id = ?
            ");
            $payStmt->execute([
                $paymentReference,
                $_SESSION['user_id'],
                $remarks !== '' ? 'Approval remark: ' . $remarks : 'Approved and released for payment.',
                $runId,
            ]);

            $runStmt = $pdo->prepare("
                UPDATE payroll_runs
                SET status = 'paid',
                    approved_by = ?,
                    approved_at = NOW(),
                    paid_by = ?,
                    paid_at = NOW(),
                    paid_from_account_id = ?,
                    payment_reference = ?,
                    rejection_reason = NULL,
                    notes = CONCAT(IFNULL(notes, ''), IF(IFNULL(notes, '') = '', '', '\n'), ?)
                WHERE id = ?
            ");
            $runStmt->execute([
                $_SESSION['user_id'],
                $_SESSION['user_id'],
                $accountId,
                $paymentReference,
                $remarks !== '' ? 'Approval: ' . $remarks : 'Approved and marked paid.',
                $runId,
            ]);

            $notifyStmt = $pdo->prepare("SELECT prepared_by FROM payroll_runs WHERE id = ?");
            $notifyStmt->execute([$runId]);
            $preparedBy = $notifyStmt->fetchColumn();
            if ($preparedBy) {
                payrollInsertNotification($pdo, [
                    'user_id' => $preparedBy,
                    'type' => 'payroll_approved',
                    'title' => 'Payroll approved and paid',
                    'message' => ($run['title'] ?? 'Payroll run') . ' has been approved and the staff items were marked paid.',
                    'related_id' => $runId,
                ]);
            }

            $pdo->commit();
            $_SESSION['success'] = 'Payroll approved and marked paid successfully.';
        } elseif (isset($_POST['reject_run'])) {
            $runId = intval($_POST['run_id']);
            $reason = trim($_POST['reason'] ?? '');
            if ($reason === '') {
                throw new Exception('Please provide a rejection reason.');
            }

            $stmt = $pdo->prepare("
                UPDATE payroll_runs
                SET status = 'rejected',
                    rejected_by = ?,
                    rejected_at = NOW(),
                    rejection_reason = ?
                WHERE id = ? AND status = 'submitted'
            ");
            $stmt->execute([$_SESSION['user_id'], $reason, $runId]);
            if ($stmt->rowCount() === 0) {
                throw new Exception('Only submitted payroll runs can be rejected.');
            }

            $runStmt = $pdo->prepare("SELECT title, prepared_by FROM payroll_runs WHERE id = ?");
            $runStmt->execute([$runId]);
            $run = $runStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!empty($run['prepared_by'])) {
                payrollInsertNotification($pdo, [
                    'user_id' => $run['prepared_by'],
                    'type' => 'payroll_rejected',
                    'title' => 'Payroll rejected',
                    'priority' => 'high',
                    'message' => ($run['title'] ?? 'Payroll run') . ' was rejected. Reason: ' . $reason,
                    'related_id' => $runId,
                ]);
            }

            $_SESSION['success'] = 'Payroll run rejected.';
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }

    header('Location: payroll_approvals.php');
    exit();
}

$pendingRuns = $pdo->query("
    SELECT pr.*, u.full_name as prepared_by_name
    FROM payroll_runs pr
    LEFT JOIN users u ON pr.prepared_by = u.id
    WHERE pr.status = 'submitted'
    ORDER BY pr.submitted_at ASC, pr.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

$fundingAccounts = financeGetActiveAccounts($pdo);
$defaultSalaryAccount = financeGetDefaultAccount($pdo, 'salary');

$historyRuns = $pdo->query("
    SELECT
        pr.*,
        u.full_name as prepared_by_name,
        a.full_name as approved_by_name,
        r.full_name as rejected_by_name,
        sa.account_name as paid_from_account_name
    FROM payroll_runs pr
    LEFT JOIN users u ON pr.prepared_by = u.id
    LEFT JOIN users a ON pr.approved_by = a.id
    LEFT JOIN users r ON pr.rejected_by = r.id
    LEFT JOIN school_accounts sa ON pr.paid_from_account_id = sa.id
    WHERE pr.status IN ('paid', 'approved', 'rejected', 'partially_paid')
    ORDER BY COALESCE(pr.paid_at, pr.approved_at, pr.rejected_at, pr.updated_at) DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

$allRuns = array_merge($pendingRuns, $historyRuns);
$runItemsById = [];
if ($allRuns) {
    $runIds = array_values(array_unique(array_column($allRuns, 'id')));
    $placeholders = implode(',', array_fill(0, count($runIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM payroll_run_items WHERE payroll_run_id IN ($placeholders) ORDER BY staff_name ASC");
    $stmt->execute($runIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $runItemsById[$item['payroll_run_id']][] = $item;
    }
}

$stats = $pdo->query("
    SELECT
        COUNT(CASE WHEN status = 'submitted' THEN 1 END) as submitted_count,
        COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count,
        COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_count,
        COALESCE(SUM(CASE WHEN status IN ('submitted', 'paid', 'approved', 'partially_paid') THEN total_net ELSE 0 END), 0) as total_value
    FROM payroll_runs
")->fetch(PDO::FETCH_ASSOC);
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
        :root{--ink:#111827;--brand:#1d4ed8;--brand2:#38bdf8;--ok:#16a34a;--warn:#f97316;--danger:#dc2626;--muted:#64748b;--line:rgba(29,78,216,.12);--card:#fff;--shadow:0 18px 42px rgba(15,23,42,.08);--radius:22px}
        *{box-sizing:border-box}body{margin:0;font-family:Inter,sans-serif;background:radial-gradient(circle at top right,rgba(56,189,248,.14),transparent 28%),linear-gradient(135deg,#f7fbff,#e8eef9);color:var(--ink)}
        .main-content{margin-left:280px;margin-top:70px;padding:2rem}.hero,.card,.run{background:rgba(255,255,255,.96);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow)}
        .hero{padding:2rem;display:grid;grid-template-columns:1.5fr 1fr;gap:1rem;margin-bottom:1.5rem}h1,h2,h3{font-family:"Space Grotesk",sans-serif;margin:0}.sub,.mini{color:var(--muted);line-height:1.6}
        .metrics{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem}.metric{padding:1rem;border-radius:18px;background:linear-gradient(135deg,#17356d,#1d4ed8);color:#fff}.metric small{display:block;color:rgba(255,255,255,.72);margin-bottom:.35rem}.metric strong{font-size:1.35rem}
        .alert{padding:1rem 1.1rem;border-radius:16px;margin-bottom:1rem;font-weight:600}.ok{background:rgba(22,163,74,.12);color:var(--ok)}.err{background:rgba(220,38,38,.10);color:var(--danger)}
        .card{padding:1.35rem;margin-bottom:1.5rem}.run{padding:1.2rem;margin-bottom:1rem}.run-meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.75rem;margin:1rem 0}.run-meta div{background:#f8fbff;border:1px solid var(--line);border-radius:16px;padding:.8rem}.run-meta small{display:block;color:var(--muted)}
        .btns{display:flex;gap:.75rem;flex-wrap:wrap}.btn{border:0;border-radius:14px;padding:.85rem 1rem;font:inherit;font-weight:700;cursor:pointer}.btn-ok{background:linear-gradient(135deg,#16a34a,#22c55e);color:#fff}.btn-no{background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff}
        textarea{width:100%;min-height:84px;border-radius:14px;border:1px solid rgba(29,78,216,.16);padding:.85rem .95rem;font:inherit;background:#fff}
        table{width:100%;border-collapse:collapse}th,td{padding:.8rem .65rem;border-bottom:1px solid rgba(148,163,184,.2);text-align:left;vertical-align:top}th{font-size:.82rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
        .badge{display:inline-flex;align-items:center;gap:.35rem;padding:.4rem .72rem;border-radius:999px;font-size:.78rem;font-weight:700}.status-draft{background:#e2e8f0;color:#334155}.status-submitted{background:rgba(249,115,22,.16);color:#c2410c}.status-approved{background:rgba(20,184,166,.12);color:#0f766e}.status-rejected{background:rgba(220,38,38,.12);color:#b91c1c}.status-paid{background:rgba(22,163,74,.14);color:#15803d}
        details summary{cursor:pointer;color:var(--brand);font-weight:700}
        @media (max-width:1100px){.main-content{margin-left:0;padding:1rem}.hero,.metrics,.run-meta{grid-template-columns:1fr}}
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
            <h1>Payroll Approval Desk</h1>
            <p class="sub">Admin reviews payroll runs prepared by the accountant. Approving a run now releases money from a real tracked school bank or M-Pesa account and marks the staff items paid.</p>
        </div>
        <div class="metrics">
            <div class="metric"><small>Waiting approval</small><strong><?php echo number_format($stats['submitted_count'] ?? 0); ?></strong></div>
            <div class="metric"><small>Paid runs</small><strong><?php echo number_format($stats['paid_count'] ?? 0); ?></strong></div>
            <div class="metric"><small>Rejected runs</small><strong><?php echo number_format($stats['rejected_count'] ?? 0); ?></strong></div>
            <div class="metric"><small>Total run value</small><strong><?php echo payrollMoney($stats['total_value'] ?? 0); ?></strong></div>
        </div>
    </section>

    <section class="card">
        <h2>Submitted Payroll Runs</h2>
        <p class="sub">Review totals, inspect the staff list, choose the funding account, then approve or reject with a reason.</p>
        <?php if (!$fundingAccounts): ?><p class="mini" style="color:#b91c1c">No school accounts are set up yet. Add a bank or M-Pesa account in `School Funds` before approving payroll.</p><?php endif; ?>
        <?php if (!$pendingRuns): ?><p class="mini">No payroll runs are waiting for approval.</p><?php endif; ?>
        <?php foreach ($pendingRuns as $run): ?>
            <article class="run">
                <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
                    <div>
                        <h3><?php echo htmlspecialchars($run['title']); ?></h3>
                        <p class="sub"><?php echo htmlspecialchars($run['payroll_code']); ?> • Prepared by <?php echo htmlspecialchars($run['prepared_by_name'] ?? 'System'); ?> • Submitted <?php echo !empty($run['submitted_at']) ? date('d M Y H:i', strtotime($run['submitted_at'])) : 'N/A'; ?></p>
                    </div>
                    <span class="badge <?php echo payrollStatusClass($run['status']); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $run['status']))); ?></span>
                </div>
                <div class="run-meta">
                    <div><small>Staff</small><strong><?php echo number_format($run['total_staff']); ?></strong></div>
                    <div><small>Gross</small><strong><?php echo payrollMoney($run['total_gross']); ?></strong></div>
                    <div><small>Deductions</small><strong><?php echo payrollMoney($run['total_deductions']); ?></strong></div>
                    <div><small>Net to release</small><strong><?php echo payrollMoney($run['total_net']); ?></strong></div>
                </div>
                <?php if (!empty($run['notes'])): ?><p class="mini"><?php echo nl2br(htmlspecialchars($run['notes'])); ?></p><?php endif; ?>
                <details style="margin:1rem 0">
                    <summary>View run staff</summary>
                    <table style="margin-top:1rem">
                        <thead><tr><th>Staff</th><th>Category</th><th>Gross</th><th>Statutory</th><th>PAYE</th><th>Net</th><th>Payment</th></tr></thead>
                        <tbody>
                        <?php foreach ($runItemsById[$run['id']] ?? [] as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['staff_name']); ?><br><span class="mini"><?php echo htmlspecialchars(ucfirst($item['role'])); ?></span></td>
                                <td><?php echo htmlspecialchars(payrollCategoryLabel($item['staff_category'])); ?></td>
                                <td><?php echo payrollMoney($item['gross_salary']); ?></td>
                                <td>NSSF <?php echo payrollMoney($item['nssf_employee'] ?? 0); ?><br>SHA <?php echo payrollMoney($item['sha_employee'] ?? 0); ?><br>Housing <?php echo payrollMoney($item['housing_levy_employee'] ?? 0); ?></td>
                                <td><?php echo payrollMoney($item['paye_tax'] ?? 0); ?></td>
                                <td><strong><?php echo payrollMoney($item['net_salary']); ?></strong></td>
                                <td><span class="badge <?php echo payrollStatusClass($item['payment_status']); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $item['payment_status']))); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                    <form method="post">
                        <input type="hidden" name="run_id" value="<?php echo (int) $run['id']; ?>">
                        <label style="display:block;font-weight:700;margin-bottom:.45rem">Pay From School Account</label>
                        <select name="account_id" style="width:100%;border-radius:14px;border:1px solid rgba(29,78,216,.16);padding:.85rem .95rem;font:inherit;background:#fff" required>
                            <option value="">Select funding account</option>
                            <?php foreach ($fundingAccounts as $account): ?>
                                <option value="<?php echo (int) $account['id']; ?>" <?php echo !empty($defaultSalaryAccount) && (int) $defaultSalaryAccount['id'] === (int) $account['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($account['account_name'] . ' (' . strtoupper($account['account_type']) . ') - KES ' . number_format((float) $account['current_balance'], 2)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label style="display:block;font-weight:700;margin-bottom:.45rem">Approval Remarks</label>
                        <textarea name="remarks" placeholder="Optional approval note for the accountant or audit trail."></textarea>
                        <div class="btns" style="margin-top:.8rem"><button class="btn btn-ok" type="submit" name="approve_run">Approve and Pay</button></div>
                    </form>
                    <form method="post">
                        <input type="hidden" name="run_id" value="<?php echo (int) $run['id']; ?>">
                        <label style="display:block;font-weight:700;margin-bottom:.45rem">Rejection Reason</label>
                        <textarea name="reason" placeholder="State what should be corrected before resubmission." required></textarea>
                        <div class="btns" style="margin-top:.8rem"><button class="btn btn-no" type="submit" name="reject_run">Reject Run</button></div>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="card">
        <h2>Payroll History</h2>
        <p class="sub">Recently approved, paid, or rejected runs stay here for quick audit review.</p>
        <?php if (!$historyRuns): ?><p class="mini">No payroll history yet.</p><?php endif; ?>
        <?php foreach ($historyRuns as $run): ?>
            <article class="run">
                <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
                    <div>
                        <h3><?php echo htmlspecialchars($run['title']); ?></h3>
                        <p class="sub"><?php echo htmlspecialchars($run['payroll_code']); ?> • Prepared by <?php echo htmlspecialchars($run['prepared_by_name'] ?? 'System'); ?></p>
                    </div>
                    <span class="badge <?php echo payrollStatusClass($run['status']); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $run['status']))); ?></span>
                </div>
                <div class="run-meta">
                    <div><small>Staff</small><strong><?php echo number_format($run['total_staff']); ?></strong></div>
                    <div><small>Net</small><strong><?php echo payrollMoney($run['total_net']); ?></strong></div>
                    <div><small>Approved By</small><strong><?php echo htmlspecialchars($run['approved_by_name'] ?? 'N/A'); ?></strong></div>
                    <div><small>Rejected By</small><strong><?php echo htmlspecialchars($run['rejected_by_name'] ?? 'N/A'); ?></strong></div>
                </div>
                <?php if (!empty($run['paid_from_account_name'])): ?><p class="mini">Paid from: <?php echo htmlspecialchars($run['paid_from_account_name']); ?><?php if (!empty($run['payment_reference'])): ?> | Ref: <?php echo htmlspecialchars($run['payment_reference']); ?><?php endif; ?></p><?php endif; ?>
                <?php if (!empty($run['rejection_reason'])): ?><p class="mini">Reason: <?php echo nl2br(htmlspecialchars($run['rejection_reason'])); ?></p><?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
</main>
</body>
</html>
