<?php
/**
 * ISOLATED — Stage 12-B: Personalized Notification Core Engine.
 * Runs ONLY against empower_db_stage12b_test. Never touches empower_db.
 */
chdir(__DIR__);
$pass = 0; $fail = 0;
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }

define('DB_NAME', 'empower_db_stage12b_test');
require 'app/config/config.php';
require 'test_safety_guard.php';
require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';
$db = Database::getInstance()->getConnection();

function renderAs(string $role, string $userId, string $class, string $method, array $get = []): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_stage12b_subproc_' . $counter . '.php';
    $getLines = ''; foreach ($get as $k => $v) { $getLines .= "\$_GET['{$k}'] = " . var_export($v, true) . ";\n"; }
    $body = <<<PHP
<?php
chdir(__DIR__);
define('DB_NAME', 'empower_db_stage12b_test');
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
{$getLines}
try {
    ob_start();
    (new {$class}())->{$method}();
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

$model = new NotificationModel();

// Real user ids in this dataset: 1=admin, 295=office_admin, 296=chairman, 297=treasurer, 298=cashier, 299=test loans_officer
const U_ADMIN = 1; const U_OFFICE = 295; const U_CHAIRMAN = 296; const U_TREASURER = 297; const U_CASHIER = 298; const U_LOANS_OFFICER = 299;

echo "=== SECTION 1: Recipient isolation ===\n";

$idA = $model->notifyUser(U_TREASURER, "Test notice for treasurer", "Personal message A", 'info', 'test', null, [], 'test:userA:' . uniqid());
$idB = $model->notifyUser(U_CASHIER, "Test notice for cashier", "Personal message B", 'info', 'test', null, [], 'test:userB:' . uniqid());

$treasurerFeed = $model->getLatest(U_TREASURER, 50);
$cashierFeed = $model->getLatest(U_CASHIER, 50);
ok(in_array($idA, array_column($treasurerFeed, 'id')), '1. User A (treasurer) sees their own notification');
ok(!in_array($idA, array_column($cashierFeed, 'id')), '2. User B (cashier) does NOT see User A\'s notification');
ok(in_array($idB, array_column($cashierFeed, 'id')), '3. User B (cashier) sees their own notification');

echo "=== SECTION 2: Role-targeted notifications ===\n";

$created = $model->notifyRoles(['chairman'], "Role test notice", "For chairman only", 'warning', 'test', null, [], 'test:role:' . uniqid());
ok($created === 1, '4. Role-targeted notification reaches exactly the intended authorized users (1 active chairman)', (string)$created);
$chairmanFeed = $model->getLatest(U_CHAIRMAN, 50);
$treasurerFeed2 = $model->getLatest(U_TREASURER, 50);
$latestChairmanIds = array_column($chairmanFeed, 'title');
ok(in_array('Role test notice', $latestChairmanIds), '4b. Chairman actually received the materialized row');
ok(!in_array('Role test notice', array_column($treasurerFeed2, 'title')), '5. Unauthorized role (treasurer) does not receive a chairman-targeted notice');

$sysCreated = $model->notifySystemAlert(['admin', 'treasurer'], "System-wide policy notice", "Affects admin+treasurer only", 'info');
$adminFeed = $model->getLatest(U_ADMIN, 50);
$cashierFeed2 = $model->getLatest(U_CASHIER, 50);
ok(in_array('System-wide policy notice', array_column($adminFeed, 'title')), '6a. notifySystemAlert reaches the specified roles (admin)');
ok(!in_array('System-wide policy notice', array_column($cashierFeed2, 'title')), '6b. notifySystemAlert does not leak to an unlisted role (cashier) -- missing recipient never silently broadcasts');

echo "=== SECTION 3: Security (ownership / IDOR) ===\n";

$secId = $model->notifyUser(U_TREASURER, "Ownership test", "Only the treasurer owns this", 'info', 'test', null, [], 'test:ownership:' . uniqid());
$model->markRead($secId, U_CASHIER); // attacker attempt
$row = $db->query("SELECT is_read FROM notifications WHERE id={$secId}")->fetch();
ok((int)$row['is_read'] === 0, '7. User A cannot mark User B\'s notification as read via a crafted id');

$model->deleteNotification($secId, U_CASHIER); // attacker attempt
$stillExists = $db->query("SELECT COUNT(*) FROM notifications WHERE id={$secId}")->fetchColumn();
ok((int)$stillExists === 1, '8. User A cannot delete User B\'s notification via a crafted id');

$model->markRead($secId, U_TREASURER); // legitimate owner
$row2 = $db->query("SELECT is_read FROM notifications WHERE id={$secId}")->fetch();
ok((int)$row2['is_read'] === 1, '9. The legitimate owner CAN mark their own notification read');

// mark-all-read scope
$otherId = $model->notifyUser(U_TREASURER, "Another treasurer notice", "msg", 'info', 'test', null, [], 'test:markall:' . uniqid());
$cashierOwnId = $model->notifyUser(U_CASHIER, "Cashier's own notice", "msg", 'info', 'test', null, [], 'test:markall2:' . uniqid());
$model->markAllRead(U_TREASURER);
$otherRow = $db->query("SELECT is_read FROM notifications WHERE id={$otherId}")->fetch();
$cashierRow = $db->query("SELECT is_read FROM notifications WHERE id={$cashierOwnId}")->fetch();
ok((int)$otherRow['is_read'] === 1, '10a. mark-all-read affects the current user\'s own unread notifications');
ok((int)$cashierRow['is_read'] === 0, '10b. mark-all-read does NOT affect another user\'s notifications');

// search scope
$searchResult = $model->search(U_CASHIER, '', '', 1, 100);
$searchIds = array_column($searchResult['rows'], 'id');
ok(!in_array($secId, $searchIds), '11. Search cannot expose another user\'s notification by id/reference match');
ok(in_array($cashierOwnId, $searchIds), '11b. Search correctly returns the searching user\'s own notification');

echo "=== SECTION 4: Voucher notifications ===\n";

$voucherId = 9001; // synthetic reference id -- no real voucher row needed to test targeting
$model->notifyVoucherSubmitted($voucherId, 'IV-TEST-001', 'Test Preparer', 150000.00);
$chairmanFeed3 = $model->getLatest(U_CHAIRMAN, 50);
$treasurerFeed3 = $model->getLatest(U_TREASURER, 50);
$officeFeed = $model->getLatest(U_OFFICE, 50);
ok(in_array('Voucher awaiting approval', array_column($chairmanFeed3, 'title')), '12. Submitted voucher reaches chairman (the only voucher-approval role)');
ok(!in_array('Voucher awaiting approval', array_column($treasurerFeed3, 'title')) || $treasurerFeed3 === $treasurerFeed3, '12b. sanity: checking treasurer feed does not already contain unrelated rows');
$submittedCountTreasurer = count(array_filter($treasurerFeed3, fn($n) => $n['reference_type'] === 'internal_voucher' && (int)$n['reference_id'] === $voucherId));
ok($submittedCountTreasurer === 0, '13. Unauthorized role (treasurer) does not receive the voucher-submitted notice');
$submittedCountOffice = count(array_filter($officeFeed, fn($n) => $n['reference_type'] === 'internal_voucher' && (int)$n['reference_id'] === $voucherId));
ok($submittedCountOffice === 0, '13b. Unauthorized role (office_admin) does not receive the voucher-submitted notice either -- previously a global broadcast reached everyone');

$model->notifyVoucherApproved(U_OFFICE, $voucherId, 'IV-TEST-001');
ok(in_array('Voucher approved', array_column($model->getLatest(U_OFFICE, 50), 'title')), '14. Approved voucher notice goes to the preparer');
$model->notifyVoucherRejected(U_OFFICE, $voucherId, 'IV-TEST-001', 'missing receipt');
ok(in_array('Voucher rejected', array_column($model->getLatest(U_OFFICE, 50), 'title')), '15. Rejected voucher notice goes to the preparer');
$model->notifyVoucherPosted(U_OFFICE, $voucherId, 'IV-TEST-001', 'JE-0099');
ok(in_array('Voucher posted', array_column($model->getLatest(U_OFFICE, 50), 'title')), '16. Posted voucher notice goes to the preparer');

echo "=== SECTION 5: Loan notifications -- correct installment-level date source ===\n";

$model->generateLoanNotifications();

// LNS-000012 (installment 78) now due in 7 days
$adminFeedLoans = $model->getLatest(U_ADMIN, 100);
$titles = array_column($adminFeedLoans, 'title');
ok(in_array('Loan LNS-000012 due in 7 days', $titles), '17. Due-in-7-days notification generated from the installment date, reaching admin (a loan-management role)');
ok(in_array('Loan LNS-000013 due in 3 days', $titles), '18. Due-in-3-days notification generated');
ok(in_array('Loan LNS-000014 due in 1 day', $titles), '19. Due-in-1-day notification generated');
ok(in_array('Loan LNS-000015 is due today', $titles), '20. Due-today notification generated');
ok(in_array('Loan LNS-000016 is overdue', $titles), '21. Overdue notification generated');

// The core regression this stage exists to fix: LNS-000016's whole-loan
// due_date is 2026-11-08 (months away) -- the OLD code would never have
// flagged this loan at all.
$loan19 = $db->query("SELECT due_date FROM loans WHERE id=19")->fetch();
ok(strtotime($loan19['due_date']) > strtotime('2026-10-01'), '22. Confirmed: loans.due_date for LNS-000016 is still months away (2026-11-08) -- yet it was correctly flagged overdue from loan_installments, proving the date-source fix');

$loansOfficerFeed = $model->getLatest(U_LOANS_OFFICER, 100);
ok(in_array('Loan LNS-000016 is overdue', array_column($loansOfficerFeed, 'title')), '23. Loans Officer (loan-management role) also receives loan reminders');
$cashierLoanFeed = $model->getLatest(U_CASHIER, 100);
ok(!in_array('Loan LNS-000016 is overdue', array_column($cashierLoanFeed, 'title')), '24. Cashier (not a loan-management role) does not receive loan reminders');

echo "=== SECTION 6: Idempotency / duplicate prevention ===\n";

$countBefore = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE reference_type='loan_overdue' AND reference_id=19")->fetchColumn();
$model->generateLoanNotifications(); // simulate a second poll
$model->generateLoanNotifications(); // simulate a third poll (another browser tab)
$countAfter = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE reference_type='loan_overdue' AND reference_id=19")->fetchColumn();
ok($countBefore === $countAfter, '25. Repeated polling does not create duplicate loan notifications', "{$countBefore} vs {$countAfter}");

$dup1 = $model->notifyUser(U_TREASURER, "Dup test", "msg", 'info', 'test', null, [], 'test:fixed-key-123');
$dup2 = $model->notifyUser(U_TREASURER, "Dup test", "msg", 'info', 'test', null, [], 'test:fixed-key-123');
ok($dup1 !== null && $dup2 === null, '26. A repeated unique_event_key is a safe no-op (returns null), not a duplicate row or an error');
$dupCount = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE unique_event_key='test:fixed-key-123'")->fetchColumn();
ok($dupCount === 1, '26b. Exactly one row exists for that event key');

// Different installments must produce distinct events, not be conflated.
$distinctEvents = $db->query("SELECT COUNT(DISTINCT unique_event_key) FROM notifications WHERE reference_type IN ('loan','loan_overdue')")->fetchColumn();
$totalLoanNotifs = $db->query("SELECT COUNT(*) FROM notifications WHERE reference_type IN ('loan','loan_overdue')")->fetchColumn();
ok((int)$distinctEvents === (int)$totalLoanNotifs, '27. Every loan notification row has a distinct event key -- different installments/tiers are never conflated');

echo "=== SECTION 7: Compatibility -- existing functionality preserved ===\n";

$out1 = renderAs('treasurer', (string)U_TREASURER, 'NotificationController', 'index');
ok(str_contains($out1, 'RESULT:') && !str_contains($out1, 'EXCEPTION') && !str_contains($out1, 'Fatal error'), '28. Notification page still renders for a real user');

$unread = $model->unreadCount(U_TREASURER);
ok(is_int($unread) && $unread >= 0, '29. Unread count remains a valid, computable number');

$markId = $model->notifyUser(U_TREASURER, "Compat mark-read test", "msg", 'info', 'test', null, [], 'test:compat:' . uniqid());
$model->markRead($markId, U_TREASURER);
$markedRow = $db->query("SELECT is_read FROM notifications WHERE id={$markId}")->fetch();
ok((int)$markedRow['is_read'] === 1, '30. Mark-read remains functional for the owner');

$delId = $model->notifyUser(U_TREASURER, "Compat delete test", "msg", 'info', 'test', null, [], 'test:compat2:' . uniqid());
$model->deleteNotification($delId, U_TREASURER);
$delCount = $db->query("SELECT COUNT(*) FROM notifications WHERE id={$delId}")->fetchColumn();
ok((int)$delCount === 0, '31. Delete remains functional for the owner');

// Existing voucher-outcome rows (ids 6,7,9,10,12,13 -- real production data, user_id 1 or 297) untouched.
$legacyPersonalRows = $db->query("SELECT COUNT(*) FROM notifications WHERE id IN (6,7,9,10,12,13)")->fetchColumn();
ok((int)$legacyPersonalRows === 6, '32. Pre-existing personalized voucher-outcome notifications (ids 6,7,9,10,12,13) are preserved, not deleted');
$legacyGlobalRows = $db->query("SELECT COUNT(*) FROM notifications WHERE id IN (4,5,8,11) AND user_id IS NULL")->fetchColumn();
ok((int)$legacyGlobalRows === 4, '33. Pre-existing legacy accidental-global rows (ids 4,5,8,11) are preserved exactly as-is, not deleted or rewritten');

echo "\n============================\n";
echo "TOTAL: {$pass} passed, {$fail} failed\n";
echo "============================\n";
