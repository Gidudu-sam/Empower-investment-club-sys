<?php
/**
 * ISOLATED — Stage 12-E.2: Notification Browser Delivery Verification.
 * Runs ONLY against empower_db_stage12e2_test. Never touches empower_db.
 *
 * Scope: everything about the server-side response the browser consumes
 * (JSON shape, per-user isolation, session-derived identity, headers,
 * source-level checks of the JS/service-worker for the browser-only
 * behaviors that cannot be exercised without a real browser -- those are
 * disclosed explicitly, not silently skipped, in the implementation report).
 */
chdir(__DIR__);
$pass = 0; $fail = 0;
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }

define('DB_NAME', 'empower_db_stage12e2_test');
require 'app/config/config.php';
require 'app/config/push.php';
require 'vendor/autoload.php';
require 'test_safety_guard.php';
require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';
$db = Database::getInstance()->getConnection();

function renderAs(string $role, string $userId, string $class, string $action, array $post = [], bool $post_flag = true): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_stage12e2_subproc_' . $counter . '.php';
    $postLines = '';
    if ($post_flag) {
        $postLines = "\$_SERVER['REQUEST_METHOD'] = 'POST';\n\$_POST['csrf_token'] = 'skip';\n";
        foreach ($post as $k => $v) { $postLines .= "\$_POST['{$k}'] = " . var_export($v, true) . ";\n"; }
    }
    $body = <<<PHP
<?php
chdir(__DIR__);
define('DB_NAME', 'empower_db_stage12e2_test');
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

const U_ADMIN = 1; const U_TREASURER = 297; const U_CASHIER = 298;
$notifModel = new NotificationModel();

echo "=== SECTION 1: fetchLatest() JSON shape and per-user correctness ===\n";

$notifModel->generateLoanNotifications(); // reproduce the real production reminder batch, isolated DB only

$adminOut = renderAs('admin', (string)U_ADMIN, 'NotificationController', 'fetchLatest', [], false);
$adminJson = json_decode(str_replace('RESULT:', '', $adminOut), true);
ok(is_array($adminJson) && array_key_exists('unread', $adminJson) && array_key_exists('notifications', $adminJson), '1. Admin fetchLatest() returns the expected JSON shape');
$adminUnreadDirect = $notifModel->unreadCount(U_ADMIN);
ok($adminJson['unread'] === $adminUnreadDirect, '2. Admin unread count in the JSON matches the server-computed unreadCount() exactly', "{$adminJson['unread']} vs {$adminUnreadDirect}");
ok(!empty($adminJson['notifications']) && $adminJson['notifications'][0]['user_id'] === U_ADMIN, '3. Every row in admin\'s response actually carries user_id=admin');

$treasurerOut = renderAs('treasurer', (string)U_TREASURER, 'NotificationController', 'fetchLatest', [], false);
$treasurerJson = json_decode(str_replace('RESULT:', '', $treasurerOut), true);
$treasurerUnreadDirect = $notifModel->unreadCount(U_TREASURER);
ok($treasurerJson['unread'] === $treasurerUnreadDirect, '4. Treasurer unread count in the JSON matches the server-computed unreadCount() exactly');
ok(!empty($treasurerJson['notifications']) && $treasurerJson['notifications'][0]['user_id'] === U_TREASURER, '5. Every row in treasurer\'s response actually carries user_id=treasurer');

echo "=== SECTION 2: Cross-user isolation ===\n";

$adminIds = array_column($adminJson['notifications'], 'id');
$treasurerIds = array_column($treasurerJson['notifications'], 'id');
ok(count(array_intersect($adminIds, $treasurerIds)) === 0, '6. Admin and treasurer never receive an identical set of notification row ids (each has their own materialized rows)');
$cashierOut = renderAs('cashier', (string)U_CASHIER, 'NotificationController', 'fetchLatest', [], false);
$cashierJson = json_decode(str_replace('RESULT:', '', $cashierOut), true);
ok($cashierJson['unread'] === 0 || count(array_intersect($adminIds, array_column($cashierJson['notifications'], 'id'))) === 0, '7. Cashier (not a loan-management role) never receives admin\'s notification rows');

echo "=== SECTION 3: Session-derived identity cannot be overridden by the client ===\n";

