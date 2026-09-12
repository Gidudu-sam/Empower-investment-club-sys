<?php
/**
 * Database Configuration
 *
 * PRODUCTION DEPLOYMENT:
 * Set these environment variables on your server (via cPanel, .env file, or /etc/environment):
 *   export DB_HOST=localhost
 *   export DB_NAME=empower_db
 *   export DB_USER=empower_app
 *   export DB_PASS=your_secure_password
 *
 * DEVELOPMENT (XAMPP):
 * Keep credentials in C:\xampp\empower_secrets\db_credentials.php:
 *   <?php
 *   define('EMPOWER_DB_HOST', '127.0.0.1');
 *   define('EMPOWER_DB_NAME', 'empower_db');
 *   define('EMPOWER_DB_USER', 'empower_app');
 *   define('EMPOWER_DB_PASS', 'your_password');
 */

// Try environment variables first (production)
$dbHost = getenv('DB_HOST');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');

// Fallback to local secrets file (development on Windows/XAMPP)
if (!$dbHost || !$dbName || !$dbUser) {
    $secretFile = 'C:\\xampp\\empower_secrets\\db_credentials.php';
    if (file_exists($secretFile)) {
        require $secretFile;
        $dbHost = defined('EMPOWER_DB_HOST') ? EMPOWER_DB_HOST : '127.0.0.1';
        $dbName = defined('EMPOWER_DB_NAME') ? EMPOWER_DB_NAME : 'empower_db';
        $dbUser = defined('EMPOWER_DB_USER') ? EMPOWER_DB_USER : 'empower_app';
        $dbPass = defined('EMPOWER_DB_PASS') ? EMPOWER_DB_PASS : '';
    } else {
        // Ultimate fallback (will fail but fail loudly)
        $dbHost = '127.0.0.1';
        $dbName = 'empower_db';
        $dbUser = 'empower_app';
        $dbPass = '';
    }
}

define('DB_HOST',    $dbHost);
define('DB_PORT',    getenv('DB_PORT') ?: '3306');
define('DB_NAME',    $dbName);
define('DB_USER',    $dbUser);
define('DB_PASS',    $dbPass);
define('DB_CHARSET', 'utf8mb4');

// Clear sensitive variables from memory
unset($dbHost, $dbName, $dbUser, $dbPass, $secretFile);
