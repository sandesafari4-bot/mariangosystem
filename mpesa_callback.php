<?php
include 'config.php';
require_once 'finance_accounts_helpers.php';
financeEnsureSchema($pdo);

$rawBody = file_get_contents('php://input');
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/mpesa_callback_' . date('Y-m-d') . '.log';
file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] Callback received\n" . $rawBody . "\n\n", FILE_APPEND);

function columnLookup(PDO $pdo, string $table): array {
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        return array_fill_keys($columns, true);
    } catch (Exception $e) {
        return [];
    }
}

function pendingFilePath(string $checkoutRequestId): string {
    return __DIR__ . '/logs/mpesa_pending/' . $checkoutRequestId . '.json';
}

function loadPendingContext(string $checkoutRequestId): ?array {
    $file = pendingFilePath($checkoutRequestId);
    if (!is_file($file)) {
        return null;
    }

    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function removePendingContext(string $checkoutRequestId): void {
    $file = pendingFilePath($checkoutRequestId);
    if (is_file($file)) {
        unlink($file);
    }
}

function addIfColumn(array &$columns, array &$values, array $lookup, string $column, $value): void {
    if (isset($lookup[$column])) {
        $columns[] = $column;
        $values[] = $value;
    }
}

function createMpesaAudit(PDO $pdo, array $lookup, array $context): void {
    if (empty($lookup)) {
        return;
    }

    $columns = [];
    $values = [];
    addIfColumn($columns, $values, $lookup, 'receipt', $context['receipt'] ?? null);
    addIfColumn($columns, $values, $lookup, 'phone', $context['phone'] ?? null);
    addIfColumn($columns, $values, $lookup, 'amount', $context['amount'] ?? null);
    addIfColumn($columns, $values, $lookup, 'accountref', $context['account_reference'] ?? null);
    addIfColumn($columns, $values, $lookup, 'transaction_time', $context['transaction_time'] ?? date('Y-m-d H:i:s'));
    addIfColumn($columns, $values, $lookup, 'status', $context['status'] ?? 'received');
    addIfColumn($columns, $values, $lookup, 'payment_id', $context['payment_id'] ?? null);
    addIfColumn($columns, $values, $lookup, 'raw_data', json_encode($context));

    if (empty($columns)) {
        return;
    }

    $sql = "INSERT INTO mpesa_transactions (" . implode(', ', $columns) . ") VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
}

function findOrCreateMpesaMethod(PDO $pdo): int {
    $stmt = $pdo->prepare("SELECT id FROM payment_methods WHERE code = 'mpesa' LIMIT 1");
    $stmt->execute();
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }

    $stmt = $pdo->prepare("INSERT INTO payment_methods (code, label) VALUES ('mpesa', 'M-Pesa')");
    $stmt->execute();
    return (int) $pdo->lastInsertId();
}

function receiptExists(PDO $pdo, array $paymentColumns, string $receipt): bool {
    $clauses = [];
    $params = [];
    foreach (['reference_no', 'reference', 'transaction_ref', 'mpesa_receipt'] as $column) {
        if (isset($paymentColumns[$column])) {
            $clauses[] = "$column = ?";
            $params[] = $receipt;
        }
    }
    if (empty($clauses)) {
        return false;
    }

    $stmt = $pdo->prepare("SELECT id FROM payments WHERE " . implode(' OR ', $clauses) . " LIMIT 1");
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

function recordPayment(PDO $pdo, array $paymentColumns, array $invoice, float $amount, string $receipt): int {
    $columns = [];
    $values = [];
    $methodId = findOrCreateMpesaMethod($pdo);
    $paymentIdUnique = 'MPESA' . date('YmdHis') . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

    addIfColumn($columns, $values, $paymentColumns, 'payment_id', $paymentIdUnique);
    addIfColumn($columns, $values, $paymentColumns, 'transaction_id', 'MPESA-' . $receipt);
    addIfColumn($columns, $values, $paymentColumns, 'invoice_id', $invoice['id']);
    addIfColumn($columns, $values, $paymentColumns, 'student_id', $invoice['student_id']);
    addIfColumn($columns, $values, $paymentColumns, 'amount', $amount);
    addIfColumn($columns, $values, $paymentColumns, 'payment_method_id', $methodId);
    addIfColumn($columns, $values, $paymentColumns, 'payment_method', 'mpesa');
    addIfColumn($columns, $values, $paymentColumns, 'transaction_ref', $receipt);
    addIfColumn($columns, $values, $paymentColumns, 'reference_no', $receipt);
    addIfColumn($columns, $values, $paymentColumns, 'reference', $receipt);
    addIfColumn($columns, $values, $paymentColumns, 'mpesa_receipt', $receipt);
    addIfColumn($columns, $values, $paymentColumns, 'status', 'completed');
    addIfColumn($columns, $values, $paymentColumns, 'recorded_by', 0);
    addIfColumn($columns, $values, $paymentColumns, 'created_by', 0);
    addIfColumn($columns, $values, $paymentColumns, 'notes', 'M-Pesa STK callback payment');
    addIfColumn($columns, $values, $paymentColumns, 'payment_date', date('Y-m-d'));
    addIfColumn($columns, $values, $paymentColumns, 'paid_at', date('Y-m-d H:i:s'));

    $stmt = $pdo->prepare("INSERT INTO payments (" . implode(', ', $columns) . ") VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")");
    $stmt->execute($values);
    $paymentId = (int) $pdo->lastInsertId();
    financeRecordStudentCollection(
        $pdo,
        $amount,
        'mpesa',
        $receipt,
        $paymentId,
        (int) $invoice['student_id'],
        0
    );
    return $paymentId;
}

function updateInvoice(PDO $pdo, array $invoice, float $amount): void {
    $newPaid = (float) $invoice['amount_paid'] + $amount;
    $invoiceTotal = (float) ($invoice['total_amount'] ?? $invoice['amount_due'] ?? 0);
    $newBalance = max(0, $invoiceTotal - $newPaid);
    $newStatus = $newBalance <= 0 ? 'paid' : 'partial';

    $stmt = $pdo->prepare("UPDATE invoices SET amount_paid = ?, balance = ?, status = ? WHERE id = ?");
    $stmt->execute([$newPaid, $newBalance, $newStatus, $invoice['id']]);
}

$callback = json_decode($rawBody, true);
if (!is_array($callback)) {
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'message' => 'Invalid payload']);
    exit;
}

