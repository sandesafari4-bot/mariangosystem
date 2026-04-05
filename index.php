<?php
include 'config.php';
include 'otp_functions.php';

date_default_timezone_set('Africa/Nairobi');

$googleClientId = trim((string) getSystemSetting('google_client_id', envValue('GOOGLE_CLIENT_ID', '')));
$googleLoginEnabled = getSystemSetting('google_login_enabled', '0') === '1' && $googleClientId !== '';
$googleCallbackUrl = buildApplicationUrl('google_callback.php');
$dynamicSchoolName = trim((string) getSystemSetting('school_name', SCHOOL_NAME));
$dynamicSchoolMotto = trim((string) getSystemSetting('school_motto', ''));
$dynamicSchoolEmail = trim((string) getSystemSetting('school_email', ''));
$supportEmail = $dynamicSchoolEmail !== ''
    ? $dynamicSchoolEmail
    : 'support@' . strtolower(preg_replace('/[^a-z0-9]+/', '', $dynamicSchoolName)) . '.edu';

function maskEmailAddress($email) {
    $email = trim((string) $email);
    if ($email === '' || strpos($email, '@') === false) {
        return $email;
    }

    [$local, $domain] = explode('@', $email, 2);
    $maskedLocal = strlen($local) <= 2
        ? substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 1))
        : substr($local, 0, 2) . str_repeat('*', max(2, strlen($local) - 2));

    $domainParts = explode('.', $domain);
    $domainName = $domainParts[0] ?? '';
    $domainExt = count($domainParts) > 1 ? '.' . implode('.', array_slice($domainParts, 1)) : '';
    $maskedDomain = strlen($domainName) <= 2
        ? substr($domainName, 0, 1) . str_repeat('*', max(1, strlen($domainName) - 1))
        : substr($domainName, 0, 2) . str_repeat('*', max(2, strlen($domainName) - 2));

    return $maskedLocal . '@' . $maskedDomain . $domainExt;
}

if (systemSetupRequired()) {
    header('Location: setup_wizard.php');
    exit();
}

// Check if system is in maintenance mode
// Allow admins to bypass maintenance mode
$maintenance_mode = getSystemSetting('maintenance_mode', 'off') === 'on';
$is_admin = isset($_SESSION['user_id']) && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';

if ($maintenance_mode && !$is_admin) {
    header("Location: maintenance.php");
    exit();
}

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    redirectBasedOnRole();
    exit();
}

// Handle different steps of OTP login
$step = isset($_GET['step']) ? $_GET['step'] : 'email';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['google_credential'])) {
        handleGoogleLoginSubmission();
    } else {
        switch ($step) {
            case 'email':
                handleEmailSubmission();
                break;
            case 'verify':
                handleOTPVerification();
                break;
        }
    }
}

// Handle remember token (existing users)
if (isset($_COOKIE['remember_token']) && !isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE remember_token = ? AND token_expiry > NOW()");
    $stmt->execute([$_COOKIE['remember_token']]);
    $user = $stmt->fetch();
    
    if ($user) {
        if (($user['status'] ?? '') !== 'active') {
            setcookie('remember_token', '', time() - 3600, '/');
            $error = "This account is inactive. Please contact the admin.";
        } else {
        loginUser($user);
        header("Location: " . getDashboardPath($user['role']));
        exit();
        }
    }
}

function handleEmailSubmission() {
    global $pdo, $error;
    
    $email = $_POST['email'] ?? '';
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address";
        return;
    }
    
    // Check if email exists in system
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $error = "No account found with this email address";
        return;
    }

    if (($user['status'] ?? '') !== 'active') {
        $error = "This account is inactive. Please contact the admin.";
        return;
    }
    
    // Generate OTP
    $otp = generateOTP();
    $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes')); // Changed to 10 minutes
    
    // Save OTP to database
    $stmt = $pdo->prepare("UPDATE users SET otp_code = ?, otp_expires_at = ?, otp_attempts = 0, otp_created_at = NOW() WHERE id = ?");
    $stmt->execute([$otp, $expires_at, $user['id']]);
    
    // Send OTP email
    if (sendOTPEmail($email, $otp, $user['full_name'])) {
        $_SESSION['otp_email'] = $email;
        $_SESSION['otp_user_id'] = $user['id'];
        $_SESSION['otp_sent_time'] = time();
        header("Location: index.php?step=verify");
        exit();
    } else {
        $error = "Failed to send OTP. Please try again.";
    }
}

function verifyGoogleIdToken($idToken, $expectedClientId) {
    if (empty($idToken) || empty($expectedClientId)) {
        return false;
    }

    $verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
    $response = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($verifyUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        curl_close($ch);
    }

    if ($response === false) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
            ],
        ]);
        $response = @file_get_contents($verifyUrl, false, $context);
    }

    if ($response === false || $response === '') {
        return false;
    }

    $payload = json_decode($response, true);
    if (!is_array($payload)) {
        return false;
    }

    if (($payload['aud'] ?? '') !== $expectedClientId) {
        return false;
    }

    if (($payload['email_verified'] ?? '') !== 'true' && ($payload['email_verified'] ?? false) !== true) {
        return false;
    }

    if (empty($payload['email'])) {
        return false;
    }

    return $payload;
}

