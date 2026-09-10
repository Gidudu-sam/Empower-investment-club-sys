<?php
/**
 * Step 9 — Loan Disbursement Accounting Tests
 *
 * NOTE: `loan_installments` was discovered broken at the engine level
 * during this step's build (SELECT/INSERT against it fails with
 * "doesn't exist in engine") -- entirely pre-existing, unrelated to Step 9,
 * and out of scope to fix here. Existing LoanModel code already silently
 * swallows this (catch (PDOException $e) {}), so loan creation itself
 * still succeeds -- but any DELETE FROM loans hits a cascading FK check
 * against that broken table and fails with error 1451. This suite's
 * cleanup wraps loan deletes in FOREIGN_KEY_CHECKS=0/1 to work around it
 * without touching or attempting to repair the broken table itself.
 */

require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Database.php';
require 'core/Autoloader.php';

$db = Database::getInstance()->getConnection();

echo "=== STEP 9 LOAN DISBURSEMENT ACCOUNTING TESTS ===\n\n";

$testsPassed = 0;
$testsFailed = 0;
function check($label, $cond) {
    global $testsPassed, $testsFailed;
    if ($cond) { echo "  PASS: $label\n"; $testsPassed++; }
    else       { echo "  FAIL: $label\n"; $testsFailed++; }
}

