<?php
session_start();
include 'config.php';
include 'otp_functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$email = $_POST['email'] ?? '';

if (empty($email)) {
    echo json_encode(['success' => false, 'message' => 'Email is required']);
    exit();
}

// Check if email exists
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit();
}

// Generate new OTP
$otp = generateOTP();
$expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

// Save to database
$stmt = $pdo->prepare("
    UPDATE users 
    SET otp_code = ?, 
        otp_expires_at = ?, 
        otp_attempts = 0,
        otp_created_at = NOW()
    WHERE id = ?
");
$stmt->execute([$otp, $expires_at, $user['id']]);

// Send OTP email
if (sendOTPEmail($email, $otp, $user['full_name'])) {
    $_SESSION['otp_email'] = $email;
    $_SESSION['otp_user_id'] = $user['id'];
    $_SESSION['otp_sent_time'] = time();
    
    echo json_encode([
        'success' => true, 
        'message' => 'OTP resent successfully'
    ]);
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to send OTP. Please try again.'
    ]);
}