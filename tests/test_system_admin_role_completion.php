<?php
/**
 * ISOLATED — System Administrator role completion tests (2026-09).
 * Runs ONLY against empower_db_ivms_test. Never touches empower_db.
 *
 * Implements the audit's Section 11 test plan: confirms 'system_admin'
 * (a) can reach every genuinely-IT function already granted to it, and
 * (b) is denied every one of the ~19 financial functions traced in the
 * pre-implementation audit, via direct controller invocation with real
 * session role manipulation -- not by trusting sidebar visibility.
 *
 * No backend gate was modified for this stage. Every "should be blocked"
 * assertion below is proving PRE-EXISTING backend behavior (system_admin
 * was never added to any financial gate) -- this suite documents and
 * freezes that contract, it does not newly create it. The only code
 * change this stage made is additive UI (sidebar-system_admin.php).
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

/** Runs one controller action in its own subprocess (many of these call
 *  redirect()/die(), which would exit this whole test script if invoked
 *  in-process) with the given session role, GET, and POST. Captures both
 *  raw stdout and the flash session state (via a shutdown function, since
 *  redirect()'s exit still lets shutdown functions run) -- mirrors the
 *  proven pattern from test_account_level_statements.php /
 *  test_stage19c_withdrawal_safety.php's _endpoint_harness.php. */
