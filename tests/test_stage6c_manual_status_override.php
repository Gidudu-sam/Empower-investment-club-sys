<?php
/**
 * STAGE 6-C — Persistent Manual Member Status Override — Test Harness
 *
 * TARGETS AN ISOLATED, DISPOSABLE CLONE (name passed as argv[1]), a full
 * clone of empower_db's current schema+data (34 known-broken storage
 * artifacts excluded) with the Stage 6-C migration
 * (database/stage6c_member_status_source.sql) already applied. NEVER
 * touches empower_db.
 *
 * Verifies the invariant Stage 6-B found missing and Stage 6-C
 * implements: automatic dormancy synchronization may manage only members
 * whose status_source is 'automatic'; an admin's explicit status
 * decision (via the dedicated Change Status action, or an actual status
 * change on the Edit-Member form) sets status_source = 'manual' and that
 * decision survives any number of subsequent syncDormantStatus() calls.
 */
$dbName = $argv[1] ?? '';
if ($dbName === '' || $dbName === 'empower_db') {
    fwrite(STDERR, "Refusing to run without an explicit, non-production disposable schema name.\n");
    exit(1);
}

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', $dbName);
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
define('APP_PATH', __DIR__ . '/../app');
define('CORE_PATH', __DIR__ . '/../core');

require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';

echo "=== TARGET DATABASE: " . DB_NAME . " (disposable clone -- never empower_db) ===\n";
$pdo = Database::getInstance()->getConnection();

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; echo "  [FAIL] $label -- $detail\n"; }
}
function section(string $t): void { echo "\n=== $t ===\n"; }

$ADMIN = 1;
$memberModel = new MemberModel();

