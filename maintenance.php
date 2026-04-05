<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'config.php';

$maintenance_value = (string) getSystemSetting('maintenance_mode', 'off');
$maintenance_mode = in_array(strtolower($maintenance_value), ['on', '1', 'true'], true);
$is_admin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';

if (!$maintenance_mode && !$is_admin) {
    header('Location: index.php');
    exit();
}

$school_name = getSystemSetting('school_name', SCHOOL_NAME);
$school_acronym = trim((string) getSystemSetting('school_acronym', ''));
$school_address = trim((string) getSystemSetting('school_address', SCHOOL_LOCATION));
$school_phone = trim((string) getSystemSetting('school_phone', ''));
$school_email = trim((string) getSystemSetting('school_email', ''));
$school_website = trim((string) getSystemSetting('school_website', ''));
$school_motto = trim((string) getSystemSetting('school_motto', ''));
$school_logo = trim((string) getSystemSetting('school_logo', SCHOOL_LOGO));
$maintenance_message = trim((string) getSystemSetting(
    'maintenance_message',
    'The system is temporarily unavailable while maintenance is being carried out. Please try again shortly.'
));
$maintenance_type = (string) getSystemSetting('maintenance_type', 'manual');
$maintenance_end_time = trim((string) getSystemSetting('maintenance_end_time', ''));
$estimated_end = '';
$logo_url = '';

if ($maintenance_end_time !== '') {
    $timestamp = strtotime($maintenance_end_time);
    if ($timestamp !== false) {
        $estimated_end = date('D, M j, Y g:i A', $timestamp);
    }
}

