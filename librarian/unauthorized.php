<?php
// Start session to get user info if available
session_start();

// Page title
$page_title = "Access Denied - " . (defined('SCHOOL_NAME') ? SCHOOL_NAME : 'School Management System');

// Get the attempted URL if available
$attempted_url = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
$requested_url = isset($_GET['url']) ? urldecode($_GET['url']) : '';

// Get user role if logged in
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : null;
$user_name = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'User';

// Define allowed pages based on role
$role_pages = [
    'admin' => [
        'dashboard' => '../admin/dashboard.php',
        'students' => '../admin/students.php',
        'teachers' => '../admin/teachers.php',
        'classes' => '../admin/classes.php',
        'fees' => '../admin/fees.php',
        'reports' => '../admin/reports.php',
        'settings' => '../admin/settings.php'
    ],
    'accountant' => [
        'dashboard' => '../accountant/dashboard.php',
        'payments' => '../accountant/payments.php',
        'expenses' => '../accountant/expenses.php',
        'invoices' => '../accountant/invoices.php',
        'reports' => '../accountant/reports.php'
    ],
    'teacher' => [
        'dashboard' => '../teacher/dashboard.php',
        'attendance' => '../teacher/attendance.php',
        'grades' => '../teacher/grades.php',
        'students' => '../teacher/students.php',
        'timetable' => '../teacher/timetable.php'
    ],
    'librarian' => [
        'dashboard' => '../librarian/dashboard.php',
        'books' => '../librarian/books.php',
        'loans' => '../librarian/loans.php',
        'fines' => '../librarian/fines.php'
    ]
];

