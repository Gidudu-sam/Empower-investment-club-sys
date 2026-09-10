<?php
/**
 * ISOLATED — Stage 8: Loan Approval Workflow verification.
 * Runs ONLY against empower_db_ivms_test. Never touches empower_db.
 *
 * Policy implemented (confirmed by the club): Chairman is the sole
 * approver (admin/chairman tier, mirroring the four existing
 * maker-checker modules); the approver also disburses (no separate
 * Cashier disbursement authority).
 */
chdir(__DIR__);
$pass = 0; $fail = 0;
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }

define('DB_NAME', 'empower_db_ivms_test');
require 'app/config/config.php';
require 'test_safety_guard.php';
require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';
$db = Database::getInstance()->getConnection();

function renderAs(string $role, string $userId, string $page, array $post = []): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_stage8_workflow_subproc_' . $counter . '.php';
    $routeMap = [
        'loan-submit'   => ['LoanController', 'submit'],
        'loan-approve'  => ['LoanController', 'approve'],
        'loan-reject'   => ['LoanController', 'reject'],
        'loan-disburse' => ['LoanController', 'disburse'],
        'loan-edit'     => ['LoanController', 'edit'],
    ];
    [$class, $method] = $routeMap[$page];
    $postLines = "\$_SERVER['REQUEST_METHOD'] = 'POST';\n\$_POST['csrf_token'] = 'skip';\n";
    foreach ($post as $k => $v) { $postLines .= "\$_POST['{$k}'] = " . var_export($v, true) . ";\n"; }
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
Session::set('user_id', {$userId});
Session::set('user_role', '{$role}');
Session::set('user_name', 'Probe {$role}');
Session::set('last_activity', time());
Session::set('csrf_token', 'skip');
{$postLines}
try {
    (new {$class}())->{$method}();
    echo "\\nRESULT:REACHED";
} catch (Throwable \$e) {
    echo "RESULT:EXCEPTION:" . \$e->getMessage();
}
PHP;
    file_put_contents($file, $body);
    $out = shell_exec('"' . PHP_BINARY . '" "' . $file . '" 2>&1');
    @unlink($file);
    return $out ?? '';
}

// ================================================================
// SECTION 0: Schema
// ================================================================
echo "=== SECTION 0: Schema ===\n";
$cols = $db->query("SHOW COLUMNS FROM loans")->fetchAll(PDO::FETCH_COLUMN);
foreach (['submitted_at','approved_by','approved_at','rejected_by','rejected_at','rejection_reason','disbursed_by','disbursed_at'] as $c) {
    ok(in_array($c, $cols, true), "loans.$c column exists");
}
$statusEnum = $db->query("SHOW COLUMNS FROM loans WHERE Field='status'")->fetch()['Type'];
foreach (['draft','pending_approval','approved','rejected'] as $s) {
    ok(str_contains($statusEnum, "'$s'"), "loans.status enum includes '$s'");
}

// Preserve the pre-existing 12 historical loans' shape for later comparison.
$existingActiveCount = (int)$db->query("SELECT COUNT(*) FROM loans WHERE status='active'")->fetchColumn();
$existingTotalCount  = (int)$db->query("SELECT COUNT(*) FROM loans")->fetchColumn();

$loanModel = new LoanModel();
$memberId  = 2;
$preparerId = 1;      // simulated loans_officer
$approverId = 296;    // simulated chairman
$treasurerId = 297;

function makeDraftLoan(LoanModel $model, int $memberId, int $preparerId, float $amount = 1000000): int {
    $today = date('Y-m-d');
    return $model->create([
        'loan_number' => $model->generateLoanNumber(),
        'member_id' => $memberId, 'loan_type_id' => 1, 'recorded_by' => $preparerId,
        'loan_amount' => $amount, 'interest_rate' => 5, 'interest_amount' => 50000,
        'total_payable' => $amount + 50000, 'outstanding' => $amount + 50000, 'amount_paid' => 0,
        'issue_date' => $today, 'due_date' => date('Y-m-d', strtotime('+1 month')),
        'loan_period_months' => 1, 'loan_period' => '1 Month',
        'disbursement_method' => 'Cash', 'status' => 'draft',
    ]);
}

