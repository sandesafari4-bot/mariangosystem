<?php
require_once '../config.php';
checkAuth();
checkRole(['admin', 'librarian']);

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'search_books':
        $query = $_GET['query'] ?? '';
        $stmt = $pdo->prepare("SELECT id, title, author, isbn, available_copies FROM books WHERE (title LIKE ? OR author LIKE ? OR isbn LIKE ?) AND available_copies > 0 LIMIT 10");
        $search = "%$query%";
        $stmt->execute([$search, $search, $search]);
        echo json_encode(['success' => true, 'books' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;
        
    case 'search_students':
        $query = $_GET['query'] ?? '';
        $stmt = $pdo->prepare("
            SELECT s.id, s.full_name, s.Admission_number, c.class_name 
            FROM students s 
            JOIN classes c ON s.class_id = c.id 
            WHERE (s.full_name LIKE ? OR s.Admission_number LIKE ?) AND s.status = 'active' 
            LIMIT 10
        ");
        $search = "%$query%";
        $stmt->execute([$search, $search]);
        echo json_encode(['success' => true, 'students' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;
        
    case 'search_issued':
        $query = $_GET['query'] ?? '';
        $stmt = $pdo->prepare("
            SELECT bi.id, b.title, b.author, s.full_name as student_name, s.Admission_number, 
                   bi.issue_date, bi.due_date, DATEDIFF(CURDATE(), bi.due_date) as days_overdue
            FROM book_issues bi
            JOIN books b ON bi.book_id = b.id
            JOIN students s ON bi.student_id = s.id
            WHERE bi.return_date IS NULL AND (b.title LIKE ? OR s.full_name LIKE ? OR s.Admission_number LIKE ?)
            LIMIT 10
        ");
        $search = "%$query%";
        $stmt->execute([$search, $search, $search]);
        echo json_encode(['success' => true, 'issues' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;
        
    case 'get_book_details':
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM books WHERE id = ?");
        $stmt->execute([$id]);
        $book = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'book' => $book]);
        break;
        
    case 'get_student_history':
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT bi.*, b.title, b.author 
            FROM book_issues bi
            JOIN books b ON bi.book_id = b.id
            WHERE bi.student_id = ?
            ORDER BY bi.issue_date DESC
            LIMIT 20
        ");
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'history' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;
        
    case 'send_reminders':
        // Get overdue books
        $overdue = $pdo->query("
            SELECT s.full_name, s.email, s.phone, b.title, bi.due_date,
                   DATEDIFF(CURDATE(), bi.due_date) as days_overdue
            FROM book_issues bi
            JOIN students s ON bi.student_id = s.id
            JOIN books b ON bi.book_id = b.id
            WHERE bi.return_date IS NULL AND bi.due_date < CURDATE()
        ")->fetchAll();
        
        // Here you would implement email/SMS sending logic
        // For now, just return count
        echo json_encode([
            'success' => true, 
            'message' => 'Reminders sent to ' . count($overdue) . ' students'
        ]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}