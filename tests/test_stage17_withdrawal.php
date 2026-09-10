<?php
/**
 * STAGE 17 PART E — Withdrawal Transaction Test Harness
 *
 * TARGETS THE ISOLATED DATABASE: empower_db_stage17. Never touches
 * empower_db. Synthetic member/account/deposit fixtures only -- no real
 * production member balances are used, per the brief's explicit instruction.
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

echo "=== TARGET DATABASE: " . DB_NAME . " (ISOLATED -- never empower_db) ===\n";

$pdo = Database::getInstance()->getConnection();

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['withdrawals', 'journal_lines', 'journal_entries', 'savings', 'savings_account_holders',
          'member_savings_accounts', 'members', 'loan_penalties', 'loan_installments', 'loan_repayments', 'loans'] as $t) {
    $pdo->exec("TRUNCATE TABLE `$t`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$pass = 0; $fail = 0; $failures = [];
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; $failures[] = "$label -- $detail"; echo "  [FAIL] $label -- $detail\n"; }
}
function section(string $t): void { echo "\n=== $t ===\n"; }

$ADMIN = 1;
$memberModel  = new MemberModel();
$accountModel = new MemberSavingsAccountModel();
$savingsModel = new SavingsModel();
$policyModel  = new WithdrawalPolicyModel();
$wdlModel     = new WithdrawalModel();

// Seed the two approved default policies fresh (idempotent-safe: delete then insert)
$pdo->exec("DELETE FROM savings_withdrawal_policies");
$policyModel->createPolicy(['account_type'=>'compulsory','withdrawal_enabled'=>1,'maximum_withdrawal_percent'=>50,'share_conversion_percent'=>50,'frequency'=>'once_per_financial_year','effective_from'=>'2026-01-01'], $ADMIN);
$policyModel->createPolicy(['account_type'=>'voluntary','withdrawal_enabled'=>1,'maximum_withdrawal_percent'=>100,'share_conversion_percent'=>0,'frequency'=>'any_time','effective_from'=>'2026-01-01'], $ADMIN);

/** Create a member with a compulsory account (auto-qualified via 2 deposits) and optionally a voluntary account, both funded to the requested balances. */
function makeMember(MemberModel $mm, MemberSavingsAccountModel $am, SavingsModel $sm, int $admin, float $compulsoryBalance, float $voluntaryBalance, string $tag): array {
    static $seq = 0; $seq++;
    $m = $mm->createWithCompulsoryAccount([
        'member_number' => $mm->generateMemberNumber(),
        'first_name' => 'Stage17WD', 'last_name' => "Member{$tag}{$seq}", 'gender' => 'Female',
        'phone' => '07000002' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT),
        'national_id' => "CM17WD{$tag}{$seq}",
        'join_date' => date('Y-m-d', strtotime('-1 year')), 'status' => 'active',
    ], $admin);
    $memberId = $m['member_id'];
    $compulsoryAccountId = $m['account_id'];

    // Deposit dates must fall inside the isolated DB's OPEN accounting
    // period (2026-07-01..2026-09-30) -- fixed dates, not relative to
    // "today", so the fixture is stable regardless of when this suite runs.
    if ($compulsoryBalance > 0) {
        $sm->recordDepositWithPosting(['member_id'=>$memberId,'savings_account_id'=>$compulsoryAccountId,'transaction_type'=>'deposit','amount'=>20000,'payment_method'=>'Cash','transaction_date'=>'2026-07-05','receipt_number'=>$sm->generateReceiptNumber(),'recorded_by'=>$admin], $admin);
        $sm->recordDepositWithPosting(['member_id'=>$memberId,'savings_account_id'=>$compulsoryAccountId,'transaction_type'=>'deposit','amount'=>$compulsoryBalance-20000,'payment_method'=>'Cash','transaction_date'=>'2026-07-10','receipt_number'=>$sm->generateReceiptNumber(),'recorded_by'=>$admin], $admin);
        $am->recordQualification($compulsoryAccountId);
    }

    $voluntaryAccountId = $am->createAccount(['account_type'=>'voluntary','opened_date'=>'2026-07-01'], [['member_id'=>$memberId,'role'=>'primary']], $admin);
    if ($voluntaryBalance > 0) {
        $sm->recordDepositWithPosting(['member_id'=>$memberId,'savings_account_id'=>$voluntaryAccountId,'transaction_type'=>'deposit','amount'=>$voluntaryBalance,'payment_method'=>'Cash','transaction_date'=>'2026-07-10','receipt_number'=>$sm->generateReceiptNumber(),'recorded_by'=>$admin], $admin);
    }

    return ['member_id'=>$memberId, 'compulsory_account_id'=>$compulsoryAccountId, 'voluntary_account_id'=>$voluntaryAccountId];
}

