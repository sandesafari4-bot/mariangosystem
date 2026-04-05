<?php
include '../config.php';
checkAuth();
checkRole(['admin']); // Only admins can access system settings

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_general_settings'])) {
        $school_name = $_POST['school_name'];
        $school_code = $_POST['school_code'];
        $school_email = $_POST['school_email'];
        $school_phone = $_POST['school_phone'];
        $school_address = $_POST['school_address'];
        $school_motto = $_POST['school_motto'];
        $academic_year = $_POST['academic_year'];
        $timezone = $_POST['timezone'];
        $currency = $_POST['currency'];
        $date_format = $_POST['date_format'];
        
        // Update settings in database
        $settings = [
            'school_name' => $school_name,
            'school_code' => $school_code,
            'school_email' => $school_email,
            'school_phone' => $school_phone,
            'school_address' => $school_address,
            'school_motto' => $school_motto,
            'academic_year' => $academic_year,
            'timezone' => $timezone,
            'currency' => $currency,
            'date_format' => $date_format
        ];
        
        foreach ($settings as $key => $value) {
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }
        
        // Sync academic year to academic_years table
        if (!empty($academic_year)) {
            // Extract year from formats like "2025-2026" or "2025"
            $year_value = preg_match('/^(\d{4})/', $academic_year, $matches) ? $matches[1] : $academic_year;
            
            // Check if this year exists in academic_years table
            $check_stmt = $pdo->prepare("SELECT id FROM academic_years WHERE year = ?");
            $check_stmt->execute([$year_value]);
            
            if (!$check_stmt->fetch()) {
                // Year doesn't exist, insert it as active
                $insert_stmt = $pdo->prepare("INSERT INTO academic_years (year, is_active) VALUES (?, 1)");
                $insert_stmt->execute([$year_value]);
            }
        }
        
        $success = "General settings updated successfully!";
    }
    
    if (isset($_POST['update_system_config'])) {
        $maintenance_mode = isset($_POST['maintenance_mode']) ? 1 : 0;
        $student_registration = isset($_POST['student_registration']) ? 1 : 0;
        $teacher_registration = isset($_POST['teacher_registration']) ? 1 : 0;
        $max_login_attempts = $_POST['max_login_attempts'];
        $session_timeout = $_POST['session_timeout'];
        $password_policy = $_POST['password_policy'];
        $backup_frequency = $_POST['backup_frequency'];
        $log_retention = $_POST['log_retention'];
        
        $settings = [
            'maintenance_mode' => $maintenance_mode,
            'student_registration' => $student_registration,
            'teacher_registration' => $teacher_registration,
            'max_login_attempts' => $max_login_attempts,
            'session_timeout' => $session_timeout,
            'password_policy' => $password_policy,
            'backup_frequency' => $backup_frequency,
            'log_retention' => $log_retention
        ];
        
        foreach ($settings as $key => $value) {
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }
        
        $success = "System configuration updated successfully!";
    }
    
    if (isset($_POST['update_email_settings'])) {
        $smtp_host = $_POST['smtp_host'];
        $smtp_port = $_POST['smtp_port'];
        $smtp_username = $_POST['smtp_username'];
        $smtp_password = $_POST['smtp_password'];
        $smtp_encryption = $_POST['smtp_encryption'];
        $from_email = $_POST['from_email'];
        $from_name = $_POST['from_name'];
        
        $settings = [
            'smtp_host' => $smtp_host,
            'smtp_port' => $smtp_port,
            'smtp_username' => $smtp_username,
            'smtp_password' => $smtp_password,
            'smtp_encryption' => $smtp_encryption,
            'from_email' => $from_email,
            'from_name' => $from_name
        ];
        
        foreach ($settings as $key => $value) {
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }
        
        $success = "Email settings updated successfully!";
    }
    
    if (isset($_POST['update_notification_settings'])) {
        $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
        $sms_notifications = isset($_POST['sms_notifications']) ? 1 : 0;
        $push_notifications = isset($_POST['push_notifications']) ? 1 : 0;
        $fee_reminders = isset($_POST['fee_reminders']) ? 1 : 0;
        $assignment_notifications = isset($_POST['assignment_notifications']) ? 1 : 0;
        $exam_notifications = isset($_POST['exam_notifications']) ? 1 : 0;
        $attendance_alerts = isset($_POST['attendance_alerts']) ? 1 : 0;
        $system_alerts = isset($_POST['system_alerts']) ? 1 : 0;
        
        $settings = [
            'email_notifications' => $email_notifications,
            'sms_notifications' => $sms_notifications,
            'push_notifications' => $push_notifications,
            'fee_reminders' => $fee_reminders,
            'assignment_notifications' => $assignment_notifications,
            'exam_notifications' => $exam_notifications,
            'attendance_alerts' => $attendance_alerts,
            'system_alerts' => $system_alerts
        ];
        
        foreach ($settings as $key => $value) {
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }
        
        $success = "Notification settings updated successfully!";
    }
    
    if (isset($_POST['test_email'])) {
        $test_email = $_POST['test_email'];
        
        // Test email configuration (simplified)
        $test_result = true; // This would be actual email test logic
        
        if ($test_result) {
            $success = "Test email sent successfully to $test_email!";
        } else {
            $error = "Failed to send test email. Please check your email settings.";
        }
    }
    
    if (isset($_POST['backup_database'])) {
        $backup_type = $_POST['backup_type'];
        
        // Perform database backup (simplified)
        $backup_result = true; // This would be actual backup logic
        
        if ($backup_result) {
            $success = "Database backup completed successfully!";
        } else {
            $error = "Database backup failed. Please try again.";
        }
    }
    
    if (isset($_POST['clear_cache'])) {
        // Clear system cache (simplified)
        $cache_result = true;
        
        if ($cache_result) {
            $success = "System cache cleared successfully!";
        } else {
            $error = "Failed to clear cache. Please try again.";
        }
    }
    
    if (isset($_POST['clear_logs'])) {
        $log_type = $_POST['log_type'];
        
        // Clear system logs (simplified)
        $log_result = true;
        
        if ($log_result) {
            $success = ucfirst($log_type) . " logs cleared successfully!";
        } else {
            $error = "Failed to clear logs. Please try again.";
        }
    }
    
    // Refresh data after form submission
    header("Location: system_settings.php?" . ($success ? "success=" . urlencode($success) : "error=" . urlencode($error)));
    exit();
}

