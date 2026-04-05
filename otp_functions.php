<?php
// otp_functions.php

function generateOTP($length = 6) {
    $digits = '0123456789';
    $otp = '';
    for ($i = 0; $i < $length; $i++) {
        $otp .= $digits[random_int(0, 9)]; // Use random_int for better randomness
    }
    return $otp;
}

function sendOTPEmail($email, $otp, $user_name = 'User') {
    // Check if PHPMailer exists
    $phpmailer_path = __DIR__ . '/PHPMailer/PHPMailer.php';
    if (!file_exists($phpmailer_path)) {
        error_log("PHPMailer not found at: $phpmailer_path");
        return false;
    }

    require_once __DIR__ . '/PHPMailer/Exception.php';
    require_once __DIR__ . '/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = defined('SMTP_USERNAME') ? SMTP_USERNAME : 'festuskatana087@gmail.com';
        $mail->Password = defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = defined('SMTP_PORT') ? SMTP_PORT : 587;
        $mail->SMTPDebug = 0; // Set to 2 for debugging
        $mail->Timeout = 30;

        // Recipients
        $fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : (defined('SMTP_USERNAME') ? SMTP_USERNAME : 'no-reply@example.com');
        $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : (defined('SCHOOL_NAME') ? SCHOOL_NAME . ' System' : 'System');

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($email, $user_name);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Your Login OTP Code - ' . (defined('SCHOOL_NAME') ? SCHOOL_NAME : 'System');
        
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; text-align: center;'>
                    <h1 style='color: white; margin: 0;'>" . SCHOOL_NAME . "</h1>
                </div>
                <div style='padding: 30px; background-color: #f9f9f9;'>
                    <h2 style='color: #333;'>Hello " . htmlspecialchars($user_name) . ",</h2>
                    <p style='color: #666; font-size: 16px; line-height: 1.6;'>
                        Your One-Time Password (OTP) for login is:
                    </p>
                    <div style='background: white; border: 2px dashed #667eea; padding: 20px; text-align: center; margin: 20px 0;'>
                        <h1 style='color: #667eea; margin: 0; font-size: 32px; letter-spacing: 10px;'>" . $otp . "</h1>
                    </div>
                    <p style='color: #666; font-size: 14px;'>
                        <strong>This OTP is valid for 10 minutes.</strong><br>
                        Do not share this code with anyone.
                    </p>
                    <p style='color: #999; font-size: 12px; margin-top: 30px;'>
                        If you didn't request this OTP, please ignore this email or contact support immediately.
                    </p>
                </div>
                <div style='background-color: #f1f1f1; padding: 20px; text-align: center;'>
                    <p style='color: #999; font-size: 12px; margin: 0;'>
                        &copy; " . date('Y') . " " . SCHOOL_NAME . ". All rights reserved.
                    </p>
                </div>
            </div>
        ";
        
        $mail->AltBody = "Your OTP for " . SCHOOL_NAME . " login is: $otp\nThis OTP is valid for 10 minutes. Do not share this code.";
        
        return $mail->send();

    } catch (Exception $e) {
        error_log("Email could not be sent. Error: {$mail->ErrorInfo} Exception: " . $e->getMessage());
        return false;
    }
}

// Backwards-compatible wrapper used by password-reset pages
function sendPasswordResetOTP($email, $otp, $user_name = 'User') {
    return sendOTPEmail($email, $otp, $user_name);
}

