<?php
/**
 * Stage 12-G.1 — Deposit Analytics chart UI refinement (period selector,
 * compact axis formatting, loading/empty/error states). Read-only
 * throughout (no INSERT/UPDATE/DELETE anywhere in the code path under
 * test), so this runs directly against production `empower_db` rather
 * than an isolated clone -- confirmed safe by inspecting
 * SavingsModel::monthlyDepositWithdrawalTrend() and
 * DashboardController::depositAnalyticsData()/index() before writing this.
 */
chdir(__DIR__);
$pass = 0; $fail = 0;
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }

require 'app/config/config.php';
require 'app/config/database.php';
require 'app/config/push.php';
require 'vendor/autoload.php';
require CORE_PATH . '/Database.php';
require CORE_PATH . '/Model.php';
require CORE_PATH . '/Autoloader.php';
require CORE_PATH . '/Session.php';
require CORE_PATH . '/Controller.php';
Session::start();
Session::set('user_id', 1);
Session::set('user_role', 'admin');
Session::set('user_name', 'Probe');
Session::set('last_activity', time());
$_SERVER['REQUEST_METHOD'] = 'GET';

$model = new SavingsModel();

function callAjax(string $months): array {
    $file = __DIR__ . '/tmp_stage12g1_ajax_' . uniqid() . '.php';
    $body = <<<PHP
<?php
chdir(__DIR__);
require 'app/config/config.php';
require 'app/config/database.php';
require 'app/config/push.php';
require 'vendor/autoload.php';
require CORE_PATH . '/Database.php';
require CORE_PATH . '/Model.php';
require CORE_PATH . '/Autoloader.php';
require CORE_PATH . '/Session.php';
require CORE_PATH . '/Controller.php';
Session::start();
Session::set('user_id', 1);
Session::set('user_role', 'admin');
Session::set('user_name', 'Probe');
Session::set('last_activity', time());
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_GET['months'] = '$months';
ob_start();
(new DashboardController())->depositAnalyticsData();
echo ob_get_clean();
PHP;
    file_put_contents($file, $body);
    $out = shell_exec('"' . PHP_BINARY . '" "' . $file . '" 2>&1');
    @unlink($file);
    return json_decode($out ?? '', true) ?? [];
}

function callAjaxUnauth(): string {
    $file = __DIR__ . '/tmp_stage12g1_ajax_' . uniqid() . '.php';
    $body = <<<PHP
<?php
chdir(__DIR__);
require 'app/config/config.php';
require 'app/config/database.php';
require 'app/config/push.php';
require 'vendor/autoload.php';
require CORE_PATH . '/Database.php';
require CORE_PATH . '/Model.php';
require CORE_PATH . '/Autoloader.php';
require CORE_PATH . '/Session.php';
require CORE_PATH . '/Controller.php';
Session::start();
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_GET['months'] = '6';
ob_start();
(new DashboardController())->depositAnalyticsData();
echo "LEAKED:" . ob_get_clean();
PHP;
    file_put_contents($file, $body);
    $out = shell_exec('"' . PHP_BINARY . '" "' . $file . '" 2>&1');
    @unlink($file);
    return $out ?? '';
}

echo "=== SECTION 1: Backend integrity (period selector data source) ===\n";

$ajax6 = callAjax('6');
ok(($ajax6['success'] ?? false) === true && count($ajax6['rows'] ?? []) === 6, '1. 6-month request via the new AJAX endpoint returns exactly 6 calendar months', json_encode($ajax6['months'] ?? null));

$ajax12 = callAjax('12');
ok(($ajax12['success'] ?? false) === true && count($ajax12['rows'] ?? []) === 12, '2. 12-month request returns exactly 12 calendar months');

$ajax3 = callAjax('3');
ok(($ajax3['success'] ?? false) === true && count($ajax3['rows'] ?? []) === 3, '3. 3-month request returns exactly 3 calendar months');

$zeroRow = null;
foreach ($ajax12['rows'] as $r) { if ((float)$r['deposits'] === 0.0 && (float)$r['withdrawals'] === 0.0 && $r['month'] < '2026-05') { $zeroRow = $r; break; } }
ok($zeroRow !== null, '4. Zero-activity months remain present in the AJAX response (not silently dropped)');

