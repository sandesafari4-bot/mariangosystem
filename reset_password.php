<?php
session_start();
require_once 'config.php';

$schoolName = getSystemSetting('school_name', SCHOOL_NAME);
$schoolMotto = getSystemSetting('school_motto', 'Mariango Primary School');
$error = '';
$success = '';
$page_title = "Reset Password - " . $schoolName;

// Get token from URL
$token = $_GET['token'] ?? '';

if (!$token) {
    header('Location: forgot_password.php');
    exit();
}

// Check if token is valid on page load
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Debug: Log the token
        error_log("GET Token from URL: " . $token);
        
        $stmt = $pdo->prepare("SELECT id, reset_token FROM users WHERE reset_token = ? AND status = 'active'");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $error = "Invalid reset token. Please request a new password reset.";
            error_log("Token not found in database: " . $token);
        } else {
            error_log("Token found for user ID: " . $user['id']);
        }
    } catch (Exception $e) {
        $error = "An error occurred. Please try again.";
        error_log("Reset password token check error: " . $e->getMessage());
    }
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $submitted_token = $_POST['token'] ?? '';
    
    // Debug: Log POST data
    error_log("POST Data - Token: $submitted_token, Password length: " . strlen($password));
    
    // Validate inputs
    if (empty($password) || empty($confirm_password)) {
        $error = "Please fill in all fields";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } else {
        try {
            // First, let's debug what's in the database
            $debug_stmt = $pdo->prepare("SELECT id, reset_token, reset_expires FROM users WHERE reset_token IS NOT NULL");
            $debug_stmt->execute();
            $all_tokens = $debug_stmt->fetchAll();
            error_log("All tokens in database: " . print_r($all_tokens, true));
            
            // Now check for our specific token
            $stmt = $pdo->prepare("SELECT id, reset_token, reset_expires FROM users WHERE reset_token = ? AND status = 'active'");
            $stmt->execute([$submitted_token]);
            $user = $stmt->fetch();
            
            error_log("Looking for token: " . $submitted_token);
            error_log("Found user: " . print_r($user, true));
            
            if (!$user) {
                $error = "Invalid reset token. Please request a new password reset.";
                error_log("Token not found: " . $submitted_token);
            } else {
                // Check expiration - compare with current database time
                $check_expiry = $pdo->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW() AND status = 'active'");
                $check_expiry->execute([$submitted_token]);
                $valid_user = $check_expiry->fetch();
                
                if (!$valid_user) {
                    // Token exists but expired
                    $check_expired = $pdo->prepare("SELECT reset_expires FROM users WHERE reset_token = ?");
                    $check_expired->execute([$submitted_token]);
                    $expiry_data = $check_expired->fetch();
                    
                    error_log("Token expired. Expiry time: " . ($expiry_data['reset_expires'] ?? 'Unknown'));
                    error_log("Current DB time: " . $pdo->query("SELECT NOW()")->fetchColumn());
                    
                    $error = "Reset token has expired. Please request a new password reset.";
                    
                    // Clear expired token
                    $clear_stmt = $pdo->prepare("UPDATE users SET reset_token = NULL, reset_expires = NULL WHERE id = ?");
                    $clear_stmt->execute([$user['id']]);
                } else {
                    // Token is valid - reset password
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Update password and clear reset token
                    $update_stmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
                    
                    if ($update_stmt->execute([$hashed_password, $valid_user['id']])) {
                        $success = "Password has been reset successfully! You can now login with your new password.";
                        $token = ''; // Clear token
                        
                        // Also log the activity
                        $log_stmt = $pdo->prepare("INSERT INTO login_activity (user_id, ip_address, user_agent, login_method) VALUES (?, ?, ?, 'password_reset')");
                        $log_stmt->execute([$valid_user['id'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
                    } else {
                        $error = "Failed to update password. Please try again.";
                    }
                }
            }
        } catch (Exception $e) {
            $error = "An error occurred. Please try again.";
            error_log("Reset password error: " . $e->getMessage());
        }
    }
}
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
            padding: 20px;
        }

        .bg-elements {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            overflow: hidden;
            z-index: 0;
        }

        .bg-element {
            position: absolute;
            border-radius: 50%;
            opacity: 0.1;
        }

        .bg-element:nth-child(1) {
            width: 400px;
            height: 400px;
            top: -100px;
            left: -100px;
            background: #fff;
        }

        .bg-element:nth-child(2) {
            width: 300px;
            height: 300px;
            bottom: -50px;
            right: -50px;
            background: #fff;
        }

        .reset-container {
            width: 100%;
            max-width: 450px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            position: relative;
            z-index: 1;
        }

        .reset-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }

        .logo-wrapper {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }

        .logo-wrapper img {
            width: 70px;
            height: 70px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.15);
            padding: 8px;
            object-fit: cover;
        }

        .school-name {
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 5px;
            letter-spacing: -0.5px;
        }

        .school-subtitle {
            font-size: 0.9rem;
            opacity: 0.9;
            margin: 0;
        }

        .reset-body {
            padding: 40px 30px;
        }

        .status-section {
            text-align: center;
            margin-bottom: 30px;
        }

        .status-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            display: inline-block;
        }

        .status-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .status-desc {
            font-size: 0.9rem;
            color: #7f8c8d;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 0.9rem;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .alert i {
            font-size: 1.1rem;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-info {
            background: #cce5ff;
            color: #004085;
            border: 1px solid #b8daff;
        }

        .form-group {
            margin-bottom: 22px;
            position: relative;
        }

        .form-label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
            letter-spacing: 0.3px;
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #7f8c8d;
            font-size: 1rem;
            pointer-events: none;
            z-index: 1;
        }

        .form-input {
            width: 100%;
            padding: 12px 45px 12px 45px;
            border: 2px solid #e0e6ed;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: white;
            font-family: 'Segoe UI', sans-serif;
        }

        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .floating-label {
            position: absolute;
            left: 45px;
            top: 50%;
            transform: translateY(-50%);
            color: #7f8c8d;
            font-size: 0.9rem;
            pointer-events: none;
            transition: all 0.25s ease;
            background: white;
            padding: 0 4px;
        }

        .form-input:focus ~ .floating-label,
        .form-input:not(:placeholder-shown) ~ .floating-label {
            top: -8px;
            transform: translateY(-50%);
            font-size: 0.75rem;
            color: #667eea;
            font-weight: 700;
            background: white;
        }

        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #7f8c8d;
            cursor: pointer;
            font-size: 1rem;
            padding: 6px;
            transition: color 0.2s ease;
            z-index: 2;
        }

        .toggle-password:hover {
            color: #667eea;
        }

        .form-input:focus ~ .toggle-password {
            color: #667eea;
        }

        .password-strength {
            display: grid;
            gap: 8px;
            margin-top: 12px;
            padding: 14px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .strength-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
            color: #7f8c8d;
        }

        .strength-item i {
            width: 16px;
            text-align: center;
            color: #bdc3c7;
        }

        .strength-item.valid {
            color: #27ae60;
        }

        .strength-item.valid i {
            color: #27ae60;
        }

        .btn {
            width: 100%;
            padding: 12px 16px;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            letter-spacing: 0.3px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            margin-bottom: 12px;
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.25);
        }

        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px rgba(102, 126, 234, 0.35);
        }

        .btn-primary:active:not(:disabled) {
            transform: translateY(0);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-secondary {
            background: #ecf0f1;
            color: #2c3e50;
            margin-bottom: 12px;
        }

        .btn-secondary:hover {
            background: #d5dbdb;
            transform: translateY(-2px);
        }

        .info-section {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
            margin-top: 20px;
            font-size: 0.85rem;
            color: #5a6c7d;
            line-height: 1.6;
            border-left: 4px solid #667eea;
        }

        .info-section p {
            margin: 0 0 8px 0;
        }

        .info-section p:last-child {
            margin: 0;
        }

        .info-section strong {
            color: #2c3e50;
        }

        .meta {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e8ecf3;
            font-size: 0.8rem;
            color: #7f8c8d;
        }

        .meta a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        @media (max-width: 600px) {
            .reset-container {
                max-width: 100%;
                margin: 10px;
            }

            .reset-header {
                padding: 30px 20px;
            }

            .reset-body {
                padding: 30px 20px;
            }

            .school-name {
                font-size: 1.4rem;
            }

            .status-icon {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="bg-elements">
        <div class="bg-element"></div>
        <div class="bg-element"></div>
    </div>

    <?php include 'loader.php'; ?>

    <div class="reset-container">
        <!-- Header -->
        <div class="reset-header">
            <div class="logo-wrapper">
                <img src="uploads/logos/<?php echo htmlspecialchars($DYNAMIC_SCHOOL_LOGO); ?>" alt="<?php echo htmlspecialchars($schoolName); ?> Logo" onerror="this.src='logo.png'">
            </div>
            <h1 class="school-name"><?php echo htmlspecialchars($schoolName); ?></h1>
            <p class="school-subtitle"><?php echo htmlspecialchars($schoolMotto !== '' ? $schoolMotto : 'School Management System'); ?></p>
        </div>

        <!-- Body -->
        <div class="reset-body">
            <!-- Status Section -->
            <div class="status-section">
                <div class="status-icon" style="color: #667eea;">
                    <i class="fas fa-key"></i>
                </div>
                <div class="status-title">Reset Your Password</div>
                <div class="status-desc">Create a new secure password to regain access</div>
            </div>
            <!-- Alerts -->
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-circle-exclamation"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo htmlspecialchars($success); ?></div>
                </div>
            <?php endif; ?>

            <!-- Reset Form -->
            <?php if (!$error && $token): ?>
                <form method="POST" action="">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <div class="input-group">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" id="password" name="password" class="form-input" placeholder=" " required>
                            <span class="floating-label">Enter a secure password</span>
                            <button type="button" class="toggle-password" onclick="togglePasswordVisibility('password')" aria-label="Toggle password visibility">
                                <i class="fas fa-eye-slash"></i>
                            </button>
                        </div>
                        <div class="password-strength">
                            <div class="strength-item" id="lengthCheck">
                                <i class="fas fa-check-circle"></i>
                                <span>At least 8 characters</span>
                            </div>
                            <div class="strength-item" id="uppercaseCheck">
                                <i class="fas fa-check-circle"></i>
                                <span>One uppercase letter</span>
                            </div>
                            <div class="strength-item" id="lowercaseCheck">
                                <i class="fas fa-check-circle"></i>
                                <span>One lowercase letter</span>
                            </div>
                            <div class="strength-item" id="numberCheck">
                                <i class="fas fa-check-circle"></i>
                                <span>One number</span>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Confirm Password</label>
                        <div class="input-group">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-input" placeholder=" " required>
                            <span class="floating-label">Confirm your password</span>
                            <button type="button" class="toggle-password" onclick="togglePasswordVisibility('confirm_password')" aria-label="Toggle password visibility">
                                <i class="fas fa-eye-slash"></i>
                            </button>
                        </div>
                        <div class="password-strength" style="margin-top: 8px;">
                            <div class="strength-item" id="matchCheck">
                                <i class="fas fa-check-circle"></i>
                                <span>Passwords match</span>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        <span>Reset Password</span>
                    </button>

                    <a href="login.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to Sign In</span>
                    </a>

                    <div class="info-section">
                        <p><strong>Password Requirements:</strong></p>
                        <p>• Minimum of 8 characters<br>• At least one uppercase letter<br>• At least one lowercase letter<br>• At least one number</p>
                    </div>
                </form>

            <!-- Success State -->
            <?php elseif ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div>Your password has been reset successfully!</div>
                </div>

                <a href="login.php" class="btn btn-primary">
                    <i class="fas fa-right-to-bracket"></i>
                    <span>Go to Sign In</span>
                </a>

                <a href="login.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Sign In</span>
                </a>

                <div class="info-section">
                    <p><strong>Next Steps:</strong></p>
                    <p>Use your email or username with your new password to sign in to the system.</p>
                </div>

            <!-- Error State -->
            <?php else: ?>
                <a href="forgot_password.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    <span>Request New Reset Link</span>
                </a>

                <a href="login.php" class="btn btn-secondary">
                    <i class="fas fa-left-to-bracket"></i>
                    <span>Back to Sign In</span>
                </a>

                <div class="info-section">
                    <p><strong>Need Help?</strong></p>
                    <p>If your reset link expired or is invalid, request a new one.</p>
                </div>
            <?php endif; ?>

            <div class="meta">
                &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($schoolName); ?>. Access is restricted to active users.
            </div>
        </div>
        </div>
    <script>
        // Password visibility toggle
        function togglePasswordVisibility(fieldId) {
            const field = document.getElementById(fieldId);
            const button = field.parentElement.querySelector('.toggle-password');
            const icon = button.querySelector('i');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            }
        }

        // Password validation
        const passwordInput = document.getElementById('password');
        const confirmInput = document.getElementById('confirm_password');
        const lengthCheck = document.getElementById('lengthCheck');
        const uppercaseCheck = document.getElementById('uppercaseCheck');
        const lowercaseCheck = document.getElementById('lowercaseCheck');
        const numberCheck = document.getElementById('numberCheck');
        const matchCheck = document.getElementById('matchCheck');

        function updatePasswordChecks() {
            if (!passwordInput || !confirmInput) return;

            const password = passwordInput.value;

            // Check password length
            if (password.length >= 8) {
                lengthCheck.classList.add('valid');
            } else {
                lengthCheck.classList.remove('valid');
            }

            // Check for uppercase
            if (/[A-Z]/.test(password)) {
                uppercaseCheck.classList.add('valid');
            } else {
                uppercaseCheck.classList.remove('valid');
            }

            // Check for lowercase
            if (/[a-z]/.test(password)) {
                lowercaseCheck.classList.add('valid');
            } else {
                lowercaseCheck.classList.remove('valid');
            }

            // Check for number
            if (/[0-9]/.test(password)) {
                numberCheck.classList.add('valid');
            } else {
                numberCheck.classList.remove('valid');
            }

            // Check password match
            if (password !== '' && password === confirmInput.value) {
                matchCheck.classList.add('valid');
            } else {
                matchCheck.classList.remove('valid');
            }
        }

        if (passwordInput && confirmInput) {
            passwordInput.addEventListener('input', updatePasswordChecks);
            confirmInput.addEventListener('input', updatePasswordChecks);
        }
    </script>
</body>
</html>