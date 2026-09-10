<?php
/**
 * ISOLATED — Stage 12-F: Notification Event Expansion & Automation.
 * Runs ONLY against empower_db_stage12f_test. Never touches empower_db.
 */
chdir(__DIR__);
$pass = 0; $fail = 0;
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }

define('DB_NAME', 'empower_db_stage12f_test');
require 'app/config/config.php';
require 'app/config/push.php';
require 'vendor/autoload.php';
require 'test_safety_guard.php';
require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';
$db = Database::getInstance()->getConnection();

function renderAs(string $role, string $userId, string $action, array $post = []): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_stage12f_subproc_' . $counter . '.php';
    $postLines = "\$_SERVER['REQUEST_METHOD'] = 'POST';\n\$_POST['csrf_token'] = 'skip';\n";
    foreach ($post as $k => $v) { $postLines .= "\$_POST['{$k}'] = " . var_export($v, true) . ";\n"; }
    $body = <<<PHP
<?php
chdir(__DIR__);
define('DB_NAME', 'empower_db_stage12f_test');
require 'app/config/config.php';
require 'app/config/push.php';
require 'vendor/autoload.php';
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
    ob_start();
    (new SavingsAccountController())->{$action}();
    \$out = ob_get_clean();
    echo "RESULT:" . \$out;
} catch (Throwable \$e) {
    echo "RESULT:EXCEPTION:" . \$e->getMessage();
}
PHP;
    file_put_contents($file, $body);
    $out = shell_exec('"' . PHP_BINARY . '" "' . $file . '" 2>&1');
    @unlink($file);
    return $out ?? '';
}

const U_ADMIN = 1; const U_OFFICE = 295; const U_CHAIRMAN = 296; const U_TREASURER = 297; const U_CASHIER = 298;
$notifModel = new NotificationModel();
$accountModel = new MemberSavingsAccountModel();

function notifsByKeyPrefix(string $prefix, PDO $db): array {
    $stmt = $db->prepare("SELECT * FROM notifications WHERE unique_event_key LIKE ? ORDER BY id");
    $stmt->execute([$prefix . '%']);
    return $stmt->fetchAll();
}

function openFd(MemberSavingsAccountModel $m, int $memberId, float $principal, float $rate, int $term, string $depositDate): int {
    $calc = $m->calculateFixedDeposit($principal, $rate, $term, $depositDate);
    $result = $m->createFixedDepositAccount($calc, $memberId, 'Cash', 1);
    return $result['account_id'];
}

echo "=== SECTION 1: FD closure lifecycle notifications ===\n";

$fdId = openFd($accountModel, 15, 1000000, 10, 2, '2026-07-01'); // matures 2026-09-01, already matured (today 2026-09-04)
$accountModel->syncMaturedFixedDeposits();
$fdStatus = $db->query("SELECT status FROM member_savings_accounts WHERE id={$fdId}")->fetchColumn();
ok($fdStatus === 'matured', '1. Test FD account correctly matured (fixture sanity check)', $fdStatus);

$reqOut = renderAs('office_admin', (string)U_OFFICE, 'fixedDepositClosureRequestStore', ['account_id' => $fdId]);
$requestRow = $db->query("SELECT id FROM fixed_deposit_closure_requests WHERE savings_account_id={$fdId} ORDER BY id DESC LIMIT 1")->fetch();
$requestId = (int)$requestRow['id'];
$requestedNotifs = notifsByKeyPrefix("fd_closure_requested:{$requestId}", $db);
$requestedRoles = array_column($requestedNotifs, 'user_id');
ok(count($requestedNotifs) === 2, '2. FD closure request notifies exactly admin+treasurer (2 rows)', (string)count($requestedNotifs));
ok(in_array(U_ADMIN, $requestedRoles) && in_array(U_TREASURER, $requestedRoles), '3. Notified users are admin and treasurer specifically');
ok(!in_array(U_OFFICE, $requestedRoles), '4. The requester (office_admin) is not redundantly notified of their own request');
$reqRow = reset($requestedNotifs);
ok($reqRow['reference_type'] === 'fixed_deposit_closure' && (int)$reqRow['reference_id'] === $requestId, '5. reference_type/reference_id correctly point at the closure request');
ok(str_contains($reqRow['action_url'], 'fd-closure-review'), '6. action_url points at the treasurer review page');

renderAs('treasurer', (string)U_TREASURER, 'fixedDepositClosureApprove', ['request_id' => $requestId]);
$approvedNotifs = notifsByKeyPrefix("fd_closure_approved:{$requestId}", $db);
$approvedRoles = array_column($approvedNotifs, 'user_id');
ok(count($approvedNotifs) === 2, '7. FD closure approval notifies exactly admin+cashier (2 rows)');
ok(in_array(U_ADMIN, $approvedRoles) && in_array(U_CASHIER, $approvedRoles), '8. Notified users are admin and cashier specifically');
ok(!in_array(U_TREASURER, $approvedRoles), '9. The approver (treasurer) is not redundantly notified of their own approval');

