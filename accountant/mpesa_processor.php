<?php
include '../config.php';
require_once '../finance_accounts_helpers.php';
checkAuth();
checkRole(['accountant', 'admin']);
financeEnsureSchema($pdo);

header('Content-Type: application/json');

class AccountantMpesaProcessor {
    private PDO $pdo;
    private array $paymentColumns;
    private array $mpesaColumns;
    private string $consumerKey;
    private string $consumerSecret;
    private string $shortcode;
    private string $passkey;
    private string $callbackUrl;
    private string $environment;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->paymentColumns = $this->columnLookup('payments');
        $this->mpesaColumns = $this->columnLookup('mpesa_transactions');
        $this->consumerKey = trim(getSystemSetting('mpesa_consumer_key', MPESA_CONSUMER_KEY));
        $this->consumerSecret = trim(getSystemSetting('mpesa_consumer_secret', MPESA_CONSUMER_SECRET));
        $this->shortcode = trim(getSystemSetting('mpesa_shortcode', MPESA_SHORTCODE));
        $this->passkey = trim(getSystemSetting('mpesa_passkey', MPESA_PASSKEY));
        $this->callbackUrl = trim(getSystemSetting('mpesa_callback_url', MPESA_CALLBACK_URL));
        $this->environment = trim(getSystemSetting('mpesa_env', MPESA_ENVIRONMENT));
    }

    private function columnLookup(string $table): array {
        try {
            $columns = $this->pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
            return array_fill_keys($columns, true);
        } catch (Exception $e) {
            return [];
        }
    }

    private function hasConfiguredCredentials(): bool {
        $values = [$this->consumerKey, $this->consumerSecret, $this->shortcode, $this->passkey];
        foreach ($values as $value) {
            if ($value === '' || str_starts_with($value, 'YOUR_')) {
                return false;
            }
        }
        return true;
    }

    private function getApiBase(): string {
        return $this->environment === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    private function getAccessToken(): ?string {
        $auth = base64_encode($this->consumerKey . ':' . $this->consumerSecret);
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->getApiBase() . '/oauth/v1/generate?grant_type=client_credentials',
            CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $auth],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $response = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($httpCode !== 200 || !$response) {
            error_log('M-Pesa auth failed: HTTP ' . $httpCode . ' ' . $response);
            return null;
        }

        $data = json_decode($response, true);
        return $data['access_token'] ?? null;
    }

    private function normalizePhone(string $phone): string {
        $phone = preg_replace('/\s+/', '', $phone);
        if (preg_match('/^0\d{9}$/', $phone)) {
            return '254' . substr($phone, 1);
        }
        if (preg_match('/^\+254\d{9}$/', $phone)) {
            return substr($phone, 1);
        }
        return $phone;
    }

    private function findInvoice(int $invoiceId, int $studentId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT i.*, s.full_name, s.Admission_number
            FROM invoices i
            JOIN students s ON i.student_id = s.id
            WHERE i.id = ? AND i.student_id = ?
            LIMIT 1
        ");
        $stmt->execute([$invoiceId, $studentId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function buildAccountReference(array $invoice): string {
        $template = trim(getSystemSetting('mpesa_account_reference', ''));
        if ($template === '') {
            $template = $invoice['Admission_number'] ?: ('INV-' . $invoice['id']);
        }

        $replacements = [
            '{invoice_id}' => (string) $invoice['id'],
            '{invoice_no}' => (string) ($invoice['invoice_no'] ?? $invoice['id']),
            '{student_id}' => (string) $invoice['student_id'],
            '{admission_number}' => (string) ($invoice['Admission_number'] ?? ''),
            '{school_name}' => (string) getSystemSetting('school_name', SCHOOL_NAME),
        ];

        return substr(strtr($template, $replacements), 0, 100);
    }

    private function buildTransactionDescription(array $invoice): string {
        $template = trim(getSystemSetting('mpesa_transaction_desc', ''));
        if ($template === '') {
            return 'School fee payment for invoice #' . ($invoice['invoice_no'] ?? $invoice['id']);
        }

        return substr(strtr($template, [
            '{invoice_id}' => (string) $invoice['id'],
            '{invoice_no}' => (string) ($invoice['invoice_no'] ?? $invoice['id']),
            '{student_name}' => (string) ($invoice['full_name'] ?? ''),
            '{admission_number}' => (string) ($invoice['Admission_number'] ?? ''),
        ]), 0, 180);
    }

    private function pendingDir(): string {
        $dir = dirname(__DIR__) . '\logs\mpesa_pending';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private function savePendingContext(string $checkoutRequestId, array $context): void {
        file_put_contents($this->pendingDir() . DIRECTORY_SEPARATOR . $checkoutRequestId . '.json', json_encode($context, JSON_PRETTY_PRINT));
    }

    private function addIfColumn(array &$columns, array &$values, string $column, $value, array $lookup): void {
        if (isset($lookup[$column])) {
            $columns[] = $column;
            $values[] = $value;
        }
    }

    private function createMpesaAudit(array $context): void {
        if (empty($this->mpesaColumns)) {
            return;
        }

        $columns = [];
        $values = [];
        $this->addIfColumn($columns, $values, 'phone', $context['phone'] ?? null, $this->mpesaColumns);
        $this->addIfColumn($columns, $values, 'amount', $context['amount'] ?? null, $this->mpesaColumns);
        $this->addIfColumn($columns, $values, 'accountref', $context['account_reference'] ?? null, $this->mpesaColumns);
        $this->addIfColumn($columns, $values, 'receipt', $context['receipt'] ?? null, $this->mpesaColumns);
        $this->addIfColumn($columns, $values, 'transaction_time', $context['transaction_time'] ?? date('Y-m-d H:i:s'), $this->mpesaColumns);
        $this->addIfColumn($columns, $values, 'status', $context['status'] ?? 'received', $this->mpesaColumns);
        $this->addIfColumn($columns, $values, 'payment_id', $context['payment_id'] ?? null, $this->mpesaColumns);
        $this->addIfColumn($columns, $values, 'raw_data', json_encode($context), $this->mpesaColumns);

        if (empty($columns)) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = "INSERT INTO mpesa_transactions (" . implode(', ', $columns) . ") VALUES ($placeholders)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    }

    private function findOrCreateMpesaMethod(): int {
        $stmt = $this->pdo->prepare("SELECT id FROM payment_methods WHERE code = 'mpesa' LIMIT 1");
        $stmt->execute();
        $methodId = $stmt->fetchColumn();
        if ($methodId) {
            return (int) $methodId;
        }

        $stmt = $this->pdo->prepare("INSERT INTO payment_methods (code, label) VALUES ('mpesa', 'M-Pesa')");
        $stmt->execute();
        return (int) $this->pdo->lastInsertId();
    }

    private function recordPayment(array $invoice, float $amount, string $reference, string $notes): int {
        $columns = [];
        $values = [];
        $methodId = $this->findOrCreateMpesaMethod();
        $paymentIdUnique = 'MPESA' . date('YmdHis') . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        $this->addIfColumn($columns, $values, 'payment_id', $paymentIdUnique, $this->paymentColumns);
        $this->addIfColumn($columns, $values, 'transaction_id', 'MPESA-' . $reference, $this->paymentColumns);
        $this->addIfColumn($columns, $values, 'invoice_id', $invoice['id'], $this->paymentColumns);
        $this->addIfColumn($columns, $values, 'student_id', $invoice['student_id'], $this->paymentColumns);
        $this->addIfColumn($columns, $values, 'amount', $amount, $this->paymentColumns);
        $this->addIfColumn($columns, $values, 'payment_method_id', $methodId, $this->paymentColumns);
        $this->addIfColumn($columns, $values, 'payment_method', 'mpesa', $this->paymentColumns);
        $this->addIfColumn($columns, $values, 'transaction_ref', $reference, $this->paymentColumns);
        $this->addIfColumn($columns, $values, 'reference_no', $reference, $this->paymentColumns);
        $this->addIfColumn($columns, $values, 'reference', $reference, $this->paymentColumns);
        $this->addIfColumn($columns, $values, 'mpesa_receipt', $reference, $this->paymentColumns);
        $this->addIfColumn($columns, $values, 'status', 'completed', $this->paymentColumns);
        $this->addIfColumn($columns, $values, 'recorded_by', $userId, $this->paymentColumns);
        $this->addIfColumn($columns, $values, 'created_by', $userId, $this->paymentColumns);
        $this->addIfColumn($columns, $values, 'notes', $notes, $this->paymentColumns);
        $this->addIfColumn($columns, $values, 'payment_date', date('Y-m-d'), $this->paymentColumns);
        $this->addIfColumn($columns, $values, 'paid_at', date('Y-m-d H:i:s'), $this->paymentColumns);

        $stmt = $this->pdo->prepare("INSERT INTO payments (" . implode(', ', $columns) . ") VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")");
        $stmt->execute($values);
        $paymentId = (int) $this->pdo->lastInsertId();
        financeRecordStudentCollection(
            $this->pdo,
            $amount,
            'mpesa',
            $reference,
            $paymentId,
            (int) $invoice['student_id'],
            $userId
        );
        return $paymentId;
    }

    private function updateInvoice(array $invoice, float $amount): void {
        $newPaid = (float) $invoice['amount_paid'] + $amount;
        $invoiceTotal = (float) ($invoice['total_amount'] ?? $invoice['amount_due'] ?? 0);
        $newBalance = max(0, $invoiceTotal - $newPaid);
        $newStatus = $newBalance <= 0 ? 'paid' : 'partial';

        $stmt = $this->pdo->prepare("UPDATE invoices SET amount_paid = ?, balance = ?, status = ? WHERE id = ?");
        $stmt->execute([$newPaid, $newBalance, $newStatus, $invoice['id']]);
    }

    public function initiateStk(int $invoiceId, int $studentId, string $phone, float $amount): array {
        $phone = $this->normalizePhone($phone);
        if (!preg_match('/^254\d{9}$/', $phone)) {
            return ['success' => false, 'message' => 'Invalid phone number. Use format 254XXXXXXXXX'];
        }
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Amount must be greater than zero'];
        }
        if (!$this->hasConfiguredCredentials()) {
            return ['success' => false, 'message' => 'M-Pesa is not configured yet. Update the M-Pesa settings first.'];
        }

        $invoice = $this->findInvoice($invoiceId, $studentId);
        if (!$invoice) {
            return ['success' => false, 'message' => 'Invoice not found'];
        }
        if ($amount > (float) $invoice['balance']) {
            return ['success' => false, 'message' => 'Amount exceeds invoice balance'];
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return ['success' => false, 'message' => 'Failed to authenticate with M-Pesa'];
        }

        $timestamp = date('YmdHis');
        $password = base64_encode($this->shortcode . $this->passkey . $timestamp);
        $accountReference = $this->buildAccountReference($invoice);
        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => (int) round($amount),
            'PartyA' => $phone,
            'PartyB' => $this->shortcode,
            'PhoneNumber' => $phone,
            'CallBackURL' => $this->callbackUrl,
            'AccountReference' => $accountReference,
            'TransactionDesc' => $this->buildTransactionDescription($invoice)
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->getApiBase() . '/mpesa/stkpush/v1/processrequest',
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $response = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $data = json_decode((string) $response, true);
        if ($httpCode !== 200 || ($data['ResponseCode'] ?? '') !== '0') {
            $message = $data['errorMessage'] ?? $data['ResponseDescription'] ?? ('STK Push failed. HTTP ' . $httpCode);
            return ['success' => false, 'message' => $message, 'http_code' => $httpCode];
        }

        $checkoutRequestId = (string) ($data['CheckoutRequestID'] ?? '');
        $context = [
            'invoice_id' => $invoiceId,
            'student_id' => $studentId,
            'phone' => $phone,
            'amount' => $amount,
            'account_reference' => $accountReference,
            'checkout_request_id' => $checkoutRequestId,
            'merchant_request_id' => $data['MerchantRequestID'] ?? '',
            'status' => 'received',
            'created_at' => date('c')
        ];
        $this->savePendingContext($checkoutRequestId, $context);
        $this->createMpesaAudit($context);

        return [
            'success' => true,
            'message' => 'STK push sent to ' . $phone . '. The payment will post once the callback arrives.',
            'checkout_request_id' => $checkoutRequestId
        ];
    }

    public function recordPaybill(int $invoiceId, int $studentId, float $amount, string $receiptNumber, string $phone): array {
        $receiptNumber = strtoupper(trim($receiptNumber));
        $phone = $this->normalizePhone($phone);

        if ($receiptNumber === '') {
            return ['success' => false, 'message' => 'M-Pesa receipt number is required'];
        }

        $invoice = $this->findInvoice($invoiceId, $studentId);
        if (!$invoice) {
            return ['success' => false, 'message' => 'Invoice not found'];
        }
        if ($amount <= 0 || $amount > (float) $invoice['balance']) {
            return ['success' => false, 'message' => 'Enter a valid amount within the invoice balance'];
        }

        $searchExpr = [];
        $params = [];
        foreach (['reference_no', 'reference', 'transaction_ref', 'mpesa_receipt'] as $column) {
            if (isset($this->paymentColumns[$column])) {
                $searchExpr[] = "$column = ?";
                $params[] = $receiptNumber;
            }
        }
        if (!empty($searchExpr)) {
            $stmt = $this->pdo->prepare("SELECT id FROM payments WHERE " . implode(' OR ', $searchExpr) . " LIMIT 1");
            $stmt->execute($params);
            if ($stmt->fetchColumn()) {
                return ['success' => false, 'message' => 'That M-Pesa receipt is already recorded'];
            }
        }

        $this->pdo->beginTransaction();
        try {
            $paymentId = $this->recordPayment($invoice, $amount, $receiptNumber, 'Manual M-Pesa paybill payment');
            $this->updateInvoice($invoice, $amount);
            $this->createMpesaAudit([
                'receipt' => $receiptNumber,
                'phone' => $phone,
                'amount' => $amount,
                'account_reference' => $this->buildAccountReference($invoice),
                'payment_id' => $paymentId,
                'status' => 'processed',
                'transaction_time' => date('Y-m-d H:i:s'),
                'created_at' => date('c')
            ]);
            $this->pdo->commit();

            return ['success' => true, 'message' => 'Paybill payment recorded successfully', 'payment_id' => $paymentId];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

$processor = new AccountantMpesaProcessor($pdo);
$action = $_POST['action'] ?? '';

switch ($action) {
    case 'initiate_stk':
        echo json_encode($processor->initiateStk(
            (int) ($_POST['invoice_id'] ?? 0),
            (int) ($_POST['student_id'] ?? 0),
            (string) ($_POST['phone'] ?? ''),
            (float) ($_POST['amount'] ?? 0)
        ));
        break;
    case 'record_paybill':
        echo json_encode($processor->recordPaybill(
            (int) ($_POST['invoice_id'] ?? 0),
            (int) ($_POST['student_id'] ?? 0),
            (float) ($_POST['amount'] ?? 0),
            (string) ($_POST['receipt_number'] ?? ''),
            (string) ($_POST['phone'] ?? '')
        ));
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}
