<?php
session_start();
ob_start();
require_once 'config.php';
require_once 'otp_functions.php';

// Check if system is in maintenance mode
$maintenance_mode = getSystemSetting('maintenance_mode', 'off') === 'on';
if ($maintenance_mode && (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin')) {
    header("Location: maintenance.php");
    exit();
}

$error = '';
$success = '';
$page_title = "Forgot Password - " . SCHOOL_NAME;

// Handle email submission for password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $reset_method = $_POST['reset_method'] ?? 'link';
    
    // Validate email
    if (empty($email)) {
        $error = "Please enter your email address";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address";
    } else {
        try {
            // Check if email exists
            $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE email = ? AND status = 'active'");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                // Security: don't reveal if email exists
                $success = "If an account with this email exists, you will receive reset instructions shortly.";
                // Don't proceed further if user doesn't exist
            } else {
                if ($reset_method === 'otp') {
                    // Generate OTP
                    $otp = generateOTP();
                    $otp_expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                    
                    // Save OTP to database
                    $update_stmt = $pdo->prepare("UPDATE users SET otp_code = ?, otp_expires_at = ?, otp_type = 'password_reset' WHERE id = ?");
                    $result = $update_stmt->execute([$otp, $otp_expires, $user['id']]);
                    
                    if (!$result) {
                        error_log("Failed to save OTP for user: " . $user['id']);
                        $error = "Database error. Please try again.";
                    } else {
                        // Send OTP email
                        if (sendPasswordResetOTP($user['email'], $otp, $user['full_name'])) {
                            $_SESSION['reset_email'] = $user['email'];
                            $_SESSION['reset_method'] = 'otp';
                            $_SESSION['reset_user_id'] = $user['id'];
                            $_SESSION['reset_request_time'] = time();
                            
                            // Debug log
                            if (DEBUG_MODE) {
                                error_log("OTP sent to: " . $user['email'] . " OTP: " . $otp);
                            }
                            
                            header("Location: verify_password_reset.php");
                            exit();
                        } else {
                            $error = "Failed to send OTP. Please try again or use the link method.";
                        }
                    }
                    
                } else { // Link method
                    // Generate unique reset token
                    $token = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    
                    // Save token to database
                    $update_stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
                    $result = $update_stmt->execute([$token, $expires, $user['id']]);
                    
                    if (!$result) {
                        error_log("Failed to save reset token for user: " . $user['id']);
                        $error = "Database error. Please try again.";
                    } else {
                        // Create reset link
                        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
                        $base_url = $protocol . $_SERVER['HTTP_HOST'];
                        $script_path = dirname($_SERVER['SCRIPT_NAME']);
                        $reset_link = $base_url . $script_path . "/reset_password.php?token=" . $token;
                        
                        // Debug log
                        if (DEBUG_MODE) {
                            error_log("Reset token generated: " . $token . " for user: " . $user['id']);
                            error_log("Reset link: " . $reset_link);
                        }
                        
                        // Send reset link email
                        if (sendPasswordResetLink($user['email'], $reset_link, $user['full_name'])) {
                            $success = "Password reset link has been sent to your email. Please check your inbox (and spam folder).";
                            
                            // Debug success
                            if (DEBUG_MODE) {
                                error_log("Reset link sent to: " . $user['email']);
                            }
                        } else {
                            $error = "Failed to send reset link. Please try again or use OTP method.";
                        }
                    }
                }
            }
            
        } catch (PDOException $e) {
            error_log("Database error in forgot_password.php: " . $e->getMessage());
            $error = "Database error. Please try again later. Error: " . (DEBUG_MODE ? $e->getMessage() : "");
        } catch (Exception $e) {
            error_log("Error in forgot_password.php: " . $e->getMessage());
            $error = "An error occurred. Please try again.";
        }
    }
}

