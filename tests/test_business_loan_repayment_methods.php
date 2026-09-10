<?php
/**
 * ISOLATED — Stage 9.2-B: Fix Repayment Method Dispatch & Implement Real
 * Business Boost. Runs ONLY against empower_db_loan_rules_test. Never
 * touches empower_db.
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
    $file = __DIR__ . '/tmp_stage92b_subproc_' . $counter . '.php';
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

function businessLoan(array $overrides = []): array {
    return array_merge([
        'member_id' => '21', 'loan_type_id' => '2', 'loan_amount' => '3000000',
        'loan_period' => '6 Months', 'issue_date' => date('Y-m-d'),
        'interest_mode' => 'percentage', 'repayment_frequency' => 'weekly',
        'disbursement_method' => 'Cash', 'weekly_savings_commitment' => '10000',
    ], $overrides);
}

function schedule(PDO $db, int $loanId): array {
    return $db->query("SELECT * FROM loan_installments WHERE loan_id={$loanId} ORDER BY installment_no")->fetchAll();
}

// ================================================================
// SECTION 1 — "Interest Only (Standard)" unchanged
// ================================================================
echo "=== SECTION 1: Interest Only (Standard) unchanged ===\n";
renderAs('loans_officer', '1', businessLoan(['repayment_method' => 'interest_only']));
$loanA = $db->query("SELECT * FROM loans WHERE loan_type_id=2 AND member_id=21 AND repayment_method='interest_only' AND loan_amount=3000000 AND loan_period_months=6 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$loanA, 'Loan created with repayment_method=interest_only');
if ($loanA) {
    ok($loanA['repayment_method'] === 'interest_only', 'Persisted repayment_method is "interest_only" (previously always forced to business_boost)', $loanA['repayment_method']);
    $inst = schedule($db, (int)$loanA['id']);
    ok(count($inst) === 12, '12 installments (4 monthly interest-only + 8 weekly recovery)', count($inst));
    $monthlyRows = array_filter($inst, fn($r) => $r['period_type'] === 'monthly');
    $weeklyRows = array_filter($inst, fn($r) => $r['period_type'] === 'weekly');
    ok(count($monthlyRows) === 4, '4 monthly interest-only installments');
    ok(count($weeklyRows) === 8, '8 weekly recovery installments');
    foreach ($monthlyRows as $r) {
        ok(abs((float)$r['principal_due']) < 0.01, 'Interest-only month has zero principal_due', $r['principal_due']);
    }
    $sumAmount = array_sum(array_column($inst, 'amount_due'));
    $sumPrincipal = array_sum(array_column($inst, 'principal_due'));
    ok(abs($sumAmount - (float)$loanA['total_payable']) < 0.01, 'SUM(amount_due) == total_payable', "sum={$sumAmount} total={$loanA['total_payable']}");
    ok(abs($sumPrincipal - (float)$loanA['loan_amount']) < 0.01, 'SUM(principal_due) == loan_amount', "sum={$sumPrincipal} amount={$loanA['loan_amount']}");
}

// ================================================================
// SECTION 2 — "Business Boost" now genuinely different
// ================================================================
echo "\n=== SECTION 2: Business Boost is now genuinely different ===\n";
// Member 21 is the only test-DB member with sufficient (>12mo) savings
// tenure to qualify for Business Loan; reused across this file's cases,
// disambiguated by repayment_method/amount in each query -- the 12
// historical production loans already occupy every other member id that
// has any Business Loan history.
renderAs('loans_officer', '1', businessLoan(['repayment_method' => 'business_boost', 'member_id' => '21']));
$loanB = $db->query("SELECT * FROM loans WHERE loan_type_id=2 AND member_id=21 AND repayment_method='business_boost' AND loan_amount=3000000 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$loanB, 'Loan created with repayment_method=business_boost');
if ($loanB) {
    ok($loanB['repayment_method'] === 'business_boost', 'Persisted repayment_method is "business_boost"', $loanB['repayment_method']);
    $inst = schedule($db, (int)$loanB['id']);
    ok(count($inst) === 24, '24 weekly installments (6 months x 4), not 12 (no fixed 8-week window)', count($inst));
    // Every single installment, starting with #1, must carry both principal and interest.
    $firstRow = $inst[0];
    ok((float)$firstRow['principal_due'] > 0, 'Installment #1 already carries principal (no deferred period)', $firstRow['principal_due']);
    ok((float)$firstRow['interest_due'] > 0, 'Installment #1 carries interest too', $firstRow['interest_due']);
    $zeroPrincipalRows = array_filter($inst, fn($r) => abs((float)$r['principal_due']) < 0.01);
    ok(count($zeroPrincipalRows) === 0, 'No installment anywhere has zero principal_due (no interest-only phase at all)', count($zeroPrincipalRows));
    $sumAmount = array_sum(array_column($inst, 'amount_due'));
    $sumPrincipal = array_sum(array_column($inst, 'principal_due'));
    ok(abs($sumAmount - (float)$loanB['total_payable']) < 0.01, 'SUM(amount_due) == total_payable exactly', "sum={$sumAmount} total={$loanB['total_payable']}");
    ok(abs($sumPrincipal - (float)$loanB['loan_amount']) < 0.01, 'SUM(principal_due) == loan_amount exactly', "sum={$sumPrincipal} amount={$loanB['loan_amount']}");
}

// ================================================================
// SECTION 3 — The two methods now produce genuinely different schedules
// for otherwise-identical loans (this is the exact audit reproduction)
// ================================================================
echo "\n=== SECTION 3: The two methods now differ (audit reproduction) ===\n";
if ($loanA && $loanB) {
    ok((int)$loanA['loan_amount'] === (int)$loanB['loan_amount'], 'Both loans have the identical principal (sanity check)');
    $instA = schedule($db, (int)$loanA['id']);
    $instB = schedule($db, (int)$loanB['id']);
    ok(count($instA) !== count($instB), 'Different installment counts (12 vs 24) -- previously identical (both 12)', count($instA) . ' vs ' . count($instB));
    ok(abs((float)$instA[0]['principal_due'] - (float)$instB[0]['principal_due']) > 1, 'Installment #1 principal differs sharply between the two methods (0 vs a real share of principal)');
}

// ================================================================
// SECTION 4 — printSchedule() regeneration agrees with creation-time
// generation for both methods
// ================================================================
echo "\n=== SECTION 4: printSchedule() regeneration agrees ===\n";
foreach ([$loanA, $loanB] as $loan) {
    if (!$loan) continue;
    $before = schedule($db, (int)$loan['id']);
    $db->prepare("DELETE FROM loan_installments WHERE loan_id=?")->execute([$loan['id']]);
    $file = __DIR__ . '/tmp_stage92b_print_' . $loan['id'] . '.php';
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
Session::set('user_id', 1);
Session::set('user_role', 'admin');
Session::set('last_activity', time());
\$_GET['id'] = {$loan['id']};
ob_start();
(new LoanController())->printSchedule();
ob_end_clean();
PHP;
    file_put_contents($file, $body);
    shell_exec('"' . PHP_BINARY . '" "' . $file . '" 2>&1');
    @unlink($file);
    $after = schedule($db, (int)$loan['id']);
    ok(count($after) === count($before), "printSchedule() regenerates the same installment count for repayment_method={$loan['repayment_method']}", count($before) . ' vs ' . count($after));
    $sumBefore = array_sum(array_column($before, 'amount_due'));
    $sumAfter = array_sum(array_column($after, 'amount_due'));
    ok(abs($sumBefore - $sumAfter) < 0.01, "printSchedule() regenerated total matches the original for repayment_method={$loan['repayment_method']}", "{$sumBefore} vs {$sumAfter}");
    if (count($after) > 0) {
        ok(abs((float)$after[0]['principal_due'] - (float)$before[0]['principal_due']) < 0.01, "Regenerated installment #1 principal matches original for repayment_method={$loan['repayment_method']}");
    }
}

// ================================================================
// SECTION 5 — Non-regression: Interest Only (Standard) math is byte-
// identical to the audit's own recorded baseline (Stage 9.2-A)
// ================================================================
echo "\n=== SECTION 5: Non-regression against the audit baseline ===\n";
if ($loanA) {
    ok(abs((float)$loanA['interest_amount'] - 540000) < 0.01, 'Total interest still 540,000 (unchanged from the audit baseline)', $loanA['interest_amount']);
    ok(abs((float)$loanA['total_payable'] - 3540000) < 0.01, 'Total payable still 3,540,000', $loanA['total_payable']);
}

// ================================================================
// SECTION 7 — Rounding: a non-evenly-dividing amount, both methods
// ================================================================
echo "\n=== SECTION 7: Rounding invariant on a non-evenly-dividing amount ===\n";
renderAs('loans_officer', '1', businessLoan(['repayment_method' => 'interest_only', 'loan_amount' => '3000001', 'member_id' => '21']));
$loanC = $db->query("SELECT * FROM loans WHERE loan_type_id=2 AND member_id=21 AND repayment_method='interest_only' AND loan_amount=3000001 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$loanC, 'Interest Only loan created with a non-evenly-dividing amount (3,000,001)');
if ($loanC) {
    $inst = schedule($db, (int)$loanC['id']);
    $sumAmount = array_sum(array_column($inst, 'amount_due'));
    $sumPrincipal = array_sum(array_column($inst, 'principal_due'));
    ok(abs($sumAmount - (float)$loanC['total_payable']) < 0.01, 'Interest Only: SUM(amount_due) matches total_payable to the cent', "sum={$sumAmount} total={$loanC['total_payable']}");
    ok(abs($sumPrincipal - (float)$loanC['loan_amount']) < 0.01, 'Interest Only: SUM(principal_due) matches loan_amount to the cent', "sum={$sumPrincipal} amount={$loanC['loan_amount']}");
}
renderAs('loans_officer', '1', businessLoan(['repayment_method' => 'business_boost', 'loan_amount' => '3000001', 'member_id' => '21']));
$loanD = $db->query("SELECT * FROM loans WHERE loan_type_id=2 AND member_id=21 AND repayment_method='business_boost' AND loan_amount=3000001 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$loanD, 'Business Boost loan created with a non-evenly-dividing amount (3,000,001)');
if ($loanD) {
    $inst = schedule($db, (int)$loanD['id']);
    $sumAmount = array_sum(array_column($inst, 'amount_due'));
    $sumPrincipal = array_sum(array_column($inst, 'principal_due'));
    ok(abs($sumAmount - (float)$loanD['total_payable']) < 0.01, 'Business Boost: SUM(amount_due) matches total_payable to the cent', "sum={$sumAmount} total={$loanD['total_payable']}");
    ok(abs($sumPrincipal - (float)$loanD['loan_amount']) < 0.01, 'Business Boost: SUM(principal_due) matches loan_amount to the cent', "sum={$sumPrincipal} amount={$loanD['loan_amount']}");
    $lastRow = end($inst);
    ok((float)$lastRow['principal_due'] > 0, 'Business Boost: last installment still carries a positive principal share (remainder absorbed, not zeroed)', $lastRow['principal_due']);
}

// ================================================================
// SECTION 8 — Smaller/larger amounts and a different term
// ================================================================
echo "\n=== SECTION 8: Other amounts/terms ===\n";
renderAs('loans_officer', '1', businessLoan(['repayment_method' => 'business_boost', 'loan_amount' => '150000', 'member_id' => '21']));
$loanE = $db->query("SELECT * FROM loans WHERE loan_type_id=2 AND member_id=21 AND loan_amount=150000 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$loanE, 'Small Business Boost loan (150,000) created');
if ($loanE) {
    $inst = schedule($db, (int)$loanE['id']);
    $sumPrincipal = array_sum(array_column($inst, 'principal_due'));
    ok(abs($sumPrincipal - 150000) < 0.01, 'Small loan: SUM(principal_due) reconciles exactly', $sumPrincipal);
}
renderAs('loans_officer', '1', businessLoan(['repayment_method' => 'business_boost', 'loan_amount' => '3000000', 'loan_period' => '8 Months', 'member_id' => '21']));
$loanF = $db->query("SELECT * FROM loans WHERE loan_type_id=2 AND member_id=21 AND loan_period_months=8 ORDER BY id DESC LIMIT 1")->fetch();
ok((bool)$loanF, 'Business Boost loan with an 8-month term created');
if ($loanF) {
    $inst = schedule($db, (int)$loanF['id']);
    ok(count($inst) === 32, '8-month term produces 32 weekly installments (8x4)', count($inst));
    $sumAmount = array_sum(array_column($inst, 'amount_due'));
    ok(abs($sumAmount - (float)$loanF['total_payable']) < 0.01, 'Reconciles exactly for a non-6-month term', "sum={$sumAmount} total={$loanF['total_payable']}");
}

// ================================================================
// SECTION 9 — Repayment posting: principal reduces outstanding, no
// negative balance, journal posts, for BOTH methods
// ================================================================
echo "\n=== SECTION 9: Repayment posting ===\n";
require_once 'app/models/RepaymentModel.php';
$repaymentModel = new RepaymentModel();
foreach (['A' => $loanA, 'B' => $loanB] as $label => $loan) {
    if (!$loan) continue;
    $before = (float)$db->query("SELECT outstanding FROM loans WHERE id={$loan['id']}")->fetchColumn();
    $payAmount = 100000.00;
    try {
        $repaymentModel->recordRepayment([
            'repayment_number' => 'TEST-REP-' . $loan['id'],
            'loan_id' => $loan['id'], 'member_id' => $loan['member_id'],
            'payment_date' => date('Y-m-d'), 'amount_paid' => $payAmount,
            'payment_method' => 'Cash', 'received_by' => 1,
        ]);
        $after = (float)$db->query("SELECT outstanding FROM loans WHERE id={$loan['id']}")->fetchColumn();
        ok(abs(($before - $after) - $payAmount) < 0.01, "Loan {$label} ({$loan['repayment_method']}): outstanding reduced by the exact payment amount", "before={$before} after={$after}");
        ok($after >= 0, "Loan {$label} ({$loan['repayment_method']}): outstanding never negative", $after);
        $journaled = (int)$db->query("SELECT COUNT(*) FROM loan_repayments WHERE loan_id={$loan['id']} AND journal_entry_id IS NOT NULL")->fetchColumn();
        ok($journaled > 0, "Loan {$label} ({$loan['repayment_method']}): repayment posted a journal entry");
    } catch (Throwable $e) {
        ok(false, "Loan {$label} ({$loan['repayment_method']}): repayment recorded without error", $e->getMessage());
    }
}

// ================================================================
// SECTION 10 — Non-regression: historical loans and other suites' data untouched
// ================================================================
echo "\n=== SECTION 10: Non-regression ===\n";
$historicalCheck = $db->query("SELECT id FROM loans WHERE id IN (14,16,17,18,19,21,27,28,31,34,35,37)")->fetchAll();
ok(count($historicalCheck) === 12, 'All 12 original historical loans still present');
$repaymentCount = (int)$db->query("SELECT COUNT(*) FROM loan_repayments WHERE repayment_number NOT LIKE 'TEST-REP-%'")->fetchColumn();
ok($repaymentCount === 40, 'Pre-existing loan_repayments row count unchanged (40)', $repaymentCount);

echo "\n=== SUMMARY ===\n";
echo "PASS: $pass\n";
echo "FAIL: $fail\n";
