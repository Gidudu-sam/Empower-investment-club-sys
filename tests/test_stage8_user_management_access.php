<?php
/**
 * ISOLATED — User Management Access & Role Assignment Alignment verification.
 * Runs ONLY against empower_db_ivms_test. Never touches empower_db.
 *
 * Confirms: System Administrator has full user-account authority (view/
 * create/edit/deactivate/reset password/change role) via a narrow,
 * dedicated gate -- separate from Database/Audit Logs. Office
 * Administrator was briefly granted this same authority, then explicitly
 * REVOKED (a direct policy reversal, not a bug) -- it is now blocked
 * exactly like Chairman, Treasurer, Cashier, and Loans Officer. Self-role
 * protection (Stage 6) is preserved for System Administrator. Financial
 * authority is verified NOT to have leaked to either role from user
 * management (system_admin never had it; office_admin's revocation here
 * doesn't touch it either).
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
require_once APP_PATH . '/models/SettingsModel.php';
$db = Database::getInstance()->getConnection();

function renderAs(string $role, int $userId, string $class, string $method, array $get = [], array $post = []): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_stage8um_subproc_' . $counter . '.php';
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
Session::set('user_id', {$userId});
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

// Fully self-contained fixture actors -- deliberately NOT assumed from
// whatever happens to already exist in this clone's production snapshot
// (production has no real system_admin user at all today, so relying on a
// specific pre-existing id would be fragile and clone-dependent). Each
// fixture's real role_id matches the role it's meant to test, so self-role
// comparisons are meaningful.
$settingsModel = new SettingsModel();
$systemAdminUserId = $settingsModel->createUser([
    'full_name' => 'Stage8UM SysAdmin Actor', 'email' => 'stage8um.actor.sysadmin@example.test',
    'phone' => '', 'role_id' => 10 /* system_admin */, 'password_hash' => password_hash('x', PASSWORD_BCRYPT), 'is_active' => 1,
]);
$officeAdminUserId = $settingsModel->createUser([
    'full_name' => 'Stage8UM OfficeAdmin Actor', 'email' => 'stage8um.actor.officeadmin@example.test',
    'phone' => '', 'role_id' => 9 /* office_admin */, 'password_hash' => password_hash('x', PASSWORD_BCRYPT), 'is_active' => 1,
]);
$chairmanUserId = $settingsModel->createUser([
    'full_name' => 'Stage8UM Chairman Actor', 'email' => 'stage8um.actor.chairman@example.test',
    'phone' => '', 'role_id' => 7 /* chairman */, 'password_hash' => password_hash('x', PASSWORD_BCRYPT), 'is_active' => 1,
]);
$treasurerUserId = $settingsModel->createUser([
    'full_name' => 'Stage8UM Treasurer Actor', 'email' => 'stage8um.actor.treasurer@example.test',
    'phone' => '', 'role_id' => 2 /* treasurer */, 'password_hash' => password_hash('x', PASSWORD_BCRYPT), 'is_active' => 1,
]);
ok($systemAdminUserId > 0 && $officeAdminUserId > 0 && $chairmanUserId > 0 && $treasurerUserId > 0, 'Fixture actor users created with correct real role_id');

// Fixtures: disposable target users to change the role of / deactivate,
// distinct from the actor accounts above so self-role logic isn't accidentally exercised.
$targetId = $settingsModel->createUser([
    'full_name' => 'Stage8UM Target One', 'email' => 'stage8um.target1@example.test',
    'phone' => '', 'role_id' => 4 /* cashier */, 'password_hash' => password_hash('x', PASSWORD_BCRYPT), 'is_active' => 1,
]);
$targetId2 = $settingsModel->createUser([
    'full_name' => 'Stage8UM Target Two', 'email' => 'stage8um.target2@example.test',
    'phone' => '', 'role_id' => 4 /* cashier */, 'password_hash' => password_hash('x', PASSWORD_BCRYPT), 'is_active' => 1,
]);
$targetId3 = $settingsModel->createUser([
    'full_name' => 'Stage8UM Target Three', 'email' => 'stage8um.target3@example.test',
    'phone' => '', 'role_id' => 4 /* cashier */, 'password_hash' => password_hash('x', PASSWORD_BCRYPT), 'is_active' => 1,
]);
ok($targetId > 0 && $targetId2 > 0 && $targetId3 > 0, 'Fixture target users created');

