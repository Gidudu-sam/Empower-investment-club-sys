<?php
/**
 * Stage 27 — test database safety guard.
 *
 * A test script includes this file INSTEAD OF requiring
 * app/config/database.php directly. It loads the real config (so DB_HOST/
 * DB_USER/DB_PASS/DB_CHARSET are still available), then refuses to let
 * execution continue if DB_NAME resolves to the production database name.
 *
 * This exists because the Stage 26 incident traced back to exactly this
 * gap: app/config/database.php hardcodes DB_NAME='empower_db' with no
 * environment switch, so any test script requiring it directly connects
 * straight to production with no warning. Fails closed, not open --
 * refusal is the default, not a warning that lets execution continue.
 */

if (!defined('EMPOWER_TEST_GUARD_LOADED')) {
    define('EMPOWER_TEST_GUARD_LOADED', true);

    // SA-2 (2026-09): CLI-only enforcement. The root .htaccess already
    // blocks direct browser access to every test_*.php script, but this
    // guard is included by all of them -- one check here is a second,
    // independent layer that holds even if .htaccess is ever bypassed or
    // the app is served by something other than Apache.
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        header('Content-Type: text/plain');
        die("Forbidden: test scripts may only be run from the command line.\n");
    }

    // If the caller already defined its own isolated DB_NAME (the safe
    // pattern), load only the connection details we still need (host/user/
    // pass/charset) without letting the production config's DB_NAME
    // definition collide with -- or warn about overriding -- the caller's.
    if (defined('DB_NAME')) {
        if (!defined('DB_HOST'))    { define('DB_HOST', 'localhost'); }
        if (!defined('DB_PORT'))    { define('DB_PORT', '3306'); }
        if (!defined('DB_USER'))    { define('DB_USER', 'root'); }
        if (!defined('DB_PASS'))    { define('DB_PASS', ''); }
        if (!defined('DB_CHARSET')) { define('DB_CHARSET', 'utf8mb4'); }
    } else {
        require __DIR__ . '/app/config/database.php';
    }

    $__empowerProdDbName = 'empower_db';
    $__empowerIsProd = (defined('DB_NAME') && DB_NAME === $__empowerProdDbName);
    $__empowerOverride = (getenv('EMPOWER_ALLOW_PROD_TEST') === '1');

    if ($__empowerIsProd && !$__empowerOverride) {
        $msg = "REFUSED: This test suite cannot execute against the production database.\n"
             . "Detected DB_NAME='" . DB_NAME . "' via app/config/database.php.\n"
             . "Define your own isolated DB_NAME constant BEFORE including this guard,\n"
             . "or set the environment variable EMPOWER_ALLOW_PROD_TEST=1 to override\n"
             . "(strongly discouraged -- only for genuinely read-only verification scripts\n"
             . "that a human has reviewed line by line).\n";
        fwrite(STDERR, $msg);
        echo $msg;
        exit(1);
    }

    if ($__empowerIsProd && $__empowerOverride) {
        $warn = "WARNING: EMPOWER_ALLOW_PROD_TEST=1 is set -- proceeding against PRODUCTION (" . DB_NAME . "). This is dangerous.\n";
        fwrite(STDERR, $warn);
        echo $warn;
    }
}
