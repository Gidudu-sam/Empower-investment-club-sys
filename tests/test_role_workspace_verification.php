<?php
/**
 * ISOLATED — Role Workspace (sidebar + dashboard) verification.
 * Runs ONLY against empower_db_ivms_test. Never touches empower_db.
 * Confirms every role's dashboard renders without a fatal error and
 * shows the right content, and every role's sidebar shows/hides the
 * expected sections. UI-layer verification only — backend authorization
 * was already verified in the prior two stages and is untouched here.
 *
 * Each role's full page is rendered in its OWN subprocess: sidebar.php
 * declares top-level functions (isActive/isActiveGroup) via a plain
 * `include` (not include_once) inside the shared layout, so rendering
 * more than one full page per PHP process would fatal on redeclare --
 * a test-harness concern only, since a real browser request is always
 * a fresh process.
 */
chdir(__DIR__);
$pass = 0; $fail = 0;
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }

function renderDashboardAs(string $role): string {
    $file = __DIR__ . '/tmp_workspace_verify_subproc_' . $role . '.php';
    $body = <<<PHP
<?php
chdir(__DIR__);
define('DB_NAME', 'empower_db_ivms_test');
require 'app/config/config.php';
require 'test_safety_guard.php';
require CORE_PATH . '/Database.php';
require CORE_PATH . '/Model.php';
require CORE_PATH . '/Autoloader.php';
require CORE_PATH . '/Session.php';
require CORE_PATH . '/Controller.php';
Session::start();
Session::set('user_id', 1);
Session::set('user_role', '{$role}');
Session::set('user_name', 'Test {$role}');
Session::set('last_activity', time());
\$_GET['page'] = 'dashboard';
(new DashboardController())->index();
PHP;
    file_put_contents($file, $body);
    $out = shell_exec('"' . PHP_BINARY . '" "' . $file . '" 2>&1');
    @unlink($file);
    return $out ?? '';
}

$roles = ['admin','treasurer','cashier','viewer','chairman','loans_officer','office_admin','system_admin'];

echo "=== SECTION 1: Dashboard renders for every role, no fatal error ===\n";
$fullPageByRole = [];
foreach ($roles as $role) {
    $out = renderDashboardAs($role);
    $fullPageByRole[$role] = $out;
    ok(!str_contains($out, 'Fatal error') && !str_contains($out, 'EXCEPTION'), "$role: dashboard renders with no fatal error", substr($out, 0, 300));
}

echo "\n=== SECTION 2: System Administrator gets the distinct technical dashboard ===\n";
$out = $fullPageByRole['system_admin'];
ok(str_contains($out, 'System Administration'), 'system_admin dashboard shows the System Administration heading');
ok(str_contains($out, 'Users by Role'), 'system_admin dashboard shows Users by Role widget');
ok(!str_contains($out, 'Total Club Savings'), 'system_admin dashboard does NOT show financial stat cards');
ok(!str_contains($out, 'Record Repayment') && !str_contains($out, 'Record Savings'), 'system_admin dashboard has no financial quick actions');

echo "\n=== SECTION 3: Chairman sees the Pending Approvals panel; other roles do not ===\n";
// Note: dashboard/index.php has an HTML *comment* mentioning "Pending
// Approvals" unconditionally (harmless -- invisible to users, no data in
// it) above the actual conditional block, so detection uses the panel's
// icon class (bi-check2-square), which only renders inside the guarded
// block itself, not the literal phrase.
$out = $fullPageByRole['chairman'];
ok(str_contains($out, 'bi-check2-square'), 'chairman dashboard shows the Pending Approvals panel');
// Stage 7A redesigned this from an aggregate per-type count table to a
// per-item list (reference/type/amount/creator/date/Review). This suite
// clones current production data as-is (not a guaranteed-empty fixture
// set) -- production may legitimately have real pending items at any
// given time, so this just confirms the widget renders one of its two
// valid states, rather than assuming which one. The itemized rendering
// itself is verified with seeded fixtures in test_stage7_chairman_approvals.php.
ok(str_contains($out, 'Nothing awaiting your approval') || str_contains($out, 'Review'),
    'chairman Pending Approvals renders either the empty state or a real itemized list');

$out = $fullPageByRole['cashier'];
ok(!str_contains($out, 'bi-check2-square'), 'cashier dashboard does NOT show the Pending Approvals panel');

echo "\n=== SECTION 4: Role-specific Quick Actions render correctly ===\n";
$expectedQuickActions = [
    // Chairman dashboard redesign (2026-09): Pending Approvals is now the
    // primary quick action, replacing the three separate "Review X" links.
    'chairman'      => ['Pending Approvals', 'Financial Summary', 'Loan Portfolio', 'Audit Activity'],
    'treasurer'     => ['New Voucher', 'Chart of Accounts', 'Trial Balance'],
    'cashier'       => ['Record Deposit', 'Record Repayment', 'Record Fee', 'Process Withdrawal'],
    'loans_officer' => ['New Loan Application', 'Loan Register'],
    'office_admin'  => ['Register Member', 'Open Savings Account'],
];
foreach ($expectedQuickActions as $role => $labels) {
    $out = $fullPageByRole[$role];
    foreach ($labels as $label) {
        ok(str_contains($out, $label), "$role dashboard Quick Actions includes \"$label\"");
    }
}
ok(!str_contains($fullPageByRole['cashier'], 'page=loan-add'), 'cashier Quick Actions has no "New Loan" link');

