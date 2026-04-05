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
        $full_name = $_POST['full_name'];
        $username = $_POST['username'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        
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
        $theme = $_POST['theme'];
        $notifications = isset($_POST['notifications']) ? 1 : 0;
        $email_alerts = isset($_POST['email_alerts']) ? 1 : 0;
        
        // Store theme preference in database
        $stmt = $pdo->prepare("UPDATE users SET theme = ? WHERE id = ?");
        if ($stmt->execute([$theme, $user_id])) {
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
    
    $upload_dir = 'uploads/profile_pictures/';
    
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
        $file_path = 'uploads/profile_pictures/' . $current_picture;
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
        return 'uploads/profile_pictures/' . $user['profile_picture'];
    } else {
        return null;
    }
}

$page_title = "My Profile - " . SCHOOL_NAME;
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --primary-hover: #2980b9;
            --danger-color: #e74c3c;
            --danger-hover: #c0392b;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --text-color: #2c3e50;
            --text-light: #6c757d;
            --bg-color: #f8f9fa;
            --card-bg: white;
            --border-color: #e9ecef;
            --shadow: 0 4px 20px rgba(0,0,0,0.08);
            --radius: 15px;
        }

        [data-theme="dark"] {
            --primary-color: #3498db;
            --primary-hover: #2980b9;
            --danger-color: #e74c3c;
            --danger-hover: #c0392b;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --text-color: #e9ecef;
            --text-light: #adb5bd;
            --bg-color: #121212;
            --card-bg: #1e1e1e;
            --border-color: #343a40;
            --shadow: 0 4px 20px rgba(0,0,0,0.3);
            --radius: 15px;
        }

        * {
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
        }

        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            transition: margin-left 0.3s ease;
            background: var(--bg-color);
            min-height: calc(100vh - 70px);
        }
        
        .profile-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .profile-header {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 2rem;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }
        
        .profile-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .profile-info {
            display: flex;
            align-items: center;
            gap: 2rem;
        }
        
        .profile-avatar-container {
            position: relative;
            display: inline-block;
        }
        
        .profile-avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3.5rem;
            font-weight: 600;
            border: 5px solid var(--card-bg);
            box-shadow: var(--shadow);
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }
        
        .profile-avatar.has-image {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .avatar-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .profile-avatar-container:hover .avatar-overlay {
            opacity: 1;
        }
        
        .avatar-overlay i {
            color: white;
            font-size: 1.8rem;
        }
        
        .profile-details {
            flex: 1;
        }
        
        .profile-name {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }
        
        .profile-role {
            display: inline-block;
            padding: 0.5rem 1.5rem;
            background: rgba(52, 152, 219, 0.1);
            color: var(--primary-color);
            border-radius: 20px;
            font-weight: 600;
            text-transform: capitalize;
            margin-bottom: 1rem;
        }
        
        .profile-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid var(--border-color);
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 0.3rem;
        }
        
        .stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
        }
        
        /* Profile Picture Modal */
        .picture-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .picture-modal-content {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 2rem;
            max-width: 600px;
            width: 90%;
            text-align: center;
        }
        
        .picture-preview {
            width: 200px;
            height: 200px;
            border-radius: 50%;
            margin: 0 auto 1.5rem;
            background-size: cover;
            background-position: center;
            border: 5px solid var(--bg-color);
        }
        
        .file-input-wrapper {
            position: relative;
            margin-bottom: 1.5rem;
        }
        
        .file-input {
            position: absolute;
            left: -9999px;
        }
        
        .file-input-label {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: var(--primary-color);
            color: white;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .file-input-label:hover {
            background: var(--primary-hover);
        }
        
        .file-name {
            margin-top: 0.5rem;
            font-size: 0.9rem;
            color: var(--text-light);
        }
        
        .picture-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 1.5rem;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
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
            background: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid var(--border-color);
            color: var(--text-light);
        }
        
        .btn-outline:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }
        
        .btn-danger {
            background: var(--danger-color);
            color: white;
        }
        
        .btn-danger:hover {
            background: var(--danger-hover);
        }
        
        /* Tabs */
        .profile-tabs {
            display: flex;
            background: var(--card-bg);
            border-radius: var(--radius) var(--radius) 0 0;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        
        .tab-btn {
            flex: 1;
            padding: 1.5rem;
            background: none;
            border: none;
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }
        
        .tab-btn:hover {
            background: var(--bg-color);
            color: var(--text-color);
        }
        
        .tab-btn.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
            background: var(--bg-color);
        }
        
        .tab-btn i {
            margin-right: 0.5rem;
        }
        
        .tab-content {
            display: none;
            background: var(--card-bg);
            border-radius: 0 0 var(--radius) var(--radius);
            padding: 2rem;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }
        
        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .form-section {
            margin-bottom: 2rem;
        }
        
        .form-section h3 {
            color: var(--text-color);
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--border-color);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .form-hint {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-top: 0.3rem;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--card-bg);
            color: var(--text-color);
        }
        
        input:focus, select:focus, textarea:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        input:disabled {
            background: var(--bg-color);
            color: var(--text-light);
            cursor: not-allowed;
        }
        
        .checkbox-group, .radio-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .checkbox-group input, .radio-group input {
            width: auto;
        }
        
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
        }
        
        .alert-success {
            background: rgba(39, 174, 96, 0.1);
            border-color: var(--success-color);
            color: var(--success-color);
        }
        
        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            border-color: var(--danger-color);
            color: var(--danger-color);
        }
        
        .security-item {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary-color);
        }
        
        .security-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .security-details h4 {
            margin: 0 0 0.5rem 0;
            color: var(--text-color);
        }
        
        .security-details p {
            margin: 0;
            color: var(--text-light);
            font-size: 0.9rem;
        }
        
        .security-status {
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-active {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success-color);
        }
        
        .status-inactive {
            background: rgba(108, 117, 125, 0.1);
            color: var(--text-light);
        }
        
        .activity-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            transition: background 0.2s ease;
        }
        
        .activity-item:hover {
            background: var(--bg-color);
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(52, 152, 219, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
            margin-right: 1rem;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 0.2rem;
        }
        
        .activity-description {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 0.2rem;
        }
        
        .activity-time {
            font-size: 0.8rem;
            color: var(--text-light);
        }

        /* Theme selector styles */
        .theme-selector {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .theme-option {
            flex: 1;
            text-align: center;
            padding: 1rem;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .theme-option:hover {
            border-color: var(--primary-color);
        }

        .theme-option.active {
            border-color: var(--primary-color);
            background: rgba(52, 152, 219, 0.1);
        }

        .theme-preview {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            margin: 0 auto 0.5rem;
            overflow: hidden;
            position: relative;
        }

        .light-theme-preview {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }

        .dark-theme-preview {
            background: linear-gradient(135deg, #121212 0%, #343a40 100%);
        }

        .auto-theme-preview {
            background: linear-gradient(135deg, #f8f9fa 0%, #121212 100%);
        }

        .theme-name {
            font-weight: 600;
            color: var(--text-color);
        }

        .theme-description {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-top: 0.3rem;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .profile-info {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
            
            .profile-tabs {
                flex-direction: column;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .profile-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .security-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .picture-actions {
                flex-direction: column;
            }

            .theme-selector {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        <div class="profile-container">
            <!-- Profile Header -->
            <div class="profile-header">
                <div class="profile-info">
                    <div class="profile-avatar-container">
                        <div class="profile-avatar <?php echo getProfilePictureUrl($user) ? 'has-image' : ''; ?>" 
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
                        <h1 class="profile-name"><?php echo $user['full_name']; ?></h1>
                        <span class="profile-role"><?php echo $user['role']; ?></span>
                        <div style="color: var(--text-light); margin-bottom: 0.5rem;">
                            <i class="fas fa-user-circle"></i> @<?php echo $user['username']; ?>
                        </div>
                        <div style="color: var(--text-light);">
                            <i class="fas fa-calendar-alt"></i> Member since <?php echo date('F Y', strtotime($user['created_at'])); ?>
                        </div>
                    </div>
                </div>
                
                <div class="profile-stats">
                    <div class="stat-item">
                        <div class="stat-value">
                            <?php
                            // Get user-specific statistics based on role
                            if ($user['role'] == 'teacher') {
                                $stats = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE teacher_id = ?");
                                $stats->execute([$user_id]);
                                echo $stats->fetchColumn();
                            } elseif ($user['role'] == 'admin') {
                                $stats = $pdo->query("SELECT COUNT(*) FROM students WHERE status = 'active'")->fetchColumn();
                            } elseif ($user['role'] == 'accountant') {
                                $stats = $pdo->query("SELECT COUNT(*) FROM fees WHERE status = 'Paid' AND MONTH(created_at) = MONTH(CURDATE())")->fetchColumn();
                            } else {
                                $stats = $pdo->query("SELECT COUNT(*) FROM book_issues WHERE status = 'Issued'")->fetchColumn();
                            }
                            ?>
                        </div>
                        <div class="stat-label">
                            <?php
                            if ($user['role'] == 'teacher') echo 'Classes';
                            elseif ($user['role'] == 'admin') echo 'Students';
                            elseif ($user['role'] == 'accountant') echo 'Transactions';
                            else echo 'Books Issued';
                            ?>
                        </div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">
                            <?php echo date('M j'); ?>
                        </div>
                        <div class="stat-label">Last Active</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">100%</div>
                        <div class="stat-label">Profile Complete</div>
                    </div>
                </div>
            </div>
            
            <!-- Success/Error Messages -->
            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <!-- Profile Picture Modal -->
            <div id="pictureModal" class="picture-modal">
                <div class="picture-modal-content">
                    <h3>Update Profile Picture</h3>
                    
                    <div class="picture-preview" id="picturePreview" 
                         style="<?php echo getProfilePictureUrl($user) ? 'background-image: url(\'' . getProfilePictureUrl($user) . '\')' : 'background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);'; ?>">
                        <?php if (!getProfilePictureUrl($user)): ?>
                            <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: white; font-size: 3rem; font-weight: 600;">
                                <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" id="pictureForm">
                        <div class="file-input-wrapper">
                            <input type="file" id="profile_picture" name="profile_picture" accept="image/*" class="file-input" onchange="previewImage(this)">
                            <label for="profile_picture" class="file-input-label">
                                <i class="fas fa-upload"></i> Choose Image
                            </label>
                            <div class="file-name" id="fileName">No file chosen</div>
                        </div>
                        
                        <div class="picture-actions">
                            <button type="button" class="btn btn-outline" onclick="closePictureModal()">Cancel</button>
                            <?php if (getProfilePictureUrl($user)): ?>
                                <button type="submit" name="remove_profile_picture" class="btn btn-danger" onclick="return confirm('Are you sure you want to remove your profile picture?')">
                                    <i class="fas fa-trash"></i> Remove
                                </button>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary" id="savePictureBtn" disabled>
                                <i class="fas fa-save"></i> Save Picture
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Tabs Navigation -->
            <div class="profile-tabs">
                <button class="tab-btn active" onclick="openTab('personal')">
                    <i class="fas fa-user"></i> Personal Info
                </button>
                <button class="tab-btn" onclick="openTab('security')">
                    <i class="fas fa-shield-alt"></i> Security
                </button>
                <button class="tab-btn" onclick="openTab('preferences')">
                    <i class="fas fa-cog"></i> Preferences
                </button>
                <button class="tab-btn" onclick="openTab('activity')">
                    <i class="fas fa-history"></i> Activity
                </button>
            </div>
            
            <!-- Personal Information Tab -->
            <div id="personal" class="tab-content active">
                <form method="POST">
                    <div class="form-section">
                        <h3>Basic Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="full_name">Full Name</label>
                                <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="username">Username</label>
                                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                <div class="form-hint">You can change your username</div>
                            </div>
                            <div class="form-group">
                                <label for="email">Email Address</label>
                                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>">
                            </div>
                            <div class="form-group">
                                <label for="phone">Phone Number</label>
                                <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>">
                            </div>
                            <div class="form-group">
                                <label for="role">Role</label>
                                <input type="text" id="role" value="<?php echo ucfirst($user['role']); ?>" disabled>
                            </div>
                            <div class="form-group">
                                <label for="status">Status</label>
                                <input type="text" id="status" value="<?php echo ucfirst($user['status']); ?>" disabled>
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
                <div class="form-section">
                    <h3>Password & Security</h3>
                    
                    <div class="security-item">
                        <div class="security-info">
                            <div class="security-details">
                                <h4>Password</h4>
                                <p>Last changed: <?php echo date('F j, Y', strtotime($user['created_at'])); ?></p>
                            </div>
                            <span class="security-status status-active">Active</span>
                        </div>
                    </div>
                    
                    <form method="POST">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="current_password">Current Password</label>
                                <input type="password" id="current_password" name="current_password" required>
                            </div>
                            <div class="form-group"></div> <!-- Spacer -->
                            <div class="form-group">
                                <label for="new_password">New Password</label>
                                <input type="password" id="new_password" name="new_password" required>
                                <div class="form-hint">Minimum 6 characters</div>
                            </div>
                            <div class="form-group">
                                <label for="confirm_password">Confirm New Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" required>
                            </div>
                        </div>
                        
                        <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                            <button type="submit" name="change_password" class="btn btn-primary">
                                <i class="fas fa-key"></i> Change Password
                            </button>
                        </div>
                    </form>
                </div>
                
                <div class="form-section">
                    <h3>Login Activity</h3>
                    <div class="activity-list">
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-sign-in-alt"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">Successful Login</div>
                                <div class="activity-description">From <?php echo $_SERVER['REMOTE_ADDR']; ?> on <?php echo $_SERVER['HTTP_USER_AGENT']; ?></div>
                                <div class="activity-time">Just now</div>
                            </div>
                        </div>
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-sign-in-alt"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">Successful Login</div>
                                <div class="activity-description">From school computer</div>
                                <div class="activity-time">Yesterday at 2:30 PM</div>
                            </div>
                        </div>
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-mobile-alt"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">Mobile Login</div>
                                <div class="activity-description">From Android device</div>
                                <div class="activity-time">2 days ago</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Preferences Tab -->
            <div id="preferences" class="tab-content">
                <form method="POST">
                    <div class="form-section">
                        <h3>Interface Preferences</h3>
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label>Theme</label>
                                <div class="theme-selector">
                                    <div class="theme-option <?php echo (isset($user['theme']) && $user['theme'] == 'light') || !isset($user['theme']) ? 'active' : ''; ?>" 
                                         onclick="selectTheme('light')">
                                        <div class="theme-preview light-theme-preview"></div>
                                        <div class="theme-name">Light</div>
                                        <div class="theme-description">Bright and clean</div>
                                    </div>
                                    <div class="theme-option <?php echo isset($user['theme']) && $user['theme'] == 'dark' ? 'active' : ''; ?>" 
                                         onclick="selectTheme('dark')">
                                        <div class="theme-preview dark-theme-preview"></div>
                                        <div class="theme-name">Dark</div>
                                        <div class="theme-description">Easy on the eyes</div>
                                    </div>
                                    <div class="theme-option <?php echo isset($user['theme']) && $user['theme'] == 'auto' ? 'active' : ''; ?>" 
                                         onclick="selectTheme('auto')">
                                        <div class="theme-preview auto-theme-preview"></div>
                                        <div class="theme-name">Auto</div>
                                        <div class="theme-description">Follows system</div>
                                    </div>
                                </div>
                                <input type="hidden" id="theme" name="theme" value="<?php echo isset($user['theme']) ? $user['theme'] : 'light'; ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3>Notification Preferences</h3>
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <div class="checkbox-group">
                                    <input type="checkbox" id="notifications" name="notifications" checked>
                                    <label for="notifications">Enable push notifications</label>
                                </div>
                                <div class="checkbox-group">
                                    <input type="checkbox" id="email_alerts" name="email_alerts" checked>
                                    <label for="email_alerts">Send email alerts</label>
                                </div>
                                <div class="checkbox-group">
                                    <input type="checkbox" id="sms_alerts" name="sms_alerts">
                                    <label for="sms_alerts">Send SMS alerts</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3>Data & Privacy</h3>
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <p style="color: var(--text-light); margin-bottom: 1rem;">
                                    Manage your data and privacy settings. You can request to download your data or delete your account.
                                </p>
                                <div style="display: flex; gap: 1rem;">
                                    <button type="button" class="btn btn-outline">
                                        <i class="fas fa-download"></i> Export Data
                                    </button>
                                    <button type="button" class="btn btn-danger">
                                        <i class="fas fa-trash"></i> Delete Account
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                        <button type="submit" name="update_preferences" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Preferences
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Activity Tab -->
            <div id="activity" class="tab-content">
                <div class="form-section">
                    <h3>Recent Activity</h3>
                    <div class="activity-list">
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-user-edit"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">Profile Updated</div>
                                <div class="activity-description">You updated your personal information</div>
                                <div class="activity-time">Just now</div>
                            </div>
                        </div>
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">Dashboard Viewed</div>
                                <div class="activity-description">You accessed the <?php echo ucfirst($user['role']); ?> Dashboard</div>
                                <div class="activity-time">Today at 9:15 AM</div>
                            </div>
                        </div>
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-file-export"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">Report Generated</div>
                                <div class="activity-description">You exported student records</div>
                                <div class="activity-time">Yesterday at 3:45 PM</div>
                            </div>
                        </div>
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">Student Added</div>
                                <div class="activity-description">You added a new student to the system</div>
                                <div class="activity-time">2 days ago</div>
                            </div>
                        </div>
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-key"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">Password Changed</div>
                                <div class="activity-description">You updated your account password</div>
                                <div class="activity-time">1 week ago</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>System Statistics</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">
                        <div style="background: var(--bg-color); padding: 1.5rem; border-radius: 8px; text-align: center;">
                            <div style="font-size: 2rem; font-weight: bold; color: var(--primary-color);">24</div>
                            <div style="color: var(--text-light);">Logins This Month</div>
                        </div>
                        <div style="background: var(--bg-color); padding: 1.5rem; border-radius: 8px; text-align: center;">
                            <div style="font-size: 2rem; font-weight: bold; color: var(--success-color);">156</div>
                            <div style="color: var(--text-light);">Total Actions</div>
                        </div>
                        <div style="background: var(--bg-color); padding: 1.5rem; border-radius: 8px; text-align: center;">
                            <div style="font-size: 2rem; font-weight: bold; color: var(--warning-color);">98%</div>
                            <div style="color: var(--text-light);">Active Time</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Tab functionality
        function openTab(tabName) {
            // Hide all tab contents
            const tabContents = document.getElementsByClassName('tab-content');
            for (let i = 0; i < tabContents.length; i++) {
                tabContents[i].classList.remove('active');
            }
            
            // Remove active class from all tab buttons
            const tabButtons = document.getElementsByClassName('tab-btn');
            for (let i = 0; i < tabButtons.length; i++) {
                tabButtons[i].classList.remove('active');
            }
            
            // Show the specific tab content and activate the button
            document.getElementById(tabName).classList.add('active');
            event.currentTarget.classList.add('active');
        }
        
        // Profile Picture Modal Functions
        function openPictureModal() {
            document.getElementById('pictureModal').style.display = 'flex';
        }
        
        function closePictureModal() {
            document.getElementById('pictureModal').style.display = 'none';
            // Reset form
            document.getElementById('pictureForm').reset();
            document.getElementById('savePictureBtn').disabled = true;
            document.getElementById('fileName').textContent = 'No file chosen';
            // Reset preview to current picture
            const currentPicture = '<?php echo getProfilePictureUrl($user); ?>';
            const preview = document.getElementById('picturePreview');
            if (currentPicture) {
                preview.style.backgroundImage = `url('${currentPicture}')`;
                preview.innerHTML = '';
            } else {
                preview.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
                preview.innerHTML = '<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: white; font-size: 3rem; font-weight: 600;"><?php echo strtoupper(substr($user['full_name'], 0, 1)); ?></div>';
            }
        }
        
        function previewImage(input) {
            const preview = document.getElementById('picturePreview');
            const fileName = document.getElementById('fileName');
            const saveBtn = document.getElementById('savePictureBtn');
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                fileName.textContent = file.name;
                
                // Validate file type
                const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!validTypes.includes(file.type)) {
                    alert('Please select a valid image file (JPEG, PNG, GIF)');
                    input.value = '';
                    fileName.textContent = 'No file chosen';
                    saveBtn.disabled = true;
                    return;
                }
                
                // Validate file size (5MB)
                if (file.size > 5242880) {
                    alert('File size must be less than 5MB');
                    input.value = '';
                    fileName.textContent = 'No file chosen';
                    saveBtn.disabled = true;
                    return;
                }
                
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    preview.style.backgroundImage = `url('${e.target.result}')`;
                    preview.innerHTML = '';
                    preview.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
                }
                
                reader.readAsDataURL(file);
                saveBtn.disabled = false;
            } else {
                fileName.textContent = 'No file chosen';
                saveBtn.disabled = true;
            }
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('pictureModal');
            if (event.target === modal) {
                closePictureModal();
            }
        }
        
        // Password strength indicator
        document.getElementById('new_password').addEventListener('input', function() {
            const password = this.value;
            const strength = checkPasswordStrength(password);
            updatePasswordStrength(strength);
        });
        
        function checkPasswordStrength(password) {
            let strength = 0;
            
            if (password.length >= 6) strength++;
            if (password.match(/[a-z]+/)) strength++;
            if (password.match(/[A-Z]+/)) strength++;
            if (password.match(/[0-9]+/)) strength++;
            if (password.match(/[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]+/)) strength++;
            
            return strength;
        }
        
        function updatePasswordStrength(strength) {
            const hint = document.querySelector('#new_password + .form-hint');
            if (!hint) return;
            
            const messages = [
                'Very Weak',
                'Weak',
                'Fair',
                'Good',
                'Strong',
                'Very Strong'
            ];
            
            const colors = [
                '#e74c3c',
                '#e67e22',
                '#f39c12',
                '#3498db',
                '#2ecc71',
                '#27ae60'
            ];
            
            hint.textContent = `Strength: ${messages[strength]}`;
            hint.style.color = colors[strength] || '#6c757d';
        }
        
        // Confirm password match
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            const hint = this.parentElement.querySelector('.form-hint');
            
            if (!hint) return;
            
            if (confirmPassword && newPassword !== confirmPassword) {
                hint.textContent = 'Passwords do not match';
                hint.style.color = '#e74c3c';
            } else if (confirmPassword) {
                hint.textContent = 'Passwords match';
                hint.style.color = '#27ae60';
            } else {
                hint.textContent = 'Confirm your new password';
                hint.style.color = '#6c757d';
            }
        });
        
        // Theme selection functionality
        function selectTheme(theme) {
            // Update active state
            const themeOptions = document.querySelectorAll('.theme-option');
            themeOptions.forEach(option => {
                option.classList.remove('active');
            });
            event.currentTarget.classList.add('active');
            
            // Update hidden input
            document.getElementById('theme').value = theme;
            
            // Apply theme immediately for preview
            applyTheme(theme);
        }
        
        function applyTheme(theme) {
            // Remove existing theme classes
            document.documentElement.classList.remove('light-theme', 'dark-theme');
            
            if (theme === 'auto') {
                // Use system preference
                if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.classList.add('dark-theme');
                } else {
                    document.documentElement.classList.add('light-theme');
                }
            } else {
                // Apply selected theme
                document.documentElement.classList.add(theme + '-theme');
            }
            
            // Update data-theme attribute
            document.documentElement.setAttribute('data-theme', theme);
        }
        
        // Initialize the page
        document.addEventListener('DOMContentLoaded', function() {
            // Add password confirmation hint if not exists
            const confirmInput = document.getElementById('confirm_password');
            if (confirmInput && !confirmInput.parentElement.querySelector('.form-hint')) {
                const hint = document.createElement('div');
                hint.className = 'form-hint';
                hint.textContent = 'Confirm your new password';
                confirmInput.parentElement.appendChild(hint);
            }
            
            // Apply current theme
            const currentTheme = '<?php echo isset($user['theme']) ? $user['theme'] : 'light'; ?>';
            applyTheme(currentTheme);
        });
    </script>
</body>
</html>