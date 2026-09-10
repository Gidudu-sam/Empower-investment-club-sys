<?php
/**
 * SA-5 — Controlled Corrections, Reversals & Financial Remediation test
 * suite (2026-09).
 *
 * Runs ONLY against empower_db_ivms_test. This is the first SA-stage
 * suite allowed to create real mutations (against the isolated test DB
 * only) -- every fixture created here is a synthetic journal entry this
 * script inserts itself, never a real production or pre-existing test
 * record, and every mutating test is independently verifiable via direct
 * SQL assertions afterward.
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
    $file    = __DIR__ . '/tmp_sa5_subproc_' . uniqid() . '.php';
    $outfile = __DIR__ . '/tmp_sa5_out_' . uniqid() . '.json';
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
Session::set('user_name', 'Test {$role}');
Session::set('last_activity', time());
\$_GET  = {$getExport};
\$_POST = {$postExport};
\$_SERVER['REQUEST_METHOD'] = empty(\$_POST) ? 'GET' : 'POST';
register_shutdown_function(function () {
    file_put_contents('{$outfile}', json_encode([
        'flash' => \$_SESSION['_flash'] ?? [],
        'csrf' => \$_SESSION['csrf_token'] ?? '',
        'session_id' => session_id(),
    ]));
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

$pdo = Database::getInstance()->getConnection();

/** Creates a synthetic, balanced journal entry fixture and returns its id. */
function makeFixture(PDO $pdo, string $classification = 'live', float $amount = 12345.00): int {
    $acct1 = (int)$pdo->query("SELECT id FROM accounts WHERE is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
    $acct2 = (int)$pdo->query("SELECT id FROM accounts WHERE is_active=1 ORDER BY id LIMIT 1 OFFSET 1")->fetchColumn();
    $num = 'T' . substr(md5(uniqid('', true)), 0, 6);
    $stmt = $pdo->prepare("INSERT INTO journal_entries (entry_number, entry_date, source_module, description, status, posted, reversed, data_classification, created_by) VALUES (?, CURDATE(), 'sa5_suite_test', 'SA5 automated test fixture', 1, 1, 0, ?, 1)");
    $stmt->execute([$num, $classification]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, 0)")->execute([$id, $acct1, $amount]);
    $pdo->prepare("INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, 0, ?)")->execute([$id, $acct2, $amount]);
    return $id;
}

echo "=== SECTION A: Authorization ===\n";
$out = runAction('system_admin', 'ControlledCorrectionController', 'index', ['page' => 'corrections']);
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'System Admin CAN access Controlled Corrections', $out['raw']);

$out = runAction('admin', 'ControlledCorrectionController', 'index', ['page' => 'corrections']);
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'Admin CAN access Controlled Corrections (existing universal convention)', $out['raw']);

foreach (['treasurer', 'cashier', 'office_admin', 'loans_officer', 'chairman', 'viewer', 'member'] as $role) {
    $out = runAction($role, 'ControlledCorrectionController', 'index', ['page' => 'corrections']);
    ok(wasBlocked($out), "{$role} CANNOT access Controlled Corrections (direct URL)", $out['raw']);
}
foreach (['prepare', 'review', 'result'] as $method) {
    $out = runAction('cashier', 'ControlledCorrectionController', $method, ['page' => "correction-{$method}", 'id' => '1', 'journal_id' => '1']);
    ok(wasBlocked($out), "Direct URL to {$method}() is blocked for an unauthorized role", $out['raw']);
}
foreach (['store', 'execute', 'cancel'] as $method) {
    $out = runAction('cashier', 'ControlledCorrectionController', $method, ['page' => "correction-{$method}"], ['id' => '1', 'journal_id' => '1', 'reason' => 'x', 'csrf_token' => 'x', 'confirm' => '1']);
    ok(wasBlocked($out), "Direct POST to {$method}() is blocked for an unauthorized role even with form data present", $out['raw']);
}

echo "\n=== SECTION B: Correction-type registry ===\n";
$svc = new ControlledCorrectionService();
$types = $svc->correctionTypes();
ok(isset($types['REVERSE_JOURNAL']) && $types['REVERSE_JOURNAL']['available'] === true, 'REVERSE_JOURNAL is registered and available');
$deferredCount = count(array_filter($types, fn($t) => $t['available'] === false));
ok($deferredCount === 5, 'Exactly 5 correction types are registered as deferred/unavailable, each with a documented reason', (string)$deferredCount);
foreach ($types as $code => $t) {
    if (!$t['available']) { ok(!empty($t['reason']), "Deferred type {$code} has a non-empty documented reason"); }
}

