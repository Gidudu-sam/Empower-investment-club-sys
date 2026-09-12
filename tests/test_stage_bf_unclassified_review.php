<?php
/**
 * Stage B/F — Unclassified Review and Controlled Classification — Test Harness
 *
 * TARGETS AN ISOLATED, DISPOSABLE CLONE (name passed as argv[1]). NEVER
 * touches empower_db. Covers all 14 required test scenarios.
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
$savingsModel = new SavingsModel();

function makeMember(MemberModel $mm, int $admin, string $tag): array {
    static $seq = 0; $seq++;
    static $runId = null; if ($runId === null) { $runId = str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT); }
    return $mm->createWithCompulsoryAccount([
        'member_number' => $mm->generateMemberNumber(),
        'first_name' => 'BFUNCL', 'last_name' => "Test{$tag}{$seq}", 'gender' => 'Female',
        'phone' => '07' . $runId . str_pad((string)(10000 + $seq), 5, '0', STR_PAD_LEFT),
        'national_id' => "BFUNCL{$runId}{$tag}{$seq}",
        'date_of_birth' => '1990-01-01', 'address' => 'Test',
        'next_of_kin_name' => 'Test', 'next_of_kin_phone' => '0700000000',
        'join_date' => date('Y-m-d'), 'status' => 'active',
    ], $admin);
}

// Creates an unclassified B/F row directly (bypassing createBroughtForward's
// mode default, to simulate a pre-dual-mode legacy record such as the real
// production id=3 row -- exactly what this stage's workflow must handle).
function makeUnclassifiedBf(PDO $db, SavingsModel $sm, int $memberId, int $accountId, float $amount, int $admin): array {
    $receipt = $sm->generateReceiptNumber();
    $stmt = $db->prepare("INSERT INTO savings (member_id, savings_account_id, receipt_number, transaction_type, debit, credit, running_balance, payment_method, transaction_date, financial_year, recorded_by, description, notes, bf_posting_mode, bf_asset_account_id)
        VALUES (?, ?, ?, 'opening_balance', 0, ?, ?, 'Other', CURDATE(), YEAR(CURDATE()), ?, 'Balance Brought Forward — Historical Savings', 'Legacy pre-dual-mode B/F.', NULL, NULL)");
    $stmt->execute([$memberId, $accountId, $receipt, $amount, $amount, $admin]);
    return ['id' => (int)$db->lastInsertId(), 'receipt_number' => $receipt];
}

function glMovement(PDO $db, int $accountId): array {
    $stmt = $db->prepare("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines WHERE account_id=?");
    $stmt->execute([$accountId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// ------------------------------------------------------------------
// TEST 1 -- Unclassified B/F appears as unclassified
// ------------------------------------------------------------------
section('TEST 1: Unclassified B/F appears as unclassified');
$m1 = makeMember($memberModel, $ADMIN, 'A');
$bf1 = makeUnclassifiedBf($db, $savingsModel, $m1['member_id'], $m1['account_id'], 200000, $ADMIN);
$register = $savingsModel->listBroughtForwardRegister('unclassified');
$found1 = null;
foreach ($register as $r) { if ((int)$r['id'] === $bf1['id']) { $found1 = $r; break; } }
check('New unclassified B/F appears in the unclassified register', $found1 !== null);
check('bf_posting_mode is NULL', $found1 && $found1['bf_posting_mode'] === null);
check('Not present in the classified-only register', !in_array($bf1['id'], array_column($savingsModel->listBroughtForwardRegister('classified'), 'id')));

// ------------------------------------------------------------------
// TEST 2 -- Classify as Cash
// ------------------------------------------------------------------
section('TEST 2: Classify unclassified B/F as Cash');
$cashBefore = glMovement($db, 7);
$liabBefore = glMovement($db, 17);
$r2 = $savingsModel->classifyBroughtForward($bf1['id'], 'verified_asset', 'Cash', null, $ADMIN);
check('Classification succeeded', $r2['posting_mode'] === 'verified_asset');
$cashAfter = glMovement($db, 7);
$liabAfter = glMovement($db, 17);
check('Dr Cash increased by 200,000', abs(($cashAfter['d'] - $cashBefore['d']) - 200000) < 0.01);
check('Cr Members Savings increased by 200,000', abs(($liabAfter['c'] - $liabBefore['c']) - 200000) < 0.01);
$row2 = $savingsModel->find($bf1['id']);
check('bf_posting_mode now verified_asset', $row2['bf_posting_mode'] === 'verified_asset');
check('bf_asset_account_id = 7 (Cash)', (int)$row2['bf_asset_account_id'] === 7);
check('journal_entry_id now set', !empty($row2['journal_entry_id']));

// ------------------------------------------------------------------
// TEST 3 -- Classify as Bank
// ------------------------------------------------------------------
section('TEST 3: Classify unclassified B/F as Bank');
$m3 = makeMember($memberModel, $ADMIN, 'B');
$bf3 = makeUnclassifiedBf($db, $savingsModel, $m3['member_id'], $m3['account_id'], 150000, $ADMIN);
$bankBefore = glMovement($db, 10);
$r3 = $savingsModel->classifyBroughtForward($bf3['id'], 'verified_asset', 'Bank Transfer', null, $ADMIN);
$bankAfter = glMovement($db, 10);
check('Dr Bank increased by 150,000', abs(($bankAfter['d'] - $bankBefore['d']) - 150000) < 0.01);
check('bf_asset_account_id = 10 (Bank)', (int)$savingsModel->find($bf3['id'])['bf_asset_account_id'] === 10);

// ------------------------------------------------------------------
// TEST 4 -- Classify as Mobile Money
// ------------------------------------------------------------------
section('TEST 4: Classify unclassified B/F as Mobile Money');
$m4 = makeMember($memberModel, $ADMIN, 'C');
$bf4 = makeUnclassifiedBf($db, $savingsModel, $m4['member_id'], $m4['account_id'], 90000, $ADMIN);
$momoBefore = glMovement($db, 8);
$r4 = $savingsModel->classifyBroughtForward($bf4['id'], 'verified_asset', 'MTN Mobile Money', null, $ADMIN);
$momoAfter = glMovement($db, 8);
check('Dr Mobile Money increased by 90,000', abs(($momoAfter['d'] - $momoBefore['d']) - 90000) < 0.01);
check('bf_asset_account_id = 8 (MoMo)', (int)$savingsModel->find($bf4['id'])['bf_asset_account_id'] === 8);

// ------------------------------------------------------------------
// TEST 5 -- Classify as Historical Only
// ------------------------------------------------------------------
section('TEST 5: Classify unclassified B/F as Historical Only');
$m5 = makeMember($memberModel, $ADMIN, 'D');
$bf5 = makeUnclassifiedBf($db, $savingsModel, $m5['member_id'], $m5['account_id'], 75000, $ADMIN);
$jeCountBefore5 = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$r5 = $savingsModel->classifyBroughtForward($bf5['id'], 'historical_only', null, 'Carried forward from prior records; asset not independently verified.', $ADMIN);
check('Classification succeeded', $r5['posting_mode'] === 'historical_only');
$row5 = $savingsModel->find($bf5['id']);
check('journal_entry_id still NULL', $row5['journal_entry_id'] === null);
check('bf_asset_account_id still NULL', $row5['bf_asset_account_id'] === null);
check('No new journal_entries row', (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn() === $jeCountBefore5);
check('Reason appended to notes', str_contains($row5['notes'], 'not independently verified'));
check('Member subledger balance still 75,000 (unaffected by classification)', abs((float)$row5['credit'] - 75000) < 0.01);

// ------------------------------------------------------------------
// TEST 6 -- Historical Only requires a reason
// ------------------------------------------------------------------
section('TEST 6: Historical Only classification requires a reason');
$m6 = makeMember($memberModel, $ADMIN, 'E');
$bf6 = makeUnclassifiedBf($db, $savingsModel, $m6['member_id'], $m6['account_id'], 40000, $ADMIN);
$noReasonThrew = false;
try { $savingsModel->classifyBroughtForward($bf6['id'], 'historical_only', null, '', $ADMIN); }
catch (InvalidArgumentException $e) { $noReasonThrew = true; }
check('Blank reason is rejected', $noReasonThrew);
check('Record is still unclassified after the rejected attempt', $savingsModel->find($bf6['id'])['bf_posting_mode'] === null);

// ------------------------------------------------------------------
// TEST 7 -- Invalid/arbitrary GL account cannot be selected
// ------------------------------------------------------------------
section('TEST 7: Arbitrary Chart of Accounts account cannot be selected as the asset account');
$m7 = makeMember($memberModel, $ADMIN, 'F');
$bf7 = makeUnclassifiedBf($db, $savingsModel, $m7['member_id'], $m7['account_id'], 60000, $ADMIN);
$arbitraryRejected = false;
try {
    // account 37 = 4090 Membership/Registration Fees income account -- active, but not a Cash/Bank/MoMo asset account.
    $savingsModel->classifyBroughtForward($bf7['id'], 'verified_asset', 'Loan Processing Fee', null, $ADMIN);
} catch (InvalidArgumentException $e) { $arbitraryRejected = true; }
check('An arbitrary/non-approved payment method label is rejected', $arbitraryRejected);
$otherRejected = false;
try {
    // 'Other' is explicitly excluded from verified_asset -- reserved for historical_only.
    $savingsModel->classifyBroughtForward($bf7['id'], 'verified_asset', 'Other', null, $ADMIN);
} catch (InvalidArgumentException $e) { $otherRejected = true; }
check("'Other' is rejected as a verified_asset method (no real GL account behind it)", $otherRejected);

// ------------------------------------------------------------------
// TEST 8 -- Classification cannot be performed twice
// ------------------------------------------------------------------
section('TEST 8: Classification cannot be performed twice');
$doubleClassifyRejected = false; $doubleMsg = '';
try { $savingsModel->classifyBroughtForward($bf1['id'], 'historical_only', null, 'second attempt', $ADMIN); }
catch (InvalidArgumentException $e) { $doubleClassifyRejected = true; $doubleMsg = $e->getMessage(); }
check('Re-classifying an already-classified B/F (bf1, already Cash) is rejected', $doubleClassifyRejected, $doubleMsg);
check('Its bf_posting_mode is unchanged (still verified_asset, not overwritten)', $savingsModel->find($bf1['id'])['bf_posting_mode'] === 'verified_asset');

// ------------------------------------------------------------------
// TEST 9 -- A posted verified B/F cannot be silently edited
// ------------------------------------------------------------------
section('TEST 9: A posted (classified+journaled) B/F cannot be silently edited');
$editBlocked = false; $editMsg = '';
try { $savingsModel->update($bf1['id'], ['credit' => 999999]); }
catch (InvalidArgumentException $e) { $editBlocked = true; $editMsg = $e->getMessage(); }
check('Editing the amount of a classified/posted B/F is blocked', $editBlocked, $editMsg);
$modeEditBlocked = false;
try { $savingsModel->update($bf1['id'], ['bf_posting_mode' => 'historical_only']); }
catch (InvalidArgumentException $e) { $modeEditBlocked = true; }
check('Directly editing bf_posting_mode via update() is blocked (must go through classifyBroughtForward())', $modeEditBlocked);
$deleteBlocked = false;
try { $savingsModel->delete($bf1['id']); } catch (InvalidArgumentException $e) { $deleteBlocked = true; }
check('Deleting a classified B/F is blocked', $deleteBlocked);

// ------------------------------------------------------------------
// TEST 10 -- Classification creates an audit-log entry
// ------------------------------------------------------------------
section('TEST 10: Classification creates an audit-log entry');
$logCount = (int)$db->prepare("SELECT COUNT(*) FROM activity_logs WHERE action='bf_classified'")->execute()
    ? (function() use ($db) { $s=$db->query("SELECT COUNT(*) FROM activity_logs WHERE action='bf_classified'"); return (int)$s->fetchColumn(); })()
    : 0;
check('At least one bf_classified activity_log entry exists (from Tests 2-5 above)', $logCount >= 4, "count={$logCount}");
$logRow = $db->query("SELECT description FROM activity_logs WHERE action='bf_classified' ORDER BY id DESC LIMIT 1")->fetch();
check('Log entry description is populated with classification detail', $logRow && strlen($logRow['description']) > 10, json_encode($logRow));

// ------------------------------------------------------------------
// TEST 11 -- Unauthorized users cannot classify B/F
// ------------------------------------------------------------------
section('TEST 11: Authorization -- source-level confirmation, unchanged from B/F gate');
$src = file_get_contents(APP_PATH . '/controllers/SavingsAccountController.php');
check('bfClassifyForm() calls requireBroughtForwardAccess()', (bool)preg_match('/function bfClassifyForm.*?requireBroughtForwardAccess\(\)/s', $src));
check('bfClassifyStore() calls requireBroughtForwardAccess()', (bool)preg_match('/function bfClassifyStore.*?requireBroughtForwardAccess\(\)/s', $src));
check('bfRegister() calls requireBroughtForwardAccess()', (bool)preg_match('/function bfRegister.*?requireBroughtForwardAccess\(\)/s', $src));
check('requireBroughtForwardAccess() itself still gates on exactly admin/treasurer (unchanged, not widened)',
    (bool)preg_match("/function requireBroughtForwardAccess.*?hasRole\\(\\['admin', 'treasurer'\\]\\)/s", $src));

// ------------------------------------------------------------------
// TEST 12 -- Trial Balance remains balanced
// ------------------------------------------------------------------
section('TEST 12: Trial Balance remains balanced after verified-asset classifications');
foreach ([$r2, $r3, $r4] as $r) {
    $stmt = $db->prepare("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines WHERE journal_entry_id=?");
    $stmt->execute([$r['journal_entry_id']]);
    $row = $stmt->fetch();
    check("Journal entry #{$r['journal_entry_id']} is balanced", abs((float)$row['d'] - (float)$row['c']) < 0.01, "d={$row['d']} c={$row['c']}");
}
$tb = $db->query("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines")->fetch();
check('System-wide Trial Balance: total debits = total credits', abs((float)$tb['d'] - (float)$tb['c']) < 0.01, "d={$tb['d']} c={$tb['c']}");

// ------------------------------------------------------------------
// TEST 13 -- Member statement/subledger remains correct after classification
// ------------------------------------------------------------------
section('TEST 13: Member subledger position unaffected by classification (only the GL side changes)');
$stmt = $db->prepare("SELECT COALESCE(SUM(credit)-SUM(debit),0) FROM savings WHERE savings_account_id=?");
$stmt->execute([$m1['account_id']]);
$bal1 = (float)$stmt->fetchColumn();
check('Member 1 subledger balance is still exactly 200,000 (classification did not change the amount)', abs($bal1 - 200000) < 0.01, "got {$bal1}");
$stmt->execute([$m5['account_id']]);
$bal5 = (float)$stmt->fetchColumn();
check('Member 5 (Historical Only) subledger balance is still exactly 75,000', abs($bal5 - 75000) < 0.01, "got {$bal5}");

// ------------------------------------------------------------------
// TEST 14 -- Existing B/F tests continue to behave correctly
// ------------------------------------------------------------------
section('TEST 14: Existing B/F behaviour spot-check (createBroughtForward/reverseBroughtForward unaffected)');
$m14 = makeMember($memberModel, $ADMIN, 'G');
$r14 = $savingsModel->createBroughtForward([
    'member_id' => $m14['member_id'], 'savings_account_id' => $m14['account_id'],
    'amount' => 30000, 'transaction_date' => date('Y-m-d'), 'notes' => 'Normal new B/F entry.',
], $ADMIN); // no posting_mode -- exercises the historical_only default
check('createBroughtForward() without posting_mode still defaults to historical_only', $r14['posting_mode'] === 'historical_only');
check('journal_entry_id NULL for the default path', $savingsModel->find($r14['id'])['journal_entry_id'] === null);
check('bf_posting_mode auto-set to historical_only', $savingsModel->find($r14['id'])['bf_posting_mode'] === 'historical_only');
$rev14 = $savingsModel->reverseBroughtForward($r14['id'], $ADMIN, 'Testing reversal still works.');
check('reverseBroughtForward() still works on a fresh (non-legacy) B/F', isset($rev14['id']));
check('Net effect back to zero after reversal', !$savingsModel->hasBroughtForward($m14['account_id']));
// The pre-existing production-style unclassified row remains classifiable exactly once, proven by bf6 (still unclassified after its rejected attempt in Test 6):
$r6ok = $savingsModel->classifyBroughtForward($bf6['id'], 'historical_only', null, 'Now classified correctly.', $ADMIN);
check('The Test-6 record (rejected once for missing reason) can be classified once a reason is supplied', $r6ok['posting_mode'] === 'historical_only');

echo "\n=== SUMMARY: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
