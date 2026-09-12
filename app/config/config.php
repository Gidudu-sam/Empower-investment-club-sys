<?php
/**
 * Application Configuration
 *
 * DEPLOYMENT NOTE: Set these environment variables on your production server:
 *   - APP_ENV=production
 *   - APP_URL=https://yourdomain.com
 *   - DB_HOST=localhost
 *   - DB_NAME=empower_db
 *   - DB_USER=empower_app
 *   - DB_PASS=your_secure_password
 *   - SMTP_HOST=smtp.gmail.com
 *   - SMTP_PORT=587
 *   - SMTP_USERNAME=your_email@gmail.com
 *   - SMTP_PASSWORD=your_app_password
 *   - SMTP_FROM=noreply@yourdomain.com
 */

// Environment (override via environment variable)
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('APP_NAME', 'Empower Investment Club');
define('APP_VERSION', '1.0.0');

// Application URL (CRITICAL: override via environment variable in production)
define('APP_URL', getenv('APP_URL') ?: 'http://localhost/empower');

// Paths
define('ROOT_PATH',   dirname(__DIR__, 2));
define('APP_PATH',    ROOT_PATH . '/app');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('VIEW_PATH',   APP_PATH  . '/views');
define('CORE_PATH',   ROOT_PATH . '/core');

// Session
define('SESSION_NAME',     'empower_session');
define('SESSION_LIFETIME', 3600); // 1 hour in seconds

// Session security (production only)
if (APP_ENV === 'production') {
    ini_set('session.cookie_httponly', '1');  // Blocks JavaScript access (XSS protection)
    ini_set('session.cookie_secure', '1');    // HTTPS only
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
}

// Timezone
date_default_timezone_set('Africa/Nairobi');

// Error reporting
if (APP_ENV === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT); // log everything, display nothing
}
