<?php
include '../config.php';
checkAuth();
checkRole(['accountant', 'admin']);

$page_title = 'M-Pesa Diagnostics - ' . SCHOOL_NAME;

function maskValue(string $value, int $visible = 4): string {
    if ($value === '') {
        return 'Not set';
    }
    if (strlen($value) <= $visible) {
        return str_repeat('*', strlen($value));
    }
    return substr($value, 0, $visible) . str_repeat('*', max(4, strlen($value) - $visible));
}

function readFileTail(string $path, int $lines = 20): array {
    if (!is_file($path)) {
        return [];
    }
    $content = file($path, FILE_IGNORE_NEW_LINES);
    return $content ? array_slice($content, -$lines) : [];
}

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$expectedCallback = $protocol . '://' . $host . '/mariango_school/mpesa_callback.php';

$settings = [
    'environment' => getSystemSetting('mpesa_env', MPESA_ENVIRONMENT),
    'consumer_key' => trim(getSystemSetting('mpesa_consumer_key', MPESA_CONSUMER_KEY)),
    'consumer_secret' => trim(getSystemSetting('mpesa_consumer_secret', MPESA_CONSUMER_SECRET)),
    'shortcode' => trim(getSystemSetting('mpesa_shortcode', MPESA_SHORTCODE)),
    'passkey' => trim(getSystemSetting('mpesa_passkey', MPESA_PASSKEY)),
    'callback_url' => trim(getSystemSetting('mpesa_callback_url', MPESA_CALLBACK_URL)),
    'account_reference' => trim(getSystemSetting('mpesa_account_reference', '{admission_number}')),
    'transaction_desc' => trim(getSystemSetting('mpesa_transaction_desc', 'School fee payment for invoice #{invoice_no}')),
];

$isConfigured = true;
foreach (['consumer_key', 'consumer_secret', 'shortcode', 'passkey'] as $field) {
    if ($settings[$field] === '' || str_starts_with($settings[$field], 'YOUR_')) {
        $isConfigured = false;
    }
}

$callbackMatches = rtrim($settings['callback_url'], '/') === rtrim($expectedCallback, '/');
$callbackFileExists = is_file(dirname(__DIR__) . '\mpesa_callback.php');
$pendingDir = dirname(__DIR__) . '\logs\mpesa_pending';
$pendingFiles = is_dir($pendingDir) ? glob($pendingDir . '\*.json') : [];
$callbackLogs = glob(dirname(__DIR__) . '\logs\mpesa_callback_*.log') ?: [];
rsort($callbackLogs);
$latestCallbackLog = $callbackLogs[0] ?? null;
$systemErrorLog = dirname(__DIR__) . '\logs\system_errors.log';