// ================================================================
// SECTION 1: Access matrix -- users() (view)
// ================================================================
echo "\n=== SECTION 1: users() view access ===\n";
foreach ([
    'system_admin'  => [$systemAdminUserId, true],
    'office_admin'  => [$officeAdminUserId, false], // access explicitly revoked
    'chairman'      => [$chairmanUserId, false],
    'treasurer'     => [$treasurerUserId, false],
    'cashier'       => [$targetId, false],
    'loans_officer' => [$targetId, false],
] as $role => [$uid, $shouldPass]) {
    $out = renderAs($role, $uid, 'SettingsController', 'users');
    $reached = str_contains($out, 'User Management') || str_contains($out, 'RESULT:REACHED');
    if ($shouldPass) { ok($reached, "$role CAN view Users", $out); }
    else { ok(!$reached, "$role is BLOCKED from viewing Users"); }
}

// ================================================================
// SECTION 2: Access matrix -- create user
// ================================================================
echo "\n=== SECTION 2: userSave() -- create a new user ===\n";
foreach ([
    'system_admin'  => [$systemAdminUserId, true],
    'office_admin'  => [$officeAdminUserId, false], // access explicitly revoked
    'chairman'      => [$chairmanUserId, false],
    'treasurer'     => [$treasurerUserId, false],
    'cashier'       => [$targetId, false],
    'loans_officer' => [$targetId, false],
] as $role => [$uid, $shouldPass]) {
    $email = 'stage8um.create.' . $role . '@example.test';
    renderAs($role, $uid, 'SettingsController', 'userSave', [], [
        'id' => '0', 'full_name' => "Created by $role", 'email' => $email, 'phone' => '', 'role_id' => '4', 'password' => 'testpass123',
    ]);
    $created = $db->prepare("SELECT id FROM users WHERE email = ?");
    $created->execute([$email]);
    $existsNow = (bool)$created->fetchColumn();
    if ($shouldPass) { ok($existsNow, "$role CAN create a new user"); }
    else { ok(!$existsNow, "$role is BLOCKED from creating a user"); }
}

// ================================================================
// SECTION 3: Access matrix -- change another user's role
// ================================================================
echo "\n=== SECTION 3: userSave() -- change ANOTHER user's role ===\n";
foreach ([
    'system_admin'  => [$systemAdminUserId, $targetId,  'stage8um.target1@example.test', 'Stage8UM Target One'],
] as $role => [$uid, $tid, $temail, $tname]) {
    $before = $db->prepare("SELECT role_id FROM users WHERE id=?"); $before->execute([$tid]);
    $beforeRole = (int)$before->fetchColumn();
    renderAs($role, $uid, 'SettingsController', 'userSave', [], [
        'id' => (string)$tid, 'full_name' => $tname, 'email' => $temail,
        'phone' => '', 'role_id' => '8' /* loans_officer */, 'password' => '',
    ]);
    $after = $db->prepare("SELECT role_id FROM users WHERE id=?"); $after->execute([$tid]);
    $afterRole = (int)$after->fetchColumn();
    ok($afterRole === 8 && $beforeRole !== 8, "$role CAN change another user's role (cashier -> loans_officer)");
}

foreach ([
    'office_admin'  => [$officeAdminUserId, $targetId3], // access explicitly revoked
    'chairman'      => [$chairmanUserId, $targetId2],
    'treasurer'     => [$treasurerUserId, $targetId2],
    'cashier'       => [$targetId2, $targetId2],
    'loans_officer' => [$targetId2, $targetId2],
] as $role => [$uid, $tid]) {
    $target = $db->prepare("SELECT full_name, email, role_id FROM users WHERE id=?"); $target->execute([$tid]);
    $targetRow = $target->fetch();
    renderAs($role, $uid, 'SettingsController', 'userSave', [], [
        'id' => (string)$tid, 'full_name' => $targetRow['full_name'], 'email' => $targetRow['email'],
        'phone' => '', 'role_id' => '8', 'password' => '',
    ]);
    $after = $db->prepare("SELECT role_id FROM users WHERE id=?"); $after->execute([$tid]);
    $afterRole = (int)$after->fetchColumn();
    ok($afterRole === (int)$targetRow['role_id'], "$role is BLOCKED from changing another user's role");
}

