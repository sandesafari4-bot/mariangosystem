<?php
/**
 * Bank Transfer Payment Integration
 * Handles bank transfer payment recording and verification
 */

include 'config.php';

class BankTransfer {
    private $pdo;
    
    public function __construct($pdo = null) {
        $this->pdo = $pdo ?? $GLOBALS['pdo'];
    }
    
    /**
     * Get bank transfer details for display to student
     */
    public function getBankDetails() {
        try {
            $bankName = getSystemSetting('bank_name', 'KCB Bank Kenya');
            $accountName = getSystemSetting('bank_account_name', 'Mariango Comprehensive School');
            $accountNumber = getSystemSetting('bank_account_number', '1234567890');
            $accountCode = getSystemSetting('bank_account_code', '001');
            
            return [
                'success' => true,
                'bank_name' => $bankName,
                'account_name' => $accountName,
                'account_number' => $accountNumber,
                'account_code' => $accountCode,
                'swift_code' => getSystemSetting('bank_swift_code', ''),
                'branch' => getSystemSetting('bank_branch', 'Nairobi Main Branch')
            ];
        } catch (Exception $e) {
            error_log("Error getting bank details: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to retrieve bank details'
            ];
        }
    }
    
    /**
     * Record bank transfer payment
     * @param int $invoiceId Invoice ID
     * @param int $studentId Student ID
     * @param float $amount Amount transferred
     * @param string $reference Bank transfer reference
     * @param string $bankCode Bank code/identifier
     * @param string $notes Additional notes
     * @return array [success => bool, message => string]
     */
    public function recordPayment($invoiceId, $studentId, $amount, $reference, $bankCode, $notes = '') {
        try {
            // Validate inputs
            if ($invoiceId <= 0 || $studentId <= 0 || $amount <= 0 || empty($reference)) {
                return [
                    'success' => false,
                    'message' => 'Please provide all required details'
                ];
            }
            
            $this->pdo->beginTransaction();
            
            // Get invoice and verify
            $stmt = $this->pdo->prepare("
                SELECT id, student_id, total_amount, amount_paid, balance, status 
                FROM invoices 
                WHERE id = ? AND student_id = ? 
                FOR UPDATE
            ");
            $stmt->execute([$invoiceId, $studentId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invoice) {
                return [
                    'success' => false,
                    'message' => 'Invoice not found'
                ];
            }
            
            // Verify amount doesn't exceed balance
            if ($amount > $invoice['balance'] && $invoice['status'] !== 'paid') {
                return [
                    'success' => false,
                    'message' => 'Payment amount (KES ' . number_format($amount, 2) . 
                                ') exceeds outstanding balance (KES ' . 
                                number_format($invoice['balance'], 2) . ')'
                ];
            }
            
            // Check for duplicate reference (within last 24 hours)
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count FROM bank_transfers 
                WHERE reference = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute([$reference]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
                return [
                    'success' => false,
                    'message' => 'This transfer reference was already recorded. Please use a different reference.'
                ];
            }
            
            // Get payment method ID for bank transfer
            $stmt = $this->pdo->prepare("
                SELECT id FROM payment_methods WHERE code = 'bank_transfer' LIMIT 1
            ");
            $stmt->execute();
            $paymentMethod = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$paymentMethod) {
                return [
                    'success' => false,
                    'message' => 'Bank transfer payment method not configured'
                ];
            }
            
            // Create payment record
            $stmt = $this->pdo->prepare("
                INSERT INTO payments (
                    invoice_id, student_id, payment_method_id, amount, 
                    reference_no, payment_date, notes, recorded_by, created_by, status, transaction_id
                ) VALUES (?, ?, ?, ?, ?, NOW(), ?, 0, 0, 'completed', ?)
            ");
            $stmt->execute([
                $invoiceId,
                $studentId,
                $paymentMethod['id'],
                $amount,
                $reference,
                $notes ?: 'Bank Transfer',
                $reference
            ]);
            $paymentId = $this->pdo->lastInsertId();
            
            // Record bank transfer details
            $stmt = $this->pdo->prepare("
                INSERT INTO bank_transfers (
                    payment_id, invoice_id, student_id, amount, 
                    reference, bank_code, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([
                $paymentId,
                $invoiceId,
                $studentId,
                $amount,
                $reference,
                $bankCode
            ]);
            
            // Update invoice
            $newPaidAmount = $invoice['amount_paid'] + $amount;
            $newBalance = max(0, $invoice['total_amount'] - $newPaidAmount);
            $newStatus = ($newBalance <= 0 || $invoice['status'] === 'paid') ? 'paid' : 'partial';
            
            $stmt = $this->pdo->prepare("
                UPDATE invoices SET 
                    amount_paid = ?, 
                    balance = ?,
                    status = ?
                WHERE id = ?
            ");
            $stmt->execute([$newPaidAmount, $newBalance, $newStatus, $invoiceId]);
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => 'Bank transfer recorded successfully',
                'payment_id' => $paymentId,
                'status' => $newStatus,
                'balance' => $newBalance
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Bank transfer error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error recording payment: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Verify bank transfer based on reference
     * This would connect to bank API or manual verification
     */
    public function verifyTransfer($reference) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM bank_transfers 
                WHERE reference = ? 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$reference]);
            $transfer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$transfer) {
                return [
                    'success' => false,
                    'message' => 'Transfer reference not found in our system'
                ];
            }
            
            return [
                'success' => true,
                'transfer' => $transfer,
                'status' => $transfer['status']
            ];
        } catch (Exception $e) {
            error_log("Verify transfer error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Approve/confirm bank transfer (Admin function)
     */
    public function confirmTransfer($transferId, $verificationCode = '') {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM bank_transfers WHERE id = ?
            ");
            $stmt->execute([$transferId]);
            $transfer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$transfer) {
                return ['success' => false, 'message' => 'Transfer not found'];
            }
            
            if ($transfer['status'] === 'completed') {
                return ['success' => false, 'message' => 'Transfer already confirmed'];
            }
            
            // Update transfer status
            $stmt = $this->pdo->prepare("
                UPDATE bank_transfers SET status = 'completed' WHERE id = ?
            ");
            $stmt->execute([$transferId]);
            
            return [
                'success' => true,
                'message' => 'Transfer confirmed successfully'
            ];
        } catch (Exception $e) {
            error_log("Confirm transfer error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Reject bank transfer
     */
    public function rejectTransfer($transferId, $reason = '') {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM bank_transfers WHERE id = ?
            ");
            $stmt->execute([$transferId]);
            $transfer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$transfer) {
                return ['success' => false, 'message' => 'Transfer not found'];
            }
            
            // Delete associated payment since transfer is rejected
            $stmt = $this->pdo->prepare("
                SELECT * FROM payments WHERE id = ?
            ");
            $stmt->execute([$transfer['payment_id']]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Reverse invoice update
            $stmt = $this->pdo->prepare("
                UPDATE invoices SET 
                    amount_paid = amount_paid - ?,
                    balance = balance + ?
                WHERE id = ?
            ");
            $stmt->execute([
                $transfer['amount'],
                $transfer['amount'],
                $transfer['invoice_id']
            ]);
            
            // Delete payment and transfer records
            $stmt = $this->pdo->prepare("
                DELETE FROM payments WHERE id = ?
            ");
            $stmt->execute([$transfer['payment_id']]);
            
            // Mark transfer as rejected
            $stmt = $this->pdo->prepare("
                UPDATE bank_transfers SET status = 'rejected', reason = ? WHERE id = ?
            ");
            $stmt->execute([$reason, $transferId]);
            
            return [
                'success' => true,
                'message' => 'Transfer rejected and payment reversed'
            ];
        } catch (Exception $e) {
            error_log("Reject transfer error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    checkAuth();
    
    $action = $_POST['action'] ?? '';
    $bankTransfer = new BankTransfer($pdo);
    
    if ($action === 'get_bank_details') {
        echo json_encode($bankTransfer->getBankDetails());
        exit();
    }
    
    if ($action === 'record_payment' && checkRole(['accountant', 'admin']) !== false) {
        $invoiceId = intval($_POST['invoice_id'] ?? 0);
        $studentId = intval($_POST['student_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        $reference = trim($_POST['reference'] ?? '');
        $bankCode = trim($_POST['bank_code'] ?? 'KCB');
        $notes = trim($_POST['notes'] ?? '');
        
        $result = $bankTransfer->recordPayment($invoiceId, $studentId, $amount, $reference, $bankCode, $notes);
        echo json_encode($result);
        exit();
    }
    
    if ($action === 'verify_transfer') {
        $reference = trim($_POST['reference'] ?? '');
        $result = $bankTransfer->verifyTransfer($reference);
        echo json_encode($result);
        exit();
    }
    
    if ($action === 'confirm_transfer' && checkRole(['admin']) !== false) {
        $transferId = intval($_POST['transfer_id'] ?? 0);
        $result = $bankTransfer->confirmTransfer($transferId);
        echo json_encode($result);
        exit();
    }
    
    if ($action === 'reject_transfer' && checkRole(['admin']) !== false) {
        $transferId = intval($_POST['transfer_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $result = $bankTransfer->rejectTransfer($transferId, $reason);
        echo json_encode($result);
        exit();
    }
}
?>
