<?php
include '../config.php';
checkAuth();
checkRole(['admin']);
require_once '../inventory_payment_helpers.php';

function ensureInventoryDashboardSchema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventory_items (
            id INT PRIMARY KEY AUTO_INCREMENT,
            item_code VARCHAR(50) UNIQUE NOT NULL,
            item_name VARCHAR(150) NOT NULL,
            category VARCHAR(100) NOT NULL,
            description TEXT,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            quantity_in_stock INT DEFAULT 0,
            reorder_level INT DEFAULT 10,
            reorder_quantity INT DEFAULT 20,
            supplier_id INT NULL,
            last_restock_date TIMESTAMP NULL,
            status ENUM('active', 'inactive', 'discontinued') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY category (category),
            KEY status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    ensureInventoryPaymentWorkflow($pdo);
}

// Create events table if it doesn't exist
function createEventsTable() {
    global $pdo;
    $sql = "CREATE TABLE IF NOT EXISTS events (
        id INT PRIMARY KEY AUTO_INCREMENT,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        event_date DATE NOT NULL,
        event_type ENUM('meeting', 'conference', 'school_event', 'exam', 'workshop', 'holiday', 'other') DEFAULT 'meeting',
        start_time TIME,
        end_time TIME,
        location VARCHAR(255),
        target_audience VARCHAR(100) DEFAULT 'all_teachers',
        class_id INT,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    try {
        $pdo->exec($sql);
        return true;
    } catch (PDOException $e) {
        error_log("Error creating events table: " . $e->getMessage());
        return false;
    }
}

// Create table on page load
createEventsTable();
ensureInventoryDashboardSchema($pdo);

// Enhanced dashboard statistics
$total_students = $pdo->query("SELECT COUNT(*) FROM students WHERE status='active'")->fetchColumn() ?: 0;
$total_staff = $pdo->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn() ?: 0;
$total_teachers = $pdo->query("SELECT COUNT(*) FROM users WHERE role='teacher' AND status='active'")->fetchColumn() ?: 0;
$total_classes = $pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn() ?: 0;

