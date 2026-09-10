<?php
/**
 * STAGE 2 — Retained Withdrawal -> Share Ledger Mirror — Test Harness
 *
 * TARGETS AN ISOLATED, DISPOSABLE CLONE (name passed as argv[1] by the
 * caller, which creates it as a full clone of the existing empower_db_stage17
 * fixture database plus the Stage 1 share_transactions schema, and drops it
 * afterward). NEVER touches empower_db. No production data is read, written,
 * or required to run this suite.
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
$memberModel  = new MemberModel();
$accountModel = new MemberSavingsAccountModel();
$savingsModel = new SavingsModel();
$policyModel  = new WithdrawalPolicyModel();
$wdlModel     = new WithdrawalModel();
$shareModel   = new ShareModel();
$settingsModel = new SettingsModel();

// Ensure the standard 50/50 compulsory + 100/0 voluntary policy is active
// for the fixed date window used below (matches the already-established
// test_stage17_withdrawal.php convention).
$pdo->exec("DELETE FROM savings_withdrawal_policies");
$policyModel->createPolicy(['account_type'=>'compulsory','withdrawal_enabled'=>1,'maximum_withdrawal_percent'=>50,'share_conversion_percent'=>50,'frequency'=>'once_per_financial_year','effective_from'=>'2026-01-01'], $ADMIN);
$policyModel->createPolicy(['account_type'=>'voluntary','withdrawal_enabled'=>1,'maximum_withdrawal_percent'=>100,'share_conversion_percent'=>0,'frequency'=>'any_time','effective_from'=>'2026-01-01'], $ADMIN);

// Force a known share_value for deterministic quantity assertions.
$pdo->prepare("UPDATE settings SET setting_val = '20000' WHERE setting_key = 'share_value'")->execute();

function makeMember(MemberModel $mm, MemberSavingsAccountModel $am, SavingsModel $sm, int $admin, float $compulsoryBalance, string $tag): array {
    static $seq = 0; $seq++;
    $m = $mm->createWithCompulsoryAccount([
        'member_number' => $mm->generateMemberNumber(),
        'first_name' => 'Stage2SHR', 'last_name' => "Member{$tag}{$seq}", 'gender' => 'Female',
        'phone' => '07000003' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT),
        'national_id' => "CM2SHR{$tag}{$seq}",
        'join_date' => date('Y-m-d', strtotime('-1 year')), 'status' => 'active',
    ], $admin);
    $memberId = $m['member_id'];
    $accountId = $m['account_id'];
    if ($compulsoryBalance > 0) {
        $sm->recordDepositWithPosting(['member_id'=>$memberId,'savings_account_id'=>$accountId,'transaction_type'=>'deposit','amount'=>20000,'payment_method'=>'Cash','transaction_date'=>'2026-07-05','receipt_number'=>$sm->generateReceiptNumber(),'recorded_by'=>$admin], $admin);
        $sm->recordDepositWithPosting(['member_id'=>$memberId,'savings_account_id'=>$accountId,'transaction_type'=>'deposit','amount'=>$compulsoryBalance-20000,'payment_method'=>'Cash','transaction_date'=>'2026-07-10','receipt_number'=>$sm->generateReceiptNumber(),'recorded_by'=>$admin], $admin);
        $am->recordQualification($accountId);
    }
    return ['member_id'=>$memberId, 'account_id'=>$accountId];
}

// ================================================================
// Test 1 — Zero retained amount (voluntary withdrawal): no mirror row.
// ================================================================
section('Test 1: Zero retained amount creates NO share_transactions row');
$f1 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 0, 'T1');
$voluntaryAccountId = $accountModel->createAccount(['account_type'=>'voluntary','opened_date'=>'2026-07-01'], [['member_id'=>$f1['member_id'],'role'=>'primary']], $ADMIN);
$savingsModel->recordDepositWithPosting(['member_id'=>$f1['member_id'],'savings_account_id'=>$voluntaryAccountId,'transaction_type'=>'deposit','amount'=>100000,'payment_method'=>'Cash','transaction_date'=>'2026-07-10','receipt_number'=>$savingsModel->generateReceiptNumber(),'recorded_by'=>$ADMIN], $ADMIN);
$stBefore1 = (int)$pdo->query('SELECT COUNT(*) FROM share_transactions')->fetchColumn();
$w1 = $wdlModel->processVoluntary(['member_id'=>$f1['member_id'],'requested_amount'=>100000,'payment_method'=>'Cash','withdrawal_date'=>'2026-07-20'], $ADMIN);
$row1 = $wdlModel->find($w1);
check('voluntary withdrawal has retained_amount = 0', (float)$row1['retained_amount'] === 0.0);
$stAfter1 = (int)$pdo->query('SELECT COUNT(*) FROM share_transactions')->fetchColumn();
check('0 share_transactions rows created (before='.$stBefore1.', after='.$stAfter1.')', $stAfter1 === $stBefore1);

// ================================================================
// Test 2 — Whole share (40,000 / 20,000 = 2.0000)
// ================================================================
section('Test 2: Whole share -- retained 40,000 / share_value 20,000 = 2.0000');
$f2 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 80000, 'T2');
$w2 = $wdlModel->processAnnualCompulsory(['member_id'=>$f2['member_id'],'requested_amount'=>40000,'payment_method'=>'Cash','withdrawal_date'=>'2026-07-20'], $ADMIN);
$row2 = $wdlModel->find($w2);
check('retained_amount = 40,000', (float)$row2['retained_amount'] === 40000.0);
$mirror2 = $pdo->prepare("SELECT * FROM share_transactions WHERE source_reference_type='withdrawal' AND source_reference_id=?");
$mirror2->execute([$w2]);
$m2 = $mirror2->fetch(PDO::FETCH_ASSOC);
check('exactly one mirror row exists', $m2 !== false);
check('quantity = 2.0000', $m2 && (string)$m2['quantity'] === '2.0000', $m2['quantity'] ?? 'MISSING');
check('transaction_type = retained_withdrawal', $m2 && $m2['transaction_type'] === 'retained_withdrawal');
check('share_value = 20000.00', $m2 && (string)$m2['share_value'] === '20000.00');
check('amount = 40000.00', $m2 && (string)$m2['amount'] === '40000.00');

// ================================================================
// Test 3 — Fractional retained share (45,000 / 20,000 = 2.2500)
// ================================================================
section('Test 3: Fractional retained share -- 45,000 / 20,000 = 2.2500');
$f3 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 90000, 'T3');
$w3 = $wdlModel->processAnnualCompulsory(['member_id'=>$f3['member_id'],'requested_amount'=>45000,'payment_method'=>'Cash','withdrawal_date'=>'2026-07-20'], $ADMIN);
$row3 = $wdlModel->find($w3);
check('retained_amount = 45,000', (float)$row3['retained_amount'] === 45000.0);
$m3 = $pdo->query("SELECT * FROM share_transactions WHERE source_reference_id=$w3 AND source_reference_type='withdrawal'")->fetch(PDO::FETCH_ASSOC);
check('quantity = 2.2500 (NOT floored to 2)', $m3 && (string)$m3['quantity'] === '2.2500', $m3['quantity'] ?? 'MISSING');

// ================================================================
// Test 4 — Larger fractional amount (55,000 / 20,000 = 2.7500)
// ================================================================
section('Test 4: Larger fractional amount -- 55,000 / 20,000 = 2.7500');
$f4 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 110000, 'T4');
$w4 = $wdlModel->processAnnualCompulsory(['member_id'=>$f4['member_id'],'requested_amount'=>55000,'payment_method'=>'Cash','withdrawal_date'=>'2026-07-20'], $ADMIN);
$m4 = $pdo->query("SELECT * FROM share_transactions WHERE source_reference_id=$w4 AND source_reference_type='withdrawal'")->fetch(PDO::FETCH_ASSOC);
check('quantity = 2.7500', $m4 && (string)$m4['quantity'] === '2.7500', $m4['quantity'] ?? 'MISSING');

// ================================================================
// Test 5 — Historical share value preservation
// ================================================================
section('Test 5: Historical share value is preserved after settings.share_value changes');
check('mirror row for Test 2 still shows share_value=20000.00 before the settings change', (string)$m2['share_value'] === '20000.00');
$pdo->prepare("UPDATE settings SET setting_val = '25000' WHERE setting_key = 'share_value'")->execute();
check('settings.share_value is now 25000', $settingsModel->get('share_value','20000') === '25000');
$m2Reread = $pdo->query("SELECT * FROM share_transactions WHERE id={$m2['id']}")->fetch(PDO::FETCH_ASSOC);
check('Test 2 mirror row STILL shows share_value=20000.00 (not retroactively changed)', (string)$m2Reread['share_value'] === '20000.00');
check('Test 2 mirror row STILL shows quantity=2.0000 (not recalculated)', (string)$m2Reread['quantity'] === '2.0000');
// Process one more withdrawal now that share_value=25000, to confirm the NEW value is used going forward.
$f5 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 100000, 'T5');
$w5 = $wdlModel->processAnnualCompulsory(['member_id'=>$f5['member_id'],'requested_amount'=>50000,'payment_method'=>'Cash','withdrawal_date'=>'2026-07-20'], $ADMIN);
$m5 = $pdo->query("SELECT * FROM share_transactions WHERE source_reference_id=$w5 AND source_reference_type='withdrawal'")->fetch(PDO::FETCH_ASSOC);
check('a NEW mirror row uses the NEW share_value (25000.00)', $m5 && (string)$m5['share_value'] === '25000.00');
check('a NEW mirror row quantity = 50000/25000 = 2.0000', $m5 && (string)$m5['quantity'] === '2.0000');
$pdo->prepare("UPDATE settings SET setting_val = '20000' WHERE setting_key = 'share_value'")->execute(); // restore for remaining tests

// ================================================================
// Test 6 — Duplicate protection
// ================================================================
section('Test 6: Duplicate mirror attempt for the same withdrawal is rejected');
$stCountBefore6 = (int)$pdo->query('SELECT COUNT(*) FROM share_transactions')->fetchColumn();
$threw = false; $exceptionClass = '';
try {
    $shareModel->mirrorRetainedWithdrawal([
        'member_id' => $f2['member_id'],
        'transaction_date' => '2026-07-20',
        'amount' => 40000,
        'payment_method' => 'Cash',
        'source_reference_id' => $w2, // SAME withdrawal id already mirrored in Test 2
        'journal_entry_id' => $row2['journal_entry_id'],
        'processed_by' => $ADMIN,
        'reference_number' => $row2['withdrawal_number'],
    ]);
} catch (Throwable $e) { $threw = true; $exceptionClass = get_class($e); }
check('second mirror attempt for the same withdrawal throws', $threw, 'no exception was thrown');
check('exception is a PDOException (unique constraint violation), not a silently-swallowed failure', $exceptionClass === 'PDOException', $exceptionClass);
$stCountAfter6 = (int)$pdo->query('SELECT COUNT(*) FROM share_transactions')->fetchColumn();
check('row count unchanged after the rejected duplicate attempt (before='.$stCountBefore6.', after='.$stCountAfter6.')', $stCountAfter6 === $stCountBefore6);

// ================================================================
// Test 7 — Read-model double-counting verification (the mandatory check)
// ================================================================
section('Test 7: Read-model counts a mirrored retained withdrawal EXACTLY ONCE');
$shareValueNow = (float)$settingsModel->get('share_value', '20000');
$f7 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 100000, 'T7');
// BEFORE processing: baseline totals.
$totalBefore = $shareModel->totalShareCapital();
$w7 = $wdlModel->processAnnualCompulsory(['member_id'=>$f7['member_id'],'requested_amount'=>50000,'payment_method'=>'Cash','withdrawal_date'=>'2026-07-20'], $ADMIN);
$row7 = $wdlModel->find($w7);
$retained7 = (float)$row7['retained_amount'];
// AFTER processing (mirror row now exists, created atomically inside processAnnualCompulsory itself):
$totalAfter = $shareModel->totalShareCapital();
check('totalShareCapital() increased by EXACTLY the retained amount ('.$retained7.'), not double', abs(($totalAfter - $totalBefore) - $retained7) < 0.005, "before=$totalBefore after=$totalAfter retained=$retained7");
$memberCap7 = $shareModel->memberCapital($f7['member_id']);
check('memberCapital() for this member = retained amount exactly once', abs($memberCap7 - $retained7) < 0.005, (string)$memberCap7);
$legacyCount = (int)$pdo->query("SELECT COUNT(*) FROM withdrawals w WHERE w.id=$w7 AND w.retained_amount>0 AND w.id NOT IN (SELECT source_reference_id FROM share_transactions WHERE source_reference_type='withdrawal')")->fetchColumn();
check('this withdrawal is EXCLUDED from the unmirrored/legacy side once mirrored', $legacyCount === 0);
$mirrorCount = (int)$pdo->query("SELECT COUNT(*) FROM share_transactions WHERE source_reference_type='withdrawal' AND source_reference_id=$w7")->fetchColumn();
check('exactly 1 share_transactions row represents it', $mirrorCount === 1);

// ================================================================
// Test 8 — Journal reuse (no second journal entry)
// ================================================================
section('Test 8: Share mirror reuses the withdrawal\'s existing journal entry -- no second posting');
$f8 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 60000, 'T8');
// Baseline taken AFTER makeMember() -- its own two funding deposits post
// their own (legitimate, unrelated) journal entries; only the withdrawal
// itself is under test here.
$jeCountBefore8 = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$w8 = $wdlModel->processAnnualCompulsory(['member_id'=>$f8['member_id'],'requested_amount'=>30000,'payment_method'=>'Cash','withdrawal_date'=>'2026-07-20'], $ADMIN);
$jeCountAfter8 = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
check('exactly ONE new journal entry created for this withdrawal (before='.$jeCountBefore8.', after='.$jeCountAfter8.')', $jeCountAfter8 === $jeCountBefore8 + 1);
$row8 = $wdlModel->find($w8);
$m8 = $pdo->query("SELECT * FROM share_transactions WHERE source_reference_id=$w8 AND source_reference_type='withdrawal'")->fetch(PDO::FETCH_ASSOC);
check('mirror.journal_entry_id === withdrawal.journal_entry_id (same entry, not a new one)', $m8 && (int)$m8['journal_entry_id'] === (int)$row8['journal_entry_id']);
// Verify the GL 3010 credit line inside that ONE entry matches the retained amount -- confirms no duplicate posting occurred.
$glLine = $pdo->prepare("SELECT jl.credit FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE jl.journal_entry_id=? AND a.code='3010'");
$glLine->execute([$row8['journal_entry_id']]);
$glCredit = $glLine->fetchColumn();
check('GL 3010 credit line for this entry equals the retained amount exactly once', (float)$glCredit === (float)$row8['retained_amount']);

// ================================================================
// Test 9 — Atomicity: a mirror failure rolls back the WHOLE withdrawal
// ================================================================
section('Test 9: Atomicity -- if the mirror insert fails, the entire withdrawal rolls back');
// Force a guaranteed mirror failure: pre-insert a colliding row so the
// unique constraint rejects processAnnualCompulsory()'s own mirror attempt.
$f9 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 100000, 'T9');
$wdBefore9 = (int)$pdo->query('SELECT COUNT(*) FROM withdrawals')->fetchColumn();
$jeBefore9 = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$stBefore9 = (int)$pdo->query('SELECT COUNT(*) FROM share_transactions')->fetchColumn();
$savingsBefore9 = (int)$pdo->query('SELECT COUNT(*) FROM savings')->fetchColumn();
// Predict the next withdrawal id (MAX+1, matching WithdrawalModel's own
// generateNumber() convention) and pre-seed a colliding mirror row for it.
$nextWdId = (int)$pdo->query('SELECT COALESCE(MAX(id),0)+1 FROM withdrawals')->fetchColumn();
$pdo->prepare("INSERT INTO share_transactions (member_id, transaction_type, transaction_date, quantity, share_value, amount, source_reference_type, source_reference_id, processed_by) VALUES (?, 'retained_withdrawal', '2026-07-20', 1, 20000, 20000, 'withdrawal', ?, ?)")
    ->execute([$f9['member_id'], $nextWdId, $ADMIN]);
$threw9 = false;
try {
    $wdlModel->processAnnualCompulsory(['member_id'=>$f9['member_id'],'requested_amount'=>50000,'payment_method'=>'Cash','withdrawal_date'=>'2026-07-20'], $ADMIN);
} catch (Throwable $e) { $threw9 = true; }
check('processAnnualCompulsory() throws when the mirror insert collides', $threw9);
$wdAfter9 = (int)$pdo->query('SELECT COUNT(*) FROM withdrawals')->fetchColumn();
$jeAfter9 = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$savingsAfter9 = (int)$pdo->query('SELECT COUNT(*) FROM savings')->fetchColumn();
check('NO withdrawal row was left behind (rolled back)', $wdAfter9 === $wdBefore9, "before=$wdBefore9 after=$wdAfter9");
check('NO journal entry was left behind (rolled back)', $jeAfter9 === $jeBefore9, "before=$jeBefore9 after=$jeAfter9");
check('NO savings ledger row was left behind (rolled back)', $savingsAfter9 === $savingsBefore9, "before=$savingsBefore9 after=$savingsAfter9");
$pdo->prepare("DELETE FROM share_transactions WHERE source_reference_id = ? AND source_reference_type='withdrawal' AND member_id = ?")->execute([$nextWdId, $f9['member_id']]);
$balAfter9 = $accountModel->getAccountBalance($f9['account_id']);
check('compulsory balance for this member is unaffected (still 100,000)', $balAfter9 === 100000.0, (string)$balAfter9);

echo "\n=== RESULTS: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
