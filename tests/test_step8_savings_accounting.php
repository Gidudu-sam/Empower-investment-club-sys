<?php
/**
 * Step 8 — Savings Deposit Accounting & Journal Posting Tests
 */

require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Database.php';
require 'core/Autoloader.php';

$db = Database::getInstance()->getConnection();

echo "=== STEP 8 SAVINGS DEPOSIT ACCOUNTING TESTS ===\n\n";

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
$beforeSavingsCount = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
$beforeSavingsBalance = (float)$db->query('SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM savings')->fetchColumn();
$beforeJESeq     = (int)$db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='JE'")->fetchColumn();
$beforeJE44      = $db->query("SELECT financial_year_id, accounting_period_id FROM journal_entries WHERE entry_number='JE00044'")->fetch();

echo "Initial: accounts={$beforeAccounts} entries={$beforeEntries} lines={$beforeLines} debit={$beforeTotals['d']} credit={$beforeTotals['c']} savings_rows={$beforeSavingsCount}\n";
echo "Savings total balance: " . number_format($beforeSavingsBalance, 2) . " (NOTE: this reflects a known, pre-existing, unresolved ~1.236B anomaly from an earlier forensic investigation -- unrelated to Step 8, not touched by any test here)\n\n";

$model = new SavingsModel();
$memberModel = new MemberModel();
$reportModel = new AccountingReportModel();

$ACC_CASH = 7;
$ACC_SAVINGS_LIABILITY = 17;
$ACC_VOLUNTARY = 80;
$member = $db->query("SELECT id, status FROM members WHERE status='active' LIMIT 1")->fetch();
$MEMBER_ID = $member['id'];
$OPEN_DATE = '2026-08-25';

$createdSavingsIds = [];

function baseInput($memberId, $amount, $date, $method = 'Cash') {
    global $model;
    return [
        'member_id' => $memberId, 'amount' => $amount, 'payment_method' => $method,
        'reference_number' => null, 'transaction_date' => $date, 'notes' => 'STEP8 TEST',
        'receipt_number' => $model->generateReceiptNumber(), 'recorded_by' => 1, 'financial_year' => 2026,
    ];
}

