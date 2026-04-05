<?php
include '../config.php';
checkAuth();
checkRole(['admin']);

$search = trim((string) ($_GET['search'] ?? ''));
$deviceFilter = trim((string) ($_GET['device'] ?? ''));
$visitorTypeFilter = trim((string) ($_GET['visitor_type'] ?? ''));

$conditions = [];
$params = [];

if ($search !== '') {
    $conditions[] = "(full_name LIKE ? OR ip_address LIKE ? OR browser LIKE ? OR operating_system LIKE ? OR last_page LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($deviceFilter !== '') {
    $conditions[] = "device_type = ?";
    $params[] = $deviceFilter;
}

if ($visitorTypeFilter !== '') {
    $conditions[] = "visitor_type = ?";
    $params[] = $visitorTypeFilter;
}

$whereClause = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$stats = $pdo->query("
    SELECT
        COUNT(*) AS total_visits,
        COUNT(CASE WHEN visitor_type = 'guest' THEN 1 END) AS guest_visits,
        COUNT(CASE WHEN visitor_type = 'authenticated' THEN 1 END) AS authenticated_visits,
        COUNT(CASE WHEN last_seen >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) AS active_last_24h
    FROM visitor_logs
")->fetch(PDO::FETCH_ASSOC) ?: [];

$deviceBreakdown = $pdo->query("
    SELECT device_type, COUNT(*) AS total
    FROM visitor_logs
    GROUP BY device_type
    ORDER BY total DESC, device_type ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stmt = $pdo->prepare("
    SELECT
        id,
        visitor_type,
        full_name,
        user_id,
        ip_address,
        device_type,
        browser,
        operating_system,
        first_page,
        last_page,
        referrer,
        page_views,
        first_seen,
        last_seen
    FROM visitor_logs
    $whereClause
    ORDER BY last_seen DESC
    LIMIT 200
");
$stmt->execute($params);
$visitors = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

function visitorLogsTimeAgo(string $timestamp): string {
    $time = strtotime($timestamp);
    if ($time === false) {
        return $timestamp;
    }

    $delta = time() - $time;
    if ($delta < 60) {
        return 'just now';
    }
    if ($delta < 3600) {
        $minutes = (int) floor($delta / 60);
        return $minutes . ' min ago';
    }
    if ($delta < 86400) {
        $hours = (int) floor($delta / 3600);
        return $hours . ' hr ago';
    }

    $days = (int) floor($delta / 86400);
    return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor Logs - <?php echo htmlspecialchars(SCHOOL_NAME); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1f4e79;
            --primary-soft: #eaf3fb;
            --accent: #16a085;
            --warning: #d97706;
            --danger: #c0392b;
            --text: #1f2937;
            --muted: #6b7280;
            --line: #dbe4ee;
            --surface: #ffffff;
            --page: #f5f7fb;
            --shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--page);
            color: var(--text);
        }

        .main-content {
            margin-left: 280px;
            padding: 2rem;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .page-header h1 {
            margin: 0 0 0.35rem;
            font-size: 1.85rem;
        }

        .page-header p {
            margin: 0;
            color: var(--muted);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card,
        .panel {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: var(--shadow);
        }

        .stat-card {
            padding: 1.2rem;
        }

        .stat-label {
            color: var(--muted);
            font-size: 0.9rem;
            margin-bottom: 0.45rem;
        }

        .stat-value {
            font-size: 1.9rem;
            font-weight: 700;
        }

        .panel {
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto;
            gap: 0.9rem;
            align-items: end;
        }

        .field label {
            display: block;
            margin-bottom: 0.45rem;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .field input,
        .field select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 0.8rem 0.9rem;
            font-size: 0.95rem;
            background: #fff;
        }

        .btn {
            border: none;
            border-radius: 12px;
            padding: 0.85rem 1.1rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
        }

        .btn-light {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .breakdown {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .chip {
            background: var(--primary-soft);
            color: var(--primary);
            border-radius: 999px;
            padding: 0.5rem 0.85rem;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 0.9rem 0.8rem;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
            font-size: 0.93rem;
        }

        th {
            color: var(--muted);
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 0.25rem 0.65rem;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .badge-guest {
            background: #fff4e5;
            color: var(--warning);
        }

        .badge-authenticated {
            background: #e9f9f2;
            color: var(--accent);
        }

        .muted {
            color: var(--muted);
        }

        .path {
            font-family: Consolas, monospace;
            word-break: break-all;
        }

        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .filter-form {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <div>
                <h1><i class="fas fa-user-shield" style="color: var(--primary);"></i> Visitor Logs</h1>
                <p>Track who visited the site, what device they used, the last page they opened, and when they were last active.</p>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total tracked visits</div>
                <div class="stat-value"><?php echo number_format((int) ($stats['total_visits'] ?? 0)); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Guests</div>
                <div class="stat-value"><?php echo number_format((int) ($stats['guest_visits'] ?? 0)); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Logged-in users</div>
                <div class="stat-value"><?php echo number_format((int) ($stats['authenticated_visits'] ?? 0)); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active in last 24 hours</div>
                <div class="stat-value"><?php echo number_format((int) ($stats['active_last_24h'] ?? 0)); ?></div>
            </div>
        </div>

        <div class="panel">
            <form method="GET" class="filter-form">
                <div class="field">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" placeholder="Name, IP, browser, OS, or page" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="field">
                    <label for="device">Device</label>
                    <select id="device" name="device">
                        <option value="">All devices</option>
                        <option value="Desktop" <?php echo $deviceFilter === 'Desktop' ? 'selected' : ''; ?>>Desktop</option>
                        <option value="Mobile" <?php echo $deviceFilter === 'Mobile' ? 'selected' : ''; ?>>Mobile</option>
                        <option value="Tablet" <?php echo $deviceFilter === 'Tablet' ? 'selected' : ''; ?>>Tablet</option>
                        <option value="Unknown Device" <?php echo $deviceFilter === 'Unknown Device' ? 'selected' : ''; ?>>Unknown</option>
                    </select>
                </div>
                <div class="field">
                    <label for="visitor_type">Type</label>
                    <select id="visitor_type" name="visitor_type">
                        <option value="">All visitors</option>
                        <option value="guest" <?php echo $visitorTypeFilter === 'guest' ? 'selected' : ''; ?>>Guest</option>
                        <option value="authenticated" <?php echo $visitorTypeFilter === 'authenticated' ? 'selected' : ''; ?>>Logged in</option>
                    </select>
                </div>
                <div style="display:flex; gap:0.75rem;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                    <a href="visitor_logs.php" class="btn btn-light">Reset</a>
                </div>
            </form>
        </div>

        <div class="panel">
            <div class="breakdown">
                <?php foreach ($deviceBreakdown as $device): ?>
                    <span class="chip">
                        <?php echo htmlspecialchars($device['device_type'] ?: 'Unknown'); ?>:
                        <?php echo number_format((int) $device['total']); ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="panel table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Visitor</th>
                        <th>Type</th>
                        <th>IP Address</th>
                        <th>Device</th>
                        <th>Browser / OS</th>
                        <th>Pages</th>
                        <th>Last Seen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$visitors): ?>
                        <tr>
                            <td colspan="7" class="muted">No visitor logs found yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($visitors as $visitor): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($visitor['full_name'] ?: 'Guest Visitor'); ?></strong><br>
                                    <span class="muted">
                                        <?php echo $visitor['user_id'] ? 'User ID: ' . (int) $visitor['user_id'] : 'Anonymous session'; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo htmlspecialchars($visitor['visitor_type']); ?>">
                                        <?php echo htmlspecialchars(ucfirst($visitor['visitor_type'])); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($visitor['ip_address']); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($visitor['device_type']); ?></strong><br>
                                    <span class="muted"><?php echo (int) $visitor['page_views']; ?> page views</span>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($visitor['browser']); ?></strong><br>
                                    <span class="muted"><?php echo htmlspecialchars($visitor['operating_system']); ?></span>
                                </td>
                                <td>
                                    <div class="path"><?php echo htmlspecialchars($visitor['last_page']); ?></div>
                                    <div class="muted">First: <?php echo htmlspecialchars($visitor['first_page']); ?></div>
                                    <?php if (!empty($visitor['referrer'])): ?>
                                        <div class="muted">Ref: <?php echo htmlspecialchars($visitor['referrer']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo visitorLogsTimeAgo($visitor['last_seen']); ?></strong><br>
                                    <span class="muted"><?php echo htmlspecialchars($visitor['last_seen']); ?></span><br>
                                    <span class="muted">Started: <?php echo htmlspecialchars($visitor['first_seen']); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
