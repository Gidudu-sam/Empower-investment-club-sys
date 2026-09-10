<?php
/**
 * SA-7 — Final Regression, Certification & System Freeze test suite
 * (2026-09).
 *
 * This is a CERTIFICATION suite, not a duplicate of SA-2 through SA-6's
 * own detailed test suites (which remain the authoritative evidence for
 * their own stages and are re-run in full as part of this certification).
 * This suite instead proves cross-cutting, whole-system properties:
 * that every certified suite is present and passes, that authorization/
 * security/accounting/forensic/recovery properties hold when sampled
 * across stage boundaries, that no drift occurred in the financial
 * authorization surface, and that production is provably unchanged
 * across the ENTIRE certification run (not just within one stage's own
 * suite).
 *
 * Runs primarily against empower_db_ivms_test; production (empower_db)
 * is touched only by read-only fingerprint queries.
 */
define('DB_NAME', 'empower_db_ivms_test');
chdir(__DIR__);
require 'app/config/config.php';
require 'test_safety_guard.php';
require CORE_PATH . '/Database.php';
require CORE_PATH . '/Model.php';
require CORE_PATH . '/Autoloader.php';
require CORE_PATH . '/Session.php';
require CORE_PATH . '/Controller.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $label, string $detail = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; echo "  [FAIL] $label -- $detail\n"; }
}

function runAction(string $role, string $controllerClass, string $method, array $get = [], array $post = []): array {
    $file    = __DIR__ . '/tmp_sa7_subproc_' . uniqid() . '.php';
    $outfile = __DIR__ . '/tmp_sa7_out_' . uniqid() . '.json';
    $getExport  = var_export($get, true);
    $postExport = var_export($post, true);
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
Session::set('user_role', '{$role}');
Session::set('last_activity', time());
\$_GET  = {$getExport};
\$_POST = {$postExport};
\$_SERVER['REQUEST_METHOD'] = empty(\$_POST) ? 'GET' : 'POST';
register_shutdown_function(function () {
    file_put_contents('{$outfile}', json_encode(['flash' => \$_SESSION['_flash'] ?? []]));
});
try {
    (new {$controllerClass}())->{$method}();
} catch (Throwable \$e) {
    echo 'CAUGHT_EXCEPTION: ' . get_class(\$e) . ': ' . \$e->getMessage();
}
PHP;
    file_put_contents($file, $body);
    $rawOut = shell_exec('"' . PHP_BINARY . '" "' . $file . '" 2>&1');
    $result = file_exists($outfile) ? json_decode(file_get_contents($outfile), true) : null;
    @unlink($file);
    @unlink($outfile);
    return ['flash' => $result['flash'] ?? [], 'raw' => $rawOut ?? ''];
}
function wasBlocked(array $resp): bool {
    $raw = $resp['raw'];
    $err = $resp['flash']['error'] ?? '';
    if (str_contains($raw, 'Fatal error')) return false;
    return str_contains($raw, 'Access denied') || str_contains($raw, 'privileges required') || $err !== '';
}

function productionFingerprint(): array {
    $prodPdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=empower_db;charset=' . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tables = ['members', 'savings', 'member_savings_accounts', 'loans', 'loan_repayments', 'withdrawals', 'member_fees', 'journal_entries', 'journal_lines', 'accounts', 'users', 'financial_years'];
    $fp = [];
    foreach ($tables as $t) { $fp[$t] = (int)$prodPdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn(); }
    $totals = $prodPdo->query("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines")->fetch();
    $fp['journal_total_debit'] = (float)$totals['d'];
    $fp['journal_total_credit'] = (float)$totals['c'];
    $fp['table_count'] = (int)$prodPdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn();
    return $fp;
}

$pdo = Database::getInstance()->getConnection();

echo "=== SECTION A: Production fingerprint -- BEFORE ===\n";
$prodBefore = productionFingerprint();
ok(true, 'Production fingerprint captured before SA-7 certification activity', json_encode($prodBefore));

