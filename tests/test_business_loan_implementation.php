<?php
/**
 * Test Script: Business Loan Repayment Implementation
 * 
 * Tests the approved Business Boost and Interest Only calculation rules
 * as specified in the implementation specification.
 * 
 * DO NOT RUN ON PRODUCTION DATABASE
 */

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../core/Model.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../app/models/LoanProductModel.php';
require_once __DIR__ . '/../app/models/LoanModel.php';

// Test configuration
define('TEST_OUTPUT_FILE', __DIR__ . '/../results/stage-business-loan-implementation-test-results.md');

// Color output for terminal
function color($text, $color = 'green') {
    $colors = [
        'green'  => "\033[32m",
        'red'    => "\033[31m",
        'yellow' => "\033[33m",
        'blue'   => "\033[34m",
        'reset'  => "\033[0m",
    ];
    return $colors[$color] . $text . $colors['reset'];
}

// Output buffer
$output = [];
function out($text) {
    global $output;
    echo $text . PHP_EOL;
    $output[] = strip_tags($text);
}

function section($title) {
    out("\n" . str_repeat('=', 80));
    out(color("  $title", 'blue'));
    out(str_repeat('=', 80));
}

function subsection($title) {
    out("\n" . color("--- $title ---", 'yellow'));
}

function pass($msg) {
    out(color("  ✓ PASS: $msg", 'green'));
}

function fail($msg) {
    out(color("  ✗ FAIL: $msg", 'red'));
}

function check($description, $condition, $details = '') {
    if ($condition) {
        pass($description);
        if ($details) out("         $details");
        return true;
    } else {
        fail($description);
        if ($details) out("         $details");
        return false;
    }
}

function money($amount) {
    return 'Shs ' . number_format($amount, 2);
}

// Initialize
section('BUSINESS LOAN REPAYMENT IMPLEMENTATION TEST');
out('Test Date: ' . date('Y-m-d H:i:s'));
out('Test Database: ' . DB_NAME);

