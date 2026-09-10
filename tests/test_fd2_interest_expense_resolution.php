<?php
/**
 * ISOLATED — Stage 11-A: Fixed Deposit Interest Expense COA Resolution &
 * FD-2 payout enablement. Runs ONLY against empower_db_fd2a_test (a
 * separate clone from empower_db_fd2_test, which stays dedicated to the
 * original, unmodified 51/51 FD-2 regression proving the blocker still
 * fires when the account is absent). Never touches empower_db.
 */
chdir(__DIR__);
$pass = 0; $fail = 0;
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }

define('DB_NAME', 'empower_db_fd2a_test');
require 'app/config/config.php';
require 'test_safety_guard.php';
require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';
$db = Database::getInstance()->getConnection();

$accountModel = new MemberSavingsAccountModel();
$service = new FixedDepositClosureService();

function openFd(MemberSavingsAccountModel $m, int $memberId, float $principal, float $rate, int $term, string $depositDate): int {
    $calc = $m->calculateFixedDeposit($principal, $rate, $term, $depositDate);
    $result = $m->createFixedDepositAccount($calc, $memberId, 'Cash', 1);
    return $result['account_id'];
}

echo "=== Test 1/2/3/4/5/6/7: real payout now succeeds through the resolved account ===\n";

$fdId = openFd($accountModel, 3, 1000000, 10, 2, '2026-07-01'); // matures 2026-09-01, within the open Q3 2026 period
$accountModel->syncMaturedFixedDeposits();
$reqId = $service->requestClosure($fdId, 295); // office_admin
$service->approve($reqId, 297); // treasurer

$before = $db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$result = $service->payout($reqId, 298, 'Cash', 'REF-STAGE11A', date('Y-m-d'));
ok(!empty($result['journal_entry_id']), '1. Expense account now exists -- payout succeeds (previously the reported blocker)');

$request = $db->query("SELECT * FROM fixed_deposit_closure_requests WHERE id={$reqId}")->fetch();
$principal = (float)$request['principal_amount'];
$interest = (float)$request['expected_interest'];
$payout = round($principal + $interest, 2);

$lines = $db->prepare("SELECT jl.*, a.code, a.name, a.type FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE jl.journal_entry_id=?");
$lines->execute([$result['journal_entry_id']]);
$lineRows = $lines->fetchAll();

$interestLine = array_values(array_filter($lineRows, fn($l) => $l['name'] === 'Fixed Deposit Interest Expense'));
ok(!empty($interestLine) && $interestLine[0]['code'] === '5360' && $interestLine[0]['type'] === 'expense', '2. Interest line posts to the correct account: 5360 Fixed Deposit Interest Expense (expense)');
ok(abs((float)$interestLine[0]['debit'] - $interest) < 0.01 && (float)$interestLine[0]['credit'] === 0.0, '2b. Interest is a DEBIT to the expense account, matching the expected interest amount');

$totalDebit = array_sum(array_column($lineRows, 'debit'));
$totalCredit = array_sum(array_column($lineRows, 'credit'));
ok(abs($totalDebit - $totalCredit) < 0.01, '3. Journal balances: SUM(debits) = SUM(credits)', "{$totalDebit} vs {$totalCredit}");
ok(abs($totalDebit - $payout) < 0.01, '3b. Total debits equal the full payout amount (principal + interest)');

$liabilityLine = array_values(array_filter($lineRows, fn($l) => $l['code'] === '2020'));
ok(!empty($liabilityLine) && abs((float)$liabilityLine[0]['debit'] - $principal) < 0.01, '4. Members\' Savings liability (2020) debited by exactly the principal');

$cashLine = array_values(array_filter($lineRows, fn($l) => $l['code'] === '1110'));
ok(!empty($cashLine) && abs((float)$cashLine[0]['credit'] - $payout) < 0.01, '6. Cash at Hand credited for the full payout (principal + interest)');

$reqAfter = $db->query("SELECT status, amount_paid, journal_entry_id FROM fixed_deposit_closure_requests WHERE id={$reqId}")->fetch();
$acctAfter = $db->query("SELECT status FROM member_savings_accounts WHERE id={$fdId}")->fetchColumn();
ok($reqAfter['status'] === 'paid', '7a. Closure request becomes "paid"');
ok($acctAfter === 'closed', '7b. Fixed Deposit account becomes "closed"');
ok(abs((float)$reqAfter['amount_paid'] - $payout) < 0.01, '5. Interest recognized as expense -- amount_paid matches principal+interest exactly');

