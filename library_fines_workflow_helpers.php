<?php

if (!function_exists('libraryChargeTableExists')) {
    function libraryChargeTableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('libraryChargeColumnExists')) {
    function libraryChargeColumnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool) $stmt->fetch();
    }
}

if (!function_exists('ensureLibraryFineWorkflowSchema')) {
    function ensureLibraryFineWorkflowSchema(PDO $pdo): void
    {
        if (libraryChargeTableExists($pdo, 'book_fines')) {
            $bookFineColumns = [
                'created_by' => "ALTER TABLE book_fines ADD COLUMN created_by INT NULL AFTER status",
                'sent_by' => "ALTER TABLE book_fines ADD COLUMN sent_by INT NULL AFTER created_by",
                'submitted_by' => "ALTER TABLE book_fines ADD COLUMN submitted_by INT NULL AFTER sent_by",
                'submitted_at' => "ALTER TABLE book_fines ADD COLUMN submitted_at DATETIME NULL AFTER submitted_by",
                'approved_by' => "ALTER TABLE book_fines ADD COLUMN approved_by INT NULL AFTER submitted_at",
                'approved_at' => "ALTER TABLE book_fines ADD COLUMN approved_at DATETIME NULL AFTER approved_by",
                'approval_notes' => "ALTER TABLE book_fines ADD COLUMN approval_notes TEXT NULL AFTER approved_at",
                'invoice_id' => "ALTER TABLE book_fines ADD COLUMN invoice_id INT NULL AFTER approval_notes",
                'invoice_generated_by' => "ALTER TABLE book_fines ADD COLUMN invoice_generated_by INT NULL AFTER invoice_id",
                'invoice_generated_at' => "ALTER TABLE book_fines ADD COLUMN invoice_generated_at DATETIME NULL AFTER invoice_generated_by",
            ];

            foreach ($bookFineColumns as $column => $sql) {
                if (!libraryChargeColumnExists($pdo, 'book_fines', $column)) {
                    $pdo->exec($sql);
                }
            }

            $pdo->exec("
                ALTER TABLE book_fines
                MODIFY COLUMN status ENUM(
                    'pending',
                    'submitted_for_approval',
                    'approved',
                    'rejected',
                    'sent_to_accountant',
                    'invoiced',
                    'paid',
                    'waived'
                ) DEFAULT 'pending'
            ");
        }

        if (libraryChargeTableExists($pdo, 'lost_books')) {
            $lostBookColumns = [
                'issue_id' => "ALTER TABLE lost_books ADD COLUMN issue_id INT NULL AFTER student_id",
                'book_title' => "ALTER TABLE lost_books ADD COLUMN book_title VARCHAR(255) NULL AFTER book_id",
                'book_isbn' => "ALTER TABLE lost_books ADD COLUMN book_isbn VARCHAR(50) NULL AFTER book_title",
                'original_price' => "ALTER TABLE lost_books ADD COLUMN original_price DECIMAL(10,2) NULL AFTER book_isbn",
                'fine_amount' => "ALTER TABLE lost_books ADD COLUMN fine_amount DECIMAL(10,2) NULL AFTER replacement_cost",
                'created_by' => "ALTER TABLE lost_books ADD COLUMN created_by INT NULL AFTER status",
                'sent_by' => "ALTER TABLE lost_books ADD COLUMN sent_by INT NULL AFTER created_by",
                'submitted_by' => "ALTER TABLE lost_books ADD COLUMN submitted_by INT NULL AFTER sent_by",
                'submitted_at' => "ALTER TABLE lost_books ADD COLUMN submitted_at DATETIME NULL AFTER submitted_by",
                'approved_by' => "ALTER TABLE lost_books ADD COLUMN approved_by INT NULL AFTER submitted_at",
                'approved_at' => "ALTER TABLE lost_books ADD COLUMN approved_at DATETIME NULL AFTER approved_by",
                'approval_notes' => "ALTER TABLE lost_books ADD COLUMN approval_notes TEXT NULL AFTER approved_at",
                'invoice_id' => "ALTER TABLE lost_books ADD COLUMN invoice_id INT NULL AFTER approval_notes",
                'invoice_generated_by' => "ALTER TABLE lost_books ADD COLUMN invoice_generated_by INT NULL AFTER invoice_id",
                'invoice_generated_at' => "ALTER TABLE lost_books ADD COLUMN invoice_generated_at DATETIME NULL AFTER invoice_generated_by",
            ];

            foreach ($lostBookColumns as $column => $sql) {
                if (!libraryChargeColumnExists($pdo, 'lost_books', $column)) {
                    $pdo->exec($sql);
                }
            }

            $pdo->exec("
                ALTER TABLE lost_books
                MODIFY COLUMN status ENUM(
                    'reported',
                    'pending',
                    'submitted_for_approval',
                    'approved',
                    'rejected',
                    'verified',
                    'sent_to_accountant',
                    'invoiced',
                    'paid'
                ) DEFAULT 'reported'
            ");
        }
    }
}

if (!function_exists('libraryFineMoney')) {
    function libraryFineMoney(float $amount): string
    {
        return 'KES ' . number_format($amount, 2);
    }
}

if (!function_exists('libraryStudentSchemaConfig')) {
    function libraryStudentSchemaConfig(PDO $pdo): array
    {
        $columns = $pdo->query("SHOW COLUMNS FROM students")->fetchAll(PDO::FETCH_COLUMN);
        $lookup = array_fill_keys($columns, true);

        $admissionColumn = isset($lookup['admission_number'])
            ? 'admission_number'
            : (isset($lookup['Admission_number']) ? 'Admission_number' : null);

        return [
            'admission_column' => $admissionColumn,
        ];
    }
}

if (!function_exists('libraryFetchChargeRows')) {
    function libraryFetchChargeRows(PDO $pdo, array $statuses = []): array
    {
        ensureLibraryFineWorkflowSchema($pdo);
        $studentSchema = libraryStudentSchemaConfig($pdo);
        $admissionExpr = $studentSchema['admission_column']
            ? "s.`{$studentSchema['admission_column']}`"
            : "''";

        $rows = [];
        $hasBookFines = libraryChargeTableExists($pdo, 'book_fines');
        $hasLostBooks = libraryChargeTableExists($pdo, 'lost_books');

        if ($hasBookFines) {
            $sql = "
                SELECT
                    bf.id AS charge_id,
                    'fine' AS source_type,
                    bf.student_id,
                    s.full_name AS student_name,
                    {$admissionExpr} AS admission_number,
                    s.class_id,
                    c.class_name,
                    b.title AS book_title,
                    CAST(bf.fine_type AS CHAR) AS charge_type,
                    COALESCE(bf.amount, 0) AS amount,
                    bf.days_overdue,
                    bf.notes,
                    bf.status,
                    bf.sent_date,
                    bf.submitted_at,
                    bf.approved_at,
                    bf.approval_notes,
                    bf.invoice_id,
                    bf.created_at AS charge_date
                FROM book_fines bf
                JOIN students s ON bf.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN books b ON bf.book_id = b.id
            ";

            if (!empty($statuses)) {
                $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
                $stmt = $pdo->prepare($sql . " WHERE bf.status IN ($placeholders) ORDER BY bf.created_at DESC");
                $stmt->execute($statuses);
            } else {
                $stmt = $pdo->query($sql . " ORDER BY bf.created_at DESC");
            }
            $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        if ($hasLostBooks) {
            $sql = "
                SELECT
                    lb.id AS charge_id,
                    'lost_book' AS source_type,
                    lb.student_id,
                    s.full_name AS student_name,
                    {$admissionExpr} AS admission_number,
                    s.class_id,
                    c.class_name,
                    COALESCE(lb.book_title, lb.title, b.title, CONCAT('Book #', lb.book_id)) AS book_title,
                    'lost_book' AS charge_type,
                    COALESCE(lb.total_amount, 0) AS amount,
                    NULL AS days_overdue,
                    lb.notes,
                    lb.status,
                    lb.sent_date,
                    lb.submitted_at,
                    lb.approved_at,
                    lb.approval_notes,
                    lb.invoice_id,
                    COALESCE(lb.report_date, lb.loss_date, lb.created_at) AS charge_date
                FROM lost_books lb
                JOIN students s ON lb.student_id = s.id
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN books b ON lb.book_id = b.id
            ";

            if (!empty($statuses)) {
                $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
                $stmt = $pdo->prepare($sql . " WHERE lb.status IN ($placeholders) ORDER BY charge_date DESC");
                $stmt->execute($statuses);
            } else {
                $stmt = $pdo->query($sql . " ORDER BY charge_date DESC");
            }
            $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        usort($rows, function ($a, $b) {
            $aDate = strtotime((string) ($a['approved_at'] ?? $a['submitted_at'] ?? $a['sent_date'] ?? $a['charge_date']));
            $bDate = strtotime((string) ($b['approved_at'] ?? $b['submitted_at'] ?? $b['sent_date'] ?? $b['charge_date']));
            return $bDate <=> $aDate;
        });

        return $rows;
    }
}

if (!function_exists('libraryGroupChargesByStudent')) {
    function libraryGroupChargesByStudent(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $studentId = (int) ($row['student_id'] ?? 0);
            if (!isset($grouped[$studentId])) {
                $grouped[$studentId] = [
                    'student_id' => $studentId,
                    'student_name' => $row['student_name'] ?? 'Student',
                    'admission_number' => $row['admission_number'] ?? '',
                    'class_id' => (int) ($row['class_id'] ?? 0),
                    'class_name' => $row['class_name'] ?? '',
                    'total_amount' => 0.0,
                    'items' => [],
                ];
            }

            $grouped[$studentId]['total_amount'] += (float) ($row['amount'] ?? 0);
            $grouped[$studentId]['items'][] = $row;
        }

        return array_values($grouped);
    }
}

if (!function_exists('libraryGroupChargesByClass')) {
    function libraryGroupChargesByClass(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $classId = (int) ($row['class_id'] ?? 0);
            $classKey = $classId > 0 ? $classId : -1;
            if (!isset($grouped[$classKey])) {
                $grouped[$classKey] = [
                    'class_id' => $classId,
                    'class_name' => $row['class_name'] ?? 'No Class',
                    'student_count' => 0,
                    'total_amount' => 0.0,
                    'student_ids' => [],
                ];
            }

            $studentId = (int) ($row['student_id'] ?? 0);
            if (!in_array($studentId, $grouped[$classKey]['student_ids'], true)) {
                $grouped[$classKey]['student_ids'][] = $studentId;
                $grouped[$classKey]['student_count']++;
            }
            $grouped[$classKey]['total_amount'] += (float) ($row['amount'] ?? 0);
        }

        return array_values($grouped);
    }
}

if (!function_exists('libraryGenerateInvoiceNumber')) {
    function libraryGenerateInvoiceNumber(PDO $pdo): string
    {
        $prefix = 'LIB-' . date('Ym');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE invoice_no LIKE ?");
        $stmt->execute([$prefix . '%']);
        $count = (int) $stmt->fetchColumn() + 1;
        return $prefix . '-' . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('libraryResolveFeeStructureId')) {
    function libraryResolveFeeStructureId(PDO $pdo, ?int $classId = null): int
    {
        $feeStructureColumns = $pdo->query("SHOW COLUMNS FROM fee_structures")->fetchAll(PDO::FETCH_COLUMN);
        $lookup = array_fill_keys($feeStructureColumns, true);

        $classColumn = isset($lookup['class_id']) ? 'class_id' : null;
        $statusColumn = isset($lookup['status']) ? 'status' : null;
        $createdAtColumn = isset($lookup['created_at']) ? 'created_at' : null;

        $conditions = [];
        $params = [];

        if ($statusColumn !== null) {
            $conditions[] = "`{$statusColumn}` IN ('approved', 'active', 'published')";
        }

        if ($classColumn !== null && $classId && $classId > 0) {
            $sql = "SELECT id FROM fee_structures";
            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(' AND ', $conditions) . " AND (`{$classColumn}` = ? OR `{$classColumn}` IS NULL OR `{$classColumn}` = 0)";
            } else {
                $sql .= " WHERE `{$classColumn}` = ? OR `{$classColumn}` IS NULL OR `{$classColumn}` = 0";
            }
            $sql .= $createdAtColumn !== null
                ? " ORDER BY CASE WHEN `{$classColumn}` = ? THEN 0 ELSE 1 END, `{$createdAtColumn}` DESC, id DESC LIMIT 1"
                : " ORDER BY CASE WHEN `{$classColumn}` = ? THEN 0 ELSE 1 END, id DESC LIMIT 1";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$classId, $classId]);
            $resolvedId = (int) $stmt->fetchColumn();
            if ($resolvedId > 0) {
                return $resolvedId;
            }
        }

        $sql = "SELECT id FROM fee_structures";
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        $sql .= $createdAtColumn !== null ? " ORDER BY `{$createdAtColumn}` DESC, id DESC LIMIT 1" : " ORDER BY id DESC LIMIT 1";

        $resolvedId = (int) $pdo->query($sql)->fetchColumn();
        if ($resolvedId > 0) {
            return $resolvedId;
        }

        throw new RuntimeException('No valid fee structure was found for library invoice generation.');
    }
}

