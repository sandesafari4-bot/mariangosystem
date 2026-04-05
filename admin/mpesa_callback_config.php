<?php
/**
 * M-Pesa Callback URL Configuration Helper
 * Use this to fix incorrect callback URL configuration
 */

include '../config.php';

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$correct_callback_url = $protocol . '://' . $host . '/mariango_school/mpesa_callback.php';

// Get current setting from database
$current_callback_url = getSystemSetting('mpesa_callback_url', '');

// Check if file exists
$callback_file_exists = file_exists(__DIR__ . '/../mpesa_callback.php');
$callback_file_path = realpath(__DIR__ . '/../mpesa_callback.php');

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_callback'])) {
    $new_url = trim($_POST['callback_url'] ?? '');
    
    // Clean the URL - remove trailing slashes
    $new_url = rtrim($new_url, '/');
    
    if (empty($new_url)) {
        $error = 'Callback URL cannot be empty';
    } elseif (!filter_var($new_url, FILTER_VALIDATE_URL)) {
        $error = 'Callback URL is not a valid URL';
    } else {
        // Save to database
        $stmt = $pdo->prepare("INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)");
        if ($stmt->execute(['mpesa_callback_url', $new_url])) {
            $success = 'Callback URL updated successfully! URL: ' . $new_url;
            $current_callback_url = $new_url;
        } else {
            $error = 'Failed to save callback URL';
        }
    }
}

// Test callback file accessibility
$callback_test_result = null;
if ($callback_file_exists) {
    // Try to make a test request to the callback file
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $correct_callback_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['test' => true]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    $callback_test_result = [
        'http_code' => $http_code,
        'accessible' => $http_code === 200,
        'response' => $response
    ];
}

