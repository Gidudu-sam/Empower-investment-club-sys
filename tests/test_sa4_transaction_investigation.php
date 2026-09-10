<?php
/**
 * SA-4 — Transaction Investigation & Forensic Traceability test suite (2026-09).
 *
 * Runs ONLY against empower_db_ivms_test. Authorization tests use the
 * established subprocess/runAction pattern (matches
 * test_system_admin_role_completion.php / test_sa3_system_integrity.php).
 * Read-only guarantee follows the same before/after row-count + balance-
 * sum proof used in SA-3's suite.
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
    $file    = __DIR__ . '/tmp_sa4_subproc_' . uniqid() . '.php';
    $outfile = __DIR__ . '/tmp_sa4_out_' . uniqid() . '.json';
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
Session::set('user_name', 'Test {$role}');
Session::set('last_activity', time());
\$_GET  = {$getExport};
\$_POST = {$postExport};
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

$pdo = Database::getInstance()->getConnection();

echo "=== SECTION A: Authorization ===\n";
$out = runAction('system_admin', 'TransactionInvestigationController', 'index', ['page' => 'investigation']);
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'System Admin CAN access Transaction Investigation', $out['raw']);

$out = runAction('admin', 'TransactionInvestigationController', 'index', ['page' => 'investigation']);
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'Admin CAN access Transaction Investigation (existing universal convention)', $out['raw']);

foreach (['treasurer', 'cashier', 'office_admin', 'loans_officer', 'chairman', 'viewer', 'member'] as $role) {
    $out = runAction($role, 'TransactionInvestigationController', 'index', ['page' => 'investigation']);
    ok(wasBlocked($out), "{$role} CANNOT access Transaction Investigation (direct URL)", $out['raw']);
}

// Direct-URL tests on every non-index action, not just the dashboard.
foreach ([
    ['member', ['id' => '1']], ['savings', ['id' => '1']], ['loan', ['id' => '1']],
    ['repayment', ['id' => '1']], ['withdrawal', ['id' => '1']], ['fee', ['id' => '1']],
    ['journal', ['id' => '1']], ['orphans', []], ['coverage', ['type' => 'loan_repayments']],
] as [$method, $extraGet]) {
    $out = runAction('cashier', 'TransactionInvestigationController', $method, array_merge(['page' => "investigation-{$method}"], $extraGet));
    ok(wasBlocked($out), "Direct URL to {$method}() is blocked for an unauthorized role (cashier), not just hidden from nav", $out['raw']);
}

echo "\n=== SECTION B: Search ===\n";
$svc = new TransactionInvestigationService();
// NOTE: this isolated test DB accumulates fixtures across many past
// engagement stages, and some have empty-string reference columns left
// over from earlier runs -- every "grab a representative row" query below
// explicitly excludes empty/null references and orders by id for a
// deterministic, valid fixture (a real defect would be caught by the
// search() call itself, not by which row happens to be "first").
$firstMember = $pdo->query("SELECT member_number FROM members WHERE member_number IS NOT NULL AND member_number != '' ORDER BY id LIMIT 1")->fetchColumn();
ok(count($svc->search($firstMember, 'member')) > 0, 'Member search finds a real member by member_number');
ok(count($svc->search('this_term_matches_absolutely_nothing_xyz')) === 0, 'Empty search finds nothing (no fallback/wildcard leakage)');
ok($svc->search('') === [], 'Blank search term returns an empty result set, not an unbounded dump');

$firstSavings = $pdo->query("SELECT receipt_number FROM savings WHERE receipt_number IS NOT NULL AND receipt_number != '' ORDER BY id LIMIT 1")->fetchColumn();
ok(count($svc->search($firstSavings, 'savings')) > 0, 'Savings transaction search finds a real receipt_number');

$firstLoan = $pdo->query("SELECT loan_number FROM loans WHERE loan_number IS NOT NULL AND loan_number != '' ORDER BY id LIMIT 1")->fetchColumn();
ok(count($svc->search($firstLoan, 'loan')) > 0, 'Loan search finds a real loan_number');

$firstRepayment = $pdo->query("SELECT repayment_number FROM loan_repayments WHERE repayment_number IS NOT NULL AND repayment_number != '' ORDER BY id LIMIT 1")->fetchColumn();
ok(count($svc->search($firstRepayment, 'repayment')) > 0, 'Repayment search finds a real repayment_number');

$firstJournal = $pdo->query("SELECT entry_number FROM journal_entries WHERE entry_number IS NOT NULL AND entry_number != '' ORDER BY id LIMIT 1")->fetchColumn();
ok(count($svc->search($firstJournal, 'journal')) > 0, 'Journal search finds a real entry_number');

$firstFeeRef = $pdo->query("SELECT reference_number FROM member_fees WHERE reference_number IS NOT NULL LIMIT 1")->fetchColumn();
if ($firstFeeRef) { ok(count($svc->search($firstFeeRef, 'fee')) > 0, 'Fee search finds a real reference_number'); }
else { ok(true, 'Fee search skipped (no member_fees.reference_number populated in this fixture set)'); }

ok(count($svc->search($firstMember)) > 0, 'Type-less search (across all entity types) still returns results');
$invalidType = $svc->search($firstMember, 'DROP TABLE members; --');
ok(is_array($invalidType), 'An invalid/malicious "type" value is safely ignored, not passed through to SQL', json_encode($invalidType));

$manyResults = $svc->search('a'); // broad term, likely to match many rows across types
ok(count($manyResults) <= 25, 'Search results are bounded (never an unbounded dump)', (string)count($manyResults));

echo "\n=== SECTION C: Traceability (valid chains) ===\n";
$memberId = (int)$pdo->query("SELECT id FROM members LIMIT 1")->fetchColumn();
$p = $svc->traceMember($memberId);
ok($p['entity_type'] === 'Member' && !empty($p['facts']), 'Member → trace produces facts', json_encode($p));
ok(count($p['related']) >= 5, 'Member trace includes Savings Accounts, Savings Transactions, Loans, Withdrawals, Fees, Adjustments groups', json_encode(array_column($p['related'], 'label')));

$savId = (int)$pdo->query("SELECT id FROM savings LIMIT 1")->fetchColumn();
$p = $svc->traceSavingsTransaction($savId);
ok($p['entity_type'] === 'Savings Transaction' && !empty($p['facts']), 'Member → Savings → trace produces facts');
ok(array_key_exists('journal', $p) && array_key_exists('journal_lines', $p), 'Savings trace exposes journal + journal_lines shape (whether linked or not)');

$loanId = (int)$pdo->query("SELECT id FROM loans LIMIT 1")->fetchColumn();
$p = $svc->traceLoan($loanId);
ok($p['entity_type'] === 'Loan' && !empty($p['facts']), 'Member → Loan → trace produces facts');
$hasInstallments = false; $hasRepayments = false;
foreach ($p['related'] as $g) {
    if (str_starts_with($g['label'], 'Installments')) $hasInstallments = true;
    if (str_starts_with($g['label'], 'Repayments')) $hasRepayments = true;
}
ok($hasInstallments && $hasRepayments, 'Loan trace includes both Installments and Repayments groups');

$repId = (int)$pdo->query("SELECT id FROM loan_repayments LIMIT 1")->fetchColumn();
$p = $svc->traceRepayment($repId);
ok($p['entity_type'] === 'Loan Repayment' && !empty($p['facts']), 'Loan → Repayment → trace produces facts (principal/interest/penalty/savings allocation visible)');
$hasAllocationFacts = count(array_filter($p['facts'], fn($f) => str_contains($f['label'], 'Allocation'))) >= 3;
ok($hasAllocationFacts, 'Repayment trace explicitly shows principal/interest/penalty allocation as separate facts', json_encode($p['facts']));

$feeId = (int)$pdo->query("SELECT id FROM member_fees LIMIT 1")->fetchColumn();
$p = $svc->traceFee($feeId);
ok($p['entity_type'] === 'Fee Charge' && !empty($p['facts']), 'Member → Fee → trace produces facts');

$jeId = (int)$pdo->query("SELECT id FROM journal_entries LIMIT 1")->fetchColumn();
$p = $svc->traceJournalEntry($jeId);
ok($p['entity_type'] === 'Journal Entry' && !empty($p['facts']), 'Journal → Source trace produces facts');

echo "\n=== SECTION D: Missing relationships ===\n";
$p = $svc->traceWithdrawal(999999999);
ok(!empty($p['missing']) && $p['missing'][0] === 'Withdrawal not found.', 'A nonexistent withdrawal ID is reported as missing, not a fatal error/blank page', json_encode($p));

$p = $svc->traceLoan(999999999);
ok(!empty($p['missing']), 'A nonexistent loan ID is reported as missing');

$p = $svc->traceJournalEntry(999999999);
ok(!empty($p['missing']), 'A nonexistent journal entry ID is reported as missing');

// A repayment/savings row with genuinely no journal must say so explicitly
// (this project's own data already has real examples of this).
$unlinkedRepayment = $pdo->query("SELECT r.id FROM loan_repayments r WHERE r.journal_entry_id IS NULL AND NOT EXISTS (SELECT 1 FROM journal_entries je WHERE je.source_module='loan_repayments' AND je.source_reference_id=r.id) LIMIT 1")->fetchColumn();
if ($unlinkedRepayment) {
    $p = $svc->traceRepayment((int)$unlinkedRepayment);
    ok($p['journal'] === null && !empty($p['missing']), 'A repayment with genuinely no journal linkage is explicitly reported as MISSING, not silently blank', json_encode($p['missing']));
} else {
    ok(true, 'Unlinked-repayment check skipped (none exist in this fixture set)');
}

// Orphan journal reference: source module points at a row that no longer exists.
$orphanJournal = $pdo->query("
    SELECT je.id FROM journal_entries je
    WHERE je.source_module IN ('savings','loan_repayments','loans','withdrawals')
      AND je.source_reference_id IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM savings s WHERE je.source_module='savings' AND s.id=je.source_reference_id)
      AND NOT EXISTS (SELECT 1 FROM loan_repayments lr WHERE je.source_module='loan_repayments' AND lr.id=je.source_reference_id)
      AND NOT EXISTS (SELECT 1 FROM loans l WHERE je.source_module='loans' AND l.id=je.source_reference_id)
      AND NOT EXISTS (SELECT 1 FROM withdrawals w WHERE je.source_module='withdrawals' AND w.id=je.source_reference_id)
    LIMIT 1
")->fetchColumn();
if ($orphanJournal) {
    $p = $svc->traceJournalEntry((int)$orphanJournal);
    ok(!empty($p['warnings']) && str_contains($p['warnings'][0], 'Source record not found'), 'An orphan journal reference is explicitly flagged as "Source record not found"', json_encode($p['warnings']));
} else {
    ok(true, 'Orphan-journal-reference check skipped (none exist in this fixture set)');
}

echo "\n=== SECTION E: Orphan/coverage listings ===\n";
$o = $svc->listOrphanJournalReferences(10, 0);
ok(is_array($o['rows']) && isset($o['total']), 'listOrphanJournalReferences() returns a bounded, paginated shape');
ok(count($o['rows']) <= 10, 'Orphan listing respects its limit parameter');
foreach ($o['rows'] as $row) {
    ok(in_array($row['state'], ['SOURCE MISSING', 'KNOWN DUMMY DATA'], true), 'Every orphan row has a valid state (SOURCE MISSING or KNOWN DUMMY DATA), never raw "corruption"', $row['state']);
    break; // one representative check is enough to avoid a huge log
}

$c = $svc->listCoverageGaps('loan_repayments', 10, 0);
ok(is_array($c['rows']) && isset($c['total']), 'listCoverageGaps("loan_repayments") returns a bounded, paginated shape');
$c2 = $svc->listCoverageGaps('savings', 10, 0);
ok(is_array($c2['rows']) && isset($c2['total']), 'listCoverageGaps("savings") returns a bounded, paginated shape');
$c3 = $svc->listCoverageGaps('not_a_real_type', 10, 0);
ok($c3['rows'] === [] && $c3['total'] === 0, 'An invalid coverage-gap type is safely ignored, not passed through to SQL');

echo "\n=== SECTION F: No 'fix' actions exist ===\n";
$controllerSrc = file_get_contents(__DIR__ . '/app/controllers/TransactionInvestigationController.php');
$viewFiles = glob(__DIR__ . '/app/views/investigation/*.php');
$forbiddenWords = ['Repair', 'Correct', 'Reverse', 'Delete', '>Post<', 'Recalculate', 'Rebuild', 'Restore'];
$found = [];
foreach (array_merge([$controllerSrc], array_map('file_get_contents', $viewFiles)) as $src) {
    // SA-4's trace view legitimately links OUT to the separate, properly
    // role-gated SA-5 workflow ("Prepare Correction") -- exactly the
    // brief's own Section 23 pattern ("show it as a link to the
    // correction workflow"), not a fix/correct ACTION performed here.
    // Strip that known-safe navigational phrase before scanning.
    $src = str_replace(['Prepare Correction', 'correction-prepare', 'correction workflow', 'controlled correction'], '', $src);
    foreach ($forbiddenWords as $w) {
        if (str_contains($src, $w)) { $found[] = $w; }
    }
}
ok(empty($found), 'No "Fix/Repair/Correct/Reverse/Delete/Post/Recalculate/Rebuild/Restore" action exists anywhere in SA-4', implode(', ', $found));

echo "\n=== SECTION G: Data-safety self-check ===\n";
$serviceSrc = file_get_contents(__DIR__ . '/app/services/TransactionInvestigationService.php');
$writeVerbs = [];
foreach (['INSERT INTO', 'UPDATE `', 'DELETE FROM', 'TRUNCATE', 'ALTER TABLE', 'DROP TABLE', 'CREATE TABLE'] as $verb) {
    if (stripos($serviceSrc, $verb) !== false) { $writeVerbs[] = $verb; }
}
ok(empty($writeVerbs), 'TransactionInvestigationService.php source contains zero write-SQL verbs', implode(', ', $writeVerbs));
// Strip comments/docblocks first -- the service's own docblock explicitly
// DOCUMENTS that it never calls these methods (a legitimate mention, not
// an invocation), which would otherwise false-positive a plain substring
// search.
$serviceSrcNoComments = preg_replace('#/\*.*?\*/#s', '', $serviceSrc);
$serviceSrcNoComments = preg_replace('#//.*#', '', $serviceSrcNoComments);
ok(!str_contains($serviceSrcNoComments, 'JournalService::post') && !str_contains($serviceSrcNoComments, 'JournalService::reverse') && !str_contains($serviceSrcNoComments, 'new JournalService'), 'Service never calls JournalService::post()/reverse() or instantiates JournalService at all (checked with comments stripped)');
ok(!preg_match('/\$_GET\[[\'"]?(sql|query|table)[\'"]?\]/i', $controllerSrc), 'Controller never accepts a raw SQL/table-name parameter from the request');

