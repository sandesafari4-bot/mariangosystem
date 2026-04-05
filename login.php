<?php
include 'config.php';

if (systemSetupRequired()) {
    header('Location: setup_wizard.php');
    exit();
}

function loginEnsureColumn(PDO $pdo, string $table, string $column, string $definition): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
        return true;
    } catch (Exception $e) {
        error_log("Failed ensuring column {$table}.{$column}: " . $e->getMessage());
        return false;
    }
}

function loginDashboardPath(string $role): string {
    switch ($role) {
        case 'admin':
            return 'admin/dashboard.php';
        case 'teacher':
            return 'teacher/dashboard.php';
        case 'accountant':
            return 'accountant/dashboard.php';
        case 'librarian':
            return 'librarian/dashboard.php';
        default:
            return 'index.php';
    }
}

function loginSetSession(array $user): void {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email'] = $user['email'] ?? '';
}

function loginRememberUser(PDO $pdo, int $userId, bool $rememberMeSupported): void {
    if (!$rememberMeSupported) {
        return;
    }

    $token = bin2hex(random_bytes(32));
    $expiry = time() + (30 * 24 * 60 * 60);

    $stmt = $pdo->prepare("UPDATE users SET remember_token = ?, token_expiry = ? WHERE id = ?");
    $stmt->execute([$token, date('Y-m-d H:i:s', $expiry), $userId]);

    setcookie('remember_token', $token, $expiry, '/');
}

function loginRecordActivity(PDO $pdo, int $userId, string $method = 'password'): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO login_activity (user_id, ip_address, user_agent, login_method)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $method,
        ]);
    } catch (Throwable $e) {
        try {
            $fallback = $pdo->prepare("
                INSERT INTO login_activity (user_id, ip_address, user_agent)
                VALUES (?, ?, ?)
            ");
            $fallback->execute([
                $userId,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]);
        } catch (Throwable $inner) {
            error_log('Failed to log login activity: ' . $inner->getMessage());
        }
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

$hasRememberToken = loginEnsureColumn($pdo, 'users', 'remember_token', "VARCHAR(191) NULL AFTER `last_login`");
$hasTokenExpiry = loginEnsureColumn($pdo, 'users', 'token_expiry', "DATETIME NULL AFTER `remember_token`");
$rememberMeSupported = $hasRememberToken && $hasTokenExpiry;

$googleClientId = trim((string) getSystemSetting('google_client_id', envValue('GOOGLE_CLIENT_ID', '')));
$googleLoginEnabled = getSystemSetting('google_login_enabled', '0') === '1' && $googleClientId !== '';
$googleCallbackUrl = buildApplicationUrl('google_callback.php');
$maintenanceMode = getSystemSetting('maintenance_mode', 'off') === 'on';
$error = '';

if (isset($_SESSION['user_id'])) {
    if ($maintenanceMode && ($_SESSION['user_role'] ?? '') !== 'admin') {
        header('Location: maintenance.php');
    } else {
        header('Location: ' . loginDashboardPath((string) ($_SESSION['user_role'] ?? '')));
    }
    exit();
}

if ($rememberMeSupported && isset($_COOKIE['remember_token']) && !isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE remember_token = ? AND token_expiry > NOW()");
    $stmt->execute([$_COOKIE['remember_token']]);
    $rememberedUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($rememberedUser) {
        if (($rememberedUser['status'] ?? '') !== 'active') {
            setcookie('remember_token', '', time() - 3600, '/');
            $error = 'This account is inactive. Please contact the admin.';
        } elseif ($maintenanceMode && ($rememberedUser['role'] ?? '') !== 'admin') {
            header('Location: maintenance.php');
            exit();
        }

        if (($rememberedUser['status'] ?? '') === 'active') {
            loginSetSession($rememberedUser);
            header('Location: ' . loginDashboardPath((string) ($rememberedUser['role'] ?? '')));
            exit();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['google_credential'])) {
        if (!$googleLoginEnabled) {
            $error = 'Google login is not configured by the admin yet.';
        } else {
            $credential = trim((string) ($_POST['google_credential'] ?? ''));
            $tokenData = verifyGoogleIdToken($credential, $googleClientId);

            if (!$tokenData) {
                $error = 'Google sign-in verification failed. Please try again.';
            } else {
                $email = filter_var((string) ($tokenData['email'] ?? ''), FILTER_SANITIZE_EMAIL);
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    $error = 'No system account is linked to this Google email.';
                } elseif (($user['status'] ?? '') !== 'active') {
                    $error = 'This account is inactive. Please contact the admin.';
                } elseif ($maintenanceMode && ($user['role'] ?? '') !== 'admin') {
                    header('Location: maintenance.php');
                    exit();
                } else {
                    loginSetSession($user);
                    loginRecordActivity($pdo, (int) $user['id'], 'google');
                    header('Location: ' . loginDashboardPath((string) ($user['role'] ?? '')));
                    exit();
                }
            }
        }
    } else {
        $identity = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $rememberMe = isset($_POST['remember_me']);

        $stmt = $pdo->prepare("
            SELECT *
            FROM users
            WHERE (username = ? OR email = ?)
            LIMIT 1
        ");
        $stmt->execute([$identity, $identity]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && ($user['status'] ?? '') !== 'active') {
            $error = 'This account is inactive. Please contact the admin.';
        } elseif ($user && password_verify($password, (string) ($user['password'] ?? ''))) {
            if ($maintenanceMode && ($user['role'] ?? '') !== 'admin') {
                header('Location: maintenance.php');
                exit();
            }

            loginSetSession($user);
            if ($rememberMe) {
                loginRememberUser($pdo, (int) $user['id'], $rememberMeSupported);
            }
            loginRecordActivity($pdo, (int) $user['id'], 'password');
            header('Location: ' . loginDashboardPath((string) ($user['role'] ?? '')));
            exit();
        }

        $error = 'Invalid username, email, or password.';
    }
}

$pageTitle = 'Sign In - ' . getSystemSetting('school_name', SCHOOL_NAME);
$schoolName = getSystemSetting('school_name', SCHOOL_NAME);
$schoolMotto = getSystemSetting('school_motto', 'School Management System');
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
            padding: 1rem;
            position: relative;
            overflow-x: hidden;
        }

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
            animation: float 6s ease-in-out infinite;
        }

        .bg-element:nth-child(1) {
            width: 80px;
            height: 80px;
            top: 10%;
            left: 10%;
            animation-delay: 0s;
        }

        .bg-element:nth-child(2) {
            width: 120px;
            height: 120px;
            top: 70%;
            left: 80%;
            animation-delay: 2s;
        }

        .bg-element:nth-child(3) {
            width: 55px;
            height: 55px;
            top: 35%;
            left: 84%;
            animation-delay: 4s;
        }

        .bg-element:nth-child(4) {
            width: 70px;
            height: 70px;
            top: 82%;
            left: 18%;
            animation-delay: 1s;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }

        .login-container {
            background: rgba(255, 255, 255, 0.97);
            backdrop-filter: blur(15px);
            padding: 1.5rem;
            border-radius: 18px;
            box-shadow: 0 15px 35px -10px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 390px;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2, #667eea);
        }

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
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
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

        .welcome-text {
            text-align: center;
            margin-bottom: 1.1rem;
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
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
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

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 1rem 0;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .remember-me {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            color: #6c757d;
            font-size: 0.84rem;
            font-weight: 600;
        }

        .remember-me input {
            width: 16px;
            height: 16px;
        }

        .action-btn {
            width: 100%;
            border: none;
            border-radius: 12px;
            padding: 0.9rem 1.15rem;
            font-size: 0.95rem;
            font-weight: 700;
            color: white;
            cursor: pointer;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.25);
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            margin: 1rem 0;
            color: #94a3b8;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }

        .google-login-section {
            display: grid;
            gap: 0.7rem;
            justify-items: center;
            margin: 0.1rem 0 0.2rem;
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

        #googleLoginButton {
            min-height: 42px;
            display: flex;
            justify-content: center;
            width: 100%;
        }

        .google-fallback {
            width: 100%;
            justify-content: center;
            background: #fff;
            color: #1f2937;
            border: 1px solid #d0d7e2;
            box-shadow: none;
        }

        .google-fallback:hover {
            background: #f8fafc;
        }

        .alternative-options {
            text-align: center;
            margin-top: 1rem;
        }

        .back-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.85rem;
        }

        .meta {
            margin-top: 1rem;
            text-align: center;
            color: #6c757d;
            font-size: 0.8rem;
            line-height: 1.5;
        }

        @media (max-width: 640px) {
            body { padding: 0.5rem; }
            .login-container { padding: 1.25rem; }
        }
    </style>