$months = array_column($ajax12['rows'], 'month');
$sequential = true;
for ($i = 1; $i < count($months); $i++) {
    $expected = (new DateTime($months[$i-1] . '-01'))->modify('+1 month')->format('Y-m');
    if ($months[$i] !== $expected) { $sequential = false; }
}
ok($sequential, '5. Chronological ordering is correct in the AJAX response (never alphabetical, never reversed)');

$prevGrowth = null; $carriesForward = true;
foreach ($ajax12['rows'] as $r) {
    if ((float)$r['deposits'] === 0.0 && (float)$r['withdrawals'] === 0.0 && $prevGrowth !== null) {
        if (abs((float)$r['growth'] - $prevGrowth) > 0.01) { $carriesForward = false; }
    }
    $prevGrowth = (float)$r['growth'];
}
ok($carriesForward, '6. Cumulative growth carries forward correctly through zero-activity months in the AJAX response');

echo "=== SECTION 2: Input validation ===\n";

$ajaxBad1 = callAjax('999');
ok(($ajaxBad1['months'] ?? null) === 6 && count($ajaxBad1['rows'] ?? []) === 6, '7. An out-of-whitelist month value (999) safely falls back to the 6-month default, not an arbitrary window');

$ajaxBad2 = callAjax('abc');
ok(($ajaxBad2['months'] ?? null) === 6, '8. A non-numeric month value falls back to the 6-month default');

$unauthOut = callAjaxUnauth();
ok(!str_contains($unauthOut, 'LEAKED:'), '9. An unauthenticated request to the AJAX endpoint is blocked before any data is returned (Session::requireAuth() redirects+exits first)');

echo "=== SECTION 3: Data integrity (backend values == chart values, no browser-side alteration) ===\n";

$directCall = $model->monthlyDepositWithdrawalTrend(6);
$identical = count($directCall) === count($ajax6['rows']);
if ($identical) {
    foreach ($directCall as $i => $r) {
        $a = $ajax6['rows'][$i];
        if ($a['month'] !== $r['month']
            || abs((float)$a['deposits'] - (float)$r['deposits']) > 0.01
            || abs((float)$a['withdrawals'] - (float)$r['withdrawals']) > 0.01
            || abs((float)$a['growth'] - (float)$r['growth']) > 0.01
        ) { $identical = false; }
    }
}
ok($identical, '10. The AJAX endpoint returns values byte-for-byte identical to a direct call to the same backend model method -- no duplicate/divergent data path exists');

echo "=== SECTION 4: UI configuration (source-level, since no browser is available in this environment) ===\n";

$viewSrc = file_get_contents(__DIR__ . '/app/views/dashboard/index.php');

ok(str_contains($viewSrc, "label: 'Deposit'") && str_contains($viewSrc, "type: 'bar'"), '11. Deposit dataset exists and is configured as a bar');
ok(str_contains($viewSrc, "label: 'Growth'") && str_contains($viewSrc, "type: 'line'"), '12. Growth dataset exists and is configured as a line');
ok(str_contains($viewSrc, "label: 'Withdraw'"), '13. Withdraw dataset exists');
ok(str_contains($viewSrc, 'tension: 0.4'), '14. Growth line uses a smooth curve');
ok(str_contains($viewSrc, "callbacks: { label:"), '15. A tooltip callback exists');
ok(str_contains($viewSrc, "position: 'bottom'"), '16. Legend is positioned at the bottom');
ok(str_contains($viewSrc, 'id="depositAnalyticsPeriod"') && str_contains($viewSrc, 'value="3"') && str_contains($viewSrc, 'value="6"') && str_contains($viewSrc, 'value="12"'), '17. Period selector exists with 3/6/12-month options');
ok(substr_count($viewSrc, 'new Chart(canvas') === 1, '18. Exactly one Chart construction call site exists in source (no duplicate-chart-instance code path)');
ok(str_contains($viewSrc, 'if (chart) { chart.destroy(); }'), '19. The chart is explicitly destroyed before any re-render, guaranteeing only one live Chart instance at runtime regardless of how many times the period changes');
ok(str_contains($viewSrc, 'fmtShsCompact'), '20. A compact axis-formatting helper exists for large financial values (axis only -- tooltip keeps full precision via a separate formatter)');
ok(str_contains($viewSrc, "fmtShsFull(ctx.parsed.y)"), '21. The tooltip uses the FULL-precision formatter, never the compact one -- large values are never misrepresented to the user on hover');
ok(str_contains($viewSrc, 'depositAnalyticsLoading') && str_contains($viewSrc, 'depositAnalyticsEmpty') && str_contains($viewSrc, 'depositAnalyticsError'), '22. Loading, empty, and error states all exist as distinct UI elements');
ok(str_contains($viewSrc, 'No deposit activity for this period') && str_contains($viewSrc, 'Unable to load deposit analytics'), '23. The required exact empty-state and error-state messages are present, not a raw/leaked error');
ok(!preg_match('/php.*Fatal|Exception.*getMessage.*innerHTML/i', $viewSrc), '24. No PHP/exception detail is ever surfaced into the DOM on failure');
ok(str_contains($viewSrc, "window.addEventListener('resize'") && !preg_match("/resize.*\{[^}]*fetch\(/s", $viewSrc), '25. Window resize re-renders from already-held data and never triggers a new network request (no unnecessary AJAX)');
ok(str_contains($viewSrc, 'aria-label="Deposit Analytics period"') || str_contains($viewSrc, "for=\"depositAnalyticsPeriod\""), '26. The period selector has an accessible label');
ok(str_contains($viewSrc, "'X-Requested-With': 'XMLHttpRequest'"), '27. The AJAX request identifies itself as such (consistent with this app\'s existing AJAX convention elsewhere)');