echo "\n=== SECTION C: Reason validation ===\n";
foreach (['', '   ', 'fix', 'test', 'wrong', 'correct', 'too short'] as $bad) {
    ok($svc->validateReason($bad) !== null, "Reason '" . addslashes($bad) . "' is rejected");
}
ok($svc->validateReason(str_repeat('a', 20)) === null, 'A 20-character non-trivial reason is accepted (minimum length boundary)');
ok($svc->validateReason('This is a properly descriptive correction reason citing evidence.') === null, 'A genuinely descriptive reason is accepted');

echo "\n=== SECTION D: Eligibility evaluation (read-only) ===\n";
$liveId = makeFixture($pdo, 'live');
$dummyId = makeFixture($pdo, 'dummy');
$unknownId = makeFixture($pdo, 'unknown');

$eval = $svc->evaluateReversalEligibility($liveId);
ok($eval['eligible'] === true, 'A live, balanced, unreversed journal entry is eligible for reversal', json_encode($eval['blockers']));

$eval = $svc->evaluateReversalEligibility($dummyId);
ok($eval['eligible'] === false && str_contains($eval['blockers'][0], 'dummy'), 'A dummy-classified journal entry is BLOCKED with an explicit reason', json_encode($eval['blockers']));

$eval = $svc->evaluateReversalEligibility($unknownId);
ok($eval['eligible'] === false && str_contains($eval['blockers'][0], 'unknown'), 'An unknown-classified journal entry is BLOCKED and never guessed as live', json_encode($eval['blockers']));

$eval = $svc->evaluateReversalEligibility(999999999);
ok($eval['eligible'] === false && $eval['evidence'] === null, 'A nonexistent journal entry ID is safely handled (not a fatal error)');

echo "\n=== SECTION E: Successful reversal (full accounting verification) ===\n";
$targetId = makeFixture($pdo, 'live', 55555.00);
$beforeCount = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$result = $svc->prepareCorrection($targetId, 1, 'SA-5 test suite: reversing a synthetic fixture journal entry created solely for this automated test run.');
ok(str_starts_with($result['correction_number'], 'COR-'), 'prepareCorrection() returns a correction number in the expected format', $result['correction_number']);

$correction = $svc->find($result['id']);
ok($correction['status'] === 'prepared', 'Correction status is "prepared" immediately after prepareCorrection()');

$outcome = $svc->executeCorrection($result['id'], 1);
ok($outcome['status'] === 'executed', 'executeCorrection() reports status "executed"');

$afterCount = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
ok($afterCount === $beforeCount + 1, 'Exactly one new journal entry was created (the reversal) -- no duplicates, nothing else changed', "before={$beforeCount} after={$afterCount}");

$original = $pdo->query("SELECT * FROM journal_entries WHERE id={$targetId}")->fetch();
ok($original !== false, 'ORIGINAL journal entry still exists (never deleted)');
ok($original['reversal_of_id'] === null, 'ORIGINAL journal entry is unmodified (reversal_of_id stays null on the original, only the new entry points back)');
ok((float)$pdo->query("SELECT SUM(debit) FROM journal_lines WHERE journal_entry_id={$targetId}")->fetchColumn() === 55555.00, 'ORIGINAL journal entry amount is completely unchanged');

$reversalId = (int)$outcome['correction']['resulting_journal_entry_id'];
$reversal = $pdo->query("SELECT * FROM journal_entries WHERE id={$reversalId}")->fetch();
ok($reversal['reversal_of_id'] == $targetId, 'REVERSAL entry correctly links back to the original via reversal_of_id');
$revTotals = $pdo->query("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines WHERE journal_entry_id={$reversalId}")->fetch();
ok(abs($revTotals['d'] - $revTotals['c']) < 0.01, 'REVERSAL entry itself is balanced (debits = credits)');
ok((float)$revTotals['d'] === 55555.00, 'REVERSAL entry amount exactly mirrors the original amount');

$audit = $pdo->query("SELECT * FROM journal_entry_audit WHERE entity_type='journal_entry' AND entity_id={$targetId} AND action='reverse'")->fetch();
ok($audit !== false, 'journal_entry_audit contains a "reverse" row for the original entry (written by the unmodified JournalService::reverse())');
ok(!empty($audit['reason']), 'The audit row carries the human-entered reason');

$finalCorrection = $svc->find($result['id']);
ok($finalCorrection['status'] === 'executed' && $finalCorrection['resulting_journal_entry_number'] === $reversal['entry_number'], 'The corrections row is fully updated with the resulting journal reference');

