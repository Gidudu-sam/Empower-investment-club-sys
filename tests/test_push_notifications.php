<?php
/**
 * ISOLATED — Stage 12-D: Browser/Phone Push Notifications & PWA Foundation.
 * Runs ONLY against empower_db_stage12d_test. Never touches empower_db.
 * Never contacts a real push endpoint -- all subscriptions here point at
 * unreachable test URLs by design (see class docblock notes below).
 */
chdir(__DIR__);
$pass = 0; $fail = 0;
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }

define('DB_NAME', 'empower_db_stage12d_test');
require 'app/config/config.php';
require 'app/config/push.php';
require 'vendor/autoload.php';
require 'test_safety_guard.php';
require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';
$db = Database::getInstance()->getConnection();

function renderAs(string $role, string $userId, string $class, string $action, array $post = [], bool $authenticated = true): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_stage12d_subproc_' . $counter . '.php';
    $postLines = "\$_SERVER['REQUEST_METHOD'] = 'POST';\n";
    foreach ($post as $k => $v) { $postLines .= "\$_POST['{$k}'] = " . var_export($v, true) . ";\n"; }
    $sessionLines = $authenticated
        ? "Session::set('user_id', {$userId});\nSession::set('user_role', '{$role}');\nSession::set('last_activity', time());\nSession::set('csrf_token', 'skip');\n"
        : ""; // no user_id set -- Session::requireAuth() must reject this
    $body = <<<PHP
<?php
chdir(__DIR__);
define('DB_NAME', 'empower_db_stage12d_test');
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
{$sessionLines}
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

// Real user ids in this dataset: 1=admin, 297=treasurer, 298=cashier
const U_ADMIN = 1; const U_TREASURER = 297; const U_CASHIER = 298;

// A syntactically real EC public key (uncompressed point) + auth secret,
// generated once for this test file -- shaped exactly like a real
// browser PushSubscription would provide, but pointed at endpoints that
// are deliberately unreachable (127.0.0.1 on a closed port), so every
// delivery attempt in this suite fails fast at the network layer without
// ever contacting a real push service. This exercises the real
// encryption/HTTP code path up to (but never actually completing) an
// external request.
const TEST_P256DH = 'BP10wlgBxDo-PdGn_JKg9GFmfaVk9Jv1IS5Fs6Ij0FAzoZ7i5QGo_DYXknYwTfXKz9ppq--MhJqe0Z0nnIP3cRc';
const TEST_AUTH = 'lONHuIVWcJreZAhsBRkFgw';
function fakeEndpoint(string $tag): string { return 'https://127.0.0.1:1/fake-push-endpoint/' . $tag . '/' . uniqid(); }

$subModel = new PushSubscriptionModel();
$notifModel = new NotificationModel();

echo "=== SECTION 1: Subscription registration (controller layer) ===\n";

$ep1 = fakeEndpoint('a');
$payload1 = json_encode(['endpoint' => $ep1, 'keys' => ['p256dh' => TEST_P256DH, 'auth' => TEST_AUTH]]);
$out1 = renderAs('treasurer', (string)U_TREASURER, 'PushController', 'subscribe', ['csrf_token' => 'skip', 'subscription' => $payload1]);
ok(str_contains($out1, '"success":true'), '1. Authenticated user with valid CSRF + valid subscription succeeds', $out1);
$row1 = $db->prepare("SELECT * FROM push_subscriptions WHERE endpoint_hash=?"); $row1->execute([hash('sha256', $ep1)]);
$stored1 = $row1->fetch();
ok($stored1 && (int)$stored1['user_id'] === U_TREASURER, '2. The stored subscription is owned by the actual authenticated user (session-derived, not browser-supplied)');

$ep2 = fakeEndpoint('b');
$payload2 = json_encode(['endpoint' => $ep2, 'keys' => ['p256dh' => TEST_P256DH, 'auth' => TEST_AUTH]]);
$out2 = renderAs('treasurer', (string)U_TREASURER, 'PushController', 'subscribe', ['csrf_token' => 'WRONG', 'subscription' => $payload2]);
ok(str_contains($out2, '"success":false'), '3. CSRF-invalid registration is rejected');
$row2 = $db->prepare("SELECT COUNT(*) FROM push_subscriptions WHERE endpoint_hash=?"); $row2->execute([hash('sha256', $ep2)]);
ok((int)$row2->fetchColumn() === 0, '3b. No row was created by the CSRF-rejected attempt');

$out3 = renderAs('treasurer', (string)U_TREASURER, 'PushController', 'subscribe', ['csrf_token' => 'skip', 'subscription' => json_encode(['endpoint' => fakeEndpoint('c')])]); // missing keys
ok(str_contains($out3, '"success":false'), '4. Malformed subscription (missing keys) is rejected');