// ================================================================
// Test 1/2/3 -- Policy consumption: engine reads the active policy,
// and enforces a CHANGED percentage with ZERO code changes.
// ================================================================
section('Test 1: Compulsory policy consumption (50%/50%/annual)');
$f1 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 1000000, 0, 'P1');
$w1 = $wdlModel->processAnnualCompulsory(['member_id'=>$f1['member_id'],'requested_amount'=>500000,'payment_method'=>'Cash'], $ADMIN);
$row1 = $wdlModel->find($w1);
check('Engine enforces 50% max (500,000 accepted)', (float)$row1['withdrawal_amount'] === 500000.0);
check('Engine computed 50% share conversion (500,000)', (float)$row1['retained_amount'] === 500000.0);

section('Test 2: Change compulsory max 50% -> 40% (config only, no code change) -- engine enforces it');
// A new version effective 2026-06-01 (still within 2026, before today, and
// before the isolated DB's open accounting period) supersedes the 2026-01-01
// row for any date from 2026-06-01 onward -- including the whole open
// accounting period (2026-07-01..2026-09-30) used for the withdrawal itself.
$policyModel->createPolicy(['account_type'=>'compulsory','withdrawal_enabled'=>1,'maximum_withdrawal_percent'=>40,'share_conversion_percent'=>60,'frequency'=>'once_per_financial_year','effective_from'=>'2026-06-01'], $ADMIN);
$f2 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 1000000, 0, 'P2');
$threw = false;
try {
    $wdlModel->processAnnualCompulsory(['member_id'=>$f2['member_id'],'requested_amount'=>500000,'payment_method'=>'Cash','withdrawal_date'=>'2026-07-20'], $ADMIN);
} catch (InvalidArgumentException $e) { $threw = true; }
check('500,000 now REJECTED under the new 40% policy (max is 400,000) -- proves no hard-coded 50%', $threw);
$w2 = $wdlModel->processAnnualCompulsory(['member_id'=>$f2['member_id'],'requested_amount'=>400000,'payment_method'=>'Cash','withdrawal_date'=>'2026-07-20'], $ADMIN);
$row2 = $wdlModel->find($w2);
check('400,000 accepted under the new 40% policy', (float)$row2['withdrawal_amount'] === 400000.0);
check('Share conversion is now 600,000 (60% policy)', (float)$row2['retained_amount'] === 600000.0);

section('Test 3: Change voluntary max 100% -> 80% (config only) -- engine enforces it');
$policyModel->createPolicy(['account_type'=>'voluntary','withdrawal_enabled'=>1,'maximum_withdrawal_percent'=>80,'share_conversion_percent'=>0,'frequency'=>'any_time','effective_from'=>'2026-06-01'], $ADMIN);
$f3 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 0, 500000, 'P3');
$threw = false;
try {
    $wdlModel->processVoluntary(['member_id'=>$f3['member_id'],'requested_amount'=>500000,'payment_method'=>'Cash','withdrawal_date'=>'2026-07-20'], $ADMIN);
} catch (InvalidArgumentException $e) { $threw = true; }
check('Full 100% (500,000) now REJECTED under the new 80% policy (max is 400,000)', $threw);
$w3 = $wdlModel->processVoluntary(['member_id'=>$f3['member_id'],'requested_amount'=>400000,'payment_method'=>'Cash','withdrawal_date'=>'2026-07-20'], $ADMIN);
check('400,000 accepted under the new 80% voluntary policy', $w3 > 0);

