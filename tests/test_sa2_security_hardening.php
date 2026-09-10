<?php
/**
 * SA-2 — Security Hardening & Direct-Access Protection test suite.
 *
 * Unlike every other test_*.php in this project, most of what SA-2 changed
 * is Apache/.htaccess behavior, which cannot be exercised by requiring
 * PHP files in-process -- it requires real HTTP requests against a
 * running server. This suite shells out to curl for every HTTP-layer
 * assertion, and only falls back to in-process PHP for the one thing that
 * genuinely is PHP-level (the CLI-only guard's PHP_SAPI check).
 *
 * REQUIRES: Apache and MySQL both running locally (XAMPP) BEFORE this
 * script is invoked -- it does not start them itself. Run from the CLI:
 *   php test_sa2_security_hardening.php
 *
 * Does not touch empower_db_ivms_test or any other test database -- this
 * suite exercises server/routing behavior and read-only application pages
 * only, never a financial write.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    die("Forbidden: this script may only be run from the command line.\n");
}

define('BASE', 'http://localhost/empower');
// shell_exec() on Windows runs via cmd.exe, which has no /dev/null.
define('NULL_SINK', stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null');

$pass = 0; $fail = 0;
function ok(bool $c, string $label, string $detail = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; echo "  [FAIL] $label -- $detail\n"; }
}

function httpCode(string $path): string {
    $url = BASE . '/' . ltrim($path, '/');
    $cmd = 'curl -s -o ' . NULL_SINK . ' -w "%{http_code}" ' . escapeshellarg($url);
    return trim((string)shell_exec($cmd));
}

function httpBody(string $path): string {
    $url = BASE . '/' . ltrim($path, '/');
    $cmd = 'curl -s ' . escapeshellarg($url);
    return (string)shell_exec($cmd);
}

// Preflight: confirm the server is actually reachable before asserting
// anything -- a connection failure must not silently read as "blocked".
$preflight = httpCode('index.php');
if ($preflight === '' || $preflight === '000') {
    echo "ABORTED: Apache does not appear to be running at " . BASE . " (got '{$preflight}').\n";
    echo "Start XAMPP's Apache and MySQL, then re-run this suite.\n";
    exit(1);
}

echo "=== SECTION A: Root PHP scripts are BLOCKED (except index.php) ===\n";
$rootScriptsThatMustBeBlocked = [
    'config.php',
    'check_mysql.php',
    'verify_forensic_integrity.php',
    'test_safety_guard.php',
    'test_chairman_role_completion.php',
    'test_system_admin_role_completion.php',
    'create_production_period.php',
    'step4_db_repair.php',
    'setup_legacy_period.php',
    'stage18_readonly_report_check.php',
    'generate_step4_report.php',
    'backfill_compulsory_accounts.php',
    'test_sa2_security_hardening.php', // this very file
];
foreach ($rootScriptsThatMustBeBlocked as $f) {
    $code = httpCode($f);
    ok($code === '403', "Direct HTTP access to /{$f} is blocked", "got HTTP {$code}");
}

echo "\n=== SECTION B: Non-public directories are BLOCKED ===\n";
$backupFiles = glob(__DIR__ . '/backups/*.sql');
$sampleBackup = $backupFiles ? basename($backupFiles[0]) : null;
if ($sampleBackup) {
    $code = httpCode('backups/' . $sampleBackup);
    ok($code === '403', "Direct download of a real backups/*.sql file is blocked", "got HTTP {$code}");
} else {
    ok(true, "backups/*.sql check skipped (no backup files present to test against)");
}
$code = httpCode('backups/dump_errors_20260827_102032.log');
ok(in_array($code, ['403', '404'], true), "backups/ mysqldump error log is not downloadable", "got HTTP {$code}");

$code = httpCode('results/stage10_evidence/01_seven_tables_diagnostic.php');
ok($code === '403', "results/ direct-PDO diagnostic script cannot be executed via HTTP", "got HTTP {$code}");

$code = httpCode('stage5_page_action_alignment_audit/01_action_inventory.md');
ok($code === '403', "stage5_page_action_alignment_audit/ internal report is not browsable", "got HTTP {$code}");

if (is_dir(__DIR__ . '/.history')) {
    $code = httpCode('.history/config_20260824163549.php');
    ok(in_array($code, ['403', '404'], true), ".history/ editor snapshots are not downloadable", "got HTTP {$code}");
}
if (is_dir(__DIR__ . '/.claude')) {
    $code = httpCode('.claude/settings.local.json');
    ok(in_array($code, ['403', '404'], true), ".claude/ tooling config is not downloadable", "got HTTP {$code}");
}

echo "\n=== SECTION C: public/ folder -- PHP blocked, static assets still served ===\n";
$code = httpCode('public/income-statement-index.php');
ok($code === '403', "Orphaned public/income-statement-index.php cannot be executed via HTTP", "got HTTP {$code}");

$cssFiles = glob(__DIR__ . '/public/css/*.css');
if ($cssFiles) {
    $code = httpCode('public/css/' . basename($cssFiles[0]));
    ok($code === '200', "Legitimate CSS asset in public/css/ still loads", "got HTTP {$code}");
}
$jsFiles = glob(__DIR__ . '/public/js/*.js');
if ($jsFiles) {
    $code = httpCode('public/js/' . basename($jsFiles[0]));
    ok($code === '200', "Legitimate JS asset in public/js/ still loads", "got HTTP {$code}");
}

echo "\n=== SECTION D: Application/authentication is unaffected ===\n";
$code = httpCode('index.php');
ok($code === '200', "index.php (front controller) still loads", "got HTTP {$code}");

$code = httpCode('index.php?page=login');
ok($code === '200', "Login page still loads", "got HTTP {$code}");

$headers = (string)shell_exec('curl -s -D - -o ' . NULL_SINK . ' ' . escapeshellarg(BASE . '/index.php?page=dashboard'));
ok(str_contains($headers, '302') && str_contains($headers, 'page=login'),
    "Unauthenticated request to a protected page (dashboard) is redirected to login, not served", substr($headers, 0, 300));

$code = httpCode('app/config/database.php');
ok($code === '403', "Direct access to app/ (already-existing rule) remains blocked", "got HTTP {$code}");

$code = httpCode('database/accounting_engine.sql');
ok(in_array($code, ['403', '404'], true), "Direct access to database/*.sql (already-existing rule) remains blocked", "got HTTP {$code}");

echo "\n=== SECTION E: CLI-only guard rejects non-CLI execution, allows CLI ===\n";
// Simulate a non-CLI SAPI in-process is not possible without a real web
// request (PHP_SAPI is read-only for the life of the process), so this
// re-uses the same HTTP check as Section A/B for the guarded scripts --
// their 403 there is enforced by .htaccess. This section instead proves
// the SECOND layer (the PHP-level guard itself) is present in source, and
// that legitimate CLI execution is NOT blocked by it.
$guardedFiles = [
    'check_mysql.php', 'create_production_period.php', 'generate_step4_report.php',
    'setup_legacy_period.php', 'stage18_readonly_report_check.php', 'step4_db_repair.php',
    'verify_forensic_integrity.php', 'backfill_compulsory_accounts.php',
];
foreach ($guardedFiles as $f) {
    $src = file_get_contents(__DIR__ . '/' . $f);
    $hasGuard = str_contains($src, 'cli_only_guard.php') || str_contains($src, 'php_sapi_name() !== ') || str_contains($src, "PHP_SAPI !== 'cli'");
    ok($hasGuard, "{$f} has an explicit CLI-only guard in source (defense-in-depth beyond .htaccess)");
}
$src = file_get_contents(__DIR__ . '/test_safety_guard.php');
ok(str_contains($src, "PHP_SAPI !== 'cli'"), "test_safety_guard.php itself now enforces CLI-only (hardens all test_*.php that include it)");

// Legitimate CLI execution must still work -- run a trivial guarded
// script via a real CLI subprocess and confirm it does NOT get the
// "Forbidden" message.
$out = shell_exec('"' . PHP_BINARY . '" ' . escapeshellarg(__DIR__ . '/check_mysql.php') . ' 2>&1');
ok(!str_contains((string)$out, 'Forbidden'), "Legitimate CLI execution of a guarded script (check_mysql.php) is NOT blocked", (string)$out);

echo "\n=== SECTION F: Legitimate authenticated backup download is unaffected ===\n";
// databaseDownload() reads backups/<file> server-side via PHP's own
// readfile(), a filesystem operation -- entirely independent of the
// Apache-level RewriteRule that now blocks direct browser requests to
// that same path. Proven by direct controller invocation (subprocess,
// since the action calls exit()) rather than raw HTTP, since exercising
// the real HTTP route would require a logged-in browser session/cookie
// this CLI suite does not have.
if ($sampleBackup) {
    $probe = __DIR__ . '/tmp_sa2_backup_probe_' . uniqid() . '.php';
    $body = <<<PHP
<?php
chdir(__DIR__);
define('DB_NAME', 'empower_db_ivms_test');
require 'app/config/config.php';
require 'test_safety_guard.php';
require CORE_PATH . '/Database.php';
require CORE_PATH . '/Model.php';
require CORE_PATH . '/Autoloader.php';
require CORE_PATH . '/Session.php';
require CORE_PATH . '/Controller.php';
Session::start();
Session::set('user_id', 1);
Session::set('user_role', 'system_admin');
Session::set('last_activity', time());
\$_GET = ['page' => 'settings-db-download', 'file' => '{$sampleBackup}'];
(new SettingsController())->databaseDownload();
PHP;
    file_put_contents($probe, $body);
    $out = shell_exec('"' . PHP_BINARY . '" "' . $probe . '" 2>&1');
    @unlink($probe);
    ok(str_contains((string)$out, '-- MariaDB dump') || str_contains((string)$out, '-- MySQL dump') || str_contains((string)$out, 'CREATE TABLE'),
        "Authenticated databaseDownload() controller action still serves real backup content", substr((string)$out, 0, 150));
} else {
    ok(true, "Backup download check skipped (no backup files present to test against)");
}

echo "\n=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