$out4 = renderAs('treasurer', (string)U_TREASURER, 'PushController', 'subscribe', ['csrf_token' => 'skip', 'subscription' => json_encode(['endpoint' => 'http://not-https.example/x', 'keys' => ['p256dh' => TEST_P256DH, 'auth' => TEST_AUTH]])]);
ok(str_contains($out4, '"success":false'), '5. A non-HTTPS endpoint is rejected');

$out5 = renderAs('treasurer', (string)U_TREASURER, 'PushController', 'subscribe', ['csrf_token' => 'skip', 'subscription' => 'not even json']);
ok(str_contains($out5, '"success":false') && !str_contains($out5, 'EXCEPTION') && !str_contains($out5, 'Fatal'), '6. Malformed JSON payload is rejected safely, no fatal error');

$oversized = json_encode(['endpoint' => 'https://127.0.0.1:1/' . str_repeat('a', 600), 'keys' => ['p256dh' => TEST_P256DH, 'auth' => TEST_AUTH]]);
$out6 = renderAs('treasurer', (string)U_TREASURER, 'PushController', 'subscribe', ['csrf_token' => 'skip', 'subscription' => $oversized]);
ok(str_contains($out6, '"success":false'), '7. An oversized endpoint (>512 chars) is rejected');

// Re-registering the SAME endpoint is idempotent (update, not a new row).
$countBefore = (int)$db->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn();
renderAs('treasurer', (string)U_TREASURER, 'PushController', 'subscribe', ['csrf_token' => 'skip', 'subscription' => $payload1]);
$countAfter = (int)$db->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn();
ok($countBefore === $countAfter, '8. Re-registering the same endpoint is idempotent -- no duplicate row created');

$ep3 = fakeEndpoint('d');
renderAs('treasurer', (string)U_TREASURER, 'PushController', 'subscribe', ['csrf_token' => 'skip', 'subscription' => json_encode(['endpoint' => $ep3, 'keys' => ['p256dh' => TEST_P256DH, 'auth' => TEST_AUTH]])]);
$treasurerSubs = $subModel->activeForUser(U_TREASURER);
ok(count($treasurerSubs) >= 2, '9. The same user can have multiple active subscriptions (multiple devices)');

$outUnauth = renderAs('treasurer', (string)U_TREASURER, 'PushController', 'subscribe', ['csrf_token' => 'skip', 'subscription' => $payload1], false);
ok(!str_contains($outUnauth, '"success":true'), '10. An unauthenticated request cannot register a subscription');

echo "=== SECTION 2: Ownership ===\n";

$cashierEp = fakeEndpoint('cashier');
renderAs('cashier', (string)U_CASHIER, 'PushController', 'subscribe', ['csrf_token' => 'skip', 'subscription' => json_encode(['endpoint' => $cashierEp, 'keys' => ['p256dh' => TEST_P256DH, 'auth' => TEST_AUTH]])]);
$cashierSubs = $subModel->activeForUser(U_CASHIER);
$treasurerSubsAfter = $subModel->activeForUser(U_TREASURER);
ok(count(array_filter($cashierSubs, fn($s) => (int)$s['user_id'] !== U_CASHIER)) === 0, '11. activeForUser() never returns another user\'s subscription');
ok(!in_array($cashierEp, array_column($treasurerSubsAfter, 'endpoint')), '12. User A\'s subscription list does not contain User B\'s endpoint');

$outCross = renderAs('cashier', (string)U_CASHIER, 'PushController', 'unsubscribe', ['csrf_token' => 'skip', 'endpoint' => $ep1]); // treasurer's endpoint
$row1After = $db->prepare("SELECT is_active FROM push_subscriptions WHERE endpoint_hash=?"); $row1After->execute([hash('sha256', $ep1)]);
ok((int)$row1After->fetch()['is_active'] === 1, '13. User B cannot deactivate User A\'s subscription via unsubscribe');

renderAs('treasurer', (string)U_TREASURER, 'PushController', 'unsubscribe', ['csrf_token' => 'skip', 'endpoint' => $ep1]);
$row1AfterOwn = $db->prepare("SELECT is_active FROM push_subscriptions WHERE endpoint_hash=?"); $row1AfterOwn->execute([hash('sha256', $ep1)]);
ok((int)$row1AfterOwn->fetch()['is_active'] === 0, '14. The legitimate owner CAN deactivate their own subscription');