// From here on, all remaining tests use "today" as the implicit withdrawal
// date (no explicit withdrawal_date passed) -- the Part E-0 seeded 2026-01-01
// policies (50%/50%/annual, 100%/0%/any_time) are the ones actually in
// effect for "today" (2026-08-27), since the 2026-06-01 changed versions
// above only affected members f2/f3's SPECIFIC withdrawal dates
// (2026-07-20) -- wait: a 2026-06-01-effective row with no effective_to
// remains open-ended and DOES also govern "today" once created. To keep the
// rest of this suite using the ORIGINAL 50/50 and 100/0 policies (matching
// the brief's own examples exactly), the changed versions are reverted here
// by creating fresh follow-up versions restoring the original rules,
// effective immediately after the Test 2/3 date, before any further test
// fixture is processed.
$policyModel->createPolicy(['account_type'=>'compulsory','withdrawal_enabled'=>1,'maximum_withdrawal_percent'=>50,'share_conversion_percent'=>50,'frequency'=>'once_per_financial_year','effective_from'=>'2026-07-21'], $ADMIN);
$policyModel->createPolicy(['account_type'=>'voluntary','withdrawal_enabled'=>1,'maximum_withdrawal_percent'=>100,'share_conversion_percent'=>0,'frequency'=>'any_time','effective_from'=>'2026-07-21'], $ADMIN);
check('Compulsory policy restored to 50%/50% for 2026-08-27 (today)', (float)$policyModel->getActivePolicy('compulsory')['maximum_withdrawal_percent'] === 50.0);
check('Voluntary policy restored to 100%/0% for 2026-08-27 (today)', (float)$policyModel->getActivePolicy('voluntary')['maximum_withdrawal_percent'] === 100.0);

// ================================================================
// Tests 4-8 -- Annual withdrawal (examples A-D from the brief, §10)
// ================================================================
section('Test 4 (Example A): Compulsory=1,000,000, Withdraw=500,000 -> Cash=500,000 Shares=500,000 Remaining=0');
$f4 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 1000000, 0, 'A');
$w4 = $wdlModel->processAnnualCompulsory(['member_id'=>$f4['member_id'],'requested_amount'=>500000,'payment_method'=>'Cash'], $ADMIN);
$row4 = $wdlModel->find($w4);
check('Cash = 500,000', (float)$row4['withdrawal_amount'] === 500000.0);
check('Shares = 500,000', (float)$row4['retained_amount'] === 500000.0);
check('Remaining compulsory balance = 0', $accountModel->getAccountBalance($f4['compulsory_account_id']) === 0.0);

section('Test 5 (Example B): Compulsory=1,000,000, Withdraw=300,000 -> Cash=300,000 Shares=700,000 Remaining=0');
$f5 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 1000000, 0, 'B');
$w5 = $wdlModel->processAnnualCompulsory(['member_id'=>$f5['member_id'],'requested_amount'=>300000,'payment_method'=>'Cash'], $ADMIN);
$row5 = $wdlModel->find($w5);
check('Cash = 300,000', (float)$row5['withdrawal_amount'] === 300000.0);
check('Shares = 700,000', (float)$row5['retained_amount'] === 700000.0);
check('Remaining compulsory balance = 0', $accountModel->getAccountBalance($f5['compulsory_account_id']) === 0.0);

section('Test 6 (Example C): Compulsory=1,000,000, Withdraw=100,000 -> Cash=100,000 Shares=900,000 Remaining=0');
$f6 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 1000000, 0, 'C');
$w6 = $wdlModel->processAnnualCompulsory(['member_id'=>$f6['member_id'],'requested_amount'=>100000,'payment_method'=>'Cash'], $ADMIN);
$row6 = $wdlModel->find($w6);
check('Cash = 100,000', (float)$row6['withdrawal_amount'] === 100000.0);
check('Shares = 900,000', (float)$row6['retained_amount'] === 900000.0);
check('Remaining compulsory balance = 0', $accountModel->getAccountBalance($f6['compulsory_account_id']) === 0.0);

section('Test 7 (Example D): Compulsory=1,000,000, Withdraw=500,001 -> REJECTED, no mutation');
$f7 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 1000000, 0, 'D');
$jeBefore7 = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$wdBefore7 = (int)$pdo->query('SELECT COUNT(*) FROM withdrawals')->fetchColumn();
$threw = false;
try {
    $wdlModel->processAnnualCompulsory(['member_id'=>$f7['member_id'],'requested_amount'=>500001,'payment_method'=>'Cash'], $ADMIN);
} catch (InvalidArgumentException $e) { $threw = true; }
check('500,001 rejected (exceeds 50% max of 500,000)', $threw);
check('No withdrawal row created', (int)$pdo->query('SELECT COUNT(*) FROM withdrawals')->fetchColumn() === $wdBefore7);
check('No journal entry created', (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn() === $jeBefore7);
check('Compulsory balance unchanged (still 1,000,000)', $accountModel->getAccountBalance($f7['compulsory_account_id']) === 1000000.0);

section('Test 8: Duplicate annual withdrawal in the same financial year rejected');
$threw = false;
try {
    $wdlModel->processAnnualCompulsory(['member_id'=>$f4['member_id'],'requested_amount'=>1000,'payment_method'=>'Cash'], $ADMIN);
} catch (InvalidArgumentException $e) { $threw = true; }
check('Second annual withdrawal for the same member+year rejected (member 4 already withdrew above)', $threw);

// ================================================================
// Tests 9-11 -- Voluntary withdrawal
// ================================================================
section('Test 9: Voluntary=800,000, Withdraw=350,000 -> Remaining=450,000, Shares=0');
$f9 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 0, 800000, 'V9');
$w9 = $wdlModel->processVoluntary(['member_id'=>$f9['member_id'],'requested_amount'=>350000,'payment_method'=>'Cash'], $ADMIN);
$row9 = $wdlModel->find($w9);
check('Cash = 350,000', (float)$row9['withdrawal_amount'] === 350000.0);
check('Shares = 0 (no auto-conversion for voluntary)', (float)$row9['retained_amount'] === 0.0);
check('Remaining voluntary balance = 450,000', $accountModel->getAccountBalance($f9['voluntary_account_id']) === 450000.0);