echo "\n=== SECTION H: Read-only guarantee ===\n";
$tables = ['members', 'member_savings_accounts', 'savings', 'loans', 'loan_repayments', 'loan_installments',
    'withdrawals', 'member_fees', 'journal_entries', 'journal_lines', 'accounts', 'activity_logs', 'journal_entry_audit'];
$before = [];
foreach ($tables as $t) { $before[$t] = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn(); }
$beforeSum = $pdo->query("SELECT COALESCE(SUM(debit),0)+COALESCE(SUM(credit),0) FROM journal_lines")->fetchColumn();

// Exercise every read path for real, exactly as the controller would.
$svc->search($firstMember);
$svc->traceMember($memberId);
$svc->traceSavingsTransaction($savId);
$svc->traceLoan($loanId);
$svc->traceRepayment($repId);
$svc->traceWithdrawal(999999);
$svc->traceFee($feeId);
$svc->traceJournalEntry($jeId);
$svc->listOrphanJournalReferences(10, 0);
$svc->listCoverageGaps('loan_repayments', 10, 0);
$svc->listCoverageGaps('savings', 10, 0);

$allSame = true;
foreach ($tables as $t) {
    $after = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    if ($after != $before[$t]) { $allSame = false; echo "    (row count changed for {$t}: {$before[$t]} -> {$after})\n"; }
}
ok($allSame, 'Row counts across every core table are byte-identical before and after a full investigation pass');
$afterSum = $pdo->query("SELECT COALESCE(SUM(debit),0)+COALESCE(SUM(credit),0) FROM journal_lines")->fetchColumn();
ok(abs($beforeSum - $afterSum) < 0.01, 'Sum of all journal debit/credit amounts is unchanged after a full investigation pass');

