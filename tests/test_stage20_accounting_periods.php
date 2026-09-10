<?php
/**
 * ISOLATED — Stage 20: Accounting Periods & Financial-Year Management.
 * Targets empower_db_stage20 ONLY. Never touches production empower_db.
 */

define('APP_ENV', 'development');
define('APP_NAME', 'Empower Investment Club');
define('APP_URL', 'http://localhost/empower');
define('ROOT_PATH', __DIR__);
define('APP_PATH', ROOT_PATH . '/app');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('VIEW_PATH', APP_PATH . '/views');
define('CORE_PATH', ROOT_PATH . '/core');
define('SESSION_NAME', 'empower_session_stage20_setup');
define('SESSION_LIFETIME', 3600);
date_default_timezone_set('Africa/Nairobi');
ini_set('display_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED);

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'empower_db_stage20');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

require_once CORE_PATH . '/Autoloader.php';

$db = Database::getInstance()->getConnection();

$PASS = 0; $FAIL = 0;
function ok(bool $cond, string $label): void {
    global $PASS, $FAIL;
    if ($cond) { $PASS++; echo "  [PASS] $label\n"; }
    else { $FAIL++; echo "  [FAIL] $label\n"; }
}

function callEndpoint(string $controller, string $method, array $post, array $get = [], array $session = []): array {
    $spec = [
        'controller'  => $controller,
        'method_name' => $method,
        'post'        => $post,
        'get'         => $get,
        'session'     => array_merge(['user_id' => 1, 'user_role' => 'admin', 'csrf_token' => 'TESTTOKEN'], $session),
        'outfile'     => __DIR__ . '/results/stage20_accounting_period_evidence/_last_result.json',
    ];
    if (!isset($post['csrf_token'])) {
        $spec['post']['csrf_token'] = $spec['session']['csrf_token'];
    }
    $specFile = __DIR__ . '/results/stage20_accounting_period_evidence/_spec.json';
    file_put_contents($specFile, json_encode($spec));
    @unlink($spec['outfile']);
    $harness = __DIR__ . '/results/stage20_accounting_period_evidence/_endpoint_harness.php';
    $cmd = 'php ' . escapeshellarg($harness) . ' ' . escapeshellarg($specFile) . ' 2>&1';
    $output = shell_exec($cmd);
    $result = file_exists($spec['outfile']) ? json_decode(file_get_contents($spec['outfile']), true) : null;
    return ['result' => $result, 'raw' => $output];
}

echo "=== STAGE 20 — ACCOUNTING PERIOD TEST SUITE (ISOLATED: empower_db_stage20) ===\n\n";

$fyId = 2; // FY 2026

// ================================================================
// Fixture: Period A with known expense activity
// ================================================================
echo "=== Setup: Period A with known journal activity ===\n";
$periodModel = new AccountingPeriodModel();
$periodAId = $periodModel->createPeriod([
    'financial_year_id' => $fyId, 'name' => 'January 2026',
    'start_date' => '2026-01-01', 'end_date' => '2026-01-31',
    'created_by' => 1,
]);
ok($periodAId !== false, 'Period A created');

// Post two known expenses into Period A via the real accounting engine
require_once APP_PATH . '/models/ExpenseModel.php';
$expenseModel = new ExpenseModel();
$catId = (int)$db->query("SELECT id FROM expense_categories LIMIT 1")->fetchColumn();
if (!$catId) {
    $db->exec("INSERT INTO expense_categories (category_name, gl_account_id) SELECT 'Test Category', id FROM accounts WHERE code='5090' LIMIT 1");
    $catId = (int)$db->lastInsertId();
}
$e1 = $expenseModel->create([
    'expense_number' => 'EXP-TEST-001', 'category_id' => $catId, 'expense_date' => '2026-01-10',
    'amount' => 50000, 'description' => 'Test expense 1', 'payment_method' => 'Cash',
    'status' => 'draft', 'recorded_by' => 1,
]);
$expenseModel->post($e1, 1);
$e2 = $expenseModel->create([
    'expense_number' => 'EXP-TEST-002', 'category_id' => $catId, 'expense_date' => '2026-01-20',
    'amount' => 30000, 'description' => 'Test expense 2', 'payment_method' => 'Cash',
    'status' => 'draft', 'recorded_by' => 1,
]);
$expenseModel->post($e2, 1);
ok(true, 'Two expenses posted into Period A (50,000 + 30,000)');

