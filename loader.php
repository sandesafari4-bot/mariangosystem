<?php
/**
 * Global Loader Include
 * Add this include immediately after <body> tag to display a loading spinner
 * Usage: <?php include 'loader.php'; ?>
 */

// Fetch school name from database
$schoolName = 'School';

try {
    // Create settings table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `skey` VARCHAR(191) NOT NULL UNIQUE,
        `svalue` TEXT NOT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // Fetch school name
    $stmt = $pdo->prepare("SELECT svalue FROM settings WHERE skey = 'school_name' LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result && !empty($result['svalue'])) {
        $schoolName = $result['svalue'];
    } elseif (defined('SCHOOL_NAME')) {
        $schoolName = SCHOOL_NAME;
    }
} catch (Exception $e) {
    // Fallback to constant if database query fails
    if (defined('SCHOOL_NAME')) {
        $schoolName = SCHOOL_NAME;
    }
}
?>

<!-- Global Loader (shows until window 'load' event) -->
<div id="global-loader" class="global-loader" role="status" aria-live="polite">
    <div class="loader-card">
        <div class="loader-spinner" aria-hidden="true">
            <svg class="spinner-svg" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <linearGradient id="arcGrad1" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" style="stop-color:#667eea;stop-opacity:1" />
                        <stop offset="100%" style="stop-color:#667eea;stop-opacity:0.2" />
                    </linearGradient>
                    <linearGradient id="arcGrad2" x1="100%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" style="stop-color:#1abc9c;stop-opacity:1" />
                        <stop offset="100%" style="stop-color:#1abc9c;stop-opacity:0.2" />
                    </linearGradient>
                </defs>
                <!-- Arc 1: Clockwise spinner (purple-blue) - broad tail, thin head -->
                <g class="arc-spinner arc1">
                    <circle cx="60" cy="60" r="45" fill="none" stroke="url(#arcGrad1)" stroke-width="12" stroke-linecap="round"
                            stroke-dasharray="120 240" stroke-dashoffset="0" opacity="0.95"/>
                </g>
                <!-- Arc 2: Counter-clockwise spinner (teal) - broad tail, thin head -->
                <g class="arc-spinner arc2">
                    <circle cx="60" cy="60" r="33" fill="none" stroke="url(#arcGrad2)" stroke-width="10" stroke-linecap="round"
                            stroke-dasharray="95 200" stroke-dashoffset="30" opacity="0.92"/>
                </g>
            </svg>
        </div>
        <div class="loader-meta">
            <div class="loader-title"><?php echo htmlspecialchars($schoolName); ?></div>
            <div class="loader-sub">Loading</div>
        </div>
    </div>
</div>

<script>
    // Immediate bootstrap so loader shows as early as possible on refresh.
    (function() {
        try {
            var __loader = document.getElementById('global-loader');
            if (__loader) {
                __loader.classList.remove('hide');
            }

            // Expose simple API for manual control
            window.showLoader = function() { try { if (__loader) __loader.classList.remove('hide'); } catch(e){} };
            window.hideLoader = function() { try { if (__loader) __loader.classList.add('hide'); setTimeout(function(){ try{ __loader.remove(); }catch(e){} },500); } catch(e){} };

            // Capture initial performance entries early for the main tracker to consume
            try {
                window.__earlyPerfEntries = (performance && performance.getEntriesByType) ? performance.getEntriesByType('resource') : [];
            } catch(e) { window.__earlyPerfEntries = []; }
        } catch(e) {}
    })();
</script>

<script>
    // Global loader control — hide when DOM content is ready
    (function() {
        var loader = document.getElementById('global-loader');
        if (!loader) return;
        var hidden = false;
        var fallbackTimer = null;

        function hideLoader() {
            if (!loader || hidden) return;
            hidden = true;
            if (fallbackTimer) {
                clearTimeout(fallbackTimer);
            }
            if (!loader.classList.contains('hide')) {
                loader.classList.add('hide');
                setTimeout(function() {
                    try { 
                        if (loader && loader.parentNode) {
                            loader.remove();
                        }
                    } catch(e) {}
                }, 300);
            }
        }

        // Ensure loader is visible immediately
        try { loader.classList.remove('hide'); } catch(e) {}

        // Follow the actual browser loading lifecycle instead of a fixed timer.
        if (document.readyState === 'complete') {
            hideLoader();
        } else {
            document.addEventListener('DOMContentLoaded', hideLoader, { once: true });
            window.addEventListener('load', hideLoader, { once: true });
            fallbackTimer = setTimeout(hideLoader, 2500);
        }

    })();
</script>

<style>
    /* Global Loader Styles */
    .global-loader {
        position: fixed;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        background: radial-gradient(rgba(0,0,0,0.35), rgba(0,0,0,0.5));
        z-index: 99999;
        transition: opacity 0.45s ease, visibility 0.45s ease;
    }

    .global-loader.hide {
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
    }

    .loader-card {
        background: linear-gradient(135deg,#ffffffcc,#f0f4ffcc);
        padding: 22px;
        border-radius: 16px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        display: flex;
        align-items: center;
        gap: 18px;
        min-width: 260px;
    }

    .loader-spinner {
        width: 80px;
        height: 80px;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .spinner-svg {
        width: 100%;
        height: 100%;
        filter: drop-shadow(0 2px 8px rgba(102,126,234,0.2));
    }

    /* First arc: clockwise rotation (faster) */
    .arc1 {
        animation: spinClockwise 1.5s linear infinite;
        transform-origin: 60px 60px;
    }

    /* Second arc: counter-clockwise rotation (faster) */
    .arc2 {
        animation: spinCounterClockwise 0.9s linear infinite;
        transform-origin: 60px 60px;
    }

    /* Subtle dash offset animation to accentuate tail movement */
    .arc1 circle { animation: dashMove1 1.5s linear infinite; }
    .arc2 circle { animation: dashMove2 0.9s linear infinite; }

    @keyframes dashMove1 {
        0% { stroke-dashoffset: 0; }
        100% { stroke-dashoffset: -360; }
    }

    @keyframes dashMove2 {
        0% { stroke-dashoffset: 30; }
        100% { stroke-dashoffset: 390; }
    }

    @keyframes spinClockwise {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    @keyframes spinCounterClockwise {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(-360deg); }
    }

    .loader-meta {
        display: flex;
        flex-direction: column;
        gap: 6px;
        color: #243b55;
    }

    .loader-title { font-size: 1rem; font-weight: 700; }
    .loader-sub { font-size: 0.85rem; color: #4b627a; }
</style>