// Send a password reset link email
function sendPasswordResetLink($email, $reset_link, $user_name = 'User') {
    // Use same PHPMailer path resolution as sendOTPEmail
    $phpmailer_path = __DIR__ . '/PHPMailer/PHPMailer.php';
    if (!file_exists($phpmailer_path)) {
        error_log("PHPMailer not found at: $phpmailer_path");
        return false;
    }
    require_once __DIR__ . '/PHPMailer/Exception.php';
    require_once __DIR__ . '/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = defined('SMTP_USERNAME') ? SMTP_USERNAME : '';
        $mail->Password = defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = defined('SMTP_PORT') ? SMTP_PORT : 587;
        $mail->SMTPDebug = 0;
        $mail->Timeout = 30;

        $fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : (defined('SMTP_USERNAME') ? SMTP_USERNAME : 'no-reply@example.com');
        $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : (defined('SCHOOL_NAME') ? SCHOOL_NAME . ' System' : 'System');

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($email, $user_name);

        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Link - ' . (defined('SCHOOL_NAME') ? SCHOOL_NAME : 'System');

        $mail->Body = "\n            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>\n                <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; text-align: center;'>\n                    <h1 style='color: white; margin: 0;'>" . (defined('SCHOOL_NAME') ? SCHOOL_NAME : '') . "</h1>\n                </div>\n                <div style='padding: 30px; background-color: #f9f9f9;'>\n                    <h2 style='color: #333;'>Hello " . htmlspecialchars($user_name) . ",</h2>\n                    <p style='color: #666; font-size: 16px; line-height: 1.6;'>\n                        You requested to reset your password. Click the button below to create a new password.\n                    </p>\n                    <div style='text-align: center; margin: 20px 0;'>\n                        <a href='" . $reset_link . "' style='display:inline-block;padding:14px 22px;background:#667eea;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;'>Reset Password</a>\n                    </div>\n                    <p style='color: #999; font-size: 12px; margin-top: 30px;'>\n                        If the button doesn't work, copy and paste the following URL into your browser:<br>\n                        <small>" . $reset_link . "</small>\n                    </p>\n                </div>\n                <div style='background-color: #f1f1f1; padding: 20px; text-align: center;'>\n                    <p style='color: #999; font-size: 12px; margin: 0;'>\n                        &copy; " . date('Y') . " " . (defined('SCHOOL_NAME') ? SCHOOL_NAME : '') . ". All rights reserved.\n                    </p>\n                </div>\n            </div>\n        ";

        $mail->AltBody = "Reset your password using the following link: $reset_link";

        return $mail->send();

    } catch (Exception $e) {
        error_log("Reset link email could not be sent. Error: {$mail->ErrorInfo} Exception: " . $e->getMessage());
        return false;
    }
}