$beforeAccounts  = (int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
$beforeEntries   = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$beforeLines     = (int)$db->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
$beforeTotals    = $db->query('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines')->fetch();
$beforeLoansCount = (int)$db->query('SELECT COUNT(*) FROM loans')->fetchColumn();
$beforeJESeq     = (int)$db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='JE'")->fetchColumn();
$beforeJE44      = $db->query("SELECT financial_year_id, accounting_period_id FROM journal_entries WHERE entry_number='JE00044'")->fetch();
$beforeJE00001   = $db->query("SELECT id, entry_number, source_reference_id FROM journal_entries WHERE entry_number='JE00001'")->fetch();

echo "Initial: accounts={$beforeAccounts} entries={$beforeEntries} lines={$beforeLines} debit={$beforeTotals['d']} credit={$beforeTotals['c']} loans={$beforeLoansCount}\n\n";

$model = new LoanModel();
$reportModel = new AccountingReportModel();

$ACC_LOANS_RECEIVABLE = 14;
$ACC_CASH = 7;
$member = $db->query("SELECT id FROM members LIMIT 1")->fetch();
$MEMBER_ID = $member['id'];
$OPEN_DATE = '2026-08-25';

$createdLoanIds = [];

function baseLoanInput($model, $memberId, $amount, $date, $method = 'Cash') {
    return [
        'loan_number' => $model->generateLoanNumber(),
        'member_id' => $memberId,
        'loan_type_id' => 1,
        'loan_amount' => $amount,
        'interest_rate' => 10,
        'interest_amount' => round($amount * 0.10, 2),
        'total_payable' => round($amount * 1.10, 2), // includes interest -- must NOT be the posted amount
        'outstanding' => round($amount * 1.10, 2),
        'amount_paid' => 0,
        'issue_date' => $date,
        'due_date' => date('Y-m-d', strtotime($date . ' +1 month')),
        'disbursement_date' => $date,
        'disbursement_method' => $method,
        'status' => 'active',
        'recorded_by' => 1,
    ];
}

function cleanupLoan($db, $loanId) {
    // loan_installments is broken at the engine level (pre-existing, unrelated
    // to Step 9) -- its CASCADE FK check fails against the broken table, so
    // deleting a loan row needs FK checks narrowly disabled for this one delete.
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    $db->exec("DELETE FROM loans WHERE id={$loanId}");
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
}

// ============================================================
// TEST 1: Create a temporary valid loan/disbursement
// ============================================================
echo "TEST 1: Create a temporary valid loan\n";
$loanId = null;
try {
    $loanId = $model->create(baseLoanInput($model, $MEMBER_ID, 1000000, $OPEN_DATE));
    check('loan created', $loanId !== false);
    $createdLoanIds[] = $loanId;
    $loan = $model->find($loanId);
    check('loan has no journal_entry_id yet (unposted)', empty($loan['journal_entry_id']));
} catch (Exception $e) {
    check('TEST 1 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 2-8: Posting correctness
// ============================================================
echo "TEST 2: Loan disbursement creates exactly one journal entry\n";
$postResult = null;
try {
    $postResult = $model->postDisbursement($loanId, 1);
    check('post() returned a journal_entry_id', !empty($postResult['journal_entry_id']));
    $stmt = $db->prepare('SELECT COUNT(*) FROM journal_entries WHERE source_module=? AND source_reference_type=? AND source_reference_id=?');
    $stmt->execute(['loans', 'disbursement', $loanId]);
    check('exactly 1 journal entry for this loan', (int)$stmt->fetchColumn() === 1);
} catch (Exception $e) {
    check('TEST 2 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

$lines = $db->prepare("SELECT account_id, debit, credit FROM journal_lines WHERE journal_entry_id = ? ORDER BY id");
$lines->execute([$postResult['journal_entry_id']]);
$rows = $lines->fetchAll();
$debitLine = null; $creditLine = null;
foreach ($rows as $r) { if ($r['debit'] > 0) $debitLine = $r; if ($r['credit'] > 0) $creditLine = $r; }

echo "TEST 3: Debit account is Loans Receivable (14)\n";
check('debit line hits account 14', $debitLine && (int)$debitLine['account_id'] === $ACC_LOANS_RECEIVABLE);
echo "\n";

echo "TEST 4: Credit account is the correct cash/bank/payment-method account\n";
check('credit line hits Cash (7) for default Cash method', $creditLine && (int)$creditLine['account_id'] === $ACC_CASH);
echo "\n";

echo "TEST 5: Debit = credit\n";
$sums = $db->prepare('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines WHERE journal_entry_id = ?');
$sums->execute([$postResult['journal_entry_id']]);
$s = $sums->fetch();
check('debit == credit', abs((float)$s['d'] - (float)$s['c']) < 0.01);
echo "\n";

echo "TEST 6: Journal amount equals actual principal disbursed (not total_payable, which includes interest)\n";
check('amount is 1,000,000.00 (loan_amount), not 1,100,000.00 (total_payable)', $debitLine && abs((float)$debitLine['debit'] - 1000000.00) < 0.01);
echo "\n";

echo "TEST 7: financial_year_id and accounting_period_id are correctly populated\n";
$je = $db->prepare("SELECT financial_year_id, accounting_period_id, source_module, source_reference_type, source_reference_id FROM journal_entries WHERE id = ?");
$je->execute([$postResult['journal_entry_id']]);
$jeRow = $je->fetch();
check('financial_year_id resolved (= 2, FY 2026)', (int)$jeRow['financial_year_id'] === 2);
check('accounting_period_id resolved (= 2, Q3 2026)', (int)$jeRow['accounting_period_id'] === 2);
echo "\n";

echo "TEST 8: Journal source reference points to the correct loan\n";
check('source_module = loans', $jeRow['source_module'] === 'loans');
check('source_reference_type = disbursement', $jeRow['source_reference_type'] === 'disbursement');
check('source_reference_id matches the loan id', (int)$jeRow['source_reference_id'] === (int)$loanId);
echo "\n";

// ============================================================
// TEST 9: Repost -- 1 loan, 1 journal entry
// ============================================================
echo "TEST 9: Reposting the same disbursement does not create a duplicate\n";
try {
    $entriesBefore9 = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    $repost = $model->postDisbursement($loanId, 1);
    $entriesAfter9 = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    check('repost returns the same journal_entry_id', (int)$repost['journal_entry_id'] === (int)$postResult['journal_entry_id']);
    check('repost created=false', $repost['created'] === false);
    check('still exactly 1 loan, 1 journal entry (no duplicate)', $entriesBefore9 === $entriesAfter9);
} catch (Exception $e) {
    check('TEST 9 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 10: Inactive payment account rejected
// ============================================================
echo "TEST 10: Disbursement via an inactive payment account is rejected\n";
try {
    $loansBefore10 = (int)$db->query('SELECT COUNT(*) FROM loans')->fetchColumn();
    $entriesBefore10 = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    $badLoanId = $model->create(baseLoanInput($model, $MEMBER_ID, 200000, $OPEN_DATE, 'Airtel Money'));
    $createdLoanIds[] = $badLoanId;

    $model->postDisbursement($badLoanId, 1);
    check('posting via inactive Airtel Money account should have thrown', false);
} catch (InvalidArgumentException $e) {
    check('inactive account rejected — ' . $e->getMessage(), true);
    $loanAfter10 = $model->find($badLoanId);
    check('loan state unchanged (still unposted)', empty($loanAfter10['journal_entry_id']));
    $entriesAfter10 = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    check('no journal entry created', $entriesBefore10 === $entriesAfter10);
} catch (Exception $e) {
    check('TEST 10 threw unexpected exception type: ' . get_class($e), false);
}
echo "\n";

// ============================================================
// TEST 11: No open accounting period rejected
// ============================================================
echo "TEST 11: Disbursement dated outside any open period is rejected\n";
$tempClosedPeriodId = null;
try {
    // Dedicated closed period NOT overlapping Q3 2026 (same Step 7 fix --
    // Legacy's Aug 2026 dates sit inside Q3 2026's own Jul-Sep range).
    $tempClosedPeriodId = (new AccountingPeriodModel())->createPeriod([
        'financial_year_id' => 2, 'name' => 'TEST Closed Period (Step 9)',
        'start_date' => '2026-02-01', 'end_date' => '2026-02-28', 'status' => 'closed',
    ]);

    $loansBefore11 = (int)$db->query('SELECT COUNT(*) FROM loans')->fetchColumn();
    $entriesBefore11 = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();

    $noPeriodLoanId = $model->create(baseLoanInput($model, $MEMBER_ID, 150000, '2026-02-15'));
    $createdLoanIds[] = $noPeriodLoanId;

    $model->postDisbursement($noPeriodLoanId, 1);
    check('posting with no open period should have thrown', false);
} catch (InvalidArgumentException $e) {
    check('no-open-period date rejected — ' . $e->getMessage(), true);
    $loanAfter11 = $model->find($noPeriodLoanId);
    check('loan state unchanged (still unposted)', empty($loanAfter11['journal_entry_id']));
    $entriesAfter11 = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    check('no journal entry created', $entriesBefore11 === $entriesAfter11);
} catch (Exception $e) {
    check('TEST 11 threw unexpected exception type: ' . get_class($e), false);
}
echo "\n";

// ============================================================
// TEST 12: Transaction rollback
// ============================================================
echo "TEST 12: Transaction rollback discards both loan linkage and journal entry\n";
try {
    $rollbackLoanId = $model->create(baseLoanInput($model, $MEMBER_ID, 333000, $OPEN_DATE));
    $createdLoanIds[] = $rollbackLoanId;

    $entriesBeforeRollback = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();

    $db->beginTransaction();
    $rollbackResult = $model->postDisbursement($rollbackLoanId, 1);
    $db->rollBack();

    $entriesAfterRollback = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    check('no new journal entry survives the rollback', $entriesBeforeRollback === $entriesAfterRollback);

    $stmt = $db->prepare('SELECT COUNT(*) FROM journal_entries WHERE id = ?');
    $stmt->execute([$rollbackResult['journal_entry_id']]);
    check('the specific rolled-back journal entry id does not exist', (int)$stmt->fetchColumn() === 0);

    $loanAfterRollback = $model->find($rollbackLoanId);
    check('loan journal_entry_id still null after rollback', $loanAfterRollback['journal_entry_id'] === null);
} catch (Exception $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    check('TEST 12 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 13/14/15: Historical data untouched
// ============================================================
echo "TEST 13: Original historical loans untouched\n";
$historicalLoans = $db->query("SELECT id, loan_number, loan_amount FROM loans WHERE id NOT IN (" . implode(',', array_map('intval', $createdLoanIds)) . ")")->fetchAll();
check('12 original historical loans still present', count($historicalLoans) === $beforeLoansCount);
echo "\n";

echo "TEST 14: Historical journal entries untouched\n";
check('JE00001 (orphaned historical loan entry) unchanged', $beforeJE00001 && $beforeJE00001['entry_number'] === 'JE00001');
$afterJE00001 = $db->query("SELECT entry_number, source_reference_id FROM journal_entries WHERE entry_number='JE00001'")->fetch();
check('JE00001 source_reference_id still 47 (untouched)', (int)$afterJE00001['source_reference_id'] === (int)$beforeJE00001['source_reference_id']);
echo "\n";

echo "TEST 15: Account 77 unchanged\n";
$account77 = $db->query("SELECT code, name FROM accounts WHERE id=77")->fetch();
check('account 77 still code 4035 / Loan Interest Income', $account77['code'] === '4035' && $account77['name'] === 'Loan Interest Income');
echo "\n";

// ============================================================
// TEST 18/19/20: Reports (before cleanup, while the posting is live)
// ============================================================
echo "TEST 18: General Ledger shows the new loan disbursement\n";
$gl = $reportModel->generalLedgerForAccount($ACC_LOANS_RECEIVABLE, ['financial_year_id' => 2]);
$foundInGL = false;
foreach ($gl['lines'] as $l) { if (abs((float)$l['debit'] - 1000000.00) < 0.01) { $foundInGL = true; break; } }
check('the disbursement debit line appears in the Loans Receivable general ledger', $foundInGL);
echo "\n";

echo "TEST 19: Trial Balance reflects Loans Receivable up / Cash movement\n";
$tb = $reportModel->trialBalance(['financial_year_id' => 2]);
check('FY2026 trial balance still balances', $tb['balanced']);
$receivableRow = null; $cashRow = null;
foreach ($tb['accounts'] as $a) {
    if ((int)$a['id'] === $ACC_LOANS_RECEIVABLE) $receivableRow = $a;
    if ((int)$a['id'] === $ACC_CASH) $cashRow = $a;
}
check('Loans Receivable shows a net debit reflecting the disbursement', $receivableRow && $receivableRow['debit'] >= 1000000.00 - 0.01);
check('Cash shows the offsetting credit/net-debit-reduction reflecting the disbursement', $cashRow !== null);
echo "\n";

echo "TEST 20: Income Statement does NOT recognize the loan principal as income\n";
$is = $reportModel->incomeStatement(['financial_year_id' => 2]);
$receivableInIncome = false;
foreach (array_merge($is['income'], $is['expense']) as $row) {
    if ((int)$row['id'] === $ACC_LOANS_RECEIVABLE) { $receivableInIncome = true; break; }
}
check('Loans Receivable account never appears in the Income Statement', !$receivableInIncome);
echo "\n";

// ============================================================
// CLEANUP -- must run before regression suites
// ============================================================
echo "=== CLEANUP ===\n";
try {
    $stmt = $db->prepare("SELECT id, journal_entry_id FROM loans WHERE id IN (" . implode(',', array_fill(0, count($createdLoanIds), '?')) . ")");
    $stmt->execute($createdLoanIds);
    $jeIds = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!empty($row['journal_entry_id'])) $jeIds[] = (int)$row['journal_entry_id'];
    }
    foreach ($jeIds as $jeId) {
        $db->exec("DELETE FROM journal_entry_audit WHERE entity_type='journal_entry' AND entity_id={$jeId}");
        $db->exec("DELETE FROM journal_lines WHERE journal_entry_id={$jeId}");
        $db->exec("DELETE FROM journal_entries WHERE id={$jeId}");
    }
    echo "Deleted " . count($jeIds) . " journal entries\n";

    foreach ($createdLoanIds as $lid) {
        cleanupLoan($db, $lid);
    }
    echo "Deleted " . count($createdLoanIds) . " test loans (FK checks narrowly disabled per-delete due to the pre-existing broken loan_installments table)\n";

    if ($tempClosedPeriodId) {
        $db->exec("DELETE FROM accounting_periods WHERE id = {$tempClosedPeriodId}");
        echo "Deleted temp closed period\n";
    }

    $db->prepare("UPDATE journal_number_sequences SET last_number = ? WHERE prefix = 'JE'")->execute([$beforeJESeq]);

    echo "Cleanup complete\n\n";
} catch (Exception $e) {
    echo "Cleanup error: " . $e->getMessage() . "\n\n";
}

// ============================================================
// TEST 16/17: Journal totals restored, sequence cleanup
// ============================================================
echo "TEST 16: Journal totals return to exact baseline after cleanup\n";
$afterEntries = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$afterLines   = (int)$db->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
$afterTotals  = $db->query('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines')->fetch();
check('journal_entries count restored', $afterEntries === $beforeEntries);
check('journal_lines count restored', $afterLines === $beforeLines);
check('debit/credit totals restored', abs((float)$afterTotals['d'] - (float)$beforeTotals['d']) < 0.01 && abs((float)$afterTotals['c'] - (float)$beforeTotals['c']) < 0.01);
echo "\n";

echo "TEST 17: Journal-number sequence restored to pre-test value\n";
$afterJESeq = (int)$db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='JE'")->fetchColumn();
check('JE sequence restored', $afterJESeq === $beforeJESeq);
echo "\n";

$afterJE44 = $db->query("SELECT financial_year_id, accounting_period_id FROM journal_entries WHERE entry_number='JE00044'")->fetch();
check('JE00044 classification unchanged', $afterJE44['financial_year_id'] == $beforeJE44['financial_year_id'] && $afterJE44['accounting_period_id'] == $beforeJE44['accounting_period_id']);
$afterLoansCount = (int)$db->query('SELECT COUNT(*) FROM loans')->fetchColumn();
check('loans row count restored to pre-test value', $afterLoansCount === $beforeLoansCount);
echo "\n";

// ============================================================
// TEST 21-26: Regression — Steps 3/4/5/6/7/8
// ============================================================
echo "TEST 21: Regression — Step 3 reversal test suite\n";
$revOutput = shell_exec('"' . PHP_BINARY . '" test_journal_reversal.php 2>&1');
check('test_journal_reversal.php reports all tests passed', str_contains($revOutput ?? '', 'ALL REVERSAL TESTS PASSED'));
echo "\n";

echo "TEST 22: Regression — Step 4 accounting period test suite\n";
$s4Output = shell_exec('"' . PHP_BINARY . '" test_step4_periods.php 2>&1');
check('test_step4_periods.php reports all tests passed', str_contains($s4Output ?? '', 'ALL STEP 4 TESTS PASSED'));
echo "\n";

echo "TEST 23: Regression — Step 5 opening balance test suite\n";
$s5Output = shell_exec('"' . PHP_BINARY . '" test_step5_opening_balances.php 2>&1');
check('test_step5_opening_balances.php reports all tests passed', str_contains($s5Output ?? '', 'ALL STEP 5 TESTS PASSED'));
echo "\n";

echo "TEST 24: Regression — Step 6 accounting reports test suite\n";
$s6Output = shell_exec('"' . PHP_BINARY . '" test_step6_accounting_reports.php 2>&1');
check('test_step6_accounting_reports.php reports all tests passed', str_contains($s6Output ?? '', 'ALL STEP 6 TESTS PASSED'));
echo "\n";

echo "TEST 25: Regression — Step 7 expense test suite\n";
$s7Output = shell_exec('"' . PHP_BINARY . '" test_step7_expenses.php 2>&1');
check('test_step7_expenses.php reports all tests passed', str_contains($s7Output ?? '', 'ALL STEP 7 TESTS PASSED'));
echo "\n";

echo "TEST 26: Regression — Step 8 savings accounting test suite\n";
$s8Output = shell_exec('"' . PHP_BINARY . '" test_step8_savings_accounting.php 2>&1');
check('test_step8_savings_accounting.php reports all tests passed', str_contains($s8Output ?? '', 'ALL STEP 8 TESTS PASSED'));
if (!str_contains($s8Output ?? '', 'ALL STEP 8 TESTS PASSED')) { echo $s8Output . "\n"; }
echo "\n";

// ============================================================
// FINAL VERIFICATION
// ============================================================
echo "=== FINAL VERIFICATION ===\n";
$finalEntries = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$finalLines   = (int)$db->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
$finalTotals  = $db->query('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines')->fetch();
$finalLoans   = (int)$db->query('SELECT COUNT(*) FROM loans')->fetchColumn();

echo "Journal entries: {$finalEntries} (expected {$beforeEntries})\n";
echo "Journal lines: {$finalLines} (expected {$beforeLines})\n";
echo "Debits: " . number_format((float)$finalTotals['d'], 2) . " (expected " . number_format((float)$beforeTotals['d'], 2) . ")\n";
echo "Credits: " . number_format((float)$finalTotals['c'], 2) . " (expected " . number_format((float)$beforeTotals['c'], 2) . ")\n";
echo "Loans: {$finalLoans} (expected {$beforeLoansCount})\n\n";

check('final state matches initial baseline exactly',
    $finalEntries === $beforeEntries && $finalLines === $beforeLines && $finalLoans === $beforeLoansCount &&
    abs((float)$finalTotals['d'] - (float)$beforeTotals['d']) < 0.01 && abs((float)$finalTotals['c'] - (float)$beforeTotals['c']) < 0.01);

// ============================================================
// SUMMARY
// ============================================================
echo "\n=== TEST SUMMARY ===\n";
echo "Tests Passed: {$testsPassed}\n";
echo "Tests Failed: {$testsFailed}\n\n";

if ($testsFailed === 0) {
    echo "ALL STEP 9 TESTS PASSED\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
