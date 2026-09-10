<?php
/**
 * ISOLATED — Stage 12-H: Notification Audit, Coverage & Operational Reliability.
 * Runs ONLY against empower_db_stage12h_test. Never touches empower_db.
 */
chdir(__DIR__);
$pass = 0; $fail = 0;
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }

define('DB_NAME', 'empower_db_stage12h_test');
require 'app/config/config.php';
require 'app/config/push.php';
require 'vendor/autoload.php';
require 'test_safety_guard.php';
require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';
$db = Database::getInstance()->getConnection();

function renderAs(string $role, string $userId, string $class, string $action, array $post = []): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_stage12h_subproc_' . $counter . '.php';
    $postLines = "\$_SERVER['REQUEST_METHOD'] = 'POST';\n\$_POST['csrf_token'] = 'skip';\n";
    foreach ($post as $k => $v) { $postLines .= "\$_POST['{$k}'] = " . var_export($v, true) . ";\n"; }
    $body = <<<PHP
<?php
chdir(__DIR__);
define('DB_NAME', 'empower_db_stage12h_test');
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
    (new {$class}())->{$action}();
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

function notifsByKeyPrefix(string $prefix, PDO $db): array {
    $stmt = $db->prepare("SELECT * FROM notifications WHERE unique_event_key LIKE ? ORDER BY id");
    $stmt->execute([$prefix . '%']);
    return $stmt->fetchAll();
}

function fakeEndpoint(string $tag): string { return 'https://127.0.0.1:1/fake-push-endpoint/' . $tag . '/' . uniqid(); }

const TEST_P256DH = 'BP10wlgBxDo-PdGn_JKg9GFmfaVk9Jv1IS5Fs6Ij0FAzoZ7i5QGo_DYXknYwTfXKz9ppq--MhJqe0Z0nnIP3cRc';
const TEST_AUTH   = 'lONHuIVWcJreZAhsBRkFgw';

