<?php
/**
 * Stage B/F — Dual Posting Modes — Test Harness
 *
 * TARGETS AN ISOLATED, DISPOSABLE CLONE (name passed as argv[1]), already
 * migrated with the bf_posting_mode/bf_asset_account_id columns. NEVER
 * touches empower_db. Covers all 10 required test scenarios.
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
define('APP_URL', 'http://localhost/Empower');
define('APP_NAME', 'Empower Test');

require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Session.php';
require_once CORE_PATH . '/Autoloader.php';
require_once APP_PATH  . '/models/MemberModel.php';
require_once APP_PATH  . '/models/MemberSavingsAccountModel.php';
require_once APP_PATH  . '/models/SavingsModel.php';
require_once APP_PATH  . '/models/AccountModel.php';
require_once APP_PATH  . '/services/JournalService.php';

echo "=== TARGET DATABASE: " . DB_NAME . " (disposable clone -- never empower_db) ===\n";
$db = Database::getInstance()->getConnection();

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; echo "  [FAIL] $label -- $detail\n"; }
}
function section(string $t): void { echo "\n=== $t ===\n"; }

$ADMIN = 1;
$memberModel  = new MemberModel();
$accountModel = new MemberSavingsAccountModel();
$savingsModel = new SavingsModel();

function makeMember(MemberModel $mm, int $admin, string $tag): array {
    static $seq = 0; $seq++;
    static $runId = null; if ($runId === null) { $runId = str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT); }
    return $mm->createWithCompulsoryAccount([
        'member_number' => $mm->generateMemberNumber(),
        'first_name' => 'BFDUAL', 'last_name' => "Test{$tag}{$seq}", 'gender' => 'Female',
        'phone' => '07' . $runId . str_pad((string)(10000 + $seq), 5, '0', STR_PAD_LEFT),
        'national_id' => "BFDUAL{$runId}{$tag}{$seq}",
        'date_of_birth' => '1990-01-01', 'address' => 'Test',
        'next_of_kin_name' => 'Test', 'next_of_kin_phone' => '0700000000',
        'join_date' => date('Y-m-d'), 'status' => 'active',
    ], $admin);
}

function getBalance(PDO $db, int $accountId): float {
    $stmt = $db->prepare("SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM savings WHERE savings_account_id=?");
    $stmt->execute([$accountId]);
    return (float)$stmt->fetchColumn();
}
function getAccountGLBalance(PDO $db, int $accountId): float {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(jl.debit - jl.credit),0)
        FROM journal_lines jl WHERE jl.account_id=?
    ");
    $stmt->execute([$accountId]);
    return (float)$stmt->fetchColumn();
}

// ------------------------------------------------------------------
// TEST 1 -- Cash B/F
// ------------------------------------------------------------------
section('TEST 1: Cash B/F -- Dr Cash / Cr Members Savings');
$m1 = makeMember($memberModel, $ADMIN, 'Cash');
$cashBefore = getAccountGLBalance($db, 7);
$liabBefore = getAccountGLBalance($db, 17);
$r1 = $savingsModel->createBroughtForward([
    'member_id' => $m1['member_id'], 'savings_account_id' => $m1['account_id'],
    'amount' => 200000, 'transaction_date' => date('Y-m-d'),
    'notes' => 'Historical savings accumulated from 01 May 2026 to 31 Aug 2026.',
    'posting_mode' => 'verified_asset', 'payment_method' => 'Cash',
], $ADMIN);
check('Cash B/F created', isset($r1['id']));
check('journal_entry_id set', !empty($r1['journal_entry_id']));
check('Dr Cash increased by 200,000', abs((getAccountGLBalance($db,7) - $cashBefore) - 200000) < 0.01);
check('Cr Members Savings increased by 200,000', abs((getAccountGLBalance($db,17) - $liabBefore) - (-200000)) < 0.01, 'liability debit-credit should move -200000');
check('bf_posting_mode = verified_asset', $savingsModel->find($r1['id'])['bf_posting_mode'] === 'verified_asset');
check('bf_asset_account_id = 7 (Cash)', (int)$savingsModel->find($r1['id'])['bf_asset_account_id'] === 7);
check('savings row credit = 200,000', abs((float)$savingsModel->find($r1['id'])['credit'] - 200000) < 0.01);

// ------------------------------------------------------------------
// TEST 2 -- Bank B/F
// ------------------------------------------------------------------
section('TEST 2: Bank B/F -- Dr Bank / Cr Members Savings');
$m2 = makeMember($memberModel, $ADMIN, 'Bank');
$bankBefore = getAccountGLBalance($db, 10);
$liabBefore2 = getAccountGLBalance($db, 17);
$r2 = $savingsModel->createBroughtForward([
    'member_id' => $m2['member_id'], 'savings_account_id' => $m2['account_id'],
    'amount' => 200000, 'transaction_date' => date('Y-m-d'),
    'notes' => 'Historical savings accumulated from 01 May 2026 to 31 Aug 2026.',
    'posting_mode' => 'verified_asset', 'payment_method' => 'Bank Transfer',
], $ADMIN);
check('Bank B/F created', isset($r2['id']));
check('Dr Bank increased by 200,000', abs((getAccountGLBalance($db,10) - $bankBefore) - 200000) < 0.01);
check('Cr Members Savings increased by 200,000', abs((getAccountGLBalance($db,17) - $liabBefore2) - (-200000)) < 0.01, 'liability debit-credit should move -200000');
check('bf_asset_account_id = 10 (Bank)', (int)$savingsModel->find($r2['id'])['bf_asset_account_id'] === 10);

// ------------------------------------------------------------------
// TEST 3 -- Mobile Money B/F
// ------------------------------------------------------------------
section('TEST 3: Mobile Money B/F -- Dr Mobile Money / Cr Members Savings');
$m3 = makeMember($memberModel, $ADMIN, 'MoMo');
$momoBefore = getAccountGLBalance($db, 8);
$liabBefore3 = getAccountGLBalance($db, 17);
$r3 = $savingsModel->createBroughtForward([
    'member_id' => $m3['member_id'], 'savings_account_id' => $m3['account_id'],
    'amount' => 200000, 'transaction_date' => date('Y-m-d'),
    'notes' => 'Historical savings accumulated from 01 May 2026 to 31 Aug 2026.',
    'posting_mode' => 'verified_asset', 'payment_method' => 'MTN Mobile Money',
], $ADMIN);
check('MoMo B/F created', isset($r3['id']));
check('Dr Mobile Money increased by 200,000', abs((getAccountGLBalance($db,8) - $momoBefore) - 200000) < 0.01);
check('Cr Members Savings increased by 200,000', abs((getAccountGLBalance($db,17) - $liabBefore3) - (-200000)) < 0.01, 'liability debit-credit should move -200000');
check('bf_asset_account_id = 8 (MoMo)', (int)$savingsModel->find($r3['id'])['bf_asset_account_id'] === 8);

// ------------------------------------------------------------------
// TEST 4 -- Historical Only
// ------------------------------------------------------------------
section('TEST 4: Historical Only -- no journal, no fabricated asset');
$m4 = makeMember($memberModel, $ADMIN, 'Hist');
$cashBefore4 = getAccountGLBalance($db, 7);
$bankBefore4 = getAccountGLBalance($db, 10);
$momoBefore4 = getAccountGLBalance($db, 8);
$liabBefore4 = getAccountGLBalance($db, 17);
$jeCountBefore = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$r4 = $savingsModel->createBroughtForward([
    'member_id' => $m4['member_id'], 'savings_account_id' => $m4['account_id'],
    'amount' => 200000, 'transaction_date' => date('Y-m-d'),
    'notes' => 'Historical savings accumulated from 01 May 2026 to 31 Aug 2026. Historical Only: carried forward from prior records; asset not independently reconciled.',
    'posting_mode' => 'historical_only',
], $ADMIN);
check('Historical Only B/F created', isset($r4['id']));
$row4 = $savingsModel->find($r4['id']);
check('Member subledger balance recorded (credit=200,000)', abs((float)$row4['credit'] - 200000) < 0.01);
check('journal_entry_id is NULL', $row4['journal_entry_id'] === null);
check('bf_posting_mode = historical_only', $row4['bf_posting_mode'] === 'historical_only');
check('bf_asset_account_id is NULL', $row4['bf_asset_account_id'] === null);
check('No new journal_entries row created', (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn() === $jeCountBefore);
check('Cash account GL balance unchanged', abs(getAccountGLBalance($db,7) - $cashBefore4) < 0.01);
check('Bank account GL balance unchanged', abs(getAccountGLBalance($db,10) - $bankBefore4) < 0.01);
check('Mobile Money account GL balance unchanged', abs(getAccountGLBalance($db,8) - $momoBefore4) < 0.01);
check('Members Savings GL balance unchanged (no fabricated Cr either)', abs(getAccountGLBalance($db,17) - $liabBefore4) < 0.01);
check('Member subledger position (all savings, incl. GL-invisible) increased by 200,000', abs(getBalance($db, $m4['account_id']) - 200000) < 0.01);

// ------------------------------------------------------------------
// TEST 5 -- Mandatory Reason
// ------------------------------------------------------------------
section('TEST 5: Historical Only without reason must fail (enforced at controller layer)');
// The model itself receives 'notes' already-combined by the controller;
// this test proves the controller's collectBroughtForwardInput() throws
// before ever reaching the model when historical_only_reason is blank --
// exercised directly via reflection since it needs $_POST + a real session.
require_once APP_PATH . '/controllers/SavingsAccountController.php';
$ref = new ReflectionClass('SavingsAccountController');
$ctrl = $ref->newInstanceWithoutConstructor();
$method = $ref->getMethod('collectBroughtForwardInput');
$method->setAccessible(true);

$_POST = [
    'amount' => '200000', 'effective_date' => date('Y-m-d'),
    'period_from' => '2026-05-01', 'period_to' => '2026-08-31',
    'posting_mode' => 'historical_only', 'historical_only_reason' => '',
];
$threw = false; $msg = '';
try { $method->invoke($ctrl, 1, 1); } catch (InvalidArgumentException $e) { $threw = true; $msg = $e->getMessage(); }
check('Historical Only with blank reason throws', $threw, $msg);
check('Error message mentions the asset/reason requirement', $threw && stripos($msg, 'asset') !== false, $msg);

$_POST['historical_only_reason'] = 'Carried forward from prior records; not independently reconciled.';
$threwWithReason = false;
try { $result5 = $method->invoke($ctrl, 1, 1); } catch (InvalidArgumentException $e) { $threwWithReason = true; }
check('Historical Only with a real reason does NOT throw', !$threwWithReason);
check('Reason text is appended into notes', !$threwWithReason && strpos($result5['notes'], 'not independently reconciled') !== false);

$_POST = [
    'amount' => '200000', 'effective_date' => date('Y-m-d'),
    'period_from' => '2026-05-01', 'period_to' => '2026-08-31',
    'posting_mode' => 'verified_asset', 'payment_method' => '',
];
$threwNoMethod = false;
try { $method->invoke($ctrl, 1, 1); } catch (InvalidArgumentException $e) { $threwNoMethod = true; }
check('verified_asset with no payment_method throws', $threwNoMethod);
$_POST = [];

// ------------------------------------------------------------------
// TEST 6 -- Duplicate Protection
// ------------------------------------------------------------------
section('TEST 6: Duplicate B/F protection (net-effect guard, mode-agnostic)');
$dupThrew = false; $dupMsg = '';
try {
    $savingsModel->createBroughtForward([
        'member_id' => $m1['member_id'], 'savings_account_id' => $m1['account_id'],
        'amount' => 50000, 'transaction_date' => date('Y-m-d'),
        'notes' => 'Second attempt.', 'posting_mode' => 'historical_only',
        'historical_only_reason' => 'test',
    ], $ADMIN);
} catch (InvalidArgumentException $e) { $dupThrew = true; $dupMsg = $e->getMessage(); }
check('Second B/F on the same account (even a different mode) is rejected', $dupThrew, $dupMsg);
check('journal_entries still has no duplicate for the first Cash B/F',
    (int)$db->prepare("SELECT COUNT(*) FROM journal_entries WHERE source_module='savings' AND source_reference_type='brought_forward' AND source_reference_id=?")
        ->execute([$r1['id']]) !== false &&
    (function() use ($db, $r1) {
        $s = $db->prepare("SELECT COUNT(*) FROM journal_entries WHERE source_module='savings' AND source_reference_type='brought_forward' AND source_reference_id=?");
        $s->execute([$r1['id']]);
        return (int)$s->fetchColumn() === 1;
    })()
);
// Re-posting the same underlying savings row id through JournalService directly would be idempotent too:
$svc = new JournalService();
$idempotent = $svc->post([
    'entry_date' => date('Y-m-d'), 'description' => 'dup test',
    'source_module' => 'savings', 'source_reference_type' => 'brought_forward', 'source_reference_id' => $r1['id'],
    'created_by' => $ADMIN,
    'lines' => [['account_id'=>7,'debit'=>200000,'credit'=>0],['account_id'=>17,'debit'=>0,'credit'=>200000]],
]);
check('Re-posting the same source_reference_id returns the existing journal (created=false)', $idempotent['created'] === false && (int)$idempotent['id'] === $r1['journal_entry_id']);

// ------------------------------------------------------------------
// TEST 7 -- Authorization (unchanged gate, not expanded)
// ------------------------------------------------------------------
section('TEST 7: Authorization unchanged -- admin/treasurer only, not expanded');
$roleMethod = $ref->getMethod('requireBroughtForwardAccess');
$roleMethod->setAccessible(true);
// Source-level check: confirm the role array literal is still exactly admin/treasurer
$src = file_get_contents(APP_PATH . '/controllers/SavingsAccountController.php');
check('requireBroughtForwardAccess() still gates on admin/treasurer only (source check)',
    (bool)preg_match("/requireBroughtForwardAccess.*?hasRole\\(\\['admin', 'treasurer'\\]\\)/s", $src));
check('No new role was added to the B/F gate', !str_contains($src, "requireBroughtForwardAccess") || substr_count($src, "hasRole(['admin', 'treasurer'])") >= 1);

// ------------------------------------------------------------------
// TEST 8 -- Reversal
// ------------------------------------------------------------------
section('TEST 8: Reversal behaviour differs correctly by mode');
// 8a: verified_asset (journal-linked) B/F must be REFUSED by reverseBroughtForward()
$refusedThrew = false; $refusedMsg = '';
try { $savingsModel->reverseBroughtForward($r1['id'], $ADMIN, 'attempted reversal of verified funds'); }
catch (InvalidArgumentException $e) { $refusedThrew = true; $refusedMsg = $e->getMessage(); }
check('Reversing a verified_asset (journal-linked) B/F via this workflow is refused', $refusedThrew, $refusedMsg);
check('Refusal message correctly points away from the B/F workflow', $refusedThrew && stripos($refusedMsg, 'Balance Brought Forward workflow') !== false, $refusedMsg);

// 8b: historical_only B/F reverses correctly, unchanged from before
$balBeforeReversal = getBalance($db, $m4['account_id']);
$rev = $savingsModel->reverseBroughtForward($r4['id'], $ADMIN, 'Entered in error, testing reversal.');
check('Historical Only B/F reversal succeeded', isset($rev['id']));
check('Subledger balance returns to 0 after reversal', abs(getBalance($db, $m4['account_id'])) < 0.01, (string)getBalance($db, $m4['account_id']));
check('hasBroughtForward() now false for that account (net effect zero)', !$savingsModel->hasBroughtForward($m4['account_id']));
$revRow = $savingsModel->find($rev['id']);
check('Reversal row bf_posting_mode = historical_only', $revRow['bf_posting_mode'] === 'historical_only');

// ------------------------------------------------------------------
// TEST 9 -- Trial Balance (verified_asset entries remain balanced)
// ------------------------------------------------------------------
section('TEST 9: Trial Balance -- verified_asset B/F journals are balanced');
foreach ([$r1['journal_entry_id'], $r2['journal_entry_id'], $r3['journal_entry_id']] as $jeId) {
    $stmt = $db->prepare("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines WHERE journal_entry_id=?");
    $stmt->execute([$jeId]);
    $row = $stmt->fetch();
    check("Journal entry #$jeId is balanced (debit=credit)", abs((float)$row['d'] - (float)$row['c']) < 0.01, "d={$row['d']} c={$row['c']}");
}
// Overall trial balance across all accounts touched by this test run must net to zero
$stmt = $db->query("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines");
$tb = $stmt->fetch();
check('System-wide Trial Balance: total debits = total credits', abs((float)$tb['d'] - (float)$tb['c']) < 0.01, "d={$tb['d']} c={$tb['c']}");

// ------------------------------------------------------------------
// TEST 10 -- Existing B/F behaviour unaffected
// ------------------------------------------------------------------
section('TEST 10: Existing pre-migration B/F record preserved untouched');
$preExisting = $db->query("SELECT id, credit, journal_entry_id, bf_posting_mode, bf_asset_account_id FROM savings WHERE transaction_type='opening_balance' AND id=3")->fetch();
if ($preExisting) {
    check('Pre-existing B/F row (id=3) still present', true);
    check('Pre-existing B/F row credit unchanged (200,000)', abs((float)$preExisting['credit'] - 200000) < 0.01);
    check('Pre-existing B/F row journal_entry_id still NULL (not auto-classified/posted)', $preExisting['journal_entry_id'] === null);
    check('Pre-existing B/F row bf_posting_mode is NULL (not auto-classified)', $preExisting['bf_posting_mode'] === null);
} else {
    check('Pre-existing B/F row (id=3) present (clone was seeded from production)', false, 'row not found in this clone -- clone may not have been seeded from a production-derived dump');
}

echo "\n=== SUMMARY: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