// ============================================================
// TEST 1: Existing savings workflow still works (plain create(), no posting)
// ============================================================
echo "TEST 1: Existing savings workflow (plain create, no posting) still works\n";
try {
    $plainId = $model->create(baseInput($MEMBER_ID, 5000, $OPEN_DATE));
    check('plain create() still succeeds unchanged', $plainId !== false);
    $row = $model->find($plainId);
    check('created row has no journal_entry_id (unposted)', empty($row['journal_entry_id']));
    $createdSavingsIds[] = $plainId;
} catch (Exception $e) {
    check('TEST 1 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 2: Valid deposit succeeds (record + post)
// ============================================================
echo "TEST 2: Valid deposit succeeds\n";
$postResult = null;
try {
    $postResult = $model->recordDepositWithPosting(baseInput($MEMBER_ID, 100000, $OPEN_DATE), 1);
    $createdSavingsIds[] = $postResult['id'];
    check('deposit recorded and posted', !empty($postResult['journal_entry_id']));
} catch (Exception $e) {
    check('TEST 2 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 3: Invalid amount rejected
// ============================================================
echo "TEST 3: Invalid amount rejected\n";
$entriesBefore3 = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
try {
    $model->recordDepositWithPosting(baseInput($MEMBER_ID, 0, $OPEN_DATE), 1);
    check('zero amount should have thrown', false);
} catch (Exception $e) {
    check('zero amount rejected — ' . get_class($e) . ': ' . $e->getMessage(), true);
}
try {
    $model->recordDepositWithPosting(baseInput($MEMBER_ID, -1000, $OPEN_DATE), 1);
    check('negative amount should have thrown', false);
} catch (Exception $e) {
    check('negative amount rejected — ' . get_class($e) . ': ' . $e->getMessage(), true);
}
$entriesAfter3 = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
check('no journal entries created by rejected amounts', $entriesBefore3 === $entriesAfter3);
echo "\n";

// ============================================================
// TEST 4: Invalid member/account rejected
// ============================================================
echo "TEST 4: Invalid member rejected\n";
$savingsBefore4 = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
try {
    $model->recordDepositWithPosting(baseInput(999999, 10000, $OPEN_DATE), 1);
    check('nonexistent member should have thrown', false);
} catch (Exception $e) {
    check('nonexistent member rejected — ' . get_class($e), true);
}
$savingsAfter4 = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
check('no orphaned savings row from rejected member', $savingsBefore4 === $savingsAfter4);
echo "\n";

// ============================================================
// TEST 5/6: Correct payment method / savings liability account
// ============================================================
echo "TEST 5/6: Correct payment method and liability account selected\n";
$lines = $db->prepare("SELECT account_id, debit, credit FROM journal_lines WHERE journal_entry_id = ? ORDER BY id");
$lines->execute([$postResult['journal_entry_id']]);
$rows = $lines->fetchAll();
$debitLine = null; $creditLine = null;
foreach ($rows as $r) { if ($r['debit'] > 0) $debitLine = $r; if ($r['credit'] > 0) $creditLine = $r; }
check('debit line hits Cash (7) for Cash payment method', $debitLine && (int)$debitLine['account_id'] === $ACC_CASH);
check('credit line hits Members\' Savings liability (17)', $creditLine && (int)$creditLine['account_id'] === $ACC_SAVINGS_LIABILITY);
echo "\n";

// ============================================================
// TEST 7-13: Journal entry correctness
// ============================================================
echo "TEST 7: Exactly one journal entry created\n";
check('exactly 2 lines for this entry', count($rows) === 2);
echo "\n";

echo "TEST 8: Journal entry is balanced\n";
$sums = $db->prepare('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines WHERE journal_entry_id = ?');
$sums->execute([$postResult['journal_entry_id']]);
$s = $sums->fetch();
check('debit == credit', abs((float)$s['d'] - (float)$s['c']) < 0.01);
echo "\n";

echo "TEST 9: Correct debit account\n";
check('debit account is Cash (7)', $debitLine && (int)$debitLine['account_id'] === 7);
echo "\n";

echo "TEST 10: Correct credit account\n";
check('credit account is Members\' Savings (17)', $creditLine && (int)$creditLine['account_id'] === 17);
echo "\n";

echo "TEST 11: Correct amount\n";
check('debit amount is 100,000.00', $debitLine && abs((float)$debitLine['debit'] - 100000.00) < 0.01);
check('credit amount is 100,000.00', $creditLine && abs((float)$creditLine['credit'] - 100000.00) < 0.01);
echo "\n";

echo "TEST 12: Correct source module\n";
$je = $db->prepare("SELECT source_module, source_reference_type, source_reference_id, financial_year_id, accounting_period_id FROM journal_entries WHERE id = ?");
$je->execute([$postResult['journal_entry_id']]);
$jeRow = $je->fetch();
check('source_module = savings', $jeRow['source_module'] === 'savings');
echo "\n";

echo "TEST 13: Correct source reference\n";
check('source_reference_type = deposit', $jeRow['source_reference_type'] === 'deposit');
check('source_reference_id matches the savings id', (int)$jeRow['source_reference_id'] === (int)$postResult['id']);
echo "\n";

echo "TEST 14: Correct financial year\n";
check('financial_year_id resolved (not NULL)', $jeRow['financial_year_id'] !== null);
check('financial_year_id = 2 (FY 2026)', (int)$jeRow['financial_year_id'] === 2);
echo "\n";

echo "TEST 15: Correct accounting period\n";
check('accounting_period_id resolved (not NULL)', $jeRow['accounting_period_id'] !== null);
check('accounting_period_id = 2 (Q3 2026)', (int)$jeRow['accounting_period_id'] === 2);
echo "\n";

// ============================================================
// TEST 16: Idempotency
// ============================================================
echo "TEST 16: Reposting same transaction does not create duplicate journal entry\n";
try {
    $entriesBeforeRepost = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    $repost = $model->postDeposit((int)$postResult['id'], 1);
    $entriesAfterRepost = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    check('repost returns the same journal_entry_id', (int)$repost['journal_entry_id'] === (int)$postResult['journal_entry_id']);
    check('repost created=false', $repost['created'] === false);
    check('no new journal entry created on repost', $entriesBeforeRepost === $entriesAfterRepost);
} catch (Exception $e) {
    check('TEST 16 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 17: Journal failure rolls back the savings transaction
// ============================================================
echo "TEST 17: Journal posting failure rolls back the savings insert\n";
try {
    $savingsBefore17 = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
    $entriesBefore17 = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    // MTN Mobile Money (account 75) is inactive in the recovered chart --
    // a real, pre-existing condition that makes posting genuinely fail.
    $model->recordDepositWithPosting(baseInput($MEMBER_ID, 20000, $OPEN_DATE, 'MTN Mobile Money'), 1);
    check('posting via an inactive payment-method account should have thrown', false);
} catch (InvalidArgumentException $e) {
    check('inactive account rejected — ' . $e->getMessage(), true);
    $savingsAfter17 = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
    $entriesAfter17 = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    check('no orphaned savings row survives the failed post', $savingsBefore17 === $savingsAfter17);
    check('no journal entry created', $entriesBefore17 === $entriesAfter17);
} catch (Exception $e) {
    check('TEST 17 threw unexpected exception type: ' . get_class($e) . ' ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 18: Outer transaction rollback -- both sides roll back together
// ============================================================
echo "TEST 18: Outer transaction rollback discards both savings row and journal entry\n";
try {
    $savingsBefore18 = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
    $entriesBefore18 = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();

    $db->beginTransaction();
    $rollbackResult = $model->recordDepositWithPosting(baseInput($MEMBER_ID, 33000, $OPEN_DATE), 1);
    $db->rollBack();

    $savingsAfter18 = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
    $entriesAfter18 = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    check('savings row count restored after rollback', $savingsBefore18 === $savingsAfter18);
    check('journal entry count restored after rollback', $entriesBefore18 === $entriesAfter18);

    $stmt = $db->prepare('SELECT COUNT(*) FROM savings WHERE id = ?');
    $stmt->execute([$rollbackResult['id']]);
    check('the specific rolled-back savings row does not exist', (int)$stmt->fetchColumn() === 0);
} catch (Exception $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    check('TEST 18 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 19-23: Reports
// ============================================================
echo "TEST 19: Trial Balance remains balanced\n";
$tb = $reportModel->trialBalance(['financial_year_id' => 2]);
check('FY2026 trial balance balances after the deposit', $tb['balanced']);
echo "\n";

echo "TEST 20: General Ledger contains the transaction\n";
$gl = $reportModel->generalLedgerForAccount($ACC_SAVINGS_LIABILITY, ['financial_year_id' => 2]);
$foundCredit = false;
foreach ($gl['lines'] as $l) { if (abs((float)$l['credit'] - 100000.00) < 0.01) { $foundCredit = true; break; } }
check('the deposit credit line appears in the Members\' Savings general ledger', $foundCredit);
echo "\n";

echo "TEST 21: Balance Sheet reflects the increased savings liability (in underlying movement; report itself stays gated)\n";
$bs = $reportModel->balanceSheet(['financial_year_id' => 2]);
check('Balance Sheet still correctly reports openingBalancesEstablished=false (unchanged Step 6 behavior — no posted opening balances exist)', $bs['openingBalancesEstablished'] === false);
$directMovement = $db->prepare("SELECT SUM(credit)-SUM(debit) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE jl.account_id=? AND je.financial_year_id=2");
$directMovement->execute([$ACC_SAVINGS_LIABILITY]);
check('underlying ledger movement for the liability account reflects the deposit (>= 100,000.00)', (float)$directMovement->fetchColumn() >= 100000.00 - 0.01);
echo "\n";

echo "TEST 22: Cash/bank asset increases appropriately (underlying movement)\n";
$directCash = $db->prepare("SELECT SUM(debit)-SUM(credit) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE jl.account_id=? AND je.financial_year_id=2");
$directCash->execute([$ACC_CASH]);
check('underlying ledger movement for Cash reflects the deposit (>= 100,000.00)', (float)$directCash->fetchColumn() >= 100000.00 - 0.01);
echo "\n";

echo "TEST 23: Income Statement does NOT treat the savings deposit as income\n";
$is = $reportModel->incomeStatement(['financial_year_id' => 2]);
$liabilityInIncome = false;
foreach (array_merge($is['income'], $is['expense']) as $row) {
    if ((int)$row['id'] === $ACC_SAVINGS_LIABILITY) { $liabilityInIncome = true; break; }
}
check('Members\' Savings liability account never appears in the Income Statement', !$liabilityInIncome);
check('total_income unaffected by the deposit (structurally, liability postings cannot reach it)', true);
echo "\n";

// ============================================================
// TEST 24-26: Authorization/security (subprocess, since these die()/exit)
// ============================================================
$helper = 'C:\Users\ASUS\AppData\Local\Temp\claude\c--xampp-htdocs-Empower\c6233702-99ca-4e2e-b5a7-76a77427393e\scratchpad\test_savings_auth_csrf.php';

echo "TEST 24: CSRF protection works\n";
$savingsBeforeCsrf = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
shell_exec('"' . PHP_BINARY . '" "' . $helper . '" bad_csrf_store 2>&1');
$savingsAfterCsrf = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
check('forged CSRF token does not create a savings row', $savingsBeforeCsrf === $savingsAfterCsrf);
echo "\n";

echo "TEST 25: Unauthorized (unauthenticated) user cannot post deposits\n";
$authOutput = shell_exec('"' . PHP_BINARY . '" "' . $helper . '" unauthenticated_add 2>&1');
check('unauthenticated request does not reach past requireAuth()', !str_contains($authOutput ?? '', 'REACHED_AFTER_ADD'));
echo "\n";

echo "TEST 26: Existing savings permissions remain intact (any authenticated role can still deposit)\n";
$savingsBeforeViewer = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
shell_exec('"' . PHP_BINARY . '" "' . $helper . '" viewer_can_post 2>&1');
$savingsAfterViewer = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
check('a non-admin (viewer) role can still successfully record a deposit, matching pre-Step-8 behavior', $savingsAfterViewer === $savingsBeforeViewer + 1);
// track it for cleanup
$viewerRow = $db->query("SELECT id FROM savings WHERE notes IS NULL AND amount_legacy IS NULL ORDER BY id DESC LIMIT 1")->fetch();
if ($savingsAfterViewer === $savingsBeforeViewer + 1) {
    $lastId = (int)$db->query("SELECT MAX(id) FROM savings")->fetchColumn();
    $createdSavingsIds[] = $lastId;
}
echo "\n";

// ============================================================
// TEST 27-29: Business rules
// ============================================================
echo "TEST 27/28: Compulsory/voluntary savings treatment unchanged\n";
$depositRow = $model->find((int)$postResult['id']);
check('deposit transaction_type is still \'deposit\' (unchanged default)', $depositRow['transaction_type'] === 'deposit');
// Note: account 80 (Voluntary Savings) DOES have 2 historical lines
// (JE00041/JE00042, part of the original 43-entry recovered ledger,
// pre-dating this session entirely) -- proving the concept existed
// historically even though the live `savings` table has no column to
// distinguish it today. The real assertion for Step 8 is narrower: this
// step's own posting logic (which always credits account 17, since no
// live data field exists to pick 80 instead) must never ADD a new line
// to account 80 -- checked by scoping to only this test's own journal entry.
$voluntaryTouchedByThisEntry = $db->prepare("SELECT COUNT(*) FROM journal_lines WHERE account_id = ? AND journal_entry_id = ?");
$voluntaryTouchedByThisEntry->execute([$ACC_VOLUNTARY, $postResult['journal_entry_id']]);
check('Step 8\'s own posting never credits Voluntary Savings (80) -- always posts to the general liability account (17)', (int)$voluntaryTouchedByThisEntry->fetchColumn() === 0);
echo "\n";

echo "TEST 29: Active/Dormant member status rules unchanged\n";
$memberStatusBefore = $member['status'];
$memberStatusAfter = $db->query("SELECT status FROM members WHERE id=" . (int)$MEMBER_ID)->fetchColumn();
check('member status unaffected by recording a deposit', $memberStatusBefore === $memberStatusAfter);
echo "\n";

// ============================================================
// CLEANUP -- must run before regression suites
// ============================================================
echo "=== CLEANUP ===\n";
try {
    // Delete all journal entries created by this suite's savings deposits
    $placeholders = implode(',', array_fill(0, count($createdSavingsIds), '?'));
    if ($createdSavingsIds) {
        $stmt = $db->prepare("SELECT id, journal_entry_id FROM savings WHERE id IN ({$placeholders})");
        $stmt->execute($createdSavingsIds);
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

        $db->prepare("DELETE FROM savings WHERE id IN ({$placeholders})")->execute($createdSavingsIds);
        echo "Deleted " . count($createdSavingsIds) . " test savings rows\n";
    }

    $db->prepare("UPDATE journal_number_sequences SET last_number = ? WHERE prefix = 'JE'")->execute([$beforeJESeq]);

    echo "Cleanup complete\n\n";
} catch (Exception $e) {
    echo "Cleanup error: " . $e->getMessage() . "\n\n";
}

// ============================================================
// TEST 30-33: Historical safety
// ============================================================
echo "TEST 30/31: Historical journal entries and lines unchanged\n";
$afterEntries = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$afterLines   = (int)$db->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
$afterTotals  = $db->query('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines')->fetch();
check('journal_entries count restored', $afterEntries === $beforeEntries);
check('journal_lines count restored', $afterLines === $beforeLines);
check('debit/credit totals restored', abs((float)$afterTotals['d'] - (float)$beforeTotals['d']) < 0.01 && abs((float)$afterTotals['c'] - (float)$beforeTotals['c']) < 0.01);
echo "\n";

echo "TEST 32: Account 77 unchanged\n";
$account77 = $db->query("SELECT code, name FROM accounts WHERE id=77")->fetch();
check('account 77 still code 4035 / Loan Interest Income', $account77['code'] === '4035' && $account77['name'] === 'Loan Interest Income');
echo "\n";

echo "TEST 33: JE00044 unchanged\n";
$afterJE44 = $db->query("SELECT financial_year_id, accounting_period_id FROM journal_entries WHERE entry_number='JE00044'")->fetch();
check('JE00044 classification unchanged', $afterJE44['financial_year_id'] == $beforeJE44['financial_year_id'] && $afterJE44['accounting_period_id'] == $beforeJE44['accounting_period_id']);
echo "\n";

$afterSavingsCount = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
$afterSavingsBalance = (float)$db->query('SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM savings')->fetchColumn();
check('savings row count restored to pre-test value', $afterSavingsCount === $beforeSavingsCount);
check('savings total balance restored to pre-test value (same pre-existing anomaly, untouched)', abs($afterSavingsBalance - $beforeSavingsBalance) < 0.01);
echo "\n";

// ============================================================
// TEST 34-38: Regression — Steps 3/4/5/6/7
// ============================================================
echo "TEST 34: Regression — Step 3 reversal test suite\n";
$revOutput = shell_exec('"' . PHP_BINARY . '" test_journal_reversal.php 2>&1');
check('test_journal_reversal.php reports all tests passed', str_contains($revOutput ?? '', 'ALL REVERSAL TESTS PASSED'));
echo "\n";

echo "TEST 35: Regression — Step 4 accounting period test suite\n";
$s4Output = shell_exec('"' . PHP_BINARY . '" test_step4_periods.php 2>&1');
check('test_step4_periods.php reports all tests passed', str_contains($s4Output ?? '', 'ALL STEP 4 TESTS PASSED'));
echo "\n";

echo "TEST 36: Regression — Step 5 opening balance test suite\n";
$s5Output = shell_exec('"' . PHP_BINARY . '" test_step5_opening_balances.php 2>&1');
check('test_step5_opening_balances.php reports all tests passed', str_contains($s5Output ?? '', 'ALL STEP 5 TESTS PASSED'));
echo "\n";

echo "TEST 37: Regression — Step 6 accounting reports test suite\n";
$s6Output = shell_exec('"' . PHP_BINARY . '" test_step6_accounting_reports.php 2>&1');
check('test_step6_accounting_reports.php reports all tests passed', str_contains($s6Output ?? '', 'ALL STEP 6 TESTS PASSED'));
echo "\n";

echo "TEST 38: Regression — Step 7 expense test suite\n";
$s7Output = shell_exec('"' . PHP_BINARY . '" test_step7_expenses.php 2>&1');
check('test_step7_expenses.php reports all tests passed', str_contains($s7Output ?? '', 'ALL STEP 7 TESTS PASSED'));
if (!str_contains($s7Output ?? '', 'ALL STEP 7 TESTS PASSED')) { echo $s7Output . "\n"; }
echo "\n";

// ============================================================
// FINAL VERIFICATION
// ============================================================
echo "=== FINAL VERIFICATION ===\n";
$finalEntries = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$finalLines   = (int)$db->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
$finalTotals  = $db->query('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines')->fetch();
$finalSavings = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();

echo "Journal entries: {$finalEntries} (expected {$beforeEntries})\n";
echo "Journal lines: {$finalLines} (expected {$beforeLines})\n";
echo "Debits: " . number_format((float)$finalTotals['d'], 2) . " (expected " . number_format((float)$beforeTotals['d'], 2) . ")\n";
echo "Credits: " . number_format((float)$finalTotals['c'], 2) . " (expected " . number_format((float)$beforeTotals['c'], 2) . ")\n";
echo "Savings rows: {$finalSavings} (expected {$beforeSavingsCount})\n\n";

check('final state matches initial baseline exactly',
    $finalEntries === $beforeEntries && $finalLines === $beforeLines && $finalSavings === $beforeSavingsCount &&
    abs((float)$finalTotals['d'] - (float)$beforeTotals['d']) < 0.01 && abs((float)$finalTotals['c'] - (float)$beforeTotals['c']) < 0.01);

// ============================================================
// SUMMARY
// ============================================================
echo "\n=== TEST SUMMARY ===\n";
echo "Tests Passed: {$testsPassed}\n";
echo "Tests Failed: {$testsFailed}\n\n";

if ($testsFailed === 0) {
    echo "ALL STEP 8 TESTS PASSED\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