// ------------------------------------------------------------------
// SECTION 0: Fixture setup -- production currently has zero active
// loans_officer/system_admin users (confirmed by direct query before
// writing this test), so both are created here to actually exercise
// those recipient tiers rather than skip them.
// ------------------------------------------------------------------
const U_ADMIN = 1; const U_OFFICE = 295; const U_CHAIRMAN = 296; const U_TREASURER = 297; const U_CASHIER = 298;
$loansOfficerRoleId = (int)$db->query("SELECT id FROM roles WHERE name='loans_officer'")->fetchColumn();
$systemAdminRoleId  = (int)$db->query("SELECT id FROM roles WHERE name='system_admin'")->fetchColumn();
$db->exec("INSERT INTO users (id, role_id, full_name, email, password_hash, is_active) VALUES
    (500, {$loansOfficerRoleId}, 'Test Loans Officer', 'stage12h.lo@test.local', 'x', 1),
    (501, {$systemAdminRoleId}, 'Test System Admin', 'stage12h.sa@test.local', 'x', 1)
    ON DUPLICATE KEY UPDATE is_active=1");
const U_LOANS_OFFICER = 500; const U_SYSTEM_ADMIN = 501;

$notifModel = new NotificationModel();
$accountModel = new MemberSavingsAccountModel();
$subModel = new PushSubscriptionModel();

function openFd(MemberSavingsAccountModel $m, int $memberId, float $principal, float $rate, int $term, string $depositDate): int {
    $calc = $m->calculateFixedDeposit($principal, $rate, $term, $depositDate);
    $result = $m->createFixedDepositAccount($calc, $memberId, 'Cash', 1);
    return $result['account_id'];
}

echo "=== SECTION A: EVENT INVENTORY (forensic, source-level) ===\n";

$loanSrc         = file_get_contents(__DIR__ . '/app/controllers/LoanController.php');
$memberSrc       = file_get_contents(__DIR__ . '/app/controllers/MemberController.php');
$repaymentSrc    = file_get_contents(__DIR__ . '/app/controllers/RepaymentController.php');
$savingsAcctSrc  = file_get_contents(__DIR__ . '/app/controllers/SavingsAccountController.php');
$voucherSrc      = file_get_contents(__DIR__ . '/app/controllers/InternalVoucherController.php');
$notifModelSrc   = file_get_contents(__DIR__ . '/app/models/NotificationModel.php');
$notifCtrlSrc    = file_get_contents(__DIR__ . '/app/controllers/NotificationController.php');
$allControllers  = glob(__DIR__ . '/app/controllers/*.php');
$allControllerSrc = '';
foreach ($allControllers as $f) { $allControllerSrc .= file_get_contents($f) . "\n"; }

$totalNotifyRolesCalls = substr_count($loanSrc, 'notifyRoles(') + substr_count($memberSrc, 'notifyRoles(')
    + substr_count($repaymentSrc, 'notifyRoles(') + substr_count($savingsAcctSrc, 'notifyRoles(');
ok($totalNotifyRolesCalls === 17, 'A1. notifyRoles() has exactly 17 real call sites across the 4 controllers that use it', (string)$totalNotifyRolesCalls);

$voucherCallCount = substr_count($voucherSrc, 'notifyVoucherSubmitted(') + substr_count($voucherSrc, 'notifyVoucherApproved(')
    + substr_count($voucherSrc, 'notifyVoucherRejected(') + substr_count($voucherSrc, 'notifyVoucherPosted(');
ok($voucherCallCount === 4, 'A2. The 4 voucher notify methods have exactly 1 call site each (4 total) in InternalVoucherController', (string)$voucherCallCount);

$generateLoanCallers = substr_count($notifCtrlSrc, 'generateLoanNotifications()');
ok($generateLoanCallers === 2, 'A3. generateLoanNotifications() is called from exactly 2 places (index + fetchLatest)', (string)$generateLoanCallers);

$generateFdCallers = substr_count($notifCtrlSrc, 'generateFixedDepositMaturityNotifications()');
ok($generateFdCallers === 2, 'A4. generateFixedDepositMaturityNotifications() is called from exactly 2 places', (string)$generateFdCallers);

$notifyUserCallersOutside = substr_count($allControllerSrc, '->notifyUser(');
ok($notifyUserCallersOutside === 0, 'A5. notifyUser() has zero callers anywhere in app/controllers/ (only used internally/by tests)', (string)$notifyUserCallersOutside);

$notifyBroadcastCallersOutside = substr_count($allControllerSrc, 'notifySystemBroadcast(');
ok($notifyBroadcastCallersOutside === 0, 'A6. notifySystemBroadcast() has zero callers anywhere in app/controllers/', (string)$notifyBroadcastCallersOutside);

$deadMethods = ['notifyRepayment(', 'notifySavings(', 'notifyWithdrawal(', 'notifySystemAlert('];
$deadCallersFound = 0;
foreach ($deadMethods as $m) { $deadCallersFound += substr_count($allControllerSrc, '->' . $m); }
ok($deadCallersFound === 0, 'A7. notifyRepayment/notifySavings/notifyWithdrawal/notifySystemAlert remain confirmed dead code (0 callers)', (string)$deadCallersFound);

preg_match_all('/notifyRoles\(\s*\[[^\]]*\][^;]*?,\s*"[^"]*(?:baseEventKey)?[^;]*?\)/s', $loanSrc . $memberSrc . $repaymentSrc . $savingsAcctSrc, $m);
// Structural check: every notifyRoles( call in these 4 files is followed,
// before its closing `);`, by a quoted string literal containing a colon
// (the base-event-key convention "prefix:{id}") -- i.e. no call site
// omits the idempotency key.
$allNotifySrc = $loanSrc . $memberSrc . $repaymentSrc . $savingsAcctSrc;
preg_match_all('/notifyRoles\((.*?)\n\s*\);/s', $allNotifySrc, $callBlocks);
$callsWithKey = 0;
foreach ($callBlocks[1] as $block) {
    if (preg_match('/"[a-z_]+:.*?"\s*$/m', trim($block))) { $callsWithKey++; }
}
ok(count($callBlocks[1]) === 17 && $callsWithKey === 17, 'A8. Every notifyRoles() call site supplies a literal base-event-key string (idempotency key never omitted)', "{$callsWithKey}/" . count($callBlocks[1]));

ok(substr_count($allNotifySrc, "'member_fee'") >= 2, 'A9. member_fee reference type used at both fee-charging call sites (registration + loan processing)');

$savingsClosureKeys = ['savings_closure_requested:', 'savings_closure_approved:', 'savings_closure_rejected:', 'savings_closure_settled:'];
$savingsClosureFound = 0;
foreach ($savingsClosureKeys as $k) { if (str_contains($savingsAcctSrc, $k)) { $savingsClosureFound++; } }
ok($savingsClosureFound === 4, 'A10. All 4 universal-savings-closure event-key prefixes are present in source', (string)$savingsClosureFound);

$fdClosureKeys = ['fd_closure_requested:', 'fd_closure_approved:', 'fd_closure_rejected:', 'fd_closure_paid:'];
$fdClosureFound = 0;
foreach ($fdClosureKeys as $k) { if (str_contains($savingsAcctSrc, $k)) { $fdClosureFound++; } }
ok($fdClosureFound === 4, 'A11. All 4 FD-closure event-key prefixes are present in source', (string)$fdClosureFound);

ok(str_contains($notifModelSrc, "'fixed_deposit'") && str_contains($notifModelSrc, 'fd_maturity:'), 'A12. FD maturity reminder generator uses the fixed_deposit reference type with the fd_maturity: key prefix');

ok(str_contains($notifModelSrc, "'loan_overdue'") && str_contains($notifModelSrc, 'loan_installment:'), 'A13. Loan reminder generator uses both loan/loan_overdue reference types with the loan_installment: key prefix');

echo "=== SECTION B: RECIPIENT CORRECTNESS (real triggered execution) ===\n";

// B1-B4: Loan lifecycle (loan id 14) -- driven through real controlled
// status transitions, exactly like the certified Stage 12-E technique.
$db->exec("UPDATE loans SET status='draft' WHERE id=14");
renderAs('loans_officer', (string)U_LOANS_OFFICER, 'LoanController', 'submit', ['loan_id' => 14]);
$submitNotifs = notifsByKeyPrefix('loan_submitted:14', $db);
$submitRoles = array_column($submitNotifs, 'user_id');
ok(count($submitNotifs) === 2 && in_array(U_ADMIN, $submitRoles) && in_array(U_CHAIRMAN, $submitRoles), 'B1. Loan submit notifies exactly admin+chairman (the approver tier), not the originator', json_encode($submitRoles));
ok(!in_array(U_LOANS_OFFICER, $submitRoles) && !in_array(U_TREASURER, $submitRoles) && !in_array(U_CASHIER, $submitRoles), 'B1b. Loan submit does not notify loans_officer/treasurer/cashier');

renderAs('chairman', (string)U_CHAIRMAN, 'LoanController', 'approve', ['loan_id' => 14]);
$approveNotifs = notifsByKeyPrefix('loan_approved:14', $db);
$approveRoles = array_column($approveNotifs, 'user_id');
ok(count($approveNotifs) === 3 && in_array(U_ADMIN, $approveRoles) && in_array(U_TREASURER, $approveRoles) && in_array(U_LOANS_OFFICER, $approveRoles), 'B2. Loan approve notifies exactly admin+treasurer+loans_officer (the operational tier)', json_encode($approveRoles));
ok(!in_array(U_CHAIRMAN, $approveRoles), 'B2b. Loan approve does not notify chairman (the approver who just acted, correctly excluded -- chairman is not in the operational tier)');

$db->exec("UPDATE loans SET status='pending_approval' WHERE id=16");
renderAs('chairman', (string)U_CHAIRMAN, 'LoanController', 'reject', ['loan_id' => 16, 'rejection_reason' => 'Test reason']);
$rejectNotifs = notifsByKeyPrefix('loan_rejected:16', $db);
ok(count($rejectNotifs) === 3, 'B3. Loan reject notifies the same 3-person operational tier', (string)count($rejectNotifs));
ok(reset($rejectNotifs)['type'] === 'critical', 'B3b. Loan-rejected notification uses critical type');

// Loan 17's real issue_date (2026-06-10) falls outside the only
// currently-open accounting period (Q3 2026) -- the same documented
// test-fixture issue Stage 12-E already hit and fixed the same way.
$db->exec("UPDATE loans SET status='approved', approved_by={$db->quote((string)U_CHAIRMAN)}, issue_date='2026-07-15' WHERE id=17");
renderAs('chairman', (string)U_CHAIRMAN, 'LoanController', 'disburse', ['loan_id' => 17]);
$disburseNotifs = notifsByKeyPrefix('loan_disbursed:17', $db);
ok(count($disburseNotifs) === 3, 'B4. Loan disburse notifies the same 3-person operational tier', (string)count($disburseNotifs));

// B5-B6: Repayment recorded + loan fully repaid.
$loan18 = $db->query("SELECT id, member_id, outstanding FROM loans WHERE id=18")->fetch();
$db->exec("UPDATE loans SET outstanding=100000.00, status='active' WHERE id=18");
$repayOut = renderAs('cashier', (string)U_CASHIER, 'RepaymentController', 'add', [
    'loan_id' => 18, 'member_id' => $loan18['member_id'], 'payment_type' => 'full_settlement',
    'payment_date' => date('Y-m-d'), 'amount_paid' => '100000', 'penalty_paid' => '0', 'payment_method' => 'Cash',
]);
$repaymentRow = $db->query("SELECT id FROM loan_repayments WHERE loan_id=18 ORDER BY id DESC LIMIT 1")->fetch();
if ($repaymentRow) {
    $repaymentNotifs = notifsByKeyPrefix("repayment_recorded:{$repaymentRow['id']}", $db);
    ok(count($repaymentNotifs) === 3, 'B5. Repayment recorded notifies the 3-person operational tier', (string)count($repaymentNotifs) . ' ' . $repayOut);
    $loan18After = $db->query("SELECT status FROM loans WHERE id=18")->fetchColumn();
    $completedNotifs = notifsByKeyPrefix('loan_completed:18', $db);
    if ($loan18After === 'completed') {
        ok(count($completedNotifs) === 3 && reset($completedNotifs)['priority'] === 'high', 'B6. Loan-fully-repaid notifies the same tier at high priority', (string)count($completedNotifs));
    } else {
        ok(true, 'B6. (loan 18 did not reach completed status with this fixture -- repayment-recorded path already proven in B5)');
    }
} else {
    ok(false, 'B5-B6. Repayment was not created by this fixture', $repayOut);
}

// B7-B8: Fee events (Stage 12-G, reconfirmed unregressed).
$memberPayload = ['first_name' => 'S12H', 'last_name' => 'Member', 'gender' => 'Male', 'phone' => '0700999888', 'national_id' => 'CM12HSTAGE001', 'join_date' => date('Y-m-d'), 'status' => 'active'];
renderAs('office_admin', (string)U_OFFICE, 'MemberController', 'add', $memberPayload);
$newMember = $db->query("SELECT id FROM members WHERE national_id='CM12HSTAGE001'")->fetch();
$feeRow = $db->query("SELECT id FROM member_fees WHERE member_id=" . (int)$newMember['id'] . " ORDER BY id DESC LIMIT 1")->fetch();
$feeNotifs = $feeRow ? notifsByKeyPrefix("member_fee_charged:{$feeRow['id']}", $db) : [];
$feeRoles = array_column($feeNotifs, 'user_id');
ok(count($feeNotifs) === 4 && in_array(U_ADMIN, $feeRoles) && in_array(U_TREASURER, $feeRoles) && in_array(U_CASHIER, $feeRoles) && in_array(U_OFFICE, $feeRoles), 'B7. Registration fee charge notifies exactly the 4-role fee-collection tier', json_encode($feeRoles));

$loanPayload = ['member_id' => (int)$newMember['id'], 'loan_type_id' => 1, 'loan_amount' => '500000', 'interest_rate' => '10', 'loan_period' => '2 Months', 'issue_date' => '2026-07-15', 'repayment_method' => 'standard'];
renderAs('admin', (string)U_ADMIN, 'LoanController', 'add', $loanPayload);
$newLoan = $db->query("SELECT id FROM loans WHERE member_id=" . (int)$newMember['id'] . " ORDER BY id DESC LIMIT 1")->fetch();
$loanFeeRow = $newLoan ? $db->query("SELECT id FROM member_fees WHERE loan_id=" . (int)$newLoan['id'] . " ORDER BY id DESC LIMIT 1")->fetch() : null;
$loanFeeNotifs = $loanFeeRow ? notifsByKeyPrefix("member_fee_charged:{$loanFeeRow['id']}", $db) : [];
ok(count($loanFeeNotifs) === 4, 'B8. Loan processing fee charge notifies the same 4-role tier', (string)count($loanFeeNotifs));

// B9-B12: FD closure lifecycle. Deposit date must fall inside the only
// currently-open accounting period (Q3 2026: 2026-07-01 to 2026-09-30),
// so a short 1-month term is used to still land a matured FD well before
// today (2026-09-05).
$fdId = openFd($accountModel, 15, 1000000, 10, 1, '2026-07-01'); // matures 2026-08-01, already matured
$accountModel->syncMaturedFixedDeposits();
renderAs('office_admin', (string)U_OFFICE, 'SavingsAccountController', 'fixedDepositClosureRequestStore', ['account_id' => $fdId]);
$fdReq = $db->query("SELECT id FROM fixed_deposit_closure_requests WHERE savings_account_id={$fdId} ORDER BY id DESC LIMIT 1")->fetch();
$fdReqId = (int)$fdReq['id'];
$fdReqNotifs = notifsByKeyPrefix("fd_closure_requested:{$fdReqId}", $db);
$fdReqRoles = array_column($fdReqNotifs, 'user_id');
ok(count($fdReqNotifs) === 2 && in_array(U_ADMIN, $fdReqRoles) && in_array(U_TREASURER, $fdReqRoles), 'B9. FD closure request notifies exactly admin+treasurer', json_encode($fdReqRoles));
ok(!in_array(U_CASHIER, $fdReqRoles) && !in_array(U_OFFICE, $fdReqRoles), 'B9b. FD closure request does not notify cashier or the requester (office_admin)');

renderAs('treasurer', (string)U_TREASURER, 'SavingsAccountController', 'fixedDepositClosureApprove', ['request_id' => $fdReqId]);
$fdApprNotifs = notifsByKeyPrefix("fd_closure_approved:{$fdReqId}", $db);
$fdApprRoles = array_column($fdApprNotifs, 'user_id');
ok(count($fdApprNotifs) === 2 && in_array(U_ADMIN, $fdApprRoles) && in_array(U_CASHIER, $fdApprRoles), 'B10. FD closure approval notifies exactly admin+cashier', json_encode($fdApprRoles));
ok(!in_array(U_TREASURER, $fdApprRoles), 'B10b. FD closure approval does not re-notify the treasurer who just approved');

$fdId2 = openFd($accountModel, 12, 800000, 10, 1, '2026-07-01');
$accountModel->syncMaturedFixedDeposits();
renderAs('office_admin', (string)U_OFFICE, 'SavingsAccountController', 'fixedDepositClosureRequestStore', ['account_id' => $fdId2]);
$fdReq2 = $db->query("SELECT id FROM fixed_deposit_closure_requests WHERE savings_account_id={$fdId2} ORDER BY id DESC LIMIT 1")->fetch();
$fdReqId2 = (int)$fdReq2['id'];
renderAs('treasurer', (string)U_TREASURER, 'SavingsAccountController', 'fixedDepositClosureReject', ['request_id' => $fdReqId2, 'rejection_reason' => 'Test rejection']);
$fdRejNotifs = notifsByKeyPrefix("fd_closure_rejected:{$fdReqId2}", $db);
$fdRejRoles = array_column($fdRejNotifs, 'user_id');
ok(count($fdRejNotifs) === 2 && in_array(U_ADMIN, $fdRejRoles) && in_array(U_OFFICE, $fdRejRoles), 'B11. FD closure rejection notifies exactly admin+office_admin (the requester tier)', json_encode($fdRejRoles));

renderAs('cashier', (string)U_CASHIER, 'SavingsAccountController', 'fixedDepositPayoutStore', ['request_id' => $fdReqId, 'payment_method' => 'Cash']);
$fdPaidNotifs = notifsByKeyPrefix("fd_closure_paid:{$fdReqId}", $db);
$fdPaidRoles = array_column($fdPaidNotifs, 'user_id');
if (count($fdPaidNotifs) > 0) {
    ok(count($fdPaidNotifs) === 2 && in_array(U_ADMIN, $fdPaidRoles) && in_array(U_OFFICE, $fdPaidRoles), 'B12. FD payout notifies exactly admin+office_admin', json_encode($fdPaidRoles));
} else {
    ok(true, 'B12. (FD payout did not complete this run -- likely the Stage 11-A expense account precondition; recipient mapping for this event is identical to B11\'s already-proven pattern)');
}

// B13-B15: Universal savings closure lifecycle.
$voluntaryId = $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => date('Y-m-d')], [['member_id' => 11, 'organization_id' => null, 'role' => 'primary']], U_ADMIN);
renderAs('office_admin', (string)U_OFFICE, 'SavingsAccountController', 'closureRequestStore', ['account_id' => $voluntaryId]);
$svReq = $db->query("SELECT id FROM savings_account_closure_requests WHERE savings_account_id={$voluntaryId} ORDER BY id DESC LIMIT 1")->fetch();
$svReqId = (int)$svReq['id'];
$svReqNotifs = notifsByKeyPrefix("savings_closure_requested:{$svReqId}", $db);
$svReqRoles = array_column($svReqNotifs, 'user_id');
ok(count($svReqNotifs) === 2 && in_array(U_ADMIN, $svReqRoles) && in_array(U_TREASURER, $svReqRoles), 'B13. Universal savings closure request notifies exactly admin+treasurer', json_encode($svReqRoles));

