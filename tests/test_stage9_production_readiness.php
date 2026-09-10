<?php
/**
 * STAGE 9 — Production Readiness Test Harness
 *
 * Runs entirely against `empower_db_test`, a fresh, isolated database
 * (schema + reference/config data only — chart of accounts, loan
 * product rules, roles, an admin user; zero transactional rows copied
 * from production). Never touches `empower_db`.
 *
 * This script defines its own DB_* constants directly (bypassing
 * app/config/database.php entirely) before loading core/Database.php,
 * so this CLI process connects to empower_db_test while the live web
 * app continues using empower_db, completely unaffected.
 */

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'empower_db_test');
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
// reference/config data (accounts, loan_types, loan_product_rules,
// loan_interest_brackets, roles, users, accounting_periods, etc.) is
// left untouched.
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['journal_lines','journal_entries','loan_repayments','loans','savings','savings_account_holders','member_savings_accounts','members'] as $t) {
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

// ================================================================
// 1. SAVINGS FLOW
// ================================================================
section('1. Savings deposit -> balance -> journal -> qualification -> Trial Balance');

$memberModel = new MemberModel();
$member = $memberModel->createWithCompulsoryAccount([
    'member_number' => $memberModel->generateMemberNumber(),
    'first_name'    => 'Stage9',
    'last_name'     => 'TestMember',
    'gender'        => 'Male',
    'phone'         => '0700000001',
    'national_id'   => 'CM9TEST0001',
    'join_date'     => date('Y-m-d'),
    'status'        => 'active',
], $ADMIN_USER_ID);
check('Member + compulsory account created', $member['member_id'] > 0 && $member['account_id'] > 0);

$savingsModel = new SavingsModel();

$dep1 = $savingsModel->recordDepositWithPosting([
    'member_id'          => $member['member_id'],
    'savings_account_id' => $member['account_id'],
    'transaction_type'   => 'deposit',
    'amount'             => 20000,
    'payment_method'     => 'Cash',
    'transaction_date'   => date('Y-m-d'),
    'receipt_number'     => $savingsModel->generateReceiptNumber(),
    'recorded_by'        => $ADMIN_USER_ID,
], $ADMIN_USER_ID);
check('Deposit 1 (20,000) recorded + posted', $dep1['created'] === true, json_encode($dep1));

$accModel = new MemberSavingsAccountModel();
$balanceAfter1 = $accModel->getAccountBalance($member['account_id']);
check('Balance after deposit 1 = 20,000', abs($balanceAfter1 - 20000) < 0.01, "got $balanceAfter1");

$je1 = $pdo->prepare("SELECT je.id, SUM(jl.debit) d, SUM(jl.credit) c FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.id=? GROUP BY je.id");
$je1->execute([$dep1['journal_entry_id']]);
$je1row = $je1->fetch();
check('Deposit 1 journal is balanced (debit=credit)', $je1row && abs($je1row['d'] - $je1row['c']) < 0.01, json_encode($je1row));

$dep2 = $savingsModel->recordDepositWithPosting([
    'member_id'          => $member['member_id'],
    'savings_account_id' => $member['account_id'],
    'transaction_type'   => 'deposit',
    'amount'             => 25000,
    'payment_method'     => 'Cash',
    'transaction_date'   => date('Y-m-d'),
    'receipt_number'     => $savingsModel->generateReceiptNumber(),
    'recorded_by'        => $ADMIN_USER_ID,
], $ADMIN_USER_ID);
check('Deposit 2 (25,000) recorded + posted', $dep2['created'] === true);

$balanceAfter2 = $accModel->getAccountBalance($member['account_id']);
check('Balance after deposit 2 = 45,000', abs($balanceAfter2 - 45000) < 0.01, "got $balanceAfter2");

$qual = $accModel->checkCompulsoryQualification($member['account_id']);
check('Qualification true after 2 deposits totaling >= 40,000', $qual['qualified'] === true, json_encode($qual));

// Idempotency: post the same deposit again -> must NOT create a second journal entry
$dep1Again = $savingsModel->postDeposit($dep1['id'], $ADMIN_USER_ID);
check('Re-posting deposit 1 is idempotent (created=false, same entry)', $dep1Again['created'] === false && $dep1Again['journal_entry_id'] === $dep1['journal_entry_id'], json_encode($dep1Again));

$jeCountForDep1 = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE source_module='savings' AND source_reference_type='deposit' AND source_reference_id=" . (int)$dep1['id'])->fetchColumn();
check('Exactly one journal entry exists for deposit 1', $jeCountForDep1 === 1, "got $jeCountForDep1");

// ================================================================
// 2. LOAN RATE ENGINE — all 14 amounts + gap-amount error behavior
// ================================================================
section('2. Interest rate engine — brackets, flat-rate products, gap amounts');

$productModel = new LoanProductModel();

$rateCases = [
    // [loan_type_id, amount, expected_rate_or_null_if_must_throw]
    [1, 100000,     10.0],
    [1, 1000000,    10.0],
    [1, 1100000,    5.0],
    [1, 5000000,    5.0],
    [1, 5100000,    4.0],
    [1, 10000000,   4.0],
    [1, 10100000,   3.0],
    [1, 15000000,   3.0],
    [1, 15100000,   2.0],
    [1, 1050000,    null], // gap -- must throw
    [1, 5050000,    null], // gap -- must throw
    [1, 10050000,   null], // gap -- must throw
    [1, 15050000,   null], // gap -- must throw
    [2, 500000,     4.0],   // Business Loan tier 1
    [3, 6000000,    2.0],   // Asset Financing flat
    [5, 500000,     3.0],   // Start-Up flat
    [6, 500000,     3.0],   // Agricultural flat
    [7, 500000,     1.5],   // Executive flat
    [8, 500000,     7.0],   // Emergency flat
];
foreach ($rateCases as [$typeId, $amount, $expected]) {
    try {
        $rate = $productModel->getInterestRate($typeId, $amount);
        if ($expected === null) {
            check("type=$typeId amount=$amount should THROW (gap)", false, "returned $rate instead of throwing");
        } else {
            check("type=$typeId amount=$amount -> {$expected}%", abs($rate - $expected) < 0.0001, "got $rate");
        }
    } catch (InvalidArgumentException $e) {
        if ($expected === null) {
            check("type=$typeId amount=$amount should THROW (gap)", true);
        } else {
            check("type=$typeId amount=$amount -> {$expected}%", false, 'threw: ' . $e->getMessage());
        }
    }
}

// Product-rule validation (amount/duration/eligibility)
$ruleErrors = $productModel->validateAgainstProductRules(2, 500000, 3, 12); // Business Loan, 3 months (< approved min 6)
check('Business Loan 3-month period is rejected (min is now 6)', !empty($ruleErrors), json_encode($ruleErrors));

$ruleErrors2 = $productModel->validateAgainstProductRules(8, 500000, 2, 5.0); // Emergency, only 5 months savings (needs > 6)
check('Emergency Loan with 5 months savings history is rejected', !empty($ruleErrors2), json_encode($ruleErrors2));

$ruleErrors3 = $productModel->validateAgainstProductRules(8, 500000, 2, 6.5); // Emergency, 6.5 months (> 6, strictly)
check('Emergency Loan with 6.5 months savings history passes', empty($ruleErrors3), json_encode($ruleErrors3));

$ruleErrors4 = $productModel->validateAgainstProductRules(8, 500000, 2, 6.0); // exactly 6 months -- must FAIL ("more than", strict >)
check('Emergency Loan with exactly 6.0 months savings history is rejected (strict >)', !empty($ruleErrors4), json_encode($ruleErrors4));

// ================================================================
// 3. LOAN DISBURSEMENT + REPAYMENT FLOW
// ================================================================
section('3. Loan disbursement -> repayments -> journals -> Trial Balance');

$loanModel = new LoanModel();
$loanNumber = $loanModel->generateLoanNumber();
$loanId = $loanModel->create([
    'loan_number'         => $loanNumber,
    'member_id'           => $member['member_id'],
    'loan_type_id'        => 1,
    'loan_amount'         => 1000000,
    'interest_rate'       => 10.0,
    'interest_amount'     => 300000,
    'total_payable'       => 1300000,
    'outstanding'         => 1300000,
    'monthly_installment' => 433333.33,
    'issue_date'          => date('Y-m-d'),
    'due_date'            => date('Y-m-d', strtotime('+3 months')),
    'disbursement_date'   => date('Y-m-d'),
    'disbursement_method' => 'Cash',
    'loan_period_months'  => 3,
    'status'              => 'active',
]);
check('Loan created', $loanId !== false, "loanId=$loanId");

$disb = $loanModel->postDisbursement((int)$loanId, $ADMIN_USER_ID);
check('Disbursement posted', $disb['created'] === true, json_encode($disb));

$disbAgain = $loanModel->postDisbursement((int)$loanId, $ADMIN_USER_ID);
check('Re-posting disbursement is idempotent', $disbAgain['created'] === false && $disbAgain['journal_entry_id'] === $disb['journal_entry_id']);

$jeCountForLoan = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE source_module='loans' AND source_reference_id=" . (int)$loanId)->fetchColumn();
check('Exactly one journal entry exists for the disbursement', $jeCountForLoan === 1, "got $jeCountForLoan");

$repaymentModel = new RepaymentModel();

// Combined principal+interest repayment
$rep1 = $repaymentModel->recordRepayment([
    'loan_id'         => $loanId,
    'member_id'       => $member['member_id'],
    'repayment_number'=> $repaymentModel->generateRepaymentNumber(),
    'payment_type'    => 'installment',
    'amount_paid'     => 433333.33,
    'principal_paid'  => 333333.33,
    'interest_paid'   => 100000,
    'payment_date'    => date('Y-m-d'),
    'payment_method'  => 'Cash',
    'received_by'     => $ADMIN_USER_ID,
]);
check('Combined principal+interest repayment recorded', $rep1 !== false, "got $rep1");

$rep1row = $pdo->query("SELECT journal_entry_id FROM loan_repayments WHERE id=" . (int)$rep1)->fetch();
check('Repayment 1 has a journal_entry_id set', !empty($rep1row['journal_entry_id']));

$rep1je = $pdo->prepare("SELECT SUM(debit) d, SUM(credit) c FROM journal_lines WHERE journal_entry_id=?");
$rep1je->execute([$rep1row['journal_entry_id']]);
$rep1jerow = $rep1je->fetch();
check('Repayment 1 journal is balanced', abs($rep1jerow['d'] - $rep1jerow['c']) < 0.01, json_encode($rep1jerow));

$rep1Lines = $pdo->prepare("SELECT a.code, jl.debit, jl.credit FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE jl.journal_entry_id=? ORDER BY jl.id");
$rep1Lines->execute([$rep1row['journal_entry_id']]);
$rep1LinesRows = $rep1Lines->fetchAll();
$hasCash = false; $hasLoans = false; $hasInterest = false;
foreach ($rep1LinesRows as $l) {
    if ($l['code'] === '1110' && $l['debit'] > 0) $hasCash = true;
    if ($l['code'] === '1180' && $l['credit'] > 0) $hasLoans = true;
    if ($l['code'] === '4035' && $l['credit'] > 0) $hasInterest = true;
}
check('Repayment 1 journal has Dr Cash, Cr Loans to Members, Cr Interest Income', $hasCash && $hasLoans && $hasInterest, json_encode($rep1LinesRows));

// Pure interest repayment
$rep2 = $repaymentModel->recordInterestPayment([
    'loan_id'          => $loanId,
    'member_id'        => $member['member_id'],
    'repayment_number' => $repaymentModel->generateRepaymentNumber(),
    'amount_paid'      => 50000,
    'payment_date'     => date('Y-m-d'),
    'payment_method'   => 'Cash',
    'received_by'      => $ADMIN_USER_ID,
]);
check('Interest-only repayment recorded', $rep2 !== false, "got $rep2");
$rep2row = $pdo->query("SELECT journal_entry_id FROM loan_repayments WHERE id=" . (int)$rep2)->fetch();
check('Interest-only repayment has a journal_entry_id set', !empty($rep2row['journal_entry_id']));

// Partial repayment (smaller amount)
$rep3 = $repaymentModel->recordRepayment([
    'loan_id'         => $loanId,
    'member_id'       => $member['member_id'],
    'repayment_number'=> $repaymentModel->generateRepaymentNumber(),
    'payment_type'    => 'installment',
    'amount_paid'     => 50000,
    'principal_paid'  => 50000,
    'interest_paid'   => 0,
    'payment_date'    => date('Y-m-d'),
    'payment_method'  => 'Cash',
    'received_by'     => $ADMIN_USER_ID,
]);
check('Partial repayment recorded', $rep3 !== false, "got $rep3");

// Final repayment clearing the loan
$loanAfter = $loanModel->find((int)$loanId);
$finalAmt = (float)$loanAfter['outstanding'];
$rep4 = $repaymentModel->recordRepayment([
    'loan_id'         => $loanId,
    'member_id'       => $member['member_id'],
    'repayment_number'=> $repaymentModel->generateRepaymentNumber(),
    'payment_type'    => 'settlement',
    'amount_paid'     => $finalAmt,
    'principal_paid'  => $finalAmt,
    'interest_paid'   => 0,
    'payment_date'    => date('Y-m-d'),
    'payment_method'  => 'Cash',
    'received_by'     => $ADMIN_USER_ID,
]);
check('Final settlement repayment recorded', $rep4 !== false, "got $rep4");
$loanFinal = $loanModel->find((int)$loanId);
check('Loan fully settled (outstanding=0, status=completed)', (float)$loanFinal['outstanding'] == 0.0 && $loanFinal['status'] === 'completed', json_encode($loanFinal));

// Trial balance check: total debits = total credits across everything posted so far
$tb = $pdo->query("SELECT SUM(debit) d, SUM(credit) c FROM journal_lines")->fetch();
check('Trial Balance: total debits = total credits', abs($tb['d'] - $tb['c']) < 0.01, json_encode($tb));

// ================================================================
// 4. DELETION SAFETY — orphan prevention
// ================================================================
section('4. Deletion safety — journal reversal, no unexplained orphan');

// --- Loan deletion ---
$loanNumber2 = $loanModel->generateLoanNumber();
$loanId2 = $loanModel->create([
    'loan_number' => $loanNumber2, 'member_id' => $member['member_id'], 'loan_type_id' => 1,
    'loan_amount' => 200000, 'interest_rate' => 10.0, 'interest_amount' => 20000,
    'total_payable' => 220000, 'outstanding' => 220000, 'monthly_installment' => 220000,
    'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+1 month')),
    'disbursement_date' => date('Y-m-d'), 'disbursement_method' => 'Cash',
    'loan_period_months' => 1, 'status' => 'active',
]);
$disb2 = $loanModel->postDisbursement((int)$loanId2, $ADMIN_USER_ID);
$deleted2 = $loanModel->delete((int)$loanId2, $ADMIN_USER_ID);
check('Loan with a posted journal can be deleted', $deleted2 === true);
$reversalRow = $pdo->prepare("SELECT id FROM journal_entries WHERE reversal_of_id=?");
$reversalRow->execute([$disb2['journal_entry_id']]);
check('Deleting the loan produced a reversal entry linked via reversal_of_id (no unexplained orphan)', $reversalRow->fetch() !== false);
$origStillExists = $pdo->prepare("SELECT id FROM journal_entries WHERE id=?");
$origStillExists->execute([$disb2['journal_entry_id']]);
check('Original disbursement journal entry still exists (never deleted/mutated)', $origStillExists->fetch() !== false);
$loanGone = $loanModel->find((int)$loanId2);
check('Loan row itself is gone', $loanGone === false);

