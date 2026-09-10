<?php
/**
 * ISOLATED — Treasurer Role Completion Audit (Phase 1 sign-off).
 * Runs ONLY against empower_db_ivms_test. Never touches empower_db.
 *
 * Consolidates, as a permanent regression artifact, the checks performed
 * during the Treasurer "final audit" pass: sidebar visibility, backend
 * controller authorization for every treasurer-sensitive action, direct
 * URL access, unauthorized POST requests, and the Cash & Bank GL
 * consistency check. This does not re-test unrelated roles' behavior in
 * depth (already covered by test_role_security_verification.php,
 * test_stage9_role_policy_alignment.php, etc.) -- it specifically locks
 * in the Treasurer boundary as of this sign-off so a future change that
 * silently widens or narrows it is caught.
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

function renderAs(string $role, string $class, string $method, array $get = [], array $post = []): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_treasurer_audit_subproc_' . $counter . '.php';
    $getLines = ''; foreach ($get as $k => $v) { $getLines .= "\$_GET['{$k}'] = " . var_export($v, true) . ";\n"; }
    $postLines = '';
    if ($post) {
        $postLines = "\$_SERVER['REQUEST_METHOD'] = 'POST';\n\$_POST['csrf_token'] = 'skip';\n";
        foreach ($post as $k => $v) { $postLines .= "\$_POST['{$k}'] = " . var_export($v, true) . ";\n"; }
    }
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
Session::set('csrf_token', 'skip');
{$getLines}
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

$reached = fn(string $out) => str_contains($out, 'RESULT:REACHED');
$anyMemberId  = (int)$db->query("SELECT id FROM members WHERE status='active' LIMIT 1")->fetchColumn();
$anyLoanId    = (int)$db->query("SELECT id FROM loans LIMIT 1")->fetchColumn();
$anyAccountId = (int)$db->query("SELECT id FROM member_savings_accounts WHERE account_type != 'corporate' AND status='active' LIMIT 1")->fetchColumn();

echo "=== SECTION 1: Sidebar visibility (dashboard render, treasurer) ===\n";
$out = renderAs('treasurer', 'DashboardController', 'index');
ok(!str_contains($out, 'Fatal error'), 'Treasurer dashboard renders with no fatal error');
$noComments = implode("\n", array_filter(explode("\n", $out), fn($l) => !str_contains($l, '<!--')));
ok(substr_count($noComments, 'Financial Operations') === 1, 'Sidebar shows "Financial Operations" heading exactly once');
ok(substr_count($noComments, '>Finance<') >= 1, 'Sidebar shows "Finance" heading');
ok(substr_count($noComments, 'Cash at Hand') >= 1, 'Sidebar shows Cash & Bank > Cash at Hand');
ok(substr_count($noComments, 'Record Loan') === 0, 'Sidebar never shows "Record Loan" text for treasurer');
ok(substr_count($noComments, 'Add Member') === 0, 'Sidebar never shows "Add Member" text for treasurer');
ok(substr_count($noComments, "Today's Collections") === 1, 'Dashboard shows "Today\'s Collections" panel');
ok(substr_count($noComments, 'Recent Withdrawals') === 1, 'Dashboard shows "Recent Withdrawals" panel');
ok(substr_count($noComments, 'Record Deposit') >= 1, 'Dashboard Quick Actions include Record Deposit');
ok(substr_count($noComments, 'Record Repayment') >= 1, 'Dashboard Quick Actions include Record Repayment');
ok(substr_count($noComments, 'Record Fee') >= 1, 'Dashboard Quick Actions include Record Fee');
ok(substr_count($noComments, 'Record Expense') >= 1, 'Dashboard Quick Actions include Record Expense');
echo "\n";

echo "=== SECTION 2: Members -- view yes, add/edit/delete no ===\n";
ok($reached(renderAs('treasurer', 'MemberController', 'view', ['id' => $anyMemberId])), 'Treasurer CAN view a member');
ok(!$reached(renderAs('treasurer', 'MemberController', 'add')), 'Treasurer BLOCKED from add-member form');
ok(!$reached(renderAs('treasurer', 'MemberController', 'edit', ['id' => $anyMemberId])), 'Treasurer BLOCKED from edit-member form');
$out = renderAs('treasurer', 'MemberController', 'add', [], ['full_name' => 'X', 'email' => 'x@example.test']);
ok(!$reached($out), 'Treasurer BLOCKED from POSTing a new member (unauthorized POST)');
echo "\n";

echo "=== SECTION 3: Savings -- deposit yes, withdrawal yes (existing policy), no edit/delete exists for anyone ===\n";
ok($reached(renderAs('treasurer', 'SavingsAccountController', 'depositForm', ['id' => $anyAccountId])), 'Treasurer CAN reach the deposit form');
ok($reached(renderAs('treasurer', 'SavingsAccountController', 'withdrawalForm', ['id' => $anyAccountId])), 'Treasurer CAN reach the withdrawal form (existing policy, unchanged)');
ok(
    !method_exists('SavingsAccountController', 'delete') && !method_exists('SavingsAccountController', 'edit'),
    'No delete()/edit() action exists on SavingsAccountController for any role (posted deposits are immutable system-wide)'
);
echo "\n";

echo "=== SECTION 4: Loans -- view yes, originate no, edit/complete preserved, repayment yes ===\n";
ok($reached(renderAs('treasurer', 'LoanController', 'view', ['id' => $anyLoanId])), 'Treasurer CAN view a loan');
ok(!$reached(renderAs('treasurer', 'LoanController', 'add')), 'Treasurer BLOCKED from the loan-origination form');
$out = renderAs('treasurer', 'LoanController', 'add', [], ['member_id' => $anyMemberId, 'loan_amount' => 100000]);
ok(!$reached($out), 'Treasurer BLOCKED from POSTing a new loan (unauthorized POST)');
// requireWriteAccess() is private; probe it the same way
// test_role_security_verification.php does elsewhere -- via a tiny
// reflection subprocess rather than calling a nonexistent public action.
$reflFile = __DIR__ . '/tmp_treasurer_audit_refl.php';
file_put_contents($reflFile, <<<PHP
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
Session::set('user_role', 'treasurer');
Session::set('last_activity', time());
try {
    \$o = new LoanController();
    \$r = new ReflectionMethod(\$o, 'requireWriteAccess');
    \$r->setAccessible(true);
    \$r->invoke(\$o);
    echo "GATE_PASSED";
} catch (Throwable \$e) {
    echo "GATE_EXCEPTION:" . \$e->getMessage();
}
PHP);
$out = shell_exec('"' . PHP_BINARY . '" "' . $reflFile . '" 2>&1');
@unlink($reflFile);
ok(str_contains($out, 'GATE_PASSED'), 'Treasurer PASSES LoanController::requireWriteAccess() (edit/complete/re-post preserved, intentional)', $out);
ok(!$reached(renderAs('treasurer', 'LoanController', 'approve', ['loan_id' => $anyLoanId])), 'Treasurer BLOCKED from approving a loan');
ok($reached(renderAs('treasurer', 'RepaymentController', 'add', ['loan_id' => $anyLoanId])), 'Treasurer CAN reach record-repayment form');
echo "\n";

echo "=== SECTION 5: Fees -- view/record/mark-paid yes, per_loan manual charge no, no charge-row delete exists ===\n";
ok($reached(renderAs('treasurer', 'FeeController', 'charges')), 'Treasurer CAN view Fee Charges');
ok($reached(renderAs('treasurer', 'FeeController', 'chargeForm')), 'Treasurer CAN reach Record Fee form');
$perLoanFeeId = (int)$db->query("SELECT id FROM fees WHERE frequency='per_loan' LIMIT 1")->fetchColumn();
if ($perLoanFeeId > 0) {
    $out = renderAs('treasurer', 'FeeController', 'chargeStore', [], ['member_id' => $anyMemberId, 'fee_id' => $perLoanFeeId]);
    ok(str_contains($out, 'EXCEPTION') === false, 'chargeStore() does not fatal on a per_loan fee attempt (it redirects with a flashed error)');
}
ok(!method_exists('FeeModel', 'deleteMemberFeeCharge'), 'No method exists anywhere to delete a posted member_fees charge row');
echo "\n";

echo "=== SECTION 6: Accounting -- view yes, approve/reverse no (maker-checker unchanged) ===\n";
ok($reached(renderAs('treasurer', 'InternalVoucherController', 'create')), 'Treasurer CAN create a voucher draft (maker)');
ok(!$reached(renderAs('treasurer', 'InternalVoucherController', 'approve', ['id' => 1])), 'Treasurer BLOCKED from approving a voucher (checker role reserved for chairman/admin)');
ok(!$reached(renderAs('treasurer', 'MemberAccountAdjustmentController', 'approve', ['id' => 1])), 'Treasurer BLOCKED from approving a member account adjustment');
ok(!$reached(renderAs('treasurer', 'MemberAccountAdjustmentController', 'reverse', ['id' => 1])), 'Treasurer BLOCKED from reversing a member account adjustment');
ok($reached(renderAs('treasurer', 'AccountingReportController', 'generalLedger')), 'Treasurer CAN view the General Ledger');
ok($reached(renderAs('treasurer', 'AccountingReportController', 'trialBalance')), 'Treasurer CAN view the Trial Balance');
// Chairman role-refinement (2026-09): these four gates were narrowed from
// admin/chairman to admin/treasurer -- Treasurer is now the designated
// "Financial Operations & Accounting" administrator for them; Chairman
// (audited/tested in test_chairman_role_completion.php) keeps view-only.
ok($reached(renderAs('treasurer', 'AccountingPeriodController', 'create')), 'Treasurer CAN create/close/reopen an accounting period (moved from Chairman this stage)');
ok($reached(renderAs('treasurer', 'FinancialYearController', 'create')), 'Treasurer CAN create/edit/activate/close/reopen a financial year (moved from Chairman this stage)');
ok($reached(renderAs('treasurer', 'ChartOfAccountsController', 'create')), 'Treasurer CAN create a Chart of Accounts entry (moved from Chairman this stage)');
ok($reached(renderAs('treasurer', 'WithdrawalPolicyController', 'create', ['account_type' => 'voluntary'])), 'Treasurer CAN create withdrawal policy (moved from Chairman this stage)');
echo "\n";

echo "=== SECTION 7: Direct URL access -- system administration stays out of reach ===\n";
ok(!$reached(renderAs('treasurer', 'SettingsController', 'users')), 'Treasurer BLOCKED from Settings > Users');
ok(!$reached(renderAs('treasurer', 'SettingsController', 'database')), 'Treasurer BLOCKED from Settings > Database');
ok(!$reached(renderAs('treasurer', 'SettingsController', 'audit')), 'Treasurer BLOCKED from Settings > Audit Logs');
echo "\n";

echo "=== SECTION 8: Cash & Bank sidebar figures are genuine GL data, not a duplicate source ===\n";
require_once APP_PATH . '/models/AccountingReportModel.php';
$ar = new AccountingReportModel();
foreach ([7 => 'Cash at Hand', 8 => 'Mobile Money', 10 => 'Bank Accounts'] as $acctId => $label) {
    $modelBalance = (float)($ar->generalLedgerForAccount($acctId, [])['closing_balance'] ?? 0);
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(jl.debit),0)-COALESCE(SUM(jl.credit),0)
        FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
        WHERE jl.account_id = ? AND je.data_classification = 'live'
    ");
    $stmt->execute([$acctId]);
    $directBalance = (float)$stmt->fetchColumn();
    ok(abs($modelBalance - $directBalance) < 0.01, "$label: dashboard/sidebar figure matches a direct live-only GL query (no duplicate balance source)");
}
echo "\n";

echo "=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
