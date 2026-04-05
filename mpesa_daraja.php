<?php
/**
 * M-Pesa Daraja API Integration
 * Handles STK Push, payment confirmation, and balance queries
 */

include 'config.php';

class MPesaDaraja {
    private $consumerKey;
    private $consumerSecret;
    private $shortCode;
    private $passKey;
    private $environment;
    private $businessUrl;
    private $callbackUrl;
    private $accessToken;
    private $pdo;
    
    public function __construct($pdo = null) {
        $this->pdo = $pdo ?? $GLOBALS['pdo'];
        
        // Get M-Pesa settings from database or config
        // Note: Database keys use 'mpesa_env' not 'mpesa_environment'
        $this->environment = getSystemSetting('mpesa_env', MPESA_ENVIRONMENT);
        $this->consumerKey = getSystemSetting('mpesa_consumer_key', MPESA_CONSUMER_KEY);
        $this->consumerSecret = getSystemSetting('mpesa_consumer_secret', MPESA_CONSUMER_SECRET);
        $this->shortCode = getSystemSetting('mpesa_shortcode', MPESA_SHORTCODE);
        $this->passKey = getSystemSetting('mpesa_passkey', MPESA_PASSKEY);
        $this->callbackUrl = getSystemSetting('mpesa_callback_url', MPESA_CALLBACK_URL);
    }
    
    /**
     * Get M-Pesa environment URLs
     */
    private function getEnvironmentUrls() {
        if ($this->environment === 'production') {
            return [
                'auth' => 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
                'stkpush' => 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
                'balance' => 'https://api.safaricom.co.ke/mpesa/accountbalance/v1/query',
                'transaction' => 'https://api.safaricom.co.ke/mpesa/transactionstatus/v1/query'
            ];
        } else {
            return [
                'auth' => 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
                'stkpush' => 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
                'balance' => 'https://sandbox.safaricom.co.ke/mpesa/accountbalance/v1/query',
                'transaction' => 'https://sandbox.safaricom.co.ke/mpesa/transactionstatus/v1/query'
            ];
        }
    }
    
