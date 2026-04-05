<?php
include '../config.php';
checkAuth();

$user_id = $_SESSION['user_id'];

if (!function_exists('messagesRedirectUrl')) {
    function messagesRedirectUrl(): string
    {
        $params = [];

        if (isset($_GET['view']) && $_GET['view'] !== '') {
            $params['view'] = (string) $_GET['view'];
        }

        if (isset($_GET['conversation']) && $_GET['conversation'] !== '') {
            $params['conversation'] = (string) $_GET['conversation'];
        }

        $query = http_build_query($params);
        return 'messages.php' . ($query !== '' ? '?' . $query : '');
    }
}

$success = $_SESSION['messages_success'] ?? null;
$error = $_SESSION['messages_error'] ?? null;
unset($_SESSION['messages_success'], $_SESSION['messages_error']);

// Get user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['send_message'])) {
        $receiver_id = $_POST['receiver_id'];
        $subject = $_POST['subject'];
        $message = $_POST['message'];
        
        if (!empty($receiver_id) && !empty($subject) && !empty($message)) {
            $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, subject, message) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$user_id, $receiver_id, $subject, $message])) {
                $_SESSION['messages_success'] = "Message sent successfully!";
            } else {
                $_SESSION['messages_error'] = "Failed to send message. Please try again.";
            }
        } else {
            $_SESSION['messages_error'] = "Please fill in all required fields.";
        }
    }
    
    if (isset($_POST['delete_message'])) {
        $message_id = $_POST['message_id'];
        $stmt = $pdo->prepare("DELETE FROM messages WHERE id = ? AND (sender_id = ? OR receiver_id = ?)");
        $stmt->execute([$message_id, $user_id, $user_id]);
        $_SESSION['messages_success'] = "Message deleted successfully!";
    }
    
    if (isset($_POST['mark_as_read'])) {
        $message_id = $_POST['message_id'];
        $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE id = ? AND receiver_id = ?");
        $stmt->execute([$message_id, $user_id]);
        $_SESSION['messages_success'] = "Message marked as read!";
    }
    
    if (isset($_POST['delete_conversation'])) {
        $other_user_id = $_POST['other_user_id'];
        $stmt = $pdo->prepare("DELETE FROM messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)");
        $stmt->execute([$user_id, $other_user_id, $other_user_id, $user_id]);
        $_SESSION['messages_success'] = "Conversation deleted successfully!";
    }
    
    if (isset($_POST['mark_all_read'])) {
        $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE receiver_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        $_SESSION['messages_success'] = "All messages marked as read!";
    }

    header('Location: ' . messagesRedirectUrl());
    exit();
}

// Get current view (inbox, sent, or conversation)
$view = $_GET['view'] ?? 'inbox';
$conversation_with = $_GET['conversation'] ?? null;

// Get message counts
$inbox_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ?");
$inbox_count_stmt->execute([$user_id]);
$inbox_count = $inbox_count_stmt->fetchColumn();

$unread_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$unread_count_stmt->execute([$user_id]);
$unread_count = $unread_count_stmt->fetchColumn();

$sent_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE sender_id = ?");
$sent_count_stmt->execute([$user_id]);
$sent_count = $sent_count_stmt->fetchColumn();

// Get all users for composing messages
$users_stmt = $pdo->prepare("SELECT id, full_name, role, profile_picture FROM users WHERE id != ? ORDER BY full_name");
$users_stmt->execute([$user_id]);
$all_users = $users_stmt->fetchAll();

// Get conversations (people you've messaged with)
$conversations_stmt = $pdo->prepare("
    SELECT 
        u.id,
        u.full_name,
        u.role,
        u.profile_picture,
        MAX(m.created_at) as last_message_time,
        COUNT(CASE WHEN m.is_read = 0 AND m.receiver_id = ? THEN 1 END) as unread_count,
        (SELECT message FROM messages WHERE (sender_id = u.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = u.id) ORDER BY created_at DESC LIMIT 1) as last_message
    FROM users u
    JOIN messages m ON (m.sender_id = u.id AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = u.id)
    WHERE u.id != ?
    GROUP BY u.id
    ORDER BY last_message_time DESC
");
$conversations_stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id]);
$conversations = $conversations_stmt->fetchAll();

