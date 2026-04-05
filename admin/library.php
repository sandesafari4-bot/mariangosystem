<?php
include '../config.php';
checkAuth();
checkRole(['admin', 'librarian', 'teacher']);

// Initialize variables with defaults
$total_books = 0;
$total_copies = 0;
$available_copies = 0;
$issued_copies = 0;
$total_categories = 0;
$total_locations = 0;
$books = [];
$categories = [];
$locations = [];
$active_issues = [];
$overdue_books = [];
$recent_transactions = [];
$selected_category = $_GET['category'] ?? '';
$selected_status = $_GET['status'] ?? '';
$search_query = $_GET['search'] ?? '';

try {
    // AJAX endpoints
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'details' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $pdo->prepare("SELECT * FROM books WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $book = $stmt->fetch(PDO::FETCH_ASSOC);
        header('Content-Type: application/json; charset=utf-8');
        if ($book) {
            echo json_encode(['success' => true, 'book' => $book]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Book not found']);
        }
        exit();
    }

    // AJAX: Save book
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_book') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $author = trim($_POST['author'] ?? '');
        $isbn = trim($_POST['isbn'] ?? '');
        $publisher = trim($_POST['publisher'] ?? '');
        $publication_year = (int)($_POST['publication_year'] ?? 0) ?: null;
        $category = trim($_POST['category'] ?? '');
        $total_copies = max(0, (int)($_POST['total_copies'] ?? 0));
        $available_copies = max(0, (int)($_POST['available_copies'] ?? 0));
        $description = trim($_POST['description'] ?? '');
        $location = trim($_POST['location'] ?? '');

        header('Content-Type: application/json; charset=utf-8');
        
        if ($id <= 0 || $title === '' || $author === '') {
            echo json_encode(['success' => false, 'error' => 'Invalid input: Title and Author are required']);
            exit();
        }

        try {
            $update = $pdo->prepare("UPDATE books SET title = ?, author = ?, isbn = ?, publisher = ?, publication_year = ?, category = ?, total_copies = ?, available_copies = ?, description = ?, location = ? WHERE id = ?");
            $ok = $update->execute([$title, $author, $isbn, $publisher, $publication_year, $category, $total_copies, $available_copies, $description, $location, $id]);
            
            if ($ok) {
                echo json_encode(['success' => true, 'message' => 'Book updated successfully']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update book']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    // AJAX: Delete category
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_category') {
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            $category_id = (int)$_POST['category_id'];
            
            // Check if category is in use
            $check = $pdo->prepare("SELECT COUNT(*) FROM books WHERE category = (SELECT name FROM book_categories WHERE id = ?)");
            $check->execute([$category_id]);
            $in_use = $check->fetchColumn();
            
            if ($in_use > 0) {
                echo json_encode(['success' => false, 'error' => 'Cannot delete category: It is being used by books!']);
                exit();
            }
            
            $stmt = $pdo->prepare("DELETE FROM book_categories WHERE id = ?");
            if ($stmt->execute([$category_id])) {
                echo json_encode(['success' => true, 'message' => 'Category deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to delete category']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    // AJAX: Delete location
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_location') {
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            $location_id = (int)$_POST['location_id'];
            
            // Check if location is in use
            $check = $pdo->prepare("SELECT COUNT(*) FROM books WHERE location = (SELECT name FROM book_locations WHERE id = ?)");
            $check->execute([$location_id]);
            $in_use = $check->fetchColumn();
            
            if ($in_use > 0) {
                echo json_encode(['success' => false, 'error' => 'Cannot delete location: It is being used by books!']);
                exit();
            }
            
            $stmt = $pdo->prepare("DELETE FROM book_locations WHERE id = ?");
            if ($stmt->execute([$location_id])) {
                echo json_encode(['success' => true, 'message' => 'Location deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to delete location']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    // AJAX: Add category
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'add_category') {
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            $category_name = trim($_POST['category_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            
            if (empty($category_name)) {
                echo json_encode(['success' => false, 'error' => 'Category name is required']);
                exit();
            }
            
            $check = $pdo->prepare("SELECT id FROM book_categories WHERE name = ?");
            $check->execute([$category_name]);
            
            if ($check->rowCount() > 0) {
                echo json_encode(['success' => false, 'error' => 'Category already exists!']);
                exit();
            }
            
            $stmt = $pdo->prepare("INSERT INTO book_categories (name, description) VALUES (?, ?)");
            if ($stmt->execute([$category_name, $description])) {
                echo json_encode(['success' => true, 'message' => 'Category added successfully']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to add category']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    // AJAX: Add location
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'add_location') {
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            $location_name = trim($_POST['location_name'] ?? '');
            $shelf = trim($_POST['shelf'] ?? '');
            $row = trim($_POST['row'] ?? '');
            $description = trim($_POST['description'] ?? '');
            
            if (empty($location_name) || empty($shelf) || empty($row)) {
                echo json_encode(['success' => false, 'error' => 'Location name, shelf, and row are required']);
                exit();
            }
            
            $check = $pdo->prepare("SELECT id FROM book_locations WHERE name = ?");
            $check->execute([$location_name]);
            
            if ($check->rowCount() > 0) {
                echo json_encode(['success' => false, 'error' => 'Location already exists!']);
                exit();
            }
            
            $stmt = $pdo->prepare("INSERT INTO book_locations (name, shelf, row, description) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$location_name, $shelf, $row, $description])) {
                echo json_encode(['success' => true, 'message' => 'Location added successfully']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to add location']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        if (isset($_POST['add_book'])) {
            $title = $_POST['title'];
            $author = $_POST['author'];
            $isbn = $_POST['isbn'] ?? '';
            $publisher = $_POST['publisher'] ?? '';
            $publication_year = $_POST['publication_year'] ?? null;
            $category = $_POST['category'];
            $copies = max(1, (int)$_POST['copies']);
            $description = $_POST['description'] ?? '';
            $location = $_POST['location'];
            
            $stmt = $pdo->prepare("INSERT INTO books (title, author, isbn, publisher, publication_year, category, total_copies, available_copies, description, location) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$title, $author, $isbn, $publisher, $publication_year, $category, $copies, $copies, $description, $location])) {
                $success = "Book added successfully!";
            } else {
                $error = "Failed to add book. Please try again.";
            }
        }
        
        if (isset($_POST['issue_book'])) {
            $book_id = $_POST['book_id'];
            $student_id = $_POST['student_id'];
            $issue_date = $_POST['issue_date'];
            $due_date = $_POST['due_date'];
            $issued_by = $_SESSION['user_id'];
            
            $book_check = $pdo->prepare("SELECT available_copies FROM books WHERE id = ?");
            $book_check->execute([$book_id]);
            $book = $book_check->fetch();
            
            if ($book && $book['available_copies'] > 0) {
                $existing_issue = $pdo->prepare("SELECT id FROM book_issues WHERE book_id = ? AND student_id = ? AND status = 'Issued'");
                $existing_issue->execute([$book_id, $student_id]);
                
                if ($existing_issue->rowCount() == 0) {
                    $stmt = $pdo->prepare("INSERT INTO book_issues (book_id, student_id, issue_date, due_date, issued_by) VALUES (?, ?, ?, ?, ?)");
                    if ($stmt->execute([$book_id, $student_id, $issue_date, $due_date, $issued_by])) {
                        $update_copies = $pdo->prepare("UPDATE books SET available_copies = available_copies - 1 WHERE id = ?");
                        $update_copies->execute([$book_id]);
                        $success = "Book issued successfully!";
                    } else {
                        $error = "Failed to issue book. Please try again.";
                    }
                } else {
                    $error = "This student already has this book issued.";
                }
            } else {
                $error = "This book is currently not available.";
            }
        }
        
        if (isset($_POST['return_book'])) {
            $issue_id = $_POST['issue_id'];
            $return_date = $_POST['return_date'];
            $condition = $_POST['condition'];
            $notes = $_POST['notes'] ?? '';
            
            $issue_stmt = $pdo->prepare("SELECT book_id FROM book_issues WHERE id = ?");
            $issue_stmt->execute([$issue_id]);
            $issue = $issue_stmt->fetch();
            
            if ($issue) {
                $stmt = $pdo->prepare("UPDATE book_issues SET return_date = ?, condition_returned = ?, notes = ?, status = 'Returned' WHERE id = ?");
                if ($stmt->execute([$return_date, $condition, $notes, $issue_id])) {
                    $update_copies = $pdo->prepare("UPDATE books SET available_copies = available_copies + 1 WHERE id = ?");
                    $update_copies->execute([$issue['book_id']]);
                    $success = "Book returned successfully!";
                } else {
                    $error = "Failed to return book. Please try again.";
                }
            }
        }
        
        // Refresh data after form submission
        header("Location: library.php?" . (isset($success) ? "success=" . urlencode($success) : "error=" . urlencode($error)));
        exit();
    }

    // Build query for books
    $query = "
        SELECT b.*, 
               (SELECT COUNT(*) FROM book_issues WHERE book_id = b.id AND status = 'Issued') as currently_issued
        FROM books b
        WHERE 1=1
    ";

    $params = [];

    if ($selected_category) {
        $query .= " AND b.category = ?";
        $params[] = $selected_category;
    }

    if ($selected_status) {
        if ($selected_status == 'available') {
            $query .= " AND b.available_copies > 0";
        } elseif ($selected_status == 'unavailable') {
            $query .= " AND b.available_copies = 0";
        }
    }

    if ($search_query) {
        $query .= " AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ?)";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
    }

    $query .= " ORDER BY b.title";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $books = $stmt->fetchAll();

    // Get categories
    $categories = $pdo->query("SELECT * FROM book_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    // Get locations
    $locations = $pdo->query("SELECT * FROM book_locations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    // Get active book issues
    $active_issues = $pdo->query("
        SELECT bi.*, b.title, b.author, b.isbn, s.full_name, s.admission_number, c.class_name,
               u.full_name as issued_by_name,
               DATEDIFF(bi.due_date, CURDATE()) as days_remaining
        FROM book_issues bi
        JOIN books b ON bi.book_id = b.id
        JOIN students s ON bi.student_id = s.id
        JOIN classes c ON s.class_id = c.id
        JOIN users u ON bi.issued_by = u.id
        WHERE bi.return_date IS NULL
        ORDER BY bi.due_date ASC
    ")->fetchAll();

    // Get overdue books
    $overdue_books = $pdo->query("
        SELECT bi.*, b.title, b.author, s.full_name, s.admission_number, c.class_name,
               DATEDIFF(CURDATE(), bi.due_date) as days_overdue
        FROM book_issues bi
        JOIN books b ON bi.book_id = b.id
        JOIN students s ON bi.student_id = s.id
        JOIN classes c ON s.class_id = c.id
        WHERE bi.return_date IS NULL AND bi.due_date < CURDATE()
        ORDER BY bi.due_date ASC
    ")->fetchAll();

    // Overdue view
    if (isset($_GET['view']) && $_GET['view'] === 'overdue') {
        header('Content-Type: text/html; charset=utf-8');
        echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Overdue Books Report</title>";
        echo "<style>body{font-family:Arial,Helvetica,sans-serif;font-size:13px;}table{width:100%;border-collapse:collapse;}th,td{padding:6px;border:1px solid #ccc;text-align:left}th{background:#f8f9fa}</style>";
        echo "</head><body>";
        echo "<h2>Overdue Books Report</h2>";
        echo "<p>Generated on: " . date('F j, Y H:i:s') . "</p>";
        if (empty($overdue_books)) {
            echo "<p>No overdue books found.</p>";
        } else {
            echo "<table><thead><tr><th>#</th><th>Book</th><th>Author</th><th>Student</th><th>Student ID</th><th>Class</th><th>Days Overdue</th><th>Due Date</th></tr></thead><tbody>";
            $i = 1;
            foreach ($overdue_books as $ob) {
                echo '<tr>';
                echo '<td>' . $i . '</td>';
                echo '<td>' . htmlspecialchars($ob['title']) . '</td>';
                echo '<td>' . htmlspecialchars($ob['author']) . '</td>';
                echo '<td>' . htmlspecialchars($ob['full_name']) . '</td>';
                echo '<td>' . htmlspecialchars($ob['admission_number']) . '</td>';
                echo '<td>' . htmlspecialchars($ob['class_name']) . '</td>';
                echo '<td style="color:#e74c3c;font-weight:bold;">' . $ob['days_overdue'] . '</td>';
                echo '<td>' . date('M j, Y', strtotime($ob['due_date'])) . '</td>';
                echo '</tr>';
                $i++;
            }
            echo "</tbody></table>";
        }
        echo "<script>window.print();</script></body></html>";
        exit();
    }

    // Printable report
    if (isset($_GET['report']) && $_GET['report'] == '1') {
        $rStmt = $pdo->prepare($query);
        $rStmt->execute($params);
        $rows = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/html; charset=utf-8');
        echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Library Collection Report</title>";
        echo "<style>body{font-family:Arial,Helvetica,sans-serif;font-size:13px;}table{width:100%;border-collapse:collapse;}th,td{padding:6px;border:1px solid #ccc;text-align:left}th{background:#f8f9fa}</style>";
        echo "</head><body>";
        echo "<h2>Library Collection Report</h2>";
        echo "<p>Generated on: " . date('F j, Y H:i:s') . "</p>";
        echo "<table><thead><tr><th>#</th><th>Title</th><th>Author</th><th>ISBN</th><th>Category</th><th>Location</th><th>Total Copies</th><th>Available</th></tr></thead><tbody>";
        $i = 1;
        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td>' . $i . '</td>';
            echo '<td>' . htmlspecialchars($r['title']) . '</td>';
            echo '<td>' . htmlspecialchars($r['author']) . '</td>';
            echo '<td>' . htmlspecialchars($r['isbn']) . '</td>';
            echo '<td>' . htmlspecialchars($r['category']) . '</td>';
            echo '<td>' . htmlspecialchars($r['location']) . '</td>';
            echo '<td>' . $r['total_copies'] . '</td>';
            echo '<td>' . $r['available_copies'] . '</td>';
            echo '</tr>';
            $i++;
        }
        echo "</tbody></table><script>window.print();</script></body></html>";
        exit();
    }

    // Get statistics
    $total_books = $pdo->query("SELECT COUNT(*) FROM books")->fetchColumn() ?: 0;
    $total_copies = $pdo->query("SELECT COALESCE(SUM(total_copies), 0) FROM books")->fetchColumn() ?: 0;
    $available_copies = $pdo->query("SELECT COALESCE(SUM(available_copies), 0) FROM books")->fetchColumn() ?: 0;
    $issued_copies = $total_copies - $available_copies;
    $total_categories = $pdo->query("SELECT COUNT(*) FROM book_categories")->fetchColumn() ?: 0;
    $total_locations = $pdo->query("SELECT COUNT(*) FROM book_locations")->fetchColumn() ?: 0;

    // Get recent transactions
    $recent_transactions = $pdo->query("
        SELECT bi.*, b.title, s.full_name, 
               CASE 
                   WHEN bi.return_date IS NOT NULL THEN 'Returned'
                   ELSE 'Issued'
               END as transaction_type
        FROM book_issues bi
        JOIN books b ON bi.book_id = b.id
        JOIN students s ON bi.student_id = s.id
        ORDER BY bi.issue_date DESC
        LIMIT 5
    ")->fetchAll();

} catch (Exception $e) {
    error_log("Library Error: " . $e->getMessage());
    $error = "An error occurred while loading data.";
}

$page_title = "Library Management - " . SCHOOL_NAME;
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
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --primary-light: #4895ef;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --success-dark: #3aa8d8;
            --info: #4895ef;
            --warning: #f8961e;
            --warning-dark: #e07c1a;
            --danger: #f94144;
            --danger-dark: #d93235;
            --purple: #7209b7;
            --purple-light: #9b59b6;
            --dark: #2b2d42;
            --dark-light: #34495e;
            --gray: #6c757d;
            --gray-light: #95a5a6;
            --light: #f8f9fa;
            --white: #ffffff;
            --gradient-1: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-2: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --gradient-3: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --gradient-4: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            --gradient-5: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 24px rgba(0,0,0,0.12);
            --shadow-xl: 0 20px 40px rgba(0,0,0,0.15);
            --border-radius-sm: 8px;
            --border-radius-md: 12px;
            --border-radius-lg: 16px;
            --border-radius-xl: 24px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* SweetAlert2 Custom Styles - Higher z-index to appear above modals */
        .swal2-container {
            z-index: 2000 !important;
        }

        .swal2-popup {
            font-family: 'Inter', sans-serif !important;
            border-radius: var(--border-radius-lg) !important;
            padding: 1.5rem !important;
        }

        .swal2-title {
            color: var(--dark) !important;
            font-weight: 600 !important;
        }

        .swal2-html-container {
            color: var(--gray) !important;
        }

        .swal2-confirm {
            background: var(--gradient-1) !important;
            border-radius: 8px !important;
            font-weight: 600 !important;
            padding: 0.75rem 1.5rem !important;
        }

        .swal2-cancel {
            background: var(--light) !important;
            color: var(--dark) !important;
            border-radius: 8px !important;
            font-weight: 600 !important;
            padding: 0.75rem 1.5rem !important;
        }

        .swal2-toast {
            border-radius: var(--border-radius-md) !important;
            box-shadow: var(--shadow-lg) !important;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            min-height: calc(100vh - 70px);
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            transition: var(--transition);
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        ::-webkit-scrollbar-track {
            background: var(--light);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--gradient-1);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
        }

        /* Page Header */
        .page-header {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: var(--gradient-1);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 2rem;
        }

        .page-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            background: var(--gradient-1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: var(--gray);
            font-size: 1rem;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--gradient-1);
            color: white;
        }

        .btn-success {
            background: var(--gradient-3);
            color: white;
        }

        .btn-danger {
            background: var(--gradient-2);
            color: white;
        }

        .btn-warning {
            background: var(--gradient-5);
            color: white;
        }

        .btn-info {
            background: var(--gradient-4);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--light);
            color: var(--dark);
        }

        .btn-outline:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
        }

        .btn-lg {
            padding: 1rem 2rem;
            font-size: 1rem;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            padding: 0;
            justify-content: center;
        }

        /* Overdue Alert */
        .overdue-alert {
            background: linear-gradient(135deg, #f94144 0%, #e74c3c 100%);
            color: white;
            padding: 1rem 2rem;
            border-radius: var(--border-radius-md);
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: var(--shadow-lg);
            animation: pulse 2s infinite;
        }

        .overdue-alert a {
            color: white;
            font-weight: 600;
            text-decoration: underline;
            margin-left: auto;
        }

        .overdue-count {
            background: rgba(255,255,255,0.2);
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-weight: 700;
            margin-left: 0.5rem;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            border-left: 4px solid;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card.books { border-left-color: var(--primary); }
        .stat-card.copies { border-left-color: var(--success); }
        .stat-card.issued { border-left-color: var(--warning); }
        .stat-card.categories { border-left-color: var(--purple); }
        .stat-card.locations { border-left-color: var(--info); }

        .stat-card::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200px;
            height: 200px;
            background: linear-gradient(135deg, rgba(255,255,255,0.1), rgba(255,255,255,0.05));
            border-radius: 50%;
            pointer-events: none;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-card.books .stat-number { color: var(--primary); }
        .stat-card.copies .stat-number { color: var(--success); }
        .stat-card.issued .stat-number { color: var(--warning); }
        .stat-card.categories .stat-number { color: var(--purple); }
        .stat-card.locations .stat-number { color: var(--info); }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            position: absolute;
            bottom: 1rem;
            right: 1rem;
            font-size: 3rem;
            opacity: 0.1;
            color: var(--dark);
        }

        /* Filter Section */
        .filter-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
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

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--light);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: var(--transition);
            background: var(--white);
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        /* Tabs */
        .tabs {
            display: flex;
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .tab {
            flex: 1;
            padding: 1rem 1.5rem;
            cursor: pointer;
            font-weight: 600;
            color: var(--gray);
            transition: var(--transition);
            border-bottom: 3px solid transparent;
            text-align: center;
        }

        .tab:hover {
            color: var(--primary);
            background: var(--light);
        }

        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: rgba(67, 97, 238, 0.05);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Data Cards */
        .data-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--gradient-1);
            color: white;
        }

        .card-header h3 {
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header .badge {
            background: rgba(255,255,255,0.2);
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            padding: 1rem;
            text-align: left;
            background: var(--light);
            color: var(--dark);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid var(--light);
            color: var(--dark);
        }

        tr:hover {
            background: rgba(67, 97, 238, 0.05);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            white-space: nowrap;
        }

        .status-available {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .status-unavailable {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        .status-issued {
            background: rgba(67, 97, 238, 0.15);
            color: var(--primary);
        }

        .status-overdue {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        .status-returned {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            background: var(--light);
            color: var(--dark);
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .action-btn:hover {
            background: var(--primary);
            color: white;
        }

        .action-btn.success:hover {
            background: var(--success);
        }

        .action-btn.danger:hover {
            background: var(--danger);
        }

        .action-btn.warning:hover {
            background: var(--warning);
        }

        .action-btn.info:hover {
            background: var(--info);
        }

        /* List Items */
        .list-container {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            margin-bottom: 1.5rem;
        }

        .list-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid var(--light);
            transition: var(--transition);
        }

        .list-item:last-child {
            border-bottom: none;
        }

        .list-item:hover {
            background: var(--light);
        }

        .item-info h4 {
            color: var(--dark);
            margin-bottom: 0.3rem;
            font-size: 1rem;
        }

        .item-info p {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .item-meta {
            font-size: 0.8rem;
            color: var(--gray);
            margin-top: 0.3rem;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
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
            position: sticky;
            top: 0;
            background: var(--white);
            z-index: 1002;
        }

        .modal-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
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
            transition: var(--transition);
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
            background: var(--white);
            z-index: 1002;
        }

        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-hint {
            font-size: 0.8rem;
            color: var(--gray);
            margin-top: 0.3rem;
        }

        .required::after {
            content: " *";
            color: var(--danger);
        }

        /* Book Details */
        .book-details {
            background: var(--light);
            padding: 1.5rem;
            border-radius: var(--border-radius-md);
            margin-bottom: 1.5rem;
        }

        .book-details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .detail-item {
            margin-bottom: 0.5rem;
        }

        .detail-label {
            font-size: 0.8rem;
            color: var(--gray);
            margin-bottom: 0.2rem;
        }

        .detail-value {
            font-weight: 600;
            color: var(--dark);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .empty-state h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            margin-bottom: 1.5rem;
        }

        /* Alert */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius-md);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: slideInDown 0.3s ease;
            border-left: 4px solid;
        }

        .alert-success {
            background: rgba(76, 201, 240, 0.1);
            border-color: var(--success);
            color: var(--success);
        }

        .alert-error {
            background: rgba(249, 65, 68, 0.1);
            border-color: var(--danger);
            color: var(--danger);
        }

        /* Quick Stats */
        .quick-stats {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin: 1rem 0;
        }

        .quick-stat {
            background: var(--light);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
        }

        .quick-stat i {
            color: var(--primary);
            margin-right: 0.3rem;
        }

        /* Animations */
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate {
            animation: slideInDown 0.3s ease;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .book-details-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }

            .page-header {
                padding: 1.5rem;
            }

            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-actions {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .tabs {
                flex-direction: column;
            }

            .tab {
                border-bottom: 1px solid var(--light);
            }

            .tab.active {
                border-bottom-color: var(--primary);
            }

            .action-buttons {
                flex-wrap: wrap;
            }

            .modal-content {
                width: 95%;
                max-height: 95vh;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .card-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
        }

        /* Loading Spinner */
        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--light);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Tooltip */
        [data-tooltip] {
            position: relative;
            cursor: help;
        }

        [data-tooltip]:before {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            padding: 0.5rem 1rem;
            background: var(--dark);
            color: white;
            font-size: 0.75rem;
            border-radius: 4px;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
            z-index: 1000;
        }

        [data-tooltip]:hover:before {
            opacity: 1;
            visibility: visible;
            bottom: calc(100% + 10px);
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
            <div class="header-content">
                <div>
                    <h1>
                        <i class="fas fa-book" style="color: var(--primary);"></i>
                        Library Management System
                    </h1>
                    <p>Manage books, track issues, and organize your library collection</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="openAddBookModal()">
                        <i class="fas fa-plus"></i> Add Book
                    </button>
                    <button class="btn btn-success" onclick="openIssueBookModal()">
                        <i class="fas fa-book-open"></i> Issue Book
                    </button>
                    <button class="btn btn-outline" onclick="generateLibraryReport()">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                </div>
            </div>
        </div>

        <!-- Success/Error Messages (using SweetAlert2 instead) -->
        <?php if (isset($_GET['success'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: <?php echo json_encode($_GET['success']); ?>,
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000
                });
            });
        </script>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: <?php echo json_encode($_GET['error']); ?>,
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000
                });
            });
        </script>
        <?php endif; ?>

        <!-- Overdue Books Alert -->
        <?php if (count($overdue_books) > 0): ?>
        <div class="overdue-alert animate">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Attention:</strong> There are 
            <span class="overdue-count"><?php echo count($overdue_books); ?> overdue books</span>
            that need to be returned.
            <a href="#" onclick="viewOverdueBooks(); return false;">
                <i class="fas fa-external-link-alt"></i> View Report
            </a>
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid animate">
            <div class="stat-card books">
                <div class="stat-number"><?php echo number_format($total_books); ?></div>
                <div class="stat-label">Total Books</div>
                <div class="stat-icon"><i class="fas fa-book"></i></div>
            </div>
            <div class="stat-card copies">
                <div class="stat-number"><?php echo number_format($total_copies); ?></div>
                <div class="stat-label">Total Copies</div>
                <div class="stat-icon"><i class="fas fa-copy"></i></div>
            </div>
            <div class="stat-card issued">
                <div class="stat-number"><?php echo number_format($issued_copies); ?></div>
                <div class="stat-label">Issued Books</div>
                <div class="stat-icon"><i class="fas fa-book-open"></i></div>
            </div>
            <div class="stat-card categories">
                <div class="stat-number"><?php echo number_format($total_categories); ?></div>
                <div class="stat-label">Categories</div>
                <div class="stat-icon"><i class="fas fa-tags"></i></div>
            </div>
            <div class="stat-card locations">
                <div class="stat-number"><?php echo number_format($total_locations); ?></div>
                <div class="stat-label">Locations</div>
                <div class="stat-icon"><i class="fas fa-map-marker-alt"></i></div>
            </div>
        </div>

        <!-- Tabs Navigation -->
        <div class="tabs animate">
            <div class="tab active" onclick="openTab('booksTab', this)">Books & Issues</div>
            <div class="tab" onclick="openTab('categoriesTab', this)">Categories (<?php echo $total_categories; ?>)</div>
            <div class="tab" onclick="openTab('locationsTab', this)">Locations (<?php echo $total_locations; ?>)</div>
        </div>

        <!-- Books Tab -->
        <div id="booksTab" class="tab-content active">
            <!-- Filter Section -->
            <div class="filter-section animate">
                <form method="GET" id="bookFilter">
                    <div class="filter-grid">
                        <div class="form-group">
                            <label for="search">Search Books</label>
                            <input type="text" id="search" name="search" class="form-control" 
                                   value="<?php echo htmlspecialchars($search_query); ?>" 
                                   placeholder="Search by title, author, or ISBN...">
                        </div>
                        <div class="form-group">
                            <label for="category">Category</label>
                            <select id="category" name="category" class="form-control">
                                <option value="">All Categories</option>
                                <?php foreach($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat['name']); ?>" 
                                        <?php echo $selected_category == $cat['name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="status">Availability</label>
                            <select id="status" name="status" class="form-control">
                                <option value="">All Books</option>
                                <option value="available" <?php echo $selected_status == 'available' ? 'selected' : ''; ?>>Available</option>
                                <option value="unavailable" <?php echo $selected_status == 'unavailable' ? 'selected' : ''; ?>>Unavailable</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary" style="width: 100%;">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Books Table -->
            <div class="data-card animate">
                <div class="card-header">
                    <h3>
                        <i class="fas fa-books"></i>
                        Book Collection
                    </h3>
                    <div class="badge"><?php echo count($books); ?> books found</div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Title & Author</th>
                                <th>Category</th>
                                <th>Location</th>
                                <th>Copies</th>
                                <th>Available</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($books)): ?>
                                <?php foreach($books as $book): 
                                    $availability = $book['available_copies'] > 0 ? 'available' : 'unavailable';
                                    $bookCategory = $book['category'] ?? 'Uncategorized';
                                ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($book['title']); ?></div>
                                        <div style="font-size: 0.85rem; color: var(--gray);">
                                            by <?php echo htmlspecialchars($book['author']); ?>
                                        </div>
                                        <?php if (!empty($book['isbn'])): ?>
                                        <div style="font-size: 0.75rem; color: var(--gray);">ISBN: <?php echo htmlspecialchars($book['isbn']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge" style="background: rgba(67, 97, 238, 0.1); color: var(--primary);">
                                            <?php echo htmlspecialchars($bookCategory); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($book['location']); ?></td>
                                    <td><?php echo $book['total_copies']; ?></td>
                                    <td><?php echo $book['available_copies']; ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $availability; ?>">
                                            <i class="fas fa-<?php echo $availability == 'available' ? 'check-circle' : 'times-circle'; ?>"></i>
                                            <?php echo $availability == 'available' ? 'Available' : 'Unavailable'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn" data-tooltip="View Details" onclick="viewBookDetails(<?php echo $book['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="action-btn success" data-tooltip="Issue Book" 
                                                    onclick="issueBook(<?php echo $book['id']; ?>)" 
                                                    <?php echo $availability == 'unavailable' ? 'disabled' : ''; ?>>
                                                <i class="fas fa-book-open"></i>
                                            </button>
                                            <button class="action-btn" data-tooltip="Edit Book" onclick="editBook(<?php echo $book['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="empty-state">
                                        <i class="fas fa-book"></i>
                                        <h3>No Books Found</h3>
                                        <p>No books match your current filters.</p>
                                        <button class="btn btn-primary" onclick="openAddBookModal()">
                                            <i class="fas fa-plus"></i> Add New Book
                                        </button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Active Issues Section -->
            <div class="data-card animate">
                <div class="card-header" style="background: var(--gradient-3);">
                    <h3>
                        <i class="fas fa-book-open"></i>
                        Currently Issued Books
                    </h3>
                    <div class="badge"><?php echo count($active_issues); ?> active issues</div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Book</th>
                                <th>Student</th>
                                <th>Class</th>
                                <th>Issue Date</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($active_issues)): ?>
                                <?php foreach($active_issues as $issue): 
                                    $is_overdue = $issue['days_remaining'] < 0;
                                ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($issue['title']); ?></div>
                                        <div style="font-size: 0.85rem; color: var(--gray);">
                                            by <?php echo htmlspecialchars($issue['author']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($issue['full_name']); ?></div>
                                        <div style="font-size: 0.85rem; color: var(--gray);">
                                            ID: <?php echo htmlspecialchars($issue['admission_number']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($issue['class_name']); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($issue['issue_date'])); ?></td>
                                    <td>
                                        <div><?php echo date('M j, Y', strtotime($issue['due_date'])); ?></div>
                                        <?php if ($is_overdue): ?>
                                            <div style="font-size: 0.75rem; color: var(--danger); font-weight: 600;">
                                                Overdue by <?php echo abs($issue['days_remaining']); ?> days
                                            </div>
                                        <?php else: ?>
                                            <div style="font-size: 0.75rem; color: var(--gray);">
                                                Due in <?php echo $issue['days_remaining']; ?> days
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $is_overdue ? 'status-overdue' : 'status-issued'; ?>">
                                            <i class="fas fa-<?php echo $is_overdue ? 'exclamation-triangle' : 'book-open'; ?>"></i>
                                            <?php echo $is_overdue ? 'Overdue' : 'Issued'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="action-btn success" data-tooltip="Return Book" onclick="returnBook(<?php echo $issue['id']; ?>)">
                                            <i class="fas fa-undo"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="empty-state">
                                        <i class="fas fa-book-open"></i>
                                        <h3>No Active Issues</h3>
                                        <p>There are no books currently issued.</p>
                                        <button class="btn btn-success" onclick="openIssueBookModal()">
                                            <i class="fas fa-book-open"></i> Issue a Book
                                        </button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Categories Tab -->
        <div id="categoriesTab" class="tab-content">
            <div class="data-card">
                <div class="card-header" style="background: var(--gradient-5);">
                    <h3>
                        <i class="fas fa-tags"></i>
                        Book Categories
                    </h3>
                    <button class="btn btn-warning btn-sm" onclick="openAddCategoryModal()">
                        <i class="fas fa-plus"></i> Add Category
                    </button>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Category Name</th>
                                <th>Description</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($categories)): ?>
                                <?php foreach($categories as $cat): ?>
                                <tr>
                                    <td>
                                        <span class="status-badge" style="background: rgba(67, 97, 238, 0.1); color: var(--primary);">
                                            <?php echo htmlspecialchars($cat['name']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($cat['description']); ?></td>
                                    <td>
                                        <button class="action-btn danger" data-tooltip="Delete Category" onclick="deleteCategory(<?php echo $cat['id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="empty-state">
                                        <i class="fas fa-tags"></i>
                                        <h3>No Categories Found</h3>
                                        <p>Start by adding your first book category.</p>
                                        <button class="btn btn-warning" onclick="openAddCategoryModal()">
                                            <i class="fas fa-plus"></i> Add First Category
                                        </button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Locations Tab -->
        <div id="locationsTab" class="tab-content">
            <div class="data-card">
                <div class="card-header" style="background: var(--gradient-4);">
                    <h3>
                        <i class="fas fa-map-marker-alt"></i>
                        Book Locations
                    </h3>
                    <button class="btn btn-info btn-sm" onclick="openAddLocationModal()">
                        <i class="fas fa-plus"></i> Add Location
                    </button>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Location</th>
                                <th>Shelf</th>
                                <th>Row</th>
                                <th>Description</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($locations)): ?>
                                <?php foreach($locations as $loc): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($loc['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($loc['shelf']); ?></td>
                                    <td><?php echo htmlspecialchars($loc['row']); ?></td>
                                    <td><?php echo htmlspecialchars($loc['description']); ?></td>
                                    <td>
                                        <button class="action-btn danger" data-tooltip="Delete Location" onclick="deleteLocation(<?php echo $loc['id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="empty-state">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <h3>No Locations Found</h3>
                                        <p>Start by adding your first book location.</p>
                                        <button class="btn btn-info" onclick="openAddLocationModal()">
                                            <i class="fas fa-plus"></i> Add First Location
                                        </button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Book Modal -->
    <div id="addBookModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class="fas fa-plus-circle" style="color: var(--primary);"></i>
                    Add New Book
                </h3>
                <button class="modal-close" onclick="closeModal('addBookModal')">&times;</button>
            </div>
            <form method="POST" id="addBookForm">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label class="required">Book Title</label>
                            <input type="text" name="title" class="form-control" required placeholder="Enter book title">
                        </div>
                        <div class="form-group">
                            <label class="required">Author</label>
                            <input type="text" name="author" class="form-control" required placeholder="Author name">
                        </div>
                        <div class="form-group">
                            <label>ISBN</label>
                            <input type="text" name="isbn" class="form-control" placeholder="International Standard Book Number">
                        </div>
                        <div class="form-group">
                            <label>Publisher</label>
                            <input type="text" name="publisher" class="form-control" placeholder="Publisher name">
                        </div>
                        <div class="form-group">
                            <label>Publication Year</label>
                            <input type="number" name="publication_year" class="form-control" min="1900" max="<?php echo date('Y'); ?>" value="<?php echo date('Y'); ?>">
                        </div>
                        <div class="form-group">
                            <label class="required">Category</label>
                            <select name="category" class="form-control" required>
                                <option value="">Select Category</option>
                                <?php foreach($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat['name']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="required">Location</label>
                            <select name="location" class="form-control" required>
                                <option value="">Select Location</option>
                                <?php foreach($locations as $loc): ?>
                                <option value="<?php echo htmlspecialchars($loc['name']); ?>">
                                    <?php echo htmlspecialchars($loc['name']); ?> (Shelf <?php echo htmlspecialchars($loc['shelf']); ?>, Row <?php echo htmlspecialchars($loc['row']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="required">Number of Copies</label>
                            <input type="number" name="copies" class="form-control" min="1" value="1" required>
                        </div>
                        <div class="form-group full-width">
                            <label>Description</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="Brief description of the book..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addBookModal')">Cancel</button>
                    <button type="submit" name="add_book" class="btn btn-primary">Add Book</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Issue Book Modal -->
    <div id="issueBookModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class="fas fa-book-open" style="color: var(--success);"></i>
                    Issue Book
                </h3>
                <button class="modal-close" onclick="closeModal('issueBookModal')">&times;</button>
            </div>
            <form method="POST" id="issueBookForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="required">Select Book</label>
                        <select name="book_id" id="issue_book_id" class="form-control" required onchange="updateBookDetails()">
                            <option value="">Choose a book...</option>
                            <?php 
                            $available_books = $pdo->query("SELECT * FROM books WHERE available_copies > 0 ORDER BY title")->fetchAll();
                            foreach($available_books as $book): 
                            ?>
                            <option value="<?php echo $book['id']; ?>" data-author="<?php echo htmlspecialchars($book['author']); ?>" data-copies="<?php echo $book['available_copies']; ?>">
                                <?php echo htmlspecialchars($book['title']); ?> by <?php echo htmlspecialchars($book['author']); ?> (<?php echo $book['available_copies']; ?> available)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="bookDetails" class="book-details" style="display: none;">
                        <!-- Populated via JavaScript -->
                    </div>
                    <div class="form-group">
                        <label class="required">Select Student</label>
                        <select name="student_id" class="form-control" required>
                            <option value="">Choose a student...</option>
                            <?php 
                            $students = $pdo->query("SELECT s.id, s.full_name, s.admission_number, c.class_name FROM students s JOIN classes c ON s.class_id = c.id WHERE s.status = 'active' ORDER BY s.full_name")->fetchAll();
                            foreach($students as $student): 
                            ?>
                            <option value="<?php echo $student['id']; ?>">
                                <?php echo htmlspecialchars($student['full_name']); ?> (<?php echo htmlspecialchars($student['admission_number']); ?>) - <?php echo htmlspecialchars($student['class_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="required">Issue Date</label>
                            <input type="date" name="issue_date" id="issue_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="required">Due Date</label>
                            <input type="date" name="due_date" id="due_date" class="form-control" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('issueBookModal')">Cancel</button>
                    <button type="submit" name="issue_book" class="btn btn-success">Issue Book</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Return Book Modal -->
    <div id="returnBookModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class="fas fa-undo" style="color: var(--success);"></i>
                    Return Book
                </h3>
                <button class="modal-close" onclick="closeModal('returnBookModal')">&times;</button>
            </div>
            <form method="POST" id="returnBookForm">
                <input type="hidden" name="issue_id" id="return_issue_id">
                <div class="modal-body">
                    <div id="returnBookInfo" class="book-details">
                        <!-- Populated via JavaScript -->
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="required">Return Date</label>
                            <input type="date" name="return_date" id="return_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="required">Book Condition</label>
                            <select name="condition" class="form-control" required>
                                <option value="">Select Condition</option>
                                <option value="Excellent">Excellent</option>
                                <option value="Good">Good</option>
                                <option value="Fair">Fair</option>
                                <option value="Poor">Poor</option>
                                <option value="Damaged">Damaged</option>
                            </select>
                        </div>
                        <div class="form-group full-width">
                            <label>Notes</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Any notes about the book's condition..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('returnBookModal')">Cancel</button>
                    <button type="submit" name="return_book" class="btn btn-success">Return Book</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Book Modal -->
    <div id="viewBookModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class="fas fa-book" style="color: var(--primary);"></i>
                    Book Details
                </h3>
                <button class="modal-close" onclick="closeModal('viewBookModal')">&times;</button>
            </div>
            <div class="modal-body" id="viewBookBody">
                <!-- Populated via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('viewBookModal')">Close</button>
                <button type="button" class="btn btn-primary" id="viewToEditBtn">Edit Book</button>
            </div>
        </div>
    </div>

    <!-- Edit Book Modal -->
    <div id="editBookModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class="fas fa-edit" style="color: var(--warning);"></i>
                    Edit Book
                </h3>
                <button class="modal-close" onclick="closeModal('editBookModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editBookForm">
                    <input type="hidden" id="edit_book_id" name="id">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label class="required">Title</label>
                            <input type="text" id="edit_title" name="title" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="required">Author</label>
                            <input type="text" id="edit_author" name="author" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>ISBN</label>
                            <input type="text" id="edit_isbn" name="isbn" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Publisher</label>
                            <input type="text" id="edit_publisher" name="publisher" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Publication Year</label>
                            <input type="number" id="edit_publication_year" name="publication_year" class="form-control" min="1900" max="<?php echo date('Y'); ?>">
                        </div>
                        <div class="form-group">
                            <label class="required">Category</label>
                            <select id="edit_category" name="category" class="form-control" required>
                                <option value="">Select Category</option>
                                <?php foreach($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat['name']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="required">Location</label>
                            <select id="edit_location" name="location" class="form-control" required>
                                <option value="">Select Location</option>
                                <?php foreach($locations as $loc): ?>
                                <option value="<?php echo htmlspecialchars($loc['name']); ?>">
                                    <?php echo htmlspecialchars($loc['name']); ?> (Shelf <?php echo htmlspecialchars($loc['shelf']); ?>, Row <?php echo htmlspecialchars($loc['row']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Total Copies</label>
                            <input type="number" id="edit_total_copies" name="total_copies" class="form-control" min="0">
                        </div>
                        <div class="form-group">
                            <label>Available Copies</label>
                            <input type="number" id="edit_available_copies" name="available_copies" class="form-control" min="0">
                        </div>
                        <div class="form-group full-width">
                            <label>Description</label>
                            <textarea id="edit_description" name="description" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editBookModal')">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveEditBook()">Save Changes</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // SweetAlert2 default configuration
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer)
                toast.addEventListener('mouseleave', Swal.resumeTimer)
            }
        });

        // Tab functions
        function openTab(tabId, element) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.getElementById(tabId).classList.add('active');
            element.classList.add('active');
        }

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Modal openers
        function openAddBookModal() {
            openModal('addBookModal');
        }

        function openIssueBookModal() {
            openModal('issueBookModal');
            updateBookDetails();
        }

        function openAddCategoryModal() {
            Swal.fire({
                title: 'Add New Category',
                html: `
                    <div style="text-align: left;">
                        <div style="margin-bottom: 1rem;">
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Category Name</label>
                            <input type="text" id="categoryName" class="swal2-input" placeholder="e.g., Fiction, Science" style="width: 100%;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Description</label>
                            <textarea id="categoryDescription" class="swal2-textarea" placeholder="Description of this category..." style="width: 100%;"></textarea>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Add Category',
                cancelButtonText: 'Cancel',
                preConfirm: () => {
                    const name = document.getElementById('categoryName').value.trim();
                    if (!name) {
                        Swal.showValidationMessage('Category name is required');
                        return false;
                    }
                    return {
                        name: name,
                        description: document.getElementById('categoryDescription').value.trim()
                    };
                }
            }).then(result => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('ajax_action', 'add_category');
                    formData.append('category_name', result.value.name);
                    formData.append('description', result.value.description);

                    Swal.fire({
                        title: 'Adding...',
                        allowOutsideClick: false,
                        didOpen: () => Swal.showLoading()
                    });

                    fetch(window.location.pathname, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Toast.fire({
                                icon: 'success',
                                title: data.message
                            }).then(() => {
                                window.location.reload();
                            });
                        } else {
                            Swal.fire('Error', data.error || 'Failed to add category', 'error');
                        }
                    })
                    .catch(err => {
                        Swal.fire('Error', 'Network error: ' + err.message, 'error');
                    });
                }
            });
        }

        function openAddLocationModal() {
            Swal.fire({
                title: 'Add New Location',
                html: `
                    <div style="text-align: left;">
                        <div style="margin-bottom: 1rem;">
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Location Name</label>
                            <input type="text" id="locationName" class="swal2-input" placeholder="e.g., Main Library, Section A" style="width: 100%;">
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                            <div>
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Shelf</label>
                                <input type="text" id="locationShelf" class="swal2-input" placeholder="e.g., A, B, 1" style="width: 100%;">
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Row</label>
                                <input type="text" id="locationRow" class="swal2-input" placeholder="e.g., 1, 2, 3" style="width: 100%;">
                            </div>
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Description</label>
                            <textarea id="locationDescription" class="swal2-textarea" placeholder="Description of this location..." style="width: 100%;"></textarea>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Add Location',
                cancelButtonText: 'Cancel',
                preConfirm: () => {
                    const name = document.getElementById('locationName').value.trim();
                    const shelf = document.getElementById('locationShelf').value.trim();
                    const row = document.getElementById('locationRow').value.trim();
                    
                    if (!name || !shelf || !row) {
                        Swal.showValidationMessage('Location name, shelf, and row are required');
                        return false;
                    }
                    
                    return {
                        name: name,
                        shelf: shelf,
                        row: row,
                        description: document.getElementById('locationDescription').value.trim()
                    };
                }
            }).then(result => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('ajax_action', 'add_location');
                    formData.append('location_name', result.value.name);
                    formData.append('shelf', result.value.shelf);
                    formData.append('row', result.value.row);
                    formData.append('description', result.value.description);

                    Swal.fire({
                        title: 'Adding...',
                        allowOutsideClick: false,
                        didOpen: () => Swal.showLoading()
                    });

                    fetch(window.location.pathname, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Toast.fire({
                                icon: 'success',
                                title: data.message
                            }).then(() => {
                                window.location.reload();
                            });
                        } else {
                            Swal.fire('Error', data.error || 'Failed to add location', 'error');
                        }
                    })
                    .catch(err => {
                        Swal.fire('Error', 'Network error: ' + err.message, 'error');
                    });
                }
            });
        }

        // Delete functions
        function deleteCategory(categoryId) {
            Swal.fire({
                title: 'Delete Category?',
                text: 'Are you sure you want to delete this category? This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f94144',
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel'
            }).then(result => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('ajax_action', 'delete_category');
                    formData.append('category_id', categoryId);

                    Swal.fire({
                        title: 'Deleting...',
                        allowOutsideClick: false,
                        didOpen: () => Swal.showLoading()
                    });

                    fetch(window.location.pathname, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Toast.fire({
                                icon: 'success',
                                title: data.message
                            }).then(() => {
                                window.location.reload();
                            });
                        } else {
                            Swal.fire('Error', data.error || 'Failed to delete category', 'error');
                        }
                    })
                    .catch(err => {
                        Swal.fire('Error', 'Network error: ' + err.message, 'error');
                    });
                }
            });
        }

        function deleteLocation(locationId) {
            Swal.fire({
                title: 'Delete Location?',
                text: 'Are you sure you want to delete this location? This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f94144',
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel'
            }).then(result => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('ajax_action', 'delete_location');
                    formData.append('location_id', locationId);

                    Swal.fire({
                        title: 'Deleting...',
                        allowOutsideClick: false,
                        didOpen: () => Swal.showLoading()
                    });

                    fetch(window.location.pathname, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Toast.fire({
                                icon: 'success',
                                title: data.message
                            }).then(() => {
                                window.location.reload();
                            });
                        } else {
                            Swal.fire('Error', data.error || 'Failed to delete location', 'error');
                        }
                    })
                    .catch(err => {
                        Swal.fire('Error', 'Network error: ' + err.message, 'error');
                    });
                }
            });
        }

        // Issue book functions
        function issueBook(bookId) {
            document.getElementById('issue_book_id').value = bookId;
            updateBookDetails();
            openModal('issueBookModal');
        }

        function updateBookDetails() {
            const select = document.getElementById('issue_book_id');
            const option = select.options[select.selectedIndex];
            const detailsDiv = document.getElementById('bookDetails');
            
            if (option.value) {
                const author = option.getAttribute('data-author');
                const copies = option.getAttribute('data-copies');
                
                detailsDiv.innerHTML = `
                    <div style="font-weight: 600; margin-bottom: 0.5rem;">Book Details:</div>
                    <div class="book-details-grid">
                        <div>
                            <div class="detail-label">Author</div>
                            <div class="detail-value">${escapeHtml(author)}</div>
                        </div>
                        <div>
                            <div class="detail-label">Available Copies</div>
                            <div class="detail-value">${copies}</div>
                        </div>
                    </div>
                `;
                detailsDiv.style.display = 'block';
            } else {
                detailsDiv.style.display = 'none';
            }
        }

        function returnBook(issueId) {
            const issueData = <?php echo json_encode(array_column($active_issues, null, 'id')); ?>;
            const issue = issueData[issueId];
            
            if (issue) {
                document.getElementById('return_issue_id').value = issue.id;
                
                const infoDiv = document.getElementById('returnBookInfo');
                const isOverdue = issue.days_remaining < 0;
                
                infoDiv.innerHTML = `
                    <div style="font-weight: 600; margin-bottom: 1rem;">Return Details:</div>
                    <div class="book-details-grid">
                        <div>
                            <div class="detail-label">Book</div>
                            <div class="detail-value">${escapeHtml(issue.title)}</div>
                        </div>
                        <div>
                            <div class="detail-label">Author</div>
                            <div class="detail-value">${escapeHtml(issue.author)}</div>
                        </div>
                        <div>
                            <div class="detail-label">Student</div>
                            <div class="detail-value">${escapeHtml(issue.full_name)}</div>
                        </div>
                        <div>
                            <div class="detail-label">Student ID</div>
                            <div class="detail-value">${escapeHtml(issue.admission_number)}</div>
                        </div>
                        <div>
                            <div class="detail-label">Issue Date</div>
                            <div class="detail-value">${new Date(issue.issue_date).toLocaleDateString()}</div>
                        </div>
                        <div>
                            <div class="detail-label">Due Date</div>
                            <div class="detail-value" style="color: ${isOverdue ? 'var(--danger)' : 'var(--success)'}; font-weight: 600;">
                                ${new Date(issue.due_date).toLocaleDateString()}
                                ${isOverdue ? ` (Overdue by ${Math.abs(issue.days_remaining)} days)` : ''}
                            </div>
                        </div>
                    </div>
                `;
                
                openModal('returnBookModal');
            }
        }

        // View book details
        function viewBookDetails(bookId) {
            fetch(window.location.pathname + '?ajax=details&id=' + encodeURIComponent(bookId), {
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    Swal.fire('Error', data.error || 'Failed to load book', 'error');
                    return;
                }
                
                const b = data.book;
                const body = document.getElementById('viewBookBody');
                
                body.innerHTML = `
                    <div class="book-details">
                        <div style="font-weight: 600; font-size: 1.2rem; margin-bottom: 1rem;">${escapeHtml(b.title)}</div>
                        <div style="color: var(--gray); margin-bottom: 1rem;">by ${escapeHtml(b.author)}</div>
                        
                        <div class="book-details-grid">
                            <div>
                                <div class="detail-label">ISBN</div>
                                <div class="detail-value">${escapeHtml(b.isbn || 'N/A')}</div>
                            </div>
                            <div>
                                <div class="detail-label">Publisher</div>
                                <div class="detail-value">${escapeHtml(b.publisher || 'N/A')} ${b.publication_year ? '(' + escapeHtml(b.publication_year) + ')' : ''}</div>
                            </div>
                            <div>
                                <div class="detail-label">Category</div>
                                <div class="detail-value">${escapeHtml(b.category || 'N/A')}</div>
                            </div>
                            <div>
                                <div class="detail-label">Location</div>
                                <div class="detail-value">${escapeHtml(b.location || 'N/A')}</div>
                            </div>
                            <div>
                                <div class="detail-label">Total Copies</div>
                                <div class="detail-value">${b.total_copies}</div>
                            </div>
                            <div>
                                <div class="detail-label">Available Copies</div>
                                <div class="detail-value">${b.available_copies}</div>
                            </div>
                        </div>
                        
                        ${b.description ? `
                            <div style="margin-top: 1rem;">
                                <div class="detail-label">Description</div>
                                <div style="margin-top: 0.5rem;">${escapeHtml(b.description)}</div>
                            </div>
                        ` : ''}
                    </div>
                `;

                const editBtn = document.getElementById('viewToEditBtn');
                editBtn.onclick = function() { 
                    closeModal('viewBookModal'); 
                    editBook(bookId); 
                };

                openModal('viewBookModal');
            })
            .catch(err => {
                console.error(err);
                Swal.fire('Error', 'Failed to load book details', 'error');
            });
        }

        // Edit book
        function editBook(bookId) {
            fetch(window.location.pathname + '?ajax=details&id=' + encodeURIComponent(bookId), {
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    Swal.fire('Error', data.error || 'Failed to load book', 'error');
                    return;
                }
                
                const b = data.book;
                document.getElementById('edit_book_id').value = b.id;
                document.getElementById('edit_title').value = b.title || '';
                document.getElementById('edit_author').value = b.author || '';
                document.getElementById('edit_isbn').value = b.isbn || '';
                document.getElementById('edit_publisher').value = b.publisher || '';
                document.getElementById('edit_publication_year').value = b.publication_year || '';
                document.getElementById('edit_category').value = b.category || '';
                document.getElementById('edit_total_copies').value = b.total_copies || 0;
                document.getElementById('edit_available_copies').value = b.available_copies || 0;
                document.getElementById('edit_location').value = b.location || '';
                document.getElementById('edit_description').value = b.description || '';

                openModal('editBookModal');
            })
            .catch(err => {
                console.error(err);
                Swal.fire('Error', 'Failed to load book for editing', 'error');
            });
        }

        // Save edit book
        function saveEditBook() {
            const formData = new FormData(document.getElementById('editBookForm'));
            formData.append('ajax_action', 'save_book');

            Swal.fire({
                title: 'Saving...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            fetch(window.location.pathname, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Toast.fire({
                        icon: 'success',
                        title: data.message || 'Book updated successfully'
                    }).then(() => {
                        closeModal('editBookModal');
                        window.location.reload();
                    });
                } else {
                    Swal.fire('Error', data.error || 'Failed to update book', 'error');
                }
            })
            .catch(err => {
                Swal.fire('Error', 'Network error: ' + err.message, 'error');
            });
        }

        // View overdue books
        function viewOverdueBooks() {
            const params = new URLSearchParams(window.location.search);
            params.set('view', 'overdue');
            const url = window.location.pathname + '?' + params.toString();
            window.open(url, '_blank');
        }

        // Generate library report
        function generateLibraryReport() {
            const params = new URLSearchParams(window.location.search);
            params.set('report', '1');
            const url = window.location.pathname + '?' + params.toString();
            window.open(url, '_blank');
        }

        // Escape HTML
        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/[&<>"']/g, function(s) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                }[s];
            });
        }

        // Initialize date fields
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            const dueDate = new Date(Date.now() + 14 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
            
            const issueDate = document.getElementById('issue_date');
            const dueDateField = document.getElementById('due_date');
            const returnDate = document.getElementById('return_date');
            
            if (issueDate) issueDate.value = today;
            if (dueDateField) dueDateField.value = dueDate;
            if (returnDate) returnDate.value = today;
        });

        // Form validation
        document.getElementById('issueBookForm')?.addEventListener('submit', function(e) {
            const dueDate = new Date(document.getElementById('due_date').value);
            const issueDate = new Date(document.getElementById('issue_date').value);
            
            if (dueDate <= issueDate) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Due date must be after the issue date.'
                });
            }
        });
    </script>
</body>
</html>
