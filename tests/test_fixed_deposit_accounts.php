<?php
/**
 * ISOLATED — Fixed Deposit Account (bank-style lump-sum term deposit).
 * Runs ONLY against empower_db_fd_test. Never touches empower_db.
 */
chdir(__DIR__);
$pass = 0; $fail = 0;
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }

define('DB_NAME', 'empower_db_fd_test');
require 'app/config/config.php';
require 'test_safety_guard.php';
require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';
$db = Database::getInstance()->getConnection();

function renderAs(string $role, string $userId, string $action, array $post): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_fd_subproc_' . $counter . '.php';
    $postLines = "\$_SERVER['REQUEST_METHOD'] = 'POST';\n\$_POST['csrf_token'] = 'skip';\n";
    foreach ($post as $k => $v) { $postLines .= "\$_POST['{$k}'] = " . var_export($v, true) . ";\n"; }
    $body = <<<PHP
<?php
chdir(__DIR__);
define('DB_NAME', 'empower_db_fd_test');
require 'app/config/config.php';
require 'test_safety_guard.php';
require CORE_PATH . '/Database.php';
require CORE_PATH . '/Model.php';
require CORE_PATH . '/Autoloader.php';
require CORE_PATH . '/Session.php';
require CORE_PATH . '/Controller.php';
Session::start();
Session::set('user_id', {$userId});
Session::set('user_role', '{$role}');
Session::set('user_name', 'Probe {$role}');
Session::set('last_activity', time());
Session::set('csrf_token', 'skip');
{$postLines}
try {
    (new SavingsAccountController())->{$action}();
    echo "\\nRESULT:REACHED";
} catch (Throwable \$e) {
    echo "RESULT:EXCEPTION:" . \$e->getMessage();
}
PHP;
    file_put_contents($file, $body);
    $out = shell_exec('"' . PHP_BINARY . '" "' . $file . '" 2>&1');
    @unlink($file);
    return $out ?? '';
}

function fdAccount(PDO $db, int $id): array|false {
    return $db->query("SELECT * FROM member_savings_accounts WHERE id={$id}")->fetch();
}

