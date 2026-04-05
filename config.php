<?php
if (!function_exists('initializeSessionStorage')) {
    function initializeSessionStorage(): void {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $currentPath = (string) session_save_path();
        $candidatePaths = [];

        if ($currentPath !== '') {
            $candidatePaths[] = $currentPath;
        }

        $candidatePaths[] = sys_get_temp_dir();

        $writablePath = '';
        foreach ($candidatePaths as $path) {
            $path = trim((string) $path);
            if ($path !== '' && is_dir($path) && is_writable($path)) {
                $writablePath = $path;
                break;
            }
        }

        if ($writablePath === '') {
            $projectSessionPath = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';
            if (!is_dir($projectSessionPath)) {
                @mkdir($projectSessionPath, 0777, true);
            }
            if (is_dir($projectSessionPath) && is_writable($projectSessionPath)) {
                $writablePath = $projectSessionPath;
            }
        }

        if ($writablePath !== '') {
            session_save_path($writablePath);
        }
    }
}

initializeSessionStorage();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('loadEnvironmentFile')) {
    function loadEnvironmentFile(string $filePath): void {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            if (getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

if (!function_exists('envValue')) {
    function envValue(string $key, $default = '') {
        $value = getenv($key);
        if ($value === false && array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];
        }
        if ($value === false && array_key_exists($key, $_SERVER)) {
            $value = $_SERVER[$key];
        }
        return $value === false ? $default : $value;
    }
}

loadEnvironmentFile(__DIR__ . '/.env');

// Database configuration
if (!defined('DB_HOST')) define('DB_HOST', envValue('DB_HOST', 'localhost'));
if (!defined('DB_NAME')) define('DB_NAME', envValue('DB_NAME', 'mariango_school'));
if (!defined('DB_USER')) define('DB_USER', envValue('DB_USER', 'root'));
if (!defined('DB_PASS')) define('DB_PASS', envValue('DB_PASS', ''));

// M-Pesa Configuration
// TODO: Replace with your actual credentials from https://developer.safaricom.co.ke/
if (!defined('MPESA_CONSUMER_KEY')) define('MPESA_CONSUMER_KEY', envValue('MPESA_CONSUMER_KEY', ''));
if (!defined('MPESA_CONSUMER_SECRET')) define('MPESA_CONSUMER_SECRET', envValue('MPESA_CONSUMER_SECRET', ''));
if (!defined('MPESA_SHORTCODE')) define('MPESA_SHORTCODE', envValue('MPESA_SHORTCODE', '')); // Your paybill/till number
if (!defined('MPESA_PASSKEY')) define('MPESA_PASSKEY', envValue('MPESA_PASSKEY', '')); // Get from Daraja portal

if (!function_exists('isLocalRequestHost')) {
    function isLocalRequestHost(?string $host): bool {
        $normalizedHost = strtolower(trim((string) $host));
        if ($normalizedHost === '') {
            return false;
        }

        $hostOnly = explode(':', $normalizedHost)[0];
        if (in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        return str_ends_with($hostOnly, '.local');
    }
}

if (!function_exists('buildApplicationUrl')) {
    function buildApplicationUrl(string $path = ''): string {
        $configuredAppUrl = trim((string) envValue('APP_URL', ''));
        $requestHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $protocol = (
            isset($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] !== '' &&
            $_SERVER['HTTPS'] !== 'off'
        ) ? 'https' : 'http';

        if ($configuredAppUrl !== '' && !isLocalRequestHost($requestHost)) {
            $baseUrl = rtrim($configuredAppUrl, '/');
        } else {
            if (function_exists('getApplicationBasePath')) {
                $applicationBasePath = getApplicationBasePath();
            } else {
                $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
                $basePath = rtrim(dirname($scriptName), '/\\');
                $projectBase = preg_replace('#/(admin|teacher|accountant|librarian)$#', '', $basePath);
                if ($projectBase === '' || $projectBase === '.') {
                    $projectBase = '/mariango_school';
                }
                $applicationBasePath = rtrim($projectBase, '/');
            }

            $baseUrl = $protocol . '://' . $requestHost . $applicationBasePath;
        }

        if ($path === '') {
            return $baseUrl;
        }

        return $baseUrl . '/' . ltrim($path, '/');
    }
}

// Base application URL for links sent in emails and external callbacks.
$base_url = buildApplicationUrl();

if (!defined('MPESA_CALLBACK_URL')) define('MPESA_CALLBACK_URL', $base_url . '/mpesa_callback.php');
if (!defined('MPESA_ENVIRONMENT')) define('MPESA_ENVIRONMENT', envValue('MPESA_ENVIRONMENT', 'production'));
if (!defined('BANK_API_ENDPOINT')) define('BANK_API_ENDPOINT', envValue('BANK_API_ENDPOINT', ''));
if (!defined('BANK_API_KEY')) define('BANK_API_KEY', envValue('BANK_API_KEY', ''));
if (!defined('BANK_API_SECRET')) define('BANK_API_SECRET', envValue('BANK_API_SECRET', ''));
if (!defined('BANK_API_MODE')) define('BANK_API_MODE', envValue('BANK_API_MODE', 'production'));
if (!defined('INVENTORY_MPESA_PAYOUT_MODE')) define('INVENTORY_MPESA_PAYOUT_MODE', envValue('INVENTORY_MPESA_PAYOUT_MODE', 'production'));

// School information
if (!defined('SCHOOL_NAME')) define('SCHOOL_NAME', 'Mariango Primary School');
if (!defined('SCHOOL_LOCATION')) define('SCHOOL_LOCATION', 'Kilifi, Kenya');
if (!defined('SCHOOL_LOGO')) define('SCHOOL_LOGO', 'school_logo_1774013364.jpeg');
if (!defined('CURRENCY')) define('CURRENCY', 'KES');

// Add these to your existing config.php
if (!defined('SMTP_HOST')) define('SMTP_HOST', envValue('SMTP_HOST', 'smtp.gmail.com'));
if (!defined('SMTP_PORT')) define('SMTP_PORT', envValue('SMTP_PORT', 587));
if (!defined('SMTP_USERNAME')) define('SMTP_USERNAME', envValue('SMTP_USERNAME', ''));
if (!defined('SMTP_PASSWORD')) define('SMTP_PASSWORD', envValue('SMTP_PASSWORD', ''));
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', envValue('SMTP_FROM_EMAIL', ''));
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', SCHOOL_NAME . ' System');

// Create database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    exit('A system error occurred. Please contact the administrator.');
}

if (!function_exists('ensureVisitorLogsTableReady')) {
    function ensureVisitorLogsTableReady(PDO $pdo): void {
        static $ready = false;

        if ($ready) {
            return;
        }

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS visitor_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    session_token VARCHAR(128) NOT NULL UNIQUE,
                    visitor_type ENUM('guest', 'authenticated') NOT NULL DEFAULT 'guest',
                    user_id INT NULL,
                    full_name VARCHAR(191) NULL,
                    ip_address VARCHAR(45) NOT NULL,
                    user_agent TEXT NULL,
                    device_type VARCHAR(50) NOT NULL DEFAULT 'Desktop',
                    browser VARCHAR(100) NOT NULL DEFAULT 'Unknown Browser',
                    operating_system VARCHAR(100) NOT NULL DEFAULT 'Unknown OS',
                    first_page VARCHAR(255) NOT NULL,
                    last_page VARCHAR(255) NOT NULL,
                    referrer VARCHAR(255) NULL,
                    page_views INT NOT NULL DEFAULT 1,
                    first_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY idx_last_seen (last_seen),
                    KEY idx_user_id (user_id),
                    KEY idx_ip_address (ip_address)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $ready = true;
        } catch (Throwable $e) {
            error_log('Failed to ensure visitor_logs table: ' . $e->getMessage());
        }
    }
}

if (!function_exists('getVisitorIpAddress')) {
    function getVisitorIpAddress(): string {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['HTTP_CLIENT_IP'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            foreach (explode(',', $candidate) as $part) {
                $ip = trim($part);
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return 'Unknown';
    }
}

if (!function_exists('parseVisitorDeviceDetails')) {
    function parseVisitorDeviceDetails(string $userAgent): array {
        $ua = strtolower($userAgent);

        $deviceType = 'Desktop';
        if ($ua === '') {
            $deviceType = 'Unknown Device';
        } elseif (strpos($ua, 'tablet') !== false || strpos($ua, 'ipad') !== false) {
            $deviceType = 'Tablet';
        } elseif (
            strpos($ua, 'mobile') !== false ||
            strpos($ua, 'android') !== false ||
            strpos($ua, 'iphone') !== false
        ) {
            $deviceType = 'Mobile';
        }

        $operatingSystem = 'Unknown OS';
        $osMatchers = [
            'Windows 11' => ['windows nt 10.0'],
            'Windows 10' => ['windows nt 10.0'],
            'Windows 8.1' => ['windows nt 6.3'],
            'Windows 8' => ['windows nt 6.2'],
            'Windows 7' => ['windows nt 6.1'],
            'Android' => ['android'],
            'iPhone' => ['iphone'],
            'iPadOS' => ['ipad'],
            'macOS' => ['mac os x', 'macintosh'],
            'Linux' => ['linux'],
        ];

        foreach ($osMatchers as $label => $needles) {
            foreach ($needles as $needle) {
                if (strpos($ua, $needle) !== false) {
                    $operatingSystem = $label;
                    break 2;
                }
            }
        }

        if ($operatingSystem === 'Windows 11' && strpos($ua, 'windows nt 10.0') !== false) {
            $operatingSystem = 'Windows';
        }

        $browser = 'Unknown Browser';
        $browserMatchers = [
            'Edge' => ['edg/'],
            'Opera' => ['opr/', 'opera'],
            'Chrome' => ['chrome/'],
            'Firefox' => ['firefox/'],
            'Safari' => ['safari/'],
            'Internet Explorer' => ['msie', 'trident/'],
        ];

        foreach ($browserMatchers as $label => $needles) {
            foreach ($needles as $needle) {
                if (strpos($ua, $needle) !== false) {
                    if ($label === 'Safari' && strpos($ua, 'chrome/') !== false) {
                        continue;
                    }
                    $browser = $label;
                    break 2;
                }
            }
        }

        return [
            'device_type' => $deviceType,
            'operating_system' => $operatingSystem,
            'browser' => $browser,
        ];
    }
}

if (!function_exists('shouldTrackVisitorRequest')) {
    function shouldTrackVisitorRequest(): bool {
        if (PHP_SAPI === 'cli') {
            return false;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return false;
        }

        $scriptName = strtolower((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $blockedScripts = [
            'mpesa_callback.php',
            'resend_otp.php',
            'loader.php',
            'timetable_api.php',
            'get_teachers.php',
            'get_lesson_plan.php',
            'get_class_students.php',
            'get_class_subjects.php',
            'ajax_hnadler.php',
        ];

        foreach ($blockedScripts as $blockedScript) {
            if ($scriptName !== '' && str_ends_with($scriptName, '/' . $blockedScript)) {
                return false;
            }
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if ($accept !== '' && strpos($accept, 'text/html') === false && strpos($accept, '*/*') === false) {
            return false;
        }

        return true;
    }
}

if (!function_exists('trackVisitorRequest')) {
    function trackVisitorRequest(PDO $pdo): void {
        if (!shouldTrackVisitorRequest()) {
            return;
        }

        ensureVisitorLogsTableReady($pdo);

        try {
            if (!isset($_SESSION['visitor_session_token']) || !is_string($_SESSION['visitor_session_token'])) {
                $_SESSION['visitor_session_token'] = bin2hex(random_bytes(32));
            }

            $sessionToken = $_SESSION['visitor_session_token'];
            $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
            $queryString = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
            $currentPage = $scriptName . ($queryString !== '' ? '?' . $queryString : '');
            $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);
            $referrer = substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 255);
            $ipAddress = getVisitorIpAddress();
            $deviceDetails = parseVisitorDeviceDetails($userAgent);
            $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $fullName = trim((string) ($_SESSION['full_name'] ?? ''));
            $visitorType = $userId ? 'authenticated' : 'guest';

            $stmt = $pdo->prepare("
                INSERT INTO visitor_logs (
                    session_token,
                    visitor_type,
                    user_id,
                    full_name,
                    ip_address,
                    user_agent,
                    device_type,
                    browser,
                    operating_system,
                    first_page,
                    last_page,
                    referrer,
                    page_views
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    visitor_type = VALUES(visitor_type),
                    user_id = VALUES(user_id),
                    full_name = VALUES(full_name),
                    ip_address = VALUES(ip_address),
                    user_agent = VALUES(user_agent),
                    device_type = VALUES(device_type),
                    browser = VALUES(browser),
                    operating_system = VALUES(operating_system),
                    last_page = VALUES(last_page),
                    referrer = VALUES(referrer),
                    page_views = page_views + 1,
                    last_seen = CURRENT_TIMESTAMP
            ");

            $stmt->execute([
                $sessionToken,
                $visitorType,
                $userId,
                $fullName !== '' ? $fullName : null,
                $ipAddress,
                $userAgent !== '' ? $userAgent : null,
                $deviceDetails['device_type'],
                $deviceDetails['browser'],
                $deviceDetails['operating_system'],
                substr($currentPage !== '' ? $currentPage : '/', 0, 255),
                substr($currentPage !== '' ? $currentPage : '/', 0, 255),
                $referrer !== '' ? $referrer : null,
            ]);
        } catch (Throwable $e) {
            error_log('Failed to track visitor request: ' . $e->getMessage());
        }
    }
}

trackVisitorRequest($pdo);

// Settings cache
$_SETTINGS_CACHE = [];
$_SETTINGS_LOADED = false;
$_SETTINGS_TABLE_READY = false;

if (!function_exists('ensureSettingsTableReady')) {
    function ensureSettingsTableReady(): void {
        global $pdo, $_SETTINGS_TABLE_READY;

        if ($_SETTINGS_TABLE_READY) {
            return;
        }

        try {
            $tableExists = $pdo->query("SHOW TABLES LIKE 'settings'")->fetchColumn();
            if (!$tableExists) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `skey` VARCHAR(191) NOT NULL UNIQUE,
                    `svalue` LONGTEXT NOT NULL,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            }

            $_SETTINGS_TABLE_READY = true;
        } catch (Exception $e) {
            error_log("Error ensuring settings table: " . $e->getMessage());
        }
    }
}

// Function to get settings from database
if (!function_exists('getSystemSetting')) {
    function getSystemSetting($key, $default = '') {
        global $pdo, $_SETTINGS_CACHE, $_SETTINGS_LOADED;
        
        // Load settings once per request
        if (!$_SETTINGS_LOADED) {
            try {
                ensureSettingsTableReady();
                $stmt = $pdo->query("SELECT skey, svalue FROM settings");
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($results as $row) {
                    $_SETTINGS_CACHE[$row['skey']] = $row['svalue'];
                }
                $_SETTINGS_LOADED = true;
            } catch (Exception $e) {
                error_log("Error loading settings: " . $e->getMessage());
            }
        }
        
        return isset($_SETTINGS_CACHE[$key]) ? $_SETTINGS_CACHE[$key] : $default;
    }
}

// Get dynamic settings
$DYNAMIC_SCHOOL_NAME = getSystemSetting('school_name', SCHOOL_NAME);
$DYNAMIC_SCHOOL_LOGO = getSystemSetting('school_logo', SCHOOL_LOGO);

if (!function_exists('getDynamicFaviconUrl')) {
    function getDynamicFaviconUrl(): string {
        $logo = trim((string) getSystemSetting('school_logo', SCHOOL_LOGO));
        $projectBase = getApplicationBasePath();

        $fallbackUrl = $projectBase . '/logo.png';
        $fallbackPath = __DIR__ . '/logo.png';

        if ($logo === '') {
            $version = is_file($fallbackPath) ? filemtime($fallbackPath) : time();
            return $fallbackUrl . '?v=' . $version;
        }

        $logoFile = basename($logo);
        $logoPath = __DIR__ . '/uploads/logos/' . $logoFile;
        if (is_file($logoPath)) {
            return $projectBase . '/uploads/logos/' . rawurlencode($logoFile) . '?v=' . filemtime($logoPath);
        }

        $version = is_file($fallbackPath) ? filemtime($fallbackPath) : time();
        return $fallbackUrl . '?v=' . $version;
    }
}

if (!function_exists('saveSystemSetting')) {
    function saveSystemSetting(string $key, string $value): bool {
        global $pdo, $_SETTINGS_CACHE, $_SETTINGS_LOADED;

        try {
            ensureSettingsTableReady();

            $stmt = $pdo->prepare("
                INSERT INTO settings (skey, svalue)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = CURRENT_TIMESTAMP
            ");
            $saved = $stmt->execute([$key, $value]);
            if ($saved) {
                $_SETTINGS_CACHE[$key] = $value;
                $_SETTINGS_LOADED = true;
            }
            return $saved;
        } catch (Throwable $e) {
            error_log("Failed to save system setting {$key}: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('ensureAcademicCalendarSchema')) {
    function ensureAcademicCalendarSchema(PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS academic_years (
                id INT AUTO_INCREMENT PRIMARY KEY,
                year VARCHAR(20) NOT NULL UNIQUE,
                is_active TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $academicYearColumns = [];
        try {
            $academicYearColumns = $pdo->query("SHOW COLUMNS FROM academic_years")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $academicYearColumns = [];
        }

        if (!in_array('is_active', $academicYearColumns, true)) {
            $pdo->exec("ALTER TABLE academic_years ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 0 AFTER year");
        }

        if (!in_array('updated_at', $academicYearColumns, true)) {
            $pdo->exec("ALTER TABLE academic_years ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        }

        if (in_array('status', $academicYearColumns, true)) {
            $pdo->exec("
                UPDATE academic_years
                SET is_active = CASE
                    WHEN LOWER(COALESCE(status, 'inactive')) = 'active' THEN 1
                    ELSE 0
                END
            ");
        }

        try {
            $indexCheck = $pdo->query("SHOW INDEX FROM academic_years WHERE Key_name = 'uniq_academic_year'")->fetch();
            if (!$indexCheck) {
                $pdo->exec("ALTER TABLE academic_years ADD UNIQUE KEY uniq_academic_year (year)");
            }
        } catch (Throwable $e) {
            // Leave existing indexes untouched if the table already enforces uniqueness another way.
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS academic_terms (
                id INT AUTO_INCREMENT PRIMARY KEY,
                academic_year_id INT NULL,
                academic_year_label VARCHAR(30) NOT NULL,
                term_name VARCHAR(50) NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                status ENUM('upcoming','active','closed') NOT NULL DEFAULT 'upcoming',
                progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                closed_at DATETIME NULL,
                closed_by INT NULL,
                created_by INT NULL,
                updated_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_term_period (academic_year_label, term_name),
                KEY idx_term_status (status),
                KEY idx_term_dates (start_date, end_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $academicTermColumns = [];
        try {
            $academicTermColumns = $pdo->query("SHOW COLUMNS FROM academic_terms")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $academicTermColumns = [];
        }

        $termColumnSql = [
            'academic_year_label' => "ALTER TABLE academic_terms ADD COLUMN academic_year_label VARCHAR(30) NOT NULL AFTER academic_year_id",
            'progress_percent' => "ALTER TABLE academic_terms ADD COLUMN progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER status",
            'closed_at' => "ALTER TABLE academic_terms ADD COLUMN closed_at DATETIME NULL AFTER progress_percent",
            'closed_by' => "ALTER TABLE academic_terms ADD COLUMN closed_by INT NULL AFTER closed_at",
            'created_by' => "ALTER TABLE academic_terms ADD COLUMN created_by INT NULL AFTER closed_by",
            'updated_by' => "ALTER TABLE academic_terms ADD COLUMN updated_by INT NULL AFTER created_by",
            'updated_at' => "ALTER TABLE academic_terms ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];

        foreach ($termColumnSql as $column => $sql) {
            if (!in_array($column, $academicTermColumns, true)) {
                $pdo->exec($sql);
            }
        }

        if (in_array('academic_year_label', $academicTermColumns, true) || array_key_exists('academic_year_label', $termColumnSql)) {
            $pdo->exec("
                UPDATE academic_terms t
                LEFT JOIN academic_years y ON y.id = t.academic_year_id
                SET t.academic_year_label = COALESCE(NULLIF(t.academic_year_label, ''), y.year, '')
                WHERE t.academic_year_label IS NULL OR t.academic_year_label = ''
            ");
        }
    }
}

if (!function_exists('getApplicationBasePath')) {
    function getApplicationBasePath(): string {
        $configuredAppUrl = trim((string) envValue('APP_URL', ''));
        $requestHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
        $basePath = rtrim(dirname($scriptName), '/\\');
        $projectBase = preg_replace('#/(admin|teacher|accountant|librarian)$#', '', $basePath);

        if ($projectBase === '' || $projectBase === '.') {
            if ($configuredAppUrl !== '' && !isLocalRequestHost($requestHost)) {
                $configuredPath = (string) parse_url($configuredAppUrl, PHP_URL_PATH);
                $configuredPath = rtrim($configuredPath, '/');
                $projectBase = $configuredPath === '/' ? '' : $configuredPath;
            } else {
                $projectBase = '';
            }
        }

        return $projectBase === '/' ? '' : rtrim($projectBase, '/');
    }
}

if (!function_exists('buildApplicationPath')) {
    function buildApplicationPath(string $path): string {
        return getApplicationBasePath() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('systemSetupRequired')) {
    function systemSetupRequired(): bool {
        global $pdo;

        $setupCompleted = strtolower(trim((string) getSystemSetting('setup_completed', '0')));
        $schoolName = trim((string) getSystemSetting('school_name', SCHOOL_NAME));
        $adminEmail = trim((string) getSystemSetting('setup_admin_email', ''));
        $hasConfiguredSchool = $schoolName !== '';
        $hasAdminEmail = $adminEmail !== '';

        $hasAdminUser = false;
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM users
                WHERE role = 'admin'
                  AND status = 'active'
                  AND (? = '' OR email = ?)
            ");
            $stmt->execute([$adminEmail, $adminEmail]);
            $hasAdminUser = ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            $hasAdminUser = false;
        }

        if (in_array($setupCompleted, ['1', 'true', 'yes', 'on'], true) && $hasConfiguredSchool && $hasAdminEmail && $hasAdminUser) {
            return false;
        }

        return !$hasConfiguredSchool || !$hasAdminEmail || !$hasAdminUser;
    }
}

if (!function_exists('fetchAcademicTermStatus')) {
    function fetchAcademicTermStatus(PDO $pdo): array {
        ensureAcademicCalendarSchema($pdo);

        $today = date('Y-m-d');
        $activeStmt = $pdo->prepare("
            SELECT *
            FROM academic_terms
            WHERE status = 'active'
            ORDER BY start_date ASC, id ASC
        ");
        $activeStmt->execute();
        $activeTerms = $activeStmt->fetchAll(PDO::FETCH_ASSOC);

        $upcomingStmt = $pdo->prepare("
            SELECT *
            FROM academic_terms
            WHERE status = 'upcoming'
            ORDER BY start_date ASC, id ASC
            LIMIT 1
        ");
        $upcomingStmt->execute();
        $nextTerm = $upcomingStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $currentTerm = null;
        foreach ($activeTerms as $term) {
            if ($today >= $term['start_date'] && $today <= $term['end_date']) {
                $currentTerm = $term;
                break;
            }
        }
        if (!$currentTerm && !empty($activeTerms)) {
            $currentTerm = $activeTerms[0];
        }

        if ($currentTerm) {
            $start = strtotime((string) $currentTerm['start_date']);
            $end = strtotime((string) $currentTerm['end_date']);
            $todayTs = strtotime($today);
            $totalDays = max(1, (int) floor(($end - $start) / 86400) + 1);
            $elapsedDays = min($totalDays, max(0, (int) floor(($todayTs - $start) / 86400) + 1));
            $progress = max(0, min(100, round(($elapsedDays / $totalDays) * 100, 2)));
            $currentTerm['progress_percent'] = $progress;
            $currentTerm['days_total'] = $totalDays;
            $currentTerm['days_elapsed'] = $elapsedDays;
            $currentTerm['days_remaining'] = max(0, $totalDays - $elapsedDays);
        }

        return [
            'today' => $today,
            'current_term' => $currentTerm,
            'next_term' => $nextTerm,
            'term_setup_required' => !$currentTerm && !$nextTerm,
        ];
    }
}

if (!function_exists('ensureAcademicLifecycle')) {
    function ensureAcademicLifecycle(PDO $pdo): void {
        ensureAcademicCalendarSchema($pdo);

        $today = date('Y-m-d');
        $activeTerms = $pdo->query("
            SELECT *
            FROM academic_terms
            WHERE status = 'active'
            ORDER BY start_date ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($activeTerms as $term) {
            $start = strtotime((string) $term['start_date']);
            $end = strtotime((string) $term['end_date']);
            $todayTs = strtotime($today);
            $totalDays = max(1, (int) floor(($end - $start) / 86400) + 1);
            $elapsedDays = min($totalDays, max(0, (int) floor(($todayTs - $start) / 86400) + 1));
            $progress = max(0, min(100, round(($elapsedDays / $totalDays) * 100, 2)));

            $pdo->prepare("UPDATE academic_terms SET progress_percent = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$progress, (int) $term['id']]);

            if ($progress >= 100 || $today > $term['end_date']) {
                $pdo->prepare("
                    UPDATE academic_terms
                    SET status = 'closed', progress_percent = 100, closed_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([(int) $term['id']]);

                saveSystemSetting('current_term_name', '');
                saveSystemSetting('term_setup_required', '1');

                $notificationFlag = 'term_closed_notified_' . (int) $term['id'];
                if (getSystemSetting($notificationFlag, '0') !== '1') {
                    $title = 'Academic Term Closed';
                    $message = ($term['term_name'] ?? 'Current term') . ' for ' . ($term['academic_year_label'] ?? 'the academic year') . ' has reached 100% and is now closed. Waiting for the admin to enter the next term dates.';
                    createRoleNotification($title, $message, 'system', ['admin', 'teacher', 'accountant', 'librarian'], 'high', (int) $term['id'], 'academic_term');
                    saveSystemSetting($notificationFlag, '1');
                }
            }
        }

        $activeCount = (int) $pdo->query("SELECT COUNT(*) FROM academic_terms WHERE status = 'active'")->fetchColumn();
        if ($activeCount === 0) {
            $upcomingStmt = $pdo->prepare("
                SELECT *
                FROM academic_terms
                WHERE status = 'upcoming' AND start_date <= ?
                ORDER BY start_date ASC, id ASC
                LIMIT 1
            ");
            $upcomingStmt->execute([$today]);
            $nextTerm = $upcomingStmt->fetch(PDO::FETCH_ASSOC);

            if ($nextTerm) {
                $pdo->prepare("
                    UPDATE academic_terms
                    SET status = 'active', progress_percent = 0, updated_at = NOW()
                    WHERE id = ?
                ")->execute([(int) $nextTerm['id']]);

                $pdo->prepare("UPDATE academic_years SET is_active = 0")->execute();
                if (!empty($nextTerm['academic_year_id'])) {
                    $pdo->prepare("UPDATE academic_years SET is_active = 1 WHERE id = ?")
                        ->execute([(int) $nextTerm['academic_year_id']]);
                }

                saveSystemSetting('academic_year', (string) ($nextTerm['academic_year_label'] ?? ''));
                saveSystemSetting('current_term_name', (string) ($nextTerm['term_name'] ?? ''));
                saveSystemSetting('term_setup_required', '0');

                $notificationFlag = 'term_started_notified_' . (int) $nextTerm['id'];
                if (getSystemSetting($notificationFlag, '0') !== '1') {
                    $title = 'New Academic Term Started';
                    $message = ($nextTerm['term_name'] ?? 'A new term') . ' for ' . ($nextTerm['academic_year_label'] ?? 'the academic year') . ' is now active.';
                    createRoleNotification($title, $message, 'system', ['admin', 'teacher', 'accountant', 'librarian'], 'high', (int) $nextTerm['id'], 'academic_term');
                    saveSystemSetting($notificationFlag, '1');
                }
            }
        }
    }
}

if (!function_exists('getDynamicFaviconTag')) {
    function getDynamicFaviconTag(): string {
        $faviconUrl = htmlspecialchars(getDynamicFaviconUrl(), ENT_QUOTES, 'UTF-8');
        return '<link rel="icon" type="image/png" href="' . $faviconUrl . '">' . "\n";
    }
}

if (!function_exists('injectDynamicFaviconIntoHtml')) {
    function injectDynamicFaviconIntoHtml(string $buffer): string {
        if (stripos($buffer, '<head') === false || stripos($buffer, '</head>') === false) {
            return $buffer;
        }

        $buffer = preg_replace('#<link\s+rel=["\']icon["\'][^>]*>\s*#i', '', $buffer);
        return preg_replace('#</head>#i', getDynamicFaviconTag() . '</head>', $buffer, 1) ?? $buffer;
    }
}

if (!function_exists('getSweetAlertHeadAssets')) {
    function getSweetAlertHeadAssets(): string {
        return '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">' . "\n";
    }
}

if (!function_exists('getSweetAlertBodyAssets')) {
    function getSweetAlertBodyAssets(): string {
        return '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>' . "\n";
    }
}

if (!function_exists('renderGlobalLoaderHtml')) {
    function renderGlobalLoaderHtml(): string {
        global $pdo;

        if (PHP_SAPI === 'cli') {
            return '';
        }

        ob_start();
        try {
            include __DIR__ . '/loader.php';
        } catch (Throwable $e) {
            ob_end_clean();
            error_log('Failed to render global loader: ' . $e->getMessage());
            return '';
        }
        return (string) ob_get_clean();
    }
}

if (!function_exists('injectSharedUiAssetsIntoHtml')) {
    function injectSharedUiAssetsIntoHtml(string $buffer): string {
        if (stripos($buffer, '<html') === false) {
            return $buffer;
        }

        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        if (
            stripos($scriptName, 'maintenance.php') !== false ||
            stripos($scriptName, 'messages.php') !== false ||
            stripos($scriptName, 'website.php') !== false ||
            stripos($scriptName, 'notifications.php') !== false ||
            stripos($scriptName, 'academic_calendar.php') !== false ||
            stripos($scriptName, 'login.php') !== false ||
            stripos($scriptName, 'verify_account.php') !== false ||
            stripos($scriptName, 'visitor_logs.php') !== false ||
            stripos($scriptName, 'setup_wizard.php') !== false ||
            stripos($scriptName, 'payment_receipt.php') !== false ||
            stripos($scriptName, 'print_invoice.php') !== false ||
            stripos($scriptName, 'timetable_printer.php') !== false ||
            stripos($scriptName, 'timetable_export.php') !== false
        ) {
            return injectDynamicFaviconIntoHtml($buffer);
        }

        $buffer = injectDynamicFaviconIntoHtml($buffer);

        if (stripos($buffer, 'sweetalert2') === false && stripos($buffer, '</head>') !== false) {
            $buffer = preg_replace('#</head>#i', getSweetAlertHeadAssets() . '</head>', $buffer, 1) ?? $buffer;
        }

        if (stripos($buffer, 'sweetalert2@11') === false && stripos($buffer, '</body>') !== false) {
            $buffer = preg_replace('#</body>#i', getSweetAlertBodyAssets() . '</body>', $buffer, 1) ?? $buffer;
        }

        if (stripos($buffer, 'id="global-loader"') === false && stripos($buffer, '<body') !== false) {
            $loaderHtml = renderGlobalLoaderHtml();
            $buffer = preg_replace('#<body([^>]*)>#i', '<body$1>' . "\n" . $loaderHtml, $buffer, 1) ?? $buffer;
        }

        return $buffer;
    }
}

if (!defined('DYNAMIC_FAVICON_BUFFER_STARTED') && PHP_SAPI !== 'cli') {
    define('DYNAMIC_FAVICON_BUFFER_STARTED', true);
    ob_start('injectSharedUiAssetsIntoHtml');
}

// Authentication check function
if (!function_exists('checkAuth')) {
    function checkAuth() {
        global $pdo;

        ensureAcademicCalendarSchema($pdo);
        ensureAcademicLifecycle($pdo);

        if (!isset($_SESSION['user_id'])) {
            header("Location: index.php");
            exit();
        }

        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $onSetupPage = stripos($scriptName, 'setup_wizard.php') !== false;
        if (systemSetupRequired() && !$onSetupPage) {
            if (($_SESSION['user_role'] ?? '') === 'admin') {
                header('Location: ' . buildApplicationPath('setup_wizard.php'));
            } else {
                session_unset();
                session_destroy();
                header('Location: ' . buildApplicationPath('setup_wizard.php'));
            }
            exit();
        }
        
        // Check maintenance mode for all authenticated users
        // Redirect non-admin users to maintenance page
        $maintenance_mode = getSystemSetting('maintenance_mode', 'off') === 'on';
        if ($maintenance_mode && isset($_SESSION['user_role']) && $_SESSION['user_role'] !== 'admin') {
            // Determine the correct path to maintenance.php based on current directory
            $script_path = $_SERVER['SCRIPT_NAME'];
            if (strpos($script_path, '/admin/') !== false || 
                strpos($script_path, '/teacher/') !== false || 
                strpos($script_path, '/accountant/') !== false || 
                strpos($script_path, '/librarian/') !== false) {
                header("Location: ../maintenance.php");
            } else {
                header("Location: maintenance.php");
            }
            exit();
        }
    }
}

// Production-safe error handling
if (!defined('DEBUG_MODE')) define('DEBUG_MODE', false);
error_reporting(E_ALL);
ini_set('display_errors', DEBUG_MODE ? 1 : 0);
ini_set('display_startup_errors', DEBUG_MODE ? 1 : 0);
ini_set('log_errors', '1');

// Role-based access control
if (!function_exists('checkRole')) {
    function checkRole($allowed_roles) {
        if (!in_array($_SESSION['user_role'], $allowed_roles)) {
            header("Location: unauthorized.php");
            exit();
        }
    }
}

// Format currency
if (!function_exists('formatCurrency')) {
    function formatCurrency($amount) {
        return CURRENCY . ' ' . number_format($amount, 2);
    }
}

// Helper function to send maintenance notification emails
if (!function_exists('sendMaintenanceNotification')) {
    function sendMaintenanceNotification($pdo, $message = '') {
        try {
            $message = trim($message) ?: 'The system will be unavailable while scheduled maintenance is being carried out.';
            createNotification(
                'Scheduled Maintenance',
                $message,
                'maintenance',
                null,
                null,
                'high'
            );

            require_once 'PHPMailer/PHPMailer.php';
            require_once 'PHPMailer/SMTP.php';
            require_once 'PHPMailer/Exception.php';
            
            // Get all active users
            $stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE status = 'active' AND email IS NOT NULL AND email != ''");
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($users)) return;
            
            $smtp_host = getSystemSetting('smtp_host', SMTP_HOST);
            $smtp_port = getSystemSetting('smtp_port', SMTP_PORT);
            $smtp_user = getSystemSetting('smtp_user', SMTP_USERNAME);
            $smtp_pass = getSystemSetting('smtp_pass', SMTP_PASSWORD);
            $from_email = getSystemSetting('from_email', SMTP_FROM_EMAIL);
            $from_name = getSystemSetting('from_name', SMTP_FROM_NAME);
            $school_name = getSystemSetting('school_name', SCHOOL_NAME);
        
        foreach ($users as $user) {
            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $smtp_host;
                $mail->Port = $smtp_port;
                $mail->SMTPAuth = true;
                $mail->Username = $smtp_user;
                $mail->Password = $smtp_pass;
                $mail->SMTPSecure = getSystemSetting('smtp_encryption', 'tls');
                
                $mail->setFrom($from_email, $from_name);
                $mail->addAddress($user['email'], $user['full_name']);
                $mail->Subject = $school_name . ' - Scheduled Maintenance';
                
                $mail->isHTML(true);
                $mail->Body = "
                <p>Dear {$user['full_name']},</p>
                <p>We are writing to inform you that <strong>{$school_name}</strong> will be undergoing scheduled maintenance.</p>
                <p><strong>Message:</strong></p>
                <p>" . htmlspecialchars($message) . "</p>
                <p>During this time, you will not be able to access the system. We apologize for any inconvenience.</p>
                <p>Thank you for your patience and understanding.</p>
                <p>Best regards,<br>{$school_name} Management</p>
                ";
                
                $mail->send();
            } catch (Exception $e) {
                error_log("Failed to send maintenance notification to {$user['email']}: " . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        error_log("Error in sendMaintenanceNotification: " . $e->getMessage());
    }
}

// Helper function to send system back online notification
if (!function_exists('sendSystemBackOnlineNotification')) {
    function sendSystemBackOnlineNotification($pdo) {
        try {
            createNotification(
                'Maintenance Completed',
                'Scheduled maintenance has been completed and the system is back online.',
                'maintenance_complete',
                null,
                null,
                'high'
            );

            require_once 'PHPMailer/PHPMailer.php';
            require_once 'PHPMailer/SMTP.php';
            require_once 'PHPMailer/Exception.php';
        
        // Get all active users
        $stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE status = 'active' AND email IS NOT NULL AND email != ''");
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($users)) return;
        
        $smtp_host = getSystemSetting('smtp_host', SMTP_HOST);
        $smtp_port = getSystemSetting('smtp_port', SMTP_PORT);
        $smtp_user = getSystemSetting('smtp_user', SMTP_USERNAME);
        $smtp_pass = getSystemSetting('smtp_pass', SMTP_PASSWORD);
        $from_email = getSystemSetting('from_email', SMTP_FROM_EMAIL);
        $from_name = getSystemSetting('from_name', SMTP_FROM_NAME);
        $school_name = getSystemSetting('school_name', SCHOOL_NAME);
        
        foreach ($users as $user) {
            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $smtp_host;
                $mail->Port = $smtp_port;
                $mail->SMTPAuth = true;
                $mail->Username = $smtp_user;
                $mail->Password = $smtp_pass;
                $mail->SMTPSecure = getSystemSetting('smtp_encryption', 'tls');
                
                $mail->setFrom($from_email, $from_name);
                $mail->addAddress($user['email'], $user['full_name']);
                $mail->Subject = $school_name . ' - System Is Back Online';
                
                $mail->isHTML(true);
                $mail->Body = "
                <p>Dear {$user['full_name']},</p>
                <p>Good news! <strong>{$school_name}</strong> system is now back online and ready to use.</p>
                <p>Thank you for your patience during the maintenance period.</p>
                <p>If you experience any issues, please contact the system administrator.</p>
                <p>Best regards,<br>{$school_name} Management</p>
                ";
                
                $mail->send();
            } catch (Exception $e) {
                error_log("Failed to send system online notification to {$user['email']}: " . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        error_log("Error in sendSystemBackOnlineNotification: " . $e->getMessage());
    }
    }
}
}

// Check and add reset password columns if they don't exist
try {
    $check_reset_token = $pdo->query("SHOW COLUMNS FROM users LIKE 'reset_token'")->fetch();
    if (!$check_reset_token) {
        $pdo->exec("ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) NULL AFTER password");
    }
    
    $check_reset_expires = $pdo->query("SHOW COLUMNS FROM users LIKE 'reset_expires'")->fetch();
    if (!$check_reset_expires) {
        $pdo->exec("ALTER TABLE users ADD COLUMN reset_expires DATETIME NULL AFTER reset_token");
    }
} catch (Exception $e) {
    error_log("Error checking/adding reset columns: " . $e->getMessage());
}

// Set timezone for PHP
date_default_timezone_set('Africa/Nairobi'); // Change to your timezone

// Set timezone for MySQL if using PDO
$pdo->exec("SET time_zone = '+03:00';"); // Change to your timezone offset.

// ==================== EXAM PORTAL HELPER FUNCTIONS ====================

// Check if exam portal is open
if (!function_exists('isExamPortalOpen')) {
    function isExamPortalOpen($exam_schedule_id) {
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT status, portal_open_date, portal_close_date FROM exam_schedules WHERE id = ?");
            $stmt->execute([$exam_schedule_id]);
            $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$schedule) return false;
            
            $now = new DateTime();
            $open_date = new DateTime($schedule['portal_open_date']);
            $close_date = new DateTime($schedule['portal_close_date']);
            
            // Hybrid approach: status column takes precedence if explicitly set
            $db_status = strtolower(trim($schedule['status'] ?? ''));
            if ($db_status === 'open') {
                return true;  // Explicitly opened
            } elseif ($db_status === 'closed') {
                return false;  // Explicitly closed
            }
            
            // Fall back to date-based check if status is 'scheduled' or null
            return $now >= $open_date && $now <= $close_date;
        } catch (Exception $e) {
            error_log("Error checking exam portal status: " . $e->getMessage());
            return false;
        }
    }
}

// Get exam grade based on marks
if (!function_exists('getGradeForMarks')) {
    function getGradeForMarks($marks) {
        global $pdo;
        try {
            $stmt = $pdo->prepare("
            SELECT grade FROM grade_mapping 
            WHERE min_marks <= ? AND max_marks >= ? 
            ORDER BY min_marks DESC LIMIT 1
        ");
        $stmt->execute([$marks, $marks]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['grade'] : 'F';
    } catch (Exception $e) {
        error_log("Error getting grade: " . $e->getMessage());
        return 'F';
    }
    }
}

if (!function_exists('examNormalizePortalDateTime')) {
    function examNormalizePortalDateTime($value, $isEnd = false) {
        if (empty($value)) {
            return null;
        }

        $value = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value . ($isEnd ? ' 23:59:59' : ' 00:00:00');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
            return str_replace('T', ' ', $value) . ':00';
        }

        return date('Y-m-d H:i:s', strtotime($value));
    }
}

if (!function_exists('examGetGradeScale')) {
    function examGetGradeScale() {
        global $pdo;

        static $scale = null;
        if ($scale !== null) {
            return $scale;
        }

        $defaultScale = [
            ['grade' => 'A', 'min_marks' => 80, 'max_marks' => 100],
            ['grade' => 'B', 'min_marks' => 70, 'max_marks' => 79.99],
            ['grade' => 'C', 'min_marks' => 60, 'max_marks' => 69.99],
            ['grade' => 'D', 'min_marks' => 50, 'max_marks' => 59.99],
            ['grade' => 'E', 'min_marks' => 40, 'max_marks' => 49.99],
            ['grade' => 'F', 'min_marks' => 0, 'max_marks' => 39.99],
        ];

        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'grade_mapping'");
            if ($stmt && $stmt->fetchColumn()) {
                $rows = $pdo->query("
                    SELECT grade, min_marks, max_marks
                    FROM grade_mapping
                    ORDER BY min_marks DESC, max_marks DESC
                ")->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    $scale = $rows;
                    return $scale;
                }
            }
        } catch (Exception $e) {
            error_log('Failed to load grade mapping: ' . $e->getMessage());
        }

        $scale = $defaultScale;
        return $scale;
    }
}

if (!function_exists('examResolveGrade')) {
    function examResolveGrade($marks, $totalMarks = 100) {
        $marks = (float) $marks;
        $totalMarks = max((float) $totalMarks, 1);
        $percentage = ($marks / $totalMarks) * 100;

        foreach (examGetGradeScale() as $band) {
            if ($percentage >= (float) $band['min_marks'] && $percentage <= (float) $band['max_marks']) {
                return (string) $band['grade'];
            }
        }

        return 'F';
    }
}

if (!function_exists('examFetchScheduleTeacherIds')) {
    function examFetchScheduleTeacherIds($scheduleId) {
        global $pdo;

        $stmt = $pdo->prepare("
            SELECT DISTINCT teacher_id
            FROM (
                SELECT es.teacher_id AS teacher_id
                FROM exam_schedules es
                WHERE es.id = ?

                UNION

                SELECT s.teacher_id AS teacher_id
                FROM exam_schedules es
                JOIN subjects s ON s.id = es.subject_id
                WHERE es.id = ?

                UNION

                SELECT c.class_teacher_id AS teacher_id
                FROM exam_schedules es
                JOIN classes c ON c.id = es.class_id
                WHERE es.id = ?
            ) teacher_pool
            WHERE teacher_id IS NOT NULL
        ");
        $stmt->execute([$scheduleId, $scheduleId, $scheduleId]);
        $teacherIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($teacherIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($teacherIds), '?'));
        $activeStmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE id IN ($placeholders) AND role = 'teacher' AND status = 'active'
        ");
        $activeStmt->execute($teacherIds);

        return array_map('intval', $activeStmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (!function_exists('examAutoCreateSchedules')) {
    function examAutoCreateSchedules($examId, $portalOpenDate = null, $portalCloseDate = null, $instructions = '') {
        global $pdo;

        $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
        $stmt->execute([$examId]);
        $exam = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$exam) {
            throw new Exception('Exam not found.');
        }

        $openDate = examNormalizePortalDateTime($portalOpenDate ?: ($exam['start_date'] ?? null), false);
        $closeDate = examNormalizePortalDateTime($portalCloseDate ?: ($exam['end_date'] ?? null), true);

        if (!$openDate) {
            $openDate = date('Y-m-d 00:00:00');
        }
        if (!$closeDate) {
            $closeDate = date('Y-m-d 23:59:59', strtotime($openDate . ' +7 days'));
        }
        if (strtotime($closeDate) <= strtotime($openDate)) {
            $closeDate = date('Y-m-d 23:59:59', strtotime($openDate . ' +7 days'));
        }

        $existingStmt = $pdo->prepare("SELECT class_id, subject_id FROM exam_schedules WHERE exam_id = ?");
        $existingStmt->execute([$examId]);
        $existingPairs = [];
        foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existingPairs[$row['class_id'] . ':' . $row['subject_id']] = true;
        }

        $eligibleStmt = $pdo->query("
            SELECT
                c.id AS class_id,
                s.id AS subject_id,
                s.teacher_id
            FROM classes c
            JOIN subjects s ON s.class_id = c.id
            JOIN students st ON st.class_id = c.id AND st.status = 'active'
            GROUP BY c.id, s.id, s.teacher_id
            ORDER BY c.class_name ASC, s.subject_name ASC
        ");
        $eligibleSchedules = $eligibleStmt->fetchAll(PDO::FETCH_ASSOC);

        $insertStmt = $pdo->prepare("
            INSERT INTO exam_schedules (
                exam_id, subject_id, class_id, exam_date, teacher_id, instructions,
                portal_open_date, portal_close_date, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
        ");

        $created = 0;
        foreach ($eligibleSchedules as $candidate) {
            $pairKey = $candidate['class_id'] . ':' . $candidate['subject_id'];
            if (isset($existingPairs[$pairKey])) {
                continue;
            }

            $insertStmt->execute([
                $examId,
                $candidate['subject_id'],
                $candidate['class_id'],
                date('Y-m-d', strtotime($openDate)),
                $candidate['teacher_id'] ?: null,
                $instructions !== '' ? $instructions : ($exam['description'] ?? ''),
                $openDate,
                $closeDate,
            ]);
            $created++;
        }

        return $created;
    }
}

if (!function_exists('finalizeExamResults')) {
    function finalizeExamResults($examId) {
        global $pdo;

        $examStmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
        $examStmt->execute([$examId]);
        $exam = $examStmt->fetch(PDO::FETCH_ASSOC);
        if (!$exam) {
            throw new Exception('Exam not found.');
        }

        $scheduleStmt = $pdo->prepare("
            SELECT es.id, es.class_id, es.subject_id
            FROM exam_schedules es
            WHERE es.exam_id = ?
            ORDER BY es.class_id, es.subject_id
        ");
        $scheduleStmt->execute([$examId]);
        $schedules = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($schedules)) {
            throw new Exception('This exam has no generated schedules.');
        }

        foreach ($schedules as $schedule) {
            $pdo->prepare("
                UPDATE exam_schedules
                SET status = 'closed'
                WHERE id = ? AND status IN ('scheduled', 'open', 'reopened')
            ")->execute([$schedule['id']]);

            analyzeExamResults($schedule['id']);
        }

        $marksColumn = 'marks_obtained';
        try {
            $examMarksColumns = $pdo->query("SHOW COLUMNS FROM exam_marks")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('marks_obtained', $examMarksColumns, true) && in_array('marks', $examMarksColumns, true)) {
                $marksColumn = 'marks';
            }
        } catch (Exception $e) {
            $marksColumn = 'marks_obtained';
        }

        $studentSummaryStmt = $pdo->prepare("
            SELECT
                st.id AS student_id,
                st.class_id,
                st.full_name,
                COUNT(DISTINCT es.subject_id) AS subject_count,
                COALESCE(SUM(em.{$marksColumn}), 0) AS total_marks,
                COALESCE(AVG(em.{$marksColumn}), 0) AS average_marks
            FROM students st
            JOIN exam_schedules es
                ON es.class_id = st.class_id
               AND es.exam_id = ?
            LEFT JOIN exam_marks em
                ON em.exam_schedule_id = es.id
               AND em.student_id = st.id
            WHERE st.status = 'active'
            GROUP BY st.id, st.class_id, st.full_name
            ORDER BY st.class_id ASC, average_marks DESC, total_marks DESC, st.full_name ASC
        ");
        $studentSummaryStmt->execute([$examId]);
        $studentSummaries = $studentSummaryStmt->fetchAll(PDO::FETCH_ASSOC);

        $pdo->prepare("DELETE FROM exam_grades WHERE exam_id = ?")->execute([$examId]);
        $insertGradeStmt = $pdo->prepare("
            INSERT INTO exam_grades (exam_id, student_id, total_marks, average_marks, grade, rank, pass_status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $rankByClass = [];
        foreach ($studentSummaries as $summary) {
            $classId = (int) $summary['class_id'];
            if (!isset($rankByClass[$classId])) {
                $rankByClass[$classId] = 1;
            }

            $averageMarks = round((float) $summary['average_marks'], 2);
            $grade = examResolveGrade($averageMarks, (float) ($exam['total_marks'] ?? 100));
            $passStatus = $averageMarks >= (float) $exam['passing_marks'] ? 'pass' : 'fail';

            $insertGradeStmt->execute([
                $examId,
                $summary['student_id'],
                round((float) $summary['total_marks'], 2),
                $averageMarks,
                $grade,
                $rankByClass[$classId],
                $passStatus,
            ]);

            $rankByClass[$classId]++;
        }

        foreach ($schedules as $schedule) {
            $pdo->prepare("UPDATE exam_schedules SET status = 'published' WHERE id = ?")->execute([$schedule['id']]);
            sendExamResultsToTeachers($schedule['id']);
            notifyExamResultsPublished($schedule['id']);
        }

        return [
            'schedule_count' => count($schedules),
            'student_count' => count($studentSummaries),
        ];
    }
}

// Analyze exam results
if (!function_exists('analyzeExamResults')) {
    function analyzeExamResults($exam_schedule_id) {
        global $pdo;
        try {
            $pdo->beginTransaction();
        
        // Get exam details
        $stmt = $pdo->prepare("
            SELECT es.*, e.passing_marks, e.total_marks 
            FROM exam_schedules es
            JOIN exams e ON es.exam_id = e.id
            WHERE es.id = ?
        ");
        $stmt->execute([$exam_schedule_id]);
        $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$schedule) {
            throw new Exception("Exam schedule not found");
        }
        
        // Get all marks for this exam
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_students,
                SUM(CASE WHEN marks_obtained >= ? THEN 1 ELSE 0 END) as passed,
                AVG(marks_obtained) as avg_marks,
                MAX(marks_obtained) as highest,
                MIN(marks_obtained) as lowest
            FROM exam_marks
            WHERE exam_schedule_id = ?
        ");
        $stmt->execute([$schedule['passing_marks'], $exam_schedule_id]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $total = $stats['total_students'] ?: 1;
        $passed = $stats['passed'] ?: 0;
        $pass_percentage = round(($passed / $total) * 100, 2);
        
        // Delete existing analysis
        $stmt = $pdo->prepare("DELETE FROM exam_analysis WHERE exam_schedule_id = ?");
        $stmt->execute([$exam_schedule_id]);
        
        // Insert new analysis
        $stmt = $pdo->prepare("
            INSERT INTO exam_analysis 
            (exam_schedule_id, subject_id, class_id, total_students, students_passed, students_failed, 
             average_marks, highest_marks, lowest_marks, pass_percentage, analyzed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $exam_schedule_id,
            $schedule['subject_id'],
            $schedule['class_id'],
            $total,
            $passed,
            $total - $passed,
            $stats['avg_marks'],
            $stats['highest'],
            $stats['lowest'],
            $pass_percentage
        ]);
        
        // Update exam schedule status
        $stmt = $pdo->prepare("UPDATE exam_schedules SET status = 'analyzed' WHERE id = ?");
        $stmt->execute([$exam_schedule_id]);
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Error analyzing exam: " . $e->getMessage());
        return false;
    }
    }
}

// Send exam results to teachers
if (!function_exists('sendExamResultsToTeachers')) {
    function sendExamResultsToTeachers($exam_schedule_id) {
        global $pdo;
        try {
            // Get exam schedule details
            $stmt = $pdo->prepare("
                SELECT es.*, e.exam_name, e.exam_code, e.passing_marks
                FROM exam_schedules es
                JOIN exams e ON es.exam_id = e.id
                WHERE es.id = ?
            ");
            $stmt->execute([$exam_schedule_id]);
            $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$schedule) return false;
            
            // Get or calculate analysis
            $stmt = $pdo->prepare("SELECT * FROM exam_analysis WHERE exam_schedule_id = ?");
            $stmt->execute([$exam_schedule_id]);
            $analysis = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // If no analysis exists, calculate it from marks
            if (!$analysis) {
                $stmt = $pdo->prepare("
                    SELECT 
                        COUNT(DISTINCT student_id) as total_students,
                        SUM(CASE WHEN marks_obtained >= ? THEN 1 ELSE 0 END) as students_passed,
                        SUM(CASE WHEN marks_obtained < ? THEN 1 ELSE 0 END) as students_failed,
                        AVG(marks_obtained) as average_marks,
                        MAX(marks_obtained) as highest_marks,
                        MIN(marks_obtained) as lowest_marks,
                        (SUM(CASE WHEN marks_obtained >= ? THEN 1 ELSE 0 END) / COUNT(DISTINCT student_id) * 100) as pass_percentage
                    FROM exam_marks
                    WHERE exam_schedule_id = ?
                ");
                $stmt->execute([
                    $schedule['passing_marks'],
                    $schedule['passing_marks'],
                    $schedule['passing_marks'],
                    $exam_schedule_id
                ]);
                $analysis = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Store the calculated analysis
                if ($analysis && $analysis['total_students'] > 0) {
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO exam_analysis 
                        (exam_schedule_id, subject_id, class_id, total_students, students_passed, students_failed, average_marks, highest_marks, lowest_marks, pass_percentage, analyzed_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE
                        total_students=VALUES(total_students),
                        students_passed=VALUES(students_passed),
                        students_failed=VALUES(students_failed),
                        average_marks=VALUES(average_marks),
                        highest_marks=VALUES(highest_marks),
                        lowest_marks=VALUES(lowest_marks),
                        pass_percentage=VALUES(pass_percentage),
                        analyzed_at=NOW()
                    ");
                    $insert_stmt->execute([
                        $exam_schedule_id,
                        $schedule['subject_id'],
                        $schedule['class_id'],
                        $analysis['total_students'],
                        $analysis['students_passed'],
                        $analysis['students_failed'],
                        $analysis['average_marks'],
                        $analysis['highest_marks'],
                        $analysis['lowest_marks'],
                        $analysis['pass_percentage']
                    ]);
                }
            }
            
            // Return if no analysis data available
            if (!$analysis || !$analysis['total_students']) {
                error_log("No exam marks found for schedule $exam_schedule_id");
                return false;
            }
            
            $teacherIds = examFetchScheduleTeacherIds($exam_schedule_id);
            if (empty($teacherIds)) {
                error_log("No teachers found for exam schedule ID: " . $exam_schedule_id);
                return false;
            }

            $placeholders = implode(',', array_fill(0, count($teacherIds), '?'));
            $stmt = $pdo->prepare("
                SELECT id, full_name
                FROM users
                WHERE id IN ($placeholders)
            ");
            $stmt->execute($teacherIds);
            $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Send notification to each teacher
            foreach ($teachers as $teacher) {
                try {
                    // Create notification message with exam results summary
                    $title = "Exam Results Published - {$schedule['exam_name']}";
                    $message = "Results are ready: " . round($analysis['pass_percentage'], 1) . "% passed, Average: " . round($analysis['average_marks'], 1) . " marks";
                    
                    // Send notification to teacher
                    createNotification(
                        $title,
                        $message,
                        'results',
                        $teacher['id'],  // target_user_id
                        null,  // target_role
                        'high',  // priority
                        'fas fa-certificate',  // icon
                        '#27ae60',  // icon_color (green)
                        $exam_schedule_id,
                        'exam_schedule'
                    );
                    
                    // Log the result sent
                    $stmt = $pdo->prepare("
                        INSERT INTO exam_results (exam_schedule_id, teacher_id, class_id, subject_id, email_sent, email_sent_at)
                        VALUES (?, ?, ?, ?, 1, NOW())
                        ON DUPLICATE KEY UPDATE email_sent=1, email_sent_at=NOW()
                    ");
                    $stmt->execute([$exam_schedule_id, $teacher['id'], $schedule['class_id'], $schedule['subject_id']]);
                    
                } catch (Exception $e) {
                    error_log("Failed to send exam results notification to teacher {$teacher['id']}: " . $e->getMessage());
                }
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Error sending exam results: " . $e->getMessage());
            return false;
        }
    }
}

// Get exam portal status description
if (!function_exists('getExamPortalStatusDescription')) {
    function getExamPortalStatusDescription($status) {
        $statuses = [
        'scheduled' => 'Not Yet Open',
        'open' => 'Portal Open - Accepting Marks',
        'closed' => 'Portal Closed - Awaiting Analysis',
        'analyzed' => 'Analysis Complete - Ready for Publishing',
        'published' => 'Results Published'
    ];
    return $statuses[$status] ?? 'Unknown';
    }
}

// Helper function to get notification icon and color based on type
if (!function_exists('getNotificationIcon')) {
    function getNotificationIcon($type) {
        $icons = [
            'payment' => ['icon' => 'fas fa-money-bill-wave', 'color' => '#27ae60'],
            'student' => ['icon' => 'fas fa-user-plus', 'color' => '#3498db'],
            'invoice' => ['icon' => 'fas fa-file-invoice', 'color' => '#f39c12'],
            'expense' => ['icon' => 'fas fa-receipt', 'color' => '#e74c3c'],
            'message' => ['icon' => 'fas fa-envelope', 'color' => '#9b59b6'],
            'attendance' => ['icon' => 'fas fa-clipboard-check', 'color' => '#1abc9c'],
            'report' => ['icon' => 'fas fa-file-alt', 'color' => '#e67e22'],
            'exam' => ['icon' => 'fas fa-certificate', 'color' => '#27ae60'],
            'exam_portal' => ['icon' => 'fas fa-door-open', 'color' => '#2563eb'],
            'results' => ['icon' => 'fas fa-chart-line', 'color' => '#7c3aed'],
            'approval' => ['icon' => 'fas fa-circle-check', 'color' => '#16a34a'],
            'request' => ['icon' => 'fas fa-paper-plane', 'color' => '#0ea5e9'],
            'maintenance' => ['icon' => 'fas fa-screwdriver-wrench', 'color' => '#dc2626'],
            'maintenance_complete' => ['icon' => 'fas fa-server', 'color' => '#16a34a'],
            'payroll' => ['icon' => 'fas fa-money-check-dollar', 'color' => '#0f766e'],
            'system' => ['icon' => 'fas fa-cog', 'color' => '#95a5a6'],
        ];
        return $icons[$type] ?? ['icon' => 'fas fa-bell', 'color' => '#667eea'];
    }
}

// Create a notification for users
if (!function_exists('createNotification')) {
    function createNotification($title, $message, $type = 'system', $target_user_id = null, $target_role = null, $priority = 'normal', $icon = null, $icon_color = null, $related_id = null, $related_type = null) {
        global $pdo;
        
        try {
            // Get icon and color from notification type if not provided
            if (!$icon || !$icon_color) {
                $icon_data = getNotificationIcon($type);
                $icon = $icon ?? $icon_data['icon'];
                $icon_color = $icon_color ?? $icon_data['color'];
            }
            
            $notificationColumns = [];
            try {
                $notificationColumns = array_fill_keys($pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN), true);
            } catch (Exception $e) {
                $notificationColumns = [];
            }

            $insertNotification = function ($userId = null) use ($pdo, $notificationColumns, $title, $message, $type, $priority, $icon, $icon_color, $related_id, $related_type) {
                $payload = [
                    'user_id' => $userId,
                    'title' => $title,
                    'message' => $message,
                    'type' => $type,
                    'priority' => $priority,
                    'icon' => $icon,
                    'icon_color' => $icon_color,
                    'related_id' => $related_id,
                    'related_type' => $related_type,
                    'is_read' => 0,
                ];

                $fields = [];
                $placeholders = [];
                $values = [];

                foreach ($payload as $column => $value) {
                    if ($column === 'user_id' && $value === null) {
                        continue;
                    }
                    if ($notificationColumns && !isset($notificationColumns[$column])) {
                        continue;
                    }
                    $fields[] = $column;
                    $placeholders[] = '?';
                    $values[] = $value;
                }

                if ($notificationColumns && isset($notificationColumns['created_at'])) {
                    $fields[] = 'created_at';
                    $placeholders[] = 'NOW()';
                }

                $sql = "INSERT INTO notifications (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $pdo->prepare($sql);
                return $stmt->execute($values);
            };

            // If specific user ID provided
            if ($target_user_id) {
                $stmt = $pdo->prepare("
                    SELECT id FROM users WHERE id = ? LIMIT 1
                ");
                $stmt->execute([$target_user_id]);
                if ($stmt->fetch()) {
                    return $insertNotification($target_user_id);
                }
                return false;
            }
            
            // If role specified, send to all users with that role
            if ($target_role) {
                $users_stmt = $pdo->prepare("SELECT id FROM users WHERE role = ? AND status = 'active'");
                $users_stmt->execute([$target_role]);
                $users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

                $success = true;
                foreach ($users as $user) {
                    $result = $insertNotification($user['id']);
                    $success = $success && $result;
                }
                return $success;
            }
            
            // If neither user_id nor role, send to all users (broadcast)
            return $insertNotification(null);
            
        } catch (Exception $e) {
            error_log("Error creating notification: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('createRoleNotification')) {
    function createRoleNotification($title, $message, $type, array $roles, $priority = 'normal', $related_id = null, $related_type = null) {
        $success = true;
        foreach ($roles as $role) {
            $result = createNotification($title, $message, $type, null, $role, $priority, null, null, $related_id, $related_type);
            $success = $success && $result;
        }
        return $success;
    }
}

if (!function_exists('notifyExamPortalStatusChange')) {
    function notifyExamPortalStatusChange($exam_id, $schedule_id, $status, $scope = 'single') {
        global $pdo;

        try {
            $stmt = $pdo->prepare("
                SELECT
                    es.id,
                    es.class_id,
                    es.subject_id,
                    es.portal_open_date,
                    es.portal_close_date,
                    e.exam_name,
                    c.class_name,
                    s.subject_name
                FROM exam_schedules es
                JOIN exams e ON es.exam_id = e.id
                LEFT JOIN classes c ON es.class_id = c.id
                LEFT JOIN subjects s ON es.subject_id = s.id
                WHERE es.id = ? AND es.exam_id = ?
            ");
            $stmt->execute([$schedule_id, $exam_id]);
            $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$schedule) {
                return false;
            }

            $statusText = [
                'open' => 'opened',
                'closed' => 'closed',
                'reopened' => 'reopened',
            ][$status] ?? $status;

            $scopePrefix = $scope === 'bulk' ? 'Exam portals have been ' : 'Exam portal has been ';
            $title = 'Exam Portal ' . ucfirst($statusText);
            $message = $scopePrefix . $statusText . ' for ' . ($schedule['exam_name'] ?? 'the exam') .
                ' - ' . ($schedule['class_name'] ?? 'Class') .
                (!empty($schedule['subject_name']) ? ' (' . $schedule['subject_name'] . ')' : '') .
                '. Window: ' . date('d M Y H:i', strtotime($schedule['portal_open_date'])) .
                ' to ' . date('d M Y H:i', strtotime($schedule['portal_close_date'])) . '.';

            foreach (examFetchScheduleTeacherIds($schedule['id']) as $teacherId) {
                createNotification($title, $message, 'exam_portal', (int) $teacherId, null, 'high', null, null, (int) $schedule['id'], 'exam_schedule');
            }

            createRoleNotification($title, $message, 'exam_portal', ['admin'], 'normal', (int) $schedule['id'], 'exam_schedule');
            return true;
        } catch (Exception $e) {
            error_log('Error sending exam portal notification: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('notifyExamResultsPublished')) {
    function notifyExamResultsPublished($exam_schedule_id) {
        global $pdo;

        try {
            $stmt = $pdo->prepare("
                SELECT
                    es.id,
                    e.exam_name,
                    c.class_name,
                    s.subject_name
                FROM exam_schedules es
                JOIN exams e ON es.exam_id = e.id
                LEFT JOIN classes c ON es.class_id = c.id
                LEFT JOIN subjects s ON es.subject_id = s.id
                WHERE es.id = ?
            ");
            $stmt->execute([$exam_schedule_id]);
            $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$schedule) {
                return false;
            }

            $title = 'Exam Results Released';
            $message = 'Results for ' . ($schedule['exam_name'] ?? 'Exam') . ' - ' .
                ($schedule['class_name'] ?? 'Class') .
                (!empty($schedule['subject_name']) ? ' (' . $schedule['subject_name'] . ')' : '') .
                ' are now available in the system and ready for printing.';

            foreach (examFetchScheduleTeacherIds($exam_schedule_id) as $teacherId) {
                createNotification($title, $message, 'results', (int) $teacherId, null, 'high', null, null, (int) $schedule['id'], 'exam_schedule');
            }
            createRoleNotification($title, $message, 'results', ['admin'], 'high', (int) $schedule['id'], 'exam_schedule');
            return true;
        } catch (Exception $e) {
            error_log('Error sending exam results notification: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('notifyApprovalRequestSubmitted')) {
    function notifyApprovalRequestSubmitted($title, $message, $related_id = null, $related_type = null) {
        return createRoleNotification($title, $message, 'request', ['admin'], 'high', $related_id, $related_type);
    }
}

?>
