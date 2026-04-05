<?php
include '../config.php';
checkAuth();
checkRole(['admin']);

// Handle AJAX requests first
if (isset($_GET['ajax']) && $_GET['ajax'] === 'generate_admission') {
    header('Content-Type: application/json');
    
    $prefix = isset($_GET['prefix']) ? strtoupper(trim($_GET['prefix'])) : '';
    
    if (empty($prefix)) {
        echo json_encode(['success' => false, 'error' => 'Prefix is required']);
        exit();
    }
    
    // Get the current year
    $year = date('Y');
    
    // Find the latest admission number with this prefix
    $stmt = $pdo->prepare("SELECT Admission_number FROM students WHERE Admission_number LIKE ? ORDER BY Admission_number DESC LIMIT 1");
    $stmt->execute(["$prefix%$year%"]);
    $last = $stmt->fetchColumn();
    
    if ($last) {
        // Extract the sequential number
        $parts = explode('/', $last);
        $lastNum = (int)end($parts);
        $nextNum = $lastNum + 1;
    } else {
        $nextNum = 1;
    }
    
    // Format: PREFIX/YYYY/0001
    $admission_number = $prefix . '/' . $year . '/' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
    
    echo json_encode(['success' => true, 'admission_number' => $admission_number]);
    exit();
}

// Handle AJAX request for getting student details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_student') {
    header('Content-Type: application/json');
    
    $student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($student_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid student ID']);
        exit();
    }
    
    $stmt = $pdo->prepare("
        SELECT s.*, c.class_name, c.id as class_id, 
               p.id as parent_id, p.full_name as parent_name, p.relationship as parent_relationship,
               p.phone as parent_phone, p.email as parent_email, p.occupation as parent_occupation,
               p.address as parent_address
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN parents p ON s.parent_id = p.id
        WHERE s.id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($student) {
        // Format dates for display
        $student['date_of_birth'] = date('Y-m-d', strtotime($student['date_of_birth']));
        $student['admission_date'] = date('Y-m-d', strtotime($student['admission_date']));
        
        echo json_encode(['success' => true, 'student' => $student]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Student not found']);
    }
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_student'])) {
        $admission_number = $_POST['admission_number'];
        $full_name = $_POST['full_name'];
        $gender = $_POST['gender'];
        $date_of_birth = $_POST['date_of_birth'];
        $place_of_birth = $_POST['place_of_birth'];
        $nationality = $_POST['nationality'];
        $religion = $_POST['religion'];
        $address = $_POST['address'];
        $city = $_POST['city'];
        $postal_code = $_POST['postal_code'];
        $phone = $_POST['phone'];
        $email = $_POST['email'];
        
        // Parent/Guardian Information
        $parent_name = $_POST['parent_name'];
        $parent_relationship = $_POST['parent_relationship'];
        $parent_phone = $_POST['parent_phone'];
        $parent_email = $_POST['parent_email'];
        $parent_occupation = $_POST['parent_occupation'];
        $parent_address = $_POST['parent_address'];
        
        // Emergency Contact
        $emergency_name = $_POST['emergency_name'];
        $emergency_relationship = $_POST['emergency_relationship'];
        $emergency_phone = $_POST['emergency_phone'];
        
        // Academic Information
        $class_id = $_POST['class_id'];
        $admission_date = $_POST['admission_date'];
        $previous_school = $_POST['previous_school'];
        $medical_notes = $_POST['medical_notes'];
        
        // Check if student ID already exists
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE Admission_number = ?");
        $check_stmt->execute([$admission_number]);
        if ($check_stmt->fetchColumn() > 0) {
            $error = "Student ID already exists. Please use a different ID.";
        } else {
            try {
                $pdo->beginTransaction();
                
                // Insert parent/guardian first
                $stmt = $pdo->prepare("INSERT INTO parents (full_name, relationship, phone, email, occupation, address) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$parent_name, $parent_relationship, $parent_phone, $parent_email, $parent_occupation, $parent_address]);
                $parent_id = $pdo->lastInsertId();
                
                // Insert student
                $stmt = $pdo->prepare("INSERT INTO students (
                    Admission_number, full_name, gender, date_of_birth, place_of_birth, 
                    nationality, religion, address, city, postal_code,
                    parent_id, emergency_contact_name, emergency_contact_relationship, 
                    emergency_contact_phone, class_id, admission_date, previous_school, 
                    medical_notes, status, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)");
                
                $stmt->execute([
                    $admission_number, $full_name, $gender, $date_of_birth, $place_of_birth,
                    $nationality, $religion, $address, $city, $postal_code,
                    $parent_id, $emergency_name, $emergency_relationship, $emergency_phone,
                    $class_id, $admission_date, $previous_school, $medical_notes,
                    $_SESSION['user_id']
                ]);
                
                $student_id = $pdo->lastInsertId();
                
                // Handle profile picture upload
                if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                    $upload_result = handleProfilePictureUpload($student_id);
                    if (!$upload_result['success']) {
                        // Log error but don't fail the student creation
                        error_log("Profile picture upload failed: " . $upload_result['message']);
                    }
                }
                
                $pdo->commit();
                
                header("Location: students.php?success=Student added successfully&id=" . $student_id);
                exit();
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Failed to add student: " . $e->getMessage();
                error_log("Student creation error: " . $e->getMessage());
            }
        }
    }
    
    if (isset($_POST['edit_student'])) {
        $student_db_id = $_POST['student_db_id'];
        $admission_number = $_POST['admission_number'];
        $full_name = $_POST['full_name'];
        $gender = $_POST['gender'];
        $date_of_birth = $_POST['date_of_birth'];
        $place_of_birth = $_POST['place_of_birth'];
        $nationality = $_POST['nationality'];
        $religion = $_POST['religion'];
        $address = $_POST['address'];
        $city = $_POST['city'];
        $postal_code = $_POST['postal_code'];
        $phone = $_POST['phone'];
        $email = $_POST['email'];
        
        // Parent/Guardian Information
        $parent_id = $_POST['parent_id'];
        $parent_name = $_POST['parent_name'];
        $parent_relationship = $_POST['parent_relationship'];
        $parent_phone = $_POST['parent_phone'];
        $parent_email = $_POST['parent_email'];
        $parent_occupation = $_POST['parent_occupation'];
        $parent_address = $_POST['parent_address'];
        
        // Emergency Contact
        $emergency_name = $_POST['emergency_name'];
        $emergency_relationship = $_POST['emergency_relationship'];
        $emergency_phone = $_POST['emergency_phone'];
        
        // Academic Information
        $class_id = $_POST['class_id'];
        $admission_date = $_POST['admission_date'];
        $previous_school = $_POST['previous_school'];
        $medical_notes = $_POST['medical_notes'];
        $status = $_POST['status'];
        
        // Check if new student ID already exists (only if ID was changed)
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE Admission_number = ? AND id != ?");
        $check_stmt->execute([$admission_number, $student_db_id]);
        if ($check_stmt->fetchColumn() > 0) {
            $error = "Student ID already exists. Please use a different ID.";
        } else {
            try {
                $pdo->beginTransaction();
                
                // Update parent/guardian
                if ($parent_id) {
                    $stmt = $pdo->prepare("UPDATE parents SET full_name = ?, relationship = ?, phone = ?, email = ?, occupation = ?, address = ? WHERE id = ?");
                    $stmt->execute([$parent_name, $parent_relationship, $parent_phone, $parent_email, $parent_occupation, $parent_address, $parent_id]);
                }
                
                // Update student
                $stmt = $pdo->prepare("UPDATE students SET 
                    Admission_number = ?, full_name = ?, gender = ?, date_of_birth = ?, 
                    place_of_birth = ?, nationality = ?, religion = ?, address = ?, 
                    city = ?, postal_code = ?,
                    emergency_contact_name = ?, emergency_contact_relationship = ?, 
                    emergency_contact_phone = ?, class_id = ?, admission_date = ?, 
                    previous_school = ?, medical_notes = ?, status = ? 
                    WHERE id = ?");
                
                $stmt->execute([
                    $admission_number, $full_name, $gender, $date_of_birth, $place_of_birth,
                    $nationality, $religion, $address, $city, $postal_code,
                    $emergency_name, $emergency_relationship, $emergency_phone,
                    $class_id, $admission_date, $previous_school, $medical_notes, $status,
                    $student_db_id
                ]);
                
                // Handle profile picture upload
                if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                    $upload_result = handleProfilePictureUpload($student_db_id);
                    if (!$upload_result['success']) {
                        error_log("Profile picture upload failed: " . $upload_result['message']);
                    }
                }
                
                // Handle profile picture removal
                if (isset($_POST['remove_profile_picture'])) {
                    removeProfilePicture($student_db_id);
                }
                
                $pdo->commit();
                
                header("Location: students.php?success=Student updated successfully");
                exit();
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Failed to update student: " . $e->getMessage();
            }
        }
    }
    
    if (isset($_POST['delete_student'])) {
        $student_db_id = $_POST['student_db_id'];
        
        try {
            // Get student data to find parent_id
            $stmt = $pdo->prepare("SELECT parent_id FROM students WHERE id = ?");
            $stmt->execute([$student_db_id]);
            $student = $stmt->fetch();
            
            $pdo->beginTransaction();
            
            // Remove profile picture if exists
            removeProfilePicture($student_db_id);
            
            // Delete student
            $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
            $stmt->execute([$student_db_id]);
            
            // Optionally delete parent if no other students reference them
            if ($student && $student['parent_id']) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE parent_id = ?");
                $stmt->execute([$student['parent_id']]);
                if ($stmt->fetchColumn() == 0) {
                    $stmt = $pdo->prepare("DELETE FROM parents WHERE id = ?");
                    $stmt->execute([$student['parent_id']]);
                }
            }
            
            $pdo->commit();
            
            header("Location: students.php?success=Student deleted successfully");
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to delete student: " . $e->getMessage();
        }
    }
}

