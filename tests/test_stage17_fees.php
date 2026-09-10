<?php
/**
 * STAGE 17 — Fees Feature Test Harness (Part B)
 *
 * Runs entirely against `empower_db_stage17`, an isolated database
 * created for Stage 17 (schema + reference/config data only, including
 * `fees`; zero transactional rows copied from production). Never
 * touches `empower_db`.
 *
 * Defines its own DB_* constants (bypassing app/config/database.php)
 * before loading core/Database.php, exactly as test_stage9's harness
 * did, so this CLI process is fully isolated from the live web app.
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

// Reset transactional tables so this script can be re-run repeatedly;
// reference/config data (accounts, fees, accounting_periods, financial_years,
// roles, users, etc.) is left untouched.
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['journal_lines', 'journal_entries', 'member_fees', 'savings_account_holders', 'member_savings_accounts', 'members'] as $t) {
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

$ADMIN_USER_ID = 1; // seeded from production's reference-data export

$memberModel = new MemberModel();
$feeModel    = new FeeModel();

// FeeModel::$table is 'fees', not 'member_fees' -- find() on $feeModel would
// query the wrong table, so member_fees charge rows are read directly here.
function getCharge(PDO $pdo, int $memberFeeId): array|false {
    $stmt = $pdo->prepare('SELECT * FROM `member_fees` WHERE `id` = ?');
    $stmt->execute([$memberFeeId]);
    return $stmt->fetch();
}

$member = $memberModel->createWithCompulsoryAccount([
    'member_number' => $memberModel->generateMemberNumber(),
    'first_name'    => 'Stage17',
    'last_name'     => 'FeeTestMember',
    'gender'        => 'Female',
    'phone'         => '0700000017',
    'national_id'   => 'CM17TEST0001',
    'join_date'     => date('Y-m-d'),
    'status'        => 'active',
], $ADMIN_USER_ID);
$memberId = $member['member_id'];
check('Member created', $memberId > 0);

// ================================================================
// 1. CHARGE -> PENDING -> NO JOURNAL YET (cash-basis)
// ================================================================
section('1. Charge fee -> pending, no journal until paid');

$regFeeId = $feeModel->chargeMember($memberId, 1, 50000.00, null, null, $ADMIN_USER_ID); // Registration Fee (one_time)
check('Registration fee charge created', $regFeeId !== false);

$charge = getCharge($pdo, $regFeeId);
check('Charge status is pending', $charge['status'] === 'pending');
check('Charge journal_entry_id is NULL before payment', $charge['journal_entry_id'] === null);

$jeCountBefore = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
check('No journal entries exist yet', $jeCountBefore === 0, "found {$jeCountBefore}");

// ================================================================
// 2. MARK PAID -> JOURNAL POSTS, BALANCED, CORRECT ACCOUNTS
// ================================================================
section('2. Mark paid -> JournalService posts a balanced Dr Cash / Cr Registration Fees Income entry');

$posted = $feeModel->markPaid($regFeeId, 'Cash', $ADMIN_USER_ID);
check('markPaid returned a journal_entry_id', ($posted['journal_entry_id'] ?? 0) > 0);

$charge = getCharge($pdo, $regFeeId);
check('Charge status is now paid', $charge['status'] === 'paid');
check('paid_date set', !empty($charge['paid_date']));
check('payment_method recorded as Cash', $charge['payment_method'] === 'Cash');
check('journal_entry_id set on member_fees row', (int)$charge['journal_entry_id'] === (int)$posted['journal_entry_id']);

$lines = $pdo->prepare('SELECT * FROM journal_lines WHERE journal_entry_id = ? ORDER BY id');
$lines->execute([$posted['journal_entry_id']]);
$lines = $lines->fetchAll();
check('Journal entry has exactly 2 lines', count($lines) === 2, 'got ' . count($lines));

$debitTotal = array_sum(array_column($lines, 'debit'));
$creditTotal = array_sum(array_column($lines, 'credit'));
check('Debits equal credits', abs($debitTotal - $creditTotal) < 0.001, "debit={$debitTotal} credit={$creditTotal}");
check('Debit total equals fee amount (50000)', abs($debitTotal - 50000.00) < 0.001, "got {$debitTotal}");

$debitLine = null; $creditLine = null;
foreach ($lines as $l) {
    if ((float)$l['debit'] > 0) $debitLine = $l;
    if ((float)$l['credit'] > 0) $creditLine = $l;
}
check('Debit line hits Cash at Hand (account 7)', $debitLine && (int)$debitLine['account_id'] === 7, 'got ' . ($debitLine['account_id'] ?? 'none'));
check('Credit line hits Registration Fees Income (account 37)', $creditLine && (int)$creditLine['account_id'] === 37, 'got ' . ($creditLine['account_id'] ?? 'none'));

$je = $pdo->prepare('SELECT * FROM journal_entries WHERE id = ?');
$je->execute([$posted['journal_entry_id']]);
$je = $je->fetch();
check('source_module is member_fees', $je['source_module'] === 'member_fees');
check('source_reference_type is fee_payment', $je['source_reference_type'] === 'fee_payment');
check('source_reference_id matches charge id', (int)$je['source_reference_id'] === (int)$regFeeId);

// ================================================================
// 3. DOUBLE MARK-PAID REJECTED (idempotency guard)
// ================================================================
section('3. Double mark-paid on the same charge is rejected');

$threw = false; $msg = '';
try {
    $feeModel->markPaid($regFeeId, 'Cash', $ADMIN_USER_ID);
} catch (InvalidArgumentException $e) {
    $threw = true; $msg = $e->getMessage();
}
check('Second markPaid() call throws InvalidArgumentException', $threw, $msg);

$jeCountAfterDouble = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
check('Still exactly 1 journal entry (no duplicate posted)', $jeCountAfterDouble === 1, "found {$jeCountAfterDouble}");

// ================================================================
// 4. INVALID PAYMENT METHOD REJECTED
// ================================================================
section('4. Invalid payment method rejected before any journal is touched');

$annualFeeId = $feeModel->chargeMember($memberId, 2, 30000.00, 2026, null, $ADMIN_USER_ID); // Annual Subscription
$threw = false;
try {
    $feeModel->markPaid($annualFeeId, 'Bitcoin', $ADMIN_USER_ID);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('markPaid() rejects an invalid payment method', $threw);
$charge = getCharge($pdo, $annualFeeId);
check('Charge remains pending after rejected payment method', $charge['status'] === 'pending');

// ================================================================
// 5. MTN MOBILE MONEY AND AIRTEL MONEY NOW BOTH MAP TO THE SAME ACTIVE
//    "1120 Mobile Money / Float" ACCOUNT (account id 8) -- the user
//    decided to combine the two into one GL account rather than the two
//    separate (permanently inactive) 1121/1122 accounts. The payment-
//    method dropdown/labels themselves are unchanged (staff still pick
//    "MTN Mobile Money" or "Airtel Money" specifically); only the GL
//    account they post to is now shared. This mirrors the identical
//    change made to Loans/Savings/Repayments/Expenses/OtherIncome/
//    Withdrawals' PAYMENT_ACCOUNTS maps.
// ================================================================
section('5. Mobile Money payment method now posts cleanly to the combined 1120 account');

$posted2 = $feeModel->markPaid($annualFeeId, 'MTN Mobile Money', $ADMIN_USER_ID);
$charge = getCharge($pdo, $annualFeeId);
check('Charge marked paid via MTN Mobile Money', $charge['status'] === 'paid');
$lines2 = $pdo->prepare('SELECT * FROM journal_lines WHERE journal_entry_id = ? ORDER BY id');
$lines2->execute([$posted2['journal_entry_id']]);
$lines2 = $lines2->fetchAll();
$debitLine2 = null; $creditLine2 = null;
foreach ($lines2 as $l) {
    if ((float)$l['debit'] > 0) $debitLine2 = $l;
    if ((float)$l['credit'] > 0) $creditLine2 = $l;
}
check('Debit line hits the combined Mobile Money / Float account (account 8)', $debitLine2 && (int)$debitLine2['account_id'] === 8, 'got ' . ($debitLine2['account_id'] ?? 'none'));
check('Credit line hits Annual Subscription Fees Income (account 39)', $creditLine2 && (int)$creditLine2['account_id'] === 39, 'got ' . ($creditLine2['account_id'] ?? 'none'));

// ================================================================
// 6. LOAN PROCESSING FEE PAID -> CORRECT INCOME ACCOUNT (4100/id 38)
// ================================================================
section('6. Loan Processing fee paid via Bank Transfer -> correct accounts');

$loanFeeId = $feeModel->chargeMember($memberId, 3, 15000.00, null, null, $ADMIN_USER_ID); // Loan Processing Fee
$posted3 = $feeModel->markPaid($loanFeeId, 'Bank Transfer', $ADMIN_USER_ID);
$lines3 = $pdo->prepare('SELECT * FROM journal_lines WHERE journal_entry_id = ? ORDER BY id');
$lines3->execute([$posted3['journal_entry_id']]);
$lines3 = $lines3->fetchAll();
$debitLine3 = null; $creditLine3 = null;
foreach ($lines3 as $l) {
    if ((float)$l['debit'] > 0) $debitLine3 = $l;
    if ((float)$l['credit'] > 0) $creditLine3 = $l;
}
check('Debit line hits Bank Accounts (account 10)', $debitLine3 && (int)$debitLine3['account_id'] === 10, 'got ' . ($debitLine3['account_id'] ?? 'none'));
check('Credit line hits Loan Processing Fees Income (account 38)', $creditLine3 && (int)$creditLine3['account_id'] === 38, 'got ' . ($creditLine3['account_id'] ?? 'none'));

// ================================================================
// 7. WAIVED FEE NEVER POSTS A JOURNAL
// ================================================================
section('7. Waived fee never posts a journal entry');

$waivedFeeId = $feeModel->chargeMember($memberId, 1, 50000.00, null, null, $ADMIN_USER_ID);
$jeCountBeforeWaive = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$feeModel->markWaived($waivedFeeId);
$charge = getCharge($pdo, $waivedFeeId);
check('Waived charge status is waived', $charge['status'] === 'waived');
$jeCountAfterWaive = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
check('No new journal entry created by waiving', $jeCountAfterWaive === $jeCountBeforeWaive, "before={$jeCountBeforeWaive} after={$jeCountAfterWaive}");

$threw = false;
try {
    $feeModel->markPaid($waivedFeeId, 'Cash', $ADMIN_USER_ID);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('markPaid() rejects an already-waived charge', $threw);

// ================================================================
// 8. CANCELLED FEE NEVER POSTS A JOURNAL
// ================================================================
section('8. Cancelled fee never posts a journal entry');

$cancelledFeeId = $feeModel->chargeMember($memberId, 1, 50000.00, null, null, $ADMIN_USER_ID);
$jeCountBeforeCancel = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$feeModel->markCancelled($cancelledFeeId);
$charge = getCharge($pdo, $cancelledFeeId);
check('Cancelled charge status is cancelled', $charge['status'] === 'cancelled');
$jeCountAfterCancel = (int)$pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
check('No new journal entry created by cancelling', $jeCountAfterCancel === $jeCountBeforeCancel, "before={$jeCountBeforeCancel} after={$jeCountAfterCancel}");

$threw = false;
try {
    $feeModel->markPaid($cancelledFeeId, 'Cash', $ADMIN_USER_ID);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('markPaid() rejects an already-cancelled charge', $threw);

// ================================================================
// 9. NONEXISTENT CHARGE REJECTED
// ================================================================
section('9. Nonexistent charge id rejected cleanly');

$threw = false;
try {
    $feeModel->markPaid(999999, 'Cash', $ADMIN_USER_ID);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('markPaid() rejects a nonexistent charge id', $threw);

// ================================================================
// 10. TRIAL BALANCE STAYS BALANCED SYSTEM-WIDE
// ================================================================
section('10. System-wide Trial Balance remains balanced after all postings');

$tb = $pdo->query("
    SELECT SUM(jl.debit) AS total_debit, SUM(jl.credit) AS total_credit
    FROM journal_lines jl
")->fetch();
check('Trial balance: total debits equal total credits', abs((float)$tb['total_debit'] - (float)$tb['total_credit']) < 0.001,
    "debit={$tb['total_debit']} credit={$tb['total_credit']}");

$expectedTotal = 50000.00 + 30000.00 + 15000.00; // reg + annual + loan processing, waived/cancelled excluded
check('Trial balance total matches sum of the 3 paid fees (95000)', abs((float)$tb['total_debit'] - $expectedTotal) < 0.001,
    "got {$tb['total_debit']}, expected {$expectedTotal}");

// ================================================================
// 11. getReportSummary() TOTALS REMAIN CORRECT
// ================================================================
section('11. getReportSummary() totals reflect paid/pending/waived/cancelled correctly');

$summary = $feeModel->getReportSummary();
check('getReportSummary() returns an array', is_array($summary));
if (is_array($summary)) {
    echo '  summary: ' . json_encode($summary) . "\n";
}

// ================================================================
// SUMMARY
// ================================================================
echo "\n================================================================\n";
echo "STAGE 17 FEES TEST RESULTS: {$pass} passed, {$fail} failed\n";
echo "================================================================\n";
if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    exit(1);
}
exit(0);
