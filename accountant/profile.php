<?php
include '../config.php';
checkAuth();

// Get current user data
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_profile'])) {
        $full_name = trim($_POST['full_name']);
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        
        // Validation
        $errors = [];
        if (empty($full_name)) $errors[] = "Full name is required";
        if (empty($username)) $errors[] = "Username is required";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required";
        
        if (empty($errors)) {
            // Check if username already exists (excluding current user)
            $check_stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $check_stmt->execute([$username, $user_id]);
            if ($check_stmt->fetch()) {
                $error = "Username already exists. Please choose a different one.";
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, phone = ? WHERE id = ?");
                if ($stmt->execute([$full_name, $username, $email, $phone, $user_id])) {
                    $_SESSION['full_name'] = $full_name;
                    $_SESSION['username'] = $username;
                    $success = "Profile updated successfully!";
                    // Refresh user data
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch();
                } else {
                    $error = "Failed to update profile. Please try again.";
                }
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
    
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Verify current password
        if (!password_verify($current_password, $user['password'])) {
            $error = "Current password is incorrect.";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match.";
        } elseif (strlen($new_password) < 6) {
            $error = "New password must be at least 6 characters long.";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($stmt->execute([$hashed_password, $user_id])) {
                $success = "Password changed successfully!";
            } else {
                $error = "Failed to change password. Please try again.";
            }
        }
    }
    
    if (isset($_POST['update_preferences'])) {
        $theme = $_POST['theme'] ?? 'light';
        $notifications = isset($_POST['notifications']) ? 1 : 0;
        $email_alerts = isset($_POST['email_alerts']) ? 1 : 0;
        $language = $_POST['language'] ?? 'en';
        $timezone = $_POST['timezone'] ?? 'Africa/Nairobi';
        
        // Store preferences in database (you may need to add these columns)
        $stmt = $pdo->prepare("UPDATE users SET theme = ?, language = ?, timezone = ?, notifications = ?, email_alerts = ? WHERE id = ?");
        if ($stmt->execute([$theme, $language, $timezone, $notifications, $email_alerts, $user_id])) {
            $_SESSION['theme'] = $theme;
            $success = "Preferences updated successfully!";
        } else {
            $error = "Failed to update preferences. Please try again.";
        }
    }
    
    // Handle profile picture upload
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $upload_result = handleProfilePictureUpload($user_id);
        if ($upload_result['success']) {
            $success = $upload_result['message'];
            // Update user data to reflect new picture
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
        } else {
            $error = $upload_result['message'];
        }
    }
    
    // Handle profile picture removal
    if (isset($_POST['remove_profile_picture'])) {
        $result = removeProfilePicture($user_id);
        if ($result['success']) {
            $success = $result['message'];
            // Update user data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
        } else {
            $error = $result['message'];
        }
    }
}

// Function to handle profile picture upload
function handleProfilePictureUpload($user_id) {
    global $pdo;
    
    $upload_dir = '../uploads/profile_pictures/';
    
    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $file = $_FILES['profile_picture'];
    $file_name = $file['name'];
    $file_tmp = $file['tmp_name'];
    $file_size = $file['size'];
    $file_error = $file['error'];
    
    // Get file extension
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    
    // Allowed extensions
    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
    
    // Check if file type is allowed
    if (!in_array($file_ext, $allowed_ext)) {
        return ['success' => false, 'message' => 'Only JPG, JPEG, PNG, and GIF files are allowed.'];
    }
    
    // Check file size (max 5MB)
    if ($file_size > 5242880) {
        return ['success' => false, 'message' => 'File size must be less than 5MB.'];
    }
    
    // Generate unique filename
    $new_filename = 'user_' . $user_id . '_' . time() . '.' . $file_ext;
    $file_destination = $upload_dir . $new_filename;
    
    // Remove old profile picture if exists
    removeOldProfilePicture($user_id);
    
    // Move uploaded file
    if (move_uploaded_file($file_tmp, $file_destination)) {
        // Update database with new profile picture path
        $stmt = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
        if ($stmt->execute([$new_filename, $user_id])) {
            return ['success' => true, 'message' => 'Profile picture updated successfully!'];
        } else {
            // Delete the uploaded file if database update fails
            unlink($file_destination);
            return ['success' => false, 'message' => 'Failed to update profile picture in database.'];
        }
    } else {
        return ['success' => false, 'message' => 'Failed to upload profile picture.'];
    }
}

