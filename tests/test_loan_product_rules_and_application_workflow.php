<?php
/**
 * ISOLATED — Stage 9: Loan Product Rules, Eligibility Enforcement &
 * Application-to-Loan Integration verification.
 * Runs ONLY against empower_db_loan_rules_test. Never touches empower_db.
 */
chdir(__DIR__);
$pass = 0; $fail = 0;
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }

define('DB_NAME', 'empower_db_loan_rules_test');
require 'app/config/config.php';
require 'test_safety_guard.php';
require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';
$db = Database::getInstance()->getConnection();

function renderAs(string $role, string $userId, string $page, array $post = []): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_stage9_workflow_subproc_' . $counter . '.php';
    $routeMap = [
        'loan-add'                 => ['LoanController', 'add'],
        'loan-convert-application' => ['LoanController', 'convertApplication'],
        'loan-application-add'     => ['LoanApplicationController', 'add'],
        'loan-application-submit'  => ['LoanApplicationController', 'submit'],
        'loan-application-approve' => ['LoanApplicationController', 'approve'],
        'loan-application-reject'  => ['LoanApplicationController', 'reject'],
    ];
    [$class, $method] = $routeMap[$page];
    $isPost = !empty($post) || in_array($page, ['loan-add','loan-application-add','loan-application-submit','loan-application-approve','loan-application-reject'], true);
    $postLines = $isPost ? "\$_SERVER['REQUEST_METHOD'] = 'POST';\n\$_POST['csrf_token'] = 'skip';\n" : '';
    foreach ($post as $k => $v) { $postLines .= "\$_POST['{$k}'] = " . var_export($v, true) . ";\n"; }
    $getLines = '';
    if ($page === 'loan-convert-application' && isset($post['__get_application_id'])) {
        $getLines = "\$_GET['application_id'] = " . var_export($post['__get_application_id'], true) . ";\n";
    }
    $body = <<<PHP
<?php
chdir(__DIR__);
define('DB_NAME', 'empower_db_loan_rules_test');
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
{$postLines}
{$getLines}
try {
    (new {$class}())->{$method}();
    echo "\\nRESULT:REACHED";
} catch (Throwable \$e) {
    echo "RESULT:EXCEPTION:" . \$e->getMessage();
}
PHP;
    file_put_contents($file, $body);
    $out = shell_exec('"' . PHP_BINARY . '" "' . $file . '" 2>&1');
    @unlink($file);
    return $out ?? '';
}

$loanProductModel = new LoanProductModel();
$loanModel        = new LoanModel();
$appModel         = new LoanApplicationModel();

// ================================================================
// SECTION 0: Schema
// ================================================================
echo "=== SECTION 0: Schema ===\n";
$loanCols = $db->query("SHOW COLUMNS FROM loans")->fetchAll(PDO::FETCH_COLUMN);
foreach (['application_id','income_source','income_details','grace_period_months'] as $c) {
    ok(in_array($c, $loanCols, true), "loans.$c column exists");
}
$instCols = $db->query("SHOW COLUMNS FROM loan_installments")->fetchAll(PDO::FETCH_COLUMN);
ok(in_array('is_grace_period', $instCols, true), 'loan_installments.is_grace_period column exists');
ok((bool)$db->query("SHOW TABLES LIKE 'loan_applications'")->fetch(), 'loan_applications table exists');
$appCols = $db->query("SHOW COLUMNS FROM loan_applications")->fetchAll(PDO::FETCH_COLUMN);
foreach (['application_number','converted_loan_id','approved_amount','approved_period_months'] as $c) {
    ok(in_array($c, $appCols, true), "loan_applications.$c column exists");
}

$existingLoanCount = (int)$db->query("SELECT COUNT(*) FROM loans")->fetchColumn();
$existingRepaymentCount = (int)$db->query("SELECT COUNT(*) FROM loan_repayments")->fetchColumn();