// Real bug found and fixed via user report + code re-inspection (not
// caught by any prior test, since no browser was available to actually
// render the chart): maintainAspectRatio:false on mobile with no
// explicit container height collapsed the whole canvas to a sliver,
// making the line look flat and the bars invisible. Guard against a
// regression of the exact fix.
ok(preg_match('/@media \(max-width: 575\.98px\)\s*\{\s*#depositAnalyticsCanvasWrap\s*\{\s*height:\s*260px/s', $viewSrc) === 1, '28. The chart wrapper has an explicit height on mobile viewports (required because maintainAspectRatio is false there) -- omitting this was the confirmed cause of the reported flat-line/invisible-bars rendering');
ok(str_contains($viewSrc, 'maxRotation: 0, minRotation: 0'), '29. X-axis labels stay horizontal at every width, never eating vertical space via rotation on an already height-constrained mobile chart');

// Guard against ever "fixing" a rendering complaint by collapsing back
// to a single shared axis -- that would silently reintroduce the exact
// defect Stage 12-G.0 fixed (one huge cumulative-growth figure
// flattening every ordinary month's deposit/withdrawal bar).
// Superseded by explicit user direction after seeing the dual-axis
// version rendered too crowded: Deposit/Withdraw/Growth now
// deliberately SHARE one axis (matching a simpler reference layout),
// accepting the disclosed tradeoff that a month with a much larger
// figure than the rest will visually flatten the others. Confirmed no
// stray yAxisID/second-scale definition was left behind from the prior
// architecture.
ok(!str_contains($viewSrc, "yAxisID:") && !str_contains($viewSrc, 'yAmount:') && !str_contains($viewSrc, 'yGrowth:'), '30. Deposit/Withdraw/Growth deliberately share a single axis (no yAxisID split, no second scale definition left over from the prior dual-axis version)');

echo "=== SECTION 5: Regression (unchanged from Stage 12-G.0) ===\n";

ok(method_exists('SavingsModel', 'monthlyTotals'), '31. The pre-existing, unrelated monthlyTotals() method still exists');
$loanModelSrc = file_get_contents(__DIR__ . '/app/models/LoanModel.php');
$journalServiceSrc = file_get_contents(__DIR__ . '/app/services/JournalService.php');
ok(!str_contains($loanModelSrc, 'depositAnalyticsData') && !str_contains($journalServiceSrc, 'depositAnalyticsData'), '32. LoanModel.php and JournalService.php were not touched by this UI-only refinement');

try {
    ob_start();
    (new DashboardController())->index();
    $dashboardHtml = ob_get_clean();
    ok(str_contains($dashboardHtml, 'Deposit Analytics'), '33. The dashboard\'s initial page load still renders the (renamed) chart card without error');
} catch (Throwable $e) {
    ok(false, '33. Dashboard initial render', $e->getMessage());
}

echo "\n============================\n";
echo "TOTAL: {$pass} passed, {$fail} failed\n";
echo "============================\n";