function runAction(string $role, string $controllerClass, string $method, array $get = [], array $post = []): array {
    $file    = __DIR__ . '/tmp_sysadmin_subproc_' . uniqid() . '.php';
    $outfile = __DIR__ . '/tmp_sysadmin_out_' . uniqid() . '.json';
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

/** True if the response looks like a denied/blocked request: a 403 die(),
 *  a flashed error redirect, or (defensively) a caught exception -- never
 *  a fatal PHP error, which would indicate a real bug, not a working gate. */
function wasBlocked(array $resp): bool {
    $raw = $resp['raw'];
    $err = $resp['flash']['error'] ?? '';
    if (str_contains($raw, 'Fatal error')) return false; // a real bug, not a gate
    // NOTE: a bare '403' substring check used to live here too, but a real
    // Chart of Accounts / GL-code-bearing page can legitimately contain
    // "403" inside an account code or id (false positive) once
    // system_admin gained real read access to that content under SA-1 --
    // "Access denied." / "...privileges required." are the actual literal
    // strings every real denial path in this codebase uses, so they are
    // the only reliable signal.
    return str_contains($raw, 'Access denied')
        || str_contains($raw, 'privileges required')
        || $err !== '';
}

echo "=== SECTION A: System Administrator CAN reach IT/system functions ===\n";

$out = runAction('system_admin', 'SettingsController', 'users');
ok(!str_contains($out['raw'], 'Fatal error') && !wasBlocked($out), 'system_admin can view User Management', $out['raw']);

$out = runAction('system_admin', 'SettingsController', 'auditLogs');
ok(!str_contains($out['raw'], 'Fatal error') && !wasBlocked($out), 'system_admin can view Audit Logs', $out['raw']);

$out = runAction('system_admin', 'SettingsController', 'database');
ok(!str_contains($out['raw'], 'Fatal error') && !wasBlocked($out), 'system_admin can view the Database/Backup page', $out['raw']);

// userToggle()/userResetPassword() need a real target user id -- use the
// seeded admin account (id 1) itself is unsafe to toggle off, so target
// any non-primary existing user instead; skip gracefully if none exists.
$pdo = Database::getInstance()->getConnection();
$targetUserId = (int)$pdo->query("SELECT id FROM users WHERE id != 1 ORDER BY id LIMIT 1")->fetchColumn();
if ($targetUserId > 0) {
    $out = runAction('system_admin', 'SettingsController', 'userToggle', [], ['id' => (string)$targetUserId, 'csrf_token' => 'x']);
    // CSRF will legitimately fail here (fresh subprocess session has no
    // matching token) -- what matters is that the ROLE gate itself does
    // not reject first with an access-denied message.
    ok(!str_contains($out['flash']['error'] ?? '', 'Access denied') && !str_contains($out['flash']['error'] ?? '', 'privileges required'),
        'system_admin is not role-blocked from userToggle() (CSRF mismatch is the only expected rejection)', json_encode($out['flash']));
} else {
    ok(true, 'userToggle() role-gate check skipped (no secondary test user available)');
}

echo "\n=== SECTION B: System Administrator dashboard/sidebar unaffected ===\n";
$out = runAction('system_admin', 'DashboardController', 'index');
ok(!str_contains($out['raw'], 'Fatal error'), 'System Administrator dashboard still renders with no fatal error');
ok(str_contains($out['raw'], 'System Administration'), 'Dashboard still shows the System Administration heading');
ok(str_contains($out['raw'], 'Total Users') && str_contains($out['raw'], 'Database Backups'), 'Dashboard still shows its system stat cards');
ok(str_contains($out['raw'], 'User &amp; Access Management') || str_contains($out['raw'], 'User & Access Management'), 'Dedicated system_admin sidebar renders (User & Access Management)');
ok(str_contains($out['raw'], 'Audit Logs'), 'Dedicated system_admin sidebar shows Audit Logs');
ok(str_contains($out['raw'], 'Backup Database'), 'Dedicated system_admin sidebar shows Backup Database');
// "Chart of Accounts" removed from this leak list: SA-1 (2026-09)
// deliberately adds it to the sidebar as a legitimate configuration link
// (which GL accounts exist is settings, not transaction posting) -- its
// own controller gate was widened to include system_admin on purpose.
$financialLeakTerms = ['Record Deposit', 'Record Repayment', 'Record Loan', 'Process Withdrawal', 'Loan Register', 'Savings Register', 'Record Fee', 'New Voucher'];
$leaked = array_filter($financialLeakTerms, fn($t) => str_contains($out['raw'], $t));
ok(empty($leaked), 'No financial module links leak into the system_admin sidebar', implode(', ', $leaked));

echo "\n=== SECTION C: System Administrator CANNOT perform financial transactions ===\n";

// Savings
$out = runAction('system_admin', 'SavingsAccountController', 'depositStore', [], ['account_id' => '1', 'amount' => '10000', 'payment_method' => 'Cash', 'transaction_date' => '2026-07-15', 'csrf_token' => 'x']);
ok(wasBlocked($out), 'system_admin cannot record a savings deposit', $out['raw'] . json_encode($out['flash']));

$out = runAction('system_admin', 'SavingsAccountController', 'voluntaryStore', [], ['member_id' => '1', 'csrf_token' => 'x']);
ok(wasBlocked($out), 'system_admin cannot open a savings account', $out['raw'] . json_encode($out['flash']));

// Repayments
$out = runAction('system_admin', 'RepaymentController', 'add', [], ['loan_id' => '1', 'amount' => '10000', 'csrf_token' => 'x']);
ok(wasBlocked($out), 'system_admin cannot record a loan repayment', $out['raw'] . json_encode($out['flash']));

// Fees
$out = runAction('system_admin', 'FeeController', 'chargeStore', [], ['member_id' => '1', 'amount' => '10000', 'csrf_token' => 'x']);
ok(wasBlocked($out), 'system_admin cannot record a fee charge', $out['raw'] . json_encode($out['flash']));

// Withdrawals
$out = runAction('system_admin', 'WithdrawalController', 'process', [], ['member_id' => '1', 'amount' => '10000', 'csrf_token' => 'x']);
ok(wasBlocked($out), 'system_admin cannot process a withdrawal', $out['raw'] . json_encode($out['flash']));

$out = runAction('system_admin', 'WithdrawalController', 'delete', ['id' => '1']);
ok(wasBlocked($out), 'system_admin cannot reverse a withdrawal', $out['raw'] . json_encode($out['flash']));

// Loans
$out = runAction('system_admin', 'LoanController', 'add', [], ['member_id' => '1', 'loan_amount' => '100000', 'csrf_token' => 'x']);
ok(wasBlocked($out), 'system_admin cannot create a loan', $out['raw'] . json_encode($out['flash']));

$out = runAction('system_admin', 'LoanController', 'approve', [], ['loan_id' => '1', 'csrf_token' => 'x']);
ok(wasBlocked($out), 'system_admin cannot approve a loan', $out['raw'] . json_encode($out['flash']));

$out = runAction('system_admin', 'LoanController', 'disburse', [], ['loan_id' => '1', 'csrf_token' => 'x']);
ok(wasBlocked($out), 'system_admin cannot disburse a loan', $out['raw'] . json_encode($out['flash']));

// Expenses / Other Income
$out = runAction('system_admin', 'ExpenseController', 'store', [], ['category_id' => '1', 'amount' => '10000', 'csrf_token' => 'x']);
ok(wasBlocked($out), 'system_admin cannot record an expense', $out['raw'] . json_encode($out['flash']));

$out = runAction('system_admin', 'OtherIncomeController', 'store', [], ['amount' => '10000', 'csrf_token' => 'x']);
ok(wasBlocked($out), 'system_admin cannot record other income', $out['raw'] . json_encode($out['flash']));

// Vouchers
$out = runAction('system_admin', 'InternalVoucherController', 'store', [], ['primary_account_id' => '1', 'contra_account_id' => '2', 'amount' => '10000', 'csrf_token' => 'x']);
ok(wasBlocked($out), 'system_admin cannot create/post an internal voucher', $out['raw'] . json_encode($out['flash']));

// Opening balances / adjustments
$out = runAction('system_admin', 'OpeningBalanceController', 'store', [], ['csrf_token' => 'x']);
ok(wasBlocked($out), 'system_admin cannot create an opening balance', $out['raw'] . json_encode($out['flash']));

$out = runAction('system_admin', 'MemberAccountAdjustmentController', 'store', [], ['member_id' => '1', 'savings_account_id' => '1', 'amount' => '10000', 'csrf_token' => 'x']);
ok(wasBlocked($out), 'system_admin cannot make a member account adjustment', $out['raw'] . json_encode($out['flash']));

// NOTE: General/Financial Year/Loan/Withdrawal SETTINGS used to be tested
// here as "system_admin cannot access" -- SA-1 (2026-09, "Full System
// Settings & Administrative Control") deliberately reverses that: System
// Administrator now owns full CONFIGURATION authority over these areas
// (they are policy/settings, not transaction posting). Those assertions
// moved to SECTION G below, now asserting the opposite (allowed).

echo "\n=== SECTION D: System Administrator has no read access to accounting/financial reports (per the frozen contract) ===\n";
// AccountingReportController and ReportController are gated at the
// CONSTRUCTOR (no per-action check) -- system_admin is not in either
// controller's allowed-role list, so even instantiating it should reject.
$out = runAction('system_admin', 'AccountingReportController', 'trialBalance');
ok(wasBlocked($out), 'system_admin cannot access Trial Balance', $out['raw'] . json_encode($out['flash']));

$out = runAction('system_admin', 'AccountingReportController', 'generalLedger');
ok(wasBlocked($out), 'system_admin cannot access General Ledger', $out['raw'] . json_encode($out['flash']));

$out = runAction('system_admin', 'AccountingReportController', 'balanceSheet');
ok(wasBlocked($out), 'system_admin cannot access Balance Sheet', $out['raw'] . json_encode($out['flash']));

$out = runAction('system_admin', 'ReportController', 'financial');
ok(wasBlocked($out), 'system_admin cannot access the Financial report', $out['raw'] . json_encode($out['flash']));

echo "\n=== SECTION E: Direct URL / unauthorized POST cannot bypass (role/session manipulation) ===\n";
// Same actions as Section C, but simulating a forged/direct request with
// no CSRF at all and a manipulated session -- proves the role gate, not
// the CSRF gate, is what's actually doing the rejecting (the role check
// runs before CSRF verification in every one of these controllers).
$out = runAction('system_admin', 'LoanController', 'disburse', ['page' => 'loan-disburse'], []);
ok(wasBlocked($out), 'Direct POST with no CSRF token at all is still rejected by the role gate first', $out['raw'] . json_encode($out['flash']));

$out = runAction('system_admin', 'SavingsAccountController', 'depositStore', ['page' => 'savings-account-deposit'], []);
ok(wasBlocked($out), 'Direct URL manipulation to the deposit endpoint cannot bypass the role gate', $out['raw'] . json_encode($out['flash']));

echo "\n=== SECTION F: Regression -- other roles' financial access is unaffected ===\n";
$out = runAction('cashier', 'SavingsAccountController', 'depositForm', ['id' => '1']);
ok(!str_contains($out['raw'], 'Access denied'), 'Cashier is still not blocked from the deposit form (unaffected by this stage)', $out['raw']);

$out = runAction('treasurer', 'ExpenseController', 'create');
ok(!str_contains($out['raw'], 'Access denied') && !str_contains($out['raw'], 'Fatal error'), 'Treasurer is still not blocked from recording expenses (unaffected)', $out['raw']);

$out = runAction('admin', 'SettingsController', 'general');
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'Admin still retains access to General Settings (untouched, per explicit instruction)', $out['raw']);

