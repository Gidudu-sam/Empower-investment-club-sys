<?php
/**
 * ISOLATED — Office Administrator Role Completion Audit (Phase 3 sign-off).
 * Runs ONLY against empower_db_ivms_test. Never touches empower_db.
 *
 * Mirrors test_treasurer_role_completion.php / test_cashier_role_completion.php:
 * a permanent regression artifact locking in the Office Admin boundary --
 * "member-facing operational administrator + collections." The audit
 * found the backend already matched this principle almost exactly; the
 * one deliberate new grant is WeeklyReportController::savings() only
 * (loans()/repayments()/overdue() remain explicitly blocked for this role).
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
    $file = __DIR__ . '/tmp_office_admin_audit_subproc_' . $counter . '.php';
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

function probeGate(string $role, string $class, string $method): string {
    $file = __DIR__ . '/tmp_office_admin_audit_refl.php';
    file_put_contents($file, <<<PHP
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
try {
    \$o = new {$class}();
    \$r = new ReflectionMethod(\$o, '{$method}');
    \$r->setAccessible(true);
    \$r->invoke(\$o);
    echo "GATE_PASSED";
} catch (Throwable \$e) {
    echo "GATE_EXCEPTION:" . \$e->getMessage();
}
PHP);
    $out = shell_exec('"' . PHP_BINARY . '" "' . $file . '" 2>&1');
    @unlink($file);
    return $out ?? '';
}

$reached = fn(string $out) => str_contains($out, 'RESULT:REACHED');
$passed  = fn(string $out) => str_contains($out, 'GATE_PASSED');
$anyMemberId  = (int)$db->query("SELECT id FROM members WHERE status='active' LIMIT 1")->fetchColumn();
$anyLoanId    = (int)$db->query("SELECT id FROM loans LIMIT 1")->fetchColumn();
$anyAccountId = (int)$db->query("SELECT id FROM member_savings_accounts WHERE account_type != 'corporate' AND status='active' LIMIT 1")->fetchColumn();
$perLoanFeeId = (int)$db->query("SELECT id FROM fees WHERE frequency='per_loan' LIMIT 1")->fetchColumn();

echo "=== SECTION 1: Sidebar & dashboard (dedicated sidebar-office_admin.php) ===\n";
$out = renderAs('office_admin', 'DashboardController', 'index');
ok(!str_contains($out, 'Fatal error'), 'office_admin dashboard renders with no fatal error');
$noComments = implode("\n", array_filter(explode("\n", $out), fn($l) => !str_contains($l, '<!--')));
ok(substr_count($noComments, 'Member Directory') >= 1, 'Sidebar shows Members > Member Directory');
ok(substr_count($noComments, 'Add / Register Member') >= 1, 'Sidebar shows Members > Add / Register Member');
ok(substr_count($noComments, 'Weekly Savings') >= 1, 'Sidebar shows Reports > Weekly Savings');
ok(substr_count($noComments, 'Weekly Loan Disbursements') === 0 && substr_count($noComments, 'Overdue Loans') === 0, 'Sidebar never shows Weekly Loans/Overdue links');
ok(substr_count($noComments, '>Loans<') === 0, 'Sidebar never shows a bare "Loans" heading (no loan register/origination)');
ok(substr_count($noComments, 'Withdrawal') === 0, 'Sidebar never shows Withdrawals');
ok(substr_count($noComments, 'Internal Vouchers') === 0, 'Sidebar never shows Internal Vouchers');
ok(substr_count($noComments, 'Expenses') === 0, 'Sidebar never shows Expenses');
ok(substr_count($noComments, 'Other Income') === 0, 'Sidebar never shows Other Income');
ok(substr_count($noComments, 'Investments') === 0, 'Sidebar never shows Investments');
ok(substr_count($noComments, 'Chart of Accounts') === 0, 'Sidebar never shows Chart of Accounts');
ok(substr_count($noComments, 'Add Member') === 0 || substr_count($noComments, 'Add / Register Member') >= 1, 'Register-member link uses the approved "Add / Register Member" label');
ok(substr_count($noComments, 'Register Member') >= 1, 'Quick Actions include Register Member');
ok(substr_count($noComments, 'Record Deposit') >= 1, 'Quick Actions include Record Deposit');
ok(substr_count($noComments, 'Record Repayment') >= 1, 'Quick Actions include Record Repayment');
ok(substr_count($noComments, 'Record Fee') >= 1, 'Quick Actions include Record Fee');
// Exactly 4 quick actions, verified against the controller's own array
// (the sidebar's own nested "Record Deposit" link under Savings
// legitimately still points to page=savings-add -- a pre-existing,
// universal pattern shared by every role's sidebar, since no specific
// account is selected yet at that point; only the QUICK ACTION button
// was asked to change, so this must be checked against the array, not
// the raw page, to avoid colliding with that unrelated sidebar link).
$reflFile = __DIR__ . '/tmp_office_admin_audit_qa.php';
file_put_contents($reflFile, <<<'PHP'
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
$o = new DashboardController();
$r = new ReflectionMethod($o, 'quickActionsFor');
$r->setAccessible(true);
echo json_encode($r->invoke($o, 'office_admin'));
PHP);
$qaOut = shell_exec('"' . PHP_BINARY . '" "' . $reflFile . '" 2>&1');
@unlink($reflFile);
$quickActions = json_decode($qaOut, true) ?? [];
ok(count($quickActions) === 4, 'office_admin has exactly 4 quick actions', $qaOut);
$qaUrls = implode(' ', array_column($quickActions, 'url'));
ok(!str_contains($qaUrls, 'page=savings-add'), 'office_admin Quick Actions do not link to the deprecated savings-add stub', $qaOut);
ok(str_contains($qaUrls, 'page=savings-accounts'), 'office_admin "Record Deposit" quick action points to the live Savings Accounts workflow', $qaOut);
echo "\n";

echo "=== SECTION 2: Members -- view/add/edit yes, delete/toggle no ===\n";
ok($reached(renderAs('office_admin', 'MemberController', 'index')), 'office_admin CAN view the member list');
ok($reached(renderAs('office_admin', 'MemberController', 'add')), 'office_admin CAN reach the add-member form');
ok($reached(renderAs('office_admin', 'MemberController', 'edit', ['id' => $anyMemberId])), 'office_admin CAN reach the edit-member form');
ok(!$reached(renderAs('office_admin', 'MemberController', 'toggleStatus', ['id' => $anyMemberId])), 'office_admin BLOCKED from toggling a member\'s status');
echo "\n";

echo "=== SECTION 3: Savings -- open account yes, deposit yes, withdrawal no ===\n";
ok($passed(probeGate('office_admin', 'SavingsAccountController', 'requireWriteAccess')), 'office_admin PASSES account-opening gate (requireWriteAccess)');
ok($reached(renderAs('office_admin', 'SavingsAccountController', 'depositForm', ['id' => $anyAccountId])), 'office_admin CAN reach the deposit form');
ok(!$reached(renderAs('office_admin', 'SavingsAccountController', 'withdrawalForm', ['id' => $anyAccountId])), 'office_admin BLOCKED from the withdrawal form');
echo "\n";

echo "=== SECTION 4: Loans -- view only; no origination/edit/approval/disbursement ===\n";
ok($reached(renderAs('office_admin', 'LoanController', 'view', ['id' => $anyLoanId])), 'office_admin CAN view a loan');
ok(!$reached(renderAs('office_admin', 'LoanController', 'add')), 'office_admin BLOCKED from the loan-origination form');
ok(!$reached(renderAs('office_admin', 'LoanController', 'add', [], ['member_id' => $anyMemberId, 'loan_amount' => 100000])), 'office_admin BLOCKED from POSTing a new loan');
ok(!$passed(probeGate('office_admin', 'LoanController', 'requireWriteAccess')), 'office_admin BLOCKED from editing/completing an existing loan');
ok(!$reached(renderAs('office_admin', 'LoanController', 'approve', ['loan_id' => $anyLoanId])), 'office_admin BLOCKED from approving a loan');
ok(!$reached(renderAs('office_admin', 'LoanController', 'disburse', ['loan_id' => $anyLoanId])), 'office_admin BLOCKED from disbursing a loan');
echo "\n";

echo "=== SECTION 5: Repayments -- record yes, delete no ===\n";
ok($reached(renderAs('office_admin', 'RepaymentController', 'add', ['loan_id' => $anyLoanId])), 'office_admin CAN reach record-repayment form');
ok(!$reached(renderAs('office_admin', 'RepaymentController', 'delete', ['id' => 1])), 'office_admin BLOCKED from deleting a posted repayment');
echo "\n";

echo "=== SECTION 6: Fees -- collect/record/mark-paid yes; waive, config, per-loan manual charge no ===\n";
ok($passed(probeGate('office_admin', 'FeeController', 'requireCollectAccess')), 'office_admin PASSES requireCollectAccess() (markPaid/chargeForm/chargeStore)');
ok($reached(renderAs('office_admin', 'FeeController', 'chargeForm')), 'office_admin CAN reach the Record Fee form');
ok(!$passed(probeGate('office_admin', 'FeeController', 'requireWaiveAccess')), 'office_admin BLOCKED from waiving a fee');
ok(!$reached(renderAs('office_admin', 'FeeController', 'index')), 'office_admin BLOCKED from fee-type configuration (Manage Fees)');
if ($perLoanFeeId > 0) {
    $out = renderAs('office_admin', 'FeeController', 'chargeStore', [], ['member_id' => $anyMemberId, 'fee_id' => $perLoanFeeId]);
    ok(!str_contains($out, 'Fatal error'), 'chargeStore() does not fatal on a per_loan fee attempt from office_admin (redirects with a flashed error)');
}
echo "\n";

echo "=== SECTION 7: Withdrawals -- zero authority (view/report only) ===\n";
ok(!$passed(probeGate('office_admin', 'WithdrawalController', 'requireProcessAccess')), 'office_admin BLOCKED from processing a withdrawal');
ok(!$passed(probeGate('office_admin', 'WithdrawalController', 'requireWriteAccess')), 'office_admin BLOCKED from reversing a withdrawal');
ok(!$reached(renderAs('office_admin', 'WithdrawalPolicyController', 'index')), 'office_admin BLOCKED from viewing/configuring withdrawal policy');
echo "\n";

echo "=== SECTION 8: Accounting-adjacent modules -- zero access anywhere ===\n";
ok(!$reached(renderAs('office_admin', 'ExpenseController', 'index')), 'office_admin BLOCKED from viewing Expenses');
ok(!$reached(renderAs('office_admin', 'OtherIncomeController', 'index')), 'office_admin BLOCKED from viewing Other Income');
ok(!$reached(renderAs('office_admin', 'InternalVoucherController', 'index')), 'office_admin BLOCKED from viewing Internal Vouchers');
ok(!$reached(renderAs('office_admin', 'InternalVoucherController', 'approve', ['id' => 1])), 'office_admin BLOCKED from approving a voucher');
ok(!$reached(renderAs('office_admin', 'MemberAccountAdjustmentController', 'index')), 'office_admin BLOCKED from viewing Member Account Adjustments');
ok(!$reached(renderAs('office_admin', 'MemberAccountAdjustmentController', 'approve', ['id' => 1])), 'office_admin BLOCKED from approving an account adjustment');
ok(!$reached(renderAs('office_admin', 'OpeningBalanceController', 'index')), 'office_admin BLOCKED from viewing Opening Balances');
ok(!$reached(renderAs('office_admin', 'ChartOfAccountsController', 'index')), 'office_admin BLOCKED from viewing Chart of Accounts');
ok(!$reached(renderAs('office_admin', 'AccountingReportController', 'generalLedger')), 'office_admin BLOCKED from viewing the General Ledger');
ok(!$reached(renderAs('office_admin', 'AccountingPeriodController', 'index')), 'office_admin BLOCKED from viewing Accounting Periods');
ok(!$reached(renderAs('office_admin', 'FinancialYearController', 'index')), 'office_admin BLOCKED from viewing Financial Years');
echo "\n";

echo "=== SECTION 9: Reports -- Members/Savings yes, financial tier no ===\n";
ok($reached(renderAs('office_admin', 'ReportController', 'members')), 'office_admin CAN view the Members Report');
ok($reached(renderAs('office_admin', 'ReportController', 'savings')), 'office_admin CAN view the Savings Report');
ok(!$reached(renderAs('office_admin', 'ReportController', 'loans')), 'office_admin BLOCKED from the Loans Report');
ok(!$reached(renderAs('office_admin', 'ReportController', 'financial')), 'office_admin BLOCKED from the Financial Summary');
echo "\n";

echo "=== SECTION 10: Weekly Reports -- Savings only, the one deliberate new grant ===\n";
ok($reached(renderAs('office_admin', 'WeeklyReportController', 'savings')), 'office_admin CAN view Weekly Savings');
ok(!$reached(renderAs('office_admin', 'WeeklyReportController', 'loans')), 'office_admin BLOCKED from Weekly Loan Disbursements');
ok(!$reached(renderAs('office_admin', 'WeeklyReportController', 'repayments')), 'office_admin BLOCKED from Weekly Loan Repayments');
ok(!$reached(renderAs('office_admin', 'WeeklyReportController', 'overdue')), 'office_admin BLOCKED from Weekly Overdue Loans');
echo "\n";

echo "=== SECTION 11: Statements / WhatsApp -- full access, matching the approved target ===\n";
ok($reached(renderAs('office_admin', 'StatementController', 'index')), 'office_admin CAN view Member Statements');
// whatsappSummary() calls $this->json() which exit()s, so RESULT:REACHED
// never gets echoed -- check for a real wa.me URL in the JSON output instead.
$waOut = renderAs('office_admin', 'StatementController', 'whatsappSummary', ['member_id' => $anyMemberId]);
ok(str_contains($waOut, 'wa.me') || str_contains($waOut, '"text"'), 'office_admin CAN generate a WhatsApp statement summary', $waOut);
echo "\n";

echo "=== SECTION 12: Administration -- zero access ===\n";
ok(!$reached(renderAs('office_admin', 'SettingsController', 'users')), 'office_admin BLOCKED from Settings > Users');
ok(!$reached(renderAs('office_admin', 'SettingsController', 'database')), 'office_admin BLOCKED from Settings > Database');
ok(!$reached(renderAs('office_admin', 'SettingsController', 'auditLogs')), 'office_admin BLOCKED from Settings > Audit Logs');
ok(!$reached(renderAs('office_admin', 'SettingsController', 'general')), 'office_admin BLOCKED from Settings > General');
echo "\n";

echo "=== SECTION 13: Frozen roles unaffected (Treasurer / Cashier spot-check) ===\n";
foreach (['loans', 'repayments', 'overdue'] as $m) {
    ok($reached(renderAs('treasurer', 'WeeklyReportController', $m)), "Treasurer still reaches WeeklyReportController::$m() (unaffected by the office_admin-only block)");
    ok($reached(renderAs('cashier', 'WeeklyReportController', $m)), "Cashier still reaches WeeklyReportController::$m() (unaffected by the office_admin-only block)");
}
ok($reached(renderAs('treasurer', 'DashboardController', 'index')), 'Treasurer dashboard still renders (sidebar-treasurer.php untouched)');
ok($reached(renderAs('cashier', 'DashboardController', 'index')), 'Cashier dashboard still renders (sidebar-cashier.php untouched)');
echo "\n";

echo "=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
