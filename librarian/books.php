<?php
require_once '../config.php';
require_once '../library_fines_workflow_helpers.php';
checkAuth();
checkRole(['admin', 'librarian']);

ensureLibraryFineWorkflowSchema($pdo);

// Ensure necessary tables exist
function ensureLibraryTables($pdo) {
    // Book locations table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS book_locations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            code VARCHAR(50) UNIQUE NOT NULL,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            shelf VARCHAR(50),
            floor VARCHAR(50),
            section VARCHAR(100),
            capacity INT DEFAULT 0,
            current_books INT DEFAULT 0,
            is_active BOOLEAN DEFAULT TRUE,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_location (name),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $locationColumns = [
        'code' => "ALTER TABLE book_locations ADD COLUMN code VARCHAR(50) UNIQUE NULL AFTER id",
        'shelf' => "ALTER TABLE book_locations ADD COLUMN shelf VARCHAR(50) NULL AFTER description",
        'floor' => "ALTER TABLE book_locations ADD COLUMN floor VARCHAR(50) NULL AFTER shelf",
        'section' => "ALTER TABLE book_locations ADD COLUMN section VARCHAR(100) NULL AFTER floor",
        'capacity' => "ALTER TABLE book_locations ADD COLUMN capacity INT DEFAULT 0 AFTER section",
        'current_books' => "ALTER TABLE book_locations ADD COLUMN current_books INT DEFAULT 0 AFTER capacity",
        'is_active' => "ALTER TABLE book_locations ADD COLUMN is_active BOOLEAN DEFAULT TRUE AFTER current_books",
        'created_by' => "ALTER TABLE book_locations ADD COLUMN created_by INT NULL AFTER is_active",
        'updated_at' => "ALTER TABLE book_locations ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];
    $existingLocationColumns = $pdo->query("SHOW COLUMNS FROM book_locations")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($locationColumns as $column => $sql) {
        if (!in_array($column, $existingLocationColumns, true)) {
            $pdo->exec($sql);
        }
    }
    if (in_array('code', $pdo->query("SHOW COLUMNS FROM book_locations")->fetchAll(PDO::FETCH_COLUMN), true)) {
        $pdo->exec("UPDATE book_locations SET code = CONCAT('LOC', LPAD(id, 3, '0')) WHERE code IS NULL OR code = ''");
        try {
            $pdo->exec("ALTER TABLE book_locations MODIFY COLUMN code VARCHAR(50) NOT NULL");
        } catch (Exception $e) {}
    }
    try {
        $pdo->exec("CREATE INDEX idx_active ON book_locations (is_active)");
    } catch (Exception $e) {}

    // Book categories table with enhanced fields
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS book_categories (
            id INT PRIMARY KEY AUTO_INCREMENT,
            code VARCHAR(50) UNIQUE NOT NULL,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            parent_id INT NULL,
            icon VARCHAR(50),
            color VARCHAR(20),
            book_count INT DEFAULT 0,
            sort_order INT DEFAULT 0,
            is_active BOOLEAN DEFAULT TRUE,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (parent_id) REFERENCES book_categories(id) ON DELETE CASCADE,
            INDEX idx_category (name),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $categoryColumns = [
        'code' => "ALTER TABLE book_categories ADD COLUMN code VARCHAR(50) UNIQUE NULL AFTER id",
        'parent_id' => "ALTER TABLE book_categories ADD COLUMN parent_id INT NULL AFTER description",
        'icon' => "ALTER TABLE book_categories ADD COLUMN icon VARCHAR(50) NULL AFTER parent_id",
        'color' => "ALTER TABLE book_categories ADD COLUMN color VARCHAR(20) NULL AFTER icon",
        'book_count' => "ALTER TABLE book_categories ADD COLUMN book_count INT DEFAULT 0 AFTER color",
        'sort_order' => "ALTER TABLE book_categories ADD COLUMN sort_order INT DEFAULT 0 AFTER book_count",
        'is_active' => "ALTER TABLE book_categories ADD COLUMN is_active BOOLEAN DEFAULT TRUE AFTER sort_order",
        'created_by' => "ALTER TABLE book_categories ADD COLUMN created_by INT NULL AFTER is_active",
        'updated_at' => "ALTER TABLE book_categories ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];
    $existingCategoryColumns = $pdo->query("SHOW COLUMNS FROM book_categories")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($categoryColumns as $column => $sql) {
        if (!in_array($column, $existingCategoryColumns, true)) {
            $pdo->exec($sql);
        }
    }
    if (in_array('code', $pdo->query("SHOW COLUMNS FROM book_categories")->fetchAll(PDO::FETCH_COLUMN), true)) {
        $pdo->exec("UPDATE book_categories SET code = CONCAT('CAT', LPAD(id, 3, '0')) WHERE code IS NULL OR code = ''");
        try {
            $pdo->exec("ALTER TABLE book_categories MODIFY COLUMN code VARCHAR(50) NOT NULL");
        } catch (Exception $e) {}
    }
    try {
        $pdo->exec("CREATE INDEX idx_active ON book_categories (is_active)");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE book_categories ADD CONSTRAINT fk_book_categories_parent FOREIGN KEY (parent_id) REFERENCES book_categories(id) ON DELETE CASCADE");
    } catch (Exception $e) {}

    // Add location_id and category_id to books table if they don't exist
    $stmt = $pdo->query("SHOW COLUMNS FROM books");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('location_id', $columns)) {
        $pdo->exec("ALTER TABLE books ADD COLUMN location_id INT NULL AFTER location");
        $pdo->exec("ALTER TABLE books ADD FOREIGN KEY (location_id) REFERENCES book_locations(id) ON DELETE SET NULL");
    }

    if (!in_array('category_id', $columns)) {
        $pdo->exec("ALTER TABLE books ADD COLUMN category_id INT NULL AFTER category");
        $pdo->exec("ALTER TABLE books ADD FOREIGN KEY (category_id) REFERENCES book_categories(id) ON DELETE SET NULL");
    }

    if (!in_array('status', $columns)) {
        $pdo->exec("ALTER TABLE books ADD COLUMN status ENUM('available', 'issued', 'reserved', 'damaged', 'lost', 'processing') DEFAULT 'available' AFTER available_copies");
    }

    if (!in_array('cover_image', $columns)) {
        $pdo->exec("ALTER TABLE books ADD COLUMN cover_image VARCHAR(500) NULL AFTER description");
    }

    if (!in_array('tags', $columns)) {
        $pdo->exec("ALTER TABLE books ADD COLUMN tags VARCHAR(500) NULL AFTER cover_image");
    }

    if (!in_array('language', $columns)) {
        $pdo->exec("ALTER TABLE books ADD COLUMN language VARCHAR(50) DEFAULT 'English' AFTER tags");
    }

    if (!in_array('pages', $columns)) {
        $pdo->exec("ALTER TABLE books ADD COLUMN pages INT NULL AFTER language");
    }

    if (!in_array('edition', $columns)) {
        $pdo->exec("ALTER TABLE books ADD COLUMN edition VARCHAR(50) NULL AFTER pages");
    }

    if (!in_array('added_by', $columns)) {
        $pdo->exec("ALTER TABLE books ADD COLUMN added_by INT NULL AFTER edition");
    }

    if (!in_array('last_updated_by', $columns)) {
        $pdo->exec("ALTER TABLE books ADD COLUMN last_updated_by INT NULL AFTER added_by");
    }
}

ensureLibraryTables($pdo);

// Handle AJAX requests
if (isset($_GET['ajax']) && $_GET['ajax'] === 'details' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("
        SELECT b.*, l.name as location_name, c.name as category_name,
               c.code as category_code, l.code as location_code,
               l.shelf, l.floor, l.section,
               u1.full_name as added_by_name, u2.full_name as updated_by_name
        FROM books b
        LEFT JOIN book_locations l ON b.location_id = l.id
        LEFT JOIN book_categories c ON b.category_id = c.id
        LEFT JOIN users u1 ON b.added_by = u1.id
        LEFT JOIN users u2 ON b.last_updated_by = u2.id
        WHERE b.id = ?
    ");
    $stmt->execute([$id]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'book' => $book]);
    exit();
}

// Handle location AJAX
if (isset($_GET['ajax']) && $_GET['ajax'] === 'locations') {
    $stmt = $pdo->query("
        SELECT 
            id,
            COALESCE(code, CONCAT('LOC', LPAD(id, 3, '0'))) as code,
            name,
            COALESCE(section, '') as section,
            COALESCE(shelf, '') as shelf,
            COALESCE(floor, '') as floor,
            COALESCE(capacity, 0) as capacity,
            COALESCE(current_books, 0) as current_books,
            COALESCE(is_active, 1) as is_active,
            COALESCE(description, '') as description
        FROM book_locations
        WHERE COALESCE(is_active, 1) = 1
        ORDER BY name
    ");
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'locations' => $locations]);
    exit();
}

// Handle category AJAX
if (isset($_GET['ajax']) && $_GET['ajax'] === 'categories') {
    $stmt = $pdo->query("
        SELECT 
            c.id,
            COALESCE(c.code, CONCAT('CAT', LPAD(c.id, 3, '0'))) as code,
            c.name,
            COALESCE(c.description, '') as description,
            c.parent_id,
            COALESCE(p.name, '') as parent_name,
            COALESCE(c.icon, 'fa-book') as icon,
            COALESCE(c.color, '#4361ee') as color,
            COALESCE(c.book_count, 0) as book_count,
            COALESCE(c.sort_order, 0) as sort_order,
            COALESCE(c.is_active, 1) as is_active
        FROM book_categories c
        LEFT JOIN book_categories p ON c.parent_id = p.id
        WHERE COALESCE(c.is_active, 1) = 1 
        ORDER BY COALESCE(c.sort_order, 0), c.name
    ");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'categories' => $categories]);
    exit();
}

// Handle location save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_location') {
    $id = (int)($_POST['id'] ?? 0);
    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $shelf = trim($_POST['shelf'] ?? '');
    $floor = trim($_POST['floor'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $capacity = (int)($_POST['capacity'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($code === '' || $name === '') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Location code and name are required']);
        exit();
    }

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE book_locations SET 
                    code = ?, name = ?, description = ?, shelf = ?, 
                    floor = ?, section = ?, capacity = ?, is_active = ?
                WHERE id = ?
            ");
            $ok = $stmt->execute([$code, $name, $description, $shelf, $floor, $section, $capacity, $is_active, $id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO book_locations (code, name, description, shelf, floor, section, capacity, is_active, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ok = $stmt->execute([$code, $name, $description, $shelf, $floor, $section, $capacity, $is_active, $_SESSION['user_id']]);
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => $ok, 'message' => 'Location saved successfully']);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Handle category save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_category') {
    $id = (int)($_POST['id'] ?? 0);
    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    $icon = trim($_POST['icon'] ?? 'fa-book');
    $color = trim($_POST['color'] ?? '#4361ee');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($code === '' || $name === '') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Category code and name are required']);
        exit();
    }

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE book_categories SET 
                    code = ?, name = ?, description = ?, parent_id = ?, 
                    icon = ?, color = ?, sort_order = ?, is_active = ?
                WHERE id = ?
            ");
            $ok = $stmt->execute([$code, $name, $description, $parent_id, $icon, $color, $sort_order, $is_active, $id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO book_categories (code, name, description, parent_id, icon, color, sort_order, is_active, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ok = $stmt->execute([$code, $name, $description, $parent_id, $icon, $color, $sort_order, $is_active, $_SESSION['user_id']]);
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => $ok, 'message' => 'Category saved successfully']);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Handle book save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_book') {
    $id = (int)($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $isbn = trim($_POST['isbn'] ?? '');
    $publisher = trim($_POST['publisher'] ?? '');
    $publication_year = (int)($_POST['publication_year'] ?? 0) ?: null;
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $location_id = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
    $total_copies = max(0, (int)($_POST['total_copies'] ?? 0));
    $available_copies = max(0, (int)($_POST['available_copies'] ?? 0));
    $description = trim($_POST['description'] ?? '');
    $tags = trim($_POST['tags'] ?? '');
    $language = trim($_POST['language'] ?? 'English');
    $pages = !empty($_POST['pages']) ? (int)$_POST['pages'] : null;
    $edition = trim($_POST['edition'] ?? '');
    $cover_image = trim($_POST['cover_image'] ?? '');

    if ($title === '' || $author === '') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Title and author are required']);
        exit();
    }

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE books SET 
                    title = ?, author = ?, isbn = ?, publisher = ?, 
                    publication_year = ?, category_id = ?, location_id = ?,
                    total_copies = ?, available_copies = ?, description = ?,
                    tags = ?, language = ?, pages = ?, edition = ?, cover_image = ?,
                    last_updated_by = ?
                WHERE id = ?
            ");
            $ok = $stmt->execute([
                $title, $author, $isbn, $publisher, $publication_year,
                $category_id, $location_id, $total_copies, $available_copies,
                $description, $tags, $language, $pages, $edition, $cover_image,
                $_SESSION['user_id'], $id
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO books (
                    title, author, isbn, publisher, publication_year,
                    category_id, location_id, total_copies, available_copies,
                    description, tags, language, pages, edition, cover_image,
                    added_by, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available')
            ");
            $ok = $stmt->execute([
                $title, $author, $isbn, $publisher, $publication_year,
                $category_id, $location_id, $total_copies, $total_copies,
                $description, $tags, $language, $pages, $edition, $cover_image,
                $_SESSION['user_id']
            ]);
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => $ok, 'message' => $id > 0 ? 'Book updated successfully' : 'Book added successfully']);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Handle book deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_book') {
    $id = (int)($_POST['id'] ?? 0);

    try {
        // Check if book has active loans
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM book_loans WHERE book_id = ? AND return_date IS NULL");
        $stmt->execute([$id]);
        $active_loans = $stmt->fetchColumn();

        if ($active_loans > 0) {
            throw new Exception("Cannot delete book with active loans");
        }

        $stmt = $pdo->prepare("DELETE FROM books WHERE id = ?");
        $ok = $stmt->execute([$id]);

        header('Content-Type: application/json');
        echo json_encode(['success' => $ok, 'message' => 'Book deleted successfully']);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Handle location deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_location') {
    $id = (int)($_POST['id'] ?? 0);

    try {
        // Check if location has books
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM books WHERE location_id = ?");
        $stmt->execute([$id]);
        $book_count = $stmt->fetchColumn();

        if ($book_count > 0) {
            throw new Exception("Cannot delete location with $book_count books assigned. Move books first.");
        }

        $stmt = $pdo->prepare("DELETE FROM book_locations WHERE id = ?");
        $ok = $stmt->execute([$id]);

        header('Content-Type: application/json');
        echo json_encode(['success' => $ok, 'message' => 'Location deleted successfully']);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Handle category deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_category') {
    $id = (int)($_POST['id'] ?? 0);

    try {
        // Check if category has books
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM books WHERE category_id = ?");
        $stmt->execute([$id]);
        $book_count = $stmt->fetchColumn();

        if ($book_count > 0) {
            throw new Exception("Cannot delete category with $book_count books assigned");
        }

        // Check if category has subcategories
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM book_categories WHERE parent_id = ?");
        $stmt->execute([$id]);
        $sub_count = $stmt->fetchColumn();

        if ($sub_count > 0) {
            throw new Exception("Cannot delete category with $sub_count subcategories");
        }

        $stmt = $pdo->prepare("DELETE FROM book_categories WHERE id = ?");
        $ok = $stmt->execute([$id]);

        header('Content-Type: application/json');
        echo json_encode(['success' => $ok, 'message' => 'Category deleted successfully']);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_book'])) {
    $title = $_POST['title'];
    $author = $_POST['author'];
    $isbn = $_POST['isbn'];
    $publisher = $_POST['publisher'];
    $publication_year = $_POST['publication_year'];
    $category_id = $_POST['category_id'];
    $location_id = $_POST['location_id'];
    $copies = $_POST['copies'];
    $description = $_POST['description'];
    $tags = $_POST['tags'];
    $language = $_POST['language'];
    $pages = $_POST['pages'];
    $edition = $_POST['edition'];

    try {
        $stmt = $pdo->prepare("
            INSERT INTO books (
                title, author, isbn, publisher, publication_year,
                category_id, location_id, total_copies, available_copies,
                description, tags, language, pages, edition, added_by, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available')
        ");
        
        if ($stmt->execute([$title, $author, $isbn, $publisher, $publication_year,
            $category_id, $location_id, $copies, $copies, $description,
            $tags, $language, $pages, $edition, $_SESSION['user_id']])) {
            $_SESSION['success'] = "Book added successfully!";
        } else {
            $_SESSION['error'] = "Failed to add book.";
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    header("Location: books.php");
    exit();
}

// Get filter parameters
$selected_category = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$selected_location = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;
$selected_status = $_GET['status'] ?? '';
$search_query = $_GET['search'] ?? '';

// Build query
$query = "
    SELECT b.*, l.name as location_name, c.name as category_name,
           l.code as location_code, c.code as category_code,
           u1.full_name as added_by_name,
           COUNT(DISTINCT CASE
               WHEN lb.status IN ('reported', 'pending', 'submitted_for_approval', 'approved', 'verified', 'sent_to_accountant', 'invoiced', 'paid')
               THEN lb.id
           END) as active_lost_reports
    FROM books b
    LEFT JOIN book_locations l ON b.location_id = l.id
    LEFT JOIN book_categories c ON b.category_id = c.id
    LEFT JOIN users u1 ON b.added_by = u1.id
    LEFT JOIN lost_books lb ON lb.book_id = b.id
    WHERE 1=1
";
$params = [];

if ($selected_category > 0) {
    $query .= " AND b.category_id = ?";
    $params[] = $selected_category;
}

if ($selected_location > 0) {
    $query .= " AND b.location_id = ?";
    $params[] = $selected_location;
}

if ($selected_status == 'available') {
    $query .= " AND b.available_copies > 0";
} elseif ($selected_status == 'unavailable') {
    $query .= " AND b.available_copies = 0";
} elseif ($selected_status == 'damaged') {
    $query .= " AND b.status = 'damaged'";
} elseif ($selected_status == 'lost') {
    $query .= " AND b.status = 'lost'";
}

if ($search_query) {
    $query .= " AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ? OR b.tags LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

$query .= " GROUP BY b.id ORDER BY b.title";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$books = $stmt->fetchAll();

// Get all categories with hierarchy
$categories = $pdo->query("
    SELECT c.*, 
           COALESCE(c.code, CONCAT('CAT', LPAD(c.id, 3, '0'))) as code,
           COALESCE(c.icon, 'fa-book') as icon,
           COALESCE(c.color, '#4361ee') as color,
           COALESCE(c.sort_order, 0) as sort_order,
           COALESCE(c.is_active, 1) as is_active,
           p.name as parent_name 
    FROM book_categories c
    LEFT JOIN book_categories p ON c.parent_id = p.id
    WHERE COALESCE(c.is_active, 1) = 1
    ORDER BY COALESCE(c.sort_order, 0), c.name
")->fetchAll();

// Get all locations
$locations = $pdo->query("
    SELECT *,
           COALESCE(code, CONCAT('LOC', LPAD(id, 3, '0'))) as code,
           COALESCE(is_active, 1) as is_active
    FROM book_locations 
    WHERE COALESCE(is_active, 1) = 1 
    ORDER BY name
")->fetchAll();

// Get statistics
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total_books,
        SUM(total_copies) as total_copies,
        SUM(available_copies) as available_copies,
        COUNT(CASE WHEN available_copies > 0 THEN 1 END) as available_titles,
        COUNT(CASE WHEN status = 'damaged' THEN 1 END) as damaged_books,
        COUNT(CASE WHEN status = 'lost' THEN 1 END) as lost_books
    FROM books
")->fetch();

$page_title = "Book Management - " . SCHOOL_NAME;
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
            --gradient-library: linear-gradient(135deg, #7209b7 0%, #9b59b6 100%);
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
            background: var(--gradient-library);
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

        .stat-card.total { border-left-color: var(--primary); }
        .stat-card.available { border-left-color: var(--success); }
        .stat-card.damaged { border-left-color: var(--warning); }
        .stat-card.lost { border-left-color: var(--danger); }

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

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .action-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.2rem;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            border: 1px solid var(--light);
            text-decoration: none;
            color: var(--dark);
            display: block;
        }

        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary);
        }

        .action-icon {
            width: 48px;
            height: 48px;
            background: var(--gradient-1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            color: white;
            font-size: 1.2rem;
        }

        .action-title {
            font-weight: 600;
            font-size: 0.9rem;
        }

        /* Tabs */
        .tabs {
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

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
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

        /* Data Table */
        .data-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            margin-bottom: 2rem;
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

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-available {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .status-issued {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        .status-reserved {
            background: rgba(114, 9, 183, 0.15);
            color: var(--purple);
        }

        .status-damaged {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        .status-lost {
            background: rgba(0, 0, 0, 0.15);
            color: var(--dark);
        }

        .status-processing {
            background: rgba(108, 117, 125, 0.15);
            color: var(--gray);
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
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease;
            box-shadow: var(--shadow-xl);
        }

        .modal-content.book-modal {
            max-width: 980px;
            border: 1px solid rgba(114, 9, 183, 0.12);
            background:
                linear-gradient(180deg, rgba(114, 9, 183, 0.04) 0%, rgba(255, 255, 255, 0) 22%),
                var(--white);
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
            position: sticky;
            top: 0;
            background: white;
            z-index: 1;
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

        .modal-intro {
            background: linear-gradient(135deg, rgba(114, 9, 183, 0.08) 0%, rgba(67, 97, 238, 0.08) 100%);
            border: 1px solid rgba(67, 97, 238, 0.12);
            border-radius: var(--border-radius-lg);
            padding: 1rem 1.1rem;
            margin-bottom: 1.25rem;
        }

        .modal-intro h4 {
            margin: 0 0 0.35rem;
            color: var(--dark);
            font-size: 1rem;
        }

        .modal-intro p {
            margin: 0;
            color: var(--gray);
            font-size: 0.92rem;
            line-height: 1.5;
        }

        .form-section {
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: var(--border-radius-lg);
            padding: 1rem 1rem 0.25rem;
            background: #fff;
            box-shadow: var(--shadow-sm);
        }

        .form-section + .form-section {
            margin-top: 1rem;
        }

        .form-section-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            color: var(--dark);
            font-weight: 700;
            font-size: 0.98rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 2px solid var(--light);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            position: sticky;
            bottom: 0;
            background: white;
        }

        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
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

        .select-with-icon {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .color-preview {
            width: 30px;
            height: 30px;
            border-radius: 6px;
            border: 2px solid var(--light);
        }

        /* Icon Grid */
        .icon-grid {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 0.5rem;
            max-height: 200px;
            overflow-y: auto;
            padding: 0.5rem;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-sm);
        }

        .icon-option {
            width: 40px;
            height: 40px;
            border-radius: var(--border-radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 2px solid transparent;
            transition: var(--transition);
        }

        .icon-option:hover {
            background: var(--light);
            border-color: var(--primary);
        }

        .icon-option.selected {
            background: var(--primary-100);
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Alert */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius-md);
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            animation: slideIn 0.3s ease;
            border-left: 4px solid;
        }

        .alert-success {
            background: rgba(76, 201, 240, 0.1);
            border-color: var(--success);
            color: var(--success);
        }

        .alert-danger {
            background: rgba(249, 65, 68, 0.1);
            border-color: var(--danger);
            color: var(--danger);
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
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
            
            .form-grid {
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
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .tabs {
                flex-wrap: wrap;
            }
            
            .icon-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .modal-content.book-modal {
                width: 94%;
                max-width: none;
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
                <h1><i class="fas fa-book"></i> Library Management System</h1>
                <p>Manage books, locations, categories, and track library inventory</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-light" onclick="openAddBookModal()">
                    <i class="fas fa-plus"></i> Add Book
                </button>
                <button class="btn btn-light" onclick="openManageLocations()">
                    <i class="fas fa-location-dot"></i> Manage Locations
                </button>
                <button class="btn btn-light" onclick="openManageCategories()">
                    <i class="fas fa-tags"></i> Manage Categories
                </button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success animate">
            <div>
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; ?>
            </div>
            <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: inherit; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger animate">
            <div>
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; ?>
            </div>
            <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: inherit; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['error']); endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card total stagger-item">
                <div class="stat-number"><?php echo number_format($stats['total_books']); ?></div>
                <div class="stat-label">Total Titles</div>
                <div class="stat-detail"><?php echo number_format($stats['total_copies']); ?> total copies</div>
            </div>
            <div class="stat-card available stagger-item">
                <div class="stat-number"><?php echo number_format($stats['available_copies']); ?></div>
                <div class="stat-label">Available Copies</div>
                <div class="stat-detail"><?php echo number_format($stats['available_titles']); ?> titles available</div>
            </div>
            <div class="stat-card damaged stagger-item">
                <div class="stat-number"><?php echo number_format($stats['damaged_books']); ?></div>
                <div class="stat-label">Damaged Books</div>
            </div>
            <div class="stat-card lost stagger-item">
                <div class="stat-number"><?php echo number_format($stats['lost_books']); ?></div>
                <div class="stat-label">Lost Books</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions animate">
            <div class="action-card" onclick="openAddBookModal()">
                <div class="action-icon"><i class="fas fa-plus"></i></div>
                <div class="action-title">Add Book</div>
            </div>
            <div class="action-card" onclick="window.location.href='circulations.php'">
                <div class="action-icon"><i class="fas fa-exchange-alt"></i></div>
                <div class="action-title">Circulations</div>
            </div>
            <div class="action-card" onclick="window.location.href='fines.php'">
                <div class="action-icon"><i class="fas fa-coins"></i></div>
                <div class="action-title">Fines</div>
            </div>
            <div class="action-card" onclick="openLocationModal()">
                <div class="action-icon"><i class="fas fa-location-dot"></i></div>
                <div class="action-title">Locations</div>
            </div>
            <div class="action-card" onclick="openCategoryModal()">
                <div class="action-icon"><i class="fas fa-tags"></i></div>
                <div class="action-title">Categories</div>
            </div>
            <div class="action-card" onclick="window.location.href='reports.php?type=library'">
                <div class="action-icon"><i class="fas fa-chart-bar"></i></div>
                <div class="action-title">Reports</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs animate">
            <div class="tab active" onclick="switchTab('books')">Books Collection</div>
            <div class="tab" onclick="switchTab('locations')">Locations</div>
            <div class="tab" onclick="switchTab('categories')">Categories</div>
        </div>

        <!-- Books Tab -->
        <div id="booksTab" class="tab-content active">
            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-header">
                    <h3><i class="fas fa-filter"></i> Filter Books</h3>
                    <span class="badge"><?php echo count($books); ?> books found</span>
                </div>
                <form method="GET" id="filterForm">
                    <div class="filter-grid">
                        <div class="form-group">
                            <label>Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Title, author, ISBN, tags..." 
                                   value="<?php echo htmlspecialchars($search_query); ?>">
                        </div>
                        <div class="form-group">
                            <label>Category</label>
                            <select name="category_id" class="form-control">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo $selected_category == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo str_repeat('— ', $cat['parent_id'] ? 1 : 0) . htmlspecialchars($cat['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Location</label>
                            <select name="location_id" class="form-control">
                                <option value="">All Locations</option>
                                <?php foreach ($locations as $loc): ?>
                                <option value="<?php echo $loc['id']; ?>" <?php echo $selected_location == $loc['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($loc['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <option value="">All Status</option>
                                <option value="available" <?php echo $selected_status == 'available' ? 'selected' : ''; ?>>Available</option>
                                <option value="unavailable" <?php echo $selected_status == 'unavailable' ? 'selected' : ''; ?>>Unavailable</option>
                                <option value="damaged" <?php echo $selected_status == 'damaged' ? 'selected' : ''; ?>>Damaged</option>
                                <option value="lost" <?php echo $selected_status == 'lost' ? 'selected' : ''; ?>>Lost</option>
                            </select>
                        </div>
                        <div class="form-group" style="display: flex; gap: 0.5rem;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Filter
                            </button>
                            <a href="books.php" class="btn btn-outline">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Books Table -->
            <div class="data-card">
                <div class="card-header">
                    <h3><i class="fas fa-book"></i> Books Collection</h3>
                    <div class="btn-group">
                        <button class="btn btn-sm btn-light" onclick="exportBooks()">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Author</th>
                                <th>ISBN</th>
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
                                <?php foreach ($books as $book): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($book['title']); ?></strong>
                                        <?php if ($book['edition']): ?>
                                        <br><small><?php echo htmlspecialchars($book['edition']); ?> ed.</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($book['author']); ?></td>
                                    <td><?php echo htmlspecialchars($book['isbn']); ?></td>
                                    <td>
                                        <?php if ($book['category_name']): ?>
                                        <span class="badge" style="background: <?php echo $book['category_color'] ?? 'var(--primary)'; ?>20; color: <?php echo $book['category_color'] ?? 'var(--primary)'; ?>;">
                                            <i class="fas <?php echo $book['category_icon'] ?? 'fa-book'; ?>"></i>
                                            <?php echo htmlspecialchars($book['category_name']); ?>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($book['location_name']): ?>
                                        <span class="badge" style="background: var(--info-light); color: var(--info);">
                                            <i class="fas fa-location-dot"></i>
                                            <?php echo htmlspecialchars($book['location_name']); ?>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $book['total_copies']; ?></td>
                                    <td><?php echo $book['available_copies']; ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $book['status']; ?>">
                                            <?php echo ucfirst($book['status']); ?>
                                        </span>
                                        <?php if (!empty($book['active_lost_reports'])): ?>
                                        <br><small style="color: var(--warning); font-weight: 600;">
                                            Lost reported: <?php echo (int) $book['active_lost_reports']; ?>
                                        </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn primary" onclick="viewBook(<?php echo $book['id']; ?>)" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="action-btn warning" onclick="editBook(<?php echo $book['id']; ?>)" title="Edit Book">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($book['available_copies'] > 0): ?>
                                            <button class="action-btn success" onclick="quickIssueBook(<?php echo $book['id']; ?>)" title="Issue Book">
                                                <i class="fas fa-book-open"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if (!empty($book['active_lost_reports'])): ?>
                                            <span class="badge" style="background: rgba(248, 150, 30, 0.15); color: var(--warning);">
                                                <i class="fas fa-triangle-exclamation"></i>
                                                Lost workflow active
                                            </span>
                                            <?php endif; ?>
                                            <button class="action-btn danger" onclick="deleteBook(<?php echo $book['id']; ?>, '<?php echo htmlspecialchars(addslashes($book['title'])); ?>')" title="Delete Book">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 3rem;">
                                        <i class="fas fa-book-open fa-3x" style="color: var(--gray); margin-bottom: 1rem;"></i>
                                        <h3>No Books Found</h3>
                                        <p style="color: var(--gray);">No books match your search criteria.</p>
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
        </div>

        <!-- Locations Tab -->
        <div id="locationsTab" class="tab-content">
            <div class="filter-section">
                <div class="filter-header">
                    <h3><i class="fas fa-location-dot"></i> Book Locations</h3>
                    <button class="btn btn-primary btn-sm" onclick="openAddLocationModal()">
                        <i class="fas fa-plus"></i> Add Location
                    </button>
                </div>
            </div>

            <div class="data-card">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Section</th>
                                <th>Shelf</th>
                                <th>Floor</th>
                                <th>Capacity</th>
                                <th>Current Books</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="locationsTableBody">
                            <!-- Populated via AJAX -->
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 2rem;">
                                    <i class="fas fa-spinner fa-spin"></i> Loading locations...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Categories Tab -->
        <div id="categoriesTab" class="tab-content">
            <div class="filter-section">
                <div class="filter-header">
                    <h3><i class="fas fa-tags"></i> Book Categories</h3>
                    <button class="btn btn-primary btn-sm" onclick="openAddCategoryModal()">
                        <i class="fas fa-plus"></i> Add Category
                    </button>
                </div>
            </div>

            <div class="data-card">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Icon</th>
                                <th>Name</th>
                                <th>Parent</th>
                                <th>Description</th>
                                <th>Books</th>
                                <th>Sort</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="categoriesTableBody">
                            <!-- Populated via AJAX -->
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 2rem;">
                                    <i class="fas fa-spinner fa-spin"></i> Loading categories...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Book Modal -->
    <div id="bookModal" class="modal">
        <div class="modal-content book-modal">
            <div class="modal-header">
                <h3 id="bookModalTitle"><i class="fas fa-book"></i> Add New Book</h3>
                <button class="modal-close" onclick="closeModal('bookModal')">&times;</button>
            </div>
            <form id="bookForm" onsubmit="return saveBook(event)">
                <input type="hidden" name="id" id="bookId" value="0">
                <input type="hidden" name="ajax_action" value="save_book">
                
                <div class="modal-body">
                    <div class="modal-intro">
                        <h4>Book Profile</h4>
                        <p>Capture the book details, place it in the right category and location, and keep copy counts accurate for circulation.</p>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-pen-nib"></i>
                            <span>Bibliographic Details</span>
                        </div>
                        <div class="form-grid">
                        <div class="form-group full-width">
                            <label class="required">Title</label>
                            <input type="text" name="title" id="bookTitle" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Author</label>
                            <input type="text" name="author" id="bookAuthor" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label>ISBN</label>
                            <input type="text" name="isbn" id="bookIsbn" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label>Publisher</label>
                            <input type="text" name="publisher" id="bookPublisher" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label>Publication Year</label>
                            <input type="number" name="publication_year" id="bookYear" class="form-control" min="1000" max="<?php echo date('Y'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Category</label>
                            <select name="category_id" id="bookCategory" class="form-control">
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>">
                                    <?php echo str_repeat('— ', $cat['parent_id'] ? 1 : 0) . htmlspecialchars($cat['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Location</label>
                            <select name="location_id" id="bookLocation" class="form-control">
                                <option value="">Select Location</option>
                                <?php foreach ($locations as $loc): ?>
                                <option value="<?php echo $loc['id']; ?>">
                                    <?php echo htmlspecialchars($loc['name']); ?> (<?php echo $loc['code']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Total Copies</label>
                            <input type="number" name="total_copies" id="bookTotalCopies" class="form-control" min="0" value="1">
                        </div>
                        
                        <div class="form-group">
                            <label>Available Copies</label>
                            <input type="number" name="available_copies" id="bookAvailableCopies" class="form-control" min="0" value="1">
                        </div>
                        
                        <div class="form-group">
                            <label>Language</label>
                            <input type="text" name="language" id="bookLanguage" class="form-control" value="English">
                        </div>
                        
                        <div class="form-group">
                            <label>Pages</label>
                            <input type="number" name="pages" id="bookPages" class="form-control" min="1">
                        </div>
                        
                        <div class="form-group">
                            <label>Edition</label>
                            <input type="text" name="edition" id="bookEdition" class="form-control" placeholder="e.g., 2nd Edition">
                        </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-layer-group"></i>
                            <span>Organization & Stock</span>
                        </div>
                        <div class="form-grid">
                        <div class="form-group full-width">
                            <label>Tags (comma separated)</label>
                            <input type="text" name="tags" id="bookTags" class="form-control" placeholder="fiction, novel, classic">
                        </div>
                        
                        <div class="form-group full-width">
                            <label>Cover Image URL</label>
                            <input type="url" name="cover_image" id="bookCover" class="form-control" placeholder="https://...">
                        </div>
                        
                        <div class="form-group full-width">
                            <label>Description</label>
                            <textarea name="description" id="bookDescription" class="form-control" rows="3"></textarea>
                        </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('bookModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Book
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Location Modal -->
    <div id="locationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="locationModalTitle"><i class="fas fa-location-dot"></i> Add Location</h3>
                <button class="modal-close" onclick="closeModal('locationModal')">&times;</button>
            </div>
            <form id="locationForm" onsubmit="return saveLocation(event)">
                <input type="hidden" name="id" id="locationId" value="0">
                <input type="hidden" name="ajax_action" value="save_location">
                
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="required">Location Code</label>
                            <input type="text" name="code" id="locationCode" class="form-control" required 
                                   placeholder="e.g., SEC-A-01">
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Location Name</label>
                            <input type="text" name="name" id="locationName" class="form-control" required 
                                   placeholder="e.g., Section A, Shelf 1">
                        </div>
                        
                        <div class="form-group">
                            <label>Section</label>
                            <input type="text" name="section" id="locationSection" class="form-control" 
                                   placeholder="e.g., Fiction Section">
                        </div>
                        
                        <div class="form-group">
                            <label>Shelf</label>
                            <input type="text" name="shelf" id="locationShelf" class="form-control" 
                                   placeholder="e.g., Shelf A-12">
                        </div>
                        
                        <div class="form-group">
                            <label>Floor</label>
                            <input type="text" name="floor" id="locationFloor" class="form-control" 
                                   placeholder="e.g., 2nd Floor">
                        </div>
                        
                        <div class="form-group">
                            <label>Capacity</label>
                            <input type="number" name="capacity" id="locationCapacity" class="form-control" min="0" value="0">
                        </div>
                        
                        <div class="form-group full-width">
                            <label>Description</label>
                            <textarea name="description" id="locationDescription" class="form-control" rows="2"></textarea>
                        </div>
                        
                        <div class="form-group full-width">
                            <label style="display: flex; align-items: center; gap: 0.5rem;">
                                <input type="checkbox" name="is_active" id="locationActive" value="1" checked>
                                <span>Active Location</span>
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('locationModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Location
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Category Modal -->
    <div id="categoryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="categoryModalTitle"><i class="fas fa-tags"></i> Add Category</h3>
                <button class="modal-close" onclick="closeModal('categoryModal')">&times;</button>
            </div>
            <form id="categoryForm" onsubmit="return saveCategory(event)">
                <input type="hidden" name="id" id="categoryId" value="0">
                <input type="hidden" name="ajax_action" value="save_category">
                
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="required">Category Code</label>
                            <input type="text" name="code" id="categoryCode" class="form-control" required 
                                   placeholder="e.g., FIC">
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Category Name</label>
                            <input type="text" name="name" id="categoryName" class="form-control" required 
                                   placeholder="e.g., Fiction">
                        </div>
                        
                        <div class="form-group">
                            <label>Parent Category</label>
                            <select name="parent_id" id="categoryParent" class="form-control">
                                <option value="">No Parent (Top Level)</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>">
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Icon</label>
                            <div class="select-with-icon">
                                <select name="icon" id="categoryIcon" class="form-control">
                                    <option value="fa-book">📚 Book</option>
                                    <option value="fa-book-open">📖 Open Book</option>
                                    <option value="fa-graduation-cap">🎓 Graduation</option>
                                    <option value="fa-flask">🧪 Science</option>
                                    <option value="fa-calculator">📊 Math</option>
                                    <option value="fa-globe">🌍 Geography</option>
                                    <option value="fa-history">📜 History</option>
                                    <option value="fa-language">🗣️ Language</option>
                                    <option value="fa-music">🎵 Music</option>
                                    <option value="fa-palette">🎨 Art</option>
                                </select>
                                <div id="iconPreview" class="color-preview" style="background: var(--primary); display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-book" style="color: white;"></i>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Color</label>
                            <div class="select-with-icon">
                                <input type="color" name="color" id="categoryColor" class="form-control" value="#4361ee" style="height: 42px;">
                                <div id="colorPreview" class="color-preview" style="background: #4361ee;"></div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Sort Order</label>
                            <input type="number" name="sort_order" id="categorySort" class="form-control" value="0" min="0">
                        </div>
                        
                        <div class="form-group full-width">
                            <label>Description</label>
                            <textarea name="description" id="categoryDescription" class="form-control" rows="2"></textarea>
                        </div>
                        
                        <div class="form-group full-width">
                            <label style="display: flex; align-items: center; gap: 0.5rem;">
                                <input type="checkbox" name="is_active" id="categoryActive" value="1" checked>
                                <span>Active Category</span>
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('categoryModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Category
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Book Modal -->
    <div id="viewBookModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-eye"></i> Book Details</h3>
                <button class="modal-close" onclick="closeModal('viewBookModal')">&times;</button>
            </div>
            <div class="modal-body" id="viewBookContent">
                <div style="text-align: center; padding: 2rem;">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p>Loading...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('viewBookModal')">Close</button>
                <button class="btn btn-primary" id="viewToEditBtn">Edit Book</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Tab switching
        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            if (tabName === 'books') {
                document.querySelectorAll('.tab')[0].classList.add('active');
                document.getElementById('booksTab').classList.add('active');
            } else if (tabName === 'locations') {
                document.querySelectorAll('.tab')[1].classList.add('active');
                document.getElementById('locationsTab').classList.add('active');
                loadLocations();
            } else if (tabName === 'categories') {
                document.querySelectorAll('.tab')[2].classList.add('active');
                document.getElementById('categoriesTab').classList.add('active');
                loadCategories();
            }
        }

        function openManageLocations() {
            switchTab('locations');
            loadLocations();
        }

        function openManageCategories() {
            switchTab('categories');
            loadCategories();
        }

        function populateBookLocationOptions(locations, selectedValue = '') {
            const select = document.getElementById('bookLocation');
            if (!select) return;
            let html = '<option value="">Select Location</option>';
            locations.forEach(loc => {
                const selected = String(selectedValue) === String(loc.id) ? 'selected' : '';
                html += `<option value="${loc.id}" ${selected}>${escapeHtml(loc.name)} (${escapeHtml(loc.code || '')})</option>`;
            });
            select.innerHTML = html;
        }

        function populateBookCategoryOptions(categories, selectedValue = '') {
            const select = document.getElementById('bookCategory');
            const parentSelect = document.getElementById('categoryParent');
            let html = '<option value="">Select Category</option>';
            let parentHtml = '<option value="">No Parent Category</option>';
            categories.forEach(cat => {
                const selected = String(selectedValue) === String(cat.id) ? 'selected' : '';
                const label = `${cat.parent_id ? '— ' : ''}${escapeHtml(cat.name)}`;
                html += `<option value="${cat.id}" ${selected}>${label}</option>`;
                parentHtml += `<option value="${cat.id}">${label}</option>`;
            });
            if (select) select.innerHTML = html;
            if (parentSelect) parentSelect.innerHTML = parentHtml;
        }

        // Load locations
        function loadLocations() {
            document.getElementById('locationsTableBody').innerHTML = `
                <tr>
                    <td colspan="9" style="text-align: center; padding: 2rem;">
                        <i class="fas fa-spinner fa-spin"></i> Loading locations...
                    </td>
                </tr>
            `;
            fetch('books.php?ajax=locations')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        populateBookLocationOptions(data.locations || []);
                        let html = '';
                        (data.locations || []).forEach(loc => {
                            html += `
                                <tr>
                                    <td><strong>${escapeHtml(loc.code)}</strong></td>
                                    <td>${escapeHtml(loc.name)}</td>
                                    <td>${escapeHtml(loc.section || '-')}</td>
                                    <td>${escapeHtml(loc.shelf || '-')}</td>
                                    <td>${escapeHtml(loc.floor || '-')}</td>
                                    <td>${loc.capacity || 0}</td>
                                    <td>${loc.current_books || 0}</td>
                                    <td>
                                        <span class="status-badge status-${loc.is_active ? 'available' : 'damaged'}">
                                            ${loc.is_active ? 'Active' : 'Inactive'}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn warning" onclick="editLocation(${loc.id})" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="action-btn danger" onclick="deleteLocation(${loc.id}, '${escapeHtml(loc.name)}')" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            `;
                        });
                        if (!html) {
                            html = `
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 2rem; color: var(--gray);">
                                        <i class="fas fa-location-dot" style="margin-bottom: 0.5rem;"></i>
                                        <div>No active locations found</div>
                                    </td>
                                </tr>
                            `;
                        }
                        document.getElementById('locationsTableBody').innerHTML = html;
                    }
                })
                .catch(() => {
                    document.getElementById('locationsTableBody').innerHTML = `
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 2rem; color: var(--danger);">
                                Failed to load locations
                            </td>
                        </tr>
                    `;
                });
        }

        // Load categories
        function loadCategories() {
            document.getElementById('categoriesTableBody').innerHTML = `
                <tr>
                    <td colspan="9" style="text-align: center; padding: 2rem;">
                        <i class="fas fa-spinner fa-spin"></i> Loading categories...
                    </td>
                </tr>
            `;
            fetch('books.php?ajax=categories')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        populateBookCategoryOptions(data.categories || []);
                        let html = '';
                        (data.categories || []).forEach(cat => {
                            html += `
                                <tr>
                                    <td><strong>${escapeHtml(cat.code)}</strong></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                            <div style="width: 30px; height: 30px; background: ${cat.color}; border-radius: 6px; display: flex; align-items: center; justify-content: center;">
                                                <i class="fas ${cat.icon}" style="color: white;"></i>
                                            </div>
                                        </div>
                                    </td>
                                    <td>${escapeHtml(cat.name)}</td>
                                    <td>${escapeHtml(cat.parent_name || '-')}</td>
                                    <td>${escapeHtml(cat.description || '-')}</td>
                                    <td>${cat.book_count || 0}</td>
                                    <td>${cat.sort_order}</td>
                                    <td>
                                        <span class="status-badge status-${cat.is_active ? 'available' : 'damaged'}">
                                            ${cat.is_active ? 'Active' : 'Inactive'}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn warning" onclick="editCategory(${cat.id})" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="action-btn danger" onclick="deleteCategory(${cat.id}, '${escapeHtml(cat.name)}')" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            `;
                        });
                        if (!html) {
                            html = `
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 2rem; color: var(--gray);">
                                        <i class="fas fa-tags" style="margin-bottom: 0.5rem;"></i>
                                        <div>No active categories found</div>
                                    </td>
                                </tr>
                            `;
                        }
                        document.getElementById('categoriesTableBody').innerHTML = html;
                    }
                })
                .catch(() => {
                    document.getElementById('categoriesTableBody').innerHTML = `
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 2rem; color: var(--danger);">
                                Failed to load categories
                            </td>
                        </tr>
                    `;
                });
        }

        // Book functions
        function openAddBookModal() {
            document.getElementById('bookModalTitle').innerHTML = '<i class="fas fa-plus"></i> Add New Book';
            document.getElementById('bookId').value = '0';
            document.getElementById('bookForm').reset();
            document.getElementById('bookAvailableCopies').value = '1';
            document.getElementById('bookTotalCopies').value = '1';
            loadLocations();
            loadCategories();
            openModal('bookModal');
        }

        function editBook(id) {
            fetch('books.php?ajax=details&id=' + id)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const b = data.book;
                        document.getElementById('bookModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Book';
                        document.getElementById('bookId').value = b.id;
                        document.getElementById('bookTitle').value = b.title || '';
                        document.getElementById('bookAuthor').value = b.author || '';
                        document.getElementById('bookIsbn').value = b.isbn || '';
                        document.getElementById('bookPublisher').value = b.publisher || '';
                        document.getElementById('bookYear').value = b.publication_year || '';
                        document.getElementById('bookCategory').value = b.category_id || '';
                        document.getElementById('bookLocation').value = b.location_id || '';
                        document.getElementById('bookTotalCopies').value = b.total_copies || 0;
                        document.getElementById('bookAvailableCopies').value = b.available_copies || 0;
                        document.getElementById('bookLanguage').value = b.language || 'English';
                        document.getElementById('bookPages').value = b.pages || '';
                        document.getElementById('bookEdition').value = b.edition || '';
                        document.getElementById('bookTags').value = b.tags || '';
                        document.getElementById('bookCover').value = b.cover_image || '';
                        document.getElementById('bookDescription').value = b.description || '';
                        openModal('bookModal');
                    }
                });
        }

        function viewBook(id) {
            fetch('books.php?ajax=details&id=' + id)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const b = data.book;
                        const content = document.getElementById('viewBookContent');
                        content.innerHTML = `
                            <div style="display: grid; gap: 1.5rem;">
                                ${b.cover_image ? `
                                    <div style="text-align: center;">
                                        <img src="${escapeHtml(b.cover_image)}" alt="${escapeHtml(b.title)}" style="max-height: 200px; max-width: 100%; border-radius: 8px; box-shadow: var(--shadow-lg);">
                                    </div>
                                ` : ''}
                                
                                <h2 style="color: var(--dark); margin-bottom: 0.5rem;">${escapeHtml(b.title)}</h2>
                                <p style="color: var(--primary); font-size: 1.1rem; margin-bottom: 1rem;">by ${escapeHtml(b.author)}</p>
                                
                                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; background: var(--light); padding: 1rem; border-radius: 8px;">
                                    <div><strong>ISBN:</strong> ${escapeHtml(b.isbn || 'N/A')}</div>
                                    <div><strong>Publisher:</strong> ${escapeHtml(b.publisher || 'N/A')}</div>
                                    <div><strong>Year:</strong> ${escapeHtml(b.publication_year || 'N/A')}</div>
                                    <div><strong>Language:</strong> ${escapeHtml(b.language || 'N/A')}</div>
                                    <div><strong>Edition:</strong> ${escapeHtml(b.edition || 'N/A')}</div>
                                    <div><strong>Pages:</strong> ${escapeHtml(b.pages || 'N/A')}</div>
                                    <div><strong>Category:</strong> ${escapeHtml(b.category_name || 'N/A')}</div>
                                    <div><strong>Location:</strong> ${escapeHtml(b.location_name || 'N/A')}</div>
                                    <div><strong>Copies:</strong> ${b.total_copies || 0} total, ${b.available_copies || 0} available</div>
                                    <div><strong>Status:</strong> 
                                        <span class="status-badge status-${b.status}">${escapeHtml(b.status)}</span>
                                    </div>
                                </div>
                                
                                ${b.tags ? `
                                    <div>
                                        <strong>Tags:</strong> 
                                        ${b.tags.split(',').map(tag => 
                                            `<span style="display: inline-block; background: var(--light); padding: 0.2rem 0.5rem; border-radius: 4px; margin: 0.2rem; font-size: 0.85rem;">${escapeHtml(tag.trim())}</span>`
                                        ).join('')}
                                    </div>
                                ` : ''}
                                
                                ${b.description ? `
                                    <div style="background: var(--light); padding: 1rem; border-radius: 8px;">
                                        <strong>Description:</strong>
                                        <p style="margin-top: 0.5rem; color: var(--dark);">${escapeHtml(b.description)}</p>
                                    </div>
                                ` : ''}
                                
                                <div style="font-size: 0.9rem; color: var(--gray);">
                                    <div>Added by: ${escapeHtml(b.added_by_name || 'System')} on ${new Date(b.created_at).toLocaleDateString()}</div>
                                    ${b.updated_by_name ? `<div>Last updated by: ${escapeHtml(b.updated_by_name)}</div>` : ''}
                                </div>
                            </div>
                        `;
                        
                        document.getElementById('viewToEditBtn').onclick = () => {
                            closeModal('viewBookModal');
                            editBook(b.id);
                        };
                        
                        openModal('viewBookModal');
                    }
                });
        }

        function deleteBook(id, title) {
            Swal.fire({
                title: 'Delete Book?',
                html: `Are you sure you want to delete "<strong>${escapeHtml(title)}</strong>"?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f94144',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('ajax_action', 'delete_book');
                    formData.append('id', id);
                    
                    fetch('books.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('Deleted!', data.message, 'success').then(() => location.reload());
                        } else {
                            Swal.fire('Error!', data.error, 'error');
                        }
                    });
                }
            });
        }

        function saveBook(event) {
            event.preventDefault();
            
            const formData = new FormData(document.getElementById('bookForm'));
            
            Swal.fire({
                title: 'Saving...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            fetch('books.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: data.message,
                        timer: 1500
                    }).then(() => location.reload());
                } else {
                    Swal.fire('Error!', data.error, 'error');
                }
            });
        }

        // Location functions
        function openAddLocationModal() {
            document.getElementById('locationModalTitle').innerHTML = '<i class="fas fa-plus"></i> Add Location';
            document.getElementById('locationId').value = '0';
            document.getElementById('locationForm').reset();
            document.getElementById('locationActive').checked = true;
            openModal('locationModal');
        }

        function editLocation(id) {
            fetch('books.php?ajax=locations')
                .then(r => r.json())
                .then(data => {
                    const loc = data.locations.find(l => l.id == id);
                    if (loc) {
                        document.getElementById('locationModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Location';
                        document.getElementById('locationId').value = loc.id;
                        document.getElementById('locationCode').value = loc.code || '';
                        document.getElementById('locationName').value = loc.name || '';
                        document.getElementById('locationSection').value = loc.section || '';
                        document.getElementById('locationShelf').value = loc.shelf || '';
                        document.getElementById('locationFloor').value = loc.floor || '';
                        document.getElementById('locationCapacity').value = loc.capacity || 0;
                        document.getElementById('locationDescription').value = loc.description || '';
                        document.getElementById('locationActive').checked = loc.is_active == 1;
                        openModal('locationModal');
                    }
                });
        }

        function deleteLocation(id, name) {
            Swal.fire({
                title: 'Delete Location?',
                html: `Are you sure you want to delete "<strong>${escapeHtml(name)}</strong>"?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f94144',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('ajax_action', 'delete_location');
                    formData.append('id', id);
                    
                    fetch('books.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('Deleted!', data.message, 'success').then(() => loadLocations());
                        } else {
                            Swal.fire('Error!', data.error, 'error');
                        }
                    });
                }
            });
        }

        function saveLocation(event) {
            event.preventDefault();
            
            const formData = new FormData(document.getElementById('locationForm'));
            
            Swal.fire({
                title: 'Saving...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            fetch('books.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: data.message,
                        timer: 1500
                    }).then(() => {
                        closeModal('locationModal');
                        loadLocations();
                    });
                } else {
                    Swal.fire('Error!', data.error, 'error');
                }
            });
        }

        // Category functions
        function openAddCategoryModal() {
            document.getElementById('categoryModalTitle').innerHTML = '<i class="fas fa-plus"></i> Add Category';
            document.getElementById('categoryId').value = '0';
            document.getElementById('categoryForm').reset();
            document.getElementById('categoryActive').checked = true;
            document.getElementById('categoryColor').value = '#4361ee';
            document.getElementById('colorPreview').style.background = '#4361ee';
            document.getElementById('categoryIcon').value = 'fa-book';
            updateIconPreview();
            openModal('categoryModal');
        }

        function editCategory(id) {
            fetch('books.php?ajax=categories')
                .then(r => r.json())
                .then(data => {
                    const cat = data.categories.find(c => c.id == id);
                    if (cat) {
                        document.getElementById('categoryModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Category';
                        document.getElementById('categoryId').value = cat.id;
                        document.getElementById('categoryCode').value = cat.code || '';
                        document.getElementById('categoryName').value = cat.name || '';
                        document.getElementById('categoryParent').value = cat.parent_id || '';
                        document.getElementById('categoryIcon').value = cat.icon || 'fa-book';
                        document.getElementById('categoryColor').value = cat.color || '#4361ee';
                        document.getElementById('colorPreview').style.background = cat.color || '#4361ee';
                        document.getElementById('categorySort').value = cat.sort_order || 0;
                        document.getElementById('categoryDescription').value = cat.description || '';
                        document.getElementById('categoryActive').checked = cat.is_active == 1;
                        updateIconPreview();
                        openModal('categoryModal');
                    }
                });
        }

        function deleteCategory(id, name) {
            Swal.fire({
                title: 'Delete Category?',
                html: `Are you sure you want to delete "<strong>${escapeHtml(name)}</strong>"?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f94144',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('ajax_action', 'delete_category');
                    formData.append('id', id);
                    
                    fetch('books.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('Deleted!', data.message, 'success').then(() => loadCategories());
                        } else {
                            Swal.fire('Error!', data.error, 'error');
                        }
                    });
                }
            });
        }

        function saveCategory(event) {
            event.preventDefault();
            
            const formData = new FormData(document.getElementById('categoryForm'));
            
            Swal.fire({
                title: 'Saving...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            fetch('books.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: data.message,
                        timer: 1500
                    }).then(() => {
                        closeModal('categoryModal');
                        loadCategories();
                    });
                } else {
                    Swal.fire('Error!', data.error, 'error');
                }
            });
        }

        // Utility functions
        function openModal(id) {
            document.getElementById(id).classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatNumber(num) {
            return parseFloat(num).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
        }

        function quickIssueBook(bookId) {
            window.location.href = 'circulations.php?action=issue&book=' + bookId;
        }

        function exportBooks() {
            window.location.href = 'reports.php?type=books&format=csv';
        }

        // Icon preview update
        document.getElementById('categoryIcon')?.addEventListener('change', updateIconPreview);
        document.getElementById('categoryColor')?.addEventListener('input', function(e) {
            document.getElementById('colorPreview').style.background = e.target.value;
        });

        function updateIconPreview() {
            const icon = document.getElementById('categoryIcon').value;
            document.getElementById('iconPreview').innerHTML = `<i class="fas ${icon}" style="color: white;"></i>`;
        }

        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }

        // Load initial data if tab is active
        document.addEventListener('DOMContentLoaded', function() {
            if (document.querySelector('.tab.active').innerText.includes('Locations')) {
                loadLocations();
            } else if (document.querySelector('.tab.active').innerText.includes('Categories')) {
                loadCategories();
            }
        });
    </script>
</body>
</html>
