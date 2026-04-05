<?php
require_once '../config.php';
require_once '../library_fines_workflow_helpers.php';
checkAuth();
checkRole(['admin', 'librarian']);

ensureLibraryFineWorkflowSchema($pdo);

$page_title = "Library Circulation Management - " . SCHOOL_NAME;

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    try {
        if ($_GET['ajax'] === 'search_books') {
            $query = trim($_GET['query'] ?? '');
            $exclude_issued = isset($_GET['exclude_issued']) ? (bool)$_GET['exclude_issued'] : false;

            if (ctype_digit($query)) {
                $stmt = $pdo->prepare("
                    SELECT b.id, b.title, b.author, b.isbn, b.available_copies, b.total_copies,
                           c.name as category_name, l.name as location_name,
                           b.status
                    FROM books b
                    LEFT JOIN book_categories c ON b.category_id = c.id
                    LEFT JOIN book_locations l ON b.location_id = l.id
                    WHERE b.id = ? AND b.available_copies > 0
                    LIMIT 1
                ");
                $stmt->execute([(int) $query]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT b.id, b.title, b.author, b.isbn, b.available_copies, b.total_copies,
                           c.name as category_name, l.name as location_name,
                           b.status
                    FROM books b
                    LEFT JOIN book_categories c ON b.category_id = c.id
                    LEFT JOIN book_locations l ON b.location_id = l.id
                    WHERE (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ?)
                      AND b.available_copies > 0
                    LIMIT 15
                ");
                $search = "%$query%";
                $stmt->execute([$search, $search, $search]);
            }

            $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'books' => $books]);
            exit();
        }

        if ($_GET['ajax'] === 'search_students') {
            $query = $_GET['query'] ?? '';
            $stmt = $pdo->prepare("
                SELECT s.id, s.full_name, s.admission_number, c.class_name,
                       (SELECT COUNT(*) FROM book_issues WHERE student_id = s.id AND return_date IS NULL) as active_loans
                FROM students s
                JOIN classes c ON s.class_id = c.id
                WHERE (s.full_name LIKE ? OR s.admission_number LIKE ?)
                  AND s.status = 'active'
                LIMIT 15
            ");
            $search = "%$query%";
            $stmt->execute([$search, $search]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'students' => $students]);
            exit();
        }

        if ($_GET['ajax'] === 'search_issued') {
            $query = $_GET['query'] ?? '';
            $filter = $_GET['filter'] ?? 'all';
            
            $sql = "
                SELECT bi.id, b.title, b.author, b.isbn,
                       s.id as student_id, s.full_name as student_name, s.admission_number,
                       c.class_name,
                       bi.issue_date, bi.due_date,
                       DATEDIFF(CURDATE(), bi.due_date) as days_overdue,
                       CASE 
                           WHEN bi.return_date IS NOT NULL THEN 'returned'
                           WHEN bi.due_date < CURDATE() THEN 'overdue'
                           ELSE 'active'
                       END as status
                FROM book_issues bi
                JOIN books b ON bi.book_id = b.id
                JOIN students s ON bi.student_id = s.id
                JOIN classes c ON s.class_id = c.id
                WHERE (b.title LIKE ? OR s.full_name LIKE ? OR s.admission_number LIKE ?)
            ";
            
            if ($filter === 'active') {
                $sql .= " AND bi.return_date IS NULL";
            } elseif ($filter === 'overdue') {
                $sql .= " AND bi.return_date IS NULL AND bi.due_date < CURDATE()";
            } elseif ($filter === 'returned') {
                $sql .= " AND bi.return_date IS NOT NULL";
            }
            
            $sql .= " LIMIT 15";
            
            $stmt = $pdo->prepare($sql);
            $search = "%$query%";
            $stmt->execute([$search, $search, $search]);
            $issues = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'issues' => $issues]);
            exit();
        }

        if ($_GET['ajax'] === 'get_issue_details') {
            $id = (int)$_GET['id'];
            $stmt = $pdo->prepare("
                SELECT bi.*, b.title, b.author, b.isbn,
                       s.full_name, s.admission_number, c.class_name,
                       u.full_name as issued_by_name,
                       DATEDIFF(CURDATE(), bi.due_date) as days_overdue
                FROM book_issues bi
                JOIN books b ON bi.book_id = b.id
                JOIN students s ON bi.student_id = s.id
                JOIN classes c ON s.class_id = c.id
                LEFT JOIN users u ON bi.issued_by = u.id
                WHERE bi.id = ?
            ");
            $stmt->execute([$id]);
            $issue = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($issue) {
                echo json_encode(['success' => true, 'issue' => $issue]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Issue not found']);
            }
            exit();
        }

        echo json_encode(['success' => false, 'error' => 'Invalid request']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'issue_book') {
            $book_id = (int)($_POST['book_id'] ?? 0);
            $student_id = (int)($_POST['student_id'] ?? 0);
            $due_date = $_POST['due_date'] ?? date('Y-m-d', strtotime('+14 days'));
            $notes = trim($_POST['notes'] ?? '');

            if ($book_id <= 0 || $student_id <= 0) {
                echo json_encode(['success' => false, 'error' => 'Please select both a book and a student']);
                exit();
            }

            // Check book availability
            $book = $pdo->prepare("SELECT title, available_copies, total_copies FROM books WHERE id = ?");
            $book->execute([$book_id]);
            $book_data = $book->fetch();

            if (!$book_data || $book_data['available_copies'] <= 0) {
                echo json_encode(['success' => false, 'error' => 'Book not available']);
                exit();
            }

            // Check student's active loans limit (max 5 books)
            $loan_count = $pdo->prepare("SELECT COUNT(*) FROM book_issues WHERE student_id = ? AND return_date IS NULL");
            $loan_count->execute([$student_id]);
            if ($loan_count->fetchColumn() >= 5) {
                echo json_encode(['success' => false, 'error' => 'Student already has maximum number of books (5)']);
                exit();
            }

            // Check if student already has this book
            $existing = $pdo->prepare("SELECT id FROM book_issues WHERE book_id = ? AND student_id = ? AND return_date IS NULL");
            $existing->execute([$book_id, $student_id]);
            if ($existing->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Student already has this book']);
                exit();
            }

            // Check for overdue books
            $overdue = $pdo->prepare("SELECT COUNT(*) FROM book_issues WHERE student_id = ? AND return_date IS NULL AND due_date < CURDATE()");
            $overdue->execute([$student_id]);
            if ($overdue->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'error' => 'Student has overdue books. Please clear them first.']);
                exit();
            }

            $pdo->beginTransaction();

            // Create issue
            $stmt = $pdo->prepare("
                INSERT INTO book_issues (book_id, student_id, issue_date, due_date, notes, issued_by) 
                VALUES (?, ?, CURDATE(), ?, ?, ?)
            ");
            $stmt->execute([$book_id, $student_id, $due_date, $notes, $_SESSION['user_id']]);
            $issue_id = $pdo->lastInsertId();

            // Update book copies
            $update = $pdo->prepare("UPDATE books SET available_copies = available_copies - 1 WHERE id = ?");
            $update->execute([$book_id]);

            // Log the activity
            try {
                $log = $pdo->prepare("
                    INSERT INTO activity_log (user_id, action, description, reference_id) 
                    VALUES (?, 'book_issued', ?, ?)
                ");
                $log->execute([$_SESSION['user_id'], "Issued '{$book_data['title']}' to student ID: $student_id", $issue_id]);
            } catch (PDOException $e) {
                // activity_log table may not exist, continue without logging
                error_log('Activity log error: ' . $e->getMessage());
            }

            $pdo->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Book issued successfully',
                'issue_id' => $issue_id
            ]);
            exit();
        }

        if ($action === 'return_book') {
            $issue_id = (int)($_POST['issue_id'] ?? 0);
            $condition = $_POST['condition'] ?? 'Good';
            $notes = trim($_POST['notes'] ?? '');

            if ($issue_id <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid issue record']);
                exit();
            }

            $pdo->beginTransaction();

            // Get book ID and details
            $issue = $pdo->prepare("
                SELECT bi.*, b.title, b.author,
                       DATEDIFF(CURDATE(), bi.due_date) as days_overdue
                FROM book_issues bi 
                JOIN books b ON bi.book_id = b.id 
                WHERE bi.id = ? AND bi.return_date IS NULL
            ");
            $issue->execute([$issue_id]);
            $issue_data = $issue->fetch();

            if (!$issue_data) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Invalid issue record or already returned']);
                exit();
            }

            // Calculate fine if overdue
            $days_overdue = max(0, (int)$issue_data['days_overdue']);
            $fine_amount = 0;
            $fine_rate = 20; // KES 20 per day
            
            if ($days_overdue > 0) {
                $fine_amount = $days_overdue * $fine_rate;
                
                // Create fine record
                $fine_stmt = $pdo->prepare("
                    INSERT INTO library_fines (book_issue_id, student_id, days_overdue, fine_rate, fine_amount, reason)
                    VALUES (?, ?, ?, ?, ?, 'Overdue fine')
                ");
                $fine_stmt->execute([$issue_id, $issue_data['student_id'], $days_overdue, $fine_rate, $fine_amount]);
            }

            // Update issue
            $stmt = $pdo->prepare("
                UPDATE book_issues 
                SET return_date = CURDATE(), condition_returned = ?, notes = CONCAT(IFNULL(notes, ''), '\n', ?)
                WHERE id = ?
            ");
            $stmt->execute([$condition, $notes, $issue_id]);

            // Update book copies
            $update = $pdo->prepare("UPDATE books SET available_copies = available_copies + 1 WHERE id = ?");
            $update->execute([$issue_data['book_id']]);

            // Log the activity
            try {
                $log = $pdo->prepare("
                    INSERT INTO activity_log (user_id, action, description, reference_id) 
                    VALUES (?, 'book_returned', ?, ?)
                ");
                $log->execute([
                    $_SESSION['user_id'], 
                    "Returned '{$issue_data['title']}' - Condition: $condition" . ($fine_amount ? " - Fine: KES $fine_amount" : ""), 
                    $issue_id
                ]);
            } catch (PDOException $e) {
                // activity_log table may not exist, continue without logging
                error_log('Activity log error: ' . $e->getMessage());
            }

            $pdo->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Book returned successfully' . ($fine_amount ? " with fine KES $fine_amount" : ""),
                'fine_amount' => $fine_amount
            ]);
            exit();
        }

        if ($action === 'renew_book') {
            $issue_id = (int)($_POST['issue_id'] ?? 0);
            $new_due_date = $_POST['new_due_date'] ?? date('Y-m-d', strtotime('+14 days'));

            if ($issue_id <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid issue record']);
                exit();
            }

            $pdo->beginTransaction();

            // Check if issue exists and is active
            $issue = $pdo->prepare("SELECT * FROM book_issues WHERE id = ? AND return_date IS NULL");
            $issue->execute([$issue_id]);
            $issue_data = $issue->fetch();

            if (!$issue_data) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Invalid or already returned book']);
                exit();
            }

            // Update due date
            $stmt = $pdo->prepare("UPDATE book_issues SET due_date = ?, renewed_count = renewed_count + 1 WHERE id = ?");
            $stmt->execute([$new_due_date, $issue_id]);

            // Log renewal
            try {
                $log = $pdo->prepare("
                    INSERT INTO activity_log (user_id, action, description, reference_id) 
                    VALUES (?, 'book_renewed', ?, ?)
                ");
                $log->execute([$_SESSION['user_id'], "Renewed book (Issue ID: $issue_id)", $issue_id]);
            } catch (PDOException $e) {
                // activity_log table may not exist, continue without logging
                error_log('Activity log error: ' . $e->getMessage());
            }

            $pdo->commit();
            
            echo json_encode(['success' => true, 'message' => 'Book renewed successfully']);
            exit();
        }

        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'active';
$search_query = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$class_filter = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

// Get classes for filter
$classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll();

// Build query for issues
$query = "
    SELECT bi.*, b.title, b.author, b.isbn, b.category_id, b.location_id,
           c.name as category_name, l.name as location_name,
           s.full_name, s.admission_number, cl.class_name,
           u.full_name as issued_by_name,
           DATEDIFF(CURDATE(), bi.due_date) as days_overdue,
           lb.id as lost_report_id,
           lb.status as lost_report_status,
           CASE 
               WHEN bi.return_date IS NOT NULL THEN 'returned'
               WHEN bi.due_date < CURDATE() THEN 'overdue'
               ELSE 'active'
           END as current_status
    FROM book_issues bi
    JOIN books b ON bi.book_id = b.id
    LEFT JOIN book_categories c ON b.category_id = c.id
    LEFT JOIN book_locations l ON b.location_id = l.id
    JOIN students s ON bi.student_id = s.id
    JOIN classes cl ON s.class_id = cl.id
    LEFT JOIN users u ON bi.issued_by = u.id
    LEFT JOIN lost_books lb
        ON lb.issue_id = bi.id
       AND lb.status IN ('reported', 'pending', 'submitted_for_approval', 'approved', 'verified', 'sent_to_accountant', 'invoiced', 'paid')
    WHERE 1=1
";
$params = [];

if ($status_filter === 'active') {
    $query .= " AND bi.return_date IS NULL";
} elseif ($status_filter === 'overdue') {
    $query .= " AND bi.return_date IS NULL AND bi.due_date < CURDATE()";
} elseif ($status_filter === 'returned') {
    $query .= " AND bi.return_date IS NOT NULL";
} elseif ($status_filter === 'today') {
    $query .= " AND bi.issue_date = CURDATE()";
}

if ($class_filter > 0) {
    $query .= " AND s.class_id = ?";
    $params[] = $class_filter;
}

if ($date_from) {
    $query .= " AND bi.issue_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $query .= " AND bi.issue_date <= ?";
    $params[] = $date_to;
}

if ($search_query) {
    $query .= " AND (b.title LIKE ? OR s.full_name LIKE ? OR s.admission_number LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

$query .= " ORDER BY bi.issue_date DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$circulations = $stmt->fetchAll();

// Get statistics
$stats = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM book_issues WHERE return_date IS NULL) as active_issues,
        (SELECT COUNT(*) FROM book_issues WHERE return_date IS NULL AND due_date < CURDATE()) as overdue_issues,
        (SELECT COUNT(*) FROM book_issues WHERE return_date IS NOT NULL) as returned_books,
        (SELECT COUNT(*) FROM book_issues WHERE issue_date = CURDATE()) as today_issues,
        (SELECT COUNT(DISTINCT student_id) FROM book_issues WHERE return_date IS NULL) as students_with_books,
        (SELECT COALESCE(SUM(DATEDIFF(CURDATE(), due_date) * 20), 0) FROM book_issues WHERE return_date IS NULL AND due_date < CURDATE()) as estimated_fines
")->fetch();
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
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

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
            --gradient-circulation: linear-gradient(135deg, #7209b7 0%, #9b59b6 100%);
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
            background: var(--gradient-circulation);
            border-radius: var(--border-radius-xl);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }

        .page-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .page-header p {
            opacity: 0.9;
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
            border-radius: var(--border-radius-md);
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-xl);
        }

        .btn-primary {
            background: var(--gradient-1);
            color: white;
        }

        .btn-success {
            background: var(--gradient-3);
            color: white;
        }

        .btn-warning {
            background: var(--gradient-5);
            color: white;
        }

        .btn-danger {
            background: var(--gradient-2);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        .btn-light {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border-left: 4px solid;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card.active { border-left-color: var(--primary); }
        .stat-card.overdue { border-left-color: var(--danger); }
        .stat-card.returned { border-left-color: var(--success); }
        .stat-card.fines { border-left-color: var(--warning); }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-detail {
            font-size: 0.8rem;
            color: var(--gray);
            margin-top: 0.3rem;
        }

        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow-x: auto;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .tab {
            padding: 1rem 1.5rem;
            cursor: pointer;
            font-weight: 600;
            color: var(--gray);
            transition: var(--transition);
            border-bottom: 3px solid transparent;
            white-space: nowrap;
            text-decoration: none;
            display: inline-block;
        }

        .tab:hover {
            color: var(--primary);
            background: rgba(67, 97, 238, 0.05);
        }

        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: rgba(67, 97, 238, 0.05);
        }

        /* Filter Section */
        .filter-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.3rem;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.85rem;
        }

        .form-control {
            width: 100%;
            padding: 0.6rem;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-sm);
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        /* Search Section */
        .search-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1rem 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .search-box {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .search-box i {
            color: var(--gray);
        }

        .search-box input {
            flex: 1;
            padding: 0.6rem;
            border: none;
            font-size: 0.95rem;
        }

        .search-box input:focus {
            outline: none;
        }

        .clear-search {
            color: var(--gray);
            text-decoration: none;
            padding: 0.3rem 0.6rem;
            border-radius: 50%;
        }

        .clear-search:hover {
            background: var(--light);
        }

        /* Data Card */
        .data-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }

        .card-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .card-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.2rem;
            font-weight: 600;
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
            font-size: 0.8rem;
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

        tr.overdue-row {
            background: rgba(249, 65, 68, 0.05);
        }

        tr.overdue-row:hover {
            background: rgba(249, 65, 68, 0.1);
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-active {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .status-overdue {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        .status-returned {
            background: rgba(108, 117, 125, 0.15);
            color: var(--gray);
        }

        .badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-warning {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        .badge-danger {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        .badge-success {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .badge-primary {
            background: rgba(67, 97, 238, 0.15);
            color: var(--primary);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.3rem;
        }

        .action-btn {
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            text-decoration: none;
            color: white;
            font-size: 0.8rem;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .action-btn.primary { background: var(--primary); }
        .action-btn.success { background: var(--success); }
        .action-btn.warning { background: var(--warning); }
        .action-btn.danger { background: var(--danger); }
        .action-btn.info { background: var(--info); }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
            z-index: 1050;
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
            animation: modalSlideIn 0.3s ease;
            box-shadow: var(--shadow-xl);
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 2px solid var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
        }

        .modal-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--dark);
            font-size: 1.2rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
            transition: var(--transition);
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .modal-close:hover {
            background: var(--light);
            color: var(--danger);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 2px solid var(--light);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-sm);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        .search-group {
            position: relative;
        }

        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-sm);
            max-height: 300px;
            overflow-y: auto;
            z-index: 10;
            display: none;
        }

        .search-item {
            padding: 0.75rem;
            border-bottom: 1px solid var(--light);
            cursor: pointer;
            transition: var(--transition);
        }

        .search-item:hover {
            background: var(--light);
        }

        .search-item:last-child {
            border-bottom: none;
        }

        .info-box {
            background: var(--light);
            padding: 1rem;
            border-radius: var(--border-radius-md);
            margin-bottom: 1rem;
        }

        .selected-return-info {
            font-size: 1rem;
            color: var(--dark);
        }

        .selected-return-info strong {
            color: var(--primary);
            font-size: 1.1rem;
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

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .filter-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filter-tabs {
                flex-wrap: wrap;
            }
            
            .tab {
                flex: 1;
                text-align: center;
            }
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate {
            animation: fadeInUp 0.6s ease-out;
        }

        .stagger-item {
            opacity: 0;
            animation: fadeInUp 0.5s ease-out forwards;
        }

        .stagger-item:nth-child(1) { animation-delay: 0.1s; }
        .stagger-item:nth-child(2) { animation-delay: 0.15s; }
        .stagger-item:nth-child(3) { animation-delay: 0.2s; }
        .stagger-item:nth-child(4) { animation-delay: 0.25s; }
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
                <h1><i class="fas fa-exchange-alt"></i> Library Circulation</h1>
                <p>Manage book issues, returns, renewals, and track circulation history</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-light" onclick="quickIssue()">
                    <i class="fas fa-book-open"></i> Issue Book
                </button>
                <button class="btn btn-light" onclick="quickReturn()">
                    <i class="fas fa-undo-alt"></i> Return Book
                </button>
                <button class="btn btn-light" onclick="exportCirculations()">
                    <i class="fas fa-download"></i> Export
                </button>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card active stagger-item">
                <div class="stat-number"><?php echo number_format($stats['active_issues']); ?></div>
                <div class="stat-label">Active Issues</div>
                <div class="stat-detail"><?php echo $stats['students_with_books']; ?> students</div>
            </div>
            <div class="stat-card overdue stagger-item">
                <div class="stat-number"><?php echo number_format($stats['overdue_issues']); ?></div>
                <div class="stat-label">Overdue Books</div>
                <div class="stat-detail">KES <?php echo number_format($stats['estimated_fines'], 2); ?> estimated fines</div>
            </div>
            <div class="stat-card returned stagger-item">
                <div class="stat-number"><?php echo number_format($stats['returned_books']); ?></div>
                <div class="stat-label">Returned Books</div>
                <div class="stat-detail"><?php echo number_format($stats['today_issues']); ?> issued today</div>
            </div>
            <div class="stat-card fines stagger-item">
                <div class="stat-number">KES <?php echo number_format($stats['estimated_fines'] ?? 0, 2); ?></div>
                <div class="stat-label">Pending Fines</div>
                <div class="stat-detail">From overdue books</div>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="filter-tabs animate">
            <a href="?status=active" class="tab <?php echo $status_filter === 'active' ? 'active' : ''; ?>">
                <i class="fas fa-book-open"></i> Active Issues (<?php echo $stats['active_issues']; ?>)
            </a>
            <a href="?status=overdue" class="tab <?php echo $status_filter === 'overdue' ? 'active' : ''; ?>">
                <i class="fas fa-exclamation-triangle"></i> Overdue (<?php echo $stats['overdue_issues']; ?>)
            </a>
            <a href="?status=returned" class="tab <?php echo $status_filter === 'returned' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i> Returned
            </a>
            <a href="?status=today" class="tab <?php echo $status_filter === 'today' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-day"></i> Today's Issues
            </a>
            <a href="?" class="tab <?php echo empty($status_filter) ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> All
            </a>
        </div>

        <!-- Filter Section -->
        <div class="filter-section animate">
            <div class="filter-header">
                <h3><i class="fas fa-filter"></i> Filter Circulations</h3>
                <span class="badge"><?php echo count($circulations); ?> records</span>
            </div>
            <form method="GET" id="filterForm">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                <div class="filter-grid">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Book, student, admission..." 
                               value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                    <div class="form-group">
                        <label>Class</label>
                        <select name="class_id" class="form-control">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="form-group">
                        <label>To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                    </div>
                    <div class="form-group" style="display: flex; gap: 0.5rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply
                        </button>
                        <a href="circulations.php?status=<?php echo urlencode($status_filter); ?>" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Quick Search -->
        <div class="search-section animate">
            <form method="GET" id="quickSearchForm">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" 
                           placeholder="Quick search by book title, student name, or admission number...">
                    <?php if ($search_query): ?>
                    <a href="?status=<?php echo urlencode($status_filter); ?>" class="clear-search">
                        <i class="fas fa-times"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Circulation Table -->
        <div class="data-card animate">
            <div class="card-header">
                <h3><i class="fas fa-exchange-alt"></i> 
                    <?php 
                    if ($status_filter === 'active') echo 'Active Issues';
                    elseif ($status_filter === 'overdue') echo 'Overdue Books';
                    elseif ($status_filter === 'returned') echo 'Return History';
                    elseif ($status_filter === 'today') echo "Today's Issues";
                    else echo 'All Circulations';
                    ?>
                </h3>
                <span class="badge"><?php echo count($circulations); ?> records</span>
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
                            <th>Return Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($circulations)): ?>
                            <?php foreach($circulations as $circ): 
                                $hasLostReport = !empty($circ['lost_report_id']);
                            ?>
                            <tr class="<?php echo $circ['current_status'] === 'overdue' ? 'overdue-row' : ''; ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($circ['title']); ?></strong>
                                    <br><small><?php echo htmlspecialchars($circ['author']); ?></small>
                                    <?php if ($circ['isbn']): ?>
                                    <br><small style="color: var(--gray);">ISBN: <?php echo $circ['isbn']; ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($circ['full_name']); ?></strong>
                                    <br><small><?php echo htmlspecialchars($circ['admission_number']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($circ['class_name']); ?></td>
                                <td><?php echo date('d M Y', strtotime($circ['issue_date'])); ?></td>
                                <td>
                                    <?php echo date('d M Y', strtotime($circ['due_date'])); ?>
                                    <?php if ($circ['current_status'] === 'overdue'): ?>
                                    <br><span class="badge badge-danger">Overdue <?php echo $circ['days_overdue']; ?> days</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($circ['return_date']): ?>
                                        <?php echo date('d M Y', strtotime($circ['return_date'])); ?>
                                        <?php if ($circ['condition_returned']): ?>
                                        <br><small>Condition: <?php echo $circ['condition_returned']; ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Not Returned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $circ['current_status']; ?>">
                                        <?php echo ucfirst($circ['current_status']); ?>
                                    </span>
                                    <?php if ($hasLostReport): ?>
                                    <br><span class="badge badge-warning">Lost Reported</span>
                                    <br><small style="color: var(--warning); font-weight: 600;">
                                        Workflow: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $circ['lost_report_status']))); ?>
                                    </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if (!$circ['return_date'] && !$hasLostReport): ?>
                                        <button class="action-btn success" onclick="returnBook(<?php echo $circ['id']; ?>)" title="Return Book">
                                            <i class="fas fa-undo-alt"></i> Return
                                        </button>
                                        <?php if ($circ['current_status'] !== 'overdue'): ?>
                                        <button class="action-btn warning" onclick="renewBook(<?php echo $circ['id']; ?>)" title="Renew Book">
                                            <i class="fas fa-sync-alt"></i> Renew
                                        </button>
                                        <?php endif; ?>
                                        <?php elseif ($hasLostReport): ?>
                                        <span class="badge badge-warning">Lost workflow active</span>
                                        <?php endif; ?>
                                        <button class="action-btn primary" onclick="viewDetails(<?php echo $circ['id']; ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="empty-state">
                                    <i class="fas fa-exchange-alt fa-3x"></i>
                                    <h3>No Circulations Found</h3>
                                    <p>No records match your current filters.</p>
                                    <button class="btn btn-primary" onclick="quickIssue()">
                                        <i class="fas fa-book-open"></i> Issue First Book
                                    </button>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Issue Modal -->
    <div id="issueModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-book-open"></i> Issue Book</h3>
                <button class="modal-close" onclick="closeModal('issueModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="issueForm">
                    <div class="form-group">
                        <label class="required">Book</label>
                        <div class="search-group">
                            <input type="text" id="bookSearch" class="form-control" 
                                   placeholder="Search by title, author, or ISBN...">
                            <div id="bookResults" class="search-results"></div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="required">Student</label>
                        <div class="search-group">
                            <input type="text" id="studentSearch" class="form-control" 
                                   placeholder="Search by name or admission number...">
                            <div id="studentResults" class="search-results"></div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="required">Due Date</label>
                        <input type="date" id="issueDueDate" class="form-control" 
                               value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>"
                               min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea id="issueNotes" class="form-control" rows="2" 
                                  placeholder="Optional notes about this issue..."></textarea>
                    </div>
                    
                    <input type="hidden" id="selectedBookId">
                    <input type="hidden" id="selectedStudentId">
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('issueModal')">Cancel</button>
                <button class="btn btn-success" onclick="processIssue()" id="processIssueBtn" disabled>
                    <i class="fas fa-book-open"></i> Issue Book
                </button>
            </div>
        </div>
    </div>

    <!-- Return Modal -->
    <div id="returnModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-undo-alt"></i> Return Book</h3>
                <button class="modal-close" onclick="closeModal('returnModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="returnForm">
                    <div class="form-group">
                        <label>Find Issued Book</label>
                        <div class="search-group">
                            <input type="text" id="returnSearch" class="form-control" 
                                   placeholder="Search by book title or student name...">
                            <div id="returnResults" class="search-results"></div>
                        </div>
                    </div>
                    
                    <div id="returnBookInfo" class="info-box" style="display: none;">
                        <!-- Populated via JavaScript -->
                    </div>
                    
                    <div class="form-group">
                        <label>Condition</label>
                        <select id="returnCondition" class="form-control">
                            <option value="Excellent">Excellent</option>
                            <option value="Good" selected>Good</option>
                            <option value="Fair">Fair</option>
                            <option value="Poor">Poor</option>
                            <option value="Damaged">Damaged</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea id="returnNotes" class="form-control" rows="2" 
                                  placeholder="Any damage or additional notes..."></textarea>
                    </div>
                    
                    <input type="hidden" id="returnIssueId">
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('returnModal')">Cancel</button>
                <button class="btn btn-success" onclick="processReturn()" id="processReturnBtn" disabled>
                    <i class="fas fa-check"></i> Process Return
                </button>
            </div>
        </div>
    </div>

    <!-- Renew Modal -->
    <div id="renewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-sync-alt"></i> Renew Book</h3>
                <button class="modal-close" onclick="closeModal('renewModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="renewForm">
                    <div id="renewBookInfo" class="info-box">
                        <!-- Populated via JavaScript -->
                    </div>
                    
                    <div class="form-group">
                        <label>New Due Date</label>
                        <input type="date" id="renewDueDate" class="form-control" 
                               value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>"
                               min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <input type="hidden" id="renewIssueId">
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('renewModal')">Cancel</button>
                <button class="btn btn-warning" onclick="processRenew()">
                    <i class="fas fa-sync-alt"></i> Renew Book
                </button>
            </div>
        </div>
    </div>

    <!-- View Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-eye"></i> Issue Details</h3>
                <button class="modal-close" onclick="closeModal('detailsModal')">&times;</button>
            </div>
            <div class="modal-body" id="detailsContent">
                <div style="text-align: center; padding: 2rem;">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p>Loading...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('detailsModal')">Close</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Quick check if book ID is passed
        <?php if (isset($_GET['action']) && $_GET['action'] === 'issue' && isset($_GET['book'])): ?>
        document.addEventListener('DOMContentLoaded', function() {
            quickIssue();
            setTimeout(() => {
                selectBookById(<?php echo (int)$_GET['book']; ?>);
            }, 500);
        });
        <?php endif; ?>

        // Modal Functions
        function openModal(id) {
            document.getElementById(id).classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        // Quick Issue
        function quickIssue() {
            resetIssueModal();
            setupBookSearch();
            setupStudentSearch();
            openModal('issueModal');
        }

        // Safe fetch JSON helper
        async function safeFetch(url) {
            try {
                const response = await fetch(url);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Response is not JSON');
                }
                return await response.json();
            } catch (error) {
                console.error('Fetch error:', error);
                throw error;
            }
        }

        function resetIssueModal() {
            document.getElementById('bookSearch').value = '';
            document.getElementById('studentSearch').value = '';
            document.getElementById('issueNotes').value = '';
            document.getElementById('selectedBookId').value = '';
            document.getElementById('selectedStudentId').value = '';
            document.getElementById('processIssueBtn').disabled = true;
            document.getElementById('bookResults').style.display = 'none';
            document.getElementById('studentResults').style.display = 'none';
        }

        // Book Search
        function setupBookSearch() {
            const searchInput = document.getElementById('bookSearch');
            const resultsDiv = document.getElementById('bookResults');
            let timeout;

            searchInput.addEventListener('input', function() {
                clearTimeout(timeout);
                const query = this.value.trim();
                
                if (query.length < 2) {
                    resultsDiv.innerHTML = '';
                    resultsDiv.style.display = 'none';
                    return;
                }

                timeout = setTimeout(() => {
                    safeFetch(`circulations.php?ajax=search_books&query=${encodeURIComponent(query)}`)
                        .then(data => {
                            if (data.success && data.books.length > 0) {
                                resultsDiv.innerHTML = data.books.map(book => `
                                    <div class="search-item" onclick="selectBook(${book.id}, '${escapeHtml(book.title)}', '${escapeHtml(book.author)}', ${book.available_copies})">
                                        <strong>${escapeHtml(book.title)}</strong>
                                        <br><small>by ${escapeHtml(book.author)} | Available: ${book.available_copies}</small>
                                        ${book.category_name ? `<br><small>Category: ${escapeHtml(book.category_name)}</small>` : ''}
                                    </div>
                                `).join('');
                                resultsDiv.style.display = 'block';
                            } else {
                                resultsDiv.innerHTML = '<div class="search-item">No books found</div>';
                                resultsDiv.style.display = 'block';
                            }
                        })
                        .catch(error => {
                            console.error('Book search error:', error);
                            resultsDiv.innerHTML = '<div class="search-item" style="color: #f94144;">Error searching books</div>';
                            resultsDiv.style.display = 'block';
                        });
                }, 300);
            });
        }

        function selectBook(id, title, author, available) {
            if (available <= 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Not Available',
                    text: 'This book is not available for issue'
                });
                return;
            }

            document.getElementById('selectedBookId').value = id;
            document.getElementById('bookSearch').value = `${title} by ${author}`;
            document.getElementById('bookResults').style.display = 'none';
            checkIssueSelections();
        }

        function selectBookById(id) {
            fetch(`circulations.php?ajax=search_books&query=${id}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.books.length > 0) {
                        const book = data.books[0];
                        selectBook(book.id, book.title, book.author, book.available_copies);
                    }
                });
        }

        // Student Search
        function setupStudentSearch() {
            const searchInput = document.getElementById('studentSearch');
            const resultsDiv = document.getElementById('studentResults');
            let timeout;

            searchInput.addEventListener('input', function() {
                clearTimeout(timeout);
                const query = this.value.trim();
                
                if (query.length < 2) {
                    resultsDiv.innerHTML = '';
                    resultsDiv.style.display = 'none';
                    return;
                }

                timeout = setTimeout(() => {
                    fetch(`circulations.php?ajax=search_students&query=${encodeURIComponent(query)}`)
                        .then(r => r.json())
                        .then(data => {
                            if (data.success && data.students.length > 0) {
                                resultsDiv.innerHTML = data.students.map(student => `
                                    <div class="search-item" onclick="selectStudent(${student.id}, '${escapeHtml(student.full_name)}', '${escapeHtml(student.admission_number)}', '${escapeHtml(student.class_name)}', ${student.active_loans})">
                                        <strong>${escapeHtml(student.full_name)}</strong>
                                        <br><small>${escapeHtml(student.admission_number)} | ${escapeHtml(student.class_name)}</small>
                                        <br><small>Active Loans: ${student.active_loans}/5</small>
                                    </div>
                                `).join('');
                                resultsDiv.style.display = 'block';
                            } else {
                                resultsDiv.innerHTML = '<div class="search-item">No students found</div>';
                                resultsDiv.style.display = 'block';
                            }
                        });
                }, 300);
            });
        }

        function selectStudent(id, name, admission, className, activeLoans) {
            if (activeLoans >= 5) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Loan Limit Reached',
                    text: 'This student already has the maximum number of books (5)'
                });
                return;
            }

            document.getElementById('selectedStudentId').value = id;
            document.getElementById('studentSearch').value = `${name} (${admission}) - ${className}`;
            document.getElementById('studentResults').style.display = 'none';
            checkIssueSelections();
        }

        function checkIssueSelections() {
            const bookId = document.getElementById('selectedBookId').value;
            const studentId = document.getElementById('selectedStudentId').value;
            document.getElementById('processIssueBtn').disabled = !(bookId && studentId);
        }

        // Process Issue
        function processIssue() {
            const bookId = document.getElementById('selectedBookId').value;
            const studentId = document.getElementById('selectedStudentId').value;
            const dueDate = document.getElementById('issueDueDate').value;
            const notes = document.getElementById('issueNotes').value;

            if (!bookId || !studentId) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Incomplete',
                    text: 'Please select both book and student'
                });
                return;
            }

            Swal.fire({
                title: 'Processing',
                text: 'Issuing book...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const formData = new FormData();
            formData.append('action', 'issue_book');
            formData.append('book_id', bookId);
            formData.append('student_id', studentId);
            formData.append('due_date', dueDate);
            formData.append('notes', notes);

            fetch('circulations.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: data.message || 'Book issued successfully',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed',
                        text: data.error || 'Could not issue book'
                    });
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'Failed',
                    text: error.message || 'Could not issue book'
                });
            });
        }

        // Quick Return
        function quickReturn() {
            resetReturnModal();
            setupReturnSearch();
            openModal('returnModal');
        }

        function resetReturnModal() {
            document.getElementById('returnSearch').value = '';
            document.getElementById('returnNotes').value = '';
            document.getElementById('returnCondition').value = 'Good';
            document.getElementById('returnIssueId').value = '';
            document.getElementById('returnBookInfo').style.display = 'none';
            document.getElementById('returnResults').style.display = 'none';
            document.getElementById('processReturnBtn').disabled = true;
        }

        function setupReturnSearch() {
            const searchInput = document.getElementById('returnSearch');
            const resultsDiv = document.getElementById('returnResults');
            let timeout;

            searchInput.addEventListener('input', function() {
                clearTimeout(timeout);
                const query = this.value.trim();
                
                if (query.length < 2) {
                    resultsDiv.innerHTML = '';
                    resultsDiv.style.display = 'none';
                    return;
                }

                timeout = setTimeout(() => {
                    fetch(`circulations.php?ajax=search_issued&query=${encodeURIComponent(query)}&filter=active`)
                        .then(r => r.json())
                        .then(data => {
                            if (data.success && data.issues.length > 0) {
                                resultsDiv.innerHTML = data.issues.map(issue => `
                                    <div class="search-item" onclick="selectReturn(${issue.id}, '${escapeHtml(issue.title)}', '${escapeHtml(issue.student_name)}', ${issue.days_overdue || 0})">
                                        <strong>${escapeHtml(issue.title)}</strong>
                                        <br><small>Student: ${escapeHtml(issue.student_name)} (${issue.admission_number})</small>
                                        <br><small>Due: ${issue.due_date}</small>
                                        ${issue.days_overdue > 0 ? `<br><small style="color: #e74c3c;">⚠️ Overdue by ${issue.days_overdue} days</small>` : ''}
                                    </div>
                                `).join('');
                                resultsDiv.style.display = 'block';
                            } else {
                                resultsDiv.innerHTML = '<div class="search-item">No issued books found</div>';
                                resultsDiv.style.display = 'block';
                            }
                        });
                }, 300);
            });
        }

        function selectReturn(id, title, student, overdue) {
            document.getElementById('returnIssueId').value = id;
            
            const infoBox = document.getElementById('returnBookInfo');
            infoBox.innerHTML = `
                <div class="selected-return-info">
                    <strong>${escapeHtml(title)}</strong>
                    <br>Student: ${escapeHtml(student)}
                    ${overdue > 0 ? `<br><span style="color: #e74c3c; font-weight: 600;">⚠️ Overdue by ${overdue} days</span>` : ''}
                </div>
            `;
            infoBox.style.display = 'block';
            
            document.getElementById('returnResults').style.display = 'none';
            document.getElementById('processReturnBtn').disabled = false;
            
            if (overdue > 0) {
                const fine = overdue * 20; // KES 20 per day
                Swal.fire({
                    icon: 'warning',
                    title: 'Overdue Book',
                    html: `This book is <strong>${overdue} days</strong> overdue.<br>Fine amount: <strong>KES ${fine.toFixed(2)}</strong>`,
                    confirmButtonColor: '#4361ee'
                });
            }
        }

        function processReturn() {
            const issueId = document.getElementById('returnIssueId').value;
            const condition = document.getElementById('returnCondition').value;
            const notes = document.getElementById('returnNotes').value;

            if (!issueId) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No Book Selected',
                    text: 'Please select a book to return'
                });
                return;
            }

            Swal.fire({
                title: 'Processing Return',
                text: 'Please wait...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const formData = new FormData();
            formData.append('action', 'return_book');
            formData.append('issue_id', issueId);
            formData.append('condition', condition);
            formData.append('notes', notes);

            fetch('circulations.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    let message = data.message || 'Book returned successfully';
                    if (data.fine_amount > 0) {
                        message += `\nFine amount: KES ${data.fine_amount.toFixed(2)}`;
                    }
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: message,
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed',
                        text: data.error || 'Could not return book'
                    });
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'Failed',
                    text: error.message || 'Could not return book'
                });
            });
        }

        function returnBook(id) {
            quickReturn();
            safeFetch(`circulations.php?ajax=get_issue_details&id=${id}`)
                .then(data => {
                    if (!data.success || !data.issue || data.issue.return_date) {
                        throw new Error(data.error || 'Invalid issue record or already returned');
                    }

                    const issue = data.issue;
                    document.getElementById('returnSearch').value = `${issue.title} - ${issue.full_name}`;
                    selectReturn(issue.id, issue.title, issue.full_name, Number(issue.days_overdue || 0));
                })
                .catch(error => {
                    closeModal('returnModal');
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed',
                        text: error.message || 'Could not load the selected issue'
                    });
                });
        }

        // Renew Book
        function renewBook(id) {
            fetch(`circulations.php?ajax=get_issue_details&id=${id}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const issue = data.issue;
                        document.getElementById('renewIssueId').value = id;
                        document.getElementById('renewBookInfo').innerHTML = `
                            <div class="selected-return-info">
                                <strong>${escapeHtml(issue.title)}</strong>
                                <br>Student: ${escapeHtml(issue.full_name)}
                                <br>Current Due: ${issue.due_date}
                                <br>Renewals: ${issue.renewed_count || 0}/3
                            </div>
                        `;
                        
                        // Calculate new due date (14 days from now)
                        const newDue = new Date();
                        newDue.setDate(newDue.getDate() + 14);
                        document.getElementById('renewDueDate').value = newDue.toISOString().split('T')[0];
                        
                        openModal('renewModal');
                    } else {
                        Swal.fire('Error', 'Could not load issue details', 'error');
                    }
                });
        }

        function processRenew() {
            const issueId = document.getElementById('renewIssueId').value;
            const newDueDate = document.getElementById('renewDueDate').value;

            Swal.fire({
                title: 'Processing Renewal',
                text: 'Please wait...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const formData = new FormData();
            formData.append('action', 'renew_book');
            formData.append('issue_id', issueId);
            formData.append('new_due_date', newDueDate);

            fetch('circulations.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: data.message || 'Book renewed successfully',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed',
                        text: data.error || 'Could not renew book'
                    });
                }
            });
        }

        // View Details
        function viewDetails(id) {
            fetch(`circulations.php?ajax=get_issue_details&id=${id}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const i = data.issue;
                        const content = document.getElementById('detailsContent');
                        content.innerHTML = `
                            <div style="display: grid; gap: 1rem;">
                                <div style="background: var(--light); padding: 1rem; border-radius: var(--border-radius-md);">
                                    <h4 style="margin-bottom: 0.5rem; color: var(--primary);">Book Information</h4>
                                    <p><strong>Title:</strong> ${escapeHtml(i.title)}</p>
                                    <p><strong>Author:</strong> ${escapeHtml(i.author)}</p>
                                    <p><strong>ISBN:</strong> ${escapeHtml(i.isbn || 'N/A')}</p>
                                </div>
                                
                                <div style="background: var(--light); padding: 1rem; border-radius: var(--border-radius-md);">
                                    <h4 style="margin-bottom: 0.5rem; color: var(--primary);">Student Information</h4>
                                    <p><strong>Name:</strong> ${escapeHtml(i.full_name)}</p>
                                    <p><strong>Admission:</strong> ${escapeHtml(i.admission_number)}</p>
                                    <p><strong>Class:</strong> ${escapeHtml(i.class_name)}</p>
                                </div>
                                
                                <div style="background: var(--light); padding: 1rem; border-radius: var(--border-radius-md);">
                                    <h4 style="margin-bottom: 0.5rem; color: var(--primary);">Issue Details</h4>
                                    <p><strong>Issue Date:</strong> ${i.issue_date}</p>
                                    <p><strong>Due Date:</strong> ${i.due_date}</p>
                                    <p><strong>Return Date:</strong> ${i.return_date || 'Not returned'}</p>
                                    <p><strong>Issued By:</strong> ${escapeHtml(i.issued_by_name)}</p>
                                    <p><strong>Condition Returned:</strong> ${i.condition_returned || 'N/A'}</p>
                                    ${i.notes ? `<p><strong>Notes:</strong> ${escapeHtml(i.notes)}</p>` : ''}
                                </div>
                            </div>
                        `;
                        openModal('detailsModal');
                    }
                });
        }

        // Export
        function exportCirculations() {
            window.location.href = 'reports.php?type=circulations&format=csv';
        }

        // Utility
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</body>
</html>
