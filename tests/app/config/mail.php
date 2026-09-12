<?php
/**
 * Email / SMTP Configuration
 *
 * PRODUCTION DEPLOYMENT:
 * Set these environment variables on your server:
 *   export SMTP_HOST=smtp.gmail.com
 *   export SMTP_PORT=587
 *   export SMTP_USERNAME=your-email@gmail.com
 *   export SMTP_PASSWORD=your-app-password
 *   export SMTP_FROM=noreply@yourdomain.com
 *   export SMTP_FROM_NAME="Empower Investment Club"
 *
 * DEVELOPMENT (XAMPP):
 * Keep credentials in C:\xampp\empower_secrets\smtp_credentials.php:
 *   <?php
 *   define('EMPOWER_SMTP_HOST', 'smtp.gmail.com');
 *   define('EMPOWER_SMTP_PORT', 587);
 *   define('EMPOWER_SMTP_USERNAME', 'your-email@gmail.com');
 *   define('EMPOWER_SMTP_PASSWORD', 'your-app-password');
 *   define('EMPOWER_SMTP_FROM', 'noreply@example.com');
 *   define('EMPOWER_SMTP_FROM_NAME', 'Empower Investment Club');
 */

// Try environment variables first (production)
$smtpHost = getenv('SMTP_HOST');
$smtpPort = getenv('SMTP_PORT');
$smtpUsername = getenv('SMTP_USERNAME');
$smtpPassword = getenv('SMTP_PASSWORD');
$smtpFrom = getenv('SMTP_FROM');
$smtpFromName = getenv('SMTP_FROM_NAME');

// Fallback to local secrets file (development on Windows/XAMPP)
if (!$smtpHost || !$smtpUsername) {
    $secretFile = 'C:\\xampp\\empower_secrets\\smtp_credentials.php';
    if (file_exists($secretFile)) {
        require $secretFile;
        $smtpHost = defined('EMPOWER_SMTP_HOST') ? EMPOWER_SMTP_HOST : 'smtp.gmail.com';
        $smtpPort = defined('EMPOWER_SMTP_PORT') ? EMPOWER_SMTP_PORT : 587;
        $smtpUsername = defined('EMPOWER_SMTP_USERNAME') ? EMPOWER_SMTP_USERNAME : '';
        $smtpPassword = defined('EMPOWER_SMTP_PASSWORD') ? EMPOWER_SMTP_PASSWORD : '';
        $smtpFrom = defined('EMPOWER_SMTP_FROM') ? EMPOWER_SMTP_FROM : 'noreply@localhost';
        $smtpFromName = defined('EMPOWER_SMTP_FROM_NAME') ? EMPOWER_SMTP_FROM_NAME : 'Empower Investment Club';
    } else {
        // Ultimate fallback (email will fail but not crash the app)
        $smtpHost = 'smtp.gmail.com';
        $smtpPort = 587;
        $smtpUsername = '';
        $smtpPassword = '';
        $smtpFrom = 'noreply@localhost';
        $smtpFromName = 'Empower Investment Club';
    }
}

define('SMTP_HOST',      $smtpHost);
define('SMTP_PORT',      (int)$smtpPort);
define('SMTP_USERNAME',  $smtpUsername);
define('SMTP_PASSWORD',  $smtpPassword);
define('SMTP_FROM',      $smtpFrom);
define('SMTP_FROM_NAME', $smtpFromName);

// Clear sensitive variables from memory
unset($smtpHost, $smtpPort, $smtpUsername, $smtpPassword, $smtpFrom, $smtpFromName, $secretFile);