function makeMember(MemberModel $mm, int $admin, string $tag, string $status = 'active', string $joinDate = null, string $source = 'automatic'): int {
    static $seq = 0; $seq++;
    $joinDate = $joinDate ?? date('Y-m-d', strtotime('-1 year'));
    $m = $mm->createWithCompulsoryAccount([
        'member_number' => $mm->generateMemberNumber(),
        'first_name' => 'Stage6c', 'last_name' => "M{$tag}{$seq}", 'gender' => 'Female',
        'phone' => '07000008' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT),
        'national_id' => "S6C{$tag}{$seq}",
        'join_date' => $joinDate, 'status' => 'active', 'status_source' => 'automatic',
    ], $admin);
    $id = $m['member_id'];
    if ($status !== 'active' || $source !== 'automatic') {
        if ($source === 'manual') {
            $mm->changeStatus($id, $status); // real manual path -- sets status_source itself
        } else {
            // force a specific starting (status, automatic) pair directly, for test setup only
            $pdoRef = (new ReflectionClass($mm))->getParentClass()->getProperty('db');
            $pdoRef->setAccessible(true);
            $db = $pdoRef->getValue($mm);
            $db->prepare("UPDATE members SET status=?, status_source='automatic' WHERE id=?")->execute([$status, $id]);
        }
    }
    return $id;
}
function insertQualifyingDeposit(PDO $pdo, int $memberId, string $date, float $totalAmount): void {
    static $seq = 0;
    $stmt = $pdo->prepare(
        "INSERT INTO `savings` (member_id, receipt_number, transaction_type, credit, debit, running_balance,
                                 payment_method, transaction_date, financial_year, recorded_by)
         VALUES (?, ?, 'deposit', ?, NULL, ?, 'Cash', ?, ?, 1)"
    );
    foreach ([$totalAmount / 2, $totalAmount / 2] as $half) {
        $seq++;
        $stmt->execute([$memberId, 'S6CRCPT' . $seq, $half, $half, $date, (int)date('Y', strtotime($date))]);
    }
}
function row(PDO $pdo, int $id): array {
    return $pdo->query("SELECT status, status_source FROM members WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
}
function status(PDO $pdo, int $id): string { return row($pdo, $id)['status']; }
function source(PDO $pdo, int $id): string { return row($pdo, $id)['status_source']; }

$STALE = '-8 months'; // no qualifying activity possible -> computes dormant
$FRESH = '-1 month';  // within policy -> computes active

// ================================================================
// SCHEMA
// ================================================================
section('Schema');
$col = $pdo->query("SHOW COLUMNS FROM members WHERE Field='status_source'")->fetch(PDO::FETCH_ASSOC);
check('status_source column exists', $col !== false);
check("status_source is ENUM('automatic','manual')", $col && str_contains($col['Type'], "'automatic'") && str_contains($col['Type'], "'manual'"), $col['Type'] ?? '');
check('status_source is NOT NULL', $col && $col['Null'] === 'NO');
check("status_source default is 'automatic'", $col && $col['Default'] === 'automatic');
$nullCount = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE status_source IS NULL")->fetchColumn();
check('no existing member has a NULL status_source', $nullCount === 0, "found {$nullCount}");
$autoCount = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE status_source='automatic'")->fetchColumn();
$totalCount = (int)$pdo->query("SELECT COUNT(*) FROM members")->fetchColumn();
check('every pre-existing member backfilled to automatic', $autoCount === $totalCount, "{$autoCount}/{$totalCount}");
$newId = makeMember($memberModel, $ADMIN, 'SchemaNew', 'active');
check('a newly created member defaults to automatic', source($pdo, $newId) === 'automatic', source($pdo, $newId));
$rejected = $memberModel->changeStatus($newId, 'not_a_real_status');
check('invalid status value cannot be persisted (whitelist unchanged)', $rejected === false);

// ================================================================
// MANUAL TRANSITIONS (all 9) -- every one must set status_source=manual
// ================================================================
section('Manual transitions (9) -- model layer');
$statuses = ['active', 'inactive', 'dormant'];
foreach ($statuses as $from) {
    foreach ($statuses as $to) {
        $id = makeMember($memberModel, $ADMIN, "T{$from}{$to}", $from);
        check("setup: {$from} starts automatic", source($pdo, $id) === 'automatic');
        $memberModel->changeStatus($id, $to);
        check("manual {$from} -> {$to}: status correct", status($pdo, $id) === $to, status($pdo, $id));
        check("manual {$from} -> {$to}: status_source = manual", source($pdo, $id) === 'manual', source($pdo, $id));
    }
}

// ================================================================
// AUTOMATIC BEHAVIOR (system-managed members only)
// ================================================================
section('Automatic behavior (status_source=automatic members)');
$idA1 = makeMember($memberModel, $ADMIN, 'Auto1', 'active', $STALE); // still automatic
$memberModel->syncDormantStatus();
check('automatic active -> dormant (stale, no qualifying activity)', status($pdo, $idA1) === 'dormant', status($pdo, $idA1));
check('remains status_source=automatic after auto transition', source($pdo, $idA1) === 'automatic', source($pdo, $idA1));

$idA2 = makeMember($memberModel, $ADMIN, 'Auto2', 'active', $STALE);
$memberModel->syncDormantStatus(); // becomes dormant
insertQualifyingDeposit($pdo, $idA2, date('Y-m-d'), 100000);
$memberModel->syncDormantStatus();
check('automatic dormant -> active (resumed qualifying activity)', status($pdo, $idA2) === 'active', status($pdo, $idA2));
check('remains status_source=automatic after auto transition', source($pdo, $idA2) === 'automatic', source($pdo, $idA2));

$idA3 = makeMember($memberModel, $ADMIN, 'Auto3', 'active', $STALE);
$memberModel->changeStatus($idA3, 'inactive'); // manual, but this scenario tests automatic protection separately below
// Reset to a genuinely automatic inactive-equivalent scenario is impossible (inactive is
// always reached via changeStatus(), which is manual by definition) -- inactive's
// automatic-side protection is instead proven structurally: syncDormantStatus()'s own
// SELECT scope is `status IN ('active','dormant')`, so inactive rows are never selected
// regardless of status_source. Confirmed by direct source inspection (Stage 6-B/6-C) and
// re-confirmed behaviorally in the "manual inactive" scenarios below.
$memberModel->syncDormantStatus();
check('inactive member (any source) untouched by sync', status($pdo, $idA3) === 'inactive', status($pdo, $idA3));

// ================================================================
// MANUAL OVERRIDE PERSISTENCE -- the core Stage 6-C invariant
// ================================================================
section('Manual override persistence across repeated sync calls');
$idP1 = makeMember($memberModel, $ADMIN, 'Persist1', 'active', $FRESH);
$memberModel->changeStatus($idP1, 'active'); // explicit reaffirm -> manual
for ($i = 0; $i < 3; $i++) { $memberModel->syncDormantStatus(); }
check('manual active remains active after 3 sync calls', status($pdo, $idP1) === 'active', status($pdo, $idP1));
check('remains status_source=manual', source($pdo, $idP1) === 'manual', source($pdo, $idP1));

$idP2 = makeMember($memberModel, $ADMIN, 'Persist2', 'active', $FRESH);
$memberModel->changeStatus($idP2, 'dormant'); // contrary to reality -- member is actually fresh/within-policy
for ($i = 0; $i < 3; $i++) { $memberModel->syncDormantStatus(); }
check('manual dormant (contrary to reality) remains dormant after 3 sync calls', status($pdo, $idP2) === 'dormant', status($pdo, $idP2));
check('remains status_source=manual', source($pdo, $idP2) === 'manual', source($pdo, $idP2));

$idP3 = makeMember($memberModel, $ADMIN, 'Persist3', 'active', $STALE);
$memberModel->changeStatus($idP3, 'inactive');
for ($i = 0; $i < 3; $i++) { $memberModel->syncDormantStatus(); }
check('manual inactive remains inactive after 3 sync calls', status($pdo, $idP3) === 'inactive', status($pdo, $idP3));
check('remains status_source=manual', source($pdo, $idP3) === 'manual', source($pdo, $idP3));

// ================================================================
// MIXED SCENARIOS
// ================================================================
section('Mixed automatic <-> manual scenarios');

$idM1 = makeMember($memberModel, $ADMIN, 'Mix1', 'active', $FRESH); // automatic, would stay active
$memberModel->changeStatus($idM1, 'dormant'); // admin forces dormant
$memberModel->syncDormantStatus();
check('automatic active -> manually dormant -> sync -> remains dormant', status($pdo, $idM1) === 'dormant', status($pdo, $idM1));

$idM2 = makeMember($memberModel, $ADMIN, 'Mix2', 'active', $STALE);
$memberModel->syncDormantStatus(); // becomes automatic dormant
$memberModel->changeStatus($idM2, 'active'); // admin forces active
$memberModel->syncDormantStatus();
check('automatic dormant -> manually active -> sync -> remains active', status($pdo, $idM2) === 'active', status($pdo, $idM2));

$idM3 = makeMember($memberModel, $ADMIN, 'Mix3', 'active', $FRESH);
$memberModel->changeStatus($idM3, 'inactive');
$memberModel->syncDormantStatus();
check('automatic active -> manually inactive -> sync -> remains inactive', status($pdo, $idM3) === 'inactive', status($pdo, $idM3));

$idM4 = makeMember($memberModel, $ADMIN, 'Mix4', 'active', $STALE);
$memberModel->syncDormantStatus();
$memberModel->changeStatus($idM4, 'inactive');
$memberModel->syncDormantStatus();
check('automatic dormant -> manually inactive -> sync -> remains inactive', status($pdo, $idM4) === 'inactive', status($pdo, $idM4));

$idM5 = makeMember($memberModel, $ADMIN, 'Mix5', 'active', $STALE);
$memberModel->changeStatus($idM5, 'active'); // manual, contrary to reality
insertQualifyingDeposit($pdo, $idM5, date('Y-m-d'), 100000); // real financial activity happens
$memberModel->syncDormantStatus();
check('manual active + real qualifying activity -> sync -> remains active (manual, not re-evaluated)', status($pdo, $idM5) === 'active', status($pdo, $idM5));
check('remains status_source=manual (activity does not clear manual)', source($pdo, $idM5) === 'manual', source($pdo, $idM5));

$idM6 = makeMember($memberModel, $ADMIN, 'Mix6', 'active', $FRESH);
$memberModel->changeStatus($idM6, 'dormant'); // manual, contrary to reality
insertQualifyingDeposit($pdo, $idM6, date('Y-m-d'), 100000); // real qualifying activity happens anyway
$memberModel->syncDormantStatus();
check('manual dormant + qualifying activity -> sync -> remains dormant (manual, not re-evaluated)', status($pdo, $idM6) === 'dormant', status($pdo, $idM6));
check('remains status_source=manual', source($pdo, $idM6) === 'manual', source($pdo, $idM6));

// ================================================================
// EDIT-FORM PRESERVATION (status_source must not be silently touched)
// ================================================================
function renderAs(string $dbName, int $userId, string $roleLabel, string $class, string $method, array $get = [], array $post = []): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_stage6c_subproc_' . $counter . '.php';
    $getLines = ''; foreach ($get as $k => $v) { $getLines .= "\$_GET['{$k}'] = " . var_export($v, true) . ";\n"; }
    $postLines = '';
    if ($post) {
        $postLines = "\$_SERVER['REQUEST_METHOD'] = 'POST';\n";
        foreach ($post as $k => $v) { $postLines .= "\$_POST['{$k}'] = " . var_export($v, true) . ";\n"; }
    }
    $body = <<<PHP
<?php
chdir(__DIR__ . '/..');
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', '{$dbName}');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
require 'app/config/config.php';
require CORE_PATH . '/Database.php';
require CORE_PATH . '/Model.php';
require CORE_PATH . '/Autoloader.php';
require CORE_PATH . '/Session.php';
require CORE_PATH . '/Controller.php';
Session::start();
Session::set('user_id', {$userId});
Session::set('user_role', '{$roleLabel}');
Session::set('user_name', 'Probe {$roleLabel}');
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
function crashed(string $out): bool { return str_contains($out, 'RESULT:EXCEPTION'); }

$roleUserIds = [
    'admin'         => 1,
    'chairman'      => (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='chairman' AND u.is_active=1 LIMIT 1")->fetchColumn(),
    'treasurer'     => (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='treasurer' AND u.is_active=1 LIMIT 1")->fetchColumn(),
    'cashier'       => (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='cashier' AND u.is_active=1 LIMIT 1")->fetchColumn(),
    'office_admin'  => (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='office_admin' AND u.is_active=1 LIMIT 1")->fetchColumn(),
];
function makeSyntheticUser(PDO $pdo, string $roleName, string $tag): int {
    $roleId = (int)$pdo->query("SELECT id FROM roles WHERE name='{$roleName}'")->fetchColumn();
    $stmt = $pdo->prepare("INSERT INTO users (role_id, full_name, email, password_hash, is_active) VALUES (?, ?, ?, ?, 1)");
    $stmt->execute([$roleId, "Stage6C Synthetic {$tag}", "stage6c.synthetic.{$tag}@test.invalid", password_hash('x', PASSWORD_DEFAULT)]);
    return (int)$pdo->lastInsertId();
}
$roleUserIds['loans_officer'] = makeSyntheticUser($pdo, 'loans_officer', 'LO');
$roleUserIds['system_admin']  = makeSyntheticUser($pdo, 'system_admin', 'SA');

section('Edit-form preservation of status AND status_source');

$idE1 = makeMember($memberModel, $ADMIN, 'E1', 'active');
$memberModel->changeStatus($idE1, 'active'); // -> manual
renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'edit', ['id' => $idE1], [
    'csrf_token' => 'skip', 'first_name' => 'Stage6c', 'last_name' => 'EditedE1', 'gender' => 'Female',
    'phone' => '0700099911', 'national_id' => 'EDITS6C1', 'join_date' => date('Y-m-d', strtotime('-1 year')),
    'status' => 'active',
]);
check('edit unrelated field, active/manual: status stays active', status($pdo, $idE1) === 'active', status($pdo, $idE1));
check('edit unrelated field, active/manual: status_source stays manual', source($pdo, $idE1) === 'manual', source($pdo, $idE1));

$idE2 = makeMember($memberModel, $ADMIN, 'E2', 'active');
$memberModel->changeStatus($idE2, 'dormant');
renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'edit', ['id' => $idE2], [
    'csrf_token' => 'skip', 'first_name' => 'Stage6c', 'last_name' => 'EditedE2', 'gender' => 'Female',
    'phone' => '0700099912', 'national_id' => 'EDITS6C2', 'join_date' => date('Y-m-d', strtotime('-1 year')),
    'status' => 'dormant',
]);
check('edit unrelated field, dormant/manual: status stays dormant', status($pdo, $idE2) === 'dormant', status($pdo, $idE2));
check('edit unrelated field, dormant/manual: status_source stays manual', source($pdo, $idE2) === 'manual', source($pdo, $idE2));

$idE3 = makeMember($memberModel, $ADMIN, 'E3', 'active');
$memberModel->changeStatus($idE3, 'inactive');
renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'edit', ['id' => $idE3], [
    'csrf_token' => 'skip', 'first_name' => 'Stage6c', 'last_name' => 'EditedE3', 'gender' => 'Female',
    'phone' => '0700099913', 'national_id' => 'EDITS6C3', 'join_date' => date('Y-m-d', strtotime('-1 year')),
    // status intentionally omitted -- collectInput() falls back to existing ('inactive')
]);
check('edit unrelated field (status omitted), inactive/manual: status stays inactive', status($pdo, $idE3) === 'inactive', status($pdo, $idE3));
check('edit unrelated field (status omitted), inactive/manual: status_source stays manual', source($pdo, $idE3) === 'manual', source($pdo, $idE3));

$idE4 = makeMember($memberModel, $ADMIN, 'E4', 'active'); // still automatic, never manually touched
renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'edit', ['id' => $idE4], [
    'csrf_token' => 'skip', 'first_name' => 'Stage6c', 'last_name' => 'EditedE4', 'gender' => 'Female',
    'phone' => '0700099914', 'national_id' => 'EDITS6C4', 'join_date' => date('Y-m-d', strtotime('-1 year')),
    'status' => 'active',
]);
check('edit unrelated field on automatic member: status stays active', status($pdo, $idE4) === 'active', status($pdo, $idE4));
check('edit unrelated field on automatic member: status_source stays automatic (not flipped to manual)', source($pdo, $idE4) === 'automatic', source($pdo, $idE4));

$idE5 = makeMember($memberModel, $ADMIN, 'E5', 'active'); // automatic
renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'edit', ['id' => $idE5], [
    'csrf_token' => 'skip', 'first_name' => 'Stage6c', 'last_name' => 'EditedE5', 'gender' => 'Female',
    'phone' => '0700099915', 'national_id' => 'EDITS6C5', 'join_date' => date('Y-m-d', strtotime('-1 year')),
    'status' => 'inactive', // an ACTUAL deliberate change via the edit form
]);
check('edit form used to actually CHANGE status: new status applied', status($pdo, $idE5) === 'inactive', status($pdo, $idE5));
check('edit form used to actually CHANGE status: status_source becomes manual', source($pdo, $idE5) === 'manual', source($pdo, $idE5));

