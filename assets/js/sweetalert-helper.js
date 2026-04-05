/**
 * SweetAlert Helper Functions
 * Provides common alert patterns for the application
 */

// Success Alert
function showSuccessAlert(title, message = '', redirectUrl = null) {
    Swal.fire({
        icon: 'success',
        title: title,
        text: message,
        confirmButtonColor: '#28a745',
        confirmButtonText: 'OK'
    }).then((result) => {
        if (result.isConfirmed && redirectUrl) {
            window.location.href = redirectUrl;
        }
    });
}

// Error Alert
function showErrorAlert(title, message = '') {
    Swal.fire({
        icon: 'error',
        title: title,
        text: message,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'OK'
    });
}

// Warning Alert
function showWarningAlert(title, message = '') {
    Swal.fire({
        icon: 'warning',
        title: title,
        text: message,
        confirmButtonColor: '#ffc107',
        confirmButtonText: 'OK'
    });
}

// Info Alert
function showInfoAlert(title, message = '') {
    Swal.fire({
        icon: 'info',
        title: title,
        text: message,
        confirmButtonColor: '#17a2b8',
        confirmButtonText: 'OK'
    });
}

// Confirmation Dialog - Returns Promise
function showConfirmAlert(title, message = '', confirmText = 'Yes', cancelText = 'No') {
    return Swal.fire({
        icon: 'question',
        title: title,
        text: message,
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#dc3545',
        confirmButtonText: confirmText,
        cancelButtonText: cancelText
    });
}

// Loading Alert (shows spinner)
function showLoadingAlert(title = 'Processing...', message = 'Please wait') {
    Swal.fire({
        icon: 'info',
        title: title,
        text: message,
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
}

// Close Loading Alert
function closeLoadingAlert() {
    Swal.close();
}

// Confirmation with Redirect
function confirmAndRedirect(title, message = '', redirectUrl) {
    showConfirmAlert(title, message).then((result) => {
        if (result.isConfirmed) {
            window.location.href = redirectUrl;
        }
    });
}

// Confirmation with Form Submit
function confirmAndSubmit(title, message = '', formId) {
    showConfirmAlert(title, message).then((result) => {
        if (result.isConfirmed) {
            document.getElementById(formId).submit();
        }
    });
}

// Input Dialog
function showInputAlert(title, label = '', placeholder = '') {
    return Swal.fire({
        title: title,
        input: 'text',
        inputLabel: label,
        inputPlaceholder: placeholder,
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#dc3545',
        confirmButtonText: 'Submit',
        cancelButtonText: 'Cancel'
    });
}

// Toast Notification (top-right corner)
function showToast(icon = 'success', title = 'Success', timer = 3000) {
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: timer,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer);
            toast.addEventListener('mouseleave', Swal.resumeTimer);
        }
    });

    Toast.fire({
        icon: icon,
        title: title
    });
}

// Delete Confirmation
function confirmDelete(itemName = 'item') {
    return showConfirmAlert(
        'Delete Confirmation',
        `Are you sure you want to delete this ${itemName}? This action cannot be undone.`,
        'Yes, Delete',
        'Cancel'
    );
}

// Disable form during submission
function disableFormDuringSubmit(formId) {
    const form = document.getElementById(formId);
    if (form) {
        form.addEventListener('submit', function() {
            const buttons = form.querySelectorAll('button[type="submit"]');
            buttons.forEach(btn => {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            });
        });
    }
}

// Handle common AJAX errors
function handleAjaxError(error, defaultMessage = 'An error occurred') {
    let message = defaultMessage;
    
    if (error.responseJSON && error.responseJSON.message) {
        message = error.responseJSON.message;
    } else if (error.statusText) {
        message = error.statusText;
    }
    
    showErrorAlert('Error', message);
}

// Success with reload
function showSuccessAndReload(title, message = '', delay = 2000) {
    Swal.fire({
        icon: 'success',
        title: title,
        text: message,
        confirmButtonColor: '#28a745',
        allowOutsideClick: false,
        allowEscapeKey: false
    }).then(() => {
        setTimeout(() => {
            location.reload();
        }, delay);
    });
}
