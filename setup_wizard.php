<?php
include 'config.php';

ensureAcademicCalendarSchema($pdo);

if (!function_exists('setupWizardNormalizeAcademicYear')) {
    function setupWizardNormalizeAcademicYear(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^(\d{4})\s*[-\/]\s*(\d{4})$/', $value, $matches)) {
            return $matches[1] . '/' . $matches[2];
        }

        if (preg_match('/^\d{4}$/', $value)) {
            $nextYear = (int) $value + 1;
            return $value . '/' . $nextYear;
        }

        return $value;
    }
}

if (systemSetupRequired() === false) {
    if (!empty($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'admin') {
        header('Location: ' . buildApplicationPath('admin/dashboard.php'));
    } else {
        header('Location: ' . buildApplicationPath('index.php'));
    }
    exit();
}

$error = '';
$success = '';
$defaultAcademicYear = date('Y') . '/' . ((int) date('Y') + 1);
$existingSchoolName = trim((string) getSystemSetting('school_name', ''));
$existingSchoolAcronym = trim((string) getSystemSetting('school_acronym', ''));
$existingSchoolAddress = trim((string) getSystemSetting('school_address', ''));
$existingSchoolPhone = trim((string) getSystemSetting('school_phone', ''));
$existingSchoolEmail = trim((string) getSystemSetting('school_email', ''));
$existingSchoolWebsite = trim((string) getSystemSetting('school_website', ''));
$existingSchoolMotto = trim((string) getSystemSetting('school_motto', ''));
$existingSchoolPrincipal = trim((string) getSystemSetting('school_principal', ''));
$existingAcademicYear = trim((string) getSystemSetting('academic_year', ''));
$existingAdminEmail = trim((string) getSystemSetting('setup_admin_email', ''));

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($requestMethod === 'POST' && isset($_POST['complete_setup'])) {
    $schoolName = trim((string) ($_POST['school_name'] ?? ''));
    $schoolAcronym = trim((string) ($_POST['school_acronym'] ?? ''));
    $schoolAddress = trim((string) ($_POST['school_address'] ?? ''));
    $schoolPhone = trim((string) ($_POST['school_phone'] ?? ''));
    $schoolEmail = trim((string) ($_POST['school_email'] ?? ''));
    $schoolWebsite = trim((string) ($_POST['school_website'] ?? ''));
    $schoolMotto = trim((string) ($_POST['school_motto'] ?? ''));
    $schoolPrincipal = trim((string) ($_POST['school_principal'] ?? ''));
    $academicYear = setupWizardNormalizeAcademicYear((string) ($_POST['academic_year'] ?? ''));

    $adminFullName = trim((string) ($_POST['admin_full_name'] ?? ''));
    $adminUsername = trim((string) ($_POST['admin_username'] ?? ''));
    $adminEmail = trim((string) ($_POST['admin_email'] ?? ''));
    $adminPhone = trim((string) ($_POST['admin_phone'] ?? ''));
    $adminPassword = (string) ($_POST['admin_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    try {
        if ($schoolName === '' || $schoolAddress === '' || $schoolPhone === '' || $schoolEmail === '' || $schoolPrincipal === '') {
            throw new RuntimeException('Enter all required school details before continuing.');
        }
        if (!filter_var($schoolEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid school email address.');
        }
        if ($academicYear === '') {
            throw new RuntimeException('Academic year is required.');
        }
        if ($adminFullName === '' || $adminUsername === '' || $adminEmail === '') {
            throw new RuntimeException('Enter all required admin details.');
        }
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid admin email address.');
        }
        if (strlen($adminPassword) < 6) {
            throw new RuntimeException('Admin password must be at least 6 characters.');
        }
        if ($adminPassword !== $confirmPassword) {
            throw new RuntimeException('Admin password confirmation does not match.');
        }

        $pdo->beginTransaction();

        $settingsToSave = [
            'school_name' => $schoolName,
            'school_acronym' => $schoolAcronym,
            'school_address' => $schoolAddress,
            'school_phone' => $schoolPhone,
            'school_email' => $schoolEmail,
            'school_website' => $schoolWebsite,
            'school_motto' => $schoolMotto,
            'school_principal' => $schoolPrincipal,
            'school_location' => $schoolAddress,
            'academic_year' => $academicYear,
            'current_term_name' => '',
            'term_setup_required' => '1',
            'setup_admin_email' => $adminEmail,
            'setup_completed' => '1',
        ];

        foreach ($settingsToSave as $key => $value) {
            if (!saveSystemSetting($key, (string) $value)) {
                throw new RuntimeException('Failed to save system setting: ' . $key);
            }
        }

        $pdo->exec("UPDATE academic_years SET is_active = 0");
        $yearStmt = $pdo->prepare("
            INSERT INTO academic_years (year, is_active)
            VALUES (?, 1)
            ON DUPLICATE KEY UPDATE is_active = 1, updated_at = CURRENT_TIMESTAMP
        ");
        $yearStmt->execute([$academicYear]);

        $adminLookup = $pdo->prepare("
            SELECT *
            FROM users
            WHERE role = 'admin' AND (email = ? OR username = ?)
            ORDER BY id ASC
            LIMIT 1
        ");
        $adminLookup->execute([$adminEmail, $adminUsername]);
        $existingAdmin = $adminLookup->fetch(PDO::FETCH_ASSOC);

        $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);

        if ($existingAdmin) {
            $updateAdmin = $pdo->prepare("
                UPDATE users
                SET username = ?, password = ?, role = 'admin', full_name = ?, email = ?, phone = ?,
                    status = 'active', email_verified = 1, updated_at = NOW()
                WHERE id = ?
            ");
            $updateAdmin->execute([
                $adminUsername,
                $passwordHash,
                $adminFullName,
                $adminEmail,
                $adminPhone !== '' ? $adminPhone : null,
                (int) $existingAdmin['id'],
            ]);
            $adminId = (int) $existingAdmin['id'];
        } else {
            $conflictStmt = $pdo->prepare("
                SELECT id
                FROM users
                WHERE email = ? OR username = ?
                LIMIT 1
            ");
            $conflictStmt->execute([$adminEmail, $adminUsername]);
            if ($conflictStmt->fetchColumn()) {
                throw new RuntimeException('The admin email or username already belongs to another user.');
            }

            $insertAdmin = $pdo->prepare("
                INSERT INTO users (
                    username, password, role, full_name, email, email_verified,
                    phone, status, created_at, updated_at
                ) VALUES (?, ?, 'admin', ?, ?, 1, ?, 'active', NOW(), NOW())
            ");
            $insertAdmin->execute([
                $adminUsername,
                $passwordHash,
                $adminFullName,
                $adminEmail,
                $adminPhone !== '' ? $adminPhone : null,
            ]);
            $adminId = (int) $pdo->lastInsertId();
        }

        $pdo->commit();

        $_SESSION['user_id'] = $adminId;
        $_SESSION['user_role'] = 'admin';
        $_SESSION['full_name'] = $adminFullName;
        $_SESSION['user_name'] = $adminFullName;
        $_SESSION['email'] = $adminEmail;
        $_SESSION['success'] = 'System setup completed. You can now sign in and continue with the academic calendar setup.';

        createNotification(
            'System Setup Completed',
            'Initial school setup is complete. Please configure the term dates for ' . $academicYear . '.',
            'system',
            $adminId,
            null,
            'high',
            'fas fa-school',
            '#0f766e'
        );

        header('Location: index.php');
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$pageTitle = 'Initial Setup - ' . getSystemSetting('school_name', SCHOOL_NAME);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --ink: #0f172a;
            --muted: #64748b;
            --panel: #ffffff;
            --line: #d9e2ec;
            --accent: #0f766e;
            --accent-2: #2563eb;
            --danger: #b91c1c;
            --danger-bg: #fee2e2;
            --success-bg: #dcfce7;
            --success-text: #166534;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Trebuchet MS", "Segoe UI", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(37, 99, 235, 0.18), transparent 34%),
                radial-gradient(circle at bottom right, rgba(15, 118, 110, 0.22), transparent 30%),
                linear-gradient(145deg, #eef5ff 0%, #f7fbfc 52%, #eef7f3 100%);
            min-height: 100vh;
            padding: 2rem;
        }

        .shell {
            max-width: 1180px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1.1fr 1.4fr;
            gap: 1.5rem;
            align-items: start;
        }

        .hero, .panel {
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.75);
            border-radius: 26px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.10);
        }

        .hero {
            padding: 2rem;
            position: sticky;
            top: 2rem;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.55rem 0.95rem;
            background: rgba(15, 118, 110, 0.10);
            color: var(--accent);
            border-radius: 999px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .hero h1 {
            margin: 1rem 0 0.8rem;
            font-size: 2.4rem;
            line-height: 1.08;
        }

        .hero p {
            color: var(--muted);
            line-height: 1.7;
            margin: 0 0 1.2rem;
        }

        .feature-list {
            display: grid;
            gap: 0.85rem;
            margin-top: 1.4rem;
        }

        .feature {
            display: flex;
            gap: 0.85rem;
            align-items: flex-start;
            padding: 0.95rem 1rem;
            background: #f8fbff;
            border-radius: 18px;
        }

        .feature i {
            color: var(--accent-2);
            margin-top: 0.15rem;
        }

        .panel {
            padding: 1.8rem;
        }

        .panel h2 {
            margin: 0 0 0.4rem;
            font-size: 1.55rem;
        }

        .panel > p {
            margin: 0 0 1.4rem;
            color: var(--muted);
        }

        .section-title {
            margin: 1.6rem 0 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-size: 1rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #1e3a8a;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 0.42rem;
            font-size: 0.92rem;
            font-weight: 700;
        }

        input, textarea {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 0.9rem 1rem;
            font: inherit;
            background: #fff;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        .hint {
            margin-top: 0.35rem;
            color: var(--muted);
            font-size: 0.84rem;
        }

        .alert {
            border-radius: 16px;
            padding: 1rem 1.1rem;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .alert-error {
            background: var(--danger-bg);
            color: var(--danger);
        }

        .alert-success {
            background: var(--success-bg);
            color: var(--success-text);
        }

        .footer-bar {
            margin-top: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .footer-bar p {
            margin: 0;
            color: var(--muted);
            max-width: 520px;
        }

        .btn {
            border: 0;
            border-radius: 14px;
            padding: 0.95rem 1.35rem;
            font: inherit;
            font-weight: 800;
            cursor: pointer;
            color: #fff;
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-2) 100%);
            box-shadow: 0 14px 28px rgba(37, 99, 235, 0.20);
        }

        @media (max-width: 980px) {
            .shell { grid-template-columns: 1fr; }
            .hero { position: static; }
        }

        @media (max-width: 700px) {
            body { padding: 1rem; }
            .grid { grid-template-columns: 1fr; }
            .hero h1 { font-size: 1.95rem; }
            .panel, .hero { padding: 1.25rem; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <section class="hero">
            <span class="hero-badge"><i class="fas fa-school"></i> First Launch Setup</span>
            <h1>Set the school profile, admin account, and academic year before the system opens.</h1>
            <p>This page appears only once at the beginning. After saving it, the system will take you straight to the academic calendar so the term dates and progression can start working automatically.</p>

            <div class="feature-list">
                <div class="feature">
                    <i class="fas fa-building-columns"></i>
                    <div>
                        <strong>School identity</strong>
                        <div class="hint">Name, contact details, principal, motto, and the working academic year.</div>
                    </div>
                </div>
                <div class="feature">
                    <i class="fas fa-user-shield"></i>
                    <div>
                        <strong>Admin ownership</strong>
                        <div class="hint">Creates or updates the main admin account and activates it immediately.</div>
                    </div>
                </div>
                <div class="feature">
                    <i class="fas fa-calendar-days"></i>
                    <div>
                        <strong>Ready for term progression</strong>
                        <div class="hint">After setup, the next page will ask for term dates so progress percentages and closure notices can run automatically.</div>
                    </div>
                </div>
            </div>
        </section>

        <section class="panel">
            <h2>Launch the School System</h2>
            <p>Fill in the core details below. Fields marked by the system as required must be completed before the first admin session can begin.</p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success !== ''): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="section-title"><i class="fas fa-school-flag"></i> School Details</div>
                <div class="grid">
                    <div class="field full">
                        <label for="school_name">School Name</label>
                        <input id="school_name" type="text" name="school_name" value="<?php echo htmlspecialchars($_POST['school_name'] ?? ($existingSchoolName !== '' ? $existingSchoolName : 'Mariango School')); ?>" required>
                    </div>
                    <div class="field">
                        <label for="school_acronym">Acronym</label>
                        <input id="school_acronym" type="text" name="school_acronym" value="<?php echo htmlspecialchars($_POST['school_acronym'] ?? $existingSchoolAcronym); ?>" placeholder="MGS">
                    </div>
                    <div class="field">
                        <label for="academic_year">Academic Year</label>
                        <input id="academic_year" type="text" name="academic_year" value="<?php echo htmlspecialchars($_POST['academic_year'] ?? ($existingAcademicYear !== '' ? $existingAcademicYear : $defaultAcademicYear)); ?>" placeholder="2026/2027" required>
                    </div>
                    <div class="field">
                        <label for="school_phone">School Phone</label>
                        <input id="school_phone" type="text" name="school_phone" value="<?php echo htmlspecialchars($_POST['school_phone'] ?? $existingSchoolPhone); ?>" required>
                    </div>
                    <div class="field">
                        <label for="school_email">School Email</label>
                        <input id="school_email" type="email" name="school_email" value="<?php echo htmlspecialchars($_POST['school_email'] ?? $existingSchoolEmail); ?>" required>
                    </div>
                    <div class="field full">
                        <label for="school_address">School Address</label>
                        <textarea id="school_address" name="school_address" required><?php echo htmlspecialchars($_POST['school_address'] ?? $existingSchoolAddress); ?></textarea>
                    </div>
                    <div class="field">
                        <label for="school_website">Website</label>
                        <input id="school_website" type="text" name="school_website" value="<?php echo htmlspecialchars($_POST['school_website'] ?? $existingSchoolWebsite); ?>" placeholder="https://example.com">
                    </div>
                    <div class="field">
                        <label for="school_principal">Principal / Head Teacher</label>
                        <input id="school_principal" type="text" name="school_principal" value="<?php echo htmlspecialchars($_POST['school_principal'] ?? $existingSchoolPrincipal); ?>" required>
                    </div>
                    <div class="field full">
                        <label for="school_motto">School Motto</label>
                        <input id="school_motto" type="text" name="school_motto" value="<?php echo htmlspecialchars($_POST['school_motto'] ?? $existingSchoolMotto); ?>" placeholder="Excellence, Discipline, Service">
                    </div>
                </div>

                <div class="section-title"><i class="fas fa-user-tie"></i> Admin Details</div>
                <div class="grid">
                    <div class="field full">
                        <label for="admin_full_name">Admin Full Name</label>
                        <input id="admin_full_name" type="text" name="admin_full_name" value="<?php echo htmlspecialchars($_POST['admin_full_name'] ?? ''); ?>" required>
                    </div>
                    <div class="field">
                        <label for="admin_username">Admin Username</label>
                        <input id="admin_username" type="text" name="admin_username" value="<?php echo htmlspecialchars($_POST['admin_username'] ?? ''); ?>" required>
                    </div>
                    <div class="field">
                        <label for="admin_email">Admin Email</label>
                        <input id="admin_email" type="email" name="admin_email" value="<?php echo htmlspecialchars($_POST['admin_email'] ?? $existingAdminEmail); ?>" required>
                    </div>
                    <div class="field">
                        <label for="admin_phone">Admin Phone</label>
                        <input id="admin_phone" type="text" name="admin_phone" value="<?php echo htmlspecialchars($_POST['admin_phone'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="admin_password">Admin Password</label>
                        <input id="admin_password" type="password" name="admin_password" required>
                        <div class="hint">Use at least 6 characters.</div>
                    </div>
                    <div class="field">
                        <label for="confirm_password">Confirm Password</label>
                        <input id="confirm_password" type="password" name="confirm_password" required>
                    </div>
                </div>

                <div class="footer-bar">
                    <p>After this step, the system will redirect to the academic calendar page so the admin can enter the term start and end dates for automatic progress tracking.</p>
                    <button class="btn" type="submit" name="complete_setup">
                        <i class="fas fa-rocket"></i> Complete Setup
                    </button>
                </div>
            </form>
        </section>
    </div>
</body>
</html>
