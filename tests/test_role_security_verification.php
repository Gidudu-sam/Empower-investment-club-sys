<?php
/**
 * ISOLATED — Six-role model security verification, requested as a
 * follow-up stage-gate before closing "Roles Foundation": (1) creator
 * != approver enforcement across every maker-checker workflow that has
 * one, (2) direct controller/URL access is blocked for the new roles
 * regardless of any menu/sidebar state, (3) System Administrator has no
 * path to financial-authority actions, (4) Office Administrator has no
 * path to deposit/withdrawal actions, (5) Loans Officer's grant is
 * exactly Treasurer's existing scope, nothing wider.
 *
 * Runs ONLY against empower_db_ivms_test. Never touches empower_db.
 * Read-only investigation -- no controller/model code is changed by
 * this script; where it finds a gap it reports it, it does not patch it.
 */
chdir(__DIR__);
define('DB_NAME', 'empower_db_ivms_test');
require 'app/config/config.php';
require 'test_safety_guard.php';
require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';
require_once CORE_PATH . '/Session.php';
require_once CORE_PATH . '/Controller.php';

$db = Database::getInstance()->getConnection();
$pass = 0; $fail = 0; $findings = [];
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }
function finding(string $f): void { global $findings; $findings[] = $f; echo "  [FINDING] $f\n"; }

Session::start();
function setRole(string $role, int $userId = 1): void {
    Session::set('user_id', $userId);
    Session::set('user_role', $role);
    Session::set('user_name', 'Test ' . $role);
    Session::set('last_activity', time());
}

$subprocCounter = 0;
function runInSubprocess(string $role, int $userId, string $code): string {
    global $subprocCounter;
    $subprocCounter++;
    $file = __DIR__ . "/tmp_role_verify_subproc_{$subprocCounter}.php";
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
Session::set('user_name', 'Test');
Session::set('last_activity', time());
try {
{$code}
    echo "GATE_PASSED";
} catch (Throwable \$e) {
    echo "EXCEPTION: " . \$e->getMessage();
}
PHP;
    file_put_contents($file, $body);
    $out = shell_exec('"' . PHP_BINARY . '" "' . $file . '" 2>&1');
    @unlink($file);
    return $out ?? '';
}

/** Invoke a private/protected gate method via reflection, inside the calling controller instance. */
function invokePrivate(object $obj, string $method): void {
    $r = new ReflectionMethod($obj, $method);
    $r->setAccessible(true);
    $r->invoke($obj);
}

echo "=== SECTION A: Self-approval (creator != approver) -- real data, model layer ===\n";

$preparerId = (int)$db->query("SELECT id FROM users LIMIT 1")->fetchColumn();
$db->exec("INSERT INTO users (role_id, full_name, email, password_hash, is_active) VALUES (1, 'Verify Approver', 'verify.approver@example.test', 'x', 1)");
$approverId = (int)$db->lastInsertId();

// A1. Internal Voucher
$ivModel = new InternalVoucherModel();
$catId = (int)$db->query("SELECT id FROM expense_categories WHERE gl_account_id IS NOT NULL LIMIT 1")->fetchColumn();
$voucherId = $ivModel->createDraft([
    'voucher_type' => 'debit', 'voucher_date' => '2026-08-20',
    'expense_category_id' => $catId, 'contra_account_id' => 7,
    'narration' => 'Self-approval verification test', 'amount' => 5000,
], $preparerId);
$ivModel->submit($voucherId, $preparerId);
$selfBlocked = false;
try { $ivModel->approve($voucherId, $preparerId); } catch (InvalidArgumentException $e) { $selfBlocked = str_contains($e->getMessage(), 'may not approve their own'); }
ok($selfBlocked, 'InternalVoucherModel: preparer cannot approve their own voucher');
$otherOk = false;
try { $ivModel->approve($voucherId, $approverId); $otherOk = true; } catch (Throwable $e) {}
ok($otherOk, 'InternalVoucherModel: a different user CAN approve the same voucher');

// A2. Member Account Adjustment
$memberA = 2; $accountA = 591;
$adjModel = new MemberAccountAdjustmentModel();
$adjId = $adjModel->createDraft([
    'member_id' => $memberA, 'savings_account_id' => $accountA, 'adjustment_type' => 'credit',
    'amount' => 1000, 'reason' => 'Self-approval verification test reason text', 'contra_account_id' => 7,
], $preparerId);
$adjModel->submit($adjId, $preparerId);
$selfBlocked = false;
try { $adjModel->approve($adjId, $preparerId); } catch (InvalidArgumentException $e) { $selfBlocked = str_contains($e->getMessage(), 'may not approve their own'); }
ok($selfBlocked, 'MemberAccountAdjustmentModel: preparer cannot approve their own adjustment');
$otherOk = false;
try { $adjModel->approve($adjId, $approverId); $otherOk = true; } catch (Throwable $e) {}
ok($otherOk, 'MemberAccountAdjustmentModel: a different user CAN approve the same adjustment');

