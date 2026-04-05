<?php
include '../config.php';
checkAuth();
checkRole(['admin', 'accountant']);

$page_title = 'Manage Fee Structures - ' . SCHOOL_NAME;
$fee_structure_item_columns = array_fill_keys(
    $pdo->query("SHOW COLUMNS FROM fee_structure_items")->fetchAll(PDO::FETCH_COLUMN),
    true
);
$has_fee_item_category = isset($fee_structure_item_columns['category']);
$has_fee_item_sort_order = isset($fee_structure_item_columns['sort_order']);

// Verify user session is valid
function verifyUserSession($pdo) {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        return ['valid' => false, 'message' => 'User not logged in'];
    }
    
    $stmt = $pdo->prepare("SELECT id, full_name, role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        session_destroy();
        return ['valid' => false, 'message' => 'User account not found'];
    }
    
    return ['valid' => true, 'user' => $user];
}

$userCheck = verifyUserSession($pdo);
if (!$userCheck['valid']) {
    $_SESSION['error'] = $userCheck['message'];
    header('Location: login.php');
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Verify user again for POST requests
        $userCheck = verifyUserSession($pdo);
        if (!$userCheck['valid']) {
            throw new Exception($userCheck['message']);
        }
        $user_id = $userCheck['user']['id'];
        
        if (isset($_POST['create_structure'])) {
            // Validate required fields
            $required = ['structure_name', 'class_id', 'term', 'academic_year'];
            foreach ($required as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Please fill in all required fields");
                }
            }
            
            $structure_name = trim($_POST['structure_name']);
            $class_id = intval($_POST['class_id']);
            $term = intval($_POST['term']);
            $academic_year = trim($_POST['academic_year']);
            $description = trim($_POST['description'] ?? '');
            
            // Verify class exists
            $class_check = $pdo->prepare("SELECT id FROM classes WHERE id = ?");
            $class_check->execute([$class_id]);
            if (!$class_check->fetch()) {
                throw new Exception("Selected class not found.");
            }
            
            // Look up academic_year ID (if it's a foreign key)
            $ay_stmt = $pdo->prepare("SELECT id FROM academic_years WHERE year = ?");
            $ay_stmt->execute([$academic_year]);
            $ay_row = $ay_stmt->fetch();
            if (!$ay_row) {
                throw new Exception("Academic year '{$academic_year}' not found. Please create it first.");
            }
            $academic_year_id = $ay_row['id'];
            
            // Check if structure name already exists for this class/term/year
            $check = $pdo->prepare("
                SELECT id FROM fee_structures 
                WHERE structure_name = ? AND class_id = ? AND term = ? AND academic_year_id = ?
            ");
            $check->execute([$structure_name, $class_id, $term, $academic_year_id]);
            if ($check->fetch()) {
                throw new Exception("A fee structure with this name already exists for this class, term, and year.");
            }
            
            $pdo->beginTransaction();
            
            // Insert fee structure
            $stmt = $pdo->prepare("
                INSERT INTO fee_structures (
                    structure_name, class_id, term, academic_year_id, 
                    description, created_by, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'draft', NOW())
            ");
            $stmt->execute([
                $structure_name, $class_id, $term, $academic_year_id,
                $description, $user_id
            ]);
            $structure_id = $pdo->lastInsertId();
            
            // Insert fee items
            if (isset($_POST['items']) && is_array($_POST['items'])) {
                $item_stmt = $pdo->prepare("
                    INSERT INTO fee_structure_items (
                        fee_structure_id, item_name, description, amount, 
                        is_mandatory, created_at
                    ) VALUES (?, ?, ?, ?, ?, NOW())
                ");
                
                foreach ($_POST['items'] as $item) {
                    if (!empty($item['name']) && !empty($item['amount'])) {
                        $item_stmt->execute([
                            $structure_id,
                            trim($item['name']),
                            trim($item['description'] ?? ''),
                            floatval($item['amount']),
                            isset($item['mandatory']) ? 1 : 0
                        ]);
                    }
                }
            }
            
            $pdo->commit();
            $_SESSION['success'] = "Fee structure '{$structure_name}' created successfully!";
            
        } elseif (isset($_POST['update_structure'])) {
            $structure_id = intval($_POST['structure_id']);
            $structure_name = trim($_POST['structure_name']);
            $class_id = intval($_POST['class_id']);
            $term = intval($_POST['term']);
            $academic_year = trim($_POST['academic_year']);
            $description = trim($_POST['description'] ?? '');
            
            // Look up academic_year ID (if it's a foreign key)
            $ay_stmt = $pdo->prepare("SELECT id FROM academic_years WHERE year = ?");
            $ay_stmt->execute([$academic_year]);
            $ay_row = $ay_stmt->fetch();
            if (!$ay_row) {
                throw new Exception("Academic year '{$academic_year}' not found.");
            }
            $academic_year_id = $ay_row['id'];
            
            // Verify structure exists and is in draft status
            $check = $pdo->prepare("SELECT id, status FROM fee_structures WHERE id = ?");
            $check->execute([$structure_id]);
            $structure = $check->fetch();
            
            if (!$structure) {
                throw new Exception("Fee structure not found.");
            }
            
            if ($structure['status'] !== 'draft') {
                throw new Exception("Only draft structures can be edited.");
            }
            
            $pdo->beginTransaction();
            
            // Update fee structure
            $stmt = $pdo->prepare("
                UPDATE fee_structures 
                SET structure_name = ?, class_id = ?, term = ?, 
                    academic_year = ?, description = ?
                WHERE id = ? AND status = 'draft'
            ");
            $stmt->execute([
                $structure_name, $class_id, $term, $academic_year_id,
                $description, $structure_id
            ]);
            
            // Delete existing items
            $stmt = $pdo->prepare("DELETE FROM fee_structure_items WHERE fee_structure_id = ?");
            $stmt->execute([$structure_id]);
            
            // Insert updated items
            if (isset($_POST['items']) && is_array($_POST['items'])) {
                $item_stmt = $pdo->prepare("
                    INSERT INTO fee_structure_items (
                        fee_structure_id, item_name, description, amount, 
                        is_mandatory, created_at
                    ) VALUES (?, ?, ?, ?, ?, NOW())
                ");
                
                foreach ($_POST['items'] as $item) {
                    if (!empty($item['name']) && !empty($item['amount'])) {
                        $item_stmt->execute([
                            $structure_id,
                            trim($item['name']),
                            trim($item['description'] ?? ''),
                            floatval($item['amount']),
                            isset($item['mandatory']) ? 1 : 0
                        ]);
                    }
                }
            }
            
            $pdo->commit();
            $_SESSION['success'] = "Fee structure '{$structure_name}' updated successfully!";
            
        } elseif (isset($_POST['duplicate_structure'])) {
            $source_id = intval($_POST['source_id']);
            $new_name = trim($_POST['new_name']);
            
            if (empty($new_name)) {
                throw new Exception("Please enter a name for the duplicated structure.");
            }
            
            $pdo->beginTransaction();
            
            // Get source structure
            $stmt = $pdo->prepare("SELECT * FROM fee_structures WHERE id = ?");
            $stmt->execute([$source_id]);
            $source = $stmt->fetch();
            
            if (!$source) {
                throw new Exception('Source fee structure not found');
            }
            
            // Check if name already exists
            $check = $pdo->prepare("
                SELECT id FROM fee_structures 
                WHERE structure_name = ? AND class_id = ? AND term = ? AND academic_year = ?
            ");
            $check->execute([$new_name, $source['class_id'], $source['term'], $source['academic_year']]);
            if ($check->fetch()) {
                throw new Exception("A structure with this name already exists for this class, term, and year.");
            }
            
            // Create duplicate
            $stmt = $pdo->prepare("
                INSERT INTO fee_structures (
                    structure_name, class_id, term, academic_year, 
                    description, created_by, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'draft', NOW())
            ");
            $stmt->execute([
                $new_name,
                $source['class_id'],
                $source['term'],
                $source['academic_year'],
                $source['description'],
                $user_id
            ]);
            $new_id = $pdo->lastInsertId();
            
            // Copy items
            $stmt = $pdo->prepare("SELECT * FROM fee_structure_items WHERE fee_structure_id = ?");
            $stmt->execute([$source_id]);
            $items = $stmt->fetchAll();
            
            if (!empty($items)) {
                $clone_item_columns = ['fee_structure_id', 'item_name', 'description', 'amount', 'is_mandatory'];
                if ($has_fee_item_category) {
                    $clone_item_columns[] = 'category';
                }
                if ($has_fee_item_sort_order) {
                    $clone_item_columns[] = 'sort_order';
                }

                $item_stmt = $pdo->prepare("
                    INSERT INTO fee_structure_items (
                        " . implode(', ', $clone_item_columns) . ", created_at
                    ) VALUES (" . implode(', ', array_fill(0, count($clone_item_columns), '?')) . ", NOW())
                ");
                
                foreach ($items as $item) {
                    $clone_values = [
                        $new_id,
                        $item['item_name'],
                        $item['description'],
                        $item['amount'],
                        $item['is_mandatory']
                    ];

                    if ($has_fee_item_category) {
                        $clone_values[] = $item['category'] ?? 'general';
                    }

                    if ($has_fee_item_sort_order) {
                        $clone_values[] = $item['sort_order'] ?? 0;
                    }

                    $item_stmt->execute($clone_values);
                }
            }
            
            $pdo->commit();
            $_SESSION['success'] = "Fee structure duplicated successfully as '{$new_name}'!";
            
        } elseif (isset($_POST['delete_structure'])) {
            $structure_id = intval($_POST['structure_id']);
            
            // Check if structure exists
            $check = $pdo->prepare("SELECT structure_name, status FROM fee_structures WHERE id = ?");
            $check->execute([$structure_id]);
            $structure = $check->fetch();
            
            if (!$structure) {
                throw new Exception("Fee structure not found.");
            }
            
            if ($structure['status'] !== 'draft') {
                throw new Exception("Only draft structures can be deleted.");
            }
            
            // Check if structure is used in invoices
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE fee_structure_id = ?");
            $stmt->execute([$structure_id]);
            $invoice_count = $stmt->fetchColumn();
            
            if ($invoice_count > 0) {
                throw new Exception("Cannot delete: This structure is used in {$invoice_count} invoices");
            }
            
            $pdo->beginTransaction();
            
            // Delete items first
            $stmt = $pdo->prepare("DELETE FROM fee_structure_items WHERE fee_structure_id = ?");
            $stmt->execute([$structure_id]);
            
            // Delete structure
            $stmt = $pdo->prepare("DELETE FROM fee_structures WHERE id = ?");
            $stmt->execute([$structure_id]);
            
            $pdo->commit();
            $_SESSION['success'] = "Fee structure '{$structure['structure_name']}' deleted successfully!";
            
        } elseif (isset($_POST['submit_for_approval'])) {
            $structure_id = intval($_POST['structure_id']);
            
            // Get structure details
            $stmt = $pdo->prepare("
                SELECT fs.*, COUNT(fsi.id) as item_count 
                FROM fee_structures fs
                LEFT JOIN fee_structure_items fsi ON fs.id = fsi.fee_structure_id
                WHERE fs.id = ? AND fs.status = 'draft'
                GROUP BY fs.id
            ");
            $stmt->execute([$structure_id]);
            $structure = $stmt->fetch();
            
            if (!$structure) {
                throw new Exception("Structure not found or cannot be submitted (must be in draft status).");
            }
            
            if ($structure['item_count'] == 0) {
                throw new Exception("Cannot submit an empty fee structure. Please add at least one fee item.");
            }
            
            // Update status to pending
            $stmt = $pdo->prepare("
                UPDATE fee_structures 
                SET status = 'pending', submitted_at = NOW()
                WHERE id = ? AND status = 'draft'
            ");
            $stmt->execute([$structure_id]);
            
            notifyApprovalRequestSubmitted(
                'Fee Structure Approval Request',
                "New fee structure '{$structure['structure_name']}' has been submitted for approval.",
                $structure_id,
                'fee_structure'
            );
            
            $_SESSION['success'] = "Fee structure submitted for approval! Admin has been notified.";
        }
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Fee Structure Error: " . $e->getMessage());
        $_SESSION['error'] = $e->getMessage();
    }
    
    header("Location: fee_structures_manage.php");
    exit();
}

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$class_filter = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'newest';

// Build query
$params = [];
$query = "
    SELECT 
        fs.*,
        c.class_name,
        u.full_name as created_by_name,
        a.full_name as approved_by_name,
        s.full_name as submitted_by_name,
        (SELECT COUNT(*) FROM fee_structure_items WHERE fee_structure_id = fs.id) as item_count,
        (SELECT SUM(amount) FROM fee_structure_items WHERE fee_structure_id = fs.id) as total_amount,
        (SELECT COUNT(*) FROM fee_structure_items WHERE fee_structure_id = fs.id AND is_mandatory = 1) as mandatory_count,
        (SELECT COUNT(*) FROM students WHERE class_id = fs.class_id AND status = 'active') as student_count,
        (SELECT COUNT(*) FROM invoices WHERE fee_structure_id = fs.id) as invoice_count
    FROM fee_structures fs
    LEFT JOIN classes c ON fs.class_id = c.id
    LEFT JOIN users u ON fs.created_by = u.id
    LEFT JOIN users a ON fs.approved_by = a.id
    LEFT JOIN users s ON fs.submitted_by = s.id
    WHERE 1=1
";

if ($status_filter) {
    $query .= " AND fs.status = ?";
    $params[] = $status_filter;
}

if ($class_filter) {
    $query .= " AND fs.class_id = ?";
    $params[] = $class_filter;
}

if ($search) {
    $query .= " AND (fs.structure_name LIKE ? OR fs.description LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
}

// Apply sorting
switch ($sort) {
    case 'oldest':
        $query .= " ORDER BY fs.created_at ASC";
        break;
    case 'name_asc':
        $query .= " ORDER BY fs.structure_name ASC";
        break;
    case 'name_desc':
        $query .= " ORDER BY fs.structure_name DESC";
        break;
    case 'amount_asc':
        $query .= " ORDER BY total_amount ASC";
        break;
    case 'amount_desc':
        $query .= " ORDER BY total_amount DESC";
        break;
    case 'newest':
    default:
        $query .= " ORDER BY fs.created_at DESC";
        break;
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$structures = $stmt->fetchAll();

// Get classes for dropdown
$classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll();

// Get statistics
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN status = 'draft' THEN 1 END) as draft_count,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
        COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_count,
        COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_count,
        COALESCE(SUM(CASE WHEN status = 'approved' THEN 
            (SELECT SUM(amount) FROM fee_structure_items WHERE fee_structure_id = fee_structures.id) 
            ELSE 0 END), 0) as total_approved_value
    FROM fee_structures
")->fetch();

// Get item categories
$categories = $pdo->query("
    SELECT DISTINCT category FROM fee_structure_items WHERE category IS NOT NULL ORDER BY category
")->fetchAll(PDO::FETCH_COLUMN);

// Get academic years for filter
$academic_years = $pdo->query("
    SELECT DISTINCT academic_year_id FROM fee_structures ORDER BY academic_year_id DESC
")->fetchAll(PDO::FETCH_COLUMN);
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
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
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card.total { border-left-color: var(--primary); }
        .stat-card.draft { border-left-color: var(--gray); }
        .stat-card.pending { border-left-color: var(--warning); }
        .stat-card.approved { border-left-color: var(--success); }
        .stat-card.rejected { border-left-color: var(--danger); }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-detail {
            font-size: 0.85rem;
            color: var(--gray);
            margin-top: 0.5rem;
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        /* Sort Bar */
        .sort-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .sort-options {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .sort-btn {
            padding: 0.5rem 1rem;
            background: var(--white);
            border: 1px solid var(--light);
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .sort-btn:hover,
        .sort-btn.active {
            background: var(--gradient-1);
            color: white;
            border-color: transparent;
        }

        .result-count {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Structures Grid */
        .structures-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 1.5rem;
        }

        .structure-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            transition: var(--transition);
            border: 1px solid var(--light);
            position: relative;
            height: fit-content;
        }

        .structure-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
            border-color: var(--primary);
        }

        .status-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            z-index: 1;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-draft {
            background: rgba(108, 117, 125, 0.15);
            color: var(--gray);
            border: 1px solid rgba(108, 117, 125, 0.3);
        }

        .status-pending {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
            border: 1px solid rgba(248, 150, 30, 0.3);
        }

        .status-approved {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
            border: 1px solid rgba(76, 201, 240, 0.3);
        }

        .status-rejected {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
            border: 1px solid rgba(249, 65, 68, 0.3);
        }

        .card-header {
            padding: 1.5rem 1.5rem 0 1.5rem;
        }

        .structure-name {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.75rem;
            padding-right: 80px;
            line-height: 1.4;
        }

        .structure-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.85rem;
            color: var(--gray);
            flex-wrap: wrap;
            margin-bottom: 0.75rem;
        }

        .structure-meta i {
            width: 16px;
            color: var(--primary);
        }

        .class-badge {
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
            padding: 0.2rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            display: inline-block;
            font-weight: 500;
        }

        .card-body {
            padding: 1.5rem;
        }

        .description {
            color: var(--dark);
            margin-bottom: 1rem;
            line-height: 1.5;
            padding: 0.75rem;
            background: var(--light);
            border-radius: var(--border-radius-md);
            font-size: 0.9rem;
        }

        .items-preview {
            margin: 1rem 0;
        }

        .item-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px dashed var(--light);
            font-size: 0.9rem;
        }

        .item-row:last-child {
            border-bottom: none;
        }

        .item-info {
            flex: 1;
        }

        .item-name {
            font-weight: 500;
            color: var(--dark);
        }

        .item-category {
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
            border-radius: 4px;
            margin-left: 0.5rem;
        }

        .item-mandatory {
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
            border-radius: 4px;
            margin-left: 0.5rem;
        }

        .item-amount {
            font-weight: 600;
            color: var(--primary);
            min-width: 80px;
            text-align: right;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.5rem;
            margin: 1rem 0;
            padding: 0.75rem;
            background: var(--light);
            border-radius: var(--border-radius-md);
        }

        .stat-item {
            text-align: center;
        }

        .stat-item .value {
            font-size: 1rem;
            font-weight: 700;
            color: var(--dark);
        }

        .stat-item .label {
            font-size: 0.65rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .total-amount {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: var(--gradient-1);
            color: white;
            border-radius: var(--border-radius-md);
            font-weight: 600;
            margin-top: 1rem;
        }

        .total-amount span:last-child {
            font-size: 1.2rem;
        }

        .card-footer {
            padding: 1rem 1.5rem;
            background: var(--light);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .creator-info {
            font-size: 0.8rem;
            color: var(--gray);
        }

        .creator-info strong {
            color: var(--dark);
        }

        .action-buttons {
            display: flex;
            gap: 0.3rem;
            flex-wrap: wrap;
        }

        .action-btn {
            width: 34px;
            height: 34px;
            border-radius: var(--border-radius-sm);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: var(--transition);
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .action-btn.view { background: var(--primary); }
        .action-btn.edit { background: var(--warning); }
        .action-btn.copy { background: var(--info); }
        .action-btn.delete { background: var(--danger); }
        .action-btn.submit { background: var(--success); }

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
            max-width: 800px;
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
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.05) 0%, rgba(63, 55, 201, 0.05) 100%);
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
            font-size: 1.3rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
            transition: var(--transition);
            width: 36px;
            height: 36px;
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
            position: sticky;
            bottom: 0;
            background: white;
        }

        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
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

        /* Fee Items */
        .fee-items-container {
            background: var(--light);
            border-radius: var(--border-radius-md);
            padding: 1rem;
            margin: 1rem 0;
            max-height: 400px;
            overflow-y: auto;
        }

        .fee-item {
            background: white;
            border-radius: var(--border-radius-md);
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid var(--light);
            transition: var(--transition);
        }

        .fee-item:hover {
            border-color: var(--primary);
            box-shadow: var(--shadow-sm);
        }

        .fee-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .fee-item-title {
            font-weight: 600;
            color: var(--dark);
        }

        .fee-item-actions {
            display: flex;
            gap: 0.3rem;
        }

        .fee-item-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1.5fr auto;
            gap: 0.5rem;
            align-items: center;
        }

        .fee-item input,
        .fee-item select {
            padding: 0.5rem;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-sm);
            font-size: 0.9rem;
        }

        .fee-item input:focus,
        .fee-item select:focus {
            border-color: var(--primary);
            outline: none;
        }

        .fee-item-checkbox {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            white-space: nowrap;
        }

        .fee-item-checkbox input[type="checkbox"] {
            width: 16px;
            height: 16px;
        }

        .btn-remove-item {
            background: rgba(249, 65, 68, 0.1);
            color: var(--danger);
            border: none;
            width: 32px;
            height: 32px;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-remove-item:hover {
            background: var(--danger);
            color: white;
        }

        .btn-add-item {
            background: var(--light);
            color: var(--primary);
            border: 2px dashed var(--primary);
            width: 100%;
            padding: 1rem;
            border-radius: var(--border-radius-md);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-add-item:hover {
            background: rgba(67, 97, 238, 0.1);
        }

        /* Total Preview */
        .total-preview {
            background: var(--gradient-1);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius-md);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            margin: 1rem 0;
        }

        .total-preview span:last-child {
            font-size: 1.3rem;
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
            padding: 4rem 2rem;
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--gray-light);
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--gray);
            margin-bottom: 1.5rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .structures-grid {
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
                text-align: center;
                gap: 1rem;
            }
            
            .header-actions {
                justify-content: center;
            }
            
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .card-footer {
                flex-direction: column;
                text-align: center;
            }
            
            .action-buttons {
                justify-content: center;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .fee-item-grid {
                grid-template-columns: 1fr;
                gap: 0.5rem;
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
        .stagger-item:nth-child(2) { animation-delay: 0.2s; }
        .stagger-item:nth-child(3) { animation-delay: 0.3s; }
        .stagger-item:nth-child(4) { animation-delay: 0.4s; }
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
                <h1><i class="fas fa-calculator"></i> Manage Fee Structures</h1>
                <p>Create, edit, and manage fee structures for all classes</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-success" onclick="openCreateModal()">
                    <i class="fas fa-plus"></i> New Structure
                </button>
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <a href="fee_structure_approvals.php" class="btn btn-warning">
                    <i class="fas fa-check-double"></i> Approvals (<?php echo $stats['pending_count']; ?>)
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success animate">
            <div>
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); ?>
            </div>
            <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: inherit; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger animate">
            <div>
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); ?>
            </div>
            <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: inherit; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['error']); endif; ?>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card total stagger-item" onclick="window.location.href='fee_structures_manage.php'">
                <div class="stat-number"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total Structures</div>
            </div>
            <div class="stat-card draft stagger-item" onclick="window.location.href='fee_structures_manage.php?status=draft'">
                <div class="stat-number"><?php echo $stats['draft_count']; ?></div>
                <div class="stat-label">Drafts</div>
            </div>
            <div class="stat-card pending stagger-item" onclick="window.location.href='fee_structures_manage.php?status=pending'">
                <div class="stat-number"><?php echo $stats['pending_count']; ?></div>
                <div class="stat-label">Pending Approval</div>
            </div>
            <div class="stat-card approved stagger-item" onclick="window.location.href='fee_structures_manage.php?status=approved'">
                <div class="stat-number"><?php echo $stats['approved_count']; ?></div>
                <div class="stat-label">Approved</div>
                <div class="stat-detail">KES <?php echo number_format($stats['total_approved_value'], 0); ?> total</div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section animate">
            <div class="filter-header">
                <h3><i class="fas fa-filter"></i> Filter & Search</h3>
                <span class="result-count"><?php echo count($structures); ?> structure(s) found</span>
            </div>
            <form method="GET" id="filterForm">
                <div class="filter-grid">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Name or description..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="">All Status</option>
                            <option value="draft" <?php echo $status_filter == 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
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
                        <label>&nbsp;</label>
                        <div style="display: flex; gap: 0.5rem;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply
                            </button>
                            <a href="fee_structures_manage.php" class="btn btn-outline">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Sort Bar -->
        <div class="sort-bar animate">
            <div class="sort-options">
                <button class="sort-btn <?php echo $sort == 'newest' ? 'active' : ''; ?>" onclick="window.location.href='fee_structures_manage.php?<?php echo http_build_query(array_merge($_GET, ['sort' => 'newest'])); ?>'">
                    <i class="fas fa-clock"></i> Newest
                </button>
                <button class="sort-btn <?php echo $sort == 'oldest' ? 'active' : ''; ?>" onclick="window.location.href='fee_structures_manage.php?<?php echo http_build_query(array_merge($_GET, ['sort' => 'oldest'])); ?>'">
                    <i class="fas fa-history"></i> Oldest
                </button>
                <button class="sort-btn <?php echo $sort == 'name_asc' ? 'active' : ''; ?>" onclick="window.location.href='fee_structures_manage.php?<?php echo http_build_query(array_merge($_GET, ['sort' => 'name_asc'])); ?>'">
                    <i class="fas fa-sort-alpha-down"></i> Name A-Z
                </button>
                <button class="sort-btn <?php echo $sort == 'amount_desc' ? 'active' : ''; ?>" onclick="window.location.href='fee_structures_manage.php?<?php echo http_build_query(array_merge($_GET, ['sort' => 'amount_desc'])); ?>'">
                    <i class="fas fa-sort-amount-down"></i> Highest Amount
                </button>
            </div>
        </div>

        <!-- Fee Structures Grid -->
        <?php if (!empty($structures)): ?>
        <div class="structures-grid">
            <?php foreach ($structures as $structure): ?>
            <div class="structure-card animate" id="structure-<?php echo $structure['id']; ?>">
                <span class="status-badge status-<?php echo $structure['status']; ?>">
                    <?php echo ucfirst($structure['status']); ?>
                </span>
                
                <div class="card-header">
                    <div class="structure-name"><?php echo htmlspecialchars($structure['structure_name']); ?></div>
                    <div class="structure-meta">
                        <span><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($structure['class_name']); ?></span>
                        <span><i class="fas fa-calendar"></i> Term <?php echo $structure['term']; ?></span>
                        <span><i class="fas fa-book"></i> <?php echo $structure['academic_year_id']; ?></span>
                    </div>
                </div>

                <div class="card-body">
                    <?php if (!empty($structure['description'])): ?>
                    <div class="description">
                        <?php echo nl2br(htmlspecialchars($structure['description'])); ?>
                    </div>
                    <?php endif; ?>

                    <!-- Quick Stats -->
                    <div class="stats-row">
                        <div class="stat-item">
                            <div class="value"><?php echo $structure['item_count']; ?></div>
                            <div class="label">Items</div>
                        </div>
                        <div class="stat-item">
                            <div class="value"><?php echo $structure['mandatory_count']; ?></div>
                            <div class="label">Required</div>
                        </div>
                        <div class="stat-item">
                            <div class="value"><?php echo $structure['student_count']; ?></div>
                            <div class="label">Students</div>
                        </div>
                        <div class="stat-item">
                            <div class="value"><?php echo $structure['invoice_count']; ?></div>
                            <div class="label">Invoices</div>
                        </div>
                    </div>

                    <!-- Items Preview -->
                    <div class="items-preview">
                        <?php
                        $item_order_by = $has_fee_item_sort_order
                            ? 'is_mandatory DESC, sort_order, item_name'
                            : 'is_mandatory DESC, item_name';
                        $item_stmt = $pdo->prepare("
                            SELECT * FROM fee_structure_items 
                            WHERE fee_structure_id = ? 
                            ORDER BY $item_order_by
                            LIMIT 3
                        ");
                        $item_stmt->execute([$structure['id']]);
                        $items = $item_stmt->fetchAll();
                        ?>
                        <?php foreach ($items as $item): ?>
                        <div class="item-row">
                            <div class="item-info">
                                <span class="item-name"><?php echo htmlspecialchars($item['item_name']); ?></span>
                                <?php if ($item['is_mandatory']): ?>
                                <span class="item-mandatory">Required</span>
                                <?php endif; ?>
                            </div>
                            <span class="item-amount">KES <?php echo number_format($item['amount'], 2); ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if ($structure['item_count'] > 3): ?>
                        <div class="item-row" style="color: var(--gray); font-style: italic;">
                            <span>+ <?php echo $structure['item_count'] - 3; ?> more items</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Total Amount -->
                    <div class="total-amount">
                        <span>Total per Student</span>
                        <span>KES <?php echo number_format($structure['total_amount'] ?? 0, 2); ?></span>
                    </div>

                    <!-- Creator Info -->
                    <div class="creator-info" style="margin-top: 0.75rem;">
                        <i class="far fa-user"></i> <strong><?php echo htmlspecialchars($structure['created_by_name'] ?? 'System'); ?></strong>
                        <span style="margin-left: 0.5rem; color: var(--gray);"><?php echo date('d M Y', strtotime($structure['created_at'])); ?></span>
                    </div>
                </div>

                <div class="card-footer">
                    <div class="action-buttons">
                        <button class="action-btn view" onclick="viewStructure(<?php echo $structure['id']; ?>)" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                        
                        <?php if ($structure['status'] == 'draft' && ($structure['created_by'] == $_SESSION['user_id'] || $_SESSION['role'] == 'admin')): ?>
                        <button class="action-btn edit" onclick="editStructure(<?php echo $structure['id']; ?>)" title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="action-btn copy" onclick="duplicateStructure(<?php echo $structure['id']; ?>, '<?php echo htmlspecialchars(addslashes($structure['structure_name'])); ?>')" title="Duplicate">
                            <i class="fas fa-copy"></i>
                        </button>
                        <button class="action-btn delete" onclick="deleteStructure(<?php echo $structure['id']; ?>, '<?php echo htmlspecialchars(addslashes($structure['structure_name'])); ?>')" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                        <?php if ($structure['item_count'] > 0): ?>
                        <button class="action-btn submit" onclick="submitForApproval(<?php echo $structure['id']; ?>)" title="Submit for Approval">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                        <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php if ($structure['status'] == 'approved'): ?>
                        <a href="generate_invoices.php?structure_id=<?php echo $structure['id']; ?>" class="action-btn view" title="Generate Invoices">
                            <i class="fas fa-file-invoice"></i>
                        </a>
                        <?php endif; ?>
                        
                        <?php if ($structure['status'] == 'rejected' && $_SESSION['role'] == 'admin'): ?>
                        <span class="text-danger" style="font-size: 0.7rem;">Rejected</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state animate">
            <i class="fas fa-calculator"></i>
            <h3>No Fee Structures Found</h3>
            <p>Create your first fee structure to get started</p>
            <button class="btn btn-success" onclick="openCreateModal()">
                <i class="fas fa-plus"></i> Create Structure
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Create/Edit Fee Structure Modal -->
    <div id="structureModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus-circle"></i> Create Fee Structure</h3>
                <button class="modal-close" onclick="closeModal('structureModal')">&times;</button>
            </div>
            <form id="structureForm" method="POST">
                <input type="hidden" name="structure_id" id="structure_id">
                <input type="hidden" name="create_structure" id="create_structure" value="1">
                <input type="hidden" name="update_structure" id="update_structure">
                
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label class="required">Structure Name</label>
                            <input type="text" name="structure_name" id="structure_name" class="form-control" required 
                                   placeholder="e.g., 2024 Term 1 Fees" maxlength="255">
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Class</label>
                            <select name="class_id" id="class_id" class="form-control" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Term</label>
                            <select name="term" id="term" class="form-control" required>
                                <option value="">Select Term</option>
                                <option value="1">Term 1</option>
                                <option value="2">Term 2</option>
                                <option value="3">Term 3</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Academic Year</label>
                            <input type="text" name="academic_year" id="academic_year" class="form-control" required 
                                   placeholder="e.g., 2024" value="<?php echo date('Y'); ?>" maxlength="20">
                        </div>
                        
                        <div class="form-group full-width">
                            <label>Description</label>
                            <textarea name="description" id="description" class="form-control" rows="2" 
                                      placeholder="Optional description..." maxlength="500"></textarea>
                        </div>
                    </div>

                    <h4 style="margin: 1.5rem 0 0.75rem; color: var(--dark);">
                        <i class="fas fa-list-ul"></i> Fee Items
                    </h4>
                    
                    <div id="feeItemsContainer" class="fee-items-container">
                        <!-- Fee items will be dynamically added here -->
                    </div>
                    
                    <button type="button" class="btn-add-item" onclick="addFeeItem()">
                        <i class="fas fa-plus"></i> Add Fee Item
                    </button>

                    <div id="totalPreview" class="total-preview" style="margin-top: 1.5rem;">
                        <span>Total per Student:</span>
                        <span id="totalAmount">KES 0.00</span>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('structureModal')">Cancel</button>
                    <button type="submit" class="btn btn-success" id="submitBtn">
                        <i class="fas fa-save"></i> Create Structure
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Duplicate Modal -->
    <div id="duplicateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-copy"></i> Duplicate Fee Structure</h3>
                <button class="modal-close" onclick="closeModal('duplicateModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="source_id" id="source_id">
                <div class="modal-body">
                    <p style="margin-bottom: 1rem;">Enter a name for the duplicated fee structure:</p>
                    <div class="form-group">
                        <label class="required">New Structure Name</label>
                        <input type="text" name="new_name" id="new_name" class="form-control" required maxlength="255">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('duplicateModal')">Cancel</button>
                    <button type="submit" name="duplicate_structure" class="btn btn-info">
                        <i class="fas fa-copy"></i> Duplicate
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        let itemCount = 0;
        let categories = <?php echo json_encode($categories); ?>;

        // Modal Functions
        function openCreateModal() {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Create Fee Structure';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save"></i> Create Structure';
            document.getElementById('create_structure').value = '1';
            document.getElementById('update_structure').value = '';
            document.getElementById('structure_id').value = '';
            document.getElementById('structureForm').reset();
            
            // Clear and add empty item
            document.getElementById('feeItemsContainer').innerHTML = '';
            addFeeItem();
            
            calculateTotal();
            openModal('structureModal');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Fee Item Management
        function addFeeItem(item = null) {
            const container = document.getElementById('feeItemsContainer');
            const itemId = Date.now() + itemCount++;
            
            // Create category options
            let categoryOptions = '<option value="">Select Category</option>';
            categories.forEach(cat => {
                categoryOptions += `<option value="${cat}" ${item && item.category == cat ? 'selected' : ''}>${cat}</option>`;
            });
            categoryOptions += '<option value="other">+ Add New</option>';
            
            const itemDiv = document.createElement('div');
            itemDiv.className = 'fee-item';
            itemDiv.id = `item-${itemId}`;
            
            itemDiv.innerHTML = `
                <div class="fee-item-header">
                    <span class="fee-item-title">Item ${container.children.length + 1}</span>
                    <div class="fee-item-actions">
                        <button type="button" class="btn-remove-item" onclick="removeFeeItem('item-${itemId}')" title="Remove Item">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                <div class="fee-item-grid">
                    <input type="text" name="items[${itemId}][name]" placeholder="Item Name" value="${item ? escapeHtml(item.item_name) : ''}" required>
                    <input type="number" name="items[${itemId}][amount]" placeholder="Amount" step="0.01" min="0" value="${item ? item.amount : ''}" required onchange="calculateTotal()">
                    <select name="items[${itemId}][category]" onchange="handleCategoryChange(this, '${itemId}')">
                        ${categoryOptions}
                    </select>
                    <div class="fee-item-checkbox">
                        <input type="checkbox" name="items[${itemId}][mandatory]" id="mandatory-${itemId}" ${item && item.is_mandatory ? 'checked' : ''}>
                        <label for="mandatory-${itemId}">Required</label>
                    </div>
                </div>
                <input type="text" name="items[${itemId}][description]" placeholder="Description (optional)" value="${item ? escapeHtml(item.description || '') : ''}" style="width: 100%; margin-top: 0.5rem; padding: 0.5rem;">
            `;
            
            container.appendChild(itemDiv);
        }

        function handleCategoryChange(select, itemId) {
            if (select.value === 'other') {
                Swal.fire({
                    title: 'Add New Category',
                    input: 'text',
                    inputLabel: 'Category Name',
                    inputPlaceholder: 'e.g., Sports, Library, Development',
                    showCancelButton: true,
                    confirmButtonColor: '#4361ee',
                    confirmButtonText: 'Add',
                    cancelButtonText: 'Cancel',
                    inputValidator: (value) => {
                        if (!value) {
                            return 'Please enter a category name';
                        }
                    }
                }).then((result) => {
                    if (result.isConfirmed && result.value) {
                        const newCategory = result.value.trim();
                        categories.push(newCategory);
                        
                        // Update all category selects
                        document.querySelectorAll('select[name*="[category]"]').forEach(selectEl => {
                            const option = document.createElement('option');
                            option.value = newCategory;
                            option.textContent = newCategory;
                            selectEl.appendChild(option);
                        });
                        
                        // Set this select to the new category
                        select.value = newCategory;
                    } else {
                        select.value = '';
                    }
                });
            }
        }

        function removeFeeItem(itemId) {
            Swal.fire({
                title: 'Remove Item?',
                text: 'Are you sure you want to remove this fee item?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f94144',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, remove'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById(itemId).remove();
                    calculateTotal();
                }
            });
        }

        function calculateTotal() {
            let total = 0;
            document.querySelectorAll('input[name*="[amount]"]').forEach(input => {
                const amount = parseFloat(input.value) || 0;
                total += amount;
            });
            document.getElementById('totalAmount').textContent = 'KES ' + formatNumber(total);
        }

        // Edit Structure
        function editStructure(structureId) {
            Swal.fire({
                title: 'Loading...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            fetch(`get_structure.php?id=${structureId}`)
                .then(response => response.json())
                .then(data => {
                    Swal.close();
                    
                    if (data.success) {
                        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Fee Structure';
                        document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save"></i> Update Structure';
                        document.getElementById('create_structure').value = '';
                        document.getElementById('update_structure').value = '1';
                        document.getElementById('structure_id').value = structureId;
                        
                        // Fill form
                        document.getElementById('structure_name').value = data.structure.structure_name || '';
                        document.getElementById('class_id').value = data.structure.class_id || '';
                        document.getElementById('term').value = data.structure.term || '';
                        document.getElementById('academic_year').value = data.structure.academic_year || '';
                        document.getElementById('description').value = data.structure.description || '';
                        
                        // Clear and add items
                        document.getElementById('feeItemsContainer').innerHTML = '';
                        if (data.items && data.items.length > 0) {
                            data.items.forEach(item => addFeeItem(item));
                        } else {
                            addFeeItem();
                        }
                        
                        calculateTotal();
                        openModal('structureModal');
                    } else {
                        Swal.fire('Error', data.message || 'Failed to load structure', 'error');
                    }
                })
                .catch(error => {
                    Swal.close();
                    Swal.fire('Error', 'Failed to load structure', 'error');
                    console.error(error);
                });
        }

        // View Structure
        function viewStructure(structureId) {
            window.location.href = 'fee_structure_details.php?id=' + structureId;
        }

        // Duplicate Structure
        function duplicateStructure(structureId, structureName) {
            document.getElementById('source_id').value = structureId;
            document.getElementById('new_name').value = structureName + ' (Copy)';
            openModal('duplicateModal');
        }

        // Submit for Approval
        function submitForApproval(structureId) {
            Swal.fire({
                title: 'Submit for Approval?',
                text: 'This fee structure will be sent to admin for review.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#4cc9f0',
                confirmButtonText: 'Yes, submit',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="submit_for_approval" value="1">
                        <input type="hidden" name="structure_id" value="${structureId}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Delete Structure
        function deleteStructure(structureId, structureName) {
            Swal.fire({
                title: 'Delete Structure?',
                html: `Are you sure you want to delete "<strong>${escapeHtml(structureName)}</strong>"?<br>This action cannot be undone.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f94144',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="delete_structure" value="1">
                        <input type="hidden" name="structure_id" value="${structureId}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Helper Functions
        function formatNumber(num) {
            return num.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Calculate total on any input change
            document.addEventListener('input', function(e) {
                if (e.target.name && e.target.name.includes('[amount]')) {
                    calculateTotal();
                }
            });

            // Close modal on outside click
            window.onclick = function(event) {
                if (event.target.classList.contains('modal')) {
                    event.target.classList.remove('active');
                    document.body.style.overflow = 'auto';
                }
            };

            // Close modal on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.modal.active').forEach(modal => {
                        modal.classList.remove('active');
                        document.body.style.overflow = 'auto';
                    });
                }
            });
        });
    </script>
</body>
</html>