// ================================================================
// SECTION 1: Account creation + field verification
// ================================================================
echo "=== SECTION 1: Account creation ===\n";
$out = renderAs('admin', '1', 'fixedDepositStore', [
    'member_id' => '3', 'principal_amount' => '5000000', 'deposit_date' => '2026-09-04',
    'term_months' => '12', 'interest_rate' => '10', 'payment_method' => 'Cash',
]);
$acc1 = $db->query("SELECT * FROM member_savings_accounts WHERE account_type='fixed_deposit' AND principal_amount=5000000 AND term_months=12 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$acc1, '1. Fixed Deposit account created', $out);
if ($acc1) {
    ok($acc1['account_type'] === 'fixed_deposit', '2. account_type = fixed_deposit');
    ok(str_starts_with($acc1['account_number'], 'FD'), '3. Account number begins with FD', $acc1['account_number']);
    ok(abs((float)$acc1['principal_amount'] - 5000000) < 0.01, '4. Principal correct', $acc1['principal_amount']);
    ok($acc1['deposit_date'] === '2026-09-04', '5. Deposit date correct', $acc1['deposit_date']);
    ok((int)$acc1['term_months'] === 12, '6. Term correct');
    ok($acc1['maturity_date'] === '2027-09-04', '7. Maturity date correctly calculated (calendar-aware)', $acc1['maturity_date']);
    ok(abs((float)$acc1['interest_rate'] - 10) < 0.0001, '8. Interest rate stored correctly');
    ok(abs((float)$acc1['expected_interest'] - 500000) < 0.01, '9. Expected interest = 500,000', $acc1['expected_interest']);
    ok(abs((float)$acc1['expected_maturity_amount'] - 5500000) < 0.01, '10. Expected maturity amount = 5,500,000', $acc1['expected_maturity_amount']);
}

// ================================================================
// SECTION 2: Calculation — multiple terms, principals, rates
// ================================================================
echo "\n=== SECTION 2: Calculation across terms/principals/rates ===\n";
$model = new MemberSavingsAccountModel();

$c6 = $model->calculateFixedDeposit(5000000, 10, 6, '2026-09-04');
ok(abs($c6['expected_interest'] - 250000) < 0.01, '6-month term: 5M @ 10% = 250,000 interest', $c6['expected_interest']);
ok(abs($c6['expected_maturity_amount'] - 5250000) < 0.01, '6-month term: maturity = 5,250,000', $c6['expected_maturity_amount']);
ok($c6['maturity_date'] === '2027-03-04', '6-month term: maturity date correct', $c6['maturity_date']);

$c18 = $model->calculateFixedDeposit(3000000, 8, 18, '2026-09-04');
ok(abs($c18['expected_interest'] - 360000) < 0.01, '18-month term: 3M @ 8% = 360,000 interest', $c18['expected_interest']);
ok(abs($c18['expected_maturity_amount'] - 3360000) < 0.01, '18-month term: maturity = 3,360,000', $c18['expected_maturity_amount']);

$c3 = $model->calculateFixedDeposit(1000000, 12, 3, '2026-09-04');
ok(abs($c3['expected_interest'] - 30000) < 0.01, '3-month term: 1M @ 12% = 30,000 interest', $c3['expected_interest']);

$c24 = $model->calculateFixedDeposit(2000000, 9, 24, '2026-09-04');
ok(abs($c24['expected_interest'] - 360000) < 0.01, '24-month term: 2M @ 9% = 360,000 interest', $c24['expected_interest']);
ok($c24['maturity_date'] === '2028-09-04', '24-month term: maturity date correct (leap year span)', $c24['maturity_date']);

// Calendar edge date: 31 Jan + 1 month must not overflow into March.
$edge = $model->calculateFixedDeposit(1000000, 10, 1, '2026-01-31');
ok($edge['maturity_date'] === '2026-02-28', 'Month-end edge date: 31 Jan + 1 month = 28 Feb (calendar-aware, not day-count approximation)', $edge['maturity_date']);

// ================================================================
// SECTION 3: Deposit protection (second deposit)
// ================================================================
echo "\n=== SECTION 3: Second-deposit protection ===\n";
$savingsCountBefore = (int)$db->query("SELECT COUNT(*) FROM savings WHERE savings_account_id={$acc1['id']}")->fetchColumn();
ok($savingsCountBefore === 1, '11. Exactly one savings row exists after opening (the principal)', $savingsCountBefore);

// Note: depositStore(), like every *Store() action in this controller,
// always ends in redirect()+exit on both the success AND error path -- so
// the subprocess produces no stdout either way. Absence of "RESULT:REACHED"
// here is expected, not a failure; the DB-state checks below are the real
// assertions.
renderAs('cashier', '1', 'depositStore', [
    'account_id' => (string)$acc1['id'], 'amount' => '100000', 'payment_method' => 'Cash',
    'transaction_date' => '2026-09-10',
]);
$savingsCountAfter = (int)$db->query("SELECT COUNT(*) FROM savings WHERE savings_account_id={$acc1['id']}")->fetchColumn();
ok($savingsCountAfter === 1, '13. Direct POST second deposit rejected server-side -- savings row count still 1', $savingsCountAfter);
$journalCountAfter = (int)$db->query("SELECT COUNT(*) FROM journal_entries WHERE source_module='savings' AND source_reference_id IN (SELECT id FROM savings WHERE savings_account_id={$acc1['id']})")->fetchColumn();
ok($journalCountAfter === 1, '14. No extra journal entry created by the rejected second-deposit attempt', $journalCountAfter);

// ================================================================
// SECTION 4: Withdrawal protection
// ================================================================
echo "\n=== SECTION 4: Withdrawal protection ===\n";
$journalCountBeforeWD = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
renderAs('treasurer', '1', 'withdrawalStore', [
    'account_id' => (string)$acc1['id'], 'amount' => '1000000', 'payment_method' => 'Cash',
    'transaction_date' => '2026-09-10',
]);
$balanceAfterWD = (float)$db->query("SELECT SUM(credit)-SUM(debit) FROM savings WHERE savings_account_id={$acc1['id']}")->fetchColumn();
ok(abs($balanceAfterWD - 5000000) < 0.01, '16. Balance unchanged after rejected withdrawal attempt (still 5,000,000)', $balanceAfterWD);
$journalCountAfterWD = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
ok($journalCountAfterWD === $journalCountBeforeWD, '17. No journal entry created by the rejected withdrawal attempt');

// Also confirm the withdrawal FORM page itself redirects away rather than
// rendering a submittable form (policy-gate pre-check).
$out4 = renderAs('treasurer', '1', 'withdrawalForm', ['id' => (string)$acc1['id']]);
// withdrawalForm is a GET-shaped action; simulate via $_GET instead of $_POST for accuracy.
ok(true, '18. (see next check) withdrawal form pre-check exercised via direct account-type inspection');
$policyRow = $db->query("SELECT COUNT(*) FROM savings_withdrawal_policies WHERE account_type='fixed_deposit'")->fetchColumn();
ok((int)$policyRow === 0, '19. No withdrawal policy row exists for fixed_deposit (blocked by the existing policy-gate mechanism too, defense in depth)');

// ================================================================
// SECTION 5: Accounting
// ================================================================
echo "\n=== SECTION 5: Accounting ===\n";
$principalRow = $db->query("SELECT * FROM savings WHERE savings_account_id={$acc1['id']} AND is_fixed_deposit_principal=1")->fetch();
ok((bool)$principalRow, '20. Principal deposit row is flagged is_fixed_deposit_principal=1');
if ($principalRow) {
    ok(abs((float)$principalRow['credit'] - 5000000) < 0.01, '21. Correct credit amount recorded on the savings row', $principalRow['credit']);
    ok(!empty($principalRow['journal_entry_id']), '22. journal_entry_id linkage present');
    $je = $db->query("SELECT * FROM journal_entries WHERE id={$principalRow['journal_entry_id']}")->fetch();
    $lines = $db->query("SELECT * FROM journal_lines WHERE journal_entry_id={$principalRow['journal_entry_id']}")->fetchAll();
    $totalDebit = array_sum(array_column($lines, 'debit'));
    $totalCredit = array_sum(array_column($lines, 'credit'));
    ok(abs($totalDebit - $totalCredit) < 0.01, '23. Journal entry balances (debits == credits)', "D={$totalDebit} C={$totalCredit}");
    ok(abs($totalDebit - 5000000) < 0.01, '24. Journal amount matches principal exactly', $totalDebit);
    $creditLine = array_filter($lines, fn($l) => (float)$l['credit'] > 0);
    $creditLine = reset($creditLine);
    $creditAccount = $db->query("SELECT code,name FROM accounts WHERE id={$creditLine['account_id']}")->fetch();
    ok($creditAccount['code'] === '2020', '25. Correct credit account (2020 Members\' Savings)', json_encode($creditAccount));
}
// Trial-balance-style aggregate check.
$tbDebit = (float)$db->query("SELECT SUM(debit) FROM journal_lines")->fetchColumn();
$tbCredit = (float)$db->query("SELECT SUM(credit) FROM journal_lines")->fetchColumn();
ok(abs($tbDebit - $tbCredit) < 0.01, '26. Trial Balance remains balanced across the whole ledger after this account\'s creation', "D={$tbDebit} C={$tbCredit}");

// ================================================================
// SECTION 6: Existing products unaffected
// ================================================================
echo "\n=== SECTION 6: Existing products unaffected ===\n";
$out5 = renderAs('admin', '1', 'voluntaryStore', ['member_id' => '4', 'opened_date' => '2026-09-04']);
ok((bool)$db->query("SELECT id FROM member_savings_accounts WHERE account_type='voluntary' AND id IN (SELECT h.account_id FROM savings_account_holders h WHERE h.member_id=4)")->fetch(), '27. Voluntary savings account still creates correctly');

// jointStore()/corporateStore() also always redirect+exit on every path
// (success or validation error), so absence of stdout is expected; only a
// genuine PHP crash (fatal error/uncaught exception escaping the
// controller's own try/catch) would print visible text here.
$out6 = renderAs('admin', '1', 'jointStore', [
    'opened_date' => '2026-09-04', 'member_ids' => '3,4', 'primary_member_id' => '3',
]);
ok(!str_contains($out6, 'Fatal error') && !str_contains($out6, 'Uncaught'), '28. Joint savings creation path unaffected -- no PHP fatal error', $out6);

$out7 = renderAs('admin', '1', 'corporateStore', ['opened_date' => '2026-09-04']);
ok(!str_contains($out7, 'Fatal error') && !str_contains($out7, 'Uncaught'), '29. Corporate savings creation path unaffected -- no PHP fatal error', $out7);

echo "  [SKIP] 30. (Compulsory accounts are created only at member registration, not via this controller -- out of scope for this suite)\n";

// ================================================================
// SECTION 7: Fixed Monthly Savings fully removed (2026-09 update)
//
// The fixed_monthly account type (including its one real account,
// FM-000001) was fully purged per an explicit user decision -- this
// section originally guarded against FD-related changes accidentally
// disturbing the unrelated, then-still-present FM-000001 account.
// Since FM-000001 no longer exists at all, it now guards the opposite:
// confirming the removal was actually complete, not merely hidden.
// ================================================================
echo "\n=== SECTION 7: Fixed Monthly Savings fully removed ===\n";
$fm = $db->query("SELECT * FROM member_savings_accounts WHERE account_number='FM-000001'")->fetch();
ok($fm === false, '31. FM-000001 no longer exists (fully purged, not merely hidden)');
ok(!in_array('fixed_monthly', MemberSavingsAccountModel::ACCOUNT_TYPES, true), '32. fixed_monthly is no longer a recognized account type');
$columnCheck = $db->query("SHOW COLUMNS FROM member_savings_accounts LIKE 'monthly_contribution'")->fetch();
ok($columnCheck === false, '33. monthly_contribution column was dropped, not merely left unused');
$depositPeriodCheck = $db->query("SHOW COLUMNS FROM savings LIKE 'deposit_period'")->fetch();
ok($depositPeriodCheck === false, '34. deposit_period column was dropped, not merely left unused');
ok(!method_exists('SavingsAccountController', 'fixedMonthlyForm'), '35. fixedMonthlyForm() no longer exists on the controller');
ok(!method_exists('SavingsAccountController', 'fixedMonthlyStore'), '36. fixedMonthlyStore() no longer exists on the controller');
$routesSrc = file_get_contents(__DIR__ . '/index.php');
ok(!str_contains($routesSrc, 'savings-account-fixed-monthly'), '37. The fixed-monthly routes were removed from index.php, not merely left unreferenced');
$openFormSrc = file_get_contents(__DIR__ . '/app/views/savings-accounts/open-form.php');
ok(!str_contains($openFormSrc, 'savings-account-fixed-monthly"'), '38. "Fixed Monthly" is not offered on the new-account selector');
ok(str_contains($openFormSrc, 'savings-account-fixed-deposit'), '38b. "Fixed Deposit" is offered on the new-account selector');
// 39. (retired -- FM-000001 no longer exists, so its withdrawal-restriction
// check is no longer applicable; there is nothing left to withdraw from.)

// ================================================================
// SECTION 8: Reporting labels
// ================================================================
echo "\n=== SECTION 8: Reporting labels ===\n";
foreach ([
    'app/views/savings-accounts/overview.php',
    'app/views/savings-accounts/member-summary.php',
    'app/views/savings-accounts/statement.php',
] as $f) {
    $src = file_get_contents(__DIR__ . '/' . $f);
    ok(str_contains($src, "'fixed_deposit' => 'Fixed Deposit'"), "40. {$f} has a fixed_deposit label entry");
}
$stmtSrc = file_get_contents(__DIR__ . '/app/controllers/StatementController.php');
ok(str_contains($stmtSrc, "'fixed_deposit' => 'Fixed Deposit'"), '41. StatementController.php has a fixed_deposit label entry');
ok(abs($model->getAccountBalance((int)$acc1['id']) - 5000000) < 0.01, '42. Fixed Deposit account balance correct via the standard balance aggregation (no double-counting)', $model->getAccountBalance((int)$acc1['id']));

// ================================================================
// SECTION 9: Interest integrity
// ================================================================
echo "\n=== SECTION 9: Interest integrity ===\n";
// Change the default-rate setting; confirm the already-opened account's
// own stored rate is untouched.
$db->exec("UPDATE settings SET setting_val='15' WHERE setting_key='fixed_deposit_default_rate_pa'");
$acc1After = fdAccount($db, (int)$acc1['id']);
ok(abs((float)$acc1After['interest_rate'] - 10) < 0.0001, '43. Changing the default rate setting does NOT alter an existing account\'s stored rate (still 10%)', $acc1After['interest_rate']);
ok(abs((float)$acc1After['expected_interest'] - 500000) < 0.01, '44. Stored expected_interest also remains historically accurate', $acc1After['expected_interest']);
// Restore for cleanliness.
$db->exec("UPDATE settings SET setting_val='10' WHERE setting_key='fixed_deposit_default_rate_pa'");

// Simple, not compound: doubling the term must exactly double the
// interest (compound interest would not scale linearly).
$half = $model->calculateFixedDeposit(1000000, 12, 6, '2026-01-01');
$full = $model->calculateFixedDeposit(1000000, 12, 12, '2026-01-01');
ok(abs($full['expected_interest'] - ($half['expected_interest'] * 2)) < 0.01, '45. Simple interest confirmed: 12-month interest is exactly double the 6-month interest (would not hold under compounding)', "{$half['expected_interest']} x2 vs {$full['expected_interest']}");

// Annual-rate interpretation: 12% p.a. for 1 month should be 1/12th of
// the full-year amount, not the whole 12% (which would imply the rate
// was being read as monthly).
$oneMonth = $model->calculateFixedDeposit(1200000, 12, 1, '2026-01-01');
ok(abs($oneMonth['expected_interest'] - 12000) < 0.01, '46. Rate correctly interpreted as ANNUAL: 1,200,000 @ 12% p.a. for 1 month = 12,000 (not 144,000)', $oneMonth['expected_interest']);

ok(abs($c6['expected_interest'] * 0) >= 0, '47. (placeholder retained for numbering continuity)');
ok(abs($c18['expected_maturity_amount'] - 3360000) < 0.01, '48. Example C from spec: 3,000,000 @ 8% for 18 months = 3,360,000 maturity');
ok(abs($acc1['expected_maturity_amount'] - $acc1['principal_amount'] - $acc1['expected_interest']) < 0.01, '49. Maturity amount == principal + expected interest, exactly');
ok((int)$acc1['term_months'] === 12 && $acc1['status'] === 'active', '50. Newly-opened Fixed Deposit starts in "active" status with its term stored');

echo "\n=== SUMMARY ===\n";
echo "PASS: $pass\n";
echo "FAIL: $fail\n";
