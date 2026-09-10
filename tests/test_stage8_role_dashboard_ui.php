<?php
/**
 * Stage 8 — Role Dashboard, Sidebar & Workflow Refinement test suite
 * (2026-09).
 *
 * Runs against empower_db_ivms_test. Covers: the mandatory voucher
 * approval role matrix (Chairman allowed, System Admin denied), dashboard/
 * sidebar rendering for all 9 roles, the viewer/member sidebar and
 * quick-actions fix, and route correctness for every new link.
 */
define('DB_NAME', 'empower_db_ivms_test');
chdir(__DIR__);
require 'app/config/config.php';
require 'test_safety_guard.php';
require CORE_PATH . '/Database.php';
require CORE_PATH . '/Model.php';
require CORE_PATH . '/Autoloader.php';
require CORE_PATH . '/Session.php';
require CORE_PATH . '/Controller.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $label, string $detail = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; echo "  [FAIL] $label -- $detail\n"; }
}

function runAction(string $role, string $controllerClass, string $method, array $get = [], array $post = []): array {
    $file    = __DIR__ . '/tmp_stage8_subproc_' . uniqid() . '.php';
    $outfile = __DIR__ . '/tmp_stage8_out_' . uniqid() . '.json';
    $getExport  = var_export($get, true);
    $postExport = var_export($post, true);
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
Session::set('last_activity', time());
\$_GET  = {$getExport};
\$_POST = {$postExport};
register_shutdown_function(function () {
    file_put_contents('{$outfile}', json_encode(['flash' => \$_SESSION['_flash'] ?? []]));
});
try {
    (new {$controllerClass}())->{$method}();
} catch (Throwable \$e) {
    echo 'CAUGHT_EXCEPTION: ' . get_class(\$e) . ': ' . \$e->getMessage();
}
PHP;
    file_put_contents($file, $body);
    $rawOut = shell_exec('"' . PHP_BINARY . '" "' . $file . '" 2>&1');
    $result = file_exists($outfile) ? json_decode(file_get_contents($outfile), true) : null;
    @unlink($file);
    @unlink($outfile);
    return ['flash' => $result['flash'] ?? [], 'raw' => $rawOut ?? ''];
}

function wasBlocked(array $resp): bool {
    $raw = $resp['raw'];
    $err = $resp['flash']['error'] ?? '';
    if (str_contains($raw, 'Fatal error')) return false;
    // Only count an authorization-shaped denial -- a deliberately-wrong
    // CSRF token in this test's own POST triggers "Security token
    // mismatch" for roles that correctly PASS the role gate (chairman,
    // admin), which is not a role denial and must not be conflated with
    // one.
    $deniedText = str_contains($raw, 'Access denied') || str_contains($raw, 'privileges required')
        || stripos($err, 'denied') !== false || stripos($err, 'privileges required') !== false;
    return $deniedText;
}

$allRoles = ['chairman', 'system_admin', 'admin', 'treasurer', 'cashier', 'office_admin', 'loans_officer', 'member', 'viewer'];

echo "=== SECTION A: CRITICAL — Voucher approval role matrix ===\n";
// 2026-09 update: after this stage shipped, the user made a further
// explicit decision -- voucher approval is narrowed from admin+chairman
// to CHAIRMAN ONLY (root cause: the one 'admin'-role user's account is
// literally named "System Administrator", making the admin-authorized
// approval widget look like a system_admin capability -- rather than
// reassign that user's role, which would leave zero admin-role users
// system-wide, the user chose to remove voucher-approval authority from
// admin specifically). Every other admin capability in this controller
// (view/create/post vouchers) is unchanged, and admin still approves
// every other pending-approval type (adjustments/investments/opening
// balances/loans).
foreach (['approve', 'reject'] as $action) {
    foreach ($allRoles as $role) {
        $out = runAction($role, 'InternalVoucherController', $action, ['page' => "internal-voucher-{$action}"], ['voucher_id' => '1', 'csrf_token' => 'x']);
        $blocked = wasBlocked($out);
        if ($role === 'chairman') {
            // CSRF is deliberately wrong in this test, so chairman must
            // get PAST the role gate (not blocked by "Access denied") --
            // a CSRF-mismatch redirect is the expected downstream outcome,
            // proven separately not to be a role-based denial.
            ok(!$blocked, "{$role} passes the role gate for {$action}() (sole voucher approver)", $out['raw']);
        } else {
            ok($blocked, "{$role} is DENIED {$action}() -- not a voucher approver (chairman-only)", $out['raw']);
        }
    }
}
// The one sentence that matters most in this entire stage:
$systemAdminApprove = runAction('system_admin', 'InternalVoucherController', 'approve', ['page' => 'internal-voucher-approve'], ['voucher_id' => '1', 'csrf_token' => 'x']);
ok(wasBlocked($systemAdminApprove), 'CRITICAL: System Administrator CANNOT approve vouchers (Chairman is the sole non-admin approver)', $systemAdminApprove['raw']);

