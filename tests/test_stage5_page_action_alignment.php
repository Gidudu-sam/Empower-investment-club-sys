<?php
/**
 * ISOLATED — Stage 5 (Page-Level Action Alignment) verification.
 * Runs ONLY against empower_db_ivms_test. Never touches empower_db.
 * Renders the actual view pages via their real controllers (real DB
 * rows, real conditionals) and confirms each fixed action button
 * appears/disappears exactly per the backend gate it mirrors. Each
 * render happens in its own subprocess -- the shared 'main' layout
 * includes sidebar.php via a plain `include`, which declares top-level
 * functions that fatal on redeclare if rendered twice in one process.
 */
chdir(__DIR__);
$pass = 0; $fail = 0;
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }

function renderAs(string $role, string $page, array $get = []): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_stage5_verify_subproc_' . $counter . '.php';
    $getLines = "\$_GET['page'] = '{$page}';\n";
    foreach ($get as $k => $v) { $getLines .= "\$_GET['{$k}'] = " . var_export($v, true) . ";\n"; }
    // Route table lives in index.php but that file also handles dispatch
    // side effects; instead resolve controller/method the same way
    // index.php's $routes array does, inline, for the handful of pages
    // this test needs.
    $routeMap = [
        'members'         => ['MemberController', 'index'],
        'member-view'     => ['MemberController', 'view'],
        'loans'           => ['LoanController', 'index'],
        'loan-view'       => ['LoanController', 'view'],
        'savings'         => ['SavingsController', 'index'],
        'savings-view'    => ['SavingsController', 'view'],
        'withdrawals'     => ['WithdrawalController', 'index'],
        'withdrawal-view' => ['WithdrawalController', 'view'],
        'fee-charges'     => ['FeeController', 'charges'],
    ];
    [$class, $method] = $routeMap[$page];
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
{$getLines}
try {
    (new {$class}())->{$method}();
} catch (Throwable \$e) {
    echo "EXCEPTION: " . \$e->getMessage() . "\\n" . \$e->getTraceAsString();
}
PHP;
    file_put_contents($file, $body);
    $out = shell_exec('"' . PHP_BINARY . '" "' . $file . '" 2>&1');
    @unlink($file);
    return $out ?? '';
}

echo "=== SECTION 1: Members — Edit/Deactivate/Delete visibility ===\n";
$out = renderAs('cashier', 'member-view', ['id' => 2]);
ok(!str_contains($out, 'Fatal error'), 'members/view.php renders for cashier with no fatal error', substr($out,0,200));
ok(!str_contains($out, 'Edit Profile'), 'cashier does NOT see Edit Profile on member view');
ok(!str_contains($out, 'Delete Member'), 'cashier does NOT see Delete Member');

$out = renderAs('office_admin', 'member-view', ['id' => 2]);
ok(str_contains($out, 'Edit Profile'), 'office_admin SEES Edit Profile on member view');
ok(!str_contains($out, 'Delete Member'), 'office_admin does NOT see Delete Member (admin-only)');

$out = renderAs('admin', 'member-view', ['id' => 2]);
ok(str_contains($out, 'Edit Profile') && str_contains($out, 'Delete Member'), 'admin sees both Edit Profile and Delete Member');

echo "\n=== SECTION 2: Members index — Add/Edit/Delete visibility ===\n";
$out = renderAs('loans_officer', 'members');
ok(!str_contains($out, 'Add Member'), 'loans_officer does NOT see Add Member on members list');
// Role-policy stage: member registration narrowed to admin/office_admin only
// (cashier explicitly reversed -- "Cashier should NOT: Register members").
$out = renderAs('cashier', 'members');
ok(!str_contains($out, 'Add Member'), 'cashier no longer sees Add Member on members list (role-policy correction)');

echo "\n=== SECTION 3: Loans — Edit/Delete visibility ===\n";
$out = renderAs('cashier', 'loan-view', ['id' => 14]);
ok(!str_contains($out, 'Fatal error'), 'loans/view.php renders for cashier with no fatal error', substr($out,0,200));
ok(!str_contains($out, 'Edit Loan'), 'cashier does NOT see Edit Loan');
$out = renderAs('loans_officer', 'loan-view', ['id' => 14]);
ok(str_contains($out, 'Edit Loan'), 'loans_officer SEES Edit Loan');
$out = renderAs('treasurer', 'loan-view', ['id' => 14]);
ok(str_contains($out, 'Edit Loan'), 'treasurer SEES Edit Loan');
ok(!preg_match('/id="openDeleteBtn"[^>]*>\s*<i class="bi bi-trash me-1"><\/i>Delete\s*</', $out), 'treasurer does NOT see the loan Delete button (admin-only)');
$out = renderAs('admin', 'loan-view', ['id' => 14]);
ok(str_contains($out, 'id="openDeleteBtn"'), 'admin SEES the loan Delete button');