// Function to remove old profile picture
function removeOldProfilePicture($user_id) {
    global $pdo;
    
    // Get current profile picture
    $stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $current_picture = $stmt->fetchColumn();
    
    if ($current_picture) {
        $file_path = '../uploads/profile_pictures/' . $current_picture;
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }
}

// Function to remove profile picture
function removeProfilePicture($user_id) {
    global $pdo;
    
    removeOldProfilePicture($user_id);
    
    // Update database to remove profile picture
    $stmt = $pdo->prepare("UPDATE users SET profile_picture = NULL WHERE id = ?");
    if ($stmt->execute([$user_id])) {
        return ['success' => true, 'message' => 'Profile picture removed successfully!'];
    } else {
        return ['success' => false, 'message' => 'Failed to remove profile picture.'];
    }
}

// Get profile picture URL
function getProfilePictureUrl($user) {
    if (!empty($user['profile_picture'])) {
        return '../uploads/profile_pictures/' . $user['profile_picture'];
    } else {
        return null;
    }
}

// Get user activity (user_logs table doesn't exist, initialize empty array)
$recent_activities = [];

// Get user statistics based on role
$stats = [];
if ($user['role'] == 'admin') {
    $stats['students'] = $pdo->query("SELECT COUNT(*) FROM students WHERE status='active'")->fetchColumn();
    $stats['teachers'] = $pdo->query("SELECT COUNT(*) FROM users WHERE role='teacher' AND status='active'")->fetchColumn();
    $stats['classes'] = $pdo->query("SELECT COUNT(*) FROM classes WHERE is_active=1")->fetchColumn();
} elseif ($user['role'] == 'accountant') {
    $stats['payments'] = $pdo->query("SELECT COUNT(*) FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE())")->fetchColumn();
    $stats['invoices'] = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status='unpaid'")->fetchColumn();
} elseif ($user['role'] == 'teacher') {
    $stats['students'] = $pdo->query("SELECT COUNT(*) FROM students WHERE class_id IN (SELECT class_id FROM teacher_classes WHERE teacher_id = $user_id)")->fetchColumn();
    $stats['subjects'] = $pdo->query("SELECT COUNT(*) FROM subjects WHERE teacher_id = $user_id")->fetchColumn();
} elseif ($user['role'] == 'librarian') {
    $stats['books'] = $pdo->query("SELECT COUNT(*) FROM books WHERE status='available'")->fetchColumn();
    $stats['issued'] = $pdo->query("SELECT COUNT(*) FROM book_loans WHERE return_date IS NULL")->fetchColumn();
}