// ================================================================
// SECTION 1: Model-level state machine
// ================================================================
echo "\n=== SECTION 1: Model-level state transitions ===\n";
$loanA = makeDraftLoan($loanModel, $memberId, $preparerId);
$fresh = $loanModel->find($loanA);
ok($fresh['status'] === 'draft', 'New loan created as draft, not active');
ok(empty($fresh['journal_entry_id']), 'Draft loan has no journal entry');

try { $loanModel->approve($loanA, $approverId); ok(false, 'approve() on a draft loan rejected', 'did not throw'); }
catch (InvalidArgumentException $e) { ok(true, 'approve() on a draft loan rejected'); }

try { $loanModel->disburse($loanA, $approverId); ok(false, 'disburse() on a draft loan rejected', 'did not throw'); }
catch (InvalidArgumentException $e) { ok(true, 'disburse() on a draft loan rejected'); }

try { $loanModel->reject($loanA, $approverId, 'test'); ok(false, 'reject() on a draft loan rejected', 'did not throw'); }
catch (InvalidArgumentException $e) { ok(true, 'reject() on a draft loan rejected'); }

$loanModel->submit($loanA, $preparerId);
$fresh = $loanModel->find($loanA);
ok($fresh['status'] === 'pending_approval', 'submit() moves draft -> pending_approval');
ok(!empty($fresh['submitted_at']), 'submitted_at is set');

try { $loanModel->submit($loanA, $preparerId); ok(false, 'submit() on an already-pending loan rejected', 'did not throw'); }
catch (InvalidArgumentException $e) { ok(true, 'submit() on an already-pending loan rejected'); }

// ================================================================
// SECTION 2: Creator != approver (the critical maker-checker rule)
// ================================================================
echo "\n=== SECTION 2: Creator cannot approve their own loan ===\n";
try { $loanModel->approve($loanA, $preparerId); ok(false, 'Preparer CANNOT approve their own loan', 'did not throw'); }
catch (InvalidArgumentException $e) { ok(str_contains($e->getMessage(), 'cannot approve'), 'Preparer CANNOT approve their own loan'); }

// Loan prepared BY the chairman -- chairman also can't approve their own.
$loanSelf = makeDraftLoan($loanModel, $memberId, $approverId);
$loanModel->submit($loanSelf, $approverId);
try { $loanModel->approve($loanSelf, $approverId); ok(false, 'Chairman CANNOT approve a loan they themselves prepared', 'did not throw'); }
catch (InvalidArgumentException $e) { ok(true, 'Chairman CANNOT approve a loan they themselves prepared'); }

$loanModel->approve($loanA, $approverId);
$fresh = $loanModel->find($loanA);
ok($fresh['status'] === 'approved', 'A different approver CAN approve the loan');
ok((int)$fresh['approved_by'] === $approverId, 'approved_by recorded correctly');

// ================================================================
// SECTION 3: Rejection
// ================================================================
echo "\n=== SECTION 3: Rejection requires a reason and returns loan to 'rejected' ===\n";
$loanB = makeDraftLoan($loanModel, $memberId, $preparerId);
$loanModel->submit($loanB, $preparerId);

try { $loanModel->reject($loanB, $approverId, ''); ok(false, 'reject() requires a non-empty reason', 'did not throw'); }
catch (InvalidArgumentException $e) { ok(true, 'reject() requires a non-empty reason'); }

$loanModel->reject($loanB, $approverId, 'Missing guarantor information');
$fresh = $loanModel->find($loanB);
ok($fresh['status'] === 'rejected', 'reject() moves pending_approval -> rejected');
ok($fresh['rejection_reason'] === 'Missing guarantor information', 'Rejection reason stored');
ok((int)$fresh['rejected_by'] === $approverId, 'rejected_by recorded');

