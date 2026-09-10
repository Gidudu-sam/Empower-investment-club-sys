<?php
/**
 * Push notification (Web Push / VAPID) configuration — Stage 12-D.
 *
 * VAPID_PUBLIC_KEY is not secret by design -- it is handed to every
 * subscribing browser (see PushController::vapidPublicKey()) -- and
 * stays defined directly here, matching this project's existing config
 * convention (see database.php).
 *
 * Final secrets hardening (2026-09-10): VAPID_PRIVATE_KEY is no longer
 * hardcoded here. It now loads from C:\xampp\empower_secrets\
 * push_credentials.php -- a file outside C:\xampp\htdocs entirely (the
 * Apache DocumentRoot), so no URL can ever reach it regardless of
 * whether .htaccess is honored by a given hosting environment. This
 * mirrors the identical pattern already used for the SMTP password and
 * the SA-6 recovery credentials. It is read only by PushDeliveryService
 * (server-side), never rendered into any view, JS bundle, or API
 * response -- unchanged from before.
 *
 * If the external file is missing, VAPID_PRIVATE_KEY resolves to an
 * empty string -- there is no fallback to the old hardcoded value or to
 * any other key. PushDeliveryService's WebPush construction will then
 * fail loudly the next time a push is attempted, rather than silently
 * signing with a stale/default key.
 *
 * The keypair itself was generated once via
 * Minishlink\WebPush\VAPID::createVapidKeys() on 2026-09-04 for this
 * deployment. Regenerating it invalidates every existing browser
 * subscription (they would all need to re-subscribe) -- do not
 * regenerate casually.
 */

define('VAPID_PUBLIC_KEY', 'BAHE4YxR_QaDI5hQxFsl7jeimF0UzqMmaGjVAtJa9uLgSn4RZc6Y898y-bc7XCvHWnyBe_4PfmnAzL2duRF4vjs');

$__empowerPushSecretFile = 'C:\\xampp\\empower_secrets\\push_credentials.php';
if (file_exists($__empowerPushSecretFile)) {
    require $__empowerPushSecretFile;
}
define('VAPID_PRIVATE_KEY', defined('EMPOWER_VAPID_PRIVATE_KEY') ? EMPOWER_VAPID_PRIVATE_KEY : '');
unset($__empowerPushSecretFile);

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