</head>
<body>
    <div class="bg-elements">
        <div class="bg-element"></div>
        <div class="bg-element"></div>
        <div class="bg-element"></div>
        <div class="bg-element"></div>
    </div>

    <div class="login-container">
        <div class="logo-section">
            <div class="logo-wrapper">
                <div class="logo">
                    <img src="uploads/logos/<?php echo htmlspecialchars($DYNAMIC_SCHOOL_LOGO); ?>" alt="<?php echo htmlspecialchars($schoolName); ?> Logo" onerror="this.src='logo.png'">
                </div>
            </div>
            <h1 class="school-name"><?php echo htmlspecialchars($schoolName); ?></h1>
            <p class="school-subtitle"><?php echo htmlspecialchars($schoolMotto !== '' ? $schoolMotto : 'School Management System'); ?></p>
        </div>

        <div class="welcome-text">
            <h2>Welcome Back</h2>
            <p>Enter your username or email and password to continue.</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <div class="input-group">
                    <i class="fas fa-user input-icon"></i>
                    <input id="username" type="text" name="username" class="form-input" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" placeholder=" " required autocomplete="username">
                    <span class="floating-label">Username</span>
                </div>
            </div>

            <div class="form-group">
                <div class="input-group">
                    <i class="fas fa-lock input-icon"></i>
                    <input id="password" type="password" name="password" class="form-input" placeholder=" " required autocomplete="current-password">
                    <span class="floating-label">Password</span>
                </div>
            </div>

            <div class="form-options">
                <label class="remember-me">
                    <input type="checkbox" name="remember_me" <?php echo isset($_POST['remember_me']) ? 'checked' : ''; ?>>
                    Remember this device
                </label>
                <a href="forgot_password.php" class="back-link">Forgot password?</a>
            </div>

            <button type="submit" class="action-btn">
                <i class="fas fa-right-to-bracket"></i> Sign In
            </button>
        </form>

        <?php if ($googleLoginEnabled): ?>
            <div class="divider">or</div>
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
        <?php endif; ?>

        <div class="alternative-options">
            <a class="back-link" href="index.php"><i class="fas fa-envelope"></i> Use OTP login</a>
        </div>

        <div class="meta">
            <?php echo date('Y'); ?> <?php echo htmlspecialchars($schoolName); ?>. Access is restricted to active school users.
        </div>
    </div>
    <?php if ($googleLoginEnabled): ?>
        <script src="https://accounts.google.com/gsi/client" async defer></script>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
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

                    setTimeout(function() {
                        if (googleButton.children.length === 0) {
                            googleFallbackButton.style.display = 'inline-flex';
                        }
                    }, 1800);
                }
            });
        </script>
    <?php endif; ?>
</body>
</html>
