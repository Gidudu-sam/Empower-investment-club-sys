<?php
/**
 * Task 6.5 — Chart of Accounts UI & Classification Tests
 */

require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Database.php';
require 'core/Autoloader.php';

$db = Database::getInstance()->getConnection();

echo "=== TASK 6.5 CHART OF ACCOUNTS TESTS ===\n\n";

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

echo "Initial: accounts={$beforeAccounts} entries={$beforeEntries} lines={$beforeLines} debit={$beforeTotals['d']} credit={$beforeTotals['c']}\n\n";

$model = new AccountModel();
$reportModel = new AccountingReportModel();

// ============================================================
// TEST 1: 82 accounts preserved
// ============================================================
echo "TEST 1: 82 accounts preserved\n";
check('accounts count is 82', $beforeAccounts === 82);
echo "\n";

// ============================================================
// TEST 2/3/4: Protected account identities
// ============================================================
echo "TEST 2: Account 77 remains 4035 Loan Interest Income\n";
$a77 = $model->find(77);
check('account 77 code=4035, name=Loan Interest Income', $a77['code'] === '4035' && $a77['name'] === 'Loan Interest Income');
echo "\n";

echo "TEST 3: Account 14 remains 1180 Loans to Members\n";
$a14 = $model->find(14);
check('account 14 code=1180, name=Loans to Members', $a14['code'] === '1180' && $a14['name'] === 'Loans to Members');
echo "\n";

echo "TEST 4: Account 17 remains 2020 Members' Savings\n";
$a17 = $model->find(17);
check("account 17 code=2020, name=Members' Savings", $a17['code'] === '2020' && $a17['name'] === "Members' Savings");
echo "\n";

