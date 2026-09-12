<?php
/**
 * Push notification (Web Push / VAPID) configuration — Stage 12-D.
 *
 * PRODUCTION DEPLOYMENT:
 * Set these environment variables on your server:
 *   export VAPID_PUBLIC_KEY=your-public-key
 *   export VAPID_PRIVATE_KEY=your-private-key
 *
 * DEVELOPMENT (XAMPP):
 * Keep credentials in C:\xampp\empower_secrets\push_credentials.php:
 *   <?php
 *   define('EMPOWER_VAPID_PUBLIC_KEY', 'your-public-key');
 *   define('EMPOWER_VAPID_PRIVATE_KEY', 'your-private-key');
 *
 * Generate new VAPID keys using: vendor/bin/web-push generate-keys
 * (or Minishlink\WebPush\VAPID::createVapidKeys() in PHP)
 */

// Try environment variables first (production)
$vapidPublicKey = getenv('VAPID_PUBLIC_KEY');
$vapidPrivateKey = getenv('VAPID_PRIVATE_KEY');

// Fallback to local secrets file (development on Windows/XAMPP)
if (!$vapidPublicKey || !$vapidPrivateKey) {
    $secretFile = 'C:\\xampp\\empower_secrets\\push_credentials.php';
    if (file_exists($secretFile)) {
        require $secretFile;
        $vapidPublicKey = defined('EMPOWER_VAPID_PUBLIC_KEY') ? EMPOWER_VAPID_PUBLIC_KEY : '';
        $vapidPrivateKey = defined('EMPOWER_VAPID_PRIVATE_KEY') ? EMPOWER_VAPID_PRIVATE_KEY : '';
    }
    // If still not set, use the original hardcoded public key (development only)
    if (!$vapidPublicKey) {
        $vapidPublicKey = 'BAHE4YxR_QaDI5hQxFsl7jeimF0UzqMmaGjVAtJa9uLgSn4RZc6Y898y-bc7XCvHWnyBe_4PfmnAzL2duRF4vjs';
    }
}

define('VAPID_PUBLIC_KEY',  $vapidPublicKey);
define('VAPID_PRIVATE_KEY', $vapidPrivateKey);

// Clear sensitive variables from memory
unset($vapidPublicKey, $vapidPrivateKey, $secretFile);

// VAPID "sub" claim -- identifies who to contact about this application's
// push traffic, per RFC 8292. A URL is a valid alternative to mailto: and
// avoids inventing a contact address that doesn't exist in this system.
define('VAPID_SUBJECT', APP_URL);

// This XAMPP/Windows PHP build's openssl extension cannot generate or use
// EC keys (VAPID uses P-256) without an explicit openssl.cnf path -- without
// this, Minishlink\WebPush throws "Unable to create the key" on every call.
// Confirmed via direct testing; setting this once here (rather than relying
// on a fragile, invisible system-level environment variable) makes the fix
// travel with the application.
if (!getenv('OPENSSL_CONF')) {
    putenv('OPENSSL_CONF=' . 'C:\\xampp\\php\\extras\\openssl\\openssl.cnf');
}