echo "\n=== SECTION G: System Administrator CAN now configure settings & financial-POLICY areas (SA-1, 2026-09) ===\n";
// Configuration authority, not transaction authority -- these gates were
// widened from admin-only to admin+system_admin via requireSettingsAccess()
// (SettingsController) / requireWriteAccess() (FinancialYearController,
// AccountingPeriodController) / requireAdmin() (ChartOfAccountsController,
// WithdrawalPolicyController, FeeController's TYPE-management gate only).

// -- Viewing each newly-granted settings/policy page --
$out = runAction('system_admin', 'SettingsController', 'general');
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'system_admin CAN view General Settings (Club Information / System Configuration)', $out['raw']);

$out = runAction('system_admin', 'SettingsController', 'loanSettings');
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'system_admin CAN view Loan policy settings', $out['raw']);

$out = runAction('system_admin', 'SettingsController', 'withdrawalSettings');
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'system_admin CAN view Withdrawal & Share policy settings', $out['raw']);

$out = runAction('system_admin', 'SettingsController', 'receiptSettings');
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'system_admin CAN view Receipt settings', $out['raw']);

$out = runAction('system_admin', 'FinancialYearController', 'index');
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'system_admin CAN view Financial Year configuration', $out['raw']);

$out = runAction('system_admin', 'ChartOfAccountsController', 'index');
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'system_admin CAN view Chart of Accounts', $out['raw']);

