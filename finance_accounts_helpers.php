<?php

function financeEnsureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function financeEnsureSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS school_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_name VARCHAR(120) NOT NULL,
            account_type VARCHAR(30) NOT NULL DEFAULT 'bank',
            provider_name VARCHAR(120) DEFAULT NULL,
            account_number VARCHAR(120) DEFAULT NULL,
            phone_number VARCHAR(30) DEFAULT NULL,
            current_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT 'KES',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_default_salary TINYINT(1) NOT NULL DEFAULT 0,
            is_default_expense TINYINT(1) NOT NULL DEFAULT 0,
            notes TEXT DEFAULT NULL,
            created_by INT DEFAULT NULL,
            updated_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_school_accounts_active (is_active),
            INDEX idx_school_accounts_type (account_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS school_account_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            transaction_type VARCHAR(30) NOT NULL,
            direction VARCHAR(10) NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            balance_before DECIMAL(14,2) NOT NULL DEFAULT 0,
            balance_after DECIMAL(14,2) NOT NULL DEFAULT 0,
            reference_no VARCHAR(100) DEFAULT NULL,
            counterparty_name VARCHAR(150) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            related_type VARCHAR(50) DEFAULT NULL,
            related_id INT DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_school_account_transactions_account (account_id),
            INDEX idx_school_account_transactions_related (related_type, related_id),
            INDEX idx_school_account_transactions_date (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    financeEnsureColumn($pdo, 'expenses', 'payment_status', "VARCHAR(30) NOT NULL DEFAULT 'unpaid' AFTER `status`");
    financeEnsureColumn($pdo, 'expenses', 'paid_at', "DATETIME DEFAULT NULL AFTER `approved_at`");
    financeEnsureColumn($pdo, 'expenses', 'paid_by', "INT DEFAULT NULL AFTER `paid_at`");
    financeEnsureColumn($pdo, 'expenses', 'paid_from_account_id', "INT DEFAULT NULL AFTER `paid_by`");
    financeEnsureColumn($pdo, 'expenses', 'payment_reference', "VARCHAR(100) DEFAULT NULL AFTER `paid_from_account_id`");

    financeEnsureColumn($pdo, 'payroll_runs', 'paid_from_account_id', "INT DEFAULT NULL AFTER `paid_by`");
    financeEnsureColumn($pdo, 'payroll_runs', 'payment_reference', "VARCHAR(100) DEFAULT NULL AFTER `paid_from_account_id`");
}

function financeGenerateReference(string $prefix = 'TXN'): string
{
    return strtoupper($prefix) . '-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function financeGetActiveAccounts(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT *
        FROM school_accounts
        WHERE is_active = 1
        ORDER BY
            is_default_salary DESC,
            is_default_expense DESC,
            account_type ASC,
            account_name ASC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function financeGetAccountById(PDO $pdo, int $accountId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM school_accounts WHERE id = ?");
    $stmt->execute([$accountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function financeSetDefaultAccount(PDO $pdo, int $accountId, string $purpose, int $userId): void
{
    if (!in_array($purpose, ['salary', 'expense'], true)) {
        throw new InvalidArgumentException('Invalid account purpose.');
    }

    $column = $purpose === 'salary' ? 'is_default_salary' : 'is_default_expense';
    $pdo->exec("UPDATE school_accounts SET `$column` = 0");
    $stmt = $pdo->prepare("UPDATE school_accounts SET `$column` = 1, updated_by = ? WHERE id = ?");
    $stmt->execute([$userId, $accountId]);
}

function financeRecordAccountTransaction(PDO $pdo, array $data): string
{
    $accountId = (int) ($data['account_id'] ?? 0);
    $amount = round((float) ($data['amount'] ?? 0), 2);
    $direction = strtolower((string) ($data['direction'] ?? ''));
    $transactionType = trim((string) ($data['transaction_type'] ?? 'manual'));
    $referenceNo = trim((string) ($data['reference_no'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));
    $createdBy = isset($data['created_by']) ? (int) $data['created_by'] : null;
    $counterpartyName = trim((string) ($data['counterparty_name'] ?? ''));
    $relatedType = trim((string) ($data['related_type'] ?? ''));
    $relatedId = isset($data['related_id']) ? (int) $data['related_id'] : null;

    if ($accountId <= 0) {
        throw new Exception('Please choose a school account.');
    }
    if ($amount <= 0) {
        throw new Exception('Amount must be greater than zero.');
    }
    if (!in_array($direction, ['credit', 'debit'], true)) {
        throw new Exception('Invalid transaction direction.');
    }

    $accountStmt = $pdo->prepare("SELECT * FROM school_accounts WHERE id = ? FOR UPDATE");
    $accountStmt->execute([$accountId]);
    $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        throw new Exception('Selected school account was not found.');
    }
    if ((int) $account['is_active'] !== 1) {
        throw new Exception('Selected school account is inactive.');
    }

    $balanceBefore = (float) $account['current_balance'];
    $balanceAfter = $direction === 'credit' ? ($balanceBefore + $amount) : ($balanceBefore - $amount);

    if ($direction === 'debit' && $balanceAfter < 0) {
        throw new Exception('Insufficient balance in ' . $account['account_name'] . '.');
    }

    if ($referenceNo === '') {
        $referenceNo = financeGenerateReference($direction === 'credit' ? 'CR' : 'DR');
    }

    $updateStmt = $pdo->prepare("UPDATE school_accounts SET current_balance = ?, updated_by = ? WHERE id = ?");
    $updateStmt->execute([$balanceAfter, $createdBy, $accountId]);

    $insertStmt = $pdo->prepare("
        INSERT INTO school_account_transactions (
            account_id, transaction_type, direction, amount, balance_before, balance_after,
            reference_no, counterparty_name, description, related_type, related_id, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insertStmt->execute([
        $accountId,
        $transactionType,
        $direction,
        $amount,
        $balanceBefore,
        $balanceAfter,
        $referenceNo,
        $counterpartyName !== '' ? $counterpartyName : null,
        $description !== '' ? $description : null,
        $relatedType !== '' ? $relatedType : null,
        $relatedId ?: null,
        $createdBy,
    ]);

    return $referenceNo;
}

function financeGetDefaultAccount(PDO $pdo, string $purpose): ?array
{
    $column = $purpose === 'salary' ? 'is_default_salary' : 'is_default_expense';
    $stmt = $pdo->query("SELECT * FROM school_accounts WHERE is_active = 1 AND `$column` = 1 ORDER BY id ASC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function financeResolveDisbursementAccount(PDO $pdo, string $paymentMethod, ?int $preferredAccountId = null): ?array
{
    if ($preferredAccountId && $preferredAccountId > 0) {
        $account = financeGetAccountById($pdo, $preferredAccountId);
        if ($account && (int) ($account['is_active'] ?? 0) === 1) {
            return $account;
        }
    }

    $paymentMethod = strtolower(trim($paymentMethod));
    $preferredType = match ($paymentMethod) {
        'mpesa' => 'mpesa',
        'bank_transfer', 'cheque' => 'bank',
        'cash' => 'cash',
        default => null,
    };

    if ($preferredType !== null) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM school_accounts
            WHERE is_active = 1 AND account_type = ?
            ORDER BY
                is_default_expense DESC,
                current_balance DESC,
                id ASC
            LIMIT 1
        ");
        $stmt->execute([$preferredType]);
        $typedAccount = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($typedAccount) {
            return $typedAccount;
        }
    }

    $defaultExpenseAccount = financeGetDefaultAccount($pdo, 'expense');
    if ($defaultExpenseAccount) {
        return $defaultExpenseAccount;
    }

    $fallback = $pdo->query("
        SELECT *
        FROM school_accounts
        WHERE is_active = 1
        ORDER BY current_balance DESC, id ASC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    return $fallback ?: null;
}

function financeResolveCollectionAccount(PDO $pdo, string $paymentMethod): ?array
{
    $paymentMethod = strtolower(trim($paymentMethod));
    $preferredType = match ($paymentMethod) {
        'mpesa' => 'mpesa',
        'bank_transfer', 'cheque' => 'bank',
        'cash' => 'cash',
        default => 'bank',
    };

    $stmt = $pdo->prepare("
        SELECT *
        FROM school_accounts
        WHERE is_active = 1 AND account_type = ?
        ORDER BY current_balance DESC, id ASC
        LIMIT 1
    ");
    $stmt->execute([$preferredType]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($account) {
        return $account;
    }

    $fallback = $pdo->query("
        SELECT *
        FROM school_accounts
        WHERE is_active = 1
        ORDER BY current_balance DESC, id ASC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    return $fallback ?: null;
}

function financeRecordStudentCollection(PDO $pdo, float $amount, string $paymentMethod, string $reference, int $paymentId, int $studentId, int $userId = 0): ?string
{
    $account = financeResolveCollectionAccount($pdo, $paymentMethod);
    if (!$account) {
        return null;
    }

    $transactionType = match (strtolower($paymentMethod)) {
        'mpesa' => 'student_collection_mpesa',
        'cash' => 'student_collection_cash',
        'bank_transfer' => 'student_collection_bank',
        'cheque' => 'student_collection_cheque',
        default => 'student_collection',
    };

    return financeRecordAccountTransaction($pdo, [
        'account_id' => (int) $account['id'],
        'transaction_type' => $transactionType,
        'direction' => 'credit',
        'amount' => $amount,
        'reference_no' => $reference !== '' ? $reference : financeGenerateReference('COL'),
        'counterparty_name' => 'Student payment',
        'description' => 'Student fee collection posted to school funds',
        'related_type' => 'payment',
        'related_id' => $paymentId,
        'created_by' => $userId,
    ]);
}

function financeStudentPaymentCondition(string $alias = 'payments'): string
{
    return "(COALESCE($alias.status, '') = '' OR $alias.status IN ('completed', 'paid', 'verified'))";
}

function financeGetUnreconciledCollectionSummary(PDO $pdo): array
{
    $paymentCondition = financeStudentPaymentCondition('p');
    $stmt = $pdo->query("
        SELECT
            COUNT(*) as payment_count,
            COALESCE(SUM(p.amount), 0) as total_amount
        FROM payments p
        LEFT JOIN school_account_transactions sat
            ON sat.related_type = 'payment' AND sat.related_id = p.id
        WHERE $paymentCondition
          AND sat.id IS NULL
    ");

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'payment_count' => (int) ($row['payment_count'] ?? 0),
        'total_amount' => (float) ($row['total_amount'] ?? 0),
    ];
}

function financeSyncHistoricalCollections(PDO $pdo, int $userId = 0): array
{
    $paymentCondition = financeStudentPaymentCondition('p');
    $stmt = $pdo->query("
        SELECT p.*
        FROM payments p
        LEFT JOIN school_account_transactions sat
            ON sat.related_type = 'payment' AND sat.related_id = p.id
        WHERE $paymentCondition
          AND sat.id IS NULL
        ORDER BY p.payment_date ASC, p.id ASC
    ");

    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $syncedCount = 0;
    $syncedAmount = 0.0;

    foreach ($payments as $payment) {
        $method = strtolower((string) ($payment['payment_method'] ?? ''));
        if ($method === '' && !empty($payment['mpesa_receipt'])) {
            $method = 'mpesa';
        }
        if ($method === '' && !empty($payment['payment_method_id'])) {
            $methodStmt = $pdo->prepare("SELECT code FROM payment_methods WHERE id = ? LIMIT 1");
            $methodStmt->execute([(int) $payment['payment_method_id']]);
            $method = strtolower((string) ($methodStmt->fetchColumn() ?: ''));
        }
        if ($method === '') {
            $method = 'bank_transfer';
        }

        $reference = '';
        foreach (['mpesa_receipt', 'reference_no', 'reference', 'transaction_ref', 'transaction_id', 'payment_id'] as $column) {
            if (!empty($payment[$column])) {
                $reference = (string) $payment[$column];
                break;
            }
        }

        financeRecordStudentCollection(
            $pdo,
            (float) $payment['amount'],
            $method,
            $reference,
            (int) $payment['id'],
            (int) ($payment['student_id'] ?? 0),
            $userId
        );

        $syncedCount++;
        $syncedAmount += (float) $payment['amount'];
    }

    return [
        'payment_count' => $syncedCount,
        'total_amount' => $syncedAmount,
    ];
}

function financePayExpense(PDO $pdo, int $expenseId, int $accountId, int $userId, string $notes = ''): string
{
    $expenseStmt = $pdo->prepare("
        SELECT e.*, COALESCE(ec.name, CONCAT('Category #', e.category_id)) as category_name
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.id
        WHERE e.id = ?
        FOR UPDATE
    ");
    $expenseStmt->execute([$expenseId]);
    $expense = $expenseStmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) {
        throw new Exception('Expense record not found.');
    }
    if (!in_array((string) $expense['status'], ['approved', 'paid'], true)) {
        throw new Exception('Only approved expenses can be paid from school funds.');
    }
    if (($expense['payment_status'] ?? 'unpaid') === 'paid') {
        throw new Exception('This expense has already been paid.');
    }

    $reference = financeRecordAccountTransaction($pdo, [
        'account_id' => $accountId,
        'transaction_type' => 'expense_payment',
        'direction' => 'debit',
        'amount' => (float) $expense['amount'],
        'reference_no' => financeGenerateReference('EXP'),
        'counterparty_name' => $expense['vendor'] ?? $expense['category_name'] ?? 'Expense',
        'description' => 'Expense payment: ' . ($expense['description'] ?? 'School expense'),
        'related_type' => 'expense',
        'related_id' => $expenseId,
        'created_by' => $userId,
    ]);

    $updateStmt = $pdo->prepare("
        UPDATE expenses
        SET status = 'paid',
            payment_status = 'paid',
            paid_at = NOW(),
            paid_by = ?,
            paid_from_account_id = ?,
            payment_reference = ?
        WHERE id = ?
    ");
    $updateStmt->execute([$userId, $accountId, $reference, $expenseId]);

    if ($notes !== '') {
        $notesStmt = $pdo->prepare("
            UPDATE expenses
            SET notes = CONCAT(IFNULL(notes, ''), IF(IFNULL(notes, '') = '', '', '\n'), ?)
            WHERE id = ?
        ");
        $notesStmt->execute([$notes, $expenseId]);
    }

    return $reference;
}