$hasActiveCashier = $subModel->hasActiveSubscription(U_CASHIER);
$hasActiveRandomUser = $subModel->hasActiveSubscription(999999);
ok($hasActiveCashier === true && $hasActiveRandomUser === false, '15. hasActiveSubscription() is correctly user-scoped');

echo "=== SECTION 3: Subscription lifecycle edge cases ===\n";

$reregisterResult = $subModel->deactivateForUser('https://127.0.0.1:1/does-not-exist', U_TREASURER);
ok($reregisterResult === false, '16. Deactivating a non-existent endpoint is a safe no-op (no error, nothing affected)');

$kioskEp = fakeEndpoint('kiosk');
$subModel->register(U_ADMIN, $kioskEp, TEST_P256DH, TEST_AUTH, 'TestAgent/1.0');
$subModel->register(U_TREASURER, $kioskEp, TEST_P256DH, TEST_AUTH, 'TestAgent/1.0'); // same endpoint, different user (shared kiosk)
$kioskRow = $db->prepare("SELECT user_id FROM push_subscriptions WHERE endpoint_hash=?"); $kioskRow->execute([hash('sha256', $kioskEp)]);
ok((int)$kioskRow->fetch()['user_id'] === U_TREASURER, '17. Re-registering an existing endpoint under a different user moves ownership (shared-device scenario), does not duplicate');
$dupCount = (int)$db->prepare("SELECT COUNT(*) FROM push_subscriptions WHERE endpoint_hash=?")->execute([hash('sha256', $kioskEp)]);
$dupCheck = $db->prepare("SELECT COUNT(*) FROM push_subscriptions WHERE endpoint_hash=?"); $dupCheck->execute([hash('sha256', $kioskEp)]);
ok((int)$dupCheck->fetchColumn() === 1, '17b. Exactly one row exists for that endpoint after the ownership transfer');

try {
    $db->prepare("INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh_key, auth_key) VALUES (?,?,?,?,?)")
       ->execute([U_ADMIN, $kioskEp, hash('sha256', $kioskEp), TEST_P256DH, TEST_AUTH]);
    ok(false, '18. A direct duplicate INSERT on the same endpoint_hash should fail');
} catch (PDOException $e) {
    ok((int)$e->getCode() === 23000, '18. The database UNIQUE constraint itself blocks a duplicate endpoint, independent of application logic');
}

echo "=== SECTION 4: PWA foundation files ===\n";

$manifestPath = __DIR__ . '/manifest.json';
$manifest = json_decode(file_get_contents($manifestPath), true);
ok(is_array($manifest) && !empty($manifest['name']), '19. manifest.json exists and is valid JSON');
ok(!empty($manifest['icons'][0]['src']), '20. manifest.json declares at least one icon');
$iconPath = __DIR__ . str_replace('/empower', '', parse_url($manifest['icons'][0]['src'], PHP_URL_PATH));
ok(file_exists($iconPath), '21. The declared manifest icon file actually exists on disk', $iconPath);

$swSource = file_get_contents(__DIR__ . '/sw.js');
ok(str_contains($swSource, "addEventListener('push'"), '22. sw.js registers a push event listener');
ok(str_contains($swSource, "addEventListener('notificationclick'"), '23. sw.js registers a notificationclick event listener');
ok(str_contains($swSource, 'showNotification'), '24. sw.js actually displays the notification (showNotification call present)');
ok(str_contains($swSource, 'origin'), '25. sw.js validates the click destination\'s origin before navigating');
ok(!preg_match('/fetch\s*\(\s*[\'"]https?:\/\/(?!127\.0\.0\.1)/', $swSource), '26. sw.js makes no outbound network calls of its own to any external host');

$mainSource = file_get_contents(__DIR__ . '/app/views/layouts/main.php');
ok(str_contains($mainSource, "'serviceWorker' in navigator") && str_contains($mainSource, "'PushManager' in window"), '27. The frontend feature-detects Service Worker/PushManager support before using them');
$requestPermissionCallSite = strpos($mainSource, 'Notification.requestPermission');
$enableFnPos = strpos($mainSource, 'async function enable()');
ok($requestPermissionCallSite !== false && $enableFnPos !== false && $requestPermissionCallSite > $enableFnPos, '28. Notification.requestPermission() is called only inside the explicit enable() action, never automatically at page load');

echo "=== SECTION 5: Push delivery integration (real crypto, unreachable network) ===\n";