$out = runAction('system_admin', 'AccountingPeriodController', 'index');
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'system_admin CAN view Accounting Periods', $out['raw']);

$out = runAction('system_admin', 'WithdrawalPolicyController', 'index');
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'system_admin CAN view Withdrawal Policies', $out['raw']);

$out = runAction('system_admin', 'FeeController', 'index');
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'system_admin CAN view Fees & Charges configuration', $out['raw']);

// -- Role gate on each save/write action is not what rejects (CSRF-token
// mismatch is the only expected rejection in a fresh subprocess session,
// exactly like the existing userToggle() precedent above) --
function notRoleBlocked(array $out): bool {
    $err = $out['flash']['error'] ?? '';
    return !str_contains($err, 'Access denied') && !str_contains($err, 'privileges required')
        && !str_contains($out['raw'], 'Access denied') && !str_contains($out['raw'], 'privileges required')
        && !str_contains($out['raw'], 'Fatal error');
}

$out = runAction('system_admin', 'SettingsController', 'generalSave', [], ['club_name' => 'x', 'csrf_token' => 'x']);
ok(notRoleBlocked($out), 'system_admin is not role-blocked from saving Organization Information', $out['raw'] . json_encode($out['flash']));

$out = runAction('system_admin', 'SettingsController', 'systemConfigSave', [], ['currency' => 'UGX', 'csrf_token' => 'x']);
ok(notRoleBlocked($out), 'system_admin is not role-blocked from saving System Configuration', $out['raw'] . json_encode($out['flash']));

$out = runAction('system_admin', 'SettingsController', 'loanSettingsSave', [], ['loan_threshold' => '1000000', 'csrf_token' => 'x']);
ok(notRoleBlocked($out), 'system_admin is not role-blocked from saving Loan settings', $out['raw'] . json_encode($out['flash']));

$out = runAction('system_admin', 'SettingsController', 'withdrawalSettingsSave', [], ['withdrawal_pct' => '50', 'csrf_token' => 'x']);
ok(notRoleBlocked($out), 'system_admin is not role-blocked from saving Withdrawal settings', $out['raw'] . json_encode($out['flash']));

$out = runAction('system_admin', 'SettingsController', 'receiptSettingsSave', [], ['receipt_prefix' => 'RCT', 'csrf_token' => 'x']);
ok(notRoleBlocked($out), 'system_admin is not role-blocked from saving Receipt settings', $out['raw'] . json_encode($out['flash']));