echo "\n=== SECTION B: Baseline -- every certified suite exists and passes ===\n";
$suites = [
    'test_sa6_database_recovery.php' => null,
    'test_sa5_controlled_corrections.php' => 79,
    'test_sa4_transaction_investigation.php' => 58,
    'test_sa3_system_integrity.php' => 44,
    'test_sa2_security_hardening.php' => 38,
    'test_system_admin_role_completion.php' => 65,
    'test_treasurer_role_completion.php' => 45,
    'test_cashier_role_completion.php' => 64,
    'test_office_admin_role_completion.php' => 76,
    'test_loans_officer_role_completion.php' => 81,
    'test_chairman_role_completion.php' => 80,
    'test_role_workspace_verification.php' => 104,
];
$suiteResults = [];
foreach ($suites as $file => $expected) {
    ok(file_exists(__DIR__ . '/' . $file), "Certified suite file exists: {$file}");
    $out = shell_exec('"' . PHP_BINARY . '" ' . escapeshellarg(__DIR__ . '/' . $file) . ' 2>&1');
    preg_match('/PASS:\s*(\d+)/', $out, $mp);
    preg_match('/FAIL:\s*(\d+)/', $out, $mf);
    $p = isset($mp[1]) ? (int)$mp[1] : null;
    $f = isset($mf[1]) ? (int)$mf[1] : null;
    $suiteResults[$file] = ['pass' => $p, 'fail' => $f];
    ok($f === 0, "{$file}: 0 failures ({$p} passed)", "fail={$f}");
    if ($expected !== null) {
        ok($p === $expected, "{$file}: pass count matches the certified baseline exactly ({$expected})", "got {$p}");
    } else {
        // SA-6's own suite has a loop-based assertion count that grows
        // with accumulated recovery-history rows in the persistent
        // isolated test DB (each run adds one more row via its own
        // Section E) -- this is an explained, already-documented
        // property of that suite's design (see SA-6 report), not a
        // certification blocker. Only "0 failures" is asserted for it.
        ok($p >= 115, "test_sa6_database_recovery.php: pass count is at or above its certified floor of 115 (grows with accumulated recovery-history rows, by design)", "got {$p}");
    }
}

echo "\n=== SECTION C: Financial authorization self-check (drift since SA-6) ===\n";
$grepOut = shell_exec('grep -rn "system_admin" ' . escapeshellarg(__DIR__ . '/app/controllers') . ' ' . escapeshellarg(__DIR__ . '/app/models') . ' ' . escapeshellarg(__DIR__ . '/app/services') . ' 2>&1');
$occurrences = 0;
foreach (explode("\n", (string)$grepOut) as $line) { if (trim($line) !== '') $occurrences++; }
// 32 is the TRUE, unfiltered SA-6 baseline: the 31 figure used inside
// SA-6's own test suite excluded SA-6's own two new files (the
// established "exclude the newest stage's own files" pattern applied at
// SA-6's certification time); now that SA-6 is itself part of the frozen
// baseline going forward, its one real authorization line
// (DatabaseRecoveryController.php's role check) is correctly INCLUDED
// in this total: 31 + 1 = 32.
ok($occurrences === 32, 'Total system_admin occurrences across app/controllers+models+services match the true SA-6 baseline exactly (32) -- zero drift', "found {$occurrences}");
$filesWithOccurrences = [];
foreach (explode("\n", (string)$grepOut) as $line) {
    if (trim($line) === '') continue;
    // grep -rn output is "path:linenum:content" -- explode(':', ...)[0]
    // breaks on Windows paths (the drive-letter colon, e.g. "C:\..."),
    // so extract the path via the first ":<digits>:" marker instead.
    if (preg_match('/^(.+?):(\d+):/', $line, $m)) {
        $filesWithOccurrences[$m[1]] = true;
    }
}
$knownFiles = ['app/controllers/AccountingPeriodController.php', 'app/controllers/ChartOfAccountsController.php',
    'app/controllers/ControlledCorrectionController.php', 'app/controllers/DashboardController.php',
    'app/controllers/DatabaseRecoveryController.php', 'app/controllers/FeeController.php',
    'app/controllers/FinancialYearController.php', 'app/controllers/SettingsController.php',
    'app/controllers/SystemIntegrityController.php', 'app/controllers/TransactionInvestigationController.php',
    'app/controllers/WithdrawalPolicyController.php', 'app/services/ControlledCorrectionService.php'];