// Rest of the file remains the same (HTML/CSS/JS)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/png" href="uploads/logos/<?php echo htmlspecialchars($DYNAMIC_SCHOOL_LOGO); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <style>
       :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --success-color: #27ae60;
            --error-color: #e74c3c;
            --warning-color: #f39c12;
            --text-dark: #2c3e50;
            --text-light: #6c757d;
            --bg-light: #f8f9fa;
            --border-color: #e9ecef;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            line-height: 1.6;
        }
        
        .container {
            width: 100%;
            max-width: 480px;
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from { 
                opacity: 0; 
                transform: translateY(30px); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
            }
        }
        
        .card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }
        
        .card-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 10%;
            right: 10%;
            height: 4px;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 2px;
        }
        
        .header-icon {
            font-size: 2.8rem;
            margin-bottom: 15px;
            display: inline-block;
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            backdrop-filter: blur(5px);
        }
        
        .card-header h1 {
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }
        
        .card-header p {
            font-size: 0.95rem;
            opacity: 0.9;
            font-weight: 400;
            max-width: 80%;
            margin: 0 auto;
        }
        
        .card-body {
            padding: 35px;
        }
        
        .alert {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from { 
                opacity: 0; 
                transform: translateY(-10px); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
            }
        }
        
        .alert-success {
            background: linear-gradient(135deg, rgba(39, 174, 96, 0.1) 0%, rgba(39, 174, 96, 0.05) 100%);
            color: var(--success-color);
            border: 1px solid rgba(39, 174, 96, 0.2);
        }
        
        .alert-error {
            background: linear-gradient(135deg, rgba(231, 76, 60, 0.1) 0%, rgba(231, 76, 60, 0.05) 100%);
            color: var(--error-color);
            border: 1px solid rgba(231, 76, 60, 0.2);
        }
        
        .alert i {
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .input-group {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-color);
            font-size: 1.1rem;
            z-index: 2;
        }
        
        .form-control {
            width: 100%;
            padding: 14px 20px 14px 50px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
            color: var(--text-dark);
            font-weight: 500;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.15);
            transform: translateY(-1px);
        }
        
        .form-control::placeholder {
            color: #adb5bd;
            font-weight: 400;
        }
        
        .reset-methods {
            background: var(--bg-light);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            border: 2px dashed #dee2e6;
        }
        
        .reset-methods h3 {
            color: var(--text-dark);
            font-size: 1rem;
            margin-bottom: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .method-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .method-radio {
            display: none;
        }
        
        .method-label {
            display: block;
            padding: 20px 15px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
            height: 100%;
        }
        
        .method-label:hover {
            border-color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.1);
        }
        
        .method-radio:checked + .method-label {
            border-color: var(--primary-color);
            background: rgba(102, 126, 234, 0.05);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.15);
        }
        
        .method-icon {
            font-size: 1.8rem;
            color: var(--primary-color);
            margin-bottom: 10px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .method-title {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.95rem;
            margin-bottom: 5px;
        }
        
        .method-desc {
            font-size: 0.8rem;
            color: var(--text-light);
            line-height: 1.4;
        }
        
        .btn {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.2);
        }
        
        .btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px rgba(102, 126, 234, 0.3);
        }
        
        .btn:disabled {
            background: var(--border-color);
            color: #adb5bd;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .loading {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top: 3px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .info-box {
            background: linear-gradient(135deg, rgba(52, 152, 219, 0.1) 0%, rgba(41, 128, 185, 0.05) 100%);
            border: 1px solid rgba(52, 152, 219, 0.2);
            border-radius: 12px;
            padding: 16px;
            margin: 25px 0;
            font-size: 0.85rem;
            color: var(--text-dark);
            display: flex;
            align-items: flex-start;
            gap: 12px;
            line-height: 1.5;
        }
        
        .info-box i {
            color: #3498db;
            font-size: 1.1rem;
            margin-top: 2px;
            flex-shrink: 0;
        }
        
        .card-footer {
            text-align: center;
            padding: 25px 35px 30px;
            border-top: 1px solid var(--border-color);
        }
        
        .card-footer a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 50px;
            transition: all 0.3s ease;
            background: var(--bg-light);
        }
        
        .card-footer a:hover {
            color: white;
            background: var(--primary-color);
            text-decoration: none;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.2);
        }
        
        @media (max-width: 576px) {
            .container {
                max-width: 100%;
                padding: 10px;
            }
            
            .card-body {
                padding: 25px 20px;
            }
            
            .card-header {
                padding: 25px 20px;
            }
            
            .method-options {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            
            .method-label {
                padding: 18px 12px;
            }
            
            .header-icon {
                width: 70px;
                height: 70px;
                font-size: 2.4rem;
            }
        }
        
        @media (max-width: 360px) {
            .card-body {
                padding: 20px 15px;
            }
            
            .card-header {
                padding: 20px 15px;
            }
            
            .card-header h1 {
                font-size: 1.4rem;
            }
            
            .btn {
                padding: 14px;
                font-size: 0.95rem;
            }
        }
        
        /* Add this for debugging info */
        .debug-info {
            background: #f8f9fa;
            border: 1px dashed #dee2e6;
            padding: 10px;
            margin-top: 15px;
            border-radius: 8px;
            font-size: 0.8rem;
            color: #6c757d;
            display: <?php echo DEBUG_MODE ? 'block' : 'none'; ?>;
        }
    </style>
</head>
<body>
    <?php include 'loader.php'; ?>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <div class="header-icon">
                    <i class="fas fa-key"></i>
                </div>
                <h1>Reset Your Password</h1>
                <p>Choose how you want to reset your password</p>
            </div>
            
            <div class="card-body">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i> 
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> 
                        <span><?php echo htmlspecialchars($success); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if (empty($success)): ?>
                <form method="POST" action="" id="forgotForm" novalidate>
                    <div class="form-group">
                        <label class="form-label" for="email">
                            <i class="fas fa-envelope"></i> Email Address
                        </label>
                        <div class="input-group">
                            <i class="fas fa-envelope input-icon"></i>
                            <input type="email" 
                                   class="form-control" 
                                   id="email" 
                                   name="email" 
                                   placeholder="Enter your registered email" 
                                   required
                                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                                   autocomplete="email"
                                   autocapitalize="off"
                                   autocorrect="off"
                                   spellcheck="false">
                        </div>
                    </div>
                    
                    <div class="reset-methods">
                        <h3><i class="fas fa-shield-alt"></i> Choose Reset Method</h3>
                        <div class="method-options">
                            <div class="method-option">
                                <input type="radio" 
                                       name="reset_method" 
                                       value="link" 
                                       id="method-link" 
                                       class="method-radio" 
                                       checked>
                                <label for="method-link" class="method-label">
                                    <div class="method-icon">
                                        <i class="fas fa-link"></i>
                                    </div>
                                    <div class="method-title">Reset Link</div>
                                    <div class="method-desc">Receive a secure link in your email (expires in 1 hour)</div>
                                </label>
                            </div>
                            <div class="method-option">
                                <input type="radio" 
                                       name="reset_method" 
                                       value="otp" 
                                       id="method-otp" 
                                       class="method-radio">
                                <label for="method-otp" class="method-label">
                                    <div class="method-icon">
                                        <i class="fas fa-mobile-alt"></i>
                                    </div>
                                    <div class="method-title">OTP Code</div>
                                    <div class="method-desc">Receive a 6-digit code via email (expires in 10 minutes)</div>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            <strong>Security Notice:</strong> For security purposes, reset links and OTP codes expire after a limited time. Make sure to check your spam folder if you don't see the email in your inbox.
                        </div>
                    </div>
                    
                    <button type="submit" class="btn" id="submitBtn">
                        <span id="btnText">Send Reset Instructions</span>
                        <i class="fas fa-paper-plane"></i>
                        <div class="loading" id="loadingSpinner"></div>
                    </button>
                    
                    <?php if (DEBUG_MODE && isset($_SESSION['debug_info'])): ?>
                    <div class="debug-info">
                        <strong>Debug Info:</strong><br>
                        <?php echo htmlspecialchars($_SESSION['debug_info']); ?>
                        <?php unset($_SESSION['debug_info']); ?>
                    </div>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div>
            
            <div class="card-footer">
                <a href="login.php">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('forgotForm');
            const submitBtn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            const loadingSpinner = document.getElementById('loadingSpinner');
            const emailInput = document.getElementById('email');
            
            if (emailInput) {
                emailInput.focus();
                
                form.addEventListener('submit', function(e) {
                    if (!emailInput.value.trim()) {
                        e.preventDefault();
                        emailInput.focus();
                        return;
                    }
                    
                    submitBtn.disabled = true;
                    btnText.textContent = 'Sending...';
                    loadingSpinner.style.display = 'block';
                });
            }
        });
    </script>
</body>
</html>