// ================================================================
// SECTION 1: Normal Loan bracket boundaries (Section 32 checklist)
// ================================================================
echo "\n=== SECTION 1: Normal Loan (type 1) interest brackets ===\n";
// Plain indexed [amount, expected] pairs -- an associative array keyed by
// a float amount would have PHP silently truncate e.g. 1000000.01 to the
// integer key 1000000, colliding with (and overwriting) the 1000000 entry.
$normalCases = [
    [99999.99, null],   // below minimum -- must throw
    [100000, 10.0],
    [1000000, 10.0],
    [1000000.01, 5.0],
    [5000000, 5.0],
    [5000000.01, 4.0],
    [10000000, 4.0],
    [10000000.01, 3.0],
    [15000000, 3.0],
    [15000000.01, 2.0],
    [20000000, 2.0],
];
foreach ($normalCases as [$amount, $expected]) {
    try {
        $rate = $loanProductModel->getInterestRate(1, (float)$amount);
        if ($expected === null) {
            ok(false, "UGX " . number_format($amount,2) . " should be rejected (below minimum) but got rate {$rate}%");
        } else {
            ok(abs($rate - $expected) < 0.0001, "UGX " . number_format($amount,2) . " => {$expected}%", "got {$rate}%");
        }
    } catch (InvalidArgumentException $e) {
        ok($expected === null, "UGX " . number_format($amount,2) . " => " . ($expected === null ? 'rejected as expected' : "{$expected}%"), $e->getMessage());
    }
}

// ================================================================
// SECTION 2: Business Loan bracket boundaries
// ================================================================
echo "\n=== SECTION 2: Business Loan (type 2) interest brackets ===\n";
$businessCases = [
    [100000, 4.0], [1000000, 4.0], [1000000.01, 3.0], [5000000, 3.0],
    [5000000.01, 2.5], [10000000, 2.5],
];
foreach ($businessCases as [$amount, $expected]) {
    $rate = $loanProductModel->getInterestRate(2, (float)$amount);
    ok(abs($rate - $expected) < 0.0001, "Business UGX " . number_format($amount,2) . " => {$expected}%", "got {$rate}%");
}

// ================================================================
// SECTION 3: Flat-rate products
// ================================================================
echo "\n=== SECTION 3: Flat-rate products ===\n";
$flatCases = [3 => 2.0, 5 => 3.0, 6 => 3.0, 7 => 1.5, 8 => 7.0];
foreach ($flatCases as $typeId => $expected) {
    $rate = $loanProductModel->getInterestRate($typeId, 500000);
    ok(abs($rate - $expected) < 0.0001, "Loan type {$typeId} flat rate => {$expected}%", "got {$rate}%");
}

// ================================================================
// SECTION 4: Processing fee -- single source
// ================================================================
echo "\n=== SECTION 4: Processing fee single source ===\n";
$feePct = $loanProductModel->processingFeePct();
ok(abs($feePct - 3.0) < 0.0001, 'Global processing fee is 3%', "got {$feePct}%");
foreach ([1,2,3,5,6,7,8] as $typeId) {
    $calc = $loanProductModel->calculateLoan($typeId, $typeId === 3 ? 6000000 : 500000, 6);
    ok(abs($calc['processing_fee_pct'] - 3.0) < 0.0001, "calculateLoan() processing_fee_pct for type {$typeId} is 3%", "got {$calc['processing_fee_pct']}%");
}