// ================================================================
// SECTION 4: Access matrix -- deactivate user
// ================================================================
echo "\n=== SECTION 4: userToggle() -- deactivate another user ===\n";
foreach ([
    'office_admin'  => [$officeAdminUserId, $targetId3], // access explicitly revoked -- own target, untouched so far
    'chairman'      => [$chairmanUserId, $targetId2],
    'treasurer'     => [$treasurerUserId, $targetId2],
    'cashier'       => [$targetId2, $targetId2],
    'loans_officer' => [$targetId2, $targetId2],
] as $role => [$uid, $tid]) {
    renderAs($role, $uid, 'SettingsController', 'userToggle', ['id' => $tid]);
    $stmt = $db->prepare("SELECT is_active FROM users WHERE id=?"); $stmt->execute([$tid]);
    ok((int)$stmt->fetchColumn() === 1, "$role is BLOCKED from deactivating a user (still active)");
}
renderAs('system_admin', $systemAdminUserId, 'SettingsController', 'userToggle', ['id' => $targetId2]);
$stmt = $db->prepare("SELECT is_active FROM users WHERE id=?"); $stmt->execute([$targetId2]);
ok((int)$stmt->fetchColumn() === 0, 'system_admin CAN deactivate another user');

// ================================================================
// SECTION 5: Self-role protection (Stage 6, preserved)
// ================================================================
echo "\n=== SECTION 5: Self-role protection preserved for System Administrator ===\n";
// office_admin dropped from this section -- its access to userSave() was
// entirely revoked (Section 2/3 already prove that), so there is no
// self-role case left to exercise for it here.
foreach ([
    'system_admin' => [$systemAdminUserId, 10, 'Stage8UM SysAdmin Actor', 'stage8um.actor.sysadmin@example.test'],
] as $role => [$uid, $ownRoleId, $ownName, $ownEmail]) {
    renderAs($role, $uid, 'SettingsController', 'userSave', [], [
        'id' => (string)$uid, 'full_name' => $ownName, 'email' => $ownEmail, 'phone' => '', 'role_id' => '1' /* attempt escalation to admin */, 'password' => '',
    ]);
    $stmt = $db->prepare("SELECT role_id FROM users WHERE id=?"); $stmt->execute([$uid]);
    ok((int)$stmt->fetchColumn() === $ownRoleId, "$role CANNOT change their own role (still role_id $ownRoleId)");

    $blockedLog = $db->prepare("SELECT COUNT(*) FROM activity_logs WHERE user_id=? AND action='self_role_change_blocked' ORDER BY id DESC LIMIT 1");
    $blockedLog->execute([$uid]);
    ok((int)$blockedLog->fetchColumn() > 0, "$role's self-role-change attempt is logged as self_role_change_blocked");

    // But normal profile fields (phone) CAN still be changed in the same request shape.
    renderAs($role, $uid, 'SettingsController', 'userSave', [], [
        'id' => (string)$uid, 'full_name' => $ownName, 'email' => $ownEmail, 'phone' => '0700000111', 'role_id' => (string)$ownRoleId, 'password' => '',
    ]);
    $stmt = $db->prepare("SELECT phone, role_id FROM users WHERE id=?"); $stmt->execute([$uid]);
    $row = $stmt->fetch();
    ok($row['phone'] === '0700000111' && (int)$row['role_id'] === $ownRoleId, "$role CAN edit their own profile info (phone) without touching their role");
}

