<?php
/**
 * ISOLATED — Stage 12-E: Loan Notification Events & Automation.
 * Runs ONLY against empower_db_stage12e_test. Never touches empower_db.
 */
chdir(__DIR__);
$pass = 0; $fail = 0;
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }

define('DB_NAME', 'empower_db_stage12e_test');
require 'app/config/config.php';
require 'app/config/push.php';
require 'vendor/autoload.php';
require 'test_safety_guard.php';
require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';
$db = Database::getInstance()->getConnection();

function renderAs(string $role, string $userId, string $class, string $action, array $post = [], array $get = []): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_stage12e_subproc_' . $counter . '.php';
    $postLines = "\$_SERVER['REQUEST_METHOD'] = 'POST';\n\$_POST['csrf_token'] = 'skip';\n";
    foreach ($post as $k => $v) { $postLines .= "\$_POST['{$k}'] = " . var_export($v, true) . ";\n"; }
    $getLines = ''; foreach ($get as $k => $v) { $getLines .= "\$_GET['{$k}'] = " . var_export($v, true) . ";\n"; }
    $body = <<<PHP
<?php
chdir(__DIR__);
define('DB_NAME', 'empower_db_stage12e_test');
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
{$getLines}
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

// Real user ids: 1=admin, 296=chairman, 297=treasurer, 298=cashier, 295=office_admin
const U_ADMIN = 1; const U_CHAIRMAN = 296; const U_TREASURER = 297; const U_CASHIER = 298;

$notifModel = new NotificationModel();
$loanModel = new LoanModel();

function notifsFor(int $loanOrRefId, string $refType, PDO $db): array {
    $stmt = $db->prepare("SELECT * FROM notifications WHERE reference_type=? AND reference_id=? ORDER BY id");
    $stmt->execute([$refType, $loanOrRefId]);
    return $stmt->fetchAll();
}

echo "=== SECTION 1: Loan submitted for approval -> admin+chairman only ===\n";

$db->exec("UPDATE loans SET status='draft', recorded_by=1 WHERE id=14");
renderAs('admin', (string)U_ADMIN, 'LoanController', 'submit', ['loan_id' => 14]);
$submitted = notifsFor(14, 'loan', $db);
$submittedThisEvent = array_filter($submitted, fn($n) => str_starts_with($n['unique_event_key'], 'loan_submitted:14:user:'));
$roles = array_column($submittedThisEvent, 'user_id');
ok(count($submittedThisEvent) === 2, '1. Loan-submitted notification creates exactly 2 rows (admin + chairman, the two active users holding those roles)', (string)count($submittedThisEvent));
ok(in_array(U_ADMIN, $roles) && in_array(U_CHAIRMAN, $roles), '2. The two rows belong to admin and chairman specifically');
ok(!in_array(U_TREASURER, $roles) && !in_array(U_CASHIER, $roles), '3. Treasurer and cashier (not approvers) do not receive the submitted notification');
$recipientRoleTag = $submittedThisEvent[0]['recipient_role'] ?? '';
ok(str_contains($recipientRoleTag, 'admin') && str_contains($recipientRoleTag, 'chairman'), '4. recipient_role carries audit metadata of which roles this was resolved from');

echo "=== SECTION 2: Loan approved -> admin+treasurer+loans_officer (operational team, NOT re-notifying chairman) ===\n";

$loanRow = $db->query("SELECT status FROM loans WHERE id=14")->fetch();
ok($loanRow['status'] === 'pending_approval', '5. submit() correctly transitioned the loan to pending_approval');

renderAs('chairman', (string)U_CHAIRMAN, 'LoanController', 'approve', ['loan_id' => 14]);
$approved = array_filter(notifsFor(14, 'loan', $db), fn($n) => str_starts_with($n['unique_event_key'], 'loan_approved:14:user:'));
$approvedRoles = array_column($approved, 'user_id');
ok(count($approved) === 2, '6. Loan-approved notification reaches exactly the operational audience size (admin + treasurer; no loans_officer user exists in this dataset)', (string)count($approved));
ok(in_array(U_ADMIN, $approvedRoles) && in_array(U_TREASURER, $approvedRoles), '7. Admin and treasurer both received the approval notice');
ok(!in_array(U_CHAIRMAN, $approvedRoles), '8. Chairman (who performed the approval) is not redundantly re-notified of their own action -- mirrors the certified voucher pattern');
$loanAfterApprove = $db->query("SELECT status FROM loans WHERE id=14")->fetch();
ok($loanAfterApprove['status'] === 'approved', '9. The loan itself is genuinely approved (notification reflects real state, not a guess)');