$healthCards = [
    ['label' => 'Core Credentials', 'value' => $isConfigured ? 'Ready' : 'Incomplete', 'tone' => $isConfigured ? 'good' : 'bad'],
    ['label' => 'Callback URL Match', 'value' => $callbackMatches ? 'Aligned' : 'Different', 'tone' => $callbackMatches ? 'good' : 'warn'],
    ['label' => 'Callback File', 'value' => $callbackFileExists ? 'Present' : 'Missing', 'tone' => $callbackFileExists ? 'good' : 'bad'],
    ['label' => 'Pending Queue', 'value' => (string) count($pendingFiles), 'tone' => count($pendingFiles) > 0 ? 'warn' : 'good'],
];
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
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800&family=Public+Sans:wght@400;500;600;700&display=swap');
        :root {
            --ink: #14212b;
            --muted: #667788;
            --surface: #ffffff;
            --surface-alt: #f5f7fb;
            --line: rgba(20, 33, 43, .08);
            --navy: #123047;
            --cyan: #0891b2;
            --cyan-soft: #d9f6fd;
            --green: #15803d;
            --green-soft: #dcfce7;
            --amber: #b45309;
            --amber-soft: #ffedd5;
            --red: #b91c1c;
            --red-soft: #fee2e2;
            --shadow: 0 18px 50px rgba(20, 33, 43, .08);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Public Sans', sans-serif; background: linear-gradient(180deg, #f5fbff 0%, #eef2f7 100%); color: var(--ink); }
        .main-content { margin-left: 280px; margin-top: 70px; padding: 2rem; min-height: calc(100vh - 70px); }
        .shell { max-width: 1450px; margin: 0 auto; display: grid; gap: 1.5rem; }
        .hero, .card { background: var(--surface); border: 1px solid var(--line); border-radius: 26px; box-shadow: var(--shadow); }
        .hero { padding: 2rem; background: linear-gradient(135deg, #0f1f2f 0%, #123047 45%, #0891b2 100%); color: #fff; position: relative; overflow: hidden; }
        .hero::after { content: ''; position: absolute; width: 360px; height: 360px; border-radius: 50%; background: rgba(255,255,255,.08); right: -100px; bottom: -120px; }
        .hero-top, .card-head { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; position: relative; z-index: 1; }
        .eyebrow, .badge { display: inline-flex; align-items: center; gap: .45rem; padding: .45rem .8rem; border-radius: 999px; font-weight: 700; font-size: .82rem; }
        .eyebrow { background: rgba(255,255,255,.14); margin-bottom: 1rem; text-transform: uppercase; letter-spacing: .05em; }
        h1, h2, .big { font-family: 'Outfit', sans-serif; }
        h1 { font-size: clamp(2rem, 4vw, 3rem); max-width: 13ch; line-height: 1.05; margin-bottom: .75rem; }
        .hero p { max-width: 64ch; color: rgba(255,255,255,.88); }
        .hero-actions { display: flex; gap: .8rem; flex-wrap: wrap; }
        .btn { text-decoration: none; padding: .9rem 1.15rem; border-radius: 14px; font-weight: 700; display: inline-flex; align-items: center; gap: .6rem; }
        .btn-light { background: #fff; color: var(--ink); }
        .btn-glass { background: rgba(255,255,255,.12); color: #fff; border: 1px solid rgba(255,255,255,.18); }
        .stats, .grid, .stack { display: grid; gap: 1.5rem; }
        .stats { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .grid { grid-template-columns: 1.2fr 1fr; }
        .card { padding: 1.35rem; }
        .label { color: var(--muted); font-size: .82rem; text-transform: uppercase; letter-spacing: .05em; margin-bottom: .45rem; }
        .big { font-size: 1.85rem; margin-bottom: .25rem; }
        .meta { color: var(--muted); font-size: .92rem; }
        .tone-good { background: var(--green-soft); color: var(--green); }
        .tone-warn { background: var(--amber-soft); color: var(--amber); }
        .tone-bad { background: var(--red-soft); color: var(--red); }
        .list { display: grid; gap: .9rem; }
        .row { display: flex; justify-content: space-between; gap: 1rem; padding: .95rem 1rem; border-radius: 18px; background: var(--surface-alt); border: 1px solid var(--line); }
        .row strong { display: block; margin-bottom: .2rem; }
        .code, .log { background: #0f172a; color: #dbe7f5; border-radius: 18px; padding: 1rem; font-family: Consolas, monospace; font-size: .84rem; overflow: auto; }
        .callout { padding: 1rem 1.1rem; border-radius: 18px; background: var(--surface-alt); border: 1px solid var(--line); color: var(--muted); }
        .mini { display: flex; flex-wrap: wrap; gap: .7rem; }
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
                    <div class="eyebrow"><i class="fas fa-stethoscope"></i> M-Pesa Diagnostics</div>
                    <h1>See config health before you blame the callback.</h1>
                    <p>Use this page to check whether your Daraja credentials are configured, whether the callback URL matches the current host, and what the latest callback and system logs are telling you.</p>
                </div>
                <div class="hero-actions">
                    <a href="mpesa_transactions.php" class="btn btn-light"><i class="fas fa-wave-square"></i> Transactions</a>
                    <a href="record_payment.php" class="btn btn-glass"><i class="fas fa-mobile-alt"></i> Try Payment Flow</a>
                </div>
            </div>
        </section>

        <section class="stats">
            <?php foreach ($healthCards as $card): ?>
                <div class="card">
                    <div class="label"><?php echo htmlspecialchars($card['label']); ?></div>
                    <div class="big"><?php echo htmlspecialchars($card['value']); ?></div>
                    <span class="badge tone-<?php echo htmlspecialchars($card['tone']); ?>">
                        <i class="fas fa-<?php echo $card['tone'] === 'good' ? 'check-circle' : ($card['tone'] === 'warn' ? 'triangle-exclamation' : 'circle-xmark'); ?>"></i>
                        <?php echo ucfirst($card['tone']); ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="grid">
            <div class="stack">
                <div class="card">
                    <div class="card-head">
                        <div>
                            <h2>Configuration Snapshot</h2>
                            <div class="meta">Pulled from saved settings with config fallbacks.</div>
                        </div>
                    </div>
                    <div class="list">
                        <div class="row"><div><strong>Environment</strong><span class="meta">Sandbox or production mode</span></div><div><?php echo htmlspecialchars($settings['environment']); ?></div></div>
                        <div class="row"><div><strong>Shortcode / Paybill</strong><span class="meta">The business number used for STK/paybill</span></div><div><?php echo htmlspecialchars($settings['shortcode'] ?: 'Not set'); ?></div></div>
                        <div class="row"><div><strong>Consumer Key</strong><span class="meta">Masked for safety</span></div><div><?php echo htmlspecialchars(maskValue($settings['consumer_key'])); ?></div></div>
                        <div class="row"><div><strong>Consumer Secret</strong><span class="meta">Masked for safety</span></div><div><?php echo htmlspecialchars(maskValue($settings['consumer_secret'])); ?></div></div>
                        <div class="row"><div><strong>Passkey</strong><span class="meta">Used to sign STK push requests</span></div><div><?php echo htmlspecialchars(maskValue($settings['passkey'])); ?></div></div>
                        <div class="row"><div><strong>Account Reference Template</strong><span class="meta">Used in STK/paybill requests</span></div><div><?php echo htmlspecialchars($settings['account_reference']); ?></div></div>
                        <div class="row"><div><strong>Transaction Description Template</strong><span class="meta">Shown to the customer and sent to Daraja</span></div><div><?php echo htmlspecialchars($settings['transaction_desc']); ?></div></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head">
                        <div>
                            <h2>Callback Health</h2>
                            <div class="meta">These checks help catch the most common STK delivery problems.</div>
                        </div>
                    </div>
                    <div class="list">
                        <div class="row"><div><strong>Configured Callback URL</strong><span class="meta">Currently saved in settings</span></div><div><?php echo htmlspecialchars($settings['callback_url']); ?></div></div>
                        <div class="row"><div><strong>Expected Callback URL</strong><span class="meta">Based on the current host and app path</span></div><div><?php echo htmlspecialchars($expectedCallback); ?></div></div>
                        <div class="row"><div><strong>Match Status</strong><span class="meta">Configured vs expected URL</span></div><div><span class="badge tone-<?php echo $callbackMatches ? 'good' : 'warn'; ?>"><?php echo $callbackMatches ? 'Aligned' : 'Different'; ?></span></div></div>
                        <div class="row"><div><strong>Callback File Present</strong><span class="meta">Whether `mpesa_callback.php` exists on disk</span></div><div><span class="badge tone-<?php echo $callbackFileExists ? 'good' : 'bad'; ?>"><?php echo $callbackFileExists ? 'Yes' : 'No'; ?></span></div></div>
                        <div class="row"><div><strong>Pending STK Queue</strong><span class="meta">Stored local requests waiting for callbacks</span></div><div><?php echo count($pendingFiles); ?> file(s)</div></div>
                    </div>
                </div>
            </div>

            <div class="stack">
                <div class="card">
                    <div class="card-head">
                        <div>
                            <h2>Pending Request Files</h2>
                            <div class="meta">Locally queued STK requests tracked before callback completion.</div>
                        </div>
                    </div>
                    <?php if (!empty($pendingFiles)): ?>
                        <div class="mini">
                            <?php foreach (array_slice($pendingFiles, 0, 15) as $file): ?>
                                <span class="badge tone-warn"><i class="fas fa-clock"></i> <?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="callout">No pending STK files are currently waiting in the queue.</div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-head">
                        <div>
                            <h2>Latest Callback Log</h2>
                            <div class="meta"><?php echo $latestCallbackLog ? htmlspecialchars(basename($latestCallbackLog)) : 'No callback logs yet'; ?></div>
                        </div>
                    </div>
                    <?php if ($latestCallbackLog): ?>
                        <div class="log"><?php echo htmlspecialchars(implode("\n", readFileTail($latestCallbackLog, 24))); ?></div>
                    <?php else: ?>
                        <div class="callout">No callback log has been created yet. Send an STK push or receive a callback to populate this section.</div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-head">
                        <div>
                            <h2>Recent System Errors</h2>
                            <div class="meta">Useful when callback processing or configuration writes fail.</div>
                        </div>
                    </div>
                    <?php if (is_file($systemErrorLog)): ?>
                        <div class="log"><?php echo htmlspecialchars(implode("\n", readFileTail($systemErrorLog, 20))); ?></div>
                    <?php else: ?>
                        <div class="callout">`logs/system_errors.log` has not been created yet.</div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-head">
                        <div>
                            <h2>Operator Notes</h2>
                            <div class="meta">Quick reminders for the most common M-Pesa support issues.</div>
                        </div>
                    </div>
                    <div class="list">
                        <div class="callout">If STK says “sent” but the phone receives nothing, first verify the environment, shortcode, passkey, and callback URL are all from the same Daraja app setup.</div>
                        <div class="callout">If parents are paying via paybill instead of STK, use the paybill receipt path in `record_payment.php` and make sure the correct account reference is communicated to them.</div>
                        <div class="callout">If callbacks are reaching the file but payments are not posting, check the callback log and then compare it with `mpesa_transactions` and the invoice balance in the transactions monitor.</div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>
</body>
</html>