echo "=== Test 8: idempotency ===\n";
$journalCountBefore = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$result2 = $service->payout($reqId, 298, 'Cash', 'REF-STAGE11A', date('Y-m-d'));
$journalCountAfter = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
ok(!empty($result2['already_paid']) && $journalCountAfter === $journalCountBefore, '8. Repeat payout is idempotent -- no duplicate journal, no duplicate closure');

echo "=== Zero-interest case ===\n";
$fdZeroId = openFd($accountModel, 4, 500000, 0, 1, '2026-07-15'); // matures 2026-08-15
$accountModel->syncMaturedFixedDeposits();
$reqZeroId = $service->requestClosure($fdZeroId, 295);
$service->approve($reqZeroId, 297);
$resultZero = $service->payout($reqZeroId, 298, 'Cash', null, date('Y-m-d'));
$zeroLines = $db->prepare("SELECT jl.*, a.name FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE jl.journal_entry_id=?");
$zeroLines->execute([$resultZero['journal_entry_id']]);
$zeroLineRows = $zeroLines->fetchAll();
ok(count($zeroLineRows) === 2, 'Z1. Zero-interest payout posts exactly 2 lines (no interest-expense line at all)', (string)count($zeroLineRows));
$zeroInterestLine = array_filter($zeroLineRows, fn($l) => $l['name'] === 'Fixed Deposit Interest Expense');
ok(empty($zeroInterestLine), 'Z2. No zero-value interest-expense line is created when interest is 0');
$zeroTotalDebit = array_sum(array_column($zeroLineRows, 'debit'));
$zeroTotalCredit = array_sum(array_column($zeroLineRows, 'credit'));
ok(abs($zeroTotalDebit - $zeroTotalCredit) < 0.01 && abs($zeroTotalDebit - 500000.0) < 0.01, 'Z3. Journal still balances at exactly the principal (Dr liability / Cr cash)');

echo "=== Test 9: fail-closed still works when the account becomes unavailable ===\n";
$fdId3 = openFd($accountModel, 6, 800000, 8, 1, '2026-07-20'); // matures 2026-08-20
$accountModel->syncMaturedFixedDeposits();
$reqId3 = $service->requestClosure($fdId3, 295);
$service->approve($reqId3, 297);

$db->exec("UPDATE accounts SET is_active=0 WHERE code='5360'");
$journalCountBeforeFail = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
try {
    $service->payout($reqId3, 298, 'Cash', null, date('Y-m-d'));
    ok(false, '9a. Payout should fail closed when the expense account is deactivated');
} catch (RuntimeException $e) {
    ok(str_starts_with($e->getMessage(), 'BLOCKER:'), '9a. Payout fails closed (BLOCKER) when the expense account is inactive/unresolvable');
}
$journalCountAfterFail = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
ok($journalCountAfterFail === $journalCountBeforeFail, '9b. No journal entry was created by the failed attempt');
$req3AfterFail = $db->query("SELECT status, journal_entry_id FROM fixed_deposit_closure_requests WHERE id={$reqId3}")->fetch();
ok($req3AfterFail['status'] === 'approved' && $req3AfterFail['journal_entry_id'] === null, '9c. Closure request remains "approved" -- no partial state change');
$acct3AfterFail = $db->query("SELECT status FROM member_savings_accounts WHERE id={$fdId3}")->fetchColumn();
ok($acct3AfterFail === 'matured', '9d. Fixed Deposit account remains "matured" -- not closed');

// Reactivate and confirm the SAME request can now be paid correctly -- proves
// this is a live, re-evaluated lookup, not a cached/stale resolution.
$db->exec("UPDATE accounts SET is_active=1 WHERE code='5360'");
$result3 = $service->payout($reqId3, 298, 'Cash', null, date('Y-m-d'));
ok(!empty($result3['journal_entry_id']), '9e. Reactivating the account allows the same, still-approved request to be paid correctly');

echo "\n=== Test 10: regression (FD-1/Stage 11 run separately; this file itself is additive to FD-2, not a replacement) ===\n";
ok(class_exists('FixedDepositClosureService') && class_exists('SavingsAccountClosureService'), '10. Both closure services remain loadable side by side');

echo "\n============================\n";
echo "TOTAL: {$pass} passed, {$fail} failed\n";
echo "============================\n";