// ================================================================
// SECURITY
// ================================================================
section('Security');
$deniedRoles = ['chairman', 'treasurer', 'cashier', 'office_admin', 'loans_officer', 'system_admin'];
foreach ($deniedRoles as $role) {
    $id = makeMember($memberModel, $ADMIN, "Sec{$role}", 'active');
    $out = renderAs($dbName, $roleUserIds[$role], $role, 'MemberController', 'changeStatus', [], ['id' => $id, 'status' => 'inactive', 'csrf_token' => 'skip']);
    check("{$role} did not crash", !crashed($out), $out);
    check("{$role} BLOCKED from changing status", status($pdo, $id) === 'active' && source($pdo, $id) === 'automatic', status($pdo, $id) . '/' . source($pdo, $id));
}

$idCsrfMissing = makeMember($memberModel, $ADMIN, 'SecCsrfMiss', 'active');
$out = renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'changeStatus', [], ['id' => $idCsrfMissing, 'status' => 'inactive']);
check('missing CSRF did not crash', !crashed($out), $out);
check('missing CSRF: status unchanged, source unchanged', status($pdo, $idCsrfMissing) === 'active' && source($pdo, $idCsrfMissing) === 'automatic');

$idGet = makeMember($memberModel, $ADMIN, 'SecGet', 'active');
$out = renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'changeStatus', ['id' => $idGet, 'status' => 'inactive'], []); // GET only, no POST
check('GET-only request did not crash', !crashed($out), $out);
check('GET cannot change status', status($pdo, $idGet) === 'active' && source($pdo, $idGet) === 'automatic');

