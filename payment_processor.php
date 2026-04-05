<?php
/**
 * Unified Payment Processor
 * Handles all payment methods: Cash, M-Pesa, Bank Transfer, Check
 */

include 'config.php';
checkAuth();
checkRole(['accountant', 'admin']);

// Check if this is an AJAX request
$action = $_POST['action'] ?? $_GET['action'] ?? '';
if (!empty($action)) {
    header('Content-Type: application/json');
}

class PaymentProcessor {
    private $pdo;
    private $userId;
    
    public function __construct($pdo, $userId) {
        $this->pdo = $pdo;
        $this->userId = $userId;
    }
    
    /**
     * Get available payment methods from database
     */
    public function getPaymentMethods() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, code, label, description, is_active 
                FROM payment_methods 
                WHERE is_active = TRUE 
                ORDER BY label
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get payment methods error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Process cash payment
     */
    public function processCashPayment($invoiceId, $studentId, $amount, $notes = '') {
        try {
            $this->pdo->beginTransaction();
            
            // Validate invoice and amount
            $validation = $this->validatePayment($invoiceId, $studentId, $amount);
            if (!$validation['valid']) {
                return ['success' => false, 'message' => $validation['error']];
            }
            
            // Record payment
            $result = $this->recordPaymentInDatabase(
                $invoiceId,
                $studentId,
                $amount,
                'cash',
                'CASH-' . date('YmdHis'),
                $notes ?: 'Cash Payment'
            );
            
            if ($result) {
                $this->pdo->commit();
                return [
                    'success' => true,
                    'message' => 'Cash payment recorded successfully',
                    'payment_id' => $result
                ];
            } else {
                throw new Exception('Failed to record payment');
            }
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Process check payment
     */
    public function processCheckPayment($invoiceId, $studentId, $amount, $checkNo, $notes = '') {
        try {
            $this->pdo->beginTransaction();
            
            $validation = $this->validatePayment($invoiceId, $studentId, $amount);
            if (!$validation['valid']) {
                return ['success' => false, 'message' => $validation['error']];
            }
            
            // Check for duplicate check number (within 30 days)
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count FROM payments 
                WHERE reference = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute([$checkNo]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
                return ['success' => false, 'message' => 'Check number already used'];
            }
            
            $result = $this->recordPaymentInDatabase(
                $invoiceId,
                $studentId,
                $amount,
                'check',
                $checkNo,
                'Check Payment - Check #' . $checkNo
            );
            
            if ($result) {
                $this->pdo->commit();
                return [
                    'success' => true,
                    'message' => 'Check payment recorded successfully',
                    'payment_id' => $result
                ];
            }
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Validate payment before processing
     */
    private function validatePayment($invoiceId, $studentId, $amount) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, student_id, total_amount, amount_paid, balance, status 
                FROM invoices 
                WHERE id = ? 
                LIMIT 1
            ");
            $stmt->execute([$invoiceId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invoice) {
                return ['valid' => false, 'error' => 'Invoice not found'];
            }
            
            if ($invoice['student_id'] != $studentId) {
                return ['valid' => false, 'error' => 'Invoice does not belong to this student'];
            }
            
            if ($amount <= 0) {
                return ['valid' => false, 'error' => 'Payment amount must be greater than zero'];
            }
            
            if ($amount > $invoice['balance']) {
                return [
                    'valid' => false,
                    'error' => 'Payment exceeds balance. Balance: KES ' . number_format($invoice['balance'], 2)
                ];
            }
            
            return ['valid' => true];
        } catch (Exception $e) {
            return ['valid' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Record payment in database
     */
    private function recordPaymentInDatabase($invoiceId, $studentId, $amount, $method, $reference, $notes) {
        try {
            // Get payment method ID
            $stmt = $this->pdo->prepare("
                SELECT id FROM payment_methods WHERE code = ? LIMIT 1
            ");
            $stmt->execute([$method]);
            $payMethod = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$payMethod) {
                throw new Exception("Payment method '$method' not found");
            }
            
            // Insert payment record
            $stmt = $this->pdo->prepare("
                INSERT INTO payments (
                    invoice_id, student_id, payment_method_id, amount, 
                    reference_no, payment_date, notes, recorded_by, created_by, status, transaction_id
                ) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, 'completed', ?)
            ");
            $stmt->execute([
                $invoiceId,
                $studentId,
                $payMethod['id'],
                $amount,
                $reference,
                $notes,
                $this->userId,
                $this->userId,
                $reference
            ]);
            $paymentId = $this->pdo->lastInsertId();
            
            // Update invoice
            $stmt = $this->pdo->prepare("
                SELECT total_amount, amount_paid, balance FROM invoices WHERE id = ?
            ");
            $stmt->execute([$invoiceId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $newPaidAmount = $invoice['amount_paid'] + $amount;
            $newBalance = max(0, $invoice['total_amount'] - $newPaidAmount);
            $newStatus = ($newBalance <= 0) ? 'paid' : 'partial';
            
            $stmt = $this->pdo->prepare("
                UPDATE invoices SET 
                    amount_paid = amount_paid + ?,
                    balance = ?,
                    status = ?
                WHERE id = ?
            ");
            $stmt->execute([$amount, $newBalance, $newStatus, $invoiceId]);
            
            // Log activity
            $this->logActivity("Recorded {$method} payment of KES " . number_format($amount, 2) . 
                             " for invoice #{$invoiceId}");
            
            return $paymentId;
        } catch (Exception $e) {
            throw $e;
        }
    }
    
    /**
     * Get payment history for invoice
     */
    public function getPaymentHistory($invoiceId, $limit = 10) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT p.*, pm.label as method_label, u.full_name as recorder_name
                FROM payments p
                JOIN payment_methods pm ON p.payment_method_id = pm.id
                LEFT JOIN users u ON p.created_by = u.id
                WHERE p.invoice_id = ?
                ORDER BY p.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$invoiceId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get payment history error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get daily payment summary
     */
    public function getDailyPaymentSummary($date) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    pm.code,
                    pm.label,
                    COUNT(p.id) as count,
                    SUM(p.amount) as total
                FROM payments p
                JOIN payment_methods pm ON p.payment_method_id = pm.id
                WHERE DATE(p.created_at) = ?
                GROUP BY pm.id, pm.code, pm.label
                ORDER BY pm.label
            ");
            $stmt->execute([$date]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get daily summary error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Log activity to audit trail
     */
    private function logActivity($description) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO login_activity (user_id, ip_address, user_agent, login_method, created_at) 
                VALUES (?, ?, ?, 'payment', NOW())
            ");
            $stmt->execute([
                $this->userId,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $e) {
            // Silently fail
        }
    }
}

// Handle AJAX requests
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$processor = new PaymentProcessor($pdo, $_SESSION['user_id'] ?? 0);

if (!empty($action)) {
    try {
        switch ($action) {
            case 'get_methods':
                echo json_encode([
                    'success' => true,
                    'methods' => $processor->getPaymentMethods()
                ]);
                break;
                
            case 'process_cash':
                $result = $processor->processCashPayment(
                    intval($_POST['invoice_id'] ?? 0),
                    intval($_POST['student_id'] ?? 0),
                    floatval($_POST['amount'] ?? 0),
                    trim($_POST['notes'] ?? '')
                );
                echo json_encode($result);
                break;
                
            case 'process_check':
                $result = $processor->processCheckPayment(
                    intval($_POST['invoice_id'] ?? 0),
                    intval($_POST['student_id'] ?? 0),
                    floatval($_POST['amount'] ?? 0),
                    trim($_POST['check_no'] ?? ''),
                    trim($_POST['notes'] ?? '')
                );
                echo json_encode($result);
                break;
                
            case 'get_payment_history':
                $history = $processor->getPaymentHistory(intval($_GET['invoice_id'] ?? 0));
                echo json_encode([
                    'success' => true,
                    'payments' => $history
                ]);
                break;
                
            case 'get_daily_summary':
                $summary = $processor->getDailyPaymentSummary($_GET['date'] ?? date('Y-m-d'));
                echo json_encode([
                    'success' => true,
                    'summary' => $summary
                ]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
    exit();
}
?>
