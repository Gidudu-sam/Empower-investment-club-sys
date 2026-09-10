<?php
/**
 * ISOLATED — Six-role model verification. Runs ONLY against
 * empower_db_ivms_test (roles_expansion_six_role_model.sql already
 * applied). Never touches empower_db.
 *
 * Two layers of proof:
 *  1. Direct Session::hasRole() checks against the exact role arrays
 *     used in each edited gate (fast, precise, covers all touched files).
 *  2. Full controller invocation for a handful of representative cases,
 *     to prove the gate is actually wired to a real request path, not
 *     just a semantically-correct array sitting unused.
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
$pass = 0; $fail = 0;
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }

echo "=== SECTION 0: Roles exist ===\n";
$roleNames = array_column($db->query("SELECT name FROM roles")->fetchAll(), 'name');
foreach (['chairman', 'loans_officer', 'office_admin', 'system_admin'] as $r) {
    ok(in_array($r, $roleNames, true), "role '$r' exists in roles table");
}
foreach (['admin', 'treasurer', 'cashier', 'member', 'viewer'] as $r) {
    ok(in_array($r, $roleNames, true), "pre-existing role '$r' still exists (unchanged)");
}

Session::start();
function setRole(string $role, int $userId = 1): void {
    Session::set('user_id', $userId);
    Session::set('user_role', $role);
    Session::set('user_name', 'Test ' . $role);
    Session::set('last_activity', time());
}

echo "\n=== SECTION 1: Chairman -- view-tier gates now include chairman ===\n";
// The exact role arrays as edited in each file.
$viewGates = [
    'AccountingPeriodController'        => ['admin','treasurer','viewer','chairman'],
    'AccountingReportController'        => ['admin','treasurer','viewer','chairman'],
    'ChartOfAccountsController'         => ['admin','treasurer','viewer','chairman'],
    'ExpenseController'                 => ['admin','treasurer','viewer','chairman'],
    'InternalVoucherController'         => ['admin','treasurer','viewer','chairman'],
    'FinancialYearController'           => ['admin','treasurer','viewer','chairman'],
    'InvestmentController'              => ['admin','treasurer','viewer','chairman'],
    'MemberAccountAdjustmentController' => ['admin','treasurer','viewer','chairman'],
    'OtherIncomeController'             => ['admin','treasurer','viewer','chairman'],
    'ReferralBonusController'           => ['admin','treasurer','cashier','viewer','chairman'],
    'SavingsAccountController'          => ['admin','treasurer','cashier','viewer','chairman','office_admin'],
    'ReportController'                  => ['admin','treasurer','cashier','viewer','chairman'],
    'StatementController'               => ['admin','treasurer','cashier','viewer','chairman','office_admin'],
    'WeeklyReportController'            => ['admin','treasurer','cashier','viewer','chairman'],
    'WithdrawalPolicyController'        => ['admin','treasurer','viewer','chairman'],
];
setRole('chairman');
foreach ($viewGates as $name => $allowed) {
    ok(Session::hasRole($allowed), "chairman passes $name's view gate");
}
ok(!Session::hasRole(['admin','treasurer']), 'sanity: chairman NOT in a plain admin/treasurer array (control case)');

echo "\n=== SECTION 2: Chairman -- approval-tier gates ===\n";
$approveGates = [
    'AccountingPeriodController.requireWriteAccess'         => ['admin','chairman'],
    'ChartOfAccountsController.requireAdmin'                => ['admin','chairman'],
    'InternalVoucherController.requireApproverAccess'       => ['admin','chairman'],
    'FinancialYearController.requireWriteAccess'            => ['admin','chairman'],
    'InvestmentController.requireApproverAccess'            => ['admin','chairman'],
    'MemberAccountAdjustmentController.requireApproverAccess' => ['admin','chairman'],
    'OpeningBalanceController.approve/reject'               => ['admin','chairman'],
    'WithdrawalPolicyController.requireAdmin'                => ['admin','chairman'],
    'WithdrawalController.requireWriteAccess (reversal)'     => ['admin','treasurer','chairman'],
];
foreach ($approveGates as $name => $allowed) {
    ok(Session::hasRole($allowed), "chairman passes $name");
}

echo "\n=== SECTION 3: Chairman -- explicitly still BLOCKED from routine write actions ===\n";
setRole('chairman');
$blockedGates = [
    'InternalVoucherController.requireWriteAccess (create voucher)' => ['admin','treasurer'],
    'MemberAccountAdjustmentController.requireWriteAccess (create adjustment)' => ['admin','treasurer'],
    'SavingsAccountController.requireTransactAccess (deposit/withdraw)' => ['admin','treasurer','cashier'],
    'ExpenseController.requireWriteAccess (create expense)' => ['admin','treasurer'],
];
foreach ($blockedGates as $name => $allowed) {
    ok(!Session::hasRole($allowed), "chairman correctly BLOCKED from $name");
}

echo "\n=== SECTION 4: Loans Officer ===\n";
setRole('loans_officer');
ok(Session::hasRole(['admin','treasurer','loans_officer']), 'loans_officer passes LoanController.requireWriteAccess');
ok(Session::hasRole(['admin','treasurer','cashier','loans_officer']), 'loans_officer passes RepaymentController.add access');
ok(!Session::hasRole(['admin','treasurer']), 'sanity control: loans_officer not in plain admin/treasurer array');

echo "\n=== SECTION 5: Office Administrator ===\n";
setRole('office_admin');
ok(Session::hasRole(['admin','office_admin']), 'office_admin passes MemberController.requireAddAccess (register) -- role-policy stage: now admin/office_admin only');
ok(Session::hasRole(['admin','treasurer','office_admin']), 'office_admin passes MemberController.requireEditAccess');
ok(Session::hasRole(['admin','treasurer','office_admin']), 'office_admin passes SavingsAccountController.requireWriteAccess (open account)');
ok(!Session::hasRole(['admin','treasurer','cashier']), 'office_admin correctly BLOCKED from SavingsAccountController.requireTransactAccess (deposit/withdraw)');
ok(Session::hasRole(['admin','treasurer','cashier','office_admin']), 'office_admin passes FeeController.requireCollectAccess');
ok(Session::hasRole(['admin','treasurer','cashier','viewer','chairman','office_admin']), 'office_admin passes StatementController view gate');

echo "\n=== SECTION 6: System Administrator ===\n";
setRole('system_admin');
ok(Session::hasRole(['admin','system_admin']), 'system_admin passes SettingsController.requireSystemAdminOrAdmin (users/database/audit)');
ok(Session::get('user_role') !== 'admin', 'sanity: system_admin is not literally admin (requireAdmin() uses a strict string check)');

echo "\n=== SECTION 7: Regression -- existing roles unaffected ===\n";
foreach (['admin','treasurer'] as $role) {
    setRole($role);
    foreach ($viewGates as $name => $allowed) {
        ok(Session::hasRole($allowed), "$role still passes $name's view gate (unchanged)");
    }
}
setRole('viewer');
ok(Session::hasRole(['admin','treasurer','viewer','chairman']), 'viewer still passes a view-tier gate (unchanged)');
ok(!Session::hasRole(['admin','treasurer']), 'viewer still correctly blocked from a write-tier gate (unchanged)');
setRole('cashier');
// Role-policy stage: member registration was explicitly REVOKED from
// cashier ("Cashier should NOT: Register members") -- this hardcoded array
// must reflect that reversal, not the old, now-incorrect grant.
ok(!Session::hasRole(['admin','office_admin']), 'cashier NO LONGER passes MemberController.requireAddAccess (explicit policy reversal)');
ok(Session::hasRole(['admin','treasurer','cashier']), 'cashier still passes SavingsAccountController.requireTransactAccess (unchanged)');
setRole('member');
ok(!Session::hasRole(['admin','treasurer','viewer','chairman']), 'member role still correctly blocked from a view-tier gate (unchanged)');

echo "\n=== SECTION 8: End-to-end controller invocation (real request path proof) ===\n";

// 8a. Chairman can reach InternalVoucherController's view (constructor gate).
setRole('chairman');
ob_start();
$threw = false;
try { new InternalVoucherController(); } catch (Throwable $e) { $threw = true; }
$out = ob_get_clean();
ok(!$threw && !str_contains($out, 'Access denied'), 'Chairman can instantiate InternalVoucherController (constructor gate passes)');

// 8b. System Administrator can reach SettingsController::users(), but NOT
// SettingsController::general() (financial policy stays admin-only).
setRole('system_admin');
ob_start();
try { (new SettingsController())->users(); } catch (Throwable $e) { echo "EXCEPTION: " . $e->getMessage(); }
$outUsers = ob_get_clean();
ok(!str_contains($outUsers, 'Access denied') && str_contains($outUsers, 'User Management'), 'System Administrator can reach SettingsController::users()');

// Controller::redirect() calls exit internally, which would kill this whole
// test process if invoked inline for the denial path -- run it as a
// subprocess instead, matching the pattern used for the print/register
// render checks in the internal-voucher-member-subledger test suite.
$subprocess = __DIR__ . '/test_six_role_model_it_general_subprocess.php';
file_put_contents($subprocess, <<<'PHP'
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
Session::set('user_role', 'system_admin');
Session::set('last_activity', time());
(new SettingsController())->general();
PHP);
$generalOutput = shell_exec('"' . PHP_BINARY . '" "' . $subprocess . '" 2>&1');
unlink($subprocess);
ok(!str_contains($generalOutput ?? '', 'General Settings'), 'System Administrator is correctly BLOCKED from SettingsController::general() (financial policy stays admin-only)', $generalOutput ?? '');

// 8c. Office Administrator can reach MemberController's add-access gate (no die/redirect reached before a distinguishable point).
setRole('office_admin');
ob_start();
$reflect = new ReflectionClass('MemberController');
$method = $reflect->getMethod('requireAddAccess');
$method->setAccessible(true);
$threwAdd = false;
try { $method->invoke(new MemberController()); } catch (Throwable $e) { $threwAdd = true; }
$outAdd = ob_get_clean();
ok(!$threwAdd, 'Office Administrator passes MemberController::requireAddAccess() without being redirected away');

// 8d. Role-policy stage: cashier and treasurer must now be actually
// REDIRECTED by requireAddAccess() (real controller invocation, not just
// the hardcoded-array checks above) -- redirect() calls exit internally,
// so this runs as a subprocess per the same rule as 8b.
foreach (['cashier', 'treasurer'] as $blockedRole) {
    $subprocess = __DIR__ . "/test_six_role_model_add_access_{$blockedRole}_subprocess.php";
    file_put_contents($subprocess, <<<PHP
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
Session::set('user_role', '{$blockedRole}');
Session::set('last_activity', time());
\$reflect = new ReflectionClass('MemberController');
\$method = \$reflect->getMethod('requireAddAccess');
\$method->setAccessible(true);
\$method->invoke(new MemberController());
echo "RESULT:REACHED";
PHP);
    $outBlocked = shell_exec('"' . PHP_BINARY . '" "' . $subprocess . '" 2>&1');
    @unlink($subprocess);
    ok(!str_contains($outBlocked ?? '', 'RESULT:REACHED'), "{$blockedRole} is actually redirected by MemberController::requireAddAccess() (real invocation, not just the array check)", $outBlocked ?? '');
}

echo "\n=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