// Function to handle profile picture upload
function handleProfilePictureUpload($student_id) {
    global $pdo;
    
    $upload_dir = '../uploads/students/';
    
    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $file = $_FILES['profile_picture'];
    $file_name = $file['name'];
    $file_tmp = $file['tmp_name'];
    $file_size = $file['size'];
    
    // Get file extension
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    
    // Allowed extensions
    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
    
    // Check if file type is allowed
    if (!in_array($file_ext, $allowed_ext)) {
        return ['success' => false, 'message' => 'Only JPG, JPEG, PNG, and GIF files are allowed.'];
    }
    
    // Check file size (max 2MB)
    if ($file_size > 2097152) {
        return ['success' => false, 'message' => 'File size must be less than 2MB.'];
    }
    
    // Generate unique filename
    $new_filename = 'student_' . $student_id . '_' . time() . '.' . $file_ext;
    $file_destination = $upload_dir . $new_filename;
    
    // Remove old profile picture if exists
    removeOldProfilePicture($student_id);
    
    // Move uploaded file
    if (move_uploaded_file($file_tmp, $file_destination)) {
        // Update database with new profile picture path
        $stmt = $pdo->prepare("UPDATE students SET profile_picture = ? WHERE id = ?");
        if ($stmt->execute([$new_filename, $student_id])) {
            return ['success' => true, 'message' => 'Profile picture uploaded successfully!'];
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
function removeOldProfilePicture($student_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT profile_picture FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $current_picture = $stmt->fetchColumn();
    
    if ($current_picture) {
        $file_path = '../uploads/students/' . $current_picture;
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }
}

// Function to remove profile picture
function removeProfilePicture($student_id) {
    global $pdo;
    
    removeOldProfilePicture($student_id);
    
    $stmt = $pdo->prepare("UPDATE students SET profile_picture = NULL WHERE id = ?");
    return $stmt->execute([$student_id]);
}

// Get profile picture URL
function getStudentProfilePicture($student) {
    if (!empty($student['profile_picture'])) {
        return '../uploads/students/' . $student['profile_picture'];
    }
    return null;
}

// Get all students with class and parent information
$students = $pdo->query("
    SELECT s.*, c.class_name, p.full_name as parent_name, p.phone as parent_phone 
    FROM students s 
    LEFT JOIN classes c ON s.class_id = c.id 
    LEFT JOIN parents p ON s.parent_id = p.id 
    ORDER BY s.created_at DESC
")->fetchAll();

// Get classes for dropdown
$classes = $pdo->query("SELECT * FROM classes ORDER BY class_name")->fetchAll();

// Handle export
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=students_' . date('Y-m-d_H-i-s') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write header row
    fputcsv($output, [
        'Student ID', 'Full Name', 'Gender', 'Date of Birth', 'Place of Birth', 
        'Nationality', 'Religion', 'Address', 'City', 'Postal Code', 'Phone', 'Email',
        'Parent Name', 'Parent Relationship', 'Parent Phone', 'Parent Email', 'Parent Occupation',
        'Emergency Contact', 'Emergency Relationship', 'Emergency Phone',
        'Class', 'Admission Date', 'Previous School', 'Medical Notes', 'Status'
    ], ',');
    
    // Write data rows
    foreach ($students as $student) {
        fputcsv($output, [
            $student['Admission_number'],
            $student['full_name'],
            $student['gender'],
            $student['date_of_birth'],
            $student['place_of_birth'],
            $student['nationality'],
            $student['religion'],
            $student['address'],
            $student['city'],
            $student['postal_code'],
            $student['phone'],
            $student['email'],
            $student['parent_name'],
            $student['parent_relationship'],
            $student['parent_phone'],
            $student['parent_email'],
            $student['parent_occupation'],
            $student['emergency_contact_name'],
            $student['emergency_contact_relationship'],
            $student['emergency_contact_phone'],
            $student['class_name'] ?: 'Not assigned',
            $student['admission_date'],
            $student['previous_school'],
            $student['medical_notes'],
            $student['status']
        ], ',');
    }
    
    fclose($output);
    exit;
}

$page_title = "Manage Students - " . SCHOOL_NAME;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../assets/css/responsive.css">
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

        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .kpi-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1.5rem;
            box-shadow: var(--shadow-md);
            transition: all 0.3s;
            border: 1px solid rgba(255,255,255,0.3);
            border-left: 4px solid;
        }

        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .kpi-card.primary { border-left-color: var(--secondary); }
        .kpi-card.success { border-left-color: var(--success); }
        .kpi-card.warning { border-left-color: var(--warning); }
        .kpi-card.info { border-left-color: var(--info); }
        .kpi-card.purple { border-left-color: var(--purple); }

        .kpi-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: white;
            flex-shrink: 0;
        }

        .kpi-card.primary .kpi-icon { background: linear-gradient(135deg, var(--secondary), var(--purple)); }
        .kpi-card.success .kpi-icon { background: linear-gradient(135deg, var(--success), var(--success-light)); }
        .kpi-card.warning .kpi-icon { background: linear-gradient(135deg, var(--warning), var(--warning-light)); }
        .kpi-card.info .kpi-icon { background: linear-gradient(135deg, var(--info), #138496); }
        .kpi-card.purple .kpi-icon { background: linear-gradient(135deg, var(--purple), var(--purple-light)); }

        .kpi-content {
            flex: 1;
        }

        .kpi-label {
            font-size: 0.85rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .kpi-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .kpi-trend {
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .trend-up { color: var(--success); }
        .trend-down { color: var(--danger); }

        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--light);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--secondary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

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

        /* Data Table */
        .data-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }

        .card-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .table-stats {
            display: flex;
            gap: 1.5rem;
        }

        .table-stats span {
            font-size: 0.9rem;
            background: rgba(255,255,255,0.2);
            padding: 0.5rem 1rem;
            border-radius: 20px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            padding: 1.25rem 1rem;
            text-align: left;
            background: var(--light);
            color: var(--dark);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        td {
            padding: 1.25rem 1rem;
            border-bottom: 1px solid var(--light);
            color: var(--dark);
        }

        tr {
            transition: all 0.2s;
        }

        tr:hover {
            background: rgba(52, 152, 219, 0.05);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-active {
            background: rgba(39, 174, 96, 0.15);
            color: var(--success);
        }

        .status-graduated {
            background: rgba(52, 152, 219, 0.15);
            color: var(--secondary);
        }

        .status-transferred {
            background: rgba(243, 156, 18, 0.15);
            color: var(--warning);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            padding: 0.5rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
        }

        .action-btn.view { background: var(--secondary); color: white; }
        .action-btn.edit { background: var(--warning); color: white; }
        .action-btn.delete { background: var(--danger); color: white; }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
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
            background: white;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 900px;
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
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary), var(--purple));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            font-weight: 600;
            margin: 0 auto 1.5rem;
            background-size: cover;
            background-position: center;
            border: 4px solid var(--white);
            box-shadow: var(--shadow-md);
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

        /* Profile Picture Upload */
        .profile-upload {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin-bottom: 1.5rem;
        }

        .profile-preview {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary), var(--purple));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            font-weight: 600;
            border: 4px solid var(--white);
            box-shadow: var(--shadow-md);
            background-size: cover;
            background-position: center;
        }

        .upload-controls {
            flex: 1;
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
            transition: all 0.3s;
        }

        .file-label:hover {
            background: var(--purple);
        }

        .file-name {
            margin-top: 0.5rem;
            color: var(--gray);
            font-size: 0.9rem;
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
            
            .profile-upload {
                flex-direction: column;
                text-align: center;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .card-header {
                flex-direction: column;
                gap: 1rem;
            }
            
            .action-buttons {
                justify-content: center;
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
                <h1><i class="fas fa-user-graduate" style="color: var(--secondary); margin-right: 0.5rem;"></i>Student Management</h1>
                <p>Manage student records and information</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-success" onclick="openStudentRegistration()">
                    <i class="fas fa-user-plus"></i> Register New Student
                </button>
                <a href="students.php?export=csv" class="btn btn-outline">
                    <i class="fas fa-download"></i> Export CSV
                </a>
            </div>
        </div>

        <!-- Messages -->
        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success animate">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['success']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
        <div class="alert alert-danger animate">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
        </div>
        <?php endif; ?>

        <!-- KPI Cards -->
        <div class="kpi-grid animate">
            <div class="kpi-card primary">
                <div class="kpi-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Total Students</div>
                    <div class="kpi-value"><?php echo count($students); ?></div>
                    <div class="kpi-trend">
                        <span>Enrolled</span>
                    </div>
                </div>
            </div>

            <div class="kpi-card success">
                <div class="kpi-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Active</div>
                    <div class="kpi-value">
                        <?php echo count(array_filter($students, fn($s) => $s['status'] == 'active')); ?>
                    </div>
                    <div class="kpi-trend">
                        <span class="trend-up">Currently enrolled</span>
                    </div>
                </div>
            </div>

            <div class="kpi-card info">
                <div class="kpi-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">Classes</div>
                    <div class="kpi-value"><?php echo count($classes); ?></div>
                    <div class="kpi-trend">
                        <span>Available</span>
                    </div>
                </div>
            </div>

            <div class="kpi-card purple">
                <div class="kpi-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-label">This Year</div>
                    <div class="kpi-value">
                        <?php echo count(array_filter($students, fn($s) => date('Y', strtotime($s['admission_date'])) == date('Y'))); ?>
                    </div>
                    <div class="kpi-trend">
                        <span>New admissions</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section animate">
            <div class="filter-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3><i class="fas fa-filter"></i> Filter Students</h3>
                <button class="btn btn-sm btn-outline" onclick="resetFilters()">
                    <i class="fas fa-redo"></i> Reset
                </button>
            </div>
            <div class="filter-grid">
                <div class="form-group">
                    <label>Search</label>
                    <input type="text" id="searchInput" placeholder="Name, ID, or parent...">
                </div>
                <div class="form-group">
                    <label>Class</label>
                    <select id="classFilter">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['class_name']; ?>"><?php echo $class['class_name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select id="statusFilter">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="graduated">Graduated</option>
                        <option value="transferred">Transferred</option>
                    </select>
                </div>
                <div class="form-group" style="display: flex; gap: 0.5rem; align-items: flex-end;">
                    <button class="btn btn-primary" onclick="applyFilters()">
                        <i class="fas fa-search"></i> Apply
                    </button>
                </div>
            </div>
        </div>

        <!-- Students Table -->
        <div class="data-card animate">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Student Records</h3>
                <div class="table-stats">
                    <span><i class="fas fa-users"></i> Total: <?php echo count($students); ?></span>
                </div>
            </div>

            <div class="table-responsive">
                <table id="studentsTable">
                    <thead>
                        <tr>
                            <th>Photo</th>
                            <th>Admission #</th>
                            <th>Student Name</th>
                            <th>Class</th>
                            <th>Gender</th>
                            <th>Parent Contact</th>
                            <th>Admission Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): 
                            $profile_pic = getStudentProfilePicture($student);
                        ?>
                        <tr data-status="<?php echo $student['status']; ?>" data-class="<?php echo $student['class_name']; ?>">
                            <td>
                                <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--secondary), var(--purple)); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; background-size: cover; background-position: center; <?php echo $profile_pic ? "background-image: url('$profile_pic');" : ''; ?>">
                                    <?php if (!$profile_pic): ?>
                                        <?php 
                                        $initials = '';
                                        $name_parts = explode(' ', $student['full_name']);
                                        if (count($name_parts) >= 2) {
                                            $initials = strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[count($name_parts)-1], 0, 1));
                                        } else {
                                            $initials = strtoupper(substr($student['full_name'], 0, 2));
                                        }
                                        echo $initials;
                                        ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><strong><?php echo htmlspecialchars($student['Admission_number']); ?></strong></td>
                            <td>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                <div style="font-size: 0.8rem; color: var(--gray);">DOB: <?php echo date('d M Y', strtotime($student['date_of_birth'])); ?></div>
                            </td>
                            <td><?php echo htmlspecialchars($student['class_name'] ?? 'Not assigned'); ?></td>
                            <td><?php echo $student['gender']; ?></td>
                            <td>
                                <div><?php echo htmlspecialchars($student['parent_name'] ?? 'N/A'); ?></div>
                                <div style="font-size: 0.8rem; color: var(--secondary);"><?php echo htmlspecialchars($student['parent_phone'] ?? ''); ?></div>
                            </td>
                            <td><?php echo date('d M Y', strtotime($student['admission_date'])); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $student['status']; ?>">
                                    <?php echo ucfirst($student['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn view" onclick="viewStudent(<?php echo $student['id']; ?>)" title="View">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="action-btn edit" onclick="editStudent(<?php echo $student['id']; ?>)" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="action-btn delete" onclick="deleteStudent(<?php echo $student['id']; ?>)" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Student Registration Modal -->
    <div id="studentRegistrationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-plus" style="color: var(--success);"></i> Register New Student</h2>
                <button class="modal-close" onclick="closeModal('studentRegistrationModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data" id="studentRegistrationForm">
                    <!-- Profile Picture Upload -->
                    <div class="form-section">
                        <h3><i class="fas fa-camera"></i> Profile Picture</h3>
                        <div class="profile-upload">
                            <div class="profile-preview" id="profilePreview">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="upload-controls">
                                <input type="file" id="profile_picture" name="profile_picture" class="file-input" accept="image/*" onchange="previewProfileImage(this)">
                                <label for="profile_picture" class="file-label">
                                    <i class="fas fa-upload"></i> Choose Image
                                </label>
                                <div class="file-name" id="fileName">No file chosen</div>
                                <small style="color: var(--gray);">Max size: 2MB. Allowed: JPG, PNG, GIF</small>
                            </div>
                        </div>
                    </div>

                    <!-- Admission Information -->
                    <div class="form-section">
                        <h3><i class="fas fa-id-card"></i> Admission Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="required">Admission Prefix</label>
                                <div style="display: flex; gap: 0.5rem;">
                                    <input type="text" id="admission_prefix" class="form-control" placeholder="e.g., MPS" maxlength="10" style="flex: 1;" required>
                                    <button type="button" class="btn btn-primary" onclick="generateAdmissionNumber()">
                                        <i class="fas fa-sync-alt"></i> Generate
                                    </button>
                                </div>
                                <small style="color: var(--gray);">Enter prefix and click Generate</small>
                            </div>
                            <div class="form-group">
                                <label class="required">Admission Number</label>
                                <input type="text" id="admission_number" name="admission_number" class="form-control" readonly required>
                            </div>
                            <div class="form-group">
                                <label class="required">Admission Date</label>
                                <input type="date" name="admission_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="required">Class</label>
                                <select name="class_id" class="form-control" required>
                                    <option value="">Select Class</option>
                                    <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>"><?php echo $class['class_name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Previous School</label>
                                <input type="text" name="previous_school" class="form-control" placeholder="Previous school attended">
                            </div>
                        </div>
                    </div>

                    <!-- Personal Information -->
                    <div class="form-section">
                        <h3><i class="fas fa-user"></i> Personal Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="required">Full Name</label>
                                <input type="text" name="full_name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label class="required">Gender</label>
                                <select name="gender" class="form-control" required>
                                    <option value="">Select Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="required">Date of Birth</label>
                                <input type="date" name="date_of_birth" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Place of Birth</label>
                                <input type="text" name="place_of_birth" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Nationality</label>
                                <input type="text" name="nationality" class="form-control" value="Kenyan">
                            </div>
                            <div class="form-group">
                                <label>Religion</label>
                                <input type="text" name="religion" class="form-control">
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information -->
                    <div class="form-section">
                        <h3><i class="fas fa-address-book"></i> Contact Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="tel" name="phone" class="form-control" placeholder="e.g., 2547XXXXXXXX">
                            </div>
                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" name="email" class="form-control" placeholder="student@example.com">
                            </div>
                            <div class="form-group">
                                <label>Address</label>
                                <input type="text" name="address" class="form-control" placeholder="Street address">
                            </div>
                            <div class="form-group">
                                <label>City</label>
                                <input type="text" name="city" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Postal Code</label>
                                <input type="text" name="postal_code" class="form-control">
                            </div>
                        </div>
                    </div>

                    <!-- Parent/Guardian Information -->
                    <div class="form-section">
                        <h3><i class="fas fa-users"></i> Parent/Guardian Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="required">Full Name</label>
                                <input type="text" name="parent_name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label class="required">Relationship</label>
                                <select name="parent_relationship" class="form-control" required>
                                    <option value="">Select Relationship</option>
                                    <option value="Father">Father</option>
                                    <option value="Mother">Mother</option>
                                    <option value="Guardian">Guardian</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="required">Phone Number</label>
                                <input type="tel" name="parent_phone" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" name="parent_email" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Occupation</label>
                                <input type="text" name="parent_occupation" class="form-control">
                            </div>
                            <div class="form-group full-width">
                                <label>Address (if different)</label>
                                <input type="text" name="parent_address" class="form-control" placeholder="Parent's address">
                            </div>
                        </div>
                    </div>

                    <!-- Emergency Contact -->
                    <div class="form-section">
                        <h3><i class="fas fa-phone-alt"></i> Emergency Contact</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Contact Name</label>
                                <input type="text" name="emergency_name" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Relationship</label>
                                <input type="text" name="emergency_relationship" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="tel" name="emergency_phone" class="form-control">
                            </div>
                        </div>
                    </div>

                    <!-- Medical Information -->
                    <div class="form-section">
                        <h3><i class="fas fa-notes-medical"></i> Medical Information</h3>
                        <div class="form-group full-width">
                            <label>Medical Notes / Allergies</label>
                            <textarea name="medical_notes" class="form-control" rows="3" placeholder="Any medical conditions, allergies, or special needs..."></textarea>
                        </div>
                    </div>

                    <input type="hidden" name="add_student" value="1">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('studentRegistrationModal')">Cancel</button>
                <button type="button" class="btn btn-success" onclick="submitStudentRegistration()">
                    <i class="fas fa-save"></i> Register Student
                </button>
            </div>
        </div>
    </div>

    <!-- Student View Modal -->
    <div id="studentViewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-graduate" style="color: var(--secondary);"></i> Student Details</h2>
                <button class="modal-close" onclick="closeModal('studentViewModal')">&times;</button>
            </div>
            <div class="modal-body" id="studentViewContent">
                <div style="text-align: center; padding: 2rem;">
                    <div class="loading-spinner"></div>
                    <p style="margin-top: 1rem; color: var(--gray);">Loading student details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('studentViewModal')">Close</button>
                <button type="button" class="btn btn-warning" onclick="editFromView()" id="editFromViewBtn" style="background: var(--warning); color: white;">Edit Student</button>
            </div>
        </div>
    </div>

    <!-- Student Edit Modal -->
    <div id="studentEditModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-edit" style="color: var(--warning);"></i> Edit Student</h2>
                <button class="modal-close" onclick="closeModal('studentEditModal')">&times;</button>
            </div>
            <div class="modal-body" id="studentEditContent">
                <div style="text-align: center; padding: 2rem;">
                    <div class="loading-spinner"></div>
                    <p style="margin-top: 1rem; color: var(--gray);">Loading student data...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('studentEditModal')">Cancel</button>
                <button type="button" class="btn btn-success" onclick="submitStudentEdit()">
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
        }

        // Student Registration
        function openStudentRegistration() {
            openModal('studentRegistrationModal');
        }

        function closeStudentRegistration() {
            closeModal('studentRegistrationModal');
            document.getElementById('studentRegistrationForm').reset();
            document.getElementById('profilePreview').style.backgroundImage = '';
            document.getElementById('profilePreview').innerHTML = '<i class="fas fa-user"></i>';
            document.getElementById('fileName').textContent = 'No file chosen';
        }

        // Generate Admission Number
        function generateAdmissionNumber() {
            const prefix = document.getElementById('admission_prefix').value.trim();
            
            if (!prefix) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Prefix Required',
                    text: 'Please enter an admission prefix first'
                });
                return;
            }

            Swal.fire({
                title: 'Generating...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            fetch('students.php?ajax=generate_admission&prefix=' + encodeURIComponent(prefix))
                .then(response => response.json())
                .then(data => {
                    Swal.close();
                    if (data.success) {
                        document.getElementById('admission_number').value = data.admission_number;
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.error
                        });
                    }
                })
                .catch(error => {
                    Swal.close();
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to generate admission number'
                    });
                });
        }

        // Preview Profile Image
        function previewProfileImage(input) {
            const preview = document.getElementById('profilePreview');
            const fileName = document.getElementById('fileName');
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                fileName.textContent = file.name;

                // Validate file type
                const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!validTypes.includes(file.type)) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Invalid File',
                        text: 'Please select a valid image file (JPG, PNG, GIF)'
                    });
                    input.value = '';
                    fileName.textContent = 'No file chosen';
                    return;
                }

                // Validate file size (2MB)
                if (file.size > 2097152) {
                    Swal.fire({
                        icon: 'error',
                        title: 'File Too Large',
                        text: 'File size must be less than 2MB'
                    });
                    input.value = '';
                    fileName.textContent = 'No file chosen';
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.style.backgroundImage = `url('${e.target.result}')`;
                    preview.style.backgroundSize = 'cover';
                    preview.style.backgroundPosition = 'center';
                    preview.innerHTML = '';
                }
                reader.readAsDataURL(file);
            } else {
                fileName.textContent = 'No file chosen';
                preview.style.backgroundImage = '';
                preview.innerHTML = '<i class="fas fa-user"></i>';
            }
        }

        // Submit Student Registration
        function submitStudentRegistration() {
            const form = document.getElementById('studentRegistrationForm');
            
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

            // Validate admission number was generated
            if (!document.getElementById('admission_number').value) {
                Swal.fire({
                    icon: 'error',
                    title: 'Admission Number Required',
                    text: 'Please generate an admission number first'
                });
                return;
            }

            Swal.fire({
                title: 'Registering Student...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            form.submit();
        }

        // Submit Student Edit
        function submitStudentEdit() {
            const form = document.querySelector('#studentEditContent form');
            
            if (!form) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Form not found. Please reload the page.'
                });
                return;
            }

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
                title: 'Saving Changes...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            form.submit();
        }

        // View Student
        function viewStudent(studentId) {
            openModal('studentViewModal');
            
            fetch(`students.php?ajax=get_student&id=${studentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayStudentView(data.student);
                        document.getElementById('editFromViewBtn').onclick = function() {
                            closeModal('studentViewModal');
                            editStudent(studentId);
                        };
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.error
                        });
                        closeModal('studentViewModal');
                    }
                })
                .catch(error => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to load student details'
                    });
                    closeModal('studentViewModal');
                });
        }

        // Display Student View
        function displayStudentView(student) {
            const profilePic = student.profile_picture ? `../uploads/students/${student.profile_picture}` : null;
            const initials = getInitials(student.full_name);
            
            let html = `
                <div class="profile-image-large" style="${profilePic ? `background-image: url('${profilePic}');` : ''}">
                    ${!profilePic ? initials : ''}
                </div>
                
                <div class="view-section">
                    <h3><i class="fas fa-id-card"></i> Admission Information</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Admission Number</div>
                            <div class="info-value">${student.Admission_number}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Admission Date</div>
                            <div class="info-value">${formatDate(student.admission_date)}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Class</div>
                            <div class="info-value">${student.class_name || 'Not assigned'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Status</div>
                            <div class="info-value"><span class="status-badge status-${student.status}">${capitalize(student.status)}</span></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Previous School</div>
                            <div class="info-value">${student.previous_school || 'N/A'}</div>
                        </div>
                    </div>
                </div>

                <div class="view-section">
                    <h3><i class="fas fa-user"></i> Personal Information</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Full Name</div>
                            <div class="info-value">${student.full_name}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Gender</div>
                            <div class="info-value">${student.gender}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Date of Birth</div>
                            <div class="info-value">${formatDate(student.date_of_birth)}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Place of Birth</div>
                            <div class="info-value">${student.place_of_birth || 'N/A'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Nationality</div>
                            <div class="info-value">${student.nationality || 'N/A'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Religion</div>
                            <div class="info-value">${student.religion || 'N/A'}</div>
                        </div>
                    </div>
                </div>

                <div class="view-section">
                    <h3><i class="fas fa-address-book"></i> Contact Information</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Phone</div>
                            <div class="info-value">${student.phone || 'N/A'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Email</div>
                            <div class="info-value">${student.email || 'N/A'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Address</div>
                            <div class="info-value">${student.address || 'N/A'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">City</div>
                            <div class="info-value">${student.city || 'N/A'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Postal Code</div>
                            <div class="info-value">${student.postal_code || 'N/A'}</div>
                        </div>
                    </div>
                </div>

                <div class="view-section">
                    <h3><i class="fas fa-users"></i> Parent/Guardian Information</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Name</div>
                            <div class="info-value">${student.parent_name || 'N/A'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Relationship</div>
                            <div class="info-value">${student.parent_relationship || 'N/A'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Phone</div>
                            <div class="info-value">${student.parent_phone || 'N/A'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Email</div>
                            <div class="info-value">${student.parent_email || 'N/A'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Occupation</div>
                            <div class="info-value">${student.parent_occupation || 'N/A'}</div>
                        </div>
                        <div class="info-item full-width">
                            <div class="info-label">Address</div>
                            <div class="info-value">${student.parent_address || 'Same as student address'}</div>
                        </div>
                    </div>
                </div>

                <div class="view-section">
                    <h3><i class="fas fa-phone-alt"></i> Emergency Contact</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Name</div>
                            <div class="info-value">${student.emergency_contact_name || 'N/A'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Relationship</div>
                            <div class="info-value">${student.emergency_contact_relationship || 'N/A'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Phone</div>
                            <div class="info-value">${student.emergency_contact_phone || 'N/A'}</div>
                        </div>
                    </div>
                </div>

                <div class="view-section">
                    <h3><i class="fas fa-notes-medical"></i> Medical Information</h3>
                    <div class="info-item">
                        <div class="info-value">${student.medical_notes || 'No medical notes recorded'}</div>
                    </div>
                </div>
            `;
            
            document.getElementById('studentViewContent').innerHTML = html;
        }

        // Edit Student
        function editStudent(studentId) {
            openModal('studentEditModal');
            
            fetch(`students.php?ajax=get_student&id=${studentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayStudentEditForm(data.student);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.error
                        });
                        closeModal('studentEditModal');
                    }
                })
                .catch(error => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to load student data'
                    });
                    closeModal('studentEditModal');
                });
        }

        // Display Student Edit Form
        function displayStudentEditForm(student) {
            const profilePic = student.profile_picture ? `../uploads/students/${student.profile_picture}` : null;
            
            let html = `
                <form method="POST" enctype="multipart/form-data" id="studentEditForm">
                    <!-- Profile Picture Upload -->
                    <div class="form-section">
                        <h3><i class="fas fa-camera"></i> Profile Picture</h3>
                        <div class="profile-upload">
                            <div class="profile-preview" id="editProfilePreview" style="${profilePic ? `background-image: url('${profilePic}'); background-size: cover; background-position: center;` : ''}">
                                ${!profilePic ? '<i class="fas fa-user"></i>' : ''}
                            </div>
                            <div class="upload-controls">
                                <input type="file" id="edit_profile_picture" name="profile_picture" class="file-input" accept="image/*" onchange="previewEditProfileImage(this)">
                                <label for="edit_profile_picture" class="file-label">
                                    <i class="fas fa-upload"></i> Change Image
                                </label>
                                <div class="file-name" id="editFileName">No file chosen</div>
                                <button type="button" class="btn btn-sm btn-outline" onclick="removeProfilePicture(${student.id})" style="margin-top: 0.5rem;">
                                    <i class="fas fa-trash"></i> Remove Current Picture
                                </button>
                                <small style="color: var(--gray); display: block; margin-top: 0.5rem;">Max size: 2MB. Allowed: JPG, PNG, GIF</small>
                            </div>
                        </div>
                    </div>

                    <!-- Admission Information -->
                    <div class="form-section">
                        <h3><i class="fas fa-id-card"></i> Admission Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="required">Admission Number</label>
                                <input type="text" name="admission_number" class="form-control" value="${student.Admission_number}" required>
                            </div>
                            <div class="form-group">
                                <label class="required">Admission Date</label>
                                <input type="date" name="admission_date" class="form-control" value="${student.admission_date}" required>
                            </div>
                            <div class="form-group">
                                <label class="required">Class</label>
                                <select name="class_id" class="form-control" required>
                                    <option value="">Select Class</option>
                                    ${getClassOptions(student.class_id)}
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Previous School</label>
                                <input type="text" name="previous_school" class="form-control" value="${student.previous_school || ''}">
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" class="form-control">
                                    <option value="active" ${student.status === 'active' ? 'selected' : ''}>Active</option>
                                    <option value="graduated" ${student.status === 'graduated' ? 'selected' : ''}>Graduated</option>
                                    <option value="transferred" ${student.status === 'transferred' ? 'selected' : ''}>Transferred</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Personal Information -->
                    <div class="form-section">
                        <h3><i class="fas fa-user"></i> Personal Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="required">Full Name</label>
                                <input type="text" name="full_name" class="form-control" value="${student.full_name}" required>
                            </div>
                            <div class="form-group">
                                <label class="required">Gender</label>
                                <select name="gender" class="form-control" required>
                                    <option value="">Select Gender</option>
                                    <option value="Male" ${student.gender === 'Male' ? 'selected' : ''}>Male</option>
                                    <option value="Female" ${student.gender === 'Female' ? 'selected' : ''}>Female</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="required">Date of Birth</label>
                                <input type="date" name="date_of_birth" class="form-control" value="${student.date_of_birth}" required>
                            </div>
                            <div class="form-group">
                                <label>Place of Birth</label>
                                <input type="text" name="place_of_birth" class="form-control" value="${student.place_of_birth || ''}">
                            </div>
                            <div class="form-group">
                                <label>Nationality</label>
                                <input type="text" name="nationality" class="form-control" value="${student.nationality || 'Kenyan'}">
                            </div>
                            <div class="form-group">
                                <label>Religion</label>
                                <input type="text" name="religion" class="form-control" value="${student.religion || ''}">
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information -->
                    <div class="form-section">
                        <h3><i class="fas fa-address-book"></i> Contact Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="tel" name="phone" class="form-control" value="${student.phone || ''}">
                            </div>
                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" name="email" class="form-control" value="${student.email || ''}">
                            </div>
                            <div class="form-group">
                                <label>Address</label>
                                <input type="text" name="address" class="form-control" value="${student.address || ''}">
                            </div>
                            <div class="form-group">
                                <label>City</label>
                                <input type="text" name="city" class="form-control" value="${student.city || ''}">
                            </div>
                            <div class="form-group">
                                <label>Postal Code</label>
                                <input type="text" name="postal_code" class="form-control" value="${student.postal_code || ''}">
                            </div>
                        </div>
                    </div>

                    <!-- Parent/Guardian Information -->
                    <div class="form-section">
                        <h3><i class="fas fa-users"></i> Parent/Guardian Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="required">Full Name</label>
                                <input type="text" name="parent_name" class="form-control" value="${student.parent_name || ''}" required>
                            </div>
                            <div class="form-group">
                                <label class="required">Relationship</label>
                                <select name="parent_relationship" class="form-control" required>
                                    <option value="">Select Relationship</option>
                                    <option value="Father" ${student.parent_relationship === 'Father' ? 'selected' : ''}>Father</option>
                                    <option value="Mother" ${student.parent_relationship === 'Mother' ? 'selected' : ''}>Mother</option>
                                    <option value="Guardian" ${student.parent_relationship === 'Guardian' ? 'selected' : ''}>Guardian</option>
                                    <option value="Other" ${student.parent_relationship === 'Other' ? 'selected' : ''}>Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="required">Phone Number</label>
                                <input type="tel" name="parent_phone" class="form-control" value="${student.parent_phone || ''}" required>
                            </div>
                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" name="parent_email" class="form-control" value="${student.parent_email || ''}">
                            </div>
                            <div class="form-group">
                                <label>Occupation</label>
                                <input type="text" name="parent_occupation" class="form-control" value="${student.parent_occupation || ''}">
                            </div>
                            <div class="form-group full-width">
                                <label>Address (if different)</label>
                                <input type="text" name="parent_address" class="form-control" value="${student.parent_address || ''}">
                            </div>
                        </div>
                    </div>

                    <!-- Emergency Contact -->
                    <div class="form-section">
                        <h3><i class="fas fa-phone-alt"></i> Emergency Contact</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Contact Name</label>
                                <input type="text" name="emergency_name" class="form-control" value="${student.emergency_contact_name || ''}">
                            </div>
                            <div class="form-group">
                                <label>Relationship</label>
                                <input type="text" name="emergency_relationship" class="form-control" value="${student.emergency_contact_relationship || ''}">
                            </div>
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="tel" name="emergency_phone" class="form-control" value="${student.emergency_contact_phone || ''}">
                            </div>
                        </div>
                    </div>

                    <!-- Medical Information -->
                    <div class="form-section">
                        <h3><i class="fas fa-notes-medical"></i> Medical Information</h3>
                        <div class="form-group full-width">
                            <label>Medical Notes / Allergies</label>
                            <textarea name="medical_notes" class="form-control" rows="3">${student.medical_notes || ''}</textarea>
                        </div>
                    </div>

                    <input type="hidden" name="student_db_id" value="${student.id}">
                    <input type="hidden" name="parent_id" value="${student.parent_id || ''}">
                    <input type="hidden" name="edit_student" value="1">
                </form>
            `;
            
            document.getElementById('studentEditContent').innerHTML = html;
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
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' });
        }

        function capitalize(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        }

        function getClassOptions(selectedClassId) {
            const classes = <?php echo json_encode($classes); ?>;
            let options = '';
            classes.forEach(c => {
                options += `<option value="${c.id}" ${c.id == selectedClassId ? 'selected' : ''}>${c.class_name}</option>`;
            });
            return options;
        }

        // Preview Edit Profile Image
        function previewEditProfileImage(input) {
            const preview = document.getElementById('editProfilePreview');
            const fileName = document.getElementById('editFileName');
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                fileName.textContent = file.name;

                // Validate file type
                const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!validTypes.includes(file.type)) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Invalid File',
                        text: 'Please select a valid image file (JPG, PNG, GIF)'
                    });
                    input.value = '';
                    fileName.textContent = 'No file chosen';
                    return;
                }

                // Validate file size (2MB)
                if (file.size > 2097152) {
                    Swal.fire({
                        icon: 'error',
                        title: 'File Too Large',
                        text: 'File size must be less than 2MB'
                    });
                    input.value = '';
                    fileName.textContent = 'No file chosen';
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.style.backgroundImage = `url('${e.target.result}')`;
                    preview.style.backgroundSize = 'cover';
                    preview.style.backgroundPosition = 'center';
                    preview.innerHTML = '';
                }
                reader.readAsDataURL(file);
            } else {
                fileName.textContent = 'No file chosen';
            }
        }

        // Remove Profile Picture
        function removeProfilePicture(studentId) {
            Swal.fire({
                title: 'Remove Profile Picture?',
                text: 'This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#e74c3c',
                cancelButtonColor: '#7f8c8d',
                confirmButtonText: 'Yes, remove it'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="student_db_id" value="${studentId}">
                        <input type="hidden" name="remove_profile_picture" value="1">
                        <input type="hidden" name="edit_student" value="1">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Submit Edit Form
        function submitStudentEdit() {
            const form = document.getElementById('studentEditForm');
            
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
                title: 'Updating Student...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            form.submit();
        }

        // Edit from View
        function editFromView() {
            // This function is dynamically set in viewStudent
        }

        // Delete Student
        function deleteStudent(studentId) {
            Swal.fire({
                title: 'Delete Student?',
                text: 'This action cannot be undone. All student data will be permanently removed.',
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
                        <input type="hidden" name="student_db_id" value="${studentId}">
                        <input type="hidden" name="delete_student" value="1">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Filter Functions
        function applyFilters() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const classFilter = document.getElementById('classFilter').value;
            const statusFilter = document.getElementById('statusFilter').value;
            
            const rows = document.querySelectorAll('#studentsTable tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const rowClass = row.getAttribute('data-class');
                const rowStatus = row.getAttribute('data-status');
                
                let show = true;
                
                if (searchTerm && !text.includes(searchTerm)) show = false;
                if (classFilter && rowClass !== classFilter) show = false;
                if (statusFilter && rowStatus !== statusFilter) show = false;
                
                row.style.display = show ? '' : 'none';
            });
        }

        function resetFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('classFilter').value = '';
            document.getElementById('statusFilter').value = '';
            
            document.querySelectorAll('#studentsTable tbody tr').forEach(row => {
                row.style.display = '';
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