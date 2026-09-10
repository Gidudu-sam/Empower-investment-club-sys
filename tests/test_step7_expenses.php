<?php
/**
 * Step 7 — Expense Module & Accounting Posting Tests
 */

require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Database.php';
require 'core/Autoloader.php';

$db = Database::getInstance()->getConnection();

echo "=== STEP 7 EXPENSE MODULE TESTS ===\n\n";

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
$beforeExpenses  = (int)$db->query('SELECT COUNT(*) FROM expenses')->fetchColumn();
$beforeCats      = (int)$db->query('SELECT COUNT(*) FROM expense_categories')->fetchColumn();
$beforeJESeq     = (int)$db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='JE'")->fetchColumn();
$beforeEXPSeq    = (int)$db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='EXP'")->fetchColumn();
$beforeJE44      = $db->query("SELECT financial_year_id, accounting_period_id FROM journal_entries WHERE entry_number='JE00044'")->fetch();

echo "Initial: accounts={$beforeAccounts} entries={$beforeEntries} lines={$beforeLines} debit={$beforeTotals['d']} credit={$beforeTotals['c']} expenses={$beforeExpenses} categories={$beforeCats}\n\n";

$catModel = new ExpenseCategoryModel();
$model = new ExpenseModel();
$reportModel = new AccountingReportModel();

$ACC_EXPENSE = 52; // 5090 Office Rent (expense type, already active)
$ACC_CASH = 7;     // 1110 Cash at Hand
$OPEN_DATE = '2026-08-25'; // inside Q3 2026 (open)
$CLOSED_DATE = '2026-01-15'; // inside a dedicated closed test period, NOT covered by Q3 2026 or Legacy

$testCategoryId = null;
$createdExpenseIds = [];
$tempFyId = null;
$tempPeriodId = null;
$tempClosedPeriodId = null;