$out = runAction('system_admin', 'FeeController', 'save', [], ['name' => 'Test Fee', 'amount' => '5000', 'csrf_token' => 'x']);
ok(notRoleBlocked($out), 'system_admin is not role-blocked from creating/editing a fee TYPE (configuration, not collection)', $out['raw'] . json_encode($out['flash']));

$out = runAction('system_admin', 'ChartOfAccountsController', 'store', [], ['code' => '9995', 'name' => 'SA1 Suite Probe', 'type' => 'asset', 'normal_balance' => 'debit', 'subtype' => 'other', 'csrf_token' => 'x']);
ok(notRoleBlocked($out), 'system_admin is not role-blocked from creating a GL account', $out['raw'] . json_encode($out['flash']));
$pdo->prepare("DELETE FROM accounts WHERE code = '9995'")->execute();

$out = runAction('system_admin', 'AccountingPeriodController', 'store', [], ['csrf_token' => 'x']);
ok(notRoleBlocked($out), 'system_admin is not role-blocked from the Accounting Period create action', $out['raw'] . json_encode($out['flash']));

$out = runAction('system_admin', 'WithdrawalPolicyController', 'toggle', [], ['id' => '999999', 'csrf_token' => 'x']);
ok(notRoleBlocked($out), 'system_admin is not role-blocked from toggling a withdrawal policy', $out['raw'] . json_encode($out['flash']));

// -- Fee COLLECTION (transaction) stays denied even though fee TYPE
//    management is now allowed -- proves the two gates stayed separate --
$out = runAction('system_admin', 'FeeController', 'chargeStore', [], ['member_id' => '1', 'amount' => '10000', 'csrf_token' => 'x']);
ok(wasBlocked($out), 'system_admin still cannot collect/charge a fee (transaction, unaffected by SA-1)', $out['raw'] . json_encode($out['flash']));

echo "\n=== SECTION H: Regression -- pre-existing settings/policy access for other roles is unaffected by SA-1 ===\n";
$out = runAction('admin', 'SettingsController', 'loanSettings');
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'Admin still retains access to Loan settings', $out['raw']);

$out = runAction('treasurer', 'FinancialYearController', 'index');
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'Treasurer still retains access to Financial Year (pre-existing, unaffected)', $out['raw']);

$out = runAction('treasurer', 'ChartOfAccountsController', 'index');
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'Treasurer still retains access to Chart of Accounts (pre-existing, unaffected)', $out['raw']);

$out = runAction('treasurer', 'AccountingPeriodController', 'index');
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'Treasurer still retains access to Accounting Periods (pre-existing, unaffected)', $out['raw']);

$out = runAction('treasurer', 'WithdrawalPolicyController', 'index');
ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), 'Treasurer still retains access to Withdrawal Policies (pre-existing, unaffected)', $out['raw']);

$out = runAction('treasurer', 'SettingsController', 'loanSettings');
ok(wasBlocked($out), 'Treasurer still correctly denied Loan settings (never had this -- SA-1 only added system_admin)', $out['raw']);

$out = runAction('treasurer', 'FeeController', 'save', [], ['name' => 'x', 'amount' => '1', 'csrf_token' => 'x']);
ok(wasBlocked($out), 'Treasurer still correctly denied fee TYPE management (never had this -- SA-1 only added system_admin)', $out['raw']);

$out = runAction('office_admin', 'SettingsController', 'loanSettings');
ok(wasBlocked($out), 'office_admin still correctly denied Loan settings (unauthorized role, unaffected)', $out['raw']);

$out = runAction('office_admin', 'ChartOfAccountsController', 'index');
ok(wasBlocked($out), 'office_admin still correctly denied Chart of Accounts (unauthorized role, unaffected)', $out['raw']);

$out = runAction('office_admin', 'AccountingPeriodController', 'index');
ok(wasBlocked($out), 'office_admin still correctly denied Accounting Periods (unauthorized role, unaffected)', $out['raw']);

$out = runAction('office_admin', 'WithdrawalPolicyController', 'index');
ok(wasBlocked($out), 'office_admin still correctly denied Withdrawal Policies (unauthorized role, unaffected)', $out['raw']);

$out = runAction('office_admin', 'FeeController', 'save', [], ['name' => 'x', 'amount' => '1', 'csrf_token' => 'x']);
ok(wasBlocked($out), 'office_admin still correctly denied fee TYPE management (unauthorized role, unaffected)', $out['raw']);

echo "\n=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