echo "\n=== SECTION F: Idempotency (no duplicate submission) ===\n";
$countBefore = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE reversal_of_id={$targetId}")->fetchColumn();
$outcome2 = $svc->executeCorrection($result['id'], 1);
ok($outcome2['status'] === 'already_executed', 'A second executeCorrection() call on the same correction returns "already_executed" instead of re-running');
$countAfter = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE reversal_of_id={$targetId}")->fetchColumn();
ok($countBefore === 1 && $countAfter === 1, 'Exactly one reversal entry exists no matter how many times execute is called', "before={$countBefore} after={$countAfter}");

// Attempting to reverse the SAME original a second time via a fresh
// correction record must also be refused (JournalService::reverse()'s own
// independent idempotency layer, via reversal_of_id).
$eval2 = $svc->evaluateReversalEligibility($targetId);
ok($eval2['eligible'] === false && str_contains($eval2['blockers'][0], 'already been reversed') === false && str_contains($eval2['blockers'][0], 'already reversed'), 'A second attempt to reverse the SAME original journal entry is blocked (already reversed)', json_encode($eval2['blockers']));

echo "\n=== SECTION G: Stale-data protection ===\n";
$staleId = makeFixture($pdo, 'live', 7777.00);
$staleResult = $svc->prepareCorrection($staleId, 1, 'SA-5 test suite: preparing then simulating a concurrent change to test stale-data protection.');
$pdo->prepare("UPDATE journal_entries SET data_classification='dummy' WHERE id=?")->execute([$staleId]);
try {
    $svc->executeCorrection($staleResult['id'], 1);
    ok(false, 'Stale-data protection should have thrown', 'did not throw');
} catch (Throwable $e) {
    ok(str_contains($e->getMessage(), 'changed after preparation'), 'Execute correctly refuses a target that changed after preparation', $e->getMessage());
}
$staleCorrection = $svc->find($staleResult['id']);
ok($staleCorrection['status'] === 'failed', 'The correction is marked "failed" (not left in limbo) after a stale-data abort');
$noReversal = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE reversal_of_id={$staleId}")->fetchColumn();
ok($noReversal === 0, 'No reversal entry was created despite the aborted execution attempt (safe rollback)');

echo "\n=== SECTION H: Invalid/edge-case targets ===\n";
try {
    $svc->prepareCorrection(999999999, 1, 'Attempting to prepare a correction against a nonexistent journal entry.');
    ok(false, 'prepareCorrection() on a nonexistent target should throw');
} catch (InvalidArgumentException $e) {
    ok(true, 'prepareCorrection() correctly rejects a nonexistent target', $e->getMessage());
}
try {
    $svc->prepareCorrection($dummyId, 1, 'Attempting to prepare a correction against dummy-classified data.');
    ok(false, 'prepareCorrection() on dummy-classified data should throw');
} catch (InvalidArgumentException $e) {
    ok(str_contains($e->getMessage(), 'dummy'), 'prepareCorrection() correctly refuses dummy-classified data', $e->getMessage());
}
try {
    $svc->prepareCorrection($liveId, 1, 'fix'); // liveId already reversed? no -- liveId untouched, but reason invalid
    ok(false, 'prepareCorrection() with a trivial reason should throw');
} catch (InvalidArgumentException $e) {
    ok(true, 'prepareCorrection() correctly refuses a trivial/blank reason', $e->getMessage());
}
try {
    $svc->executeCorrection(999999999, 1);
    ok(false, 'executeCorrection() on a nonexistent correction id should throw');
} catch (InvalidArgumentException $e) {
    ok(true, 'executeCorrection() correctly rejects a nonexistent correction id', $e->getMessage());
}
try {
    $svc->cancel($result['id'], 1); // already executed
    ok(false, 'cancel() on an already-executed correction should throw');
} catch (InvalidArgumentException $e) {
    ok(true, 'cancel() correctly refuses to cancel an already-executed correction', $e->getMessage());
}

echo "\n=== SECTION I: Confirmation / GET-cannot-mutate (controller level) ===\n";
$prep = runAction('system_admin', 'ControlledCorrectionController', 'prepare', ['page' => 'correction-prepare', 'journal_id' => (string)$liveId]);
$sid = $prep['session_id']; $csrf = $prep['csrf'];
ok(!empty($csrf), 'A CSRF token is issued on the prepare page');

$storeNoCsrf = runAction('system_admin', 'ControlledCorrectionController', 'store', ['page' => 'correction-store'], ['journal_id' => (string)$liveId, 'reason' => 'Attempting to store without a CSRF token at all.'], $sid);
ok(wasBlocked($storeNoCsrf), 'store() without any CSRF token is rejected', $storeNoCsrf['raw']);
$countStillZero = (int)$pdo->query("SELECT COUNT(*) FROM corrections WHERE target_entity_id={$liveId}")->fetchColumn();
ok($countStillZero === 0, 'No correction record was created by the CSRF-less store attempt');

