<?php
/**
 * Step 6 — Accounting Reports Tests
 * Trial Balance / General Ledger / Income Statement / Balance Sheet.
 * All reports are pure SELECT — no writes happen anywhere in this module,
 * so there is no test data to create/clean up for the report logic itself.
 */

require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Database.php';
require 'core/Autoloader.php';

$db = Database::getInstance()->getConnection();

echo "=== STEP 6 ACCOUNTING REPORTS TESTS ===\n\n";

$testsPassed = 0;
$testsFailed = 0;
function check($label, $cond) {
    global $testsPassed, $testsFailed;
    if ($cond) { echo "  PASS: $label\n"; $testsPassed++; }
    else       { echo "  FAIL: $label\n"; $testsFailed++; }
}

$beforeAccounts = (int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
$beforeEntries  = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$beforeLines    = (int)$db->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
$beforeTotals   = $db->query('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines')->fetch();
$beforeJE44     = $db->query("SELECT financial_year_id, accounting_period_id FROM journal_entries WHERE entry_number='JE00044'")->fetch();

echo "Initial state: accounts={$beforeAccounts} entries={$beforeEntries} lines={$beforeLines} debit={$beforeTotals['d']} credit={$beforeTotals['c']}\n\n";

$model = new AccountingReportModel();
$LEGACY_FY = 1;
$PROD_FY = 2;
$PROD_PERIOD = 2;

// ============================================================
// TEST 1: Trial Balance (Legacy FY) balances exactly
// ============================================================
echo "TEST 1: Trial Balance (Legacy FY) balances\n";
try {
    $tb = $model->trialBalance(['financial_year_id' => $LEGACY_FY]);
    check('trial balance is balanced', $tb['balanced']);
    check('difference is 0.00', abs($tb['difference']) < 0.01);
    check('total_debit == total_credit', abs($tb['total_debit'] - $tb['total_credit']) < 0.01);
    check('total_debit equals the known reconciled figure 3,079,161.00', abs($tb['total_debit'] - 3079161.00) < 0.01);
} catch (Exception $e) {
    check('TEST 1 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 2: General Ledger for account 77 reconciles with direct SQL
// ============================================================
echo "TEST 2: General Ledger (account 77) reconciles with journal_lines\n";
try {
    $gl = $model->generalLedgerForAccount(77, ['financial_year_id' => $LEGACY_FY]);
    $direct = $db->prepare("
        SELECT SUM(jl.debit) d, SUM(jl.credit) c FROM journal_lines jl
        JOIN journal_entries je ON je.id = jl.journal_entry_id
        WHERE jl.account_id = 77 AND je.financial_year_id = ?
    ");
    $direct->execute([$LEGACY_FY]);
    $d = $direct->fetch();

    $glDebit = array_sum(array_column($gl['lines'], 'debit'));
    $glCredit = array_sum(array_column($gl['lines'], 'credit'));
    check('GL debit total matches direct SQL', abs($glDebit - (float)$d['d']) < 0.01);
    check('GL credit total matches direct SQL', abs($glCredit - (float)$d['c']) < 0.01);
    check('GL line count is non-zero for a known-active account', count($gl['lines']) > 0);
} catch (Exception $e) {
    check('TEST 2 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 3: Income Statement (Legacy FY) — Loan Interest Income = 659,161
// ============================================================
echo "TEST 3: Income Statement Loan Interest Income figure\n";
try {
    $is = $model->incomeStatement(['financial_year_id' => $LEGACY_FY]);
    $interestRow = null;
    foreach ($is['income'] as $row) {
        if ((int)$row['id'] === 77) { $interestRow = $row; break; }
    }
    check('Loan Interest Income (account 77) present in income statement', $interestRow !== null);
    check('Loan Interest Income net == 659,161.00 (independent re-derivation of Phase 4 figure)',
        $interestRow !== null && abs($interestRow['net_amount'] - 659161.00) < 0.01);

    // Cross-check directly against journal_lines, not just against the model's own math
    $direct = $db->prepare("SELECT SUM(credit)-SUM(debit) AS net FROM journal_lines jl
        JOIN journal_entries je ON je.id=jl.journal_entry_id
        WHERE jl.account_id=77 AND je.financial_year_id=?");
    $direct->execute([$LEGACY_FY]);
    $directNet = (float)$direct->fetchColumn();
    check('matches direct SQL SUM(credit)-SUM(debit) for account 77', abs($directNet - 659161.00) < 0.01);
} catch (Exception $e) {
    check('TEST 3 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 4: Balance Sheet — opening balances not established
// ============================================================
echo "TEST 4: Balance Sheet opening-balances-not-established handling\n";
try {
    $postedCount = (int)$db->query("SELECT COUNT(*) FROM opening_balance_batches WHERE status='posted'")->fetchColumn();
    check('precondition: no posted opening balance batches exist', $postedCount === 0);

    $bs = $model->balanceSheet(['financial_year_id' => $LEGACY_FY]);
    check('openingBalancesEstablished is false', $bs['openingBalancesEstablished'] === false);
    check('no fabricated total_assets figure returned', $bs['total_assets'] === null);
    check('no fabricated total_liabilities figure returned', $bs['total_liabilities'] === null);
    check('no fabricated total_equity figure returned', $bs['total_equity'] === null);
} catch (Exception $e) {
    check('TEST 4 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 5: Accounting-period / financial-year filter — FY2026 is empty
// ============================================================
echo "TEST 5: FY 2026 (no real activity yet) reports correctly empty\n";
try {
    $tbEmpty = $model->trialBalance(['financial_year_id' => $PROD_FY]);
    check('FY2026 trial balance total_debit is 0.00', abs($tbEmpty['total_debit']) < 0.01);
    check('FY2026 trial balance total_credit is 0.00', abs($tbEmpty['total_credit']) < 0.01);
    check('FY2026 trial balance is trivially balanced (0=0)', $tbEmpty['balanced']);
    check('FY2026 trial balance still lists all 82 accounts (zero-activity accounts not hidden)', count($tbEmpty['accounts']) === 82);

    $isEmpty = $model->incomeStatement(['financial_year_id' => $PROD_FY]);
    check('FY2026 income statement net surplus is 0.00', abs($isEmpty['net_surplus']) < 0.01);

    $periodEmpty = $model->trialBalance(['accounting_period_id' => $PROD_PERIOD]);
    check('Q3 2026 period trial balance total_debit is 0.00', abs($periodEmpty['total_debit']) < 0.01);
} catch (Exception $e) {
    check('TEST 5 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 6: Financial-year filter distinguishes Legacy vs FY2026
// ============================================================
echo "TEST 6: Financial-year filter distinguishes Legacy vs FY2026\n";
try {
    $tbLegacy = $model->trialBalance(['financial_year_id' => $LEGACY_FY]);
    $tbProd   = $model->trialBalance(['financial_year_id' => $PROD_FY]);
    check('Legacy and FY2026 trial balances differ', abs($tbLegacy['total_debit'] - $tbProd['total_debit']) > 0.01);
} catch (Exception $e) {
    check('TEST 6 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 7: Date-range filter narrows results
// ============================================================
echo "TEST 7: Date-range filter narrows results\n";
try {
    $full = $model->trialBalance(['financial_year_id' => $LEGACY_FY]);
    // Legacy ledger spans 2026-08-20 to 2026-08-22 — a narrow single-day
    // window should produce a strictly smaller (or equal, never larger) total.
    $narrow = $model->trialBalance(['financial_year_id' => $LEGACY_FY, 'date_from' => '2026-08-20', 'date_to' => '2026-08-20']);
    check('narrow date window total_debit <= full-range total_debit', $narrow['total_debit'] <= $full['total_debit'] + 0.01);

    $outside = $model->trialBalance(['financial_year_id' => $LEGACY_FY, 'date_from' => '2020-01-01', 'date_to' => '2020-01-02']);
    check('date window outside all activity returns 0.00', abs($outside['total_debit']) < 0.01);
} catch (Exception $e) {
    check('TEST 7 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 8: Account filter (General Ledger) returns only that account
// ============================================================
echo "TEST 8: General Ledger account filter isolates one account\n";
try {
    $gl77 = $model->generalLedgerForAccount(77, ['financial_year_id' => $LEGACY_FY]);
    $onlyAccount77 = true;
    foreach ($gl77['lines'] as $l) {
        // generalLedgerForAccount's query is WHERE jl.account_id = 77 by construction;
        // this re-derives the same guarantee directly against journal_lines.
    }
    $directCount = $db->prepare("SELECT COUNT(*) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE jl.account_id=77 AND je.financial_year_id=?");
    $directCount->execute([$LEGACY_FY]);
    check('GL line count for account 77 matches a direct COUNT(*) on journal_lines', count($gl77['lines']) === (int)$directCount->fetchColumn());
} catch (Exception $e) {
    check('TEST 8 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 9: Zero-activity accounts are not hidden from Trial Balance
// ============================================================
echo "TEST 9: Trial Balance includes zero-activity accounts\n";
try {
    $tb = $model->trialBalance(['financial_year_id' => $LEGACY_FY]);
    check('all 82 accounts present regardless of activity', count($tb['accounts']) === 82);
    $zeroActivityFound = false;
    foreach ($tb['accounts'] as $a) {
        if ($a['debit'] == 0 && $a['credit'] == 0) { $zeroActivityFound = true; break; }
    }
    check('at least one zero-activity account is present (not filtered out)', $zeroActivityFound);
} catch (Exception $e) {
    check('TEST 9 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 10: No historical data mutation from any report call
// ============================================================
echo "TEST 10: No data mutation from report generation\n";
$afterAccounts = (int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
$afterEntries  = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$afterLines    = (int)$db->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
$afterTotals   = $db->query('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines')->fetch();
$afterJE44     = $db->query("SELECT financial_year_id, accounting_period_id FROM journal_entries WHERE entry_number='JE00044'")->fetch();
$account77     = $db->query("SELECT code, name FROM accounts WHERE id=77")->fetch();

check('accounts count unchanged', $afterAccounts === $beforeAccounts);
check('journal_entries count unchanged', $afterEntries === $beforeEntries);
check('journal_lines count unchanged', $afterLines === $beforeLines);
check('debit/credit totals unchanged', abs((float)$afterTotals['d'] - (float)$beforeTotals['d']) < 0.01 && abs((float)$afterTotals['c'] - (float)$beforeTotals['c']) < 0.01);
check('account 77 still code 4035 / Loan Interest Income', $account77['code'] === '4035' && $account77['name'] === 'Loan Interest Income');
check('JE00044 classification unchanged', $afterJE44['financial_year_id'] == $beforeJE44['financial_year_id'] && $afterJE44['accounting_period_id'] == $beforeJE44['accounting_period_id']);
echo "\n";

// ============================================================
// TEST 11-13: Regression — Steps 3/4/5 test suites
// ============================================================
echo "TEST 11: Regression — Step 3 reversal test suite\n";
$revOutput = shell_exec('"' . PHP_BINARY . '" test_journal_reversal.php 2>&1');
check('test_journal_reversal.php reports all tests passed', str_contains($revOutput ?? '', 'ALL REVERSAL TESTS PASSED'));
echo "\n";

echo "TEST 12: Regression — Step 4 accounting period test suite\n";
$s4Output = shell_exec('"' . PHP_BINARY . '" test_step4_periods.php 2>&1');
check('test_step4_periods.php reports all tests passed', str_contains($s4Output ?? '', 'ALL STEP 4 TESTS PASSED'));
echo "\n";

echo "TEST 13: Regression — Step 5 opening balance test suite\n";
$s5Output = shell_exec('"' . PHP_BINARY . '" test_step5_opening_balances.php 2>&1');
check('test_step5_opening_balances.php reports all tests passed', str_contains($s5Output ?? '', 'ALL STEP 5 TESTS PASSED'));
if (!str_contains($s5Output ?? '', 'ALL STEP 5 TESTS PASSED')) { echo $s5Output . "\n"; }
echo "\n";

// ============================================================
// FINAL VERIFICATION (post-regression, since 11-13 create/clean their own temp data)
// ============================================================
echo "=== FINAL VERIFICATION ===\n";
$finalAccounts = (int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
$finalEntries  = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$finalLines    = (int)$db->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
$finalTotals   = $db->query('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines')->fetch();

echo "Accounts: {$finalAccounts} (expected {$beforeAccounts})\n";
echo "Journal entries: {$finalEntries} (expected {$beforeEntries})\n";
echo "Journal lines: {$finalLines} (expected {$beforeLines})\n";
echo "Debits: " . number_format((float)$finalTotals['d'], 2) . " (expected " . number_format((float)$beforeTotals['d'], 2) . ")\n";
echo "Credits: " . number_format((float)$finalTotals['c'], 2) . " (expected " . number_format((float)$beforeTotals['c'], 2) . ")\n\n";

check('final state matches initial baseline exactly',
    $finalAccounts === $beforeAccounts && $finalEntries === $beforeEntries && $finalLines === $beforeLines &&
    abs((float)$finalTotals['d'] - (float)$beforeTotals['d']) < 0.01 && abs((float)$finalTotals['c'] - (float)$beforeTotals['c']) < 0.01);

// ============================================================
// SUMMARY
// ============================================================
echo "\n=== TEST SUMMARY ===\n";
echo "Tests Passed: {$testsPassed}\n";
echo "Tests Failed: {$testsFailed}\n\n";

if ($testsFailed === 0) {
    echo "ALL STEP 6 TESTS PASSED\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