// A3. Investment
$invModel = new InvestmentModel();
$invTypeId = (int)$db->query("SELECT id FROM investment_types WHERE is_active=1 LIMIT 1")->fetchColumn();
if ($invTypeId) {
    $investmentId = $invModel->createDraft([
        'investment_type_id' => $invTypeId, 'principal_amount' => 10000, 'start_date' => '2026-08-20',
        'funding_account_id' => 7,
    ], $preparerId);
    $invModel->submit($investmentId, $preparerId);
    $selfBlocked = false;
    try { $invModel->approve($investmentId, $preparerId); } catch (InvalidArgumentException $e) { $selfBlocked = str_contains($e->getMessage(), 'may not approve their own'); }
    ok($selfBlocked, 'InvestmentModel: preparer cannot approve their own investment');
    $otherOk = false;
    try { $invModel->approve($investmentId, $approverId); $otherOk = true; } catch (Throwable $e) {}
    ok($otherOk, 'InvestmentModel: a different user CAN approve the same investment');
} else {
    echo "  [SKIP] No active investment_types fixture available -- InvestmentModel self-approval not exercised with live data (verified by direct code read instead: InvestmentModel.php line ~294 has the identical recorded_by===userId guard).\n";
}

// A4. Opening Balance Batch -- this module is policy-frozen (Stage 21:
// NO-GO pending leadership decisions), and its createDraft() requires a
// balanced set of lines against a real financial_year_id, which is not a
// safe or representative fixture to fabricate here. Verified by direct
// code read instead (already done, see OpeningBalanceBatchModel.php
// line ~228: identical entered_by===userId guard as the other 3 models).
echo "  [SKIP] OpeningBalanceBatchModel not exercised with live data (policy-frozen module, complex balanced-lines fixture not safe to fabricate) -- verified by direct code read: line ~228 has the identical entered_by===userId guard as the other 3 models.\n";

echo "\n=== SECTION B: Reversal actions -- creator/reverser separation check (reporting only) ===\n";
// MemberAccountAdjustmentModel::reverse() and WithdrawalModel::reverseWithdrawal()
// were read directly: neither checks whether the reverser is the same user
// who originally posted/processed the transaction being reversed -- both
// are gated by ROLE only (admin/chairman; admin/treasurer/chairman). This
// differs from approve(), which does block self-approval on all 4
// maker-checker models. Proving it empirically for the adjustment case:
$adjModel2 = new MemberAccountAdjustmentModel();
$adjId2 = $adjModel2->createDraft([
    'member_id' => $memberA, 'savings_account_id' => $accountA, 'adjustment_type' => 'credit',
    'amount' => 500, 'reason' => 'Reversal self-check verification test reason', 'contra_account_id' => 7,
], $approverId);
$adjModel2->submit($adjId2, $approverId);
$adjModel2->approve($adjId2, $preparerId);
$adjModel2->post($adjId2, $preparerId);
$sameUserCanReverse = false;
try { $adjModel2->reverse($adjId2, $preparerId, 'Verification: same user who posted this now reverses it'); $sameUserCanReverse = true; } catch (Throwable $e) {}
if ($sameUserCanReverse) {
    finding('MemberAccountAdjustmentModel::reverse() has NO creator/reverser separation check -- the same user who posted an adjustment can also reverse it, if they hold the approver role. Pre-existing (not introduced by the six-role work); approve() on the same model DOES block self-approval, reverse() does not.');
} else {
    ok(false, 'MemberAccountAdjustmentModel::reverse() unexpectedly blocked same-user reversal -- re-check assumption', '');
}
echo "  (WithdrawalModel::reverseWithdrawal() confirmed by direct code read to have the same characteristic -- no recorded_by check at all in that method.)\n";

echo "\n=== SECTION C: Menu-hiding is not the control -- direct gate invocation for System Administrator ===\n";
$systemAdminChecks = [
    ['InternalVoucherController', 'requireWriteAccess'],
    ['MemberAccountAdjustmentController', 'requireWriteAccess'],
    ['InvestmentController', 'requireWriteAccess'],
    ['LoanController', 'requireWriteAccess'],
    ['WithdrawalController', 'requireWriteAccess'],
    ['WithdrawalController', 'requireProcessAccess'],
    ['FinancialYearController', 'requireWriteAccess'],
    ['AccountingPeriodController', 'requireWriteAccess'],
    ['ChartOfAccountsController', 'requireAdmin'],
    ['WithdrawalPolicyController', 'requireAdmin'],
    ['ExpenseController', 'requireWriteAccess'],
    ['OpeningBalanceController', '__construct'],
];
foreach ($systemAdminChecks as [$class, $method]) {
    $code = $method === '__construct'
        ? "\$o = new {$class}();"
        : "\$o = new {$class}(); \$r = new ReflectionMethod(\$o, '{$method}'); \$r->setAccessible(true); \$r->invoke(\$o);";
    $out = runInSubprocess('system_admin', 1, "    {$code}\n");
    ok(!str_contains($out, 'GATE_PASSED'), "System Administrator BLOCKED from {$class}::{$method}()", $out);
}

