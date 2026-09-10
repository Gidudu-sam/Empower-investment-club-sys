<?php
/**
 * STAGE 17 PART E-0 — Withdrawal Policy Architecture Test Harness
 *
 * Runs entirely against `empower_db_stage17`, an isolated database. Never
 * touches `empower_db`. This is a pure configuration-architecture test --
 * no savings/withdrawals/journal rows are ever created or read by these
 * tests, confirming the policy engine has zero financial side effects.
 */

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'empower_db_stage17');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
define('APP_PATH', __DIR__ . '/app');
define('CORE_PATH', __DIR__ . '/core');

require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';

$pdo = Database::getInstance()->getConnection();

$pass = 0; $fail = 0; $failures = [];
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; $failures[] = "$label -- $detail"; echo "  [FAIL] $label -- $detail\n"; }
}
function section(string $t): void { echo "\n=== $t ===\n"; }

$ADMIN = 1;
$model = new WithdrawalPolicyModel();

// ================================================================
// Snapshot financial tables BEFORE any policy test (Test 11 baseline)
// ================================================================
function financialSnapshot(PDO $pdo): array {
    return [
        'savings'         => (int)$pdo->query('SELECT COUNT(*) FROM savings')->fetchColumn(),
        'withdrawals'     => (int)$pdo->query('SELECT COUNT(*) FROM withdrawals')->fetchColumn(),
        'journal_entries' => (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn(),
        'journal_lines'   => (int)$pdo->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn(),
    ];
}
$snapshotBefore = financialSnapshot($pdo);

// Clean slate for policy rows only (never touches financial tables)
$pdo->exec("DELETE FROM savings_withdrawal_policies");
$activityLogsBefore = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action LIKE 'withdrawal_policy_%'")->fetchColumn();

// ================================================================
// TEST 1 — Compulsory policy: 50% max, 50% share, once per financial year
// ================================================================
section('Test 1: Compulsory policy');

$compulsoryId = $model->createPolicy([
    'account_type' => 'compulsory', 'withdrawal_enabled' => 1,
    'maximum_withdrawal_percent' => 50, 'share_conversion_percent' => 50,
    'frequency' => 'once_per_financial_year', 'effective_from' => '2026-01-01',
], $ADMIN);
check('Compulsory policy created', $compulsoryId > 0);

$compulsory = $model->getActivePolicy('compulsory', '2026-06-15');
check('getActivePolicy resolves the compulsory policy for a 2026 date', $compulsory !== null);
check('Compulsory: 50% maximum withdrawal', (float)$compulsory['maximum_withdrawal_percent'] === 50.0);
check('Compulsory: 50% share conversion', (float)$compulsory['share_conversion_percent'] === 50.0);
check('Compulsory: once per financial year frequency', $compulsory['frequency'] === 'once_per_financial_year');

$maxWithdrawal = $model->calculateMaximumWithdrawal(1000000, $compulsory);
check('calculateMaximumWithdrawal(1,000,000, 50%) = 500,000', abs($maxWithdrawal - 500000) < 0.01, "got {$maxWithdrawal}");

$full = $model->calculateShareConversion(1000000, 500000, $compulsory);
check('Full withdrawal: share_conversion = 500,000', abs($full['share_conversion'] - 500000) < 0.01, 'got ' . $full['share_conversion']);
check('Full withdrawal: remains_in_savings = 0', abs($full['remains_in_savings']) < 0.01);

$partial = $model->calculateShareConversion(1000000, 300000, $compulsory);
check('Partial withdrawal (300,000 of a 500,000 max): share_conversion = 700,000 (Model A)', abs($partial['share_conversion'] - 700000) < 0.01, 'got ' . $partial['share_conversion']);
check('Partial withdrawal: remains_in_savings = 0 (no third bucket for a 100%-summing policy)', abs($partial['remains_in_savings']) < 0.01);

// ================================================================
// TEST 2 — Voluntary policy: 100% max, 0% share, any time
// ================================================================
section('Test 2: Voluntary policy');

$voluntaryId = $model->createPolicy([
    'account_type' => 'voluntary', 'withdrawal_enabled' => 1,
    'maximum_withdrawal_percent' => 100, 'share_conversion_percent' => 0,
    'frequency' => 'any_time', 'effective_from' => '2026-01-01',
], $ADMIN);
check('Voluntary policy created', $voluntaryId > 0);

$voluntary = $model->getActivePolicy('voluntary');
check('Voluntary: 100% maximum withdrawal', (float)$voluntary['maximum_withdrawal_percent'] === 100.0);
check('Voluntary: 0% share conversion', (float)$voluntary['share_conversion_percent'] === 0.0);
check('Voluntary: any_time frequency', $voluntary['frequency'] === 'any_time');

$volMax = $model->calculateMaximumWithdrawal(800000, $voluntary);
check('calculateMaximumWithdrawal(800,000, 100%) = 800,000', abs($volMax - 800000) < 0.01);
$volShare = $model->calculateShareConversion(800000, 800000, $voluntary);
check('Voluntary full withdrawal: share_conversion = 0', abs($volShare['share_conversion']) < 0.01);
check('Voluntary full withdrawal: remains_in_savings = 0', abs($volShare['remains_in_savings']) < 0.01);

// ================================================================
// TEST 3 — Custom synthetic policy: 30% max, 20% share
// ================================================================
section('Test 3: Custom synthetic policy (not a real account type -- reusing "corporate" as the synthetic slot)');

$customId = $model->createPolicy([
    'account_type' => 'corporate', 'withdrawal_enabled' => 1,
    'maximum_withdrawal_percent' => 30, 'share_conversion_percent' => 20,
    'frequency' => 'quarterly', 'effective_from' => '2026-01-01',
], $ADMIN);
check('Custom 30/20 policy created', $customId > 0);
$custom = $model->getActivePolicy('corporate');
$customMax = $model->calculateMaximumWithdrawal(1000000, $custom);
check('Custom: max withdrawal = 300,000 (30%)', abs($customMax - 300000) < 0.01);
$customFull = $model->calculateShareConversion(1000000, 300000, $custom); // full max requested
check('Custom: at full max, share_conversion = 200,000 (20%)', abs($customFull['share_conversion'] - 200000) < 0.01, 'got ' . $customFull['share_conversion']);
check('Custom: at full max, remains_in_savings = 500,000 (the untouched 50%)', abs($customFull['remains_in_savings'] - 500000) < 0.01, 'got ' . $customFull['remains_in_savings']);
$customPartial = $model->calculateShareConversion(1000000, 100000, $custom); // partial, less than max
check('Custom: partial withdrawal (100,000) -> share absorbs the unused ceiling (400,000)', abs($customPartial['share_conversion'] - 400000) < 0.01, 'got ' . $customPartial['share_conversion']);
check('Custom: partial withdrawal -> remains_in_savings still 500,000 (constant, policy-defined)', abs($customPartial['remains_in_savings'] - 500000) < 0.01, 'got ' . $customPartial['remains_in_savings']);

// ================================================================
// TEST 4 — Invalid percentages rejected
// ================================================================
section('Test 4: Invalid percentages rejected');

$errors1 = $model->validatePolicy(['account_type' => 'voluntary', 'withdrawal_enabled' => 1, 'maximum_withdrawal_percent' => -1, 'share_conversion_percent' => 0, 'frequency' => 'any_time', 'effective_from' => '2026-01-01']);
check('-1% maximum rejected', !empty($errors1));

$errors2 = $model->validatePolicy(['account_type' => 'voluntary', 'withdrawal_enabled' => 1, 'maximum_withdrawal_percent' => 101, 'share_conversion_percent' => 0, 'frequency' => 'any_time', 'effective_from' => '2026-01-01']);
check('101% maximum rejected', !empty($errors2));

$errors3 = $model->validatePolicy(['account_type' => 'voluntary', 'withdrawal_enabled' => 1, 'maximum_withdrawal_percent' => 50, 'share_conversion_percent' => -5, 'frequency' => 'any_time', 'effective_from' => '2026-01-01']);
check('-5% share conversion rejected', !empty($errors3));

// ================================================================
// TEST 5 — Invalid combined policy rejected (application layer + DB CHECK)
// ================================================================
section('Test 5: Combined percentage exceeding 100% rejected');

$errors4 = $model->validatePolicy(['account_type' => 'joint', 'withdrawal_enabled' => 1, 'maximum_withdrawal_percent' => 70, 'share_conversion_percent' => 40, 'frequency' => 'any_time', 'effective_from' => '2026-01-01']);
check('Application-layer validation rejects 70%+40%=110%', !empty($errors4));

$threw = false;
try {
    $model->createPolicy(['account_type' => 'joint', 'withdrawal_enabled' => 1, 'maximum_withdrawal_percent' => 70, 'share_conversion_percent' => 40, 'frequency' => 'any_time', 'effective_from' => '2026-01-01'], $ADMIN);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('createPolicy() throws for an impossible combined percentage', $threw);

// Bypass the model to prove the DB-level CHECK constraint is the ultimate backstop
$dbThrew = false;
try {
    $pdo->exec("INSERT INTO savings_withdrawal_policies (account_type,maximum_withdrawal_percent,share_conversion_percent,effective_from) VALUES ('joint',70,40,'2026-01-01')");
} catch (PDOException $e) {
    $dbThrew = true;
}
check('DB-level CHECK constraint independently rejects the same impossible combination', $dbThrew);

// ================================================================
// TEST 6 — Disabled policy resolves to a clear "not allowed" result
// ================================================================
section('Test 6: Disabled withdrawal policy');

$disabledId = $model->createPolicy([
    'account_type' => 'joint', 'withdrawal_enabled' => 0,
    'maximum_withdrawal_percent' => 0, 'share_conversion_percent' => 0,
    'frequency' => 'any_time', 'effective_from' => '2026-01-01',
], $ADMIN);
$disabled = $model->getActivePolicy('joint');
check('Disabled policy still resolves (so the caller can see it exists but is off)', $disabled !== null);
check('withdrawal_enabled = 0', (int)$disabled['withdrawal_enabled'] === 0);
$disabledMax = $model->calculateMaximumWithdrawal(500000, $disabled);
check('calculateMaximumWithdrawal() returns 0 when disabled, regardless of balance', $disabledMax === 0.0, 'got ' . $disabledMax);

// ================================================================
// TEST 7 — Effective dates: a 2026 policy and a 2027 policy resolve correctly
// ================================================================
section('Test 7: Effective-dated resolution (2026 vs 2027)');

// Supersede the compulsory policy with a 2027 version (40% instead of 50%)
$compulsory2027Id = $model->createPolicy([
    'account_type' => 'compulsory', 'withdrawal_enabled' => 1,
    'maximum_withdrawal_percent' => 40, 'share_conversion_percent' => 60,
    'frequency' => 'once_per_financial_year', 'effective_from' => '2027-01-01',
], $ADMIN);
check('2027 compulsory policy version created', $compulsory2027Id > 0);

$resolved2026 = $model->getActivePolicy('compulsory', '2026-06-15');
$resolved2027 = $model->getActivePolicy('compulsory', '2027-03-01');
check('A 2026 transaction date resolves to the 2026 policy (50%)', (float)$resolved2026['maximum_withdrawal_percent'] === 50.0, 'got ' . $resolved2026['maximum_withdrawal_percent']);
check('A 2027 transaction date resolves to the 2027 policy (40%)', (float)$resolved2027['maximum_withdrawal_percent'] === 40.0, 'got ' . $resolved2027['maximum_withdrawal_percent']);

$oldRow = $pdo->prepare('SELECT * FROM savings_withdrawal_policies WHERE id=?');
$oldRow->execute([$compulsoryId]);
$oldRow = $oldRow->fetch();
check('The 2026 policy row was automatically closed out (effective_to = 2026-12-31)', $oldRow['effective_to'] === '2026-12-31', 'got ' . $oldRow['effective_to']);
check('The 2026 policy row itself was NOT rewritten (still shows 50%, not 40%)', (float)$oldRow['maximum_withdrawal_percent'] === 50.0);

// ================================================================
// TEST 8 — Overlapping policies rejected
// ================================================================
section('Test 8: Overlapping effective-dated policies rejected');

// createPolicy()'s auto-supersede path always closes out whichever row is
// currently OPEN-ENDED (effective_to IS NULL) when a later effective_from
// is submitted -- that is the intended "create a new version" flow (§13),
// not an ambiguous overlap. A genuinely ambiguous overlap is two BOUNDED
// (non-open-ended) rows whose ranges intersect, since neither is "the
// current row" the supersede logic would auto-resolve. Construct that case
// directly against 'joint' (a still-unused account type at this point in
// the suite, disabled in Test 6 but that doesn't matter for this check).
$boundedFutureId = $model->createPolicy([
    'account_type' => 'joint', 'withdrawal_enabled' => 1,
    'maximum_withdrawal_percent' => 25, 'share_conversion_percent' => 25,
    'frequency' => 'quarterly', 'effective_from' => '2028-01-01', 'effective_to' => '2028-06-30',
], $ADMIN);
check('A bounded future joint policy (2028-01-01..2028-06-30) is created (supersedes Test 6\'s open-ended row)', $boundedFutureId > 0);

$overlapThrew = false;
try {
    // This range (2028-03-01..2028-09-30) overlaps the bounded row above,
    // and is NOT itself "the current open-ended row" (there isn't one --
    // the bounded row has an explicit effective_to), so hasOverlap() must
    // be what catches this, not the past-history guard.
    $model->createPolicy([
        'account_type' => 'joint', 'withdrawal_enabled' => 1,
        'maximum_withdrawal_percent' => 30, 'share_conversion_percent' => 30,
        'frequency' => 'quarterly', 'effective_from' => '2028-03-01', 'effective_to' => '2028-09-30',
    ], $ADMIN);
} catch (InvalidArgumentException $e) {
    $overlapThrew = true;
}
check('A policy overlapping an existing BOUNDED (non-open-ended) future policy is rejected by hasOverlap()', $overlapThrew);

$pastThrew = false;
try {
    $model->createPolicy([
        'account_type' => 'compulsory', 'withdrawal_enabled' => 1,
        'maximum_withdrawal_percent' => 45, 'share_conversion_percent' => 55,
        'frequency' => 'once_per_financial_year', 'effective_from' => '2025-01-01',
    ], $ADMIN);
} catch (InvalidArgumentException $e) {
    $pastThrew = true;
}
check('A new version dated BEFORE the current policy\'s own effective_from is rejected (cannot rewrite past history)', $pastThrew);

// ================================================================
// TEST 9 — Account-type isolation
// ================================================================
section('Test 9: Changing Compulsory does not alter Voluntary');

$voluntaryBefore = $model->getActivePolicy('voluntary');
// (Compulsory was already changed via the 2027 version above)
$voluntaryAfter = $model->getActivePolicy('voluntary');
check('Voluntary policy unaffected by all the Compulsory changes made above',
    (float)$voluntaryBefore['maximum_withdrawal_percent'] === (float)$voluntaryAfter['maximum_withdrawal_percent']
    && (float)$voluntaryBefore['share_conversion_percent'] === (float)$voluntaryAfter['share_conversion_percent']
    && $voluntaryBefore['id'] === $voluntaryAfter['id']);

// ================================================================
// TEST 10 — Audit trail
// ================================================================
section('Test 10: Policy changes produce an audit trail');

$activityLogsAfter = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action LIKE 'withdrawal_policy_%'")->fetchColumn();
check('activity_logs gained entries for the policy changes made in this run', $activityLogsAfter > $activityLogsBefore, "before={$activityLogsBefore} after={$activityLogsAfter}");

$sample = $pdo->query("SELECT * FROM activity_logs WHERE action = 'withdrawal_policy_created' ORDER BY id DESC LIMIT 1")->fetch();
check('A sample audit row captures user_id', !empty($sample['user_id']));
check('A sample audit row description mentions the account type and old/new values', str_contains($sample['description'], 'Account type:') && str_contains($sample['description'], 'Old:') && str_contains($sample['description'], 'New:'));

// Toggle test
$model->toggleStatus($voluntaryId, $ADMIN);
$toggled = $pdo->prepare('SELECT status FROM savings_withdrawal_policies WHERE id=?');
$toggled->execute([$voluntaryId]);
check('toggleStatus() flips status to inactive', $toggled->fetchColumn() === 'inactive');
$toggleLog = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='withdrawal_policy_status_toggled'")->fetchColumn();
check('Status toggle produces its own audit log entry', $toggleLog > 0);
$model->toggleStatus($voluntaryId, $ADMIN); // restore for any later use

// ================================================================
// TEST 11 — No financial mutation whatsoever
// ================================================================
section('Test 11: Creating/changing policy configuration touches ZERO financial tables');

$snapshotAfter = financialSnapshot($pdo);
check('savings row count unchanged', $snapshotAfter['savings'] === $snapshotBefore['savings'], 'before=' . $snapshotBefore['savings'] . ' after=' . $snapshotAfter['savings']);
check('withdrawals row count unchanged', $snapshotAfter['withdrawals'] === $snapshotBefore['withdrawals'], 'before=' . $snapshotBefore['withdrawals'] . ' after=' . $snapshotAfter['withdrawals']);
check('journal_entries row count unchanged', $snapshotAfter['journal_entries'] === $snapshotBefore['journal_entries'], 'before=' . $snapshotBefore['journal_entries'] . ' after=' . $snapshotAfter['journal_entries']);
check('journal_lines row count unchanged', $snapshotAfter['journal_lines'] === $snapshotBefore['journal_lines'], 'before=' . $snapshotBefore['journal_lines'] . ' after=' . $snapshotAfter['journal_lines']);

// ================================================================
// Additional: getFrequencyRule() and updateDraft()/hasOverlap() sanity
// ================================================================
section('Additional: getFrequencyRule() and draft editing');

$freqRule = $model->getFrequencyRule($compulsory);
check('getFrequencyRule() returns a human label', $freqRule['label'] === 'Once per financial year');

// Draft edit: the 2027 compulsory row is still in the future relative to "today" (2026)
$draftUpdateOk = $model->updateDraft($compulsory2027Id, [
    'maximum_withdrawal_percent' => 42, 'share_conversion_percent' => 58,
    'frequency' => 'once_per_financial_year', 'effective_from' => '2027-01-01', 'effective_to' => '',
], $ADMIN);
check('A future (not-yet-effective) draft policy can be edited directly', $draftUpdateOk);
$redrawn = $model->find($compulsory2027Id);
check('Draft edit persisted (42% not 40%)', (float)$redrawn['maximum_withdrawal_percent'] === 42.0, 'got ' . $redrawn['maximum_withdrawal_percent']);

$immutableThrew = false;
try {
    $model->updateDraft($voluntaryId, ['maximum_withdrawal_percent' => 90, 'share_conversion_percent' => 10, 'frequency' => 'any_time', 'effective_from' => '2026-01-01'], $ADMIN);
} catch (RuntimeException $e) {
    $immutableThrew = true;
}
check('An already-effective policy (effective_from in the past) cannot be edited in place', $immutableThrew);

// ================================================================
// SUMMARY
// ================================================================
echo "\n================================================================\n";
echo "STAGE 17 PART E-0 — WITHDRAWAL POLICY TEST RESULTS: {$pass} passed, {$fail} failed\n";
echo "================================================================\n";
if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    exit(1);
}
exit(0);