foreach ($knownFiles as $kf) {
    $matched = false;
    foreach (array_keys($filesWithOccurrences) as $f) { if (str_ends_with(str_replace('\\', '/', $f), $kf)) { $matched = true; } }
    ok($matched, "Known SA-1..SA-6 authorization file still present: {$kf}");
}
ok(count($filesWithOccurrences) === count($knownFiles), 'No new, unexpected file has gained a system_admin occurrence since SA-6', 'found ' . count($filesWithOccurrences) . ' files, expected ' . count($knownFiles));

echo "\n=== SECTION D: Role authorization boundary spot-check (direct URL, all 9 roles) ===\n";
$sensitiveRoutes = [
    ['SystemIntegrityController', 'index', 'system-integrity'],
    ['TransactionInvestigationController', 'index', 'investigation'],
    ['ControlledCorrectionController', 'index', 'corrections'],
    ['DatabaseRecoveryController', 'index', 'recovery'],
];
foreach ($sensitiveRoutes as [$class, $method, $page]) {
    $allowed = runAction('system_admin', $class, $method, ['page' => $page]);
    ok(!wasBlocked($allowed) && !str_contains($allowed['raw'], 'Fatal error'), "system_admin can access {$page} (direct URL)", $allowed['raw']);
    $allowedAdmin = runAction('admin', $class, $method, ['page' => $page]);
    ok(!wasBlocked($allowedAdmin) && !str_contains($allowedAdmin['raw'], 'Fatal error'), "admin can access {$page} (universal convention intact)", $allowedAdmin['raw']);
    foreach (['treasurer', 'cashier', 'office_admin', 'loans_officer', 'chairman', 'viewer', 'member'] as $role) {
        $denied = runAction($role, $class, $method, ['page' => $page]);
        ok(wasBlocked($denied), "{$role} is denied {$page} (direct URL, not just hidden nav)", $denied['raw']);
    }
}

echo "\n=== SECTION E: Accounting certification ===\n";
$prodPdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=empower_db;charset=' . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$unbalanced = $prodPdo->query("
    SELECT COUNT(*) FROM (
        SELECT je.id, SUM(jl.debit) d, SUM(jl.credit) c
        FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id = je.id
        GROUP BY je.id HAVING ROUND(d - c, 2) <> 0
    ) x
")->fetchColumn();
ok((int)$unbalanced === 0, 'Every posted journal entry in production remains balanced (SUM(debits) = SUM(credits))', "unbalanced count: {$unbalanced}");

$journalServiceSrc = file_get_contents(__DIR__ . '/app/services/JournalService.php');
ok(!str_contains($journalServiceSrc, 'SA-7'), 'JournalService.php carries no SA-7 modification marker -- untouched');
$correctionServiceSrc = file_get_contents(__DIR__ . '/app/services/ControlledCorrectionService.php');
ok(substr_count($correctionServiceSrc, '->reverse(') === 1 && str_contains($correctionServiceSrc, 'new JournalService()'), 'SA-5 correction workflow still calls JournalService::reverse() exactly once as its sole mutation path -- not replaced');
ok(!str_contains($correctionServiceSrc, 'SA-7'), 'ControlledCorrectionService.php carries no SA-7 modification marker -- untouched');

$auditCount = (int)$prodPdo->query("SELECT COUNT(*) FROM journal_entry_audit")->fetchColumn();
ok($auditCount > 0, 'journal_entry_audit contains historical entries (audit trail remains available)', (string)$auditCount);

echo "\n=== SECTION F: Forensic (SA-4) certification -- read-only re-confirmation ===\n";
$invSvc = new TransactionInvestigationService();
$memberId = (int)$pdo->query("SELECT id FROM members LIMIT 1")->fetchColumn();
$before = $pdo->query("SELECT COUNT(*) FROM members")->fetchColumn();
$trace = $invSvc->traceMember($memberId);
$after = $pdo->query("SELECT COUNT(*) FROM members")->fetchColumn();
ok(!empty($trace['facts']), 'Member tracing still functions and returns evidence');
ok($before == $after, 'Member tracing performed zero mutation (row count unchanged)');
$invServiceSrc = file_get_contents(__DIR__ . '/app/services/TransactionInvestigationService.php');
ok(!preg_match('/\b(INSERT|UPDATE|DELETE|TRUNCATE|ALTER|DROP|CREATE)\s+(INTO|TABLE|DATABASE)?\b/i', preg_replace('#/\*.*?\*/#s', '', $invServiceSrc)), 'TransactionInvestigationService.php still contains zero write-SQL verbs');
ok(!str_contains($invServiceSrc, 'SA-7'), 'TransactionInvestigationService.php carries no SA-7 modification marker -- untouched');