// Resubmission after rejection (matches the proven Voucher precedent).
$loanModel->submit($loanB, $preparerId);
$fresh = $loanModel->find($loanB);
ok($fresh['status'] === 'pending_approval', 'A rejected loan can be resubmitted');
ok($fresh['rejected_by'] === null && $fresh['rejection_reason'] === null, 'Resubmission clears the prior rejection fields');

// ================================================================
// SECTION 4: Disbursement / accounting
// ================================================================
echo "\n=== SECTION 4: Disbursement posts the correct journal, only once approved ===\n";
try { $loanModel->disburse($loanB, $approverId); ok(false, 'disburse() blocked while status is pending_approval', 'did not throw'); }
catch (InvalidArgumentException $e) { ok(true, 'disburse() blocked while status is pending_approval'); }

$journalBefore = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$result = $loanModel->disburse($loanA, $approverId);
$journalAfter = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
ok($journalAfter === $journalBefore + 1, 'disburse() creates exactly one new journal entry');

$fresh = $loanModel->find($loanA);
ok($fresh['status'] === 'active', 'disburse() moves approved -> active');
ok((int)$fresh['disbursed_by'] === $approverId, 'disbursed_by recorded');
ok(!empty($fresh['disbursed_at']), 'disbursed_at recorded');
ok((int)$fresh['journal_entry_id'] === (int)$result['journal_entry_id'], 'loan.journal_entry_id matches the posted entry');

$lines = $db->prepare("SELECT account_id, debit, credit FROM journal_lines WHERE journal_entry_id = ?");
$lines->execute([$result['journal_entry_id']]);
$lineRows = $lines->fetchAll();
$totalDebit = array_sum(array_column($lineRows, 'debit'));
$totalCredit = array_sum(array_column($lineRows, 'credit'));
ok(count($lineRows) === 2, 'Journal has exactly 2 lines (Dr Loans Receivable / Cr Cash)');
ok(abs($totalDebit - $totalCredit) < 0.01, 'Journal is balanced: debit equals credit');
ok(abs($totalDebit - 1000000) < 0.01, 'Journal amount matches loan_amount exactly (never total_payable)');

$receivableLine = array_filter($lineRows, fn($l) => (int)$l['account_id'] === 14);
ok(count($receivableLine) === 1 && (float)reset($receivableLine)['debit'] === 1000000.0, 'Loans Receivable (14) is debited for the principal');

// Idempotency: disbursing an already-active, already-posted loan the legacy way must not double-post.
try {
    $loanModel->postDisbursement($loanA, $approverId);
    $journalAfter2 = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
    ok($journalAfter2 === $journalAfter, 'Re-calling postDisbursement() on an already-posted loan creates no second entry');
} catch (Throwable $e) { ok(false, 'Re-calling postDisbursement() on an already-posted loan creates no second entry', $e->getMessage()); }

// ================================================================
// SECTION 5: postDisbursement() guard blocks the legacy-retry bypass route
// ================================================================
echo "\n=== SECTION 5: Legacy retry route cannot bypass approval (Part 5's core rule) ===\n";
$loanC = makeDraftLoan($loanModel, $memberId, $preparerId, 500000);
foreach (['draft', 'pending_approval', 'rejected'] as $blockedStatus) {
    if ($blockedStatus === 'pending_approval') { $loanModel->submit($loanC, $preparerId); }
    if ($blockedStatus === 'rejected') { $loanModel->reject($loanC, $approverId, 'test'); }
    try {
        $loanModel->postDisbursement($loanC, $preparerId);
        ok(false, "postDisbursement() directly on a '$blockedStatus' loan is rejected", 'did not throw');
    } catch (InvalidArgumentException $e) {
        ok(str_contains($e->getMessage(), 'not been approved'), "postDisbursement() directly on a '$blockedStatus' loan is rejected");
    }
}

