<?php
include '../config.php';
checkAuth();

$user_id = $_SESSION['user_id'];

// Get user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['create_notification'])) {
        $title = $_POST['title'];
        $message = $_POST['message'];
        $type = $_POST['type'];
        $target_users = $_POST['target_users'] ?? 'all';
        $specific_users = $_POST['specific_users'] ?? [];
        $priority = $_POST['priority'] ?? 'medium';
        
        // Map priority values to database enum values
        $priority_map = [
            'low' => 'low',
            'medium' => 'normal',
            'high' => 'high'
        ];
        $priority = $priority_map[$priority] ?? 'normal';
        
        if (!empty($title) && !empty($message)) {
            // Handle different target user scenarios
            if ($target_users === 'all') {
                // Send to all users (user_id = NULL)
                $stmt = $pdo->prepare("INSERT INTO notifications (title, message, type, priority, icon, icon_color) VALUES (?, ?, ?, ?, ?, ?)");
                $icon_data = getNotificationIcon($type);
                $stmt->execute([$title, $message, $type, $priority, $icon_data['icon'], $icon_data['color']]);
            } elseif ($target_users === 'specific' && !empty($specific_users)) {
                // Send to specific users
                $icon_data = getNotificationIcon($type);
                foreach ($specific_users as $target_user_id) {
                    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, priority, icon, icon_color) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$target_user_id, $title, $message, $type, $priority, $icon_data['icon'], $icon_data['color']]);
                }
            } elseif ($target_users === 'role') {
                // Send to users by role
                $role = $_POST['target_role'] ?? '';
                if (!empty($role)) {
                    $users_stmt = $pdo->prepare("SELECT id FROM users WHERE role = ?");
                    $users_stmt->execute([$role]);
                    $target_users = $users_stmt->fetchAll();
                    
                    $icon_data = getNotificationIcon($type);
                    foreach ($target_users as $target_user) {
                        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, priority, icon, icon_color) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$target_user['id'], $title, $message, $type, $priority, $icon_data['icon'], $icon_data['color']]);
                    }
                }
            }
            
            $success = "Notification created and sent successfully!";
        } else {
            $error = "Please fill in all required fields.";
        }
    }
    
    if (isset($_POST['mark_all_read'])) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $success = "All notifications marked as read!";
    }
    
    if (isset($_POST['mark_as_read'])) {
        $notification_id = $_POST['notification_id'];
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$notification_id, $user_id]);
        $success = "Notification marked as read!";
    }
    
    if (isset($_POST['delete_notification'])) {
        $notification_id = $_POST['notification_id'];
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$notification_id, $user_id]);
        $success = "Notification deleted!";
    }
    
    if (isset($_POST['clear_all'])) {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $success = "All notifications cleared!";
    }
    
    // Mark single notification as read via AJAX-style POST
    if (isset($_POST['quick_mark_read'])) {
        $notification_id = $_POST['notification_id'];
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        if ($stmt->execute([$notification_id, $user_id])) {
            echo json_encode(['success' => true]);
            exit;
        }
    }
}

// Get filter parameters
$filter = $_GET['filter'] ?? 'all';
$type = $_GET['type'] ?? 'all';
$date_range = $_GET['date_range'] ?? 'all';

// Build query based on filters
$query = "SELECT * FROM notifications WHERE (user_id = ? OR user_id IS NULL)";
$params = [$user_id];

if ($filter === 'unread') {
    $query .= " AND is_read = 0";
} elseif ($filter === 'read') {
    $query .= " AND is_read = 1";
}

if ($type !== 'all') {
    $query .= " AND type = ?";
    $params[] = $type;
}

if ($date_range !== 'all') {
    $date_condition = "";
    switch ($date_range) {
        case 'today':
            $date_condition = "DATE(created_at) = CURDATE()";
            break;
        case 'yesterday':
            $date_condition = "DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'week':
            $date_condition = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $date_condition = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
    }
    $query .= " AND $date_condition";
}

$query .= " ORDER BY 
    CASE priority 
        WHEN 'high' THEN 1 
        WHEN 'normal' THEN 2 
        WHEN 'low' THEN 3 
    END, 
    created_at DESC";

// Get notifications count for stats
$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id = ? OR user_id IS NULL)");
$total_stmt->execute([$user_id]);
$total_count = $total_stmt->fetchColumn();

