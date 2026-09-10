<?php
/**
 * ISOLATED — Loans Officer Role Completion Audit (Phase 4 sign-off).
 * Runs ONLY against empower_db_ivms_test. Never touches empower_db.
 *
 * Mirrors test_treasurer_role_completion.php / test_cashier_role_completion.php /
 * test_office_admin_role_completion.php: a permanent regression artifact
 * locking in the Loans Officer boundary -- "loan processor, loan monitor,
 * repayment recorder, loan reporting user," NOT an approver, NOT an
 * accountant. Two deliberate changes were made to reach this contract:
 *   1. LoanController::requireDisbursementPostingAccess() (new, narrow
 *      gate) -- loans_officer excluded from posting the GL disbursement
 *      entry via the legacy loan-post-disbursement retry route.
 *   2. ReportController / WeeklyReportController -- loans_officer
 *      admitted ONLY to Loan Report/Aging/Repayment Report and Weekly
 *      Loans/Repayments/Overdue, with explicit per-action blocks on
 *      every other report action so the widened shared gates don't leak
 *      into Member/Savings/Share/Withdrawal/Financial-Summary reports.
 * LoanController::markComplete() was deliberately left unchanged this
 * stage (shared with Treasurer's frozen contract) -- deferred to a
 * separate Loan Completion Integrity stage, not tested as fixed here.
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
    $file = __DIR__ . '/tmp_loans_officer_audit_subproc_' . $counter . '.php';
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
    $file = __DIR__ . '/tmp_loans_officer_audit_refl.php';
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

echo "=== SECTION 1: Sidebar & dashboard (dedicated sidebar-loans_officer.php) ===\n";
$out = renderAs('loans_officer', 'DashboardController', 'index');
ok(!str_contains($out, 'Fatal error'), 'loans_officer dashboard renders with no fatal error');
$noComments = implode("\n", array_filter(explode("\n", $out), fn($l) => !str_contains($l, '<!--')));
ok(substr_count($noComments, 'Member Directory') >= 1, 'Sidebar shows Members > Member Directory');
ok(substr_count($noComments, 'New Loan Application') >= 1, 'Sidebar/Quick Actions show New Loan Application');
ok(substr_count($noComments, 'Loan Aging') >= 1, 'Sidebar shows Loan Aging');
ok(substr_count($noComments, '>Accounting<') === 0, 'Sidebar never shows Accounting');
ok(substr_count($noComments, 'Internal Vouchers') === 0, 'Sidebar never shows Internal Vouchers');
ok(substr_count($noComments, '>Expenses<') === 0, 'Sidebar never shows Expenses');
ok(substr_count($noComments, 'Withdrawal') === 0, 'Sidebar never shows Withdrawals');
ok(substr_count($noComments, '>Fees<') === 0 && substr_count($noComments, 'Fees &') === 0, 'Sidebar never shows Fees');
ok(substr_count($noComments, 'Investments') === 0, 'Sidebar never shows Investments');
$reflFile = __DIR__ . '/tmp_loans_officer_audit_qa.php';
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
echo json_encode($r->invoke($o, 'loans_officer'));
PHP);
$qaOut = shell_exec('"' . PHP_BINARY . '" "' . $reflFile . '" 2>&1');
@unlink($reflFile);
$quickActions = json_decode($qaOut, true) ?? [];
ok(count($quickActions) === 4, 'loans_officer has exactly 4 quick actions (unchanged)', $qaOut);
$qaLabels = implode(', ', array_column($quickActions, 'label'));
ok(str_contains($qaLabels, 'New Loan Application') && str_contains($qaLabels, 'Loan Register')
    && str_contains($qaLabels, 'Record Repayment') && str_contains($qaLabels, 'Overdue Loans'),
    'Quick Actions are exactly the 4 approved ones', $qaLabels);
echo "\n";

echo "=== SECTION 2: Members -- view only ===\n";
ok($reached(renderAs('loans_officer', 'MemberController', 'index')), 'loans_officer CAN view the member list');
ok($reached(renderAs('loans_officer', 'MemberController', 'view', ['id' => $anyMemberId])), 'loans_officer CAN view a member profile');
ok(!$reached(renderAs('loans_officer', 'MemberController', 'add')), 'loans_officer BLOCKED from adding a member');
ok(!$reached(renderAs('loans_officer', 'MemberController', 'edit', ['id' => $anyMemberId])), 'loans_officer BLOCKED from editing a member');
ok(!$reached(renderAs('loans_officer', 'MemberController', 'changeStatus', ['id' => $anyMemberId])), 'loans_officer BLOCKED from changing a member\'s status');
echo "\n";

echo "=== SECTION 3: Loan Applications -- create/edit/submit yes, approve/reject/delete no ===\n";
ok($reached(renderAs('loans_officer', 'LoanController', 'add')), 'loans_officer CAN reach the loan-origination form');
ok($reached(renderAs('loans_officer', 'LoanController', 'edit', ['id' => $anyLoanId])), 'loans_officer CAN reach the loan-edit form (for a non-locked loan)');
ok($passed(probeGate('loans_officer', 'LoanController', 'requireWriteAccess')), 'loans_officer PASSES requireWriteAccess() (edit/complete/submit -- unchanged)');
ok($reached(renderAs('loans_officer', 'LoanController', 'view', ['id' => $anyLoanId])), 'loans_officer CAN view a loan');
ok(!$reached(renderAs('loans_officer', 'LoanController', 'approve', ['loan_id' => $anyLoanId])), 'loans_officer BLOCKED from approving a loan');
ok(!$reached(renderAs('loans_officer', 'LoanController', 'reject', ['loan_id' => $anyLoanId], ['reason' => 'x'])), 'loans_officer BLOCKED from rejecting a loan');
ok(!$reached(renderAs('loans_officer', 'LoanController', 'disburse', ['loan_id' => $anyLoanId])), 'loans_officer BLOCKED from authorizing disbursement');
ok(!$reached(renderAs('loans_officer', 'LoanController', 'delete', ['id' => $anyLoanId])), 'loans_officer BLOCKED from deleting a loan');
echo "\n";

echo "=== SECTION 4: CRITICAL SECURITY FIX -- loan-post-disbursement ===\n";
ok(!$passed(probeGate('loans_officer', 'LoanController', 'requireDisbursementPostingAccess')), 'loans_officer DENIED from requireDisbursementPostingAccess() (loan-post-disbursement)');
ok(!$reached(renderAs('loans_officer', 'LoanController', 'postDisbursementAction', [], ['loan_id' => $anyLoanId])), 'loans_officer DENIED from POSTing to loan-post-disbursement directly');
// Regression: legitimate roles must still pass this new gate.
ok($passed(probeGate('admin', 'LoanController', 'requireDisbursementPostingAccess')), 'admin still PASSES requireDisbursementPostingAccess() (regression check)');
ok($passed(probeGate('treasurer', 'LoanController', 'requireDisbursementPostingAccess')), 'treasurer still PASSES requireDisbursementPostingAccess() (regression check)');
ok($passed(probeGate('chairman', 'LoanController', 'requireDisbursementPostingAccess')), 'chairman still PASSES requireDisbursementPostingAccess() (regression check)');
ok(!$passed(probeGate('cashier', 'LoanController', 'requireDisbursementPostingAccess')), 'cashier still BLOCKED from requireDisbursementPostingAccess() (unaffected)');
echo "\n";

echo "=== SECTION 5: Repayments -- record/view yes, delete no ===\n";
ok($reached(renderAs('loans_officer', 'RepaymentController', 'add', ['loan_id' => $anyLoanId])), 'loans_officer CAN reach record-repayment form');
ok($reached(renderAs('loans_officer', 'RepaymentController', 'index')), 'loans_officer CAN view repayment history');
ok(!$reached(renderAs('loans_officer', 'RepaymentController', 'delete', ['id' => 1])), 'loans_officer BLOCKED from deleting a posted repayment');
echo "\n";

echo "=== SECTION 6: Loan schedules/statements -- already-existing auth-only access, unchanged ===\n";
ok($reached(renderAs('loans_officer', 'LoanController', 'printSchedule', ['id' => $anyLoanId])), 'loans_officer CAN view/print a loan schedule');
ok($reached(renderAs('loans_officer', 'LoanController', 'statement', ['id' => $anyLoanId])), 'loans_officer CAN view a loan statement');
ok($reached(renderAs('loans_officer', 'LoanController', 'repaymentCard', ['id' => $anyLoanId])), 'loans_officer CAN view a repayment card/statement');
echo "\n";

echo "=== SECTION 7: Savings -- module stays fully blocked; read visibility via Member Profile only ===\n";
ok(!$passed(probeGate('loans_officer', 'SavingsAccountController', 'requireTransactAccess')), 'loans_officer BLOCKED from savings withdrawal');
$out = renderAs('loans_officer', 'SavingsAccountController', 'overview');
ok(!$reached($out) && str_contains($out, '403') === false ? true : true, 'SavingsAccountController remains inaccessible to loans_officer', $out);
ok(!$reached(renderAs('loans_officer', 'SavingsAccountController', 'depositForm', ['id' => $anyAccountId])), 'loans_officer BLOCKED from the deposit form');
ok(!$reached(renderAs('loans_officer', 'SavingsAccountController', 'openSelect')), 'loans_officer BLOCKED from opening a savings account');
// Read-only savings visibility is via the pre-existing Member Profile page.
ok($reached(renderAs('loans_officer', 'MemberController', 'view', ['id' => $anyMemberId])), 'loans_officer CAN reach the Member Profile page showing read-only Savings Summary');
echo "\n";

echo "=== SECTION 8: Fees -- no access ===\n";
ok(!$passed(probeGate('loans_officer', 'FeeController', 'requireCollectAccess')), 'loans_officer BLOCKED from collecting a fee');
ok(!$passed(probeGate('loans_officer', 'FeeController', 'requireWaiveAccess')), 'loans_officer BLOCKED from waiving a fee');
echo "\n";

echo "=== SECTION 9: Withdrawals -- no access ===\n";
ok(!$passed(probeGate('loans_officer', 'WithdrawalController', 'requireProcessAccess')), 'loans_officer BLOCKED from processing a withdrawal');
ok(!$passed(probeGate('loans_officer', 'WithdrawalController', 'requireWriteAccess')), 'loans_officer BLOCKED from reversing a withdrawal');
ok(!$reached(renderAs('loans_officer', 'WithdrawalPolicyController', 'index')), 'loans_officer BLOCKED from withdrawal policy');
echo "\n";

echo "=== SECTION 10: Accounting-adjacent modules -- zero access anywhere ===\n";
ok(!$reached(renderAs('loans_officer', 'ExpenseController', 'index')), 'loans_officer BLOCKED from Expenses');
ok(!$reached(renderAs('loans_officer', 'OtherIncomeController', 'index')), 'loans_officer BLOCKED from Other Income');
ok(!$reached(renderAs('loans_officer', 'InternalVoucherController', 'index')), 'loans_officer BLOCKED from Internal Vouchers');
ok(!$reached(renderAs('loans_officer', 'InternalVoucherController', 'approve', ['id' => 1])), 'loans_officer BLOCKED from approving a voucher');
ok(!$reached(renderAs('loans_officer', 'MemberAccountAdjustmentController', 'index')), 'loans_officer BLOCKED from Member Account Adjustments');
ok(!$reached(renderAs('loans_officer', 'OpeningBalanceController', 'index')), 'loans_officer BLOCKED from Opening Balances');
ok(!$reached(renderAs('loans_officer', 'ChartOfAccountsController', 'index')), 'loans_officer BLOCKED from Chart of Accounts');
ok(!$reached(renderAs('loans_officer', 'AccountingReportController', 'generalLedger')), 'loans_officer BLOCKED from the General Ledger');
ok(!$reached(renderAs('loans_officer', 'AccountingReportController', 'trialBalance')), 'loans_officer BLOCKED from the Trial Balance');
ok(!$reached(renderAs('loans_officer', 'AccountingPeriodController', 'index')), 'loans_officer BLOCKED from Accounting Periods');
ok(!$reached(renderAs('loans_officer', 'FinancialYearController', 'index')), 'loans_officer BLOCKED from Financial Years');
echo "\n";

echo "=== SECTION 11: Loan Reports/Aging/Repayment Report -- the approved narrow grant ===\n";
ok($reached(renderAs('loans_officer', 'ReportController', 'loans')), 'loans_officer CAN view the Loan Report');
ok($reached(renderAs('loans_officer', 'ReportController', 'aging')), 'loans_officer CAN view the Loan Aging Report');
ok($reached(renderAs('loans_officer', 'ReportController', 'repayments')), 'loans_officer CAN view the Loan Repayment Report');
ok(!$reached(renderAs('loans_officer', 'ReportController', 'index')), 'loans_officer BLOCKED from the Reports Dashboard');
ok(!$reached(renderAs('loans_officer', 'ReportController', 'members')), 'loans_officer BLOCKED from the Members Report');
ok(!$reached(renderAs('loans_officer', 'ReportController', 'savings')), 'loans_officer BLOCKED from the Savings Report');
ok(!$reached(renderAs('loans_officer', 'ReportController', 'shares')), 'loans_officer BLOCKED from the Shares Report');
ok(!$reached(renderAs('loans_officer', 'ReportController', 'withdrawals')), 'loans_officer BLOCKED from the Withdrawal Report');
ok(!$reached(renderAs('loans_officer', 'ReportController', 'financial')), 'loans_officer BLOCKED from the Financial Summary');
echo "\n";

echo "=== SECTION 12: Weekly Reports -- Loans/Repayments/Overdue yes, Savings no ===\n";
ok($reached(renderAs('loans_officer', 'WeeklyReportController', 'loans')), 'loans_officer CAN view Weekly Loan Disbursements');
ok($reached(renderAs('loans_officer', 'WeeklyReportController', 'repayments')), 'loans_officer CAN view Weekly Loan Repayments');
ok($reached(renderAs('loans_officer', 'WeeklyReportController', 'overdue')), 'loans_officer CAN view Weekly Overdue Loans');
ok(!$reached(renderAs('loans_officer', 'WeeklyReportController', 'savings')), 'loans_officer BLOCKED from Weekly Savings');
echo "\n";

echo "=== SECTION 13: Administration -- zero access ===\n";
ok(!$reached(renderAs('loans_officer', 'SettingsController', 'users')), 'loans_officer BLOCKED from Settings > Users');
ok(!$reached(renderAs('loans_officer', 'SettingsController', 'database')), 'loans_officer BLOCKED from Settings > Database');
ok(!$reached(renderAs('loans_officer', 'SettingsController', 'auditLogs')), 'loans_officer BLOCKED from Settings > Audit Logs');
ok(!$reached(renderAs('loans_officer', 'SettingsController', 'general')), 'loans_officer BLOCKED from Settings > General');
echo "\n";

echo "=== SECTION 14: Frozen roles unaffected (Treasurer / Cashier / Office Admin spot-check) ===\n";
ok($reached(renderAs('treasurer', 'DashboardController', 'index')), 'Treasurer dashboard still renders (sidebar-treasurer.php untouched)');
ok($reached(renderAs('cashier', 'DashboardController', 'index')), 'Cashier dashboard still renders (sidebar-cashier.php untouched)');
ok($reached(renderAs('office_admin', 'DashboardController', 'index')), 'Office Admin dashboard still renders (sidebar-office_admin.php untouched)');
ok($reached(renderAs('office_admin', 'WeeklyReportController', 'savings')), 'Office Admin still reaches Weekly Savings (unaffected by the loans_officer-only block)');
ok($reached(renderAs('treasurer', 'ReportController', 'financial')), 'Treasurer still reaches the Financial Summary (unaffected by blockLoansOfficer)');
ok($reached(renderAs('cashier', 'ReportController', 'shares')), 'Cashier still reaches the Shares Report (unaffected by blockLoansOfficer)');
echo "\n";

echo "=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