echo "\n=== SECTION G: Recovery (SA-6) certification ===\n";
$backupSvc = new BackupInventoryService();
$backups = $backupSvc->scan();
$latest = $backups[0] ?? null;
// 2026-09 update: 'pre_fixed_monthly_savings_20260902_165627.sql' was the
// certified recovery point at SA-7 time, but a real, independently-fixed
// bug in SettingsController::databaseBackup() (it crashed on every
// attempt against production's known corrupted legacy tables) means the
// backup feature now genuinely works again -- newer, real backups have
// since been created through it (by both the operator and this fix's own
// verification). A newer backup is the CORRECT outcome of that fix, not
// drift -- the actual invariant this suite must protect is that the
// registry's own "newest first" ordering is mechanically correct, not
// that one specific filename is permanently the answer.
ok($latest !== null, 'The backup registry still discovers at least one candidate backup', $latest['filename'] ?? 'none found');
if ($latest !== null) {
    $newestMtime = 0;
    foreach ($backups as $b) { $newestMtime = max($newestMtime, strtotime($b['modified_at'])); }
    ok(strtotime($latest['modified_at']) === $newestMtime, 'The registry\'s first entry is genuinely the most recently modified backup on disk (newest-first ordering is mechanically correct)', $latest['filename']);
}
$recoverySvc = new DatabaseRecoveryService();
$reflGate = new ReflectionMethod('DatabaseRecoveryService', 'assertIsolatedTarget');
$reflGate->setAccessible(true);
try { $reflGate->invoke($recoverySvc, 'empower_db'); ok(false, 'Production-target rejection must still hold'); }
catch (RuntimeException $e) { ok(true, 'Production-target rejection still holds (assertIsolatedTarget refuses "empower_db")'); }
$recoveryServiceSrc = file_get_contents(__DIR__ . '/app/services/DatabaseRecoveryService.php');
ok(!str_contains($recoveryServiceSrc, 'function executeSql'), 'No generic arbitrary-SQL executor exists in the recovery service');
$recoveryControllerSrc = file_get_contents(__DIR__ . '/app/controllers/DatabaseRecoveryController.php');
ok(str_contains($recoveryControllerSrc, "preg_match('/^[a-f0-9]{16}\$/'"), 'Controller still validates backup_id format (opaque hex id only) before resolving it');

echo "\n=== SECTION H: Public exposure re-check (structural, .htaccess text) ===\n";
$htaccess = file_get_contents(__DIR__ . '/.htaccess');
foreach (['app', 'core', 'database', 'backups', 'results', '\\.history', '\\.claude'] as $dir) {
    ok((bool)preg_match('/' . $dir . '/', $htaccess), ".htaccess still references protecting: {$dir}");
}
ok(str_contains($htaccess, 'index\\.php'), '.htaccess still carries the index.php-only FilesMatch exemption');
$publicHtaccess = @file_get_contents(__DIR__ . '/public/.htaccess');
ok($publicHtaccess !== false && str_contains($publicHtaccess, '.php'), 'public/.htaccess still blocks PHP execution in the static-assets directory');