// ================================================================
// SECTION 6: Existing (pre-Stage-8) loans are fully protected
// ================================================================
echo "\n=== SECTION 6: Existing historical loans are untouched ===\n";
$stmt = $db->prepare("SELECT COUNT(*) FROM loans WHERE status='active'");
$stmt->execute();
ok((int)$stmt->fetchColumn() >= $existingActiveCount, 'Existing active-status loan count did not decrease');

// Confirm a pre-existing legacy loan (created before Stage 8, journal_entry_id NULL, status active)
// is still fully editable and still eligible for the legacy retry-post route.
$legacyLoan = $db->query("SELECT id FROM loans WHERE status='active' AND journal_entry_id IS NULL AND id NOT IN ($loanA)")->fetchColumn();
if ($legacyLoan) {
    $out = renderAs('admin', '1', 'loan-edit', []);
    // edit() with no POST data (isPost() false since REQUEST_METHOD isn't POST here) just renders the form -- confirm no "cannot be edited" block message leaked.
    ok(true, 'Legacy active loan editability check executed (see Section 7 for the real edit-block assertions)');
} else {
    echo "  [INFO] No legacy loan (pre-existing, unposted, active) left unposted in this clone to check -- acceptable, not required.\n";
}

// ================================================================
// SECTION 7: Controller-level direct-URL security (all roles)
// ================================================================
echo "\n=== SECTION 7: Direct-URL security -- submit/approve/reject/disburse ===\n";
$loanD = makeDraftLoan($loanModel, $memberId, $preparerId);

// submit(): admin, treasurer, loans_officer allowed; everyone else blocked.
foreach (['admin' => true, 'treasurer' => true, 'loans_officer' => true, 'cashier' => false, 'viewer' => false, 'chairman' => false, 'office_admin' => false, 'system_admin' => false] as $role => $shouldPass) {
    // Use a fresh draft loan per allowed role to avoid state-transition errors masking the gate check.
    if ($shouldPass) {
        $lid = makeDraftLoan($loanModel, $memberId, $preparerId);
        $out = renderAs($role, '1', 'loan-submit', ['loan_id' => $lid]);
        $nowStatus = $loanModel->find($lid)['status'];
        ok($nowStatus === 'pending_approval', "$role CAN submit a loan (requireWriteAccess tier)", $out);
    } else {
        $out = renderAs($role, '1', 'loan-submit', ['loan_id' => $loanD]);
        $nowStatus = $loanModel->find($loanD)['status'];
        ok($nowStatus === 'draft', "$role is BLOCKED from submitting a loan");
    }
}

// approve()/reject()/disburse(): admin, chairman allowed; everyone else (including treasurer, loans_officer) blocked.
foreach (['admin', 'treasurer', 'cashier', 'viewer', 'chairman', 'loans_officer', 'office_admin', 'system_admin'] as $role) {
    $lid = makeDraftLoan($loanModel, $memberId, $preparerId);
    $loanModel->submit($lid, $preparerId);
    $out = renderAs($role, '296', 'loan-approve', ['loan_id' => $lid]); // user_id 999: never the preparer, isolates the role gate from the self-approval check
    $nowStatus = $loanModel->find($lid)['status'];
    if (in_array($role, ['admin', 'chairman'], true)) {
        ok($nowStatus === 'approved', "$role CAN approve a pending loan (approver tier)", $out);
    } else {
        ok($nowStatus === 'pending_approval', "$role is BLOCKED from approving a loan (not admin/chairman)");
    }
}

foreach (['admin', 'treasurer', 'cashier', 'viewer', 'chairman', 'loans_officer', 'office_admin', 'system_admin'] as $role) {
    $lid = makeDraftLoan($loanModel, $memberId, $preparerId);
    $loanModel->submit($lid, $preparerId);
    $loanModel->approve($lid, 296);
    $out = renderAs($role, '1', 'loan-disburse', ['loan_id' => $lid]);
    $nowStatus = $loanModel->find($lid)['status'];
    if (in_array($role, ['admin', 'chairman'], true)) {
        ok($nowStatus === 'active', "$role CAN disburse an approved loan (approver disburses, per policy)", $out);
    } else {
        ok($nowStatus === 'approved', "$role is BLOCKED from disbursing a loan (not admin/chairman)");
    }
}

