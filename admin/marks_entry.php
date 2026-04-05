<?php
include '../config.php';
checkAuth();
checkRole(['admin']);

$schedule_id = $_GET['schedule_id'] ?? null;
if (!$schedule_id) {
    header("Location: exam_schedules.php");
    exit();
}

// Get schedule details
$stmt = $pdo->prepare("
    SELECT 
        es.*,
        e.exam_name,
        e.exam_code,
        e.total_marks,
        e.passing_marks,
        c.class_name,
        s.subject_name
    FROM exam_schedules es
    JOIN exams e ON es.exam_id = e.id
    JOIN classes c ON es.class_id = c.id
    LEFT JOIN subjects s ON es.subject_id = s.id
    WHERE es.id = ?
");
$stmt->execute([$schedule_id]);
$schedule = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$schedule) {
    header("Location: exam_schedules.php");
    exit();
}

$error = '';
$success = '';

// Handle mark updates from admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_marks'])) {
    try {
        $pdo->beginTransaction();
        
        foreach ($_POST['marks'] as $mark_id => $marks) {
            $marks = floatval($marks);
            $remarks = $_POST['remarks'][$mark_id] ?? null;
            
            if ($marks < 0 || $marks > $schedule['total_marks']) {
                throw new Exception("Invalid marks for entry ID $mark_id. Must be between 0 and {$schedule['total_marks']}");
            }
            
            $grade = getGradeForMarks($marks);
            
            // Update mark with admin review
            $stmt = $pdo->prepare("
                UPDATE exam_marks 
                SET marks_obtained = ?, grade = ?, remarks = ?
                WHERE id = ? AND exam_schedule_id = ?
            ");
            
            $stmt->execute([$marks, $grade, $remarks, $mark_id, $schedule_id]);
        }
        
        $pdo->commit();
        $success = 'Marks updated successfully!';
        
        // Refresh to show updated data
        header("Refresh: 1; url=" . $_SERVER['REQUEST_URI']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'Error updating marks: ' . $e->getMessage();
    }
}

// Get all marks for this schedule
$stmt = $pdo->prepare("
    SELECT 
        em.*,
        CONCAT(s.full_name) as student_name,
        s.admission_number,
        CONCAT(u.full_name) as teacher_full_name
    FROM exam_marks em
    JOIN students s ON em.student_id = s.id
    JOIN users u ON em.entered_by = u.id
    WHERE em.exam_schedule_id = ? AND em.submission_status = 'submitted'
    ORDER BY s.full_name
");
$stmt->execute([$schedule_id]);
$marks_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_marks,
        COUNT(DISTINCT student_id) as unique_students,
        AVG(marks_obtained) as average_marks,
        MAX(marks_obtained) as highest_marks,
        MIN(marks_obtained) as lowest_marks,
        SUM(CASE WHEN marks_obtained >= ? THEN 1 ELSE 0 END) as passed
    FROM exam_marks
    WHERE exam_schedule_id = ? AND submission_status = 'submitted'
");
$stmt->execute([$schedule['passing_marks'], $schedule_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$page_title = 'Review Marks - ' . SCHOOL_NAME;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            background: #f8f9fa;
            min-height: calc(100vh - 70px);
            position: relative;
            z-index: 1;
            width: calc(100% - 280px);
        }
        
        .card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 0.8rem;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .stat-boxes {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.2rem;
            border-radius: 8px;
            text-align: center;
        }
        
        .stat-box.average {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .stat-box.highest {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        
        .stat-box.lowest {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }
        
        .stat-box.passed {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: bold;
        }
        
        .stat-label {
            font-size: 0.85rem;
            opacity: 0.9;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }
        
        .table th {
            background: #ecf0f1;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #bdc3c7;
        }
        
        .table td {
            padding: 0.8rem 1rem;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .table tr:hover {
            background: #f8f9fa;
        }
        
        .marks-input {
            width: 100px;
            padding: 0.5rem;
            border: 2px solid #ecf0f1;
            border-radius: 4px;
        }
        
        .marks-input:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        .remarks-input {
            width: 200px;
            padding: 0.5rem;
            border: 1px solid #ecf0f1;
            border-radius: 4px;
            resize: vertical;
        }
        
        .grade-badge {
            display: inline-block;
            padding: 0.3rem 0.6rem;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.85rem;
            background: #ecf0f1;
            color: #2c3e50;
        }
        
        .btn-group {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
        }
        
        .btn-secondary {
            background: #95a5a6;
            color: white;
        }
        
        .header-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .info-box {
            background: #ecf0f1;
            padding: 1rem;
            border-radius: 6px;
        }
        
        .info-label {
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.9rem;
        }
        
        .info-value {
            color: #555;
            margin-top: 0.3rem;
            font-size: 0.95rem;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
                width: 100%;
            }
            
            .table {
                font-size: 0.85rem;
            }
            
            .table-container {
                overflow-x: auto;
            }
            
            .marks-input, .remarks-input {
                width: 100%;
                max-width: 150px;
            }
            
            .btn-group {
                flex-direction: column;
                width: 100%;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .stat-box {
                padding: 1rem;
            }
            
            .stat-value {
                font-size: 1.5rem;
            }
        }
        
        /* SweetAlert2 z-index fix */
        .swal2-container {
            z-index: 2000 !important;
        }
        
        .swal2-modal {
            z-index: 2001 !important;
        }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container" style="max-width: 1200px; margin: 0 auto;">
            <div style="margin-bottom: 2rem;">
                <a href="exam_schedules.php" style="color: #3498db; text-decoration: none;">
                    <i class="fas fa-arrow-left"></i> Back to Schedules
                </a>
                <h1 style="color: #2c3e50; margin: 0.5rem 0 0;">
                    Review Submitted Marks
                </h1>
            </div>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo $success; ?></div>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                </div>
            <?php endif; ?>
            
            <!-- Exam Information -->
            <div class="card">
                <div class="header-info">
                    <div class="info-box">
                        <div class="info-label">Exam Name</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($schedule['exam_name']); ?>
                        </div>
                    </div>
                    
                    <div class="info-box">
                        <div class="info-label">Exam Code</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($schedule['exam_code']); ?>
                        </div>
                    </div>
                    
                    <div class="info-box">
                        <div class="info-label">Class</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($schedule['class_name']); ?>
                        </div>
                    </div>
                    
                    <div class="info-box">
                        <div class="info-label">Total Marks</div>
                        <div class="info-value">
                            <?php echo $schedule['total_marks']; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Statistics -->
            <?php if ($stats['total_marks'] > 0): ?>
                <div class="card">
                    <h3>Entry Statistics</h3>
                    <div class="stat-boxes">
                        <div class="stat-box">
                            <div class="stat-value"><?php echo $stats['unique_students']; ?></div>
                            <div class="stat-label">Total Students</div>
                        </div>
                        <div class="stat-box passed">
                            <div class="stat-value"><?php echo $stats['passed']; ?></div>
                            <div class="stat-label">Passed</div>
                        </div>
                        <div class="stat-box average">
                            <div class="stat-value"><?php echo number_format($stats['average_marks'], 1); ?></div>
                            <div class="stat-label">Average Marks</div>
                        </div>
                        <div class="stat-box highest">
                            <div class="stat-value"><?php echo number_format($stats['highest_marks'], 1); ?></div>
                            <div class="stat-label">Highest Marks</div>
                        </div>
                        <div class="stat-box lowest">
                            <div class="stat-value"><?php echo number_format($stats['lowest_marks'], 1); ?></div>
                            <div class="stat-label">Lowest Marks</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Marks Review Form -->
            <div class="card">
                <?php if (!empty($marks_list)): ?>
                    <form method="POST">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Admission No.</th>
                                    <th>Submitted Marks</th>
                                    <th>Grade</th>
                                    <th>Entered By</th>
                                    <th>Remarks</th>
                                    <th>Submitted At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($marks_list as $mark): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($mark['student_name']); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($mark['admission_number']); ?>
                                        </td>
                                        <td>
                                            <input type="number" 
                                                   class="marks-input" 
                                                   name="marks[<?php echo $mark['id']; ?>]"
                                                   min="0" 
                                                   max="<?php echo $schedule['total_marks']; ?>" 
                                                   step="0.01"
                                                   value="<?php echo $mark['marks_obtained']; ?>">
                                        </td>
                                        <td>
                                            <span class="grade-badge">
                                                <?php echo htmlspecialchars($mark['grade']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small><?php echo htmlspecialchars($mark['teacher_full_name']); ?></small>
                                        </td>
                                        <td>
                                            <textarea class="remarks-input" 
                                                      name="remarks[<?php echo $mark['id']; ?>]"
                                                      rows="2" 
                                                      placeholder="Add remarks..."><?php echo htmlspecialchars($mark['remarks'] ?? ''); ?></textarea>
                                        </td>
                                        <td>
                                            <small style="color: #7f8c8d;">
                                                <?php echo isset($mark['created_at']) && $mark['created_at'] ? date('M d, H:i', strtotime($mark['created_at'])) : 'N/A'; ?>
                                            </small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <div class="btn-group">
                            <button type="submit" name="update_marks" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save All Changes
                            </button>
                            <a href="exam_schedules.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                <?php else: ?>
                    <div style="padding: 2rem; text-align: center; color: #7f8c8d;">
                        <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                        <p>No submitted marks yet. Check back when teachers have submitted marks for this exam.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
</body>
</html>