$idBadStatus = makeMember($memberModel, $ADMIN, 'SecBadStatus', 'active');
$out = renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'changeStatus', [], ['id' => $idBadStatus, 'status' => 'bogus', 'csrf_token' => 'skip']);
check('invalid status did not crash', !crashed($out), $out);
check('invalid status rejected', status($pdo, $idBadStatus) === 'active' && source($pdo, $idBadStatus) === 'automatic');

$idInjectAuto = makeMember($memberModel, $ADMIN, 'SecInjAuto', 'active');
$out = renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'changeStatus', [], ['id' => $idInjectAuto, 'status' => 'dormant', 'status_source' => 'automatic', 'csrf_token' => 'skip']);
check('injected status_source=automatic did not crash', !crashed($out), $out);
check('injected status_source=automatic ignored -- real change is still manual', status($pdo, $idInjectAuto) === 'dormant' && source($pdo, $idInjectAuto) === 'manual', status($pdo, $idInjectAuto) . '/' . source($pdo, $idInjectAuto));

$idInjectManual = makeMember($memberModel, $ADMIN, 'SecInjManual', 'active');
$out = renderAs($dbName, $roleUserIds['chairman'], 'chairman', 'MemberController', 'changeStatus', [], ['id' => $idInjectManual, 'status' => 'inactive', 'status_source' => 'manual', 'csrf_token' => 'skip']);
check('injected status_source=manual from unauthorized role did not crash', !crashed($out), $out);
check('injected status_source=manual from unauthorized role grants NO authority (blocked entirely)', status($pdo, $idInjectManual) === 'active' && source($pdo, $idInjectManual) === 'automatic', status($pdo, $idInjectManual) . '/' . source($pdo, $idInjectManual));

// ================================================================
// REGRESSION
// ================================================================
section('Regression');
$indexPhp = file_get_contents(__DIR__ . '/../index.php');
check("old 'member-toggle' route still absent", !str_contains($indexPhp, "'member-toggle'"));
check("'member-status-change' route still present", str_contains($indexPhp, "'member-status-change'"));
require_once APP_PATH . '/controllers/MemberController.php';
check('MemberController::toggleStatus() still does not exist', !method_exists('MemberController', 'toggleStatus'));
check('MemberModel::toggleStatus() still does not exist', !method_exists('MemberModel', 'toggleStatus'));

$logCounts = $pdo->query("SELECT action, COUNT(*) c FROM activity_logs WHERE action IN ('member_status_changed','member_status_auto_synced') GROUP BY action")->fetchAll(PDO::FETCH_KEY_PAIR);
check('activity_logs has manual (member_status_changed) entries', ($logCounts['member_status_changed'] ?? 0) > 0, json_encode($logCounts));
check('activity_logs has automatic (member_status_auto_synced) entries', ($logCounts['member_status_auto_synced'] ?? 0) > 0, json_encode($logCounts));

echo "\n=== SUMMARY: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
