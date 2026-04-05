<?php
include '../config.php';
checkAuth();
checkRole(['accountant', 'admin']);

$page_title = 'Fee Structures - ' . SCHOOL_NAME;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['add_structure'])) {
            $structure_name = trim($_POST['structure_name']);
            $class_id = (int)$_POST['class_id'];
            $term = (int)$_POST['term'];
            $academic_year = trim($_POST['academic_year']);
            $description = trim($_POST['description'] ?? '');
            
            $pdo->beginTransaction();
            
            // Insert fee structure
            $stmt = $pdo->prepare("
                INSERT INTO fee_structures (structure_name, class_id, term, academic_year, description, created_by, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$structure_name, $class_id, $term, $academic_year, $description, $_SESSION['user_id']]);
            $structure_id = $pdo->lastInsertId();
            
            // Insert fee items
            if (isset($_POST['items']) && is_array($_POST['items'])) {
                $item_stmt = $pdo->prepare("
                    INSERT INTO fee_structure_items (fee_structure_id, item_name, amount, is_mandatory, description) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                foreach ($_POST['items'] as $item) {
                    if (!empty($item['name']) && !empty($item['amount'])) {
                        $item_stmt->execute([
                            $structure_id,
                            trim($item['name']),
                            (float)$item['amount'],
                            isset($item['mandatory']) ? 1 : 0,
                            trim($item['description'] ?? '')
                        ]);
                    }
                }
            }
            
            $pdo->commit();
            $_SESSION['success'] = "Fee structure created successfully and sent for approval!";
            
        } elseif (isset($_POST['update_structure'])) {
            $structure_id = (int)$_POST['structure_id'];
            $structure_name = trim($_POST['structure_name']);
            $class_id = (int)$_POST['class_id'];
            $term = (int)$_POST['term'];
            $academic_year = trim($_POST['academic_year']);
            $description = trim($_POST['description'] ?? '');
            
            $pdo->beginTransaction();
            
            // Update fee structure
            $stmt = $pdo->prepare("
                UPDATE fee_structures 
                SET structure_name = ?, class_id = ?, term = ?, academic_year = ?, description = ?
                WHERE id = ? AND created_by = ? AND status = 'draft'
            ");
            $stmt->execute([$structure_name, $class_id, $term, $academic_year, $description, $structure_id, $_SESSION['user_id']]);
            
            // Delete existing items
            $stmt = $pdo->prepare("DELETE FROM fee_structure_items WHERE fee_structure_id = ?");
            $stmt->execute([$structure_id]);
            
            // Insert updated items
            if (isset($_POST['items']) && is_array($_POST['items'])) {
                $item_stmt = $pdo->prepare("
                    INSERT INTO fee_structure_items (fee_structure_id, item_name, amount, is_mandatory, description) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                foreach ($_POST['items'] as $item) {
                    if (!empty($item['name']) && !empty($item['amount'])) {
                        $item_stmt->execute([
                            $structure_id,
                            trim($item['name']),
                            (float)$item['amount'],
                            isset($item['mandatory']) ? 1 : 0,
                            trim($item['description'] ?? '')
                        ]);
                    }
                }
            }
            
            $pdo->commit();
            $_SESSION['success'] = "Fee structure updated successfully!";
            
        } elseif (isset($_POST['delete_structure'])) {
            $structure_id = (int)$_POST['structure_id'];
            
            $stmt = $pdo->prepare("DELETE FROM fee_structures WHERE id = ? AND created_by = ? AND status = 'draft'");
            $stmt->execute([$structure_id, $_SESSION['user_id']]);
            
            $_SESSION['success'] = "Fee structure deleted successfully!";
        }
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    
    header("Location: fee_structures.php");
    exit();
}

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$class_filter = $_GET['class_id'] ?? '';
$year_filter = $_GET['academic_year'] ?? '';

// Build query
$query = "
    SELECT 
        fs.*,
        c.class_name,
        u.full_name as created_by_name,
        (SELECT COUNT(*) FROM fee_structure_items WHERE fee_structure_id = fs.id) as item_count,
        (SELECT SUM(amount) FROM fee_structure_items WHERE fee_structure_id = fs.id) as total_amount
    FROM fee_structures fs
    LEFT JOIN classes c ON fs.class_id = c.id
    LEFT JOIN users u ON fs.created_by = u.id
    WHERE 1=1
";

$params = [];

if ($status_filter) {
    $query .= " AND fs.status = ?";
    $params[] = $status_filter;
}

if ($class_filter) {
    $query .= " AND fs.class_id = ?";
    $params[] = $class_filter;
}

if ($year_filter) {
    $query .= " AND fs.academic_year = ?";
    $params[] = $year_filter;
}

$query .= " ORDER BY fs.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$fee_structures = $stmt->fetchAll();

// Get classes for dropdown
$classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll();

// Get academic years
$academic_years = $pdo->query("SELECT DISTINCT academic_year FROM fee_structures ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_COLUMN);

// Get statistics
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
    FROM fee_structures
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
            position: relative;
            overflow: hidden;
            border-left: 4px solid;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card.total { border-left-color: var(--primary); }
        .stat-card.approved { border-left-color: var(--success); }
        .stat-card.pending { border-left-color: var(--warning); }
        .stat-card.draft { border-left-color: var(--gray); }
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
            border-radius: var(--border-radius-sm);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .form-group input:focus,
        .form-group select:focus {
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

        /* Data Cards */
        .structures-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .structure-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            transition: var(--transition);
            border: 1px solid rgba(255,255,255,0.3);
        }

        .structure-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .card-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border-bottom: 2px solid var(--light);
            position: relative;
        }

        .status-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .status-approved {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .status-pending {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        .status-draft {
            background: rgba(108, 117, 125, 0.15);
            color: var(--gray);
        }

        .status-rejected {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        .structure-name {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
            padding-right: 80px;
        }

        .structure-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
        }

        .structure-meta i {
            width: 16px;
            color: var(--primary);
        }

        .card-body {
            padding: 1.5rem;
        }

        .items-list {
            margin-bottom: 1rem;
        }

        .item-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px dashed var(--light);
        }

        .item-name {
            font-weight: 500;
            color: var(--dark);
        }

        .item-amount {
            font-weight: 600;
            color: var(--primary);
        }

        .item-mandatory {
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
            border-radius: 4px;
            margin-left: 0.5rem;
        }

        .total-amount {
            display: flex;
            justify-content: space-between;
            padding: 1rem 0;
            font-weight: 700;
            color: var(--dark);
            border-top: 2px solid var(--light);
        }

        .total-amount span:last-child {
            color: var(--success);
            font-size: 1.2rem;
        }

        .card-footer {
            padding: 1rem 1.5rem;
            background: var(--light);
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            border-top: 1px solid var(--border-color);
        }

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

        /* Fee Items */
        .fee-items-container {
            background: var(--light);
            border-radius: var(--border-radius-md);
            padding: 1rem;
            margin: 1rem 0;
        }

        .fee-item {
            background: white;
            border-radius: var(--border-radius-sm);
            padding: 1rem;
            margin-bottom: 1rem;
            display: grid;
            grid-template-columns: 1fr 120px 100px auto;
            gap: 0.5rem;
            align-items: center;
            border: 1px solid var(--light);
        }

        .fee-item:last-child {
            margin-bottom: 0;
        }

        .fee-item input[type="text"],
        .fee-item input[type="number"] {
            padding: 0.5rem;
            border: 2px solid var(--light);
            border-radius: var(--border-radius-sm);
        }

        .fee-item input[type="text"]:focus,
        .fee-item input[type="number"]:focus {
            border-color: var(--primary);
            outline: none;
        }

        .fee-item-checkbox {
            display: flex;
            align-items: center;
            gap: 0.3rem;
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
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
        }

        .btn-add-item:hover {
            background: rgba(67, 97, 238, 0.1);
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
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
            
            .header-actions {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .fee-item {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success animate" style="padding: 1rem 1.5rem; border-radius: var(--border-radius-md); margin-bottom: 1.5rem; background: rgba(76, 201, 240, 0.1); border-left: 4px solid var(--success); color: var(--success); display: flex; justify-content: space-between; align-items: center;">
            <div>
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; ?>
            </div>
            <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: inherit; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger animate" style="padding: 1rem 1.5rem; border-radius: var(--border-radius-md); margin-bottom: 1.5rem; background: rgba(249, 65, 68, 0.1); border-left: 4px solid var(--danger); color: var(--danger); display: flex; justify-content: space-between; align-items: center;">
            <div>
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; ?>
            </div>
            <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; color: inherit; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['error']); endif; ?>

        <!-- Page Header -->
        <div class="page-header animate">
            <div>
                <h1><i class="fas fa-calculator"></i> Fee Structures</h1>
                <p>Create and manage fee structures for different classes</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-success" onclick="openCreateModal()">
                    <i class="fas fa-plus"></i> New Structure
                </button>
                <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="fee_structure_approvals.php" class="btn btn-warning">
                    <i class="fas fa-check-double"></i> Approvals
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card total stagger-item">
                <div class="stat-number"><?php echo $stats['total'] ?? 0; ?></div>
                <div class="stat-label">Total Structures</div>
            </div>
            <div class="stat-card approved stagger-item">
                <div class="stat-number"><?php echo $stats['approved'] ?? 0; ?></div>
                <div class="stat-label">Approved</div>
            </div>
            <div class="stat-card pending stagger-item">
                <div class="stat-number"><?php echo $stats['pending'] ?? 0; ?></div>
                <div class="stat-label">Pending Approval</div>
            </div>
            <div class="stat-card draft stagger-item">
                <div class="stat-number"><?php echo $stats['draft'] ?? 0; ?></div>
                <div class="stat-label">Drafts</div>
            </div>
            <div class="stat-card rejected stagger-item">
                <div class="stat-number"><?php echo $stats['rejected'] ?? 0; ?></div>
                <div class="stat-label">Rejected</div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section animate">
            <form method="GET" id="filterForm">
                <div class="filter-grid">
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Status</label>
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="draft" <?php echo $status_filter == 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-graduation-cap"></i> Class</label>
                        <select name="class_id">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar"></i> Academic Year</label>
                        <select name="academic_year">
                            <option value="">All Years</option>
                            <?php foreach ($academic_years as $year): ?>
                            <option value="<?php echo $year; ?>" <?php echo $year_filter == $year ? 'selected' : ''; ?>>
                                <?php echo $year; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="display: flex; gap: 0.5rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <a href="fee_structures.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Fee Structures Grid -->
        <?php if (!empty($fee_structures)): ?>
        <div class="structures-grid">
            <?php foreach ($fee_structures as $structure): ?>
            <div class="structure-card animate">
                <div class="card-header">
                    <span class="status-badge status-<?php echo $structure['status']; ?>">
                        <?php echo ucfirst($structure['status']); ?>
                    </span>
                    <div class="structure-name"><?php echo htmlspecialchars($structure['structure_name']); ?></div>
                    <div class="structure-meta">
                        <span><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($structure['class_name']); ?></span>
                        <span><i class="fas fa-calendar"></i> Term <?php echo $structure['term']; ?></span>
                        <span><i class="fas fa-book"></i> <?php echo $structure['academic_year']; ?></span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="items-list">
                        <?php
                        // Get items for this structure
                        $item_stmt = $pdo->prepare("SELECT * FROM fee_structure_items WHERE fee_structure_id = ? LIMIT 3");
                        $item_stmt->execute([$structure['id']]);
                        $items = $item_stmt->fetchAll();
                        ?>
                        <?php foreach ($items as $item): ?>
                        <div class="item-row">
                            <span class="item-name">
                                <?php echo htmlspecialchars($item['item_name']); ?>
                                <?php if ($item['is_mandatory']): ?>
                                <span class="item-mandatory">Required</span>
                                <?php endif; ?>
                            </span>
                            <span class="item-amount">KES <?php echo number_format($item['amount'], 2); ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if ($structure['item_count'] > 3): ?>
                        <div class="item-row" style="color: var(--gray); font-style: italic;">
                            <span>+ <?php echo $structure['item_count'] - 3; ?> more items</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="total-amount">
                        <span>Total Amount</span>
                        <span>KES <?php echo number_format($structure['total_amount'] ?? 0, 2); ?></span>
                    </div>
                    <?php if ($structure['description']): ?>
                    <div style="font-size: 0.9rem; color: var(--gray); margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px dashed var(--light);">
                        <i class="fas fa-align-left"></i> <?php echo htmlspecialchars(substr($structure['description'], 0, 100)) . (strlen($structure['description']) > 100 ? '...' : ''); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer">
                    <button class="btn btn-sm btn-outline" onclick="viewStructure(<?php echo $structure['id']; ?>)">
                        <i class="fas fa-eye"></i> View
                    </button>
                    <?php if ($structure['status'] == 'draft' && $structure['created_by'] == $_SESSION['user_id']): ?>
                    <button class="btn btn-sm btn-warning" onclick="editStructure(<?php echo $structure['id']; ?>)">
                        <i class="fas fa-edit"></i> Edit
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="deleteStructure(<?php echo $structure['id']; ?>, '<?php echo htmlspecialchars(addslashes($structure['structure_name'])); ?>')">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                    <?php endif; ?>
                    <?php if ($structure['status'] == 'approved'): ?>
                    <a href="generate_invoices.php?structure_id=<?php echo $structure['id']; ?>" class="btn btn-sm btn-success">
                        <i class="fas fa-file-invoice"></i> Generate
                    </a>
                    <?php endif; ?>
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
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form id="structureForm" method="POST">
                <input type="hidden" name="structure_id" id="structure_id">
                <input type="hidden" name="add_structure" id="add_structure" value="1">
                <input type="hidden" name="update_structure" id="update_structure">
                
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label class="required">Structure Name</label>
                            <input type="text" name="structure_name" id="structure_name" class="form-control" required placeholder="e.g., 2024 Term 1 Fees">
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
                            <input type="text" name="academic_year" id="academic_year" class="form-control" required placeholder="e.g., 2024">
                        </div>
                        
                        <div class="form-group full-width">
                            <label>Description</label>
                            <textarea name="description" id="description" class="form-control" rows="2" placeholder="Optional description..."></textarea>
                        </div>
                    </div>

                    <h4 style="margin: 1rem 0; color: var(--dark);">Fee Items</h4>
                    <div id="feeItemsContainer" class="fee-items-container">
                        <!-- Fee items will be dynamically added here -->
                    </div>
                    
                    <button type="button" class="btn-add-item" onclick="addFeeItem()">
                        <i class="fas fa-plus"></i> Add Fee Item
                    </button>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-success" id="submitBtn">
                        <i class="fas fa-save"></i> Create Structure
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        let itemCount = 0;

        // Modal Functions
        function openCreateModal() {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Create Fee Structure';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save"></i> Create Structure';
            document.getElementById('add_structure').value = '1';
            document.getElementById('update_structure').value = '';
            document.getElementById('structure_id').value = '';
            document.getElementById('structureForm').reset();
            
            // Clear and add empty item
            document.getElementById('feeItemsContainer').innerHTML = '';
            addFeeItem();
            
            document.getElementById('structureModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('structureModal').classList.remove('active');
        }

        // Fee Item Management
        function addFeeItem(item = null) {
            const container = document.getElementById('feeItemsContainer');
            const itemId = Date.now() + itemCount++;
            
            const itemDiv = document.createElement('div');
            itemDiv.className = 'fee-item';
            itemDiv.id = `item-${itemId}`;
            
            itemDiv.innerHTML = `
                <input type="text" name="items[${itemId}][name]" placeholder="Item Name" value="${item ? item.item_name : ''}" required>
                <input type="number" name="items[${itemId}][amount]" placeholder="Amount" step="0.01" min="0" value="${item ? item.amount : ''}" required>
                <div class="fee-item-checkbox">
                    <input type="checkbox" name="items[${itemId}][mandatory]" id="mandatory-${itemId}" ${item && item.is_mandatory ? 'checked' : ''}>
                    <label for="mandatory-${itemId}">Required</label>
                </div>
                <button type="button" class="btn-remove-item" onclick="removeFeeItem('item-${itemId}')">
                    <i class="fas fa-trash"></i>
                </button>
            `;
            
            container.appendChild(itemDiv);
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
                }
            });
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
                        document.getElementById('add_structure').value = '';
                        document.getElementById('update_structure').value = '1';
                        document.getElementById('structure_id').value = structureId;
                        
                        // Fill form
                        document.getElementById('structure_name').value = data.structure.structure_name;
                        document.getElementById('class_id').value = data.structure.class_id;
                        document.getElementById('term').value = data.structure.term;
                        document.getElementById('academic_year').value = data.structure.academic_year;
                        document.getElementById('description').value = data.structure.description || '';
                        
                        // Clear and add items
                        document.getElementById('feeItemsContainer').innerHTML = '';
                        data.items.forEach(item => addFeeItem(item));
                        
                        document.getElementById('structureModal').classList.add('active');
                    } else {
                        Swal.fire('Error', data.message, 'error');
                    }
                })
                .catch(error => {
                    Swal.close();
                    Swal.fire('Error', 'Failed to load structure', 'error');
                });
        }

        // View Structure Details
        function viewStructure(structureId) {
            window.location.href = 'fee_structure_details.php?id=' + structureId;
        }

        // Delete Structure
        function deleteStructure(structureId, structureName) {
            Swal.fire({
                title: 'Delete Structure?',
                html: `Are you sure you want to delete "<strong>${structureName}</strong>"?<br>This action cannot be undone.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f94144',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it'
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

        // Initialize with one empty item when modal opens
        document.addEventListener('DOMContentLoaded', function() {
            // Close modal on outside click
            window.onclick = function(event) {
                const modal = document.getElementById('structureModal');
                if (event.target == modal) {
                    closeModal();
                }
            };
        });
    </script>
</body>
</html>