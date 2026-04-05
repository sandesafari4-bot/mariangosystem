<?php
require_once 'config.php';

$maintenanceMode = getSystemSetting('maintenance_mode', 'off') === 'on';
$isAdmin = isset($_SESSION['user_id'], $_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
if ($maintenanceMode && !$isAdmin) {
    header('Location: maintenance.php');
    exit();
}

$schoolName = getSystemSetting('school_name', SCHOOL_NAME);
$schoolMotto = getSystemSetting('school_motto', 'Mariango Primary School');
$pageTitle = 'Verify Account - ' . $schoolName;
$loginPath = 'login.php';

$error = '';
$success = '';
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$user = null;
$tokenState = 'missing';

function loadVerificationUser(PDO $pdo, string $token): array
{
    if ($token === '') {
        return ['state' => 'missing', 'user' => null];
    }

    $stmt = $pdo->prepare("
        SELECT id, username, full_name, email, role, status, email_verified, verification_token, verification_expires
        FROM users
        WHERE verification_token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return ['state' => 'invalid', 'user' => null];
    }

    if ((int) ($user['email_verified'] ?? 0) === 1) {
        return ['state' => 'verified', 'user' => $user];
    }

    $expiresAt = $user['verification_expires'] ?? null;
    if (!empty($expiresAt) && strtotime((string) $expiresAt) !== false && strtotime((string) $expiresAt) < time()) {
        return ['state' => 'expired', 'user' => $user];
    }

    return ['state' => 'valid', 'user' => $user];
}

$verificationLookup = loadVerificationUser($pdo, $token);
$tokenState = $verificationLookup['state'];
$user = $verificationLookup['user'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($tokenState === 'missing') {
        $error = 'The verification link is incomplete. Please open the full link from your email.';
    } elseif ($tokenState === 'invalid') {
        $error = 'This verification link is invalid. Please contact the administrator for a new verification email.';
    } elseif ($tokenState === 'verified') {
        $success = 'This account has already been verified. You can sign in now.';
    } elseif ($tokenState === 'expired') {
        $error = 'This verification link has expired. Please ask the administrator to resend the verification email.';
    } elseif ($password === '' || $confirmPassword === '') {
        $error = 'Please fill in both password fields.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $updateStmt = $pdo->prepare("
                UPDATE users
                SET password = ?, email_verified = 1, status = 'active',
                    verification_token = NULL, verification_expires = NULL, updated_at = NOW()
                WHERE id = ?
            ");

            if ($updateStmt->execute([$passwordHash, (int) $user['id']])) {
                $success = 'Your account has been verified successfully. You can now sign in with your email or username and new password.';
                $tokenState = 'verified';
            } else {
                $error = 'We could not verify your account right now. Please try again.';
            }
        } catch (Throwable $e) {
            error_log('Account verification error: ' . $e->getMessage());
            $error = 'An unexpected error occurred while verifying your account. Please try again.';
        }
    }
}

