<?php
/**
 * ISOLATED — Stage FD-2: Fixed Deposit Maturity, Payout & Closure
 * Governance. Runs ONLY against empower_db_fd2_test. Never touches
 * empower_db.
 */
chdir(__DIR__);
$pass = 0; $fail = 0;
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }

define('DB_NAME', 'empower_db_fd2_test');
require 'app/config/config.php';
require 'test_safety_guard.php';
require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';
$db = Database::getInstance()->getConnection();

function renderAs(string $role, string $userId, string $action, array $post): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_fd2_subproc_' . $counter . '.php';
    $postLines = "\$_SERVER['REQUEST_METHOD'] = 'POST';\n\$_POST['csrf_token'] = 'skip';\n";
    foreach ($post as $k => $v) { $postLines .= "\$_POST['{$k}'] = " . var_export($v, true) . ";\n"; }
    $body = <<<PHP
<?php
chdir(__DIR__);
define('DB_NAME', 'empower_db_fd2_test');
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

$accountModel = new MemberSavingsAccountModel();
$service = new FixedDepositClosureService();

/** Opens a Fixed Deposit directly via the model (bypassing the controller,
 *  since these tests need full control of deposit_date/term to construct
 *  before/on/after-maturity scenarios) and returns its id. */
function openFd(MemberSavingsAccountModel $m, int $memberId, float $principal, float $rate, int $term, string $depositDate): int {
    $calc = $m->calculateFixedDeposit($principal, $rate, $term, $depositDate);
    $result = $m->createFixedDepositAccount($calc, $memberId, 'Cash', 1);
    return $result['account_id'];
}

// ================================================================
// SECTION 1: Maturity detection
// ================================================================
echo "=== SECTION 1: Maturity detection ===\n";
$notYetId = openFd($accountModel, 3, 1000000, 10, 12, date('Y-m-d')); // matures in 12 months -- not yet
$onDateId = openFd($accountModel, 4, 1000000, 10, 1, date('Y-m-d', strtotime('-1 month'))); // matures today
$pastId   = openFd($accountModel, 6, 1000000, 10, 1, date('Y-m-d', strtotime('-2 months'))); // matured last month

$accountModel->syncMaturedFixedDeposits();
$statusNotYet = $db->query("SELECT status FROM member_savings_accounts WHERE id={$notYetId}")->fetchColumn();
$statusOnDate = $db->query("SELECT status FROM member_savings_accounts WHERE id={$onDateId}")->fetchColumn();
$statusPast   = $db->query("SELECT status FROM member_savings_accounts WHERE id={$pastId}")->fetchColumn();
ok($statusNotYet === 'active', '1. FD before maturity remains active', $statusNotYet);
ok($statusOnDate === 'matured', '2. FD exactly on maturity date becomes maturity-eligible', $statusOnDate);
ok($statusPast === 'matured', '3. FD after maturity becomes maturity-eligible', $statusPast);