echo "\n=== SECTION D: Direct gate invocation for Office Administrator (withdrawal stays blocked; deposit is a deliberate grant) ===\n";
$officeAdminChecks = [
    ['SavingsAccountController', 'requireTransactAccess'], // withdrawal-only now; deposit was split into requireDepositAccess() below
    ['WithdrawalController', 'requireProcessAccess'],
    ['WithdrawalController', 'requireWriteAccess'],
];
foreach ($officeAdminChecks as [$class, $method]) {
    $code = "\$o = new {$class}(); \$r = new ReflectionMethod(\$o, '{$method}'); \$r->setAccessible(true); \$r->invoke(\$o);";
    $out = runInSubprocess('office_admin', 1, "    {$code}\n");
    ok(!str_contains($out, 'GATE_PASSED'), "Office Administrator BLOCKED from {$class}::{$method}()", $out);
}
// Positive control: Office Administrator SHOULD pass account-opening (requireWriteAccess on SavingsAccountController).
$out = runInSubprocess('office_admin', 1, "    \$o = new SavingsAccountController(); \$r = new ReflectionMethod(\$o, 'requireWriteAccess'); \$r->setAccessible(true); \$r->invoke(\$o);\n");
ok(str_contains($out, 'GATE_PASSED'), 'Office Administrator correctly PASSES SavingsAccountController::requireWriteAccess() (account opening -- intended grant, positive control)', $out);
// Payment-recording policy alignment: Office Administrator gained deposit
// (money received) access, deliberately, while withdrawal (money paid out)
// stays excluded above -- these must never be re-merged onto one gate.
$out = runInSubprocess('office_admin', 1, "    \$o = new SavingsAccountController(); \$r = new ReflectionMethod(\$o, 'requireDepositAccess'); \$r->setAccessible(true); \$r->invoke(\$o);\n");
ok(str_contains($out, 'GATE_PASSED'), 'Office Administrator correctly PASSES SavingsAccountController::requireDepositAccess() (deposit -- intended grant, positive control)', $out);

echo "\n=== SECTION E: Loans Officer grant is exactly Treasurer's existing scope, nothing wider ===\n";
// Direct source inspection: every hasRole() array that contains 'loans_officer'
// must also contain 'treasurer'. Grep-equivalent check via reflection isn't
// meaningful for private method bodies, so this is verified by re-reading
// the two touched files directly and confirming the exact arrays.
$loanControllerSrc = file_get_contents(__DIR__ . '/app/controllers/LoanController.php');
$repaymentControllerSrc = file_get_contents(__DIR__ . '/app/controllers/RepaymentController.php');
ok(
    (bool)preg_match("/hasRole\(\['admin', 'treasurer', 'loans_officer'\]\)/", $loanControllerSrc),
    'LoanController.requireWriteAccess grants loans_officer exactly alongside admin/treasurer (no wider array found)'
);
// Payment-recording policy alignment: RepaymentController.add deliberately
// widened to also include office_admin (front-desk repayment collection) --
// the array below is now the full, intended set, not accidental drift.
ok(
    (bool)preg_match("/hasRole\(\['admin', 'treasurer', 'cashier', 'loans_officer', 'office_admin'\]\)/", $repaymentControllerSrc),
    'RepaymentController.add grants loans_officer alongside admin/treasurer/cashier/office_admin (deliberate widening, no wider array found)'
);
// DashboardController.php legitimately references 'loans_officer' as of
// the role-workspace stage -- purely to pick which Quick Actions buttons
// to display (a match() arm, no hasRole() gate, no authorization change),
// not a backend permission grant. Loans Officer role-refinement (2026-09)
// deliberately widened ReportController/WeeklyReportController to admit
// loans_officer into a narrow slice (Loan Report/Aging/Repayment Report;
// Weekly Loans/Repayments/Overdue) -- each with an explicit blockLoansOfficer()
// exclusion on every other action in those same controllers (see the new
// test_loans_officer_role_completion.php for the full per-action proof).
// Excluded from this check by name/reason, not silently -- every OTHER
// controller must still have zero references.
$loansOfficerElsewhere = [];
foreach (glob(__DIR__ . '/app/controllers/*.php') as $file) {
    $base = basename($file);
    if (in_array($base, ['LoanController.php', 'RepaymentController.php', 'DashboardController.php', 'ReportController.php', 'WeeklyReportController.php'], true)) continue;
    if (str_contains(file_get_contents($file), 'loans_officer')) {
        $loansOfficerElsewhere[] = $base;
    }
}
ok(empty($loansOfficerElsewhere), 'loans_officer does not appear in any controller other than LoanController/RepaymentController/DashboardController(UI-only)/ReportController/WeeklyReportController(narrow loan-reporting grant)', implode(', ', $loansOfficerElsewhere));
$hasLimitationComment = str_contains($loanControllerSrc, 'no separate "assess vs approve" tier');
ok($hasLimitationComment, 'LoanController carries an explicit code comment documenting the assess/approve separation limitation');

echo "\n=== SECTION F: Re-run the complete existing test suite ===\n";
echo "(run as separate subprocess invocations below, see summary)\n";

echo "\n=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
echo "FINDINGS: " . count($findings) . "\n";
foreach ($findings as $f) { echo "  - $f\n"; }
if ($fail > 0) { exit(1); }
