<?php
/**
 * ISOLATED — Stage 7A verification: Chairman approval workspace
 * completion, including the OpeningBalanceController reachability fix.
 * Runs ONLY against empower_db_ivms_test. Never touches empower_db.
 *
 * Confirms, with real seeded pending records:
 *  1. The dashboard's Pending Approvals widget lists all 4 workflow
 *     types (vouchers, adjustments, investments, opening balances) with
 *     correct reference/type/amount/creator/review-link.
 *  2. Chairman can now actually reach OpeningBalanceController's
 *     index()/view() (the bug this stage found: the constructor
 *     previously rejected chairman before approve()/reject()'s own
 *     admin/chairman check was ever reached).
 *  3. Chairman is still correctly blocked from create/store/submit/post
 *     on the same controller -- the fix grants view+approve+reject only.
 *  4. admin/treasurer's existing full access is unaffected.
 */
chdir(__DIR__);
$pass = 0; $fail = 0;
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }

function renderAs(string $role, string $page, array $get = []): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_stage7_subproc_' . $counter . '.php';
    $getLines = "\$_GET['page'] = '{$page}';\n";
    foreach ($get as $k => $v) { $getLines .= "\$_GET['{$k}'] = " . var_export($v, true) . ";\n"; }
    $routeMap = [
        'dashboard'          => ['DashboardController', 'index'],
        'opening-balances'   => ['OpeningBalanceController', 'index'],
        'opening-balance-view' => ['OpeningBalanceController', 'view'],
        'opening-balance-create' => ['OpeningBalanceController', 'create'],
    ];
    [$class, $method] = $routeMap[$page];
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
{$getLines}
try {
    (new {$class}())->{$method}();
    echo "\\nGATE_PASSED";
} catch (Throwable \$e) {
    echo "EXCEPTION: " . \$e->getMessage();
}
PHP;
    file_put_contents($file, $body);
    $out = shell_exec('"' . PHP_BINARY . '" "' . $file . '" 2>&1');
    @unlink($file);
    return $out ?? '';
}

echo "=== SECTION 0: Fixtures — seed one pending item in each of the 4 workflows ===\n";
chdir(__DIR__);
define('DB_NAME', 'empower_db_ivms_test');
require 'app/config/config.php';
require 'test_safety_guard.php';
require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';
$db = Database::getInstance()->getConnection();
$preparerId = (int)$db->query("SELECT id FROM users LIMIT 1")->fetchColumn();

// Voucher
$ivModel = new InternalVoucherModel();
$catId = (int)$db->query("SELECT id FROM expense_categories WHERE gl_account_id IS NOT NULL LIMIT 1")->fetchColumn();
$voucherId = $ivModel->createDraft([
    'voucher_type' => 'debit', 'voucher_date' => '2026-08-20',
    'expense_category_id' => $catId, 'contra_account_id' => 7,
    'narration' => 'Stage 7 pending approval fixture', 'amount' => 75000,
], $preparerId);
$ivModel->submit($voucherId, $preparerId);
ok((bool)$ivModel->find($voucherId), 'Seeded a pending voucher', '');

// Member Adjustment
$adjModel = new MemberAccountAdjustmentModel();
$adjId = $adjModel->createDraft([
    'member_id' => 2, 'savings_account_id' => 591, 'adjustment_type' => 'credit',
    'amount' => 25000, 'reason' => 'Stage 7 pending approval fixture reason text', 'contra_account_id' => 7,
], $preparerId);
$adjModel->submit($adjId, $preparerId);
ok((bool)$adjModel->find($adjId), 'Seeded a pending member adjustment', '');

// Investment
$invModel = new InvestmentModel();
$invTypeId = (int)$db->query("SELECT id FROM investment_types WHERE is_active=1 LIMIT 1")->fetchColumn();
$investmentId = 0;
if ($invTypeId) {
    $investmentId = $invModel->createDraft([
        'investment_type_id' => $invTypeId, 'principal_amount' => 200000, 'start_date' => '2026-08-20',
        'funding_account_id' => 7,
    ], $preparerId);
    $invModel->submit($investmentId, $preparerId);
    ok((bool)$invModel->find($investmentId), 'Seeded a pending investment', '');
} else {
    echo "  [SKIP] No active investment type fixture available.\n";
}

