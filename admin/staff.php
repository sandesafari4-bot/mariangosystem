<?php
include '../config.php';
include '../otp_functions.php';
checkAuth();
checkRole(['admin']);

function buildVerificationLink(string $token): string
{
    $publicBaseUrl = 'https://mariangopri.ct.ws';

    return rtrim($publicBaseUrl, '/') . '/verify_account.php?token=' . urlencode($token);
}

function shouldExposeVerificationLink(): bool
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    return function_exists('isLocalRequestHost') && isLocalRequestHost($host);
}

// Handle AJAX requests first
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_staff') {
    header('Content-Type: application/json');
    
    $staff_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($staff_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid staff ID']);
        exit();
    }
    
    $stmt = $pdo->prepare("
        SELECT u.*, 
               (SELECT COUNT(*) FROM classes c WHERE c.class_teacher_id = u.id) as class_count,
               (SELECT COUNT(*) FROM subjects s WHERE s.teacher_id = u.id) as subject_count
        FROM users u 
        WHERE u.id = ?
    ");
    $stmt->execute([$staff_id]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($staff) {
        echo json_encode(['success' => true, 'staff' => $staff]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Staff not found']);
    }
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_staff'])) {
        $username = $_POST['username'];
        $role = $_POST['role'];
        $full_name = $_POST['full_name'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $address = $_POST['address'];
        $qualifications = $_POST['qualifications'];
        $employment_date = $_POST['employment_date'];
        
        // Check if username already exists
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $check_stmt->execute([$username]);
        if ($check_stmt->fetchColumn() > 0) {
            $error = "Username already exists. Please choose a different username.";
        } else {
            // Check if email already exists
            $email_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $email_check->execute([$email]);
            if ($email_check->fetchColumn() > 0) {
                $error = "Email address already exists. Please use a different email address.";
            } else {
                try {
                    // Generate verification token
                    $verification_token = bin2hex(random_bytes(32));
                    $verification_expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    
                    $stmt = $pdo->prepare("INSERT INTO users (username, role, full_name, email, phone, address, qualifications, employment_date, email_verified, verification_token, verification_expires, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 'inactive')");
                    if ($stmt->execute([$username, $role, $full_name, $email, $phone, $address, $qualifications, $employment_date, $verification_token, $verification_expires])) {
                        // Send verification email
                        $verification_link = buildVerificationLink($verification_token);
                        if (sendAccountVerificationEmail($email, $verification_link, $full_name)) {
                            $success = "Staff member added successfully! A verification email has been sent to " . htmlspecialchars($email) . ".";
                            if (shouldExposeVerificationLink()) {
                                $verification_link_for_alert = $verification_link;
                            }
                        } else {
                            $error = "Staff member added but verification email could not be sent. Please try resending the verification email.";
                            $verification_link_for_alert = $verification_link;
                        }
                    } else {
                        $error = "Failed to add staff member. Please try again.";
                    }
                } catch (PDOException $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }
    
    if (isset($_POST['update_staff'])) {
        $user_id = $_POST['user_id'];
        $role = $_POST['role'];
        $full_name = $_POST['full_name'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $address = $_POST['address'];
        $qualifications = $_POST['qualifications'];
        $employment_date = $_POST['employment_date'];
        $status = $_POST['status'];
        
        // Check if email already exists for a different user
        $email_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
        $email_check->execute([$email, $user_id]);
        if ($email_check->fetchColumn() > 0) {
            $error = "Email address already exists for another user. Please use a different email address.";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE users SET role = ?, full_name = ?, email = ?, phone = ?, address = ?, qualifications = ?, employment_date = ?, status = ? WHERE id = ?");
                if ($stmt->execute([$role, $full_name, $email, $phone, $address, $qualifications, $employment_date, $status, $user_id])) {
                    $success = "Staff member updated successfully!";
                } else {
                    $error = "Failed to update staff member. Please try again.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
    
    if (isset($_POST['delete_staff'])) {
        $user_id = $_POST['user_id'];
        
        // Prevent admin from deleting themselves
        if ($user_id == $_SESSION['user_id']) {
            $error = "You cannot delete your own account.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            if ($stmt->execute([$user_id])) {
                $success = "Staff member deleted successfully!";
            } else {
                $error = "Failed to delete staff member. Please try again.";
            }
        }
    }
    
    if (isset($_POST['reset_password'])) {
        $user_id = $_POST['user_id'];
        $new_password = password_hash('password123', PASSWORD_DEFAULT); // Default password
        
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        if ($stmt->execute([$new_password, $user_id])) {
            $success = "Password reset successfully! Default password: password123";
        } else {
            $error = "Failed to reset password. Please try again.";
        }
    }
    
    if (isset($_POST['resend_verification'])) {
        $user_id = $_POST['user_id'];
        
        // Get user details
        $user_stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $user_stmt->execute([$user_id]);
        $user = $user_stmt->fetch();
        
        if ($user && (!isset($user['email_verified']) || !$user['email_verified']) && !empty($user['email'])) {
            // Generate new verification token
            $verification_token = bin2hex(random_bytes(32));
            $verification_expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
            
            $update_stmt = $pdo->prepare("UPDATE users SET verification_token = ?, verification_expires = ? WHERE id = ?");
            if ($update_stmt->execute([$verification_token, $verification_expires, $user_id])) {
                // Send verification email
                $verification_link = buildVerificationLink($verification_token);
                if (sendAccountVerificationEmail($user['email'], $verification_link, $user['full_name'])) {
                    $success = "Verification email resent successfully to " . htmlspecialchars($user['email']) . ".";
                    if (shouldExposeVerificationLink()) {
                        $verification_link_for_alert = $verification_link;
                    }
                } else {
                    $error = "Failed to send verification email. Please try again.";
                    $verification_link_for_alert = $verification_link;
                }
            } else {
                $error = "Failed to update verification token. Please try again.";
            }
        } else {
            $error = "User not found or already verified.";
        }
    }
    
    // Refresh data after form submission
    $redirectParams = [];
    if (!empty($success)) {
        $redirectParams['success'] = strip_tags($success);
    } elseif (!empty($error)) {
        $redirectParams['error'] = strip_tags($error);
    }
    if (!empty($verification_link_for_alert)) {
        $redirectParams['verification_link'] = $verification_link_for_alert;
    }

    header("Location: staff.php" . (!empty($redirectParams) ? '?' . http_build_query($redirectParams) : ''));
    exit();
}

// Get all staff members with additional details
$staff = $pdo->query("
    SELECT u.*, u.email_verified,
           (SELECT COUNT(*) FROM classes c WHERE c.class_teacher_id = u.id) as class_count,
           (SELECT COUNT(*) FROM subjects s WHERE s.teacher_id = u.id) as subject_count
    FROM users u 
    ORDER BY u.role, u.full_name
")->fetchAll();

// Get statistics
$total_staff = count($staff);
$active_staff = array_filter($staff, fn($s) => $s['status'] == 'active');
$teachers = array_filter($staff, fn($s) => $s['role'] == 'teacher');
$admins = array_filter($staff, fn($s) => $s['role'] == 'admin');
$accountants = array_filter($staff, fn($s) => $s['role'] == 'accountant');
$librarians = array_filter($staff, fn($s) => $s['role'] == 'librarian');

$page_title = "Staff Management - " . SCHOOL_NAME;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
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

        .header-actions {
            display: flex;
            gap: 1rem;
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

        .btn-success {
            background: linear-gradient(135deg, var(--success), var(--success-light));
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), var(--warning-light));
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), var(--danger-light));
            color: white;
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info), #138496));
            color: white;
        }

        .btn-outline {
            background: var(--light);
            color: var(--dark);
            border: 2px solid transparent;
        }

        .btn-outline:hover {
            background: #d5dbdb;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        .btn-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border-left: 4px solid;
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card.total { border-left-color: var(--warning); }
        .stat-card.active { border-left-color: var(--success); }
        .stat-card.teachers { border-left-color: var(--secondary); }
        .stat-card.admins { border-left-color: var(--danger); }
        .stat-card.accountants { border-left-color: var(--info); }
        .stat-card.librarians { border-left-color: var(--purple); }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .stat-card.total .stat-icon { background: linear-gradient(135deg, var(--warning), var(--warning-light)); }
        .stat-card.active .stat-icon { background: linear-gradient(135deg, var(--success), var(--success-light)); }
        .stat-card.teachers .stat-icon { background: linear-gradient(135deg, var(--secondary), var(--purple)); }
        .stat-card.admins .stat-icon { background: linear-gradient(135deg, var(--danger), var(--danger-light)); }
        .stat-card.accountants .stat-icon { background: linear-gradient(135deg, var(--info), #138496); }
        .stat-card.librarians .stat-icon { background: linear-gradient(135deg, var(--purple), var(--purple-light)); }

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

        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .filter-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--dark);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto;
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--light);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--secondary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        /* Staff Grid */
        .staff-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .staff-card {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-md);
            transition: all 0.3s;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .staff-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .staff-card.teacher { border-top: 4px solid var(--secondary); }
        .staff-card.admin { border-top: 4px solid var(--danger); }
        .staff-card.accountant { border-top: 4px solid var(--success); }
        .staff-card.librarian { border-top: 4px solid var(--purple); }

        .staff-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--light), #ffffff);
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid var(--light);
        }

        .staff-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary), var(--purple));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.8rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        .staff-info {
            flex: 1;
        }

        .staff-name {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.3rem;
        }

        .staff-role {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 0.5rem;
        }

        .staff-role.teacher { background: rgba(52, 152, 219, 0.1); color: var(--secondary); }
        .staff-role.admin { background: rgba(231, 76, 60, 0.1); color: var(--danger); }
        .staff-role.accountant { background: rgba(39, 174, 96, 0.1); color: var(--success); }
        .staff-role.librarian { background: rgba(155, 89, 182, 0.1); color: var(--purple); }

        .staff-contact {
            font-size: 0.85rem;
            color: var(--gray);
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
        }

        .staff-contact i {
            width: 16px;
            margin-right: 0.5rem;
            color: var(--secondary);
        }

        .staff-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.5rem;
            padding: 1rem 1.5rem;
            background: var(--light);
            border-bottom: 1px solid #d0d7dd;
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.2rem;
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--gray);
            text-transform: uppercase;
        }

        .staff-details {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--light);
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px dashed var(--light);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.85rem;
        }

        .detail-value {
            color: var(--gray);
            font-size: 0.85rem;
        }

        .staff-actions {
            padding: 1rem 1.5rem;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            background: var(--white);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-active {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
        }

        .status-inactive {
            background: rgba(127, 140, 141, 0.1);
            color: var(--gray);
        }

        .email-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 600;
            margin-left: 0.3rem;
        }

        .email-verified {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
        }

        .email-unverified {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }

        /* Modal Styles */
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
            background: white;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
            animation: modalSlideIn 0.3s ease-out;
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
            padding: 1.5rem;
            border-bottom: 1px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
            z-index: 1052;
        }

        .modal-header h2 {
            font-size: 1.5rem;
            color: var(--dark);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .modal-close:hover {
            background: var(--light);
            color: var(--dark);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--light);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            position: sticky;
            bottom: 0;
            background: white;
            z-index: 1052;
        }

        /* Form Styles */
        .form-section {
            background: var(--light);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-section h3 {
            font-size: 1rem;
            color: var(--dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
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

        .required::after {
            content: " *";
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--light);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
            font-family: inherit;
        }

        .form-control:focus {
            border-color: var(--secondary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .form-control[readonly] {
            background: var(--light);
            cursor: not-allowed;
        }

        /* View Modal Styles */
        .view-section {
            background: var(--light);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .view-section h3 {
            font-size: 1rem;
            color: var(--dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--white);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .info-item {
            padding: 0.75rem;
            background: white;
            border-radius: 6px;
        }

        .info-label {
            font-size: 0.8rem;
            color: var(--gray);
            margin-bottom: 0.25rem;
        }

        .info-value {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
        }

        .profile-image-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary), var(--purple));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.5rem;
            font-weight: 600;
            margin: 0 auto 1.5rem;
            background-size: cover;
            background-position: center;
            border: 4px solid var(--white);
            box-shadow: var(--shadow-md);
        }

        /* Loading Spinner */
        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--light);
            border-top-color: var(--secondary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
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

        .alert-danger {
            background: rgba(231, 76, 60, 0.1);
            border-color: var(--danger);
            color: var(--danger);
        }

        /* No Data */
        .no-data {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
        }

        .no-data i {
            font-size: 3rem;
            color: var(--gray-light);
            margin-bottom: 1rem;
        }

        .no-data h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .no-data p {
            color: var(--gray);
            margin-bottom: 1.5rem;
        }

        /* Animations */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate {
            animation: slideIn 0.5s ease-out;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .staff-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .staff-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .staff-header {
                flex-direction: column;
                text-align: center;
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
            <div>
                <h1><i class="fas fa-users-cog" style="color: var(--secondary); margin-right: 0.5rem;"></i>Staff Management</h1>
                <p>Manage teaching and administrative staff members</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="openModal('addStaffModal')">
                    <i class="fas fa-user-plus"></i> Add Staff
                </button>
                <a href="staff.php?export=csv" class="btn btn-outline">
                    <i class="fas fa-download"></i> Export
                </a>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success animate">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['success']); ?>
            <?php if (!empty($_GET['verification_link'])): ?>
                <a href="<?php echo htmlspecialchars($_GET['verification_link']); ?>" target="_blank" rel="noopener noreferrer" style="margin-left: 0.5rem; font-weight: 700; color: inherit; text-decoration: underline;">
                    Open verification link
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger animate">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_GET['error']); ?>
            <?php if (!empty($_GET['verification_link'])): ?>
                <a href="<?php echo htmlspecialchars($_GET['verification_link']); ?>" target="_blank" rel="noopener noreferrer" style="margin-left: 0.5rem; font-weight: 700; color: inherit; text-decoration: underline;">
                    Open verification link
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="stats-grid animate">
            <div class="stat-card total">
                <div class="stat-header">
                    <span class="stat-label">Total Staff</span>
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                </div>
                <div class="stat-value"><?php echo $total_staff; ?></div>
                <div class="stat-label">All staff members</div>
            </div>

            <div class="stat-card active">
                <div class="stat-header">
                    <span class="stat-label">Active</span>
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                </div>
                <div class="stat-value"><?php echo count($active_staff); ?></div>
                <div class="stat-label">Currently active</div>
            </div>

            <div class="stat-card teachers">
                <div class="stat-header">
                    <span class="stat-label">Teachers</span>
                    <div class="stat-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                </div>
                <div class="stat-value"><?php echo count($teachers); ?></div>
                <div class="stat-label">Teaching staff</div>
            </div>

            <div class="stat-card admins">
                <div class="stat-header">
                    <span class="stat-label">Admins</span>
                    <div class="stat-icon"><i class="fas fa-user-tie"></i></div>
                </div>
                <div class="stat-value"><?php echo count($admins); ?></div>
                <div class="stat-label">Administrators</div>
            </div>

            <div class="stat-card accountants">
                <div class="stat-header">
                    <span class="stat-label">Accountants</span>
                    <div class="stat-icon"><i class="fas fa-calculator"></i></div>
                </div>
                <div class="stat-value"><?php echo count($accountants); ?></div>
                <div class="stat-label">Finance staff</div>
            </div>

            <div class="stat-card librarians">
                <div class="stat-header">
                    <span class="stat-label">Librarians</span>
                    <div class="stat-icon"><i class="fas fa-book"></i></div>
                </div>
                <div class="stat-value"><?php echo count($librarians); ?></div>
                <div class="stat-label">Library staff</div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section animate">
            <div class="filter-header">
                <h3><i class="fas fa-filter" style="color: var(--secondary);"></i> Filter Staff</h3>
                <button class="btn btn-sm btn-outline" onclick="resetFilters()">
                    <i class="fas fa-redo-alt"></i> Reset
                </button>
            </div>
            <div class="filter-grid">
                <div class="form-group">
                    <label for="search">Search</label>
                    <input type="text" id="search" placeholder="Search by name, email, or role...">
                </div>
                <div class="form-group">
                    <label for="role_filter">Role</label>
                    <select id="role_filter">
                        <option value="">All Roles</option>
                        <option value="teacher">Teacher</option>
                        <option value="admin">Administrator</option>
                        <option value="accountant">Accountant</option>
                        <option value="librarian">Librarian</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="status_filter">Status</label>
                    <select id="status_filter">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div>
                    <button class="btn btn-primary" onclick="applyFilters()">
                        <i class="fas fa-search"></i> Apply
                    </button>
                </div>
            </div>
        </div>

        <!-- Staff Grid -->
        <div class="staff-grid animate">
            <?php foreach($staff as $member): 
                $initials = '';
                $name_parts = explode(' ', $member['full_name']);
                if (count($name_parts) >= 2) {
                    $initials = strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[count($name_parts)-1], 0, 1));
                } else {
                    $initials = strtoupper(substr($member['full_name'], 0, 2));
                }
            ?>
            <div class="staff-card <?php echo $member['role']; ?>" data-role="<?php echo $member['role']; ?>" data-status="<?php echo $member['status']; ?>">
                <div class="staff-header">
                    <div class="staff-avatar">
                        <?php echo $initials; ?>
                    </div>
                    <div class="staff-info">
                        <div class="staff-name"><?php echo htmlspecialchars($member['full_name']); ?></div>
                        <span class="staff-role <?php echo $member['role']; ?>">
                            <i class="fas <?php 
                                echo $member['role'] == 'teacher' ? 'fa-chalkboard-teacher' : 
                                    ($member['role'] == 'admin' ? 'fa-user-tie' : 
                                    ($member['role'] == 'accountant' ? 'fa-calculator' : 'fa-book')); 
                            ?>"></i>
                            <?php echo ucfirst($member['role']); ?>
                        </span>
                        <div class="staff-contact">
                            <div><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($member['email'] ?: 'No email'); ?></div>
                            <div><i class="fas fa-phone"></i> <?php echo htmlspecialchars($member['phone'] ?: 'No phone'); ?></div>
                        </div>
                    </div>
                </div>

                <div class="staff-stats">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $member['class_count']; ?></div>
                        <div class="stat-label">Classes</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $member['subject_count']; ?></div>
                        <div class="stat-label">Subjects</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">
                            <span class="status-badge status-<?php echo $member['status']; ?>">
                                <?php echo ucfirst($member['status']); ?>
                            </span>
                        </div>
                        <div class="stat-label">Status</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">
                            <span class="email-badge <?php echo (isset($member['email_verified']) && $member['email_verified']) ? 'email-verified' : 'email-unverified'; ?>">
                                <?php echo (isset($member['email_verified']) && $member['email_verified']) ? 'Verified' : 'Unverified'; ?>
                            </span>
                        </div>
                        <div class="stat-label">Email</div>
                    </div>
                </div>

                <div class="staff-details">
                    <div class="detail-row">
                        <span class="detail-label">Username:</span>
                        <span class="detail-value">@<?php echo htmlspecialchars($member['username']); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Employed:</span>
                        <span class="detail-value"><?php echo date('M j, Y', strtotime($member['employment_date'])); ?></span>
                    </div>
                </div>

                <div class="staff-actions">
                    <button class="btn btn-sm btn-outline" onclick="viewStaff(<?php echo $member['id']; ?>)" title="View">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn btn-sm btn-warning" onclick="editStaff(<?php echo $member['id']; ?>)" title="Edit">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-info" onclick="resetPassword(<?php echo $member['id']; ?>)" title="Reset Password">
                        <i class="fas fa-key"></i>
                    </button>
                    <?php if ((isset($member['email_verified']) && !$member['email_verified']) && !empty($member['email'])): ?>
                    <button class="btn btn-sm btn-success" onclick="resendVerification(<?php echo $member['id']; ?>)" title="Resend Verification">
                        <i class="fas fa-envelope"></i>
                    </button>
                    <?php endif; ?>
                    <?php if ($member['id'] != $_SESSION['user_id']): ?>
                    <button class="btn btn-sm btn-danger" onclick="deleteStaff(<?php echo $member['id']; ?>)" title="Delete">
                        <i class="fas fa-trash"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($staff)): ?>
        <div class="no-data animate">
            <i class="fas fa-users-slash"></i>
            <h3>No Staff Members Found</h3>
            <p>Get started by adding your first staff member.</p>
            <button class="btn btn-primary" onclick="openModal('addStaffModal')">
                <i class="fas fa-user-plus"></i> Add First Staff Member
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Add Staff Modal -->
    <div id="addStaffModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-plus" style="color: var(--success);"></i> Add New Staff Member</h2>
                <button class="modal-close" onclick="closeModal('addStaffModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addStaffForm">
                    <!-- Personal Information -->
                    <div class="form-section">
                        <h3><i class="fas fa-user"></i> Personal Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="required">Full Name</label>
                                <input type="text" name="full_name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label class="required">Username</label>
                                <input type="text" name="username" class="form-control" required>
                                <small style="color: var(--gray);">Must be unique, min 3 characters</small>
                            </div>
                            <div class="form-group">
                                <label class="required">Role</label>
                                <select name="role" class="form-control" required>
                                    <option value="">Select Role</option>
                                    <option value="teacher">Teacher</option>
                                    <option value="admin">Administrator</option>
                                    <option value="accountant">Accountant</option>
                                    <option value="librarian">Librarian</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="required">Email Address</label>
                                <input type="email" name="email" class="form-control" required>
                                <small style="color: var(--gray);">Required for account verification</small>
                            </div>
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="tel" name="phone" class="form-control" placeholder="e.g., 2547XXXXXXXX">
                            </div>
                        </div>
                    </div>

                    <!-- Address & Qualifications -->
                    <div class="form-section">
                        <h3><i class="fas fa-address-card"></i> Address & Qualifications</h3>
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label>Address</label>
                                <textarea name="address" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="form-group full-width">
                                <label>Qualifications</label>
                                <textarea name="qualifications" class="form-control" rows="3" placeholder="e.g., Bachelor of Education, TSC Certified..."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Employment Information -->
                    <div class="form-section">
                        <h3><i class="fas fa-briefcase"></i> Employment Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="required">Employment Date</label>
                                <input type="date" name="employment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="add_staff" value="1">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addStaffModal')">Cancel</button>
                <button type="button" class="btn btn-success" onclick="submitAddStaff()">
                    <i class="fas fa-save"></i> Add Staff Member
                </button>
            </div>
        </div>
    </div>

    <!-- View Staff Modal -->
    <div id="viewStaffModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user" style="color: var(--secondary);"></i> Staff Member Details</h2>
                <button class="modal-close" onclick="closeModal('viewStaffModal')">&times;</button>
            </div>
            <div class="modal-body" id="viewStaffContent">
                <div style="text-align: center; padding: 2rem;">
                    <div class="loading-spinner"></div>
                    <p style="margin-top: 1rem; color: var(--gray);">Loading staff details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('viewStaffModal')">Close</button>
                <button type="button" class="btn btn-warning" onclick="editFromView()" id="editFromViewBtn">Edit Staff</button>
            </div>
        </div>
    </div>

    <!-- Edit Staff Modal -->
    <div id="editStaffModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-edit" style="color: var(--warning);"></i> Edit Staff Member</h2>
                <button class="modal-close" onclick="closeModal('editStaffModal')">&times;</button>
            </div>
            <div class="modal-body" id="editStaffContent">
                <div style="text-align: center; padding: 2rem;">
                    <div class="loading-spinner"></div>
                    <p style="margin-top: 1rem; color: var(--gray);">Loading staff data...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editStaffModal')">Cancel</button>
                <button type="button" class="btn btn-warning" onclick="submitEditStaff()">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Modal Functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = 'auto';
            
            // Clear modal content when closing
            if (modalId === 'viewStaffModal') {
                document.getElementById('viewStaffContent').innerHTML = `
                    <div style="text-align: center; padding: 2rem;">
                        <div class="loading-spinner"></div>
                        <p style="margin-top: 1rem; color: var(--gray);">Loading staff details...</p>
                    </div>
                `;
            }
        }

        // Submit Add Staff Form
        function submitAddStaff() {
            const form = document.getElementById('addStaffForm');
            
            // Validate required fields
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.style.borderColor = 'var(--danger)';
                    isValid = false;
                } else {
                    field.style.borderColor = 'var(--light)';
                }
            });

            // Validate username length
            const username = form.querySelector('input[name="username"]');
            if (username.value.length < 3) {
                username.style.borderColor = 'var(--danger)';
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Username',
                    text: 'Username must be at least 3 characters long.'
                });
                return;
            }

            if (!isValid) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Please fill all required fields'
                });
                return;
            }

            Swal.fire({
                title: 'Adding Staff Member...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            form.submit();
        }

        // View Staff
        function viewStaff(staffId) {
            openModal('viewStaffModal');
            
            fetch(`staff.php?ajax=get_staff&id=${staffId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayStaffView(data.staff);
                        document.getElementById('editFromViewBtn').onclick = function() {
                            closeModal('viewStaffModal');
                            editStaff(staffId);
                        };
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.error
                        });
                        closeModal('viewStaffModal');
                    }
                })
                .catch(error => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to load staff details'
                    });
                    closeModal('viewStaffModal');
                });
        }

        // Display Staff View
        function displayStaffView(staff) {
            const initials = getInitials(staff.full_name);
            
            let html = `
                <div class="profile-image-large">
                    ${initials}
                </div>

                <div class="view-section">
                    <h3><i class="fas fa-id-card"></i> Personal Information</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Full Name</div>
                            <div class="info-value">${staff.full_name}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Username</div>
                            <div class="info-value">@${staff.username}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Role</div>
                            <div class="info-value">${capitalize(staff.role)}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Status</div>
                            <div class="info-value">
                                <span class="status-badge status-${staff.status}">${capitalize(staff.status)}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="view-section">
                    <h3><i class="fas fa-address-book"></i> Contact Information</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Email Address</div>
                            <div class="info-value">
                                ${staff.email || 'N/A'}
                                ${staff.email_verified ? 
                                    '<span class="email-badge email-verified">Verified</span>' : 
                                    '<span class="email-badge email-unverified">Unverified</span>'}
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Phone Number</div>
                            <div class="info-value">${staff.phone || 'N/A'}</div>
                        </div>
                        <div class="info-item full-width">
                            <div class="info-label">Address</div>
                            <div class="info-value">${staff.address || 'No address provided'}</div>
                        </div>
                    </div>
                </div>

                <div class="view-section">
                    <h3><i class="fas fa-graduation-cap"></i> Qualifications</h3>
                    <div class="info-item">
                        <div class="info-value">${staff.qualifications || 'No qualifications recorded'}</div>
                    </div>
                </div>

                <div class="view-section">
                    <h3><i class="fas fa-briefcase"></i> Employment Details</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Employment Date</div>
                            <div class="info-value">${formatDate(staff.employment_date)}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Member Since</div>
                            <div class="info-value">${formatDate(staff.created_at)}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Classes</div>
                            <div class="info-value">${staff.class_count}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Subjects</div>
                            <div class="info-value">${staff.subject_count}</div>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('viewStaffContent').innerHTML = html;
        }

        // Edit Staff
        function editStaff(staffId) {
            openModal('editStaffModal');
            
            fetch(`staff.php?ajax=get_staff&id=${staffId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayStaffEditForm(data.staff);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.error
                        });
                        closeModal('editStaffModal');
                    }
                })
                .catch(error => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to load staff data'
                    });
                    closeModal('editStaffModal');
                });
        }

        // Display Staff Edit Form
        function displayStaffEditForm(staff) {
            let html = `
                <form method="POST" id="editStaffForm">
                    <input type="hidden" name="user_id" value="${staff.id}">

                    <!-- Personal Information -->
                    <div class="form-section">
                        <h3><i class="fas fa-user"></i> Personal Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="required">Full Name</label>
                                <input type="text" name="full_name" class="form-control" value="${staff.full_name.replace(/"/g, '&quot;')}" required>
                            </div>
                            <div class="form-group">
                                <label class="required">Role</label>
                                <select name="role" class="form-control" required>
                                    <option value="teacher" ${staff.role === 'teacher' ? 'selected' : ''}>Teacher</option>
                                    <option value="admin" ${staff.role === 'admin' ? 'selected' : ''}>Administrator</option>
                                    <option value="accountant" ${staff.role === 'accountant' ? 'selected' : ''}>Accountant</option>
                                    <option value="librarian" ${staff.role === 'librarian' ? 'selected' : ''}>Librarian</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="required">Email Address</label>
                                <input type="email" name="email" class="form-control" value="${staff.email ? staff.email.replace(/"/g, '&quot;') : ''}" required>
                            </div>
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="tel" name="phone" class="form-control" value="${staff.phone || ''}">
                            </div>
                        </div>
                    </div>

                    <!-- Address & Qualifications -->
                    <div class="form-section">
                        <h3><i class="fas fa-address-card"></i> Address & Qualifications</h3>
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label>Address</label>
                                <textarea name="address" class="form-control" rows="2">${staff.address || ''}</textarea>
                            </div>
                            <div class="form-group full-width">
                                <label>Qualifications</label>
                                <textarea name="qualifications" class="form-control" rows="3">${staff.qualifications || ''}</textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Employment Information -->
                    <div class="form-section">
                        <h3><i class="fas fa-briefcase"></i> Employment Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="required">Employment Date</label>
                                <input type="date" name="employment_date" class="form-control" value="${staff.employment_date}" required>
                            </div>
                            <div class="form-group">
                                <label class="required">Status</label>
                                <select name="status" class="form-control" required>
                                    <option value="active" ${staff.status === 'active' ? 'selected' : ''}>Active</option>
                                    <option value="inactive" ${staff.status === 'inactive' ? 'selected' : ''}>Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="update_staff" value="1">
                </form>
            `;
            
            document.getElementById('editStaffContent').innerHTML = html;
        }

        // Submit Edit Form
        function submitEditStaff() {
            const form = document.getElementById('editStaffForm');
            
            // Validate required fields
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.style.borderColor = 'var(--danger)';
                    isValid = false;
                } else {
                    field.style.borderColor = 'var(--light)';
                }
            });

            if (!isValid) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Please fill all required fields'
                });
                return;
            }

            Swal.fire({
                title: 'Updating Staff Member...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            form.submit();
        }

        // Delete Staff
        function deleteStaff(staffId) {
            Swal.fire({
                title: 'Delete Staff Member?',
                text: 'This action cannot be undone. All associated data will be affected.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#e74c3c',
                cancelButtonColor: '#7f8c8d',
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="user_id" value="${staffId}">
                        <input type="hidden" name="delete_staff" value="1">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Reset Password
        function resetPassword(staffId) {
            Swal.fire({
                title: 'Reset Password?',
                text: 'Password will be reset to: password123. Staff member will need to change it on first login.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3498db',
                cancelButtonColor: '#7f8c8d',
                confirmButtonText: 'Yes, reset it',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="user_id" value="${staffId}">
                        <input type="hidden" name="reset_password" value="1">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Resend Verification
        function resendVerification(staffId) {
            Swal.fire({
                title: 'Resend Verification Email?',
                text: 'A new verification link will be sent to the staff member\'s email.',
                icon: 'info',
                showCancelButton: true,
                confirmButtonColor: '#27ae60',
                cancelButtonColor: '#7f8c8d',
                confirmButtonText: 'Yes, send it',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="user_id" value="${staffId}">
                        <input type="hidden" name="resend_verification" value="1">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Helper Functions
        function getInitials(name) {
            const parts = name.split(' ');
            if (parts.length >= 2) {
                return (parts[0][0] + parts[parts.length-1][0]).toUpperCase();
            }
            return name.substring(0, 2).toUpperCase();
        }

        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' });
        }

        function capitalize(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        }

        // Edit from View
        function editFromView() {
            // This function is dynamically set in viewStaff
        }

        // Filter Functions
        function applyFilters() {
            const searchTerm = document.getElementById('search').value.toLowerCase();
            const roleFilter = document.getElementById('role_filter').value;
            const statusFilter = document.getElementById('status_filter').value;
            
            const cards = document.querySelectorAll('.staff-card');
            
            cards.forEach(card => {
                const text = card.textContent.toLowerCase();
                const role = card.getAttribute('data-role');
                const status = card.getAttribute('data-status');
                
                let show = true;
                
                if (searchTerm && !text.includes(searchTerm)) show = false;
                if (roleFilter && role !== roleFilter) show = false;
                if (statusFilter && status !== statusFilter) show = false;
                
                card.style.display = show ? 'block' : 'none';
            });
        }

        function resetFilters() {
            document.getElementById('search').value = '';
            document.getElementById('role_filter').value = '';
            document.getElementById('status_filter').value = '';
            
            document.querySelectorAll('.staff-card').forEach(card => {
                card.style.display = 'block';
            });
        }

        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                closeModal(event.target.id);
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(modal => {
                    closeModal(modal.id);
                });
            }
        });

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