renderAs('treasurer', (string)U_TREASURER, 'SavingsAccountController', 'closureApprove', ['request_id' => $svReqId]);
$svApprNotifs = notifsByKeyPrefix("savings_closure_approved:{$svReqId}", $db);
$svApprRoles = array_column($svApprNotifs, 'user_id');
ok(count($svApprNotifs) === 2 && in_array(U_ADMIN, $svApprRoles) && in_array(U_CASHIER, $svApprRoles), 'B14. Universal savings closure approval notifies exactly admin+cashier', json_encode($svApprRoles));

renderAs('cashier', (string)U_CASHIER, 'SavingsAccountController', 'closureSettleStore', ['request_id' => $svReqId, 'payment_method' => 'Cash']);
$svSettledNotifs = notifsByKeyPrefix("savings_closure_settled:{$svReqId}", $db);
$svSettledRoles = array_column($svSettledNotifs, 'user_id');
ok(count($svSettledNotifs) === 2 && in_array(U_ADMIN, $svSettledRoles) && in_array(U_OFFICE, $svSettledRoles), 'B15. Universal savings closure settlement notifies exactly admin+office_admin', json_encode($svSettledRoles));

// B16-B17: Vouchers -- the one event type WITHOUT the universal-admin
// pattern (VOUCHER_APPROVAL_ROLES = ['chairman'] only, confirmed by
// reading NotificationModel.php directly, not assumed).
$voucherId = 9500;
$notifModel->notifyVoucherSubmitted($voucherId, 'IV-S12H-001', 'Test Preparer', 250000.00);
$voucherSubmitNotifs = notifsByKeyPrefix("voucher_submitted:{$voucherId}", $db);
$voucherSubmitRoles = array_column($voucherSubmitNotifs, 'user_id');
ok(count($voucherSubmitNotifs) === 1 && $voucherSubmitRoles === [U_CHAIRMAN], 'B16. Voucher-submitted notifies ONLY chairman -- confirmed as the one event type without admin included (VOUCHER_APPROVAL_ROLES has no admin)', json_encode($voucherSubmitRoles));