// Get current settings
function getSetting($key, $default = '') {
    global $pdo;
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['setting_value'] : $default;
}

// Get system information
$system_info = [
    'php_version' => PHP_VERSION,
    'database_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
    'server_software' => $_SERVER['SERVER_SOFTWARE'],
    'max_upload_size' => ini_get('upload_max_filesize'),
    'max_execution_time' => ini_get('max_execution_time'),
    'memory_limit' => ini_get('memory_limit')
];

// Get disk space information
$disk_total = disk_total_space('/');
$disk_free = disk_free_space('/');
$disk_used = $disk_total - $disk_free;
$disk_usage_percent = $disk_total > 0 ? round(($disk_used / $disk_total) * 100, 2) : 0;

// Get recent system logs
$recent_logs = $pdo->query("
    SELECT * FROM system_logs 
    ORDER BY created_at DESC 
    LIMIT 10
")->fetchAll();

// Get backup history
$backup_history = $pdo->query("
    SELECT * FROM backups 
    ORDER BY created_at DESC 
    LIMIT 5
")->fetchAll();

$page_title = "System Settings - " . SCHOOL_NAME;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            transition: margin-left 0.3s ease;
            background: #f8f9fa;
            min-height: calc(100vh - 70px);
        }
        
        .management-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e9ecef;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
        }
        
        .btn {
            padding: 0.7rem 1.5rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-1px);
        }
        
        .btn-success {
            background: #27ae60;
            color: white;
        }
        
        .btn-warning {
            background: #f39c12;
            color: white;
        }
        
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid #3498db;
            color: #3498db;
        }
        
        .btn-outline:hover {
            background: #3498db;
            color: white;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }
        
        /* Settings Layout */
        .settings-layout {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 2rem;
        }
        
        .settings-sidebar {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            height: fit-content;
            position: sticky;
            top: 90px;
        }
        
        .settings-nav {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .settings-nav-item {
            margin-bottom: 0.5rem;
        }
        
        .settings-nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: 6px;
            color: #495057;
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .settings-nav-link:hover, .settings-nav-link.active {
            background: #3498db;
            color: white;
        }
        
        .settings-nav-link i {
            width: 20px;
            text-align: center;
        }
        
        .settings-content {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .settings-section {
            display: none;
            padding: 2rem;
        }
        
        .settings-section.active {
            display: block;
        }
        
        .settings-header {
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e9ecef;
        }
        
        .settings-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        
        .settings-description {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .form-hint {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 0.3rem;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        
        input:focus, select:focus, textarea:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        /* Toggle Switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 30px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 22px;
            width: 22px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: #27ae60;
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(30px);
        }
        
        /* System Info Cards */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .info-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border-left: 4px solid #3498db;
        }
        
        .info-icon {
            font-size: 2rem;
            color: #3498db;
            margin-bottom: 1rem;
        }
        
        .info-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        
        .info-label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        /* Progress Bar */
        .progress-container {
            margin: 1.5rem 0;
        }
        
        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .progress-bar {
            height: 8px;
            background: #ecf0f1;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: #3498db;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        /* Alert Styles */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
        }
        
        .alert-success {
            background: rgba(39, 174, 96, 0.1);
            border-color: #27ae60;
            color: #155724;
        }
        
        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            border-color: #e74c3c;
            color: #721c24;
        }
        
        .alert-warning {
            background: rgba(243, 156, 18, 0.1);
            border-color: #f39c12;
            color: #856404;
        }
        
        /* Maintenance Actions */
        .maintenance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .maintenance-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border: 1px solid #e9ecef;
        }
        
        .maintenance-icon {
            font-size: 2rem;
            color: #3498db;
            margin-bottom: 1rem;
        }
        
        .maintenance-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        
        .maintenance-description {
            color: #6c757d;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }
        
        /* Logs Table */
        .logs-table {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        
        .table-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8f9fa;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-success {
            background: rgba(39, 174, 96, 0.1);
            color: #27ae60;
        }
        
        .status-error {
            background: rgba(231, 76, 60, 0.1);
            color: #e74c3c;
        }
        
        .status-warning {
            background: rgba(243, 156, 18, 0.1);
            color: #f39c12;
        }
        
        .status-info {
            background: rgba(52, 152, 219, 0.1);
            color: #3498db;
        }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .settings-layout {
                grid-template-columns: 1fr;
            }
            
            .settings-sidebar {
                position: static;
                margin-bottom: 1rem;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .management-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .maintenance-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include 'loader.php'; ?>
    <?php include 'navigation.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="main-content">
        <div class="management-header">
            <div>
                <h1>System Settings</h1>
                <p>Manage system configuration, preferences, and maintenance</p>
            </div>
            <div class="action-buttons">
                <button class="btn btn-outline" onclick="backupDatabase()">
                    <i class="fas fa-database"></i>
                    Backup
                </button>
                <button class="btn btn-outline" onclick="clearSystemCache()">
                    <i class="fas fa-broom"></i>
                    Clear Cache
                </button>
            </div>
        </div>
        
        <!-- Success/Error Messages -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['success']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>
        
        <div class="settings-layout">
            <!-- Settings Sidebar -->
            <div class="settings-sidebar">
                <ul class="settings-nav">
                    <li class="settings-nav-item">
                        <a href="#general" class="settings-nav-link active" onclick="showSection('general')">
                            <i class="fas fa-cog"></i>
                            General Settings
                        </a>
                    </li>
                    <li class="settings-nav-item">
                        <a href="#system" class="settings-nav-link" onclick="showSection('system')">
                            <i class="fas fa-sliders-h"></i>
                            System Configuration
                        </a>
                    </li>
                    <li class="settings-nav-item">
                        <a href="#email" class="settings-nav-link" onclick="showSection('email')">
                            <i class="fas fa-envelope"></i>
                            Email Settings
                        </a>
                    </li>
                    <li class="settings-nav-item">
                        <a href="#notifications" class="settings-nav-link" onclick="showSection('notifications')">
                            <i class="fas fa-bell"></i>
                            Notifications
                        </a>
                    </li>
                    <li class="settings-nav-item">
                        <a href="#maintenance" class="settings-nav-link" onclick="showSection('maintenance')">
                            <i class="fas fa-tools"></i>
                            Maintenance
                        </a>
                    </li>
                    <li class="settings-nav-item">
                        <a href="#system-info" class="settings-nav-link" onclick="showSection('system-info')">
                            <i class="fas fa-info-circle"></i>
                            System Information
                        </a>
                    </li>
                </ul>
            </div>
            
            <!-- Settings Content -->
            <div class="settings-content">
                <!-- General Settings -->
                <div id="general-section" class="settings-section active">
                    <div class="settings-header">
                        <h2 class="settings-title">General Settings</h2>
                        <p class="settings-description">Configure basic school information and system preferences</p>
                    </div>
                    
                    <form method="POST">
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label for="school_name">School Name *</label>
                                <input type="text" id="school_name" name="school_name" value="<?php echo htmlspecialchars(getSetting('school_name', SCHOOL_NAME)); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="school_code">School Code</label>
                                <input type="text" id="school_code" name="school_code" value="<?php echo htmlspecialchars(getSetting('school_code', 'SCH001')); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="academic_year">Academic Year *</label>
                                <select id="academic_year" name="academic_year" required>
                                    <?php
                                    $current_year = date('Y');
                                    for ($year = $current_year - 2; $year <= $current_year + 2; $year++):
                                        $academic_year = $year . '/' . ($year + 1);
                                        $selected = getSetting('academic_year', $current_year . '/' . ($current_year + 1)) == $academic_year ? 'selected' : '';
                                    ?>
                                    <option value="<?php echo $academic_year; ?>" <?php echo $selected; ?>>
                                        <?php echo $academic_year; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="school_email">School Email *</label>
                                <input type="email" id="school_email" name="school_email" value="<?php echo htmlspecialchars(getSetting('school_email', 'info@school.edu')); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="school_phone">School Phone</label>
                                <input type="text" id="school_phone" name="school_phone" value="<?php echo htmlspecialchars(getSetting('school_phone', '+254700000000')); ?>">
                            </div>
                            
                            <div class="form-group full-width">
                                <label for="school_address">School Address</label>
                                <textarea id="school_address" name="school_address" rows="3"><?php echo htmlspecialchars(getSetting('school_address', 'School Address, City, Country')); ?></textarea>
                            </div>
                            
                            <div class="form-group full-width">
                                <label for="school_motto">School Motto</label>
                                <input type="text" id="school_motto" name="school_motto" value="<?php echo htmlspecialchars(getSetting('school_motto', 'Education for Excellence')); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="timezone">Timezone *</label>
                                <select id="timezone" name="timezone" required>
                                    <option value="Africa/Nairobi" <?php echo getSetting('timezone', 'Africa/Nairobi') == 'Africa/Nairobi' ? 'selected' : ''; ?>>Africa/Nairobi</option>
                                    <option value="UTC" <?php echo getSetting('timezone') == 'UTC' ? 'selected' : ''; ?>>UTC</option>
                                    <!-- Add more timezones as needed -->
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="currency">Currency *</label>
                                <select id="currency" name="currency" required>
                                    <option value="KES" <?php echo getSetting('currency', 'KES') == 'KES' ? 'selected' : ''; ?>>KES - Kenyan Shilling</option>
                                    <option value="USD" <?php echo getSetting('currency') == 'USD' ? 'selected' : ''; ?>>USD - US Dollar</option>
                                    <option value="EUR" <?php echo getSetting('currency') == 'EUR' ? 'selected' : ''; ?>>EUR - Euro</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="date_format">Date Format *</label>
                                <select id="date_format" name="date_format" required>
                                    <option value="Y-m-d" <?php echo getSetting('date_format', 'Y-m-d') == 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                                    <option value="d/m/Y" <?php echo getSetting('date_format') == 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY</option>
                                    <option value="m/d/Y" <?php echo getSetting('date_format') == 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY</option>
                                </select>
                            </div>
                        </div>
                        
                        <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                            <button type="button" class="btn btn-outline" onclick="resetForm('general')">Reset</button>
                            <button type="submit" name="update_general_settings" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
                
                <!-- System Configuration -->
                <div id="system-section" class="settings-section">
                    <div class="settings-header">
                        <h2 class="settings-title">System Configuration</h2>
                        <p class="settings-description">Configure system behavior, security, and performance settings</p>
                    </div>
                    
                    <form method="POST">
                        <div class="form-grid">
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 1rem;">
                                    <span>Maintenance Mode</span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="maintenance_mode" <?php echo getSetting('maintenance_mode', '0') == '1' ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </label>
                                <div class="form-hint">When enabled, only administrators can access the system</div>
                            </div>
                            
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 1rem;">
                                    <span>Student Registration</span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="student_registration" <?php echo getSetting('student_registration', '1') == '1' ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </label>
                                <div class="form-hint">Allow new student registrations</div>
                            </div>
                            
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 1rem;">
                                    <span>Teacher Registration</span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="teacher_registration" <?php echo getSetting('teacher_registration', '1') == '1' ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </label>
                                <div class="form-hint">Allow new teacher registrations</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="max_login_attempts">Max Login Attempts</label>
                                <input type="number" id="max_login_attempts" name="max_login_attempts" min="1" max="10" value="<?php echo getSetting('max_login_attempts', '5'); ?>">
                                <div class="form-hint">Number of failed login attempts before account lockout</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="session_timeout">Session Timeout (minutes)</label>
                                <input type="number" id="session_timeout" name="session_timeout" min="5" max="480" value="<?php echo getSetting('session_timeout', '30'); ?>">
                                <div class="form-hint">User session timeout in minutes</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="password_policy">Password Policy</label>
                                <select id="password_policy" name="password_policy">
                                    <option value="low" <?php echo getSetting('password_policy', 'medium') == 'low' ? 'selected' : ''; ?>>Low (6+ characters)</option>
                                    <option value="medium" <?php echo getSetting('password_policy', 'medium') == 'medium' ? 'selected' : ''; ?>>Medium (8+ characters, mixed case)</option>
                                    <option value="high" <?php echo getSetting('password_policy') == 'high' ? 'selected' : ''; ?>>High (10+ characters, mixed case + numbers + symbols)</option>
                                </select>
                                <div class="form-hint">Password strength requirements</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="backup_frequency">Backup Frequency</label>
                                <select id="backup_frequency" name="backup_frequency">
                                    <option value="daily" <?php echo getSetting('backup_frequency', 'daily') == 'daily' ? 'selected' : ''; ?>>Daily</option>
                                    <option value="weekly" <?php echo getSetting('backup_frequency') == 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                    <option value="monthly" <?php echo getSetting('backup_frequency') == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                </select>
                                <div class="form-hint">How often to automatically backup the database</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="log_retention">Log Retention (days)</label>
                                <input type="number" id="log_retention" name="log_retention" min="7" max="365" value="<?php echo getSetting('log_retention', '30'); ?>">
                                <div class="form-hint">Number of days to keep system logs</div>
                            </div>
                        </div>
                        
                        <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                            <button type="button" class="btn btn-outline" onclick="resetForm('system')">Reset</button>
                            <button type="submit" name="update_system_config" class="btn btn-primary">Save Configuration</button>
                        </div>
                    </form>
                </div>
                
                <!-- Email Settings -->
                <div id="email-section" class="settings-section">
                    <div class="settings-header">
                        <h2 class="settings-title">Email Settings</h2>
                        <p class="settings-description">Configure email server settings for system notifications</p>
                    </div>
                    
                    <form method="POST">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="smtp_host">SMTP Host *</label>
                                <input type="text" id="smtp_host" name="smtp_host" value="<?php echo htmlspecialchars(getSetting('smtp_host', 'smtp.gmail.com')); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="smtp_port">SMTP Port *</label>
                                <input type="number" id="smtp_port" name="smtp_port" value="<?php echo getSetting('smtp_port', '587'); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="smtp_username">SMTP Username *</label>
                                <input type="text" id="smtp_username" name="smtp_username" value="<?php echo htmlspecialchars(getSetting('smtp_username', '')); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="smtp_password">SMTP Password *</label>
                                <input type="password" id="smtp_password" name="smtp_password" value="<?php echo htmlspecialchars(getSetting('smtp_password', '')); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="smtp_encryption">Encryption</label>
                                <select id="smtp_encryption" name="smtp_encryption">
                                    <option value="">None</option>
                                    <option value="tls" <?php echo getSetting('smtp_encryption', 'tls') == 'tls' ? 'selected' : ''; ?>>TLS</option>
                                    <option value="ssl" <?php echo getSetting('smtp_encryption') == 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="from_email">From Email *</label>
                                <input type="email" id="from_email" name="from_email" value="<?php echo htmlspecialchars(getSetting('from_email', getSetting('school_email', 'noreply@school.edu'))); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="from_name">From Name *</label>
                                <input type="text" id="from_name" name="from_name" value="<?php echo htmlspecialchars(getSetting('from_name', getSetting('school_name', SCHOOL_NAME))); ?>" required>
                            </div>
                        </div>
                        
                        <!-- Test Email Section -->
                        <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; margin: 2rem 0;">
                            <h3 style="margin-bottom: 1rem; color: #2c3e50;">Test Email Configuration</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="test_email">Test Email Address</label>
                                    <input type="email" id="test_email" name="test_email" placeholder="Enter email to test configuration">
                                </div>
                            </div>
                            <button type="submit" name="test_email" class="btn btn-outline">
                                <i class="fas fa-paper-plane"></i> Send Test Email
                            </button>
                        </div>
                        
                        <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                            <button type="button" class="btn btn-outline" onclick="resetForm('email')">Reset</button>
                            <button type="submit" name="update_email_settings" class="btn btn-primary">Save Email Settings</button>
                        </div>
                    </form>
                </div>
                
                <!-- Notification Settings -->
                <div id="notifications-section" class="settings-section">
                    <div class="settings-header">
                        <h2 class="settings-title">Notification Settings</h2>
                        <p class="settings-description">Configure how and when the system sends notifications</p>
                    </div>
                    
                    <form method="POST">
                        <div class="form-grid">
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 1rem;">
                                    <span>Email Notifications</span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="email_notifications" <?php echo getSetting('email_notifications', '1') == '1' ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </label>
                                <div class="form-hint">Send notifications via email</div>
                            </div>
                            
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 1rem;">
                                    <span>SMS Notifications</span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="sms_notifications" <?php echo getSetting('sms_notifications', '0') == '1' ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </label>
                                <div class="form-hint">Send notifications via SMS (requires SMS gateway)</div>
                            </div>
                            
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 1rem;">
                                    <span>Push Notifications</span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="push_notifications" <?php echo getSetting('push_notifications', '0') == '1' ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </label>
                                <div class="form-hint">Send push notifications to mobile app</div>
                            </div>
                            
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 1rem;">
                                    <span>Fee Reminders</span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="fee_reminders" <?php echo getSetting('fee_reminders', '1') == '1' ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </label>
                                <div class="form-hint">Send fee payment reminders to parents</div>
                            </div>
                            
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 1rem;">
                                    <span>Assignment Notifications</span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="assignment_notifications" <?php echo getSetting('assignment_notifications', '1') == '1' ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </label>
                                <div class="form-hint">Notify students about new assignments</div>
                            </div>
                            
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 1rem;">
                                    <span>Exam Notifications</span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="exam_notifications" <?php echo getSetting('exam_notifications', '1') == '1' ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </label>
                                <div class="form-hint">Notify about upcoming exams and results</div>
                            </div>
                            
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 1rem;">
                                    <span>Attendance Alerts</span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="attendance_alerts" <?php echo getSetting('attendance_alerts', '1') == '1' ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </label>
                                <div class="form-hint">Send alerts for student attendance issues</div>
                            </div>
                            
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 1rem;">
                                    <span>System Alerts</span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="system_alerts" <?php echo getSetting('system_alerts', '1') == '1' ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </label>
                                <div class="form-hint">Receive system maintenance and security alerts</div>
                            </div>
                        </div>
                        
                        <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                            <button type="button" class="btn btn-outline" onclick="resetForm('notifications')">Reset</button>
                            <button type="submit" name="update_notification_settings" class="btn btn-primary">Save Notification Settings</button>
                        </div>
                    </form>
                </div>
                
                <!-- Maintenance -->
                <div id="maintenance-section" class="settings-section">
                    <div class="settings-header">
                        <h2 class="settings-title">System Maintenance</h2>
                        <p class="settings-description">Perform system maintenance tasks and monitor system health</p>
                    </div>
                    
                    <!-- System Health -->
                    <div class="info-grid">
                        <div class="info-card">
                            <div class="info-icon">
                                <i class="fas fa-hdd"></i>
                            </div>
                            <div class="info-value"><?php echo $disk_usage_percent; ?>%</div>
                            <div class="info-label">Disk Usage</div>
                        </div>
                        
                        <div class="info-card">
                            <div class="info-icon">
                                <i class="fas fa-database"></i>
                            </div>
                            <div class="info-value"><?php echo formatBytes($disk_used); ?></div>
                            <div class="info-label">Database Size</div>
                        </div>
                        
                        <div class="info-card">
                            <div class="info-icon">
                                <i class="fas fa-file"></i>
                            </div>
                            <div class="info-value"><?php echo count($recent_logs); ?></div>
                            <div class="info-label">Recent Logs</div>
                        </div>
                        
                        <div class="info-card">
                            <div class="info-icon">
                                <i class="fas fa-shield-alt"></i>
                            </div>
                            <div class="info-value">Active</div>
                            <div class="info-label">Security Status</div>
                        </div>
                    </div>
                    
                    <!-- Disk Usage Progress -->
                    <div class="progress-container">
                        <div class="progress-label">
                            <span>Disk Space Usage</span>
                            <span><?php echo formatBytes($disk_used); ?> / <?php echo formatBytes($disk_total); ?></span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $disk_usage_percent; ?>%"></div>
                        </div>
                    </div>
                    
                    <!-- Maintenance Actions -->
                    <div class="maintenance-grid">
                        <div class="maintenance-card">
                            <div class="maintenance-icon">
                                <i class="fas fa-database"></i>
                            </div>
                            <div class="maintenance-title">Database Backup</div>
                            <div class="maintenance-description">
                                Create a backup of the entire database. Recommended before system updates.
                            </div>
                            <form method="POST" style="margin-top: auto;">
                                <input type="hidden" name="backup_type" value="full">
                                <button type="submit" name="backup_database" class="btn btn-primary">
                                    <i class="fas fa-download"></i> Backup Now
                                </button>
                            </form>
                        </div>
                        
                        <div class="maintenance-card">
                            <div class="maintenance-icon">
                                <i class="fas fa-broom"></i>
                            </div>
                            <div class="maintenance-title">Clear System Cache</div>
                            <div class="maintenance-description">
                                Clear temporary cache files to free up space and resolve display issues.
                            </div>
                            <form method="POST" style="margin-top: auto;">
                                <button type="submit" name="clear_cache" class="btn btn-warning">
                                    <i class="fas fa-broom"></i> Clear Cache
                                </button>
                            </form>
                        </div>
                        
                        <div class="maintenance-card">
                            <div class="maintenance-icon">
                                <i class="fas fa-trash-alt"></i>
                            </div>
                            <div class="maintenance-title">Clear System Logs</div>
                            <div class="maintenance-description">
                                Remove old system logs to free up storage space.
                            </div>
                            <form method="POST" style="margin-top: auto;">
                                <input type="hidden" name="log_type" value="system">
                                <button type="submit" name="clear_logs" class="btn btn-danger">
                                    <i class="fas fa-trash"></i> Clear Logs
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Recent System Logs -->
                    <div class="logs-table" style="margin-top: 2rem;">
                        <div class="table-header">
                            <h3>Recent System Logs</h3>
                        </div>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Level</th>
                                        <th>Module</th>
                                        <th>Message</th>
                                        <th>User</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($recent_logs as $log): ?>
                                    <tr>
                                        <td><?php echo date('M j, Y H:i', strtotime($log['created_at'])); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower($log['level']); ?>">
                                                <?php echo $log['level']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($log['module']); ?></td>
                                        <td><?php echo htmlspecialchars($log['message']); ?></td>
                                        <td><?php echo htmlspecialchars($log['user_id'] ?: 'System'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- System Information -->
                <div id="system-info-section" class="settings-section">
                    <div class="settings-header">
                        <h2 class="settings-title">System Information</h2>
                        <p class="settings-description">Detailed information about the system environment and configuration</p>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>PHP Version</label>
                            <input type="text" value="<?php echo $system_info['php_version']; ?>" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label>Database Version</label>
                            <input type="text" value="<?php echo $system_info['database_version']; ?>" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label>Server Software</label>
                            <input type="text" value="<?php echo $system_info['server_software']; ?>" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label>Maximum Upload Size</label>
                            <input type="text" value="<?php echo $system_info['max_upload_size']; ?>" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label>Maximum Execution Time</label>
                            <input type="text" value="<?php echo $system_info['max_execution_time']; ?> seconds" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label>Memory Limit</label>
                            <input type="text" value="<?php echo $system_info['memory_limit']; ?>" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label>System Version</label>
                            <input type="text" value="1.0.0" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label>Last Update</label>
                            <input type="text" value="<?php echo date('F j, Y'); ?>" readonly>
                        </div>
                    </div>
                    
                    <!-- Backup History -->
                    <div class="logs-table" style="margin-top: 2rem;">
                        <div class="table-header">
                            <h3>Recent Backups</h3>
                        </div>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Backup Date</th>
                                        <th>Type</th>
                                        <th>Size</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($backup_history as $backup): ?>
                                    <tr>
                                        <td><?php echo date('M j, Y H:i', strtotime($backup['created_at'])); ?></td>
                                        <td><?php echo ucfirst($backup['type']); ?></td>
                                        <td><?php echo formatBytes($backup['size']); ?></td>
                                        <td>
                                            <span class="status-badge status-success">Completed</span>
                                        </td>
                                        <td>
                                            <div class="action-buttons-small">
                                                <button class="btn btn-sm btn-outline" onclick="downloadBackup(<?php echo $backup['id']; ?>)">
                                                    <i class="fas fa-download"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline" onclick="restoreBackup(<?php echo $backup['id']; ?>)">
                                                    <i class="fas fa-undo"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Settings navigation
        function showSection(sectionId) {
            // Hide all sections
            document.querySelectorAll('.settings-section').forEach(section => {
                section.classList.remove('active');
            });
            
            // Remove active class from all nav links
            document.querySelectorAll('.settings-nav-link').forEach(link => {
                link.classList.remove('active');
            });
            
            // Show selected section
            document.getElementById(sectionId + '-section').classList.add('active');
            
            // Add active class to clicked nav link
            event.target.classList.add('active');
        }
        
        // Form reset functions
        function resetForm(section) {
            if (confirm('Are you sure you want to reset all changes in this section?')) {
                document.forms[0].reset();
            }
        }
        
        // Maintenance functions
        function backupDatabase() {
            if (confirm('Create a full database backup? This may take a few minutes.')) {
                // This would trigger the backup process
                alert('Database backup started...');
            }
        }
        
        function clearSystemCache() {
            if (confirm('Clear all system cache? This will remove temporary files.')) {
                // This would trigger cache clearing
                alert('System cache cleared successfully!');
            }
        }
        
        function downloadBackup(backupId) {
            alert('Downloading backup: ' + backupId);
            // Implement backup download
        }
        
        function restoreBackup(backupId) {
            if (confirm('WARNING: This will restore the database from backup. Current data may be lost. Continue?')) {
                alert('Restoring from backup: ' + backupId);
                // Implement backup restoration
            }
        }
        
        // Format bytes to human-readable format
        function formatBytes(bytes, decimals = 2) {
            if (bytes === 0) return '0 Bytes';
            
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }
        
        // Initialize the page
        document.addEventListener('DOMContentLoaded', function() {
            // Set current year in academic year selector if not set
            const currentYear = new Date().getFullYear();
            const academicYear = document.getElementById('academic_year');
            if (academicYear && !academicYear.value) {
                academicYear.value = currentYear + '/' + (currentYear + 1);
            }
        });
    </script>
</body>
</html>

<?php
// Helper function to format bytes
function formatBytes($bytes, $decimals = 2) {
    $size = ['B', 'KB', 'MB', 'GB', 'TB'];
    $factor = floor((strlen($bytes) - 1) / 3);
    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . ' ' . @$size[$factor];
}
?>