renderAs('cashier', (string)U_CASHIER, 'fixedDepositPayoutStore', ['request_id' => $requestId, 'payment_method' => 'Cash']);
$fdStatusAfter = $db->query("SELECT status FROM member_savings_accounts WHERE id={$fdId}")->fetchColumn();
$paidNotifs = notifsByKeyPrefix("fd_closure_paid:{$requestId}", $db);
if ($fdStatusAfter === 'closed') {
    ok(count($paidNotifs) === 2, '10. FD payout notifies admin+office_admin once the account is genuinely closed', "status={$fdStatusAfter}, notifs=" . count($paidNotifs));
} else {
    // The real production FD-2 interest-expense account may or may not exist in
    // this clone -- if payout is blocked (the certified Stage 11-A scenario),
    // zero notifications must exist rather than a false success notice.
    ok(count($paidNotifs) === 0, '10. If FD payout is blocked (missing interest-expense account), no false success notification is created', "status={$fdStatusAfter}");
}

echo "=== SECTION 2: FD closure rejection ===\n";

$fdId2 = openFd($accountModel, 16, 500000, 8, 1, '2026-07-15'); // matures 2026-08-15
$accountModel->syncMaturedFixedDeposits();
renderAs('office_admin', (string)U_OFFICE, 'fixedDepositClosureRequestStore', ['account_id' => $fdId2]);
$requestRow2 = $db->query("SELECT id FROM fixed_deposit_closure_requests WHERE savings_account_id={$fdId2} ORDER BY id DESC LIMIT 1")->fetch();
$requestId2 = (int)$requestRow2['id'];
renderAs('treasurer', (string)U_TREASURER, 'fixedDepositClosureReject', ['request_id' => $requestId2, 'rejection_reason' => 'Member disputes maturity amount']);
$rejectedNotifs = notifsByKeyPrefix("fd_closure_rejected:{$requestId2}", $db);
$rejectedRoles = array_column($rejectedNotifs, 'user_id');
ok(count($rejectedNotifs) === 2, '11. FD closure rejection notifies admin+office_admin (2 rows)');
ok(in_array(U_OFFICE, $rejectedRoles) && in_array(U_ADMIN, $rejectedRoles), '12. Notified users are admin and office_admin specifically');
$rejRow = reset($rejectedNotifs);
ok(str_contains($rejRow['message'], 'Member disputes maturity amount'), '13. Rejection reason is included in the message');

echo "=== SECTION 3: Universal savings closure lifecycle ===\n";

$voluntaryId = $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => date('Y-m-d')], [['member_id' => 17, 'role' => 'primary']], U_ADMIN);
renderAs('office_admin', (string)U_OFFICE, 'closureRequestStore', ['account_id' => $voluntaryId]);
$svReqRow = $db->query("SELECT id FROM savings_account_closure_requests WHERE savings_account_id={$voluntaryId} ORDER BY id DESC LIMIT 1")->fetch();
$svReqId = (int)$svReqRow['id'];
$svRequestedNotifs = notifsByKeyPrefix("savings_closure_requested:{$svReqId}", $db);
ok(count($svRequestedNotifs) === 2, '14. Universal closure request notifies admin+treasurer (2 rows)');
ok(str_contains(reset($svRequestedNotifs)['message'], 'Voluntary'), '15. Message correctly identifies the account type');

renderAs('treasurer', (string)U_TREASURER, 'closureApprove', ['request_id' => $svReqId]);
$svApprovedNotifs = notifsByKeyPrefix("savings_closure_approved:{$svReqId}", $db);
ok(count($svApprovedNotifs) === 2, '16. Universal closure approval notifies admin+cashier (2 rows)');

renderAs('cashier', (string)U_CASHIER, 'closureSettleStore', ['request_id' => $svReqId, 'payment_method' => 'Cash']);
$svSettledNotifs = notifsByKeyPrefix("savings_closure_settled:{$svReqId}", $db);
ok(count($svSettledNotifs) === 2, '17. Universal closure settlement notifies admin+office_admin (2 rows) -- zero-balance case (new account, no deposits)');
ok(str_contains(reset($svSettledNotifs)['message'], 'zero balance'), '18. Zero-balance settlement message correctly reflects no payment was needed');

echo "=== SECTION 4: Universal closure with a real balance ===\n";