$page_title = "M-Pesa Callback URL Configuration";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        .header {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .header h1 {
            color: #333;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .header p {
            color: #666;
            font-size: 0.95rem;
        }
        .card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .card h2 {
            color: #333;
            font-size: 1.3rem;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f0f0f0;
        }
        .card h3 {
            color: #555;
            font-size: 1rem;
            margin: 1.5rem 0 0.5rem 0;
        }
        .info-box {
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border-left: 4px solid;
        }
        .info-box.correct {
            background: #d4edda;
            border-left-color: #28a745;
            color: #155724;
        }
        .info-box.warning {
            background: #fff3cd;
            border-left-color: #ffc107;
            color: #856404;
        }
        .info-box.error {
            background: #f8d7da;
            border-left-color: #dc3545;
            color: #721c24;
        }
        .info-box.info {
            background: #d1ecf1;
            border-left-color: #17a2b8;
            color: #0c5460;
        }
        .info-label {
            font-weight: 600;
            display: block;
            margin-bottom: 0.5rem;
        }
        .info-value {
            font-family: 'Courier New', monospace;
            font-size: 0.95rem;
            word-break: break-all;
            background: rgba(0,0,0,0.05);
            padding: 0.75rem;
            border-radius: 4px;
            margin-top: 0.5rem;
        }
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }
        .status-ok {
            background: #28a745;
        }
        .status-error {
            background: #dc3545;
        }
        .status-warning {
            background: #ffc107;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
        }
        input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: 'Courier New', monospace;
            transition: all 0.3s;
        }
        input:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .form-text {
            font-size: 0.85rem;
            color: #666;
            margin-top: 0.5rem;
        }
        button {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
            font-size: 0.95rem;
        }
        button.btn-primary {
            background: #667eea;
            color: white;
        }
        button.btn-primary:hover {
            background: #5a67d8;
        }
        button.btn-secondary {
            background: #6c757d;
            color: white;
        }
        button.btn-secondary:hover {
            background: #5a6268;
        }
        button.btn-copy {
            background: #17a2b8;
            color: white;
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }
        button.btn-copy:hover {
            background: #138496;
        }
        .steps {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin: 1.5rem 0;
        }
        .steps ol {
            margin-left: 2rem;
            line-height: 1.8;
            color: #555;
        }
        .steps li {
            margin-bottom: 1rem;
        }
        .code-block {
            background: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 1rem;
            margin: 1rem 0;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            overflow-x: auto;
        }
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .button-group {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 2rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>
                <i class="fas fa-link"></i> M-Pesa Callback URL Configuration
            </h1>
            <p>Configure and test your M-Pesa callback URL for payment notifications</p>
        </div>

        <!-- Messages -->
        <?php if (isset($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- Current Status -->
        <div class="card">
            <h2><i class="fas fa-info-circle"></i> Current Status</h2>
            
            <h3>Callback File</h3>
            <?php if ($callback_file_exists): ?>
                <div class="info-box correct">
                    <span class="status-indicator status-ok"></span>
                    <span class="info-label">✓ File exists</span>
                    <div class="info-value"><?php echo $callback_file_path; ?></div>
                </div>
            <?php else: ?>
                <div class="info-box error">
                    <span class="status-indicator status-error"></span>
                    <span class="info-label">✗ File NOT found</span>
                    <div class="info-value"><?php echo __DIR__ . '/../mpesa_callback.php'; ?></div>
                    <p>A file must exist at this location for M-Pesa to post payments to it.</p>
                </div>
            <?php endif; ?>

            <h3>Correct Callback URL</h3>
            <div class="info-box info">
                <span class="info-label">Should be set to:</span>
                <div class="info-value" id="correctUrl"><?php echo $correct_callback_url; ?></div>
                <button class="btn-copy" onclick="copyToClipboard('correctUrl')">
                    <i class="fas fa-copy"></i> Copy
                </button>
            </div>

            <h3>Current Database Setting</h3>
            <?php if ($current_callback_url === $correct_callback_url): ?>
                <div class="info-box correct">
                    <span class="status-indicator status-ok"></span>
                    <span class="info-label">✓ Correctly configured</span>
                    <div class="info-value"><?php echo $current_callback_url; ?></div>
                </div>
            <?php elseif (empty($current_callback_url)): ?>
                <div class="info-box warning">
                    <span class="status-indicator status-warning"></span>
                    <span class="info-label">⚠️ Not configured</span>
                    <p>The callback URL is not set in the database. Use the form below to configure it.</p>
                </div>
            <?php else: ?>
                <div class="info-box error">
                    <span class="status-indicator status-error"></span>
                    <span class="info-label">✗ Incorrect URL</span>
                    <div class="info-value"><?php echo $current_callback_url; ?></div>
                    <p>This URL does not match the correct URL. Use the form below to update it.</p>
                </div>
            <?php endif; ?>

            <?php if ($callback_test_result): ?>
                <h3>Callback File Accessibility Test</h3>
                <?php if ($callback_test_result['accessible']): ?>
                    <div class="info-box correct">
                        <span class="status-indicator status-ok"></span>
                        <span class="info-label">✓ File is accessible</span>
                        <p>HTTP Code: <?php echo $callback_test_result['http_code']; ?></p>
                    </div>
                <?php else: ?>
                    <div class="info-box error">
                        <span class="status-indicator status-error"></span>
                        <span class="info-label">✗ File returned error</span>
                        <p>HTTP Code: <?php echo $callback_test_result['http_code']; ?></p>
                        <div class="info-value"><?php echo htmlspecialchars($callback_test_result['response']); ?></div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Configuration Form -->
        <div class="card">
            <h2><i class="fas fa-cog"></i> Update Callback URL</h2>
            
            <form method="POST">
                <div class="form-group">
                    <label for="callback_url">Callback URL</label>
                    <input type="url" id="callback_url" name="callback_url" 
                           value="<?php echo htmlspecialchars($current_callback_url ?: $correct_callback_url); ?>" 
                           required>
                    <div class="form-text">
                        This URL should be where M-Pesa posts payment confirmations.
                    </div>
                </div>

                <button type="submit" name="update_callback" value="1" class="btn-primary">
                    <i class="fas fa-save"></i> Update Callback URL
                </button>
            </form>
        </div>

        <!-- Quick Setup Guide -->
        <div class="card">
            <h2><i class="fas fa-book"></i> Quick Setup Guide</h2>
            
            <div class="steps">
                <ol>
                    <li><strong>Copy the correct callback URL</strong>
                        <div class="code-block"><?php echo $correct_callback_url; ?></div>
                    </li>
                    <li><strong>Go to Admin Dashboard</strong> → Settings → M-Pesa Configuration</li>
                    <li><strong>Paste the URL</strong> into the "Callback URL" field</li>
                    <li><strong>Click Save</strong></li>
                    <li><strong>Verify on Safaricom Daraja Portal</strong>
                        <ul style="margin-top: 0.5rem; margin-left: 2rem;">
                            <li>Log in to https://developer.safaricom.co.ke/</li>
                            <li>Go to your app settings</li>
                            <li>Update the callback URL there as well</li>
                            <li>Save changes on Daraja portal</li>
                        </ul>
                    </li>
                    <li><strong>Test a payment</strong> to confirm it works</li>
                </ol>
            </div>
        </div>

        <!-- Troubleshooting -->
        <div class="card">
            <h2><i class="fas fa-wrench"></i> Troubleshooting</h2>
            
            <h3>Still Getting 404 Errors?</h3>
            <p><strong>Check these:</strong></p>
            <ul style="margin-left: 2rem; line-height: 1.8; color: #555;">
                <li>✓ Callback file exists: <code style="background: #f0f0f0; padding: 0.25rem 0.5rem; border-radius: 3px;"><?php echo realpath(__DIR__ . '/../mpesa_callback.php') ?: 'FILE NOT FOUND'; ?></code></li>
                <li>✓ File is readable by web server (check permissions)</li>
                <li>✓ URL is publicly accessible (test from external device)</li>
                <li>✓ No typos in the callback URL</li>
                <li>✓ Both database AND Daraja portal have the same URL</li>
            </ul>

            <h3>Testing Remote Accessibility</h3>
            <p>Visit this URL in your browser to test:</p>
            <div class="code-block"><a href="<?php echo $correct_callback_url; ?>" target="_blank"><?php echo $correct_callback_url; ?></a></div>
            <p style="margin-top: 1rem; color: #666; font-size: 0.9rem;">
                You should see "Invalid JSON" or "Callback received" - NOT a 404 Page Not Found
            </p>
        </div>

        <!-- Back Button -->
        <div style="margin-top: 2rem; text-align: center;">
            <a href="system_settings.php" style="background: #6c757d; color: white; padding: 0.75rem 1.5rem; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-arrow-left"></i> Back to Settings
            </a>
        </div>
    </div>

    <script>
        function copyToClipboard(elementId) {
            const element = document.getElementById(elementId);
            const text = element.textContent;
            
            navigator.clipboard.writeText(text).then(() => {
                const btn = event.target.closest('button');
                const original = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                setTimeout(() => {
                    btn.innerHTML = original;
                }, 2000);
            });
        }
    </script>
</body>
</html>