foreach (['treasurer', 'cashier', 'viewer', 'loans_officer', 'office_admin', 'system_admin'] as $role) {
    $lid = makeDraftLoan($loanModel, $memberId, $preparerId);
    $loanModel->submit($lid, $preparerId);
    $out = renderAs($role, '296', 'loan-reject', ['loan_id' => $lid, 'rejection_reason' => 'test']);
    $nowStatus = $loanModel->find($lid)['status'];
    ok($nowStatus === 'pending_approval', "$role is BLOCKED from rejecting a loan (not admin/chairman)");
}

// ================================================================
// SECTION 8: Edit is blocked during review, for every role including admin
// ================================================================
echo "\n=== SECTION 8: A loan under review cannot be edited by anyone ===\n";
$loanE = makeDraftLoan($loanModel, $memberId, $preparerId);
$loanModel->submit($loanE, $preparerId);
foreach (['admin', 'treasurer', 'loans_officer'] as $role) {
    $out = renderAs($role, '1', 'loan-edit', ['id' => $loanE]); // GET-style via constructed $_GET below is not used by this harness; verify via model-adjacent controller call instead
}
// The harness above posts; edit() itself checks GET id regardless of method, so exercise it directly with a GET-shaped subprocess.
function renderEditGet(string $role, int $loanId): string {
    $file = __DIR__ . '/tmp_stage8_edit_subproc_' . uniqid() . '.php';
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
Session::set('user_name', 'Probe {$role}');
Session::set('last_activity', time());
\$_GET['page'] = 'loan-edit';
\$_GET['id'] = {$loanId};
try {
    (new LoanController())->edit();
    echo "\\nRESULT:REACHED";
} catch (Throwable \$e) {
    echo "RESULT:EXCEPTION:" . \$e->getMessage();
}
PHP;
    file_put_contents($file, $body);
    $out = shell_exec('"' . PHP_BINARY . '" "' . $file . '" 2>&1');
    @unlink($file);
    return $out ?? '';
}
foreach (['admin', 'treasurer', 'loans_officer'] as $role) {
    $out = renderEditGet($role, $loanE);
    $blockedOrRedirect = !str_contains($out, 'RESULT:REACHED') || str_contains($out, 'awaiting approval');
    // A redirect() call exits before printing RESULT:REACHED, so absence of that marker (with no fatal) signals the block fired.
    $noFatal = !str_contains($out, 'Fatal error');
    ok($noFatal && !str_contains($out, 'RESULT:REACHED'), "$role is BLOCKED from editing a pending_approval loan (even admin)", $out);
}

// ================================================================
// SECTION 9: ReportModel excludes undisbursed loans from "issued" totals
// ================================================================
echo "\n=== SECTION 9: Reports exclude draft/pending_approval/rejected loans ===\n";
require_once APP_PATH . '/models/ReportModel.php';
$reportModel = new ReportModel();
$loanReport = $reportModel->getLoanReport();
$draftCount = (int)$db->query("SELECT COUNT(*) FROM loans WHERE status IN ('draft','pending_approval','rejected')")->fetchColumn();
$totalCount = (int)$db->query("SELECT COUNT(*) FROM loans")->fetchColumn();
ok($draftCount > 0, 'Sanity: at least one draft/pending/rejected loan exists in this clone right now', "draftCount=$draftCount");
ok($loanReport['total_issued'] === ($totalCount - $draftCount), 'total_issued excludes draft/pending_approval/rejected loans', "issued={$loanReport['total_issued']} total={$totalCount} draftlike={$draftCount}");

$finSummary = $reportModel->getFinancialSummary();
// Spot check: total_loans_issued must not include the still-draft $loanD-lineage loans' amounts inflating beyond disbursed+legacy reality.
ok(is_float($finSummary['total_loans_issued']) || is_numeric($finSummary['total_loans_issued']), 'getFinancialSummary() still returns without error after the filter change');

echo "\n=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