// ============================================================
// TEST 1: Create expense category
// ============================================================
echo "TEST 1: Create expense category\n";
try {
    $testCategoryId = $catModel->create([
        'category_name' => 'TEST Category Step7',
        'description'   => 'temporary test category',
        'gl_account_id' => $ACC_EXPENSE,
        'is_active'     => 1,
    ]);
    check('category created', $testCategoryId !== false);
} catch (Exception $e) {
    check('TEST 1 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 2: Create expense
// ============================================================
echo "TEST 2: Create expense\n";
$expenseId = null;
try {
    $expenseId = $model->createDraft([
        'category_id'    => $testCategoryId,
        'expense_date'   => $OPEN_DATE,
        'amount'         => 150000.00,
        'description'    => 'TEST expense',
        'payment_method' => 'Cash',
    ], 1);
    $createdExpenseIds[] = $expenseId;
    $exp = $model->find($expenseId);
    check('expense created with status=draft', $exp && $exp['status'] === 'draft');
    check('expense_number matches EXP-###### pattern', (bool)preg_match('/^EXP-\d{6}$/', $exp['expense_number']));
} catch (Exception $e) {
    check('TEST 2 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 3: Validate required fields
// ============================================================
echo "TEST 3: Required field validation\n";
try {
    $model->createDraft(['expense_date' => $OPEN_DATE, 'amount' => 100, 'payment_method' => 'Cash'], 1);
    check('missing category_id should have thrown', false);
} catch (InvalidArgumentException $e) {
    check('missing category_id rejected — ' . $e->getMessage(), true);
}
echo "\n";

// ============================================================
// TEST 4: Reject invalid amount
// ============================================================
echo "TEST 4: Reject invalid amount\n";
try {
    $model->createDraft(['category_id' => $testCategoryId, 'expense_date' => $OPEN_DATE, 'amount' => 0, 'payment_method' => 'Cash'], 1);
    check('zero amount should have thrown', false);
} catch (InvalidArgumentException $e) {
    check('zero amount rejected — ' . $e->getMessage(), true);
}
try {
    $model->createDraft(['category_id' => $testCategoryId, 'expense_date' => $OPEN_DATE, 'amount' => -50, 'payment_method' => 'Cash'], 1);
    check('negative amount should have thrown', false);
} catch (InvalidArgumentException $e) {
    check('negative amount rejected — ' . $e->getMessage(), true);
}
echo "\n";

// ============================================================
// TEST 5: Reject inactive financial year
// ============================================================
echo "TEST 5: Reject posting into an inactive financial year\n";
try {
    $tempFyId = $db->query("INSERT INTO financial_years (name, start_date, end_date, status, is_legacy) VALUES ('TEST FY Inactive', '2027-01-01', '2027-12-31', 'pending', 0)")
        ? (int)$db->lastInsertId() : null;
    $tempPeriodId = (new AccountingPeriodModel())->createPeriod([
        'financial_year_id' => $tempFyId, 'name' => 'TEST Period (inactive FY)',
        'start_date' => '2027-06-01', 'end_date' => '2027-06-30', 'status' => 'open',
    ]);

    $tempExpenseId = $model->createDraft([
        'category_id' => $testCategoryId, 'expense_date' => '2027-06-15',
        'amount' => 5000, 'payment_method' => 'Cash',
    ], 1);
    $createdExpenseIds[] = $tempExpenseId;

    try {
        $model->post($tempExpenseId, 1);
        check('posting into inactive-FY period should have thrown', false);
    } catch (InvalidArgumentException $e) {
        // The period itself is 'open', but its financial year is 'pending' (not active),
        // so JournalService's auto-resolve correctly finds no eligible period at all.
        check('posting into inactive-FY period rejected — ' . $e->getMessage(), true);
    }
} catch (Exception $e) {
    check('TEST 5 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 6: Reject closed accounting period
// ============================================================
echo "TEST 6: Reject posting dated inside a closed accounting period\n";
try {
    // Dedicated closed period whose date range does NOT overlap Q3 2026,
    // so auto-resolution can't accidentally find a different open period
    // covering the same date (which is what the overlapping Legacy-period
    // range would have done, since Legacy's Aug 2026 dates sit inside
    // Q3 2026's own Jul-Sep range).
    $tempClosedPeriodId = (new AccountingPeriodModel())->createPeriod([
        'financial_year_id' => 2, 'name' => 'TEST Closed Period (Step 7)',
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'closed',
    ]);

    $closedDateExpenseId = $model->createDraft([
        'category_id' => $testCategoryId, 'expense_date' => $CLOSED_DATE,
        'amount' => 25000, 'payment_method' => 'Cash',
    ], 1);
    $createdExpenseIds[] = $closedDateExpenseId;

    try {
        $model->post($closedDateExpenseId, 1);
        check('posting into a closed-period date should have thrown', false);
    } catch (InvalidArgumentException $e) {
        check('closed-period date rejected — ' . $e->getMessage(), true);
    }
} catch (Exception $e) {
    check('TEST 6 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 7: Accept expense in open period
// ============================================================
echo "TEST 7: Accept posting in the open Q3 2026 period\n";
$postResult = null;
try {
    $postResult = $model->post($expenseId, 1);
    check('post() returned a journal_entry_id', !empty($postResult['journal_entry_id']));
    $exp = $model->find($expenseId);
    check('expense status is now posted', $exp['status'] === 'posted');
} catch (Exception $e) {
    check('TEST 7 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 8/9/10: Correct Dr/Cr accounts
// ============================================================
echo "TEST 8/9/10: Correct debit/credit posting and accounts\n";
try {
    $lines = $db->prepare("SELECT account_id, debit, credit FROM journal_lines WHERE journal_entry_id = ? ORDER BY id");
    $lines->execute([$postResult['journal_entry_id']]);
    $rows = $lines->fetchAll();
    check('exactly 2 lines created', count($rows) === 2);

    $debitLine = null; $creditLine = null;
    foreach ($rows as $r) {
        if ($r['debit'] > 0) $debitLine = $r;
        if ($r['credit'] > 0) $creditLine = $r;
    }
    check('debit line exists and hits the expense account (52)', $debitLine && (int)$debitLine['account_id'] === $ACC_EXPENSE);
    check('credit line exists and hits the Cash account (7)', $creditLine && (int)$creditLine['account_id'] === $ACC_CASH);
    check('debit amount matches expense amount', $debitLine && abs((float)$debitLine['debit'] - 150000.00) < 0.01);
    check('credit amount matches expense amount', $creditLine && abs((float)$creditLine['credit'] - 150000.00) < 0.01);
} catch (Exception $e) {
    check('TEST 8/9/10 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 11: Journal entry is balanced
// ============================================================
echo "TEST 11: Journal entry is balanced\n";
$sums = $db->prepare('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines WHERE journal_entry_id = ?');
$sums->execute([$postResult['journal_entry_id']]);
$s = $sums->fetch();
check('debit == credit for this entry', abs((float)$s['d'] - (float)$s['c']) < 0.01);
echo "\n";

// ============================================================
// TEST 12: Source reference is correct
// ============================================================
echo "TEST 12: Source reference is correct\n";
$je = $db->prepare("SELECT source_module, source_reference_type, source_reference_id FROM journal_entries WHERE id = ?");
$je->execute([$postResult['journal_entry_id']]);
$jeRow = $je->fetch();
check('source_module = expenses', $jeRow['source_module'] === 'expenses');
check('source_reference_type = expense', $jeRow['source_reference_type'] === 'expense');
check('source_reference_id matches the expense id', (int)$jeRow['source_reference_id'] === $expenseId);
echo "\n";

// ============================================================
// TEST 13: Duplicate posting is prevented
// ============================================================
echo "TEST 13: Duplicate posting is prevented\n";
try {
    $entriesBefore = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    $postAgain = $model->post($expenseId, 1);
    $entriesAfter = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    check('second post() returns the same journal_entry_id', (int)$postAgain['journal_entry_id'] === (int)$postResult['journal_entry_id']);
    check('no new journal entry created on repost', $entriesBefore === $entriesAfter);
} catch (Exception $e) {
    check('TEST 13 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 14: Expense appears in Income Statement
// ============================================================
echo "TEST 14: Expense appears in Income Statement\n";
try {
    $is = $reportModel->incomeStatement(['financial_year_id' => 2]); // FY 2026 -- where our OPEN_DATE test expense lives
    $found = null;
    foreach ($is['expense'] as $row) {
        if ((int)$row['id'] === $ACC_EXPENSE) { $found = $row; break; }
    }
    check('expense account present in FY2026 income statement', $found !== null);
    check('expense account net_amount includes the test posting (150,000.00)', $found && abs($found['net_amount'] - 150000.00) < 0.01);
} catch (Exception $e) {
    check('TEST 14 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 15: Expense appears in General Ledger
// ============================================================
echo "TEST 15: Expense appears in General Ledger\n";
try {
    $gl = $reportModel->generalLedgerForAccount($ACC_EXPENSE, ['financial_year_id' => 2]);
    $foundLine = false;
    foreach ($gl['lines'] as $l) {
        if ((int)$l['debit'] === 150000 || abs((float)$l['debit'] - 150000.00) < 0.01) { $foundLine = true; break; }
    }
    check('the posted expense line appears in the General Ledger for account 52', $foundLine);
} catch (Exception $e) {
    check('TEST 15 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 16: Trial Balance remains balanced
// ============================================================
echo "TEST 16: Trial Balance remains balanced\n";
try {
    $tbLegacy = $reportModel->trialBalance(['financial_year_id' => 1]);
    check('Legacy trial balance still balances after Step 7 posting', $tbLegacy['balanced']);
    $tbFy2026 = $reportModel->trialBalance(['financial_year_id' => 2]);
    check('FY2026 trial balance balances (now includes the test posting)', $tbFy2026['balanced']);
} catch (Exception $e) {
    check('TEST 16 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 17: Transaction rollback works
// ============================================================
echo "TEST 17: Transaction rollback works\n";
try {
    $rollbackExpenseId = $model->createDraft([
        'category_id' => $testCategoryId, 'expense_date' => $OPEN_DATE,
        'amount' => 77000, 'payment_method' => 'Cash',
    ], 1);
    $createdExpenseIds[] = $rollbackExpenseId;

    $entriesBeforeRollback = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();

    $db->beginTransaction();
    $rollbackResult = $model->post($rollbackExpenseId, 1); // nests inside this outer transaction
    $db->rollBack();

    $entriesAfterRollback = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    check('no new journal entry survives the rollback', $entriesBeforeRollback === $entriesAfterRollback);

    $stmt = $db->prepare('SELECT COUNT(*) FROM journal_entries WHERE id = ?');
    $stmt->execute([$rollbackResult['journal_entry_id']]);
    check('the specific rolled-back journal entry id does not exist', (int)$stmt->fetchColumn() === 0);

    $exp = $model->find($rollbackExpenseId);
    check('expense status reverted to draft (not posted) after rollback', $exp['status'] === 'draft');
    check('expense.journal_entry_id still null after rollback', $exp['journal_entry_id'] === null);
} catch (Exception $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    check('TEST 17 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 18: Authorization works (subprocess, since the controller die()s)
// ============================================================
echo "TEST 18: Authorization — viewer role blocked from create()\n";
$helper = 'C:\Users\ASUS\AppData\Local\Temp\claude\c--xampp-htdocs-Empower\c6233702-99ca-4e2e-b5a7-76a77427393e\scratchpad\test_expense_auth_csrf.php';
$authOutput = shell_exec('"' . PHP_BINARY . '" "' . $helper . '" viewer_create 2>&1');
check('viewer role receives Access denied on create()', str_contains($authOutput ?? '', 'Access denied'));
echo "\n";

// ============================================================
// TEST 19: CSRF protection works (subprocess)
// ============================================================
echo "TEST 19: CSRF protection — forged token blocks store()\n";
$expensesBeforeCsrfTest = (int)$db->query('SELECT COUNT(*) FROM expenses')->fetchColumn();
shell_exec('"' . PHP_BINARY . '" "' . $helper . '" bad_csrf_store 2>&1');
$expensesAfterCsrfTest = (int)$db->query('SELECT COUNT(*) FROM expenses')->fetchColumn();
check('forged CSRF token does not create an expense', $expensesBeforeCsrfTest === $expensesAfterCsrfTest);
echo "\n";

// ============================================================
// TEST 20 (part 1): historical totals unchanged BEFORE cleanup check happens below after cleanup
// ============================================================

// ============================================================
// CLEANUP
// ============================================================
echo "=== CLEANUP ===\n";
try {
    // Delete the real posted journal entry from TEST 7/8-16
    if (!empty($postResult['journal_entry_id'])) {
        $jeId = (int)$postResult['journal_entry_id'];
        $db->exec("DELETE FROM journal_entry_audit WHERE entity_type='journal_entry' AND entity_id={$jeId}");
        $db->exec("DELETE FROM journal_lines WHERE journal_entry_id={$jeId}");
        $db->exec("DELETE FROM journal_entries WHERE id={$jeId}");
        echo "Deleted journal entry {$jeId} and its lines/audit\n";
    }

    // Delete test expenses
    if (!empty($createdExpenseIds)) {
        $ids = implode(',', array_map('intval', $createdExpenseIds));
        $db->exec("DELETE FROM expenses WHERE id IN ({$ids})");
        echo "Deleted " . count($createdExpenseIds) . " test expenses\n";
    }

    // Delete test category
    if ($testCategoryId) {
        $db->exec("DELETE FROM expense_categories WHERE id = {$testCategoryId}");
        echo "Deleted test category {$testCategoryId}\n";
    }

    // Delete temp periods + financial year
    if ($tempPeriodId) {
        $db->exec("DELETE FROM accounting_periods WHERE id = {$tempPeriodId}");
    }
    if ($tempClosedPeriodId) {
        $db->exec("DELETE FROM accounting_periods WHERE id = {$tempClosedPeriodId}");
    }
    if ($tempFyId) {
        $db->exec("DELETE FROM financial_years WHERE id = {$tempFyId}");
    }
    echo "Deleted temp periods/financial year\n";

    // Restore sequences
    $db->prepare("UPDATE journal_number_sequences SET last_number = ? WHERE prefix = 'JE'")->execute([$beforeJESeq]);
    $db->prepare("UPDATE journal_number_sequences SET last_number = ? WHERE prefix = 'EXP'")->execute([$beforeEXPSeq]);

    echo "Cleanup complete\n\n";
} catch (Exception $e) {
    echo "Cleanup error: " . $e->getMessage() . "\n\n";
}

// ============================================================
// TEST 20: Historical totals unchanged before test, restored after cleanup
// ============================================================
echo "TEST 20: Historical journal totals unchanged/restored\n";
$afterAccounts = (int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
$afterEntries  = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$afterLines    = (int)$db->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
$afterTotals   = $db->query('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines')->fetch();
$afterExpenses = (int)$db->query('SELECT COUNT(*) FROM expenses')->fetchColumn();
$afterCats     = (int)$db->query('SELECT COUNT(*) FROM expense_categories')->fetchColumn();
$afterJE44     = $db->query("SELECT financial_year_id, accounting_period_id FROM journal_entries WHERE entry_number='JE00044'")->fetch();
$account77     = $db->query("SELECT code, name FROM accounts WHERE id=77")->fetch();

check('accounts count unchanged', $afterAccounts === $beforeAccounts);
check('journal_entries count restored', $afterEntries === $beforeEntries);
check('journal_lines count restored', $afterLines === $beforeLines);
check('debit/credit totals restored', abs((float)$afterTotals['d'] - (float)$beforeTotals['d']) < 0.01 && abs((float)$afterTotals['c'] - (float)$beforeTotals['c']) < 0.01);
check('expenses count restored to pre-test value (only the real historical row remains)', $afterExpenses === $beforeExpenses);
check('expense_categories count restored', $afterCats === $beforeCats);
check('account 77 still code 4035 / Loan Interest Income', $account77['code'] === '4035' && $account77['name'] === 'Loan Interest Income');
check('JE00044 classification unchanged', $afterJE44['financial_year_id'] == $beforeJE44['financial_year_id'] && $afterJE44['accounting_period_id'] == $beforeJE44['accounting_period_id']);

$expense4 = $db->query("SELECT expense_number, amount, status, journal_entry_id FROM expenses WHERE id=4")->fetch();
check('historical expense id=4 (EXP-000001) untouched', $expense4 && $expense4['expense_number'] === 'EXP-000001' && abs((float)$expense4['amount'] - 2000000.00) < 0.01 && $expense4['status'] === 'posted');
echo "\n";

// ============================================================
// TEST 21-24: Regression — Steps 3/4/5/6
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
if (!str_contains($s6Output ?? '', 'ALL STEP 6 TESTS PASSED')) { echo $s6Output . "\n"; }
echo "\n";

// ============================================================
// FINAL VERIFICATION
// ============================================================
echo "=== FINAL VERIFICATION ===\n";
$finalEntries = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$finalLines   = (int)$db->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
$finalTotals  = $db->query('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines')->fetch();
$finalExpenses = (int)$db->query('SELECT COUNT(*) FROM expenses')->fetchColumn();

echo "Journal entries: {$finalEntries} (expected {$beforeEntries})\n";
echo "Journal lines: {$finalLines} (expected {$beforeLines})\n";
echo "Debits: " . number_format((float)$finalTotals['d'], 2) . " (expected " . number_format((float)$beforeTotals['d'], 2) . ")\n";
echo "Credits: " . number_format((float)$finalTotals['c'], 2) . " (expected " . number_format((float)$beforeTotals['c'], 2) . ")\n";
echo "Expenses: {$finalExpenses} (expected {$beforeExpenses})\n\n";

check('final state matches initial baseline exactly',
    $finalEntries === $beforeEntries && $finalLines === $beforeLines && $finalExpenses === $beforeExpenses &&
    abs((float)$finalTotals['d'] - (float)$beforeTotals['d']) < 0.01 && abs((float)$finalTotals['c'] - (float)$beforeTotals['c']) < 0.01);

// ============================================================
// SUMMARY
// ============================================================
echo "\n=== TEST SUMMARY ===\n";
echo "Tests Passed: {$testsPassed}\n";
echo "Tests Failed: {$testsFailed}\n\n";

if ($testsFailed === 0) {
    echo "ALL STEP 7 TESTS PASSED\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
