<?php
// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', '../logs/system_errors.log');

include '../config.php';
checkAuth();
checkRole(['admin']);

// Helper: ensure settings table exists with all required fields
function ensureSettingsTable($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `skey` VARCHAR(191) NOT NULL UNIQUE,
        `svalue` TEXT NOT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

function getSetting($pdo, $key, $default = '') {
    try {
        ensureSettingsTable($pdo);
        $stmt = $pdo->prepare("SELECT svalue FROM settings WHERE skey = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['svalue'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function saveSetting($pdo, $key, $value) {
    ensureSettingsTable($pdo);
    try {
        $stmt = $pdo->prepare("INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = CURRENT_TIMESTAMP");
        return $stmt->execute([$key, $value]);
    } catch (Exception $e) {
        error_log("Error saving setting '$key': " . $e->getMessage());
        return false;
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=" . str_repeat("=", 80));
    error_log("POST REQUEST RECEIVED AT: " . date('Y-m-d H:i:s'));
    error_log("POST data keys: " . (empty($_POST) ? 'NONE' : implode(', ', array_keys($_POST))));
    error_log("=" . str_repeat("=", 80));
    
    // Check for end_maintenance first (separate action)
    if (isset($_POST['end_maintenance'])) {
        error_log("[END_MAINTENANCE] Processing end maintenance request");
        
        // Turn off maintenance mode
        saveSetting($pdo, 'maintenance_mode', 'off');
        
        // Send notification if checkbox was checked
        if (isset($_POST['notify_users']) && $_POST['notify_users'] == '1') {
            sendSystemBackOnlineNotification($pdo);
            $message = 'Maintenance ended and users notified';
        } else {
            $message = 'Maintenance ended';
        }
        
        // Clear output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        header('Location: system_settings.php?success=' . urlencode($message));
        exit();
    }
    
    // Save general settings (including maintenance mode)
    if (isset($_POST['save_settings'])) {
        error_log("[SAVE_SETTINGS] Starting to process save_settings action");
        
        // General settings
        $general_keys = [
            'school_name', 'school_acronym', 'school_address', 'school_phone', 'school_email',
            'school_website', 'school_motto', 'school_principal', 'academic_year',
            'currency', 'timezone', 'date_format', 'items_per_page'
        ];
        foreach ($general_keys as $k) {
            $val = $_POST[$k] ?? '';
            saveSetting($pdo, $k, $val);
        }
        
        // Sync academic year to academic_years table
        if (!empty($_POST['academic_year'])) {
            $academic_year_input = $_POST['academic_year'];
            // Extract year from formats like "2025-2026" or "2025"
            $year_value = preg_match('/^(\d{4})/', $academic_year_input, $matches) ? $matches[1] : $academic_year_input;
            
            // Check if this year exists in academic_years table
            $check_stmt = $pdo->prepare("SELECT id FROM academic_years WHERE year = ?");
            $check_stmt->execute([$year_value]);
            
            if (!$check_stmt->fetch()) {
                // Year doesn't exist, insert it as active
                $insert_stmt = $pdo->prepare("INSERT INTO academic_years (year, is_active) VALUES (?, 1)");
                $insert_stmt->execute([$year_value]);
            }
        }
        
        // Email settings
        $email_keys = ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_encryption', 'from_email', 'from_name'];
        foreach ($email_keys as $k) {
            $val = $_POST[$k] ?? '';
            saveSetting($pdo, $k, $val);
        }
        
        // M-Pesa Daraja API settings
        $mpesa_keys = [
            'mpesa_env', 'mpesa_consumer_key', 'mpesa_consumer_secret', 'mpesa_passkey',
            'mpesa_shortcode', 'mpesa_initiator_name', 'mpesa_security_credential',
            'mpesa_callback_url', 'mpesa_account_reference', 'mpesa_transaction_desc'
        ];
        foreach ($mpesa_keys as $k) {
            $val = $_POST[$k] ?? '';
            saveSetting($pdo, $k, $val);
        }
        
        // SMS settings
        $sms_keys = ['sms_enabled', 'sms_provider', 'sms_api_key', 'sms_api_secret', 'sms_sender_id', 'sms_balance_url'];
        foreach ($sms_keys as $k) {
            $val = $_POST[$k] ?? '';
            saveSetting($pdo, $k, $val);
        }
        
        // System features
        $feature_keys = ['fee_reminders', 'auto_backup', 'login_attempts', 'session_timeout'];
        foreach ($feature_keys as $k) {
            $val = $_POST[$k] ?? '';
            saveSetting($pdo, $k, $val);
        }
        
        // Maintenance settings (save all maintenance-related settings)
        $maintenance_keys = [
            'maintenance_mode', 'maintenance_message', 'maintenance_type', 
            'maintenance_start_time', 'maintenance_end_time', 'notify_on_maintenance'
        ];
        foreach ($maintenance_keys as $k) {
            $val = $_POST[$k] ?? '';
            saveSetting($pdo, $k, $val);
        }
        
        // Handle school logo upload
        if (isset($_FILES['school_logo']) && $_FILES['school_logo']['error'] == UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/logos/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml'];
            $file_type = $_FILES['school_logo']['type'];
            
            if (in_array($file_type, $allowed_types)) {
                $file_ext = pathinfo($_FILES['school_logo']['name'], PATHINFO_EXTENSION);
                $new_filename = 'school_logo_' . time() . '.' . $file_ext;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['school_logo']['tmp_name'], $upload_path)) {
                    saveSetting($pdo, 'school_logo', $new_filename);
                    updateConfigFile('school_logo', $new_filename);
                }
            }
        }
        
        // Update config.php with school name
        if (!empty($_POST['school_name'])) {
            updateConfigFile('school_name', $_POST['school_name']);
        }
        
        // Send maintenance notifications if enabled and turning maintenance ON
        $old_maintenance = getSetting($pdo, 'maintenance_mode', 'off');
        $new_maintenance = $_POST['maintenance_mode'] ?? 'off';
        
        if ($old_maintenance != 'on' && $new_maintenance == 'on' && isset($_POST['notify_on_maintenance']) && $_POST['notify_on_maintenance'] == '1') {
            sendMaintenanceNotification($pdo, $_POST['maintenance_message'] ?? 'System is under maintenance');
        }
        
        // Clear output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        header('Location: system_settings.php?success=' . urlencode('Settings saved successfully'));
        exit();
    }

    // Download backup
    if (isset($_POST['download_backup'])) {
        $tables = ['students','users','fees','transactions','books','book_issues','messages','notifications','classes','settings','book_categories','book_locations'];
        $sqlDump = "-- Database Backup Generated on " . date('Y-m-d H:i:s') . "\n";
        $sqlDump .= "-- MariaGO School Management System\n\n";
        
        foreach ($tables as $t) {
            try {
                $rows = $pdo->query("SELECT * FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC);
                if (empty($rows)) continue;
                
                $sqlDump .= "-- Table: {$t}\n";
                foreach ($rows as $r) {
                    $cols = array_map(function($c){ return "`".str_replace('`','``',$c)."`"; }, array_keys($r));
                    $vals = array_map(function($v) use ($pdo) { 
                        if (is_null($v)) return 'NULL'; 
                        return $pdo->quote($v);
                    }, array_values($r));
                    $sqlDump .= "INSERT INTO `{$t}` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n";
                }
                $sqlDump .= "\n";
            } catch (Exception $e) {
                // skip table if missing
            }
        }
        
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="school_backup_' . date('Y-m-d_His') . '.sql"');
        echo $sqlDump;
        exit();
    }

    // Reset system
    if (isset($_POST['reset_system'])) {
        $confirm = $_POST['confirm_phrase'] ?? '';
        $keep_admin = isset($_POST['keep_admin']) && $_POST['keep_admin'] === '1';
        $reset_type = $_POST['reset_type'] ?? 'all';
        
        if ($confirm !== 'DELETE ALL DATA') {
            header('Location: system_settings.php?error=' . urlencode('Confirmation phrase did not match.')); 
            exit();
        }

        try {
            $pdo->beginTransaction();
            
            if ($reset_type === 'all' || $reset_type === 'students') {
                $pdo->exec("DELETE FROM fees");
                $pdo->exec("DELETE FROM transactions");
                $pdo->exec("DELETE FROM students");
                $pdo->exec("ALTER TABLE students AUTO_INCREMENT = 1");
                $pdo->exec("ALTER TABLE fees AUTO_INCREMENT = 1");
                $pdo->exec("ALTER TABLE transactions AUTO_INCREMENT = 1");
            }
            
            if ($reset_type === 'all' || $reset_type === 'library') {
                $pdo->exec("DELETE FROM book_issues");
                $pdo->exec("DELETE FROM books");
                $pdo->exec("ALTER TABLE books AUTO_INCREMENT = 1");
                $pdo->exec("ALTER TABLE book_issues AUTO_INCREMENT = 1");
            }
            
            if ($reset_type === 'all' || $reset_type === 'users') {
                if (!$keep_admin) {
                    $current = (int)$_SESSION['user_id'];
                    $pdo->exec("DELETE FROM users WHERE id != {$current}");
                    $pdo->exec("ALTER TABLE users AUTO_INCREMENT = 2");
                }
            }
            
            if ($reset_type === 'all') {
                $pdo->exec("DELETE FROM messages");
                $pdo->exec("DELETE FROM notifications");
                $pdo->exec("ALTER TABLE messages AUTO_INCREMENT = 1");
                $pdo->exec("ALTER TABLE notifications AUTO_INCREMENT = 1");
            }
            
            $pdo->commit();
            header('Location: system_settings.php?success=' . urlencode('System reset completed.')); 
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            header('Location: system_settings.php?error=' . urlencode('Reset failed: ' . $e->getMessage())); 
            exit();
        }
    }
    
    // Test M-Pesa Connection
    if (isset($_POST['test_mpesa'])) {
        $consumer_key = getSetting($pdo, 'mpesa_consumer_key', '');
        $consumer_secret = getSetting($pdo, 'mpesa_consumer_secret', '');
        $env = getSetting($pdo, 'mpesa_env', 'sandbox');
        
        if (empty($consumer_key) || empty($consumer_secret)) {
            header('Location: system_settings.php?error=' . urlencode('M-Pesa credentials not configured')); 
            exit();
        }
        
        $url = ($env == 'production') 
            ? 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials'
            : 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        
        $credentials = base64_encode($consumer_key . ':' . $consumer_secret);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . $credentials]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        if (isset($result['access_token'])) {
            header('Location: system_settings.php?success=' . urlencode('M-Pesa API connection successful!')); 
        } else {
            header('Location: system_settings.php?error=' . urlencode('M-Pesa API connection failed: ' . ($result['errorMessage'] ?? 'Unknown error'))); 
        }
        exit();
    }
    
    // Test SMTP Connection
    if (isset($_POST['test_smtp'])) {
        $smtp_host = getSetting($pdo, 'smtp_host', '');
        $smtp_port = getSetting($pdo, 'smtp_port', '');
        $smtp_user = getSetting($pdo, 'smtp_user', '');
        $smtp_pass = getSetting($pdo, 'smtp_pass', '');
        
        if (empty($smtp_host) || empty($smtp_port) || empty($smtp_user)) {
            header('Location: system_settings.php?error=' . urlencode('SMTP settings not configured')); 
            exit();
        }
        
        try {
            require_once '../vendor/phpmailer/phpmailer/src/PHPMailer.php';
            require_once '../vendor/phpmailer/phpmailer/src/SMTP.php';
            require_once '../vendor/phpmailer/phpmailer/src/Exception.php';
            
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $smtp_host;
            $mail->Port = $smtp_port;
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_user;
            $mail->Password = $smtp_pass;
            $mail->SMTPSecure = getSetting($pdo, 'smtp_encryption', 'tls');
            
            $mail->setFrom($smtp_user, getSetting($pdo, 'from_name', 'School System'));
            $mail->addAddress($smtp_user);
            $mail->Subject = 'SMTP Test from School System';
            $mail->Body = 'This is a test email to verify SMTP settings.';
            
            if ($mail->send()) {
                header('Location: system_settings.php?success=' . urlencode('SMTP test email sent successfully!')); 
            } else {
                header('Location: system_settings.php?error=' . urlencode('SMTP test failed: ' . $mail->ErrorInfo)); 
            }
        } catch (Exception $e) {
            header('Location: system_settings.php?error=' . urlencode('SMTP test failed: ' . $e->getMessage())); 
        }
        exit();
    }
}

// Helper function to resize images
function resizeImage($file, $max_width, $max_height) {
    if (!function_exists('imagecreatetruecolor')) {
        error_log("GD library not available. Image resizing skipped.");
        return false;
    }
    
    try {
        list($orig_width, $orig_height, $type) = getimagesize($file);
        
        $ratio = $orig_width / $orig_height;
        
        if ($max_width/$max_height > $ratio) {
            $width = $max_height * $ratio;
            $height = $max_height;
        } else {
            $width = $max_width;
            $height = $max_width / $ratio;
        }
        
        $image_p = imagecreatetruecolor($width, $height);
        
        switch($type) {
            case IMAGETYPE_JPEG:
                $image = imagecreatefromjpeg($file);
                imagecopyresampled($image_p, $image, 0, 0, 0, 0, $width, $height, $orig_width, $orig_height);
                imagejpeg($image_p, $file, 90);
                break;
            case IMAGETYPE_PNG:
                $image = imagecreatefrompng($file);
                imagealphablending($image_p, false);
                imagesavealpha($image_p, true);
                imagecopyresampled($image_p, $image, 0, 0, 0, 0, $width, $height, $orig_width, $orig_height);
                imagepng($image_p, $file, 9);
                break;
            default:
                return false;
        }
        
        imagedestroy($image_p);
        if (isset($image)) {
            imagedestroy($image);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Image resize error: " . $e->getMessage());
        return false;
    }
}

// Helper function to update config.php
function updateConfigFile($key, $value) {
    $config_file = '../config.php';
    if (!file_exists($config_file)) return;
    
    $content = file_get_contents($config_file);
    
    if ($key == 'school_name') {
        $pattern = "/define\('SCHOOL_NAME',\s*'[^']*'\);/";
        $replacement = "define('SCHOOL_NAME', '" . addslashes($value) . "');";
        $content = preg_replace($pattern, $replacement, $content);
    }
    
    if ($key == 'school_logo') {
        $pattern = "/define\('SCHOOL_LOGO',\s*'[^']*'\);/";
        $replacement = "define('SCHOOL_LOGO', '" . addslashes($value) . "');";
        $content = preg_replace($pattern, $replacement, $content);
    }
    
    file_put_contents($config_file, $content);
}

// Load current settings
$school_name = getSetting($pdo, 'school_name', SCHOOL_NAME);
$school_acronym = getSetting($pdo, 'school_acronym', '');
$school_address = getSetting($pdo, 'school_address', '');
$school_phone = getSetting($pdo, 'school_phone', '');
$school_email = getSetting($pdo, 'school_email', '');
$school_website = getSetting($pdo, 'school_website', '');
$school_motto = getSetting($pdo, 'school_motto', '');
$school_principal = getSetting($pdo, 'school_principal', '');
$school_logo = getSetting($pdo, 'school_logo', SCHOOL_LOGO);
$academic_year = getSetting($pdo, 'academic_year', date('Y'));
$currency = getSetting($pdo, 'currency', CURRENCY);
$timezone = getSetting($pdo, 'timezone', date_default_timezone_get());
$date_format = getSetting($pdo, 'date_format', 'Y-m-d');
$items_per_page = getSetting($pdo, 'items_per_page', '15');

// Email settings
$smtp_host = getSetting($pdo, 'smtp_host', '');
$smtp_port = getSetting($pdo, 'smtp_port', '587');
$smtp_user = getSetting($pdo, 'smtp_user', '');
$smtp_pass = getSetting($pdo, 'smtp_pass', '');
$smtp_encryption = getSetting($pdo, 'smtp_encryption', 'tls');
$from_email = getSetting($pdo, 'from_email', '');
$from_name = getSetting($pdo, 'from_name', $school_name);

// M-Pesa settings
$mpesa_env = getSetting($pdo, 'mpesa_env', 'sandbox');
$mpesa_consumer_key = getSetting($pdo, 'mpesa_consumer_key', '');
$mpesa_consumer_secret = getSetting($pdo, 'mpesa_consumer_secret', '');
$mpesa_passkey = getSetting($pdo, 'mpesa_passkey', '');
$mpesa_shortcode = getSetting($pdo, 'mpesa_shortcode', '');
$mpesa_initiator_name = getSetting($pdo, 'mpesa_initiator_name', '');
$mpesa_security_credential = getSetting($pdo, 'mpesa_security_credential', '');
$mpesa_callback_url = getSetting($pdo, 'mpesa_callback_url', '');
$mpesa_account_reference = getSetting($pdo, 'mpesa_account_reference', $school_acronym);
$mpesa_transaction_desc = getSetting($pdo, 'mpesa_transaction_desc', 'School Fees Payment');

// SMS settings
$sms_enabled = getSetting($pdo, 'sms_enabled', '0');
$sms_provider = getSetting($pdo, 'sms_provider', 'africastalking');
$sms_api_key = getSetting($pdo, 'sms_api_key', '');
$sms_api_secret = getSetting($pdo, 'sms_api_secret', '');
$sms_sender_id = getSetting($pdo, 'sms_sender_id', $school_acronym ?: 'SCHOOL');
$sms_balance_url = getSetting($pdo, 'sms_balance_url', '');

// System features
$fee_reminders = getSetting($pdo, 'fee_reminders', '1');
$auto_backup = getSetting($pdo, 'auto_backup', '0');
$login_attempts = getSetting($pdo, 'login_attempts', '5');
$session_timeout = getSetting($pdo, 'session_timeout', '30');
$maintenance_mode = getSetting($pdo, 'maintenance_mode', 'off'); // Changed default to 'off'
$maintenance_message = getSetting($pdo, 'maintenance_message', 'System is under maintenance. Please try again later.');
$maintenance_type = getSetting($pdo, 'maintenance_type', 'manual');
$maintenance_start_time = getSetting($pdo, 'maintenance_start_time', '');
$maintenance_end_time = getSetting($pdo, 'maintenance_end_time', '');
$notify_on_maintenance = getSetting($pdo, 'notify_on_maintenance', '0');

$page_title = 'System Settings - ' . $school_name;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <style>
        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            transition: margin-left 0.3s ease;
            background: #f8f9fa;
            min-height: calc(100vh - 70px);
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e9ecef;
        }
        
        .page-title h1 {
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        
        .page-title p {
            color: #6c757d;
            margin-bottom: 0;
        }
        
        /* Alert Messages */
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
        
        /* Tabs Navigation */
        .tabs {
            display: flex;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            flex-wrap: wrap;
        }
        
        .tab {
            padding: 1rem 1.5rem;
            cursor: pointer;
            font-weight: 600;
            color: #6c757d;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            white-space: nowrap;
        }
        
        .tab.active {
            color: #3498db;
            border-bottom-color: #3498db;
            background: #f8f9fa;
        }
        
        .tab-content {
            display: none;
            background: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 1.5rem;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Form Styles */
        .form-section {
            margin-bottom: 2rem;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e9ecef;
        }
        
        .section-header h3 {
            color: #2c3e50;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.9rem;
        }
        
        .required {
            color: #e74c3c;
            margin-left: 2px;
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        .form-text {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 0.25rem;
            line-height: 1.4;
        }
        
        /* Buttons */
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
        
        .btn-secondary {
            background: #6c757d;
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
        
        /* Logo Preview */
        .logo-preview {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .logo-image {
            width: 100px;
            height: 100px;
            border-radius: 8px;
            object-fit: contain;
            border: 2px dashed #dee2e6;
            padding: 0.5rem;
        }
        
        .logo-upload {
            flex: 1;
        }
        
        /* Maintenance Mode */
        .maintenance-toggle {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
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
        
        .slider {
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
        
        .slider:before {
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
        
        input:checked + .slider {
            background-color: #2196F3;
        }
        
        input:checked + .slider:before {
            transform: translateX(30px);
        }
        
        /* Test Connection Section */
        .test-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 1.5rem;
            border-left: 4px solid #3498db;
        }
        
        /* Danger Zone */
        .danger-zone {
            background: #fff5f5;
            border: 2px solid #feb2b2;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 2rem;
        }
        
        .danger-header {
            color: #c53030;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        /* End Maintenance Form */
        .end-maintenance-form {
            display: inline-block;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab {
                border-bottom: 1px solid #e9ecef;
                border-left: 3px solid transparent;
            }
            
            .tab.active {
                border-left-color: #3498db;
                border-bottom-color: #e9ecef;
            }
        }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container">
            <div class="page-header">
                <div class="page-title">
                    <h1>System Settings</h1>
                    <p>Configure your school management system</p>
                </div>
                <div class="action-buttons">
                    <button type="submit" form="settingsForm" name="save_settings" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save All Settings
                    </button>
                    
                    <?php if ($maintenance_mode == 'on'): ?>
                    <!-- Separate form for End Maintenance -->
                    <form method="POST" action="system_settings.php" class="end-maintenance-form" id="endMaintenanceForm">
                        <input type="hidden" name="end_maintenance" value="1">
                        <button type="button" class="btn btn-success" onclick="confirmEndMaintenance()">
                            <i class="fas fa-power-off"></i> End Maintenance
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <strong>Success!</strong> <?php echo htmlspecialchars($_GET['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <strong>Error!</strong> <?php echo htmlspecialchars($_GET['error']); ?>
                </div>
            <?php endif; ?>
            
            <!-- Tabs Navigation -->
            <div class="tabs">
                <div class="tab active" onclick="openTab('generalTab')">
                    <i class="fas fa-school"></i> General
                </div>
                <div class="tab" onclick="openTab('emailTab')">
                    <i class="fas fa-envelope"></i> Email/SMTP
                </div>
                <div class="tab" onclick="openTab('mpesaTab')">
                    <i class="fas fa-mobile-alt"></i> M-Pesa Daraja
                </div>
                <div class="tab" onclick="openTab('smsTab')">
                    <i class="fas fa-sms"></i> SMS Settings
                </div>
                <div class="tab" onclick="openTab('featuresTab')">
                    <i class="fas fa-cogs"></i> System Features
                </div>
                <div class="tab" onclick="openTab('backupTab')">
                    <i class="fas fa-database"></i> Backup & Reset
                </div>
            </div>
            
            <form method="POST" action="system_settings.php" enctype="multipart/form-data" id="settingsForm">
                <!-- General Settings Tab -->
                <div id="generalTab" class="tab-content active">
                    <div class="form-section">
                        <div class="section-header">
                            <h3><i class="fas fa-school"></i> School Information</h3>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="school_name">
                                    School Name <span class="required">*</span>
                                </label>
                                <input type="text" class="form-control" id="school_name" name="school_name" 
                                       value="<?php echo htmlspecialchars($school_name); ?>" required>
                                <div class="form-text">This name will be displayed throughout the system</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="school_acronym">School Acronym</label>
                                <input type="text" class="form-control" id="school_acronym" name="school_acronym" 
                                       value="<?php echo htmlspecialchars($school_acronym); ?>" 
                                       placeholder="e.g., HGS, KHS">
                                <div class="form-text">Short abbreviation of school name</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="school_logo">School Logo</label>
                                <div class="logo-preview">
                                    <img src="../uploads/logos/<?php echo htmlspecialchars($school_logo); ?>" 
                                         alt="School Logo" 
                                         class="logo-image"
                                         onerror="this.src='../logo.png'">
                                    <div class="logo-upload">
                                        <input type="file" class="form-control" id="school_logo" name="school_logo" 
                                               accept="image/*">
                                        <div class="form-text">Upload school logo (PNG, JPG, SVG, max 300×300px)</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="school_address">School Address</label>
                                <textarea class="form-control" id="school_address" name="school_address" rows="3"><?php echo htmlspecialchars($school_address); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="school_phone">School Phone</label>
                                <input type="tel" class="form-control" id="school_phone" name="school_phone" 
                                       value="<?php echo htmlspecialchars($school_phone); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="school_email">School Email</label>
                                <input type="email" class="form-control" id="school_email" name="school_email" 
                                       value="<?php echo htmlspecialchars($school_email); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="school_website">School Website</label>
                                <input type="url" class="form-control" id="school_website" name="school_website" 
                                       value="<?php echo htmlspecialchars($school_website); ?>" 
                                       placeholder="https://">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="school_motto">School Motto</label>
                                <input type="text" class="form-control" id="school_motto" name="school_motto" 
                                       value="<?php echo htmlspecialchars($school_motto); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="school_principal">Principal's Name</label>
                                <input type="text" class="form-control" id="school_principal" name="school_principal" 
                                       value="<?php echo htmlspecialchars($school_principal); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="section-header">
                            <h3><i class="fas fa-cog"></i> System Configuration</h3>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="academic_year">Academic Year</label>
                                <input type="text" class="form-control" id="academic_year" name="academic_year" 
                                       value="<?php echo htmlspecialchars($academic_year); ?>" 
                                       placeholder="e.g., 2023-2024">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="currency">Currency</label>
                                <input type="text" class="form-control" id="currency" name="currency" 
                                       value="<?php echo htmlspecialchars($currency); ?>" 
                                       placeholder="e.g., KES, USD, EUR">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="timezone">Timezone</label>
                                <select class="form-control" id="timezone" name="timezone">
                                    <?php
                                    $timezones = DateTimeZone::listIdentifiers();
                                    foreach ($timezones as $tz) {
                                        $selected = $tz == $timezone ? 'selected' : '';
                                        echo "<option value=\"" . htmlspecialchars($tz) . "\" $selected>" . htmlspecialchars($tz) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="date_format">Date Format</label>
                                <select class="form-control" id="date_format" name="date_format">
                                    <option value="Y-m-d" <?php echo $date_format == 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                                    <option value="d/m/Y" <?php echo $date_format == 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY</option>
                                    <option value="m/d/Y" <?php echo $date_format == 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY</option>
                                    <option value="d-M-Y" <?php echo $date_format == 'd-M-Y' ? 'selected' : ''; ?>>DD-MMM-YYYY</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="items_per_page">Items Per Page</label>
                                <input type="number" class="form-control" id="items_per_page" name="items_per_page" 
                                       value="<?php echo htmlspecialchars($items_per_page); ?>" min="5" max="100">
                                <div class="form-text">Number of items to display in tables</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Email/SMTP Tab -->
                <div id="emailTab" class="tab-content">
                    <div class="form-section">
                        <div class="section-header">
                            <h3><i class="fas fa-envelope"></i> SMTP Email Settings</h3>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="smtp_host">SMTP Host</label>
                                <input type="text" class="form-control" id="smtp_host" name="smtp_host" 
                                       value="<?php echo htmlspecialchars($smtp_host); ?>" 
                                       placeholder="e.g., smtp.gmail.com">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="smtp_port">SMTP Port</label>
                                <input type="number" class="form-control" id="smtp_port" name="smtp_port" 
                                       value="<?php echo htmlspecialchars($smtp_port); ?>" 
                                       placeholder="e.g., 587">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="smtp_encryption">Encryption</label>
                                <select class="form-control" id="smtp_encryption" name="smtp_encryption">
                                    <option value="tls" <?php echo $smtp_encryption == 'tls' ? 'selected' : ''; ?>>TLS</option>
                                    <option value="ssl" <?php echo $smtp_encryption == 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                    <option value="" <?php echo empty($smtp_encryption) ? 'selected' : ''; ?>>None</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="smtp_user">SMTP Username</label>
                                <input type="text" class="form-control" id="smtp_user" name="smtp_user" 
                                       value="<?php echo htmlspecialchars($smtp_user); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="smtp_pass">SMTP Password</label>
                                <input type="password" class="form-control" id="smtp_pass" name="smtp_pass" 
                                       value="<?php echo htmlspecialchars($smtp_pass); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="from_email">From Email</label>
                                <input type="email" class="form-control" id="from_email" name="from_email" 
                                       value="<?php echo htmlspecialchars($from_email); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="from_name">From Name</label>
                                <input type="text" class="form-control" id="from_name" name="from_name" 
                                       value="<?php echo htmlspecialchars($from_name); ?>">
                            </div>
                        </div>
                        
                        <div class="test-section">
                            <h4><i class="fas fa-vial"></i> Test SMTP Connection</h4>
                            <p class="form-text">Test your SMTP settings by sending a test email</p>
                            <button type="submit" name="test_smtp" class="btn btn-success">
                                <i class="fas fa-paper-plane"></i> Send Test Email
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- M-Pesa Daraja Tab -->
                <div id="mpesaTab" class="tab-content">
                    <div class="form-section">
                        <div class="section-header">
                            <h3><i class="fas fa-mobile-alt"></i> M-Pesa Daraja API Settings</h3>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="mpesa_env">Environment</label>
                                <select class="form-control" id="mpesa_env" name="mpesa_env">
                                    <option value="sandbox" <?php echo $mpesa_env == 'sandbox' ? 'selected' : ''; ?>>Sandbox (Testing)</option>
                                    <option value="production" <?php echo $mpesa_env == 'production' ? 'selected' : ''; ?>>Production (Live)</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="mpesa_consumer_key">Consumer Key</label>
                                <input type="text" class="form-control" id="mpesa_consumer_key" name="mpesa_consumer_key" 
                                       value="<?php echo htmlspecialchars($mpesa_consumer_key); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="mpesa_consumer_secret">Consumer Secret</label>
                                <input type="password" class="form-control" id="mpesa_consumer_secret" name="mpesa_consumer_secret" 
                                       value="<?php echo htmlspecialchars($mpesa_consumer_secret); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="mpesa_passkey">Passkey</label>
                                <input type="password" class="form-control" id="mpesa_passkey" name="mpesa_passkey" 
                                       value="<?php echo htmlspecialchars($mpesa_passkey); ?>">
                                <div class="form-text">Daraja API passkey from Safaricom</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="mpesa_shortcode">Shortcode</label>
                                <input type="text" class="form-control" id="mpesa_shortcode" name="mpesa_shortcode" 
                                       value="<?php echo htmlspecialchars($mpesa_shortcode); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="mpesa_initiator_name">Initiator Name</label>
                                <input type="text" class="form-control" id="mpesa_initiator_name" name="mpesa_initiator_name" 
                                       value="<?php echo htmlspecialchars($mpesa_initiator_name); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="mpesa_security_credential">Security Credential</label>
                                <input type="password" class="form-control" id="mpesa_security_credential" name="mpesa_security_credential" 
                                       value="<?php echo htmlspecialchars($mpesa_security_credential); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="mpesa_callback_url">Callback URL</label>
                                <input type="url" class="form-control" id="mpesa_callback_url" name="mpesa_callback_url" 
                                       value="<?php echo htmlspecialchars($mpesa_callback_url); ?>">
                                <div class="form-text">URL to receive payment notifications</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="mpesa_account_reference">Account Reference</label>
                                <input type="text" class="form-control" id="mpesa_account_reference" name="mpesa_account_reference" 
                                       value="<?php echo htmlspecialchars($mpesa_account_reference); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="mpesa_transaction_desc">Transaction Description</label>
                                <input type="text" class="form-control" id="mpesa_transaction_desc" name="mpesa_transaction_desc" 
                                       value="<?php echo htmlspecialchars($mpesa_transaction_desc); ?>">
                            </div>
                        </div>
                        
                        <div class="test-section">
                            <h4><i class="fas fa-vial"></i> Test M-Pesa Connection</h4>
                            <p class="form-text">Test your M-Pesa API connection</p>
                            <button type="submit" name="test_mpesa" class="btn btn-success">
                                <i class="fas fa-plug"></i> Test Connection
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- SMS Settings Tab -->
                <div id="smsTab" class="tab-content">
                    <div class="form-section">
                        <div class="section-header">
                            <h3><i class="fas fa-sms"></i> SMS Gateway Settings</h3>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="sms_enabled">SMS Enabled</label>
                                <select class="form-control" id="sms_enabled" name="sms_enabled">
                                    <option value="1" <?php echo $sms_enabled == '1' ? 'selected' : ''; ?>>Yes</option>
                                    <option value="0" <?php echo $sms_enabled == '0' ? 'selected' : ''; ?>>No</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="sms_provider">SMS Provider</label>
                                <select class="form-control" id="sms_provider" name="sms_provider">
                                    <option value="africastalking" <?php echo $sms_provider == 'africastalking' ? 'selected' : ''; ?>>Africa's Talking</option>
                                    <option value="bulksms" <?php echo $sms_provider == 'bulksms' ? 'selected' : ''; ?>>BulkSMS</option>
                                    <option value="twilio" <?php echo $sms_provider == 'twilio' ? 'selected' : ''; ?>>Twilio</option>
                                    <option value="custom" <?php echo $sms_provider == 'custom' ? 'selected' : ''; ?>>Custom API</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="sms_api_key">API Key</label>
                                <input type="text" class="form-control" id="sms_api_key" name="sms_api_key" 
                                       value="<?php echo htmlspecialchars($sms_api_key); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="sms_api_secret">API Secret</label>
                                <input type="password" class="form-control" id="sms_api_secret" name="sms_api_secret" 
                                       value="<?php echo htmlspecialchars($sms_api_secret); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="sms_sender_id">Sender ID</label>
                                <input type="text" class="form-control" id="sms_sender_id" name="sms_sender_id" 
                                       value="<?php echo htmlspecialchars($sms_sender_id); ?>">
                                <div class="form-text">Name that appears as SMS sender (max 11 chars)</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="sms_balance_url">Balance Check URL</label>
                                <input type="url" class="form-control" id="sms_balance_url" name="sms_balance_url" 
                                       value="<?php echo htmlspecialchars($sms_balance_url); ?>">
                                <div class="form-text">URL to check SMS credits balance</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- System Features Tab -->
                <div id="featuresTab" class="tab-content">
                    <div class="form-section">
                        <div class="section-header">
                            <h3><i class="fas fa-cogs"></i> System Features</h3>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="fee_reminders">Fee Reminders</label>
                                <select class="form-control" id="fee_reminders" name="fee_reminders">
                                    <option value="1" <?php echo $fee_reminders == '1' ? 'selected' : ''; ?>>Enabled</option>
                                    <option value="0" <?php echo $fee_reminders == '0' ? 'selected' : ''; ?>>Disabled</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="auto_backup">Auto Backup</label>
                                <select class="form-control" id="auto_backup" name="auto_backup">
                                    <option value="0" <?php echo $auto_backup == '0' ? 'selected' : ''; ?>>Disabled</option>
                                    <option value="daily" <?php echo $auto_backup == 'daily' ? 'selected' : ''; ?>>Daily</option>
                                    <option value="weekly" <?php echo $auto_backup == 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                    <option value="monthly" <?php echo $auto_backup == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="login_attempts">Max Login Attempts</label>
                                <input type="number" class="form-control" id="login_attempts" name="login_attempts" 
                                       value="<?php echo htmlspecialchars($login_attempts); ?>" min="1" max="10">
                                <div class="form-text">Number of failed login attempts before lockout</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="session_timeout">Session Timeout (minutes)</label>
                                <input type="number" class="form-control" id="session_timeout" name="session_timeout" 
                                       value="<?php echo htmlspecialchars($session_timeout); ?>" min="5" max="1440">
                            </div>
                            
                            <div class="form-group full-width">
                                <div class="maintenance-toggle">
                                    <label class="toggle-switch">
                                        <input type="hidden" name="maintenance_mode" value="off">
                                        <input type="checkbox" id="maintenance_mode" name="maintenance_mode" value="on" 
                                               <?php echo $maintenance_mode == 'on' ? 'checked' : ''; ?> 
                                               onchange="toggleMaintenanceOptions()">
                                        <span class="slider"></span>
                                    </label>
                                    <label for="maintenance_mode" style="margin: 0; cursor: pointer; font-weight: 600;">
                                        Enable Maintenance Mode
                                    </label>
                                </div>
                                <div class="form-text">
                                    <strong>Important:</strong> When enabled, only administrators can access the system. 
                                    All other users will be redirected to a maintenance page. Make sure to test this feature.
                                </div>
                            </div>
                            
                            <div class="form-group full-width" id="maintenanceTypeContainer" 
                                 style="<?php echo $maintenance_mode == 'on' ? '' : 'display: none;'; ?>">
                                <label class="form-label" for="maintenance_type">Maintenance Type</label>
                                <select class="form-control" id="maintenance_type" name="maintenance_type" onchange="toggleMaintenanceSchedule()">
                                    <option value="manual" <?php echo $maintenance_type == 'manual' ? 'selected' : ''; ?>>Manual (Emergency)</option>
                                    <option value="scheduled" <?php echo $maintenance_type == 'scheduled' ? 'selected' : ''; ?>>Scheduled Maintenance</option>
                                </select>
                            </div>
                            
                            <div class="form-group full-width" id="maintenanceScheduleContainer" 
                                 style="<?php echo $maintenance_mode == 'on' && $maintenance_type == 'scheduled' ? '' : 'display: none;'; ?>">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                                    <div>
                                        <label class="form-label" for="maintenance_start_time">Maintenance Start Time</label>
                                        <input type="datetime-local" class="form-control" id="maintenance_start_time" name="maintenance_start_time" 
                                               value="<?php echo htmlspecialchars($maintenance_start_time); ?>">
                                    </div>
                                    <div>
                                        <label class="form-label" for="maintenance_end_time">Estimated End Time</label>
                                        <input type="datetime-local" class="form-control" id="maintenance_end_time" name="maintenance_end_time" 
                                               value="<?php echo htmlspecialchars($maintenance_end_time); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group full-width" id="maintenanceNotifyContainer" 
                                 style="<?php echo $maintenance_mode == 'on' ? '' : 'display: none;'; ?>">
                                <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                                    <label class="toggle-switch">
                                        <input type="hidden" name="notify_on_maintenance" value="0">
                                        <input type="checkbox" id="notify_on_maintenance" name="notify_on_maintenance" value="1" 
                                               <?php echo $notify_on_maintenance == '1' ? 'checked' : ''; ?>>
                                        <span class="slider"></span>
                                    </label>
                                    <label for="notify_on_maintenance" style="margin: 0; cursor: pointer; font-weight: 600;">
                                        Notify Users when Maintenance Starts
                                    </label>
                                </div>
                            </div>
                            
                            <div class="form-group full-width" id="maintenanceMessageContainer" 
                                 style="<?php echo $maintenance_mode == 'on' ? '' : 'display: none;'; ?>">
                                <label class="form-label" for="maintenance_message">Maintenance Message</label>
                                <textarea class="form-control" id="maintenance_message" name="maintenance_message" 
                                          rows="4"><?php echo htmlspecialchars($maintenance_message); ?></textarea>
                                <div class="form-text">Message shown to users during maintenance and sent in notification emails</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Backup & Reset Tab -->
                <div id="backupTab" class="tab-content">
                    <div class="form-section">
                        <div class="section-header">
                            <h3><i class="fas fa-database"></i> Database Backup</h3>
                        </div>
                        <p class="form-text">
                            Download a complete backup of your database. This includes all student records, fees, books, and system settings.
                        </p>
                        <div style="margin-top: 1rem;">
                            <button type="submit" name="download_backup" class="btn btn-success">
                                <i class="fas fa-download"></i> Download SQL Backup
                            </button>
                        </div>
                    </div>
                    
                    <div class="danger-zone">
                        <div class="danger-header">
                            <i class="fas fa-exclamation-triangle"></i>
                            <h3 style="margin: 0;">Danger Zone — System Reset</h3>
                        </div>
                        
                        <div style="margin-bottom: 1rem;">
                            <p><strong>Warning:</strong> These actions will permanently delete data. Make sure you have a backup before proceeding.</p>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="reset_type">Reset Type</label>
                                <select class="form-control" id="reset_type" name="reset_type">
                                    <option value="all">All Data (Full Reset)</option>
                                    <option value="students">Students & Fees Only</option>
                                    <option value="library">Library Data Only</option>
                                    <option value="users">Users Only (except admin)</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <div style="margin: 1rem 0;">
                                    <label style="display: flex; align-items: center; gap: 0.5rem;">
                                        <input type="checkbox" name="keep_admin" value="1" checked>
                                        <span>Keep my admin account (recommended)</span>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="form-group full-width">
                                <label class="form-label" for="confirm_phrase">
                                    Type <strong>DELETE ALL DATA</strong> to confirm
                                </label>
                                <input type="text" class="form-control" id="confirm_phrase" name="confirm_phrase" 
                                       placeholder="Type DELETE ALL DATA exactly as shown">
                            </div>
                        </div>
                        
                        <div style="margin-top: 1.5rem;">
                            <button type="submit" name="reset_system" class="btn btn-danger" 
                                    onclick="return confirmReset(event)">
                                <i class="fas fa-exclamation-triangle"></i> Reset System
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Form Footer with Action Buttons -->
                <div style="background: #f8f9fa; border-top: 2px solid #dee2e6; padding: 1.5rem; margin-top: 2rem; border-radius: 0 0 8px 8px;">
                    <div style="max-width: 1200px; margin: 0 auto; display: flex; gap: 1rem; flex-wrap: wrap; align-items: center;">
                        <button type="submit" name="save_settings" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save All Settings
                        </button>
                        <span style="margin-left: auto; font-size: 0.9rem; color: #6c757d;">
                            Last updated: <?php echo date('M d, Y \a\t h:i A'); ?>
                        </span>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script>
        // Tab functionality
        function openTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.getElementById(tabId).classList.add('active');
            event.target.classList.add('active');
        }
        
        // Maintenance mode toggle and schedule visibility
        function toggleMaintenanceOptions() {
            const maintenanceMode = document.getElementById('maintenance_mode');
            const maintenanceTypeContainer = document.getElementById('maintenanceTypeContainer');
            const maintenanceNotifyContainer = document.getElementById('maintenanceNotifyContainer');
            const messageContainer = document.getElementById('maintenanceMessageContainer');
            
            if (maintenanceMode.checked) {
                maintenanceTypeContainer.style.display = 'block';
                maintenanceNotifyContainer.style.display = 'block';
                messageContainer.style.display = 'block';
                toggleMaintenanceSchedule();
            } else {
                maintenanceTypeContainer.style.display = 'none';
                maintenanceNotifyContainer.style.display = 'none';
                messageContainer.style.display = 'none';
                document.getElementById('maintenanceScheduleContainer').style.display = 'none';
            }
        }
        
        function toggleMaintenanceSchedule() {
            const maintenanceType = document.getElementById('maintenance_type');
            const scheduleContainer = document.getElementById('maintenanceScheduleContainer');
            
            if (maintenanceType.value === 'scheduled') {
                scheduleContainer.style.display = 'block';
            } else {
                scheduleContainer.style.display = 'none';
            }
        }
        
        // Confirm End Maintenance with notification option
        function confirmEndMaintenance() {
            Swal.fire({
                title: 'End Maintenance Mode?',
                html: '<div style="text-align: left;">' +
                      '<p>This will disable maintenance mode and restore normal system access.</p>' +
                      '<div style="margin: 15px 0;">' +
                      '<label style="display: flex; align-items: center; gap: 10px;">' +
                      '<input type="checkbox" id="notifyUsers" value="1"> ' +
                      '<span>Notify all users that system is back online</span>' +
                      '</label>' +
                      '</div>' +
                      '</div>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#27ae60',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, End Maintenance',
                cancelButtonText: 'Cancel',
                preConfirm: () => {
                    const notify = document.getElementById('notifyUsers').checked;
                    return { notify: notify };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'Processing',
                        text: 'Ending maintenance mode...',
                        icon: 'info',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    // Create and submit form
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'system_settings.php';
                    
                    const endInput = document.createElement('input');
                    endInput.type = 'hidden';
                    endInput.name = 'end_maintenance';
                    endInput.value = '1';
                    form.appendChild(endInput);
                    
                    if (result.value.notify) {
                        const notifyInput = document.createElement('input');
                        notifyInput.type = 'hidden';
                        notifyInput.name = 'notify_users';
                        notifyInput.value = '1';
                        form.appendChild(notifyInput);
                    }
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
        
        // Confirm reset with extra validation
        function confirmReset(event) {
            event.preventDefault();
            
            const confirmPhrase = document.getElementById('confirm_phrase').value;
            
            if (confirmPhrase !== 'DELETE ALL DATA') {
                Swal.fire({
                    title: 'Invalid Confirmation',
                    text: 'Please type "DELETE ALL DATA" exactly as shown to confirm.',
                    icon: 'error',
                    confirmButtonColor: '#e74c3c'
                });
                return false;
            }
            
            Swal.fire({
                title: 'Are you absolutely sure?',
                text: 'This action cannot be undone! Make sure you have a backup.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#e74c3c',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, reset system',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('settingsForm').submit();
                }
            });
            
            return false;
        }
        
        // Logo preview with size check
        const logoInput = document.getElementById('school_logo');
        if (logoInput) {
            logoInput.addEventListener('change', function(e) {
                if (this.files && this.files[0]) {
                    const file = this.files[0];
                    
                    if (file.size > 5 * 1024 * 1024) {
                        Swal.fire({
                            title: 'File Too Large',
                            text: 'Maximum file size is 5MB. Your file is ' + Math.round(file.size / (1024 * 1024)) + 'MB.',
                            icon: 'error',
                            confirmButtonColor: '#e74c3c'
                        });
                        this.value = '';
                        return false;
                    }
                    
                    // Show preview
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const logoImage = document.querySelector('.logo-image');
                        if (logoImage) {
                            logoImage.src = e.target.result;
                        }
                    };
                    reader.readAsDataURL(file);
                }
            });
        }
        
        // Form submission handling
        const settingsForm = document.getElementById('settingsForm');
        if (settingsForm) {
            settingsForm.addEventListener('submit', function(e) {
                const buttons = this.querySelectorAll('button[type="submit"]');
                buttons.forEach(btn => {
                    if (!btn.name || (btn.name !== 'test_mpesa' && btn.name !== 'test_smtp' && btn.name !== 'reset_system')) {
                        btn.disabled = true;
                        btn.style.opacity = '0.6';
                        btn.style.cursor = 'not-allowed';
                    }
                });
            });
        }
        
        // Test M-Pesa connection validation
        const testMpesaBtn = document.querySelector('button[name="test_mpesa"]');
        if (testMpesaBtn) {
            testMpesaBtn.addEventListener('click', function(e) {
                const consumerKey = document.getElementById('mpesa_consumer_key').value;
                const consumerSecret = document.getElementById('mpesa_consumer_secret').value;
                
                if (!consumerKey || !consumerSecret) {
                    e.preventDefault();
                    Swal.fire({
                        title: 'Incomplete',
                        text: 'Please enter both Consumer Key and Consumer Secret before testing.',
                        icon: 'warning',
                        confirmButtonColor: '#f39c12'
                    });
                }
            });
        }
        
        // Test SMTP connection validation
        const testSmtpBtn = document.querySelector('button[name="test_smtp"]');
        if (testSmtpBtn) {
            testSmtpBtn.addEventListener('click', function(e) {
                const smtpHost = document.getElementById('smtp_host').value;
                const smtpPort = document.getElementById('smtp_port').value;
                const smtpUser = document.getElementById('smtp_user').value;
                
                if (!smtpHost || !smtpPort || !smtpUser) {
                    e.preventDefault();
                    Swal.fire({
                        title: 'Incomplete',
                        text: 'Please enter SMTP Host, Port, and Username before testing.',
                        icon: 'warning',
                        confirmButtonColor: '#f39c12'
                    });
                }
            });
        }
        
        // Initialize maintenance options on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleMaintenanceOptions();
        });
    </script>
</body>
</html>