echo "\n=== SECTION I: Destructive-operation audit (live app code) ===\n";
function scanDirForDestructive(string $dir): array {
    $found = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') continue;
        $content = file_get_contents($file->getPathname());
        // \bTRUNCATE\b alone false-positives on the Bootstrap CSS utility
        // class "text-truncate" (a real match found in app/views/layouts/
        // main.php during SA-7) -- real SQL always pairs it with TABLE.
        if (preg_match('/\bDROP\s+DATABASE\b|\bDROP\s+TABLE\b|\bTRUNCATE\s+TABLE\b/i', $content)) {
            // Normalize to forward slashes -- RecursiveDirectoryIterator's
            // getPathname() mixes separator styles on Windows depending on
            // how the base path was given, so a raw prefix-strip is
            // unreliable; comparing normalized full paths is not.
            $found[] = str_replace('\\', '/', $file->getPathname());
        }
    }
    return $found;
}
$appDestructive = scanDirForDestructive(__DIR__ . '/app');
$expectedAppDestructive = ['app/controllers/SettingsController.php', 'app/services/BackupInventoryService.php', 'app/services/DatabaseRecoveryService.php'];
sort($appDestructive); sort($expectedAppDestructive);
$matchesExpected = count($appDestructive) === count($expectedAppDestructive);
if ($matchesExpected) {
    foreach ($expectedAppDestructive as $i => $exp) {
        if (!str_ends_with($appDestructive[$i], $exp)) { $matchesExpected = false; break; }
    }
}
ok($matchesExpected, 'Every file in app/ containing DROP/TRUNCATE is a known, already-classified case (backup-dump text generation, static file scanning, or the isolated-target-only recovery executor) -- no new occurrence', json_encode($appDestructive));

echo "\n=== SECTION J: Sidebar / route consistency (Financial Years) ===\n";
$sidebarFiles = ['sidebar-chairman.php', 'sidebar-treasurer.php', 'sidebar.php', 'sidebar-system_admin.php'];
$financialYearsRoutes = [];
foreach ($sidebarFiles as $sf) {
    $content = @file_get_contents(__DIR__ . '/app/views/layouts/' . $sf);
    if ($content !== false && preg_match_all('/page=([a-z0-9-]*financial-years[a-z0-9-]*)/i', $content, $m)) {
        foreach ($m[1] as $route) { $financialYearsRoutes[$route] = true; }
    }
}
ok(count($financialYearsRoutes) === 1 && isset($financialYearsRoutes['financial-years']), 'Every role sidebar\'s "Financial Years" link points to the single canonical route -- no conflicting destination', json_encode(array_keys($financialYearsRoutes)));
ok(str_contains(file_get_contents(__DIR__ . '/app/controllers/SettingsController.php'), "redirect(APP_URL . '/index.php?page=financial-years')"), 'The legacy settings-financial-years route remains a harmless redirect stub to the canonical destination');

echo "\n=== SECTION K: Architecture presence (required services/controllers exist) ===\n";
$requiredClasses = [
    'app/services/SystemIntegrityService.php', 'app/services/TransactionInvestigationService.php',
    'app/services/ControlledCorrectionService.php', 'app/services/DatabaseRecoveryService.php',
    'app/services/BackupInventoryService.php', 'app/services/JournalService.php',
    'app/controllers/SystemIntegrityController.php', 'app/controllers/TransactionInvestigationController.php',
    'app/controllers/ControlledCorrectionController.php', 'app/controllers/DatabaseRecoveryController.php',
    'app/controllers/SettingsController.php',
];
foreach ($requiredClasses as $rc) { ok(file_exists(__DIR__ . '/' . $rc), "Required architecture file present: {$rc}"); }
$routeSrc = file_get_contents(__DIR__ . '/index.php');
foreach (['system-integrity', 'investigation', 'corrections', 'recovery', 'financial-years'] as $route) {
    ok((bool)preg_match('/[\'"]' . preg_quote($route, '/') . '[\'"]\s*=>/', $routeSrc), "Route '{$route}' is still registered in index.php");
}

echo "\n=== SECTION L: Role freeze -- certified role list unchanged ===\n";
$roleRows = $pdo->query("SELECT name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$certifiedRoles = ['admin', 'cashier', 'chairman', 'loans_officer', 'member', 'office_admin', 'system_admin', 'treasurer', 'viewer'];
sort($roleRows); sort($certifiedRoles);
ok($roleRows === $certifiedRoles, 'The roles table contains exactly the 9 certified roles -- none added, none removed', json_encode($roleRows));

echo "\n=== SECTION M: Production fingerprint -- AFTER (must be identical to Section A) ===\n";
$prodAfter = productionFingerprint();
$identical = ($prodBefore === $prodAfter);
ok($identical, 'Production fingerprint is byte-identical before and after the entire SA-7 certification run', $identical ? 'match' : json_encode(['before' => $prodBefore, 'after' => $prodAfter]));

echo "\n=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
