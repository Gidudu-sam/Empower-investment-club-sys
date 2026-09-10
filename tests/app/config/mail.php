<?php
/**
 * Outbound email (SMTP) configuration -- Member Statement Emailing stage.
 *
 * Mirrors this project's existing config convention (see database.php,
 * push.php): plain PHP constants in a config file, protected from direct
 * HTTP access by the existing SA-2 .htaccess rules (blocks the whole app/
 * directory, and every root-level *.php except index.php).
 *
 * Pre-deployment remediation (2026-09-10, F-5): SMTP_PASS is no longer
 * hardcoded here. It now loads from C:\xampp\empower_secrets\
 * mail_credentials.php -- a file outside C:\xampp\htdocs entirely (the
 * Apache DocumentRoot), so no URL can ever reach it regardless of whether
 * .htaccess is honored by a given hosting environment. This is
 * defense-in-depth on top of the existing .htaccess protection, not a
 * replacement for it -- app/config/mail.php itself is still .htaccess-
 * blocked as before.
 *
 * SMTP_PASS is a Gmail "App Password" (Google Account -> Security -> 2-Step
 * Verification -> App passwords), NOT the account's real login password --
 * a normal Gmail password will not work for SMTP and should never be put
 * here. Generate one at https://myaccount.google.com/apppasswords with
 * 2-Step Verification already enabled on the sending account.
 *
 * Left blank if the external credential file is missing: MailerService
 * checks SMTP_USER/SMTP_PASS are both non-empty before attempting to
 * send, and fails closed with a clear "email is not configured yet"
 * result rather than a fatal error or a silent no-op -- the exact same
 * safety property the original inline definition already had.
 */

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'empowerclub2024@gmail.com');

$__empowerMailSecretFile = 'C:\\xampp\\empower_secrets\\mail_credentials.php';
if (file_exists($__empowerMailSecretFile)) {
    require $__empowerMailSecretFile;
}
define('SMTP_PASS', defined('EMPOWER_SMTP_PASS') ? EMPOWER_SMTP_PASS : '');
unset($__empowerMailSecretFile);

define('SMTP_FROM_NAME', APP_NAME . ' Statements');