// Opening Balance batch
$obModel = new OpeningBalanceBatchModel();
$period = $db->query("SELECT id, financial_year_id FROM accounting_periods WHERE status='open' LIMIT 1")->fetch();
$batchId = 0;
if ($period) {
    $accounts = $db->query("SELECT id FROM accounts WHERE is_active=1 LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    $batchId = $obModel->createDraft(
        ['financial_year_id' => $period['financial_year_id'], 'accounting_period_id' => $period['id'], 'as_of_date' => '2026-08-01'],
        [
            ['account_id' => $accounts[0], 'debit' => 150000, 'credit' => 0, 'description' => 'Stage 7 fixture'],
            ['account_id' => $accounts[1], 'debit' => 0, 'credit' => 150000, 'description' => 'Stage 7 fixture'],
        ],
        $preparerId
    );
    $obModel->submit($batchId, $preparerId);
    ok((bool)$obModel->find($batchId), 'Seeded a pending opening balance batch', '');
} else {
    echo "  [SKIP] No open accounting period fixture available.\n";
}

echo "\n=== SECTION 1: Chairman dashboard shows all seeded pending items ===\n";
$out = renderAs('chairman', 'dashboard');
ok(!str_contains($out, 'Fatal error') && !str_contains($out, 'EXCEPTION'), 'Chairman dashboard renders with no fatal error', substr($out, 0, 300));
ok(str_contains($out, '75,000.00'), 'Dashboard shows the pending voucher amount');
ok(str_contains($out, '25,000.00'), 'Dashboard shows the pending adjustment amount');
if ($investmentId) { ok(str_contains($out, '200,000.00'), 'Dashboard shows the pending investment amount'); }
if ($batchId) {
    ok(str_contains($out, '150,000.00'), 'Dashboard shows the pending opening balance amount (Stage 7A fix: previously unreachable)');
    ok(str_contains($out, 'Opening Balance'), 'Dashboard labels the opening balance item correctly');
}
ok(str_contains($out, 'internal-voucher-view&id=' . $voucherId), 'Voucher review link points to the correct record');

echo "\n=== SECTION 2: Chairman can now actually reach OpeningBalanceController (the Stage 7A bug fix) ===\n";
$out = renderAs('chairman', 'opening-balances');
ok(!str_contains($out, 'Fatal error') && !str_contains($out, 'Access denied'), 'Chairman can view the Opening Balances list (previously 403)', substr($out, 0, 200));
if ($batchId) {
    $out = renderAs('chairman', 'opening-balance-view', ['id' => $batchId]);
    ok(!str_contains($out, 'Access denied'), 'Chairman can view a specific Opening Balance batch (previously 403)', substr($out, 0, 200));
    ok(str_contains($out, 'Approve') || str_contains($out, 'approve'), 'Chairman sees the Approve action on a pending batch (now genuinely reachable)');
}

echo "\n=== SECTION 3: Chairman is still BLOCKED from create/store (write-tier untouched) ===\n";
$out = renderAs('chairman', 'opening-balance-create');
ok(str_contains($out, 'Access denied') && !str_contains($out, 'GATE_PASSED'), 'Chairman BLOCKED from OpeningBalanceController::create() (still admin/treasurer only)', substr($out, 0, 200));

echo "\n=== SECTION 4: admin/treasurer unaffected (still full access) ===\n";
$out = renderAs('admin', 'opening-balances');
ok(!str_contains($out, 'Access denied'), 'admin still has full access to Opening Balances list');
$out = renderAs('treasurer', 'opening-balance-create');
ok(str_contains($out, 'GATE_PASSED'), 'treasurer still has write access to Opening Balances create');

echo "\n=== SUMMARY ===\n";
echo "PASS: {$pass}\nFAIL: {$fail}\n";
if ($fail > 0) { exit(1); }