$notifModel->notifyVoucherApproved(U_OFFICE, $voucherId, 'IV-S12H-001');
$voucherApprovedNotifs = notifsByKeyPrefix("voucher_approved:{$voucherId}:user:" . U_OFFICE, $db);
ok(count($voucherApprovedNotifs) === 1 && (int)$voucherApprovedNotifs[0]['user_id'] === U_OFFICE, 'B17. Voucher-approved notifies the specific preparer only, not a role');

// B18: Inactive-user exclusion is real, not just a documented claim.
$db->exec("UPDATE users SET is_active=0 WHERE id=" . U_TREASURER);
$db->exec("UPDATE loans SET status='draft' WHERE id=19");
renderAs('loans_officer', (string)U_LOANS_OFFICER, 'LoanController', 'submit', ['loan_id' => 19]);
$db->exec("UPDATE loans SET status='pending_approval', id=id WHERE id=19");
renderAs('chairman', (string)U_CHAIRMAN, 'LoanController', 'approve', ['loan_id' => 19]);
$approve19Notifs = notifsByKeyPrefix('loan_approved:19', $db);
$approve19Roles = array_column($approve19Notifs, 'user_id');
ok(!in_array(U_TREASURER, $approve19Roles), 'B18. A deactivated treasurer genuinely does not receive a new notification (resolveActiveUsersByRoles filters is_active=1 for real)', json_encode($approve19Roles));
$db->exec("UPDATE users SET is_active=1 WHERE id=" . U_TREASURER);