$showForm = $tokenState === 'valid' && $success === '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
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

        .verify-container {
            width: 100%;
            max-width: 450px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            position: relative;
            z-index: 1;
        }

        .verify-header {
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

        .verify-body {
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
            .verify-container {
                max-width: 100%;
                margin: 10px;
            }

            .verify-header {
                padding: 30px 20px;
            }

            .verify-body {
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

    <div class="verify-container">
        <!-- Header -->
        <div class="verify-header">
            <div class="logo-wrapper">
                <img src="uploads/logos/<?php echo htmlspecialchars($DYNAMIC_SCHOOL_LOGO); ?>" alt="<?php echo htmlspecialchars($schoolName); ?> Logo" onerror="this.src='logo.png'">
            </div>
            <h1 class="school-name"><?php echo htmlspecialchars($schoolName); ?></h1>
            <p class="school-subtitle"><?php echo htmlspecialchars($schoolMotto !== '' ? $schoolMotto : 'School Management System'); ?></p>
        </div>

        <!-- Body -->
        <div class="verify-body">
            <!-- Status Section -->
            <?php if ($tokenState === 'verified' || $success):?>
                <div class="status-section">
                    <div class="status-icon" style="color: #27ae60;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="status-title">Account Verified</div>
                    <div class="status-desc">Your email has been confirmed successfully</div>
                </div>
            <?php elseif ($tokenState === 'expired'):?>
                <div class="status-section">
                    <div class="status-icon" style="color: #f39c12;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="status-title">Link Expired</div>
                    <div class="status-desc">This verification link is no longer valid</div>
                </div>
            <?php elseif ($tokenState === 'invalid' || $tokenState === 'missing'):?>
                <div class="status-section">
                    <div class="status-icon" style="color: #e74c3c;">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="status-title">Link Not Valid</div>
                    <div class="status-desc">We couldn't validate this verification request</div>
                </div>
            <?php else:?>
                <div class="status-section">
                    <div class="status-icon" style="color: #667eea;">
                        <i class="fas fa-envelope-open-text"></i>
                    </div>
                    <div class="status-title">Verify Your Account</div>
                    <div class="status-desc">Set a secure password to activate your account</div>
                </div>
            <?php endif;?>

            <!-- Alerts -->
            <?php if ($error):?>
                <div class="alert alert-error">
                    <i class="fas fa-circle-exclamation"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                </div>
            <?php endif;?>

            <?php if ($success):?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo htmlspecialchars($success); ?></div>
                </div>
            <?php endif;?>

            <!-- Verification Form -->
            <?php if ($showForm):?>
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
                            <div class="strength-item" id="matchCheck">
                                <i class="fas fa-check-circle"></i>
                                <span>Passwords match</span>
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
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-user-check"></i>
                        <span>Verify Account & Activate</span>
                    </button>

                    <a href="<?php echo htmlspecialchars($loginPath); ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to Sign In</span>
                    </a>

                    <div class="info-section">
                        <p><strong>Password Requirements:</strong></p>
                        <p>• Minimum of 8 characters<br>• Use a mix of numbers, letters, and symbols for better security</p>
                    </div>
                </form>

            <!-- Success State -->
            <?php elseif ($tokenState === 'verified' || $success):?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div>Your account is now active and ready to use!</div>
                </div>

                <a href="<?php echo htmlspecialchars($loginPath); ?>" class="btn btn-primary">
                    <i class="fas fa-right-to-bracket"></i>
                    <span>Go to Sign In</span>
                </a>

                <a href="<?php echo htmlspecialchars($loginPath); ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Sign In</span>
                </a>

                <div class="info-section">
                    <p><strong>Next Steps:</strong></p>
                    <p>Use your email or username with your new password to sign in to the system.</p>
                </div>

            <!-- Expired State -->
            <?php elseif ($tokenState === 'expired'):?>
                <div class="alert alert-error">
                    <i class="fas fa-clock"></i>
                    <div>This verification link expired. Request a fresh verification email from the administrator.</div>
                </div>

                <a href="<?php echo htmlspecialchars($loginPath); ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Sign In</span>
                </a>

                <div class="info-section">
                    <p><strong>Need Help?</strong></p>
                    <p>Contact your administrator to request a new verification email.</p>
                </div>

            <!-- Invalid Token State -->
            <?php else:?>
                <div class="alert alert-error">
                    <i class="fas fa-circle-exclamation"></i>
                    <div><?php echo $tokenState === 'missing' ? 'The verification link is incomplete.' : 'This verification link is invalid.'; ?></div>
                </div>

                <a href="<?php echo htmlspecialchars($loginPath); ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Sign In</span>
                </a>

                <div class="info-section">
                    <p><strong>What to do:</strong></p>
                    <p>
                        • Check that you opened the complete link from your email<br>
                        • Request a new verification email from admin if needed<br>
                        • Copy and paste the full URL from your email
                    </p>
                </div>
            <?php endif;?>

            <div class="meta">
                &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($schoolName); ?>. Access is restricted to verified users.
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
        const matchCheck = document.getElementById('matchCheck');

        function updatePasswordChecks() {
            if (!passwordInput || !confirmInput || !lengthCheck || !matchCheck) return;

            // Check password length
            if (passwordInput.value.length >= 8) {
                lengthCheck.classList.add('valid');
            } else {
                lengthCheck.classList.remove('valid');
            }

            // Check password match
            if (passwordInput.value !== '' && passwordInput.value === confirmInput.value) {
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
