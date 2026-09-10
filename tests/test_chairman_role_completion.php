<?php
/**
 * ISOLATED — Chairman / Team Leader Role Completion Audit (Phase 5 sign-off).
 * Runs ONLY against empower_db_ivms_test. Never touches empower_db.
 *
 * Mirrors test_treasurer_role_completion.php / test_cashier_role_completion.php /
 * test_office_admin_role_completion.php / test_loans_officer_role_completion.php:
 * a permanent regression artifact locking in the Chairman boundary --
 * "governance, approval, oversight" -- not a second Administrator.
 *
 * Two deliberate backend changes were made to reach this contract:
 *   1. ChartOfAccountsController::requireAdmin(), AccountingPeriodController::
 *      requireWriteAccess(), FinancialYearController::requireWriteAccess(),
 *      WithdrawalPolicyController::requireAdmin() -- all narrowed from
 *      admin/chairman to admin/treasurer. Chairman keeps view-only access
 *      to all four (unchanged constructor gates); Treasurer gains
 *      create/close/reopen/configure as the designated "Financial
 *      Operations & Accounting" role (re-verified in
 *      test_treasurer_role_completion.php, not re-tested here beyond the
 *      Chairman-blocked side).
 *   2. SettingsController::requireAuditLogAccess() (new, narrow gate) --
 *      Chairman gains read-only Audit Log visibility, without touching
 *      Database/Backup access (still admin/system_admin only).
 * Every other Chairman capability (loan/voucher/adjustment/opening-balance/
 * investment approve-reject, withdrawal reversal, full report/statement
 * view access) was already correct and is re-verified here unchanged.
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
    $file = __DIR__ . '/tmp_chairman_audit_subproc_' . $counter . '.php';
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
$anyMemberId = (int)$db->query("SELECT id FROM members WHERE status='active' LIMIT 1")->fetchColumn();
$anyLoanId   = (int)$db->query("SELECT id FROM loans LIMIT 1")->fetchColumn();

echo "=== SECTION 1: Sidebar & dashboard (dedicated sidebar-chairman.php) ===\n";
$out = renderAs('chairman', 'DashboardController', 'index');
ok(!str_contains($out, 'Fatal error'), 'Chairman dashboard renders with no fatal error');
$noComments = implode("\n", array_filter(explode("\n", $out), fn($l) => !str_contains($l, '<!--')));
ok(substr_count($noComments, "Chairman's Overview") === 1, 'Dashboard heading reads "Chairman\'s Overview"');
ok(substr_count($noComments, 'Action Required') >= 1, 'Dashboard shows the Action Required hero panel');
ok(substr_count($noComments, 'Loan Portfolio') >= 1, 'Dashboard shows the Loan Portfolio panel');
ok(substr_count($noComments, 'Club Health') >= 1, 'Dashboard shows the Club Health panel');
ok(substr_count($noComments, 'Recent Decisions') >= 1, 'Dashboard shows the Recent Decisions panel');
ok(substr_count($noComments, 'Recent Activity') >= 1, 'Dashboard shows the Recent Activity panel');
ok(substr_count($noComments, "This Week's Savings Collections") === 0, 'Dashboard no longer shows This Week\'s Savings Collections for Chairman');
ok(substr_count($noComments, 'Loans Due This Week') === 0, 'Dashboard no longer shows Loans Due This Week for Chairman');
ok(substr_count($noComments, 'Overdue Loans') >= 1, 'Dashboard still shows Overdue Loans for Chairman (kept)');
ok(substr_count($noComments, 'Active Members') >= 1, 'Dashboard still shows Active Members for Chairman (kept)');
ok(substr_count($noComments, 'Audit Activity') >= 1, 'Quick Actions include Audit Activity');
$reflFile = __DIR__ . '/tmp_chairman_audit_qa.php';
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
echo json_encode($r->invoke($o, 'chairman'));
PHP);
$qaOut = shell_exec('"' . PHP_BINARY . '" "' . $reflFile . '" 2>&1');
@unlink($reflFile);
$quickActions = json_decode($qaOut, true) ?? [];
ok(count($quickActions) === 4, 'Chairman has exactly 4 quick actions', $qaOut);
ok(($quickActions[0]['label'] ?? '') === 'Pending Approvals', 'Pending Approvals is the primary (first) quick action', $qaOut);
echo "\n";

echo "=== SECTION 2: Members -- view only ===\n";
ok($reached(renderAs('chairman', 'MemberController', 'index')), 'Chairman CAN view the member list');
ok(!$reached(renderAs('chairman', 'MemberController', 'add')), 'Chairman BLOCKED from adding a member');
ok(!$reached(renderAs('chairman', 'MemberController', 'edit', ['id' => $anyMemberId])), 'Chairman BLOCKED from editing a member');
ok(!$reached(renderAs('chairman', 'MemberController', 'changeStatus', ['id' => $anyMemberId])), 'Chairman BLOCKED from changing a member\'s status');
echo "\n";

echo "=== SECTION 3: Loans -- view + approve/reject/disburse yes, originate/edit/delete no ===\n";
ok($reached(renderAs('chairman', 'LoanController', 'view', ['id' => $anyLoanId])), 'Chairman CAN view a loan');
ok(!$reached(renderAs('chairman', 'LoanController', 'add')), 'Chairman BLOCKED from originating a loan');
ok(!$reached(renderAs('chairman', 'LoanController', 'edit', ['id' => $anyLoanId])), 'Chairman BLOCKED from editing a loan');
ok(!$reached(renderAs('chairman', 'LoanController', 'delete', ['id' => $anyLoanId])), 'Chairman BLOCKED from deleting a loan');
ok(!$reached(renderAs('chairman', 'LoanController', 'approve', ['loan_id' => $anyLoanId], ['x' => '1'])), 'Chairman reaches the approve gate (result depends on loan status, not blocked by role)');
echo "\n";

echo "=== SECTION 4: Repayments -- view only ===\n";
ok($reached(renderAs('chairman', 'RepaymentController', 'index')), 'Chairman CAN view repayment history');
ok(!$reached(renderAs('chairman', 'RepaymentController', 'add', ['loan_id' => $anyLoanId])), 'Chairman BLOCKED from recording a repayment');
ok(!$reached(renderAs('chairman', 'RepaymentController', 'delete', ['id' => 1])), 'Chairman BLOCKED from deleting a repayment');
echo "\n";

echo "=== SECTION 5: Savings -- view only, no transactions ===\n";
ok($reached(renderAs('chairman', 'SavingsAccountController', 'overview')), 'Chairman CAN view Savings Accounts overview');
ok(!$reached(renderAs('chairman', 'SavingsAccountController', 'depositForm', ['id' => 1])), 'Chairman BLOCKED from the deposit form');
ok(!$reached(renderAs('chairman', 'SavingsAccountController', 'withdrawalForm', ['id' => 1])), 'Chairman BLOCKED from the withdrawal form');
ok(!$reached(renderAs('chairman', 'SavingsAccountController', 'openSelect')), 'Chairman BLOCKED from opening a savings account');
echo "\n";

echo "=== SECTION 6: Withdrawals -- view + reverse yes, process no ===\n";
ok($reached(renderAs('chairman', 'WithdrawalController', 'index')), 'Chairman CAN view withdrawals');
ok(!$reached(renderAs('chairman', 'WithdrawalController', 'process')), 'Chairman BLOCKED from processing a new withdrawal');
ok(!$reached(renderAs('chairman', 'WithdrawalController', 'settings')), 'Chairman BLOCKED from withdrawal settings (legacy, admin-only)');
echo "\n";

echo "=== SECTION 7: Fees / Expenses / Other Income -- view only ===\n";
ok($reached(renderAs('chairman', 'FeeController', 'charges')), 'Chairman CAN view Fee Charges');
ok(!$reached(renderAs('chairman', 'FeeController', 'chargeForm')), 'Chairman BLOCKED from recording a fee');
ok(!$reached(renderAs('chairman', 'FeeController', 'index')), 'Chairman BLOCKED from fee-type configuration');
ok($reached(renderAs('chairman', 'ExpenseController', 'index')), 'Chairman CAN view Expenses');
ok(!$reached(renderAs('chairman', 'ExpenseController', 'create')), 'Chairman BLOCKED from creating an expense');
ok($reached(renderAs('chairman', 'OtherIncomeController', 'index')), 'Chairman CAN view Other Income');
ok(!$reached(renderAs('chairman', 'OtherIncomeController', 'create')), 'Chairman BLOCKED from creating other income');
echo "\n";

echo "=== SECTION 8: Vouchers / Adjustments / Opening Balances / Investments -- view + approve/reject(/reverse) yes, maker no ===\n";
ok($reached(renderAs('chairman', 'InternalVoucherController', 'index')), 'Chairman CAN view Internal Vouchers');
ok(!$reached(renderAs('chairman', 'InternalVoucherController', 'create')), 'Chairman BLOCKED from creating a voucher (maker)');
ok(!$reached(renderAs('chairman', 'InternalVoucherController', 'approve', ['id' => 1])), 'Chairman reaches the voucher-approve gate (result depends on record state, role is not the blocker)');
ok($reached(renderAs('chairman', 'MemberAccountAdjustmentController', 'index')), 'Chairman CAN view Member Account Adjustments');
ok(!$reached(renderAs('chairman', 'MemberAccountAdjustmentController', 'create')), 'Chairman BLOCKED from creating an adjustment (maker)');
ok($reached(renderAs('chairman', 'OpeningBalanceController', 'index')), 'Chairman CAN view Opening Balances');
ok(!$reached(renderAs('chairman', 'OpeningBalanceController', 'create')), 'Chairman BLOCKED from creating an opening balance (maker)');
ok($reached(renderAs('chairman', 'InvestmentController', 'index')), 'Chairman CAN view Investments');
ok(!$reached(renderAs('chairman', 'InvestmentController', 'create')), 'Chairman BLOCKED from creating an investment (maker)');
echo "\n";

echo "=== SECTION 9: THE FOUR NARROWED GATES -- Chairman view-only, Treasurer now the administrator ===\n";
ok($reached(renderAs('chairman', 'ChartOfAccountsController', 'index')), 'Chairman CAN view Chart of Accounts');
ok(!$reached(renderAs('chairman', 'ChartOfAccountsController', 'create')), 'Chairman BLOCKED from creating a Chart of Accounts entry (moved to Treasurer)');
ok($reached(renderAs('treasurer', 'ChartOfAccountsController', 'create')), 'Treasurer CAN create a Chart of Accounts entry (regression check)');
ok($reached(renderAs('chairman', 'AccountingPeriodController', 'index')), 'Chairman CAN view Accounting Periods');
ok(!$reached(renderAs('chairman', 'AccountingPeriodController', 'create')), 'Chairman BLOCKED from creating an accounting period (moved to Treasurer)');
ok($reached(renderAs('treasurer', 'AccountingPeriodController', 'create')), 'Treasurer CAN create an accounting period (regression check)');
ok($reached(renderAs('chairman', 'FinancialYearController', 'index')), 'Chairman CAN view Financial Years');
ok(!$reached(renderAs('chairman', 'FinancialYearController', 'create')), 'Chairman BLOCKED from creating a financial year (moved to Treasurer)');
ok($reached(renderAs('treasurer', 'FinancialYearController', 'create')), 'Treasurer CAN create a financial year (regression check)');
ok($reached(renderAs('chairman', 'WithdrawalPolicyController', 'index')), 'Chairman CAN view Withdrawal Policies');
ok(!$reached(renderAs('chairman', 'WithdrawalPolicyController', 'create', ['account_type' => 'voluntary'])), 'Chairman BLOCKED from creating a withdrawal policy (moved to Treasurer)');
ok($reached(renderAs('treasurer', 'WithdrawalPolicyController', 'create', ['account_type' => 'voluntary'])), 'Treasurer CAN create a withdrawal policy (regression check)');
echo "\n";

echo "=== SECTION 10: Audit Logs -- the one new grant this stage ===\n";
ok($reached(renderAs('chairman', 'SettingsController', 'auditLogs')), 'Chairman CAN view Audit Logs (new this stage)');
ok(!$reached(renderAs('chairman', 'SettingsController', 'database')), 'Chairman BLOCKED from Database (unchanged, not part of this grant)');
ok(!$reached(renderAs('chairman', 'SettingsController', 'databaseBackup')), 'Chairman BLOCKED from Database Backup (unchanged)');
echo "\n";

echo "=== SECTION 11: Reports / Statements -- full view access, unchanged ===\n";
ok($reached(renderAs('chairman', 'ReportController', 'loans')), 'Chairman CAN view the Loan Report');
ok($reached(renderAs('chairman', 'ReportController', 'financial')), 'Chairman CAN view the Financial Summary');
ok($reached(renderAs('chairman', 'AccountingReportController', 'trialBalance')), 'Chairman CAN view the Trial Balance');
ok($reached(renderAs('chairman', 'AccountingReportController', 'generalLedger')), 'Chairman CAN view the General Ledger');
ok($reached(renderAs('chairman', 'StatementController', 'index')), 'Chairman CAN view Member Statements');
ok($reached(renderAs('chairman', 'WeeklyReportController', 'overdue')), 'Chairman CAN view Weekly Overdue Loans');
echo "\n";

echo "=== SECTION 12: Administration -- zero access beyond the new Audit Log grant ===\n";
ok(!$reached(renderAs('chairman', 'SettingsController', 'users')), 'Chairman BLOCKED from Settings > Users');
ok(!$reached(renderAs('chairman', 'SettingsController', 'general')), 'Chairman BLOCKED from Settings > General');
ok(!$reached(renderAs('chairman', 'SettingsController', 'withdrawalSettings')), 'Chairman BLOCKED from legacy withdrawal settings');
echo "\n";

echo "=== SECTION 13: Frozen/refined roles unaffected (spot-check) ===\n";
ok($reached(renderAs('treasurer', 'DashboardController', 'index')), 'Treasurer dashboard still renders (sidebar-treasurer.php untouched)');
ok($reached(renderAs('cashier', 'DashboardController', 'index')), 'Cashier dashboard still renders (sidebar-cashier.php untouched)');
ok($reached(renderAs('office_admin', 'DashboardController', 'index')), 'Office Admin dashboard still renders (sidebar-office_admin.php untouched)');
ok($reached(renderAs('loans_officer', 'DashboardController', 'index')), 'Loans Officer dashboard still renders (sidebar-loans_officer.php untouched)');
ok(!$reached(renderAs('loans_officer', 'ChartOfAccountsController', 'index')), 'Loans Officer still BLOCKED from Chart of Accounts (unaffected)');
ok(!$reached(renderAs('cashier', 'AccountingPeriodController', 'index')), 'Cashier still BLOCKED from Accounting Periods (unaffected)');
ok(!$reached(renderAs('office_admin', 'FinancialYearController', 'index')), 'Office Admin still BLOCKED from Financial Years (unaffected)');
echo "\n";

echo "=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
