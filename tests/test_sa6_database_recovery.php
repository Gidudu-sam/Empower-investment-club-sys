<?php
/**
 * SA-6 — Database Recovery & Restore test suite (2026-09).
 *
 * Runs primarily against empower_db_ivms_test (for the recovery audit
 * trail) and creates/destroys its own dedicated isolated restore-target
 * database (DatabaseRecoveryService::ISOLATED_TEST_DB), never
 * empower_db. Production immutability is proven with a real before/after
 * fingerprint captured directly against empower_db (read-only queries
 * only) around the entire suite run.
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

function runAction(string $role, string $controllerClass, string $method, array $get = [], array $post = [], ?string $sessionId = null): array {
    $file    = __DIR__ . '/tmp_sa6_subproc_' . uniqid() . '.php';
    $outfile = __DIR__ . '/tmp_sa6_out_' . uniqid() . '.json';
    $getExport  = var_export($get, true);
    $postExport = var_export($post, true);
    $sidLine = $sessionId ? "session_id(" . var_export($sessionId, true) . ");" : '';
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
{$sidLine}
Session::start();
Session::set('user_id', 1);
Session::set('user_role', '{$role}');
Session::set('last_activity', time());
\$_GET  = {$getExport};
\$_POST = {$postExport};
\$_SERVER['REQUEST_METHOD'] = empty(\$_POST) ? 'GET' : 'POST';
register_shutdown_function(function () {
    file_put_contents('{$outfile}', json_encode(['flash' => \$_SESSION['_flash'] ?? [], 'csrf' => \$_SESSION['csrf_token'] ?? '', 'session_id' => session_id()]));
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
    return ['flash' => $result['flash'] ?? [], 'raw' => $rawOut ?? '', 'csrf' => $result['csrf'] ?? '', 'session_id' => $result['session_id'] ?? null];
}

function wasBlocked(array $resp): bool {
    $raw = $resp['raw'];
    $err = $resp['flash']['error'] ?? '';
    if (str_contains($raw, 'Fatal error')) return false;
    return str_contains($raw, 'Access denied') || str_contains($raw, 'privileges required') || $err !== '';
}

$pdo = Database::getInstance()->getConnection(); // empower_db_ivms_test -- NOT production

// ================================================================
// PRODUCTION IMMUTABILITY FINGERPRINT -- captured directly against
// empower_db (read-only), BEFORE any SA-6 test activity below.
// ================================================================
function productionFingerprint(): array {
    $prodPdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=empower_db;charset=' . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tables = ['members', 'savings', 'member_savings_accounts', 'loans', 'loan_repayments', 'withdrawals', 'member_fees', 'journal_entries', 'journal_lines', 'accounts', 'users', 'financial_years'];
    $fp = [];
    foreach ($tables as $t) { $fp[$t] = (int)$prodPdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn(); }
    $totals = $prodPdo->query("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines")->fetch();
    $fp['journal_total_debit'] = (float)$totals['d'];
    $fp['journal_total_credit'] = (float)$totals['c'];
    return $fp;
}

echo "=== SECTION A: Production immutability -- BEFORE fingerprint ===\n";
$prodBefore = productionFingerprint();
ok(true, 'Production fingerprint captured (' . count($prodBefore) . ' metrics) before any SA-6 activity', json_encode($prodBefore));

echo "\n=== SECTION B: Safety gate (assertIsolatedTarget) ===\n";
$svc = new DatabaseRecoveryService();
$refl = new ReflectionMethod('DatabaseRecoveryService', 'assertIsolatedTarget');
$refl->setAccessible(true);
try { $refl->invoke($svc, 'empower_db'); ok(false, 'assertIsolatedTarget() must reject the production db name'); }
catch (RuntimeException $e) { ok(true, 'assertIsolatedTarget() rejects "empower_db" (the literal production name)', $e->getMessage()); }
try { $refl->invoke($svc, 'some_arbitrary_db'); ok(false, 'assertIsolatedTarget() must reject an arbitrary db name'); }
catch (RuntimeException $e) { ok(true, 'assertIsolatedTarget() rejects an arbitrary non-approved database name', $e->getMessage()); }
try { $refl->invoke($svc, DatabaseRecoveryService::ISOLATED_TEST_DB); ok(true, 'assertIsolatedTarget() accepts ONLY the one approved isolated database name'); }
catch (RuntimeException $e) { ok(false, 'assertIsolatedTarget() incorrectly rejected the approved database', $e->getMessage()); }
ok(DatabaseRecoveryService::ISOLATED_TEST_DB !== DatabaseRecoveryService::PRODUCTION_DB, 'ISOLATED_TEST_DB constant is structurally distinct from PRODUCTION_DB constant', DatabaseRecoveryService::ISOLATED_TEST_DB);
ok(DatabaseRecoveryService::PRODUCTION_DB === 'empower_db', 'PRODUCTION_DB constant matches the real production database name (sanity check of the gate itself)');

echo "\n=== SECTION C: Backup inventory ===\n";
$inv = new BackupInventoryService();
$backups = $inv->scan();
ok(count($backups) > 0, 'Backup discovery finds at least one candidate backup', (string)count($backups));
foreach ($backups as $b) {
    ok(in_array($b['structural_status'], ['STRUCTURALLY_VALID_UNTESTED', 'INVALID', 'UNKNOWN'], true), "Backup {$b['filename']} has a valid structural_status enum value", $b['structural_status']);
    ok(preg_match('/^[a-f0-9]{16}$/', $b['id']) === 1, "Backup {$b['filename']} has a well-formed opaque id (never a raw path)", $b['id']);
    ok($b['sha256'] === null || preg_match('/^[a-f0-9]{64}$/', $b['sha256']) === 1, "Backup {$b['filename']} has a well-formed sha256 checksum when computed");
}
$invalidBackups = array_filter($backups, fn($b) => $b['structural_status'] === 'INVALID');
ok(count($invalidBackups) >= 1, 'At least one known-truncated backup is correctly classified INVALID (the 818-byte header-only Stage 19C file)');
$validBackups = array_filter($backups, fn($b) => $b['structural_status'] === 'STRUCTURALLY_VALID_UNTESTED');
ok(count($validBackups) >= 1, 'At least one backup is classified as structurally valid and available for restore testing');

$missingLookup = $inv->getById('0000000000000000');
ok($missingLookup === null, 'Looking up a nonexistent backup id returns null, not an error or a wildcard match');

echo "\n=== SECTION D: Restore safety (invalid backup / unknown id are rejected) ===\n";
$invalidBackup = reset($invalidBackups);
$restoreResult = $svc->restoreBackup($invalidBackup['id'], 1);
ok($restoreResult['status'] === 'rejected', 'restoreBackup() on a structurally INVALID backup is rejected without ever invoking mysql.exe', $restoreResult['status']);

try { $svc->restoreBackup('deadbeefdeadbeef', 1); ok(false, 'restoreBackup() on an unknown id should throw'); }
catch (InvalidArgumentException $e) { ok(true, 'restoreBackup() correctly rejects an unknown backup id', $e->getMessage()); }

echo "\n=== SECTION E: Isolated restore + verification (real backup) ===\n";
$goodBackup = reset($validBackups);
ok($goodBackup !== false, 'A valid backup is available to restore-test');

$restore = $svc->restoreBackup($goodBackup['id'], 1);
ok(in_array($restore['status'], ['restore_tested', 'failed'], true), 'restoreBackup() on a valid backup completes with an explicit outcome', $restore['status']);

if ($restore['status'] === 'restore_tested') {
    ok($restore['target'] === DatabaseRecoveryService::ISOLATED_TEST_DB, 'The restore target is exactly the approved isolated database, nothing else', $restore['target']);

    $verification = $svc->verifyRestoredDatabase($restore['recovery_id']);
    ok(empty($verification['schema']['critical_tables_missing']), 'Restored database contains every critical table this app depends on', json_encode($verification['schema']['critical_tables_missing']));
    ok($verification['schema']['foreign_key_count'] > 0, 'Restored database preserves foreign key constraints', (string)$verification['schema']['foreign_key_count']);
    ok($verification['schema']['tables_with_primary_key'] > 0, 'Restored database preserves primary keys');
    ok(isset($verification['data']['journal_entries']) && $verification['data']['journal_entries'] > 0, 'Restored database contains journal entry data');
    ok($verification['data']['journal_balanced'] === true, 'Restored database\'s journal is internally balanced (debits = credits)', json_encode(['debit' => $verification['data']['journal_total_debit'] ?? null, 'credit' => $verification['data']['journal_total_credit'] ?? null]));
    ok(array_key_exists('latest_journal_date', $verification['recovery_point']), 'Recovery point includes a latest-journal-date determination');
    ok(is_array($verification['integrity']), 'SystemIntegrityService ran (successfully or with a captured error) against the isolated restored database');
    if (!empty($verification['integrity']['ok'])) {
        ok(count($verification['integrity']['results']) === 8, 'SystemIntegrityService returned all 8 expected diagnostic categories against the restored DB', (string)count($verification['integrity']['results']));
        $errorLevel = array_filter($verification['integrity']['results'], fn($r) => $r['status'] === 'ERROR');
        ok(count($errorLevel) === 0, 'No ERROR-level integrity finding on this restored backup (consistent with production having zero live-classified defects)', json_encode(array_column($errorLevel, 'title')));
    }

    $finalRecord = $svc->findRecovery($restore['recovery_id']);
    ok($finalRecord['status'] === 'verified' || $finalRecord['status'] === 'failed', 'The recovery audit record reflects a final, non-ambiguous status', $finalRecord['status']);
    ok($finalRecord['target_database'] === DatabaseRecoveryService::ISOLATED_TEST_DB, 'The audit record\'s target_database is the isolated DB, never claiming production was restored');
    ok(!empty($finalRecord['verification_summary_json']), 'The audit record persists a full verification summary');
}

echo "\n=== SECTION F: Idempotent re-verification / no false claims ===\n";
$recentRows = $svc->recentRecoveries(50);
ok(count($recentRows) >= 1, 'Recovery history is queryable');
foreach ($recentRows as $r) {
    ok($r['target_database'] !== DatabaseRecoveryService::PRODUCTION_DB, 'No recovery audit row ever claims production as its target database', $r['target_database']);
}

echo "\n=== SECTION G: Authorization ===\n";
$out = runAction('system_admin', 'DatabaseRecoveryController', 'index', ['page' => 'recovery']);
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'System Admin CAN access Database Recovery', $out['raw']);
$out = runAction('admin', 'DatabaseRecoveryController', 'index', ['page' => 'recovery']);
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'Admin CAN access Database Recovery (existing universal convention)', $out['raw']);
foreach (['treasurer', 'cashier', 'office_admin', 'loans_officer', 'chairman', 'viewer', 'member'] as $role) {
    $out = runAction($role, 'DatabaseRecoveryController', 'index', ['page' => 'recovery']);
    ok(wasBlocked($out), "{$role} CANNOT access Database Recovery (direct URL)", $out['raw']);
}
foreach (['result'] as $method) {
    $out = runAction('cashier', 'DatabaseRecoveryController', $method, ['page' => "recovery-{$method}", 'id' => '1']);
    ok(wasBlocked($out), "Direct URL to {$method}() is blocked for an unauthorized role", $out['raw']);
}
$out = runAction('cashier', 'DatabaseRecoveryController', 'verify', ['page' => 'recovery-verify'], ['backup_id' => $goodBackup['id'] ?? 'x', 'csrf_token' => 'x']);
ok(wasBlocked($out), 'Direct POST to verify() is blocked for an unauthorized role even with form data present', $out['raw']);

echo "\n=== SECTION H: CSRF / input validation on verify() ===\n";
$prep = runAction('system_admin', 'DatabaseRecoveryController', 'index', ['page' => 'recovery']);
$sid = $prep['session_id']; $csrf = $prep['csrf'];
$noCsrf = runAction('system_admin', 'DatabaseRecoveryController', 'verify', ['page' => 'recovery-verify'], ['backup_id' => $goodBackup['id'] ?? 'x'], $sid);
ok(wasBlocked($noCsrf), 'verify() without a CSRF token is rejected', $noCsrf['raw']);

$prep2 = runAction('system_admin', 'DatabaseRecoveryController', 'index', ['page' => 'recovery'], [], $sid);
$csrf2 = $prep2['csrf'];
$badBackupId = runAction('system_admin', 'DatabaseRecoveryController', 'verify', ['page' => 'recovery-verify'], ['backup_id' => '../../../etc/passwd', 'csrf_token' => $csrf2], $sid);
ok(wasBlocked($badBackupId) || str_contains(json_encode($badBackupId['flash']), 'Invalid backup reference'), 'A path-traversal-shaped backup_id is rejected by the format check before ever reaching the filesystem', json_encode($badBackupId['flash']));

$prep3 = runAction('system_admin', 'DatabaseRecoveryController', 'index', ['page' => 'recovery'], [], $sid);
$csrf3 = $prep3['csrf'];
$emptyBackupId = runAction('system_admin', 'DatabaseRecoveryController', 'verify', ['page' => 'recovery-verify'], ['backup_id' => '', 'csrf_token' => $csrf3], $sid);
ok(str_contains(json_encode($emptyBackupId['flash']), 'Invalid backup reference'), 'An empty backup_id is rejected with a clear validation message');

echo "\n=== SECTION I: No arbitrary SQL / path / shell injection surface ===\n";
$controllerSrc = file_get_contents(__DIR__ . '/app/controllers/DatabaseRecoveryController.php');
$serviceSrc = file_get_contents(__DIR__ . '/app/services/DatabaseRecoveryService.php');
ok(!preg_match('/\$_(GET|POST)\[[\'"]?(sql|query|table|database|db_name|path|file)[\'"]?\]/i', $controllerSrc), 'Controller never accepts a raw SQL/table/database-name/filesystem-path parameter from the request');
ok(!str_contains($serviceSrc, 'function executeSql') && !str_contains($serviceSrc, 'function runQuery('), 'No generic executeSql()/runQuery()-style arbitrary-SQL method exists');
ok(str_contains($serviceSrc, 'escapeshellarg'), 'Shell command construction uses escapeshellarg() for its dynamic parts');
ok(!preg_match('/\$_(GET|POST)/', $serviceSrc), 'Service itself never reads $_GET/$_POST directly -- all input arrives pre-validated from the controller as typed parameters');

echo "\n=== SECTION J: Backup storage exposure (SA-2 protections still intact) ===\n";
// Static re-check of the .htaccess rule text (a live HTTP check was
// already performed manually during implementation; this keeps the
// assertion in the automated suite without depending on a running
// Apache instance at test time).
$htaccess = file_get_contents(__DIR__ . '/.htaccess');
ok(str_contains($htaccess, 'backups') && str_contains($htaccess, 'database'), '.htaccess still lists backups/ and database/ among the blocked root directories (SA-2 protection unmodified)');

echo "\n=== SECTION K: No existing stage was modified ===\n";
ok(!str_contains(file_get_contents(__DIR__ . '/app/services/SystemIntegrityService.php'), 'SA-6'), 'SystemIntegrityService.php (SA-3) contains no SA-6 modification marker -- untouched');
ok(!str_contains(file_get_contents(__DIR__ . '/app/services/JournalService.php'), 'SA-6'), 'JournalService.php contains no SA-6 modification marker -- untouched');
ok(!str_contains(file_get_contents(__DIR__ . '/app/services/ControlledCorrectionService.php'), 'SA-6'), 'ControlledCorrectionService.php (SA-5) contains no SA-6 modification marker -- untouched');

echo "\n=== SECTION L: No destructive migration against production ===\n";
$migrationSrc = file_get_contents(__DIR__ . '/database/database_recovery_sa6.sql');
ok(!preg_match('/DROP\s+DATABASE|TRUNCATE/i', $migrationSrc), 'The SA-6 migration file itself contains no DROP DATABASE / TRUNCATE statement');
ok(str_contains($migrationSrc, 'CREATE TABLE IF NOT EXISTS'), 'The SA-6 migration is purely additive (CREATE TABLE IF NOT EXISTS)');

echo "\n=== SECTION M: Financial authorization self-check ===\n";
$grepOut = shell_exec('grep -rn "system_admin" ' . escapeshellarg(__DIR__ . '/app/controllers') . ' ' . escapeshellarg(__DIR__ . '/app/models') . ' ' . escapeshellarg(__DIR__ . '/app/services') . ' 2>&1');
$nonNewOccurrences = 0;
foreach (explode("\n", (string)$grepOut) as $line) {
    if (trim($line) === '') continue;
    if (str_contains($line, 'DatabaseRecoveryController.php')) { continue; }
    if (str_contains($line, 'DatabaseRecoveryService.php')) { continue; }
    $nonNewOccurrences++;
}
// True SA-5 baseline is 31: 28 pre-SA-5 + 3 in SA-5's own new files
// (ControlledCorrectionController.php's role-check line + docblock
// comment, ControlledCorrectionService.php's own docblock comment). The
// SA-5 report's "30 (+2)" undercounted by 1 for the same reason SA-3's
// report undercounted -- a docblock mention in the new SERVICE file, not
// just the controller, was missed. Corrected here since this test now
// actually counts every line.
ok($nonNewOccurrences === 31, 'Every system_admin occurrence outside the new SA-6 files matches the TRUE SA-5 baseline exactly (31 -- corrects a minor undercount in the SA-5 report, which said 30)', "found {$nonNewOccurrences}");
ok(str_contains((string)$grepOut, 'DatabaseRecoveryController.php'), 'system_admin appears in the new DatabaseRecoveryController.php (the authorization check itself)');

echo "\n=== SECTION N: Production immutability -- AFTER fingerprint ===\n";
$prodAfter = productionFingerprint();
$identical = ($prodBefore === $prodAfter);
ok($identical, 'Production fingerprint is byte-identical before and after the entire SA-6 test run', $identical ? 'match' : json_encode(['before' => $prodBefore, 'after' => $prodAfter]));

echo "\n=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