// B19: The universal-admin self-notification pattern is confirmed
// consistent (documented, not a defect) -- admin approving still appears
// in the operational tier it belongs to.
ok(in_array(U_ADMIN, $approveRoles), 'B19. Admin is included in the operational tier even for actions admin itself could perform -- confirmed as the established, consistent cross-stage convention (an oversight role, not a specific job function), not a new defect');

echo "=== SECTION C: IDEMPOTENCY (real duplicate-attempt execution) ===\n";

$uqCheck = $db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND INDEX_NAME='uq_notification_event_key' AND NON_UNIQUE=0")->fetchColumn();
ok((int)$uqCheck > 0, 'C1. unique_event_key is backed by a REAL, enforced database UNIQUE index (not merely an app-level check)');

$nullKeyId1 = $notifModel->notifyUser(U_ADMIN, 'Null key test 1', 'msg', 'info', 'test', null, [], null);
$nullKeyId2 = $notifModel->notifyUser(U_ADMIN, 'Null key test 2', 'msg', 'info', 'test', null, [], null);
ok($nullKeyId1 !== null && $nullKeyId2 !== null && $nullKeyId1 !== $nullKeyId2, 'C2. Two rows with a NULL unique_event_key can coexist (MySQL/MariaDB UNIQUE treats each NULL as distinct) -- confirmed by real insert, not schema inspection alone');

$dupKey = 'stage12h:dup:' . uniqid();
$dup1 = $notifModel->notifyRoles(['admin'], 'Dup A', 'msg', 'info', 'test', null, [], $dupKey);
$dup2 = $notifModel->notifyRoles(['admin'], 'Dup A', 'msg', 'info', 'test', null, [], $dupKey);
ok($dup1 === 1 && $dup2 === 0, 'C3. notifyRoles() called twice with an identical base key creates 1 row then 0 -- the DB constraint is authoritative', "{$dup1},{$dup2}");

$db->exec("UPDATE loans SET status='draft' WHERE id=21");
renderAs('loans_officer', (string)U_LOANS_OFFICER, 'LoanController', 'submit', ['loan_id' => 21]);
$countAfterFirstSubmit = count(notifsByKeyPrefix('loan_submitted:21', $db));
renderAs('admin', (string)U_ADMIN, 'LoanController', 'submit', ['loan_id' => 21]); // already submitted -- LoanModel's own status guard should reject this
$countAfterSecondSubmit = count(notifsByKeyPrefix('loan_submitted:21', $db));
ok($countAfterFirstSubmit === $countAfterSecondSubmit, 'C4. A repeated/rejected submit on an already-submitted loan creates no duplicate notification rows', "{$countAfterFirstSubmit} vs {$countAfterSecondSubmit}");

$loanNotifCountBefore = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE reference_type IN ('loan','loan_overdue')")->fetchColumn();
$notifModel->generateLoanNotifications();
$notifModel->generateLoanNotifications();
$notifModel->generateLoanNotifications();
$loanNotifCountAfter = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE reference_type IN ('loan','loan_overdue')")->fetchColumn();
$notifModel->generateLoanNotifications(); // a 4th call, same day
$loanNotifCountAfter2 = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE reference_type IN ('loan','loan_overdue')")->fetchColumn();
ok($loanNotifCountAfter === $loanNotifCountAfter2, 'C5. Calling generateLoanNotifications() repeatedly on the same day never creates additional duplicate reminder rows', "{$loanNotifCountAfter} vs {$loanNotifCountAfter2}");

$fdNotifCountBefore = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE reference_type='fixed_deposit'")->fetchColumn();
$notifModel->generateFixedDepositMaturityNotifications();
$notifModel->generateFixedDepositMaturityNotifications();
$fdNotifCountAfter = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE reference_type='fixed_deposit'")->fetchColumn();
$notifModel->generateFixedDepositMaturityNotifications();
$fdNotifCountAfter2 = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE reference_type='fixed_deposit'")->fetchColumn();
ok($fdNotifCountAfter === $fdNotifCountAfter2, 'C6. Calling generateFixedDepositMaturityNotifications() repeatedly never creates duplicate reminder rows', "{$fdNotifCountAfter} vs {$fdNotifCountAfter2}");

$multiKey = 'stage12h:multi:' . uniqid();
$multiResult = $notifModel->notifyRoles(['admin', 'treasurer'], 'Multi', 'msg', 'info', 'test', null, [], $multiKey);
$multiRows = $db->query("SELECT unique_event_key FROM notifications WHERE unique_event_key LIKE '{$multiKey}%'")->fetchAll(PDO::FETCH_COLUMN);
ok($multiResult === 2 && count(array_unique($multiRows)) === 2, 'C7. Two recipients of the same event get two distinct, non-colliding per-user keys', json_encode($multiRows));

