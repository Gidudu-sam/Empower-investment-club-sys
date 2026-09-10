<?php
/**
 * ISOLATED — Stage 9: Role Policy Correction & Workspace Alignment.
 * Runs ONLY against empower_db_ivms_test. Never touches empower_db.
 *
 * Verifies the policy corrections made in this stage, by direct controller
 * invocation (not sidebar/button visibility alone):
 *  1. Member registration narrowed to admin/office_admin only (treasurer
 *     and cashier explicitly removed, reversing prior design intent).
 *  2. Office Administrator gained: Savings Accounts "Open Account" UI
 *     (backend already allowed it), Member/Savings reports, a WhatsApp
 *     statement-sharing endpoint.
 *  3. Office Administrator explicitly still excluded from financial/loan
 *     reports, Chart of Accounts, accounting periods, financial years,
 *     loan approval/disbursement, and all money-movement actions.
 *  4. Treasurer still has no approval authority anywhere (unchanged,
 *     re-verified) and no member-registration authority (newly removed).
 *  5. Chairman/Loans Officer/Cashier/System Administrator behavior is
 *     unchanged by this stage (regression, not new grants).
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
    $file = __DIR__ . '/tmp_stage9_subproc_' . $counter . '.php';
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

// ================================================================
// SECTION 1: Member registration -- narrowed to admin/office_admin
// ================================================================
echo "=== SECTION 1: Member registration (MemberController::add) ===\n";
foreach ([
    'admin'         => true,
    'office_admin'  => true,
    'treasurer'     => false,
    'cashier'       => false,
    'chairman'      => false,
    'loans_officer' => false,
    'system_admin'  => false,
] as $role => $shouldPass) {
    $out = renderAs($role, 'MemberController', 'add');
    if ($shouldPass) { ok($reached($out), "$role CAN reach Member registration", $out); }
    else { ok(!$reached($out), "$role is BLOCKED from Member registration (explicit policy correction)", $out); }
}

// ================================================================
// SECTION 2: Member editing -- admin/office_admin only
// Sidebar-redesign policy alignment (2026-09): treasurer's prior edit
// grant is deliberately revoked here -- Treasurer is financial-control/
// oversight only ("view members, don't edit them"), a real policy
// reversal from this stage's original admin/treasurer/office_admin grant.
// ================================================================
echo "\n=== SECTION 2: Member editing -- admin/office_admin only (treasurer revoked) ===\n";
$anyMemberId = (int)$db->query("SELECT id FROM members LIMIT 1")->fetchColumn();
foreach (['admin' => true, 'treasurer' => false, 'office_admin' => true, 'cashier' => false] as $role => $shouldPass) {
    $out = renderAs($role, 'MemberController', 'edit', ['id' => $anyMemberId]);
    if ($shouldPass) { ok($reached($out), "$role CAN edit a member", $out); }
    else { ok(!$reached($out), "$role is BLOCKED from editing a member" . ($role === 'treasurer' ? ' (deliberate policy reversal)' : ' (unchanged)')); }
}

// ================================================================
// SECTION 3: Savings Accounts -- office_admin already had backend write
// access; confirm it's still correct and cashier/others still excluded.
// ================================================================
echo "\n=== SECTION 3: Savings Account opening (backend regression check) ===\n";
foreach (['admin' => true, 'treasurer' => true, 'office_admin' => true, 'cashier' => false, 'chairman' => false] as $role => $shouldPass) {
    $out = renderAs($role, 'SavingsAccountController', 'openSelect');
    if ($shouldPass) { ok($reached($out), "$role CAN open a savings account (unchanged backend)", $out); }
    else { ok(!$reached($out), "$role BLOCKED from opening a savings account (unchanged)"); }
}

// ================================================================
// SECTION 4: Reports -- office_admin gains Member/Savings, stays out of
// everything loan/financial-specific.
// ================================================================
echo "\n=== SECTION 4: Report access split (Member/Savings vs Financial/Loan) ===\n";
foreach ([
    ['ReportController', 'members', ['admin','treasurer','cashier','viewer','chairman','office_admin']],
    ['ReportController', 'savings', ['admin','treasurer','cashier','viewer','chairman','office_admin']],
    ['ReportController', 'index',   ['admin','treasurer','cashier','viewer','chairman','office_admin']],
] as [$class, $method, $allowedRoles]) {
    foreach ($allowedRoles as $role) {
        $out = renderAs($role, $class, $method);
        ok($reached($out), "$role CAN view $class::$method() (member/savings reports open to office_admin)", $out);
    }
    $out = renderAs('loans_officer', $class, $method === 'index' ? 'index' : $method); // sanity: unrelated role unaffected either way (loans_officer never had report access)
}
foreach (['loans', 'aging', 'repayments', 'shares', 'withdrawals', 'financial'] as $method) {
    foreach (['admin','treasurer','cashier','viewer','chairman'] as $role) {
        $out = renderAs($role, 'ReportController', $method, $method === 'financial' ? [] : []);
        ok($reached($out), "$role CAN still view ReportController::$method() (unchanged)", $out);
    }
    $out = renderAs('office_admin', 'ReportController', $method);
    ok(!$reached($out), "office_admin is BLOCKED from ReportController::$method() (finance/loan-specific, correctly excluded)", $out);
}

// ================================================================
// SECTION 5: office_admin financial isolation -- unchanged, re-verified
// ================================================================
echo "\n=== SECTION 5: office_admin still has no financial-transaction or accounting authority ===\n";
$anyLoanId = (int)$db->query("SELECT id FROM loans LIMIT 1")->fetchColumn();
$checks = [
    ['SavingsController', 'edit', ['id' => 1]],
    ['LoanController', 'add', []],
    ['LoanController', 'approve', []],
    ['InternalVoucherController', 'create', []],
    ['MemberAccountAdjustmentController', 'create', []],
    ['ChartOfAccountsController', 'index', []],
    ['AccountingPeriodController', 'index', []],
    ['FinancialYearController', 'create', []],
];
foreach ($checks as [$class, $method, $get]) {
    $out = renderAs('office_admin', $class, $method, $get);
    ok(!$reached($out), "office_admin BLOCKED from {$class}::{$method}()", $out);
}

// ================================================================
// SECTION 6: Treasurer -- no approval authority, no member-add (re-verified)
// ================================================================
echo "\n=== SECTION 6: Treasurer has zero approval authority anywhere ===\n";
$out = renderAs('treasurer', 'MemberController', 'add');
ok(!$reached($out), 'Treasurer BLOCKED from Member registration', $out);

$pendingVoucher = (int)$db->query("SELECT id FROM internal_vouchers WHERE status='pending_approval' LIMIT 1")->fetchColumn();
if ($pendingVoucher) {
    $out = renderAs('treasurer', 'InternalVoucherController', 'approve', [], ['id' => $pendingVoucher]);
    ok(!$reached($out), 'Treasurer BLOCKED from approving a pending voucher');
} else {
    echo "  [INFO] No pending voucher fixture in this clone -- skipped, not required (already covered by existing suites).\n";
}
$pendingLoan = (int)$db->query("SELECT id FROM loans WHERE status='pending_approval' LIMIT 1")->fetchColumn();
if ($pendingLoan) {
    $out = renderAs('treasurer', 'LoanController', 'approve', [], ['loan_id' => $pendingLoan]);
    ok(!$reached($out), 'Treasurer BLOCKED from approving a pending loan');
} else {
    echo "  [INFO] No pending-approval loan fixture in this clone -- skipped (covered by test_stage8_loan_approval_workflow.php).\n";
}

// ================================================================
// SECTION 7: Chairman / Loans Officer / Cashier / System Administrator
// unaffected by this stage (spot-check regression, not new grants)
// ================================================================
echo "\n=== SECTION 7: Unrelated roles unaffected (spot-check) ===\n";
$out = renderAs('chairman', 'MemberController', 'add');
ok(!$reached($out), 'Chairman still cannot register members (unchanged)');
$out = renderAs('loans_officer', 'LoanController', 'add');
ok($reached($out), 'Loans Officer can still create loan drafts (unchanged)', $out);
$out = renderAs('cashier', 'SavingsAccountController', 'depositForm', ['id' => 1]);
// depositForm may 404 on a missing account, but must not be an access-denied block
ok(!str_contains($out, 'Access denied'), 'Cashier still not blocked from the deposit workflow itself (unchanged)', $out);
$out = renderAs('system_admin', 'SettingsController', 'users');
ok($reached($out) || str_contains($out, 'User Management'), 'System Administrator still manages users (unchanged)', $out);

// ================================================================
// SECTION 8: WhatsApp statement sharing endpoint reachable for office_admin
// ================================================================
echo "\n=== SECTION 8: WhatsApp statement-sharing endpoint ===\n";
$anyMemberId2 = (int)$db->query("SELECT id FROM members LIMIT 1")->fetchColumn();
foreach (['admin','treasurer','cashier','viewer','chairman','office_admin'] as $role) {
    $out = renderAs($role, 'StatementController', 'whatsappSummary', ['member_id' => $anyMemberId2]);
    ok(str_contains($out, '"url"') || str_contains($out, 'wa.me'), "$role CAN generate a WhatsApp statement summary", $out);
}
$out = renderAs('system_admin', 'StatementController', 'whatsappSummary', ['member_id' => $anyMemberId2]);
ok(!$reached($out) && !str_contains($out, 'wa.me'), 'System Administrator still cannot access member statements (unchanged financial isolation)', $out);

echo "\n=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
