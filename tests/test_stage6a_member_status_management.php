<?php
/**
 * STAGE 6-A — Three-State Member Status Management — Test Harness
 *
 * TARGETS AN ISOLATED, DISPOSABLE CLONE (name passed as argv[1]), a full
 * clone of empower_db's current schema+data (34 known-broken storage
 * artifacts excluded). NEVER touches empower_db for financial/member writes.
 *
 * Builds on results/stage6_member_status_toggle_audit.md, which confirmed
 * MemberModel::toggleStatus() always folded a dormant member to 'inactive'
 * and that the Edit-Member status <select> had no 'Dormant' option (so any
 * unrelated edit silently reset a dormant member to 'active'). This suite
 * verifies the replacement: MemberModel::changeStatus() (explicit 3-state,
 * whitelist-validated), MemberController::changeStatus() (the new
 * 'member-status-change' route, admin-only/CSRF/no-op-safe), the fixed
 * Edit-Member status preservation, retirement of the old toggle route, and
 * that MemberModel::syncDormantStatus()'s own active<->dormant self-healing
 * still works unaffected and now logs every auto-driven change.
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

function makeMember(MemberModel $mm, int $admin, string $tag, string $status = 'active', string $joinDate = null): int {
    static $seq = 0; $seq++;
    $joinDate = $joinDate ?? date('Y-m-d', strtotime('-1 year'));
    $m = $mm->createWithCompulsoryAccount([
        'member_number' => $mm->generateMemberNumber(),
        'first_name' => 'Stage6a', 'last_name' => "Member{$tag}{$seq}", 'gender' => 'Female',
        'phone' => '07000007' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT),
        'national_id' => "CM6A{$tag}{$seq}",
        'join_date' => $joinDate, 'status' => $status,
    ], $admin);
    $id = $m['member_id'];
    if ($status !== 'active') {
        // createWithCompulsoryAccount always creates 'active' (see its own
        // comment) -- force the requested starting status directly for
        // test setup purposes only.
        $mm->changeStatus($id, $status);
    }
    return $id;
}

// Inserts a genuinely QUALIFYING month of activity per SettingsModel::
// memberActivityPolicy() defaults (min_monthly_deposits=2, min_monthly_
// savings=40000 total, confirmed live -- no override rows exist in this
// clone's settings table): two deposit rows in the same calendar month
// summing to >= $totalAmount. A single row (COUNT(*)=1) would NEVER
// qualify regardless of amount -- that was this test file's own bug on
// its first run (H1/H3 below), not a defect in syncDormantStatus().
function insertQualifyingDeposit(PDO $pdo, int $memberId, string $date, float $totalAmount): void {
    static $seq = 0;
    $stmt = $pdo->prepare(
        "INSERT INTO `savings` (member_id, receipt_number, transaction_type, credit, debit, running_balance,
                                 payment_method, transaction_date, financial_year, recorded_by)
         VALUES (?, ?, 'deposit', ?, NULL, ?, 'Cash', ?, ?, 1)"
    );
    foreach ([$totalAmount / 2, $totalAmount / 2] as $half) {
        $seq++;
        $stmt->execute([$memberId, 'S6ARCPT' . $seq, $half, $half, $date, (int)date('Y', strtotime($date))]);
    }
}

function memberStatus(PDO $pdo, int $id): string {
    return (string)$pdo->query("SELECT status FROM members WHERE id={$id}")->fetchColumn();
}

// ================================================================
// A. Schema / route retirement sanity
// ================================================================
section('A. Schema and route-retirement sanity');
$col = $pdo->query("SHOW COLUMNS FROM members WHERE Field='status'")->fetch(PDO::FETCH_ASSOC);
check("status column still ENUM('active','inactive','dormant')",
    str_contains($col['Type'], "'active'") && str_contains($col['Type'], "'inactive'") && str_contains($col['Type'], "'dormant'"),
    $col['Type']);

$indexPhp = file_get_contents(__DIR__ . '/../index.php');
check("old 'member-toggle' route removed from index.php", !str_contains($indexPhp, "'member-toggle'"));
check("new 'member-status-change' route present in index.php", str_contains($indexPhp, "'member-status-change'"));

require_once APP_PATH . '/controllers/MemberController.php';
$hasOldMethod = method_exists('MemberController', 'toggleStatus');
$hasNewMethod = method_exists('MemberController', 'changeStatus');
check('MemberController::toggleStatus() no longer exists', !$hasOldMethod);
check('MemberController::changeStatus() exists', $hasNewMethod);
check('MemberModel::changeStatus() exists', method_exists('MemberModel', 'changeStatus'));
check('MemberModel::toggleStatus() no longer exists', !method_exists('MemberModel', 'toggleStatus'));

// ================================================================
// B. MemberModel::changeStatus() -- all 9 manual transition combinations
// ================================================================
section('B. MemberModel::changeStatus() -- 9 manual transitions (model layer)');
$statuses = ['active', 'inactive', 'dormant'];
foreach ($statuses as $from) {
    foreach ($statuses as $to) {
        $id = makeMember($memberModel, $ADMIN, "B{$from}{$to}", $from);
        check("model: {$from} -> {$to}", memberStatus($pdo, $id) === $from, 'setup failed'); // sanity before the real transition
        $ok = $memberModel->changeStatus($id, $to);
        check("changeStatus({$from} -> {$to}) returns true", $ok === true);
        check("changeStatus({$from} -> {$to}) actually wrote {$to}", memberStatus($pdo, $id) === $to, memberStatus($pdo, $id));
    }
}

// ================================================================
// C. Server-side whitelist validation (model layer)
// ================================================================
section('C. Server-side whitelist validation');
$idInvalid = makeMember($memberModel, $ADMIN, 'CINV', 'active');
$rejected = $memberModel->changeStatus($idInvalid, 'bogus');
check('changeStatus() rejects an out-of-whitelist value', $rejected === false);
check('status unchanged after rejected value', memberStatus($pdo, $idInvalid) === 'active');

$idEmpty = makeMember($memberModel, $ADMIN, 'CEMPTY', 'active');
check('changeStatus() rejects empty string', $memberModel->changeStatus($idEmpty, '') === false);

$idCase = makeMember($memberModel, $ADMIN, 'CCASE', 'active');
check('changeStatus() rejects wrong-case value (Active)', $memberModel->changeStatus($idCase, 'Active') === false);

$idSql = makeMember($memberModel, $ADMIN, 'CSQL', 'active');
$sqlAttempt = "active' OR '1'='1";
check('changeStatus() rejects SQL-injection-shaped value', $memberModel->changeStatus($idSql, $sqlAttempt) === false);
check('member row survives the injection attempt unchanged', memberStatus($pdo, $idSql) === 'active');

// ================================================================
// D. Controller-level: whitelist-validated 'member-status-change' route
//    (real HTTP-style flow: role, CSRF, POST body -- via subprocess so
//    Session/Controller bootstrap exactly as a real request would)
//
// IMPORTANT: Session::requireAuth() -> revalidateAuthorization() (Stage
// 13-C, core/Session.php) re-syncs the session's cached role from the
// REAL `users`/`roles` row for the given user_id on every single
// request -- deliberately, so a role change or deactivation takes effect
// immediately for a session already in progress. That means a fake
// Session::set('user_role', $x) with an unrelated/always-admin user_id
// gets silently overwritten back to that user's real DB role before any
// gate check runs. renderAs() below therefore takes a real $userId whose
// row in this disposable clone's `users` table genuinely carries the
// target role -- the only way to exercise this app's authorization
// gates faithfully. (Any prior test that hardcoded user_id=1 for every
// simulated role, combined with a controller action that always ends
// in redirect()+exit on both the allowed and blocked paths, could not
// have reliably distinguished "blocked" from "allowed" -- see the
// Stage 6-A report for how this was handled for THIS suite's own
// authorization checks.)
// ================================================================
function renderAs(string $dbName, int $userId, string $roleLabel, string $class, string $method, array $get = [], array $post = []): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_stage6a_subproc_' . $counter . '.php';
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
// NOTE: reached()/RESULT:REACHED is NOT a reliable success/failure signal
// for changeStatus() -- every path through it (blocked-by-requireAdmin,
// rejected-invalid-status, AND a fully successful change) ends in
// Controller::redirect(), which calls exit before "RESULT:REACHED" can
// print. It is kept below only to catch an actual uncaught exception
// (crash), never as a stand-in for "the action succeeded." The real
// signal used throughout D/E/F is the resulting DB row.
function crashed(string $out): bool { return str_contains($out, 'RESULT:EXCEPTION'); }

// Real per-role user ids in THIS disposable clone (data cloned from
// empower_db) -- required because Session::requireAuth() resyncs the
// session's role from the DB on every call (Stage 13-C, by design).
$roleUserIds = [
    'admin'         => 1,
    'chairman'      => (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='chairman' AND u.is_active=1 LIMIT 1")->fetchColumn(),
    'treasurer'     => (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='treasurer' AND u.is_active=1 LIMIT 1")->fetchColumn(),
    'cashier'       => (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='cashier' AND u.is_active=1 LIMIT 1")->fetchColumn(),
    'office_admin'  => (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='office_admin' AND u.is_active=1 LIMIT 1")->fetchColumn(),
];
$rolesWithRealAccount = [];
$rolesWithoutRealAccount = [];
foreach (['chairman', 'treasurer', 'cashier', 'office_admin'] as $r) {
    if ($roleUserIds[$r] > 0) { $rolesWithRealAccount[] = $r; } else { $rolesWithoutRealAccount[] = $r; }
}
// No real active production account exists for loans_officer or
// system_admin in this data snapshot (confirmed by direct query --
// see the Stage 6-A report). A synthetic, clearly-labeled test user is
// created in THIS DISPOSABLE CLONE ONLY so the code-level authorization
// gate is still verified end-to-end rather than skipped outright.
function makeSyntheticUser(PDO $pdo, string $roleName, string $tag): int {
    $roleId = (int)$pdo->query("SELECT id FROM roles WHERE name='{$roleName}'")->fetchColumn();
    $stmt = $pdo->prepare(
        "INSERT INTO users (role_id, full_name, email, password_hash, is_active)
         VALUES (?, ?, ?, ?, 1)"
    );
    $stmt->execute([$roleId, "Stage6A Synthetic {$tag}", "stage6a.synthetic.{$tag}@test.invalid", password_hash('x', PASSWORD_DEFAULT)]);
    return (int)$pdo->lastInsertId();
}
$roleUserIds['loans_officer'] = makeSyntheticUser($pdo, 'loans_officer', 'LO');
$roleUserIds['system_admin']  = makeSyntheticUser($pdo, 'system_admin', 'SA');
$rolesWithRealAccount[] = 'loans_officer'; // synthetic, disclosed above and in the report
$rolesWithRealAccount[] = 'system_admin';  // synthetic, disclosed above and in the report

section("D. Controller 'member-status-change' route (admin) -- 9 transitions via real HTTP-style POST");
foreach ($statuses as $from) {
    foreach ($statuses as $to) {
        $id = makeMember($memberModel, $ADMIN, "D{$from}{$to}", $from);
        $out = renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'changeStatus', [], ['id' => $id, 'status' => $to, 'csrf_token' => 'skip']);
        check("controller: admin {$from} -> {$to} did not crash", !crashed($out), $out);
        check("controller: admin {$from} -> {$to} wrote correct status", memberStatus($pdo, $id) === $to, memberStatus($pdo, $id));
    }
}

section('D2. Controller whitelist validation');
$idBadStatus = makeMember($memberModel, $ADMIN, 'DBAD', 'active');
$out = renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'changeStatus', [], ['id' => $idBadStatus, 'status' => 'bogus', 'csrf_token' => 'skip']);
check('controller rejects out-of-whitelist status without crashing', !crashed($out), $out);
check('controller rejects out-of-whitelist status: no DB change', memberStatus($pdo, $idBadStatus) === 'active');

$idMissingStatus = makeMember($memberModel, $ADMIN, 'DMISS', 'active');
$out = renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'changeStatus', [], ['id' => $idMissingStatus, 'csrf_token' => 'skip']);
check('controller rejects missing status field without crashing', !crashed($out), $out);
check('controller rejects missing status field: no DB change', memberStatus($pdo, $idMissingStatus) === 'active');

// ================================================================
// E. Authorization -- admin allowed, every other real role denied
// ================================================================
section('E. Authorization on member-status-change');
echo "  (real provisioned accounts used for: " . implode(', ', $rolesWithRealAccount) . ")\n";
if ($rolesWithoutRealAccount) {
    echo "  (no real provisioned account existed for: " . implode(', ', $rolesWithoutRealAccount) . " -- none, since loans_officer/system_admin used synthetic disposable-clone accounts instead of being skipped)\n";
}
$deniedRoles = ['chairman', 'treasurer', 'cashier', 'office_admin', 'loans_officer', 'system_admin'];
foreach ($deniedRoles as $role) {
    $id = makeMember($memberModel, $ADMIN, "E{$role}", 'active');
    $out = renderAs($dbName, $roleUserIds[$role], $role, 'MemberController', 'changeStatus', [], ['id' => $id, 'status' => 'inactive', 'csrf_token' => 'skip']);
    check("{$role} did not crash", !crashed($out), $out);
    check("{$role} BLOCKED: status left unchanged", memberStatus($pdo, $id) === 'active', memberStatus($pdo, $id));
}
$idAdminOk = makeMember($memberModel, $ADMIN, 'Eadmin', 'active');
$out = renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'changeStatus', [], ['id' => $idAdminOk, 'status' => 'inactive', 'csrf_token' => 'skip']);
check('admin did not crash', !crashed($out), $out);
check('admin CAN change a member\'s status: change actually applied', memberStatus($pdo, $idAdminOk) === 'inactive', memberStatus($pdo, $idAdminOk));

// ================================================================
// F. CSRF
// ================================================================
section('F. CSRF protection on member-status-change');
$idCsrfMissing = makeMember($memberModel, $ADMIN, 'FMISS', 'active');
$out = renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'changeStatus', [], ['id' => $idCsrfMissing, 'status' => 'inactive']); // no csrf_token key at all
check('missing CSRF token did not crash', !crashed($out), $out);
check('missing CSRF token BLOCKED: no DB change', memberStatus($pdo, $idCsrfMissing) === 'active', memberStatus($pdo, $idCsrfMissing));

$idCsrfWrong = makeMember($memberModel, $ADMIN, 'FWRONG', 'active');
$out = renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'changeStatus', [], ['id' => $idCsrfWrong, 'status' => 'inactive', 'csrf_token' => 'wrong-token']);
check('wrong CSRF token did not crash', !crashed($out), $out);
check('wrong CSRF token BLOCKED: no DB change', memberStatus($pdo, $idCsrfWrong) === 'active', memberStatus($pdo, $idCsrfWrong));

$idCsrfRight = makeMember($memberModel, $ADMIN, 'FRIGHT', 'active');
$out = renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'changeStatus', [], ['id' => $idCsrfRight, 'status' => 'inactive', 'csrf_token' => 'skip']);
check('correct CSRF token did not crash', !crashed($out), $out);
check('correct CSRF token ALLOWED: change applied', memberStatus($pdo, $idCsrfRight) === 'inactive', memberStatus($pdo, $idCsrfRight));

// ================================================================
// G. Edit-Member status preservation (the Stage 6 §8 finding)
// ================================================================
section('G. Edit-Member form status preservation');

// G1: dormant member, unrelated edit (no 'status' field posted at all --
// simulates a stale/broken client) must NOT silently reset to active.
$idG1 = makeMember($memberModel, $ADMIN, 'G1', 'active');
$memberModel->changeStatus($idG1, 'dormant');
$out = renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'edit', ['id' => $idG1], [
    'csrf_token' => 'skip', 'first_name' => 'Stage6a', 'last_name' => 'EditedG1', 'gender' => 'Female',
    'phone' => '0700099901', 'national_id' => 'EDITG1', 'join_date' => date('Y-m-d', strtotime('-1 year')),
    // status intentionally omitted
]);
check('G1: dormant + unrelated edit (status omitted) preserves dormant', memberStatus($pdo, $idG1) === 'dormant', memberStatus($pdo, $idG1));

// G2: dormant member, edit form correctly posts status=dormant (the fixed
// <select> now offers this and preselects it) -- must stay dormant.
$idG2 = makeMember($memberModel, $ADMIN, 'G2', 'active');
$memberModel->changeStatus($idG2, 'dormant');
$out = renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'edit', ['id' => $idG2], [
    'csrf_token' => 'skip', 'first_name' => 'Stage6a', 'last_name' => 'EditedG2', 'gender' => 'Female',
    'phone' => '0700099902', 'national_id' => 'EDITG2', 'join_date' => date('Y-m-d', strtotime('-1 year')),
    'status' => 'dormant',
]);
check('G2: dormant member, form posts status=dormant, stays dormant', memberStatus($pdo, $idG2) === 'dormant', memberStatus($pdo, $idG2));

// G3: dormant member, admin explicitly selects Active and saves -- the
// pre-existing safe workaround must keep working.
$idG3 = makeMember($memberModel, $ADMIN, 'G3', 'active');
$memberModel->changeStatus($idG3, 'dormant');
$out = renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'edit', ['id' => $idG3], [
    'csrf_token' => 'skip', 'first_name' => 'Stage6a', 'last_name' => 'EditedG3', 'gender' => 'Female',
    'phone' => '0700099903', 'national_id' => 'EDITG3', 'join_date' => date('Y-m-d', strtotime('-1 year')),
    'status' => 'active',
]);
check('G3: dormant member, form explicitly posts status=active, becomes active', memberStatus($pdo, $idG3) === 'active', memberStatus($pdo, $idG3));

// G4: inactive member, unrelated edit (status omitted) preserves inactive.
$idG4 = makeMember($memberModel, $ADMIN, 'G4', 'active');
$memberModel->changeStatus($idG4, 'inactive');
$out = renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'edit', ['id' => $idG4], [
    'csrf_token' => 'skip', 'first_name' => 'Stage6a', 'last_name' => 'EditedG4', 'gender' => 'Female',
    'phone' => '0700099904', 'national_id' => 'EDITG4', 'join_date' => date('Y-m-d', strtotime('-1 year')),
]);
check('G4: inactive + unrelated edit (status omitted) preserves inactive', memberStatus($pdo, $idG4) === 'inactive', memberStatus($pdo, $idG4));

// G5: active member, unrelated edit (status posted as active, matching
// the correctly-preselected form) stays active.
$idG5 = makeMember($memberModel, $ADMIN, 'G5', 'active');
$out = renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'edit', ['id' => $idG5], [
    'csrf_token' => 'skip', 'first_name' => 'Stage6a', 'last_name' => 'EditedG5', 'gender' => 'Female',
    'phone' => '0700099905', 'national_id' => 'EDITG5', 'join_date' => date('Y-m-d', strtotime('-1 year')),
    'status' => 'active',
]);
check('G5: active member, unrelated edit stays active', memberStatus($pdo, $idG5) === 'active', memberStatus($pdo, $idG5));

// G6: new member add (no existing status) with status field tampered to
// an invalid value falls back to 'active', never silently accepts junk.
$out = renderAs($dbName, $roleUserIds['admin'], 'admin', 'MemberController', 'add', [], [
    'csrf_token' => 'skip', 'first_name' => 'Stage6a', 'last_name' => 'NewG6', 'gender' => 'Female',
    'phone' => '0700099906', 'national_id' => 'EDITG6', 'join_date' => date('Y-m-d', strtotime('-1 year')),
    'status' => 'bogus',
]);
$newG6 = $pdo->query("SELECT id, status FROM members WHERE national_id='EDITG6'")->fetch(PDO::FETCH_ASSOC);
check('G6: add-member with invalid status falls back to active (not junk)', $newG6 && $newG6['status'] === 'active', json_encode($newG6));

// ================================================================
// H. Automatic dormancy sync -- regression (must remain unaffected)
// ================================================================
section('H. syncDormantStatus() regression');

// H1: active member with a qualifying deposit this month stays active.
$idH1 = makeMember($memberModel, $ADMIN, 'H1', 'active', date('Y-m-d', strtotime('-8 months')));
insertQualifyingDeposit($pdo, $idH1, date('Y-m-d'), 100000);
$memberModel->syncDormantStatus();
check('H1: recently-active member stays active after sync', memberStatus($pdo, $idH1) === 'active', memberStatus($pdo, $idH1));

// H2: active member with NO qualifying activity since join 8 months ago
// (dormancy_months default 6) becomes dormant automatically.
$idH2 = makeMember($memberModel, $ADMIN, 'H2', 'active', date('Y-m-d', strtotime('-8 months')));
$memberModel->syncDormantStatus();
check('H2: 8-month-inactive member auto-flips to dormant', memberStatus($pdo, $idH2) === 'dormant', memberStatus($pdo, $idH2));
$logH2 = $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='member_status_auto_synced' AND description LIKE '%dormant%'")->fetchColumn();
check('H2: auto-sync change was logged (not silent)', (int)$logH2 >= 1);

// H3: dormant member who resumes qualifying activity this month
// auto-flips back to active (the self-healing behaviour Stage 6 confirmed
// must remain intact).
$idH3 = makeMember($memberModel, $ADMIN, 'H3', 'active', date('Y-m-d', strtotime('-8 months')));
$memberModel->syncDormantStatus();
check('H3 setup: member became dormant first', memberStatus($pdo, $idH3) === 'dormant');
insertQualifyingDeposit($pdo, $idH3, date('Y-m-d'), 100000);
$memberModel->syncDormantStatus();
check('H3: dormant member with resumed activity auto-flips back to active', memberStatus($pdo, $idH3) === 'active', memberStatus($pdo, $idH3));

// H4: inactive members are never touched by sync, even with zero activity.
$idH4 = makeMember($memberModel, $ADMIN, 'H4', 'active', date('Y-m-d', strtotime('-24 months')));
$memberModel->changeStatus($idH4, 'inactive');
$memberModel->syncDormantStatus();
check('H4: inactive member untouched by sync regardless of inactivity', memberStatus($pdo, $idH4) === 'inactive', memberStatus($pdo, $idH4));

// H5: a manual override that AGREES with computed reality is stable
// across a sync call (no false churn).
$idH5 = makeMember($memberModel, $ADMIN, 'H5', 'active', date('Y-m-d', strtotime('-1 month')));
insertQualifyingDeposit($pdo, $idH5, date('Y-m-d'), 100000);
$memberModel->changeStatus($idH5, 'active'); // explicit no-op-equivalent manual confirmation
$memberModel->syncDormantStatus();
check('H5: manual status agreeing with reality is stable after sync', memberStatus($pdo, $idH5) === 'active', memberStatus($pdo, $idH5));

// H6 -- UPDATED by Stage 6-C (originally documented the open item from
// the Stage 6-A report: a manual override contradicting computed reality
// used to be recomputed away by the very next sync call, confirmed live
// in the Stage 6-B audit's simulation). Stage 6-C closed that gap with
// the members.status_source column: syncDormantStatus() now only ever
// considers status_source='automatic' rows, so a manual override -- in
// either direction -- persists. This assertion intentionally now checks
// the OPPOSITE of what it originally checked; that is the correct,
// deliberate outcome of Stage 6-C, not a regression.
$idH6 = makeMember($memberModel, $ADMIN, 'H6', 'active', date('Y-m-d', strtotime('-8 months')));
$memberModel->changeStatus($idH6, 'active'); // force active despite 8 months with no qualifying activity -- now manual
$memberModel->syncDormantStatus();
check('H6 (Stage 6-C): forced active contrary to policy now PERSISTS across a sync call (status_source=manual)',
    memberStatus($pdo, $idH6) === 'active', memberStatus($pdo, $idH6));

echo "\n=== SUMMARY: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