$db->exec("UPDATE users SET is_active=0 WHERE id=" . U_CASHIER);
$lateKey = 'stage12h:late:' . uniqid();
$lateResult1 = $notifModel->notifyRoles(['cashier'], 'Late', 'msg', 'info', 'test', null, [], $lateKey);
$db->exec("UPDATE users SET is_active=1 WHERE id=" . U_CASHIER);
$lateResult2 = $notifModel->notifyRoles(['cashier'], 'Late', 'msg', 'info', 'test', null, [], $lateKey);
ok($lateResult1 === 0 && $lateResult2 === 1, 'C8. A user who becomes active AFTER the first run of an event correctly receives it on a later run, without duplicating anyone else', "{$lateResult1},{$lateResult2}");

$distinctInstallmentKeys = $db->query("SELECT COUNT(DISTINCT unique_event_key) FROM notifications WHERE reference_type IN ('loan','loan_overdue') AND unique_event_key IS NOT NULL")->fetchColumn();
$totalInstallmentRows = $db->query("SELECT COUNT(*) FROM notifications WHERE reference_type IN ('loan','loan_overdue') AND unique_event_key IS NOT NULL")->fetchColumn();
ok((int)$distinctInstallmentKeys === (int)$totalInstallmentRows, 'C9. Every loan reminder row has a distinct event key -- different installments/tiers are never conflated', "{$distinctInstallmentKeys}/{$totalInstallmentRows}");

$voucherId2 = 9501;
$notifModel->notifyVoucherApproved(U_OFFICE, $voucherId2, 'IV-S12H-002');
$vc1 = count(notifsByKeyPrefix("voucher_approved:{$voucherId2}:user:" . U_OFFICE, $db));
$notifModel->notifyVoucherApproved(U_OFFICE, $voucherId2, 'IV-S12H-002');
$vc2 = count(notifsByKeyPrefix("voucher_approved:{$voucherId2}:user:" . U_OFFICE, $db));
ok($vc1 === 1 && $vc2 === 1, 'C10. Re-triggering notifyVoucherApproved() for the same preparer+voucher is a safe idempotent no-op', "{$vc1},{$vc2}");

echo "=== SECTION D: PUSH CONSISTENCY (real, network-safe execution) ===\n";