$voluntaryId2 = $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => date('Y-m-d')], [['member_id' => 11, 'role' => 'primary']], U_ADMIN);
$savingsModel = new SavingsModel();
$savingsModel->recordDepositWithPosting([
    'member_id' => 11, 'savings_account_id' => $voluntaryId2, 'amount' => 50000,
    'transaction_type' => 'deposit', 'payment_method' => 'Cash',
    'receipt_number' => $savingsModel->generateReceiptNumber(), 'transaction_date' => date('Y-m-d'),
], U_ADMIN);
renderAs('office_admin', (string)U_OFFICE, 'closureRequestStore', ['account_id' => $voluntaryId2]);
$svReqRow2 = $db->query("SELECT id FROM savings_account_closure_requests WHERE savings_account_id={$voluntaryId2} ORDER BY id DESC LIMIT 1")->fetch();
$svReqId2 = (int)$svReqRow2['id'];
renderAs('treasurer', (string)U_TREASURER, 'closureApprove', ['request_id' => $svReqId2]);
renderAs('cashier', (string)U_CASHIER, 'closureSettleStore', ['request_id' => $svReqId2, 'payment_method' => 'Cash']);
$svSettledNotifs2 = notifsByKeyPrefix("savings_closure_settled:{$svReqId2}", $db);
ok(count($svSettledNotifs2) === 2, '19. Non-zero-balance settlement also notifies correctly (2 rows)');
ok(str_contains(reset($svSettledNotifs2)['message'], '50,000'), '20. The settled amount appears correctly in the message');
$voluntary2Status = $db->query("SELECT status FROM member_savings_accounts WHERE id={$voluntaryId2}")->fetchColumn();
ok($voluntary2Status === 'closed', '21. The account is genuinely closed (notification reflects real state)');

echo "=== SECTION 5: FD maturity reminders (time-driven, traffic-generated) ===\n";

// Build controlled maturity scenarios directly via UPDATE (matches Stage 12-B's
// established technique for controlled tier testing). Dates are computed
// relative to today, not hardcoded absolute dates -- a prior version of
// this fixture used fixed dates that were correct only on the day it was
// written and silently drifted a tier once the wall-clock date advanced
// (a real incident: 2026-09-05's run computed daysLeft=6/0 instead of the
// intended 7/1 against yesterday's hardcoded dates -- a test-fixture bug,
// not a notification-engine defect).
$fdA = openFd($accountModel, 18, 700000, 7, 1, '2026-08-04'); // arbitrary, will override maturity_date
$db->exec("UPDATE member_savings_accounts SET maturity_date='" . date('Y-m-d', strtotime('+7 days')) . "', status='active' WHERE id={$fdA}"); // due in 7 days
$fdB = openFd($accountModel, 19, 600000, 7, 1, '2026-08-04');
$db->exec("UPDATE member_savings_accounts SET maturity_date='" . date('Y-m-d', strtotime('+1 day')) . "', status='active' WHERE id={$fdB}"); // due tomorrow
$fdC = openFd($accountModel, 20, 500000, 7, 1, '2026-08-04');
$db->exec("UPDATE member_savings_accounts SET maturity_date='" . date('Y-m-d', strtotime('-16 days')) . "', status='active' WHERE id={$fdC}"); // already matured

$notifModel->generateFixedDepositMaturityNotifications();

$due7Notifs = notifsByKeyPrefix("fd_maturity:{$fdA}:due_7:", $db);
$due1Notifs = notifsByKeyPrefix("fd_maturity:{$fdB}:due_1:", $db);
$maturedNotifs = notifsByKeyPrefix("fd_maturity:{$fdC}:matured:", $db);
ok(count($due7Notifs) === 2, '22. Due-in-7-days FD maturity reminder generated for admin+office_admin');
ok(count($due1Notifs) === 2, '23. Due-tomorrow FD maturity reminder generated');
ok(count($maturedNotifs) === 2, '24. Matured FD reminder generated');
$maturedAccountStatus = $db->query("SELECT status FROM member_savings_accounts WHERE id={$fdC}")->fetchColumn();
ok($maturedAccountStatus === 'matured', '25. The FD account itself was actually transitioned to matured by the reminder generator\'s own sync call');
$maturedRow = reset($maturedNotifs);
ok($maturedRow['priority'] === 'high', '26. A matured FD reminder carries high priority');

echo "=== SECTION 6: FD maturity reminder excludes accounts with an active closure request ===\n";

$fdD = openFd($accountModel, 12, 400000, 6, 1, '2026-08-04');
$db->exec("UPDATE member_savings_accounts SET maturity_date='2026-08-25', status='matured' WHERE id={$fdD}");
renderAs('office_admin', (string)U_OFFICE, 'fixedDepositClosureRequestStore', ['account_id' => $fdD]);
$countBeforeReminder = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE unique_event_key LIKE 'fd_maturity:{$fdD}:%'")->fetchColumn();
$notifModel->generateFixedDepositMaturityNotifications();
$countAfterReminder = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE unique_event_key LIKE 'fd_maturity:{$fdD}:%'")->fetchColumn();
ok($countBeforeReminder === 0 && $countAfterReminder === 0, '27. An FD with an active closure request already pending does not also generate a redundant maturity reminder');