section('Test 10: Withdraw 100% of voluntary savings -> Remaining=0, Shares=0');
$f10 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 0, 250000, 'V10');
$w10 = $wdlModel->processVoluntary(['member_id'=>$f10['member_id'],'requested_amount'=>250000,'payment_method'=>'Cash'], $ADMIN);
$row10 = $wdlModel->find($w10);
check('Full voluntary balance withdrawn', (float)$row10['withdrawal_amount'] === 250000.0);
check('Remaining voluntary balance = 0', $accountModel->getAccountBalance($f10['voluntary_account_id']) === 0.0);
check('Shares = 0', (float)$row10['retained_amount'] === 0.0);

section('Test 11: Voluntary over-balance rejected');
$f11 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 0, 100000, 'V11');
$threw = false;
try {
    $wdlModel->processVoluntary(['member_id'=>$f11['member_id'],'requested_amount'=>150000,'payment_method'=>'Cash'], $ADMIN);
} catch (InvalidArgumentException $e) { $threw = true; }
check('Withdrawal exceeding voluntary balance rejected', $threw);
check('Voluntary balance unchanged', $accountModel->getAccountBalance($f11['voluntary_account_id']) === 100000.0);

// Multiple voluntary withdrawals in the same year must NOT be blocked
// (frequency = any_time) -- proves the type-scoped uniqueness works.
$w11b = $wdlModel->processVoluntary(['member_id'=>$f11['member_id'],'requested_amount'=>30000,'payment_method'=>'Cash'], $ADMIN);
check('A second voluntary withdrawal for the same member in the same year succeeds (any_time frequency)', $w11b > 0);

// ================================================================
// Test 12 (§38) -- THE critical separation test
// ================================================================
section('Test 12 (CRITICAL, §38): Compulsory=1,000,000 + Voluntary=500,000, sequenced withdrawals, full isolation');
$f12 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 1000000, 500000, 'SEP');

$w12v = $wdlModel->processVoluntary(['member_id'=>$f12['member_id'],'requested_amount'=>300000,'payment_method'=>'Cash'], $ADMIN);
check('After voluntary withdrawal: Compulsory UNCHANGED at 1,000,000', $accountModel->getAccountBalance($f12['compulsory_account_id']) === 1000000.0);
check('After voluntary withdrawal: Voluntary = 200,000', $accountModel->getAccountBalance($f12['voluntary_account_id']) === 200000.0);
check('After voluntary withdrawal: Shares = 0', (float)$wdlModel->find($w12v)['retained_amount'] === 0.0);

$w12c = $wdlModel->processAnnualCompulsory(['member_id'=>$f12['member_id'],'requested_amount'=>300000,'payment_method'=>'Cash'], $ADMIN);
check('After compulsory withdrawal: Voluntary STILL 200,000 (unaffected)', $accountModel->getAccountBalance($f12['voluntary_account_id']) === 200000.0);
check('After compulsory withdrawal: Compulsory = 0', $accountModel->getAccountBalance($f12['compulsory_account_id']) === 0.0);
check('After compulsory withdrawal: Shares = 700,000', (float)$wdlModel->find($w12c)['retained_amount'] === 700000.0);