if ($school_logo !== '') {
    $logo_file = basename($school_logo);
    $logo_path = __DIR__ . '/uploads/logos/' . $logo_file;
    if (is_file($logo_path)) {
        $logo_url = 'uploads/logos/' . rawurlencode($logo_file) . '?v=' . filemtime($logo_path);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance - <?php echo htmlspecialchars($school_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

        :root {
            --primary: #4361ee;
            --primary-light: #4895ef;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --warning: #f8961e;
            --danger: #f94144;
            --dark: #2b2d42;
            --gray: #6c757d;
            --gray-light: #95a5a6;
            --light: #f8f9fa;
            --white: #ffffff;
            --gradient-1: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-2: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --gradient-3: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 12px 32px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 24px 60px rgba(15, 23, 42, 0.22);
            --radius-md: 16px;
            --radius-lg: 24px;
            --transition: all 0.3s ease;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            color: var(--dark);
            background: var(--gradient-1);
        }

        .page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 20px;
            background:
                radial-gradient(circle at top left, rgba(255, 255, 255, 0.16), transparent 24%),
                radial-gradient(circle at bottom right, rgba(79, 172, 254, 0.25), transparent 22%),
                linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .shell {
            width: 100%;
            max-width: 1080px;
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            background: rgba(255, 255, 255, 0.94);
            border-radius: 28px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.35);
            box-shadow: var(--shadow-lg);
            backdrop-filter: blur(14px);
        }

        .hero {
            padding: 48px 42px;
            background:
                linear-gradient(160deg, rgba(67, 97, 238, 0.08), rgba(67, 97, 238, 0)),
                linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.94));
        }

        .status-side {
            padding: 48px 36px;
            background:
                radial-gradient(circle at top right, rgba(255, 255, 255, 0.12), transparent 24%),
                linear-gradient(180deg, #1f2937 0%, #111827 100%);
            color: #e5eefc;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 18px;
        }

        .brand-row {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 24px;
        }

        .brand-icon {
            width: 58px;
            height: 58px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--gradient-1);
            color: var(--white);
            font-size: 1.4rem;
            box-shadow: var(--shadow-md);
            flex-shrink: 0;
            overflow: hidden;
        }

        .brand-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .brand-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
        }

        .brand-subtitle {
            font-size: 0.9rem;
            color: var(--gray);
            margin-top: 0.2rem;
        }

        .motto {
            margin-top: 14px;
            padding-left: 14px;
            border-left: 4px solid rgba(67, 97, 238, 0.2);
            color: var(--gray);
            font-size: 0.98rem;
            line-height: 1.7;
            font-style: italic;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(248, 150, 30, 0.12);
            color: #c76b09;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 16px;
        }

        h1 {
            font-size: clamp(2rem, 4vw, 3.25rem);
            line-height: 1.05;
            color: var(--dark);
            margin-bottom: 14px;
            max-width: 12ch;
        }

        .lead {
            font-size: 1.02rem;
            line-height: 1.75;
            color: var(--gray);
            max-width: 60ch;
            margin-bottom: 28px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }

        .panel {
            background: var(--white);
            border: 1px solid rgba(148, 163, 184, 0.16);
            border-radius: var(--radius-md);
            padding: 20px;
            box-shadow: var(--shadow-sm);
        }

        .panel h2 {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
            color: var(--dark);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 12px;
        }

        .panel p {
            color: var(--gray);
            line-height: 1.65;
            font-size: 15px;
        }

        .status {
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 10px;
        }

        .timeline-card,
        .help-card {
            position: relative;
            z-index: 1;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: var(--radius-md);
            padding: 22px;
            backdrop-filter: blur(8px);
        }

        .side-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #9ec5ff;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .side-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: #ffffff;
        }

        .side-text {
            color: #d6e3f7;
            line-height: 1.7;
            font-size: 0.96rem;
        }

        .help-list {
            list-style: none;
            display: grid;
            gap: 12px;
            margin-top: 14px;
        }

        .help-list li {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            color: #dce9f9;
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .help-list i {
            color: var(--success);
            margin-top: 3px;
        }

        .contact-list {
            list-style: none;
            display: grid;
            gap: 12px;
            margin-top: 14px;
        }

        .contact-list li {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            color: #dce9f9;
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .contact-list i {
            color: #9ec5ff;
            margin-top: 3px;
        }

        .contact-list a {
            color: #ffffff;
            text-decoration: none;
        }

        .contact-list a:hover {
            text-decoration: underline;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 6px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 18px;
            border-radius: 14px;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            transition: var(--transition);
        }

        .btn-primary {
            background: var(--gradient-1);
            color: var(--white);
            box-shadow: 0 10px 22px rgba(67, 97, 238, 0.24);
        }

        .btn-secondary {
            background: rgba(67, 97, 238, 0.08);
            color: var(--dark);
            border: 1px solid rgba(67, 97, 238, 0.12);
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .footer {
            margin-top: 22px;
            font-size: 14px;
            color: var(--gray-light);
            line-height: 1.6;
        }

        @media (max-width: 920px) {
            .shell {
                grid-template-columns: 1fr;
            }

            .hero,
            .status-side {
                padding: 32px 24px;
            }

            h1 {
                max-width: none;
            }
        }

        @media (max-width: 640px) {
            h1 {
                font-size: 28px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .actions {
                flex-direction: column;
            }

            .btn {
                justify-content: center;
                width: 100%;
            }

            .brand-row {
                align-items: flex-start;
            }

            .hero,
            .status-side {
                padding: 24px 18px;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <main class="shell">
            <section class="hero">
                <div class="brand-row">
                    <div class="brand-icon">
                        <?php if ($logo_url !== ''): ?>
                        <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="<?php echo htmlspecialchars($school_name); ?> logo">
                        <?php else: ?>
                        <i class="fas fa-school"></i>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="brand-title"><?php echo htmlspecialchars($school_name); ?></div>
                        <div class="brand-subtitle">
                            <?php
                            if ($school_acronym !== '') {
                                echo htmlspecialchars($school_acronym) . ' • ';
                            }
                            echo 'School Management System';
                            ?>
                        </div>
                    </div>
                </div>

                <?php if ($school_motto !== ''): ?>
                <div class="motto">"<?php echo htmlspecialchars($school_motto); ?>"</div>
                <?php endif; ?>

                <div class="badge">
                    <i class="fas fa-screwdriver-wrench"></i>
                    <?php echo $maintenance_type === 'scheduled' ? 'Scheduled Maintenance' : 'Maintenance In Progress'; ?>
                </div>

                <h1>We’re polishing the system for you.</h1>
                <p class="lead">
                    Access is temporarily paused while updates are being applied. We’re working to bring the platform back online quickly and smoothly.
                </p>

                <section class="stats-grid">
                    <div class="panel">
                        <h2><i class="fas fa-bullhorn" style="color: var(--primary);"></i> Admin Message</h2>
                        <p><?php echo nl2br(htmlspecialchars($maintenance_message)); ?></p>
                    </div>

                    <div class="panel">
                        <h2><i class="fas fa-clock" style="color: var(--warning);"></i> Status</h2>
                        <p class="status">
                            <?php echo $maintenance_type === 'scheduled' ? 'Scheduled update underway' : 'Temporary system maintenance'; ?>
                        </p>
                        <p>
                            <?php if ($estimated_end !== ''): ?>
                                Estimated completion: <?php echo htmlspecialchars($estimated_end); ?>
                            <?php else: ?>
                                No estimated completion time has been provided yet.
                            <?php endif; ?>
                        </p>
                    </div>
                </section>

                <div class="actions">
                    <a class="btn btn-primary" href="login.php">
                        <i class="fas fa-rotate-right"></i>
                        Try Again
                    </a>
                    <a class="btn btn-secondary" href="index.php">
                        <i class="fas fa-house"></i>
                        Back to Home
                    </a>
                    <?php if ($is_admin): ?>
                    <a class="btn btn-secondary" href="admin/system_settings.php">
                        <i class="fas fa-sliders"></i>
                        Open Settings
                    </a>
                    <?php endif; ?>
                </div>

                <div class="footer">
                    Thank you for your patience while maintenance is being completed.
                </div>
            </section>

            <aside class="status-side">
                <div class="timeline-card">
                    <div class="side-label">Current Overview</div>
                    <div class="side-title">System update in progress</div>
                    <div class="side-text">
                        The platform is temporarily limited while maintenance tasks are finalized. Your data remains intact and protected during this process.
                    </div>
                </div>

                <div class="help-card">
                    <div class="side-label">What You Can Do</div>
                    <ul class="help-list">
                        <li><i class="fas fa-circle-check"></i><span>Wait a few minutes and refresh the page again.</span></li>
                        <li><i class="fas fa-circle-check"></i><span>Use the estimated end time as a guide when it is available.</span></li>
                        <li><i class="fas fa-circle-check"></i><span>Contact the administrator if the interruption lasts longer than expected.</span></li>
                    </ul>
                </div>

                <?php if ($school_address !== '' || $school_phone !== '' || $school_email !== '' || $school_website !== ''): ?>
                <div class="help-card">
                    <div class="side-label">School Details</div>
                    <ul class="contact-list">
                        <?php if ($school_address !== ''): ?>
                        <li><i class="fas fa-location-dot"></i><span><?php echo nl2br(htmlspecialchars($school_address)); ?></span></li>
                        <?php endif; ?>
                        <?php if ($school_phone !== ''): ?>
                        <li><i class="fas fa-phone"></i><span><?php echo htmlspecialchars($school_phone); ?></span></li>
                        <?php endif; ?>
                        <?php if ($school_email !== ''): ?>
                        <li><i class="fas fa-envelope"></i><span><a href="mailto:<?php echo htmlspecialchars($school_email); ?>"><?php echo htmlspecialchars($school_email); ?></a></span></li>
                        <?php endif; ?>
                        <?php if ($school_website !== ''): ?>
                        <li><i class="fas fa-globe"></i><span><a href="<?php echo htmlspecialchars($school_website); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($school_website); ?></a></span></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </aside>
        </main>
    </div>
</body>
</html>