// --- Savings deletion ---
$dep3 = $savingsModel->recordDepositWithPosting([
    'member_id' => $member['member_id'], 'savings_account_id' => $member['account_id'],
    'transaction_type' => 'deposit', 'amount' => 15000, 'payment_method' => 'Cash',
    'transaction_date' => date('Y-m-d'), 'receipt_number' => $savingsModel->generateReceiptNumber(),
    'recorded_by' => $ADMIN_USER_ID,
], $ADMIN_USER_ID);
$deletedSav = $savingsModel->delete((int)$dep3['id'], $ADMIN_USER_ID);
check('Savings row with a posted journal can be deleted', $deletedSav === true);
$savReversal = $pdo->prepare("SELECT id FROM journal_entries WHERE reversal_of_id=?");
$savReversal->execute([$dep3['journal_entry_id']]);
check('Deleting the savings row produced a reversal entry', $savReversal->fetch() !== false);

// --- Repayment deletion ---
$deletedRep = $repaymentModel->delete((int)$rep2, $ADMIN_USER_ID);
check('Repayment with a posted journal can be deleted', $deletedRep === true);
$repReversal = $pdo->prepare("SELECT id FROM journal_entries WHERE reversal_of_id=?");
$repReversal->execute([$rep2row['journal_entry_id']]);
check('Deleting the repayment produced a reversal entry', $repReversal->fetch() !== false);

// ================================================================
// 5. loan_installments absence handled gracefully (not silently fatal)
// ================================================================
section('5. loan_installments failure is handled gracefully (Stage 7E table absent from this test DB)');
try {
    $loanModel->generateInstallments((int)$loanId, 100000, 3, date('Y-m-d'));
    check('generateInstallments() does not throw when loan_installments is unavailable', true);
} catch (Throwable $e) {
    check('generateInstallments() does not throw when loan_installments is unavailable', false, $e->getMessage());
}

// ================================================================
// SUMMARY
// ================================================================
section('SUMMARY');
echo "PASS: $pass\nFAIL: $fail\n";
if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) echo "  - $f\n";
}
echo "\nDONE\n";