// Debug logging (only when DEBUG_MODE is enabled)
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    $dbg = "[".date('Y-m-d H:i:s')."] messages.php - user:".$user_id." view:".$view." conv_with:".($conversation_with ?? 'null')." conv_count:".count($conversations)."\n";
    @file_put_contents(__DIR__ . '/../debug_messages.log', $dbg, FILE_APPEND);
}

// Get messages based on view
if ($view === 'inbox') {
    $messages_stmt = $pdo->prepare("
        SELECT m.*, u.full_name as sender_name, u.role as sender_role, u.profile_picture as sender_picture
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.receiver_id = ?
        ORDER BY m.created_at DESC
    ");
    $messages_stmt->execute([$user_id]);
    $messages = $messages_stmt->fetchAll();
} elseif ($view === 'sent') {
    $messages_stmt = $pdo->prepare("
        SELECT m.*, u.full_name as receiver_name, u.role as receiver_role, u.profile_picture as receiver_picture
        FROM messages m
        JOIN users u ON m.receiver_id = u.id
        WHERE m.sender_id = ?
        ORDER BY m.created_at DESC
    ");
    $messages_stmt->execute([$user_id]);
    $messages = $messages_stmt->fetchAll();
} elseif ($view === 'conversation' && $conversation_with) {
    // Get conversation messages
    $messages_stmt = $pdo->prepare("
        SELECT m.*, 
               sender.full_name as sender_name, 
               sender.role as sender_role,
               sender.profile_picture as sender_picture,
               receiver.full_name as receiver_name,
               receiver.role as receiver_role
        FROM messages m
        JOIN users sender ON m.sender_id = sender.id
        JOIN users receiver ON m.receiver_id = receiver.id
        WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
        ORDER BY m.created_at ASC
    ");
    $messages_stmt->execute([$user_id, $conversation_with, $conversation_with, $user_id]);
    $conversation_messages = $messages_stmt->fetchAll();
    
    // Mark messages as read when viewing conversation
    $mark_read_stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE receiver_id = ? AND sender_id = ? AND is_read = 0");
    $mark_read_stmt->execute([$user_id, $conversation_with]);
    
    // Get conversation partner info
    $partner_stmt = $pdo->prepare("SELECT id, full_name, role, profile_picture FROM users WHERE id = ?");
    $partner_stmt->execute([$conversation_with]);
    $conversation_partner = $partner_stmt->fetch();
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

// Function to get profile picture URL
function getProfilePictureUrl($user, $path = '') {
    if (!empty($user['profile_picture'])) {
        return $path . 'uploads/profile_pictures/' . $user['profile_picture'];
    } else {
        return null;
    }
}

$page_title = "Messages - " . SCHOOL_NAME;
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
            --message-sent: #e3f2fd;
            --message-received: #f5f5f5;
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

        /* Page Header */
        .page-header {
            background: white;
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
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-header p {
            color: var(--gray);
            font-size: 1rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            text-align: center;
            border-left: 4px solid var(--secondary);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card.inbox {
            border-left-color: var(--secondary);
        }

        .stat-card.unread {
            border-left-color: var(--warning);
        }

        .stat-card.sent {
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

        /* Messages Container */
        .messages-container {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 2rem;
            height: calc(100vh - 250px);
        }

        /* Sidebar */
        .messages-sidebar {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--light);
        }

        .view-toggle {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .view-btn {
            flex: 1;
            padding: 0.75rem;
            border: 2px solid var(--light);
            background: white;
            color: var(--dark);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            text-align: center;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .view-btn.active {
            background: linear-gradient(135deg, var(--secondary), var(--purple));
            color: white;
            border-color: transparent;
        }

        .view-btn:hover:not(.active) {
            border-color: var(--secondary);
            color: var(--secondary);
        }

        .compose-btn {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, var(--success), var(--success-light));
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 1rem;
        }

        .compose-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Conversations List */
        .conversations-list {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 0;
        }

        .conversation-item {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--light);
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .conversation-item:hover {
            background: var(--light);
        }

        .conversation-item.active {
            background: rgba(52, 152, 219, 0.1);
            border-left: 4px solid var(--secondary);
        }

        .conversation-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 0.5rem;
        }

        .conversation-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary), var(--purple));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.2rem;
            background-size: cover;
            background-position: center;
            flex-shrink: 0;
        }

        .conversation-info {
            flex: 1;
            min-width: 0;
        }

        .conversation-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.2rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conversation-role {
            color: var(--gray);
            font-size: 0.8rem;
            text-transform: capitalize;
        }

        .conversation-preview {
            color: var(--gray);
            font-size: 0.85rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-right: 1rem;
        }

        .conversation-time {
            color: var(--gray);
            font-size: 0.75rem;
            white-space: nowrap;
            margin-top: 0.25rem;
        }

        .unread-badge {
            background: var(--warning);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            position: absolute;
            top: 1rem;
            right: 1.5rem;
        }

        /* Main Content Area */
        .messages-main {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .messages-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .messages-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--dark);
        }

        /* Messages List */
        .messages-list {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 0;
        }

        .message-item {
            padding: 1.5rem;
            border-bottom: 1px solid var(--light);
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
        }

        .message-item:hover {
            background: var(--light);
        }

        .message-item.unread {
            background: rgba(52, 152, 219, 0.05);
        }

        .message-header {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }

        .message-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary), var(--purple));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.2rem;
            background-size: cover;
            background-position: center;
            flex-shrink: 0;
        }

        .message-content {
            flex: 1;
        }

        .message-sender {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.2rem;
        }

        .message-subject {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .message-text {
            color: var(--gray);
            margin-bottom: 0.5rem;
            line-height: 1.4;
        }

        .message-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            color: var(--gray);
            font-size: 0.8rem;
        }

        .message-time i {
            margin-right: 0.3rem;
        }

        .unread-indicator {
            color: var(--warning);
            font-weight: 600;
        }

        .message-actions {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            display: flex;
            gap: 0.5rem;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .message-item:hover .message-actions {
            opacity: 1;
        }

        .action-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .action-btn.mark-read {
            background: rgba(52, 152, 219, 0.1);
            color: var(--secondary);
        }

        .action-btn.mark-read:hover {
            background: var(--secondary);
            color: white;
        }

        .action-btn.delete {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }

        .action-btn.delete:hover {
            background: var(--danger);
            color: white;
        }

        /* Conversation View */
        .conversation-view {
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .conversation-partner {
            padding: 1.5rem;
            border-bottom: 1px solid var(--light);
            display: flex;
            align-items: center;
            gap: 1rem;
            background: var(--light);
        }

        .partner-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary), var(--purple));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.2rem;
            background-size: cover;
            background-position: center;
        }

        .partner-info h3 {
            color: var(--dark);
            margin-bottom: 0.2rem;
        }

        .partner-info p {
            color: var(--gray);
            font-size: 0.9rem;
            text-transform: capitalize;
        }

        .conversation-messages {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
            background: var(--light);
        }

        .message-bubble {
            max-width: 70%;
            margin-bottom: 1.5rem;
            padding: 1rem 1.2rem;
            border-radius: 18px;
            position: relative;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .message-bubble.sent {
            background: var(--message-sent);
            margin-left: auto;
            border-bottom-right-radius: 4px;
        }

        .message-bubble.received {
            background: var(--message-received);
            margin-right: auto;
            border-bottom-left-radius: 4px;
        }

        .message-text {
            color: var(--dark);
            margin-bottom: 0.5rem;
            line-height: 1.4;
        }

        .message-time {
            font-size: 0.7rem;
            color: var(--gray);
            text-align: right;
        }

        .conversation-compose {
            padding: 1.5rem;
            border-top: 1px solid var(--light);
            background: white;
        }

        .compose-form {
            display: flex;
            gap: 1rem;
        }

        .message-input {
            flex: 1;
            padding: 1rem 1.5rem;
            border: 2px solid var(--light);
            border-radius: 25px;
            font-size: 1rem;
            resize: none;
            height: 60px;
            transition: all 0.3s ease;
        }

        .message-input:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .send-btn {
            padding: 1rem 2rem;
            background: linear-gradient(135deg, var(--secondary), var(--purple));
            color: white;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .send-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Compose Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(5px);
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-xl);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow: hidden;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--gray);
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .close-btn:hover {
            background: var(--light);
            color: var(--dark);
        }

        .modal-body {
            padding: 1.5rem;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--light);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
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
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
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

        /* Button Styles */
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
            color: var(--gray);
        }

        .btn-outline:hover {
            border-color: var(--secondary);
            color: var(--secondary);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), var(--success-light));
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Alert Styles */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: slideIn 0.3s ease;
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

        /* Loading State */
        .loading {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
        }

        .loading i {
            font-size: 2rem;
            margin-bottom: 1rem;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .messages-container {
                grid-template-columns: 300px 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .messages-container {
                grid-template-columns: 1fr;
                height: auto;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .messages-sidebar {
                height: 400px;
            }

            .message-bubble {
                max-width: 85%;
            }

            .message-actions {
                position: static;
                opacity: 1;
                margin-top: 1rem;
            }

            .modal-content {
                width: 95%;
                margin: 1rem;
            }
        }
    </style>
</head>
<body>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1>
                <i class="fas fa-envelope" style="color: var(--secondary);"></i>
                Messages
            </h1>
            <p>Communicate with staff and manage your conversations</p>
            
            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card inbox" onclick="window.location.href='messages.php?view=inbox'">
                    <div class="stat-value"><?php echo $inbox_count; ?></div>
                    <div class="stat-label">Inbox</div>
                </div>
                <div class="stat-card unread" onclick="window.location.href='messages.php?view=inbox'">
                    <div class="stat-value"><?php echo $unread_count; ?></div>
                    <div class="stat-label">Unread</div>
                </div>
                <div class="stat-card sent" onclick="window.location.href='messages.php?view=sent'">
                    <div class="stat-value"><?php echo $sent_count; ?></div>
                    <div class="stat-label">Sent</div>
                </div>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- Messages Container -->
        <div class="messages-container">
            <!-- Sidebar -->
            <div class="messages-sidebar">
                <div class="sidebar-header">
                    <div class="view-toggle">
                        <a href="messages.php?view=inbox" class="view-btn <?php echo $view === 'inbox' ? 'active' : ''; ?>">
                            <i class="fas fa-inbox"></i> Inbox
                        </a>
                        <a href="messages.php?view=sent" class="view-btn <?php echo $view === 'sent' ? 'active' : ''; ?>">
                            <i class="fas fa-paper-plane"></i> Sent
                        </a>
                    </div>
                    <button class="compose-btn" onclick="openComposeModal()">
                        <i class="fas fa-edit"></i> Compose Message
                    </button>
                </div>

                <div class="conversations-list">
                    <?php if (empty($conversations)): ?>
                        <div class="empty-state">
                            <i class="fas fa-comments"></i>
                            <p>No conversations yet</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($conversations as $conversation): ?>
                            <div class="conversation-item <?php echo $conversation_with == $conversation['id'] ? 'active' : ''; ?>" 
                                 onclick="window.location.href='messages.php?view=conversation&conversation=<?php echo $conversation['id']; ?>'">
                                <div class="conversation-header">
                                    <div class="conversation-avatar" style="<?php echo getProfilePictureUrl($conversation) ? 'background-image: url(\'' . getProfilePictureUrl($conversation, '../') . '\')' : ''; ?>">
                                        <?php if (!getProfilePictureUrl($conversation)): ?>
                                            <?php echo strtoupper(substr($conversation['full_name'], 0, 1)); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="conversation-info">
                                        <div class="conversation-name"><?php echo htmlspecialchars($conversation['full_name']); ?></div>
                                        <div class="conversation-role"><?php echo $conversation['role']; ?></div>
                                    </div>
                                </div>
                                <div class="conversation-preview">
                                    <?php echo htmlspecialchars(substr($conversation['last_message'] ?? 'No messages', 0, 60)); ?>...
                                </div>
                                <div class="conversation-time">
                                    <i class="far fa-clock"></i> <?php echo timeAgo($conversation['last_message_time']); ?>
                                </div>
                                <?php if ($conversation['unread_count'] > 0): ?>
                                    <div class="unread-badge"><?php echo $conversation['unread_count']; ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Main Content -->
            <div class="messages-main">
                <?php if ($view === 'conversation' && isset($conversation_partner)): ?>
                    <!-- Conversation View -->
                    <div class="conversation-view">
                        <div class="conversation-partner">
                            <div class="partner-avatar" style="<?php echo getProfilePictureUrl($conversation_partner) ? 'background-image: url(\'' . getProfilePictureUrl($conversation_partner, '../') . '\')' : ''; ?>">
                                <?php if (!getProfilePictureUrl($conversation_partner)): ?>
                                    <?php echo strtoupper(substr($conversation_partner['full_name'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="partner-info">
                                <h3><?php echo htmlspecialchars($conversation_partner['full_name']); ?></h3>
                                <p><i class="fas fa-user-tag"></i> <?php echo ucfirst($conversation_partner['role']); ?></p>
                            </div>
                            <form method="POST" style="margin-left: auto;" onsubmit="return confirm('Are you sure you want to delete this entire conversation?')">
                                <input type="hidden" name="other_user_id" value="<?php echo $conversation_partner['id']; ?>">
                                <button type="submit" name="delete_conversation" class="btn btn-outline">
                                    <i class="fas fa-trash-alt"></i> Delete Conversation
                                </button>
                            </form>
                        </div>

                        <div class="conversation-messages" id="conversationMessages">
                            <?php if (empty($conversation_messages)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-comments"></i>
                                    <h3>No messages yet</h3>
                                    <p>Start a conversation by sending a message below.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($conversation_messages as $message): ?>
                                    <div class="message-bubble <?php echo $message['sender_id'] == $user_id ? 'sent' : 'received'; ?>">
                                        <div class="message-text"><?php echo nl2br(htmlspecialchars($message['message'])); ?></div>
                                        <div class="message-time">
                                            <i class="far fa-clock"></i> <?php echo timeAgo($message['created_at']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="conversation-compose">
                            <form method="POST" class="compose-form">
                                <input type="hidden" name="receiver_id" value="<?php echo $conversation_partner['id']; ?>">
                                <input type="hidden" name="subject" value="Re: Conversation">
                                <textarea name="message" class="message-input" placeholder="Type your message..." required></textarea>
                                <button type="submit" name="send_message" class="send-btn">
                                    <i class="fas fa-paper-plane"></i> Send
                                </button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Inbox/Sent View -->
                    <div class="messages-header">
                        <div class="messages-title">
                            <?php if ($view === 'inbox'): ?>
                                <i class="fas fa-inbox" style="color: var(--secondary); margin-right: 0.5rem;"></i>
                                Inbox Messages
                            <?php else: ?>
                                <i class="fas fa-paper-plane" style="color: var(--success); margin-right: 0.5rem;"></i>
                                Sent Messages
                            <?php endif; ?>
                        </div>
                        <?php if ($view === 'inbox' && $unread_count > 0): ?>
                            <form method="POST">
                                <button type="submit" name="mark_all_read" class="btn btn-success">
                                    <i class="fas fa-check-double"></i> Mark All as Read
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <div class="messages-list">
                        <?php if (empty($messages)): ?>
                            <div class="empty-state">
                                <i class="fas fa-envelope-open"></i>
                                <h3>No messages found</h3>
                                <p>
                                    <?php if ($view === 'inbox'): ?>
                                        Your inbox is empty. You'll see messages here when you receive them.
                                    <?php else: ?>
                                        You haven't sent any messages yet.
                                    <?php endif; ?>
                                </p>
                                <button class="btn btn-primary" onclick="openComposeModal()">
                                    <i class="fas fa-edit"></i> Compose New Message
                                </button>
                            </div>
                        <?php else: ?>
                            <?php foreach ($messages as $message): ?>
                                <div class="message-item <?php echo (!$message['is_read'] && $view === 'inbox') ? 'unread' : ''; ?>" 
                                     onclick="window.location.href='messages.php?view=conversation&conversation=<?php echo $view === 'inbox' ? $message['sender_id'] : $message['receiver_id']; ?>'">
                                    <div class="message-header">
                                        <div class="message-avatar" style="<?php echo getProfilePictureUrl($message, '../') ? 'background-image: url(\'' . getProfilePictureUrl($message, '../') . '\')' : ''; ?>">
                                            <?php if (!getProfilePictureUrl($message, '../')): ?>
                                                <?php echo strtoupper(substr($view === 'inbox' ? $message['sender_name'] : $message['receiver_name'], 0, 1)); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="message-content">
                                            <div class="message-sender">
                                                <?php echo htmlspecialchars($view === 'inbox' ? $message['sender_name'] : $message['receiver_name']); ?>
                                                <span style="color: var(--gray); font-size: 0.85rem; margin-left: 0.5rem;">
                                                    (<?php echo ucfirst($view === 'inbox' ? $message['sender_role'] : $message['receiver_role']); ?>)
                                                </span>
                                            </div>
                                            <div class="message-subject"><?php echo htmlspecialchars($message['subject']); ?></div>
                                            <div class="message-text"><?php echo htmlspecialchars(substr($message['message'], 0, 150)); ?>...</div>
                                            <div class="message-meta">
                                                <span class="message-time">
                                                    <i class="far fa-clock"></i> <?php echo timeAgo($message['created_at']); ?>
                                                </span>
                                                <?php if (!$message['is_read'] && $view === 'inbox'): ?>
                                                    <span class="unread-indicator">
                                                        <i class="fas fa-circle"></i> Unread
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="message-actions">
                                        <?php if (!$message['is_read'] && $view === 'inbox'): ?>
                                            <form method="POST" onclick="event.stopPropagation()">
                                                <input type="hidden" name="message_id" value="<?php echo $message['id']; ?>">
                                                <button type="submit" name="mark_as_read" class="action-btn mark-read">
                                                    <i class="fas fa-check"></i> Mark Read
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this message?')" onclick="event.stopPropagation()">
                                            <input type="hidden" name="message_id" value="<?php echo $message['id']; ?>">
                                            <button type="submit" name="delete_message" class="action-btn delete">
                                                <i class="fas fa-trash-alt"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Compose Modal -->
    <div class="modal" id="composeModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class="fas fa-edit" style="color: var(--secondary);"></i>
                    Compose New Message
                </h3>
                <button class="close-btn" onclick="closeComposeModal()">&times;</button>
            </div>
            <form method="POST" id="composeForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">To</label>
                        <select name="receiver_id" class="form-control" required>
                            <option value="">Select recipient...</option>
                            <?php foreach ($all_users as $recipient): ?>
                                <option value="<?php echo $recipient['id']; ?>">
                                    <?php echo htmlspecialchars($recipient['full_name']); ?> (<?php echo ucfirst($recipient['role']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Subject</label>
                        <input type="text" name="subject" class="form-control" placeholder="Enter message subject..." required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Message</label>
                        <textarea name="message" class="form-control" placeholder="Type your message here..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeComposeModal()">Cancel</button>
                    <button type="submit" name="send_message" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Send Message
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Compose Modal Functions
        function openComposeModal() {
            document.getElementById('composeModal').classList.add('show');
        }

        function closeComposeModal() {
            document.getElementById('composeModal').classList.remove('show');
            document.getElementById('composeForm').reset();
        }

        // Close modal when clicking outside
        document.getElementById('composeModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeComposeModal();
            }
        });

        // Auto-scroll to bottom in conversation view
        function scrollToBottom() {
            const messagesContainer = document.getElementById('conversationMessages');
            if (messagesContainer) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
        }

        // Scroll to bottom when page loads for conversation view
        document.addEventListener('DOMContentLoaded', function() {
            scrollToBottom();
            
            // Auto-refresh conversation every 10 seconds
            if (window.location.search.includes('view=conversation')) {
                setInterval(function() {
                    if (!document.hidden) {
                        window.location.reload();
                    }
                }, 10000);
            }
        });

        // Auto-hide success messages after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s ease';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + N to compose new message
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                openComposeModal();
            }
            
            // Escape to close modal
            if (e.key === 'Escape') {
                closeComposeModal();
            }
        });

        // Auto-focus message input in conversation view
        const messageInput = document.querySelector('.message-input');
        if (messageInput) {
            messageInput.focus();
        }

        // Show welcome toast for new users
        document.addEventListener('DOMContentLoaded', function() {
            if (<?php echo $inbox_count; ?> === 0 && <?php echo $sent_count; ?> === 0) {
                Swal.fire({
                    icon: 'info',
                    title: 'Welcome to Messages!',
                    text: 'This is your message center. You can communicate with staff and manage all your conversations here.',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 5000
                });
            }
        });
    </script>
</body>
</html>