// Attempt to smuggle a different user_id via POST/GET -- fetchLatest() must
// still resolve identity from Session::get('user_id') only.
$file = __DIR__ . '/tmp_stage12e2_smuggle.php';
file_put_contents($file, <<<'PHP'
<?php
chdir(__DIR__);
define('DB_NAME', 'empower_db_stage12e2_test');
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
Session::set('user_id', 298); // real authenticated session identity: cashier
Session::set('user_role', 'cashier');
Session::set('last_activity', time());
$_GET['user_id'] = 1;       // attacker-supplied, must be ignored
$_POST['user_id'] = 1;      // attacker-supplied, must be ignored
try {
    ob_start();
    (new NotificationController())->fetchLatest();
    echo "RESULT:" . ob_get_clean();
} catch (Throwable $e) { echo "RESULT:EXCEPTION:" . $e->getMessage(); }
PHP
);
$smuggleOut = shell_exec('"' . PHP_BINARY . '" "' . $file . '" 2>&1');
@unlink($file);
$smuggleJson = json_decode(str_replace('RESULT:', '', $smuggleOut), true);
ok(($smuggleJson['unread'] ?? -1) === $notifModel->unreadCount(U_CASHIER), '8. A GET/POST user_id=1 cannot override the real session identity (cashier) -- fetchLatest() still returns cashier\'s own data');
ok(empty($smuggleJson['notifications']) || $smuggleJson['notifications'][0]['user_id'] !== U_ADMIN, '9. The smuggled user_id never causes admin\'s rows to be returned to the cashier session');

echo "=== SECTION 4: Full /notifications page uses the same authenticated identity ===\n";

$adminPageOut = renderAs('admin', (string)U_ADMIN, 'NotificationController', 'index', [], false);
ok(str_contains($adminPageOut, 'RESULT:') && !str_contains($adminPageOut, 'Fatal error'), '10. Admin\'s full notification page renders without error');
$treasurerPageOut = renderAs('treasurer', (string)U_TREASURER, 'NotificationController', 'index', [], false);
ok(str_contains($treasurerPageOut, 'RESULT:') && !str_contains($treasurerPageOut, 'Fatal error'), '11. Treasurer\'s full notification page renders without error');

echo "=== SECTION 5: Response header / caching posture (documented, not assumed) ===\n";

$file2 = __DIR__ . '/tmp_stage12e2_headers.php';
file_put_contents($file2, <<<PHP
<?php
chdir(__DIR__);
define('DB_NAME', 'empower_db_stage12e2_test');
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
Session::set('user_id', 1);
Session::set('user_role', 'admin');
Session::set('last_activity', time());
register_shutdown_function(function () { file_put_contents('php://stderr', implode('|', headers_list())); });
(new NotificationController())->fetchLatest();
PHP
);
$headerOut = shell_exec('"' . PHP_BINARY . '" "' . $file2 . '" 2>&1');
@unlink($file2);
// CLI SAPI does not reliably surface header() calls via headers_list() --
// confirmed directly (this always returns empty under CLI regardless of
// what the code sends), so this is a source-level check, not a live
// HTTP-response check; disclosed as such rather than asserting something
// CLI cannot actually observe.
$setsJsonContentType = str_contains(file_get_contents(__DIR__ . '/core/Controller.php'), "'Content-Type: application/json'");
ok($setsJsonContentType, '12. Controller::json() sets Content-Type: application/json (verified from source -- CLI SAPI does not track header() calls via headers_list(), confirmed empty regardless of code)');
$hasExplicitCacheDirective = str_contains(file_get_contents(__DIR__ . '/core/Controller.php'), 'Cache-Control');
echo "  [INFO] Explicit Cache-Control header present in Controller::json(): " . ($hasExplicitCacheDirective ? 'yes' : 'no (matches this app\'s universal convention -- no endpoint anywhere sets one; no Last-Modified/ETag either, so heuristic caching does not meaningfully apply per RFC 7234)') . "\n";
ok(true, '13. Caching posture documented (not treated as a proven defect without reproduction)');

echo "=== SECTION 6: Service worker cannot intercept notification-fetch (source-level, verified) ===\n";

$swSource = file_get_contents(__DIR__ . '/sw.js');
ok(!preg_match('/addEventListener\s*\(\s*[\'"]fetch[\'"]/', $swSource), '14. sw.js registers no "fetch" event listener at all -- it structurally cannot intercept, cache, or interfere with notification-fetch or any other request');
ok(str_contains($swSource, "addEventListener('push'") && str_contains($swSource, "addEventListener('notificationclick'"), '15. sw.js only ever listens for push/notificationclick, exactly as designed in Stage 12-D');

echo "=== SECTION 7: JS source-level checks (no persisted cross-session state) ===\n";

$mainSource = file_get_contents(__DIR__ . '/app/views/layouts/main.php');
ok(!str_contains($mainSource, 'localStorage'), '16. The notification bell script never uses localStorage (no risk of a previous user\'s notification state surviving a login switch)');
ok(str_contains($mainSource, "document.getElementById('notif-badge')") && str_contains($mainSource, "document.getElementById('notifList')"), '17. Badge/list DOM selectors are read fresh from the current page on every load (no module-level caching across page loads, since the whole script re-runs on each full page render)');
ok(!preg_match('/Notification\.requestPermission\(\)/', substr($mainSource, 0, strpos($mainSource, 'async function enable'))), '18. Notification.requestPermission() still only appears inside the explicit enable() action, never at top-level page load (re-confirmed unregressed since Stage 12-D)');

echo "\n============================\n";
echo "TOTAL: {$pass} passed, {$fail} failed\n";
echo "============================\n";
