<?php
// enter_marks.php
require_once '../config.php';
checkAuth();
checkRole(['teacher', 'admin']);

$schedule_id = isset($_GET['schedule_id']) ? intval($_GET['schedule_id']) : 0;
if (!$schedule_id) {
    header("Location: exam_marks_entry.php");
    exit();
}

$teacher_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Verify exam schedule exists and is open
try {
    $stmt = $pdo->prepare("
        SELECT 
            es.id,
            es.exam_id,
            es.class_id,
            es.subject_id,
            es.status,
            es.portal_open_date,
            es.portal_close_date,
            e.exam_name,
            e.total_marks,
            e.passing_marks,
            c.class_name,
            s.subject_name
        FROM exam_schedules es
        JOIN exams e ON es.exam_id = e.id
        JOIN classes c ON es.class_id = c.id
        LEFT JOIN subjects s ON es.subject_id = s.id
        WHERE es.id = ?
          AND (
              es.teacher_id = ?
              OR s.teacher_id = ?
              OR c.class_teacher_id = ?
          )
          AND (es.status = 'open' OR NOW() BETWEEN es.portal_open_date AND es.portal_close_date)
    ");
    $stmt->execute([$schedule_id, $teacher_id, $teacher_id, $teacher_id]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$exam) {
        header("Location: exam_marks_entry.php");
        exit();
    }
} catch (Exception $e) {
    $error_message = "Error loading exam schedule: " . $e->getMessage();
}

// Handle marks submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_marks'])) {
    if (isset($_POST['marks']) && is_array($_POST['marks'])) {
        $success_count = 0;
        $error_count = 0;
        
        try {
            $pdo->beginTransaction();
            
            foreach ($_POST['marks'] as $student_id => $marks_obtained) {
                $student_id = intval($student_id);
                $marks_obtained = floatval($marks_obtained);
                $remarks = $_POST['remarks'][$student_id] ?? '';
                
                // Validate marks
                if ($marks_obtained < 0 || $marks_obtained > $exam['total_marks']) {
                    throw new Exception("Invalid marks for student ID $student_id");
                }
                
                // Check if marks already exist
                $check_stmt = $pdo->prepare("SELECT id FROM exam_marks WHERE exam_schedule_id = ? AND student_id = ? LIMIT 1");
                $check_stmt->execute([$schedule_id, $student_id]);
                $existing = $check_stmt->fetch();
                
                if ($existing) {
                    // Update existing marks
                    $update_stmt = $pdo->prepare("
                        UPDATE exam_marks 
                        SET marks_obtained = ?, remarks = ?, submission_status = 'submitted' 
                        WHERE exam_schedule_id = ? AND student_id = ?
                    ");
                    $update_stmt->execute([$marks_obtained, $remarks, $schedule_id, $student_id]);
                } else {
                    // Insert new marks
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO exam_marks (exam_schedule_id, student_id, marks_obtained, remarks, entered_by, submission_status) 
                        VALUES (?, ?, ?, ?, ?, 'submitted')
                    ");
                    $insert_stmt->execute([$schedule_id, $student_id, $marks_obtained, $remarks, $teacher_id]);
                }
                $success_count++;
            }
            
            $pdo->commit();
            $success_message = "Marks saved successfully for $success_count students!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = "Error saving marks: " . $e->getMessage();
        }
    }
}

// Fetch students for this class with their existing marks
try {
    if (isset($exam['class_id']) && $exam['class_id']) {
        $stmt = $pdo->prepare("
            SELECT 
                s.id, s.full_name, s.admission_number,
                em.marks_obtained, em.remarks
            FROM students s
            LEFT JOIN exam_marks em ON em.student_id = s.id AND em.exam_schedule_id = ?
            WHERE s.class_id = ? AND s.status = 'active'
            ORDER BY s.full_name
        ");
        $stmt->execute([$schedule_id, $exam['class_id']]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Debug: if no students found, try without status filter
        if (empty($students)) {
            $debug_stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE class_id = ?");
            $debug_stmt->execute([$exam['class_id']]);
            $total_students = $debug_stmt->fetchColumn();
            
            if ($total_students > 0) {
                // Students exist but not active, let's get all of them
                $stmt = $pdo->prepare("
                    SELECT 
                        s.id, s.full_name, s.admission_number,
                        em.marks_obtained, em.remarks
                    FROM students s
                    LEFT JOIN exam_marks em ON em.student_id = s.id AND em.exam_schedule_id = ?
                    WHERE s.class_id = ?
                    ORDER BY s.full_name
                ");
                $stmt->execute([$schedule_id, $exam['class_id']]);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } else {
        $students = [];
        $error_message = "Class ID not found for this exam schedule.";
    }
} catch (Exception $e) {
    $error_message = "Error fetching students: " . $e->getMessage();
    $students = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enter Marks - <?php echo htmlspecialchars($exam['exam_name'] ?? 'Exam'); ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
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
            background: #f8f9fa;
            min-height: calc(100vh - 70px);
            width: calc(100% - 280px);
        }

        .page-header {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }

        .page-header h1 {
            color: #333;
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 0.5rem;
        }

        .page-header h1 i {
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-size: 32px;
        }

        .page-header p {
            color: #999;
            font-size: 14px;
        }

        /* Exam Info Cards */
        .exam-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #667eea;
        }

        .info-card h3 {
            color: #999;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .info-card p {
            color: #333;
            font-size: 18px;
            font-weight: 600;
        }

        /* Main Form Card */
        .form-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow: hidden;
        }

        .form-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-header h2 {
            font-size: 20px;
            margin: 0;
        }

        .form-body {
            padding: 2rem;
        }

        /* Quick Action Buttons */
        .quick-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #f0f0f0;
        }

        .quick-btn {
            padding: 8px 16px;
            background: #f0f0f0;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #333;
        }

        .quick-btn:hover {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            transform: translateY(-2px);
        }

        /* Statistics Bar */
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .stat-item {
            text-align: center;
        }

        .stat-label {
            color: #999;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #667eea;
        }

        /* Table Styles */
        .table-wrapper {
            overflow-x: auto;
            margin-bottom: 1.5rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            border-bottom: 2px solid #667eea;
        }

        th {
            padding: 1rem;
            text-align: left;
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
            color: #333;
        }

        tbody tr:hover {
            background: #f8f9fa;
        }

        .admission-no {
            font-weight: 600;
            color: #667eea;
        }

        .marks-input, .remarks-input {
            width: 100%;
            padding: 8px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .marks-input {
            max-width: 100px;
        }

        .marks-input:focus, .remarks-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .marks-input.invalid {
            border-color: #f5576c;
            background-color: rgba(245, 87, 108, 0.05);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-entered {
            background: rgba(67, 233, 123, 0.1);
            color: #43e97b;
        }

        .status-pending {
            background: rgba(253, 160, 133, 0.1);
            color: #fda085;
        }

        /* Action Buttons */
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            padding-top: 1.5rem;
            border-top: 1px solid #f0f0f0;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #f0f0f0;
            color: #333;
        }

        .btn-secondary:hover {
            background: #e0e0e0;
        }

        /* Alerts */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background: linear-gradient(135deg, rgba(67, 233, 123, 0.1), rgba(56, 249, 215, 0.1));
            color: #43e97b;
            border-left: 4px solid #43e97b;
        }

        .alert-error {
            background: linear-gradient(135deg, rgba(245, 87, 108, 0.1), rgba(240, 147, 251, 0.1));
            color: #f5576c;
            border-left: 4px solid #f5576c;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #999;
        }

        .empty-state i {
            font-size: 48px;
            color: #e0e0e0;
            margin-bottom: 1rem;
            display: block;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                width: 100%;
            }

            .exam-info {
                grid-template-columns: 1fr;
            }

            .marks-input {
                max-width: 80px;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .quick-actions {
                flex-direction: column;
            }

            .quick-btn {
                width: 100%;
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
        <div class="page-header">
            <h1>
                <i class="fas fa-pen"></i>
                Enter Exam Marks
            </h1>
            <p><?php echo htmlspecialchars($exam['exam_name'] ?? ''); ?></p>
        </div>

        <!-- Alerts -->
        <?php if ($success_message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <span><?php echo $success_message; ?></span>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo $error_message; ?></span>
        </div>
        <?php endif; ?>

        <!-- Exam Information Cards -->
        <div class="exam-info">
            <div class="info-card">
                <h3><i class="fas fa-book"></i> Subject</h3>
                <p><?php echo htmlspecialchars($exam['subject_name'] ?? 'N/A'); ?></p>
            </div>
            <div class="info-card">
                <h3><i class="fas fa-users"></i> Class</h3>
                <p><?php echo htmlspecialchars($exam['class_name'] ?? 'N/A'); ?></p>
            </div>
            <div class="info-card">
                <h3><i class="fas fa-star"></i> Total Marks</h3>
                <p><?php echo htmlspecialchars($exam['total_marks'] ?? '0'); ?></p>
            </div>
            <div class="info-card">
                <h3><i class="fas fa-flag-checkered"></i> Passing Marks</h3>
                <p><?php echo htmlspecialchars($exam['passing_marks'] ?? '0'); ?></p>
            </div>
        </div>

        <!-- Marks Entry Form -->
        <form method="POST" id="marksForm" onsubmit="return validateForm()">
            <input type="hidden" name="submit_marks" value="1">
            
            <div class="form-card">
                <div class="form-header">
                    <i class="fas fa-edit"></i>
                    <h2>Student Marks Entry</h2>
                </div>

                <div class="form-body">
                    <!-- Quick Action Buttons -->
                    <div class="quick-actions">
                        <button type="button" class="quick-btn" onclick="fillAllMarks(100)">
                            <i class="fas fa-star"></i> All Max
                        </button>
                        <button type="button" class="quick-btn" onclick="fillAllMarks(90)">
                            <i class="fas fa-arrow-up"></i> All 90
                        </button>
                        <button type="button" class="quick-btn" onclick="fillPassMarks()">
                            <i class="fas fa-check"></i> All Pass
                        </button>
                        <button type="button" class="quick-btn" onclick="clearAllMarks()">
                            <i class="fas fa-eraser"></i> Clear All
                        </button>
                    </div>

                    <!-- Statistics Bar -->
                    <div class="stats-bar">
                        <div class="stat-item">
                            <div class="stat-label">Total Students</div>
                            <div class="stat-value" id="totalStudents"><?php echo count($students); ?></div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-label">Marks Entered</div>
                            <div class="stat-value" id="marksEntered">0</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-label">Average Marks</div>
                            <div class="stat-value" id="averageMarks">0.0</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-label">Progress</div>
                            <div class="stat-value" id="progressPercent">0%</div>
                        </div>
                    </div>

                    <!-- Students Table -->
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Admission No.</th>
                                    <th>Student Name</th>
                                    <th>Marks (Max: <?php echo $exam['total_marks']; ?>)</th>
                                    <th>Remarks</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($students)): ?>
                                    <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td><span class="admission-no"><?php echo htmlspecialchars($student['admission_number']); ?></span></td>
                                        <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                        <td>
                                            <input type="number" 
                                                   name="marks[<?php echo $student['id']; ?>]" 
                                                   class="marks-input" 
                                                   value="<?php echo $student['marks_obtained'] !== null ? floatval($student['marks_obtained']) : ''; ?>"
                                                   min="0" 
                                                   max="<?php echo $exam['total_marks']; ?>"
                                                   step="0.5"
                                                   onchange="updateStats()"
                                                   onkeyup="updateStats()"
                                                   placeholder="Enter marks">
                                        </td>
                                        <td>
                                            <input type="text" 
                                                   name="remarks[<?php echo $student['id']; ?>]" 
                                                   class="remarks-input"
                                                   value="<?php echo htmlspecialchars($student['remarks'] ?? ''); ?>"
                                                   placeholder="Add remarks (optional)">
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $student['marks_obtained'] !== null ? 'status-entered' : 'status-pending'; ?>">
                                                <i class="fas fa-<?php echo $student['marks_obtained'] !== null ? 'check-circle' : 'circle'; ?>"></i>
                                                <?php echo $student['marks_obtained'] !== null ? 'Entered' : 'Pending'; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5">
                                            <div class="empty-state">
                                                <i class="fas fa-inbox"></i>
                                                <h3>No Students Found</h3>
                                                <p>No active students in this class for this exam.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='exam_marks_entry.php'">
                            <i class="fas fa-arrow-left"></i> Back
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Marks
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script>
        // Update statistics
        function updateStats() {
            const inputs = document.querySelectorAll('.marks-input');
            let entered = 0;
            let total = 0;
            const totalStudents = inputs.length;

            inputs.forEach(input => {
                if (input.value !== '') {
                    entered++;
                    total += parseFloat(input.value) || 0;
                }
            });

            document.getElementById('marksEntered').textContent = entered;
            const average = entered > 0 ? (total / entered).toFixed(1) : '0.0';
            document.getElementById('averageMarks').textContent = average;
            const progress = totalStudents > 0 ? Math.round((entered / totalStudents) * 100) : 0;
            document.getElementById('progressPercent').textContent = progress + '%';
        }

        // Quick fill functions
        function fillAllMarks(marks) {
            Swal.fire({
                title: 'Fill All Marks?',
                text: `This will set all students' marks to ${marks}. Continue?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#667eea',
                cancelButtonColor: '#999',
                confirmButtonText: 'Yes, fill all'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.querySelectorAll('.marks-input').forEach(input => {
                        input.value = marks;
                    });
                    updateStats();
                }
            });
        }

        function fillPassMarks() {
            const maxMarks = <?php echo $exam['total_marks']; ?>;
            const passingMarks = Math.ceil(maxMarks * 0.33);
            
            Swal.fire({
                title: 'Fill Passing Marks?',
                text: `This will set all students' marks to passing marks (${passingMarks}). Continue?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#667eea',
                cancelButtonColor: '#999',
                confirmButtonText: 'Yes, fill passing marks'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.querySelectorAll('.marks-input').forEach(input => {
                        input.value = passingMarks;
                    });
                    updateStats();
                }
            });
        }

        function clearAllMarks() {
            Swal.fire({
                title: 'Clear All Marks?',
                text: 'This will remove all entered marks. Continue?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f5576c',
                cancelButtonColor: '#999',
                confirmButtonText: 'Yes, clear all'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.querySelectorAll('.marks-input').forEach(input => {
                        input.value = '';
                    });
                    updateStats();
                }
            });
        }

        // Form validation
        function validateForm() {
            const inputs = document.querySelectorAll('.marks-input');
            const maxMarks = <?php echo $exam['total_marks']; ?>;
            let isValid = true;

            inputs.forEach(input => {
                if (input.value !== '') {
                    const value = parseFloat(input.value);
                    if (isNaN(value) || value < 0 || value > maxMarks) {
                        isValid = false;
                        input.classList.add('invalid');
                    } else {
                        input.classList.remove('invalid');
                    }
                }
            });

            if (!isValid) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Marks',
                    text: `Marks must be between 0 and ${maxMarks}`
                });
                return false;
            }

            // Confirm submission
            Swal.fire({
                title: 'Save Marks?',
                text: 'Please review entries before saving.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#667eea',
                cancelButtonColor: '#999',
                confirmButtonText: 'Yes, save marks'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('marksForm').submit();
                }
            });

            return false;
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateStats();
            
            // Auto-hide alerts after 5 seconds
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    alert.style.display = 'none';
                });
            }, 5000);
        });

        // Warn before leaving if unsaved changes
        let hasChanges = false;
        document.querySelectorAll('.marks-input, .remarks-input').forEach(input => {
            input.addEventListener('change', () => {
                hasChanges = true;
            });
        });

        window.addEventListener('beforeunload', (e) => {
            if (hasChanges) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    </script>
</body>
</html>