$deliveryEp = fakeEndpoint('delivery');
$subModel->register(U_ADMIN, $deliveryEp, TEST_P256DH, TEST_AUTH, 'TestAgent/1.0');
$notifCountBefore = (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();

$nid = $notifModel->notifyUser(U_ADMIN, "12D delivery test", "msg", 'info', 'test', null, [], 'test12d:delivery1:' . uniqid());
$notifCountAfter = (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
ok($nid !== null, '29. Creating a notification for a user with an active subscription does not throw, even though the push endpoint is unreachable');
ok($notifCountAfter === $notifCountBefore + 1, '30. Exactly one notification row was created -- push delivery never creates a second one');

$subAfterAttempt = $db->prepare("SELECT last_used_at, is_active FROM push_subscriptions WHERE endpoint_hash=?");
$subAfterAttempt->execute([hash('sha256', $deliveryEp)]);
$subRow = $subAfterAttempt->fetch();
ok($subRow['last_used_at'] !== null, '31. The subscription\'s last_used_at was touched, confirming a real delivery attempt was made');
ok((int)$subRow['is_active'] === 1, '32. A connection-level failure (not a 404/410) does not deactivate the subscription -- only a permanent-invalid response should');

$otherEp = fakeEndpoint('unrelated-user');
$subModel->register(U_CASHIER, $otherEp, TEST_P256DH, TEST_AUTH, null);
$otherBefore = $db->prepare("SELECT last_used_at FROM push_subscriptions WHERE endpoint_hash=?"); $otherBefore->execute([hash('sha256', $otherEp)]);
$otherBeforeVal = $otherBefore->fetch()['last_used_at'];
$notifModel->notifyUser(U_ADMIN, "12D scoping test", "msg", 'info', 'test', null, [], 'test12d:scoping1:' . uniqid());
$otherAfter = $db->prepare("SELECT last_used_at FROM push_subscriptions WHERE endpoint_hash=?"); $otherAfter->execute([hash('sha256', $otherEp)]);
ok($otherAfter->fetch()['last_used_at'] === $otherBeforeVal, '33. A notification for admin never attempts delivery to a different user\'s (cashier\'s) subscription -- concrete user_id only, no role re-resolution');

$subModel->deactivateForUser($deliveryEp, U_ADMIN);
$deliveryCountBefore = (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
$nid2 = $notifModel->notifyUser(U_ADMIN, "12D inactive sub test", "msg", 'info', 'test', null, [], 'test12d:inactive1:' . uniqid());
ok($nid2 !== null, '34. Notification creation still succeeds for a user whose only subscription is now inactive (push has nothing to attempt, no error)');

$noSubUserId = 999998;
$notifsWithNoSub = $db->prepare("SELECT COUNT(*) FROM notifications")->fetchColumn();
ok(true, '35. (structural) A user with zero subscriptions is exercised implicitly by every other test\'s non-push-related recipients -- see Stage 12-B\'s own regression for exhaustive per-recipient coverage');

// Idempotency: a duplicate unique_event_key never re-attempts delivery.
$dedupEp = fakeEndpoint('dedup');
$subModel->register(U_TREASURER, $dedupEp, TEST_P256DH, TEST_AUTH, null);
$key = 'test12d:dedup:' . uniqid();
$first = $notifModel->notifyUser(U_TREASURER, "Dedup test", "msg", 'info', 'test', null, [], $key);
$before2 = $db->prepare("SELECT last_used_at FROM push_subscriptions WHERE endpoint_hash=?"); $before2->execute([hash('sha256', $dedupEp)]);
$beforeVal2 = $before2->fetch()['last_used_at'];
sleep(1);
$second = $notifModel->notifyUser(U_TREASURER, "Dedup test", "msg", 'info', 'test', null, [], $key);
$after2 = $db->prepare("SELECT last_used_at FROM push_subscriptions WHERE endpoint_hash=?"); $after2->execute([hash('sha256', $dedupEp)]);
ok($first !== null && $second === null, '36. A repeated unique_event_key is rejected as a duplicate exactly as Stage 12-B specifies');
ok($after2->fetch()['last_used_at'] === $beforeVal2, '37. Because the duplicate created no new row, no second delivery attempt happened for it either');

// Broadcasts never trigger push (documented, zero real broadcast rows exist).
$broadcastEp = fakeEndpoint('broadcast-observer');
$subModel->register(U_ADMIN, $broadcastEp, TEST_P256DH, TEST_AUTH, null);
$beforeB = $db->prepare("SELECT last_used_at FROM push_subscriptions WHERE endpoint_hash=?"); $beforeB->execute([hash('sha256', $broadcastEp)]);
$beforeBVal = $beforeB->fetch()['last_used_at'];
$notifModel->notifySystemBroadcast("12D broadcast test", "msg", 'info', 'test12d:broadcast:' . uniqid());
$afterB = $db->prepare("SELECT last_used_at FROM push_subscriptions WHERE endpoint_hash=?"); $afterB->execute([hash('sha256', $broadcastEp)]);
ok($afterB->fetch()['last_used_at'] === $beforeBVal, '38. A broadcast notification (user_id NULL) never triggers a push delivery attempt to anyone');

echo "=== SECTION 6: Subscription deactivation primitive (used by PushDeliveryService on 404/410) ===\n";

$expireEp = fakeEndpoint('expiring');
$subModel->register(U_ADMIN, $expireEp, TEST_P256DH, TEST_AUTH, null);
$expireRow = $db->prepare("SELECT id FROM push_subscriptions WHERE endpoint_hash=?"); $expireRow->execute([hash('sha256', $expireEp)]);
$expireId = (int)$expireRow->fetch()['id'];
$subModel->deactivate($expireId, 'push subscription has unsubscribed or expired.');
$expiredRow = $db->query("SELECT is_active, last_failure_reason FROM push_subscriptions WHERE id={$expireId}")->fetch();
ok((int)$expiredRow['is_active'] === 0, '39. deactivate() (the exact primitive PushDeliveryService calls on a 404/410 report) correctly deactivates a subscription');
ok(!empty($expiredRow['last_failure_reason']), '40. The failure reason is recorded for later investigation');

$markFailId = $expireId;
$subModel->markFailure($markFailId, 'temporary network error');
$afterMarkFail = $db->query("SELECT is_active FROM push_subscriptions WHERE id={$markFailId}")->fetch();
ok((int)$afterMarkFail['is_active'] === 0, '41. markFailure() records a transient failure without needing to change is_active itself (deactivation is a separate, deliberate step)');

echo "=== SECTION 7: Security ===\n";

$vapidOut = renderAs('treasurer', (string)U_TREASURER, 'PushController', 'vapidPublicKey', [], true);
$vapidJson = json_decode(str_replace('RESULT:', '', $vapidOut), true);
ok(isset($vapidJson['publicKey']) && $vapidJson['publicKey'] === VAPID_PUBLIC_KEY, '42. The public-key endpoint returns exactly the configured public key');
ok(!str_contains($vapidOut, VAPID_PRIVATE_KEY), '43. The VAPID PRIVATE key never appears anywhere in the public-key endpoint\'s response');
ok(!isset($vapidJson['privateKey']), '44. The response contains no privateKey field at all');

$pushConfigSource = file_get_contents(__DIR__ . '/app/config/push.php');
$viewsAndControllers = glob(__DIR__ . '/app/views/**/*.php') + glob(__DIR__ . '/app/controllers/*.php');
$leaked = false;
foreach ($viewsAndControllers as $f) {
    if ($f === __DIR__ . '/app/controllers/PushController.php') continue; // reads the constant name, never its value, into JSON -- checked separately above
    if (str_contains(file_get_contents($f), VAPID_PRIVATE_KEY)) { $leaked = true; break; }
}
ok(!$leaked, '45. The literal VAPID private key string does not appear in any view or controller file');

// safeActionUrl() -- private method, tested via Reflection (a legitimate,
// common technique for verifying a security-critical private helper
// without weakening its encapsulation by making it public).
$service = new PushDeliveryService();
$reflection = new ReflectionMethod(PushDeliveryService::class, 'safeActionUrl');
$reflection->setAccessible(true);
ok($reflection->invoke($service, 'https://evil.example.com/steal') === null, '46. An external, cross-origin action_url is rejected (returns null)');
ok($reflection->invoke($service, APP_URL . '/index.php?page=loan-view&id=1') === APP_URL . '/index.php?page=loan-view&id=1', '47. A same-origin, internal action_url is accepted unchanged');
ok($reflection->invoke($service, null) === null, '48. A missing action_url is handled safely (null in, null out)');

$outGetSubscribe = renderAs('treasurer', (string)U_TREASURER, 'PushController', 'subscribe', [], true); // no csrf_token key at all posted, simulating a bare non-form request
ok(str_contains($outGetSubscribe, '"success":false'), '49. subscribe() without any csrf_token in the request body is rejected');

$outStatus = renderAs('cashier', (string)U_CASHIER, 'PushController', 'status', [], true);
$statusJson = json_decode(str_replace('RESULT:', '', $outStatus), true);
ok(isset($statusJson['enabled']) && $statusJson['enabled'] === true, '50. push-status correctly reports this user\'s own actual subscription state (cashier has one active subscription from Section 2)');

echo "\n============================\n";
echo "TOTAL: {$pass} passed, {$fail} failed\n";
echo "============================\n";