echo "\n=== SECTION 4: Savings — Edit/Delete visibility ===\n";
$out = renderAs('cashier', 'savings-view', ['id' => 1]);
ok(!str_contains($out, 'Fatal error'), 'savings/view.php renders for cashier with no fatal error', substr($out,0,200));
ok(!str_contains($out, 'Danger Zone'), 'cashier does NOT see the savings Danger Zone (delete) card');
$out = renderAs('treasurer', 'savings-view', ['id' => 1]);
ok(str_contains($out, '>Edit<') || str_contains($out, 'bi-pencil me-1'), 'treasurer SEES the savings Edit action');
ok(!str_contains($out, 'Danger Zone'), 'treasurer does NOT see Danger Zone either (admin-only delete)');
$out = renderAs('admin', 'savings-view', ['id' => 1]);
ok(str_contains($out, 'Danger Zone'), 'admin SEES the savings Danger Zone (delete)');

echo "\n=== SECTION 5: Withdrawals — Process visibility + Reverse fix ===\n";
$out = renderAs('office_admin', 'withdrawals');
ok(!str_contains($out, 'Process Withdrawal'), 'office_admin does NOT see Process Withdrawal');
$out = renderAs('cashier', 'withdrawals');
ok(str_contains($out, 'Process Withdrawal'), 'cashier SEES Process Withdrawal');

// withdrawals is empty in both prod and this clone (feature not yet used
// in real life), so withdrawal-view has no row to render against. Seed
// one raw fixture row directly (no journal_entry_id -- no accounting
// effect) purely so the view page has something to render; this test
// only checks button visibility, not withdrawal business logic.
define('DB_NAME', 'empower_db_ivms_test');
require 'app/config/config.php';
require 'test_safety_guard.php';
require CORE_PATH . '/Database.php';
$db = Database::getInstance()->getConnection();
$db->exec("INSERT INTO withdrawals
    (withdrawal_number, member_id, savings_account_id, financial_year, withdrawal_type,
     total_available_savings, withdrawal_percentage, retained_percentage, withdrawal_amount, retained_amount,
     withdrawal_date, payment_method, processed_by)
    VALUES
    ('STAGE5-FIXTURE-1', 2, NULL, YEAR(CURDATE()), 'voluntary',
     100000, 100, 0, 50000, 50000,
     CURDATE(), 'Cash', 1)");
$withdrawalFixtureId = (int)$db->lastInsertId();

// The confirmed Yellow fix: chairman now sees Reverse (backend already allowed it).
$out = renderAs('chairman', 'withdrawal-view', ['id' => $withdrawalFixtureId]);
ok(!str_contains($out, 'Fatal error'), 'withdrawals/view.php renders for chairman with no fatal error', substr($out,0,300));
ok(str_contains($out, 'bi-arrow-counterclockwise'), 'chairman NOW sees Reverse on a withdrawal (Stage 5 fix — backend already allowed this)');
$out = renderAs('cashier', 'withdrawal-view', ['id' => $withdrawalFixtureId]);
ok(!str_contains($out, 'bi-arrow-counterclockwise'), 'cashier still does NOT see Reverse (unchanged, correct)');

echo "\n=== SECTION 6: Fees — Collect/Waive visibility ===\n";
$out = renderAs('cashier', 'fee-charges');
ok(!str_contains($out, 'Fatal error'), 'fees/charges.php renders for cashier with no fatal error', substr($out,0,200));
ok(str_contains($out, 'Mark Paid'), 'cashier SEES Mark Paid (collect access)');
ok(!str_contains($out, 'title="Waive"'), 'cashier does NOT see Waive');
$out = renderAs('treasurer', 'fee-charges');
ok(str_contains($out, 'title="Waive"'), 'treasurer SEES Waive');
$out = renderAs('office_admin', 'fee-charges');
ok(str_contains($out, 'Mark Paid') && !str_contains($out, 'title="Waive"'), 'office_admin sees Mark Paid but not Waive');

echo "\n=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