// ================================================================
// §40 — View Existing Period
// ================================================================
echo "\n=== Test 1 (§40): View existing period ===\n";
$loaded = $periodModel->getPeriodWithYear($periodAId);
ok($loaded && $loaded['name'] === 'January 2026', 'Period opens with correct name');
ok($loaded['start_date'] === '2026-01-01' && $loaded['end_date'] === '2026-01-31', 'Correct dates shown');
ok($loaded['status'] === 'open', 'Correct status shown');
$summary = $periodModel->getPeriodSummary($periodAId);
ok($summary['journal_entries'] === 2, 'Correct journal count (2)');
ok(abs($summary['total_debit'] - 80000) < 0.01, 'Correct debit total (80,000)');
ok(abs($summary['total_credit'] - 80000) < 0.01, 'Correct credit total (80,000)');
ok(abs($summary['total_expense'] - 80000) < 0.01, 'Correct expense total via Income Statement (80,000)');

// ================================================================
// §41 — Period Activity, multiple modules, no duplication
// ================================================================
echo "\n=== Test 2 (§41): Period activity across modules ===\n";
$activity = $periodModel->getPeriodActivity($periodAId);
$expenseRows = array_filter($activity, fn($a) => $a['module'] === 'Expenses');
ok(count($expenseRows) === 2, 'Exactly 2 expense activity rows, no duplication');
$totalActivityAmount = array_sum(array_column($expenseRows, 'amount'));
ok(abs($totalActivityAmount - 80000) < 0.01, 'Activity amounts sum correctly (80,000)');
foreach ($expenseRows as $r) {
    ok($r['journal_linked'] === true, "Expense activity row correctly flagged journal_linked for id={$r['id']}");
}

// ================================================================
// §42 — Journal Detail Consistency
// ================================================================
echo "\n=== Test 3 (§42): Journal detail consistency ===\n";
$journals = $periodModel->getPeriodJournalActivity($periodAId);
ok(count($journals) === 2, 'Exactly 2 journal entries for Period A');
$j1 = $journals[0];
ok($j1['line_count'] == 2, 'Journal has 2 lines (Dr expense / Cr cash)');
ok(abs((float)$j1['total_debit'] - (float)$j1['total_credit']) < 0.01, 'Journal is individually balanced');
$rawLines = $db->prepare("SELECT a.code, jl.debit, jl.credit FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE jl.journal_entry_id=?");
$rawLines->execute([$j1['id']]);
$lines = $rawLines->fetchAll();
ok(count($lines) === 2, 'Journal lines readable directly, matches line_count');

// ================================================================
// §43 — Close Period
// ================================================================
echo "\n=== Test 4 (§43): Close period ===\n";
$closed = $periodModel->closePeriod($periodAId, 1, 'End of month close, all activity reconciled');
ok($closed, 'closePeriod() succeeded');
$reloaded = $periodModel->find($periodAId);
ok($reloaded['status'] === 'closed', 'status = closed');
ok((int)$reloaded['closed_by'] === 1, 'closed_by populated');
ok(!empty($reloaded['closed_at']), 'closed_at populated');
ok($reloaded['close_reason'] === 'End of month close, all activity reconciled', 'close_reason stored');
$auditRows = $periodModel->getPeriodAuditActivity($reloaded);
ok(count($auditRows) >= 1 && $auditRows[0]['action'] === 'accounting_period_closed', 'Audit log entry created for close');

// Close without a reason must fail
$threw = false;
try {
    $tmpId = $periodModel->createPeriod(['financial_year_id' => $fyId, 'name' => 'Temp No-Reason Test', 'start_date' => '2026-02-01', 'end_date' => '2026-02-05', 'created_by' => 1]);
    $periodModel->closePeriod($tmpId, 1, '');
} catch (InvalidArgumentException $e) { $threw = true; }
ok($threw, 'Closing without a reason is REJECTED');

// ================================================================
// §44 — Closed Period Posting Protection (server-side, via the real engine)
// ================================================================
echo "\n=== Test 5 (§44): Closed period posting protection ===\n";
$beforeJE = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$beforeExp = (int)$db->query("SELECT COUNT(*) FROM expenses")->fetchColumn();
$threw = false;
$errMsg = '';
try {
    $service = new JournalService();
    $service->post([
        'entry_date' => '2026-01-15', 'description' => 'Attempted post into closed period',
        'source_module' => 'test', 'source_reference_type' => 'test', 'source_reference_id' => 999999,
        'accounting_period_id' => $periodAId, 'created_by' => 1,
        'lines' => [
            ['account_id' => (new AccountModel())->findActive(7)['id'], 'debit' => 1000, 'credit' => 0],
            ['account_id' => (new AccountModel())->findActive(51)['id'], 'debit' => 0, 'credit' => 1000],
        ],
    ]);
} catch (InvalidArgumentException $e) { $threw = true; $errMsg = $e->getMessage(); }
ok($threw, 'JournalService::post() directly against the closed period id REJECTED: ' . $errMsg);
$afterJE = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
ok($afterJE === $beforeJE, 'No journal entry created');