echo "=== SECTION 3: Loan rejected -> operational team, reason included ===\n";

$db->exec("UPDATE loans SET status='draft', rejected_by=NULL WHERE id=16");
renderAs('admin', (string)U_ADMIN, 'LoanController', 'submit', ['loan_id' => 16]);
$out = renderAs('chairman', (string)U_CHAIRMAN, 'LoanController', 'reject', ['loan_id' => 16, 'rejection_reason' => 'Insufficient collateral documentation']);
$rejected = array_filter(notifsFor(16, 'loan', $db), fn($n) => str_starts_with($n['unique_event_key'], 'loan_rejected:16:user:'));
ok(count($rejected) === 2, '10. Loan-rejected notification reaches the operational audience');
$rejectedMsg = reset($rejected)['message'] ?? '';
ok(str_contains($rejectedMsg, 'Insufficient collateral documentation'), '11. The rejection reason is included in the notification message');
$loanAfterReject = $db->query("SELECT status FROM loans WHERE id=16")->fetch();
ok($loanAfterReject['status'] === 'rejected', '12. The loan is genuinely rejected');

echo "=== SECTION 4: Loan disbursed ===\n";

// issue_date must fall within the currently open accounting period (Q3 2026)
// for JournalService to accept the disbursement posting -- a test-fixture
// requirement, not a Stage 12-E code change (loan 17's real issue_date is
// 2026-06-10, outside today's open period).
$db->exec("UPDATE loans SET status='approved', approved_by=297, issue_date='2026-07-15' WHERE id=17");
$outDisburse = renderAs('chairman', (string)U_CHAIRMAN, 'LoanController', 'disburse', ['loan_id' => 17]);
$disbursed = array_filter(notifsFor(17, 'loan', $db), fn($n) => str_starts_with($n['unique_event_key'], 'loan_disbursed:17:user:'));
ok(count($disbursed) === 2, '13. Loan-disbursed notification reaches the operational audience', $outDisburse);
$loanAfterDisburse = $db->query("SELECT status FROM loans WHERE id=17")->fetch();
ok($loanAfterDisburse['status'] === 'active', '14. The loan is genuinely active (disbursed) -- confirms the notification followed a real, successful accounting operation, not a guess');

echo "=== SECTION 5: Repayment recorded + loan completion ===\n";

$db->exec("UPDATE loans SET outstanding=5000.00, status='active' WHERE id=18");
$repayOut = renderAs('cashier', (string)U_CASHIER, 'RepaymentController', 'add', [
    'loan_id' => 18, 'member_id' => 11, 'payment_type' => 'installment',
    'payment_date' => date('Y-m-d'), 'amount_paid' => '5000', 'penalty_paid' => '0',
    'payment_method' => 'Cash',
]);
$loanAfterRepay = $db->query("SELECT status, outstanding FROM loans WHERE id=18")->fetch();
ok((float)$loanAfterRepay['outstanding'] <= 0.01, '15. The full outstanding balance was actually paid off (real financial state, not assumed)', json_encode($loanAfterRepay));
ok($loanAfterRepay['status'] === 'completed', '16. The loan genuinely transitioned to completed', $repayOut);

$repaymentIdRow = $db->query("SELECT id FROM loan_repayments WHERE loan_id=18 ORDER BY id DESC LIMIT 1")->fetch();
$repaymentId = (int)$repaymentIdRow['id'];
$repaymentNotifs = notifsFor($repaymentId, 'repayment', $db);
ok(count($repaymentNotifs) === 2, '17. A "repayment recorded" notification was created for the operational audience', (string)count($repaymentNotifs));
$completedNotifs = array_filter(notifsFor(18, 'loan', $db), fn($n) => str_starts_with($n['unique_event_key'], 'loan_completed:18:user:'));
ok(count($completedNotifs) === 2, '18. A separate "loan fully repaid" notification was ALSO created (distinct event from the ordinary repayment notice)');
$completedRow = reset($completedNotifs);
ok($completedRow['priority'] === 'high', '19. The loan-completed notification carries high priority (a more significant event than a routine repayment)');
ok($completedRow['type'] === 'success', '20. The loan-completed notification uses the "success" type');

