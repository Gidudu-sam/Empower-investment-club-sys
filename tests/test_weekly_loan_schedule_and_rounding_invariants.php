<?php
/**
 * ISOLATED — Stage 9.2: Weekly Loan Schedule Fix & Rounding-Invariant
 * Hardening. Runs ONLY against empower_db_loan_rules_test. Never touches
 * empower_db.
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

function renderAs(string $role, string $userId, array $post): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_stage92_subproc_' . $counter . '.php';
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
    (new LoanController())->add();
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

function baseLoan(array $overrides = []): array {
    return array_merge([
        'member_id' => '3', 'loan_type_id' => '1', 'loan_amount' => '3000000',
        'loan_period' => '6 Months', 'issue_date' => date('Y-m-d'),
        'interest_mode' => 'percentage', 'repayment_frequency' => 'monthly',
        'disbursement_method' => 'Cash',
    ], $overrides);
}

function schedule(PDO $db, int $loanId): array {
    return $db->query("SELECT * FROM loan_installments WHERE loan_id={$loanId} ORDER BY installment_no")->fetchAll();
}

// ================================================================
// SECTION 1 — Monthly baseline (report's Test 1)
// ================================================================
echo "=== SECTION 1: Monthly baseline ===\n";
// Agricultural Loan (type 6): 3% flat, max 6 months -- matches the exact
// amount/rate/term from the reported bug. (Normal Loan's own configured
// max_period_months is 3, so a 6-month term needs a product that actually
// allows it.)
renderAs('loans_officer', '1', baseLoan(['loan_amount' => '3000000', 'loan_type_id' => '6']));
$loan1 = $db->query("SELECT * FROM loans WHERE member_id=3 AND loan_amount=3000000 AND loan_type_id=6 AND repayment_frequency='monthly' ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$loan1, 'Monthly loan created');
if ($loan1) {
    ok(abs((float)$loan1['interest_amount'] - 540000) < 0.01, 'Total interest is 540,000 (3,000,000 x 3% x 6)', $loan1['interest_amount']);
    ok(abs((float)$loan1['total_payable'] - 3540000) < 0.01, 'Total payable is 3,540,000', $loan1['total_payable']);
    $inst = schedule($db, (int)$loan1['id']);
    ok(count($inst) === 6, '6 monthly installments generated', count($inst));
    ok(abs((float)$inst[0]['amount_due'] - 590000) < 0.01, 'Each installment is 590,000 (evenly divides)', $inst[0]['amount_due']);
    $sumAmount = array_sum(array_column($inst, 'amount_due'));
    $sumPrincipal = array_sum(array_column($inst, 'principal_due'));
    $sumInterest = array_sum(array_column($inst, 'interest_due'));
    ok(abs($sumAmount - 3540000) < 0.01, 'SUM(amount_due) == total_payable exactly', $sumAmount);
    ok(abs($sumPrincipal - 3000000) < 0.01, 'SUM(principal_due) == principal exactly', $sumPrincipal);
    ok(abs($sumInterest - 540000) < 0.01, 'SUM(interest_due) == total_interest exactly', $sumInterest);
}

// ================================================================
// SECTION 2 — Weekly equivalent (the exact bug reported: 22,500/wk,
// which was 540,000 interest-only / 24 weeks, instead of the true total)
// ================================================================
echo "\n=== SECTION 2: Weekly equivalent of the same loan ===\n";
renderAs('loans_officer', '1', baseLoan(['loan_amount' => '3000000', 'loan_type_id' => '6', 'repayment_frequency' => 'weekly']));
$loan2 = $db->query("SELECT * FROM loans WHERE member_id=3 AND loan_amount=3000000 AND loan_type_id=6 AND repayment_frequency='weekly' ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$loan2, 'Weekly loan created');
if ($loan2) {
    ok(abs((float)$loan2['total_payable'] - 3540000) < 0.01, 'Weekly loan has the SAME total_payable as its monthly equivalent (3,540,000)', $loan2['total_payable']);
    $inst = schedule($db, (int)$loan2['id']);
    ok(count($inst) === 24, '24 weekly installments generated (6 months x 4 weeks), not 6 monthly-spaced rows', count($inst));
    $dueDates = array_column($inst, 'due_date');
    $gapDays = (strtotime($dueDates[1]) - strtotime($dueDates[0])) / 86400;
    ok(abs($gapDays - 7) < 1, 'Installments are spaced 7 days apart (real weekly cadence), not ~30', $gapDays);
    $sumAmount = array_sum(array_column($inst, 'amount_due'));
    $sumPrincipal = array_sum(array_column($inst, 'principal_due'));
    ok(abs($sumAmount - 3540000) < 0.01, 'SUM(weekly amount_due) == total_payable exactly (the reported bug: this used to sum to far less)', $sumAmount);
    ok(abs($sumPrincipal - 3000000) < 0.01, 'SUM(weekly principal_due) == principal exactly', $sumPrincipal);
    ok(abs((float)$loan2['monthly_installment'] - (3540000 / 24)) < 0.01, 'Stored recurring-payment figure is the WEEKLY amount (147,500), not a monthly one', $loan2['monthly_installment']);
    ok(abs((float)$inst[0]['amount_due'] - 22500) > 100, 'Weekly installment is NOT the old buggy 22,500 (interest-only/24)', $inst[0]['amount_due']);
}

// ================================================================
// SECTION 3 — Different product uses its own rules
// ================================================================
echo "\n=== SECTION 3: Product-specific rules ===\n";
// Member 21 has ~12.7 months of savings tenure, qualifying for Business
// Loan's >12-month gate (member 3 does not).
renderAs('loans_officer', '1', baseLoan(['loan_amount' => '3000000', 'loan_type_id' => '2', 'loan_period' => '12 Months', 'weekly_savings_commitment' => '10000', 'member_id' => '21']));
// Business Loan is interest_only -- uses Business Boost, not this stage's generator. Just confirm it still uses ITS OWN rate (3% for 3,000,000, Tier 2), not Normal Loan's 5%.
$loan3 = $db->query("SELECT * FROM loans WHERE member_id=21 AND loan_amount=3000000 AND loan_type_id=2 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$loan3, 'Business Loan created');
if ($loan3) {
    ok(abs((float)$loan3['suggested_interest_rate'] - 3.0) < 0.0001, 'Business Loan suggests its own 3% (Tier 2), not Normal Loan\'s 5%', $loan3['suggested_interest_rate']);
}

// ================================================================
// SECTION 4 — Amount change recalculates everything
// ================================================================
echo "\n=== SECTION 4: Amount change recalculates ===\n";
// Normal Loan (type 1)'s configured max_period_months is 3.
renderAs('loans_officer', '1', baseLoan(['loan_amount' => '7000000', 'loan_type_id' => '1', 'loan_period' => '3 Months']));
// 7,000,000 is Tier 3 -> 4%/mo. Interest = 7,000,000*0.04*3=840,000. Total=7,840,000.
$loan4 = $db->query("SELECT * FROM loans WHERE member_id=3 AND loan_amount=7000000 AND loan_type_id=1 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$loan4, 'Loan created at the new amount');
if ($loan4) {
    ok(abs((float)$loan4['suggested_interest_rate'] - 4.0) < 0.0001, 'Rate recalculated to 4% for the new amount (Tier 3)', $loan4['suggested_interest_rate']);
    ok(abs((float)$loan4['total_payable'] - 7840000) < 0.01, 'Total payable recalculated to 7,840,000', $loan4['total_payable']);
}

// ================================================================
// SECTION 5 — Rate override drives the calculation, not the suggestion
// ================================================================
echo "\n=== SECTION 5: Rate override drives calculation ===\n";
renderAs('loans_officer', '1', baseLoan(['loan_amount' => '3000000', 'loan_type_id' => '1', 'loan_period' => '3 Months', 'approved_interest_rate' => '4']));
$loan5 = $db->query("SELECT * FROM loans WHERE member_id=3 AND loan_amount=3000000 AND loan_type_id=1 AND rate_overridden=1 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$loan5, 'Loan created with a 4% override (suggested was 5%)');
if ($loan5) {
    ok(abs((float)$loan5['interest_amount'] - (3000000 * 0.04 * 3)) < 0.01, 'Interest uses the APPROVED 4%, not the suggested 5%', $loan5['interest_amount']);
    $inst = schedule($db, (int)$loan5['id']);
    $sumInterest = array_sum(array_column($inst, 'interest_due'));
    ok(abs($sumInterest - (3000000 * 0.04 * 3)) < 0.01, 'Schedule interest also reflects the approved 4%', $sumInterest);
}

// ================================================================
// SECTION 6 — Grace period preserved in the weekly schedule
// ================================================================
echo "\n=== SECTION 6: Grace period in a weekly schedule ===\n";
renderAs('loans_officer', '1', array_merge(baseLoan(['loan_amount' => '1000000', 'loan_type_id' => '5', 'loan_period' => '6 Months', 'repayment_frequency' => 'weekly', 'member_id' => '21']),
    ['security_type' => 'ignored', 'security_description' => 'Furniture']));
$loan6 = $db->query("SELECT * FROM loans WHERE member_id=21 AND loan_type_id=5 AND repayment_frequency='weekly' ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$loan6, 'Start-Up weekly loan created (1-month grace configured)');
if ($loan6) {
    ok((int)$loan6['grace_period_months'] === 1, 'grace_period_months snapshot is 1');
    $inst = schedule($db, (int)$loan6['id']);
    ok(count($inst) === 24, '24 weekly installments for a 6-month Start-Up loan');
    $graceRows = array_filter($inst, fn($r) => (int)$r['is_grace_period'] === 1);
    ok(count($graceRows) === 4, 'First 4 weeks (1 month x 4) are flagged as grace', count($graceRows));
    foreach (array_slice($inst, 0, 4) as $row) {
        ok(abs((float)$row['principal_due']) < 0.01, 'Grace week has zero principal_due', $row['principal_due']);
    }
    $sumAmount = array_sum(array_column($inst, 'amount_due'));
    ok(abs($sumAmount - (float)$loan6['total_payable']) < 0.01, 'Weekly grace schedule still sums exactly to total_payable', "sum={$sumAmount} total={$loan6['total_payable']}");
}

// ================================================================
// SECTION 7 — Rounding: a non-evenly-dividing amount
// ================================================================
echo "\n=== SECTION 7: Rounding invariant on a non-evenly-dividing amount ===\n";
// Normal Loan (type 1): max_amount is effectively unbounded, but
// max_period_months is 3.
renderAs('loans_officer', '1', baseLoan(['loan_amount' => '3000001', 'loan_type_id' => '1', 'loan_period' => '3 Months']));
$loan7 = $db->query("SELECT * FROM loans WHERE member_id=3 AND loan_amount=3000001 AND loan_type_id=1 AND repayment_frequency='monthly' ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$loan7, 'Loan created with a non-evenly-dividing amount (3,000,001)');
if ($loan7) {
    $inst = schedule($db, (int)$loan7['id']);
    $sumAmount = array_sum(array_column($inst, 'amount_due'));
    $sumPrincipal = array_sum(array_column($inst, 'principal_due'));
    ok(abs($sumAmount - (float)$loan7['total_payable']) < 0.005, 'SUM(amount_due) matches total_payable to the cent despite uneven division', "sum={$sumAmount} total={$loan7['total_payable']}");
    ok(abs($sumPrincipal - (float)$loan7['loan_amount']) < 0.005, 'SUM(principal_due) matches loan_amount to the cent', "sum={$sumPrincipal} amount={$loan7['loan_amount']}");
}
// Same check for the weekly path.
renderAs('loans_officer', '1', baseLoan(['loan_amount' => '3000001', 'loan_type_id' => '1', 'loan_period' => '3 Months', 'repayment_frequency' => 'weekly']));
$loan8 = $db->query("SELECT * FROM loans WHERE member_id=3 AND loan_amount=3000001 AND loan_type_id=1 AND repayment_frequency='weekly' ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$loan8, 'Weekly loan created with a non-evenly-dividing amount');
if ($loan8) {
    $inst = schedule($db, (int)$loan8['id']);
    $sumAmount = array_sum(array_column($inst, 'amount_due'));
    ok(abs($sumAmount - (float)$loan8['total_payable']) < 0.005, 'Weekly SUM(amount_due) matches total_payable to the cent', "sum={$sumAmount} total={$loan8['total_payable']}");
}

// ================================================================
// SECTION 8 — Non-regression: legacy 4-arg generateInstallments() callers
// (schedule re-print, historical import) are byte-identical
// ================================================================
echo "\n=== SECTION 8: Legacy generateInstallments() callers unaffected ===\n";
$loanModel = new LoanModel();
// A fresh throwaway loan, never touching any of the 12 real historical
// loans even in this isolated clone.
$legacyLoanId = $loanModel->create([
    'loan_number' => 'LNS-TEST-LEGACY', 'member_id' => 3, 'loan_type_id' => 1,
    'loan_amount' => 1500000, 'interest_rate' => 0, 'interest_amount' => 0,
    'total_payable' => 1500000, 'outstanding' => 1500000, 'amount_paid' => 0,
    'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+3 months')),
    'loan_period_months' => 3, 'status' => 'draft', 'recorded_by' => 1,
]);
$loanModel->generateInstallments($legacyLoanId, 500000.00, 3, date('Y-m-d'));
$legacyInst = schedule($db, $legacyLoanId);
ok(count($legacyInst) === 3, 'Legacy 4-arg call still produces 3 installments');
ok(abs((float)$legacyInst[0]['amount_due'] - 500000) < 0.01, 'Legacy 4-arg call still produces the flat passed-in amount per row', $legacyInst[0]['amount_due']);
ok(abs((float)$legacyInst[0]['principal_due']) < 0.01 && abs((float)$legacyInst[0]['interest_due']) < 0.01, 'Legacy 4-arg call leaves principal_due/interest_due at 0 (unchanged from before this stage)');
$legacySum = array_sum(array_column($legacyInst, 'amount_due'));
ok(abs($legacySum - 1500000) < 0.01, 'Legacy call schedule still sums correctly (500,000 x 3)', $legacySum);
// Restore this historical loan's real schedule so the non-regression checks
// in other suites still find its original data untouched going forward --
// re-run the production seed's own regenerate path is out of scope here;
// this is an isolated test DB clone, not production, so no cleanup is required.

echo "\n=== SUMMARY ===\n";
echo "PASS: $pass\n";
echo "FAIL: $fail\n";