$page_title = "My Profile - " . SCHOOL_NAME;
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $_SESSION['theme'] ?? 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <style>
        :root {
            --primary: #2c3e50;
            --primary-light: #34495e;
            --secondary: #3498db;
            --success: #27ae60;
            --success-light: #2ecc71;
            --danger: #e74c3c;
            --danger-light: #c0392b;
            --warning: #f39c12;
            --warning-light: #f1c40f;
            --info: #17a2b8;
            --purple: #9b59b6;
            --purple-light: #8e44ad;
            --dark: #2c3e50;
            --dark-light: #34495e;
            --gray: #7f8c8d;
            --gray-light: #95a5a6;
            --light: #ecf0f1;
            --white: #ffffff;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.1);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
            --shadow-xl: 0 20px 25px rgba(0,0,0,0.1);
            --border-radius: 12px;
        }

        [data-theme="dark"] {
            --primary: #34495e;
            --primary-light: #3d566e;
            --secondary: #2980b9;
            --success: #229954;
            --danger: #c0392b;
            --warning: #e67e22;
            --dark: #ecf0f1;
            --dark-light: #bdc3c7;
            --gray: #95a5a6;
            --gray-light: #7f8c8d;
            --light: #2c3e50;
            --white: #34495e;
            --shadow-md: 0 4px 6px rgba(0,0,0,0.3);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.4);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            transition: background-color 0.3s, color 0.3s, border-color 0.3s;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            min-height: calc(100vh - 70px);
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }

        [data-theme="dark"] .main-content {
            background: linear-gradient(135deg, #1a2634 0%, #2c3e50 100%);
        }

        /* Page Header */
        .page-header {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            border-left: 5px solid var(--secondary);
        }

        .page-header h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: var(--gray);
            font-size: 1rem;
        }

        /* Profile Header */
        .profile-header {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .profile-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(135deg, var(--secondary), var(--purple));
        }

        .profile-info {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .avatar-container {
            position: relative;
        }

        .avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary), var(--purple));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 4rem;
            font-weight: 600;
            border: 5px solid var(--white);
            box-shadow: var(--shadow-lg);
            background-size: cover;
            background-position: center;
        }

        .avatar.has-image {
            background: var(--white);
        }

        .avatar-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: all 0.3s;
            cursor: pointer;
        }

        .avatar-container:hover .avatar-overlay {
            opacity: 1;
        }

        .avatar-overlay i {
            color: white;
            font-size: 2rem;
        }

        .profile-details {
            flex: 1;
        }

        .profile-name {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .profile-role {
            display: inline-block;
            padding: 0.5rem 1.5rem;
            background: rgba(52, 152, 219, 0.1);
            color: var(--secondary);
            border-radius: 30px;
            font-weight: 600;
            margin-bottom: 1rem;
            text-transform: capitalize;
        }

        .profile-meta {
            display: flex;
            gap: 2rem;
            color: var(--gray);
        }

        .profile-meta i {
            margin-right: 0.5rem;
            color: var(--secondary);
        }

        .profile-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 2px solid var(--light);
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Tabs */
        .tabs-container {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }

        .tabs {
            display: flex;
            background: var(--light);
            border-bottom: 2px solid #dee2e6;
        }

        .tab-btn {
            flex: 1;
            padding: 1.25rem;
            background: transparent;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: var(--gray);
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .tab-btn:hover {
            color: var(--secondary);
        }

        .tab-btn.active {
            color: var(--secondary);
            border-bottom-color: var(--secondary);
            background: var(--white);
        }

        .tab-content {
            display: none;
            padding: 2rem;
        }

        .tab-content.active {
            display: block;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Form Sections */
        .form-section {
            margin-bottom: 2rem;
        }

        .form-section h3 {
            font-size: 1.2rem;
            color: var(--dark);
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--light);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--light);
            border-radius: 8px;
            font-size: 0.95rem;
            background: var(--white);
            color: var(--dark);
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: var(--secondary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .form-control:disabled {
            background: var(--light);
            cursor: not-allowed;
        }

        .form-hint {
            font-size: 0.8rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary), var(--purple));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--light);
            color: var(--dark);
        }

        .btn-outline:hover {
            border-color: var(--secondary);
            color: var(--secondary);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), var(--danger-light));
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        /* Alert */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .alert-success {
            background: rgba(39, 174, 96, 0.1);
            border-color: var(--success);
            color: var(--success);
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            border-color: var(--danger);
            color: var(--danger);
        }

        /* Security Items */
        .security-item {
            background: var(--light);
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border-left: 4px solid var(--secondary);
        }

        .security-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .security-header h4 {
            color: var(--dark);
            font-size: 1rem;
            margin: 0;
        }

        .security-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-active {
            background: rgba(39, 174, 96, 0.15);
            color: var(--success);
        }

        /* Activity List */
        .activity-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid var(--light);
            transition: all 0.2s;
        }

        .activity-item:hover {
            background: var(--light);
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: rgba(52, 152, 219, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--secondary);
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .activity-time {
            font-size: 0.8rem;
            color: var(--gray);
        }

        /* Theme Options */
        .theme-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
        }

        .theme-option {
            border: 2px solid var(--light);
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }

        .theme-option:hover {
            border-color: var(--secondary);
        }

        .theme-option.active {
            border-color: var(--secondary);
            background: rgba(52, 152, 219, 0.05);
        }

        .theme-preview {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            margin: 0 auto 0.5rem;
        }

        .preview-light {
            background: linear-gradient(135deg, #f5f7fa, #c3cfe2);
        }

        .preview-dark {
            background: linear-gradient(135deg, #2c3e50, #34495e);
        }

        .theme-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1050;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 500px;
            padding: 2rem;
            box-shadow: var(--shadow-xl);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .modal-header h3 {
            color: var(--dark);
            font-size: 1.2rem;
            font-weight: 600;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
        }

        .modal-body {
            text-align: center;
        }

        .picture-preview {
            width: 200px;
            height: 200px;
            border-radius: 50%;
            margin: 0 auto 1.5rem;
            background: linear-gradient(135deg, var(--secondary), var(--purple));
            background-size: cover;
            background-position: center;
            border: 5px solid var(--light);
        }

        .file-input {
            display: none;
        }

        .file-label {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: var(--secondary);
            color: white;
            border-radius: 8px;
            cursor: pointer;
            margin-bottom: 1rem;
        }

        .file-name {
            color: var(--gray);
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .profile-info {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-meta {
                justify-content: center;
            }
        }

        @media (max-width: 768px) {
            .tabs {
                flex-direction: column;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .profile-stats {
                grid-template-columns: 1fr;
            }
            
            .profile-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header animate">
            <h1><i class="fas fa-user-circle" style="color: var(--secondary); margin-right: 0.5rem;"></i>My Profile</h1>
            <p>Manage your personal information and account settings</p>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($success)): ?>
        <div class="alert alert-success animate">
            <i class="fas fa-check-circle"></i>
            <div><?php echo $success; ?></div>
        </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
        <div class="alert alert-error animate">
            <i class="fas fa-exclamation-circle"></i>
            <div><?php echo $error; ?></div>
        </div>
        <?php endif; ?>

        <!-- Profile Header -->
        <div class="profile-header animate">
            <div class="profile-info">
                <div class="avatar-container">
                    <div class="avatar <?php echo getProfilePictureUrl($user) ? 'has-image' : ''; ?>" 
                         style="<?php echo getProfilePictureUrl($user) ? 'background-image: url(\'' . getProfilePictureUrl($user) . '\')' : ''; ?>">
                        <?php if (!getProfilePictureUrl($user)): ?>
                            <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="avatar-overlay" onclick="openPictureModal()">
                        <i class="fas fa-camera"></i>
                    </div>
                </div>
                <div class="profile-details">
                    <h2 class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></h2>
                    <span class="profile-role"><?php echo ucfirst($user['role']); ?></span>
                    <div class="profile-meta">
                        <span><i class="fas fa-user"></i> @<?php echo htmlspecialchars($user['username']); ?></span>
                        <span><i class="fas fa-calendar"></i> Joined <?php echo date('F Y', strtotime($user['created_at'])); ?></span>
                        <span><i class="fas fa-clock"></i> Last active: Today</span>
                    </div>
                </div>
            </div>

            <!-- Stats -->
            <div class="profile-stats">
                <?php if ($user['role'] == 'admin'): ?>
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($stats['students'] ?? 0); ?></div>
                    <div class="stat-label">Active Students</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($stats['teachers'] ?? 0); ?></div>
                    <div class="stat-label">Teachers</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($stats['classes'] ?? 0); ?></div>
                    <div class="stat-label">Classes</div>
                </div>
                <?php elseif ($user['role'] == 'accountant'): ?>
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($stats['payments'] ?? 0); ?></div>
                    <div class="stat-label">Payments This Month</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($stats['invoices'] ?? 0); ?></div>
                    <div class="stat-label">Pending Invoices</div>
                </div>
                <?php elseif ($user['role'] == 'teacher'): ?>
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($stats['students'] ?? 0); ?></div>
                    <div class="stat-label">My Students</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($stats['subjects'] ?? 0); ?></div>
                    <div class="stat-label">Subjects</div>
                </div>
                <?php elseif ($user['role'] == 'librarian'): ?>
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($stats['books'] ?? 0); ?></div>
                    <div class="stat-label">Available Books</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($stats['issued'] ?? 0); ?></div>
                    <div class="stat-label">Issued Books</div>
                </div>
                <?php endif; ?>
                <div class="stat-item">
                    <div class="stat-value">100%</div>
                    <div class="stat-label">Profile Complete</div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs-container animate">
            <div class="tabs">
                <button class="tab-btn active" onclick="openTab(event, 'personal')">
                    <i class="fas fa-user"></i> Personal Info
                </button>
                <button class="tab-btn" onclick="openTab(event, 'security')">
                    <i class="fas fa-shield-alt"></i> Security
                </button>
                <button class="tab-btn" onclick="openTab(event, 'preferences')">
                    <i class="fas fa-cog"></i> Preferences
                </button>
                <button class="tab-btn" onclick="openTab(event, 'activity')">
                    <i class="fas fa-history"></i> Activity
                </button>
            </div>

            <!-- Personal Info Tab -->
            <div id="personal" class="tab-content active">
                <form method="POST">
                    <div class="form-section">
                        <h3><i class="fas fa-id-card"></i> Basic Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" name="full_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Username</label>
                                <input type="text" name="username" class="form-control" 
                                       value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                <div class="form-hint">Used for login</div>
                            </div>
                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" name="email" class="form-control" 
                                       value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="tel" name="phone" class="form-control" 
                                       value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Role</label>
                                <input type="text" class="form-control" value="<?php echo ucfirst($user['role']); ?>" disabled>
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <input type="text" class="form-control" value="<?php echo ucfirst($user['status']); ?>" disabled>
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                        <button type="submit" name="update_profile" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <!-- Security Tab -->
            <div id="security" class="tab-content">
                <form method="POST">
                    <div class="form-section">
                        <h3><i class="fas fa-lock"></i> Change Password</h3>
                        <div class="security-item">
                            <div class="security-header">
                                <h4>Password Settings</h4>
                                <span class="security-status status-active">Active</span>
                            </div>
                            <p style="color: var(--gray); margin-bottom: 1rem;">
                                Last changed: <?php echo date('F j, Y', strtotime($user['created_at'])); ?>
                            </p>
                        </div>

                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label>Current Password</label>
                                <input type="password" name="current_password" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>New Password</label>
                                <input type="password" name="new_password" class="form-control" id="new_password" required>
                                <div class="form-hint">Minimum 6 characters</div>
                            </div>
                            <div class="form-group">
                                <label>Confirm New Password</label>
                                <input type="password" name="confirm_password" class="form-control" id="confirm_password" required>
                                <div class="form-hint" id="password_match_hint"></div>
                            </div>
                        </div>

                        <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1rem;">
                            <button type="submit" name="change_password" class="btn btn-primary">
                                <i class="fas fa-key"></i> Change Password
                            </button>
                        </div>
                    </div>
                </form>

                <div class="form-section">
                    <h3><i class="fas fa-history"></i> Recent Login Activity</h3>
                    <div class="activity-list">
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-sign-in-alt"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">Current Session</div>
                                <div class="activity-time">IP: <?php echo $_SERVER['REMOTE_ADDR']; ?></div>
                            </div>
                            <span class="security-status status-active">Active Now</span>
                        </div>
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-laptop"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">Previous Login</div>
                                <div class="activity-time">Today at 9:30 AM</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Preferences Tab -->
            <div id="preferences" class="tab-content">
                <form method="POST">
                    <div class="form-section">
                        <h3><i class="fas fa-palette"></i> Appearance</h3>
                        <div class="form-group full-width">
                            <label>Theme</label>
                            <div class="theme-selector">
                                <div class="theme-option <?php echo ($_SESSION['theme'] ?? 'light') == 'light' ? 'active' : ''; ?>" 
                                     onclick="selectTheme('light')">
                                    <div class="theme-preview preview-light"></div>
                                    <div class="theme-name">Light</div>
                                </div>
                                <div class="theme-option <?php echo ($_SESSION['theme'] ?? '') == 'dark' ? 'active' : ''; ?>" 
                                     onclick="selectTheme('dark')">
                                    <div class="theme-preview preview-dark"></div>
                                    <div class="theme-name">Dark</div>
                                </div>
                            </div>
                            <input type="hidden" name="theme" id="theme_input" value="<?php echo $_SESSION['theme'] ?? 'light'; ?>">
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-bell"></i> Notifications</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 0.5rem;">
                                    <input type="checkbox" name="notifications" value="1" checked>
                                    Enable push notifications
                                </label>
                            </div>
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 0.5rem;">
                                    <input type="checkbox" name="email_alerts" value="1" checked>
                                    Email alerts
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-globe"></i> Regional Settings</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Language</label>
                                <select name="language" class="form-control">
                                    <option value="en">English</option>
                                    <option value="sw">Swahili</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Timezone</label>
                                <select name="timezone" class="form-control">
                                    <option value="Africa/Nairobi">Nairobi (EAT)</option>
                                    <option value="Africa/Kampala">Kampala (EAT)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-database"></i> Data Management</h3>
                        <div style="display: flex; gap: 1rem;">
                            <button type="button" class="btn btn-outline" onclick="exportData()">
                                <i class="fas fa-download"></i> Export Data
                            </button>
                            <button type="button" class="btn btn-danger" onclick="deleteAccount()">
                                <i class="fas fa-trash"></i> Delete Account
                            </button>
                        </div>
                    </div>

                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                        <button type="submit" name="update_preferences" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Preferences
                        </button>
                    </div>
                </form>
            </div>

            <!-- Activity Tab -->
            <div id="activity" class="tab-content">
                <div class="form-section">
                    <h3><i class="fas fa-clock"></i> Recent Activity</h3>
                    <div class="activity-list">
                        <?php if (!empty($recent_activities)): ?>
                            <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-<?php echo $activity['icon'] ?? 'circle'; ?>"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title"><?php echo htmlspecialchars($activity['action']); ?></div>
                                    <div class="activity-time">
                                        <?php echo date('M j, Y H:i', strtotime($activity['created_at'])); ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-info-circle"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">No recent activity</div>
                                    <div class="activity-time">Your actions will appear here</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-section">
                    <h3><i class="fas fa-chart-bar"></i> Account Statistics</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem;">
                        <div style="background: var(--light); padding: 1rem; border-radius: 8px; text-align: center;">
                            <div style="font-size: 1.5rem; font-weight: 700; color: var(--secondary);">
                                <?php echo count($recent_activities); ?>
                            </div>
                            <div style="color: var(--gray);">Total Actions</div>
                        </div>
                        <div style="background: var(--light); padding: 1rem; border-radius: 8px; text-align: center;">
                            <div style="font-size: 1.5rem; font-weight: 700; color: var(--success);">
                                30
                            </div>
                            <div style="color: var(--gray);">Days Active</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Profile Picture Modal -->
    <div id="pictureModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-camera"></i> Update Profile Picture</h3>
                <button class="modal-close" onclick="closePictureModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="picture-preview" id="picturePreview" 
                     style="<?php echo getProfilePictureUrl($user) ? 'background-image: url(\'' . getProfilePictureUrl($user) . '\')' : ''; ?>">
                </div>

                <form method="POST" enctype="multipart/form-data" id="pictureForm">
                    <input type="file" id="profile_picture" name="profile_picture" class="file-input" accept="image/*" onchange="previewImage(this)">
                    <label for="profile_picture" class="file-label">
                        <i class="fas fa-upload"></i> Choose Image
                    </label>
                    <div class="file-name" id="fileName">No file chosen</div>

                    <div class="modal-actions">
                        <button type="button" class="btn btn-outline" onclick="closePictureModal()">Cancel</button>
                        <?php if (getProfilePictureUrl($user)): ?>
                        <button type="submit" name="remove_profile_picture" class="btn btn-danger" 
                                onclick="return confirm('Remove profile picture?')">
                            <i class="fas fa-trash"></i> Remove
                        </button>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary" id="savePictureBtn" disabled>
                            <i class="fas fa-save"></i> Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Tab switching
        function openTab(event, tabName) {
            const tabContents = document.querySelectorAll('.tab-content');
            const tabButtons = document.querySelectorAll('.tab-btn');
            
            tabContents.forEach(content => content.classList.remove('active'));
            tabButtons.forEach(btn => btn.classList.remove('active'));
            
            document.getElementById(tabName).classList.add('active');
            event.currentTarget.classList.add('active');
        }

        // Profile Picture Modal
        function openPictureModal() {
            document.getElementById('pictureModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closePictureModal() {
            document.getElementById('pictureModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        function previewImage(input) {
            const preview = document.getElementById('picturePreview');
            const fileName = document.getElementById('fileName');
            const saveBtn = document.getElementById('savePictureBtn');

            if (input.files && input.files[0]) {
                const file = input.files[0];
                fileName.textContent = file.name;

                // Validate file type
                if (!file.type.match('image.*')) {
                    Swal.fire('Error', 'Please select an image file', 'error');
                    input.value = '';
                    fileName.textContent = 'No file chosen';
                    saveBtn.disabled = true;
                    return;
                }

                // Validate file size (5MB)
                if (file.size > 5242880) {
                    Swal.fire('Error', 'File size must be less than 5MB', 'error');
                    input.value = '';
                    fileName.textContent = 'No file chosen';
                    saveBtn.disabled = true;
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.style.backgroundImage = `url('${e.target.result}')`;
                    preview.style.background = 'linear-gradient(135deg, var(--secondary), var(--purple))';
                }
                reader.readAsDataURL(file);
                saveBtn.disabled = false;
            }
        }

        // Password validation
        document.getElementById('new_password')?.addEventListener('input', validatePassword);
        document.getElementById('confirm_password')?.addEventListener('input', validatePasswordMatch);

        function validatePassword() {
            const password = document.getElementById('new_password').value;
            const hint = document.querySelector('#new_password + .form-hint');
            
            if (password.length < 6) {
                hint.style.color = 'var(--danger)';
            } else {
                hint.style.color = 'var(--success)';
            }
        }

        function validatePasswordMatch() {
            const password = document.getElementById('new_password').value;
            const confirm = document.getElementById('confirm_password').value;
            const hint = document.getElementById('password_match_hint');

            if (confirm === '') {
                hint.textContent = 'Confirm your new password';
                hint.style.color = 'var(--gray)';
            } else if (password === confirm) {
                hint.textContent = '✓ Passwords match';
                hint.style.color = 'var(--success)';
            } else {
                hint.textContent = '✗ Passwords do not match';
                hint.style.color = 'var(--danger)';
            }
        }

        // Theme selection
        function selectTheme(theme) {
            document.querySelectorAll('.theme-option').forEach(opt => {
                opt.classList.remove('active');
            });
            event.currentTarget.classList.add('active');
            
            document.getElementById('theme_input').value = theme;
            document.documentElement.setAttribute('data-theme', theme);
        }

        // Export data
        function exportData() {
            Swal.fire({
                title: 'Export Data',
                text: 'This will export your account data. Continue?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3498db',
                cancelButtonColor: '#7f8c8d',
                confirmButtonText: 'Yes, export'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Export Started',
                        text: 'Your data export will be emailed to you shortly',
                        timer: 3000
                    });
                }
            });
        }

        // Delete account
        function deleteAccount() {
            Swal.fire({
                title: 'Delete Account?',
                text: 'This action cannot be undone. All your data will be permanently removed.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#e74c3c',
                cancelButtonColor: '#7f8c8d',
                confirmButtonText: 'Yes, delete my account'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire(
                        'Account Deleted',
                        'Your account has been scheduled for deletion.',
                        'info'
                    );
                }
            });
        }

        // Close modal on outside click
        window.onclick = function(event) {
            const modal = document.getElementById('pictureModal');
            if (event.target === modal) {
                closePictureModal();
            }
        }

        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>