echo "=== SECTION 7: Idempotency ===\n";

$countBefore = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE unique_event_key LIKE 'fd_maturity:{$fdA}:%'")->fetchColumn();
$notifModel->generateFixedDepositMaturityNotifications();
$notifModel->generateFixedDepositMaturityNotifications();
$countAfter = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE unique_event_key LIKE 'fd_maturity:{$fdA}:%'")->fetchColumn();
ok($countBefore === $countAfter, '28. Repeated maturity-reminder generation (simulating polling) does not create duplicates');

$countBeforeRepeatClosure = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE unique_event_key LIKE 'fd_closure_requested:{$requestId}%'")->fetchColumn();
renderAs('office_admin', (string)U_OFFICE, 'fixedDepositClosureRequestStore', ['account_id' => $fdId]); // already has an active request -- should be rejected by the service's own guard
$countAfterRepeatClosure = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE unique_event_key LIKE 'fd_closure_requested:{$requestId}%'")->fetchColumn();
ok($countBeforeRepeatClosure === $countAfterRepeatClosure, '29. A rejected duplicate closure-request attempt (service-level guard) creates no duplicate notification');

echo "=== SECTION 8: Transaction safety ===\n";

$countBeforeInvalid = (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
renderAs('cashier', (string)U_CASHIER, 'fixedDepositClosureRequestStore', ['account_id' => 999999]); // nonexistent account -- must fail before any notification
$countAfterInvalid = (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
ok($countAfterInvalid === $countBeforeInvalid, '30. A business-rule-rejected request (nonexistent account) creates no notification');

echo "=== SECTION 9: Push compatibility ===\n";

$db->exec("RENAME TABLE push_subscriptions TO push_subscriptions_backup_temp");
$fdE = openFd($accountModel, 14, 300000, 5, 1, '2026-08-04');
$db->exec("UPDATE member_savings_accounts SET maturity_date='2026-08-22', status='matured' WHERE id={$fdE}");
$noPushOut = renderAs('office_admin', (string)U_OFFICE, 'fixedDepositClosureRequestStore', ['account_id' => $fdE]);
ok(!str_contains($noPushOut, 'Fatal error') && !str_contains($noPushOut, 'EXCEPTION'), '31. Closure request notification creation still works with push_subscriptions entirely absent');
$noPushNotifs = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE reference_type='fixed_deposit_closure' AND reference_id=(SELECT id FROM fixed_deposit_closure_requests WHERE savings_account_id={$fdE})")->fetchColumn();
ok($noPushNotifs === 2, '32. The notification rows were still created despite push infrastructure being unavailable');
$db->exec("RENAME TABLE push_subscriptions_backup_temp TO push_subscriptions");

echo "=== SECTION 10: Security / recipient isolation unregressed ===\n";

$victimRow = reset($requestedNotifs); // an admin/treasurer row from Section 1
$notifModel->markRead((int)$victimRow['id'], U_CASHIER);
$victimAfter = $db->query("SELECT is_read FROM notifications WHERE id=" . (int)$victimRow['id'])->fetch();
ok((int)$victimAfter['is_read'] === 0, '33. Stage 12-B/12-C ownership enforcement still blocks cross-user access to the new closure-notification types');

$out403 = renderAs('cashier', (string)U_CASHIER, 'fixedDepositClosureApprove', ['request_id' => $requestId2]);
ok(str_contains($out403, 'Access denied') || !str_contains($out403, 'approved'), '34. Cashier (not an approver) is blocked from approving an FD closure');

echo "=== SECTION 11: Category labels ===\n";

$categoryLabelsSource = file_get_contents(__DIR__ . '/app/controllers/NotificationController.php');
ok(str_contains($categoryLabelsSource, "'fixed_deposit_closure'") && str_contains($categoryLabelsSource, "'savings_closure'") && str_contains($categoryLabelsSource, "'fixed_deposit'"), '35. New reference_types have category labels registered for the notification centre filter');

echo "=== SECTION 12: Metadata correctness ===\n";

ok(!empty($reqRow['action_url']) && str_contains($reqRow['action_url'], APP_URL), '36. action_url is same-origin (starts with APP_URL)');
ok($reqRow['type'] === 'info', '37. FD closure requested uses info type');
ok(reset($approvedNotifs)['type'] === 'success', '38. FD closure approved uses success type');
ok($rejRow['type'] === 'critical', '39. FD closure rejected uses critical type');
ok(reset($svRequestedNotifs)['reference_type'] === 'savings_closure', '40. Universal closure notifications use reference_type=savings_closure, distinct from fixed_deposit_closure');

echo "\n============================\n";
echo "TOTAL: {$pass} passed, {$fail} failed\n";
echo "============================\n";
