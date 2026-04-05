<?php
include 'config.php';

if (systemSetupRequired()) {
    header('Location: setup_wizard.php');
    exit();
}

function googleCallbackDashboardPath($role) {
    switch ((string) $role) {
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

function googleCallbackSetSession(array $user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email'] = isset($user['email']) ? $user['email'] : '';
}

function googleCallbackRecordActivity(PDO $pdo, $userId, $method) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO login_activity (user_id, ip_address, user_agent, login_method)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            (int) $userId,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $method,
        ]);
    } catch (Throwable $e) {
        error_log('Failed to log Google callback activity: ' . $e->getMessage());
    }
}

function googleCallbackErrorRedirect($message) {
    $target = 'login.php?error=' . urlencode($message);
    header('Location: ' . $target);
    exit();
}

function googleCallbackFetchJson($url, array $postFields = null) {
    $response = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if ($postFields !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        }

        $response = curl_exec($ch);
        curl_close($ch);
    }

    if ($response === false) {
        $contextOptions = [
            'http' => [
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ];

        if ($postFields !== null) {
            $contextOptions['http']['method'] = 'POST';
            $contextOptions['http']['header'] = "Content-type: application/x-www-form-urlencoded\r\n";
            $contextOptions['http']['content'] = http_build_query($postFields);
        }

        $context = stream_context_create($contextOptions);
        $response = @file_get_contents($url, false, $context);
    }

    if ($response === false || $response === '') {
        return null;
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

function googleCallbackVerifyIdToken($idToken, $expectedClientId) {
    if ($idToken === '' || $expectedClientId === '') {
        return null;
    }

    $payload = googleCallbackFetchJson(
        'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken)
    );

    if (!is_array($payload)) {
        return null;
    }

    $audience = isset($payload['aud']) ? (string) $payload['aud'] : '';
    $email = isset($payload['email']) ? (string) $payload['email'] : '';
    $emailVerified = $payload['email_verified'] ?? false;

    if ($audience !== $expectedClientId) {
        return null;
    }

    if ($email === '' || ($emailVerified !== true && $emailVerified !== 'true')) {
        return null;
    }

    return $payload;
}

function googleCallbackExchangeCode($code, $clientId, $clientSecret, $redirectUri) {
    return googleCallbackFetchJson('https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
    ]);
}

function googleCallbackFinalizeLogin(PDO $pdo, array $payload, $method, $maintenanceMode) {
    $email = filter_var((string) ($payload['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    if ($email === '') {
        googleCallbackErrorRedirect('Google sign-in did not return a valid email address.');
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        googleCallbackErrorRedirect('No active system account is linked to that Google email.');
    }

    if (($user['status'] ?? '') !== 'active') {
        googleCallbackErrorRedirect('This account is inactive. Please contact the administrator.');
    }

    if ($maintenanceMode && ($user['role'] ?? '') !== 'admin') {
        header('Location: maintenance.php');
        exit();
    }

    googleCallbackSetSession($user);
    googleCallbackRecordActivity($pdo, (int) $user['id'], $method);
    header('Location: ' . googleCallbackDashboardPath($user['role'] ?? ''));
    exit();
}

$googleClientId = trim((string) getSystemSetting('google_client_id', envValue('GOOGLE_CLIENT_ID', '')));
$googleClientSecret = trim((string) getSystemSetting('google_client_secret', envValue('GOOGLE_CLIENT_SECRET', '')));
$googleLoginEnabled = getSystemSetting('google_login_enabled', '0') === '1' && $googleClientId !== '';
$maintenanceMode = getSystemSetting('maintenance_mode', 'off') === 'on';
$redirectUri = buildApplicationUrl('google_callback.php');

if (!$googleLoginEnabled) {
    googleCallbackErrorRedirect('Google login is not configured yet.');
}

if (isset($_SESSION['user_id'])) {
    header('Location: ' . googleCallbackDashboardPath($_SESSION['user_role'] ?? ''));
    exit();
}

if (!empty($_GET['error'])) {
    $errorText = str_replace('_', ' ', (string) $_GET['error']);
    googleCallbackErrorRedirect('Google sign-in failed: ' . $errorText);
}

$idToken = trim((string) ($_POST['credential'] ?? $_POST['google_credential'] ?? $_POST['id_token'] ?? $_GET['id_token'] ?? ''));
$authCode = trim((string) ($_GET['code'] ?? $_POST['code'] ?? ''));

if ($idToken !== '') {
    $payload = googleCallbackVerifyIdToken($idToken, $googleClientId);
    if (!$payload) {
        googleCallbackErrorRedirect('Google sign-in verification failed. Please try again.');
    }

    googleCallbackFinalizeLogin($pdo, $payload, 'google_callback_token', $maintenanceMode);
}

if ($authCode !== '') {
    if ($googleClientSecret === '') {
        googleCallbackErrorRedirect('Google Client Secret is missing. Add it before using redirect-based Google login.');
    }

    $tokenResponse = googleCallbackExchangeCode($authCode, $googleClientId, $googleClientSecret, $redirectUri);
    if (!is_array($tokenResponse)) {
        googleCallbackErrorRedirect('Google token exchange failed. Please try again.');
    }

    $returnedIdToken = isset($tokenResponse['id_token']) ? (string) $tokenResponse['id_token'] : '';
    $payload = $returnedIdToken !== '' ? googleCallbackVerifyIdToken($returnedIdToken, $googleClientId) : null;

    if (!$payload && !empty($tokenResponse['access_token'])) {
        $userInfo = googleCallbackFetchJson(
            'https://www.googleapis.com/oauth2/v3/userinfo?access_token=' . urlencode((string) $tokenResponse['access_token'])
        );

        if (is_array($userInfo)) {
            $payload = $userInfo;
        }
    }

    if (!$payload) {
        googleCallbackErrorRedirect('Google login completed, but user details could not be verified.');
    }

    googleCallbackFinalizeLogin($pdo, $payload, 'google_callback_code', $maintenanceMode);
}

googleCallbackErrorRedirect('No Google login response was received.');