// Get user initials for avatar
$user_initials = '';
if (isset($_SESSION['full_name'])) {
    $name_parts = explode(' ', $_SESSION['full_name']);
    foreach ($name_parts as $part) {
        $user_initials .= strtoupper(substr($part, 0, 1));
    }
    $user_initials = substr($user_initials, 0, 2);
} else {
    $user_initials = 'U';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        }

        .container {
            width: 100%;
            max-width: 800px;
        }

        .error-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .error-header {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            padding: 2.5rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .error-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: rgba(255, 255, 255, 0.1);
            transform: rotate(45deg);
            animation: shine 3s infinite;
        }

        @keyframes shine {
            0% {
                transform: rotate(45deg) translateY(-100%);
            }
            20% {
                transform: rotate(45deg) translateY(100%);
            }
            100% {
                transform: rotate(45deg) translateY(100%);
            }
        }

        .error-icon {
            font-size: 5rem;
            color: white;
            margin-bottom: 1rem;
            animation: bounce 2s infinite;
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-20px);
            }
            60% {
                transform: translateY(-10px);
            }
        }

        .error-code {
            font-size: 8rem;
            font-weight: 800;
            color: white;
            line-height: 1;
            text-shadow: 4px 4px 0 rgba(0, 0, 0, 0.1);
            margin-bottom: 0.5rem;
        }

        .error-title {
            font-size: 2rem;
            font-weight: 600;
            color: white;
            margin-bottom: 0.5rem;
        }

        .error-subtitle {
            color: rgba(255, 255, 255, 0.9);
            font-size: 1.1rem;
        }

        .error-body {
            padding: 2.5rem;
        }

        .message-box {
            background: #f8f9fa;
            border-left: 4px solid #e74c3c;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }

        .message-box h3 {
            color: #2c3e50;
            margin-bottom: 0.5rem;
            font-size: 1.2rem;
        }

        .message-box p {
            color: #7f8c8d;
            line-height: 1.6;
        }

        .user-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 600;
            color: #667eea;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
        }

        .user-details h4 {
            font-size: 1.2rem;
            margin-bottom: 0.25rem;
        }

        .user-details p {
            opacity: 0.9;
            font-size: 0.95rem;
        }

        .user-details .role-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            text-transform: uppercase;
            margin-top: 0.5rem;
        }

        .suggestions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .suggestion-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem 1rem;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            border: 2px solid transparent;
        }

        .suggestion-card:hover {
            transform: translateY(-5px);
            border-color: #667eea;
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.2);
        }

        .suggestion-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: white;
            font-size: 1.3rem;
        }

        .suggestion-title {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.25rem;
        }

        .suggestion-desc {
            font-size: 0.85rem;
            color: #7f8c8d;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 2rem;
        }

        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid #667eea;
            color: #667eea;
        }

        .btn-outline:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(231, 76, 60, 0.4);
        }

        .request-info {
            background: #f1f9fe;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 2rem;
            font-size: 0.9rem;
            color: #3498db;
            border: 1px dashed #3498db;
        }

        .request-info i {
            margin-right: 0.5rem;
        }

        .footer {
            text-align: center;
            margin-top: 2rem;
            color: white;
            opacity: 0.8;
            font-size: 0.9rem;
        }

        .footer a {
            color: white;
            text-decoration: none;
            font-weight: 600;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .error-code {
                font-size: 6rem;
            }
            
            .error-title {
                font-size: 1.5rem;
            }
            
            .user-info {
                flex-direction: column;
                text-align: center;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="error-card">
            <!-- Header Section -->
            <div class="error-header">
                <div class="error-icon">
                    <i class="fas fa-shield-hal"></i>
                </div>
                <div class="error-code">403</div>
                <div class="error-title">Access Denied</div>
                <div class="error-subtitle">You don't have permission to access this page</div>
            </div>

            <!-- Body Section -->
            <div class="error-body">
                <!-- User Info Section -->
                <?php if (isset($user_role) && $user_role): ?>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo isset($user_initials) ? htmlspecialchars($user_initials) : 'U'; ?>
                    </div>
                    <div class="user-details">
                        <h4>Welcome, <?php echo htmlspecialchars($user_name ?? 'User'); ?>!</h4>
                        <p>You are logged in as:</p>
                        <span class="role-badge">
                            <i class="fas fa-user-tag"></i> 
                            <?php echo ucfirst(htmlspecialchars($user_role)); ?>
                        </span>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Message Box -->
                <div class="message-box">
                    <h3>
                        <i class="fas fa-exclamation-triangle" style="color: #e74c3c;"></i>
                        Why am I seeing this?
                    </h3>
                    <p>
                        <?php if (isset($user_role) && $user_role): ?>
                            Your account role "<?php echo ucfirst(htmlspecialchars($user_role)); ?>" does not have the necessary 
                            permissions to access the requested resource. Each role has specific access rights 
                            to ensure data security and proper system usage.
                        <?php else: ?>
                            You need to be logged in to access this page. Please login with appropriate credentials 
                            to view the requested content.
                        <?php endif; ?>
                    </p>
                </div>

                <!-- Suggestions for logged-in users -->
                <?php if (isset($user_role) && $user_role && isset($role_pages[$user_role])): ?>
                <h3 style="margin-bottom: 1rem; color: #2c3e50;">
                    <i class="fas fa-compass" style="margin-right: 0.5rem; color: #667eea;"></i>
                    Suggested Pages for You
                </h3>
                <div class="suggestions">
                    <?php 
                    $suggestions = array_slice($role_pages[$user_role], 0, 4, true);
                    foreach ($suggestions as $key => $page): 
                    ?>
                    <a href="<?php echo $page; ?>" class="suggestion-card">
                        <div class="suggestion-icon">
                            <?php
                            $icons = [
                                'dashboard' => 'fa-chart-pie',
                                'students' => 'fa-user-graduate',
                                'teachers' => 'fa-chalkboard-teacher',
                                'classes' => 'fa-school',
                                'fees' => 'fa-money-bill-wave',
                                'reports' => 'fa-chart-line',
                                'settings' => 'fa-cogs',
                                'payments' => 'fa-credit-card',
                                'expenses' => 'fa-wallet',
                                'invoices' => 'fa-file-invoice',
                                'attendance' => 'fa-calendar-check',
                                'grades' => 'fa-star',
                                'timetable' => 'fa-clock',
                                'books' => 'fa-book',
                                'loans' => 'fa-hand-holding-heart',
                                'fines' => 'fa-exclamation-circle'
                            ];
                            $icon = isset($icons[$key]) ? $icons[$key] : 'fa-link';
                            echo "<i class='fas $icon'></i>";
                            ?>
                        </div>
                        <div class="suggestion-title"><?php echo ucfirst($key); ?></div>
                        <div class="suggestion-desc">Manage <?php echo $key; ?></div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="action-buttons">
                    <?php if (isset($user_role) && $user_role): ?>
                        <a href="../<?php echo htmlspecialchars($user_role); ?>/dashboard.php" class="btn btn-primary">
                            <i class="fas fa-tachometer-alt"></i> Go to Dashboard
                        </a>
                        <a href="../index.php" class="btn btn-outline">
                            <i class="fas fa-home"></i> Home
                        </a>
                    <?php else: ?>
                        <a href="../login.php" class="btn btn-primary">
                            <i class="fas fa-sign-in-alt"></i> Login
                        </a>
                        <a href="../index.php" class="btn btn-outline">
                            <i class="fas fa-home"></i> Home
                        </a>
                    <?php endif; ?>
                    <button onclick="history.back()" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Go Back
                    </button>
                    <a href="../logout.php" class="btn btn-danger">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>

                <!-- Request Information -->
                <?php if ((isset($requested_url) && $requested_url) || (isset($attempted_url) && $attempted_url)): ?>
                <div class="request-info">
                    <i class="fas fa-info-circle"></i>
                    <strong>Attempted access:</strong> 
                    <?php echo htmlspecialchars($requested_url ?: $attempted_url); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> <a href="#"><?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'School Management System'; ?></a>. All rights reserved.</p>
            <p style="margin-top: 0.5rem;">
                <i class="fas fa-shield-alt"></i> Need help? Contact your system administrator
            </p>
        </div>
    </div>
</body>
</html>