$stkCallback = $callback['Body']['stkCallback'] ?? [];
$checkoutRequestId = (string) ($stkCallback['CheckoutRequestID'] ?? '');
$resultCode = (string) ($stkCallback['ResultCode'] ?? '-1');
$resultDesc = (string) ($stkCallback['ResultDesc'] ?? 'Unknown response');

$pending = $checkoutRequestId !== '' ? loadPendingContext($checkoutRequestId) : null;
$paymentColumns = columnLookup($pdo, 'payments');
$mpesaColumns = columnLookup($pdo, 'mpesa_transactions');

try {
    if ($resultCode === '0') {
        $receipt = '';
        $amount = 0.0;
        $phone = '';
        $transactionTime = date('Y-m-d H:i:s');

        foreach (($stkCallback['CallbackMetadata']['Item'] ?? []) as $item) {
            $name = $item['Name'] ?? '';
            $value = $item['Value'] ?? null;
            if ($name === 'MpesaReceiptNumber') {
                $receipt = (string) $value;
            } elseif ($name === 'Amount') {
                $amount = (float) $value;
            } elseif ($name === 'PhoneNumber') {
                $phone = (string) $value;
            } elseif ($name === 'TransactionDate' && $value) {
                $parsed = DateTime::createFromFormat('YmdHis', (string) $value);
                if ($parsed) {
                    $transactionTime = $parsed->format('Y-m-d H:i:s');
                }
            }
        }

        if ($pending && !empty($pending['invoice_id']) && !empty($pending['student_id']) && $receipt !== '') {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND student_id = ? LIMIT 1");
            $stmt->execute([(int) $pending['invoice_id'], (int) $pending['student_id']]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($invoice && !receiptExists($pdo, $paymentColumns, $receipt)) {
                $paymentId = recordPayment($pdo, $paymentColumns, $invoice, $amount, $receipt);
                updateInvoice($pdo, $invoice, $amount);
                createMpesaAudit($pdo, $mpesaColumns, [
                    'receipt' => $receipt,
                    'phone' => $phone ?: ($pending['phone'] ?? ''),
                    'amount' => $amount,
                    'account_reference' => $pending['account_reference'] ?? '',
                    'transaction_time' => $transactionTime,
                    'payment_id' => $paymentId,
                    'status' => 'processed',
                    'checkout_request_id' => $checkoutRequestId,
                    'raw_callback' => $callback
                ]);
            } else {
                createMpesaAudit($pdo, $mpesaColumns, [
                    'receipt' => $receipt,
                    'phone' => $phone ?: ($pending['phone'] ?? ''),
                    'amount' => $amount,
                    'account_reference' => $pending['account_reference'] ?? '',
                    'transaction_time' => $transactionTime,
                    'status' => 'matched',
                    'checkout_request_id' => $checkoutRequestId,
                    'raw_callback' => $callback
                ]);
            }

            $pdo->commit();
            removePendingContext($checkoutRequestId);
        } else {
            createMpesaAudit($pdo, $mpesaColumns, [
                'receipt' => $receipt,
                'phone' => $phone,
                'amount' => $amount,
                'transaction_time' => $transactionTime,
                'status' => 'matched',
                'checkout_request_id' => $checkoutRequestId,
                'raw_callback' => $callback
            ]);
        }
    } else {
        createMpesaAudit($pdo, $mpesaColumns, [
            'phone' => $pending['phone'] ?? '',
            'amount' => $pending['amount'] ?? 0,
            'account_reference' => $pending['account_reference'] ?? '',
            'status' => 'failed',
            'checkout_request_id' => $checkoutRequestId,
            'result_code' => $resultCode,
            'result_desc' => $resultDesc,
            'raw_callback' => $callback
        ]);
        removePendingContext($checkoutRequestId);
    }

    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] Processed checkout {$checkoutRequestId} with result {$resultCode}\n\n", FILE_APPEND);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . "\n\n", FILE_APPEND);
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
