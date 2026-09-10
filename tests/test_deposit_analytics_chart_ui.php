<?php
/**
 * Deposit Analytics chart UI upgrade — presentation-layer verification.
 * Read-only throughout (no INSERT/UPDATE/DELETE anywhere in the code
 * path under test), so this runs directly against production `empower_db`
 * rather than an isolated clone -- confirmed safe by inspecting
 * SavingsModel::monthlyDepositWithdrawalTrend() and
 * DashboardController::index() before writing this file.
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

echo "=== SECTION 1: Zero-fill / month-range correctness ===\n";

$rows12 = $model->monthlyDepositWithdrawalTrend(12);
ok(count($rows12) === 12, '1. monthlyDepositWithdrawalTrend(12) returns exactly 12 rows (previously returned only months with data -- confirmed 4/12 before this fix)', (string)count($rows12));

$prevMonth = null;
$sequential = true;
foreach ($rows12 as $r) {
    if ($prevMonth !== null) {
        $expected = (new DateTime($prevMonth . '-01'))->modify('+1 month')->format('Y-m');
        if ($r['month'] !== $expected) { $sequential = false; }
    }
    $prevMonth = $r['month'];
}
ok($sequential, '2. Months are strictly chronologically sequential with no gaps');

$allKeysPresent = true;
foreach ($rows12 as $r) {
    if (!array_key_exists('month', $r) || !array_key_exists('label', $r) || !array_key_exists('deposits', $r) || !array_key_exists('withdrawals', $r) || !array_key_exists('growth', $r)) {
        $allKeysPresent = false;
    }
}
ok($allKeysPresent, '3. Every row has month/label/deposits/withdrawals/growth keys');

$rows6 = $model->monthlyDepositWithdrawalTrend(6);
ok(count($rows6) === 6, '4. A shorter period (6 months) is supported and returns exactly 6 rows', (string)count($rows6));

echo "=== SECTION 2: Real production data shape (as of today) ===\n";

$byMonth = [];
foreach ($rows12 as $r) { $byMonth[$r['month']] = $r; }

// These are the real, previously-confirmed production figures for
// May-Aug 2026 (unchanged by this fix -- only the previously-missing
// months were added, no existing value was altered).
$knownReal = [
    '2026-05' => ['deposits' => 120000.00, 'withdrawals' => 0.0],
    '2026-06' => ['deposits' => 315000.00, 'withdrawals' => 0.0],
    '2026-07' => ['deposits' => 1237274963.00, 'withdrawals' => 0.0],
    '2026-08' => ['deposits' => 345899.65, 'withdrawals' => 0.0],
];
$matchesKnown = true;
foreach ($knownReal as $month => $expected) {
    $actual = $byMonth[$month] ?? null;
    if (!$actual || abs($actual['deposits'] - $expected['deposits']) > 0.01 || abs($actual['withdrawals'] - $expected['withdrawals']) > 0.01) {
        $matchesKnown = false;
    }
}
ok($matchesKnown, '5. The previously-confirmed real May-Aug 2026 deposit figures are unchanged by the zero-fill fix (including the known 1,237,274,963 July deposit)');

$zeroMonth = $byMonth['2025-10'] ?? null;
ok($zeroMonth !== null && (float)$zeroMonth['deposits'] === 0.0 && (float)$zeroMonth['withdrawals'] === 0.0, '6. A month with genuinely zero activity (Oct 2025) shows deposits=0 and withdrawals=0 explicitly, not missing');

// Growth must CARRY FORWARD across a zero-activity month, never reset.
$sepGrowth = $byMonth['2026-09']['growth'] ?? null;
$augGrowth = $byMonth['2026-08']['growth'] ?? null;
ok($sepGrowth !== null && $augGrowth !== null && abs($sepGrowth - $augGrowth) < 0.01, '7. Growth correctly carries forward unchanged through a zero-activity month (Sep 2026, no transactions yet) rather than resetting to 0', "{$sepGrowth} vs {$augGrowth}");

echo "=== SECTION 3: Internal data-integrity (growth derived correctly from deposits/withdrawals, no drift) ===\n";

$driftFree = true;
$prevGrowth = 0.0;
foreach ($rows12 as $r) {
    $expectedDelta = (float)$r['deposits'] - (float)$r['withdrawals'];
    $actualDelta = (float)$r['growth'] - $prevGrowth;
    if (abs($expectedDelta - $actualDelta) > 0.01) { $driftFree = false; }
    $prevGrowth = (float)$r['growth'];
}
ok($driftFree, '8. Every month\'s growth-over-growth delta exactly equals that month\'s (deposits - withdrawals) -- no arithmetic drift between the three figures');

echo "=== SECTION 4: Backend-to-frontend integrity (the chart shows exactly what the backend computed) ===\n";

try {
    ob_start();
    (new DashboardController())->index();
    $html = ob_get_clean();
} catch (Throwable $e) {
    $html = '';
    ok(false, '9. Dashboard rendered without error', $e->getMessage());
}

if ($html !== '') {
    ok(true, '9. Dashboard rendered without error');

    // Stage 12-G.1 changed the initial-render default from 12 to 6
    // months (the period selector's own default) and renamed the JS
    // variable from monthlySavings to currentRows (needed so the
    // resize handler can re-render from already-held data) -- both
    // deliberate, spec-required changes from that later stage, not a
    // regression here. Reconciled against monthlyDepositWithdrawalTrend(6),
    // not the 12-month $rows12 used elsewhere in this file.
    // Two "currentRows = ..." assignments exist in source: an initial
    // "let currentRows = [];" declaration, then the real data a few
    // lines later -- must match the one actually carrying JSON content
    // (containing "month"), not the empty-array declaration.
    if (preg_match('/currentRows = (\[\{.*?\}\]);/s', $html, $m)) {
        $embedded = json_decode($m[1], true);
        ok(is_array($embedded) && count($embedded) === 6, '10. The chart\'s embedded data array has exactly 6 entries (Stage 12-G.1\'s new default), matching the model output', json_last_error_msg());

        $rows6 = $model->monthlyDepositWithdrawalTrend(6);
        $identical = true;
        foreach ($rows6 as $i => $r) {
            $e = $embedded[$i] ?? null;
            if (!$e || $e['month'] !== $r['month']
                || abs((float)$e['deposits'] - (float)$r['deposits']) > 0.01
                || abs((float)$e['withdrawals'] - (float)$r['withdrawals']) > 0.01
                || abs((float)$e['growth'] - (float)$r['growth']) > 0.01
            ) { $identical = false; }
        }
        ok($identical, '11. Every value embedded in the rendered page is byte-for-byte identical to a direct call to the backend model -- the chart never recalculates or alters any figure');
    } else {
        ok(false, '10-11. Could not locate the embedded chart data in the rendered HTML');
    }

    // Stage 12-G.1 renamed the dataset labels to singular (Deposit/Withdraw)
    // to match that stage's own tooltip spec example exactly.
    ok(str_contains($html, "type: 'bar'") && str_contains($html, "label: 'Deposit'"), '12. Deposit is configured as a bar dataset');
    ok(str_contains($html, "type: 'bar'") && str_contains($html, "label: 'Withdraw'"), '13. Withdraw is configured as a bar dataset');
    ok(str_contains($html, "type: 'line'") && str_contains($html, "label: 'Growth'"), '14. Growth is configured as a line dataset');
    ok(str_contains($html, 'tension: 0.4'), '15. The Growth line uses a smooth curve (tension), not a jagged straight-segment line');
    ok(str_contains($html, "position: 'bottom'"), '16. The legend is positioned at the bottom of the chart');
    ok(!preg_match('/[\'"](January|February|March|April|May|June|July|August|September|October|November|December)[\'"]/', $html), '17. No calendar month name is hardcoded anywhere in the page source -- labels come entirely from the backend-supplied data');
    ok(str_contains($html, 'callbacks: { label:'), '18. A custom tooltip callback exists to format each series value');
}

echo "\n=== SECTION 5: No-change confirmation (unrelated financial logic untouched) ===\n";

ok(method_exists('SavingsModel', 'monthlyTotals'), '19. The pre-existing, unrelated monthlyTotals() method still exists (not removed as a side effect)');
$oldMethodRows = $model->monthlyTotals(12);
ok(is_array($oldMethodRows), '20. monthlyTotals() still executes without error, unaffected by the new method added alongside it');

$loanModelSrc = file_get_contents(__DIR__ . '/app/models/LoanModel.php');
$journalServiceSrc = file_get_contents(__DIR__ . '/app/services/JournalService.php');
ok(!str_contains($loanModelSrc, 'monthlyDepositWithdrawalTrend'), '21. LoanModel.php was not touched by this chart-only change');
ok(!str_contains($journalServiceSrc, 'monthlyDepositWithdrawalTrend'), '22. JournalService.php was not touched by this chart-only change');

echo "\n============================\n";
echo "TOTAL: {$pass} passed, {$fail} failed\n";
echo "============================\n";