// ============================================================
// TEST 5/6/7/8: Historical data unmodified
// ============================================================
echo "TEST 5/6: No journal entries or lines modified\n";
check('journal_entries count unchanged', (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn() === $beforeEntries);
check('journal_lines count unchanged', (int)$db->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn() === $beforeLines);
echo "\n";

echo "TEST 7/8: Debit/credit totals unchanged\n";
$totals = $db->query('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines')->fetch();
check('debit total = 7,257,500.00', abs((float)$totals['d'] - 7257500.00) < 0.01);
check('credit total = 7,257,500.00', abs((float)$totals['c'] - 7257500.00) < 0.01);
echo "\n";

// ============================================================
// TEST 9: Trial Balance remains balanced
// ============================================================
echo "TEST 9: Trial Balance remains balanced\n";
$tb = $reportModel->trialBalance(['financial_year_id' => 1]);
check('Legacy FY trial balance still balances', $tb['balanced']);
check('Trial Balance includes all 82 accounts', count($tb['accounts']) === 82);
echo "\n";

// ============================================================
// TEST 10: Income Statement correctly identifies Loan Interest Income
// ============================================================
echo "TEST 10: Income Statement correctly identifies Loan Interest Income\n";
$is = $reportModel->incomeStatement(['financial_year_id' => 1]);
$found = null;
foreach ($is['income'] as $row) { if ((int)$row['id'] === 77) { $found = $row; break; } }
check('account 77 present in income statement', $found !== null);
check('code is 4035', $found && $found['code'] === '4035');
check('net_amount = 659,161.00 (unchanged since Step 6)', $found && abs($found['net_amount'] - 659161.00) < 0.01);
echo "\n";

// ============================================================
// TEST 11: Chart of Accounts page renders
// ============================================================
echo "TEST 11: Chart of Accounts page renders successfully\n";
$helper = 'C:\Users\ASUS\AppData\Local\Temp\claude\c--xampp-htdocs-Empower\c6233702-99ca-4e2e-b5a7-76a77427393e\scratchpad\test_coa_render.php';
$indexOut = shell_exec('"' . PHP_BINARY . '" "' . $helper . '" index 2>&1');
check('index() renders with no PHP errors/warnings', !str_contains($indexOut ?? '', 'Fatal error') && !str_contains($indexOut ?? '', 'Warning'));
check('page shows account count', str_contains($indexOut ?? '', '82 accounts'));
check('page shows Loan Interest Income', str_contains($indexOut ?? '', 'Loan Interest Income'));
echo "\n";

// ============================================================
// TEST 12: Sidebar navigation
// ============================================================
echo "TEST 12: Sidebar navigation includes Chart of Accounts\n";
$sidebarContent = file_get_contents(__DIR__ . '/app/views/layouts/sidebar.php');
check('sidebar has chart-of-accounts link', str_contains($sidebarContent, "page=chart-of-accounts"));
check('sidebar link labeled Chart of Accounts', str_contains($sidebarContent, 'Chart of Accounts'));
echo "\n";

// ============================================================
// TEST 13: Search/filter functionality
// ============================================================
echo "TEST 13: Search/filter functionality works\n";
$searchOut = shell_exec('"' . PHP_BINARY . '" "' . $helper . '" index "search=4035" 2>&1');
check('search for 4035 finds Loan Interest Income', str_contains($searchOut ?? '', 'Loan Interest Income'));
check('search for 4035 excludes unrelated accounts', !str_contains($searchOut ?? '', 'Cash at Hand'));

$typeOut = shell_exec('"' . PHP_BINARY . '" "' . $helper . '" index "type=income" 2>&1');
check('type=income filter shows an Income section header', (bool)preg_match('/>Income<\/span>/', $typeOut ?? ''));
echo "\n";

// ============================================================
// TEST 14: Authorization works
// ============================================================
echo "TEST 14: Authorization enforced\n";
$authHelper = 'C:\Users\ASUS\AppData\Local\Temp\claude\c--xampp-htdocs-Empower\c6233702-99ca-4e2e-b5a7-76a77427393e\scratchpad\test_coa_auth_csrf.php';
$unauthOut = shell_exec('"' . PHP_BINARY . '" "' . $authHelper . '" unauthenticated_index 2>&1');
check('unauthenticated request never reaches past requireAuth()', !str_contains($unauthOut ?? '', 'REACHED_AFTER_INDEX'));

$viewerOut = shell_exec('"' . PHP_BINARY . '" "' . $authHelper . '" viewer_create 2>&1');
check('viewer role blocked from create()', str_contains($viewerOut ?? '', 'Access denied'));
echo "\n";

// ============================================================
// TEST 15: CSRF protection works
// ============================================================
echo "TEST 15: CSRF protection works\n";
$beforeCsrf = (int)$db->query("SELECT COUNT(*) FROM accounts WHERE code='9999'")->fetchColumn();
shell_exec('"' . PHP_BINARY . '" "' . $authHelper . '" bad_csrf_store 2>&1');
$afterCsrf = (int)$db->query("SELECT COUNT(*) FROM accounts WHERE code='9999'")->fetchColumn();
check('forged CSRF token does not create an account', $beforeCsrf === $afterCsrf && $afterCsrf === 0);
echo "\n";

// ============================================================
// TEST: AccountModel delete() protection (defensive guard)
// ============================================================
echo "TEST (bonus): AccountModel::delete() blocks accounts with journal activity\n";
try {
    $model->delete(77); // Loan Interest Income, has heavy journal activity
    check('delete() on account 77 should have thrown', false);
} catch (RuntimeException $e) {
    check('delete() blocked — ' . $e->getMessage(), true);
}
$stillExists = $model->find(77);
check('account 77 still exists after blocked delete attempt', $stillExists !== false);
echo "\n";

// ============================================================
// Confirm no accidental writes from this suite itself
// ============================================================
echo "=== Post-test state check (this suite performs no writes of its own) ===\n";
$afterAccounts = (int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
$afterEntries  = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$afterLines    = (int)$db->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
check('accounts count unchanged', $afterAccounts === $beforeAccounts);
check('journal_entries count unchanged', $afterEntries === $beforeEntries);
check('journal_lines count unchanged', $afterLines === $beforeLines);
echo "\n";

// ============================================================
// TEST 16: Regression — all 7 prior suites
// ============================================================
echo "TEST 16: Regression — Steps 3-9 test suites\n";
$suites = [
    'test_journal_reversal.php' => 'ALL REVERSAL TESTS PASSED',
    'test_step4_periods.php' => 'ALL STEP 4 TESTS PASSED',
    'test_step5_opening_balances.php' => 'ALL STEP 5 TESTS PASSED',
    'test_step6_accounting_reports.php' => 'ALL STEP 6 TESTS PASSED',
    'test_step7_expenses.php' => 'ALL STEP 7 TESTS PASSED',
    'test_step8_savings_accounting.php' => 'ALL STEP 8 TESTS PASSED',
    'test_step9_loan_disbursement.php' => 'ALL STEP 9 TESTS PASSED',
];
foreach ($suites as $file => $marker) {
    $out = shell_exec('"' . PHP_BINARY . '" ' . $file . ' 2>&1');
    $passed = str_contains($out ?? '', $marker);
    check("{$file} reports all tests passed", $passed);
    if (!$passed) { echo substr($out ?? '', -2000) . "\n"; }
}
echo "\n";

// ============================================================
// FINAL VERIFICATION
// ============================================================
echo "=== FINAL VERIFICATION ===\n";
$finalAccounts = (int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
$finalEntries  = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$finalLines    = (int)$db->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
$finalTotals   = $db->query('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines')->fetch();

echo "Accounts: {$finalAccounts} (expected {$beforeAccounts})\n";
echo "Journal entries: {$finalEntries} (expected {$beforeEntries})\n";
echo "Journal lines: {$finalLines} (expected {$beforeLines})\n";
echo "Debits: " . number_format((float)$finalTotals['d'], 2) . "\n";
echo "Credits: " . number_format((float)$finalTotals['c'], 2) . "\n\n";

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
    echo "ALL TASK 6.5 TESTS PASSED\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