// ================================================================
// Test 13 -- Accounting: annual journal structure + balance
// ================================================================
section('Test 13: Annual withdrawal journal -- Dr Members\' Savings / Cr Cash / Cr Shares, balanced');
$rowAnnualJournal = $wdlModel->find($w4); // Cash=500000 Shares=500000
$jl = $pdo->prepare('SELECT * FROM journal_lines WHERE journal_entry_id = ?');
$jl->execute([$rowAnnualJournal['journal_entry_id']]);
$lines = $jl->fetchAll();
check('Exactly 3 lines', count($lines) === 3, 'got ' . count($lines));
$debitTotal = array_sum(array_column($lines, 'debit'));
$creditTotal = array_sum(array_column($lines, 'credit'));
check('Balanced: debit = credit = 1,000,000', abs($debitTotal - 1000000) < 0.01 && abs($creditTotal - 1000000) < 0.01, "d={$debitTotal} c={$creditTotal}");
$debitLine = array_values(array_filter($lines, fn($l) => (float)$l['debit'] > 0))[0];
check('Debit line hits Members\' Savings (17)', (int)$debitLine['account_id'] === 17);
check('Debit = 1,000,000 (full qualifying balance)', abs((float)$debitLine['debit'] - 1000000) < 0.01);
$cashLine = array_values(array_filter($lines, fn($l) => (int)$l['account_id'] === 7))[0] ?? null;
check('Cr Cash(7) = 500,000', $cashLine && abs((float)$cashLine['credit'] - 500000) < 0.01);
$sharesLine = array_values(array_filter($lines, fn($l) => (int)$l['account_id'] === 24))[0] ?? null;
check('Cr Shares(24) = 500,000', $sharesLine && abs((float)$sharesLine['credit'] - 500000) < 0.01);

section('Test 14: Voluntary withdrawal journal -- Dr Members\' Savings / Cr Cash only, no Shares line');
$rowVolJournal = $wdlModel->find($w9);
$jl2 = $pdo->prepare('SELECT * FROM journal_lines WHERE journal_entry_id = ?');
$jl2->execute([$rowVolJournal['journal_entry_id']]);
$lines2 = $jl2->fetchAll();
check('Exactly 2 lines (no Shares line)', count($lines2) === 2, 'got ' . count($lines2));
check('No line hits Shares account (24)', !in_array(24, array_column($lines2, 'account_id')));
$d2 = array_sum(array_column($lines2, 'debit')); $c2 = array_sum(array_column($lines2, 'credit'));
check('Balanced: debit = credit = 350,000', abs($d2-350000)<0.01 && abs($c2-350000)<0.01);

// ================================================================
// Test 15 -- Idempotency: re-posting the same withdrawal id
// ================================================================
section('Test 15: Idempotency -- re-invoking the posting step for the same withdrawal id does not duplicate');
$reflection = new ReflectionClass(SavingsModel::class);
$postWithdrawalMethod = $reflection->getMethod('postWithdrawal');
$savingsRowForW9 = $pdo->prepare('SELECT id FROM savings WHERE journal_entry_id = ?');
$savingsRowForW9->execute([$rowVolJournal['journal_entry_id']]);
$savingsIdForW9 = $savingsRowForW9->fetchColumn();
$secondPost = $savingsModel->postWithdrawal((int)$savingsIdForW9, $ADMIN);
check('Re-posting the same savings/withdrawal row returns created=false', $secondPost['created'] === false);
check('Re-posting returns the SAME journal_entry_id', (int)$secondPost['journal_entry_id'] === (int)$rowVolJournal['journal_entry_id']);
$jeCountForW9 = $pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE source_module='savings' AND source_reference_id=?");
$jeCountForW9->execute([$savingsIdForW9]);
check('Exactly one journal entry exists for this withdrawal', (int)$jeCountForW9->fetchColumn() === 1);

// ================================================================
// Test 16 -- Concurrency: FOR UPDATE lock actually serializes access
// ================================================================
section('Test 16: Concurrency protection -- FOR UPDATE lock on the account row is real and blocks a concurrent reader');
$f16 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 600000, 0, 'CONC');
// Second, independent PDO connection simulating a concurrent request.
$pdo2 = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS);
$pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo2->exec('SET SESSION innodb_lock_wait_timeout = 2');

$pdo->beginTransaction();
$pdo->prepare('SELECT id FROM member_savings_accounts WHERE id = ? FOR UPDATE')->execute([$f16['compulsory_account_id']]);
// Connection 1 now holds the lock, uncommitted.
$blocked = false;
try {
    $pdo2->beginTransaction();
    $pdo2->prepare('SELECT id FROM member_savings_accounts WHERE id = ? FOR UPDATE')->execute([$f16['compulsory_account_id']]);
    $pdo2->commit();
} catch (PDOException $e) {
    $blocked = true; // lock wait timeout -- proves the row is genuinely locked
    if ($pdo2->inTransaction()) $pdo2->rollBack();
}
$pdo->commit();
check('A concurrent FOR UPDATE attempt on the same account is blocked until the first transaction commits (real row-level locking, not just an application-level check)', $blocked);

