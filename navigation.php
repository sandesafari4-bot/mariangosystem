<?php

// Helper function to get notification icon and color based on type
if (!function_exists('getNotificationIcon')) {
    function getNotificationIcon($type) {
        $icons = [
            'payment' => ['icon' => 'fas fa-money-bill-wave', 'color' => '#27ae60'],
            'student' => ['icon' => 'fas fa-user-plus', 'color' => '#3498db'],
            'invoice' => ['icon' => 'fas fa-file-invoice', 'color' => '#f39c12'],
            'expense' => ['icon' => 'fas fa-receipt', 'color' => '#e74c3c'],
            'message' => ['icon' => 'fas fa-envelope', 'color' => '#9b59b6'],
            'attendance' => ['icon' => 'fas fa-clipboard-check', 'color' => '#1abc9c'],
            'report' => ['icon' => 'fas fa-file-alt', 'color' => '#e67e22'],
            'exam' => ['icon' => 'fas fa-certificate', 'color' => '#27ae60'],
            'system' => ['icon' => 'fas fa-cog', 'color' => '#95a5a6'],
        ];
        return $icons[$type] ?? ['icon' => 'fas fa-bell', 'color' => '#667eea'];
    }
}

// Helper function to convert timestamp to relative time format
if (!function_exists('timeAgo')) {
    function timeAgo($timestamp) {
        $time = strtotime($timestamp);
        $now = time();
        $ago = $now - $time;
        
        if ($ago < 60) {
            return 'just now';
        } elseif ($ago < 3600) {
            $minutes = round($ago / 60);
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
        } elseif ($ago < 86400) {
            $hours = round($ago / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($ago < 604800) {
            $days = round($ago / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } elseif ($ago < 2592000) {
            $weeks = round($ago / 604800);
            return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
        } elseif ($ago < 31536000) {
            $months = round($ago / 2592000);
            return $months . ' month' . ($months > 1 ? 's' : '') . ' ago';
        } else {
            $years = round($ago / 31536000);
            return $years . ' year' . ($years > 1 ? 's' : '') . ' ago';
        }
    }
}

// Helper function to abbreviate school name
function abbreviateSchoolName($name) {
    if (empty($name)) return 'SMS';
    
    $words = preg_split('/[\s&-]+/', $name);
    $abbrev = '';
    
    if (strlen($name) <= 20) return $name;
    
    foreach ($words as $word) {
        $word = trim($word);
        if (!empty($word) && !in_array(strtolower($word), ['the', 'and', 'of', 'for', 'at', 'in', 'school', 'academy', 'institute'])) {
            $abbrev .= strtoupper(substr($word, 0, 1));
        }
    }
    
    if (strlen($abbrev) >= 2) {
        return $abbrev;
    }
    
    return substr($name, 0, 15) . '...';
}

// Fetch school settings from database
$school_name = 'Mariango School';
$school_location = 'Kenya';
$school_logo = null;

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `skey` VARCHAR(191) NOT NULL UNIQUE,
        `svalue` TEXT NOT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    $stmt = $pdo->prepare("SELECT svalue FROM settings WHERE skey = 'school_name' LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result && !empty($result['svalue'])) {
        $school_name = $result['svalue'];
    }
    
    $stmt = $pdo->prepare("SELECT svalue FROM settings WHERE skey = 'school_address' LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result && !empty($result['svalue'])) {
        $school_location = $result['svalue'];
    }
    
    $stmt = $pdo->prepare("SELECT svalue FROM settings WHERE skey = 'school_logo' LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result && !empty($result['svalue'])) {
        $school_logo = $result['svalue'];
    }
} catch (Exception $e) {
    error_log("Error fetching school settings: " . $e->getMessage());
}

// Helper function to get profile picture URL
if (!function_exists('getProfilePictureUrl')) {
    function getProfilePictureUrl($user) {
        if (!empty($user) && !empty($user['profile_picture'])) {
            return 'uploads/profile_pictures/' . $user['profile_picture'];
        }
        return null;
    }
}

// Get current user data for navigation
$current_user = null;
$notification_count = 0;
$message_count = 0;
$recent_notifications = [];
$recent_messages = [];

if (isset($_SESSION['user_id'])) {
    try {
        $user_id = $_SESSION['user_id'];
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $current_user = $stmt->fetch();
        
        if ($current_user) {
            // Get notification count
            try {
                $notification_stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM notifications 
                    WHERE (user_id = ? OR user_id IS NULL) 
                    AND is_read = 0
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ");
                $notification_stmt->execute([$user_id]);
                $notification_result = $notification_stmt->fetch();
                $notification_count = $notification_result['count'] ?? 0;
            } catch (Exception $e) {
                error_log("Error fetching notification count: " . $e->getMessage());
            }
            
            // Get unread message count
            try {
                $message_stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM messages 
                    WHERE receiver_id = ? 
                    AND is_read = 0
                ");
                $message_stmt->execute([$user_id]);
                $message_result = $message_stmt->fetch();
                $message_count = $message_result['count'] ?? 0;
            } catch (Exception $e) {
                error_log("Error fetching message count: " . $e->getMessage());
            }
            
            // Get recent notifications
            try {
                $recent_notifications_stmt = $pdo->prepare("
                    SELECT * 
                    FROM notifications 
                    WHERE (user_id = ? OR user_id IS NULL)
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    ORDER BY created_at DESC 
                    LIMIT 5
                ");
                $recent_notifications_stmt->execute([$user_id]);
                $recent_notifications = $recent_notifications_stmt->fetchAll() ?: [];
            } catch (Exception $e) {
                error_log("Error fetching recent notifications: " . $e->getMessage());
            }
            
            // Get recent messages
            try {
                $recent_messages_stmt = $pdo->prepare("
                    SELECT m.*, u.full_name as sender_name, u.role as sender_role
                    FROM messages m
                    JOIN users u ON m.sender_id = u.id
                    WHERE m.receiver_id = ?
                    ORDER BY m.created_at DESC 
                    LIMIT 3
                ");
                $recent_messages_stmt->execute([$user_id]);
                $recent_messages = $recent_messages_stmt->fetchAll() ?: [];
            } catch (Exception $e) {
                error_log("Error fetching recent messages: " . $e->getMessage());
            }
            
            // Handle mark all as read
            if (isset($_POST['mark_all_read'])) {
                try {
                    $mark_read_stmt = $pdo->prepare("
                        UPDATE notifications 
                        SET is_read = 1 
                        WHERE user_id = ? AND is_read = 0
                    ");
                    $mark_read_stmt->execute([$user_id]);
                } catch (Exception $e) {
                    error_log("Error marking notifications as read: " . $e->getMessage());
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error in navigation user data fetch: " . $e->getMessage());
    }
}

// Abbreviated school name for mobile
$abbreviated_school = abbreviateSchoolName($school_name);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Navigation - School Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f8f9fa;
            color: #333;
            padding-top: 70px;
        }

        body.sidebar-mobile-open {
            overflow: hidden;
            touch-action: none;
        }

        body.sidebar-collapsed .main-content {
            margin-left: 70px !important;
        }

        .main-content {
            transition: margin-left 0.3s ease, padding 0.3s ease;
        }
        
        .top-navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0;
            box-shadow: 0 2px 15px rgba(0,0,0,0.1);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1100;
            height: 70px;
            backdrop-filter: blur(10px);
        }
        
        .nav-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1.5rem;
            height: 100%;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        /* Left section - Always at the beginning */
        .logo-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-right: auto;
        }
        
        .mobile-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 6px;
            transition: background 0.3s ease;
            width: 40px;
            height: 40px;
            align-items: center;
            justify-content: center;
        }
        
        .mobile-toggle:hover {
            background: rgba(255,255,255,0.1);
        }
        
        .logo {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
            flex-shrink: 0;
            background: rgba(255,255,255,0.2);
        }
        
        .logo-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            background: rgba(255,255,255,0.9);
        }
        
        .school-info {
            overflow: hidden;
            white-space: nowrap;
        }

        .school-name {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 0.1rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .school-name .full-name {
            display: inline;
        }

        .school-name .abbrev-name {
            display: none;
        }

        .school-location {
            font-size: 0.75rem;
            opacity: 0.8;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        /* Center section */
        .nav-center {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            margin: 0 2rem;
        }
        
        .quick-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .nav-action-btn {
            background: rgba(255,255,255,0.1);
            border: none;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.85rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            backdrop-filter: blur(10px);
            white-space: nowrap;
        }
        
        .nav-action-btn:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-1px);
        }
        
        .search-container {
            position: relative;
            width: 300px;
        }
        
        .search-input {
            width: 100%;
            padding: 0.6rem 1rem 0.6rem 2.2rem;
            border: none;
            border-radius: 20px;
            background: rgba(255,255,255,0.15);
            color: white;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }
        
        .search-input::placeholder {
            color: rgba(255,255,255,0.7);
        }
        
        .search-input:focus {
            outline: none;
            background: rgba(255,255,255,0.25);
            box-shadow: 0 0 0 2px rgba(255,255,255,0.3);
        }
        
        .search-icon {
            position: absolute;
            left: 0.8rem;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,0.7);
            font-size: 0.8rem;
        }
        
        /* Right section */
        .user-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-shrink: 0;
        }
        
        .nav-icon-btn {
            background: none;
            border: none;
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 50%;
            transition: all 0.3s ease;
            position: relative;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .nav-icon-btn:hover {
            background: rgba(255,255,255,0.1);
        }
        
        .notification-badge {
            position: absolute;
            top: 2px;
            right: 2px;
            background: #e74c3c;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            border: 2px solid rgba(255,255,255,0.3);
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.3rem 0.5rem 0.3rem 0.3rem;
            border-radius: 30px;
            transition: all 0.3s ease;
            position: relative;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
        }

        .user-profile-trigger {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            background: transparent;
            border: 0;
            color: inherit;
            padding: 0;
            font: inherit;
            text-align: left;
        }
        
        .user-profile:hover {
            background: rgba(255,255,255,0.2);
        }
        
        .profile-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
            border: 2px solid rgba(255,255,255,0.3);
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            flex-shrink: 0;
        }
        
        .user-info {
            text-align: right;
        }
        
        .user-name {
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 0.1rem;
            line-height: 1.2;
        }
        
        .user-role {
            font-size: 0.7rem;
            opacity: 0.8;
            text-transform: capitalize;
            line-height: 1.2;
        }
        
        .dropdown-arrow {
            font-size: 0.7rem;
            opacity: 0.7;
            transition: transform 0.3s ease;
            margin-left: 0.2rem;
        }
        
        .user-profile.active .dropdown-arrow {
            transform: rotate(180deg);
        }
        
        /* Dropdown Menus */
        .notifications-wrapper,
        .messages-wrapper,
        .user-profile {
            position: relative;
        }
        
        .dropdown-menu-container {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            min-width: 260px;
            max-width: 90vw;
            max-height: calc(100vh - 100px);
            overflow-y: auto;
            z-index: 1102;
            display: none;
            overscroll-behavior: contain;
            -webkit-overflow-scrolling: touch;
        }
        
        .user-profile .dropdown-menu-container {
            right: 0;
            left: auto;
        }
        
        .notifications-wrapper .dropdown-menu-container,
        .messages-wrapper .dropdown-menu-container {
            width: 350px;
        }
        
        .user-profile.active .dropdown-menu-container,
        .notifications-wrapper.active .dropdown-menu-container,
        .messages-wrapper.active .dropdown-menu-container {
            display: block;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .dropdown-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
        }
        
        .dropdown-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.5rem;
            margin: 0 auto 1rem;
            border: 3px solid rgba(255,255,255,0.3);
            background-size: cover;
            background-position: center;
        }
        
        .dropdown-user-name {
            font-weight: 600;
            margin-bottom: 0.2rem;
        }
        
        .dropdown-user-role {
            font-size: 0.8rem;
            opacity: 0.9;
        }
        
        .dropdown-menu {
            padding: 0.5rem 0;
        }
        
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.8rem 1.5rem;
            color: #2c3e50;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .dropdown-item:hover {
            background: #f8f9fa;
            color: #667eea;
        }
        
        .dropdown-item i {
            width: 20px;
            text-align: center;
            color: #6c757d;
        }
        
        .dropdown-item:hover i {
            color: #667eea;
        }
        
        .dropdown-divider {
            height: 1px;
            background: #e9ecef;
            margin: 0.5rem 0;
        }
        
        /* Notifications and Messages specific styles */
        .notifications-header, .messages-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notifications-header h3, .messages-header h3 {
            margin: 0;
            color: #2c3e50;
            font-size: 1rem;
        }
        
        .mark-all-read, .view-all-messages {
            background: none;
            border: none;
            color: #667eea;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
        }
        
        .notifications-list, .messages-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .notification-item, .message-item {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #f8f9fa;
            cursor: pointer;
            transition: background 0.3s ease;
            position: relative;
        }
        
        .notification-item:hover, .message-item:hover {
            background: #f8f9fa;
        }
        
        .notification-item.unread, .message-item.unread {
            background: rgba(102, 126, 234, 0.05);
        }
        
        .notification-item.empty, .message-item.empty {
            border-bottom: none;
        }
        
        .notification-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #667eea;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            margin-right: 1rem;
            flex-shrink: 0;
        }
        
        .message-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.8rem;
            margin-right: 1rem;
            flex-shrink: 0;
        }
        
        .notification-content, .message-content {
            flex: 1;
            min-width: 0;
        }
        
        .notification-title, .message-sender {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.2rem;
            font-size: 0.9rem;
        }
        
        .sender-role {
            color: #6c757d;
            font-weight: normal;
            font-size: 0.8rem;
        }
        
        .notification-message, .message-subject {
            color: #6c757d;
            font-size: 0.8rem;
            margin-bottom: 0.2rem;
            line-height: 1.3;
        }
        
        .notification-time, .message-time {
            color: #95a5a6;
            font-size: 0.7rem;
        }
        
        .notifications-footer, .messages-footer {
            padding: 1rem 1.5rem;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }
        
        .view-all-notifications, .compose-message {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Dark Mode Styles */
        .dark-mode {
            background: #1a1a1a;
            color: #ffffff;
        }
        
        .dark-mode .dropdown-menu-container {
            background: #2d2d2d;
            border-color: #3d3d3d;
        }
        
        .dark-mode .dropdown-item {
            color: #e0e0e0;
        }
        
        .dark-mode .dropdown-item:hover {
            background: #3d3d3d;
        }
        
        .dark-mode .notifications-header,
        .dark-mode .messages-header {
            border-bottom-color: #3d3d3d;
        }
        
        .dark-mode .notification-title,
        .dark-mode .message-sender {
            color: #e0e0e0;
        }
        
        .switch {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 20px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 2px;
            bottom: 2px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: #667eea;
        }
        
        input:checked + .slider:before {
            transform: translateX(20px);
        }
        
        /* Dropdown Backdrop */
        .dropdown-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.22);
            backdrop-filter: blur(2px);
            z-index: 1090;
        }
        
        .dropdown-backdrop.active {
            display: block;
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 70px;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(6px);
            z-index: 998;
            opacity: 0;
            transition: opacity 0.25s ease;
        }

        .sidebar-backdrop.active {
            display: block;
            opacity: 1;
        }
        
        /* Responsive Breakpoints */
        @media (max-width: 1200px) {
            .nav-container {
                padding: 0 1rem;
            }
            
            .search-container {
                width: 250px;
            }
            
            .nav-action-btn span {
                display: none;
            }
            
            .nav-action-btn i {
                margin-right: 0;
            }
        }
        
        @media (max-width: 1024px) {
            .nav-center {
                display: none;
            }
            
            .logo-section {
                flex: 1;
            }
            
            .mobile-toggle {
                display: flex;
            }

            .main-content {
                margin-left: 0 !important;
                padding-left: 1rem !important;
                padding-right: 1rem !important;
            }
        }
        
        @media (max-width: 768px) {
            .nav-container {
                padding: 0 0.75rem;
            }
            
            .logo {
                width: 38px;
                height: 38px;
            }
            
            .school-name .full-name {
                display: none;
            }
            
            .school-name .abbrev-name {
                display: inline;
                font-size: 1rem;
            }
            
            .school-location {
                display: none;
            }
            
            .user-info {
                display: none !important;
            }
            
            .user-profile {
                padding: 0.2rem;
                background: transparent;
            }
            
            .profile-avatar {
                width: 34px;
                height: 34px;
            }
            
            .nav-icon-btn {
                width: 36px;
                height: 36px;
                font-size: 1rem;
            }
            
            .dropdown-arrow {
                display: none;
            }
            
            .dropdown-menu-container {
                position: fixed;
                top: calc(70px + 8px);
                left: 12px !important;
                right: 12px !important;
                width: auto !important;
                max-width: none;
                min-width: 0;
                max-height: calc(100dvh - 90px);
                overflow-y: auto;
                border-radius: 18px;
                box-shadow: 0 18px 40px rgba(15, 23, 42, 0.18);
            }
            
            .notifications-wrapper .dropdown-menu-container,
            .messages-wrapper .dropdown-menu-container {
                left: 12px !important;
                right: 12px !important;
            }

            .user-profile .dropdown-menu-container {
                left: auto !important;
                right: 12px !important;
                width: min(320px, calc(100vw - 24px)) !important;
            }

            .main-content {
                padding: 0.85rem !important;
                padding-bottom: calc(1rem + env(safe-area-inset-bottom)) !important;
            }

            .dropdown-backdrop {
                background: rgba(15, 23, 42, 0.08);
                backdrop-filter: none;
            }
        }
        
        @media (max-width: 480px) {
            .nav-container {
                padding: 0 0.5rem;
            }
            
            .logo {
                width: 32px;
                height: 32px;
            }
            
            .school-name .abbrev-name {
                font-size: 0.9rem;
            }
            
            .mobile-toggle {
                width: 34px;
                height: 34px;
                font-size: 1rem;
            }
            
            .profile-avatar {
                width: 30px;
                height: 30px;
                font-size: 0.8rem;
            }
            
            .nav-icon-btn {
                width: 32px;
                height: 32px;
                font-size: 0.9rem;
            }
            
            .user-section {
                gap: 0.25rem;
            }
            
            .dropdown-menu-container {
                top: calc(64px + 8px);
                left: 10px !important;
                right: 10px !important;
                max-height: calc(100dvh - 82px);
            }

            .user-profile .dropdown-menu-container {
                left: auto !important;
                right: 10px !important;
                width: min(300px, calc(100vw - 20px)) !important;
            }

            .main-content {
                padding: 0.75rem !important;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar Component -->
    <nav class="top-navbar">
        <div class="nav-container">
            <!-- Left Section - Always at the beginning -->
            <div class="logo-section">
                <button class="mobile-toggle" id="sidebarToggle" aria-label="Toggle Sidebar">
                    <i class="fas fa-bars"></i>
                </button>
                
                <div class="logo">
                    <img src="<?php 
                        if ($school_logo) {
                            $path = (strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false || strpos($_SERVER['SCRIPT_NAME'], '/teacher/') !== false || strpos($_SERVER['SCRIPT_NAME'], '/librarian/') !== false || strpos($_SERVER['SCRIPT_NAME'], '/accountant/') !== false) ? '../' : '';
                            echo $path . 'uploads/logos/' . $school_logo;
                        } else {
                            echo (strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false || strpos($_SERVER['SCRIPT_NAME'], '/teacher/') !== false || strpos($_SERVER['SCRIPT_NAME'], '/librarian/') !== false || strpos($_SERVER['SCRIPT_NAME'], '/accountant/') !== false) ? '../uploads/logos/logo.png' : 'uploads/logos/logo.png';
                        }
                    ?>" alt="School Logo" class="logo-img" loading="lazy">
                </div>
                
                <div class="school-info">
                    <div class="school-name">
                        <span class="full-name"><?php echo htmlspecialchars($school_name); ?></span>
                        <span class="abbrev-name"><?php echo htmlspecialchars($abbreviated_school); ?></span>
                    </div>
                    <div class="school-location"><?php echo htmlspecialchars($school_location); ?></div>
                </div>
            </div>
            
            <!-- Center Section -->
            <div class="nav-center">
                <div class="search-container">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="search-input" placeholder="Search...">
                </div>
            </div>
            
            <!-- Right Section -->
            <div class="user-section">
                <!-- Notifications -->
                <div class="notifications-wrapper" id="notificationsWrapper">
                    <button class="nav-icon-btn" id="notificationsBtn" aria-label="Notifications">
                        <i class="fas fa-bell"></i>
                        <?php if ($notification_count > 0): ?>
                            <span class="notification-badge"><?php echo $notification_count > 9 ? '9+' : $notification_count; ?></span>
                        <?php endif; ?>
                    </button>
                    
                    <div class="dropdown-menu-container">
                        <div class="notifications-header">
                            <h3>Notifications</h3>
                            <?php if ($notification_count > 0): ?>
                                <form method="POST" style="display: inline;">
                                    <button type="submit" name="mark_all_read" class="mark-all-read">Mark all as read</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div class="notifications-list">
                            <?php if (empty($recent_notifications)): ?>
                                <div class="notification-item empty">
                                    <div style="text-align: center; padding: 2rem; color: #6c757d;">
                                        <i class="fas fa-bell-slash" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                        <div>No new notifications</div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recent_notifications as $notification): ?>
                                    <?php $icon_data = getNotificationIcon($notification['type']); ?>
                                    <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>" 
                                         data-notification-id="<?php echo $notification['id']; ?>"
                                         onclick="window.location.href='notifications.php?id=<?php echo $notification['id']; ?>'">
                                        <div style="display: flex; align-items: flex-start;">
                                            <div class="notification-icon" style="background: <?php echo $notification['icon_color'] ?: $icon_data['color']; ?>;">
                                                <i class="<?php echo $notification['icon'] ?: $icon_data['icon']; ?>"></i>
                                            </div>
                                            <div class="notification-content">
                                                <div class="notification-title"><?php echo htmlspecialchars($notification['title']); ?></div>
                                                <div class="notification-message"><?php echo htmlspecialchars(substr($notification['message'], 0, 50)) . (strlen($notification['message']) > 50 ? '...' : ''); ?></div>
                                                <div class="notification-time"><?php echo timeAgo($notification['created_at']); ?></div>
                                            </div>
                                            <?php if (!$notification['is_read']): ?>
                                                <button class="mark-single-read" title="Mark as read" onclick="event.stopPropagation(); markNotificationRead(<?php echo $notification['id']; ?>, this)">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="notifications-footer">
                            <a href="notifications.php" class="view-all-notifications">
                                <i class="fas fa-arrow-right"></i> View All Notifications
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Messages -->
                <div class="messages-wrapper" id="messagesWrapper">
                    <button class="nav-icon-btn" id="messagesBtn" aria-label="Messages">
                        <i class="fas fa-envelope"></i>
                        <?php if ($message_count > 0): ?>
                            <span class="notification-badge" style="background: #27ae60;"><?php echo $message_count > 9 ? '9+' : $message_count; ?></span>
                        <?php endif; ?>
                    </button>
                    
                    <div class="dropdown-menu-container">
                        <div class="messages-header">
                            <h3>Messages</h3>
                            <a href="messages.php" class="view-all-messages">View All</a>
                        </div>
                        <div class="messages-list">
                            <?php if (empty($recent_messages)): ?>
                                <div class="message-item empty">
                                    <div style="text-align: center; padding: 2rem; color: #6c757d;">
                                        <i class="fas fa-envelope-open" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                        <div>No new messages</div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recent_messages as $message): ?>
                                    <div class="message-item <?php echo $message['is_read'] ? '' : 'unread'; ?>" onclick="window.location.href='messages.php?view=conversation&conversation=<?php echo $message['sender_id']; ?>'">
                                        <div style="display: flex; align-items: flex-start;">
                                            <div class="message-avatar">
                                                <?php echo strtoupper(substr($message['sender_name'], 0, 1)); ?>
                                            </div>
                                            <div class="message-content">
                                                <div class="message-sender">
                                                    <?php echo htmlspecialchars($message['sender_name']); ?>
                                                    <span class="sender-role">(<?php echo ucfirst($message['sender_role']); ?>)</span>
                                                </div>
                                                <div class="message-subject"><?php echo htmlspecialchars($message['subject']); ?></div>
                                                <div class="message-preview"><?php echo htmlspecialchars(substr($message['message'], 0, 40)) . '...'; ?></div>
                                                <div class="message-time"><?php echo timeAgo($message['created_at']); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="messages-footer">
                            <a href="messages.php" class="compose-message">
                                <i class="fas fa-edit"></i> Compose New Message
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- User Profile -->
                <div class="user-profile" id="userProfile">
                    <button type="button" class="user-profile-trigger" id="userProfileTrigger" aria-expanded="false" aria-haspopup="true">
                        <div class="profile-avatar" style="<?php echo ($current_user && getProfilePictureUrl($current_user)) ? 'background-image: url(\'' . getProfilePictureUrl($current_user) . '\')' : ''; ?>">
                            <?php if (!$current_user || !getProfilePictureUrl($current_user)): ?>
                                <?php echo $current_user ? strtoupper(substr($current_user['full_name'], 0, 1)) : 'U'; ?>
                            <?php endif; ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name"><?php echo $current_user ? htmlspecialchars($current_user['full_name']) : 'User'; ?></div>
                            <div class="user-role"><?php echo $current_user ? htmlspecialchars($current_user['role']) : 'guest'; ?></div>
                        </div>
                        <i class="fas fa-chevron-down dropdown-arrow"></i>
                    </button>
                    
                    <!-- User Dropdown Menu -->
                    <div class="dropdown-menu-container">
                        <div class="dropdown-header">
                            <div class="dropdown-avatar" style="<?php echo ($current_user && getProfilePictureUrl($current_user)) ? 'background-image: url(\'' . getProfilePictureUrl($current_user) . '\')' : ''; ?>">
                                <?php if (!$current_user || !getProfilePictureUrl($current_user)): ?>
                                    <?php echo $current_user ? strtoupper(substr($current_user['full_name'], 0, 1)) : 'U'; ?>
                                <?php endif; ?>
                            </div>
                            <div class="dropdown-user-name"><?php echo $current_user ? htmlspecialchars($current_user['full_name']) : 'User'; ?></div>
                            <div class="dropdown-user-role"><?php echo $current_user ? ucfirst(htmlspecialchars($current_user['role'])) : 'Guest'; ?></div>
                        </div>
                        
                        <div class="dropdown-menu">
                            <a href="profile.php" class="dropdown-item">
                                <i class="fas fa-user"></i>
                                My Profile
                            </a>
                            <a href="messages.php" class="dropdown-item">
                                <i class="fas fa-envelope"></i>
                                Messages
                                <?php if ($message_count > 0): ?>
                                    <span style="margin-left: auto; background: #27ae60; color: white; padding: 0.2rem 0.5rem; border-radius: 10px; font-size: 0.7rem;"><?php echo $message_count; ?> New</span>
                                <?php endif; ?>
                            </a>
                            <a href="notifications.php" class="dropdown-item">
                                <i class="fas fa-bell"></i>
                                Notifications
                                <?php if ($notification_count > 0): ?>
                                    <span style="margin-left: auto; background: #667eea; color: white; padding: 0.2rem 0.5rem; border-radius: 10px; font-size: 0.7rem;"><?php echo $notification_count; ?> New</span>
                                <?php endif; ?>
                            </a>                          
                            
                            <div class="dropdown-divider"></div>
                            
                            <a href="#" class="dropdown-item" onclick="toggleDarkMode(event)">
                                <i class="fas fa-moon"></i>
                                Dark Mode
                                <span style="margin-left: auto;">
                                    <label class="switch" onclick="event.stopPropagation()">
                                        <input type="checkbox" id="darkModeToggle">
                                        <span class="slider"></span>
                                    </label>
                                </span>
                            </a>
                            
                            <div class="dropdown-divider"></div>
                            
                            <a href="../logout.php" class="dropdown-item" style="color: #e74c3c;">
                                <i class="fas fa-sign-out-alt"></i>
                                Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Dropdown Backdrop -->
    <div class="dropdown-backdrop" id="dropdownBackdrop"></div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Sidebar toggle functionality
            const sidebarToggleBtn = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');

            if (sidebarToggleBtn && sidebar) {
                const backdrop = document.querySelector('.sidebar-backdrop') || (() => {
                    const b = document.createElement('div');
                    b.className = 'sidebar-backdrop';
                    document.body.appendChild(b);
                    return b;
                })();

                function syncSidebarBodyState() {
                    const isMobile = window.innerWidth <= 1024;
                    const isMobileOpen = sidebar.classList.contains('mobile-open');
                    const isCollapsed = sidebar.classList.contains('collapsed');

                    document.body.classList.toggle('sidebar-mobile-open', isMobile && isMobileOpen);
                    document.body.classList.toggle('sidebar-collapsed', !isMobile && isCollapsed);
                    sidebarToggleBtn.setAttribute('aria-expanded', isMobile ? String(isMobileOpen) : String(!isCollapsed));
                }

                function toggleSidebar() {
                    if (window.innerWidth <= 1024) {
                        const isMobileOpen = sidebar.classList.contains('mobile-open');
                        if (isMobileOpen) {
                            sidebar.classList.remove('mobile-open');
                            backdrop.classList.remove('active');
                        } else {
                            sidebar.classList.add('mobile-open');
                            backdrop.classList.add('active');
                        }
                    } else {
                        const isCollapsed = sidebar.classList.contains('collapsed');
                        if (isCollapsed) {
                            sidebar.classList.remove('collapsed');
                        } else {
                            sidebar.classList.add('collapsed');
                        }
                    }
                    syncSidebarBodyState();
                }

                sidebarToggleBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    toggleSidebar();
                });

                backdrop.addEventListener('click', () => {
                    if (sidebar.classList.contains('mobile-open')) {
                        sidebar.classList.remove('mobile-open');
                        backdrop.classList.remove('active');
                        syncSidebarBodyState();
                    }
                });

                window.addEventListener('resize', () => {
                    if (window.innerWidth > 1024) {
                        sidebar.classList.remove('mobile-open');
                        backdrop.classList.remove('active');
                    } else {
                        sidebar.classList.remove('collapsed');
                    }
                    syncSidebarBodyState();
                });

                syncSidebarBodyState();
            }

            // Dropdown functionality
            const userProfile = document.getElementById('userProfile');
            const userProfileTrigger = document.getElementById('userProfileTrigger');
            const notificationsWrapper = document.getElementById('notificationsWrapper');
            const messagesWrapper = document.getElementById('messagesWrapper');
            const dropdownBackdrop = document.getElementById('dropdownBackdrop');

            function closeAllDropdowns() {
                userProfile?.classList.remove('active');
                userProfileTrigger?.setAttribute('aria-expanded', 'false');
                notificationsWrapper?.classList.remove('active');
                messagesWrapper?.classList.remove('active');
                dropdownBackdrop?.classList.remove('active');
                
                document.querySelectorAll('.dropdown-menu-container').forEach(container => {
                    container.style.removeProperty('top');
                    container.style.removeProperty('bottom');
                });
            }

            function positionDropdown(dropdownMenu) {
                if (!dropdownMenu || window.innerWidth <= 768) return;
                
                const rect = dropdownMenu.getBoundingClientRect();
                const viewportHeight = window.innerHeight;
                
                if (rect.bottom > viewportHeight - 20) {
                    dropdownMenu.style.top = 'auto';
                    dropdownMenu.style.bottom = 'calc(100% + 10px)';
                } else {
                    dropdownMenu.style.top = 'calc(100% + 10px)';
                    dropdownMenu.style.bottom = 'auto';
                }
            }

            if (userProfile && userProfileTrigger) {
                userProfileTrigger.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const wasActive = userProfile.classList.contains('active');
                    closeAllDropdowns();
                    if (!wasActive) {
                        userProfile.classList.add('active');
                        userProfileTrigger.setAttribute('aria-expanded', 'true');
                        const dropdownMenu = userProfile.querySelector('.dropdown-menu-container');
                        if (dropdownMenu) {
                            positionDropdown(dropdownMenu);
                        }
                        if (window.innerWidth <= 768) {
                            dropdownBackdrop.classList.add('active');
                        }
                    }
                });
            }

            if (notificationsWrapper) {
                const notificationsBtn = notificationsWrapper.querySelector('#notificationsBtn');
                notificationsBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const wasActive = notificationsWrapper.classList.contains('active');
                    closeAllDropdowns();
                    if (!wasActive) {
                        notificationsWrapper.classList.add('active');
                        const dropdownMenu = notificationsWrapper.querySelector('.dropdown-menu-container');
                        if (dropdownMenu) {
                            positionDropdown(dropdownMenu);
                        }
                        if (window.innerWidth <= 768) {
                            dropdownBackdrop.classList.add('active');
                        }
                    }
                });
            }

            if (messagesWrapper) {
                const messagesBtn = messagesWrapper.querySelector('#messagesBtn');
                messagesBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const wasActive = messagesWrapper.classList.contains('active');
                    closeAllDropdowns();
                    if (!wasActive) {
                        messagesWrapper.classList.add('active');
                        const dropdownMenu = messagesWrapper.querySelector('.dropdown-menu-container');
                        if (dropdownMenu) {
                            positionDropdown(dropdownMenu);
                        }
                        if (window.innerWidth <= 768) {
                            dropdownBackdrop.classList.add('active');
                        }
                    }
                });
            }

            document.addEventListener('click', closeAllDropdowns);

            document.querySelectorAll('.dropdown-menu-container').forEach(dropdown => {
                dropdown.addEventListener('click', (e) => e.stopPropagation());
            });

            document.querySelectorAll('.dropdown-menu-container a, .dropdown-menu-container button, .dropdown-menu-container input, .dropdown-menu-container label').forEach(item => {
                item.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const keepOpen = item.closest('.switch') || item.id === 'darkModeToggle';

                    if (window.innerWidth <= 768 && item.tagName === 'A') {
                        const href = item.getAttribute('href');
                        const target = item.getAttribute('target');
                        const isActionLink = href && href !== '#' && !keepOpen;

                        if (isActionLink) {
                            e.preventDefault();
                            closeAllDropdowns();

                            if (target === '_blank') {
                                window.open(item.href, '_blank', 'noopener');
                            } else {
                                window.location.href = item.href;
                            }
                            return;
                        }
                    }

                    if (!keepOpen && window.innerWidth <= 768) {
                        setTimeout(closeAllDropdowns, 120);
                    }
                });
            });

            dropdownBackdrop.addEventListener('click', closeAllDropdowns);

            let scrollTimeout;
            window.addEventListener('scroll', () => {
                clearTimeout(scrollTimeout);
                scrollTimeout = setTimeout(() => {
                    if (userProfile?.classList.contains('active')) {
                        const dropdownMenu = userProfile.querySelector('.dropdown-menu-container');
                        if (dropdownMenu) positionDropdown(dropdownMenu);
                    }
                    if (notificationsWrapper?.classList.contains('active')) {
                        const dropdownMenu = notificationsWrapper.querySelector('.dropdown-menu-container');
                        if (dropdownMenu) positionDropdown(dropdownMenu);
                    }
                    if (messagesWrapper?.classList.contains('active')) {
                        const dropdownMenu = messagesWrapper.querySelector('.dropdown-menu-container');
                        if (dropdownMenu) positionDropdown(dropdownMenu);
                    }
                }, 100);
            });

            window.addEventListener('resize', () => {
                if (window.innerWidth <= 768) {
                    document.querySelectorAll('.dropdown-menu-container').forEach(container => {
                        container.style.removeProperty('top');
                        container.style.removeProperty('bottom');
                    });
                } else {
                    if (userProfile?.classList.contains('active')) {
                        const dropdownMenu = userProfile.querySelector('.dropdown-menu-container');
                        if (dropdownMenu) positionDropdown(dropdownMenu);
                    }
                    if (notificationsWrapper?.classList.contains('active')) {
                        const dropdownMenu = notificationsWrapper.querySelector('.dropdown-menu-container');
                        if (dropdownMenu) positionDropdown(dropdownMenu);
                    }
                    if (messagesWrapper?.classList.contains('active')) {
                        const dropdownMenu = messagesWrapper.querySelector('.dropdown-menu-container');
                        if (dropdownMenu) positionDropdown(dropdownMenu);
                    }
                }
            });

            // Dark mode toggle
            const darkModeToggle = document.getElementById('darkModeToggle');
            if (darkModeToggle) {
                darkModeToggle.addEventListener('change', function() {
                    if (this.checked) {
                        document.body.classList.add('dark-mode');
                        localStorage.setItem('darkMode', 'enabled');
                    } else {
                        document.body.classList.remove('dark-mode');
                        localStorage.setItem('darkMode', 'disabled');
                    }
                });

                if (localStorage.getItem('darkMode') === 'enabled') {
                    darkModeToggle.checked = true;
                    document.body.classList.add('dark-mode');
                }
            }

            // Keyboard shortcuts
            document.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                    e.preventDefault();
                    document.querySelector('.search-input')?.focus();
                }
                
                if (e.key === 'Escape') {
                    closeAllDropdowns();
                }
            });

            // Search functionality
            const searchInput = document.querySelector('.search-input');
            if (searchInput) {
                searchInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        const query = searchInput.value.trim();
                        if (query) {
                            window.location.href = 'search.php?q=' + encodeURIComponent(query);
                        }
                    }
                });
            }
        });

        // Mark notification as read
        function markNotificationRead(notificationId, button) {
            event.stopPropagation();
            const notificationItem = button.closest('.notification-item');
            
            notificationItem.classList.remove('unread');
            button.remove();
            
            const badge = document.querySelector('#notificationsBtn .notification-badge');
            if (badge) {
                let count = parseInt(badge.textContent);
                if (count > 1) {
                    badge.textContent = count - 1;
                } else {
                    badge.remove();
                }
            }
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'mark_notification_read=true&notification_id=' + notificationId
            });
        }

        // Toggle dark mode
        function toggleDarkMode(event) {
            event.preventDefault();
            const toggle = document.getElementById('darkModeToggle');
            toggle.checked = !toggle.checked;
            toggle.dispatchEvent(new Event('change'));
        }
    </script>
</body>
</html>