echo "\n=== SECTION B: System Admin dashboard has zero voucher/approval content ===\n";
$sysAdminDashSrc = file_get_contents(__DIR__ . '/app/controllers/DashboardController.php');
ok(preg_match('/systemAdminDashboard.*?\{.*?\n\}/s', $sysAdminDashSrc), 'systemAdminDashboard() method exists');
$viewSrc = file_get_contents(__DIR__ . '/app/views/dashboard/system-admin.php');
// A disclaimer sentence mentioning "vouchers" as an example of financial
// authority System Admin does NOT have (confirmed present, and a good
// thing) is not the same as an actual pending-approval queue/review
// widget -- check specifically for the latter shape.
ok(!preg_match('/pending.?approval.{0,80}(review|approve|reject)/is', $viewSrc), 'dashboard/system-admin.php contains no pending-approval review/approve widget', 'checked for a pending-approval + review/approve/reject widget pattern');
ok(str_contains($viewSrc, 'does not have financial transaction authority'), 'dashboard/system-admin.php explicitly documents the boundary (vouchers/savings/loans/withdrawals belong to other roles)');

echo "\n=== SECTION C: Chairman dashboard retains its Pending Approvals workflow; admin's card removed entirely ===\n";
// 2026-09 second update: after the voucher-only narrowing above, the
// user asked to remove the whole "Pending Approvals" card from admin's
// dashboard outright (it had been reduced to an anticlimactic
// "Nothing awaiting your approval" empty state). Chairman is completely
// unaffected -- their own separate "Action Required" overview panel
// still uses this exact same underlying data.
$out = runAction('chairman', 'DashboardController', 'index', ['page' => 'dashboard']);
ok(!str_contains($out['raw'], 'Fatal error'), 'Chairman dashboard renders without a fatal error');
$dashCtrlSrc = file_get_contents(__DIR__ . '/app/controllers/DashboardController.php');
ok(str_contains($dashCtrlSrc, "if (Session::hasRole(['chairman'])) {") && !str_contains($dashCtrlSrc, "hasRole(['chairman', 'admin'])") && !str_contains($dashCtrlSrc, "hasRole(['chairman','admin'])"), 'Pending Approvals data is now built for chairman only -- admin no longer receives it at all');
$adminOut = runAction('admin', 'DashboardController', 'index', ['page' => 'dashboard']);
ok(!str_contains($adminOut['raw'], 'IV-000002') && !str_contains($adminOut['raw'], 'IV-'), 'Admin dashboard shows no voucher reference');
ok(!str_contains($adminOut['raw'], 'Nothing awaiting your approval') && !str_contains($adminOut['raw'], 'What requires my attention'), 'Admin dashboard shows no Pending Approvals card content at all -- not even the empty state');
$chairmanOut = runAction('chairman', 'DashboardController', 'index', ['page' => 'dashboard']);
ok(str_contains($chairmanOut['raw'], 'IV-000002'), 'Chairman still sees the real pending voucher in their own Action Required panel (unaffected)');

echo "\n=== SECTION D: All 9 roles render the dashboard without a fatal error ===\n";
foreach ($allRoles as $role) {
    $out = runAction($role, 'DashboardController', 'index', ['page' => 'dashboard']);
    ok(!str_contains($out['raw'], 'Fatal error') && !str_contains($out['raw'], 'CAUGHT_EXCEPTION'), "{$role} dashboard renders cleanly", $out['raw']);
}

echo "\n=== SECTION E: Viewer sidebar/quick-actions fix ===\n";
$out = runAction('viewer', 'DashboardController', 'index', ['page' => 'dashboard']);
ok(str_contains($out['raw'], 'Reports &amp; Statements') || str_contains($out['raw'], 'Reports & Statements'), 'Viewer sees its dedicated read-only sidebar section');
ok(!str_contains($out['raw'], 'Record Repayment') && !str_contains($out['raw'], 'Record Savings') && !str_contains($out['raw'], 'Record Loan') && !str_contains($out['raw'], 'Add Member'), 'Viewer sees NO staff transaction-mutation quick-action buttons (previously a misleading dead-end)');
ok(str_contains($out['raw'], 'View Reports') && str_contains($out['raw'], 'View Statements'), 'Viewer sees its real, backend-confirmed quick actions instead');