echo "\n=== SECTION 5: Sidebar section visibility per role (same rendered page) ===\n";
$sidebarExpectations = [
    'system_admin' => [
        'see' => ['>Users<', '>Database<', '>Audit Logs<'],
        'not' => ['>Members<', '>Savings<', '>Loans<', '>Accounting<', 'Internal Vouchers', 'Financial Reports'],
    ],
    'cashier' => [
        // Sidebar-redesign (2026-09 Cashier audit): dedicated
        // sidebar-cashier.php, "Collections"-first IA -- no bare "Fees"
        // or "Loans" heading anymore (Fees & Charges / Loan Repayments
        // respectively), and "Record Deposit" replaces "Record Savings"
        // to match the label Treasurer's sidebar already used.
        'see' => ['Fees & Charges', 'Record Deposit', 'Process Withdrawal', 'Loan Repayments'],
        'not' => ['>Accounting<', 'Chart of Accounts', '>Users<', '>Database<', 'Financial Reports', '>Loans<', 'Internal Vouchers', 'Investments'],
    ],
    'office_admin' => [
        // '>Users<' briefly moved to 'see' during the user-management
        // alignment stage, then explicitly reverted -- Office
        // Administrator's grant there was revoked, back to admin/system_admin only.
        // Sidebar-redesign (2026-09 Office Admin audit): dedicated
        // sidebar-office_admin.php uses "Fees & Charges" (not bare "Fees")
        // and "Add / Register Member" (not bare "Add Member").
        'see' => ['Fees & Charges', 'Savings Accounts', '>Statements<', 'Add / Register Member', 'Weekly Savings'],
        'not' => ['Record Savings', 'Process Withdrawal', '>Accounting<', '>Database<', '>Audit Logs<', '>Users<', '>Loans<', 'Weekly Loan Disbursements', 'Overdue Loans'],
    ],
    'loans_officer' => [
        // Sidebar-redesign (2026-09 Loans Officer role refinement): dedicated
        // sidebar-loans_officer.php uses "Loan Applications" (not a bare
        // "Loans" heading) and "New Loan Application" (matching the
        // dashboard's own label, not "Record Loan").
        'see' => ['Loan Applications', 'Loan Register', 'New Loan Application', 'Record Repayment', 'Loan Aging'],
        'not' => ['>Accounting<', '>Users<', '>Database<', 'Internal Vouchers', '>Fees<', 'Withdrawal', 'Investments'],
    ],
    'chairman' => [
        // Opening Balances moved here in Stage 7A: the reachability bug
        // (constructor blocked chairman before approve()/reject()'s own
        // gate could ever run) is now fixed, so chairman correctly sees
        // this link -- previously it was deliberately hidden precisely
        // because it was a dead end.
        // Chairman role-refinement (2026-09): dedicated sidebar-chairman.php
        // folds Trial Balance/Income Statement/Balance Sheet into the
        // single "Accounting" section (no separate "Financial Reports"
        // heading, matching Treasurer's own precedent) and no longer shows
        // any create/manage link for Chart of Accounts/Accounting Periods/
        // Financial Years/Withdrawal Policies (moved to Treasurer).
        'see' => ['>Accounting<', 'Trial Balance', 'Internal Vouchers', 'Investments', '>Loans<', 'Opening Balances', 'Audit Logs'],
        'not' => ['Record Savings', 'Process Withdrawal', 'Add Member', '>Users<', 'Financial Reports'],
    ],
    'treasurer' => [
        // Sidebar-redesign (2026-09): Treasurer gets a dedicated finance-
        // control sidebar (sidebar-treasurer.php) that folds Trial
        // Balance/Income Statement/Balance Sheet into the single
        // "Accounting" section rather than a separate "Financial Reports"
        // heading -- the content still exists, just regrouped.
        'see' => ['>Accounting<', 'Chart of Accounts', 'Trial Balance', 'Balance Sheet', 'Opening Balances', 'Internal Vouchers', 'Financial Operations', '>Finance<'],
        'not' => ['>Users<', '>Database<', '>Audit Logs<', 'Financial Reports', 'Record Loan', 'Add Member'],
    ],
];
/**
 * Matches a needle either literally, or — for the ">Word<" shorthand used
 * above to mean "this exact nav-link label, not a substring elsewhere on
 * the page" — tolerating the whitespace/newlines real markup has between
 * an icon tag and its label (e.g. `></i>\n    Database\n</a>`).
 */
function pageContains(string $html, string $needle): bool {
    if (str_contains($html, $needle)) return true;
    if (preg_match('/^>(.+)<$/', $needle, $m)) {
        return (bool)preg_match('/>\s*' . preg_quote($m[1], '/') . '\s*</', $html);
    }
    return false;
}

foreach ($sidebarExpectations as $role => $expect) {
    $html = $fullPageByRole[$role];
    foreach ($expect['see'] as $needle) {
        ok(pageContains($html, $needle), "$role sidebar SHOWS \"$needle\"");
    }
    foreach ($expect['not'] as $needle) {
        ok(!pageContains($html, $needle), "$role sidebar HIDES \"$needle\"");
    }
}

echo "\n=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