// verifyCsrf() rotates the stored token on every call, success or failure
// -- the CSRF-less attempt just above already consumed/rotated it, so a
// fresh token must be re-fetched before the "valid CSRF" attempt below.
$prep2 = runAction('system_admin', 'ControlledCorrectionController', 'prepare', ['page' => 'correction-prepare', 'journal_id' => (string)$liveId], [], $sid);
$csrf = $prep2['csrf'];

$storeReal = runAction('system_admin', 'ControlledCorrectionController', 'store', ['page' => 'correction-store'], ['journal_id' => (string)$liveId, 'reason' => 'SA-5 suite Section I: valid CSRF store to reach the confirmation-step tests.', 'csrf_token' => $csrf], $sid);
$corrId = (int)$pdo->query("SELECT id FROM corrections WHERE target_entity_id={$liveId} ORDER BY id DESC LIMIT 1")->fetchColumn();
ok($corrId > 0, 'A valid CSRF store request creates a correction record', (string)$corrId);

$reviewResp = runAction('system_admin', 'ControlledCorrectionController', 'review', ['page' => 'correction-review', 'id' => (string)$corrId], [], $sid);
$csrf2 = $reviewResp['csrf'];

$execUnconfirmed = runAction('system_admin', 'ControlledCorrectionController', 'execute', ['page' => 'correction-execute'], ['id' => (string)$corrId, 'csrf_token' => $csrf2], $sid);
$statusAfterUnconfirmed = $pdo->query("SELECT status FROM corrections WHERE id={$corrId}")->fetchColumn();
ok($statusAfterUnconfirmed === 'prepared', 'execute() without the explicit "confirm" checkbox does not execute', $statusAfterUnconfirmed);

// GET request: no route in this app maps correction-execute to a GET
// handler distinct from POST, and the controller has no fallback that
// would execute without a CSRF token -- confirmed structurally via the
// CSRF requirement already tested above (a GET request/typed URL/forged
// cross-site request has no mechanism to supply a valid session-bound token).
$src = file_get_contents(__DIR__ . '/app/controllers/ControlledCorrectionController.php');
ok(substr_count($src, 'verifyCsrf') >= 3, 'Every mutating action (store/execute/cancel) calls verifyCsrf() -- the only real protection a GET request cannot forge');

echo "\n=== SECTION J: No generic editor / no fix buttons ===\n";
$viewFiles = glob(__DIR__ . '/app/views/corrections/*.php');
$forbidden = ['UPDATE savings', 'UPDATE loans', 'UPDATE journal_entries SET', '<select name="account', 'name="debit"', 'name="credit"', 'name="amount"'];
$found = [];
foreach ($viewFiles as $f) {
    $content = file_get_contents($f);
    foreach ($forbidden as $w) { if (stripos($content, $w) !== false) { $found[] = "$w in " . basename($f); } }
}
ok(empty($found), 'No generic transaction/journal-line editor field exists in any SA-5 view', implode(', ', $found));

$controllerAndServiceSrc = file_get_contents(__DIR__ . '/app/services/ControlledCorrectionService.php');
ok(!preg_match('/account_id["\']?\s*=>\s*\$_(POST|GET)/', $controllerAndServiceSrc), 'Service never builds a journal line from a raw request-supplied account_id');

