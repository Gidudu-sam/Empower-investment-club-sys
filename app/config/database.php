<?php
/**
 * Database Configuration
 *
 * Pre-deployment remediation (2026-09-10, F-4): connects as a dedicated,
 * least-privilege application account, "empower_app", granted ONLY
 * SELECT, INSERT, UPDATE, DELETE on "empower_db" -- no DDL (CREATE/ALTER/
 * DROP), no access to any other schema (including "mysql"), no global
 * privileges. Verified directly: CREATE TABLE and access to the "mysql"
 * system schema are both denied for this account; normal application
 * reads/writes succeed.
 *
 * Granted for both "127.0.0.1" and "localhost" -- this MariaDB instance
 * has name resolution enabled (skip_name_resolve=OFF) and was observed
 * resolving a 127.0.0.1 TCP connection's privilege lookup against the
 * "localhost" host entry, matching the same two-host pattern already used
 * for the existing root@127.0.0.1 / root@localhost rows.
 *
 * The prior root/blank-password configuration is fully retired -- there is
 * no fallback to it anywhere in this file or in core/Database.php.
 *
 * NOTE for DatabaseRecoveryService (SA-6): that service's isolated
 * restore-test connection intentionally uses hardcoded root credentials
 * directly (matching its own mysql.exe shell-outs, which have always used
 * root independently of these constants) rather than DB_USER/DB_PASS,
 * since its target schema (empower_db_sa6_restore_test) is outside this
 * account's intentionally narrow grant. See that file's own comments.
 *
 * Git/GitHub hardening (2026-09-10): DB_PASS is no longer hardcoded here.
 * It now loads from C:\xampp\empower_secrets\db_credentials.php -- outside
 * C:\xampp\htdocs entirely and outside this git repository -- following
 * the identical pattern already used for the SMTP, VAPID, and SA-6
 * recovery credentials. This was found and fixed before this file was
 * ever committed to version control: the earlier F-4 remediation had left
 * DB_PASS hardcoded (relying on .htaccess alone), which is a materially
 * different exposure once a file enters git history, since a commit
 * persists regardless of .htaccess or even repository visibility changing
 * later. No fallback to any other value exists if the external file is
 * missing -- DB_PASS resolves to an empty string, and the application's
 * own connection will then fail loudly rather than silently using a
 * blank/default credential.
 */
define('DB_HOST',    '127.0.0.1');
define('DB_PORT',    '3306');
define('DB_NAME',    'empower_db');
define('DB_USER',    'empower_app');

$__empowerDbSecretFile = 'C:\\xampp\\empower_secrets\\db_credentials.php';
if (file_exists($__empowerDbSecretFile)) {
    require $__empowerDbSecretFile;
}
define('DB_PASS', defined('EMPOWER_DB_PASS') ? EMPOWER_DB_PASS : '');
unset($__empowerDbSecretFile);

define('DB_CHARSET', 'utf8mb4');