// ================================================================
// SECTION 6: Role-change audit trail
// ================================================================
echo "\n=== SECTION 6: Role-change audit records old -> new role ===\n";
$log = $db->prepare("SELECT description FROM activity_logs WHERE action='user_role_changed' ORDER BY id DESC LIMIT 1");
$log->execute();
$desc = $log->fetchColumn();
ok($desc && str_contains($desc, 'Cashier') && str_contains($desc, 'Loans Officer'), 'user_role_changed log records old and new role labels', $desc ?: '(none)');

// ================================================================
// SECTION 7: role_id validation (Part 6 -- never trust a client role_id)
// ================================================================
echo "\n=== SECTION 7: Invalid role_id is rejected, not silently accepted ===\n";
$before = $db->prepare("SELECT role_id FROM users WHERE id=?"); $before->execute([$targetId]);
$beforeRole = (int)$before->fetchColumn();
renderAs('system_admin', $systemAdminUserId, 'SettingsController', 'userSave', [], [
    'id' => (string)$targetId, 'full_name' => 'Stage8UM Target One', 'email' => 'stage8um.target1@example.test',
    'phone' => '', 'role_id' => '9999', 'password' => '',
]);
$after = $db->prepare("SELECT role_id FROM users WHERE id=?"); $after->execute([$targetId]);
ok((int)$after->fetchColumn() === $beforeRole, 'A nonexistent role_id (9999) is rejected, role unchanged');

// ================================================================
// SECTION 8: Financial isolation -- system_admin and office_admin
// ================================================================
echo "\n=== SECTION 8: Financial isolation preserved for both user-management roles ===\n";
require_once APP_PATH . '/models/LoanModel.php';
foreach (['system_admin', 'office_admin'] as $role) {
    $uid = $role === 'system_admin' ? $systemAdminUserId : $officeAdminUserId;

    $out = renderAs($role, $uid, 'SavingsController', 'add');
    ok(!str_contains($out, 'RESULT:REACHED'), "$role BLOCKED from recording a savings deposit", $out);

    $out = renderAs($role, $uid, 'WithdrawalController', 'process');
    ok(!str_contains($out, 'RESULT:REACHED'), "$role BLOCKED from processing a withdrawal", $out);

    // Payment-recording policy alignment: office_admin was deliberately
    // granted loan-repayment recording (front-desk collection), so this
    // check is no longer uniform across both roles -- system_admin stays
    // blocked (no financial-transaction authority), office_admin is now
    // expected to REACH the form.
    $out = renderAs($role, $uid, 'RepaymentController', 'add');
    if ($role === 'office_admin') {
        ok(str_contains($out, 'RESULT:REACHED'), "$role CAN reach recording a loan repayment (deliberate grant)", $out);
    } else {
        ok(!str_contains($out, 'RESULT:REACHED'), "$role BLOCKED from recording a loan repayment", $out);
    }

    $out = renderAs($role, $uid, 'LoanController', 'add');
    ok(!str_contains($out, 'RESULT:REACHED'), "$role BLOCKED from creating a loan", $out);

    $anyLoanId = (int)$db->query("SELECT id FROM loans LIMIT 1")->fetchColumn();
    $out = renderAs($role, $uid, 'LoanController', 'approve', [], ['loan_id' => $anyLoanId]);
    ok(!str_contains($out, 'RESULT:REACHED'), "$role BLOCKED from approving a loan", $out);

    $out = renderAs($role, $uid, 'InternalVoucherController', 'create');
    ok(!str_contains($out, 'RESULT:REACHED'), "$role BLOCKED from creating an internal voucher", $out);

    $out = renderAs($role, $uid, 'MemberAccountAdjustmentController', 'create');
    ok(!str_contains($out, 'RESULT:REACHED'), "$role BLOCKED from creating a member account adjustment", $out);

    $out = renderAs($role, $uid, 'AccountingPeriodController', 'index');
    // AccountingPeriodController's own index() may allow view-only for some roles;
    // the write-gated action is what matters here.
    $out2 = renderAs($role, $uid, 'FinancialYearController', 'create');
    ok(!str_contains($out2, 'RESULT:REACHED'), "$role BLOCKED from creating a financial year", $out2);
}

echo "\n=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
