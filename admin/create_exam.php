<?php
include '../config.php';
checkAuth();
checkRole(['admin']);

$error = '';
$success = '';

// Get academic years from database
$academic_years = $pdo->query("SELECT DISTINCT academic_year FROM exams ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($academic_years)) {
    $current_year = date('Y');
    $academic_years = range($current_year - 2, $current_year + 2);
}

$terms = ['Term 1', 'Term 2', 'Term 3'];

// Generate unique exam code
function generateExamCode($pdo) {
    $year = date('Y');
    $prefix = "EXM";
    $random = strtoupper(substr(uniqid(), -4));
    $code = $prefix . '-' . $year . '-' . $random;
    
    // Check if code exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM exams WHERE exam_code = ?");
    $stmt->execute([$code]);
    if ($stmt->fetchColumn() > 0) {
        return generateExamCode($pdo);
    }
    return $code;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $exam_name = $_POST['exam_name'] ?? '';
    $exam_code = $_POST['exam_code'] ?? generateExamCode($pdo);
    $description = $_POST['description'] ?? '';
    $academic_year = $_POST['academic_year'] ?? '';
    $term = $_POST['term'] ?? '';
    $total_marks = $_POST['total_marks'] ?? 100;
    $passing_marks = $_POST['passing_marks'] ?? 40;
    $start_date = $_POST['start_date'] ?? null;
    $end_date = $_POST['end_date'] ?? null;
    $status = $_POST['status'] ?? 'draft';
    
    // Validation
    $errors = [];
    if (empty($exam_name)) $errors[] = 'Exam name is required';
    if (empty($academic_year)) $errors[] = 'Academic year is required';
    if (empty($term)) $errors[] = 'Term is required';
    if ($passing_marks > $total_marks) $errors[] = 'Passing marks cannot exceed total marks';
    
    // Date validation
    if ($start_date && $end_date && strtotime($start_date) > strtotime($end_date)) {
        $errors[] = 'End date must be after start date';
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("
                INSERT INTO exams (
                    exam_name, exam_code, description, academic_year, term, 
                    total_marks, passing_marks, start_date, end_date, status, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $exam_name,
                $exam_code,
                $description,
                $academic_year,
                $term,
                $total_marks,
                $passing_marks,
                $start_date,
                $end_date,
                $status,
                $_SESSION['user_id']
            ]);
            
            $exam_id = $pdo->lastInsertId();
            $createdSchedules = examAutoCreateSchedules($exam_id, $start_date, $end_date, $description);
            
            $pdo->commit();
            
            $success = "Exam created successfully! {$createdSchedules} class-subject portals were prepared automatically.";
            
            // Redirect based on action
            if (isset($_POST['save_and_add_schedule'])) {
                header("Location: exam_schedules.php?exam_id=$exam_id&success=" . urlencode($success));
                exit();
            } elseif (isset($_POST['save_and_view'])) {
                header("Location: exam_details.php?id=$exam_id&success=" . urlencode($success));
                exit();
            } elseif (isset($_POST['save_and_publish'])) {
                // Update status to published
                $publish_stmt = $pdo->prepare("UPDATE exams SET status = 'published' WHERE id = ?");
                $publish_stmt->execute([$exam_id]);
                header("Location: exams.php?success=" . urlencode("Exam created and published successfully!"));
                exit();
            } else {
                header("Location: exams.php?success=" . urlencode($success));
                exit();
            }
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                $errors[] = 'This exam code already exists. Please use a different code.';
            } else {
                $errors[] = 'Error creating exam: ' . $e->getMessage();
            }
            error_log("Exam creation error: " . $e->getMessage());
        }
    }
    
    if (!empty($errors)) {
        $error = implode('<br>', $errors);
    }
}