// ================================================================
// SECTION 5: Interest-rate override rejection (Section 11 -- the
// central Stage 9 fix)
// ================================================================
echo "\n=== SECTION 5: Interest-rate override rejection ===\n";
$out = renderAs('loans_officer', '1', 'loan-add', [
    'member_id' => '2', 'loan_type_id' => '1', 'loan_amount' => '2000000',
    'loan_period' => '3 Months', 'issue_date' => date('Y-m-d'), // Normal Loan's configured max_period_months is 3
    'interest_rate' => '100', // attempted override -- correct rate for 2,000,000 is 5%
    'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly',
    'disbursement_method' => 'Cash',
]);
$newLoan = $db->query("SELECT * FROM loans WHERE loan_amount=2000000 AND member_id=2 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$newLoan, 'Loan created despite submitted interest_rate=100', $out);
if ($newLoan) {
    ok(abs((float)$newLoan['interest_rate'] - 5.0) < 0.0001, 'Persisted rate is the calculated 5%, not the submitted 100%', 'got ' . $newLoan['interest_rate']);
}

// A bracket-gap amount with a manual rate supplied must still be rejected
// outright (no escape hatch).
$gapLoanCountBefore = (int)$db->query("SELECT COUNT(*) FROM loans WHERE loan_amount=1050000")->fetchColumn();
renderAs('loans_officer', '1', 'loan-add', [
    'member_id' => '2', 'loan_type_id' => '1', 'loan_amount' => '1050000', // previously an unconfigured gap; now resolves to Tier 2
    'loan_period' => '3 Months', 'issue_date' => date('Y-m-d'),
    'interest_rate' => '2', 'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly',
    'disbursement_method' => 'Cash',
]);
// 1,050,000 now resolves cleanly to Tier 2 (5%) since gaps are closed -- confirm no override happened here either.
$gapLoan = $db->query("SELECT * FROM loans WHERE loan_amount=1050000 ORDER BY id DESC LIMIT 1")->fetch();
if ($gapLoan) {
    ok(abs((float)$gapLoan['interest_rate'] - 5.0) < 0.0001, 'UGX 1,050,000 (previously a gap, now closed) resolves to 5%, submitted 2% ignored', 'got ' . $gapLoan['interest_rate']);
}

// Below the Normal Loan minimum must be rejected outright.
$belowMinCountBefore = (int)$db->query("SELECT COUNT(*) FROM loans WHERE loan_amount=50000")->fetchColumn();
renderAs('loans_officer', '1', 'loan-add', [
    'member_id' => '2', 'loan_type_id' => '1', 'loan_amount' => '50000',
    'loan_period' => '3 Months', 'issue_date' => date('Y-m-d'),
    'interest_rate' => '10', 'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly',
    'disbursement_method' => 'Cash',
]);
$belowMinCountAfter = (int)$db->query("SELECT COUNT(*) FROM loans WHERE loan_amount=50000")->fetchColumn();
ok($belowMinCountAfter === $belowMinCountBefore, 'UGX 50,000 (below UGX 100,000 minimum) is rejected, no loan created');

// ================================================================
// SECTION 6: Eligibility gates
// ================================================================
echo "\n=== SECTION 6: Eligibility gates ===\n";

// Asset Financing (type 3) -- member 3 has no tenure requirement to worry about.
$beforeCount = (int)$db->query("SELECT COUNT(*) FROM loans WHERE loan_type_id=3 AND member_id=3")->fetchColumn();
renderAs('loans_officer', '1', 'loan-add', [
    'member_id' => '3', 'loan_type_id' => '3', 'loan_amount' => '10000000',
    'loan_period' => '12 Months', 'issue_date' => date('Y-m-d'),
    'interest_rate' => '2', 'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly',
    'disbursement_method' => 'Cash',
    // deliberately missing income_source, asset_purchase_price, member_contribution, security_description
]);
$afterCount = (int)$db->query("SELECT COUNT(*) FROM loans WHERE loan_type_id=3 AND member_id=3")->fetchColumn();
ok($afterCount === $beforeCount, 'Asset Financing loan rejected when income/contribution/security are all missing');

renderAs('loans_officer', '1', 'loan-add', [
    'member_id' => '3', 'loan_type_id' => '3', 'loan_amount' => '10000000',
    'loan_period' => '12 Months', 'issue_date' => date('Y-m-d'),
    'interest_rate' => '2', 'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly',
    'disbursement_method' => 'Cash',
    'income_source' => 'salary', 'income_details' => 'ABC Ltd',
    'asset_purchase_price' => '10000000', 'member_contribution' => '2000000', // only 20%, needs 30%
    'security_type' => 'asset', 'security_description' => 'The financed vehicle',
]);
$afterCount2 = (int)$db->query("SELECT COUNT(*) FROM loans WHERE loan_type_id=3 AND member_id=3")->fetchColumn();
ok($afterCount2 === $beforeCount, 'Asset Financing loan rejected when contribution is only 20% (needs >=30%)');

renderAs('loans_officer', '1', 'loan-add', [
    'member_id' => '3', 'loan_type_id' => '3', 'loan_amount' => '10000000',
    'loan_period' => '12 Months', 'issue_date' => date('Y-m-d'),
    'interest_rate' => '2', 'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly',
    'disbursement_method' => 'Cash',
    'income_source' => 'salary', 'income_details' => 'ABC Ltd',
    'asset_purchase_price' => '10000000', 'member_contribution' => '3000000', // exactly 30%
    'security_type' => 'asset', 'security_description' => 'The financed vehicle',
]);
$assetLoan = $db->query("SELECT * FROM loans WHERE loan_type_id=3 AND member_id=3 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$assetLoan, 'Asset Financing loan accepted with income source, exactly-30% contribution, and security recorded');

// Start-Up (type 5) -- use member 21 (~12.7 months tenure, qualifies for the 6-month gate).
$beforeCountSU = (int)$db->query("SELECT COUNT(*) FROM loans WHERE loan_type_id=5 AND member_id=21")->fetchColumn();
renderAs('loans_officer', '1', 'loan-add', [
    'member_id' => '21', 'loan_type_id' => '5', 'loan_amount' => '1000000',
    'loan_period' => '6 Months', 'issue_date' => date('Y-m-d'),
    'interest_rate' => '3', 'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly',
    'disbursement_method' => 'Cash',
    // missing security_description
]);
$afterCountSU = (int)$db->query("SELECT COUNT(*) FROM loans WHERE loan_type_id=5 AND member_id=21")->fetchColumn();
ok($afterCountSU === $beforeCountSU, 'Start-Up loan rejected when chattel security is not recorded');

renderAs('loans_officer', '1', 'loan-add', [
    'member_id' => '21', 'loan_type_id' => '5', 'loan_amount' => '1000000',
    'loan_period' => '6 Months', 'issue_date' => date('Y-m-d'),
    'interest_rate' => '3', 'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly',
    'disbursement_method' => 'Cash',
    'security_type' => 'ignored-by-design', 'security_description' => 'Household furniture and a motorcycle',
]);
$startupLoan = $db->query("SELECT * FROM loans WHERE loan_type_id=5 AND member_id=21 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$startupLoan, 'Start-Up loan accepted once chattel security is recorded');
if ($startupLoan) {
    ok($startupLoan['security_type'] === 'chattel', 'Submitted security_type is ignored -- forced to the product-fixed "chattel"', 'got ' . $startupLoan['security_type']);
    ok((int)$startupLoan['grace_period_months'] === 1, 'Start-Up loan snapshots grace_period_months=1 from product config');
}

// Business Loan (type 2) -- member 21 qualifies for the 12-month tenure gate too.
$beforeCountBL = (int)$db->query("SELECT COUNT(*) FROM loans WHERE loan_type_id=2 AND member_id=21")->fetchColumn();
renderAs('loans_officer', '1', 'loan-add', [
    'member_id' => '21', 'loan_type_id' => '2', 'loan_amount' => '2000000',
    'loan_period' => '12 Months', 'issue_date' => date('Y-m-d'),
    'interest_rate' => '3', 'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly',
    'disbursement_method' => 'Cash',
    // missing weekly_savings_commitment
]);
$afterCountBL = (int)$db->query("SELECT COUNT(*) FROM loans WHERE loan_type_id=2 AND member_id=21")->fetchColumn();
ok($afterCountBL === $beforeCountBL, 'Business Loan rejected when weekly savings commitment is not recorded');

renderAs('loans_officer', '1', 'loan-add', [
    'member_id' => '21', 'loan_type_id' => '2', 'loan_amount' => '2000000',
    'loan_period' => '12 Months', 'issue_date' => date('Y-m-d'),
    'interest_rate' => '3', 'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly',
    'disbursement_method' => 'Cash', 'weekly_savings_commitment' => '20000',
]);
$businessLoan = $db->query("SELECT * FROM loans WHERE loan_type_id=2 AND member_id=21 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$businessLoan, 'Business Loan accepted once weekly savings commitment is recorded');

// Low-tenure member (member 2, ~4 months) must fail Business Loan's 12-month gate
// regardless of eligibility fields being present.
$beforeCountLowTenure = (int)$db->query("SELECT COUNT(*) FROM loans WHERE loan_type_id=2 AND member_id=2")->fetchColumn();
renderAs('loans_officer', '1', 'loan-add', [
    'member_id' => '2', 'loan_type_id' => '2', 'loan_amount' => '2000000',
    'loan_period' => '12 Months', 'issue_date' => date('Y-m-d'),
    'interest_rate' => '3', 'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly',
    'disbursement_method' => 'Cash', 'weekly_savings_commitment' => '20000',
]);
$afterCountLowTenure = (int)$db->query("SELECT COUNT(*) FROM loans WHERE loan_type_id=2 AND member_id=2")->fetchColumn();
ok($afterCountLowTenure === $beforeCountLowTenure, 'Business Loan rejected for a member with only ~4 months savings tenure (needs >12)');

// ================================================================
// SECTION 7: Grace period schedule shape
// ================================================================
echo "\n=== SECTION 7: Grace period schedule shape ===\n";
if ($startupLoan) {
    $installments = $db->query("SELECT * FROM loan_installments WHERE loan_id=" . (int)$startupLoan['id'] . " ORDER BY installment_no")->fetchAll();
    ok(count($installments) === 6, 'Start-Up loan (6 months) generated 6 installments', 'got ' . count($installments));
    if (count($installments) === 6) {
        ok((int)$installments[0]['is_grace_period'] === 1, 'Installment 1 is flagged as grace period');
        ok(abs((float)$installments[0]['amount_due'] - (float)$startupLoan['interest_amount'] / 6) < 1.0,
            'Grace installment amount_due is interest-only (no principal)');
        for ($i = 1; $i < 6; $i++) {
            ok((int)$installments[$i]['is_grace_period'] === 0, "Installment " . ($i+1) . " is NOT flagged as grace period");
        }
        $totalDue = array_sum(array_column($installments, 'amount_due'));
        ok(abs($totalDue - (float)$startupLoan['total_payable']) < 1.0, 'Sum of all installments equals total_payable despite grace deferral', "sum={$totalDue} total_payable={$startupLoan['total_payable']}");
    }
}
// Agricultural (grace=0 configured) must be completely unaffected.
renderAs('loans_officer', '1', 'loan-add', [
    'member_id' => '3', 'loan_type_id' => '6', 'loan_amount' => '1000000',
    'loan_period' => '6 Months', 'issue_date' => date('Y-m-d'),
    'interest_rate' => '3', 'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly',
    'disbursement_method' => 'Cash',
]);
$agriLoan = $db->query("SELECT * FROM loans WHERE loan_type_id=6 AND member_id=3 ORDER BY id DESC LIMIT 1")->fetch();
if ($agriLoan) {
    ok((int)$agriLoan['grace_period_months'] === 0, 'Agricultural loan snapshots grace_period_months=0 (unchanged config)');
    $agriInst = $db->query("SELECT * FROM loan_installments WHERE loan_id=" . (int)$agriLoan['id'])->fetchAll();
    $graceFlagged = array_filter($agriInst, fn($r) => (int)$r['is_grace_period'] === 1);
    ok(count($graceFlagged) === 0, 'Agricultural loan has zero grace-flagged installments');
}

// ================================================================
// SECTION 8: Application-to-loan workflow
// ================================================================
echo "\n=== SECTION 8: Application-to-loan workflow ===\n";

// 8a. Create + submit an application as loans_officer (user 1)
$out = renderAs('loans_officer', '1', 'loan-application-add', [
    'member_id' => '3', 'loan_type_id' => '1', 'requested_amount' => '5000000',
    'requested_period_months' => '3', // Normal Loan's configured max_period_months is 3
]);
$app1 = $db->query("SELECT * FROM loan_applications WHERE member_id=3 AND requested_amount=5000000 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$app1, 'Application created as draft', $out);
if ($app1) { ok($app1['status'] === 'draft', 'New application status is draft'); }

if ($app1) {
    renderAs('loans_officer', '1', 'loan-application-submit', ['application_id' => (string)$app1['id']]);
    $app1 = $db->query("SELECT * FROM loan_applications WHERE id=" . (int)$app1['id'])->fetch();
    ok($app1['status'] === 'pending_approval', 'Application moves to pending_approval after submit');
}

// 8b. Pending application cannot be converted
if ($app1) {
    // convertApplication() always ends in redirect()+exit (both the error
    // and success paths), so there is no printable "reached" marker to
    // assert on here -- the real assertion is the DB state check below.
    renderAs('loans_officer', '1', 'loan-convert-application', ['__get_application_id' => (string)$app1['id']]);
    $loanFromPending = $db->query("SELECT COUNT(*) FROM loans WHERE application_id=" . (int)$app1['id'])->fetchColumn();
    ok((int)$loanFromPending === 0, 'A pending application cannot be converted to a loan');
}

// 8c. Self-approval prevention: the same user (1) who created it cannot approve it
if ($app1) {
    renderAs('loans_officer', '1', 'loan-application-approve', [
        'application_id' => (string)$app1['id'], 'approved_amount' => '4000000', 'approved_period_months' => '3',
    ]);
    $app1check = $db->query("SELECT status FROM loan_applications WHERE id=" . (int)$app1['id'])->fetch();
    ok($app1check['status'] === 'pending_approval', 'Application creator cannot approve their own application (still pending)');
}

// 8d. System Admin cannot approve a loan application
if ($app1) {
    renderAs('system_admin', '999', 'loan-application-approve', [
        'application_id' => (string)$app1['id'], 'approved_amount' => '4000000', 'approved_period_months' => '3',
    ]);
    $app1check2 = $db->query("SELECT status FROM loan_applications WHERE id=" . (int)$app1['id'])->fetch();
    ok($app1check2['status'] === 'pending_approval', 'System Admin cannot approve a loan application (still pending)');
}

// 8e. Chairman (different user) approves with an amount LOWER than requested
if ($app1) {
    renderAs('chairman', '296', 'loan-application-approve', [
        'application_id' => (string)$app1['id'], 'approved_amount' => '4000000', 'approved_period_months' => '3',
    ]);
    $app1 = $db->query("SELECT * FROM loan_applications WHERE id=" . (int)$app1['id'])->fetch();
    ok($app1['status'] === 'approved', 'Chairman (not the creator) approves the application');
    ok(abs((float)$app1['approved_amount'] - 4000000) < 0.01, 'Approved amount (4,000,000) differs from requested (5,000,000) and is stored correctly');
}

// 8f. Rejected/pending applications cannot convert -- prove with a second, freshly rejected application
$out = renderAs('loans_officer', '1', 'loan-application-add', [
    'member_id' => '4', 'loan_type_id' => '1', 'requested_amount' => '2000000', 'requested_period_months' => '3',
]);
$app2 = $db->query("SELECT * FROM loan_applications WHERE member_id=4 AND requested_amount=2000000 ORDER BY id DESC LIMIT 1")->fetch();
if ($app2) {
    renderAs('loans_officer', '1', 'loan-application-submit', ['application_id' => (string)$app2['id']]);
    renderAs('chairman', '296', 'loan-application-reject', ['application_id' => (string)$app2['id'], 'reason' => 'Insufficient documentation']);
    $app2 = $db->query("SELECT * FROM loan_applications WHERE id=" . (int)$app2['id'])->fetch();
    ok($app2['status'] === 'rejected', 'Application rejected by Chairman');
    renderAs('loans_officer', '1', 'loan-convert-application', ['__get_application_id' => (string)$app2['id']]);
    $rejectedConverted = $db->query("SELECT COUNT(*) FROM loans WHERE application_id=" . (int)$app2['id'])->fetchColumn();
    ok((int)$rejectedConverted === 0, 'A rejected application cannot be converted to a loan');
}

// 8g. Convert the approved application (app1) into a real loan -- using the
// approved amount/period, not the originally requested ones.
if ($app1 && $app1['status'] === 'approved') {
    renderAs('loans_officer', '1', 'loan-convert-application', ['__get_application_id' => (string)$app1['id']]);
    // convertApplication() flashes into session and redirects -- the actual
    // loan record is only created on a subsequent POST to loan-add carrying
    // application_id, which the real browser flow supplies automatically
    // from the flashed form_old. Simulate that follow-up POST directly.
    renderAs('loans_officer', '1', 'loan-add', [
        'application_id' => (string)$app1['id'],
        'member_id' => (string)$app1['member_id'], 'loan_type_id' => (string)$app1['loan_type_id'],
        'loan_amount' => (string)$app1['approved_amount'], 'loan_period' => $app1['approved_period_months'] . ' Months',
        'issue_date' => date('Y-m-d'), 'interest_rate' => '999', // attempted override, must be ignored
        'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly', 'disbursement_method' => 'Cash',
    ]);
    $convertedLoan = $db->query("SELECT * FROM loans WHERE application_id=" . (int)$app1['id'] . " ORDER BY id DESC LIMIT 1")->fetch();
    ok((bool)$convertedLoan, 'Approved application converted into a real loan');
    if ($convertedLoan) {
        ok(abs((float)$convertedLoan['loan_amount'] - 4000000) < 0.01, 'Converted loan uses the APPROVED amount (4,000,000), not the requested (5,000,000)');
        ok(abs((float)$convertedLoan['interest_rate'] - 5.0) < 0.0001, 'Converted loan rate is server-calculated (5%), submitted 999% ignored');
    }
    $appAfterConvert = $db->query("SELECT * FROM loan_applications WHERE id=" . (int)$app1['id'])->fetch();
    ok(!empty($appAfterConvert['converted_loan_id']), 'Application now records converted_loan_id (reverse link)');
    if ($convertedLoan && $appAfterConvert) {
        ok((int)$appAfterConvert['converted_loan_id'] === (int)$convertedLoan['id'], 'Forward and reverse links point at each other');
    }

    // 8h. Duplicate conversion attempt (direct POST replay) must fail --
    // the UNIQUE constraint + getConvertibleOrFail() both guard this.
    $loanCountBeforeReplay = (int)$db->query("SELECT COUNT(*) FROM loans WHERE application_id=" . (int)$app1['id'])->fetchColumn();
    renderAs('loans_officer', '1', 'loan-add', [
        'application_id' => (string)$app1['id'],
        'member_id' => (string)$app1['member_id'], 'loan_type_id' => (string)$app1['loan_type_id'],
        'loan_amount' => (string)$app1['approved_amount'], 'loan_period' => $app1['approved_period_months'] . ' Months',
        'issue_date' => date('Y-m-d'), 'interest_rate' => '5',
        'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly', 'disbursement_method' => 'Cash',
    ]);
    $loanCountAfterReplay = (int)$db->query("SELECT COUNT(*) FROM loans WHERE application_id=" . (int)$app1['id'])->fetchColumn();
    ok($loanCountAfterReplay === $loanCountBeforeReplay, 'Replaying the conversion POST does not create a second loan for the same application');

    // Direct UNIQUE-constraint proof: attempt a raw duplicate insert.
    try {
        $db->prepare("UPDATE loan_applications SET converted_loan_id=? WHERE id=?")->execute([$convertedLoan['id'], $app2['id'] ?? 0]);
        ok(false, 'UNIQUE constraint on converted_loan_id should have rejected a second application claiming the same loan');
    } catch (PDOException $e) {
        ok(true, 'Database UNIQUE constraint independently rejects two applications claiming the same converted_loan_id');
    }
}

// ================================================================
// SECTION 9: Role authorization -- direct action attempts
// ================================================================
echo "\n=== SECTION 9: Role authorization ===\n";
$deniedRoles = ['system_admin', 'cashier', 'office_admin', 'treasurer'];
foreach ($deniedRoles as $role) {
    $countBefore = (int)$db->query("SELECT COUNT(*) FROM loan_applications")->fetchColumn();
    renderAs($role, '888', 'loan-application-add', [
        'member_id' => '2', 'loan_type_id' => '1', 'requested_amount' => '1000000', 'requested_period_months' => '6',
    ]);
    $countAfter = (int)$db->query("SELECT COUNT(*) FROM loan_applications")->fetchColumn();
    ok($countAfter === $countBefore, "{$role} cannot create a loan application");
}
foreach (['loans_officer', 'treasurer', 'cashier', 'office_admin'] as $role) {
    if (!$app2) continue;
    renderAs($role, '888', 'loan-application-approve', [
        'application_id' => (string)$app2['id'], 'approved_amount' => '1', 'approved_period_months' => '1',
    ]);
    $stillRejected = $db->query("SELECT status FROM loan_applications WHERE id=" . (int)$app2['id'])->fetch();
    ok($stillRejected['status'] === 'rejected', "{$role} cannot approve an application (already-rejected one stays rejected)");
}

// ================================================================
// SECTION 10: Non-regression -- historical loans and repayments untouched
// ================================================================
echo "\n=== SECTION 10: Non-regression ===\n";
$finalHistoricalCheck = $db->query("SELECT id, loan_number, loan_amount, interest_rate, outstanding FROM loans WHERE id IN (14,16,17,18,19,21,27,28,31,34,35,37)")->fetchAll();
ok(count($finalHistoricalCheck) === 12, 'All 12 original historical loans still present');
$repaymentCountFinal = (int)$db->query("SELECT COUNT(*) FROM loan_repayments")->fetchColumn();
ok($repaymentCountFinal === $existingRepaymentCount, 'No existing loan_repayments rows were touched', "before={$existingRepaymentCount} after={$repaymentCountFinal}");

echo "\n=== SUMMARY ===\n";
echo "PASS: $pass\n";
echo "FAIL: $fail\n";