function handleGoogleLoginSubmission() {
    global $pdo, $error, $googleClientId;

    if ($googleClientId === '') {
        $error = "Google login is not configured by the admin yet.";
        return;
    }

    $credential = trim((string) ($_POST['google_credential'] ?? ''));
    if ($credential === '') {
        $error = "Google sign-in did not return a valid credential.";
        return;
    }

    $tokenData = verifyGoogleIdToken($credential, $googleClientId);
    if (!$tokenData) {
        $error = "Google sign-in verification failed. Please try again or use OTP login.";
        return;
    }

    $email = filter_var((string) ($tokenData['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Google sign-in returned an invalid email address.";
        return;
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $error = "No system account is linked to this Google email.";
        return;
    }

    if (($user['status'] ?? '') !== 'active') {
        $error = "This account is inactive. Please contact the admin.";
        return;
    }

    loginUser($user);
    logLoginActivity($user['id'], 'google');
    unset($_SESSION['otp_email'], $_SESSION['otp_user_id'], $_SESSION['otp_sent_time']);

    header("Location: " . getDashboardPath($user['role']));
    exit();
}

function handleOTPVerification() {
    global $pdo, $error;
    
    if (!isset($_SESSION['otp_email']) || !isset($_SESSION['otp_user_id'])) {
        $error = "OTP session expired. Please start over.";
        header("Location: index.php");
        exit();
    }
    
    $email = $_SESSION['otp_email'];
    $otp = $_POST['otp'] ?? '';

    $statusStmt = $pdo->prepare("SELECT status FROM users WHERE email = ? LIMIT 1");
    $statusStmt->execute([$email]);
    $accountStatus = $statusStmt->fetchColumn();

    if ($accountStatus !== false && $accountStatus !== 'active') {
        unset($_SESSION['otp_email'], $_SESSION['otp_user_id'], $_SESSION['otp_sent_time']);
        $error = "This account is inactive. Please contact the admin.";
        header("Location: index.php");
        exit();
    }
    
    if (empty($otp) || strlen($otp) != 6) {
        $error = "Please enter a valid 6-digit OTP code";
        return;
    }
    
    // Verify OTP
    $user = verifyOTP($pdo, $email, $otp);
    
    if ($user) {
        $remember_me = isset($_POST['remember_me']);
        
        // Set session
        loginUser($user);
        
        // Set remember me cookie if selected
        if ($remember_me) {
            setRememberMeCookie($user['id']);
        }
        
        // Log login activity
        logLoginActivity($user['id']);
        
        // Clear OTP session
        unset($_SESSION['otp_email']);
        unset($_SESSION['otp_user_id']);
        unset($_SESSION['otp_sent_time']);
        
        // Redirect
        header("Location: " . getDashboardPath($user['role']));
        exit();
    } else {
        $error = "Invalid or expired OTP code";
        
        // Check if too many attempts
        $stmt = $pdo->prepare("SELECT otp_attempts FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $attempts = $stmt->fetchColumn();
        
        if ($attempts >= 5) {
            $error = "Too many failed attempts. Please request a new OTP.";
            clearOTP($pdo, $email);
            header("Location: index.php");
            exit();
        }
    }
}

function loginUser($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['last_login'] = time();
}

function setRememberMeCookie($user_id) {
    global $pdo;
    
    $token = bin2hex(random_bytes(32));
    $expiry = time() + (30 * 24 * 60 * 60);
    
    $stmt = $pdo->prepare("UPDATE users SET remember_token = ?, token_expiry = ? WHERE id = ?");
    $stmt->execute([$token, date('Y-m-d H:i:s', $expiry), $user_id]);
    
    setcookie('remember_token', $token, $expiry, '/');
}

function logLoginActivity($user_id, $method = 'otp') {
    global $pdo;
    
    $stmt = $pdo->prepare("INSERT INTO login_activity (user_id, ip_address, user_agent, login_method) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $user_id,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? '',
        $method,
    ]);
}

function redirectBasedOnRole() {
    if (isset($_SESSION['user_role'])) {
        header("Location: " . getDashboardPath($_SESSION['user_role']));
        exit();
    }
}

function getDashboardPath($role) {
    switch ($role) {
        case 'admin': return 'admin/dashboard.php';
        case 'teacher': return 'teacher/dashboard.php';
        case 'accountant': return 'accountant/dashboard.php';
        case 'librarian': return 'librarian/dashboard.php';
        default: return 'index.php';
    }
}

// Add a debug function to check OTP status
function debugOTP($pdo, $email, $otp) {
    $stmt = $pdo->prepare("SELECT otp_code, otp_expires_at, NOW() as current_time FROM users WHERE email = ?");
    $stmt->execute([$email]);
    return $stmt->fetch();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Login - <?php echo htmlspecialchars($dynamicSchoolName); ?></title>
    <link rel="stylesheet" href="assets/css/responsive.css">
    <link rel="icon" type="image/png" href="uploads/logos/<?php echo htmlspecialchars($DYNAMIC_SCHOOL_LOGO); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Your existing CSS remains the same, just updating the timer section */
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
            position: relative;
            overflow-x: hidden;
        }

        /* Animated Background Elements */
        .bg-elements {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: -1;
        }

        .bg-element {
            position: absolute;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 8s ease-in-out infinite;
        }

        .bg-element:nth-child(1) {
            width: 60px;
            height: 60px;
            top: 10%;
            left: 10%;
            animation-delay: 0s;
        }

        .bg-element:nth-child(2) {
            width: 90px;
            height: 90px;
            top: 70%;
            left: 80%;
            animation-delay: 2s;
        }

        .bg-element:nth-child(3) {
            width: 50px;
            height: 50px;
            top: 40%;
            left: 85%;
            animation-delay: 4s;
        }

        .bg-element:nth-child(4) {
            width: 70px;
            height: 70px;
            top: 80%;
            left: 15%;
            animation-delay: 1s;
        }

        .bg-element:nth-child(5) {
            width: 55px;
            height: 55px;
            top: 20%;
            left: 75%;
            animation-delay: 3s;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg) scale(1); }
            50% { transform: translateY(-20px) rotate(180deg) scale(1.1); }
        }

        /* Login Container - Compact Version */
        .login-container {
            background: rgba(255, 255, 255, 0.97);
            backdrop-filter: blur(15px);
            padding: 1.5rem;
            border-radius: 18px;
            box-shadow: 0 15px 35px -10px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 380px;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.3);
            animation: slideUp 0.6s ease-out;
            margin: 0.5rem;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Decorative Elements */
        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2, #667eea);
            background-size: 200% 100%;
            animation: gradientFlow 3s ease infinite;
        }

        @keyframes gradientFlow {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Logo Section - Smaller */
        .logo-section {
            text-align: center;
            margin-bottom: 1.2rem;
        }

        .logo-wrapper {
            width: 60px;
            height: 60px;
            margin: 0 auto 0.6rem;
            position: relative;
        }

        .logo {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.4rem;
            font-weight: bold;
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3); }
            50% { box-shadow: 0 12px 25px rgba(102, 126, 234, 0.4); }
            100% { box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3); }
        }

        .logo-wrapper::after {
            content: '';
            position: absolute;
            top: -4px;
            left: -4px;
            right: -4px;
            bottom: -4px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 20px;
            z-index: -1;
            opacity: 0.4;
            filter: blur(8px);
        }

        .logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 16px;
        }

        .school-name {
            font-size: 1.3rem;
            font-weight: 800;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.2rem;
        }

        .school-subtitle {
            color: #6c757d;
            font-size: 0.8rem;
            font-weight: 500;
            letter-spacing: 0.3px;
        }

        /* Step Indicator - Compact */
        .step-indicator {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 1.2rem;
            gap: 1.2rem;
        }

        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
        }

        .step-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 0.3rem;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .step.active .step-icon {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 10px rgba(102, 126, 234, 0.3);
        }

        .step.inactive .step-icon {
            background: #e9ecef;
            color: #adb5bd;
        }

        .step-text {
            font-size: 0.7rem;
            font-weight: 600;
            color: #2c3e50;
        }

        .step.inactive .step-text {
            color: #adb5bd;
        }

        .step-line {
            position: absolute;
            top: 16px;
            left: 100%;
            width: 1rem;
            height: 2px;
            background: #e9ecef;
        }

        /* Welcome Text - Compact */
        .welcome-text {
            text-align: center;
            margin-bottom: 1.2rem;
        }

        .welcome-text h2 {
            font-size: 1.2rem;
            color: #2c3e50;
            margin-bottom: 0.3rem;
            font-weight: 700;
        }

        .welcome-text p {
            color: #6c757d;
            font-size: 0.85rem;
            line-height: 1.4;
        }

        /* Error/Success Messages - Compact */
        .message-container {
            margin-bottom: 1rem;
        }

        .error-message {
            background: linear-gradient(135deg, rgba(231, 76, 60, 0.1) 0%, rgba(231, 76, 60, 0.05) 100%);
            border: 1px solid rgba(231, 76, 60, 0.2);
            color: #e74c3c;
            padding: 0.7rem 1rem;
            border-radius: 10px;
            text-align: center;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            font-size: 0.8rem;
            backdrop-filter: blur(8px);
            animation: shake 0.5s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-3px); }
            20%, 40%, 60%, 80% { transform: translateX(3px); }
        }

        .success-message {
            background: linear-gradient(135deg, rgba(39, 174, 96, 0.1) 0%, rgba(39, 174, 96, 0.05) 100%);
            border: 1px solid rgba(39, 174, 96, 0.2);
            color: #27ae60;
            padding: 0.7rem 1rem;
            border-radius: 10px;
            text-align: center;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            font-size: 0.8rem;
            backdrop-filter: blur(8px);
            animation: slideIn 0.5s ease;
        }

        .google-login-section {
            margin-top: 1rem;
            display: grid;
            gap: 0.7rem;
            justify-items: center;
        }

        .google-login-shell {
            min-height: 42px;
            display: flex;
            justify-content: center;
            width: 100%;
        }

        .google-login-card {
            width: 100%;
            max-width: 320px;
            padding: 0.95rem;
            border-radius: 16px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            border: 1px solid rgba(15, 23, 42, 0.08);
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.06);
        }

        .google-login-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            margin-bottom: 0.75rem;
            color: #1f2937;
            font-size: 0.92rem;
            font-weight: 700;
        }

        .google-mark {
            width: 24px;
            height: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .google-mark svg {
            width: 100%;
            height: 100%;
            display: block;
        }

        .google-login-note {
            text-align: center;
            color: #6c757d;
            font-size: 0.82rem;
            line-height: 1.5;
            max-width: 280px;
            margin: 0 auto;
        }

        .google-fallback {
            width: 100%;
            justify-content: center;
            background: #fff;
            color: #1f2937;
        }

        .google-fallback:hover {
            background: linear-gradient(135deg, #ffffff 0%, #eef4ff 100%);
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Forms - Compact */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.4rem;
            color: #2c3e50;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            color: #667eea;
            z-index: 2;
            font-size: 0.95rem;
            transition: all 0.25s ease;
        }

        .form-input {
            width: 100%;
            padding: 1.08rem 1rem 0.48rem 2.6rem;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            background: #fff;
            color: #2c3e50;
        }

        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            transform: translateY(-1px);
        }

        .floating-label {
            position: absolute;
            left: 2.6rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 0.9rem;
            pointer-events: none;
            transition: all 0.25s ease;
            background: #fff;
            padding: 0 0.2rem;
        }

        .form-input:focus + .floating-label,
        .form-input:not(:placeholder-shown) + .floating-label {
            top: 0;
            transform: translateY(-45%);
            font-size: 0.72rem;
            color: #667eea;
            font-weight: 700;
        }

        .form-input:focus ~ .input-icon,
        .form-input:not(:placeholder-shown) ~ .input-icon {
            color: #4f46e5;
        }

        /* OTP Specific Styles - Compact */
        .email-display {
            background: linear-gradient(135deg, #f8f9fa 0%, #f1f3f5 100%);
            padding: 0.72rem 0.85rem;
            border-radius: 12px;
            margin: 0.8rem 0 0.9rem;
            text-align: center;
            border: 2px dashed #dee2e6;
            font-size: 0.82rem;
            animation: otpFloat 3.8s ease-in-out infinite;
            transform-origin: center;
        }

        .email-display strong {
            color: #667eea;
            font-weight: 700;
            font-size: 0.88rem;
            letter-spacing: 0.03em;
        }

        .otp-inputs-container {
            margin: 0.95rem 0;
            animation: otpFloat 4.5s ease-in-out infinite;
        }

        .otp-inputs {
            display: flex;
            gap: 6px;
            justify-content: center;
            margin-bottom: 0.65rem;
        }

        .otp-input {
            width: 39px;
            height: 46px;
            text-align: center;
            font-size: 18px;
            font-weight: 700;
            border: 2px solid #e9ecef;
            border-radius: 9px;
            background: #fff;
            transition: all 0.3s ease;
            color: #2c3e50;
        }

        .otp-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            transform: translateY(-1px);
            outline: none;
        }

        .otp-input.filled {
            border-color: #27ae60;
            background: rgba(39, 174, 96, 0.05);
        }

        .otp-input.error {
            border-color: #e74c3c;
            background: rgba(231, 76, 60, 0.05);
            animation: shake 0.5s ease;
        }

        .otp-timer {
            text-align: center;
            margin: 0.8rem 0 0.7rem;
        }

        .timer-container {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: linear-gradient(135deg, #f8f9fa 0%, #f1f3f5 100%);
            padding: 0.5rem 0.95rem;
            border-radius: 40px;
            border: 2px solid #e9ecef;
            font-size: 0.8rem;
        }

        .timer-container.expiring {
            border-color: #e74c3c;
            background: linear-gradient(135deg, rgba(231, 76, 60, 0.1) 0%, rgba(231, 76, 60, 0.05) 100%);
        }

        #countdown {
            font-family: 'Courier New', monospace;
            font-weight: 700;
            font-size: 0.92rem;
            color: #2c3e50;
        }

        @keyframes otpFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-4px); }
        }

        .timer-container.expiring #countdown {
            color: #e74c3c;
        }

        .resend-otp {
            text-align: center;
            margin: 1rem 0;
        }

        .resend-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 0.7rem 1.5rem;
            border-radius: 40px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            box-shadow: 0 4px 10px rgba(102, 126, 234, 0.3);
        }

        .resend-btn:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 6px 15px rgba(102, 126, 234, 0.4);
        }

        .resend-btn:disabled {
            background: #e9ecef;
            color: #adb5bd;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* Form Options - Compact */
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 1rem 0;
            padding: 0.8rem 0;
            border-top: 1px solid #e9ecef;
            border-bottom: 1px solid #e9ecef;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            color: #2c3e50;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.85rem;
        }

        .remember-me input {
            width: 16px;
            height: 16px;
            accent-color: #667eea;
            cursor: pointer;
        }

        /* Buttons - Compact */
        .action-btn {
            width: 100%;
            padding: 0.8rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            margin: 1rem 0;
            position: relative;
            overflow: hidden;
            box-shadow: 0 6px 15px rgba(102, 126, 234, 0.3);
        }

        .action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: 0.5s;
        }

        .action-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }

        .action-btn:hover:not(:disabled)::before {
            left: 100%;
        }

        .action-btn:disabled {
            background: #e9ecef;
            color: #adb5bd;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .action-btn:active:not(:disabled) {
            transform: translateY(-1px);
        }

        .loading {
            display: none;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top: 2px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Alternative Options - Compact */
        .alternative-options {
            text-align: center;
            margin-top: 1rem;
        }

        .back-link {
            color: #41089cff;
            text-decoration: none;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.6rem 1.2rem;
            border-radius: 40px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        .back-link:hover {
            color: #667eea;
            border-radius: 4px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            background: #e9ecef;
            text-decoration: none;
            transform: translateY(-1px);
        }

        .divider {
            display: flex;
            align-items: center;
            margin: 1rem 0;
            color: #adb5bd;
            font-size: 0.85rem;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e9ecef;
        }

        .divider span {
            padding: 0 0.8rem;
        }

        /* System Information - Compact */
        .system-info {
            text-align: center;
            color: #6c757d;
            font-size: 0.75rem;
            margin-top: 1.2rem;
            padding-top: 1rem;
            border-top: 1px solid #e9ecef;
            line-height: 1.4;
        }

        .system-info a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }

        .system-info a:hover {
            text-decoration: underline;
        }

        /* Responsive Design - Mobile First */
        @media (max-width: 480px) {
            body {
                padding: 0.3rem;
            }
            
            .login-container {
                padding: 1.2rem;
                border-radius: 16px;
                margin: 0.3rem;
            }
            
            .logo-wrapper {
                width: 55px;
                height: 55px;
            }
            
            .logo {
                font-size: 1.2rem;
                border-radius: 14px;
            }
            
            .school-name {
                font-size: 1.2rem;
            }
            
            .otp-input {
                width: 34px;
                height: 42px;
                font-size: 17px;
            }

            .otp-inputs {
                gap: 5px;
            }
            
            .step-indicator {
                gap: 1rem;
            }
            
            .step-icon {
                width: 28px;
                height: 28px;
                font-size: 0.8rem;
            }
            
            .step-line {
                width: 0.8rem;
                top: 14px;
            }
            
            .step-text {
                font-size: 0.65rem;
            }
            
            .welcome-text h2 {
                font-size: 1.1rem;
            }
            
            .welcome-text p {
                font-size: 0.8rem;
            }
            
            .action-btn, .resend-btn {
                padding: 0.6rem 1.2rem;
                font-size: 0.85rem;
            }
            
            .form-input {
                padding: 0.7rem 0.9rem 0.7rem 2.4rem;
                font-size: 0.85rem;
            }
        }

        /* Focus Animation - Subtle */
        @keyframes focusPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(102, 126, 234, 0.3); }
            50% { box-shadow: 0 0 0 6px rgba(102, 126, 234, 0); }
        }

        .form-input:focus {
            animation: focusPulse 2s infinite;
        }

        .timer-container.expiring-soon {
            border-color: #f39c12;
            background: linear-gradient(135deg, rgba(243, 156, 18, 0.1) 0%, rgba(243, 156, 18, 0.05) 100%);
        }
        
        .timer-container.expiring-soon #countdown {
            color: #f39c12;
        }
        
        /* Scrollbar styling for small screens */
        @media (max-height: 600px) {
            body {
                align-items: flex-start;
                padding-top: 1rem;
                overflow-y: auto;
            }
            
            .login-container {
                margin-top: 1rem;
                margin-bottom: 1rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'loader.php'; ?>
    <!-- Animated Background Elements -->
    <div class="bg-elements">
        <div class="bg-element"></div>
        <div class="bg-element"></div>
        <div class="bg-element"></div>
        <div class="bg-element"></div>
        <div class="bg-element"></div>
    </div>

    <div class="login-container">
        <!-- Logo Section -->
        <div class="logo-section">
            <div class="logo-wrapper">
                <div class="logo">
                    <img src="uploads/logos/<?php echo htmlspecialchars($DYNAMIC_SCHOOL_LOGO); ?>" alt="<?php echo htmlspecialchars($dynamicSchoolName); ?> Logo" onerror="this.src='logo.png'">
                </div>
            </div>
            <h1 class="school-name"><?php echo htmlspecialchars($dynamicSchoolName); ?></h1>
            <p class="school-subtitle"><?php echo htmlspecialchars($dynamicSchoolMotto !== '' ? $dynamicSchoolMotto : 'Secure OTP Authentication'); ?></p>
        </div>

        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step <?php echo $step == 'email' ? 'active' : ($step == 'verify' ? 'inactive' : ''); ?>">
                <div class="step-icon">
                    <i class="fas fa-envelope"></i>
                </div>
                <span class="step-text">Enter Email</span>
                <div class="step-line"></div>
            </div>
            <div class="step <?php echo $step == 'verify' ? 'active' : 'inactive'; ?>">
                <div class="step-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <span class="step-text">Verify OTP</span>
            </div>
        </div>

        <!-- Welcome Text -->
        <div class="welcome-text">
            <?php if ($step == 'email'): ?>
                <h2>Secure Login</h2>
                <p>Enter your email to receive a one-time password</p>
            <?php else: ?>
                <h2>Verify Identity</h2>
                <p>Enter the 6-digit code sent to your email</p>
            <?php endif; ?>
        </div>

        <!-- Messages -->
        <div class="message-container">
            <?php if (isset($error)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['message'])): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_GET['message']); ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Forms -->
        <?php if ($step == 'email'): ?>
            <!-- Email Input Form -->
            <form method="POST" id="emailForm">
                <div class="form-group">
                    <div class="input-group">
                        <i class="fas fa-envelope input-icon"></i>
                        <input type="email" name="email" class="form-input" 
                               placeholder=" " required 
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                               autocomplete="email">
                        <span class="floating-label">Email Address</span>
                    </div>
                </div>

                <button type="submit" class="action-btn" id="submitBtn">
                    <span>Send OTP</span>
                    <i class="fas fa-paper-plane"></i>
                    <div class="loading" id="loadingSpinner"></div>
                </button>
            </form>

            <?php if ($googleLoginEnabled): ?>
            <div class="divider">
                <span>OR</span>
            </div>

            <div class="google-login-section">
                <div class="google-login-card">
                    <div class="google-login-header">
                        <span class="google-mark" aria-hidden="true">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path fill="#EA4335" d="M12 10.2v3.9h5.5c-.2 1.3-1.5 3.9-5.5 3.9-3.3 0-6-2.7-6-6s2.7-6 6-6c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.8 3.5 14.6 2.6 12 2.6 6.8 2.6 2.6 6.8 2.6 12S6.8 21.4 12 21.4c6.1 0 9.1-4.3 9.1-6.5 0-.4 0-.8-.1-1.2H12z"/>
                                <path fill="#4285F4" d="M21.1 12.9c0-.4 0-.8-.1-1.2H12v3.9h5.5c-.3 1.5-1.1 2.7-2.4 3.5l3 2.3c1.8-1.7 2.9-4.1 2.9-6.8z"/>
                                <path fill="#FBBC05" d="M5.6 14.3c-.2-.6-.3-1.2-.3-1.9s.1-1.3.3-1.9l-3.1-2.4C1.7 9.3 1.4 10.6 1.4 12s.3 2.7 1.1 3.9l3.1-1.6z"/>
                                <path fill="#34A853" d="M12 21.4c2.5 0 4.7-.8 6.3-2.3l-3-2.3c-.8.6-1.9 1-3.3 1-2.6 0-4.9-1.8-5.7-4.2l-3.1 2.4c1.6 3.2 4.9 5.4 8.8 5.4z"/>
                                <path fill="#4285F4" d="M6.3 8.5C7.1 6 9.4 4.2 12 4.2c1.4 0 2.6.5 3.6 1.4l2.8-2.8C16.7 1.2 14.5.4 12 .4 8.1.4 4.8 2.6 3.2 5.8l3.1 2.7z"/>
                            </svg>
                        </span>
                        <span>Continue With Google</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="alternative-options">
                <a href="login.php" class="back-link">
                    <i class="fas fa-key"></i>
                    Use password login
                </a>
            </div>

        <?php else: ?>
            <!-- OTP Verification Form -->
            <div class="email-display">
                <i class="fas fa-envelope-open-text"></i>
                <br>
                <small>OTP sent to:</small><br>
                <strong><?php echo isset($_SESSION['otp_email']) ? htmlspecialchars(maskEmailAddress($_SESSION['otp_email'])) : ''; ?></strong>
                <?php if (isset($_SESSION['otp_sent_time'])): ?>
                <br><small>Sent at: <?php echo date('H:i:s', $_SESSION['otp_sent_time']); ?></small>
                <?php endif; ?>
            </div>

            <form method="POST" id="otpForm">
                <div class="form-group">
                    <label class="form-label">Enter 6-digit OTP</label>
                    <div class="otp-inputs-container">
                        <div class="otp-inputs" id="otpContainer">
                            <input type="text" class="otp-input" maxlength="1" data-index="1" autocomplete="off" inputmode="numeric" pattern="[0-9]*">
                            <input type="text" class="otp-input" maxlength="1" data-index="2" autocomplete="off" inputmode="numeric" pattern="[0-9]*">
                            <input type="text" class="otp-input" maxlength="1" data-index="3" autocomplete="off" inputmode="numeric" pattern="[0-9]*">
                            <input type="text" class="otp-input" maxlength="1" data-index="4" autocomplete="off" inputmode="numeric" pattern="[0-9]*">
                            <input type="text" class="otp-input" maxlength="1" data-index="5" autocomplete="off" inputmode="numeric" pattern="[0-9]*">
                            <input type="text" class="otp-input" maxlength="1" data-index="6" autocomplete="off" inputmode="numeric" pattern="[0-9]*">
                        </div>
                        <input type="hidden" name="otp" id="otpInput">
                        
                        <div class="otp-timer">
                            <div class="timer-container" id="timerContainer">
                                <i class="fas fa-clock"></i>
                                <span>Code expires in:</span>
                                <span id="countdown">10:00</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="resend-otp">
                    <button type="button" id="resendBtn" class="resend-btn" disabled>
                        <i class="fas fa-redo"></i>
                        Resend OTP (<span id="resendTimer">60</span>s)
                    </button>
                </div>

                <div class="form-options">
                    <label class="remember-me">
                        <input type="checkbox" name="remember_me" id="remember_me">
                        Remember this device for 30 days
                    </label>
                </div>

                <button type="submit" class="action-btn" id="verifyBtn">
                    <span>Verify & Continue</span>
                    <i class="fas fa-arrow-right"></i>
                    <div class="loading" id="verifySpinner"></div>
                </button>
            </form>

            <div class="alternative-options">
                <a href="index.php" class="back-link">
                    <i class="fas fa-arrow-left"></i>
                    Use different email
                </a>
            </div>
        <?php endif; ?>

        <!-- System Information -->
    <div class="system-info">
        <p>Need assistance? <a href="mailto:<?php echo htmlspecialchars($supportEmail); ?>">Contact IT Support</a></p>
        <p>© <?php echo date('Y'); ?> <?php echo htmlspecialchars($dynamicSchoolName); ?>. All rights reserved.<br>
        <small>Secure login powered by One-Time Password authentication</small></p>
    </div>
    </div>
    <?php if ($googleLoginEnabled): ?>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <?php endif; ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const step = '<?php echo $step; ?>';
            const googleEnabled = <?php echo $googleLoginEnabled ? 'true' : 'false'; ?>;
            
            if (step === 'email') {
                initEmailStep();
            } else {
                initOTPStep();
            }

            if (googleEnabled && step === 'email') {
                initGoogleSignIn();
            }
            
            function initEmailStep() {
                const emailForm = document.getElementById('emailForm');
                const submitBtn = document.getElementById('submitBtn');
                const loadingSpinner = submitBtn.querySelector('.loading');
                const emailInput = document.querySelector('input[name="email"]');
                
                emailInput.focus();
                
                emailForm.addEventListener('submit', function(e) {
                    if (!emailInput.value.trim()) {
                        e.preventDefault();
                        emailInput.focus();
                        return;
                    }
                    
                    submitBtn.disabled = true;
                    submitBtn.querySelector('span').textContent = 'Sending OTP...';
                    loadingSpinner.style.display = 'block';
                });
                
                emailInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter' && this.value.trim()) {
                        emailForm.requestSubmit();
                    }
                });
            }

            function initGoogleSignIn() {
                if (typeof google === 'undefined' || !google.accounts || !google.accounts.id) {
                    return;
                }

                const googleButton = document.getElementById('googleLoginButton');
                const googleFallbackButton = document.getElementById('googleFallbackButton');

                if (!googleButton) {
                    return;
                }

                const redirectUrl = '<?php echo htmlspecialchars($googleCallbackUrl, ENT_QUOTES); ?>';
                const fallbackUrl = 'https://accounts.google.com/o/oauth2/v2/auth'
                    + '?client_id=' + encodeURIComponent('<?php echo htmlspecialchars($googleClientId, ENT_QUOTES); ?>')
                    + '&redirect_uri=' + encodeURIComponent(redirectUrl)
                    + '&response_type=code'
                    + '&scope=' + encodeURIComponent('openid email profile')
                    + '&prompt=select_account';

                google.accounts.id.initialize({
                    client_id: '<?php echo htmlspecialchars($googleClientId, ENT_QUOTES); ?>',
                    ux_mode: 'redirect',
                    login_uri: redirectUrl
                });

                google.accounts.id.renderButton(googleButton, {
                    theme: 'outline',
                    size: 'large',
                    shape: 'pill',
                    text: 'signin_with',
                    width: 280
                });

                if (googleFallbackButton) {
                    googleFallbackButton.addEventListener('click', function() {
                        window.location.href = fallbackUrl;
                    });

                    googleFallbackButton.style.display = 'inline-flex';
                }
            }
            
            function initOTPStep() {
                const otpInputs = document.querySelectorAll('.otp-input');
                const otpHidden = document.getElementById('otpInput');
                const otpForm = document.getElementById('otpForm');
                const resendBtn = document.getElementById('resendBtn');
                const verifyBtn = document.getElementById('verifyBtn');
                const verifySpinner = verifyBtn.querySelector('.loading');
                const timerContainer = document.getElementById('timerContainer');
                const countdownElement = document.getElementById('countdown');
                
                let otpValue = '';
                let timeLeft = 600; // 10 minutes in seconds
                let resendTime = 60; // 60 seconds cooldown
                let otpExpired = false;
                
                // Initialize OTP input handling
                otpInputs.forEach((input, index) => {
                    input.addEventListener('input', handleOTPInput);
                    input.addEventListener('keydown', handleOTPKeydown);
                    input.addEventListener('paste', handleOTPPaste);
                    input.addEventListener('focus', handleOTPFocus);
                });
                
                otpInputs[0].focus();
                
                // Start timers
                startOTPTimer();
                startResendTimer();
                
                function handleOTPInput(e) {
                    const input = e.target;
                    const value = input.value;
                    const index = parseInt(input.dataset.index);
                    
                    // Allow only numbers
                    if (!/^\d$/.test(value)) {
                        input.value = '';
                        return;
                    }
                    
                    input.classList.add('filled');
                    input.classList.remove('error');
                    
                    if (value && index < otpInputs.length) {
                        otpInputs[index].focus();
                    }
                    
                    updateOTPValue();
                    
                    // Auto-submit when all digits are entered
                    if (otpValue.length === 6 && !verifyBtn.disabled) {
                        setTimeout(() => {
                            otpForm.requestSubmit();
                        }, 300);
                    }
                }
                
                function handleOTPKeydown(e) {
                    const input = e.target;
                    const index = parseInt(input.dataset.index);
                    
                    if (e.key === 'Backspace') {
                        if (input.value === '' && index > 1) {
                            otpInputs[index - 2].focus();
                        } else {
                            input.value = '';
                            input.classList.remove('filled', 'error');
                            updateOTPValue();
                        }
                        e.preventDefault();
                    }
                    
                    if (e.key === 'ArrowLeft' && index > 1) {
                        otpInputs[index - 2].focus();
                        e.preventDefault();
                    }
                    
                    if (e.key === 'ArrowRight' && index < otpInputs.length) {
                        otpInputs[index].focus();
                        e.preventDefault();
                    }
                }
                
                function handleOTPPaste(e) {
                    e.preventDefault();
                    const pastedData = e.clipboardData.getData('text');
                    const digits = pastedData.replace(/\D/g, '').split('');
                    
                    digits.forEach((digit, i) => {
                        if (otpInputs[i]) {
                            otpInputs[i].value = digit;
                            otpInputs[i].classList.add('filled');
                            otpInputs[i].classList.remove('error');
                        }
                    });
                    
                    updateOTPValue();
                    
                    const emptyIndex = Array.from(otpInputs).findIndex(input => !input.value);
                    const focusIndex = emptyIndex === -1 ? otpInputs.length - 1 : Math.min(emptyIndex, otpInputs.length - 1);
                    otpInputs[focusIndex].focus();
                }
                
                function handleOTPFocus(e) {
                    const input = e.target;
                    setTimeout(() => input.select(), 0);
                }
                
                function updateOTPValue() {
                    otpValue = Array.from(otpInputs).map(input => input.value).join('');
                    otpHidden.value = otpValue;
                    
                    const isComplete = otpValue.length === otpInputs.length;
                    verifyBtn.disabled = !isComplete || otpExpired;
                    
                    if (otpValue.length > 0) {
                        otpInputs.forEach(input => input.classList.remove('error'));
                    }
                }
                
                function startOTPTimer() {
                    const timerInterval = setInterval(() => {
                        timeLeft--;
                        const minutes = Math.floor(timeLeft / 60);
                        const seconds = timeLeft % 60;
                        
                        countdownElement.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                        
                        // Warning state (last 3 minutes)
                        if (timeLeft <= 180 && timeLeft > 60) {
                            timerContainer.classList.add('expiring-soon');
                        }
                        
                        // Critical state (last minute)
                        if (timeLeft <= 60) {
                            timerContainer.classList.remove('expiring-soon');
                            timerContainer.classList.add('expiring');
                        }
                        
                        if (timeLeft <= 0) {
                            clearInterval(timerInterval);
                            otpExpired = true;
                            countdownElement.textContent = '00:00';
                            verifyBtn.disabled = true;
                            verifyBtn.innerHTML = '<span>OTP Expired</span> <i class="fas fa-clock"></i>';
                            
                            otpInputs.forEach(input => {
                                input.disabled = true;
                                input.classList.add('error');
                            });
                            
                            showErrorMessage('OTP has expired. Please request a new one.');
                        }
                    }, 1000);
                }
                
                function startResendTimer() {
                    const resendInterval = setInterval(() => {
                        resendTime--;
                        document.getElementById('resendTimer').textContent = resendTime;
                        
                        if (resendTime <= 0) {
                            clearInterval(resendInterval);
                            resendBtn.disabled = false;
                            resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend OTP';
                        }
                    }, 1000);
                }
                
                resendBtn.addEventListener('click', function() {
                    if (this.disabled) return;
                    
                    this.disabled = true;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
                    
                    // Use AJAX to resend OTP
                    const formData = new FormData();
                    formData.append('email', '<?php echo $_SESSION['otp_email'] ?? ''; ?>');
                    
                    fetch('resend_otp.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            resetOTPForm();
                            showSuccessMessage('New OTP sent successfully!');
                            
                            timeLeft = 600;
                            resendTime = 60;
                            otpExpired = false;
                            
                            startOTPTimer();
                            startResendTimer();
                            
                            timerContainer.classList.remove('expiring', 'expiring-soon');
                            verifyBtn.disabled = false;
                            verifyBtn.innerHTML = '<span>Verify & Continue</span> <i class="fas fa-arrow-right"></i> <div class="loading"></div>';
                        } else {
                            showErrorMessage(data.message || 'Failed to resend OTP');
                            this.disabled = false;
                            this.innerHTML = '<i class="fas fa-redo"></i> Resend OTP';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showErrorMessage('Network error. Please try again.');
                        this.disabled = false;
                        this.innerHTML = '<i class="fas fa-redo"></i> Resend OTP';
                    });
                });
                
                function resetOTPForm() {
                    otpInputs.forEach(input => {
                        input.value = '';
                        input.classList.remove('filled', 'error');
                        input.disabled = false;
                    });
                    
                    otpValue = '';
                    otpHidden.value = '';
                    
                    otpInputs[0].focus();
                }
                
                function showSuccessMessage(message) {
                    const messageDiv = document.createElement('div');
                    messageDiv.className = 'success-message';
                    messageDiv.innerHTML = `<i class="fas fa-check-circle"></i> ${message}`;
                    
                    const messageContainer = document.querySelector('.message-container');
                    messageContainer.innerHTML = '';
                    messageContainer.appendChild(messageDiv);
                    
                    setTimeout(() => {
                        messageDiv.style.opacity = '0';
                        setTimeout(() => messageDiv.remove(), 300);
                    }, 5000);
                }
                
                function showErrorMessage(message) {
                    const messageDiv = document.createElement('div');
                    messageDiv.className = 'error-message';
                    messageDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
                    
                    const messageContainer = document.querySelector('.message-container');
                    messageContainer.innerHTML = '';
                    messageContainer.appendChild(messageDiv);
                    
                    otpInputs.forEach(input => input.classList.add('error'));
                }
                
                otpForm.addEventListener('submit', function(e) {
                    if (verifyBtn.disabled) {
                        e.preventDefault();
                        return;
                    }
                    
                    if (otpValue.length !== 6) {
                        e.preventDefault();
                        showErrorMessage('Please enter the complete 6-digit OTP');
                        return;
                    }
                    
                    verifyBtn.disabled = true;
                    verifyBtn.querySelector('span').textContent = 'Verifying...';
                    verifySpinner.style.display = 'block';
                });
            }
        });
    </script>
</body>
</html>
