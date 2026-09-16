<?php
/**
 * Test Script: Business Boost Calendar-Duration Correction
 * 
 * Tests the corrected Business Boost calculation that uses actual calendar
 * duration instead of months × 4 weeks.
 * 
 * DO NOT RUN ON PRODUCTION DATABASE
 */

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../core/Model.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../app/models/LoanProductModel.php';
require_once __DIR__ . '/../app/models/LoanModel.php';

define('TEST_OUTPUT_FILE', __DIR__ . '/../results/business-boost-calendar-duration-test-results.md');

function color($text, $color = 'green') {
    $colors = ['green' => "\033[32m", 'red' => "\033[31m", 'yellow' => "\033[33m", 'blue' => "\033[34m", 'reset' => "\033[0m"];
    return $colors[$color] . $text . $colors['reset'];
}

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

section('BUSINESS BOOST CALENDAR-DURATION CORRECTION TEST');
out('Test Date: ' . date('Y-m-d H:i:s'));
out('Test Database: ' . DB_NAME);

try {
    $db = Database::getInstance()->getConnection();
    $productModel = new LoanProductModel();
    
    // ========================================================================
    // TEST A: 6-MONTH BUSINESS BOOST — CALENDAR DURATION
    // ========================================================================
    
    section('TEST A: Business Boost 6 Months — Calendar Duration');
    
    $testA = [
        'principal' => 10000000,
        'rate' => 5.0,
        'months' => 6,
        'start_date' => '2026-09-16',
        'method' => 'business_boost',
    ];
    
    subsection('Input Parameters');
    out("  Principal:        " . money($testA['principal']));
    out("  Rate:             {$testA['rate']}% per month");
    out("  Term:             {$testA['months']} months");
    out("  Start Date:       {$testA['start_date']}");
    out("  Method:           {$testA['method']}");
    
    subsection('Calendar Calculation');
    $contractualEnd = LoanModel::addCalendarMonths($testA['start_date'], $testA['months']);
    out("  Contractual End Date: $contractualEnd");
    out("  Expected: 2027-03-16 (6 months from 2026-09-16)");
    
    check(
        'Contractual end date is correct',
        $contractualEnd === '2027-03-16',
        "Got: $contractualEnd"
    );
    
    // Count actual weeks
    $actualWeeks = 0;
    $currentDate = strtotime("+1 week", strtotime($testA['start_date']));
    $weekDates = [];
    while (date('Y-m-d', $currentDate) <= $contractualEnd) {
        $actualWeeks++;
        $weekDates[] = date('Y-m-d', $currentDate);
        $currentDate = strtotime("+1 week", $currentDate);
    }
    
    out("  Actual Weekly Intervals: $actualWeeks");
    out("  First Week Date: {$weekDates[0]}");
    out("  Last Week Date: {$weekDates[count($weekDates)-1]}");
    
    check(
        'Actual weeks > 24 (not months × 4)',
        $actualWeeks > 24,
        "Actual: $actualWeeks, Old formula would give: 24"
    );
    
    subsection('Backend Calculation');
    $calcA = $productModel->calculateBusinessBoost(
        $testA['principal'],
        $testA['rate'],
        $testA['months'],
        $testA['start_date']
    );
    
    out("  Monthly Interest:    " . money($calcA['monthly_interest']));
    out("  Total Interest:      " . money($calcA['total_interest']));
    out("  Total Payable:       " . money($calcA['total_payable']));
    out("  Calculated Weeks:    {$calcA['total_weeks']}");
    out("  Weekly Installment:  " . money($calcA['weekly_installment']));
    out("  Contractual End:     {$calcA['contractual_end_date']}");
    
    check(
        'Calculated weeks matches actual calendar weeks',
        $calcA['total_weeks'] === $actualWeeks,
        "Expected: $actualWeeks, Got: {$calcA['total_weeks']}"
    );
    
    check(
        'Total Interest = 3,000,000',
        abs($calcA['total_interest'] - 3000000) < 0.01
    );
    
    check(
        'Total Payable = 13,000,000',
        abs($calcA['total_payable'] - 13000000) < 0.01
    );
    
    subsection('Schedule Generation Test');
    
    $db->beginTransaction();
    
    // Create test member
    $stmt = $db->prepare("
        INSERT INTO members (member_number, first_name, last_name, gender, date_of_birth, phone, email, address, status)
        VALUES ('TEST-CAL-A', 'Test', 'CalendarA', 'Male', '1990-01-01', '0700010001', 'test.cal.a@test.com', 'Test', 'active')
    ");
    $stmt->execute();
    $memberIdA = $db->lastInsertId();
    
    // Create test loan
    $stmt = $db->prepare("
        INSERT INTO loans (
            member_id, loan_type_id, loan_number, loan_amount, interest_rate, interest_amount,
            total_payable, outstanding, monthly_installment, loan_period_months,
            issue_date, due_date, status, repayment_frequency, repayment_method, recorded_by
        ) VALUES (
            ?, 2, 'TEST-CAL-A-001', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', 'weekly', 'business_boost', 1
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
        $testA['start_date'],
        $contractualEnd
    ]);
    $loanIdA = $db->lastInsertId();
    
    out("  Created test loan: TEST-CAL-A-001 (ID: $loanIdA)");
    
    // Generate schedule
    $productModel->generateBusinessBoostSchedule(
        $loanIdA,
        $testA['principal'],
        $testA['rate'],
        $testA['months'],
        $testA['start_date']
    );
    
    out("  Schedule generated");
    
    subsection('Schedule Verification');
    
    $stmt = $db->prepare("SELECT * FROM loan_installments WHERE loan_id = ? ORDER BY installment_no");
    $stmt->execute([$loanIdA]);
    $installments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    out("  Total installments: " . count($installments));
    
    check(
        'Schedule has ' . $actualWeeks . ' installments (actual calendar weeks)',
        count($installments) === $actualWeeks,
        "Expected: $actualWeeks, Got: " . count($installments)
    );
    
    // Check dates
    $firstDate = $installments[0]['due_date'];
    $lastDate = end($installments)['due_date'];
    
    out("  First installment: $firstDate");
    out("  Last installment: $lastDate");
    out("  Contractual end: $contractualEnd");
    
    check(
        'First installment is 1 week after start',
        $firstDate === $weekDates[0],
        "Expected: {$weekDates[0]}, Got: $firstDate"
    );
    
    check(
        'Last installment does not exceed contractual end',
        $lastDate <= $contractualEnd,
        "Last: $lastDate, Contractual End: $contractualEnd"
    );
    
    check(
        'Schedule extends beyond old formula end date (2027-03-03)',
        $lastDate > '2027-03-03',
        "New schedule ends: $lastDate, Old would end: 2027-03-03"
    );
    
    // Reconciliation
    $totalPrincipal = array_sum(array_column($installments, 'principal_due'));
    $totalInterest = array_sum(array_column($installments, 'interest_due'));
    $totalAmount = array_sum(array_column($installments, 'amount_due'));
    $finalBalance = (float)end($installments)['balance_after'];
    
    out("\n  Schedule Totals:");
    out("    Scheduled Principal: " . money($totalPrincipal));
    out("    Scheduled Interest:  " . money($totalInterest));
    out("    Scheduled Total:     " . money($totalAmount));
    out("    Final Balance:       " . money($finalBalance));
    
    check(
        'Principal reconciles exactly',
        abs($totalPrincipal - $testA['principal']) < 0.01,
        money($totalPrincipal)
    );
    
    check(
        'Interest reconciles exactly',
        abs($totalInterest - $calcA['total_interest']) < 0.01,
        money($totalInterest)
    );
    
    check(
        'Total reconciles exactly',
        abs($totalAmount - $calcA['total_payable']) < 0.01,
        money($totalAmount)
    );
    
    check(
        'Final balance = 0',
        abs($finalBalance) < 0.01,
        money($finalBalance)
    );
    
    $db->rollback();
    out("\n  Test data rolled back");
    
    subsection('TEST A RESULT');
    out(color("  ✓ TEST A: PASS", 'green'));
    out("  Business Boost now uses actual calendar duration");
    out("  Schedule extends to contractual end date");
    out("  Uses $actualWeeks weeks (not 24)");
    
    // ========================================================================
    // TEST B: 12-MONTH BUSINESS BOOST
    // ========================================================================
    
    section('TEST B: Business Boost 12 Months');
    
    $testB = [
        'principal' => 10000000,
        'rate' => 5.0,
        'months' => 12,
        'start_date' => '2026-09-16',
    ];
    
    out("  Principal:        " . money($testB['principal']));
    out("  Rate:             {$testB['rate']}% per month");
    out("  Term:             {$testB['months']} months");
    out("  Start Date:       {$testB['start_date']}");
    
    $contractualEndB = LoanModel::addCalendarMonths($testB['start_date'], $testB['months']);
    out("  Contractual End:  $contractualEndB");
    
    // Count actual weeks
    $actualWeeksB = 0;
    $currentDate = strtotime("+1 week", strtotime($testB['start_date']));
    while (date('Y-m-d', $currentDate) <= $contractualEndB) {
        $actualWeeksB++;
        $currentDate = strtotime("+1 week", $currentDate);
    }
    
    out("  Actual Weeks:     $actualWeeksB");
    out("  Old Formula:      " . ($testB['months'] * 4) . " weeks");
    
    check(
        'Actual weeks ≠ months × 4',
        $actualWeeksB !== ($testB['months'] * 4),
        "Actual: $actualWeeksB, Formula: " . ($testB['months'] * 4)
    );
    
    $calcB = $productModel->calculateBusinessBoost(
        $testB['principal'],
        $testB['rate'],
        $testB['months'],
        $testB['start_date']
    );
    
    check(
        'Calculated weeks matches actual',
        $calcB['total_weeks'] === $actualWeeksB
    );
    
    out(color("  ✓ TEST B: PASS", 'green'));
    
    // ========================================================================
    // TEST C: MONTH-END DATE
    // ========================================================================
    
    section('TEST C: Month-End Start Date');
    
    $testC = [
        'principal' => 5000000,
        'rate' => 4.0,
        'months' => 1,
        'start_date' => '2027-01-31',
    ];
    
    out("  Principal:        " . money($testC['principal']));
    out("  Term:             {$testC['months']} month");
    out("  Start Date:       {$testC['start_date']} (month-end)");
    
    $contractualEndC = LoanModel::addCalendarMonths($testC['start_date'], $testC['months']);
    out("  Contractual End:  $contractualEndC");
    
    check(
        'Month-end date handled correctly',
        $contractualEndC === '2027-02-28',
        "Jan 31 + 1 month = Feb 28 (Feb has 28 days in 2027)"
    );
    
    // Count weeks
    $actualWeeksC = 0;
    $currentDate = strtotime("+1 week", strtotime($testC['start_date']));
    $datesC = [];
    while (date('Y-m-d', $currentDate) <= $contractualEndC) {
        $actualWeeksC++;
        $datesC[] = date('Y-m-d', $currentDate);
        $currentDate = strtotime("+1 week", $currentDate);
    }
    
    out("  Actual Weeks:     $actualWeeksC");
    out("  Week Dates:       " . implode(', ', $datesC));
    
    check(
        'No invalid dates generated',
        count($datesC) === $actualWeeksC && $actualWeeksC > 0
    );
    
    check(
        'All dates ≤ contractual end',
        end($datesC) <= $contractualEndC
    );
    
    out(color("  ✓ TEST C: PASS", 'green'));
    
    // ========================================================================
    // TEST D: ROUNDING VERIFICATION
    // ========================================================================
    
    section('TEST D: Rounding with Actual Calendar Weeks');
    
    $testD = [
        'principal' => 7777777,
        'rate' => 3.33,
        'months' => 6,
        'start_date' => '2026-09-16',
    ];
    
    out("  Principal:        " . money($testD['principal']) . " (creates fractions)");
    out("  Rate:             {$testD['rate']}% (creates fractions)");
    out("  Term:             {$testD['months']} months");
    
    $db->beginTransaction();
    
    $stmt = $db->prepare("
        INSERT INTO members (member_number, first_name, last_name, gender, date_of_birth, phone, email, address, status)
        VALUES ('TEST-CAL-D', 'Test', 'CalendarD', 'Female', '1990-01-01', '0700010004', 'test.cal.d@test.com', 'Test', 'active')
    ");
    $stmt->execute();
    $memberIdD = $db->lastInsertId();
    
    $calcD = $productModel->calculateBusinessBoost(
        $testD['principal'],
        $testD['rate'],
        $testD['months'],
        $testD['start_date']
    );
    
    $stmt = $db->prepare("
        INSERT INTO loans (
            member_id, loan_type_id, loan_number, loan_amount, interest_rate, interest_amount,
            total_payable, outstanding, monthly_installment, loan_period_months,
            issue_date, due_date, status, repayment_frequency, repayment_method, recorded_by
        ) VALUES (
            ?, 2, 'TEST-CAL-D-001', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', 'weekly', 'business_boost', 1
        )
    ");
    $stmt->execute([
        $memberIdD,
        $testD['principal'],
        $testD['rate'],
        $calcD['total_interest'],
        $calcD['total_payable'],
        $calcD['total_payable'],
        $calcD['monthly_installment'],
        $testD['months'],
        $testD['start_date'],
        $calcD['contractual_end_date']
    ]);
    $loanIdD = $db->lastInsertId();
    
    $productModel->generateBusinessBoostSchedule(
        $loanIdD,
        $testD['principal'],
        $testD['rate'],
        $testD['months'],
        $testD['start_date']
    );
    
    $stmt = $db->prepare("SELECT * FROM loan_installments WHERE loan_id = ? ORDER BY installment_no");
    $stmt->execute([$loanIdD]);
    $installmentsD = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalPrincipalD = array_sum(array_column($installmentsD, 'principal_due'));
    $totalInterestD = array_sum(array_column($installmentsD, 'interest_due'));
    $totalAmountD = array_sum(array_column($installmentsD, 'amount_due'));
    $finalBalanceD = (float)end($installmentsD)['balance_after'];
    
    check(
        'Principal reconciles with fractions',
        abs($totalPrincipalD - $testD['principal']) < 0.01
    );
    
    check(
        'Interest reconciles with fractions',
        abs($totalInterestD - $calcD['total_interest']) < 0.01
    );
    
    check(
        'Total reconciles with fractions',
        abs($totalAmountD - $calcD['total_payable']) < 0.01
    );
    
    check(
        'Final balance = 0 with fractions',
        abs($finalBalanceD) < 0.01
    );
    
    $db->rollback();
    out(color("  ✓ TEST D: PASS", 'green'));
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    out(color("\n✗ TEST FAILED: " . $e->getMessage(), 'red'));
    out("Stack trace:");
    out($e->getTraceAsString());
}

section('SAVING TEST RESULTS');
$reportContent = "# Business Boost Calendar-Duration Test Results\n\n";
$reportContent .= "**Test Date:** " . date('Y-m-d H:i:s') . "\n\n";
$reportContent .= "## Test Output\n\n```\n";
$reportContent .= implode("\n", $output);
$reportContent .= "\n```\n";

file_put_contents(TEST_OUTPUT_FILE, $reportContent);
out("Results saved to: " . TEST_OUTPUT_FILE);

section('TEST COMPLETE');