// Financial stats aligned with the live invoice/payment flow used by accountant pages.
$total_fees_collected = $pdo->query("
    SELECT COALESCE(SUM(COALESCE(amount_paid, 0)), 0)
    FROM invoices
")->fetchColumn();
$pending_fees = $pdo->query("
    SELECT COALESCE(SUM(GREATEST(COALESCE(balance, total_amount - COALESCE(amount_paid, 0)), 0)), 0)
    FROM invoices
")->fetchColumn();
$total_revenue = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
$total_expenses = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();

// Monthly trends
$current_month = date('m');
$current_year = date('Y');
$monthly_revenue = $pdo->query("
    SELECT MONTH(created_at) as month, COALESCE(SUM(amount), 0) as total
    FROM payments
    WHERE YEAR(created_at) = $current_year
    GROUP BY MONTH(created_at)
    ORDER BY MONTH(created_at)
")->fetchAll(PDO::FETCH_ASSOC);

// Class distribution with percentages
$total_active_students = $total_students ?: 1; // Prevent division by zero
$class_distribution = $pdo->query("
    SELECT c.class_name, COUNT(s.id) as student_count,
           ROUND((COUNT(s.id) / $total_active_students) * 100, 1) as percentage
    FROM classes c
    LEFT JOIN students s ON c.id = s.class_id AND s.status='active'
    GROUP BY c.id, c.class_name
    ORDER BY student_count DESC
")->fetchAll();

// Enhanced attendance data
$attendance_stats = $pdo->query("
    SELECT
        COUNT(CASE WHEN status='Present' THEN 1 END) as total_present,
        COUNT(CASE WHEN status='Absent' THEN 1 END) as total_absent,
        COUNT(CASE WHEN status='Late' THEN 1 END) as total_late,
        COUNT(*) as total_records
    FROM attendance
    WHERE date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
")->fetch();

$attendance_rate = $attendance_stats && $attendance_stats['total_records'] > 0 ?
    round(($attendance_stats['total_present'] / $attendance_stats['total_records']) * 100, 1) : 0;

// Fee payment status derived from invoices so the dashboard matches accountant views.
$fee_status = $pdo->query("
    SELECT
        CASE
            WHEN COALESCE(amount_paid, 0) <= 0 THEN 'Unpaid'
            WHEN GREATEST(COALESCE(balance, total_amount - COALESCE(amount_paid, 0)), 0) <= 0 THEN 'Paid'
            ELSE 'Partial'
        END as status,
        COUNT(*) as count,
        COALESCE(SUM(total_amount), 0) as total_amount,
        COALESCE(SUM(COALESCE(amount_paid, 0)), 0) as paid_amount
    FROM invoices
    GROUP BY status
")->fetchAll(PDO::FETCH_ASSOC);

// Gender distribution
$gender_stats = $pdo->query("
    SELECT COALESCE(gender, 'Not Specified') as gender, COUNT(*) as count
    FROM students
    WHERE status='active'
    GROUP BY gender
")->fetchAll(PDO::FETCH_ASSOC);

// Recent activities with more details
$recent_activities = $pdo->query("
    (SELECT 'student' as type, full_name as title, created_at as date, 'fas fa-user-plus' as icon, '#3498db' as color
     FROM students
     ORDER BY created_at DESC
     LIMIT 4)
    UNION ALL
    (SELECT 'payment' as type, CONCAT('Payment: ', COALESCE(transaction_ref, 'N/A')) as title, created_at as date, 'fas fa-money-bill' as icon, '#27ae60' as color
     FROM payments
     ORDER BY created_at DESC
     LIMIT 4)
    ORDER BY date DESC
    LIMIT 8
")->fetchAll();

// Additional metrics
$absent_today = $pdo->query("SELECT COUNT(*) FROM attendance WHERE date = CURDATE() AND status='Absent'")->fetchColumn() ?: 0;
$present_today = $pdo->query("SELECT COUNT(*) FROM attendance WHERE date = CURDATE() AND status='Present'")->fetchColumn() ?: 0;
$total_books = $pdo->query("SELECT COUNT(*) FROM books WHERE status='available'")->fetchColumn() ?: 0;
$issued_books = $pdo->query("SELECT COUNT(*) FROM book_loans WHERE return_date IS NULL")->fetchColumn() ?: 0;
$overdue_books = $pdo->query("SELECT COUNT(*) FROM book_loans WHERE return_date IS NULL AND due_date < CURDATE()")->fetchColumn() ?: 0;

$unread_messages = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read=0");
$unread_messages->execute([$_SESSION['user_id']]);
$total_messages = $unread_messages->fetchColumn() ?: 0;

// Performance indicators
$avg_class_size = $total_students > 0 && $total_classes > 0 ? round($total_students / $total_classes, 1) : 0;
$book_utilization = $total_books > 0 ? round(($issued_books / $total_books) * 100, 1) : 0;

// Calculate collection rate
$collection_rate = ($total_fees_collected + $pending_fees) > 0 ? 
    round(($total_fees_collected / ($total_fees_collected + $pending_fees)) * 100, 1) : 0;

$inventory_totals = $pdo->query("
    SELECT
        COUNT(*) AS total_items,
        COUNT(CASE WHEN approval_status = 'pending' THEN 1 END) AS pending_approvals,
        COUNT(CASE WHEN approval_status = 'approved' AND payment_status = 'pending' THEN 1 END) AS awaiting_payment,
        COUNT(CASE WHEN quantity_in_stock <= reorder_level THEN 1 END) AS low_stock_items,
        COALESCE(SUM(quantity_in_stock * unit_price), 0) AS stock_value,
        COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN COALESCE(requested_payment_amount, quantity_in_stock * unit_price) ELSE 0 END), 0) AS paid_inventory_amount
    FROM inventory_items
")->fetch(PDO::FETCH_ASSOC) ?: [];

$inventory_status_breakdown = $pdo->query("
    SELECT
        CASE
            WHEN approval_status = 'pending' THEN 'Pending Approval'
            WHEN approval_status = 'rejected' THEN 'Rejected'
            WHEN payment_status = 'paid' THEN 'Paid'
            WHEN payment_status = 'cancelled' THEN 'Cancelled'
            WHEN quantity_in_stock <= reorder_level THEN 'Low Stock'
            ELSE 'Active'
        END AS status_group,
        COUNT(*) AS item_count
    FROM inventory_items
    GROUP BY status_group
    ORDER BY item_count DESC
")->fetchAll(PDO::FETCH_ASSOC);

$inventory_monthly_trend = $pdo->query("
    SELECT
        DATE_FORMAT(created_at, '%Y-%m') AS month_key,
        DATE_FORMAT(created_at, '%b') AS month_name,
        COUNT(*) AS items_added,
        COALESCE(SUM(quantity_in_stock), 0) AS stock_units,
        COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN COALESCE(requested_payment_amount, quantity_in_stock * unit_price) ELSE 0 END), 0) AS paid_amount
    FROM inventory_items
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%b')
    ORDER BY month_key ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Event Management Functions with better error handling
function createEvent($data) {
    global $pdo;
    try {
        // Validate required fields
        if (empty($data['title']) || empty($data['event_date'])) {
            return ['success' => false, 'message' => 'Title and date are required'];
        }

        $sql = "INSERT INTO events 
                (title, description, event_date, event_type, start_time, end_time, 
                 location, target_audience, class_id, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            trim($data['title']),
            !empty($data['description']) ? trim($data['description']) : null,
            $data['event_date'],
            $data['event_type'] ?? 'meeting',
            !empty($data['start_time']) ? $data['start_time'] : null,
            !empty($data['end_time']) ? $data['end_time'] : null,
            !empty($data['location']) ? trim($data['location']) : null,
            $data['target_audience'] ?? 'all_teachers',
            !empty($data['class_id']) ? $data['class_id'] : null,
            $_SESSION['user_id']
        ]);

        if ($result) {
            $event_id = $pdo->lastInsertId();
            return ['success' => true, 'message' => 'Event created successfully', 'event_id' => $event_id];
        } else {
            return ['success' => false, 'message' => 'Failed to create event'];
        }
    } catch (PDOException $e) {
        error_log("Error creating event: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function updateEvent($event_id, $data) {
    global $pdo;
    try {
        // Check if event exists and belongs to user
        $check = $pdo->prepare("SELECT id FROM events WHERE id = ? AND created_by = ?");
        $check->execute([$event_id, $_SESSION['user_id']]);
        if (!$check->fetch()) {
            return ['success' => false, 'message' => 'Event not found or permission denied'];
        }

        $sql = "UPDATE events SET 
                title = ?, description = ?, event_date = ?, event_type = ?, 
                start_time = ?, end_time = ?, location = ?, target_audience = ?, class_id = ?
                WHERE id = ? AND created_by = ?";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            trim($data['title']),
            !empty($data['description']) ? trim($data['description']) : null,
            $data['event_date'],
            $data['event_type'] ?? 'meeting',
            !empty($data['start_time']) ? $data['start_time'] : null,
            !empty($data['end_time']) ? $data['end_time'] : null,
            !empty($data['location']) ? trim($data['location']) : null,
            $data['target_audience'] ?? 'all_teachers',
            !empty($data['class_id']) ? $data['class_id'] : null,
            $event_id,
            $_SESSION['user_id']
        ]);

        if ($result) {
            return ['success' => true, 'message' => 'Event updated successfully'];
        } else {
            return ['success' => false, 'message' => 'No changes made to the event'];
        }
    } catch (PDOException $e) {
        error_log("Error updating event: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function deleteEvent($event_id) {
    global $pdo;
    try {
        // Check if event exists and belongs to user
        $check = $pdo->prepare("SELECT title FROM events WHERE id = ? AND created_by = ?");
        $check->execute([$event_id, $_SESSION['user_id']]);
        $event = $check->fetch();
        
        if (!$event) {
            return ['success' => false, 'message' => 'Event not found or permission denied'];
        }

        $stmt = $pdo->prepare("DELETE FROM events WHERE id = ? AND created_by = ?");
        $result = $stmt->execute([$event_id, $_SESSION['user_id']]);

        if ($result) {
            return ['success' => true, 'message' => 'Event "' . $event['title'] . '" deleted successfully'];
        } else {
            return ['success' => false, 'message' => 'Failed to delete event'];
        }
    } catch (PDOException $e) {
        error_log("Error deleting event: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function getEventById($event_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT e.*, u.full_name as created_by_name, c.class_name
                              FROM events e
                              LEFT JOIN users u ON e.created_by = u.id
                              LEFT JOIN classes c ON e.class_id = c.id
                              WHERE e.id = ? AND (e.created_by = ? OR ? = 'admin')");
        $stmt->execute([$event_id, $_SESSION['user_id'], $_SESSION['role']]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error getting event: " . $e->getMessage());
        return false;
    }
}

// Get all classes for dropdown
$classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll();

// Handle AJAX requests
$response = ['success' => false, 'message' => ''];
$show_sweetalert = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_event'])) {
        $event_data = [
            'title' => $_POST['title'] ?? '',
            'description' => $_POST['description'] ?? '',
            'event_date' => $_POST['event_date'] ?? '',
            'event_type' => $_POST['event_type'] ?? 'meeting',
            'start_time' => $_POST['start_time'] ?? '',
            'end_time' => $_POST['end_time'] ?? '',
            'location' => $_POST['location'] ?? '',
            'target_audience' => $_POST['target_audience'] ?? 'all_teachers',
            'class_id' => !empty($_POST['class_id']) ? $_POST['class_id'] : null
        ];
        
        $result = createEvent($event_data);
        $response = $result;
        $show_sweetalert = true;
        
        // Clear POST data to prevent form resubmission
        echo "<script>if (window.history.replaceState) { window.history.replaceState(null, null, window.location.href); }</script>";
        
    } elseif (isset($_POST['update_event'])) {
        $event_id = $_POST['event_id'] ?? 0;
        $event_data = [
            'title' => $_POST['title'] ?? '',
            'description' => $_POST['description'] ?? '',
            'event_date' => $_POST['event_date'] ?? '',
            'event_type' => $_POST['event_type'] ?? 'meeting',
            'start_time' => $_POST['start_time'] ?? '',
            'end_time' => $_POST['end_time'] ?? '',
            'location' => $_POST['location'] ?? '',
            'target_audience' => $_POST['target_audience'] ?? 'all_teachers',
            'class_id' => !empty($_POST['class_id']) ? $_POST['class_id'] : null
        ];
        
        if ($event_id > 0) {
            $result = updateEvent($event_id, $event_data);
            $response = $result;
            $show_sweetalert = true;
        }
        
    } elseif (isset($_POST['delete_event'])) {
        $event_id = $_POST['event_id'] ?? 0;
        if ($event_id > 0) {
            $result = deleteEvent($event_id);
            $response = $result;
            $show_sweetalert = true;
        }
    }
    
    // If AJAX request, return JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}

// Get events for display (optional)
$upcoming_events = $pdo->query("
    SELECT e.*, u.full_name as created_by_name 
    FROM events e
    LEFT JOIN users u ON e.created_by = u.id
    WHERE e.event_date >= CURDATE()
    ORDER BY e.event_date ASC
    LIMIT 5
")->fetchAll();

// Initialize other variables
$edit_event = null;
$view_event = null;

// Check for edit/view parameters
if (isset($_GET['edit_event'])) {
    $edit_event = getEventById($_GET['edit_event']);
}

if (isset($_GET['view_event'])) {
    $view_event = getEventById($_GET['view_event']);
}

$page_title = "Admin Dashboard - " . SCHOOL_NAME;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            gap: 1rem;
        }

        .welcome-section h1 {
            font-size: 2.2rem;
            font-weight: 700;
            background: var(--gradient-1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .welcome-section p {
            color: var(--gray);
            font-size: 1rem;
        }

        .date-time {
            text-align: right;
            background: rgba(102, 126, 234, 0.1);
            padding: 1rem 2rem;
            border-radius: 50px;
            border: 1px solid rgba(102, 126, 234, 0.2);
        }

        .current-date {
            font-size: 1rem;
            font-weight: 500;
            color: var(--gray);
            margin-bottom: 0.3rem;
        }

        .current-time {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            font-family: 'Courier New', monospace;
            letter-spacing: 2px;
        }

        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .kpi-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
            cursor: pointer;
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            transition: var(--transition);
        }

        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .kpi-card:hover::before {
            width: 6px;
        }

        .kpi-card.success::before { background: var(--gradient-3); }
        .kpi-card.info::before { background: var(--gradient-1); }
        .kpi-card.warning::before { background: var(--gradient-5); }
        .kpi-card.danger::before { background: var(--gradient-2); }

        .kpi-content {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .kpi-icon {
            width: 60px;
            height: 60px;
            border-radius: var(--border-radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            flex-shrink: 0;
            transition: var(--transition);
        }

        .kpi-card:hover .kpi-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .kpi-card.success .kpi-icon { background: var(--gradient-3); }
        .kpi-card.info .kpi-icon { background: var(--gradient-1); }
        .kpi-card.warning .kpi-icon { background: var(--gradient-5); }
        .kpi-card.danger .kpi-icon { background: var(--gradient-2); }

        .kpi-info h3 {
            font-size: 0.9rem;
            color: var(--gray);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .kpi-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .kpi-change {
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            background: rgba(76, 201, 240, 0.1);
            width: fit-content;
        }

        .kpi-change.positive { color: var(--success); }
        .kpi-change.negative { color: var(--danger); }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .chart-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            border: 1px solid rgba(255,255,255,0.3);
        }

        .chart-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-xl);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light);
        }

        .chart-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-period {
            padding: 0.4rem 1rem;
            background: var(--light);
            border-radius: 20px;
            font-size: 0.8rem;
            color: var(--gray);
            font-weight: 500;
        }

        .chart-container {
            height: 300px;
            position: relative;
        }

        /* Analytics Grid */
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .analytics-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            border: 1px solid rgba(255,255,255,0.3);
        }

        .analytics-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-xl);
        }

        .analytics-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light);
        }

        .analytics-header h4 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .analytics-badge {
            padding: 0.3rem 0.8rem;
            background: var(--light);
            border-radius: 20px;
            font-size: 0.8rem;
            color: var(--gray);
            font-weight: 500;
        }

        /* Class List */
        .class-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .class-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.8rem;
            background: var(--light);
            border-radius: var(--border-radius-sm);
            transition: var(--transition);
        }

        .class-item:hover {
            background: rgba(67, 97, 238, 0.1);
            transform: translateX(5px);
        }

        .class-rank {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: var(--gradient-1);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .class-info {
            flex: 1;
        }

        .class-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.2rem;
        }

        .class-stats {
            font-size: 0.8rem;
            color: var(--gray);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.3rem;
        }

        .class-progress {
            width: 100%;
            height: 4px;
            background: rgba(0,0,0,0.1);
            border-radius: 2px;
            overflow: hidden;
        }

        .class-progress-fill {
            height: 100%;
            background: var(--gradient-1);
            border-radius: 2px;
            transition: width 0.3s ease;
        }

        .class-count {
            font-weight: 700;
            color: var(--primary);
            font-size: 1.1rem;
        }

        /* Progress Circle */
        .progress-circle {
            width: 120px;
            height: 120px;
            margin: 0 auto 1rem;
            position: relative;
        }

        .progress-circle svg {
            width: 120px;
            height: 120px;
            transform: rotate(-90deg);
        }

        .progress-circle circle {
            fill: none;
            stroke-width: 8;
            stroke-linecap: round;
        }

        .progress-circle .bg {
            stroke: var(--light);
        }

        .progress-circle .progress {
            stroke: url(#gradient);
            stroke-dasharray: 314;
            stroke-dashoffset: <?php echo 314 - (314 * $collection_rate / 100); ?>;
            transition: stroke-dashoffset 1s ease;
        }

        .progress-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }

        .progress-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary);
            line-height: 1;
        }

        .progress-label {
            font-size: 0.8rem;
            color: var(--gray);
        }

        /* Activity Feed */
        .activity-feed {
            max-height: 400px;
            overflow-y: auto;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            transition: var(--transition);
        }

        .activity-item:hover {
            background: var(--light);
            transform: translateX(5px);
        }

        .activity-icon-wrapper {
            position: relative;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
            transition: var(--transition);
        }

        .activity-item:hover .activity-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .activity-dot {
            position: absolute;
            top: -2px;
            right: -2px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--success);
            border: 2px solid white;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.2rem;
            font-size: 0.95rem;
        }

        .activity-time {
            font-size: 0.75rem;
            color: var(--gray);
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* Quick Actions - Icon Only */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.8rem;
        }

        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 1rem;
            background: var(--light);
            border: none;
            border-radius: var(--border-radius-md);
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            font-size: 0.8rem;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--gradient-1);
            opacity: 0;
            transition: var(--transition);
            z-index: 1;
        }

        .action-btn:hover::before {
            opacity: 1;
        }

        .action-btn i, .action-btn span {
            position: relative;
            z-index: 2;
            transition: var(--transition);
        }

        .action-btn:hover i,
        .action-btn:hover span {
            color: white;
        }

        .action-btn i {
            font-size: 1.5rem;
            color: var(--primary);
        }

        .action-btn .notification-badge {
            position: absolute;
            top: 5px;
            right: 5px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 3;
        }

        /* Mini Stats */
        .mini-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.8rem;
            margin-top: 1rem;
        }

        .mini-stat {
            text-align: center;
            padding: 0.8rem;
            background: var(--light);
            border-radius: var(--border-radius-sm);
            transition: var(--transition);
        }

        .mini-stat:hover {
            transform: translateY(-2px);
            background: rgba(67, 97, 238, 0.1);
        }

        .mini-stat-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary);
        }

        .mini-stat-label {
            font-size: 0.7rem;
            color: var(--gray);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
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

        @keyframes fadeInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes fadeInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .animate-fade-up {
            animation: fadeInUp 0.6s ease-out;
        }

        .animate-fade-left {
            animation: fadeInLeft 0.6s ease-out;
        }

        .animate-fade-right {
            animation: fadeInRight 0.6s ease-out;
        }

        .stagger-item {
            opacity: 0;
            animation: fadeInUp 0.5s ease-out forwards;
        }

        .stagger-item:nth-child(1) { animation-delay: 0.1s; }
        .stagger-item:nth-child(2) { animation-delay: 0.2s; }
        .stagger-item:nth-child(3) { animation-delay: 0.3s; }
        .stagger-item:nth-child(4) { animation-delay: 0.4s; }

        /* Skeleton Loading */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: skeleton-loading 1.5s infinite;
        }

        @keyframes skeleton-loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        .loading .kpi-card:not(.skeleton),
        .loading .chart-card:not(.skeleton),
        .loading .analytics-card:not(.skeleton),
        .loading .page-header:not(.skeleton) {
            display: none;
        }

        .loading .skeleton {
            display: block;
        }

        /* Responsive */
        @media (max-width: 1400px) {
            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .analytics-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .analytics-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 0.85rem;
            }

            .page-header {
                padding: 1.25rem 1rem;
                border-radius: var(--border-radius-lg);
            }
            
            .kpi-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .header-content {
                flex-direction: column;
                text-align: center;
                align-items: stretch;
            }

            .welcome-section h1 {
                font-size: 1.65rem;
                line-height: 1.15;
            }

            .welcome-section p {
                font-size: 0.95rem;
            }
            
            .date-time {
                text-align: center;
                width: 100%;
                max-width: 100%;
                padding: 0.9rem 1rem;
                border-radius: 20px;
                overflow: hidden;
            }

            .current-date {
                font-size: 0.9rem;
                word-break: break-word;
            }

            .current-time {
                font-size: 1.35rem;
                letter-spacing: 0.5px;
                line-height: 1.2;
                word-break: break-word;
                overflow-wrap: anywhere;
            }

            .kpi-card {
                padding: 1.1rem;
            }

            .kpi-content {
                align-items: flex-start;
                gap: 0.85rem;
            }

            .kpi-icon {
                width: 52px;
                height: 52px;
                font-size: 1.2rem;
            }

            .kpi-info {
                min-width: 0;
                flex: 1;
            }

            .kpi-info h3 {
                font-size: 0.8rem;
                line-height: 1.35;
            }

            .kpi-value {
                font-size: 1.45rem;
                line-height: 1.2;
                overflow-wrap: anywhere;
                word-break: break-word;
            }

            .kpi-change {
                font-size: 0.78rem;
                line-height: 1.3;
                flex-wrap: wrap;
            }
            
            .quick-actions-grid {
                grid-template-columns: 1fr;
            }
            
            .chart-container {
                height: 250px;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 0.75rem;
            }

            .page-header {
                padding: 1rem 0.9rem;
            }

            .welcome-section h1 {
                font-size: 1.45rem;
            }

            .date-time {
                padding: 0.8rem 0.85rem;
            }

            .current-date {
                font-size: 0.82rem;
            }

            .current-time {
                font-size: 1.15rem;
                letter-spacing: 0;
            }

            .kpi-content {
                gap: 0.75rem;
            }

            .kpi-icon {
                width: 46px;
                height: 46px;
                font-size: 1.05rem;
            }

            .kpi-value {
                font-size: 1.25rem;
            }
        }

        /* Modal Styling */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(3px);
            animation: fadeIn 0.3s ease-in-out;
        }

        .modal.active {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
            animation: slideUp 0.3s ease-in-out;
        }

        .modal-header {
            padding: 2rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.05) 0%, rgba(63, 55, 201, 0.05) 100%);
        }

        .modal-title {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        .btn-close {
            background: none;
            border: none;
            font-size: 1.8rem;
            cursor: pointer;
            color: var(--gray);
            transition: var(--transition);
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .btn-close:hover {
            background-color: var(--light);
            color: var(--danger);
        }

        .modal-body {
            padding: 2rem;
        }

        .modal-footer {
            padding: 1.5rem 2rem;
            border-top: 1px solid rgba(0,0,0,0.1);
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            background: var(--light);
        }

        /* Form Styling */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.95rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid rgba(0,0,0,0.1);
            border-radius: var(--border-radius-md);
            font-size: 1rem;
            font-family: 'Inter', sans-serif;
            transition: var(--transition);
            background: var(--white);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .form-control:hover {
            border-color: var(--primary);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .form-hint {
            font-size: 0.85rem;
            color: var(--gray);
            margin-top: 0.5rem;
            font-style: italic;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius-md);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            white-space: nowrap;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline {
            background: transparent;
            color: var(--gray);
            border: 1px solid var(--gray);
        }

        .btn-outline:hover {
            background: var(--light);
            color: var(--dark);
            border-color: var(--dark);
        }

        .btn-success {
            background: var(--success);
            color: var(--white);
        }

        .btn-success:hover {
            background: var(--success-dark);
        }

        .btn-danger {
            background: var(--danger);
            color: var(--white);
        }

        .btn-danger:hover {
            background: var(--danger-dark);
        }

        .btn-warning {
            background: var(--warning);
            color: var(--white);
        }

        .btn-warning:hover {
            background: var(--warning-dark);
        }

        /* Event Details */
        .event-detail-item {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }

        .event-detail-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .detail-label {
            font-weight: 600;
            color: var(--gray);
            font-size: 0.95rem;
        }

        .detail-value {
            color: var(--dark);
            line-height: 1.6;
        }

        /* Delete Confirmation */
        .delete-confirm {
            text-align: center;
        }

        .delete-message {
            color: var(--dark);
        }

        /* Modal Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from {
                transform: translateY(30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 95%;
                margin: 1rem;
            }

            .event-detail-item {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>
    <?php include '../loader.php'; ?>

    <div class="main-content">
        <!-- Skeleton Loaders -->
        <div class="page-header skeleton" style="display: none;">
            <div class="skeleton-text title" style="width: 50%;"></div>
            <div class="skeleton-text subtitle" style="width: 70%;"></div>
        </div>

        <div class="kpi-grid">
            <div class="kpi-card skeleton" style="display: none;"></div>
            <div class="kpi-card skeleton" style="display: none;"></div>
            <div class="kpi-card skeleton" style="display: none;"></div>
            <div class="kpi-card skeleton" style="display: none;"></div>
        </div>

        <!-- Dashboard Header -->
        <div class="page-header animate-fade-up">
            <div class="header-content">
                <div class="welcome-section">
                    <h1>Dashboard Overview</h1>
                    <p>Welcome back, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?>! Here's your comprehensive school analytics.</p>
                </div>
                <div style="display: flex; gap: 1rem; align-items: center;">
                    <button class="btn btn-success" onclick="openEventModal()" style="margin-right: 1rem;">
                        <i class="fas fa-calendar-plus"></i> Create Event
                    </button>
                    <div class="date-time">
                        <div class="current-date"><?php echo date('l, F j, Y'); ?></div>
                        <div class="current-time" id="currentTime"><?php echo date('H:i:s'); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card success stagger-item">
                <div class="kpi-content">
                    <div class="kpi-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="kpi-info">
                        <h3>Total Students</h3>
                        <div class="kpi-value"><?php echo number_format($total_students); ?></div>
                        <div class="kpi-change positive">
                            <i class="fas fa-arrow-up"></i> Active enrollment
                        </div>
                    </div>
                </div>
            </div>

            <div class="kpi-card info stagger-item">
                <div class="kpi-content">
                    <div class="kpi-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="kpi-info">
                        <h3>Revenue (30 Days)</h3>
                        <div class="kpi-value">KES <?php echo number_format($total_revenue); ?></div>
                        <div class="kpi-change positive">
                            <i class="fas fa-arrow-up"></i> Monthly collection
                        </div>
                    </div>
                </div>
            </div>

            <div class="kpi-card warning stagger-item">
                <div class="kpi-content">
                    <div class="kpi-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="kpi-info">
                        <h3>Pending Fees</h3>
                        <div class="kpi-value">KES <?php echo number_format($pending_fees); ?></div>
                        <div class="kpi-change negative">
                            <i class="fas fa-arrow-down"></i> Outstanding
                        </div>
                    </div>
                </div>
            </div>

            <div class="kpi-card danger stagger-item">
                <div class="kpi-content">
                    <div class="kpi-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="kpi-info">
                        <h3>Attendance Rate</h3>
                        <div class="kpi-value"><?php echo $attendance_rate; ?>%</div>
                        <div class="kpi-change positive">
                            <i class="fas fa-arrow-up"></i> Last 30 days
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Grid -->
        <div class="charts-grid">
            <!-- Revenue Chart -->
            <div class="chart-card animate-fade-left">
                <div class="chart-header">
                    <h3>
                        <i class="fas fa-chart-line" style="color: var(--primary);"></i>
                        Monthly Revenue Trend
                    </h3>
                    <span class="chart-period"><?php echo date('Y'); ?></span>
                </div>
                <div class="chart-container">
                    <canvas id="revenueChart"></canvas>
                </div>
                <div class="mini-stats">
                    <div class="mini-stat">
                        <div class="mini-stat-value">KES <?php echo number_format($total_revenue/1000, 0); ?>k</div>
                        <div class="mini-stat-label">This Month</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?php echo $collection_rate; ?>%</div>
                        <div class="mini-stat-label">Collection Rate</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?php echo count($monthly_revenue); ?></div>
                        <div class="mini-stat-label">Months</div>
                    </div>
                </div>
            </div>

            <!-- Fee Status Chart -->
            <div class="chart-card animate-fade-right">
                <div class="chart-header">
                    <h3>
                        <i class="fas fa-chart-pie" style="color: var(--warning);"></i>
                        Fee Payment Status
                    </h3>
                    <span class="chart-period">By Count</span>
                </div>
                <div class="chart-container">
                    <canvas id="feeStatusChart"></canvas>
                </div>
                <div class="mini-stats">
                    <div class="mini-stat">
                        <div class="mini-stat-value">KES <?php echo number_format($total_fees_collected/1000, 0); ?>k</div>
                        <div class="mini-stat-label">Collected</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-value">KES <?php echo number_format($pending_fees/1000, 0); ?>k</div>
                        <div class="mini-stat-label">Pending</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?php echo $collection_rate; ?>%</div>
                        <div class="mini-stat-label">Rate</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="charts-grid">
            <div class="chart-card animate-fade-left">
                <div class="chart-header">
                    <h3>
                        <i class="fas fa-boxes-stacked" style="color: var(--info);"></i>
                        Inventory Trend
                    </h3>
                    <span class="chart-period">Last 6 Months</span>
                </div>
                <div class="chart-container">
                    <canvas id="inventoryTrendChart"></canvas>
                </div>
                <div class="mini-stats">
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?php echo number_format((int)($inventory_totals['total_items'] ?? 0)); ?></div>
                        <div class="mini-stat-label">Tracked Items</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-value">KES <?php echo number_format(((float)($inventory_totals['stock_value'] ?? 0))/1000, 0); ?>k</div>
                        <div class="mini-stat-label">Stock Value</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?php echo number_format((int)($inventory_totals['low_stock_items'] ?? 0)); ?></div>
                        <div class="mini-stat-label">Low Stock</div>
                    </div>
                </div>
            </div>

            <div class="chart-card animate-fade-right">
                <div class="chart-header">
                    <h3>
                        <i class="fas fa-warehouse" style="color: var(--success);"></i>
                        Inventory Workflow
                    </h3>
                    <span class="chart-period">Current Snapshot</span>
                </div>
                <div class="chart-container">
                    <canvas id="inventoryStatusChart"></canvas>
                </div>
                <div class="mini-stats">
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?php echo number_format((int)($inventory_totals['pending_approvals'] ?? 0)); ?></div>
                        <div class="mini-stat-label">Pending Approval</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?php echo number_format((int)($inventory_totals['awaiting_payment'] ?? 0)); ?></div>
                        <div class="mini-stat-label">Awaiting Payment</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-value">KES <?php echo number_format(((float)($inventory_totals['paid_inventory_amount'] ?? 0))/1000, 0); ?>k</div>
                        <div class="mini-stat-label">Paid Out</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Analytics Grid -->
        <div class="analytics-grid">
            <!-- Class Distribution -->
            <div class="analytics-card animate-fade-up">
                <div class="analytics-header">
                    <h4>
                        <i class="fas fa-layer-group" style="color: var(--primary);"></i>
                        Class Distribution
                    </h4>
                    <span class="analytics-badge"><?php echo count($class_distribution); ?> classes</span>
                </div>
                <div class="class-list">
                    <?php if (!empty($class_distribution)): ?>
                        <?php foreach(array_slice($class_distribution, 0, 5) as $index => $class): ?>
                        <div class="class-item">
                            <div class="class-rank"><?php echo $index + 1; ?></div>
                            <div class="class-info">
                                <div class="class-name"><?php echo htmlspecialchars($class['class_name'] ?? 'N/A'); ?></div>
                                <div class="class-stats">
                                    <span><?php echo $class['student_count'] ?? 0; ?> students</span>
                                    <span>•</span>
                                    <span><?php echo $class['percentage'] ?? 0; ?>%</span>
                                </div>
                                <div class="class-progress">
                                    <div class="class-progress-fill" style="width: <?php echo $class['percentage'] ?? 0; ?>%;"></div>
                                </div>
                            </div>
                            <div class="class-count"><?php echo $class['student_count'] ?? 0; ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-bar"></i>
                            <p>No class data available</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Gender Distribution & Collection Rate -->
            <div class="analytics-card animate-fade-up">
                <div class="analytics-header">
                    <h4>
                        <i class="fas fa-venus-mars" style="color: var(--success);"></i>
                        Gender Distribution
                    </h4>
                    <span class="analytics-badge">Demographics</span>
                </div>
                <div class="chart-container" style="height: 200px;">
                    <canvas id="genderChart"></canvas>
                </div>
                
                <div style="margin-top: 2rem;">
                    <div class="analytics-header" style="margin-bottom: 1rem;">
                        <h4>
                            <i class="fas fa-chart-pie" style="color: var(--warning);"></i>
                            Collection Rate
                        </h4>
                    </div>
                    <div class="progress-circle">
                        <svg>
                            <defs>
                                <linearGradient id="gradient" x1="0%" y1="0%" x2="100%" y2="0%">
                                    <stop offset="0%" stop-color="#667eea"/>
                                    <stop offset="100%" stop-color="#764ba2"/>
                                </linearGradient>
                            </defs>
                            <circle class="bg" cx="60" cy="60" r="50"></circle>
                            <circle class="progress" cx="60" cy="60" r="50"></circle>
                        </svg>
                        <div class="progress-text">
                            <div class="progress-value"><?php echo $collection_rate; ?>%</div>
                            <div class="progress-label">Collected</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions - Icon Only -->
            <div class="analytics-card animate-fade-up">
                <div class="analytics-header">
                    <h4>
                        <i class="fas fa-bolt" style="color: var(--warning);"></i>
                        Quick Actions
                    </h4>
                    <span class="analytics-badge">Shortcuts</span>
                </div>
                <div class="quick-actions-grid">
                    <a href="exams.php" class="action-btn" data-tooltip="Manage Exams">
                        <i class="fas fa-pencil-alt"></i>
                        <span>Exams</span>
                    </a>
                    <a href="attendance.php" class="action-btn" data-tooltip="Mark Attendance">
                        <i class="fas fa-clipboard-check"></i>
                        <span>Attendance</span>
                    </a>
                    <a href="students.php" class="action-btn" data-tooltip="Add Student">
                        <i class="fas fa-user-plus"></i>
                        <span>Student</span>
                    </a>
                    <a href="reports.php" class="action-btn" data-tooltip="View Reports">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                    <a href="messages.php" class="action-btn" data-tooltip="Messages">
                        <i class="fas fa-envelope"></i>
                        <span>Messages</span>
                        <?php if ($total_messages > 0): ?>
                            <span class="notification-badge"><?php echo $total_messages; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="library.php" class="action-btn" data-tooltip="Library">
                        <i class="fas fa-book"></i>
                        <span>Library</span>
                    </a>
                    <a href="inventory.php" class="action-btn" data-tooltip="Inventory">
                        <i class="fas fa-boxes"></i>
                        <span>Inventory</span>
                    </a>
                    <a href="school_funds.php" class="action-btn" data-tooltip="School Fund">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>School Fund</span>
                    </a>
                </div>

                <!-- Upcoming Events Preview -->
                <?php if (!empty($upcoming_events)): ?>
                <div style="margin-top: 1.5rem;">
                    <div class="analytics-header" style="margin-bottom: 1rem;">
                        <h4>
                            <i class="fas fa-calendar-alt" style="color: var(--primary);"></i>
                            Upcoming Events
                        </h4>
                        <span class="analytics-badge">Next 5</span>
                    </div>
                    <div class="activity-feed" style="max-height: 200px;">
                        <?php foreach($upcoming_events as $event): ?>
                        <div class="activity-item" onclick="viewEvent(<?php echo $event['id']; ?>)" style="cursor: pointer;">
                            <div class="activity-icon-wrapper">
                                <div class="activity-icon" style="background: <?php 
                                    echo $event['event_type'] == 'meeting' ? '#4361ee' : 
                                        ($event['event_type'] == 'exam' ? '#f94144' : 
                                        ($event['event_type'] == 'holiday' ? '#4cc9f0' : '#f8961e')); 
                                ?>">
                                    <i class="fas fa-calendar-day"></i>
                                </div>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title"><?php echo htmlspecialchars($event['title']); ?></div>
                                <div class="activity-time">
                                    <i class="far fa-calendar"></i> <?php echo date('M j, Y', strtotime($event['event_date'])); ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Recent Activities -->
            <div class="analytics-card animate-fade-up">
                <div class="analytics-header">
                    <h4>
                        <i class="fas fa-history" style="color: var(--info);"></i>
                        Recent Activities
                    </h4>
                    <span class="analytics-badge">Live</span>
                </div>
                <div class="activity-feed">
                    <?php if (!empty($recent_activities)): ?>
                        <?php foreach($recent_activities as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon-wrapper">
                                <div class="activity-icon" style="background: <?php echo $activity['color'] ?? '#4361ee'; ?>">
                                    <i class="<?php echo $activity['icon'] ?? 'fas fa-bell'; ?>"></i>
                                </div>
                                <div class="activity-dot"></div>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title"><?php echo htmlspecialchars($activity['title'] ?? 'Activity'); ?></div>
                                <div class="activity-time">
                                    <i class="far fa-clock"></i> <?php echo isset($activity['date']) ? date('M j, H:i', strtotime($activity['date'])) : 'Just now'; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-bell-slash"></i>
                            <p>No recent activities</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Library Statistics -->
            <div class="analytics-card animate-fade-up">
                <div class="analytics-header">
                    <h4>
                        <i class="fas fa-book" style="color: var(--purple);"></i>
                        Library Status
                    </h4>
                    <span class="analytics-badge"><?php echo $book_utilization; ?>% utilized</span>
                </div>
                <div class="mini-stats">
                    <div class="mini-stat">
                        <div class="mini-stat-value" style="color: var(--success);"><?php echo $total_books; ?></div>
                        <div class="mini-stat-label">Available</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-value" style="color: var(--warning);"><?php echo $issued_books; ?></div>
                        <div class="mini-stat-label">Issued</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-value" style="color: var(--danger);"><?php echo $overdue_books; ?></div>
                        <div class="mini-stat-label">Overdue</div>
                    </div>
                </div>
                
                <div style="margin-top: 1.5rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                        <span>Book Utilization</span>
                        <span><?php echo $book_utilization; ?>%</span>
                    </div>
                    <div class="class-progress">
                        <div class="class-progress-fill" style="width: <?php echo $book_utilization; ?>%;"></div>
                    </div>
                </div>
            </div>

            <!-- Performance Indicators -->
            <div class="analytics-card animate-fade-up">
                <div class="analytics-header">
                    <h4>
                        <i class="fas fa-tachometer-alt" style="color: var(--danger);"></i>
                        Performance Indicators
                    </h4>
                    <span class="analytics-badge">Metrics</span>
                </div>
                <div class="mini-stats" style="grid-template-columns: repeat(2, 1fr);">
                    <div class="mini-stat">
                        <div class="mini-stat-value" style="color: var(--primary);"><?php echo $avg_class_size; ?></div>
                        <div class="mini-stat-label">Avg Class Size</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-value" style="color: var(--purple);"><?php echo $total_teachers; ?></div>
                        <div class="mini-stat-label">Active Teachers</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-value" style="color: var(--warning);"><?php echo $total_classes; ?></div>
                        <div class="mini-stat-label">Total Classes</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-value" style="color: var(--info);"><?php echo $total_messages; ?></div>
                        <div class="mini-stat-label">Unread Messages</div>
                    </div>
                </div>
                
                <!-- Today's Attendance Summary -->
                <div style="margin-top: 1.5rem;">
                    <div class="analytics-header" style="margin-bottom: 1rem;">
                        <h4>
                            <i class="fas fa-calendar-check" style="color: var(--success);"></i>
                            Today's Attendance
                        </h4>
                    </div>
                    <div class="mini-stats">
                        <div class="mini-stat">
                            <div class="mini-stat-value" style="color: var(--success);"><?php echo $present_today; ?></div>
                            <div class="mini-stat-label">Present</div>
                        </div>
                        <div class="mini-stat">
                            <div class="mini-stat-value" style="color: var(--danger);"><?php echo $absent_today; ?></div>
                            <div class="mini-stat-label">Absent</div>
                        </div>
                        <div class="mini-stat">
                            <div class="mini-stat-value" style="color: var(--primary);"><?php echo $attendance_rate; ?>%</div>
                            <div class="mini-stat-label">Rate</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create/Edit Event Modal -->
    <div id="eventModal" class="modal">
        <div class="modal-content">
            <form method="POST" action="" id="eventForm" onsubmit="return validateEventForm()">
                <input type="hidden" name="event_id" id="eventId" value="">
                <input type="hidden" name="create_event" id="createEvent" value="1">
                <input type="hidden" name="update_event" id="updateEvent" value="">
                
                <div class="modal-header">
                    <h3 class="modal-title" id="modalTitle">
                        <i class="fas fa-calendar-plus"></i>
                        <span id="modalTitleText">Create New Event</span>
                    </h3>
                    <button type="button" class="btn-close" onclick="closeEventModal()">&times;</button>
                </div>
                
                <div class="modal-body">
                    <div class="form-group">
                        <label for="eventTitle">Event Title *</label>
                        <input type="text" id="eventTitle" name="title" class="form-control" required 
                               placeholder="Enter event title" maxlength="255">
                    </div>
                    
                    <div class="form-group">
                        <label for="eventDescription">Description</label>
                        <textarea id="eventDescription" name="description" class="form-control" 
                                  rows="3" placeholder="Enter event description" maxlength="1000"></textarea>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="eventDate">Date *</label>
                            <input type="date" id="eventDate" name="event_date" class="form-control" required
                                   min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="eventType">Event Type *</label>
                            <select id="eventType" name="event_type" class="form-control" required>
                                <option value="meeting">Staff Meeting</option>
                                <option value="conference">Parent-Teacher Conference</option>
                                <option value="school_event">School Event</option>
                                <option value="exam">Exam Schedule</option>
                                <option value="workshop">Workshop</option>
                                <option value="holiday">Holiday</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="startTime">Start Time</label>
                            <input type="time" id="startTime" name="start_time" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label for="endTime">End Time</label>
                            <input type="time" id="endTime" name="end_time" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="location">Location</label>
                        <input type="text" id="location" name="location" class="form-control" 
                               placeholder="Enter location (e.g., Staff Room, Classroom 101)" maxlength="255">
                    </div>
                    
                    <div class="form-group">
                        <label for="targetAudience">Audience *</label>
                        <input type="text" id="targetAudience" name="target_audience" class="form-control" 
                               required placeholder="e.g., All Teachers, Class 10A, Science Department" maxlength="100">
                        <div class="form-hint">
                            Examples: "All Teachers", "Class 10A", "Science Department", "Grade 7 Students"
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="class_id">Related Class (Optional)</label>
                        <select id="class_id" name="class_id" class="form-control">
                            <option value="">-- No specific class --</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeEventModal()">Cancel</button>
                    <button type="submit" class="btn btn-success" id="modalSubmitBtn">
                        <i class="fas fa-save"></i> Save Event
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- View Event Modal -->
    <div id="viewEventModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-calendar-alt"></i>
                    Event Details
                </h3>
                <button type="button" class="btn-close" onclick="closeViewEventModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div id="eventDetailsContent">
                    <?php if ($view_event): ?>
                    <div class="event-detail-item">
                        <div class="detail-label">Title:</div>
                        <div class="detail-value"><strong><?php echo htmlspecialchars($view_event['title']); ?></strong></div>
                    </div>
                    
                    <div class="event-detail-item">
                        <div class="detail-label">Type:</div>
                        <div class="detail-value">
                            <?php 
                            $type_labels = [
                                'meeting' => 'Staff Meeting',
                                'conference' => 'Parent-Teacher Conference',
                                'school_event' => 'School Event',
                                'exam' => 'Exam Schedule',
                                'workshop' => 'Workshop',
                                'holiday' => 'Holiday',
                                'other' => 'Other'
                            ];
                            echo htmlspecialchars($type_labels[$view_event['event_type']] ?? ucfirst($view_event['event_type']));
                            ?>
                        </div>
                    </div>
                    
                    <div class="event-detail-item">
                        <div class="detail-label">Date:</div>
                        <div class="detail-value">
                            <?php echo htmlspecialchars(date('F j, Y', strtotime($view_event['event_date']))); ?>
                        </div>
                    </div>
                    
                    <?php if ($view_event['start_time']): ?>
                    <div class="event-detail-item">
                        <div class="detail-label">Time:</div>
                        <div class="detail-value">
                            <?php echo htmlspecialchars(date('h:i A', strtotime($view_event['start_time']))); ?>
                            <?php if ($view_event['end_time']): ?>
                            to <?php echo htmlspecialchars(date('h:i A', strtotime($view_event['end_time']))); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($view_event['location']): ?>
                    <div class="event-detail-item">
                        <div class="detail-label">Location:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($view_event['location']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="event-detail-item">
                        <div class="detail-label">Audience:</div>
                        <div class="detail-value">
                            <strong><?php echo htmlspecialchars($view_event['target_audience']); ?></strong>
                            <?php if (!empty($view_event['class_name'])): ?>
                            <br><span style="color: var(--primary);">
                                (<?php echo htmlspecialchars($view_event['class_name']); ?>)
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($view_event['description'])): ?>
                    <div class="event-detail-item">
                        <div class="detail-label">Description:</div>
                        <div class="detail-value">
                            <?php echo nl2br(htmlspecialchars($view_event['description'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="event-detail-item">
                        <div class="detail-label">Created By:</div>
                        <div class="detail-value">
                            <?php echo htmlspecialchars($view_event['created_by_name'] ?? 'Unknown'); ?>
                        </div>
                    </div>
                    
                    <div class="event-detail-item">
                        <div class="detail-label">Created On:</div>
                        <div class="detail-value">
                            <?php echo htmlspecialchars(date('F j, Y \a\t h:i A', strtotime($view_event['created_at']))); ?>
                        </div>
                    </div>
                    
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-exclamation-circle"></i>
                        <p>Event not found or you don't have permission to view it.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($view_event): ?>
            <div class="modal-footer">
                <button type="button" class="btn btn-warning" onclick="editEvent(<?php echo $view_event['id']; ?>)">
                    <i class="fas fa-edit"></i> Edit Event
                </button>
                <button type="button" class="btn btn-danger" 
                        onclick="deleteEvent(<?php echo $view_event['id']; ?>, '<?php echo htmlspecialchars(addslashes($view_event['title'])); ?>')">
                    <i class="fas fa-trash"></i> Delete Event
                </button>
                <button type="button" class="btn btn-outline" onclick="closeViewEventModal()">Close</button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i>
                    Confirm Deletion
                </h3>
                <button type="button" class="btn-close" onclick="closeDeleteModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <form method="POST" action="" id="deleteForm">
                    <input type="hidden" name="event_id" id="deleteEventId" value="">
                    <input type="hidden" name="delete_event" value="1">
                    
                    <div class="delete-confirm" style="text-align: center; padding: 1rem;">
                        <div style="font-size: 4rem; color: var(--danger); margin-bottom: 1rem;">
                            <i class="fas fa-trash-alt"></i>
                        </div>
                        <div class="delete-message" id="deleteMessage" style="margin-bottom: 1.5rem; font-size: 1.1rem;">
                            Are you sure you want to delete this event?
                        </div>
                        <div class="btn-group" style="display: flex; gap: 1rem; justify-content: center;">
                            <button type="button" class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-trash"></i> Delete Event
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Update time
        function updateTime() {
            const now = new Date();
            document.getElementById('currentTime').textContent = now.toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit',
                hour12: true 
            });
        }
        updateTime();
        setInterval(updateTime, 1000);

        // Prepare revenue data
        const revenueData = <?php echo json_encode($monthly_revenue ?: []); ?>;
        const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        
        // Initialize all months with zero
        const monthlyValues = new Array(12).fill(0);
        if (revenueData && revenueData.length > 0) {
            revenueData.forEach(item => {
                if (item && item.month >= 1 && item.month <= 12) {
                    monthlyValues[item.month - 1] = parseFloat(item.total) || 0;
                }
            });
        }

        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart')?.getContext('2d');
        if (revenueCtx) {
            new Chart(revenueCtx, {
                type: 'line',
                data: {
                    labels: monthNames,
                    datasets: [{
                        label: 'Revenue (KES)',
                        data: monthlyValues,
                        borderColor: '#4361ee',
                        backgroundColor: 'rgba(67, 97, 238, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#4361ee',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 8,
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: '#4361ee',
                        pointHoverBorderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#2b2d42',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: '#4361ee',
                            borderWidth: 2,
                            callbacks: {
                                label: function(context) {
                                    return 'KES ' + context.parsed.y.toLocaleString('en-KE', {maximumFractionDigits: 0});
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.05)' },
                            ticks: {
                                callback: function(value) {
                                    return 'KES ' + (value/1000).toFixed(0) + 'k';
                                }
                            }
                        },
                        x: { grid: { display: false } }
                    },
                    animation: {
                        duration: 2000,
                        easing: 'easeInOutQuart'
                    }
                }
            });
        }

        // Fee Status Chart
        const feeStatusData = <?php echo json_encode($fee_status ?: []); ?>;
        const feeLabels = [];
        const feeCounts = [];
        const feeColors = [];
        
        if (feeStatusData && feeStatusData.length > 0) {
            feeStatusData.forEach(item => {
                if (item && parseInt(item.count) > 0) {
                    feeLabels.push(item.status || 'Unknown');
                    feeCounts.push(parseInt(item.count));
                    
                    if (item.status === 'Paid') feeColors.push('#4cc9f0');
                    else if (item.status === 'Unpaid') feeColors.push('#f94144');
                    else if (item.status === 'Partial') feeColors.push('#f8961e');
                    else feeColors.push('#6c757d');
                }
            });
        }

        const feeCtx = document.getElementById('feeStatusChart')?.getContext('2d');
        if (feeCtx) {
            new Chart(feeCtx, {
                type: 'doughnut',
                data: {
                    labels: feeLabels.length ? feeLabels : ['No Data'],
                    datasets: [{
                        data: feeCounts.length ? feeCounts : [1],
                        backgroundColor: feeColors.length ? feeColors : ['#6c757d'],
                        borderColor: '#fff',
                        borderWidth: 3,
                        hoverOffset: 15
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { padding: 20, usePointStyle: true }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = total > 0 ? ((context.raw / total) * 100).toFixed(1) : 0;
                                    return context.label + ': ' + context.raw + ' (' + percentage + '%)';
                                }
                            }
                        }
                    },
                    animation: {
                        animateRotate: true,
                        animateScale: true,
                        duration: 2000
                    }
                }
            });
        }

        // Gender Chart
        const genderData = <?php echo json_encode($gender_stats ?: []); ?>;
        const genderLabels = genderData.map(g => g.gender || 'Not Specified');
        const genderValues = genderData.map(g => parseInt(g.count) || 0);
        const genderColors = ['#FF6B9D', '#4ECDC4', '#45B7D1', '#6c757d'];

        const genderCtx = document.getElementById('genderChart')?.getContext('2d');
        if (genderCtx) {
            new Chart(genderCtx, {
                type: 'bar',
                data: {
                    labels: genderLabels.length ? genderLabels : ['No Data'],
                    datasets: [{
                        label: 'Number of Students',
                        data: genderValues.length ? genderValues : [0],
                        backgroundColor: genderColors.slice(0, genderLabels.length || 1),
                        borderColor: 'transparent',
                        borderRadius: 8,
                        barPercentage: 0.6,
                        categoryPercentage: 0.8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.raw + ' students';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.05)' },
                            ticks: { stepSize: 5 }
                        },
                        x: { grid: { display: false } }
                    },
                    animation: {
                        duration: 2000,
                        easing: 'easeInOutBounce'
                    }
                }
            });
        }

        const inventoryTrendData = <?php echo json_encode($inventory_monthly_trend ?: []); ?>;
        const inventoryTrendCtx = document.getElementById('inventoryTrendChart');
        if (inventoryTrendCtx) {
            new Chart(inventoryTrendCtx, {
                type: 'line',
                data: {
                    labels: inventoryTrendData.map(item => item.month_name),
                    datasets: [
                        {
                            label: 'Items Added',
                            data: inventoryTrendData.map(item => Number(item.items_added)),
                            borderColor: '#17a2b8',
                            backgroundColor: 'rgba(23, 162, 184, 0.15)',
                            tension: 0.35,
                            fill: true
                        },
                        {
                            label: 'Units in Requests',
                            data: inventoryTrendData.map(item => Number(item.stock_units)),
                            borderColor: '#4361ee',
                            backgroundColor: 'rgba(67, 97, 238, 0.12)',
                            tension: 0.35,
                            fill: false
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } }
                }
            });
        }

        const inventoryStatusData = <?php echo json_encode($inventory_status_breakdown ?: []); ?>;
        const inventoryStatusCtx = document.getElementById('inventoryStatusChart');
        if (inventoryStatusCtx) {
            new Chart(inventoryStatusCtx, {
                type: 'doughnut',
                data: {
                    labels: inventoryStatusData.map(item => item.status_group),
                    datasets: [{
                        data: inventoryStatusData.map(item => Number(item.item_count)),
                        backgroundColor: ['#4361ee', '#27ae60', '#f39c12', '#e74c3c', '#17a2b8', '#9b59b6']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } }
                }
            });
        }

        // Skeleton Loading Handler
        document.addEventListener('DOMContentLoaded', function() {
            const mainContent = document.querySelector('.main-content');
            
            window.addEventListener('load', function() {
                if (mainContent) {
                    mainContent.classList.remove('loading');
                }
            });
        });

        if (document.readyState === 'loading') {
            const mainContent = document.querySelector('.main-content');
            if (mainContent) {
                mainContent.classList.add('loading');
            }
        }

        // Show SweetAlert notifications
        <?php if ($show_sweetalert && !empty($response)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: '<?php echo $response['success'] ? 'success' : 'error'; ?>',
                title: '<?php echo $response['success'] ? 'Success!' : 'Error!'; ?>',
                text: '<?php echo addslashes($response['message']); ?>',
                timer: 3000,
                showConfirmButton: true,
                timerProgressBar: true,
                didOpen: (toast) => {
                    toast.addEventListener('mouseenter', Swal.stopTimer);
                    toast.addEventListener('mouseleave', Swal.resumeTimer);
                }
            });
        });
        <?php endif; ?>

        // Event Modal Functions
        let currentEventId = null;
        let currentEventTitle = '';
        
        function openEventModal(eventData = null) {
            const modal = document.getElementById('eventModal');
            const titleText = document.getElementById('modalTitleText');
            const submitBtn = document.getElementById('modalSubmitBtn');
            const updateField = document.getElementById('updateEvent');
            const createField = document.getElementById('createEvent');
            const eventIdField = document.getElementById('eventId');
            
            // Reset form
            const form = document.getElementById('eventForm');
            if (form) form.reset();
            
            if (eventData) {
                // Edit mode
                titleText.textContent = 'Edit Event';
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Update Event';
                updateField.value = '1';
                createField.disabled = true;
                eventIdField.value = eventData.id || '';
                
                // Fill form with event data
                document.getElementById('eventTitle').value = eventData.title || '';
                document.getElementById('eventDescription').value = eventData.description || '';
                document.getElementById('eventDate').value = eventData.event_date || '';
                document.getElementById('eventType').value = eventData.event_type || 'meeting';
                document.getElementById('startTime').value = eventData.start_time || '';
                document.getElementById('endTime').value = eventData.end_time || '';
                document.getElementById('location').value = eventData.location || '';
                document.getElementById('targetAudience').value = eventData.target_audience || '';
                if (document.getElementById('class_id')) {
                    document.getElementById('class_id').value = eventData.class_id || '';
                }
            } else {
                // Create mode
                titleText.textContent = 'Create New Event';
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Save Event';
                updateField.value = '';
                createField.disabled = false;
                eventIdField.value = '';
                
                // Set default date to today
                const today = new Date().toISOString().split('T')[0];
                document.getElementById('eventDate').value = today;
            }
            
            modal.classList.add('active');
        }
        
        function closeEventModal() {
            document.getElementById('eventModal').classList.remove('active');
        }
        
        function viewEvent(eventId) {
            window.location.href = '?view_event=' + eventId;
        }
        
        function closeViewEventModal() {
            window.location.href = window.location.pathname;
        }
        
        function editEvent(eventId) {
            closeViewEventModal();
            window.location.href = '?edit_event=' + eventId;
        }
        
        function deleteEvent(eventId, eventTitle) {
            currentEventId = eventId;
            currentEventTitle = eventTitle;
            
            const modal = document.getElementById('deleteModal');
            const message = document.getElementById('deleteMessage');
            const eventIdField = document.getElementById('deleteEventId');
            
            if (message) {
                message.innerHTML = `Are you sure you want to delete "<strong>${eventTitle}</strong>"? This action cannot be undone.`;
            }
            if (eventIdField) {
                eventIdField.value = eventId;
            }
            
            modal.classList.add('active');
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }
        
        // Form validation
        function validateEventForm() {
            const title = document.getElementById('eventTitle').value.trim();
            const date = document.getElementById('eventDate').value;
            const audience = document.getElementById('targetAudience').value.trim();
            
            if (!title) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Please enter an event title'
                });
                return false;
            }
            
            if (!date) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Please select an event date'
                });
                return false;
            }
            
            if (!audience) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Please specify the target audience'
                });
                return false;
            }
            
            return true;
        }
        
        // Close modals when clicking outside
        document.addEventListener('click', function(e) {
            const modals = ['eventModal', 'viewEventModal', 'deleteModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (modal && e.target === modal) {
                    if (modalId === 'eventModal') closeEventModal();
                    if (modalId === 'viewEventModal') closeViewEventModal();
                    if (modalId === 'deleteModal') closeDeleteModal();
                }
            });
        });
        
        // Close modals on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeEventModal();
                closeViewEventModal();
                closeDeleteModal();
            }
        });
        
        // Auto-open edit modal if edit parameter is present
        <?php if ($edit_event): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const eventData = {
                id: <?php echo json_encode($edit_event['id'] ?? ''); ?>,
                title: <?php echo json_encode($edit_event['title'] ?? ''); ?>,
                description: <?php echo json_encode($edit_event['description'] ?? ''); ?>,
                event_date: <?php echo json_encode($edit_event['event_date'] ?? ''); ?>,
                event_type: <?php echo json_encode($edit_event['event_type'] ?? 'meeting'); ?>,
                start_time: <?php echo json_encode($edit_event['start_time'] ?? ''); ?>,
                end_time: <?php echo json_encode($edit_event['end_time'] ?? ''); ?>,
                location: <?php echo json_encode($edit_event['location'] ?? ''); ?>,
                target_audience: <?php echo json_encode($edit_event['target_audience'] ?? 'all_teachers'); ?>,
                class_id: <?php echo json_encode($edit_event['class_id'] ?? ''); ?>
            };
            openEventModal(eventData);
        });
        <?php endif; ?>
        
        // Auto-open view modal if view parameter is present
        <?php if ($view_event): ?>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('viewEventModal').classList.add('active');
        });
        <?php endif; ?>
    </script>
</body>
</html>
