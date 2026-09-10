<?php
/**
 * Application Configuration
 */

// Environment
define('APP_ENV', 'production'); // development | production
define('APP_NAME', 'Empower Investment Club');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/empower');

// Paths
define('ROOT_PATH',   dirname(__DIR__, 2));
define('APP_PATH',    ROOT_PATH . '/app');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('VIEW_PATH',   APP_PATH  . '/views');
define('CORE_PATH',   ROOT_PATH . '/core');

// Session
define('SESSION_NAME',     'empower_session');
define('SESSION_LIFETIME', 3600); // 1 hour in seconds

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
