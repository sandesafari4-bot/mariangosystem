/**
 * Payment Processing UI Handler
 * Integrates M-Pesa, Bank Transfer, Cash, and Check payments with SweetAlert notifications
 */

class PaymentProcessor {
    constructor() {
        this.paymentMethods = [];
        this.currentInvoice = null;
        this.loadingAlert = null;
    }
    
    /**
     * Initialize payment processor
     */
    init() {
        this.loadPaymentMethods();
        this.attachEventListeners();
    }
    
    /**
     * Load available payment methods from database
     */
    loadPaymentMethods() {
        fetch('payment_processor.php?action=get_methods', {
            method: 'GET'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.paymentMethods = data.methods;
                this.updatePaymentMethodUI();
            }
        })
        .catch(error => console.error('Error loading payment methods:', error));
    }
    
    /**
     * Update payment method dropdown/display
     */
    updatePaymentMethodUI() {
        const methodSelect = document.getElementById('payment_method_select');
        if (methodSelect) {
            methodSelect.innerHTML = '<option value="">-- Select Payment Method --</option>';
            this.paymentMethods.forEach(method => {
                const option = document.createElement('option');
                option.value = method.id;
                option.dataset.code = method.code;
                option.textContent = method.label;
                methodSelect.appendChild(option);
            });
        }
    }
    
    /**
     * Attach event listeners
     */
    attachEventListeners() {
        // Payment method change
        const methodSelect = document.getElementById('payment_method_select');
        if (methodSelect) {
            methodSelect.addEventListener('change', (e) => this.onMethodChange(e));
        }
        
        // Payment buttons
        document.getElementById('btn_pay_cash')?.addEventListener('click', () => this.processCash());
        document.getElementById('btn_pay_mpesa')?.addEventListener('click', () => this.processMPesa());
        document.getElementById('btn_pay_bank')?.addEventListener('click', () => this.processBank());
        document.getElementById('btn_pay_check')?.addEventListener('click', () => this.processCheck());
    }
    
    /**
     * Handle payment method selection
     */
    onMethodChange(e) {
        const code = e.target.options[e.target.selectedIndex]?.dataset.code;
        this.hideAllPaymentForms();
        
        switch (code) {
            case 'cash':
                this.showCashForm();
                break;
            case 'mpesa':
                this.showMPesaForm();
                break;
            case 'bank':
                this.showBankForm();
                break;
            case 'check':
                this.showCheckForm();
                break;
        }
    }
    
    /**
     * Hide all payment forms
     */
    hideAllPaymentForms() {
        ['cash', 'mpesa', 'bank', 'check'].forEach(method => {
            const form = document.getElementById(`${method}_payment_form`);
            if (form) form.style.display = 'none';
        });
    }
    
    /**
     * Show cash payment form
     */
    showCashForm() {
        const form = document.getElementById('cash_payment_form');
        if (!form) {
            const html = `
                <div id="cash_payment_form" class="payment-form card mt-3">
                    <div class="card-body">
                        <h5 class="card-title">Cash Payment</h5>
                        <div class="form-group">
                            <label for="cash_amount">Amount (KES)</label>
                            <input type="number" class="form-control" id="cash_amount" 
                                   placeholder="Enter amount" step="0.01" min="1">
                        </div>
                        <div class="form-group">
                            <label for="cash_notes">Notes</label>
                            <textarea class="form-control" id="cash_notes" rows="2" 
                                      placeholder="Optional notes"></textarea>
                        </div>
                        <button type="button" class="btn btn-success" id="btn_pay_cash">
                            <i class="fas fa-money-bill"></i> Record Cash Payment
                        </button>
                    </div>
                </div>
            `;
            document.getElementById('payment_methods_container').insertAdjacentHTML('beforeend', html);
            document.getElementById('btn_pay_cash')?.addEventListener('click', () => this.processCash());
        } else {
            form.style.display = 'block';
        }
    }
    
    /**
     * Process cash payment
     */
    processCash() {
        const amount = parseFloat(document.getElementById('cash_amount')?.value || 0);
        const notes = document.getElementById('cash_notes')?.value || '';
        
        if (!this.currentInvoice) {
            Swal.fire('Error', 'No invoice selected', 'error');
            return;
        }
        
        if (amount < 1) {
            Swal.fire('Validation', 'Please enter a valid amount', 'warning');
            return;
        }
        
        this.showLoadingSpinner('Processing cash payment...');
        
        fetch('payment_processor.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=process_cash&invoice_id=${this.currentInvoice.id}&student_id=${this.currentInvoice.student_id}&amount=${amount}&notes=${encodeURIComponent(notes)}`
        })
        .then(response => response.json())
        .then(data => {
            this.closeLoadingSpinner();
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Payment Recorded',
                    text: data.message,
                    confirmButtonColor: '#4CAF50'
                }).then(() => location.reload());
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            this.closeLoadingSpinner();
            Swal.fire('Error', 'Failed to process payment', 'error');
            console.error('Error:', error);
        });
    }
    
    /**
     * Show M-Pesa payment form
     */
    showMPesaForm() {
        const form = document.getElementById('mpesa_payment_form');
        if (!form) {
            const html = `
                <div id="mpesa_payment_form" class="payment-form card mt-3">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="fas fa-mobile-alt"></i> M-Pesa Payment
                        </h5>
                        <div class="alert alert-info">
                            <small>Enter your phone number to receive an STK Push prompt</small>
                        </div>
                        <div class="form-group">
                            <label for="mpesa_phone">Phone Number</label>
                            <input type="text" class="form-control" id="mpesa_phone" 
                                   placeholder="254XXXXXXXXX" maxlength="12">
                            <small class="form-text text-muted">Format: 254 followed by 9 digits</small>
                        </div>
                        <div class="form-group">
                            <label for="mpesa_amount">Amount (KES)</label>
                            <input type="number" class="form-control" id="mpesa_amount" 
                                   placeholder="Enter amount" step="0.01" min="1">
                        </div>
                        <button type="button" class="btn btn-primary" id="btn_pay_mpesa">
                            <i class="fas fa-check-circle"></i> Send Payment Prompt
                        </button>
                    </div>
                </div>
            `;
            document.getElementById('payment_methods_container').insertAdjacentHTML('beforeend', html);
            document.getElementById('btn_pay_mpesa')?.addEventListener('click', () => this.processMPesa());
        } else {
            form.style.display = 'block';
        }
    }
    
    /**
     * Process M-Pesa payment
     */
    processMPesa() {
        const phone = document.getElementById('mpesa_phone')?.value.trim() || '';
        const amount = parseFloat(document.getElementById('mpesa_amount')?.value || 0);
        
        if (!this.currentInvoice) {
            Swal.fire('Error', 'No invoice selected', 'error');
            return;
        }
        
        // Validate phone
        if (!/^254\d{9}$/.test(phone)) {
            Swal.fire('Validation', 'Invalid phone format. Use 254XXXXXXXXX', 'warning');
            return;
        }
        
        if (amount < 1) {
            Swal.fire('Validation', 'Please enter valid amount', 'warning');
            return;
        }
        
        this.showLoadingSpinner('Sending payment prompt to ' + phone + '...');
        
        fetch('mpesa_processor.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=initiate_stk&invoice_id=${this.currentInvoice.id}&student_id=${this.currentInvoice.student_id}&phone=${phone}&amount=${amount}`
        })
        .then(response => response.json())
        .then(data => {
            this.closeLoadingSpinner();
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'STK Sent',
                    text: data.message + '\nCheck your phone and enter your M-Pesa PIN.',
                    confirmButtonColor: '#4CAF50',
                    timer: 8000,
                    timerProgressBar: true
                });
                
                // Poll for payment status
                if (data.checkout_request_id) {
                    this.pollPaymentStatus(data.checkout_request_id, 60000);
                }
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            this.closeLoadingSpinner();
            Swal.fire('Error', 'Failed to send payment prompt', 'error');
            console.error('Error:', error);
        });
    }
    
    /**
     * Poll for M-Pesa payment status
     */
    pollPaymentStatus(checkoutRequestId, timeout = 60000) {
        const startTime = Date.now();
        const pollInterval = setInterval(() => {
            if (Date.now() - startTime > timeout) {
                clearInterval(pollInterval);
                return;
            }
            
            fetch('mpesa_processor.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=query_status&checkout_request_id=${checkoutRequestId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.status === 'completed') {
                    clearInterval(pollInterval);
                    Swal.fire({
                        icon: 'success',
                        title: 'Payment Successful',
                        text: 'Your payment has been processed successfully!',
                        confirmButtonColor: '#4CAF50'
                    }).then(() => location.reload());
                }
            })
            .catch(error => console.error('Poll error:', error));
        }, 5000);
    }
    
    /**
     * Show Bank Transfer form
     */
    showBankForm() {
        const form = document.getElementById('bank_payment_form');
        if (!form) {
            let html = `
                <div id="bank_payment_form" class="payment-form card mt-3">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="fas fa-university"></i> Bank Transfer
                        </h5>
                        <div class="alert alert-info">
                            <strong>School Bank Details:</strong>
                            <div id="bank_details_display">Loading...</div>
                        </div>
                        <div class="form-group">
                            <label for="bank_reference">Transaction Reference</label>
                            <input type="text" class="form-control" id="bank_reference" 
                                   placeholder="Enter your bank transfer reference">
                            <small class="form-text text-muted">Reference from your bank receipt</small>
                        </div>
                        <div class="form-group">
                            <label for="bank_amount">Amount (KES)</label>
                            <input type="number" class="form-control" id="bank_amount" 
                                   placeholder="Amount transferred" step="0.01" min="1">
                        </div>
                        <div class="form-group">
                            <label for="bank_code">Bank Code (Optional)</label>
                            <input type="text" class="form-control" id="bank_code" 
                                   placeholder="e.g., KCB, EQUITY">
                        </div>
                        <button type="button" class="btn btn-primary" id="btn_pay_bank">
                            <i class="fas fa-save"></i> Record Bank Transfer
                        </button>
                    </div>
                </div>
            `;
            document.getElementById('payment_methods_container').insertAdjacentHTML('beforeend', html);
            
            // Load bank details
            fetch('bank_processor.php?action=get_bank_details')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const details = data.data;
                    const display = document.getElementById('bank_details_display');
                    if (display) {
                        display.innerHTML = `
                            <p><strong>${details.bank_name}</strong><br>
                            Account: ${details.account_name}<br>
                            Number: ${details.account_number}<br>
                            ${details.branch_code ? 'Branch: ' + details.branch_code + '<br>' : ''}
                            ${details.swift_code ? 'SWIFT: ' + details.swift_code : ''}</p>
                        `;
                    }
                }
            });
            
            document.getElementById('btn_pay_bank')?.addEventListener('click', () => this.processBank());
        } else {
            form.style.display = 'block';
        }
    }
    
    /**
     * Process Bank Transfer payment
     */
    processBank() {
        const reference = document.getElementById('bank_reference')?.value.trim() || '';
        const amount = parseFloat(document.getElementById('bank_amount')?.value || 0);
        const bankCode = document.getElementById('bank_code')?.value.trim() || '';
        
        if (!this.currentInvoice) {
            Swal.fire('Error', 'No invoice selected', 'error');
            return;
        }
        
        if (!reference) {
            Swal.fire('Validation', 'Please enter transaction reference', 'warning');
            return;
        }
        
        if (amount < 1) {
            Swal.fire('Validation', 'Please enter valid amount', 'warning');
            return;
        }
        
        this.showLoadingSpinner('Recording bank transfer...');
        
        fetch('bank_processor.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=record_transfer&invoice_id=${this.currentInvoice.id}&student_id=${this.currentInvoice.student_id}&amount=${amount}&reference=${encodeURIComponent(reference)}&bank_code=${encodeURIComponent(bankCode)}`
        })
        .then(response => response.json())
        .then(data => {
            this.closeLoadingSpinner();
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Transfer Recorded',
                    text: data.message,
                    confirmButtonColor: '#4CAF50'
                }).then(() => location.reload());
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            this.closeLoadingSpinner();
            Swal.fire('Error', 'Failed to record transfer', 'error');
            console.error('Error:', error);
        });
    }
    
    /**
     * Show Check payment form
     */
    showCheckForm() {
        const form = document.getElementById('check_payment_form');
        if (!form) {
            const html = `
                <div id="check_payment_form" class="payment-form card mt-3">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="fas fa-check"></i> Check Payment
                        </h5>
                        <div class="form-group">
                            <label for="check_number">Check Number</label>
                            <input type="text" class="form-control" id="check_number" 
                                   placeholder="Check number">
                        </div>
                        <div class="form-group">
                            <label for="check_amount">Amount (KES)</label>
                            <input type="number" class="form-control" id="check_amount" 
                                   placeholder="Check amount" step="0.01" min="1">
                        </div>
                        <div class="form-group">
                            <label for="check_date">Check Date</label>
                            <input type="date" class="form-control" id="check_date">
                        </div>
                        <button type="button" class="btn btn-success" id="btn_pay_check">
                            <i class="fas fa-save"></i> Record Check Payment
                        </button>
                    </div>
                </div>
            `;
            document.getElementById('payment_methods_container').insertAdjacentHTML('beforeend', html);
            document.getElementById('btn_pay_check')?.addEventListener('click', () => this.processCheck());
        } else {
            form.style.display = 'block';
        }
    }
    
    /**
     * Process Check payment
     */
    processCheck() {
        const checkNo = document.getElementById('check_number')?.value.trim() || '';
        const amount = parseFloat(document.getElementById('check_amount')?.value || 0);
        
        if (!this.currentInvoice) {
            Swal.fire('Error', 'No invoice selected', 'error');
            return;
        }
        
        if (!checkNo) {
            Swal.fire('Validation', 'Please enter check number', 'warning');
            return;
        }
        
        if (amount < 1) {
            Swal.fire('Validation', 'Please enter valid amount', 'warning');
            return;
        }
        
        this.showLoadingSpinner('Recording check payment...');
        
        fetch('payment_processor.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=process_check&invoice_id=${this.currentInvoice.id}&student_id=${this.currentInvoice.student_id}&amount=${amount}&check_no=${encodeURIComponent(checkNo)}`
        })
        .then(response => response.json())
        .then(data => {
            this.closeLoadingSpinner();
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Payment Recorded',
                    text: data.message,
                    confirmButtonColor: '#4CAF50'
                }).then(() => location.reload());
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            this.closeLoadingSpinner();
            Swal.fire('Error', 'Failed to process payment', 'error');
            console.error('Error:', error);
        });
    }
    
    /**
     * Set current invoice for payment
     */
    setInvoice(invoiceData) {
        this.currentInvoice = invoiceData;
        
        // Update UI with invoice details
        const details = document.getElementById('invoice_details_display');
        if (details) {
            details.innerHTML = `
                <strong>Invoice #${invoiceData.id}</strong><br>
                Amount: KES ${parseFloat(invoiceData.total_amount).toLocaleString('en-KE', {minimumFractionDigits: 2})}<br>
                Paid: KES ${parseFloat(invoiceData.amount_paid).toLocaleString('en-KE', {minimumFractionDigits: 2})}<br>
                Balance: KES ${parseFloat(invoiceData.balance).toLocaleString('en-KE', {minimumFractionDigits: 2})}
            `;
        }
    }
    
    /**
     * Show loading spinner with SweetAlert
     */
    showLoadingSpinner(message = 'Processing...') {
        Swal.fire({
            title: message,
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        this.loadingAlert = true;
    }
    
    /**
     * Close loading spinner
     */
    closeLoadingSpinner() {
        if (this.loadingAlert) {
            Swal.close();
            this.loadingAlert = false;
        }
    }
}

// Initialize payment processor when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.paymentProcessor = new PaymentProcessor();
    window.paymentProcessor.init();
});