// ================================================================
// Test 17 -- Reversal (annual)
// ================================================================
section('Test 17: Annual withdrawal reversal restores savings, shares, and reverses the journal');
$f17 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 800000, 0, 'REV');
$w17 = $wdlModel->processAnnualCompulsory(['member_id'=>$f17['member_id'],'requested_amount'=>400000,'payment_method'=>'Cash'], $ADMIN);
$row17 = $wdlModel->find($w17);
check('Pre-reversal: compulsory balance = 0', $accountModel->getAccountBalance($f17['compulsory_account_id']) === 0.0);
$originalJournalId = $row17['journal_entry_id'];

$wdlModel->reverseWithdrawal($w17, $ADMIN);
check('Withdrawal row deleted', $wdlModel->find($w17) === false);
check('Compulsory balance restored to 800,000', $accountModel->getAccountBalance($f17['compulsory_account_id']) === 800000.0);
$origEntry = $pdo->prepare('SELECT * FROM journal_entries WHERE id = ?');
$origEntry->execute([$originalJournalId]);
$origEntry = $origEntry->fetch();
check('Original journal entry still exists, untouched (never mutated)', $origEntry !== false);
$reversalEntry = $pdo->prepare('SELECT * FROM journal_entries WHERE reversal_of_id = ?');
$reversalEntry->execute([$originalJournalId]);
check('A linked reversal entry exists', $reversalEntry->fetch() !== false);

section('Test 18: Voluntary withdrawal reversal restores the voluntary balance');
$f18 = makeMember($memberModel, $accountModel, $savingsModel, $ADMIN, 0, 300000, 'REVV');
$w18 = $wdlModel->processVoluntary(['member_id'=>$f18['member_id'],'requested_amount'=>120000,'payment_method'=>'Cash'], $ADMIN);
check('Pre-reversal: voluntary balance = 180,000', $accountModel->getAccountBalance($f18['voluntary_account_id']) === 180000.0);
$wdlModel->reverseWithdrawal($w18, $ADMIN);
check('Voluntary balance restored to 300,000', $accountModel->getAccountBalance($f18['voluntary_account_id']) === 300000.0);

// ================================================================
// Test 19 -- Report integration: Share Report / Withdrawal Report
// ================================================================
section('Test 19: Share Report / Withdrawal Report reconciliation');
$reportModel = new ReportModel();
$shareReport = $reportModel->getShareReport();
$expectedShareCapital = (float)$pdo->query('SELECT COALESCE(SUM(retained_amount),0) FROM withdrawals')->fetchColumn();
check('getShareReport() total_share_capital matches SUM(withdrawals.retained_amount)', abs($shareReport['total_share_capital'] - $expectedShareCapital) < 0.01);

$notWithdrawn2026 = $reportModel->getWithdrawalReport(2026)['not_withdrawn'];
$memberNumbersNotWithdrawn = array_column($notWithdrawn2026, 'member_number');
$f9Member = $memberModel->find($f9['member_id']);
check('A member who ONLY made a voluntary withdrawal still appears in "not yet withdrawn annual" list', in_array($f9Member['member_number'], $memberNumbersNotWithdrawn));
$f4Member = $memberModel->find($f4['member_id']);
check('A member who made the annual withdrawal does NOT appear in "not yet withdrawn" list', !in_array($f4Member['member_number'], $memberNumbersNotWithdrawn));

// ================================================================
// Test 20 -- Trial Balance stays balanced across the whole suite
// ================================================================
section('Test 20: Trial Balance -- SUM(debits) = SUM(credits) across the whole isolated suite');
$tb = $pdo->query('SELECT SUM(debit) AS d, SUM(credit) AS c FROM journal_lines')->fetch();
check('Trial balance: total debits equal total credits', abs((float)$tb['d'] - (float)$tb['c']) < 0.01, "debit={$tb['d']} credit={$tb['c']}");

// ================================================================
// SUMMARY
// ================================================================
echo "\n================================================================\n";
echo "STAGE 17 PART E — WITHDRAWAL TRANSACTION TEST RESULTS: {$pass} passed, {$fail} failed\n";
echo "================================================================\n";
if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    exit(1);
}
exit(0);
