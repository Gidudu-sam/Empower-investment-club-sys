<?php
/**
 * ISOLATED — Stage 12-C: In-App Notification Centre & UX.
 * Runs ONLY against empower_db_stage12c_test. Never touches empower_db.
 */
chdir(__DIR__);
$pass = 0; $fail = 0;
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }

define('DB_NAME', 'empower_db_stage12c_test');
require 'app/config/config.php';
require 'test_safety_guard.php';
require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';
$db = Database::getInstance()->getConnection();

/** Matches the exact renderAs() harness convention already used by
 *  test_fixed_deposit_closure.php / test_universal_savings_account_closure.php
 *  -- csrf_token is hardcoded to 'skip' on both sides of a real subprocess. */
function renderAs(string $role, string $userId, string $action, array $post = [], array $get = [], bool $csrf = true): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_stage12c_subproc_' . $counter . '.php';
    $postLines = '';
    if ($post !== [] || $csrf) {
        $postLines = "\$_SERVER['REQUEST_METHOD'] = 'POST';\n";
        if ($csrf) { $postLines .= "\$_POST['csrf_token'] = 'skip';\n"; }
        foreach ($post as $k => $v) { $postLines .= "\$_POST['{$k}'] = " . var_export($v, true) . ";\n"; }
    }
    $getLines = ''; foreach ($get as $k => $v) { $getLines .= "\$_GET['{$k}'] = " . var_export($v, true) . ";\n"; }
    $body = <<<PHP
