<?php
/**
 * M-Pesa Payment Verification & Detection
 * Allows students/accountants to verify payments made via M-Pesa sandbox
 * Without needing to push STK first
 */

include 'config.php';

class MPesaPaymentVerifier {
    private $pdo;
    private $consumerKey;
    private $consumerSecret;
    private $shortcode;
    private $passkey;
    private $callbackUrl;
    private $environment;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        
        // Get M-Pesa settings from database or config
        $this->consumerKey = trim(getSystemSetting('mpesa_consumer_key') ?: MPESA_CONSUMER_KEY);
        $this->consumerSecret = trim(getSystemSetting('mpesa_consumer_secret') ?: MPESA_CONSUMER_SECRET);
        $this->shortcode = trim(getSystemSetting('mpesa_shortcode') ?: MPESA_SHORTCODE);
        $this->passkey = trim(getSystemSetting('mpesa_passkey') ?: MPESA_PASSKEY);
        $this->callbackUrl = trim(getSystemSetting('mpesa_callback_url') ?: MPESA_CALLBACK_URL);
        $this->environment = trim(getSystemSetting('mpesa_env') ?: MPESA_ENVIRONMENT);
        
        // Clean up callback URL
        $this->callbackUrl = rtrim($this->callbackUrl, '/');
    }
    
    /**
     * Get M-Pesa API URLs based on environment
     */
    private function getApiUrls() {
        if ($this->environment === 'production') {
            return [
                'authenticate' => 'https://api.safaricom.co.ke/oauth/v1/generate',
                'transaction_status' => 'https://api.safaricom.co.ke/mpesa/transactionstatus/v1/query'
            ];
        } else {
            return [
                'authenticate' => 'https://sandbox.safaricom.co.ke/oauth/v1/generate',
                'transaction_status' => 'https://sandbox.safaricom.co.ke/mpesa/transactionstatus/v1/query'
            ];
        }
    }
    
    /**
     * Get OAuth access token from Safaricom
     */
    private function getAccessToken() {
        try {
            $urls = $this->getApiUrls();
            $auth = base64_encode($this->consumerKey . ':' . $this->consumerSecret);
            
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $urls['authenticate'] . '?grant_type=client_credentials',
                CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $auth],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_SSL_VERIFYPEER => true
            ]);
            
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            
            if ($httpCode == 200) {
                $result = json_decode($response, true);
                return $result['access_token'] ?? null;
            }
            
            error_log("M-Pesa access token error: HTTP {$httpCode} - {$response}");
            return null;
        } catch (Exception $e) {
            error_log("M-Pesa auth error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Generate signature for API request
     */
    private function generateSignature($timestamp) {
        return base64_encode($this->shortcode . $this->passkey . $timestamp);
    }
    
    /**
     * Query transaction status to verify payment
     * @param string $phone Phone number (254XXXXXXXXX)
     * @param string $transactionId Transaction ID (M-Pesa receipt number)
     * @param int $invoiceId Invoice ID (optional)
     * @param int $studentId Student ID (optional)
     * @return array Result with payment details if found
     */
    public function verifyPayment($phone, $transactionId, $invoiceId = null, $studentId = null) {
        try {
            // Validate phone
            $phone = preg_replace('/^0/', '254', $phone);
            if (!preg_match('/^254\d{9}$/', $phone)) {
                return [
                    'success' => false,
                    'message' => 'Invalid phone number format. Use format: 0712345678'
                ];
            }
            
            // Validate transaction ID (should be M-Pesa receipt number - usually 10 chars)
            if (empty($transactionId) || strlen($transactionId) < 3) {
                return [
                    'success' => false,
                    'message' => 'Invalid M-Pesa reference number'
                ];
            }
            
            // Check if payment already recorded
            $stmt = $this->pdo->prepare("
                SELECT * FROM payments 
                WHERE reference_no = ? OR transaction_id = ?
                LIMIT 1
            ");
            $stmt->execute([$transactionId, 'MPESA-' . $transactionId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                return [
                    'success' => false,
                    'message' => 'This payment has already been recorded',
                    'already_recorded' => true,
                    'existing_payment' => $existing
                ];
            }
            
            // Get access token
            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                return [
                    'success' => false,
                    'message' => 'Failed to connect to M-Pesa. Please try again.',
                    'error_code' => 'AUTH_FAILED'
                ];
            }
            
            // Prepare transaction query
            $timestamp = date('YmdHis');
            $signature = $this->generateSignature($timestamp);
            
            $urls = $this->getApiUrls();
            $payload = [
                'CommandID' => 'QueryPaymentDetails',
                'ShortCode' => $this->shortcode,
                'Receipt' => $transactionId,
                'Initiator' => 'testapi',
                'SecurityCredential' => $signature,
                'QueueTimeOutURL' => $this->callbackUrl,
                'ResultURL' => $this->callbackUrl,
            ];
            
            // Alternative: Use CheckoutRequestID if available
            if (preg_match('/^[a-z0-9]+$/', $transactionId)) {
                // This looks like a CheckoutRequestID
                $payload = [
                    'BusinessShortCode' => $this->shortcode,
                    'Password' => $signature,
                    'Timestamp' => $timestamp,
                    'CheckoutRequestID' => $transactionId
                ];
            }
            
            // Query M-Pesa
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $urls['transaction_status'],
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json'
                ],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_SSL_VERIFYPEER => true
            ]);
            
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            
            $result = json_decode($response, true);
            
            if ($httpCode == 200 && $result) {
                // Check if payment is confirmed
                $responseCode = $result['ResponseCode'] ?? $result['response_code'] ?? null;
                
                if ($responseCode === '0' || $responseCode === 0) {
                    // Payment successful - extract details
                    $paymentDetails = $this->extractPaymentDetails($result);
                    
                    // If we have invoice and student IDs, record the payment
                    if ($invoiceId && $studentId) {
                        $recordResult = $this->recordVerifiedPayment(
                            $invoiceId,
                            $studentId,
                            $paymentDetails,
                            $phone,
                            $transactionId
                        );
                        
                        if ($recordResult['success']) {
                            return [
                                'success' => true,
                                'message' => 'Payment verified and recorded successfully',
                                'payment_details' => $paymentDetails,
                                'payment_id' => $recordResult['payment_id']
                            ];
                        } else {
                            return [
                                'success' => false,
                                'message' => 'Payment verified but failed to record: ' . $recordResult['error']
                            ];
                        }
                    }
                    
                    return [
                        'success' => true,
                        'message' => 'Payment verified successfully',
                        'payment_details' => $paymentDetails,
                        'can_record' => true
                    ];
                } else {
                    // Payment not found or failed
                    $errorMessage = $result['ResponseDescription'] ?? $result['response_description'] ?? 'Payment not confirmed';
                    
                    return [
                        'success' => false,
                        'message' => 'Payment not found or not confirmed. ' . $errorMessage,
                        'error_code' => $responseCode
                    ];
                }
            } else {
                // M-Pesa API error
                $errorMsg = $result['errorMessage'] ?? $result['ResponseDescription'] ?? $response;
                
                error_log("M-Pesa verify error: HTTP {$httpCode} - " . json_encode($result));
                
                return [
                    'success' => false,
                    'message' => 'M-Pesa verification failed. Status: ' . $httpCode,
                    'http_code' => $httpCode,
                    'error_details' => is_string($errorMsg) ? $errorMsg : 'Check system logs'
                ];
            }
        } catch (Exception $e) {
            error_log("Payment verification exception: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error verifying payment: ' . $e->getMessage(),
                'error_code' => 'EXCEPTION'
            ];
        }
    }
    
    /**
     * Extract payment details from M-Pesa response
     */
    private function extractPaymentDetails($response) {
        $details = [
            'amount' => 0,
            'receipt' => '',
            'timestamp' => date('Y-m-d H:i:s'),
            'phone' => '',
            'raw_response' => $response
        ];
        
        // Extract from callback metadata if present
        if (isset($response['CallbackMetadata']['Item'])) {
            foreach ($response['CallbackMetadata']['Item'] as $item) {
                switch ($item['Name'] ?? '') {
                    case 'Amount':
                        $details['amount'] = (float)($item['Value'] ?? 0);
                        break;
                    case 'MpesaReceiptNumber':
                        $details['receipt'] = $item['Value'] ?? '';
                        break;
                    case 'TransactionDate':
                        $details['timestamp'] = $item['Value'] ?? $details['timestamp'];
                        break;
                    case 'PhoneNumber':
                        $details['phone'] = $item['Value'] ?? '';
                        break;
                }
            }
        }
        
        // Extract from direct response fields
        $details['amount'] = $details['amount'] ?: (float)($response['Amount'] ?? 0);
        $details['receipt'] = $details['receipt'] ?: ($response['Receipt'] ?? $response['MpesaReceiptNumber'] ?? '');
        $details['phone'] = $details['phone'] ?: ($response['PhoneNumber'] ?? $response['Initiator'] ?? '');
        
        return $details;
    }
    
    /**
     * Record a verified payment into the system
     */
    private function recordVerifiedPayment($invoiceId, $studentId, $paymentDetails, $phone, $transactionId) {
        try {
            $this->pdo->beginTransaction();
            
            // Validate invoice
            $stmt = $this->pdo->prepare("
                SELECT * FROM invoices 
                WHERE id = ? AND student_id = ? 
                FOR UPDATE
            ");
            $stmt->execute([$invoiceId, $studentId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invoice) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'error' => 'Invoice not found'
                ];
            }
            
            $amount = (float)$paymentDetails['amount'];
            
            if ($amount <= 0) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'error' => 'Invalid payment amount'
                ];
            }
            
            if ($amount > $invoice['balance']) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'error' => 'Payment amount exceeds balance'
                ];
            }
            
            // Update invoice
            $newPaidAmount = (float)$invoice['amount_paid'] + $amount;
            $newBalance = (float)$invoice['total_amount'] - $newPaidAmount;
            $newStatus = $newBalance <= 0 ? 'paid' : 'partial';
            
            $stmt = $this->pdo->prepare("
                UPDATE invoices 
                SET amount_paid = ?, 
                    balance = ?, 
                    status = ?,
                    last_payment_date = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$newPaidAmount, max(0, $newBalance), $newStatus, $invoiceId]);
            
            // Get or create payment method
            $stmt = $this->pdo->prepare("
                SELECT id FROM payment_methods 
                WHERE code = 'mpesa' LIMIT 1
            ");
            $stmt->execute();
            $paymentMethod = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$paymentMethod) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO payment_methods (code, label) 
                    VALUES ('mpesa', 'M-Pesa')
                ");
                $stmt->execute();
                $paymentMethodId = $this->pdo->lastInsertId();
            } else {
                $paymentMethodId = $paymentMethod['id'];
            }
            
            // Create payment record
            $receiptNumber = $paymentDetails['receipt'] ?: $transactionId;
            $transactionIdFull = 'MPESA-' . $receiptNumber;
            
            $stmt = $this->pdo->prepare("
                INSERT INTO payments (
                    invoice_id, student_id, amount, payment_date, 
                    payment_method_id, reference_no, transaction_id, 
                    status, recorded_by, notes, created_by
                ) VALUES (?, ?, ?, NOW(), ?, ?, ?, 'completed', 0, ?, 0)
            ");
            
            $notes = 'M-Pesa Payment Verified - Phone: ' . $phone . ', Amount: KES ' . number_format($amount, 2);
            
            $stmt->execute([
                $invoiceId,
                $studentId,
                $amount,
                $paymentMethodId,
                $receiptNumber,
                $transactionIdFull,
                $notes
            ]);
            
            $paymentId = $this->pdo->lastInsertId();
            
            // Store M-Pesa transaction record
            $stmt = $this->pdo->prepare("
                INSERT INTO mpesa_transactions (
                    invoice_id, student_id, phone, amount, 
                    receipt_number, status, created_at
                ) VALUES (?, ?, ?, ?, ?, 'completed', NOW())
                ON DUPLICATE KEY UPDATE
                    status = 'completed', updated_at = NOW()
            ");
            $stmt->execute([
                $invoiceId,
                $studentId,
                $phone,
                $amount,
                $receiptNumber
            ]);
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'payment_id' => $paymentId,
                'message' => 'Payment recorded successfully'
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            
            error_log("Record verified payment error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get student's pending invoices
     */
    public function getStudentInvoices($studentId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, invoice_no, total_amount, amount_paid, balance, status 
                FROM invoices 
                WHERE student_id = ? AND status IN ('unpaid', 'partial')
                ORDER BY created_at DESC
            ");
            $stmt->execute([$studentId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get student invoices error: " . $e->getMessage());
            return [];
        }
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkAuth();
    header('Content-Type: application/json');
    
    try {
        $verifier = new MPesaPaymentVerifier($pdo);
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'verify_payment':
                $phone = trim($_POST['phone'] ?? '');
                $transactionId = trim($_POST['transaction_id'] ?? '');
                $invoiceId = intval($_POST['invoice_id'] ?? 0);
                $studentId = intval($_POST['student_id'] ?? 0);
                
                if (empty($phone) || empty($transactionId)) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Phone and transaction ID are required'
                    ]);
                } else {
                    $result = $verifier->verifyPayment($phone, $transactionId, $invoiceId, $studentId);
                    echo json_encode($result);
                }
                break;
            
            case 'get_invoices':
                $studentId = intval($_POST['student_id'] ?? 0);
                if ($studentId <= 0) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Invalid student ID'
                    ]);
                } else {
                    $invoices = $verifier->getStudentInvoices($studentId);
                    echo json_encode([
                        'success' => true,
                        'invoices' => $invoices
                    ]);
                }
                break;
            
            default:
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid action'
                ]);
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $e->getMessage()
        ]);
    }
    exit;
}

// If accessed directly without AJAX, show error
http_response_code(400);
echo 'Invalid request';
exit;
?>