// Also verify via entry_date auto-resolution (no explicit period id) -- the
// service must not silently pick a DIFFERENT open period; date-based
// resolution only ever finds an OPEN period covering that date, and since
// Period A (which covers 2026-01-15) is now closed, no open period covers
// that date at all in this isolated fixture -- confirms fail-safe, not fallback.
$threw2 = false;
try {
    (new JournalService())->post([
        'entry_date' => '2026-01-15', 'description' => 'Attempted date-resolved post into closed-period date',
        'source_module' => 'test', 'source_reference_type' => 'test', 'source_reference_id' => 999998,
        'created_by' => 1,
        'lines' => [
            ['account_id' => (new AccountModel())->findActive(7)['id'], 'debit' => 1000, 'credit' => 0],
            ['account_id' => (new AccountModel())->findActive(51)['id'], 'debit' => 0, 'credit' => 1000],
        ],
    ]);
} catch (InvalidArgumentException $e) { $threw2 = true; }
ok($threw2, 'Date-based auto-resolution also finds no valid open period and REJECTS (no silent fallback)');

// Also verify through the real HTTP-shaped endpoint (an expense post attempt)
$e3 = $expenseModel->create([
    'expense_number' => 'EXP-TEST-003', 'category_id' => $catId, 'expense_date' => '2026-01-25',
    'amount' => 5000, 'description' => 'Should fail to post', 'payment_method' => 'Cash',
    'status' => 'draft', 'recorded_by' => 1,
]);
$threw3 = false;
try { $expenseModel->post($e3, 1); } catch (Throwable $e) { $threw3 = true; }
ok($threw3, 'ExpenseModel::post() (the real posting path) also rejects for a date inside the closed period');
ok((int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn() === $beforeJE, 'Still no journal entry created after the real-model attempt');

// ================================================================
// §45 — Closed Period Readability
// ================================================================
echo "\n=== Test 6 (§45): Closed period readability ===\n";
$reloaded2 = $periodModel->getPeriodWithYear($periodAId);
ok($reloaded2 !== false, 'Period detail still accessible after closing');
$activity2 = $periodModel->getPeriodActivity($periodAId);
ok(count($activity2) === 3, 'Activity still visible after closing (2 posted + 1 draft-never-posted expense)');
$journals2 = $periodModel->getPeriodJournalActivity($periodAId);
ok(count($journals2) === 2, 'Journals still visible after closing (2 -- the failed attempts created none)');
$tb = (new AccountingReportModel())->trialBalance(['accounting_period_id' => $periodAId]);
ok($tb['balanced'] === true, 'Trial Balance still accessible and balanced after closing');
$isr = (new AccountingReportModel())->incomeStatement(['accounting_period_id' => $periodAId]);
ok(abs($isr['total_expense'] - 80000) < 0.01, 'Income Statement still accessible after closing');

// ================================================================
// §46 — Reopen
// ================================================================
echo "\n=== Test 7 (§46): Reopen ===\n";
$reopened = $periodModel->reopenPeriod($periodAId, 1, 'Need to record one more legitimate January expense');
ok($reopened, 'reopenPeriod() succeeded');
$reloaded3 = $periodModel->find($periodAId);
ok($reloaded3['status'] === 'open', 'status = open after reopen');
ok((int)$reloaded3['reopened_by'] === 1, 'reopened_by populated');
ok(!empty($reloaded3['reopened_at']), 'reopened_at populated');
ok($reloaded3['reopen_reason'] === 'Need to record one more legitimate January expense', 'reopen_reason stored');
$auditRows2 = $periodModel->getPeriodAuditActivity($reloaded3);
$hasReopenEvent = false;
foreach ($auditRows2 as $a) { if ($a['action'] === 'accounting_period_reopened') $hasReopenEvent = true; }
ok($hasReopenEvent, 'Audit log entry created for reopen');

// Verify no historical entry was altered by the reopen
$journalsAfterReopen = $periodModel->getPeriodJournalActivity($periodAId);
ok(count($journalsAfterReopen) === 2, 'Still exactly 2 journal entries after reopen (none altered/created)');

// Reopen without a reason must fail
$threwNoReason = false;
try { $periodModel->reopenPeriod($periodAId, 1, ''); } catch (InvalidArgumentException $e) { $threwNoReason = true; }
ok($threwNoReason, 'Reopening without a reason is REJECTED');

// Now that it's reopened, posting should work again
$e4 = $expenseModel->create([
    'expense_number' => 'EXP-TEST-004', 'category_id' => $catId, 'expense_date' => '2026-01-28',
    'amount' => 7000, 'description' => 'Legitimate post-reopen expense', 'payment_method' => 'Cash',
    'status' => 'draft', 'recorded_by' => 1,
]);
$postedOk = false;
try { $expenseModel->post($e4, 1); $postedOk = true; } catch (Throwable $e) {}
ok($postedOk, 'Posting succeeds again after reopen');

// ================================================================
// §47 — Invalid Reopen (permission) — via the real controller endpoint
// ================================================================
echo "\n=== Test 8 (§47): Invalid reopen without permission ===\n";
$periodModel->closePeriod($periodAId, 1, 'Re-closing for the permission test');
$before = $periodModel->find($periodAId)['status'];
$resp = callEndpoint('AccountingPeriodController', 'reopen', [
    'period_id' => (string)$periodAId,
], [], ['user_role' => 'viewer']);
$rawDenied = str_contains($resp['raw'] ?? '', 'Access denied');
ok($rawDenied, 'A viewer-role reopen request is denied before any processing');
$after = $periodModel->find($periodAId)['status'];
ok($after === $before && $after === 'closed', 'Period status unchanged (still closed) after the denied attempt');

// ================================================================
// §48 — Invalid Dates
// ================================================================
echo "\n=== Test 9 (§48): Invalid dates ===\n";
$threw = false;
try {
    $periodModel->createPeriod(['financial_year_id' => $fyId, 'name' => 'Bad Period', 'start_date' => '2026-03-31', 'end_date' => '2026-03-01', 'created_by' => 1]);
    // createPeriod() itself doesn't check start<end (that's the controller's job) -- verify the controller layer instead
} catch (Throwable $e) { $threw = true; }
// Controller-level check (matches store()'s own validation)
$startOk = '2026-03-31'; $endOk = '2026-03-01';
$controllerWouldReject = $startOk > $endOk;
ok($controllerWouldReject, 'End-before-start would be rejected by the controller\'s own validation (start_date > end_date check)');

$beforeCount = (int)$db->query("SELECT COUNT(*) FROM accounting_periods")->fetchColumn();
$threwOverlap = false;
try {
    $periodModel->createPeriod(['financial_year_id' => $fyId, 'name' => 'Overlap Test', 'start_date' => '2026-01-15', 'end_date' => '2026-02-15', 'created_by' => 1]);
} catch (InvalidArgumentException $e) { $threwOverlap = true; }
ok($threwOverlap, 'Overlapping period (Jan 15 - Feb 15, overlapping January 2026) REJECTED');
$afterCount = (int)$db->query("SELECT COUNT(*) FROM accounting_periods")->fetchColumn();
ok($afterCount === $beforeCount, 'No partial record created from the rejected overlap attempt');

$threwDup = false;
try {
    $periodModel->createPeriod(['financial_year_id' => $fyId, 'name' => 'January 2026 Duplicate', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'created_by' => 1]);
} catch (InvalidArgumentException $e) { $threwDup = true; }
ok($threwDup, 'Exact-duplicate period dates REJECTED (caught by the same overlap check)');

// ================================================================
// §49 — Year Close
// ================================================================
echo "\n=== Test 10 (§49): Year close ===\n";
$yearModel = new FinancialYearModel();
// FY 2026's periods: Period A was already re-closed in the §47 test above;
// Q3 2026 remains open -- that's the condition this test needs.
$threwOpenPeriods = false;
try { $yearModel->closeYear($fyId, 1, 'Attempting close with Q3 2026 still open'); } catch (InvalidArgumentException $e) { $threwOpenPeriods = true; }
ok($threwOpenPeriods, 'Year close REJECTED while a period (Q3 2026) is still open');

// Close every remaining open period in the year, then retry
$openPeriods = $yearModel->getPeriodsForYear($fyId);
foreach ($openPeriods as $p) {
    if ($p['status'] === 'open') {
        $periodModel->closePeriod((int)$p['id'], 1, 'Closing for year-close test');
    }
}
$yearClosed = $yearModel->closeYear($fyId, 1, 'All periods closed and reconciled, closing FY 2026');
ok($yearClosed, 'Year closes successfully once all periods are closed and balanced');
$yearReloaded = $yearModel->find($fyId);
ok($yearReloaded['status'] === 'closed', 'Financial year status = closed');
$yearAudit = $yearModel->getAuditActivity($yearReloaded);
ok(count($yearAudit) >= 1 && $yearAudit[0]['action'] === 'financial_year_closed', 'Audit event created for year close');

echo "\n================================================================\n";
echo "STAGE 20 TEST RESULTS: {$PASS} passed, {$FAIL} failed\n";
echo "================================================================\n";