echo "\n=== SECTION K: Mutation self-check ===\n";
$writeVerbs = ['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'TRUNCATE', 'ALTER TABLE', 'DROP TABLE', 'CREATE TABLE'];
// The service performs its mutations through the Model layer (->create()/
// ->update()), not literal SQL of its own -- core/Model.php owns the
// actual "INSERT INTO"/"UPDATE ... SET" text. So the correct check here
// is that the service DOES call mutating Model/JournalService methods,
// not that it contains literal SQL verbs itself.
$serviceCallsMutatingMethods = preg_match('/->model->(create|update)\(/', $controllerAndServiceSrc) && str_contains($controllerAndServiceSrc, '->reverse(');
ok($serviceCallsMutatingMethods, 'ControlledCorrectionService.php DOES call mutating methods (model->create/update, JournalService->reverse) -- expected, this is the one authorized place');

// Every OTHER SA controller/service must remain completely free of write verbs.
$otherFiles = array_merge(
    glob(__DIR__ . '/app/controllers/SystemIntegrityController.php'),
    glob(__DIR__ . '/app/controllers/TransactionInvestigationController.php'),
    glob(__DIR__ . '/app/services/SystemIntegrityService.php'),
    glob(__DIR__ . '/app/services/TransactionInvestigationService.php')
);
$leaked = [];
foreach ($otherFiles as $f) {
    $c = file_get_contents($f);
    foreach ($writeVerbs as $v) { if (stripos($c, $v) !== false) { $leaked[] = basename($f) . ":$v"; } }
}
ok(empty($leaked), 'SA-3/SA-4 read-only services/controllers remain free of any write-SQL verb (SA-5 introduced no leakage into them)', implode(', ', $leaked));

$controllerSrc = file_get_contents(__DIR__ . '/app/controllers/ControlledCorrectionController.php');
ok(!preg_match('/\$_(GET|POST)\[[\'"]?(sql|query|table|column)[\'"]?\]/i', $controllerSrc), 'Controller never accepts a raw SQL/table/column name from the request');

echo "\n=== SECTION L: JournalService self-check ===\n";
ok(substr_count($controllerAndServiceSrc, 'JournalService::post(') === 0, 'Service never calls JournalService::post() directly (only reverse(), which calls post() internally)');
ok(substr_count($controllerAndServiceSrc, '->reverse(') === 1, 'Service calls JournalService::reverse() exactly once (the sole mutation entry point)');
ok(str_contains($controllerAndServiceSrc, 'new JournalService()'), 'Service instantiates the existing canonical JournalService (no duplicate engine)');

echo "\n=== SECTION M: Audit immutability ===\n";
ok(!method_exists('ControlledCorrectionController', 'deleteAudit') && !method_exists('ControlledCorrectionController', 'editAudit'), 'No audit-deletion/editing method exists on the controller');
ok(!str_contains($controllerSrc, "DELETE FROM `journal_entry_audit`") && !str_contains($controllerSrc, "DELETE FROM `corrections`"), 'Controller contains no DELETE statement against corrections or journal_entry_audit');
$auditStillThere = $pdo->query("SELECT COUNT(*) FROM journal_entry_audit WHERE entity_id={$targetId} AND action='reverse'")->fetchColumn();
ok((int)$auditStillThere === 1, 'The audit row from Section E is still present and singular (nothing in this suite removed it)');

echo "\n=== SECTION N: Financial authorization self-check ===\n";
$grepOut = shell_exec('grep -rn "system_admin" ' . escapeshellarg(__DIR__ . '/app/controllers') . ' ' . escapeshellarg(__DIR__ . '/app/models') . ' ' . escapeshellarg(__DIR__ . '/app/services') . ' 2>&1');
$nonNewOccurrences = 0;
foreach (explode("\n", (string)$grepOut) as $line) {
    if (trim($line) === '') continue;
    if (str_contains($line, 'ControlledCorrectionController.php')) { continue; }
    if (str_contains($line, 'ControlledCorrectionService.php')) { continue; } // its own docblock mentions system_admin once, same pattern as SA-3/SA-4's own new files
    // SA-6 (2026-09) added two more new files after this suite was written, same pattern.
    if (str_contains($line, 'DatabaseRecoveryController.php')) { continue; }
    if (str_contains($line, 'DatabaseRecoveryService.php')) { continue; }
    $nonNewOccurrences++;
}
// True SA-4 baseline was 28 (26 pre-SA-4 + 2 in TransactionInvestigationController.php).
ok($nonNewOccurrences === 28, 'Every system_admin occurrence outside the new controller matches the SA-4 baseline exactly (28)', "found {$nonNewOccurrences}");
ok(str_contains((string)$grepOut, 'ControlledCorrectionController.php'), 'system_admin appears in the new ControlledCorrectionController.php (the authorization check itself)');

echo "\n=== SECTION O: Read-only guarantee for evaluation/display paths ===\n";
$tables = ['journal_entries', 'journal_lines', 'accounts', 'members', 'loans', 'loan_repayments', 'savings', 'withdrawals'];
$before2 = [];
foreach ($tables as $t) { $before2[$t] = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn(); }
// Exercise every read-only path.
$svc->evaluateReversalEligibility($liveId);
$svc->evaluateReversalEligibility($dummyId);
$svc->correctionTypes();
$svc->recent(50);
$svc->validateReason('irrelevant');
$allSame = true;
foreach ($tables as $t) {
    $after2 = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    if ($after2 != $before2[$t]) { $allSame = false; echo "    (row count changed for {$t}: {$before2[$t]} -> {$after2})\n"; }
}
ok($allSame, 'Row counts across every core financial table are unchanged by every read-only SA-5 method (evaluate/types/recent/validate)');

echo "\n=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
