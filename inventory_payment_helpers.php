<?php

if (!function_exists('ensureInventoryItemColumn')) {
    function ensureInventoryItemColumn(PDO $pdo, string $column, string $definition): void
    {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM inventory_items LIKE ?");
        $stmt->execute([$column]);
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE inventory_items ADD COLUMN `$column` $definition");
        }
    }
}

if (!function_exists('ensureInventoryPaymentWorkflow')) {
    function ensureInventoryPaymentWorkflow(PDO $pdo): void
    {
        $columns = [
            'approval_status' => "VARCHAR(30) NOT NULL DEFAULT 'pending'",
            'payment_status' => "VARCHAR(30) NOT NULL DEFAULT 'pending'",
            'payment_method' => "VARCHAR(50) NULL",
            'payment_reference' => "VARCHAR(150) NULL",
            'payment_notes' => "TEXT NULL",
            'paid_by' => "INT NULL",
            'paid_at' => "DATETIME NULL",
            'requested_payment_method' => "VARCHAR(50) NULL",
            'requested_payment_amount' => "DECIMAL(10,2) NULL",
            'payee_name' => "VARCHAR(255) NULL",
            'payee_phone' => "VARCHAR(30) NULL",
            'bank_name' => "VARCHAR(150) NULL",
            'bank_account_name' => "VARCHAR(255) NULL",
            'bank_account_number' => "VARCHAR(120) NULL",
            'bank_branch' => "VARCHAR(150) NULL",
            'mpesa_number' => "VARCHAR(30) NULL",
            'payment_narration' => "TEXT NULL",
            'payment_gateway_status' => "VARCHAR(50) NULL",
            'external_transaction_id' => "VARCHAR(120) NULL",
            'gateway_response' => "LONGTEXT NULL",
            'paid_from_account_id' => "INT NULL"
        ];

        foreach ($columns as $column => $definition) {
            ensureInventoryItemColumn($pdo, $column, $definition);
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS inventory_payment_transactions (
                id INT PRIMARY KEY AUTO_INCREMENT,
                inventory_item_id INT NOT NULL,
                payment_method VARCHAR(50) NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                payee_name VARCHAR(255) NULL,
                destination_reference VARCHAR(150) NULL,
                external_transaction_id VARCHAR(150) NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'pending',
                request_payload LONGTEXT NULL,
                response_payload LONGTEXT NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                processed_at DATETIME NULL,
                KEY inventory_item_id (inventory_item_id),
                KEY payment_method (payment_method),
                KEY status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

if (!function_exists('inventoryPaymentAmount')) {
    function inventoryPaymentAmount(array $item): float
    {
        $requested = isset($item['requested_payment_amount']) ? (float) $item['requested_payment_amount'] : 0.0;
        if ($requested > 0) {
            return $requested;
        }

        return (float) ($item['unit_price'] ?? 0) * (int) ($item['quantity_in_stock'] ?? 0);
    }
}

if (!function_exists('inventoryPaymentMethodLabel')) {
    function inventoryPaymentMethodLabel(?string $method): string
    {
        $labels = [
            'bank_transfer' => 'Bank Transfer',
            'mpesa' => 'M-Pesa',
            'cash' => 'Cash',
            'cheque' => 'Cheque',
            'manual' => 'Manual'
        ];

        return $labels[$method ?? ''] ?? ucfirst(str_replace('_', ' ', (string) $method));
    }
}

if (!class_exists('InventoryPaymentGateway')) {
    class InventoryPaymentGateway
    {
        private PDO $pdo;
        private int $userId;

        public function __construct(PDO $pdo, int $userId)
        {
            $this->pdo = $pdo;
            $this->userId = $userId;
        }

        public function process(int $itemId, string $method, array $payload = []): array
        {
            $stmt = $this->pdo->prepare("SELECT * FROM inventory_items WHERE id = ? LIMIT 1");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                return ['success' => false, 'message' => 'Inventory item not found.'];
            }

            if (($item['approval_status'] ?? '') !== 'approved') {
                return ['success' => false, 'message' => 'Only approved inventory items can be paid.'];
            }

            $amount = isset($payload['amount']) && (float) $payload['amount'] > 0
                ? (float) $payload['amount']
                : inventoryPaymentAmount($item);

            if ($amount <= 0) {
                return ['success' => false, 'message' => 'Payment amount must be greater than zero.'];
            }

            $method = $method !== '' ? $method : (string) ($item['requested_payment_method'] ?? 'manual');
            $payeeName = trim((string) ($payload['payee_name'] ?? $item['payee_name'] ?? ''));
            $reference = trim((string) ($payload['payment_reference'] ?? ''));
            $notes = trim((string) ($payload['payment_notes'] ?? $item['payment_narration'] ?? ''));

            if ($method === 'bank_transfer') {
                return $this->processBankTransfer($item, $amount, $payeeName, $reference, $notes, $payload);
            }

            if ($method === 'mpesa') {
                return $this->processMpesa($item, $amount, $payeeName, $reference, $notes, $payload);
            }

            return $this->recordManualPayment($item, $amount, $payeeName, $reference, $notes, $method);
        }

        private function processBankTransfer(array $item, float $amount, string $payeeName, string $reference, string $notes, array $payload): array
        {
            $endpoint = trim((string) getSystemSetting('bank_api_endpoint', defined('BANK_API_ENDPOINT') ? BANK_API_ENDPOINT : ''));
            $apiKey = trim((string) getSystemSetting('bank_api_key', defined('BANK_API_KEY') ? BANK_API_KEY : ''));
            $apiSecret = trim((string) getSystemSetting('bank_api_secret', defined('BANK_API_SECRET') ? BANK_API_SECRET : ''));
            $mode = trim((string) getSystemSetting('bank_api_mode', defined('BANK_API_MODE') ? BANK_API_MODE : 'sandbox'));

            $requestPayload = [
                'inventory_item_id' => (int) $item['id'],
                'item_code' => $item['item_code'] ?? null,
                'amount' => $amount,
                'currency' => 'KES',
                'payee_name' => $payeeName,
                'bank_name' => $payload['bank_name'] ?? $item['bank_name'] ?? '',
                'bank_account_name' => $payload['bank_account_name'] ?? $item['bank_account_name'] ?? '',
                'bank_account_number' => $payload['bank_account_number'] ?? $item['bank_account_number'] ?? '',
                'bank_branch' => $payload['bank_branch'] ?? $item['bank_branch'] ?? '',
                'narration' => $notes
            ];

            if ($mode !== 'live' || $endpoint === '' || str_contains($endpoint, 'YOUR_')) {
                $externalId = 'BANKSIM-' . date('YmdHis') . '-' . random_int(1000, 9999);
                return $this->finalizePayment($item, 'bank_transfer', $amount, $payeeName, $reference !== '' ? $reference : $externalId, $notes, 'success', $externalId, $requestPayload, [
                    'mode' => 'sandbox',
                    'message' => 'Simulated bank transfer payment completed.'
                ]);
            }

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-API-KEY: ' . $apiKey,
                    'X-API-SECRET: ' . $apiSecret
                ],
                CURLOPT_POSTFIELDS => json_encode($requestPayload),
                CURLOPT_TIMEOUT => 60
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false || $curlError !== '') {
                return $this->logOnlyFailure($item, 'bank_transfer', $amount, $payeeName, $requestPayload, ['error' => $curlError], 'Bank API request failed.');
            }

            $decoded = json_decode($response, true);
            $success = $httpCode >= 200 && $httpCode < 300 && !empty($decoded);
            $externalId = $decoded['transaction_id'] ?? $decoded['reference'] ?? ('BANK-' . date('YmdHis'));

            if ($success) {
                return $this->finalizePayment($item, 'bank_transfer', $amount, $payeeName, $reference !== '' ? $reference : $externalId, $notes, 'success', $externalId, $requestPayload, $decoded);
            }

            return $this->logOnlyFailure($item, 'bank_transfer', $amount, $payeeName, $requestPayload, $decoded ?: ['raw' => $response], 'Bank API returned an error response.');
        }

        private function processMpesa(array $item, float $amount, string $payeeName, string $reference, string $notes, array $payload): array
        {
            $mode = trim((string) getSystemSetting('inventory_mpesa_payout_mode', defined('INVENTORY_MPESA_PAYOUT_MODE') ? INVENTORY_MPESA_PAYOUT_MODE : 'sandbox'));
            $phone = trim((string) ($payload['mpesa_number'] ?? $item['mpesa_number'] ?? $item['payee_phone'] ?? ''));

            if ($phone === '') {
                return ['success' => false, 'message' => 'M-Pesa number is required for M-Pesa payments.'];
            }

            $requestPayload = [
                'inventory_item_id' => (int) $item['id'],
                'item_code' => $item['item_code'] ?? null,
                'amount' => $amount,
                'phone' => $phone,
                'payee_name' => $payeeName,
                'narration' => $notes
            ];

            if ($mode !== 'live') {
                $externalId = 'MPESASIM-' . date('YmdHis') . '-' . random_int(1000, 9999);
                return $this->finalizePayment($item, 'mpesa', $amount, $payeeName, $reference !== '' ? $reference : $externalId, $notes, 'success', $externalId, $requestPayload, [
                    'mode' => 'sandbox',
                    'message' => 'Simulated M-Pesa supplier payment completed.'
                ]);
            }

            return $this->logOnlyFailure($item, 'mpesa', $amount, $payeeName, $requestPayload, [
                'message' => 'Live M-Pesa supplier payout is not configured yet.'
            ], 'Live M-Pesa payout credentials are not configured yet.');
        }

        private function recordManualPayment(array $item, float $amount, string $payeeName, string $reference, string $notes, string $method): array
        {
            $reference = $reference !== '' ? $reference : strtoupper($method) . '-' . date('YmdHis');

            return $this->finalizePayment(
                $item,
                $method !== '' ? $method : 'manual',
                $amount,
                $payeeName,
                $reference,
                $notes,
                'manual',
                $reference,
                ['mode' => 'manual'],
                ['message' => 'Manual payment recorded.']
            );
        }

        private function finalizePayment(array $item, string $method, float $amount, string $payeeName, string $reference, string $notes, string $gatewayStatus, string $externalId, array $requestPayload, array $responsePayload): array
        {
            try {
                $this->pdo->beginTransaction();

                $fundAccountId = $this->resolveFundAccountId($method, $payload = $requestPayload);
                if ($fundAccountId === null) {
                    throw new RuntimeException('No active School Funds account is available for this inventory payment.');
                }

                $ledgerReference = $this->debitSchoolFunds($item, $method, $amount, $payeeName, $reference, $notes, $fundAccountId);

                $stmt = $this->pdo->prepare("
                    UPDATE inventory_items
                    SET payment_status = 'paid',
                        payment_method = ?,
                        payment_reference = ?,
                        payment_notes = ?,
                        paid_by = ?,
                        paid_from_account_id = ?,
                        paid_at = NOW(),
                        payment_gateway_status = ?,
                        external_transaction_id = ?,
                        gateway_response = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $method,
                    $ledgerReference,
                    $notes !== '' ? $notes : null,
                    $this->userId,
                    $fundAccountId,
                    $gatewayStatus,
                    $externalId,
                    json_encode($responsePayload),
                    $item['id']
                ]);

                $this->logTransaction((int) $item['id'], $method, $amount, $payeeName, $ledgerReference, $externalId, 'success', $requestPayload, $responsePayload);
                $this->pdo->commit();

                return [
                    'success' => true,
                    'message' => 'Payment processed successfully.',
                    'reference' => $ledgerReference,
                    'external_transaction_id' => $externalId
                ];
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                return ['success' => false, 'message' => 'Payment processing error: ' . $e->getMessage()];
            }
        }

        private function logOnlyFailure(array $item, string $method, float $amount, string $payeeName, array $requestPayload, array $responsePayload, string $message): array
        {
            $this->logTransaction((int) $item['id'], $method, $amount, $payeeName, null, null, 'failed', $requestPayload, $responsePayload);
            return ['success' => false, 'message' => $message];
        }

        private function logTransaction(int $itemId, string $method, float $amount, string $payeeName, ?string $destinationReference, ?string $externalId, string $status, array $requestPayload, array $responsePayload): void
        {
            $stmt = $this->pdo->prepare("
                INSERT INTO inventory_payment_transactions (
                    inventory_item_id, payment_method, amount, payee_name, destination_reference,
                    external_transaction_id, status, request_payload, response_payload, created_by, processed_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $itemId,
                $method,
                $amount,
                $payeeName !== '' ? $payeeName : null,
                $destinationReference,
                $externalId,
                $status,
                json_encode($requestPayload),
                json_encode($responsePayload),
                $this->userId
            ]);
        }

        private function resolveFundAccountId(string $method, array $payload): ?int
        {
            if (!function_exists('financeResolveDisbursementAccount')) {
                return null;
            }

            $preferredAccountId = isset($payload['fund_account_id']) ? (int) $payload['fund_account_id'] : 0;
            $account = financeResolveDisbursementAccount($this->pdo, $method, $preferredAccountId > 0 ? $preferredAccountId : null);
            return $account ? (int) $account['id'] : null;
        }

        private function debitSchoolFunds(array $item, string $method, float $amount, string $payeeName, string $reference, string $notes, int $accountId): string
        {
            if (!function_exists('financeRecordAccountTransaction')) {
                return $reference;
            }

            return financeRecordAccountTransaction($this->pdo, [
                'account_id' => $accountId,
                'transaction_type' => 'inventory_payment',
                'direction' => 'debit',
                'amount' => $amount,
                'reference_no' => $reference !== '' ? $reference : financeGenerateReference('INV'),
                'counterparty_name' => $payeeName !== '' ? $payeeName : ($item['payee_name'] ?? $item['item_name'] ?? 'Inventory supplier'),
                'description' => 'Inventory payment for ' . ($item['item_name'] ?? 'inventory item') . (!empty($notes) ? ' - ' . $notes : ''),
                'related_type' => 'inventory_payment',
                'related_id' => (int) $item['id'],
                'created_by' => $this->userId,
            ]);
        }
    }
}