if (!function_exists('libraryCreateInvoiceForStudent')) {
    function libraryCreateInvoiceForStudent(PDO $pdo, int $studentId, int $createdBy, ?int $classId = null, ?string $dueDate = null): array
    {
        $studentSchema = libraryStudentSchemaConfig($pdo);
        $admissionExpr = $studentSchema['admission_column']
            ? "s.`{$studentSchema['admission_column']}`"
            : "''";
        $readyStatuses = ['approved', 'sent_to_accountant', 'verified'];
        $charges = array_values(array_filter(
            libraryFetchChargeRows($pdo, $readyStatuses),
            fn($row) => (int) ($row['student_id'] ?? 0) === $studentId && empty($row['invoice_id'])
        ));

        if (empty($charges)) {
            throw new RuntimeException('No approved library charges were found for this student.');
        }

        $studentStmt = $pdo->prepare("
            SELECT s.id, s.full_name, {$admissionExpr} AS admission_number, s.class_id, c.class_name
            FROM students s
            LEFT JOIN classes c ON s.class_id = c.id
            WHERE s.id = ?
            LIMIT 1
        ");
        $studentStmt->execute([$studentId]);
        $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            throw new RuntimeException('Student record not found.');
        }

        $invoiceColumns = $pdo->query("SHOW COLUMNS FROM invoices")->fetchAll(PDO::FETCH_COLUMN);
        $invoiceColumnLookup = array_fill_keys($invoiceColumns, true);
        $invoiceItemColumns = $pdo->query("SHOW COLUMNS FROM invoice_items")->fetchAll(PDO::FETCH_COLUMN);
        $invoiceItemLookup = array_fill_keys($invoiceItemColumns, true);

        $invoiceNo = libraryGenerateInvoiceNumber($pdo);
        $totalAmount = array_sum(array_map(fn($row) => (float) $row['amount'], $charges));
        $dueDate = $dueDate ?: date('Y-m-d', strtotime('+14 days'));
        $classId = $classId ?: (int) ($student['class_id'] ?? 0);
        $feeStructureId = libraryResolveFeeStructureId($pdo, $classId);
        $startedTransaction = !$pdo->inTransaction();

        if ($startedTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $payload = [
                'invoice_no' => $invoiceNo,
                'student_id' => $studentId,
                'class_id' => $classId,
                'fee_structure_id' => $feeStructureId,
                'admission_number' => $student['admission_number'] ?? null,
                'term' => null,
                'total_amount' => $totalAmount,
                'amount_paid' => 0,
                'balance' => $totalAmount,
                'due_date' => $dueDate,
                'issued_date' => date('Y-m-d'),
                'status' => 'issued',
                'invoice_type' => 'library_fine',
                'notes' => 'Library charges invoice generated from approved library fines / lost books.',
                'created_by' => $createdBy,
                'approved_by' => $createdBy,
            ];

            $fields = [];
            $placeholders = [];
            $values = [];
            foreach ($payload as $column => $value) {
                if (isset($invoiceColumnLookup[$column])) {
                    $fields[] = $column;
                    $placeholders[] = '?';
                    $values[] = $value;
                }
            }

            $invoiceStmt = $pdo->prepare("
                INSERT INTO invoices (" . implode(', ', $fields) . ")
                VALUES (" . implode(', ', $placeholders) . ")
            ");
            $invoiceStmt->execute($values);
            $invoiceId = (int) $pdo->lastInsertId();

            foreach ($charges as $charge) {
                $itemPayload = [
                    'invoice_id' => $invoiceId,
                    'item_name' => $charge['source_type'] === 'lost_book' ? 'Lost Book Charge' : 'Library Fine',
                    'description' => ($charge['book_title'] ?? 'Library charge') . ' - ' . ucfirst(str_replace('_', ' ', (string) ($charge['charge_type'] ?? 'charge'))),
                    'quantity' => 1,
                    'unit_price' => (float) ($charge['amount'] ?? 0),
                    'amount' => (float) ($charge['amount'] ?? 0),
                    'item_type' => 'library_fine',
                    'is_mandatory' => 1,
                ];

                $itemFields = [];
                $itemPlaceholders = [];
                $itemValues = [];
                foreach ($itemPayload as $column => $value) {
                    if (isset($invoiceItemLookup[$column])) {
                        $itemFields[] = $column;
                        $itemPlaceholders[] = '?';
                        $itemValues[] = $value;
                    }
                }

                $itemStmt = $pdo->prepare("
                    INSERT INTO invoice_items (" . implode(', ', $itemFields) . ")
                    VALUES (" . implode(', ', $itemPlaceholders) . ")
                ");
                $itemStmt->execute($itemValues);
            }

            $now = date('Y-m-d H:i:s');

            foreach ($charges as $charge) {
                if (($charge['source_type'] ?? '') === 'fine' && libraryChargeTableExists($pdo, 'book_fines')) {
                    $stmt = $pdo->prepare("
                        UPDATE book_fines
                        SET status = 'invoiced',
                            invoice_id = ?,
                            invoice_generated_by = ?,
                            invoice_generated_at = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$invoiceId, $createdBy, $now, (int) $charge['charge_id']]);
                }

                if (($charge['source_type'] ?? '') === 'lost_book' && libraryChargeTableExists($pdo, 'lost_books')) {
                    $stmt = $pdo->prepare("
                        UPDATE lost_books
                        SET status = 'invoiced',
                            invoice_id = ?,
                            invoice_generated_by = ?,
                            invoice_generated_at = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$invoiceId, $createdBy, $now, (int) $charge['charge_id']]);
                }
            }

            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }

            return [
                'invoice_id' => $invoiceId,
                'invoice_no' => $invoiceNo,
                'student_name' => $student['full_name'] ?? 'Student',
                'total_amount' => $totalAmount,
                'charges_count' => count($charges),
            ];
        } catch (Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