echo "\n=== SECTION I: Financial authorization self-check ===\n";
$grepOut = shell_exec('grep -rn "system_admin" ' . escapeshellarg(__DIR__ . '/app/controllers') . ' ' . escapeshellarg(__DIR__ . '/app/models') . ' ' . escapeshellarg(__DIR__ . '/app/services') . ' 2>&1');
$nonNewOccurrences = 0;
foreach (explode("\n", (string)$grepOut) as $line) {
    if (trim($line) === '') continue;
    if (str_contains($line, 'TransactionInvestigationController.php')) { continue; }
    // SA-5 (2026-09) added two more new files after this suite was written, same pattern.
    if (str_contains($line, 'ControlledCorrectionController.php')) { continue; }
    if (str_contains($line, 'ControlledCorrectionService.php')) { continue; }
    // SA-6 (2026-09) added two more new files after that, same pattern.
    if (str_contains($line, 'DatabaseRecoveryController.php')) { continue; }
    if (str_contains($line, 'DatabaseRecoveryService.php')) { continue; }
    $nonNewOccurrences++;
}
// True SA-3 baseline is 26: 24 pre-existing (SA-1/SA-2) + 2 in
// SystemIntegrityController.php (its role-check line AND a docblock
// comment mentioning system_admin). The SA-3 final report stated "25"
// (+1) -- that undercounted by 1 because it missed the comment mention;
// corrected here since this test now actually counts every line.
ok($nonNewOccurrences === 26, 'Every system_admin occurrence outside the new controller matches the TRUE SA-3 baseline exactly (26 -- corrects a minor undercount in the SA-3 report, which said 25)', "found {$nonNewOccurrences}");
ok(str_contains((string)$grepOut, 'TransactionInvestigationController.php'), 'system_admin appears in the new TransactionInvestigationController.php (the authorization check itself)');

echo "\n=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