$unread_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0");
$unread_stmt->execute([$user_id]);
$unread_count = $unread_stmt->fetchColumn();

$read_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND is_read = 1");
$read_stmt->execute([$user_id]);
$read_count = $read_stmt->fetchColumn();

// Get notifications with filters
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$notifications = $stmt->fetchAll();

// Get all users for notification targeting (only for admins)
$all_users = [];
if ($user['role'] === 'admin') {
    $users_stmt = $pdo->prepare("SELECT id, full_name, role FROM users WHERE id != ? ORDER BY full_name");
    $users_stmt->execute([$user_id]);
    $all_users = $users_stmt->fetchAll();
}

// Function to get icon and color based on notification type
function getNotificationIcon($type) {
    $icons = [
        'attendance' => ['icon' => 'fas fa-user-clock', 'color' => '#3498db', 'bg_color' => 'rgba(52, 152, 219, 0.1)'],
        'fee' => ['icon' => 'fas fa-money-bill-wave', 'color' => '#27ae60', 'bg_color' => 'rgba(39, 174, 96, 0.1)'],
        'library' => ['icon' => 'fas fa-book', 'color' => '#f39c12', 'bg_color' => 'rgba(243, 156, 18, 0.1)'],
        'system' => ['icon' => 'fas fa-exclamation-triangle', 'color' => '#e74c3c', 'bg_color' => 'rgba(231, 76, 60, 0.1)'],
        'academic' => ['icon' => 'fas fa-graduation-cap', 'color' => '#9b59b6', 'bg_color' => 'rgba(155, 89, 182, 0.1)'],
        'approval' => ['icon' => 'fas fa-circle-check', 'color' => '#16a34a', 'bg_color' => 'rgba(22, 163, 74, 0.1)'],
        'request' => ['icon' => 'fas fa-paper-plane', 'color' => '#0ea5e9', 'bg_color' => 'rgba(14, 165, 233, 0.1)'],
        'exam_portal' => ['icon' => 'fas fa-door-open', 'color' => '#2563eb', 'bg_color' => 'rgba(37, 99, 235, 0.1)'],
        'results' => ['icon' => 'fas fa-chart-line', 'color' => '#7c3aed', 'bg_color' => 'rgba(124, 58, 237, 0.1)'],
        'maintenance' => ['icon' => 'fas fa-screwdriver-wrench', 'color' => '#dc2626', 'bg_color' => 'rgba(220, 38, 38, 0.1)'],
        'maintenance_complete' => ['icon' => 'fas fa-server', 'color' => '#15803d', 'bg_color' => 'rgba(21, 128, 61, 0.1)'],
        'payroll' => ['icon' => 'fas fa-money-check-dollar', 'color' => '#0f766e', 'bg_color' => 'rgba(15, 118, 110, 0.1)'],
        'general' => ['icon' => 'fas fa-bell', 'color' => '#95a5a6', 'bg_color' => 'rgba(149, 165, 166, 0.1)']
    ];
    
    return $icons[$type] ?? $icons['general'];
}