function verifyOTP($pdo, $email, $otp) {
    // First check if OTP exists and is not expired
    $stmt = $pdo->prepare("
        SELECT * FROM users 
        WHERE email = ? 
        AND otp_code = ? 
        AND otp_expires_at > NOW() 
        AND status = 'active'
        AND otp_attempts < 5
    ");
    
    if (!$stmt->execute([$email, $otp])) {
        error_log("OTP verification query failed: " . implode(', ', $stmt->errorInfo()));
        return false;
    }
    
    $user = $stmt->fetch();
    
    if ($user) {
        // Clear OTP after successful verification
        $clearStmt = $pdo->prepare("
            UPDATE users 
            SET otp_code = NULL, 
                otp_expires_at = NULL, 
                otp_attempts = 0,
                last_login = NOW()
            WHERE id = ?
        ");
        
        if (!$clearStmt->execute([$user['id']])) {
            error_log("Failed to clear OTP: " . implode(', ', $clearStmt->errorInfo()));
        }
        
        return $user;
    }
    
    // Increment failed attempts
    $attemptStmt = $pdo->prepare("
        UPDATE users 
        SET otp_attempts = otp_attempts + 1 
        WHERE email = ?
    ");
    
    if (!$attemptStmt->execute([$email])) {
        error_log("Failed to increment OTP attempts: " . implode(', ', $attemptStmt->errorInfo()));
    }
    
    return false;
}

function clearOTP($pdo, $email) {
    $stmt = $pdo->prepare("
        UPDATE users 
        SET otp_code = NULL, 
            otp_expires_at = NULL, 
            otp_attempts = 0 
        WHERE email = ?
    ");
    
    return $stmt->execute([$email]);
}

// Debug function to check database status
function checkOTPStatus($pdo, $email) {
    $stmt = $pdo->prepare("
        SELECT 
            email,
            otp_code,
            otp_expires_at,
            otp_attempts,
            NOW() as current_time,
            TIMESTAMPDIFF(SECOND, NOW(), otp_expires_at) as seconds_remaining
        FROM users 
        WHERE email = ?
    ");
    
    $stmt->execute([$email]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Send account verification email
function sendAccountVerificationEmail($email, $verification_link, $user_name = 'User') {
    // Use same PHPMailer path resolution as sendOTPEmail
    $phpmailer_path = __DIR__ . '/PHPMailer/PHPMailer.php';
    if (!file_exists($phpmailer_path)) {
        error_log("PHPMailer not found at: $phpmailer_path");
        return false;
    }
    require_once __DIR__ . '/PHPMailer/Exception.php';
    require_once __DIR__ . '/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $safeVerificationLink = htmlspecialchars($verification_link, ENT_QUOTES, 'UTF-8');
        $safeUserName = htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8');

        $mail->isSMTP();
        $mail->Host = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = defined('SMTP_USERNAME') ? SMTP_USERNAME : '';
        $mail->Password = defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = defined('SMTP_PORT') ? SMTP_PORT : 587;
        $mail->SMTPDebug = 0;
        $mail->Timeout = 30;

        $fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : (defined('SMTP_USERNAME') ? SMTP_USERNAME : 'no-reply@example.com');
        $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : (defined('SCHOOL_NAME') ? SCHOOL_NAME . ' System' : 'System');

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($email, $user_name);

        $mail->isHTML(true);
        $mail->Subject = 'Verify Your Account - ' . (defined('SCHOOL_NAME') ? SCHOOL_NAME : 'System');

        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; text-align: center;'>
                    <h1 style='color: white; margin: 0;'>" . (defined('SCHOOL_NAME') ? SCHOOL_NAME : '') . "</h1>
                </div>
                <div style='padding: 30px; background-color: #f9f9f9;'>
                    <h2 style='color: #333;'>Hello " . $safeUserName . ",</h2>
                    <p style='color: #666; font-size: 16px; line-height: 1.6;'>
                        Welcome to " . (defined('SCHOOL_NAME') ? SCHOOL_NAME : 'our system') . "! Your account has been created and needs to be verified.
                    </p>
                    <p style='color: #666; font-size: 16px; line-height: 1.6;'>
                        Click the button below to verify your email and set up your password.
                    </p>
                    <table role='presentation' cellspacing='0' cellpadding='0' border='0' align='center' style='margin:20px auto;'>
                        <tr>
                            <td style='border-radius:8px;background:#667eea;text-align:center;'>
                                <a href='" . $verification_link . "' style='display:inline-block;padding:14px 22px;color:#ffffff;text-decoration:none;font-weight:700;font-family:Arial,sans-serif;'>Verify Account and Set Password</a>
                            </td>
                        </tr>
                    </table>
                    <p style='color: #999; font-size: 12px; margin-top: 30px;'>
                        If the button does not work, use this direct verification link:<br>
                        <a href='" . $verification_link . "' style='color:#667eea;word-break:break-all;text-decoration:underline;'>" . $safeVerificationLink . "</a>
                    </p>
                    <p style='color: #666; font-size: 14px;'>
                        <strong>This verification link is valid for 24 hours.</strong>
                    </p>
                </div>
                <div style='background-color: #f1f1f1; padding: 20px; text-align: center;'>
                    <p style='color: #999; font-size: 12px; margin: 0;'>
                        &copy; " . date('Y') . " " . (defined('SCHOOL_NAME') ? SCHOOL_NAME : '') . ". All rights reserved.
                    </p>
                </div>
            </div>
        ";

        $mail->AltBody = "Verify your account and set password using the following link: $verification_link\nThis link is valid for 24 hours.";

        return $mail->send();

    } catch (Exception $e) {
        error_log("Verification email could not be sent. Error: {$mail->ErrorInfo} Exception: " . $e->getMessage());
        return false;
    }
}
?>