<?php
chdir(__DIR__);
define('DB_NAME', 'empower_db_stage12c_test');
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
{$getLines}
{$postLines}
try {
    ob_start();
    (new NotificationController())->{$action}();
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
const U_TREASURER = 297; const U_CASHIER = 298; const U_ADMIN = 1;

echo "=== SECTION 1: CSRF / POST enforcement (new in 12-C) ===\n";

$rid1 = $model->notifyUser(U_TREASURER, "12C CSRF test 1", "msg", 'info', 'test', null, [], 'test12c:csrf1:' . uniqid());
$out = renderAs('treasurer', (string)U_TREASURER, 'markRead', ['id' => $rid1], [], false); // no csrf_token posted
$row = $db->query("SELECT is_read FROM notifications WHERE id={$rid1}")->fetch();
ok((int)$row['is_read'] === 0, '1. markRead without a CSRF token is rejected -- notification remains unread');

$out2 = renderAs('treasurer', (string)U_TREASURER, 'markRead', ['id' => $rid1]);
$row2 = $db->query("SELECT is_read FROM notifications WHERE id={$rid1}")->fetch();
ok((int)$row2['is_read'] === 1, '2. markRead with a valid CSRF token succeeds');

$rid2 = $model->notifyUser(U_TREASURER, "12C CSRF test 2", "msg", 'info', 'test', null, [], 'test12c:csrf2:' . uniqid());
renderAs('treasurer', (string)U_TREASURER, 'delete', ['id' => $rid2], [], false); // no csrf
$stillThere = $db->query("SELECT COUNT(*) FROM notifications WHERE id={$rid2}")->fetchColumn();
ok((int)$stillThere === 1, '3. delete without a CSRF token is rejected -- notification not deleted');

renderAs('treasurer', (string)U_TREASURER, 'delete', ['id' => $rid2]);
$gone = $db->query("SELECT COUNT(*) FROM notifications WHERE id={$rid2}")->fetchColumn();
ok((int)$gone === 0, '4. delete with a valid CSRF token succeeds');

$rid3 = $model->notifyUser(U_CASHIER, "12C mark-all csrf", "msg", 'info', 'test', null, [], 'test12c:markall1:' . uniqid());
renderAs('cashier', (string)U_CASHIER, 'markAllRead', [], [], false); // no csrf
$row3 = $db->query("SELECT is_read FROM notifications WHERE id={$rid3}")->fetch();
ok((int)$row3['is_read'] === 0, '5. markAllRead without a CSRF token is rejected');

renderAs('cashier', (string)U_CASHIER, 'markAllRead');
$row3b = $db->query("SELECT is_read FROM notifications WHERE id={$rid3}")->fetch();
ok((int)$row3b['is_read'] === 1, '6. markAllRead with a valid CSRF token succeeds for the owner\'s own notifications');

echo "=== SECTION 2: Ownership enforced through the real controller route (not just the model directly) ===\n";

$victimRow = $model->notifyUser(U_TREASURER, "12C ownership victim", "msg", 'info', 'test', null, [], 'test12c:victim1:' . uniqid());
renderAs('cashier', (string)U_CASHIER, 'markRead', ['id' => $victimRow]); // attacker has valid CSRF for THEIR OWN session, but not ownership
$victimAfter = $db->query("SELECT is_read FROM notifications WHERE id={$victimRow}")->fetch();
ok((int)$victimAfter['is_read'] === 0, '7. A valid CSRF token does not let User B mark User A\'s notification read -- ownership (WHERE user_id=?) still blocks it');

$victimRow2 = $model->notifyUser(U_TREASURER, "12C ownership victim 2", "msg", 'info', 'test', null, [], 'test12c:victim2:' . uniqid());
renderAs('cashier', (string)U_CASHIER, 'delete', ['id' => $victimRow2]);
$victim2Still = $db->query("SELECT COUNT(*) FROM notifications WHERE id={$victimRow2}")->fetchColumn();
ok((int)$victim2Still === 1, '8. A valid CSRF token does not let User B delete User A\'s notification');

$out3 = renderAs('cashier', (string)U_CASHIER, 'markRead', ['id' => 99999999]);
ok(!str_contains($out3, 'Fatal error') && !str_contains($out3, 'EXCEPTION'), '9. markRead on a non-existent id fails gracefully, no fatal error');

echo "=== SECTION 3: GET can no longer mutate ===\n";

$rid4 = $model->notifyUser(U_TREASURER, "12C GET test", "msg", 'info', 'test', null, [], 'test12c:get1:' . uniqid());
$file = __DIR__ . '/tmp_stage12c_get.php';
file_put_contents($file, <<<PHP
<?php
chdir(__DIR__);
define('DB_NAME', 'empower_db_stage12c_test');
require 'app/config/config.php';
require 'test_safety_guard.php';
require CORE_PATH . '/Database.php';
require CORE_PATH . '/Model.php';
require CORE_PATH . '/Autoloader.php';
require CORE_PATH . '/Session.php';
require CORE_PATH . '/Controller.php';
Session::start();
Session::set('user_id', {$rid4});
Session::set('user_id', 297);
Session::set('user_role', 'treasurer');
Session::set('last_activity', time());
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_GET['id'] = {$rid4};
try { (new NotificationController())->markRead(); } catch (Throwable \$e) {}
PHP
);
shell_exec('"' . PHP_BINARY . '" "' . $file . '" 2>&1');
@unlink($file);
$row4 = $db->query("SELECT is_read FROM notifications WHERE id={$rid4}")->fetch();
ok((int)$row4['is_read'] === 0, '10. A plain GET request to markRead (the pre-12-C behavior) no longer mutates anything');

echo "=== SECTION 4: index() page / filtering ===\n";

$outIdx = renderAs('treasurer', (string)U_TREASURER, 'index', [], [], false);
ok(str_contains($outIdx, 'RESULT:') && !str_contains($outIdx, 'Fatal error') && !str_contains($outIdx, 'EXCEPTION'), '11. Notifications page renders successfully for an authenticated user');
ok(str_contains($outIdx, 'Vouchers'), '12. Category filter now includes "Vouchers" (internal_voucher) -- previously missing from the dropdown');
ok(str_contains($outIdx, 'csrf_token'), '13. The rendered page embeds a CSRF token for its mark-read/delete/mark-all forms');

$voucherOnly = $model->search(U_ADMIN, '', 'internal_voucher', 1, 50);
ok(count($voucherOnly['rows']) > 0 && count(array_filter($voucherOnly['rows'], fn($r) => $r['reference_type'] !== 'internal_voucher')) === 0, '14. Category filter "internal_voucher" returns only voucher notifications');

$loanOnly = $model->search(U_ADMIN, '', 'loan_overdue', 1, 50);
ok(count(array_filter($loanOnly['rows'], fn($r) => $r['reference_type'] !== 'loan_overdue')) === 0, '15. Category filter "loan_overdue" returns only overdue-loan notifications');

$unreadOnly = $model->search(U_ADMIN, 'unread', '', 1, 50);
ok(count(array_filter($unreadOnly['rows'], fn($r) => (int)$r['is_read'] !== 0)) === 0, '16. Status filter "unread" returns only unread rows');

$readOnly = $model->search(U_TREASURER, 'read', '', 1, 50);
ok(count(array_filter($readOnly['rows'], fn($r) => (int)$r['is_read'] !== 1)) === 0, '17. Status filter "read" returns only read rows');

$combined = $model->search(U_ADMIN, 'unread', 'loan_overdue', 1, 50);
ok(count(array_filter($combined['rows'], fn($r) => (int)$r['is_read'] !== 0 || $r['reference_type'] !== 'loan_overdue')) === 0, '18. Combined status+category filter applies both conditions together');

echo "=== SECTION 5: Pagination ===\n";

for ($i = 0; $i < 25; $i++) {
    $model->notifyUser(U_ADMIN, "Pagination test {$i}", "msg", 'info', 'test', null, [], "test12c:page:{$i}:" . uniqid());
}
$page1 = $model->search(U_ADMIN, '', '', 1, 10);
$page2 = $model->search(U_ADMIN, '', '', 2, 10);
ok(count($page1['rows']) === 10, '19. Page 1 returns exactly the requested page size');
$page1Ids = array_column($page1['rows'], 'id');
$page2Ids = array_column($page2['rows'], 'id');
ok(count(array_intersect($page1Ids, $page2Ids)) === 0, '20. Page 2 does not duplicate any row from page 1');
$sortedDesc = $page1Ids;
$check = $page1Ids; rsort($check);
ok($sortedDesc == $check, '21. Page 1 is ordered newest-first (descending id order)');

$cashierPage = $model->search(U_CASHIER, '', '', 1, 10);
$adminIdsInCashierPage = array_intersect(array_column($cashierPage['rows'], 'id'), $page1Ids);
ok(count($adminIdsInCashierPage) === 0, '22. Pagination remains user-scoped -- another user\'s page never contains this user\'s rows');

echo "=== SECTION 6: Legacy / broadcast compatibility ===\n";

$legacyGlobalIds = [4, 5, 8, 11];
$adminFeedFull = $model->search(U_ADMIN, '', '', 1, 1000);
$treasurerFeedFull = $model->search(U_TREASURER, '', '', 1, 1000);
$foundInAdmin = array_intersect($legacyGlobalIds, array_column($adminFeedFull['rows'], 'id'));
$foundInTreasurer = array_intersect($legacyGlobalIds, array_column($treasurerFeedFull['rows'], 'id'));
ok(empty($foundInAdmin) && empty($foundInTreasurer), '23. Legacy accidental-global rows (ids 4,5,8,11) do not appear in any user\'s notification centre');

$broadcastId = $model->notifySystemBroadcast("12C broadcast fixture", "Isolated test only", 'info', 'test12c:broadcast:' . uniqid());
$adminSeesIt = in_array($broadcastId, array_column($model->getLatest(U_ADMIN, 200), 'id'));
$cashierSeesIt = in_array($broadcastId, array_column($model->getLatest(U_CASHIER, 200), 'id'));
ok($adminSeesIt && $cashierSeesIt, '24. A genuine is_broadcast=1 fixture is visible to every user per Stage 12-B semantics (isolated fixture only, never created in production by this stage)');

echo "=== SECTION 7: action_url rendering / fallback ===\n";

$withUrl = $model->notifyUser(U_TREASURER, "12C action url test", "msg", 'info', 'loan', 999, ['action_url' => APP_URL . '/index.php?page=loan-view&id=999'], 'test12c:url1:' . uniqid());
$rowUrl = $db->query("SELECT action_url FROM notifications WHERE id={$withUrl}")->fetch();
ok($rowUrl['action_url'] === APP_URL . '/index.php?page=loan-view&id=999', '25. A notification created with an explicit action_url stores it exactly');

// Render the full page and confirm the trusted action_url appears as the View link's href.
$outWithUrl = renderAs('treasurer', (string)U_TREASURER, 'index', [], [], false);
ok(str_contains($outWithUrl, 'loan-view&id=999') || str_contains($outWithUrl, 'loan-view&amp;id=999'), '26. The rendered page uses the stored action_url for its View link');

$legacyNoUrl = $model->notifyUser(U_TREASURER, "12C legacy loan fallback", "msg", 'info', 'loan', 777, [], 'test12c:fallback1:' . uniqid());
$outFallback = renderAs('treasurer', (string)U_TREASURER, 'index', [], [], false);
ok(str_contains($outFallback, 'loan-view&id=777') || str_contains($outFallback, 'loan-view&amp;id=777'), '27. A legacy-shaped row with no action_url falls back to the original reference_type-based link (loan)');

$legacyVoucherNoUrl = $model->notifyUser(U_TREASURER, "12C legacy voucher fallback", "msg", 'info', 'internal_voucher', 888, [], 'test12c:fallback2:' . uniqid());
$outFallback2 = renderAs('treasurer', (string)U_TREASURER, 'index', [], [], false);
ok(str_contains($outFallback2, 'internal-voucher-view&id=888') || str_contains($outFallback2, 'internal-voucher-view&amp;id=888'), '28. A legacy-shaped row with no action_url falls back correctly for vouchers too');

echo "=== SECTION 8: Output escaping ===\n";

$xssId = $model->notifyUser(U_TREASURER, "12C <script>alert(1)</script>", "msg with <b>markup</b>", 'info', 'test', null, [], 'test12c:xss:' . uniqid());
$outXss = renderAs('treasurer', (string)U_TREASURER, 'index', [], [], false);
ok(!str_contains($outXss, '<script>alert(1)</script>'), '29. A malicious notification title is never rendered unescaped in the full page');
ok(str_contains($outXss, '&lt;script&gt;'), '29b. The title is correctly HTML-escaped');

echo "=== SECTION 9: fetchLatest AJAX ===\n";

$fetchOut = renderAs('cashier', (string)U_CASHIER, 'fetchLatest', [], [], false);
$json = json_decode(str_replace('RESULT:', '', $fetchOut), true);
ok(is_array($json) && array_key_exists('unread', $json) && array_key_exists('notifications', $json), '30. fetchLatest returns the expected JSON shape');
if (is_array($json) && !empty($json['notifications'])) {
    ok(array_key_exists('action_url', $json['notifications'][0]), '31. fetchLatest rows include action_url for the dropdown to use');
} else {
    ok(true, '31. (skipped -- cashier had no notifications at fetch time)');
}
$cashierUnreadDirect = $model->unreadCount(U_CASHIER);
ok(($json['unread'] ?? -1) === $cashierUnreadDirect, '32. fetchLatest\'s unread count matches the authenticated user\'s own server-computed count exactly');

echo "=== SECTION 10: Compatibility with Stage 12-B ===\n";

$compatId = $model->notifyUser(U_ADMIN, "12C compat", "msg", 'info', 'test', null, [], 'test12c:compat:' . uniqid());
renderAs('admin', (string)U_ADMIN, 'markRead', ['id' => $compatId]);
$compatRow = $db->query("SELECT is_read, read_at FROM notifications WHERE id={$compatId}")->fetch();
ok((int)$compatRow['is_read'] === 1 && $compatRow['read_at'] !== null, '33. markRead through the controller still sets is_read AND read_at (Stage 12-B semantics preserved)');

$delCompatId = $model->notifyUser(U_ADMIN, "12C compat delete", "msg", 'info', 'test', null, [], 'test12c:compatdel:' . uniqid());
$countBeforeDel = (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
renderAs('admin', (string)U_ADMIN, 'delete', ['id' => $delCompatId]);
$countAfterDel = (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
ok($countAfterDel === $countBeforeDel - 1, '34. Deleting one notification removes exactly that one row, nothing else');

$otherUserUnreadBefore = $model->unreadCount(U_TREASURER);
renderAs('admin', (string)U_ADMIN, 'markAllRead');
$otherUserUnreadAfter = $model->unreadCount(U_TREASURER);
ok($otherUserUnreadBefore === $otherUserUnreadAfter, '35. mark-all-read via the controller only affects the authenticated user, never another user\'s unread count');

echo "\n============================\n";
echo "TOTAL: {$pass} passed, {$fail} failed\n";
echo "============================\n";