echo "\n=== SECTION F: Member sidebar/quick-actions fix ===\n";
$out = runAction('member', 'DashboardController', 'index', ['page' => 'dashboard']);
ok(str_contains($out['raw'], 'My Account'), 'Member sees its dedicated minimal sidebar section');
ok(!str_contains($out['raw'], 'Record Repayment') && !str_contains($out['raw'], 'Record Savings') && !str_contains($out['raw'], 'Record Loan') && !str_contains($out['raw'], 'Add Member'), 'Member sees NO staff transaction-mutation quick-action buttons');
ok(!str_contains($out['raw'], 'Membership') && !str_contains($out['raw'], 'Financial Reports'), 'Member does not see the full generic staff sidebar sections');

echo "\n=== SECTION G: Admin is completely unaffected (regression) ===\n";
$out = runAction('admin', 'DashboardController', 'index', ['page' => 'dashboard']);
ok(str_contains($out['raw'], 'Record Repayment'), 'Admin still sees the original quick actions (universal authority preserved)');
ok(str_contains($out['raw'], 'Membership'), 'Admin still sees the original full generic sidebar (unaffected)');

echo "\n=== SECTION H: Route correctness -- every link in the new sidebars resolves ===\n";
$routeChecks = [
    ['ReportController', 'index', 'reports', 'viewer'],
    ['ReportController', 'financial', 'report-financial', 'viewer'],
    ['StatementController', 'index', 'statements', 'viewer'],
    ['ChartOfAccountsController', 'index', 'chart-of-accounts', 'viewer'],
    ['FinancialYearController', 'index', 'financial-years', 'viewer'],
    ['InternalVoucherController', 'index', 'internal-vouchers', 'viewer'],
    ['WeeklyReportController', 'savings', 'weekly-savings', 'viewer'],
    ['DashboardController', 'index', 'dashboard', 'member'],
];
foreach ($routeChecks as [$class, $method, $page, $role]) {
    $out = runAction($role, $class, $method, ['page' => $page]);
    ok(!wasBlocked($out) && !str_contains($out['raw'], 'Fatal error'), "{$role} can actually load '{$page}' (linked from its sidebar, not a dead end)", $out['raw']);
}
$routeSrc = file_get_contents(__DIR__ . '/index.php');
foreach (['reports', 'report-financial', 'statements', 'chart-of-accounts', 'financial-years', 'internal-vouchers', 'weekly-savings'] as $route) {
    ok((bool)preg_match('/[\'"]' . preg_quote($route, '/') . '[\'"]\s*=>/', $routeSrc), "Route '{$route}' is registered in index.php (sidebar link is not a stale/invented route)");
}

echo "\n=== SECTION I: No financial/accounting logic touched ===\n";
foreach (['app/services/JournalService.php', 'app/services/ControlledCorrectionService.php', 'app/services/SystemIntegrityService.php', 'app/services/TransactionInvestigationService.php', 'app/services/DatabaseRecoveryService.php'] as $f) {
    ok(!str_contains(file_get_contents(__DIR__ . '/' . $f), 'Stage 8'), "{$f} carries no Stage 8 modification marker -- untouched");
}
// 2026-09 update: InternalVoucherController.php WAS deliberately modified
// after this stage shipped -- a real, explicit, user-directed policy
// change (voucher approval narrowed to chairman-only), not a regression
// or scope creep. The important invariants are: it's the ONLY narrowing
// (view/create/post access is untouched), and it's precisely scoped
// (requireApproverAccess() alone, not the constructor's broader view gate).
$voucherControllerSrc = file_get_contents(__DIR__ . '/app/controllers/InternalVoucherController.php');
ok(str_contains($voucherControllerSrc, "hasRole(['chairman'])") && !str_contains($voucherControllerSrc, "hasRole(['admin', 'chairman'])"), 'requireApproverAccess() now reads exactly chairman-only (deliberate, documented policy change)');
ok(str_contains($voucherControllerSrc, "hasRole(['admin', 'treasurer', 'viewer', 'chairman'])"), 'The broader view-access gate (admin/treasurer/viewer/chairman) is unchanged -- only approval authority was narrowed');
ok(str_contains($voucherControllerSrc, "hasRole(['admin', 'treasurer'])"), 'Create/submit/post access (admin/treasurer) is unchanged');

echo "\n=== SECTION J: Production immutability ===\n";
$prodPdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=empower_db;charset=' . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$journalCount = (int)$prodPdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$voucherStatus = $prodPdo->query("SELECT status FROM internal_vouchers WHERE voucher_number='IV-000002'")->fetchColumn();
ok($voucherStatus === 'pending_approval', 'The real IV-000002 voucher in production is untouched (still pending_approval, never approved/rejected by this test run)', (string)$voucherStatus);
ok($journalCount > 0, 'Production journal_entries table is reachable and non-empty (sanity check)');

echo "\n=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
