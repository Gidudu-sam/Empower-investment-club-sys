<?php
/**
 * STAGE 6-B AUDIT-ONLY SIMULATION — NOT a permanent regression test.
 *
 * Purpose: observe and report the ACTUAL current behavior of manual
 * MemberModel::changeStatus() vs automatic MemberModel::syncDormantStatus()
 * for all 9 manual-target x pre/post-sync combinations, against a
 * disposable clone of production data. This script asserts nothing and
 * fails nothing -- it prints narrative before/after state so the Stage 6-B
 * report can quote real, reproduced results rather than inferred ones.
 *
 * TARGETS A DISPOSABLE CLONE (argv[1]) ONLY. Never touches empower_db.
 * Delete this file (or leave it -- it is inert without an explicit
 * disposable db name argument) once Stage 6-B's report is written.
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

echo "=== STAGE 6-B SIMULATION -- TARGET: " . DB_NAME . " (disposable clone) ===\n";
$pdo = Database::getInstance()->getConnection();
$memberModel = new MemberModel();
$ADMIN = 1;

function makeMember(MemberModel $mm, int $admin, string $tag, string $joinOffset): int {
    static $seq = 0; $seq++;
    $m = $mm->createWithCompulsoryAccount([
        'member_number' => $mm->generateMemberNumber(),
        'first_name' => 'Stage6bSim', 'last_name' => "M{$tag}{$seq}", 'gender' => 'Female',
        'phone' => '07000009' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT),
        'national_id' => "S6BSIM{$tag}{$seq}",
        'join_date' => date('Y-m-d', strtotime($joinOffset)), 'status' => 'active',
    ], $admin);
    return $m['member_id'];
}
function status(PDO $pdo, int $id): string {
    return (string)$pdo->query("SELECT status FROM members WHERE id={$id}")->fetchColumn();
}
function lastLog(PDO $pdo): string {
    $row = $pdo->query("SELECT action, description FROM activity_logs ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    return $row ? "{$row['action']}: {$row['description']}" : '(none)';
}
function scenario(int $n, string $title): void { echo "\n--- Scenario {$n}: {$title} ---\n"; }
function step(string $label, string $value): void { echo "  {$label}: {$value}\n"; }

// A member joined 8 months ago with NO qualifying savings activity --
// under the live default policy (dormancy_months=6, min_monthly_deposits=2,
// min_monthly_savings=40000, confirmed live, no override rows in settings)
// this member's computed-correct status is 'dormant'.
$STALE_JOIN = '-8 months';
// A member joined 1 month ago -- computed-correct status is 'active'
// (within the dormancy window regardless of activity).
$FRESH_JOIN = '-1 month';

scenario(1, 'Active member manually set to Dormant (contrary to computed reality -- member is actually FRESH/within-policy)');
$id1 = makeMember($memberModel, $ADMIN, 'S1', $FRESH_JOIN);
step('Before manual change', status($pdo, $id1));
$memberModel->changeStatus($id1, 'dormant');
step('After manual set to dormant', status($pdo, $id1));
$memberModel->syncDormantStatus();
step('After syncDormantStatus() runs', status($pdo, $id1));
step('Last activity_logs entry', lastLog($pdo));

scenario(2, 'Dormant member manually set to Active (contrary to computed reality -- member is actually STALE/no qualifying activity)');
$id2 = makeMember($memberModel, $ADMIN, 'S2', $STALE_JOIN);
$memberModel->syncDormantStatus(); // let it become genuinely dormant first
step('Before manual change (already dormant via real sync)', status($pdo, $id2));
$memberModel->changeStatus($id2, 'active');
step('After manual set to active', status($pdo, $id2));
$memberModel->syncDormantStatus();
step('After syncDormantStatus() runs again', status($pdo, $id2));
step('Last activity_logs entry', lastLog($pdo));

scenario(3, 'Active member manually set to Inactive');
$id3 = makeMember($memberModel, $ADMIN, 'S3', $FRESH_JOIN);
step('Before manual change', status($pdo, $id3));
$memberModel->changeStatus($id3, 'inactive');
step('After manual set to inactive', status($pdo, $id3));
$memberModel->syncDormantStatus();
step('After syncDormantStatus() runs', status($pdo, $id3));

scenario(4, 'Inactive member manually set to Active');
$id4 = makeMember($memberModel, $ADMIN, 'S4', $STALE_JOIN);
$memberModel->changeStatus($id4, 'inactive');
step('Before manual change (was inactive)', status($pdo, $id4));
$memberModel->changeStatus($id4, 'active');
step('After manual set to active', status($pdo, $id4));
$memberModel->syncDormantStatus();
step('After syncDormantStatus() runs (member is STALE -- no qualifying activity)', status($pdo, $id4));

scenario(5, 'Inactive member manually set to Dormant');
$id5 = makeMember($memberModel, $ADMIN, 'S5', $FRESH_JOIN);
$memberModel->changeStatus($id5, 'inactive');
step('Before manual change (was inactive)', status($pdo, $id5));
$memberModel->changeStatus($id5, 'dormant');
step('After manual set to dormant', status($pdo, $id5));
$memberModel->syncDormantStatus();
step('After syncDormantStatus() runs (member is FRESH -- within policy)', status($pdo, $id5));

scenario(6, 'Dormant member manually set to Inactive');
$id6 = makeMember($memberModel, $ADMIN, 'S6', $STALE_JOIN);
$memberModel->syncDormantStatus();
step('Before manual change (already dormant via real sync)', status($pdo, $id6));
$memberModel->changeStatus($id6, 'inactive');
step('After manual set to inactive', status($pdo, $id6));
$memberModel->syncDormantStatus();
step('After syncDormantStatus() runs', status($pdo, $id6));

scenario(7, '[explicit repeat, same-request pacing] Auto sync run IMMEDIATELY after manual Dormant, no other action in between');
$id7 = makeMember($memberModel, $ADMIN, 'S7', $FRESH_JOIN);
$memberModel->changeStatus($id7, 'dormant');
$before7 = status($pdo, $id7);
$memberModel->syncDormantStatus();
$after7 = status($pdo, $id7);
step('Immediately after manual set', $before7);
step('Immediately after the very next sync call', $after7);
step('Changed on the very next sync call?', $before7 !== $after7 ? 'YES -- overridden' : 'no -- held');

scenario(8, '[explicit repeat] Auto sync run IMMEDIATELY after manual Active on a stale member');
$id8 = makeMember($memberModel, $ADMIN, 'S8', $STALE_JOIN);
$memberModel->syncDormantStatus();
$memberModel->changeStatus($id8, 'active');
$before8 = status($pdo, $id8);
$memberModel->syncDormantStatus();
$after8 = status($pdo, $id8);
step('Immediately after manual set', $before8);
step('Immediately after the very next sync call', $after8);
step('Changed on the very next sync call?', $before8 !== $after8 ? 'YES -- overridden' : 'no -- held');

scenario(9, '[explicit repeat] Auto sync run IMMEDIATELY after manual Inactive (protection check)');
$id9 = makeMember($memberModel, $ADMIN, 'S9', $STALE_JOIN);
$memberModel->changeStatus($id9, 'inactive');
$before9 = status($pdo, $id9);
$memberModel->syncDormantStatus();
$after9 = status($pdo, $id9);
step('Immediately after manual set', $before9);
step('Immediately after the very next sync call', $after9);
step('Changed on the very next sync call?', $before9 !== $after9 ? 'YES -- overridden' : 'no -- held (protected)');

echo "\n=== SIMULATION COMPLETE ===\n";
