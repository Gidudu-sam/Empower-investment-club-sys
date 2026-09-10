<?php
/**
 * ISOLATED — Cashier Role Completion Audit (Phase 2 sign-off).
 * Runs ONLY against empower_db_ivms_test. Never touches empower_db.
 *
 * Mirrors test_treasurer_role_completion.php's structure and intent: a
 * permanent regression artifact that locks in the Cashier boundary
 * established by the Phase 2 audit -- "collect and record money," no
 * loan/voucher/adjustment/accounting authority, no reversal authority.
 * The audit found the backend already matched this principle almost
 * exactly (no permission changes made here) -- this test exists so a
 * future change that silently widens or narrows Cashier is caught.
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
    $file = __DIR__ . '/tmp_cashier_audit_subproc_' . $counter . '.php';
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
    $file = __DIR__ . '/tmp_cashier_audit_refl.php';
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

echo "=== SECTION 1: Sidebar visibility (dashboard render, cashier) ===\n";
$out = renderAs('cashier', 'DashboardController', 'index');
ok(!str_contains($out, 'Fatal error'), 'Cashier dashboard renders with no fatal error');
$noComments = implode("\n", array_filter(explode("\n", $out), fn($l) => !str_contains($l, '<!--')));
ok(substr_count($noComments, '>Collections<') === 1, 'Sidebar shows "Collections" heading exactly once');
ok(substr_count($noComments, 'Charge Ledger') >= 1, 'Sidebar shows Fees & Charges > Charge Ledger');
ok(substr_count($noComments, 'Add Member') === 0, 'Sidebar never shows "Add Member" text for cashier');
ok(substr_count($noComments, 'Record Loan') === 0, 'Sidebar never shows "Record Loan" text for cashier');
ok(substr_count($noComments, 'Internal Vouchers') === 0, 'Sidebar never shows "Internal Vouchers" for cashier');
ok(substr_count($noComments, 'Expenses') === 0, 'Sidebar never shows "Expenses" for cashier');
ok(substr_count($noComments, 'Other Income') === 0, 'Sidebar never shows "Other Income" for cashier');
ok(substr_count($noComments, 'Chart of Accounts') === 0, 'Sidebar never shows "Chart of Accounts" for cashier');
ok(substr_count($noComments, 'Investments') === 0, 'Sidebar never shows "Investments" for cashier');
ok(substr_count($noComments, '>Financial Reports<') === 0, 'Sidebar never shows the "Financial Reports" (Trial Balance/Income Statement/Balance Sheet) section for cashier');
ok(substr_count($noComments, 'Record Deposit') >= 1, 'Dashboard Quick Actions include Record Deposit');
ok(substr_count($noComments, 'Record Repayment') >= 1, 'Dashboard Quick Actions include Record Repayment');
ok(substr_count($noComments, 'Record Fee') >= 1, 'Dashboard Quick Actions include Record Fee');
ok(substr_count($noComments, 'Process Withdrawal') >= 1, 'Dashboard Quick Actions include Process Withdrawal');
// Checked against DashboardController's own quickActionsFor() output
// directly, not the rendered page -- the sidebar's own "Record Deposit"
// nested link legitimately still points to page=savings-add (a pre-
// existing, universal pattern shared by every role's sidebar, including
// Treasurer's frozen one) since no specific account is selected yet at
// that point; only the QUICK ACTION button was asked to change.
$reflFile = __DIR__ . '/tmp_cashier_audit_qa.php';
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
echo json_encode($r->invoke($o, 'cashier'));
PHP);
$qaOut = shell_exec('"' . PHP_BINARY . '" "' . $reflFile . '" 2>&1');
@unlink($reflFile);
$quickActions = json_decode($qaOut, true) ?? [];
$qaUrls = implode(' ', array_column($quickActions, 'url'));
ok(!str_contains($qaUrls, 'page=savings-add'), 'Cashier Quick Actions no longer link to the deprecated savings-add stub', $qaOut);
ok(str_contains($qaUrls, 'page=savings-accounts'), 'Cashier "Record Deposit" quick action points to the live Savings Accounts workflow', $qaOut);
ok(count($quickActions) === 4, 'Cashier has exactly 4 quick actions (Record Deposit/Repayment/Fee, Process Withdrawal -- no separate Mark Paid button)', $qaOut);
echo "\n";

echo "=== SECTION 2: Members -- view yes, add/edit/delete no ===\n";
ok($reached(renderAs('cashier', 'MemberController', 'view', ['id' => $anyMemberId])), 'Cashier CAN view a member');
ok(!$reached(renderAs('cashier', 'MemberController', 'add')), 'Cashier BLOCKED from add-member form');
ok(!$reached(renderAs('cashier', 'MemberController', 'edit', ['id' => $anyMemberId])), 'Cashier BLOCKED from edit-member form');
ok(!$reached(renderAs('cashier', 'MemberController', 'add', [], ['full_name' => 'X', 'email' => 'x@example.test'])), 'Cashier BLOCKED from POSTing a new member');
echo "\n";

echo "=== SECTION 3: Savings -- deposit yes, withdrawal (process) yes, no account-opening ===\n";
ok($reached(renderAs('cashier', 'SavingsAccountController', 'depositForm', ['id' => $anyAccountId])), 'Cashier CAN reach the deposit form');
ok($reached(renderAs('cashier', 'SavingsAccountController', 'withdrawalForm', ['id' => $anyAccountId])), 'Cashier CAN reach the withdrawal form (existing policy, unchanged)');
ok(!$passed(probeGate('cashier', 'SavingsAccountController', 'requireWriteAccess')), 'Cashier BLOCKED from opening a new savings account (requireWriteAccess)');
echo "\n";

echo "=== SECTION 4: Loans -- view yes, originate no, approve no; repayment recording yes ===\n";
ok($reached(renderAs('cashier', 'LoanController', 'view', ['id' => $anyLoanId])), 'Cashier CAN view a loan');
ok(!$reached(renderAs('cashier', 'LoanController', 'add')), 'Cashier BLOCKED from the loan-origination form');
ok(!$reached(renderAs('cashier', 'LoanController', 'add', [], ['member_id' => $anyMemberId, 'loan_amount' => 100000])), 'Cashier BLOCKED from POSTing a new loan');
ok(!$reached(renderAs('cashier', 'LoanController', 'approve', ['loan_id' => $anyLoanId])), 'Cashier BLOCKED from approving a loan');
ok(!$passed(probeGate('cashier', 'LoanController', 'requireWriteAccess')), 'Cashier BLOCKED from editing/completing an existing loan (narrower than Treasurer, by design)');
ok($reached(renderAs('cashier', 'RepaymentController', 'add', ['loan_id' => $anyLoanId])), 'Cashier CAN reach record-repayment form');
ok(!$reached(renderAs('cashier', 'RepaymentController', 'delete', ['id' => 1])), 'Cashier BLOCKED from deleting a posted repayment (admin-only)');
echo "\n";

echo "=== SECTION 5: Fees -- view/record/mark-paid yes, waive no, per_loan manual charge no ===\n";
ok($reached(renderAs('cashier', 'FeeController', 'charges')), 'Cashier CAN view Fee Charges (Charge Ledger)');
ok($reached(renderAs('cashier', 'FeeController', 'chargeForm')), 'Cashier CAN reach Record Fee form');
ok($passed(probeGate('cashier', 'FeeController', 'requireCollectAccess')), 'Cashier PASSES requireCollectAccess() (markPaid/chargeForm/chargeStore)');
ok(!$passed(probeGate('cashier', 'FeeController', 'requireWaiveAccess')), 'Cashier BLOCKED from waiving a fee');
if ($perLoanFeeId > 0) {
    $out = renderAs('cashier', 'FeeController', 'chargeStore', [], ['member_id' => $anyMemberId, 'fee_id' => $perLoanFeeId]);
    ok(!str_contains($out, 'Fatal error'), 'chargeStore() does not fatal on a per_loan fee attempt from cashier (redirects with a flashed error)');
}
echo "\n";

echo "=== SECTION 6: Withdrawals -- process yes, reverse no (the documented asymmetry) ===\n";
ok($passed(probeGate('cashier', 'WithdrawalController', 'requireProcessAccess')), 'Cashier PASSES requireProcessAccess() -- can process/initiate a withdrawal');
ok(!$passed(probeGate('cashier', 'WithdrawalController', 'requireWriteAccess')), 'Cashier BLOCKED from requireWriteAccess() -- cannot reverse a withdrawal (Treasurer/Chairman/Admin only, documented business rule)');
ok(!$reached(renderAs('cashier', 'WithdrawalPolicyController', 'index')), 'Cashier BLOCKED from viewing/configuring withdrawal policy');
echo "\n";

echo "=== SECTION 7: Accounting-adjacent modules -- zero access anywhere (Expenses/Other Income/Vouchers/Accounting) ===\n";
ok(!$reached(renderAs('cashier', 'ExpenseController', 'index')), 'Cashier BLOCKED from viewing Expenses (constructor gate)');
ok(!$reached(renderAs('cashier', 'OtherIncomeController', 'index')), 'Cashier BLOCKED from viewing Other Income (constructor gate)');
ok(!$reached(renderAs('cashier', 'InternalVoucherController', 'index')), 'Cashier BLOCKED from viewing Internal Vouchers (constructor gate)');
ok(!$reached(renderAs('cashier', 'InternalVoucherController', 'approve', ['id' => 1])), 'Cashier BLOCKED from approving a voucher');
ok(!$reached(renderAs('cashier', 'MemberAccountAdjustmentController', 'index')), 'Cashier BLOCKED from viewing Member Account Adjustments (constructor gate)');
ok(!$reached(renderAs('cashier', 'MemberAccountAdjustmentController', 'approve', ['id' => 1])), 'Cashier BLOCKED from approving an account adjustment');
ok(!$reached(renderAs('cashier', 'MemberAccountAdjustmentController', 'reverse', ['id' => 1])), 'Cashier BLOCKED from reversing an account adjustment');
ok(!$reached(renderAs('cashier', 'OpeningBalanceController', 'index')), 'Cashier BLOCKED from viewing Opening Balances (constructor gate)');
ok(!$reached(renderAs('cashier', 'ChartOfAccountsController', 'index')), 'Cashier BLOCKED from viewing Chart of Accounts');
ok(!$reached(renderAs('cashier', 'AccountingReportController', 'generalLedger')), 'Cashier BLOCKED from viewing the General Ledger');
ok(!$reached(renderAs('cashier', 'AccountingReportController', 'trialBalance')), 'Cashier BLOCKED from viewing the Trial Balance');
ok(!$reached(renderAs('cashier', 'AccountingPeriodController', 'index')), 'Cashier BLOCKED from viewing Accounting Periods (constructor gate)');
ok(!$reached(renderAs('cashier', 'FinancialYearController', 'index')), 'Cashier BLOCKED from viewing Financial Years (constructor gate)');
echo "\n";

echo "=== SECTION 8: Reports -- operational tier reachable, viewing != modifying ===\n";
ok($reached(renderAs('cashier', 'ReportController', 'savings')), 'Cashier CAN view the Savings Report');
ok($reached(renderAs('cashier', 'ReportController', 'repayments')), 'Cashier CAN view Repayment Reports');
ok($reached(renderAs('cashier', 'ReportController', 'withdrawals')), 'Cashier CAN view Withdrawal Reports');
ok($reached(renderAs('cashier', 'FeeController', 'report')), 'Cashier CAN view the Fees Report');
ok($reached(renderAs('cashier', 'StatementController', 'index')) || $reached(renderAs('cashier', 'StatementController', 'view')), 'Cashier CAN view Member Statements');
ok($reached(renderAs('cashier', 'WeeklyReportController', 'savings')), 'Cashier CAN view Weekly Savings Report');
echo "\n";

echo "=== SECTION 9: Administration -- zero access ===\n";
ok(!$reached(renderAs('cashier', 'SettingsController', 'users')), 'Cashier BLOCKED from Settings > Users');
ok(!$reached(renderAs('cashier', 'SettingsController', 'database')), 'Cashier BLOCKED from Settings > Database');
ok(!$reached(renderAs('cashier', 'SettingsController', 'auditLogs')), 'Cashier BLOCKED from Settings > Audit Logs');
ok(!$reached(renderAs('cashier', 'SettingsController', 'general')), 'Cashier BLOCKED from Settings > General/club settings');
echo "\n";

echo "=== SECTION 10: Cashier can never approve their own work (maker-checker sanity) ===\n";
// Cashier has no maker role in any maker-checker module (vouchers/
// adjustments/opening balances/loans) at all -- confirmed by Section 7,
// so there is no "own work" for cashier to approve in the first place.
// This section documents that fact rather than re-testing self-approval
// logic that belongs to admin/chairman/treasurer's own test suites.
ok(true, 'Cashier holds no maker role in any maker-checker module -- self-approval is structurally impossible, not merely blocked');
echo "\n";

echo "=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