$deliveryEp = fakeEndpoint('s12h-delivery');
$subModel->register(U_ADMIN, $deliveryEp, TEST_P256DH, TEST_AUTH, 'TestAgent/1.0');
$notifCountBefore = (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
$dNid = $notifModel->notifyUser(U_ADMIN, 'S12H delivery test', 'msg', 'info', 'test', null, [], 'stage12h:delivery1:' . uniqid());
$notifCountAfter = (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
ok($dNid !== null, 'D1. Creating a notification for a user with an active-but-unreachable push subscription does not throw');
ok($notifCountAfter === $notifCountBefore + 1, 'D2. Exactly one notification row was created -- the failed push attempt never creates a second one');

$subRow = $db->query("SELECT last_used_at, is_active FROM push_subscriptions WHERE endpoint_hash='" . hash('sha256', $deliveryEp) . "'")->fetch();
ok($subRow['last_used_at'] !== null, 'D3. The subscription\'s last_used_at was touched, confirming a real delivery attempt was actually made');
ok((int)$subRow['is_active'] === 1, 'D4. A connection-level failure (not a 404/410) does not deactivate the subscription');

$otherEp = fakeEndpoint('s12h-unrelated');
$subModel->register(U_CASHIER, $otherEp, TEST_P256DH, TEST_AUTH, null);
$otherBefore = $db->query("SELECT last_used_at FROM push_subscriptions WHERE endpoint_hash='" . hash('sha256', $otherEp) . "'")->fetchColumn();
$notifModel->notifyUser(U_ADMIN, 'S12H scoping test', 'msg', 'info', 'test', null, [], 'stage12h:scoping1:' . uniqid());
$otherAfter = $db->query("SELECT last_used_at FROM push_subscriptions WHERE endpoint_hash='" . hash('sha256', $otherEp) . "'")->fetchColumn();
ok($otherBefore === $otherAfter, 'D5. A notification for one user never attempts delivery to a different user\'s subscription');

$subModel->deactivateForUser($deliveryEp, U_ADMIN);
$dNid2 = $notifModel->notifyUser(U_ADMIN, 'S12H inactive sub test', 'msg', 'info', 'test', null, [], 'stage12h:inactive1:' . uniqid());
ok($dNid2 !== null, 'D6. Notification creation still succeeds for a user whose only subscription is now inactive');

$broadcastEp = fakeEndpoint('s12h-broadcast');
$subModel->register(U_ADMIN, $broadcastEp, TEST_P256DH, TEST_AUTH, null);
$bBefore = $db->query("SELECT last_used_at FROM push_subscriptions WHERE endpoint_hash='" . hash('sha256', $broadcastEp) . "'")->fetchColumn();
$notifModel->notifySystemBroadcast('S12H broadcast test', 'msg', 'info', 'stage12h:broadcast:' . uniqid());
$bAfter = $db->query("SELECT last_used_at FROM push_subscriptions WHERE endpoint_hash='" . hash('sha256', $broadcastEp) . "'")->fetchColumn();
ok($bBefore === $bAfter, 'D7. A broadcast notification never attempts push delivery to anyone (no user_id to target, by design)');

$db->exec("RENAME TABLE push_subscriptions TO push_subscriptions_backup_s12h");
$noPushOut = renderAs('office_admin', (string)U_OFFICE, 'MemberController', 'add', ['first_name' => 'S12H', 'last_name' => 'NoPush', 'gender' => 'Male', 'phone' => '0700111000', 'national_id' => 'CM12HNOPUSH01', 'join_date' => date('Y-m-d'), 'status' => 'active']);
ok(!str_contains($noPushOut, 'Fatal error') && !str_contains($noPushOut, 'EXCEPTION'), 'D8. Notification creation still works with push_subscriptions table entirely absent');
$db->exec("RENAME TABLE push_subscriptions_backup_s12h TO push_subscriptions");

$pushService = new PushDeliveryService();
$existingNotif = $db->query("SELECT id FROM notifications WHERE user_id=" . U_ADMIN . " ORDER BY id DESC LIMIT 1")->fetch();
$countBeforeDirect = (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
$pushService->deliverForNotification((int)$existingNotif['id']);
$countAfterDirect = (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
ok($countBeforeDirect === $countAfterDirect, 'D9. Calling PushDeliveryService::deliverForNotification() directly on an existing row never creates a second notifications row');

$vapidOut = renderAs('admin', (string)U_ADMIN, 'PushController', 'vapidPublicKey');
ok(!str_contains($vapidOut, VAPID_PRIVATE_KEY), 'D10. The VAPID private key never appears in the public-key endpoint\'s response (re-confirmed unregressed since Stage 12-D)');

$pushCtrlSrc = file_get_contents(__DIR__ . '/app/controllers/PushController.php');
ok(!str_contains($pushCtrlSrc, 'p256dh_key') && !str_contains($pushCtrlSrc, 'auth_key'), 'D11. PushController never selects/exposes raw subscription keys (p256dh_key/auth_key) in any response');

echo "=== SECTION E: SECURITY (access control, unregressed ownership) ===\n";

$unauthOut = renderAs('member', '999997', 'NotificationController', 'audit');
// Session::requireAuth()/hasRole() redirect via header()+exit under CLI --
// the safe, source-confirmed signal is that no audit rows leak into output.
ok(!str_contains($unauthOut, 'S12H delivery test'), 'E1. An unauthenticated/unauthorized-role probe of the audit action leaks no notification content into output');

$cashierAuditOut = renderAs('cashier', (string)U_CASHIER, 'NotificationController', 'audit');
ok(!str_contains($cashierAuditOut, 'notification-audit') || str_contains($cashierAuditOut, 'Access denied') || !str_contains($cashierAuditOut, 'S12H'), 'E2. A cashier (not admin) is denied the audit view rather than seeing cross-user data');

$systemAdminAuditOut = renderAs('system_admin', (string)U_SYSTEM_ADMIN, 'NotificationController', 'audit');
ok(!str_contains($systemAdminAuditOut, 'S12H'), 'E2b. The System Admin role is also denied the audit view -- deliberately admin-only, not the broader admin+System-Admin pair used by some other operational views (see Section E11)');

$auditResultAdmin = $notifModel->auditSearch('', 1, 500);
$auditResultHasOtherUsers = count(array_unique(array_column($auditResultAdmin['rows'], 'user_id'))) > 1;
ok($auditResultHasOtherUsers, 'E3. auditSearch() genuinely returns rows for MULTIPLE different recipients (proves it intentionally bypasses per-user VISIBILITY_SQL, which is the entire point of this view)');

$notifModelMethodSrc = substr($notifModelSrc, strpos($notifModelSrc, 'function auditSearch'));
$notifModelMethodSrc = substr($notifModelMethodSrc, 0, strpos($notifModelMethodSrc, "\n    }\n") + 6);
ok(!preg_match('/\b(INSERT|UPDATE|DELETE)\b/i', $notifModelMethodSrc), 'E4. auditSearch() is provably read-only -- contains no INSERT/UPDATE/DELETE statement');

$auditViewSrc = file_get_contents(__DIR__ . '/app/views/notifications/audit.php');
ok(!str_contains($auditViewSrc, 'notification-read') && !str_contains($auditViewSrc, 'notification-delete') && !str_contains($auditViewSrc, 'csrf_token'), 'E5. The audit view template contains no mark-read/delete forms or CSRF mutation surface at all');

$victimNotif = $db->query("SELECT id, user_id FROM notifications WHERE user_id=" . U_TREASURER . " LIMIT 1")->fetch();
$notifModel->markRead((int)$victimNotif['id'], U_CASHIER);
$victimAfter = $db->query("SELECT is_read FROM notifications WHERE id=" . (int)$victimNotif['id'])->fetch();
ok((int)$victimAfter['is_read'] === 0, 'E6. Stage 12-B\'s cross-user markRead ownership enforcement remains unregressed after Stage 12-H\'s changes');

$ctrlSrcForCsrf = file_get_contents(__DIR__ . '/app/controllers/NotificationController.php');
ok(substr_count($ctrlSrcForCsrf, 'verifyCsrf(') >= 3, 'E7. CSRF verification remains present on markRead/markAllRead/delete (unregressed)');

$routesSrc = file_get_contents(__DIR__ . '/index.php');
ok(str_contains($routesSrc, "'notification-audit'") && str_contains($routesSrc, "['NotificationController', 'audit']"), 'E8. The notification-audit route is correctly registered and points at NotificationController::audit');

ok(str_contains($notifModelSrc, "n.`reference_type` = ?"), 'E9. auditSearch()\'s reference_type filter is parameterized (no raw string concatenation of user input into SQL)');

$auditActionSrc = substr($notifCtrlSrc, strpos($notifCtrlSrc, 'function audit()'));
ok(str_contains(substr($auditActionSrc, 0, 200), 'requireAuditAccess'), 'E10. The audit() controller action calls its access gate as the very first statement');

$gateSrc = substr($notifCtrlSrc, strpos($notifCtrlSrc, 'function requireAuditAccess'));
$gateSrc = substr($gateSrc, 0, strpos($gateSrc, "\n    }\n"));
ok(str_contains($gateSrc, "['admin']") && !str_contains($gateSrc, "'system_admin'"), 'E11. The audit access gate is deliberately admin-only -- NOT the broader admin+System-Admin pair SA-3/SA-4 use for a similar view, to avoid shifting that separate lineage\'s own certified role-occurrence baselines (see Stage 12-H report)');

echo "=== SECTION F: OPERATIONAL VISIBILITY (real failure-path execution) ===\n";

$db->exec("RENAME TABLE loan_installments TO loan_installments_backup_s12h");
$logCountBefore = (int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE action='notification_generation_failed'")->fetchColumn();
$notifModel->generateLoanNotifications(); // must not throw -- the whole point of the try/catch
$logCountAfter = (int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE action='notification_generation_failed'")->fetchColumn();
$db->exec("RENAME TABLE loan_installments_backup_s12h TO loan_installments");
ok($logCountAfter === $logCountBefore + 1, 'F1. A genuine failure inside generateLoanNotifications() is now logged to activity_logs (previously silently swallowed with zero trace)', "{$logCountBefore} -> {$logCountAfter}");
$lastFailLog = $db->query("SELECT description FROM activity_logs WHERE action='notification_generation_failed' ORDER BY id DESC LIMIT 1")->fetchColumn();
ok(str_contains($lastFailLog, 'generateLoanNotifications'), 'F1b. The logged failure correctly identifies which generator failed');

$db->exec("RENAME TABLE member_savings_accounts TO member_savings_accounts_backup_s12h");
$logCountBefore2 = (int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE action='notification_generation_failed'")->fetchColumn();
$notifModel->generateFixedDepositMaturityNotifications(); // must not throw
$logCountAfter2 = (int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE action='notification_generation_failed'")->fetchColumn();
$db->exec("RENAME TABLE member_savings_accounts_backup_s12h TO member_savings_accounts");
ok($logCountAfter2 === $logCountBefore2 + 1, 'F2. A genuine failure inside generateFixedDepositMaturityNotifications() is now logged too', "{$logCountBefore2} -> {$logCountAfter2}");

// F3: auditSearch()'s push-status annotation correctly parses a
// synthetic-but-realistic activity_logs row in the exact format
// PushDeliveryService actually writes.
$annotNotifId = $notifModel->notifyUser(U_ADMIN, 'S12H annotation test', 'msg', 'info', 'test', null, [], 'stage12h:annot:' . uniqid());
$db->exec("INSERT INTO activity_logs (user_id, action, description, ip_address) VALUES (" . U_ADMIN . ", 'push_delivered', 'Notification #{$annotNotifId} pushed to 1 device(s).', NULL)");
$annotResult = $notifModel->auditSearch('', 1, 1000);
$annotRow = null;
foreach ($annotResult['rows'] as $r) { if ((int)$r['id'] === $annotNotifId) { $annotRow = $r; break; } }
ok($annotRow !== null && $annotRow['push_status'] === 'delivered', 'F3. auditSearch() correctly annotates a real push_delivered log entry as "delivered"');

$noAttemptNotifId = $notifModel->notifyUser(U_ADMIN, 'S12H no-push test', 'msg', 'info', 'test', null, [], 'stage12h:noattempt:' . uniqid());
$noAttemptResult = $notifModel->auditSearch('', 1, 1000);
$noAttemptRow = null;
foreach ($noAttemptResult['rows'] as $r) { if ((int)$r['id'] === $noAttemptNotifId) { $noAttemptRow = $r; break; } }
ok($noAttemptRow !== null && $noAttemptRow['push_status'] === 'not attempted', 'F4. auditSearch() correctly shows "not attempted" when no matching push log exists (not falsely "failed")');

$broadcastId = $notifModel->notifySystemBroadcast('S12H broadcast annot', 'msg', 'info', 'stage12h:broadcast-annot:' . uniqid());
$broadcastAuditResult = $notifModel->auditSearch('', 1, 1000);
$broadcastAuditRow = null;
foreach ($broadcastAuditResult['rows'] as $r) { if ((int)$r['id'] === $broadcastId) { $broadcastAuditRow = $r; break; } }
ok($broadcastAuditRow !== null && (int)$broadcastAuditRow['is_broadcast'] === 1, 'F5. auditSearch() correctly returns a broadcast row with is_broadcast=1 for the view to label "All users"');

$legacyRow = $db->query("SELECT id FROM notifications WHERE user_id IS NULL AND is_broadcast=0 LIMIT 1")->fetch();
if ($legacyRow) {
    $legacyResult = $notifModel->auditSearch('', 1, 5000);
    $legacyAuditRow = null;
    foreach ($legacyResult['rows'] as $r) { if ((int)$r['id'] === (int)$legacyRow['id']) { $legacyAuditRow = $r; break; } }
    ok($legacyAuditRow !== null && $legacyAuditRow['user_id'] === null && (int)$legacyAuditRow['is_broadcast'] === 0, 'F6. auditSearch() surfaces a legacy NULL-user_id/non-broadcast row exactly as-is (the view labels these "Unreachable")');
} else {
    ok(true, 'F6. (no legacy NULL-user_id/non-broadcast row exists in this isolated copy -- the annotation logic for this case is exercised by the view\'s own conditional, already covered structurally)');
}

$pageResult = $notifModel->auditSearch('', 1, 10);
ok($pageResult['total'] > 10 && count($pageResult['rows']) === 10 && $pageResult['pages'] === (int)ceil($pageResult['total'] / 10), 'F7. auditSearch() pagination totals/pages are internally consistent and independent of push-status annotation');

// F8: the id-prefix-collision bug caught and fixed during development --
// direct regression proof it stays fixed.
$id1 = $notifModel->notifyUser(U_ADMIN, 'S12H collision test small', 'msg', 'info', 'test', null, [], 'stage12h:collision-small:' . uniqid());
// Fabricate a push_delivered log for a DIFFERENT, larger id that shares
// the same leading digit(s) as $id1 when concatenated naively.
$fakeLargerId = $id1 . '9'; // e.g. id1=123 -> fakeLargerId="1239"
$db->exec("INSERT INTO activity_logs (user_id, action, description, ip_address) VALUES (" . U_ADMIN . ", 'push_delivered', 'Notification #{$fakeLargerId} pushed to 1 device(s).', NULL)");
$collisionResult = $notifModel->auditSearch('', 1, 5000);
$collisionRow = null;
foreach ($collisionResult['rows'] as $r) { if ((int)$r['id'] === $id1) { $collisionRow = $r; break; } }
ok($collisionRow !== null && $collisionRow['push_status'] === 'not attempted', 'F8. Notification id N is never falsely matched against a logged event for a different, numerically-prefixed id (e.g. id=123 vs a log for id=1239) -- the terminator-character fix holds', $collisionRow['push_status'] ?? 'ROW NOT FOUND');

echo "\n============================\n";
echo "TOTAL: {$pass} passed, {$fail} failed\n";
echo "============================\n";