$page_title = 'Create New Exam - ' . SCHOOL_NAME;
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

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--secondary);
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 1rem;
            transition: all 0.3s;
        }

        .back-link:hover {
            color: var(--purple);
            transform: translateX(-5px);
        }

        /* Form Card */
        .form-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 2.5rem;
            box-shadow: var(--shadow-xl);
            max-width: 900px;
            margin: 0 auto;
            animation: slideIn 0.5s ease-out;
        }

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

        /* Form Sections */
        .form-section {
            background: var(--light);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .form-section h3 {
            font-size: 1.1rem;
            color: var(--dark);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--white);
        }

        .form-section h3 i {
            color: var(--secondary);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.95rem;
        }

        .required {
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
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

        select.form-control {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%232c3e50' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 16px;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .form-helper {
            font-size: 0.8rem;
            color: var(--gray);
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .form-helper i {
            color: var(--secondary);
            font-size: 0.75rem;
        }

        /* Code Input Group */
        .code-input-group {
            display: flex;
            gap: 0.5rem;
        }

        .code-input-group .form-control {
            flex: 1;
        }

        .btn-generate {
            padding: 0 1.5rem;
            background: linear-gradient(135deg, var(--secondary), var(--purple));
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .btn-generate:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Alert */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
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
            background: rgba(39, 174, 96, 0.1);
            border-color: var(--success);
            color: var(--success);
        }

        .alert-danger {
            background: rgba(231, 76, 60, 0.1);
            border-color: var(--danger);
            color: var(--danger);
        }

        .alert i {
            font-size: 1.2rem;
            margin-top: 0.1rem;
        }

        .alert-content {
            flex: 1;
        }

        .alert-content ul {
            margin: 0.5rem 0 0 1.5rem;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 2px solid var(--light);
            flex-wrap: wrap;
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

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), var(--success-light));
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), var(--warning-light));
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info), #138496);
            color: white;
        }

        .btn-outline {
            background: var(--light);
            color: var(--dark);
            border: 2px solid transparent;
        }

        .btn-outline:hover {
            background: #d5dbdb;
            transform: translateY(-2px);
        }

        .btn-publish {
            background: linear-gradient(135deg, var(--purple), var(--danger));
            color: white;
        }

        .btn-publish:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-group {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-draft {
            background: rgba(127, 140, 141, 0.1);
            color: var(--gray);
        }

        .status-published {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
        }

        /* Info Box */
        .info-box {
            background: rgba(52, 152, 219, 0.05);
            border: 1px dashed var(--secondary);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }

        .info-box i {
            color: var(--secondary);
            font-size: 1.5rem;
        }

        .info-box p {
            color: var(--gray);
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .info-box strong {
            color: var(--dark);
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

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .form-card {
                padding: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                padding: 1.5rem;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .code-input-group {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <?php include '../loader.php'; ?>
    <?php include '../navigation.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="main-content">
        <div class="page-header animate">
            <a href="exams.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Exams
            </a>
            <h1><i class="fas fa-pencil-alt" style="color: var(--secondary); margin-right: 0.5rem;"></i>Create New Exam</h1>
            <p>Define a new examination and set its parameters</p>
        </div>

        <?php if ($success): ?>
        <div class="alert alert-success animate">
            <i class="fas fa-check-circle"></i>
            <div class="alert-content">
                <strong>Success!</strong> <?php echo $success; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-danger animate">
            <i class="fas fa-exclamation-circle"></i>
            <div class="alert-content">
                <strong>Error!</strong> <?php echo $error; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="form-card animate">
            <form method="POST" id="examForm">
                <!-- Basic Information Section -->
                <div class="form-section">
                    <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label class="form-label">
                                <span class="required">*</span> Exam Name
                            </label>
                            <input type="text" class="form-control" name="exam_name" 
                                   value="<?php echo isset($_POST['exam_name']) ? htmlspecialchars($_POST['exam_name']) : ''; ?>"
                                   placeholder="e.g., First Term Examinations" required>
                            <div class="form-helper">
                                <i class="fas fa-info-circle"></i>
                                Enter a descriptive name for this examination
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Exam Code</label>
                            <div class="code-input-group">
                                <input type="text" class="form-control" name="exam_code" 
                                       id="exam_code"
                                       value="<?php echo isset($_POST['exam_code']) ? htmlspecialchars($_POST['exam_code']) : ''; ?>"
                                       placeholder="Auto-generated if empty">
                                <button type="button" class="btn-generate" onclick="generateCode()">
                                    <i class="fas fa-sync-alt"></i> Generate
                                </button>
                            </div>
                            <div class="form-helper">
                                <i class="fas fa-info-circle"></i>
                                Leave empty for auto-generated unique code
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Academic Details Section -->
                <div class="form-section">
                    <h3><i class="fas fa-calendar-alt"></i> Academic Details</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">
                                <span class="required">*</span> Academic Year
                            </label>
                            <select class="form-control" name="academic_year" required>
                                <option value="">Select Academic Year</option>
                                <?php foreach ($academic_years as $year): ?>
                                    <option value="<?php echo $year; ?>" 
                                            <?php echo (isset($_POST['academic_year']) && $_POST['academic_year'] == $year) ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <span class="required">*</span> Term
                            </label>
                            <select class="form-control" name="term" required>
                                <option value="">Select Term</option>
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?php echo $term; ?>" 
                                            <?php echo (isset($_POST['term']) && $_POST['term'] == $term) ? 'selected' : ''; ?>>
                                        <?php echo $term; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Marks Configuration Section -->
                <div class="form-section">
                    <h3><i class="fas fa-chart-line"></i> Marks Configuration</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Total Marks</label>
                            <input type="number" class="form-control" name="total_marks" 
                                   id="total_marks"
                                   value="<?php echo isset($_POST['total_marks']) ? $_POST['total_marks'] : '100'; ?>"
                                   min="1" max="999" step="1">
                            <div class="form-helper">
                                <i class="fas fa-info-circle"></i>
                                Maximum possible marks (default: 100)
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Passing Marks</label>
                            <input type="number" class="form-control" name="passing_marks" 
                                   id="passing_marks"
                                   value="<?php echo isset($_POST['passing_marks']) ? $_POST['passing_marks'] : '40'; ?>"
                                   min="0" max="999" step="1">
                            <div class="form-helper">
                                <i class="fas fa-info-circle"></i>
                                Minimum marks required to pass
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Schedule Section -->
                <div class="form-section">
                    <h3><i class="fas fa-clock"></i> Schedule (Optional)</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date" 
                                   value="<?php echo isset($_POST['start_date']) ? $_POST['start_date'] : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date" 
                                   value="<?php echo isset($_POST['end_date']) ? $_POST['end_date'] : ''; ?>">
                        </div>
                    </div>
                    <div class="form-helper">
                        <i class="fas fa-info-circle"></i>
                        Set exam period dates (optional)
                    </div>
                </div>

                <!-- Status Section -->
                <div class="form-section">
                    <h3><i class="fas fa-toggle-on"></i> Status</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Exam Status</label>
                            <select class="form-control" name="status">
                                <option value="draft" <?php echo (isset($_POST['status']) && $_POST['status'] == 'draft') ? 'selected' : ''; ?>>
                                    Draft - Hidden from students
                                </option>
                                <option value="published" <?php echo (isset($_POST['status']) && $_POST['status'] == 'published') ? 'selected' : ''; ?>>
                                    Published - Visible to students
                                </option>
                            </select>
                            <div class="form-helper">
                                <i class="fas fa-info-circle"></i>
                                Draft exams are only visible to staff
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Description Section -->
                <div class="form-section">
                    <h3><i class="fas fa-align-left"></i> Description (Optional)</h3>
                    <div class="form-group">
                        <textarea class="form-control" name="description" rows="4" 
                                  placeholder="Enter exam description, instructions, or notes..."><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                        <div class="form-helper">
                            <i class="fas fa-info-circle"></i>
                            Additional details about this examination
                        </div>
                    </div>
                </div>

                <!-- Info Box -->
                <div class="info-box">
                    <i class="fas fa-lightbulb"></i>
                    <div>
                        <strong>Pro Tip:</strong> After creating the exam, you'll be able to:
                        <ul style="margin-top: 0.5rem; margin-left: 1.5rem; color: var(--gray);">
                            <li>Add exam schedules (dates, times, rooms)</li>
                            <li>Assign subjects and teachers</li>
                            <li>Record student grades</li>
                            <li>Generate performance reports</li>
                        </ul>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="action-buttons">
                    <button type="submit" name="save_only" class="btn btn-primary">
                        <i class="fas fa-save"></i> Create Exam
                    </button>
                    <button type="submit" name="save_and_add_schedule" class="btn btn-success">
                        <i class="fas fa-calendar-plus"></i> Create & Add Schedule
                    </button>
                    <button type="submit" name="save_and_view" class="btn btn-warning">
                        <i class="fas fa-eye"></i> Create & View Details
                    </button>
                    <button type="submit" name="save_and_publish" class="btn btn-publish">
                        <i class="fas fa-globe"></i> Create & Publish
                    </button>
                    <a href="exams.php" class="btn btn-outline">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Generate unique exam code
        function generateCode() {
            const prefix = 'EXM';
            const year = new Date().getFullYear();
            const random = Math.random().toString(36).substring(2, 6).toUpperCase();
            const code = `${prefix}-${year}-${random}`;
            
            document.getElementById('exam_code').value = code;
            
            // Show notification
            Swal.fire({
                icon: 'success',
                title: 'Code Generated',
                text: `New exam code: ${code}`,
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000
            });
        }

        // Validate passing marks
        document.getElementById('passing_marks').addEventListener('input', function() {
            const total = parseInt(document.getElementById('total_marks').value) || 100;
            const passing = parseInt(this.value) || 0;
            
            if (passing > total) {
                this.style.borderColor = 'var(--danger)';
                showNotification('Passing marks cannot exceed total marks', 'error');
            } else {
                this.style.borderColor = 'var(--light)';
            }
        });

        // Validate dates
        document.querySelectorAll('input[name="start_date"], input[name="end_date"]').forEach(input => {
            input.addEventListener('change', function() {
                const start = document.querySelector('input[name="start_date"]').value;
                const end = document.querySelector('input[name="end_date"]').value;
                
                if (start && end && new Date(start) > new Date(end)) {
                    showNotification('End date must be after start date', 'error');
                }
            });
        });

        // Form validation before submit
        document.getElementById('examForm').addEventListener('submit', function(e) {
            const total = parseInt(document.getElementById('total_marks').value) || 100;
            const passing = parseInt(document.getElementById('passing_marks').value) || 0;
            
            if (passing > total) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Passing marks cannot exceed total marks'
                });
                return false;
            }
            
            // Show loading state
            Swal.fire({
                title: 'Creating Exam...',
                text: 'Please wait while we set up your exam.',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
        });

        // Show notification function
        function showNotification(message, type) {
            Swal.fire({
                icon: type,
                title: message,
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000
            });
        }

        // Auto-dismiss alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Confirm before leaving if form is dirty
        let formDirty = false;
        document.querySelectorAll('#examForm input, #examForm select, #examForm textarea').forEach(input => {
            input.addEventListener('change', () => formDirty = true);
        });

        window.addEventListener('beforeunload', function(e) {
            if (formDirty) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            }
        });
    </script>
</body>
</html>