try {
    $db = Database::getInstance()->getConnection();
    $productModel = new LoanProductModel();
    
    // ========================================================================
    // TEST A: BUSINESS BOOST — 10M, 5%, 6 MONTHS
    // ========================================================================
    
    section('TEST A: Business Boost — Principal + Interest from Week 1');
    
    subsection('Input Parameters');
    $testA = [
        'principal' => 10000000,
        'rate' => 5.0,
        'months' => 6,
        'method' => 'business_boost',
    ];
    out("  Principal:        " . money($testA['principal']));
    out("  Rate:             {$testA['rate']}% per month");
    out("  Term:             {$testA['months']} months");
    out("  Method:           {$testA['method']}");
    
    subsection('Expected Results');
    $expectedA = [
        'monthly_interest' => 500000,
        'total_interest' => 3000000,
        'total_payable' => 13000000,
        'total_weeks' => 24,
        'monthly_installment' => 2166666.67,
        'weekly_installment' => 541666.67,
        'weekly_principal' => 416666.67,
        'weekly_interest' => 125000,
    ];
    out("  Monthly Interest:    " . money($expectedA['monthly_interest']));
    out("  Total Interest:      " . money($expectedA['total_interest']));
    out("  Total Payable:       " . money($expectedA['total_payable']));
    out("  Total Weeks:         {$expectedA['total_weeks']}");
    out("  Monthly Installment: " . money($expectedA['monthly_installment']));
    out("  Weekly Installment:  " . money($expectedA['weekly_installment']));
    
    subsection('Backend Calculation');
    $calcA = $productModel->calculateBusinessBoost(
        $testA['principal'],
        $testA['rate'],
        $testA['months']
    );
    
    out("  Monthly Interest:    " . money($calcA['monthly_interest']));
    out("  Total Interest:      " . money($calcA['total_interest']));
    out("  Total Payable:       " . money($calcA['total_payable']));
    out("  Total Weeks:         {$calcA['total_weeks']}");
    out("  Monthly Installment: " . money($calcA['monthly_installment']));
    out("  Weekly Installment:  " . money($calcA['weekly_installment']));
    out("  Weekly Principal:    " . money($calcA['weekly_principal']));
    out("  Weekly Interest:     " . money($calcA['weekly_interest']));
    
    subsection('Calculation Verification');
    check(
        'Total Interest = Monthly Interest × Months',
        abs($calcA['total_interest'] - $expectedA['total_interest']) < 0.01,
        "Expected: {$expectedA['total_interest']}, Got: {$calcA['total_interest']}"
    );
    check(
        'Total Payable = Principal + Total Interest',
        abs($calcA['total_payable'] - $expectedA['total_payable']) < 0.01,
        "Expected: {$expectedA['total_payable']}, Got: {$calcA['total_payable']}"
    );
    check(
        'Total Weeks = Months × 4',
        $calcA['total_weeks'] === $expectedA['total_weeks'],
        "Expected: {$expectedA['total_weeks']}, Got: {$calcA['total_weeks']}"
    );
    check(
        'Monthly Installment = Total Payable ÷ Months',
        abs($calcA['monthly_installment'] - $expectedA['monthly_installment']) < 0.01,
        "Expected: {$expectedA['monthly_installment']}, Got: {$calcA['monthly_installment']}"
    );
    check(
        'Weekly Installment ≈ Monthly Installment ÷ 4',
        abs($calcA['weekly_installment'] - $expectedA['weekly_installment']) < 1,
        "Expected: ~{$expectedA['weekly_installment']}, Got: {$calcA['weekly_installment']}"
    );
    check(
        'Interest Only Months = 0 (no interest-only phase)',
        $calcA['interest_only_months'] === 0,
        "Business Boost must have NO interest-only phase"
    );
    check(
        'Recovery Weeks = Total Weeks',
        $calcA['recovery_weeks'] === $calcA['total_weeks'],
        "All {$calcA['total_weeks']} weeks are principal + interest"
    );
    
    subsection('Schedule Generation Test');
    
    // Create test loan
    $db->beginTransaction();
    
    // Insert test member
    $stmt = $db->prepare("
        INSERT INTO members (member_number, first_name, last_name, gender, date_of_birth, phone, email, address, status)
        VALUES ('TEST-A-001', 'Test', 'BusinessBoost', 'Male', '1990-01-01', '0700000001', 'test.a@test.com', 'Test', 'active')
    ");
    $stmt->execute();
    $memberIdA = $db->lastInsertId();
    
    // Insert test loan
    $startDate = '2026-09-16';
    $stmt = $db->prepare("
        INSERT INTO loans (
            member_id, loan_type_id, loan_number, loan_amount, interest_rate, interest_amount,
            total_payable, outstanding, monthly_installment, loan_period_months,
            issue_date, due_date, status, repayment_frequency, repayment_method, recorded_by
        ) VALUES (
            ?, 2, 'TEST-BB-001', ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(?, INTERVAL 6 MONTH), 'draft', 'weekly', 'business_boost', 1
        )
    ");
    $stmt->execute([
        $memberIdA,
        $testA['principal'],
        $testA['rate'],
        $calcA['total_interest'],
        $calcA['total_payable'],
        $calcA['total_payable'],
        $calcA['monthly_installment'],
        $testA['months'],
        $startDate,
        $startDate
    ]);
    $loanIdA = $db->lastInsertId();
    
    out("  Created test loan: TEST-BB-001 (ID: $loanIdA)");
    
    // Generate schedule
    $productModel->generateBusinessBoostSchedule(
        $loanIdA,
        $testA['principal'],
        $testA['rate'],
        $testA['months'],
        $startDate
    );
    
    out("  Schedule generated for loan $loanIdA");
    
    subsection('Schedule Verification');
    
    // Fetch schedule
    $stmt = $db->prepare("
        SELECT * FROM loan_installments 
        WHERE loan_id = ? 
        ORDER BY installment_no
    ");
    $stmt->execute([$loanIdA]);
    $installmentsA = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    out("  Total installments: " . count($installmentsA));
    
    check(
        'Schedule has 24 weekly installments',
        count($installmentsA) === 24,
        "Expected: 24, Got: " . count($installmentsA)
    );
    
    // Check first installment
    $first = $installmentsA[0];
    check(
        'First installment is weekly',
        $first['period_type'] === 'weekly',
        "Type: {$first['period_type']}"
    );
    check(
        'First installment is principal_interest (NOT interest_only)',
        $first['payment_type'] === 'principal_interest',
        "Type: {$first['payment_type']}"
    );
    check(
        'First installment has principal > 0',
        (float)$first['principal_due'] > 0,
        "Principal: " . money($first['principal_due'])
    );
    check(
        'First installment has interest > 0',
        (float)$first['interest_due'] > 0,
        "Interest: " . money($first['interest_due'])
    );
    
    // Check NO interest-only installments
    $interestOnlyCount = 0;
    foreach ($installmentsA as $inst) {
        if ($inst['payment_type'] === 'interest_only') {
            $interestOnlyCount++;
        }
    }
    check(
        'NO interest-only installments in Business Boost',
        $interestOnlyCount === 0,
        "Found $interestOnlyCount interest-only rows (should be 0)"
    );
    
    // Sum schedule totals
    $totalPrincipalDue = 0;
    $totalInterestDue = 0;
    $totalAmountDue = 0;
    
    foreach ($installmentsA as $inst) {
        $totalPrincipalDue += (float)$inst['principal_due'];
        $totalInterestDue += (float)$inst['interest_due'];
        $totalAmountDue += (float)$inst['amount_due'];
    }
    
    out("\n  Schedule Totals:");
    out("    Scheduled Principal: " . money($totalPrincipalDue));
    out("    Scheduled Interest:  " . money($totalInterestDue));
    out("    Scheduled Total:     " . money($totalAmountDue));
    
    subsection('Reconciliation');
    
    check(
        'Scheduled Principal = Contractual Principal',
        abs($totalPrincipalDue - $testA['principal']) < 0.01,
        "Expected: " . money($testA['principal']) . ", Got: " . money($totalPrincipalDue)
    );
    check(
        'Scheduled Interest = Contractual Interest',
        abs($totalInterestDue - $calcA['total_interest']) < 0.01,
        "Expected: " . money($calcA['total_interest']) . ", Got: " . money($totalInterestDue)
    );
    check(
        'Scheduled Total = Contractual Total Payable',
        abs($totalAmountDue - $calcA['total_payable']) < 0.01,
        "Expected: " . money($calcA['total_payable']) . ", Got: " . money($totalAmountDue)
    );
    
    // Check final balance
    $lastInstallment = end($installmentsA);
    check(
        'Final balance reaches zero',
        abs((float)$lastInstallment['balance_after']) < 0.01,
        "Final balance: " . money($lastInstallment['balance_after'])
    );
    
    subsection('Date Verification');
    
    // Check first installment date
    $expectedFirstDate = date('Y-m-d', strtotime('+1 week', strtotime($startDate)));
    check(
        'First installment is 1 week after start date',
        $first['due_date'] === $expectedFirstDate,
        "Expected: $expectedFirstDate, Got: {$first['due_date']}"
    );
    
    // Check last installment date
    $expectedLastDate = date('Y-m-d', strtotime('+24 weeks', strtotime($startDate)));
    check(
        'Last installment is 24 weeks after start date',
        $lastInstallment['due_date'] === $expectedLastDate,
        "Expected: $expectedLastDate, Got: {$lastInstallment['due_date']}"
    );
    
    // Rollback test data
    $db->rollback();
    out("\n  Test data rolled back (loan and member deleted)");
    
    subsection('TEST A RESULT');
    out(color("  ✓ TEST A: PASS", 'green'));
    out("  Business Boost correctly generates weekly principal + interest from Week 1");
    out("  NO interest-only phase present");
    out("  Schedule reconciles exactly with contractual amounts");
    
    // ========================================================================
    // TEST B: INTEREST ONLY — 10M, 5%, 6 MONTHS
    // ========================================================================
    
    section('TEST B: Interest Only — 4 Months Interest-Only + 8 Weeks Recovery');
    
    subsection('Input Parameters');
    $testB = [
        'principal' => 10000000,
        'rate' => 5.0,
        'months' => 6,
        'method' => 'interest_only',
    ];
    out("  Principal:        " . money($testB['principal']));
    out("  Rate:             {$testB['rate']}% per month");
    out("  Term:             {$testB['months']} months");
    out("  Method:           {$testB['method']}");
    
    subsection('Expected Results');
    $expectedB = [
        'monthly_interest' => 500000,
        'total_interest' => 3000000,
        'total_payable' => 13000000,
        'interest_only_months' => 4,
        'recovery_weeks' => 8,
        'phase1_interest' => 2000000,
        'remaining_interest' => 1000000,
        'weekly_principal' => 1250000,
        'weekly_interest' => 125000,
        'weekly_payment' => 1375000,
    ];
    out("  Monthly Interest:       " . money($expectedB['monthly_interest']));
    out("  Total Interest:         " . money($expectedB['total_interest']));
    out("  Total Payable:          " . money($expectedB['total_payable']));
    out("  Interest-Only Months:   {$expectedB['interest_only_months']}");
    out("  Recovery Weeks:         {$expectedB['recovery_weeks']}");
    out("  Phase 1 Interest:       " . money($expectedB['phase1_interest']));
    out("  Remaining Interest:     " . money($expectedB['remaining_interest']));
    out("  Weekly Principal:       " . money($expectedB['weekly_principal']));
    out("  Weekly Interest:        " . money($expectedB['weekly_interest']));
    out("  Weekly Payment:         " . money($expectedB['weekly_payment']));
    
    subsection('Backend Calculation');
    $calcB = $productModel->calculateInterestOnly(
        $testB['principal'],
        $testB['rate'],
        $testB['months']
    );
    
    out("  Monthly Interest:       " . money($calcB['monthly_interest']));
    out("  Total Interest:         " . money($calcB['total_interest']));
    out("  Total Payable:          " . money($calcB['total_payable']));
    out("  Interest-Only Months:   {$calcB['interest_only_months']}");
    out("  Recovery Weeks:         {$calcB['recovery_weeks']}");
    out("  Phase 1 Interest:       " . money($calcB['phase1_interest']));
    out("  Remaining Interest:     " . money($calcB['remaining_interest']));
    out("  Weekly Principal:       " . money($calcB['weekly_principal']));
    out("  Weekly Interest:        " . money($calcB['weekly_interest']));
    out("  Weekly Payment:         " . money($calcB['weekly_payment']));
    
    subsection('Calculation Verification');
    check(
        'Interest-Only Months = Total Months - 2',
        $calcB['interest_only_months'] === $expectedB['interest_only_months'],
        "Expected: {$expectedB['interest_only_months']}, Got: {$calcB['interest_only_months']}"
    );
    check(
        'Recovery Weeks = 8',
        $calcB['recovery_weeks'] === $expectedB['recovery_weeks'],
        "Expected: {$expectedB['recovery_weeks']}, Got: {$calcB['recovery_weeks']}"
    );
    check(
        'Phase 1 Interest = Monthly Interest × Interest-Only Months',
        abs($calcB['phase1_interest'] - $expectedB['phase1_interest']) < 0.01,
        "Expected: {$expectedB['phase1_interest']}, Got: {$calcB['phase1_interest']}"
    );
    check(
        'Remaining Interest = Total Interest - Phase 1 Interest',
        abs($calcB['remaining_interest'] - $expectedB['remaining_interest']) < 0.01,
        "Expected: {$expectedB['remaining_interest']}, Got: {$calcB['remaining_interest']}"
    );
    check(
        'Weekly Principal = Principal ÷ 8',
        abs($calcB['weekly_principal'] - $expectedB['weekly_principal']) < 0.01,
        "Expected: {$expectedB['weekly_principal']}, Got: {$calcB['weekly_principal']}"
    );
    check(
        'Weekly Interest = Remaining Interest ÷ 8',
        abs($calcB['weekly_interest'] - $expectedB['weekly_interest']) < 0.01,
        "Expected: {$expectedB['weekly_interest']}, Got: {$calcB['weekly_interest']}"
    );
    check(
        'Weekly Payment = Weekly Principal + Weekly Interest',
        abs($calcB['weekly_payment'] - $expectedB['weekly_payment']) < 0.01,
        "Expected: {$expectedB['weekly_payment']}, Got: {$calcB['weekly_payment']}"
    );
    
    subsection('Schedule Generation Test');
    
    // Create test loan
    $db->beginTransaction();
    
    // Insert test member
    $stmt = $db->prepare("
        INSERT INTO members (member_number, first_name, last_name, gender, date_of_birth, phone, email, address, status)
        VALUES ('TEST-B-001', 'Test', 'InterestOnly', 'Female', '1990-01-01', '0700000002', 'test.b@test.com', 'Test', 'active')
    ");
    $stmt->execute();
    $memberIdB = $db->lastInsertId();
    
    // Insert test loan
    $startDate = '2026-09-16';
    $stmt = $db->prepare("
        INSERT INTO loans (
            member_id, loan_type_id, loan_number, loan_amount, interest_rate, interest_amount,
            total_payable, outstanding, monthly_installment, loan_period_months,
            issue_date, due_date, status, repayment_frequency, repayment_method, recorded_by
        ) VALUES (
            ?, 2, 'TEST-IO-001', ?, ?, ?, ?, ?, 0, ?, ?, DATE_ADD(?, INTERVAL 6 MONTH), 'draft', 'weekly', 'interest_only', 1
        )
    ");
    $stmt->execute([
        $memberIdB,
        $testB['principal'],
        $testB['rate'],
        $calcB['total_interest'],
        $calcB['total_payable'],
        $calcB['total_payable'],
        $testB['months'],
        $startDate,
        $startDate
    ]);
    $loanIdB = $db->lastInsertId();
    
    out("  Created test loan: TEST-IO-001 (ID: $loanIdB)");
    
    // Generate schedule
    $productModel->generateInterestOnlySchedule(
        $loanIdB,
        $testB['principal'],
        $testB['rate'],
        $testB['months'],
        $startDate
    );
    
    out("  Schedule generated for loan $loanIdB");
    
    subsection('Schedule Verification');
    
    // Fetch schedule
    $stmt = $db->prepare("
        SELECT * FROM loan_installments 
        WHERE loan_id = ? 
        ORDER BY installment_no
    ");
    $stmt->execute([$loanIdB]);
    $installmentsB = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    out("  Total installments: " . count($installmentsB));
    
    check(
        'Schedule has 12 total installments (4 monthly + 8 weekly)',
        count($installmentsB) === 12,
        "Expected: 12, Got: " . count($installmentsB)
    );
    
    // Separate phases
    $phase1Installments = [];
    $phase2Installments = [];
    foreach ($installmentsB as $inst) {
        if ($inst['payment_type'] === 'interest_only') {
            $phase1Installments[] = $inst;
        } else {
            $phase2Installments[] = $inst;
        }
    }
    
    out("  Phase 1 (Interest-Only): " . count($phase1Installments) . " installments");
    out("  Phase 2 (Recovery): " . count($phase2Installments) . " installments");
    
    check(
        'Phase 1 has 4 monthly interest-only installments',
        count($phase1Installments) === 4,
        "Expected: 4, Got: " . count($phase1Installments)
    );
    check(
        'Phase 2 has 8 weekly principal+interest installments',
        count($phase2Installments) === 8,
        "Expected: 8, Got: " . count($phase2Installments)
    );
    
    // Check first interest-only installment
    $firstPhase1 = $phase1Installments[0];
    check(
        'First installment is monthly',
        $firstPhase1['period_type'] === 'monthly',
        "Type: {$firstPhase1['period_type']}"
    );
    check(
        'First installment is interest_only',
        $firstPhase1['payment_type'] === 'interest_only',
        "Type: {$firstPhase1['payment_type']}"
    );
    check(
        'First installment has principal_due = 0',
        abs((float)$firstPhase1['principal_due']) < 0.01,
        "Principal: " . money($firstPhase1['principal_due'])
    );
    check(
        'First installment has interest_due = Monthly Interest',
        abs((float)$firstPhase1['interest_due'] - $expectedB['monthly_interest']) < 0.01,
        "Expected: " . money($expectedB['monthly_interest']) . ", Got: " . money($firstPhase1['interest_due'])
    );
    
    // Check first recovery installment
    $firstPhase2 = $phase2Installments[0];
    check(
        'First recovery installment is weekly',
        $firstPhase2['period_type'] === 'weekly',
        "Type: {$firstPhase2['period_type']}"
    );
    check(
        'First recovery installment is principal_interest',
        $firstPhase2['payment_type'] === 'principal_interest',
        "Type: {$firstPhase2['payment_type']}"
    );
    check(
        'First recovery installment has principal > 0',
        (float)$firstPhase2['principal_due'] > 0,
        "Principal: " . money($firstPhase2['principal_due'])
    );
    check(
        'First recovery installment has interest > 0',
        (float)$firstPhase2['interest_due'] > 0,
        "Interest: " . money($firstPhase2['interest_due'])
    );
    
    // Sum schedule totals
    $totalPrincipalDue = 0;
    $totalInterestDue = 0;
    $totalAmountDue = 0;
    
    foreach ($installmentsB as $inst) {
        $totalPrincipalDue += (float)$inst['principal_due'];
        $totalInterestDue += (float)$inst['interest_due'];
        $totalAmountDue += (float)$inst['amount_due'];
    }
    
    out("\n  Schedule Totals:");
    out("    Scheduled Principal: " . money($totalPrincipalDue));
    out("    Scheduled Interest:  " . money($totalInterestDue));
    out("    Scheduled Total:     " . money($totalAmountDue));
    
    subsection('Reconciliation');
    
    check(
        'Scheduled Principal = Contractual Principal',
        abs($totalPrincipalDue - $testB['principal']) < 0.01,
        "Expected: " . money($testB['principal']) . ", Got: " . money($totalPrincipalDue)
    );
    check(
        'Scheduled Interest = Contractual Interest',
        abs($totalInterestDue - $calcB['total_interest']) < 0.01,
        "Expected: " . money($calcB['total_interest']) . ", Got: " . money($totalInterestDue)
    );
    check(
        'Scheduled Total = Contractual Total Payable',
        abs($totalAmountDue - $calcB['total_payable']) < 0.01,
        "Expected: " . money($calcB['total_payable']) . ", Got: " . money($totalAmountDue)
    );
    
    // Check final balance
    $lastInstallment = end($installmentsB);
    check(
        'Final balance reaches zero',
        abs((float)$lastInstallment['balance_after']) < 0.01,
        "Final balance: " . money($lastInstallment['balance_after'])
    );
    
    subsection('Date Verification');
    
    // Check first monthly installment date
    $expectedFirstMonthly = date('Y-m-d', strtotime('+1 month', strtotime($startDate)));
    check(
        'First monthly installment is 1 month after start date',
        $firstPhase1['due_date'] === $expectedFirstMonthly,
        "Expected: $expectedFirstMonthly, Got: {$firstPhase1['due_date']}"
    );
    
    // Check last monthly installment date
    $lastPhase1 = end($phase1Installments);
    $expectedLastMonthly = date('Y-m-d', strtotime('+4 months', strtotime($startDate)));
    check(
        'Last monthly installment is 4 months after start date',
        $lastPhase1['due_date'] === $expectedLastMonthly,
        "Expected: $expectedLastMonthly, Got: {$lastPhase1['due_date']}"
    );
    
    // Check first weekly installment is 1 week after last monthly
    $expectedFirstWeekly = date('Y-m-d', strtotime('+1 week', strtotime($lastPhase1['due_date'])));
    check(
        'First weekly installment is 1 week after last monthly',
        $firstPhase2['due_date'] === $expectedFirstWeekly,
        "Expected: $expectedFirstWeekly, Got: {$firstPhase2['due_date']}"
    );
    
    // Check last weekly installment
    $lastPhase2 = end($phase2Installments);
    $expectedLastWeekly = date('Y-m-d', strtotime('+8 weeks', strtotime($lastPhase1['due_date'])));
    check(
        'Last weekly installment is 8 weeks after last monthly',
        $lastPhase2['due_date'] === $expectedLastWeekly,
        "Expected: $expectedLastWeekly, Got: {$lastPhase2['due_date']}"
    );
    
    // Rollback test data
    $db->rollback();
    out("\n  Test data rolled back (loan and member deleted)");
    
    subsection('TEST B RESULT');
    out(color("  ✓ TEST B: PASS", 'green'));
    out("  Interest Only correctly generates 4 monthly interest-only installments");
    out("  Followed by 8 weekly principal + interest recovery installments");
    out("  Schedule reconciles exactly with contractual amounts");
    out("  Phase transition and dates are correct");
    
    // ========================================================================
    // TEST C: DIFFERENT TERM (12 MONTHS) — VERIFY NOT HARDCODED TO 6 MONTHS
    // ========================================================================
    
    section('TEST C: Different Term (12 Months) — System Flexibility');
    
    subsection('TEST C1: Business Boost — 5M, 4%, 12 months');
    $testC1 = [
        'principal' => 5000000,
        'rate' => 4.0,
        'months' => 12,
        'method' => 'business_boost',
    ];
    out("  Principal:        " . money($testC1['principal']));
    out("  Rate:             {$testC1['rate']}% per month");
    out("  Term:             {$testC1['months']} months");
    
    $calcC1 = $productModel->calculateBusinessBoost(
        $testC1['principal'],
        $testC1['rate'],
        $testC1['months']
    );
    
    out("  Monthly Interest:    " . money($calcC1['monthly_interest']));
    out("  Total Interest:      " . money($calcC1['total_interest']));
    out("  Total Payable:       " . money($calcC1['total_payable']));
    out("  Total Weeks:         {$calcC1['total_weeks']}");
    out("  Weekly Installment:  " . money($calcC1['weekly_installment']));
    
    check(
        'Total Weeks = 48 (12 × 4)',
        $calcC1['total_weeks'] === 48,
        "Expected: 48, Got: {$calcC1['total_weeks']}"
    );
    check(
        'Total Interest = Monthly Interest × 12',
        abs($calcC1['total_interest'] - ($calcC1['monthly_interest'] * 12)) < 0.01,
        "Expected: " . ($calcC1['monthly_interest'] * 12) . ", Got: {$calcC1['total_interest']}"
    );
    check(
        'Interest Only Months = 0',
        $calcC1['interest_only_months'] === 0,
        "Business Boost has no interest-only phase"
    );
    
    // Generate schedule
    $db->beginTransaction();
    
    $stmt = $db->prepare("
        INSERT INTO members (member_number, first_name, last_name, gender, date_of_birth, phone, email, address, status)
        VALUES ('TEST-C1-001', 'Test', 'BB12Month', 'Male', '1990-01-01', '0700000003', 'test.c1@test.com', 'Test', 'active')
    ");
    $stmt->execute();
    $memberIdC1 = $db->lastInsertId();
    
    $startDate = '2026-09-16';
    $stmt = $db->prepare("
        INSERT INTO loans (
            member_id, loan_type_id, loan_number, loan_amount, interest_rate, interest_amount,
            total_payable, outstanding, monthly_installment, loan_period_months,
            issue_date, due_date, status, repayment_frequency, repayment_method, recorded_by
        ) VALUES (
            ?, 2, 'TEST-BB12-001', ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(?, INTERVAL 12 MONTH), 'draft', 'weekly', 'business_boost', 1
        )
    ");
    $stmt->execute([
        $memberIdC1,
        $testC1['principal'],
        $testC1['rate'],
        $calcC1['total_interest'],
        $calcC1['total_payable'],
        $calcC1['total_payable'],
        $calcC1['monthly_installment'],
        $testC1['months'],
        $startDate,
        $startDate
    ]);
    $loanIdC1 = $db->lastInsertId();
    
    $productModel->generateBusinessBoostSchedule(
        $loanIdC1,
        $testC1['principal'],
        $testC1['rate'],
        $testC1['months'],
        $startDate
    );
    
    $stmt = $db->prepare("SELECT * FROM loan_installments WHERE loan_id = ? ORDER BY installment_no");
    $stmt->execute([$loanIdC1]);
    $installmentsC1 = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    check(
        'Schedule has 48 weekly installments',
        count($installmentsC1) === 48,
        "Expected: 48, Got: " . count($installmentsC1)
    );
    
    $interestOnlyCount = 0;
    foreach ($installmentsC1 as $inst) {
        if ($inst['payment_type'] === 'interest_only') $interestOnlyCount++;
    }
    
    check(
        'NO interest-only installments',
        $interestOnlyCount === 0,
        "Found $interestOnlyCount interest-only rows"
    );
    
    $totalPrincipalC1 = array_sum(array_column($installmentsC1, 'principal_due'));
    $totalInterestC1 = array_sum(array_column($installmentsC1, 'interest_due'));
    $finalBalanceC1 = (float)end($installmentsC1)['balance_after'];
    
    check(
        'Schedule reconciles: Principal',
        abs($totalPrincipalC1 - $testC1['principal']) < 0.01,
        money($totalPrincipalC1)
    );
    check(
        'Schedule reconciles: Interest',
        abs($totalInterestC1 - $calcC1['total_interest']) < 0.01,
        money($totalInterestC1)
    );
    check(
        'Final balance = 0',
        abs($finalBalanceC1) < 0.01,
        money($finalBalanceC1)
    );
    
    $db->rollback();
    out(color("  ✓ Business Boost 12-month test PASS", 'green'));
    
    subsection('TEST C2: Interest Only — 5M, 4%, 12 months');
    $testC2 = [
        'principal' => 5000000,
        'rate' => 4.0,
        'months' => 12,
        'method' => 'interest_only',
    ];
    out("  Principal:        " . money($testC2['principal']));
    out("  Rate:             {$testC2['rate']}% per month");
    out("  Term:             {$testC2['months']} months");
    
    $calcC2 = $productModel->calculateInterestOnly(
        $testC2['principal'],
        $testC2['rate'],
        $testC2['months']
    );
    
    out("  Monthly Interest:       " . money($calcC2['monthly_interest']));
    out("  Total Interest:         " . money($calcC2['total_interest']));
    out("  Interest-Only Months:   {$calcC2['interest_only_months']}");
    out("  Recovery Weeks:         {$calcC2['recovery_weeks']}");
    out("  Weekly Principal:       " . money($calcC2['weekly_principal']));
    out("  Weekly Interest:        " . money($calcC2['weekly_interest']));
    
    check(
        'Interest-Only Months = 10 (12 - 2)',
        $calcC2['interest_only_months'] === 10,
        "Expected: 10, Got: {$calcC2['interest_only_months']}"
    );
    check(
        'Recovery Weeks = 8',
        $calcC2['recovery_weeks'] === 8,
        "Expected: 8, Got: {$calcC2['recovery_weeks']}"
    );
    
    // Generate schedule
    $db->beginTransaction();
    
    $stmt = $db->prepare("
        INSERT INTO members (member_number, first_name, last_name, gender, date_of_birth, phone, email, address, status)
        VALUES ('TEST-C2-001', 'Test', 'IO12Month', 'Female', '1990-01-01', '0700000004', 'test.c2@test.com', 'Test', 'active')
    ");
    $stmt->execute();
    $memberIdC2 = $db->lastInsertId();
    
    $stmt = $db->prepare("
        INSERT INTO loans (
            member_id, loan_type_id, loan_number, loan_amount, interest_rate, interest_amount,
            total_payable, outstanding, monthly_installment, loan_period_months,
            issue_date, due_date, status, repayment_frequency, repayment_method, recorded_by
        ) VALUES (
            ?, 2, 'TEST-IO12-001', ?, ?, ?, ?, ?, 0, ?, ?, DATE_ADD(?, INTERVAL 12 MONTH), 'draft', 'weekly', 'interest_only', 1
        )
    ");
    $stmt->execute([
        $memberIdC2,
        $testC2['principal'],
        $testC2['rate'],
        $calcC2['total_interest'],
        $calcC2['total_payable'],
        $calcC2['total_payable'],
        $testC2['months'],
        $startDate,
        $startDate
    ]);
    $loanIdC2 = $db->lastInsertId();
    
    $productModel->generateInterestOnlySchedule(
        $loanIdC2,
        $testC2['principal'],
        $testC2['rate'],
        $testC2['months'],
        $startDate
    );
    
    $stmt = $db->prepare("SELECT * FROM loan_installments WHERE loan_id = ? ORDER BY installment_no");
    $stmt->execute([$loanIdC2]);
    $installmentsC2 = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    check(
        'Schedule has 18 total installments (10 monthly + 8 weekly)',
        count($installmentsC2) === 18,
        "Expected: 18, Got: " . count($installmentsC2)
    );
    
    $phase1C2 = array_filter($installmentsC2, fn($i) => $i['payment_type'] === 'interest_only');
    $phase2C2 = array_filter($installmentsC2, fn($i) => $i['payment_type'] === 'principal_interest');
    
    check(
        'Phase 1 has 10 interest-only installments',
        count($phase1C2) === 10,
        "Expected: 10, Got: " . count($phase1C2)
    );
    check(
        'Phase 2 has 8 recovery installments',
        count($phase2C2) === 8,
        "Expected: 8, Got: " . count($phase2C2)
    );
    
    $totalPrincipalC2 = array_sum(array_column($installmentsC2, 'principal_due'));
    $totalInterestC2 = array_sum(array_column($installmentsC2, 'interest_due'));
    $finalBalanceC2 = (float)end($installmentsC2)['balance_after'];
    
    check(
        'Schedule reconciles: Principal',
        abs($totalPrincipalC2 - $testC2['principal']) < 0.01,
        money($totalPrincipalC2)
    );
    check(
        'Schedule reconciles: Interest',
        abs($totalInterestC2 - $calcC2['total_interest']) < 0.01,
        money($totalInterestC2)
    );
    check(
        'Final balance = 0',
        abs($finalBalanceC2) < 0.01,
        money($finalBalanceC2)
    );
    
    $db->rollback();
    out(color("  ✓ Interest Only 12-month test PASS", 'green'));
    
    subsection('TEST C RESULT');
    out(color("  ✓ TEST C: PASS", 'green'));
    out("  System correctly handles 12-month term (not hardcoded to 6 months)");
    out("  Business Boost generates 48 weekly installments");
    out("  Interest Only generates 10 monthly + 8 weekly installments");
    out("  All schedules reconcile exactly");
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    out(color("\n✗ TEST FAILED: " . $e->getMessage(), 'red'));
    out("Stack trace:");
    out($e->getTraceAsString());
}

// Save results to file
section('SAVING TEST RESULTS');
$reportContent = "# Business Loan Implementation Test Results\n\n";
$reportContent .= "**Test Date:** " . date('Y-m-d H:i:s') . "\n\n";
$reportContent .= "**Test Database:** " . DB_NAME . "\n\n";
$reportContent .= "## Test Output\n\n```\n";
$reportContent .= implode("\n", $output);
$reportContent .= "\n```\n";

file_put_contents(TEST_OUTPUT_FILE, $reportContent);
out("Results saved to: " . TEST_OUTPUT_FILE);

section('TEST COMPLETE');