// Function to format time difference
function timeAgo($timestamp) {
    $time_ago = strtotime($timestamp);
    $current_time = time();
    $time_difference = $current_time - $time_ago;
    
    if ($time_difference < 60) {
        return 'Just now';
    } elseif ($time_difference < 3600) {
        $minutes = round($time_difference / 60);
        return $minutes . ' min ago';
    } elseif ($time_difference < 86400) {
        $hours = round($time_difference / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($time_difference < 604800) {
        $days = round($time_difference / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time_ago);
    }
}

$page_title = "Notifications - " . SCHOOL_NAME;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
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
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
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

        .notifications-container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Page Header */
        .page-header {
            background: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            border-left: 5px solid var(--secondary);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--secondary), var(--purple), var(--warning), var(--success));
        }

        .page-header h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-header p {
            color: var(--gray);
            font-size: 1rem;
            margin-bottom: 1rem;
        }

        .unread-badge-header {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(243, 156, 18, 0.1);
            color: var(--warning);
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            text-align: center;
            border-left: 4px solid;
            cursor: pointer;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card.total {
            border-left-color: var(--secondary);
        }

        .stat-card.unread {
            border-left-color: var(--warning);
        }

        .stat-card.read {
            border-left-color: var(--success);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Header Actions */
        .header-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
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
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary), var(--purple));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), var(--success-light));
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--light);
            color: var(--gray);
        }

        .btn-outline:hover {
            border-color: var(--secondary);
            color: var(--secondary);
            transform: translateY(-2px);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), var(--danger-light));
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Create Notification Section */
        .create-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            border-left: 5px solid var(--info);
            display: none;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .section-header h3 {
            font-size: 1.3rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .create-form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
        }

        .form-hint {
            font-size: 0.8rem;
            color: var(--gray);
            margin-top: 0.3rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--light);
            border-radius: 8px;
            font-size: 1rem;
            transition: var(--transition);
            background: white;
            color: var(--dark);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 120px;
        }

        select.form-control {
            cursor: pointer;
        }

        /* Target Options */
        .target-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .target-option {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            border: 2px solid var(--light);
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
        }

        .target-option:hover {
            border-color: var(--secondary);
            transform: translateY(-2px);
        }

        .target-option.selected {
            border-color: var(--secondary);
            background: rgba(52, 152, 219, 0.05);
        }

        .target-option i {
            font-size: 1.5rem;
            color: var(--secondary);
        }

        .target-option-content {
            flex: 1;
        }

        .target-option-title {
            font-weight: 600;
            color: var(--dark);
        }

        .target-option-desc {
            font-size: 0.8rem;
            color: var(--gray);
        }

        /* Users Select */
        .users-select {
            max-height: 200px;
            overflow-y: auto;
            border: 2px solid var(--light);
            border-radius: 8px;
            padding: 0.5rem;
        }

        .user-option {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .user-option:hover {
            background: var(--light);
        }

        .user-option input {
            margin: 0;
        }

        /* Priority Options */
        .priority-options {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .priority-option {
            flex: 1;
            text-align: center;
            padding: 0.75rem;
            border: 2px solid var(--light);
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
        }

        .priority-option:hover {
            transform: translateY(-2px);
        }

        .priority-option.selected {
            border-color: var(--secondary);
            background: rgba(52, 152, 219, 0.05);
        }

        .priority-option.low .priority-title {
            color: var(--success);
        }

        .priority-option.medium .priority-title {
            color: var(--warning);
        }

        .priority-option.high .priority-title {
            color: var(--danger);
        }

        .priority-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .priority-desc {
            font-size: 0.8rem;
            color: var(--gray);
        }

        /* Filters Section */
        .filters-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .filter-group {
            margin-bottom: 0.5rem;
        }

        .filter-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
        }

        .filter-select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--light);
            border-radius: 8px;
            font-size: 1rem;
            background: white;
            color: var(--dark);
            transition: var(--transition);
            cursor: pointer;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        /* Notifications List */
        .notifications-list {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }

        .list-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }

        .list-header h3 {
            font-size: 1.2rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .list-count {
            color: var(--gray);
            font-size: 0.9rem;
            padding: 0.3rem 0.8rem;
            background: white;
            border-radius: 20px;
        }

        /* Notification Item */
        .notification-item {
            padding: 1.5rem;
            border-bottom: 1px solid var(--light);
            transition: var(--transition);
            position: relative;
            cursor: pointer;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-item:hover {
            background: var(--light);
        }

        .notification-item.unread {
            background: rgba(52, 152, 219, 0.05);
            border-left: 4px solid var(--secondary);
        }

        .notification-content {
            display: flex;
            align-items: flex-start;
            gap: 1.5rem;
        }

        .notification-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .notification-details {
            flex: 1;
        }

        .notification-header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 1rem;
            margin-bottom: 0.5rem;
        }

        .notification-title {
            font-weight: 600;
            color: var(--dark);
            font-size: 1.1rem;
        }

        .notification-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            background: rgba(52, 152, 219, 0.1);
            color: var(--secondary);
        }

        .notification-priority {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .priority-high {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }

        .priority-medium {
            background: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }

        .priority-low {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
        }

        .notification-message {
            color: var(--dark);
            margin-bottom: 0.5rem;
            line-height: 1.5;
        }

        .notification-meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 1rem;
            color: var(--gray);
            font-size: 0.85rem;
        }

        .notification-time i {
            margin-right: 0.3rem;
        }

        .notification-read-status {
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .read-badge {
            color: var(--success);
        }

        .unread-badge {
            color: var(--warning);
            font-weight: 600;
        }

        .notification-actions {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            display: flex;
            gap: 0.5rem;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .notification-item:hover .notification-actions {
            opacity: 1;
        }

        .action-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 500;
            transition: var(--transition);
        }

        .action-btn.mark-read {
            background: rgba(52, 152, 219, 0.1);
            color: var(--secondary);
        }

        .action-btn.mark-read:hover {
            background: var(--secondary);
            color: white;
            transform: translateY(-2px);
        }

        .action-btn.delete {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }

        .action-btn.delete:hover {
            background: var(--danger);
            color: white;
            transform: translateY(-2px);
        }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 0.5rem;
        }

        .quick-action-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: none;
            background: white;
            color: var(--gray);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
        }

        .quick-action-btn:hover {
            background: var(--secondary);
            color: white;
            transform: rotate(15deg);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            opacity: 0.3;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            margin-bottom: 2rem;
        }

        /* Alert */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            justify-content: space-between;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
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

        .close-alert {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            font-size: 1.2rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .create-form {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .header-actions {
                flex-direction: column;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .notification-content {
                flex-direction: column;
                text-align: center;
            }

            .notification-header {
                flex-direction: column;
                text-align: center;
            }

            .notification-actions {
                position: static;
                opacity: 1;
                margin-top: 1rem;
                justify-content: center;
            }

            .quick-actions {
                position: static;
                margin-top: 1rem;
                justify-content: center;
            }

            .priority-options {
                flex-direction: column;
            }

            .target-options {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        <div class="notifications-container">
            <!-- Page Header -->
            <div class="page-header">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <h1>
                            <i class="fas fa-bell" style="color: var(--secondary);"></i>
                            Notifications
                        </h1>
                        <p>Manage and view all your system notifications</p>
                    </div>
                    <?php if ($unread_count > 0): ?>
                        <div class="unread-badge-header">
                            <i class="fas fa-circle"></i>
                            <?php echo $unread_count; ?> unread notification<?php echo $unread_count > 1 ? 's' : ''; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Header Actions -->
                <div class="header-actions">
                    <?php if ($user['role'] === 'admin'): ?>
                        <button class="btn btn-primary" onclick="toggleCreateSection()">
                            <i class="fas fa-plus"></i> Create Notification
                        </button>
                    <?php endif; ?>
                    
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="mark_all_read" class="btn btn-success" <?php echo $unread_count == 0 ? 'disabled' : ''; ?>>
                            <i class="fas fa-check-double"></i> Mark All as Read
                        </button>
                    </form>
                    
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to clear all notifications? This action cannot be undone.')">
                        <button type="submit" name="clear_all" class="btn btn-danger" <?php echo $total_count == 0 ? 'disabled' : ''; ?>>
                            <i class="fas fa-trash-alt"></i> Clear All
                        </button>
                    </form>
                    
                    <a href="notifications.php" class="btn btn-outline">
                        <i class="fas fa-sync"></i> Refresh
                    </a>
                </div>

                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card total" onclick="window.location.href='notifications.php?filter=all'">
                        <div class="stat-value"><?php echo $total_count; ?></div>
                        <div class="stat-label">Total Notifications</div>
                    </div>
                    <div class="stat-card unread" onclick="window.location.href='notifications.php?filter=unread'">
                        <div class="stat-value"><?php echo $unread_count; ?></div>
                        <div class="stat-label">Unread</div>
                    </div>
                    <div class="stat-card read" onclick="window.location.href='notifications.php?filter=read'">
                        <div class="stat-value"><?php echo $read_count; ?></div>
                        <div class="stat-label">Read</div>
                    </div>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    <div>
                        <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    </div>
                    <button class="close-alert" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <div>
                        <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                    </div>
                    <button class="close-alert" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Create Notification Section (Admin Only) -->
            <?php if ($user['role'] === 'admin'): ?>
                <div class="create-section" id="createSection">
                    <div class="section-header">
                        <h3>
                            <i class="fas fa-bullhorn" style="color: var(--info);"></i>
                            Create New Notification
                        </h3>
                        <button class="btn btn-outline" onclick="toggleCreateSection()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>

                    <form method="POST" id="createForm" onsubmit="return validateNotificationForm()">
                        <div class="create-form">
                            <div class="form-group">
                                <label class="form-label">Title *</label>
                                <input type="text" name="title" class="form-control" placeholder="Enter notification title..." required maxlength="100">
                                <div class="form-hint">Max 100 characters</div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Type *</label>
                                <select name="type" class="form-control" required>
                                    <option value="">Select type...</option>
                                    <option value="general">General</option>
                                    <option value="attendance">Attendance</option>
                                    <option value="fee">Fee Payment</option>
                                    <option value="library">Library</option>
                                    <option value="system">System</option>
                                    <option value="academic">Academic</option>
                                    <option value="approval">Approval</option>
                                    <option value="request">Request</option>
                                    <option value="exam_portal">Exam Portal</option>
                                    <option value="results">Results</option>
                                    <option value="maintenance">Maintenance</option>
                                    <option value="maintenance_complete">Maintenance Complete</option>
                                    <option value="payroll">Payroll</option>
                                </select>
                            </div>

                            <div class="form-group full-width">
                                <label class="form-label">Message *</label>
                                <textarea name="message" class="form-control" placeholder="Enter notification message..." required maxlength="500"></textarea>
                                <div class="form-hint">Max 500 characters</div>
                            </div>

                            <div class="form-group full-width">
                                <label class="form-label">Target Audience</label>
                                <div class="target-options">
                                    <label class="target-option" id="targetAll">
                                        <input type="radio" name="target_users" value="all" style="display: none;" checked>
                                        <i class="fas fa-users"></i>
                                        <div class="target-option-content">
                                            <div class="target-option-title">All Users</div>
                                            <div class="target-option-desc">Send to everyone</div>
                                        </div>
                                    </label>

                                    <label class="target-option" id="targetRole">
                                        <input type="radio" name="target_users" value="role" style="display: none;">
                                        <i class="fas fa-user-tag"></i>
                                        <div class="target-option-content">
                                            <div class="target-option-title">By Role</div>
                                            <div class="target-option-desc">Send to specific roles</div>
                                        </div>
                                    </label>

                                    <label class="target-option" id="targetSpecific">
                                        <input type="radio" name="target_users" value="specific" style="display: none;">
                                        <i class="fas fa-user-friends"></i>
                                        <div class="target-option-content">
                                            <div class="target-option-title">Specific Users</div>
                                            <div class="target-option-desc">Select individual users</div>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <!-- Role Selection -->
                            <div class="form-group" id="roleSelection" style="display: none;">
                                <label class="form-label">Select Role</label>
                                <select name="target_role" class="form-control">
                                    <option value="">Choose a role...</option>
                                    <option value="admin">Administrators</option>
                                    <option value="teacher">Teachers</option>
                                    <option value="accountant">Accountants</option>
                                    <option value="librarian">Librarians</option>
                                </select>
                            </div>

                            <!-- User Selection -->
                            <div class="form-group full-width" id="userSelection" style="display: none;">
                                <label class="form-label">Select Users</label>
                                <div class="users-select">
                                    <?php foreach ($all_users as $user_option): ?>
                                        <label class="user-option">
                                            <input type="checkbox" name="specific_users[]" value="<?php echo $user_option['id']; ?>">
                                            <?php echo htmlspecialchars($user_option['full_name']); ?>
                                            <span style="color: var(--gray); font-size: 0.8rem; margin-left: auto;">
                                                (<?php echo ucfirst($user_option['role']); ?>)
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="form-hint">Select at least one user</div>
                            </div>

                            <div class="form-group full-width">
                                <label class="form-label">Priority Level</label>
                                <div class="priority-options">
                                    <label class="priority-option low" id="priorityLow">
                                        <input type="radio" name="priority" value="low" style="display: none;">
                                        <div class="priority-title">Low</div>
                                        <div class="priority-desc">Normal</div>
                                    </label>

                                    <label class="priority-option medium" id="priorityMedium">
                                        <input type="radio" name="priority" value="medium" style="display: none;" checked>
                                        <div class="priority-title">Medium</div>
                                        <div class="priority-desc">Important</div>
                                    </label>

                                    <label class="priority-option high" id="priorityHigh">
                                        <input type="radio" name="priority" value="high" style="display: none;">
                                        <div class="priority-title">High</div>
                                        <div class="priority-desc">Urgent</div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                            <button type="submit" name="create_notification" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Send Notification
                            </button>
                            <button type="button" class="btn btn-outline" onclick="resetNotificationForm()">
                                <i class="fas fa-undo"></i> Reset Form
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Filters Section -->
            <div class="filters-section">
                <form method="GET" id="filtersForm">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select name="filter" class="filter-select" onchange="document.getElementById('filtersForm').submit()">
                                <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Notifications</option>
                                <option value="unread" <?php echo $filter === 'unread' ? 'selected' : ''; ?>>Unread Only</option>
                                <option value="read" <?php echo $filter === 'read' ? 'selected' : ''; ?>>Read Only</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label class="filter-label">Type</label>
                            <select name="type" class="filter-select" onchange="document.getElementById('filtersForm').submit()">
                                <option value="all" <?php echo $type === 'all' ? 'selected' : ''; ?>>All Types</option>
                                <option value="attendance" <?php echo $type === 'attendance' ? 'selected' : ''; ?>>Attendance</option>
                                <option value="fee" <?php echo $type === 'fee' ? 'selected' : ''; ?>>Fee Payments</option>
                                <option value="library" <?php echo $type === 'library' ? 'selected' : ''; ?>>Library</option>
                                <option value="system" <?php echo $type === 'system' ? 'selected' : ''; ?>>System</option>
                                <option value="academic" <?php echo $type === 'academic' ? 'selected' : ''; ?>>Academic</option>
                                <option value="general" <?php echo $type === 'general' ? 'selected' : ''; ?>>General</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label class="filter-label">Date Range</label>
                            <select name="date_range" class="filter-select" onchange="document.getElementById('filtersForm').submit()">
                                <option value="all" <?php echo $date_range === 'all' ? 'selected' : ''; ?>>All Time</option>
                                <option value="today" <?php echo $date_range === 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="yesterday" <?php echo $date_range === 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                                <option value="week" <?php echo $date_range === 'week' ? 'selected' : ''; ?>>Last 7 Days</option>
                                <option value="month" <?php echo $date_range === 'month' ? 'selected' : ''; ?>>Last 30 Days</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Notifications List -->
            <div class="notifications-list" id="notificationsList">
                <div class="list-header">
                    <h3>
                        <i class="fas fa-list"></i>
                        Your Notifications
                    </h3>
                    <span class="list-count"><?php echo count($notifications); ?> found</span>
                </div>

                <?php if (empty($notifications)): ?>
                    <div class="empty-state">
                        <i class="fas fa-bell-slash"></i>
                        <h3>No Notifications Found</h3>
                        <p>You're all caught up! There are no notifications matching your current filters.</p>
                        <a href="notifications.php" class="btn btn-primary">
                            <i class="fas fa-sync"></i> Reset Filters
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications as $notification): ?>
                        <?php $icon_data = getNotificationIcon($notification['type']); ?>
                        <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>" 
                             data-notification-id="<?php echo $notification['id']; ?>">
                            
                            <div class="notification-content">
                                <div class="notification-icon" style="background: <?php echo $icon_data['color']; ?>;">
                                    <i class="<?php echo $icon_data['icon']; ?>"></i>
                                </div>

                                <div class="notification-details">
                                    <div class="notification-header">
                                        <span class="notification-title"><?php echo htmlspecialchars($notification['title']); ?></span>
                                        <span class="notification-badge" style="background: <?php echo $icon_data['bg_color']; ?>; color: <?php echo $icon_data['color']; ?>;">
                                            <?php echo ucfirst($notification['type']); ?>
                                        </span>
                                        <?php 
                                        $display_priority = $notification['priority'] == 'normal' ? 'medium' : $notification['priority'];
                                        if ($display_priority !== 'medium'): 
                                        ?>
                                            <span class="notification-priority priority-<?php echo $display_priority; ?>">
                                                <?php echo ucfirst($display_priority); ?> Priority
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="notification-message">
                                        <?php echo nl2br(htmlspecialchars($notification['message'])); ?>
                                    </div>

                                    <div class="notification-meta">
                                        <div class="notification-time">
                                            <i class="far fa-clock"></i>
                                            <?php echo timeAgo($notification['created_at']); ?>
                                        </div>
                                        <div class="notification-read-status">
                                            <?php if ($notification['is_read']): ?>
                                                <span class="read-badge">
                                                    <i class="fas fa-check-circle"></i> Read
                                                </span>
                                            <?php else: ?>
                                                <span class="unread-badge">
                                                    <i class="fas fa-circle"></i> Unread
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="notification-actions">
                                <?php if (!$notification['is_read']): ?>
                                    <form method="POST" style="display: inline;" onsubmit="event.stopPropagation()">
                                        <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                        <button type="submit" name="mark_as_read" class="action-btn mark-read">
                                            <i class="fas fa-check"></i> Mark Read
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" style="display: inline;" onsubmit="event.stopPropagation(); return confirm('Are you sure you want to delete this notification?')">
                                    <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                    <button type="submit" name="delete_notification" class="action-btn delete">
                                        <i class="fas fa-trash-alt"></i> Delete
                                    </button>
                                </form>
                            </div>

                            <div class="quick-actions">
                                <?php if (!$notification['is_read']): ?>
                                    <button class="quick-action-btn" title="Mark as Read" onclick="markAsReadQuick(<?php echo $notification['id']; ?>, this)">
                                        <i class="fas fa-check"></i>
                                    </button>
                                <?php endif; ?>
                                <button class="quick-action-btn" title="Delete" onclick="deleteNotificationQuick(<?php echo $notification['id']; ?>, this)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Toggle create notification section
        function toggleCreateSection() {
            const section = document.getElementById('createSection');
            if (section.style.display === 'none' || section.style.display === '') {
                section.style.display = 'block';
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                section.style.display = 'none';
            }
        }

        // Target audience selection
        document.querySelectorAll('.target-option').forEach(option => {
            option.addEventListener('click', function() {
                const radio = this.querySelector('input[type="radio"]');
                radio.checked = true;
                
                // Update visual selection
                document.querySelectorAll('.target-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                this.classList.add('selected');
                
                // Show/hide relevant sections
                const roleSelection = document.getElementById('roleSelection');
                const userSelection = document.getElementById('userSelection');
                
                if (radio.value === 'role') {
                    roleSelection.style.display = 'block';
                    userSelection.style.display = 'none';
                } else if (radio.value === 'specific') {
                    roleSelection.style.display = 'none';
                    userSelection.style.display = 'block';
                } else {
                    roleSelection.style.display = 'none';
                    userSelection.style.display = 'none';
                }
            });
        });

        // Priority selection
        document.querySelectorAll('.priority-option').forEach(option => {
            option.addEventListener('click', function() {
                const radio = this.querySelector('input[type="radio"]');
                radio.checked = true;
                
                document.querySelectorAll('.priority-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                this.classList.add('selected');
            });
        });

        // Reset notification form
        function resetNotificationForm() {
            document.getElementById('createForm').reset();
            
            // Reset selections
            document.querySelectorAll('.target-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            document.getElementById('targetAll').classList.add('selected');
            
            document.querySelectorAll('.priority-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            document.getElementById('priorityMedium').classList.add('selected');
            
            // Hide additional sections
            document.getElementById('roleSelection').style.display = 'none';
            document.getElementById('userSelection').style.display = 'none';
        }

        // Validate notification form
        function validateNotificationForm() {
            const title = document.querySelector('[name="title"]').value.trim();
            const message = document.querySelector('[name="message"]').value.trim();
            const targetUsers = document.querySelector('[name="target_users"]:checked')?.value;
            
            if (!title || !message) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Please fill in all required fields.',
                    timer: 3000
                });
                return false;
            }
            
            if (targetUsers === 'specific') {
                const selectedUsers = document.querySelectorAll('[name="specific_users[]"]:checked');
                if (selectedUsers.length === 0) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Validation Error',
                        text: 'Please select at least one user.',
                        timer: 3000
                    });
                    return false;
                }
            }
            
            if (message.length > 500) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Message is too long. Maximum 500 characters allowed.',
                    timer: 3000
                });
                return false;
            }
            
            return true;
        }

        // Quick mark as read with AJAX
        async function markAsReadQuick(notificationId, button) {
            const notificationItem = button.closest('.notification-item');
            
            try {
                const formData = new FormData();
                formData.append('notification_id', notificationId);
                formData.append('quick_mark_read', '1');
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Update UI
                    notificationItem.classList.remove('unread');
                    
                    // Update read status
                    const readStatus = notificationItem.querySelector('.notification-read-status');
                    readStatus.innerHTML = '<span class="read-badge"><i class="fas fa-check-circle"></i> Read</span>';
                    
                    // Remove mark as read buttons
                    const markReadBtns = notificationItem.querySelectorAll('.mark-read, .mark-read-quick');
                    markReadBtns.forEach(btn => btn.remove());
                    
                    // Update counts
                    updateStats('mark_read');
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Marked as Read',
                        text: 'Notification has been marked as read.',
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 2000
                    });
                }
            } catch (error) {
                console.error('Error marking as read:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to mark notification as read.',
                    timer: 3000
                });
            }
        }

        // Quick delete with AJAX
        function deleteNotificationQuick(notificationId, button) {
            Swal.fire({
                title: 'Delete Notification',
                text: 'Are you sure you want to delete this notification?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#e74c3c',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    const notificationItem = button.closest('.notification-item');
                    
                    const formData = new FormData();
                    formData.append('notification_id', notificationId);
                    formData.append('delete_notification', '1');
                    
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.text())
                    .then(() => {
                        notificationItem.style.opacity = '0';
                        notificationItem.style.transform = 'translateX(-20px)';
                        
                        setTimeout(() => {
                            notificationItem.remove();
                            updateStats('delete');
                            
                            // Check if list is empty
                            const remainingItems = document.querySelectorAll('.notification-item');
                            if (remainingItems.length === 0) {
                                location.reload();
                            }
                        }, 300);
                        
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted',
                            text: 'Notification has been deleted.',
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 2000
                        });
                    })
                    .catch(error => {
                        console.error('Error deleting notification:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Failed to delete notification.',
                            timer: 3000
                        });
                    });
                }
            });
        }

        // Update statistics
        function updateStats(action) {
            const totalStat = document.querySelector('.stat-card.total .stat-value');
            const unreadStat = document.querySelector('.stat-card.unread .stat-value');
            const readStat = document.querySelector('.stat-card.read .stat-value');
            const listCount = document.querySelector('.list-count');
            
            if (action === 'mark_read') {
                const currentUnread = parseInt(unreadStat.textContent);
                const currentRead = parseInt(readStat.textContent);
                
                unreadStat.textContent = currentUnread - 1;
                readStat.textContent = currentRead + 1;
                
                // Update header unread badge
                const headerBadge = document.querySelector('.unread-badge-header');
                if (headerBadge) {
                    if (currentUnread - 1 > 0) {
                        headerBadge.innerHTML = `<i class="fas fa-circle"></i> ${currentUnread - 1} unread notification${currentUnread - 1 > 1 ? 's' : ''}`;
                    } else {
                        headerBadge.remove();
                    }
                }
            } else if (action === 'delete') {
                const currentTotal = parseInt(totalStat.textContent);
                totalStat.textContent = currentTotal - 1;
                
                if (listCount) {
                    const currentListCount = parseInt(listCount.textContent);
                    listCount.textContent = `${currentListCount - 1} found`;
                }
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + N to create new notification (admin only)
            <?php if ($user['role'] === 'admin'): ?>
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                toggleCreateSection();
            }
            <?php endif; ?>
            
            // Ctrl + R to refresh
            if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                e.preventDefault();
                window.location.reload();
            }
            
            // Escape to close create section or clear filters
            if (e.key === 'Escape') {
                const createSection = document.getElementById('createSection');
                if (createSection && createSection.style.display === 'block') {
                    toggleCreateSection();
                } else {
                    window.location.href = 'notifications.php';
                }
            }
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Set default selections
            document.getElementById('targetAll')?.classList.add('selected');
            document.getElementById('priorityMedium')?.classList.add('selected');
            
            // Show create section if there was an error
            <?php if (isset($error) && $user['role'] === 'admin'): ?>
                toggleCreateSection();
            <?php endif; ?>
            
            // Welcome toast for new users
            <?php if ($total_count === 0): ?>
                Swal.fire({
                    icon: 'info',
                    title: 'Welcome to Notifications!',
                    text: 'This is your notification center. You\'ll see all system updates and alerts here.',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 5000
                });
            <?php endif; ?>
        });
    </script>
</body>
</html>
