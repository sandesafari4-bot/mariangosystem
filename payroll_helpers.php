<?php

function payrollEnsureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function payrollEnsureSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payroll_staff_profiles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            staff_category VARCHAR(50) NOT NULL DEFAULT 'non_teaching_staff',
            employment_type VARCHAR(50) NOT NULL DEFAULT 'monthly',
            basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
            house_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
            transport_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
            medical_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
            other_allowances DECIMAL(12,2) NOT NULL DEFAULT 0,
            tax_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
            pension_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
            other_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
            phone_number VARCHAR(30) DEFAULT NULL,
            bank_name VARCHAR(100) DEFAULT NULL,
            account_number VARCHAR(100) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT DEFAULT NULL,
            updated_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_payroll_staff_category (staff_category),
            INDEX idx_payroll_staff_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    payrollEnsureColumn($pdo, 'payroll_staff_profiles', 'tax_deduction', "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `other_allowances`");
    payrollEnsureColumn($pdo, 'payroll_staff_profiles', 'pension_deduction', "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `tax_deduction`");
    payrollEnsureColumn($pdo, 'payroll_staff_profiles', 'other_deductions', "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `pension_deduction`");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payroll_runs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            payroll_month TINYINT NOT NULL,
            payroll_year SMALLINT NOT NULL,
            payroll_code VARCHAR(50) NOT NULL UNIQUE,
            title VARCHAR(150) NOT NULL,
            notes TEXT DEFAULT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            total_staff INT NOT NULL DEFAULT 0,
            eligible_staff INT NOT NULL DEFAULT 0,
            total_gross DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_net DECIMAL(12,2) NOT NULL DEFAULT 0,
            prepared_by INT NOT NULL,
            submitted_by INT DEFAULT NULL,
            submitted_at DATETIME DEFAULT NULL,
            approved_by INT DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            rejected_by INT DEFAULT NULL,
            rejected_at DATETIME DEFAULT NULL,
            rejection_reason TEXT DEFAULT NULL,
            paid_by INT DEFAULT NULL,
            paid_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_payroll_period_status (payroll_month, payroll_year, status),
            INDEX idx_payroll_status (status),
            INDEX idx_payroll_period (payroll_year, payroll_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payroll_run_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            payroll_run_id INT NOT NULL,
            user_id INT NOT NULL,
            staff_name VARCHAR(150) NOT NULL,
            role VARCHAR(50) NOT NULL,
            staff_category VARCHAR(50) NOT NULL,
            employment_type VARCHAR(50) NOT NULL DEFAULT 'monthly',
            basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_allowances DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
            gross_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
            net_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
            payment_status VARCHAR(30) NOT NULL DEFAULT 'pending',
            payment_method VARCHAR(50) DEFAULT NULL,
            payment_reference VARCHAR(100) DEFAULT NULL,
            paid_at DATETIME DEFAULT NULL,
            paid_by INT DEFAULT NULL,
            remarks TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_payroll_run_user (payroll_run_id, user_id),
            INDEX idx_payroll_item_status (payment_status),
            INDEX idx_payroll_run (payroll_run_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $runItemColumns = [
        'nssf_employee' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `gross_salary`",
        'nssf_employer' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `nssf_employee`",
        'sha_employee' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `nssf_employer`",
        'housing_levy_employee' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `sha_employee`",
        'housing_levy_employer' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `housing_levy_employee`",
        'additional_pension' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `housing_levy_employer`",
        'other_pre_tax_deductions' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `additional_pension`",
        'pre_tax_deductions' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `other_pre_tax_deductions`",
        'taxable_pay' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `pre_tax_deductions`",
        'paye_before_relief' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `taxable_pay`",
        'personal_relief' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `paye_before_relief`",
        'paye_tax' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `personal_relief`",
        'post_tax_deductions' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `paye_tax`",
        'employer_cost' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `net_salary`",
    ];

    foreach ($runItemColumns as $column => $definition) {
        payrollEnsureColumn($pdo, 'payroll_run_items', $column, $definition);
    }
}

function payrollEligibleCategories(): array
{
    return ['bom_teacher', 'non_teaching_staff'];
}

function payrollCategoryOptions(): array
{
    return [
        'bom_teacher' => 'BOM Teacher',
        'non_teaching_staff' => 'Non-Teaching Staff',
        'teaching_staff' => 'Teaching Staff',
        'management' => 'Management',
        'other' => 'Other',
    ];
}

function payrollEmploymentOptions(): array
{
    return [
        'monthly' => 'Monthly',
        'contract' => 'Contract',
        'casual' => 'Casual',
    ];
}

function payrollCategoryLabel(string $category): string
{
    $labels = payrollCategoryOptions();
    return $labels[$category] ?? ucwords(str_replace('_', ' ', $category));
}

function payrollStatusClass(string $status): string
{
    $map = [
        'draft' => 'status-draft',
        'submitted' => 'status-submitted',
        'approved' => 'status-approved',
        'rejected' => 'status-rejected',
        'paid' => 'status-paid',
        'partially_paid' => 'status-partial',
        'pending' => 'status-submitted',
    ];

    return $map[$status] ?? 'status-draft';
}

function payrollMoney($amount): string
{
    return 'KES ' . number_format((float) $amount, 2);
}

function payrollGenerateCode(int $month, int $year): string
{
    return 'PAY-' . $year . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function payrollKenyaStatutoryConfig(?DateTimeInterface $forDate = null): array
{
    $forDate = $forDate ?: new DateTimeImmutable('now');
    $effectiveDate = $forDate->format('Y-m-d');

    $nssfLowerLimit = 9000.00;
    $nssfUpperLimit = 108000.00;

    if ($effectiveDate < '2026-02-01') {
        $nssfLowerLimit = 8000.00;
        $nssfUpperLimit = 72000.00;
    }

    return [
        'effective_date' => $effectiveDate,
        'paye_bands' => [
            ['limit' => 24000.00, 'rate' => 0.10],
            ['limit' => 8333.00, 'rate' => 0.25],
            ['limit' => 467667.00, 'rate' => 0.30],
            ['limit' => 300000.00, 'rate' => 0.325],
            ['limit' => null, 'rate' => 0.35],
        ],
        'personal_relief' => 2400.00,
        'sha_rate' => 0.0275,
        'sha_minimum' => 300.00,
        'housing_levy_rate_employee' => 0.015,
        'housing_levy_rate_employer' => 0.015,
        'nssf_rate_employee' => 0.06,
        'nssf_rate_employer' => 0.06,
        'nssf_lower_limit' => $nssfLowerLimit,
        'nssf_upper_limit' => $nssfUpperLimit,
        'pension_tax_cap' => 30000.00,
        'sources' => [
            'paye' => 'https://kra.go.ke/images/publications/PAYE-AS-YOU-EARN-PAYE_4-01-2025.pdf',
            'housing_levy' => 'https://kra.go.ke/news-center/public-notices/2099-collection-of-the-affordable-housing-levy-by-kenya-revenue-authority',
            'tax_amendments' => 'https://kra.go.ke/news-center/public-notices/2157-amendments-to-paye-computation-pursuant-to-the-tax-laws-amendment-act%2C-2024',
            'shif' => 'https://new.kenyalaw.org/akn/ke/act/ln/2024/49/eng%402024-09-20/source',
            'nssf_notice' => 'https://www.mygov.go.ke/node/3472',
        ],
    ];
}

function payrollKenyaPaye(float $taxablePay, array $config): array
{
    $remaining = max(0, $taxablePay);
    $grossTax = 0.0;

    foreach ($config['paye_bands'] as $band) {
        $bandLimit = $band['limit'];
        $bandRate = $band['rate'];
        if ($remaining <= 0) {
            break;
        }

        $taxableBandAmount = $bandLimit === null ? $remaining : min($remaining, $bandLimit);
        $grossTax += $taxableBandAmount * $bandRate;
        $remaining -= $taxableBandAmount;
    }

    $netTax = max(0, $grossTax - $config['personal_relief']);

    return [
        'gross_tax' => $grossTax,
        'relief' => $config['personal_relief'],
        'net_tax' => $netTax,
    ];
}

function payrollFetchStaff(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            u.id,
            u.full_name,
            u.email,
            u.phone,
            u.role,
            u.status,
            u.employment_date,
            COALESCE(psp.staff_category, CASE WHEN u.role = 'teacher' THEN 'bom_teacher' ELSE 'non_teaching_staff' END) as staff_category,
            COALESCE(psp.employment_type, 'monthly') as employment_type,
            COALESCE(psp.basic_salary, 0) as basic_salary,
            COALESCE(psp.house_allowance, 0) as house_allowance,
            COALESCE(psp.transport_allowance, 0) as transport_allowance,
            COALESCE(psp.medical_allowance, 0) as medical_allowance,
            COALESCE(psp.other_allowances, 0) as other_allowances,
            COALESCE(psp.tax_deduction, 0) as tax_deduction,
            COALESCE(psp.pension_deduction, 0) as pension_deduction,
            COALESCE(psp.other_deductions, 0) as other_deductions,
            COALESCE(psp.phone_number, u.phone) as payroll_phone,
            psp.bank_name,
            psp.account_number,
            psp.notes,
            COALESCE(psp.is_active, 1) as payroll_active
        FROM users u
        LEFT JOIN payroll_staff_profiles psp ON psp.user_id = u.id
        WHERE u.status = 'active'
          AND u.role IN ('teacher', 'staff', 'accountant', 'librarian', 'admin')
        ORDER BY
            CASE WHEN u.role = 'teacher' THEN 1 ELSE 2 END,
            u.full_name ASC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function payrollSummarizeStaff(array $staff): array
{
    $allowances = (float) $staff['house_allowance']
        + (float) $staff['transport_allowance']
        + (float) $staff['medical_allowance']
        + (float) $staff['other_allowances'];
    $gross = (float) $staff['basic_salary'] + $allowances;
    $config = payrollKenyaStatutoryConfig();

    $pensionablePay = min($gross, (float) $config['nssf_upper_limit']);
    $nssfEmployee = round($pensionablePay * (float) $config['nssf_rate_employee'], 2);
    $nssfEmployer = round($pensionablePay * (float) $config['nssf_rate_employer'], 2);
    $shaEmployee = round(max($gross * (float) $config['sha_rate'], (float) $config['sha_minimum']), 2);
    $housingLevyEmployee = round($gross * (float) $config['housing_levy_rate_employee'], 2);
    $housingLevyEmployer = round($gross * (float) $config['housing_levy_rate_employer'], 2);

    $additionalPension = min((float) $staff['pension_deduction'], (float) $config['pension_tax_cap']);
    $otherPreTax = (float) $staff['tax_deduction'];
    $postTaxDeductions = (float) $staff['other_deductions'];

    $preTaxDeductions = $nssfEmployee + $additionalPension + $otherPreTax + $housingLevyEmployee + $shaEmployee;
    $taxablePay = max(0, $gross - $nssfEmployee - $additionalPension - $otherPreTax - $housingLevyEmployee - $shaEmployee);
    $paye = payrollKenyaPaye($taxablePay, $config);
    $totalDeductions = $preTaxDeductions + $paye['net_tax'] + $postTaxDeductions;
    $net = max(0, $gross - $totalDeductions);
    $employerCost = $gross + $nssfEmployer + $housingLevyEmployer;

    return [
        'config' => $config,
        'total_allowances' => $allowances,
        'gross_salary' => $gross,
        'nssf_employee' => $nssfEmployee,
        'nssf_employer' => $nssfEmployer,
        'sha_employee' => $shaEmployee,
        'housing_levy_employee' => $housingLevyEmployee,
        'housing_levy_employer' => $housingLevyEmployer,
        'additional_pension' => $additionalPension,
        'other_pre_tax_deductions' => $otherPreTax,
        'pre_tax_deductions' => $preTaxDeductions,
        'taxable_pay' => $taxablePay,
        'paye_before_relief' => $paye['gross_tax'],
        'personal_relief' => $paye['relief'],
        'paye_tax' => $paye['net_tax'],
        'post_tax_deductions' => $postTaxDeductions,
        'total_deductions' => $totalDeductions,
        'net_salary' => $net,
        'employer_cost' => $employerCost,
    ];
}

function payrollInsertNotification(PDO $pdo, array $payload): void
{
    try {
        $columns = array_fill_keys($pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN), true);
    } catch (Exception $e) {
        return;
    }

    $data = [
        'user_id' => $payload['user_id'] ?? null,
        'type' => $payload['type'] ?? 'payroll',
        'title' => $payload['title'] ?? 'Payroll update',
        'priority' => $payload['priority'] ?? 'normal',
        'message' => $payload['message'] ?? '',
        'related_id' => $payload['related_id'] ?? null,
        'related_type' => $payload['related_type'] ?? 'payroll_run',
    ];

    $fields = [];
    $placeholders = [];
    $values = [];

    foreach ($data as $column => $value) {
        if ($value === null || !isset($columns[$column])) {
            continue;
        }
        $fields[] = $column;
        $placeholders[] = '?';
        $values[] = $value;
    }

    if (!$fields) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO notifications (" . implode(', ', $fields) . ")
        VALUES (" . implode(', ', $placeholders) . ")
    ");
    $stmt->execute($values);
}
