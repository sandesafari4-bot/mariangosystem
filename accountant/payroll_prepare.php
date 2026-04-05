<?php
include '../config.php';
require_once '../payroll_helpers.php';

checkAuth();
checkRole(['accountant', 'admin']);
payrollEnsureSchema($pdo);

$page_title = 'Payroll Preparation - ' . SCHOOL_NAME;
$month = max(1, min(12, intval($_GET['month'] ?? date('n'))));
$year = max(2024, intval($_GET['year'] ?? date('Y')));
$eligibleCategories = payrollEligibleCategories();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['save_profile'])) {
            $stmt = $pdo->prepare("
                INSERT INTO payroll_staff_profiles (
                    user_id, staff_category, employment_type, basic_salary,
                    house_allowance, transport_allowance, medical_allowance, other_allowances,
                    tax_deduction, pension_deduction, other_deductions,
                    phone_number, bank_name, account_number, notes, is_active, created_by, updated_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    staff_category = VALUES(staff_category),
                    employment_type = VALUES(employment_type),
                    basic_salary = VALUES(basic_salary),
                    house_allowance = VALUES(house_allowance),
                    transport_allowance = VALUES(transport_allowance),
                    medical_allowance = VALUES(medical_allowance),
                    other_allowances = VALUES(other_allowances),
                    tax_deduction = VALUES(tax_deduction),
                    pension_deduction = VALUES(pension_deduction),
                    other_deductions = VALUES(other_deductions),
                    phone_number = VALUES(phone_number),
                    bank_name = VALUES(bank_name),
                    account_number = VALUES(account_number),
                    notes = VALUES(notes),
                    is_active = VALUES(is_active),
                    updated_by = VALUES(updated_by)
            ");
            $stmt->execute([
                intval($_POST['user_id']),
                $_POST['staff_category'] ?? 'non_teaching_staff',
                $_POST['employment_type'] ?? 'monthly',
                max(0, (float) ($_POST['basic_salary'] ?? 0)),
                max(0, (float) ($_POST['house_allowance'] ?? 0)),
                max(0, (float) ($_POST['transport_allowance'] ?? 0)),
                max(0, (float) ($_POST['medical_allowance'] ?? 0)),
                max(0, (float) ($_POST['other_allowances'] ?? 0)),
                max(0, (float) ($_POST['tax_deduction'] ?? 0)),
                max(0, (float) ($_POST['pension_deduction'] ?? 0)),
                max(0, (float) ($_POST['other_deductions'] ?? 0)),
                trim($_POST['phone_number'] ?? '') ?: null,
                trim($_POST['bank_name'] ?? '') ?: null,
                trim($_POST['account_number'] ?? '') ?: null,
                trim($_POST['notes'] ?? '') ?: null,
                isset($_POST['is_active']) ? 1 : 0,
                $_SESSION['user_id'],
                $_SESSION['user_id'],
            ]);
            $_SESSION['success'] = 'Payroll profile saved successfully.';
        } elseif (isset($_POST['generate_payroll'])) {
            $runMonth = max(1, min(12, intval($_POST['payroll_month'] ?? date('n'))));
            $runYear = max(2024, intval($_POST['payroll_year'] ?? date('Y')));
            $title = trim($_POST['title'] ?? ('Payroll ' . date('F Y', mktime(0, 0, 0, $runMonth, 1, $runYear))));
            $notes = trim($_POST['notes'] ?? '');

            $check = $pdo->prepare("
                SELECT id FROM payroll_runs
                WHERE payroll_month = ? AND payroll_year = ? AND status IN ('draft', 'submitted', 'approved', 'paid', 'partially_paid')
                LIMIT 1
            ");
            $check->execute([$runMonth, $runYear]);
            if ($check->fetch()) {
                throw new Exception('A payroll run already exists for this month.');
            }

            $staffRows = payrollFetchStaff($pdo);
            $rows = [];
            $gross = 0;
            $deductions = 0;
            $net = 0;

            foreach ($staffRows as $staff) {
                if ((int) $staff['payroll_active'] !== 1 || !in_array($staff['staff_category'], $eligibleCategories, true)) {
                    continue;
                }
                $summary = payrollSummarizeStaff($staff);
                if ($summary['net_salary'] <= 0) {
                    continue;
                }
                $rows[] = array_merge($staff, $summary);
                $gross += $summary['gross_salary'];
                $deductions += $summary['total_deductions'];
                $net += $summary['net_salary'];
            }

            if (!$rows) {
                throw new Exception('No active staff profiles are ready for payroll.');
            }

            $pdo->beginTransaction();

            $code = payrollGenerateCode($runMonth, $runYear);
            $stmt = $pdo->prepare("
                INSERT INTO payroll_runs (
                    payroll_month, payroll_year, payroll_code, title, notes, status,
                    total_staff, eligible_staff, total_gross, total_deductions, total_net, prepared_by
                ) VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$runMonth, $runYear, $code, $title, $notes ?: null, count($rows), count($rows), $gross, $deductions, $net, $_SESSION['user_id']]);
            $runId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare("
                INSERT INTO payroll_run_items (
                    payroll_run_id, user_id, staff_name, role, staff_category, employment_type,
                    basic_salary, total_allowances, total_deductions, gross_salary,
                    nssf_employee, nssf_employer, sha_employee, housing_levy_employee, housing_levy_employer,
                    additional_pension, other_pre_tax_deductions, pre_tax_deductions, taxable_pay,
                    paye_before_relief, personal_relief, paye_tax, post_tax_deductions, net_salary, employer_cost, remarks
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($rows as $row) {
                $itemStmt->execute([
                    $runId,
                    $row['id'],
                    $row['full_name'],
                    $row['role'],
                    $row['staff_category'],
                    $row['employment_type'],
                    $row['basic_salary'],
                    $row['total_allowances'],
                    $row['total_deductions'],
                    $row['gross_salary'],
                    $row['nssf_employee'],
                    $row['nssf_employer'],
                    $row['sha_employee'],
                    $row['housing_levy_employee'],
                    $row['housing_levy_employer'],
                    $row['additional_pension'],
                    $row['other_pre_tax_deductions'],
                    $row['pre_tax_deductions'],
                    $row['taxable_pay'],
                    $row['paye_before_relief'],
                    $row['personal_relief'],
                    $row['paye_tax'],
                    $row['post_tax_deductions'],
                    $row['net_salary'],
                    $row['employer_cost'],
                    trim(($row['bank_name'] ? 'Bank: ' . $row['bank_name'] . '. ' : '') . ($row['account_number'] ? 'Account: ' . $row['account_number'] . '. ' : '') . ($row['notes'] ?? '')) ?: null,
                ]);
            }

            $pdo->commit();
            $_SESSION['success'] = 'Payroll run prepared successfully.';
        } elseif (isset($_POST['submit_run'])) {
            $runId = intval($_POST['run_id']);
            $stmt = $pdo->prepare("
                UPDATE payroll_runs
                SET status = 'submitted', submitted_by = ?, submitted_at = NOW()
                WHERE id = ? AND status = 'draft'
            ");
            $stmt->execute([$_SESSION['user_id'], $runId]);
            if ($stmt->rowCount() === 0) {
                throw new Exception('Only draft runs can be submitted.');
            }

            $runStmt = $pdo->prepare("SELECT title, payroll_code FROM payroll_runs WHERE id = ?");
            $runStmt->execute([$runId]);
            $run = $runStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $adminIds = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($adminIds as $adminId) {
                payrollInsertNotification($pdo, [
                    'user_id' => $adminId,
                    'type' => 'payroll_submitted',
                    'title' => 'Payroll submitted',
                    'message' => ($run['title'] ?? 'Payroll run') . ' (' . ($run['payroll_code'] ?? 'N/A') . ') is awaiting approval.',
                    'related_id' => $runId,
                ]);
            }

            $_SESSION['success'] = 'Payroll run submitted for admin approval.';
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }

    header('Location: payroll_prepare.php?month=' . $month . '&year=' . $year);
    exit();
}

$staffRows = payrollFetchStaff($pdo);
$statutoryConfig = payrollKenyaStatutoryConfig();
$profileStats = ['count' => 0, 'eligible' => 0, 'gross' => 0, 'net' => 0];
foreach ($staffRows as &$staff) {
    $staff['summary'] = payrollSummarizeStaff($staff);
    if ((int) $staff['payroll_active'] === 1) {
        $profileStats['count']++;
        $profileStats['gross'] += $staff['summary']['gross_salary'];
        $profileStats['net'] += $staff['summary']['net_salary'];
        if (in_array($staff['staff_category'], $eligibleCategories, true)) {
            $profileStats['eligible']++;
        }
    }
}
unset($staff);

$runs = $pdo->query("
    SELECT pr.*, u.full_name as prepared_by_name, s.full_name as submitted_by_name, a.full_name as approved_by_name
    FROM payroll_runs pr
    LEFT JOIN users u ON pr.prepared_by = u.id
    LEFT JOIN users s ON pr.submitted_by = s.id
    LEFT JOIN users a ON pr.approved_by = a.id
    ORDER BY pr.payroll_year DESC, pr.payroll_month DESC, pr.created_at DESC
    LIMIT 12
")->fetchAll(PDO::FETCH_ASSOC);

$runItemsById = [];
if ($runs) {
    $runIds = array_column($runs, 'id');
    $placeholders = implode(',', array_fill(0, count($runIds), '?'));
    $itemsStmt = $pdo->prepare("SELECT * FROM payroll_run_items WHERE payroll_run_id IN ($placeholders) ORDER BY staff_name ASC");
    $itemsStmt->execute($runIds);
    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $runItemsById[$item['payroll_run_id']][] = $item;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
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
            --gradient-payroll: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%);
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
            background: var(--gradient-payroll);
            border-radius: var(--border-radius-xl);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
            color: white;
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

        .page-header::after {
            content: '';
            position: absolute;
            bottom: -50%;
            left: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.08);
            border-radius: 50%;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            z-index: 1;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255,255,255,0.15);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1rem;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .page-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .page-header p {
            opacity: 0.9;
            font-size: 1rem;
            max-width: 600px;
        }

        /* Stats Cards */
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
        .stat-card.eligible { border-left-color: var(--success); }
        .stat-card.gross { border-left-color: var(--warning); }
        .stat-card.net { border-left-color: var(--purple); }

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

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
        }

        .card-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .card-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.3rem;
        }

        .card-header p {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Statutory Boxes */
        .statutory-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin: 1.5rem 0;
        }

        .stat-box {
            background: var(--light);
            border-radius: var(--border-radius-md);
            padding: 1rem;
            border-left: 3px solid var(--primary);
        }

        .stat-box small {
            display: block;
            color: var(--gray);
            font-size: 0.75rem;
            text-transform: uppercase;
            margin-bottom: 0.3rem;
        }

        .stat-box strong {
            font-size: 1rem;
            color: var(--dark);
        }

        /* Form Fields */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
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

        .btn-soft {
            background: var(--light);
            color: var(--primary);
        }

        .btn-soft:hover {
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

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-draft {
            background: rgba(108, 117, 125, 0.15);
            color: var(--gray);
        }

        .badge-submitted {
            background: rgba(248, 150, 30, 0.15);
            color: var(--warning);
        }

        .badge-approved {
            background: rgba(76, 201, 240, 0.15);
            color: var(--success);
        }

        .badge-rejected {
            background: rgba(249, 65, 68, 0.15);
            color: var(--danger);
        }

        .badge-paid {
            background: rgba(39, 174, 96, 0.15);
            color: #27ae60;
        }

        /* Staff Table */
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
            vertical-align: top;
        }

        tr:hover {
            background: rgba(67, 97, 238, 0.05);
        }

        .staff-name {
            font-weight: 600;
            color: var(--dark);
        }

        .staff-role {
            font-size: 0.8rem;
            color: var(--gray);
        }

        .staff-summary {
            font-size: 0.85rem;
            line-height: 1.5;
        }

        .staff-summary strong {
            color: var(--primary);
        }

        /* Inline Edit Details */
        details {
            margin-top: 0.5rem;
        }

        details summary {
            cursor: pointer;
            color: var(--primary);
            font-weight: 600;
            font-size: 0.85rem;
            padding: 0.3rem 0;
        }

        .edit-form {
            margin-top: 1rem;
            padding: 1rem;
            background: var(--light);
            border-radius: var(--border-radius-md);
        }

        /* Run Cards */
        .run-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .run-header {
            padding: 1.2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .run-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
        }

        .run-code {
            font-size: 0.85rem;
            opacity: 0.9;
            font-family: monospace;
        }

        .run-body {
            padding: 1.5rem;
        }

        .run-meta {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .meta-item {
            background: var(--light);
            border-radius: var(--border-radius-md);
            padding: 1rem;
            text-align: center;
        }

        .meta-item small {
            display: block;
            color: var(--gray);
            font-size: 0.7rem;
            text-transform: uppercase;
            margin-bottom: 0.3rem;
        }

        .meta-item strong {
            font-size: 1.2rem;
            color: var(--dark);
        }

        .run-footer {
            margin-top: 1rem;
            font-size: 0.85rem;
            color: var(--gray);
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

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
            
            .run-meta {
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
            
            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .run-meta {
                grid-template-columns: 1fr;
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
            <div class="header-content">
                <div>
                    <div class="header-badge">
                        <i class="fas fa-calculator"></i>
                        <span>Payroll Management</span>
                    </div>
                    <h1>Payroll Preparation Desk</h1>
                    <p>Prepare Kenyan payroll with statutory deductions: PAYE, SHA, NSSF, and Housing Levy.</p>
                </div>
                <div class="btn-group">
                    <a href="payroll_reports.php" class="btn btn-soft">
                        <i class="fas fa-chart-bar"></i> Reports
                    </a>
                </div>
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
            <div class="stat-card total stagger-item">
                <div class="stat-number"><?php echo number_format($profileStats['count']); ?></div>
                <div class="stat-label">Active Profiles</div>
                <div class="stat-detail">Staff with payroll profiles</div>
            </div>
            <div class="stat-card eligible stagger-item">
                <div class="stat-number"><?php echo number_format($profileStats['eligible']); ?></div>
                <div class="stat-label">Eligible to Pay</div>
                <div class="stat-detail">BOM teachers & non-teaching staff</div>
            </div>
            <div class="stat-card gross stagger-item">
                <div class="stat-number">KES <?php echo number_format($profileStats['gross'], 2); ?></div>
                <div class="stat-label">Gross Estimate</div>
                <div class="stat-detail">Total before deductions</div>
            </div>
            <div class="stat-card net stagger-item">
                <div class="stat-number">KES <?php echo number_format($profileStats['net'], 2); ?></div>
                <div class="stat-label">Net Estimate</div>
                <div class="stat-detail">Total after deductions</div>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Left Column - Payroll Run Form -->
            <div class="card animate">
                <div class="card-header">
                    <h2><i class="fas fa-file-invoice"></i> Prepare New Payroll Run</h2>
                    <p>Create a new payroll run for the selected month</p>
                </div>
                <div class="card-body">
                    <!-- Statutory Info Boxes -->
                    <div class="statutory-grid">
                        <div class="stat-box">
                            <small>PAYE / AHL / NSSF Due</small>
                            <strong>By the 9th of following month</strong>
                        </div>
                        <div class="stat-box">
                            <small>SHA / SHIF Due</small>
                            <strong>By the 9th of following month</strong>
                        </div>
                        <div class="stat-box">
                            <small>Taxable Pay Rule</small>
                            <strong>Gross less statutory deductions</strong>
                        </div>
                        <div class="stat-box">
                            <small>NSSF Contribution</small>
                            <strong>KES <?php echo number_format($statutoryConfig['nssf_upper_limit'], 2); ?> cap</strong>
                        </div>
                    </div>

                    <form method="POST">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Month</label>
                                <input type="number" name="payroll_month" class="form-control" min="1" max="12" value="<?php echo $month; ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Year</label>
                                <input type="number" name="payroll_year" class="form-control" min="2024" value="<?php echo $year; ?>" required>
                            </div>
                            <div class="form-group full-width">
                                <label>Title</label>
                                <input type="text" name="title" class="form-control" value="Payroll <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?>" required>
                            </div>
                            <div class="form-group full-width">
                                <label>Notes (Optional)</label>
                                <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes for approver..."></textarea>
                            </div>
                        </div>
                        <div class="btn-group" style="margin-top: 1rem;">
                            <button type="submit" name="generate_payroll" class="btn btn-primary">
                                <i class="fas fa-file-invoice"></i> Generate Payroll Run
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right Column - Statutory Guide -->
            <div class="card animate">
                <div class="card-header">
                    <h2><i class="fas fa-scale-balanced"></i> Kenyan Statutory Guide</h2>
                    <p>Current tax and deduction rates</p>
                </div>
                <div class="card-body">
                    <div class="statutory-grid">
                        <div class="stat-box">
                            <small>PAYE Bands</small>
                            <strong>10%, 25%, 30%, 32.5%, 35%</strong>
                        </div>
                        <div class="stat-box">
                            <small>Personal Relief</small>
                            <strong>KES <?php echo number_format($statutoryConfig['personal_relief'], 2); ?>/month</strong>
                        </div>
                        <div class="stat-box">
                            <small>SHA / SHIF</small>
                            <strong>2.75% of gross (min KES <?php echo number_format($statutoryConfig['sha_minimum'], 2); ?>)</strong>
                        </div>
                        <div class="stat-box">
                            <small>Housing Levy</small>
                            <strong>1.5% employee + 1.5% employer</strong>
                        </div>
                        <div class="stat-box">
                            <small>NSSF Employee</small>
                            <strong>6% up to KES <?php echo number_format($statutoryConfig['nssf_upper_limit'], 2); ?></strong>
                        </div>
                        <div class="stat-box">
                            <small>NSSF Employer</small>
                            <strong>6% matched by employer</strong>
                        </div>
                    </div>
                    <p class="staff-summary" style="margin-top: 1rem; color: var(--gray);">
                        <i class="fas fa-info-circle"></i> 
                        Payroll sequence: Gross pay → Statutory deductions → Taxable pay → PAYE after relief → Post-tax deductions → Net pay
                    </p>
                </div>
            </div>
        </div>

        <!-- Staff Profiles Section -->
        <div class="card animate" style="margin-bottom: 2rem;">
            <div class="card-header">
                <h2><i class="fas fa-users"></i> Staff Payroll Profiles</h2>
                <p>Manage staff payroll details and view calculated deductions</p>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Staff</th>
                                <th>Category</th>
                                <th>Payroll Summary</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($staffRows as $staff): ?>
                            <tr>
                                <td>
                                    <div class="staff-name"><?php echo htmlspecialchars($staff['full_name']); ?></div>
                                    <div class="staff-role"><?php echo htmlspecialchars(ucfirst($staff['role'])); ?></div>
                                </td>
                                <td>
                                    <span class="badge <?php echo in_array($staff['staff_category'], $eligibleCategories, true) ? 'badge-approved' : 'badge-draft'; ?>">
                                        <?php echo htmlspecialchars(payrollCategoryLabel($staff['staff_category'])); ?>
                                    </span>
                                    <div style="margin-top: 0.3rem;">
                                        <span class="badge <?php echo $staff['payroll_active'] ? 'badge-approved' : 'badge-draft'; ?>" style="font-size: 0.7rem;">
                                            <?php echo $staff['payroll_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="staff-summary">
                                        <strong>Net: KES <?php echo number_format($staff['summary']['net_salary'], 2); ?></strong><br>
                                        <span style="color: var(--gray);">Gross: KES <?php echo number_format($staff['summary']['gross_salary'], 2); ?></span><br>
                                        <span style="color: var(--gray);">PAYE: KES <?php echo number_format($staff['summary']['paye_tax'], 2); ?></span><br>
                                        <span style="color: var(--gray);">NSSF: KES <?php echo number_format($staff['summary']['nssf_employee'], 2); ?> | SHA: KES <?php echo number_format($staff['summary']['sha_employee'], 2); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <details>
                                        <summary><i class="fas fa-edit"></i> Edit Profile</summary>
                                        <div class="edit-form">
                                            <form method="POST">
                                                <input type="hidden" name="user_id" value="<?php echo (int) $staff['id']; ?>">
                                                
                                                <div class="form-grid">
                                                    <div class="form-group">
                                                        <label>Category</label>
                                                        <select name="staff_category" class="form-control">
                                                            <?php foreach (payrollCategoryOptions() as $value => $label): ?>
                                                            <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $staff['staff_category'] === $value ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($label); ?>
                                                            </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    
                                                    <div class="form-group">
                                                        <label>Employment Type</label>
                                                        <select name="employment_type" class="form-control">
                                                            <?php foreach (payrollEmploymentOptions() as $value => $label): ?>
                                                            <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $staff['employment_type'] === $value ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($label); ?>
                                                            </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    
                                                    <div class="form-group">
                                                        <label>Basic Salary</label>
                                                        <input type="number" name="basic_salary" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($staff['basic_salary']); ?>">
                                                    </div>
                                                    
                                                    <div class="form-group">
                                                        <label>House Allowance</label>
                                                        <input type="number" name="house_allowance" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($staff['house_allowance']); ?>">
                                                    </div>
                                                    
                                                    <div class="form-group">
                                                        <label>Transport Allowance</label>
                                                        <input type="number" name="transport_allowance" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($staff['transport_allowance']); ?>">
                                                    </div>
                                                    
                                                    <div class="form-group">
                                                        <label>Medical Allowance</label>
                                                        <input type="number" name="medical_allowance" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($staff['medical_allowance']); ?>">
                                                    </div>
                                                    
                                                    <div class="form-group">
                                                        <label>Other Allowances</label>
                                                        <input type="number" name="other_allowances" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($staff['other_allowances']); ?>">
                                                    </div>
                                                    
                                                    <div class="form-group">
                                                        <label>Pre-Tax Deductions</label>
                                                        <input type="number" name="tax_deduction" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($staff['tax_deduction']); ?>">
                                                    </div>
                                                    
                                                    <div class="form-group">
                                                        <label>Additional Pension</label>
                                                        <input type="number" name="pension_deduction" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($staff['pension_deduction']); ?>">
                                                    </div>
                                                    
                                                    <div class="form-group">
                                                        <label>Post-Tax Deductions</label>
                                                        <input type="number" name="other_deductions" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($staff['other_deductions']); ?>">
                                                    </div>
                                                    
                                                    <div class="form-group">
                                                        <label>Phone Number</label>
                                                        <input type="text" name="phone_number" class="form-control" value="<?php echo htmlspecialchars($staff['payroll_phone'] ?? ''); ?>">
                                                    </div>
                                                    
                                                    <div class="form-group">
                                                        <label>Bank Name</label>
                                                        <input type="text" name="bank_name" class="form-control" value="<?php echo htmlspecialchars($staff['bank_name'] ?? ''); ?>">
                                                    </div>
                                                    
                                                    <div class="form-group">
                                                        <label>Account Number</label>
                                                        <input type="text" name="account_number" class="form-control" value="<?php echo htmlspecialchars($staff['account_number'] ?? ''); ?>">
                                                    </div>
                                                    
                                                    <div class="form-group full-width">
                                                        <label style="display: flex; align-items: center; gap: 0.5rem;">
                                                            <input type="checkbox" name="is_active" value="1" <?php echo $staff['payroll_active'] ? 'checked' : ''; ?>>
                                                            Include in payroll
                                                        </label>
                                                    </div>
                                                    
                                                    <div class="form-group full-width">
                                                        <label>Notes</label>
                                                        <textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars($staff['notes'] ?? ''); ?></textarea>
                                                    </div>
                                                </div>
                                                
                                                <div class="btn-group" style="margin-top: 1rem;">
                                                    <button type="submit" name="save_profile" class="btn btn-soft">
                                                        <i class="fas fa-save"></i> Save Profile
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Payroll Runs -->
        <div class="card animate">
            <div class="card-header">
                <h2><i class="fas fa-history"></i> Recent Payroll Runs</h2>
                <p>Previous payroll runs and their status</p>
            </div>
            <div class="card-body">
                <?php if (empty($runs)): ?>
                    <div class="empty-state" style="text-align: center; padding: 3rem; color: var(--gray);">
                        <i class="fas fa-file-invoice fa-3x" style="margin-bottom: 1rem; opacity: 0.3;"></i>
                        <h3>No Payroll Runs Yet</h3>
                        <p>Generate your first payroll run using the form above.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($runs as $run): ?>
                        <div class="run-card">
                            <div class="run-header">
                                <div>
                                    <h3><?php echo htmlspecialchars($run['title']); ?></h3>
                                    <span class="run-code"><?php echo htmlspecialchars($run['payroll_code']); ?></span>
                                </div>
                                <div class="btn-group">
                                    <span class="badge badge-<?php echo $run['status']; ?>">
                                        <?php echo ucwords(str_replace('_', ' ', $run['status'])); ?>
                                    </span>
                                    <?php if ($run['status'] === 'draft'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="run_id" value="<?php echo (int) $run['id']; ?>">
                                            <button type="submit" name="submit_run" class="btn btn-sm btn-warning">
                                                <i class="fas fa-paper-plane"></i> Submit
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="run-body">
                                <div class="run-meta">
                                    <div class="meta-item">
                                        <small>Staff Count</small>
                                        <strong><?php echo number_format($run['total_staff']); ?></strong>
                                    </div>
                                    <div class="meta-item">
                                        <small>Total Gross</small>
                                        <strong>KES <?php echo number_format($run['total_gross'], 2); ?></strong>
                                    </div>
                                    <div class="meta-item">
                                        <small>Total Deductions</small>
                                        <strong>KES <?php echo number_format($run['total_deductions'], 2); ?></strong>
                                    </div>
                                    <div class="meta-item">
                                        <small>Total Net</small>
                                        <strong>KES <?php echo number_format($run['total_net'], 2); ?></strong>
                                    </div>
                                </div>
                                
                                <div class="run-footer">
                                    <i class="fas fa-user"></i> Prepared by <?php echo htmlspecialchars($run['prepared_by_name'] ?? 'System'); ?>
                                    <?php if (!empty($run['submitted_at'])): ?>
                                        • <i class="fas fa-clock"></i> Submitted <?php echo date('d M Y H:i', strtotime($run['submitted_at'])); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($run['approved_at'])): ?>
                                        • <i class="fas fa-check-circle"></i> Approved by <?php echo htmlspecialchars($run['approved_by_name'] ?? 'Admin'); ?> on <?php echo date('d M Y H:i', strtotime($run['approved_at'])); ?>
                                    <?php endif; ?>
                                </div>
                                
                                <details style="margin-top: 1rem;">
                                    <summary><i class="fas fa-list"></i> View Staff Breakdown</summary>
                                    <div class="table-responsive" style="margin-top: 1rem;">
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th>Staff</th>
                                                    <th>Category</th>
                                                    <th>Gross</th>
                                                    <th>PAYE</th>
                                                    <th>NSSF</th>
                                                    <th>SHA</th>
                                                    <th>Housing</th>
                                                    <th>Net</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($runItemsById[$run['id']] ?? [] as $item): ?>
                                                <tr>
                                                    <td>
                                                        <div class="staff-name"><?php echo htmlspecialchars($item['staff_name']); ?></div>
                                                        <div class="staff-role"><?php echo htmlspecialchars(ucfirst($item['role'])); ?></div>
                                                    </td>
                                                    <td><span class="badge badge-info"><?php echo htmlspecialchars(payrollCategoryLabel($item['staff_category'])); ?></span></td>
                                                    <td>KES <?php echo number_format($item['gross_salary'], 2); ?></td>
                                                    <td>KES <?php echo number_format($item['paye_tax'] ?? 0, 2); ?></td>
                                                    <td>KES <?php echo number_format($item['nssf_employee'] ?? 0, 2); ?></td>
                                                    <td>KES <?php echo number_format($item['sha_employee'] ?? 0, 2); ?></td>
                                                    <td>KES <?php echo number_format($item['housing_levy_employee'] ?? 0, 2); ?></td>
                                                    <td><strong>KES <?php echo number_format($item['net_salary'], 2); ?></strong></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </details>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>