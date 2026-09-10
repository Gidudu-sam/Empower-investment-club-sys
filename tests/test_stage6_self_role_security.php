<?php
/**
 * ISOLATED — Stage 6 self-role-escalation security fix verification.
 * Runs ONLY against empower_db_ivms_test. Never touches empower_db.
 * Calls SettingsController::userSave() for real (in a subprocess, since
 * it calls redirect()->exit()), then inspects the isolated DB directly
 * to confirm the actual outcome — not just the HTTP response.
 */
chdir(__DIR__);
define('DB_NAME', 'empower_db_ivms_test');
require 'app/config/config.php';
require 'test_safety_guard.php';
require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';

$db = Database::getInstance()->getConnection();
$pass = 0; $fail = 0;
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }

function callUserSave(string $asRole, int $asUserId, array $post): string {
    $file = __DIR__ . '/tmp_stage6_subproc_' . uniqid() . '.php';
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
Session::set('user_id', {$asUserId});
Session::set('user_role', '{$asRole}');
Session::set('user_name', 'Test');
Session::set('last_activity', time());
Session::set('csrf_token', 'test-csrf-token');
\$_POST = {$postExport};
\$_POST['csrf_token'] = 'test-csrf-token';
try {
    (new SettingsController())->userSave();
} catch (Throwable \$e) {
    echo "EXCEPTION: " . \$e->getMessage();
}
PHP;
    file_put_contents($file, $body);
    $out = shell_exec('"' . PHP_BINARY . '" "' . $file . '" 2>&1');
    @unlink($file);
    return $out ?? '';
}

function getUser(PDO $db, int $id): array|false {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}
function latestLog(PDO $db, string $action): array|false {
    $stmt = $db->prepare("SELECT * FROM activity_logs WHERE action = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$action]);
    return $stmt->fetch();
}

echo "=== SECTION 0: Fixtures ===\n";
$db->exec("INSERT INTO users (role_id, full_name, email, password_hash, is_active) VALUES (10, 'Test System Admin', 'test.sysadmin.stage6@example.test', 'x', 1)");
$sysAdminId = (int)$db->lastInsertId();
ok($sysAdminId > 0, 'Created a test system_admin fixture user', (string)$sysAdminId);
$treasurerRow = $db->query("SELECT id FROM users WHERE role_id = 2 LIMIT 1")->fetch();
$treasurerId = (int)($treasurerRow['id'] ?? 0);
ok($treasurerId > 0, 'Found an existing treasurer fixture user to use as the "another user" target', (string)$treasurerId);

echo "\n=== SECTION 1: System Administrator CANNOT change their own role ===\n";
$before = getUser($db, $sysAdminId);
callUserSave('system_admin', $sysAdminId, [
    'id' => $sysAdminId, 'full_name' => 'Test System Admin', 'email' => 'test.sysadmin.stage6@example.test',
    'phone' => '', 'role_id' => 1, // attempting self-escalation to admin
]);
$after = getUser($db, $sysAdminId);
ok((int)$after['role_id'] === (int)$before['role_id'], 'system_admin role_id UNCHANGED after self-escalation attempt', "before={$before['role_id']} after={$after['role_id']}");
ok((int)$after['role_id'] === 10, 'system_admin is still role_id 10 (system_admin), not 1 (admin)');
$blockedLog = latestLog($db, 'self_role_change_blocked');
ok($blockedLog !== false && (int)$blockedLog['user_id'] === $sysAdminId, 'Blocked self-escalation attempt was recorded in the audit trail');

echo "\n=== SECTION 2: System Administrator CAN still self-edit non-role fields ===\n";
callUserSave('system_admin', $sysAdminId, [
    'id' => $sysAdminId, 'full_name' => 'Test System Admin Updated', 'email' => 'test.sysadmin.stage6@example.test',
    'phone' => '0700000000', 'role_id' => 10, // unchanged -- same as current
]);
$after2 = getUser($db, $sysAdminId);
ok($after2['full_name'] === 'Test System Admin Updated', 'system_admin successfully self-edited full_name (role unchanged in the same request)');
ok($after2['phone'] === '0700000000', 'system_admin successfully self-edited phone');
ok((int)$after2['role_id'] === 10, 'role_id still unchanged after a legitimate self-edit');

echo "\n=== SECTION 3: Admin CAN still change ANOTHER user's role (positive control) ===\n";
$beforeTreasurer = getUser($db, $treasurerId);
callUserSave('admin', 1, [
    'id' => $treasurerId, 'full_name' => $beforeTreasurer['full_name'], 'email' => $beforeTreasurer['email'],
    'phone' => $beforeTreasurer['phone'] ?? '', 'role_id' => 4, // treasurer -> cashier
]);
$afterTreasurer = getUser($db, $treasurerId);
ok((int)$afterTreasurer['role_id'] === 4, 'admin successfully changed another user\'s role (treasurer -> cashier)', (string)$afterTreasurer['role_id']);
$roleChangeLog = latestLog($db, 'user_role_changed');
ok($roleChangeLog !== false && str_contains($roleChangeLog['description'], 'Treasurer') && str_contains($roleChangeLog['description'], 'Cashier'),
    'Role change for another user was recorded in the audit trail with old and new role labels', $roleChangeLog['description'] ?? 'none');

// Restore for cleanliness.
$db->exec("UPDATE users SET role_id = 2 WHERE id = {$treasurerId}");

echo "\n=== SECTION 4: Admin also CANNOT change their own role (universal rule, not just system_admin) ===\n";
$beforeAdmin = getUser($db, 1);
callUserSave('admin', 1, [
    'id' => 1, 'full_name' => $beforeAdmin['full_name'], 'email' => $beforeAdmin['email'],
    'phone' => $beforeAdmin['phone'] ?? '', 'role_id' => 10, // attempting self-change to system_admin
]);
$afterAdmin = getUser($db, 1);
ok((int)$afterAdmin['role_id'] === (int)$beforeAdmin['role_id'], 'admin role_id UNCHANGED after attempting to change their own role', "before={$beforeAdmin['role_id']} after={$afterAdmin['role_id']}");

echo "\n=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
