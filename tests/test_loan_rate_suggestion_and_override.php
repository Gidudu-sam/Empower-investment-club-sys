<?php
/**
 * ISOLATED — Stage 9.1: Dynamic Rate Prediction & Controlled Rate Override.
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
    $file = __DIR__ . '/tmp_stage91_subproc_' . $counter . '.php';
    $routeMap = ['loan-add' => ['LoanController', 'add']];
    [$class, $method] = $routeMap[$page];
    $postLines = "\$_SERVER['REQUEST_METHOD'] = 'POST';\n\$_POST['csrf_token'] = 'skip';\n";
    foreach ($post as $k => $v) { $postLines .= "\$_POST['{$k}'] = " . var_export($v, true) . ";\n"; }
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

$productModel = new LoanProductModel();

// ================================================================
// SECTION 0: Schema
// ================================================================
echo "=== SECTION 0: Schema ===\n";
$cols = $db->query("SHOW COLUMNS FROM loans")->fetchAll(PDO::FETCH_COLUMN);
foreach (['suggested_interest_rate','rate_overridden','rate_override_by','rate_override_reason'] as $c) {
    ok(in_array($c, $cols, true), "loans.$c column exists");
}

// ================================================================
// SECTION 1: getProductInfo()/getBracketsOrFlatRate() cover all 7 products
// (Stage 9.1 fix -- loan_product_settings only ever had 3 rows; the
// rate-prediction UI previously got nothing back for the other 4)
// ================================================================
echo "\n=== SECTION 1: Product info available for every active loan type ===\n";
$allTypes = [1 => 'Normal', 2 => 'Business', 3 => 'Asset Financing', 5 => 'Start-Up', 6 => 'Agricultural', 7 => 'Executive', 8 => 'Emergency'];
foreach ($allTypes as $typeId => $label) {
    $info = $productModel->getProductInfo($typeId);
    ok($info !== false, "getProductInfo({$typeId}) [{$label}] returns data");
    $brackets = $productModel->getBracketsOrFlatRate($typeId);
    ok(count($brackets) > 0, "getBracketsOrFlatRate({$typeId}) [{$label}] returns at least one bracket/rate");
}
// Flat-rate products return the synthetic single bracket the JS expects.
foreach ([3 => 2.0, 5 => 3.0, 6 => 3.0, 7 => 1.5, 8 => 7.0] as $typeId => $expectedRate) {
    $brackets = $productModel->getBracketsOrFlatRate($typeId);
    ok(count($brackets) === 1 && (float)$brackets[0]['min_amount'] === 0.0 && (float)$brackets[0]['max_amount'] === 0.0,
        "Type {$typeId} returns a synthetic flat-rate bracket (min=0,max=0)");
    ok(abs((float)$brackets[0]['monthly_rate'] - $expectedRate) < 0.0001, "Type {$typeId} synthetic bracket rate is {$expectedRate}%");
}
// Tiered products still return their real bracket rows.
ok(count($productModel->getBracketsOrFlatRate(1)) === 5, 'Normal Loan returns its 5 real tiered brackets');
ok(count($productModel->getBracketsOrFlatRate(2)) === 3, 'Business Loan returns its 3 real tiered brackets');

// ================================================================
// SECTION 2: applyRate() re-derives the same formula calculateLoan() uses
// ================================================================
echo "\n=== SECTION 2: applyRate() ===\n";
$calc = $productModel->calculateLoan(1, 2000000, 3); // Normal Loan, suggested 5%
ok(abs($calc['interest_rate'] - 5.0) < 0.0001, 'Baseline calculateLoan() suggests 5% for 2,000,000');
$overridden = $productModel->applyRate($calc, 4.0);
ok(abs($overridden['interest_rate'] - 4.0) < 0.0001, 'applyRate() sets the requested rate');
ok(abs($overridden['monthly_interest'] - (2000000 * 0.04)) < 0.01, 'applyRate() recomputes monthly_interest for the new rate');
ok(abs($overridden['total_payable'] - (2000000 + 2000000 * 0.04 * 3)) < 0.01, 'applyRate() recomputes total_payable for the new rate');
ok($overridden['loan_amount'] == $calc['loan_amount'], 'applyRate() leaves loan_amount unchanged');

// ================================================================
// SECTION 3: End-to-end via loan-add -- no override submitted
// ================================================================
echo "\n=== SECTION 3: No override submitted -- approved rate defaults to suggested ===\n";
renderAs('loans_officer', '1', 'loan-add', [
    'member_id' => '2', 'loan_type_id' => '1', 'loan_amount' => '2000000',
    'loan_period' => '3 Months', 'issue_date' => date('Y-m-d'),
    'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly', 'disbursement_method' => 'Cash',
    // approved_interest_rate intentionally omitted
]);
$loan1 = $db->query("SELECT * FROM loans WHERE member_id=2 AND loan_amount=2000000 AND loan_type_id=1 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$loan1, 'Loan created with no approved_interest_rate submitted');
if ($loan1) {
    ok(abs((float)$loan1['interest_rate'] - 5.0) < 0.0001, 'interest_rate defaults to the suggested 5%');
    ok(abs((float)$loan1['suggested_interest_rate'] - 5.0) < 0.0001, 'suggested_interest_rate is recorded as 5%');
    ok((int)$loan1['rate_overridden'] === 0, 'rate_overridden is 0 (no override)');
    ok($loan1['rate_override_by'] === null, 'rate_override_by is NULL');
}

// ================================================================
// SECTION 4: Explicit override, different from suggestion
// ================================================================
echo "\n=== SECTION 4: Explicit override, different from suggestion ===\n";
renderAs('loans_officer', '1', 'loan-add', [
    'member_id' => '3', 'loan_type_id' => '1', 'loan_amount' => '2000000',
    'loan_period' => '3 Months', 'issue_date' => date('Y-m-d'),
    'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly', 'disbursement_method' => 'Cash',
    'approved_interest_rate' => '4', 'rate_override_reason' => 'Negotiated rate approved by Chairman',
]);
$loan2 = $db->query("SELECT * FROM loans WHERE member_id=3 AND loan_amount=2000000 AND loan_type_id=1 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$loan2, 'Loan created with an explicit override');
if ($loan2) {
    ok(abs((float)$loan2['interest_rate'] - 4.0) < 0.0001, 'interest_rate (final) is the approved 4%');
    ok(abs((float)$loan2['suggested_interest_rate'] - 5.0) < 0.0001, 'suggested_interest_rate still records the original 5% suggestion');
    ok((int)$loan2['rate_overridden'] === 1, 'rate_overridden is 1');
    ok((int)$loan2['rate_override_by'] === 1, 'rate_override_by records the acting user');
    ok($loan2['rate_override_reason'] === 'Negotiated rate approved by Chairman', 'rate_override_reason is recorded verbatim');
    ok(abs((float)$loan2['interest_amount'] - (2000000 * 0.04 * 3)) < 0.01, 'interest_amount reflects the APPROVED (4%), not suggested, rate');
    ok(abs((float)$loan2['total_payable'] - (2000000 + 2000000 * 0.04 * 3)) < 0.01, 'total_payable reflects the approved rate');
}

// ================================================================
// SECTION 5: Submitted approved rate EQUALS the suggestion -- not an override
// ================================================================
echo "\n=== SECTION 5: Approved rate equal to suggestion is not flagged as an override ===\n";
renderAs('loans_officer', '1', 'loan-add', [
    'member_id' => '4', 'loan_type_id' => '1', 'loan_amount' => '2000000',
    'loan_period' => '3 Months', 'issue_date' => date('Y-m-d'),
    'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly', 'disbursement_method' => 'Cash',
    'approved_interest_rate' => '5',
]);
$loan3 = $db->query("SELECT * FROM loans WHERE member_id=4 AND loan_amount=2000000 AND loan_type_id=1 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$loan3, 'Loan created with approved rate equal to suggestion');
if ($loan3) {
    ok((int)$loan3['rate_overridden'] === 0, 'rate_overridden is 0 when approved equals suggested');
}

// ================================================================
// SECTION 6: Security -- fabricated/manipulated POST values ignored
// ================================================================
echo "\n=== SECTION 6: Manipulated POST values are independently re-validated ===\n";
renderAs('loans_officer', '1', 'loan-add', [
    'member_id' => '6', 'loan_type_id' => '1', 'loan_amount' => '2000000',
    'loan_period' => '3 Months', 'issue_date' => date('Y-m-d'),
    'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly', 'disbursement_method' => 'Cash',
    // Fabricated suggested_interest_rate -- this key does not exist in
    // collectInput()'s POST reads at all; confirm it has zero effect.
    'suggested_interest_rate' => '0.01',
    'interest_rate' => '0.01', // the OLD field name -- must also have no effect (Stage 9 guarantee, re-confirmed)
    'approved_interest_rate' => '4',
]);
$loan4 = $db->query("SELECT * FROM loans WHERE member_id=6 AND loan_amount=2000000 AND loan_type_id=1 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$loan4, 'Loan created despite fabricated suggested_interest_rate/interest_rate POST fields');
if ($loan4) {
    ok(abs((float)$loan4['suggested_interest_rate'] - 5.0) < 0.0001, 'suggested_interest_rate is server-recalculated (5%), fabricated 0.01% ignored');
    ok(abs((float)$loan4['interest_rate'] - 4.0) < 0.0001, 'interest_rate is the genuine approved_interest_rate (4%), old interest_rate field ignored');
}

// A negative/garbage approved rate must not be silently accepted as the final rate.
renderAs('loans_officer', '1', 'loan-add', [
    'member_id' => '7', 'loan_type_id' => '1', 'loan_amount' => '2000000',
    'loan_period' => '3 Months', 'issue_date' => date('Y-m-d'),
    'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly', 'disbursement_method' => 'Cash',
    'approved_interest_rate' => '-5',
]);
$loan5 = $db->query("SELECT * FROM loans WHERE member_id=7 AND loan_amount=2000000 AND loan_type_id=1 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$loan5, 'Loan created with a negative approved_interest_rate submitted');
if ($loan5) {
    ok(abs((float)$loan5['interest_rate'] - 5.0) < 0.0001, 'A non-positive approved rate is ignored -- falls back to the suggested 5%');
    ok((int)$loan5['rate_overridden'] === 0, 'No override recorded for a rejected (non-positive) approved rate');
}

// ================================================================
// SECTION 7: Different products use their own independent rules
// ================================================================
echo "\n=== SECTION 7: Product-specific rules remain independent ===\n";
renderAs('loans_officer', '1', 'loan-add', [
    'member_id' => '3', 'loan_type_id' => '3', 'loan_amount' => '10000000',
    'loan_period' => '12 Months', 'issue_date' => date('Y-m-d'),
    'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly', 'disbursement_method' => 'Cash',
    'income_source' => 'salary', 'income_details' => 'ABC Ltd',
    'asset_purchase_price' => '10000000', 'member_contribution' => '3000000',
    'security_type' => 'asset', 'security_description' => 'The financed vehicle',
]);
$assetLoan = $db->query("SELECT * FROM loans WHERE loan_type_id=3 AND member_id=3 AND loan_amount=10000000 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$assetLoan, 'Asset Financing loan created (independent eligibility gates from Stage 9 still enforced)');
if ($assetLoan) {
    ok(abs((float)$assetLoan['suggested_interest_rate'] - 2.0) < 0.0001, 'Asset Financing suggests its own 2% flat rate, not Normal Loan\'s bracket table');
}

// ================================================================
// SECTION 8: Non-regression -- historical loans and repayments untouched
// ================================================================
echo "\n=== SECTION 8: Non-regression ===\n";
$historicalCheck = $db->query("SELECT id FROM loans WHERE id IN (14,16,17,18,19,21,27,28,31,34,35,37)")->fetchAll();
ok(count($historicalCheck) === 12, 'All 12 original historical loans still present');
$repaymentCount = (int)$db->query("SELECT COUNT(*) FROM loan_repayments")->fetchColumn();
ok($repaymentCount === 40, 'loan_repayments row count unchanged (40)');

echo "\n=== SUMMARY ===\n";
echo "PASS: $pass\n";
echo "FAIL: $fail\n";