    /**
     * Generate Access Token
     */
    private function generateAccessToken() {
        try {
            $urls = $this->getEnvironmentUrls();
            $curl = curl_init();
            
            curl_setopt_array($curl, [
                CURLOPT_URL => $urls['auth'],
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => $this->consumerKey . ':' . $this->consumerSecret,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            
            if ($httpCode == 200) {
                $result = json_decode($response);
                return $result->access_token;
            } else {
                error_log("M-Pesa Token Error: HTTP $httpCode - $response");
                return null;
            }
        } catch (Exception $e) {
            error_log("M-Pesa Token Exception: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Generate timestamp and signature
     */
    private function generateSignature() {
        $timestamp = date('YmdHis');
        $password = base64_encode($this->shortCode . $this->passKey . $timestamp);
        return [
            'timestamp' => $timestamp,
            'password' => $password
        ];
    }
    
    /**
     * Initiate STK Push for student payment
     * @param string $phone Phone number (format: 254XXXXXXXXX)
     * @param float $amount Amount to pay
     * @param int $invoiceId Invoice ID
     * @param int $studentId Student ID
     * @return array [success => bool, message => string, checkoutRequestId => string]
     */
    public function initiateSTKPush($phone, $amount, $invoiceId, $studentId) {
        try {
            // Validate phone number
            $phone = preg_replace('/^0/', '254', $phone);
            if (!preg_match('/^254\d{9}$/', $phone)) {
                return [
                    'success' => false,
                    'message' => 'Invalid phone number format. Use format: 0712345678'
                ];
            }
            
            // Validate amount
            if ($amount < 1 || $amount > 150000) {
                return [
                    'success' => false,
                    'message' => 'Amount must be between KES 1 and KES 150,000'
                ];
            }
            
            // Get access token
            $accessToken = $this->generateAccessToken();
            if (!$accessToken) {
                error_log("Failed to get M-Pesa access token");
                return [
                    'success' => false,
                    'message' => 'Failed to connect to M-Pesa. Please try again.'
                ];
            }
            
            // Generate signature
            $signature = $this->generateSignature();
            
            // Prepare request
            $urls = $this->getEnvironmentUrls();
            $payload = [
                'BusinessShortCode' => $this->shortCode,
                'Password' => $signature['password'],
                'Timestamp' => $signature['timestamp'],
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => (int)ceil($amount),
                'PartyA' => $phone,
                'PartyB' => $this->shortCode,
                'PhoneNumber' => $phone,
                'CallBackURL' => $this->callbackUrl,
                'AccountReference' => 'INV-' . $invoiceId,
                'TransactionDesc' => 'School Fee Payment - Invoice #' . $invoiceId
            ];
            
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $urls['stkpush'],
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json'
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            
            $result = json_decode($response, true);
            
            if ($httpCode == 200 && isset($result['CheckoutRequestID'])) {
                // Store M-Pesa transaction in database
                $stmt = $this->pdo->prepare("
                    INSERT INTO mpesa_transactions (
                        invoice_id, student_id, phone, amount, checkout_request_id, 
                        merchant_request_id, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, 'initiated', NOW())
                ");
                $stmt->execute([
                    $invoiceId,
                    $studentId,
                    $phone,
                    $amount,
                    $result['CheckoutRequestID'],
                    $result['MerchantRequestID']
                ]);
                
                return [
                    'success' => true,
                    'message' => 'STK push sent successfully. Check your phone for the M-Pesa prompt.',
                    'checkoutRequestId' => $result['CheckoutRequestID'],
                    'merchantRequestId' => $result['MerchantRequestID']
                ];
            } else {
                $errorMsg = $result['errorMessage'] ?? 'Unknown error from M-Pesa';
                error_log("M-Pesa STK Push Error: HTTP $httpCode - " . json_encode($result));
                
                return [
                    'success' => false,
                    'message' => 'M-Pesa error: ' . $errorMsg
                ];
            }
        } catch (Exception $e) {
            error_log("M-Pesa STK Push Exception: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Query transaction status
     * @param string $checkoutRequestId Checkout request ID from STK push
     * @return array Transaction details
     */
    public function queryTransactionStatus($checkoutRequestId) {
        try {
            $accessToken = $this->generateAccessToken();
            if (!$accessToken) {
                return ['success' => false, 'message' => 'Failed to connect to M-Pesa'];
            }
            
            $signature = $this->generateSignature();
            $urls = $this->getEnvironmentUrls();
            
            $payload = [
                'BusinessShortCode' => $this->shortCode,
                'Password' => $signature['password'],
                'Timestamp' => $signature['timestamp'],
                'CheckoutRequestID' => $checkoutRequestId
            ];
            
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $urls['stkpush'] . '/query',
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json'
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            
            $response = curl_exec($curl);
            curl_close($curl);
            
            return json_decode($response, true);
        } catch (Exception $e) {
            error_log("Query Transaction Exception: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Handle M-Pesa callback from Daraja API
     */
    public function handleCallback($callbackData) {
        try {
            $this->pdo->beginTransaction();
            
            $Body = $callbackData['Body']['stkCallback'];
            $checkoutRequestId = $Body['CheckoutRequestID'];
            $resultCode = $Body['ResultCode'];
            $resultDesc = $Body['ResultDesc'];
            
            // Get transaction record
            $stmt = $this->pdo->prepare("
                SELECT * FROM mpesa_transactions 
                WHERE checkout_request_id = ? 
                LIMIT 1
            ");
            $stmt->execute([$checkoutRequestId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$transaction) {
                error_log("M-Pesa callback: Transaction not found - $checkoutRequestId");
                return false;
            }
            
            if ($resultCode == 0) {
                // Success
                $callbackMetadata = $Body['CallbackMetadata']['Item'];
                $mpesaData = [];
                
                foreach ($callbackMetadata as $item) {
                    $mpesaData[$item['Name']] = $item['Value'];
                }
                
                $receiptNumber = $mpesaData['MpesaReceiptNumber'] ?? '';
                $transactionDate = $mpesaData['TransactionDate'] ?? date('YmdHis');
                $phoneNumber = $mpesaData['PhoneNumber'] ?? '';
                $amount = $mpesaData['Amount'] ?? $transaction['amount'];
                
                // Update transaction status
                $stmt = $this->pdo->prepare("
                    UPDATE mpesa_transactions SET 
                        status = 'completed',
                        receipt_number = ?,
                        transaction_date = ?,
                        raw_response = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $receiptNumber,
                    $transactionDate,
                    json_encode($Body),
                    $transaction['id']
                ]);
                
                // Record payment in payments table
                $stmt = $this->pdo->prepare("
                    INSERT INTO payments (
                        invoice_id, student_id, payment_method_id, amount, 
                        reference_no, payment_date, notes, recorded_by, created_by, status, transaction_id
                    ) SELECT ?, ?, id, ?, ?, NOW(), ?, ?, ?, 'completed', ?
                    FROM payment_methods 
                    WHERE code = 'mpesa'
                    LIMIT 1
                ");
                
                $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
                
                $stmt->execute([
                    $transaction['invoice_id'],
                    $transaction['student_id'],
                    $amount,
                    $receiptNumber,
                    "M-Pesa Payment - Receipt: $receiptNumber",
                    $user_id,
                    $user_id,
                    'MPESA-' . $receiptNumber
                ]);
                
                // Update invoice
                $stmt = $this->pdo->prepare("
                    SELECT i.*, 
                           (i.total_amount - (i.amount_paid + ?)) as new_balance
                    FROM invoices i 
                    WHERE i.id = ?
                ");
                $stmt->execute([$amount, $transaction['invoice_id']]);
                $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $newStatus = ($invoice['new_balance'] <= 0) ? 'paid' : 'partial';
                
                $stmt = $this->pdo->prepare("
                    UPDATE invoices SET 
                        amount_paid = amount_paid + ?,
                        balance = GREATEST(total_amount - (amount_paid + ?), 0),
                        status = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $amount,
                    $amount,
                    $newStatus,
                    $transaction['invoice_id']
                ]);
                
                $this->pdo->commit();
                
                // Send confirmation SMS/Email
                $this->sendPaymentConfirmation($transaction, $receiptNumber, $amount);
                
                return true;
            } else {
                // Failed
                $stmt = $this->pdo->prepare("
                    UPDATE mpesa_transactions SET 
                        status = 'failed',
                        raw_response = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    json_encode($Body),
                    $transaction['id']
                ]);
                
                $this->pdo->commit();
                return false;
            }
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("M-Pesa callback handler exception: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send payment confirmation to student/parent
     */
    private function sendPaymentConfirmation($transaction, $receiptNumber, $amount) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT s.full_name, s.parent_phone, i.invoice_no
                FROM mpesa_transactions mt
                JOIN students s ON mt.student_id = s.id
                JOIN invoices i ON mt.invoice_id = i.id
                WHERE mt.id = ?
            ");
            $stmt->execute([$transaction['id']]);
            $info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $message = "Asante! Payment of KES " . number_format($amount, 2) . 
                      " received for invoice #{$info['invoice_no']}. Receipt: {$receiptNumber}. " .
                      "Mariango School.";
            
            // Send SMS to parent (if SMS gateway configured)
            // sendSMS($info['parent_phone'], $message);
            
        } catch (Exception $e) {
            error_log("Error sending confirmation: " . $e->getMessage());
        }
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $mpesa = new MPesaDaraja($pdo);
    
    if ($action === 'initiate_stk') {
        $phone = $_POST['phone'] ?? '';
        $amount = floatval($_POST['amount'] ?? 0);
        $invoiceId = intval($_POST['invoice_id'] ?? 0);
        $studentId = intval($_POST['student_id'] ?? 0);
        
        $result = $mpesa->initiateSTKPush($phone, $amount, $invoiceId, $studentId);
        echo json_encode($result);
        exit();
    }
    
    if ($action === 'query_status') {
        $checkoutRequestId = $_POST['checkout_request_id'] ?? '';
        $result = $mpesa->queryTransactionStatus($checkoutRequestId);
        echo json_encode($result);
        exit();
    }
}
?>