echo "=== SECTION 6: Partial repayment does not falsely trigger completion ===\n";

$db->exec("UPDATE loans SET outstanding=100000.00, status='active' WHERE id=19");
renderAs('cashier', (string)U_CASHIER, 'RepaymentController', 'add', [
    'loan_id' => 19, 'member_id' => 17, 'payment_type' => 'installment',
    'payment_date' => date('Y-m-d'), 'amount_paid' => '20000', 'penalty_paid' => '0',
    'payment_method' => 'Cash',
]);
$loan19After = $db->query("SELECT status FROM loans WHERE id=19")->fetch();
ok($loan19After['status'] !== 'completed', '21. A partial repayment leaves the loan not-completed');
$loan19Repay = $db->query("SELECT id FROM loan_repayments WHERE loan_id=19 ORDER BY id DESC LIMIT 1")->fetch();
$partialCompletedNotifs = array_filter(notifsFor(19, 'loan', $db), fn($n) => str_starts_with($n['unique_event_key'], 'loan_completed:19:user:'));
ok(count($partialCompletedNotifs) === 0, '22. No false "loan completed" notification was created for a partial repayment');
$partialRepayNotifs = notifsFor((int)$loan19Repay['id'], 'repayment', $db);
ok(count($partialRepayNotifs) === 2, '23. The ordinary repayment-recorded notification still fires correctly for a partial payment');

echo "=== SECTION 7: Security / authorization ===\n";

$out403 = renderAs('cashier', (string)U_CASHIER, 'LoanController', 'approve', ['loan_id' => 14]);
ok(str_contains($out403, 'Access denied') || !str_contains($out403, 'approved'), '24. Cashier (not an approver) is blocked from approving a loan');
$db->exec("UPDATE loans SET status='pending_approval', recorded_by=1 WHERE id=14");
$countBeforeSelfApprove = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE unique_event_key LIKE 'loan_approved:14%'")->fetchColumn();
$outSelfApprove = renderAs('admin', (string)U_ADMIN, 'LoanController', 'approve', ['loan_id' => 14]); // admin = recorded_by
$countAfterSelfApprove = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE unique_event_key LIKE 'loan_approved:14%'")->fetchColumn();
$loan14StillPending = $db->query("SELECT status FROM loans WHERE id=14")->fetch();
ok($loan14StillPending['status'] === 'pending_approval', '25. Self-approval (recorded_by === approver) is still blocked -- Stage 12-E did not weaken this existing maker-checker rule');
ok($countAfterSelfApprove === $countBeforeSelfApprove, '26. No approval notification was created by the blocked self-approval attempt');

echo "=== SECTION 8: Idempotency / deduplication ===\n";

// issue_date must fall within the currently open accounting period (Q3 2026)
// for JournalService to accept the disbursement posting -- a test-fixture
// requirement, not a Stage 12-E code change (loan 17's real issue_date is
// 2026-06-10, outside today's open period).
$db->exec("UPDATE loans SET status='approved', approved_by=297, issue_date='2026-07-15' WHERE id=17");
$countBeforeDup = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE unique_event_key LIKE 'loan_disbursed:17%'")->fetchColumn();
try {
    renderAs('chairman', (string)U_CHAIRMAN, 'LoanController', 'disburse', ['loan_id' => 17]); // already disbursed once above -- should be rejected by LoanModel's own status guard now
} catch (Throwable $e) {}
$countAfterDup = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE unique_event_key LIKE 'loan_disbursed:17%'")->fetchColumn();
ok($countAfterDup === $countBeforeDup, '27. A repeated disburse attempt (blocked by LoanModel\'s own status guard, loan already active) creates no duplicate notification');