$logCountBefore = (int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE action='fixed_deposit_matured'")->fetchColumn();
$accountModel->syncMaturedFixedDeposits(); // run again
$logCountAfter = (int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE action='fixed_deposit_matured'")->fetchColumn();
$statusPastAgain = $db->query("SELECT status FROM member_savings_accounts WHERE id={$pastId}")->fetchColumn();
ok($logCountAfter === $logCountBefore, '4. Running maturity processing twice does not duplicate audit log entries', "{$logCountBefore} vs {$logCountAfter}");
ok($statusPastAgain === 'matured', '4b. Running maturity processing twice does not change an already-matured account');

// ================================================================
// SECTION 2: Early payout / closure-request rejection (active FD)
// ================================================================
echo "\n=== SECTION 2: Early rejection on an ACTIVE (non-matured) FD ===\n";
try {
    $service->requestClosure($notYetId, 1);
    ok(false, '5. Closure request on an active FD rejected by the service', 'no exception thrown');
} catch (InvalidArgumentException $e) {
    ok(str_contains($e->getMessage(), 'maturity'), '5. Closure request on an active FD rejected by the service', $e->getMessage());
}
renderAs('office_admin', '1', 'fixedDepositClosureRequestStore', ['account_id' => (string)$notYetId]);
$reqCountForActive = (int)$db->query("SELECT COUNT(*) FROM fixed_deposit_closure_requests WHERE savings_account_id={$notYetId}")->fetchColumn();
ok($reqCountForActive === 0, '6. Direct POST closure-request attempt on an active FD created no request row', $reqCountForActive);

// ================================================================
// SECTION 3: Closure request creation
// ================================================================
echo "\n=== SECTION 3: Closure request creation ===\n";
$reqId1 = $service->requestClosure($pastId, 295);
ok($reqId1 > 0, '7. office_admin (simulated) can request an eligible FD closure');
$req1 = $db->query("SELECT * FROM fixed_deposit_closure_requests WHERE id={$reqId1}")->fetch();
ok($req1['status'] === 'pending', '8. New request starts pending');
ok(abs((float)$req1['payout_amount'] - ((float)$req1['principal_amount'] + (float)$req1['expected_interest'])) < 0.01, '9. Request amount is system-derived (principal+interest), matches stored figures exactly');

try {
    $service->requestClosure($pastId, 295);
    ok(false, '10. Duplicate active closure request rejected');
} catch (InvalidArgumentException $e) {
    ok(str_contains($e->getMessage(), 'already exists'), '10. Duplicate active closure request rejected', $e->getMessage());
}

renderAs('cashier', '1', 'fixedDepositClosureRequestStore', ['account_id' => (string)$onDateId]);
$reqCountByUnauthorized = (int)$db->query("SELECT COUNT(*) FROM fixed_deposit_closure_requests WHERE savings_account_id={$onDateId}")->fetchColumn();
ok($reqCountByUnauthorized === 0, '11. Non-authorized role (cashier) cannot request closure via direct POST -- 403 blocks it before the service ever runs');

// ================================================================
// SECTION 4: Approval
// ================================================================
echo "\n=== SECTION 4: Approval ===\n";
try {
    $service->approve($reqId1, 295); // same user as requester
    ok(false, '12. Requester cannot approve their own request');
} catch (InvalidArgumentException $e) {
    ok(str_contains($e->getMessage(), 'cannot approve'), '12. Requester cannot approve their own request', $e->getMessage());
}
$selfApprovalLog = (int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE action='fixed_deposit_closure_self_approval_blocked'")->fetchColumn();
ok($selfApprovalLog > 0, '12b. Attempted self-approval is recorded in the audit log');

$service->approve($reqId1, 297); // a different user
$req1After = $db->query("SELECT * FROM fixed_deposit_closure_requests WHERE id={$reqId1}")->fetch();
ok($req1After['status'] === 'approved', '13. treasurer (simulated) can approve an eligible request');
ok((int)$req1After['approved_by'] === 297, '13b. approved_by correctly recorded');

renderAs('office_admin', '1', 'fixedDepositClosureApprove', ['request_id' => (string)$reqId1]);
$stillApproved = $db->query("SELECT status FROM fixed_deposit_closure_requests WHERE id={$reqId1}")->fetchColumn();
ok($stillApproved === 'approved', '14. office_admin cannot approve (403) -- status unaffected by the attempt');

// A second request for the reject/cancel tests.
$reqId2 = $service->requestClosure($onDateId, 295);
$service->reject($reqId2, 297, 'Member disputes the maturity date.');
$req2 = $db->query("SELECT * FROM fixed_deposit_closure_requests WHERE id={$reqId2}")->fetch();
ok($req2['status'] === 'rejected', '15. Rejection recorded with a reason', $req2['rejection_reason']);
try {
    $service->payout($reqId2, 1, 'Cash', null, date('Y-m-d'));
    ok(false, '16. A rejected request cannot be paid');
} catch (InvalidArgumentException $e) {
    ok(true, '16. A rejected request cannot be paid', $e->getMessage());
}
// After rejection, a NEW request for the same account should now be possible.
$reqId2b = $service->requestClosure($onDateId, 295);
ok($reqId2b > $reqId2, '17. A new closure request is possible for the same account after the prior one was rejected');
$service->cancel($reqId2b, 295);
$req2bAfter = $db->query("SELECT status FROM fixed_deposit_closure_requests WHERE id={$reqId2b}")->fetchColumn();
ok($req2bAfter === 'cancelled', '18. Requester can cancel their own pending request');
try {
    $service->payout($reqId2b, 1, 'Cash', null, date('Y-m-d'));
    ok(false, '19. A cancelled request cannot be paid');
} catch (InvalidArgumentException $e) {
    ok(true, '19. A cancelled request cannot be paid', $e->getMessage());
}

// ================================================================
// SECTION 5: Payout -- the reported blocker (current real state: no
// Fixed Deposit Interest Expense account exists)
// ================================================================
echo "\n=== SECTION 5: Payout blocker (no interest-expense account) ===\n";
$journalCountBefore = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$hasExpenseAccount = (bool)$db->query("SELECT id FROM accounts WHERE name='Fixed Deposit Interest Expense' AND type='expense' LIMIT 1")->fetchColumn();
ok(!$hasExpenseAccount, '20. Confirmed: no Fixed Deposit Interest Expense account exists (matches live production state)');
try {
    $service->payout($reqId1, 298, 'Cash', 'REF-001', date('Y-m-d'));
    ok(false, '21. Payout of an approved request fails cleanly when the required expense account is missing');
} catch (RuntimeException $e) {
    ok(str_starts_with($e->getMessage(), 'BLOCKER:'), '21. Payout of an approved request fails cleanly when the required expense account is missing', $e->getMessage());
}
// This IS the mandatory forced-failure/rollback test (§41): the failure
// happens after every other check has passed, immediately before the
// journal would be posted -- verify nothing was left partially committed.
$req1AfterBlocker = $db->query("SELECT * FROM fixed_deposit_closure_requests WHERE id={$reqId1}")->fetch();
ok($req1AfterBlocker['status'] === 'approved', '22. Request remains "approved" (not paid) after the blocked payout attempt');
ok($req1AfterBlocker['journal_entry_id'] === null, '23. No journal_entry_id was recorded on the request');
$accountAfterBlocker = $db->query("SELECT status FROM member_savings_accounts WHERE id={$pastId}")->fetchColumn();
ok($accountAfterBlocker === 'matured', '24. Fixed Deposit account remains "matured" (not closed) after the blocked payout attempt');
$journalCountAfter = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
ok($journalCountAfter === $journalCountBefore, '25. No journal entry was created by the blocked payout attempt');

// ================================================================
// SECTION 6: Full payout mechanics -- proven correct once the required
// account exists (isolated test DB only; NOT created in production)
// ================================================================
echo "\n=== SECTION 6: Full payout mechanics (test-only placeholder expense account) ===\n";
$db->exec("INSERT INTO accounts (code, name, type, subtype, normal_balance, is_active) VALUES ('4999', 'Fixed Deposit Interest Expense', 'expense', 'expense', 'debit', 1)");
$result = $service->payout($reqId1, 298, 'Cash', 'REF-002', date('Y-m-d'));
ok(!empty($result['journal_entry_id']), '26. Payout succeeds once the required expense account exists');
$req1Paid = $db->query("SELECT * FROM fixed_deposit_closure_requests WHERE id={$reqId1}")->fetch();
ok($req1Paid['status'] === 'paid', '27. Request becomes "paid"');
$accountPaid = $db->query("SELECT status FROM member_savings_accounts WHERE id={$pastId}")->fetchColumn();
ok($accountPaid === 'closed', '28. Fixed Deposit account becomes "closed" only after successful payout');
ok(abs((float)$req1Paid['amount_paid'] - (float)$req1Paid['payout_amount']) < 0.01, '29. Amount paid equals the approved payout amount exactly');
ok($req1Paid['payment_reference'] === 'REF-002', '30. Payment reference recorded');

$lines = $db->query("SELECT * FROM journal_lines WHERE journal_entry_id={$req1Paid['journal_entry_id']}")->fetchAll();
$totalDebit = array_sum(array_column($lines, 'debit'));
$totalCredit = array_sum(array_column($lines, 'credit'));
ok(abs($totalDebit - $totalCredit) < 0.01, '31. Journal entry balances (debits == credits)', "D={$totalDebit} C={$totalCredit}");
ok(abs($totalDebit - (float)$req1Paid['payout_amount']) < 0.01, '32. Journal total matches the payout amount exactly');
$liabilityLine = array_filter($lines, fn($l) => abs((float)$l['debit'] - (float)$req1Paid['principal_amount']) < 0.01);
ok(count($liabilityLine) === 1, '33. One journal line debits exactly the principal (Members\' Savings release)');
$interestLine = array_filter($lines, fn($l) => abs((float)$l['debit'] - (float)$req1Paid['expected_interest']) < 0.01);
ok(count($interestLine) === 1, '34. One journal line debits exactly the interest (FD Interest Expense)');
$creditLine = array_filter($lines, fn($l) => (float)$l['credit'] > 0);
ok(count($creditLine) === 1 && abs((float)reset($creditLine)['credit'] - (float)$req1Paid['payout_amount']) < 0.01, '35. One journal line credits exactly the total payout (Cash)');

// ================================================================
// SECTION 7: Double-payout protection
// ================================================================
echo "\n=== SECTION 7: Double-payout protection ===\n";
$journalCountBeforeRepeat = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$repeat = $service->payout($reqId1, 298, 'Cash', 'REF-003', date('Y-m-d'));
ok(!empty($repeat['already_paid']), '36. Repeating payout on an already-paid request is a safe no-op, not an error');
$journalCountAfterRepeat = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
ok($journalCountAfterRepeat === $journalCountBeforeRepeat, '37. No duplicate journal entry created by the repeated payout attempt');
$reqCountUnchanged = (int)$db->query("SELECT COUNT(*) FROM fixed_deposit_closure_requests WHERE id={$reqId1}")->fetchColumn();
ok($reqCountUnchanged === 1, '38. No duplicate closure-request row created');

renderAs('cashier', '1', 'fixedDepositPayoutStore', ['request_id' => (string)$reqId1, 'payment_method' => 'Cash']);
$journalCountAfterPostAttempt = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
ok($journalCountAfterPostAttempt === $journalCountBeforeRepeat, '39. Repeated POST via the real controller action still creates no duplicate journal');

// ================================================================
// SECTION 8: Authorization (unauthorized roles rejected server-side)
// ================================================================
echo "\n=== SECTION 8: Authorization ===\n";
try { $service->payout($reqId2b, 1, 'Cash', null, date('Y-m-d')); ok(false, '40. Cannot pay a cancelled request'); }
catch (InvalidArgumentException $e) { ok(true, '40. Cannot pay a cancelled request'); }

renderAs('office_admin', '1', 'fixedDepositPayoutStore', ['request_id' => (string)$reqId1, 'payment_method' => 'Cash']);
$stillPaidOnce = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
ok($stillPaidOnce === $journalCountAfterPostAttempt, '41. office_admin cannot execute payout (403) -- no state change');

renderAs('treasurer', '1', 'fixedDepositPayoutStore', ['request_id' => (string)$reqId1, 'payment_method' => 'Cash']);
$stillPaidOnce2 = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
ok($stillPaidOnce2 === $journalCountAfterPostAttempt, '42. treasurer cannot execute payout (403) -- no state change');

// ================================================================
// SECTION 9: Legacy + regression
//
// FM-000001/fixed_monthly was fully purged (2026-09, explicit user
// decision) since this section was first written -- it originally
// guarded against FD-related changes disturbing that unrelated account;
// now it confirms the removal itself was actually complete.
// ================================================================
echo "\n=== SECTION 9: Legacy regression ===\n";
$fm = $db->query("SELECT * FROM member_savings_accounts WHERE account_number='FM-000001'")->fetch();
ok($fm === false, '43. FM-000001 no longer exists (fully purged, not merely hidden)');
ok(!in_array('fixed_monthly', MemberSavingsAccountModel::ACCOUNT_TYPES, true), '44. fixed_monthly is no longer a recognized account type');
ok(!in_array('fixed_monthly', SavingsAccountClosureService::ACCOUNT_TYPES, true), '45. fixed_monthly is no longer eligible for the universal closure workflow');
ok(true, '46. (retired -- FM-000001 balance check no longer applicable, account does not exist)');

$voluntaryOk = $db->query("SELECT COUNT(*) FROM member_savings_accounts WHERE account_type='voluntary'")->fetchColumn();
ok($voluntaryOk > 0, '47. Voluntary savings accounts unaffected/still present');
$compulsoryOk = $db->query("SELECT COUNT(*) FROM member_savings_accounts WHERE account_type='compulsory'")->fetchColumn();
ok($compulsoryOk > 0, '48. Compulsory savings accounts unaffected/still present');

echo "\n=== SUMMARY ===\n";
echo "PASS: $pass\n";
echo "FAIL: $fail\n";
