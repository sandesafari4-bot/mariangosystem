<?php
include '../config.php';
checkAuth();
checkRole(['accountant', 'admin']);

$page_title = 'M-Pesa Transactions - ' . SCHOOL_NAME;

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
    $logDir = dirname(__DIR__) . '\logs';
    $files = glob($logDir . '\mpesa_callback_*.log');
    if (!$files) {
        return ['file' => null, 'lines' => []];
    }

    rsort($files);
    $file = $files[0];
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return ['file' => basename($file), 'lines' => array_slice($lines, -12)];
}

$mpesaTransactions = [];
$mpesaPayments = [];
$statusFilter = $_GET['status'] ?? '';
$paymentSearch = trim($_GET['search'] ?? '');

$hasMpesaTransactions = tableExists($pdo, 'mpesa_transactions');
$paymentMethods = columnsFor($pdo, 'payment_methods');
$paymentsColumns = columnsFor($pdo, 'payments');
$mpesaColumns = columnsFor($pdo, 'mpesa_transactions');

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
        foreach (['receipt', 'phone', 'accountref'] as $column) {
            if (isset($mpesaColumns[$column])) {
                $searchClauses[] = "$column LIKE ?";
                $params[] = '%' . $paymentSearch . '%';
            }
        }
        if ($searchClauses) {
            $conditions[] = '(' . implode(' OR ', $searchClauses) . ')';
        }
    }

    if ($conditions) {
        $query .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $orderColumn = isset($mpesaColumns['transaction_time']) ? 'transaction_time' : 'created_at';
    $query .= " ORDER BY $orderColumn DESC LIMIT 100";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $mpesaTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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
            SELECT p.*, s.full_name as student_name, s.Admission_number, i.invoice_no
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

$summary = [
    'transactions' => count($mpesaTransactions),
    'received_total' => array_sum(array_map(fn($row) => (float) ($row['amount'] ?? 0), $mpesaTransactions)),
    'completed_count' => count(array_filter($mpesaTransactions, fn($row) => ($row['status'] ?? '') === 'processed' || ($row['status'] ?? '') === 'completed')),
    'failed_count' => count(array_filter($mpesaTransactions, fn($row) => ($row['status'] ?? '') === 'failed')),
    'payments_count' => count($mpesaPayments),
];

$pendingDir = dirname(__DIR__) . '\logs\mpesa_pending';
$pendingFiles = is_dir($pendingDir) ? glob($pendingDir . '\*.json') : [];
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
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Manrope:wght@400;500;600;700&display=swap');
        :root {
            --ink: #15202b;
            --muted: #607080;
            --surface: #ffffff;
            --surface-alt: #f3f7f8;
            --line: rgba(21, 32, 43, 0.08);
            --green: #0f766e;
            --green-soft: #d6f5ef;
            --amber: #d97706;
            --amber-soft: #ffefcf;
            --red: #dc2626;
            --red-soft: #fee2e2;
            --blue: #0369a1;
            --blue-soft: #dbeafe;
            --shadow: 0 18px 50px rgba(21, 32, 43, 0.08);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Manrope', sans-serif;
            background: linear-gradient(180deg, #f4fbfb 0%, #edf2f7 100%);
            color: var(--ink);
        }
        .main-content { margin-left: 280px; margin-top: 70px; padding: 2rem; min-height: calc(100vh - 70px); }
        .shell { max-width: 1450px; margin: 0 auto; display: grid; gap: 1.5rem; }
        .hero, .card { background: var(--surface); border: 1px solid var(--line); border-radius: 26px; box-shadow: var(--shadow); }
        .hero {
            padding: 2rem;
            background: linear-gradient(135deg, #072b31 0%, #0f766e 60%, #14b8a6 100%);
            color: #fff;
            overflow: hidden;
            position: relative;
        }
        .hero::after {
            content: '';
            position: absolute;
            width: 320px;
            height: 320px;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
            right: -90px;
            bottom: -120px;
        }
        .hero-top, .card-head, .filters, .mini-grid { display: flex; gap: 1rem; }
        .hero-top, .card-head { justify-content: space-between; align-items: flex-start; }
        .eyebrow, .pill, .status {
            display: inline-flex; align-items: center; gap: .45rem; padding: .45rem .8rem; border-radius: 999px; font-weight: 700; font-size: .82rem;
        }
        .eyebrow { background: rgba(255,255,255,.14); margin-bottom: 1rem; text-transform: uppercase; letter-spacing: .05em; }
        h1, h2, .metric { font-family: 'Space Grotesk', sans-serif; }
        h1 { font-size: clamp(2rem, 4vw, 3rem); max-width: 12ch; line-height: 1.05; margin-bottom: .75rem; }
        .hero p { max-width: 60ch; color: rgba(255,255,255,.88); }
        .hero-actions { display: flex; gap: .8rem; flex-wrap: wrap; }
        .btn { text-decoration: none; border: 0; cursor: pointer; padding: .9rem 1.15rem; border-radius: 14px; font-weight: 700; display: inline-flex; align-items: center; gap: .6rem; }
        .btn-light { background: #fff; color: var(--ink); }
        .btn-glass { background: rgba(255,255,255,.12); color: #fff; border: 1px solid rgba(255,255,255,.18); }
        .stats { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 1rem; }
        .card { padding: 1.35rem; }
        .label { color: var(--muted); font-size: .83rem; text-transform: uppercase; letter-spacing: .05em; margin-bottom: .5rem; }
        .metric { font-size: 1.85rem; margin-bottom: .25rem; }
        .meta { color: var(--muted); font-size: .92rem; }
        .grid { display: grid; grid-template-columns: 1.4fr 1fr; gap: 1.5rem; }
        .stack { display: grid; gap: 1.5rem; }
        .filters { flex-wrap: wrap; }
        .filters input, .filters select {
            padding: .85rem 1rem; border-radius: 14px; border: 1px solid var(--line); background: var(--surface-alt); min-width: 180px;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: .95rem .85rem; text-align: left; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { font-size: .8rem; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; background: rgba(3, 105, 161, 0.04); }
        tr:last-child td { border-bottom: none; }
        .table-wrap { overflow-x: auto; }
        .pill-ok { background: var(--green-soft); color: var(--green); }
        .pill-fail { background: var(--red-soft); color: var(--red); }
        .pill-pending { background: var(--amber-soft); color: var(--amber); }
        .pill-info { background: var(--blue-soft); color: var(--blue); }
        .status { background: var(--surface-alt); color: var(--muted); }
        .log-box, .empty {
            background: #0f172a; color: #dbe7f5; border-radius: 18px; padding: 1rem; font-family: Consolas, monospace; font-size: .84rem; overflow: auto;
        }
        .empty { background: var(--surface-alt); color: var(--muted); font-family: inherit; border: 1px dashed var(--line); }
        .mini-grid { flex-wrap: wrap; }
        @media (max-width: 1200px) { .grid { grid-template-columns: 1fr; } .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 900px) { .main-content { margin-left: 0; padding: 1rem; } .hero-top, .card-head { flex-direction: column; } }
        @media (max-width: 640px) { .stats { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<?php include '../navigation.php'; ?>
<?php include '../sidebar.php'; ?>
<div class="main-content">
    <div class="shell">
        <section class="hero">
            <div class="hero-top">
                <div>
                    <div class="eyebrow"><i class="fas fa-signal"></i> M-Pesa Operations</div>
                    <h1>Track prompts, callbacks, and posted payments in one place.</h1>
                    <p>This screen helps accounting see whether STK requests were received, whether callbacks arrived, and which M-Pesa payments have already been posted into the invoice flow.</p>
                </div>
                <div class="hero-actions">
                    <a href="record_payment.php" class="btn btn-light"><i class="fas fa-mobile-alt"></i> Record Payment</a>
                    <a href="mpesa_diagnostics.php" class="btn btn-glass"><i class="fas fa-stethoscope"></i> Diagnostics</a>
                </div>
            </div>
        </section>

        <section class="stats">
            <div class="card"><div class="label">Tracked Transactions</div><div class="metric"><?php echo $summary['transactions']; ?></div><div class="meta"><?php echo money($summary['received_total']); ?> seen in M-Pesa audit records</div></div>
            <div class="card"><div class="label">Completed / Processed</div><div class="metric"><?php echo $summary['completed_count']; ?></div><div class="meta">Transactions marked completed or processed</div></div>
            <div class="card"><div class="label">Failed</div><div class="metric"><?php echo $summary['failed_count']; ?></div><div class="meta">Callbacks or prompts marked failed</div></div>
            <div class="card"><div class="label">Posted Payments</div><div class="metric"><?php echo $summary['payments_count']; ?></div><div class="meta">Recent M-Pesa payments in the finance ledger</div></div>
            <div class="card"><div class="label">Pending STK Files</div><div class="metric"><?php echo count($pendingFiles); ?></div><div class="meta">Open requests waiting for a callback</div></div>
        </section>

        <section class="grid">
            <div class="stack">
                <div class="card">
                    <div class="card-head">
                        <div>
                            <h2>Transaction Audit Trail</h2>
                            <div class="meta">Rows from `mpesa_transactions`, normalized around the current schema.</div>
                        </div>
                        <form class="filters" method="get">
                            <input type="text" name="search" placeholder="Search phone, receipt, account ref" value="<?php echo htmlspecialchars($paymentSearch); ?>">
                            <select name="status">
                                <option value="">All statuses</option>
                                <?php foreach (['received', 'matched', 'processed', 'completed', 'failed'] as $status): ?>
                                    <option value="<?php echo $status; ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-light" type="submit"><i class="fas fa-filter"></i> Apply</button>
                        </form>
                    </div>
                    <?php if ($hasMpesaTransactions && !empty($mpesaTransactions)): ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Receipt / Ref</th>
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
                                        $pillClass = $status === 'failed' ? 'pill-fail' : (($status === 'processed' || $status === 'completed') ? 'pill-ok' : (($status === 'matched') ? 'pill-info' : 'pill-pending'));
                                        ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($row['receipt'] ?? 'Pending receipt'); ?></strong></td>
                                            <td><?php echo htmlspecialchars($row['phone'] ?? '-'); ?></td>
                                            <td><?php echo money($row['amount'] ?? 0); ?></td>
                                            <td><?php echo htmlspecialchars($row['accountref'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars((string) ($row['transaction_time'] ?? $row['created_at'] ?? '-')); ?></td>
                                            <td><span class="pill <?php echo $pillClass; ?>"><?php echo ucfirst($status); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty">No `mpesa_transactions` records are available yet. STK requests and callbacks will start appearing here once they are logged.</div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-head">
                        <div>
                            <h2>Posted M-Pesa Payments</h2>
                            <div class="meta">These are actual payment records already posted into invoices and student balances.</div>
                        </div>
                    </div>
                    <?php if (!empty($mpesaPayments)): ?>
                        <div class="table-wrap">
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
                                                <strong><?php echo htmlspecialchars($payment['student_name'] ?? 'Unknown'); ?></strong><br>
                                                <small><?php echo htmlspecialchars($payment['Admission_number'] ?? ''); ?></small>
                                            </td>
                                            <td>
                                                <?php if (!empty($payment['invoice_id'])): ?>
                                                    <a href="invoice_details.php?id=<?php echo (int) $payment['invoice_id']; ?>">#<?php echo htmlspecialchars($payment['invoice_no'] ?? $payment['invoice_id']); ?></a>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td><strong><?php echo money($payment['amount'] ?? 0); ?></strong></td>
                                            <td><?php echo htmlspecialchars($reference); ?></td>
                                            <td><?php echo htmlspecialchars((string) $dateValue); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty">No posted M-Pesa payments were found yet.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="stack">
                <div class="card">
                    <div class="card-head">
                        <div>
                            <h2>Pending Callback Queue</h2>
                            <div class="meta">These are local pending STK request files waiting for a callback.</div>
                        </div>
                    </div>
                    <?php if (!empty($pendingFiles)): ?>
                        <div class="mini-grid">
                            <?php foreach (array_slice($pendingFiles, 0, 12) as $file): ?>
                                <span class="status"><i class="fas fa-clock"></i> <?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty">No pending STK requests are sitting in the local callback queue.</div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-head">
                        <div>
                            <h2>Latest Callback Log</h2>
                            <div class="meta"><?php echo $recentLog['file'] ? htmlspecialchars($recentLog['file']) : 'No callback log yet'; ?></div>
                        </div>
                        <a href="mpesa_diagnostics.php" class="btn btn-light"><i class="fas fa-external-link-alt"></i> Full Diagnostics</a>
                    </div>
                    <?php if (!empty($recentLog['lines'])): ?>
                        <div class="log-box"><?php echo htmlspecialchars(implode("\n", $recentLog['lines'])); ?></div>
                    <?php else: ?>
                        <div class="empty">No callback log content is available yet.</div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-head">
                        <div>
                            <h2>What To Watch</h2>
                            <div class="meta">Quick interpretation guide for common states.</div>
                        </div>
                    </div>
                    <div class="mini-grid">
                        <span class="pill pill-pending"><i class="fas fa-hourglass-half"></i> `received`: STK was sent, callback still pending</span>
                        <span class="pill pill-info"><i class="fas fa-link"></i> `matched`: callback arrived but payment may still need review</span>
                        <span class="pill pill-ok"><i class="fas fa-check-circle"></i> `processed`: callback posted to the ledger</span>
                        <span class="pill pill-fail"><i class="fas fa-triangle-exclamation"></i> `failed`: user cancelled, timeout, or processor error</span>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>
</body>
</html>