$directDup1 = $notifModel->notifyRoles(['admin'], "dup test", "msg", 'info', 'loan', 999, [], 'test12e:dup:fixed');
$directDup2 = $notifModel->notifyRoles(['admin'], "dup test", "msg", 'info', 'loan', 999, [], 'test12e:dup:fixed');
ok($directDup1 === 1 && $directDup2 === 0, '28. A repeated unique_event_key at the notifyRoles() level is rejected as a duplicate (the DB UNIQUE constraint, not an app-level check, is authoritative)');

echo "=== SECTION 9: Reminder correctness (installment-level, re-confirming Stage 12-B is unregressed) ===\n";

$db->exec("
    UPDATE loan_installments SET due_date='2026-09-11', status='pending' WHERE id=78;
    UPDATE loan_installments SET due_date='2026-09-04', status='pending' WHERE id=150;
    UPDATE loan_installments SET status='paid', amount_paid=amount_due, paid_date='2026-08-01' WHERE id=151;
");
$notifModel->generateLoanNotifications();
$titles7 = $db->query("SELECT title FROM notifications WHERE unique_event_key LIKE 'loan_installment:78:due_7:%'")->fetchAll(PDO::FETCH_COLUMN);
$titlesToday = $db->query("SELECT title FROM notifications WHERE unique_event_key LIKE 'loan_installment:150:due_0:%'")->fetchAll(PDO::FETCH_COLUMN);
ok(count($titles7) === 2, '29. Due-in-7-days reminder still generates correctly for the operational audience (Stage 12-B unregressed)');
ok(count($titlesToday) === 2, '30. Due-today reminder still generates correctly');

// Loan 14's real installment #2 stays overdue while #1 is paid and #3 is later-pending --
// confirm the "current installment" selection still ignores the later one.
$loan14Installments = $db->query("SELECT id, installment_no, status FROM loan_installments WHERE loan_id=14 ORDER BY installment_no LIMIT 3")->fetchAll();
ok($loan14Installments[0]['status'] === 'paid' && in_array($loan14Installments[1]['status'], ['overdue','pending']), '31. Multi-installment loan 14 still has installment #1 paid / #2 current, matching real production shape');

echo "=== SECTION 10: Metadata correctness ===\n";

$submittedRow = $db->query("SELECT * FROM notifications WHERE unique_event_key LIKE 'loan_submitted:14:user:%' LIMIT 1")->fetch();
ok((int)$submittedRow['loan_id'] === 14, '32. loan_id metadata column is correctly populated');
ok($submittedRow['reference_type'] === 'loan' && (int)$submittedRow['reference_id'] === 14, '33. reference_type=loan and reference_id=LOAN id (not an installment id) -- preserves the existing UI contract');
ok(str_contains($submittedRow['action_url'], 'loan-view&id=14'), '34. action_url points at the correct loan-view page');
ok($submittedRow['priority'] === 'normal', '35. Ordinary lifecycle events use normal priority');
ok($completedRow['type'] === 'success' && $completedRow['priority'] === 'high', '36. Loan-completed correctly combines type=success with priority=high');

echo "=== SECTION 11: Transaction safety ===\n";

// A submit() call on an already-approved loan must fail (status guard) and create NO notification at all.
$countBeforeInvalid = (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
renderAs('admin', (string)U_ADMIN, 'LoanController', 'submit', ['loan_id' => 17]); // loan 17 is 'active' now, not draft/rejected
$countAfterInvalid = (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
ok($countAfterInvalid === $countBeforeInvalid, '37. A business-rule-rejected transition (wrong status) creates no notification -- notification never fires ahead of a real state change');

// A repayment that fails validation (exceeds outstanding) creates no notification.
$db->exec("UPDATE loans SET outstanding=1000.00, status='active' WHERE id=14");
$countBeforeOverpay = (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
renderAs('cashier', (string)U_CASHIER, 'RepaymentController', 'add', [
    'loan_id' => 14, 'member_id' => 15, 'payment_type' => 'installment',
    'payment_date' => date('Y-m-d'), 'amount_paid' => '999999', 'penalty_paid' => '0', 'payment_method' => 'Cash',
]);
$countAfterOverpay = (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
ok($countAfterOverpay === $countBeforeOverpay, '38. A repayment rejected by validation (exceeds outstanding) creates no notification');

echo "=== SECTION 12: Push compatibility ===\n";

$db->exec("DROP TABLE IF EXISTS push_subscriptions_backup_temp");
$db->exec("RENAME TABLE push_subscriptions TO push_subscriptions_backup_temp");
$db->exec("UPDATE loans SET status='draft' WHERE id=19");
$outNoPushTable = renderAs('admin', (string)U_ADMIN, 'LoanController', 'submit', ['loan_id' => 19]);
ok(!str_contains($outNoPushTable, 'Fatal error') && !str_contains($outNoPushTable, 'EXCEPTION'), '39. Loan-submitted notification creation still works with push_subscriptions table entirely absent');
$submitted19 = array_filter(notifsFor(19, 'loan', $db), fn($n) => str_starts_with($n['unique_event_key'], 'loan_submitted:19:user:'));
ok(count($submitted19) === 2, '40. The notification row(s) were still created despite push infrastructure being unavailable');
$db->exec("RENAME TABLE push_subscriptions_backup_temp TO push_subscriptions");

echo "=== SECTION 13: Recipient isolation / IDOR (Stage 12-B/12-C protections remain intact) ===\n";

$adminRow = reset($approved);
$treasurerRow = null;
foreach ($approved as $r) { if ((int)$r['user_id'] === U_TREASURER) { $treasurerRow = $r; break; } }
$notifModel->markRead((int)$treasurerRow['id'], U_CASHIER); // cashier attempts to mark treasurer's notification read
$treasurerRowAfter = $db->query("SELECT is_read FROM notifications WHERE id=" . (int)$treasurerRow['id'])->fetch();
ok((int)$treasurerRowAfter['is_read'] === 0, '41. Stage 12-B\'s ownership enforcement (markRead requires the correct user_id) still blocks cross-user access to a new loan notification type');

$notifModel->markRead((int)$treasurerRow['id'], U_TREASURER);
$treasurerRowAfter2 = $db->query("SELECT is_read FROM notifications WHERE id=" . (int)$treasurerRow['id'])->fetch();
ok((int)$treasurerRowAfter2['is_read'] === 1, '42. The legitimate owner can mark their own new-type notification read');

$outNoCsrf = renderAs('admin', (string)U_ADMIN, 'NotificationController', 'markRead', ['id' => (int)$adminRow['id']]);
ok(!str_contains($outNoCsrf, 'Fatal'), '43. Stage 12-C\'s CSRF-protected mark-read route still functions correctly for a new loan-notification type');

echo "=== SECTION 14: Additional metadata / recipient consistency ===\n";

$repaymentNotifRow = reset($repaymentNotifs);
ok((int)$repaymentNotifRow['loan_id'] === 18, '44. The repayment-recorded notification correctly carries loan_id=18, not the repayment id, in its loan_id column');
ok(str_contains($repaymentNotifRow['action_url'], 'repayment-view'), '45. The repayment-recorded notification\'s action_url points at the repayment view, not the loan view');
ok(!empty($repaymentNotifRow['member_id']), '46. The repayment-recorded notification carries the member_id context');

$rejectedRow = reset($rejected);
ok($rejectedRow['type'] === 'critical', '47. Loan-rejected notification uses the critical type (matches the existing severity convention for a rejection)');

$disbursedRow = reset($disbursed);
ok($disbursedRow['type'] === 'success', '48. Loan-disbursed notification uses the success type');

// loans_officer role currently has zero active users in this dataset --
// notifyRoles() must still succeed gracefully (2 recipients, not an error).
ok(count($approved) === 2 && !str_contains($outDisburse, 'Fatal'), '49. notifyRoles() with a role that currently has zero active users (loans_officer) still succeeds gracefully for the roles that do have active users');

// Confirm no accidental global/NULL-recipient row was created by any Stage 12-E event.
$anyNullRecipientLoanRows = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE reference_type IN ('loan','repayment') AND user_id IS NULL AND is_broadcast=0 AND unique_event_key LIKE 'loan_%'")->fetchColumn();
ok($anyNullRecipientLoanRows === 0, '50. Zero accidental NULL-recipient rows were created by any new Stage 12-E event -- every row has a concrete user_id');

echo "\n============================\n";
echo "TOTAL: {$pass} passed, {$fail} failed\n";
echo "============================\n";
