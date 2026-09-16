<?php
/**
 * Business Boost 30-Day / 4-Week Contractual Formula Test
 * 
 * Tests that Business Boost uses contractual formula:
 * 1 month = 30 days = 4 weeks for installment calculation
 * 
 * NOT calendar-based week counting.
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../core/Model.php';
require_once __DIR__ . '/../app/models/LoanModel.php';
require_once __DIR__ . '/../app/models/LoanProductModel.php';

echo "\n" . str_repeat("=", 80) . "\n";
echo "BUSINESS BOOST CONTRACTUAL FORMULA VERIFICATION\n";
echo str_repeat("=", 80) . "\n\n";

$db = Database::getInstance()->getConnection();

try {
    $db->beginTransaction();
    
    // =================================================================
    // TEST 1: 6 Months — 3M Principal, 3% Rate
    // =================================================================
    
    echo "TEST 1: Business Boost 6 Months (3M Principal, 3% Rate)\n";
    echo str_repeat("-", 80) . "\n";
    
    $principal = 3000000;
    $rate = 3.0;
    $months = 6;
    $startDate = '2026-09-16';
    
    $loanProductModel = new LoanProductModel($db);
    $calc = $loanProductModel->calculateBusinessBoost($principal, $rate, $months, $startDate);
    
    echo "Input:\n";
    echo "  Principal: UGX " . number_format($principal, 2) . "\n";
    echo "  Monthly Rate: {$rate}%\n";
    echo "  Term: {$months} months\n";
    echo "  Start Date: {$startDate}\n\n";
    
    echo "Calculation Results:\n";
    echo "  Monthly Interest: UGX " . number_format($calc['monthly_interest'], 2) . "\n";
    echo "  Total Interest: UGX " . number_format($calc['total_interest'], 2) . "\n";
    echo "  Total Payable: UGX " . number_format($calc['total_payable'], 2) . "\n";
    echo "  Total Weeks: {$calc['total_weeks']} (contractual)\n";
    echo "  Weekly Installment: UGX " . number_format($calc['weekly_installment'], 2) . "\n\n";
    
    // Expected values
    $expectedInterest = 540000;  // 3M × 3% × 6
    $expectedPayable = 3540000;   // 3M + 540K
    $expectedWeeks = 24;          // 6 × 4
    $expectedWeekly = 147500;     // 3,540,000 ÷ 24
    
    echo "Expected Values:\n";
    echo "  Total Interest: UGX " . number_format($expectedInterest, 2) . "\n";
    echo "  Total Payable: UGX " . number_format($expectedPayable, 2) . "\n";
    echo "  Total Weeks: {$expectedWeeks} (6 × 4)\n";
    echo "  Weekly Installment: UGX " . number_format($expectedWeekly, 2) . "\n\n";
    
    $test1Pass = (
        $calc['total_interest'] == $expectedInterest &&
        $calc['total_payable'] == $expectedPayable &&
        $calc['total_weeks'] == $expectedWeeks &&
        $calc['weekly_installment'] == $expectedWeekly
    );
    
    echo "Verification:\n";
    echo "  Interest Match: " . ($calc['total_interest'] == $expectedInterest ? "✓ PASS" : "✗ FAIL") . "\n";
    echo "  Payable Match: " . ($calc['total_payable'] == $expectedPayable ? "✓ PASS" : "✗ FAIL") . "\n";
    echo "  Weeks Match: " . ($calc['total_weeks'] == $expectedWeeks ? "✓ PASS" : "✗ FAIL") . "\n";
    echo "  Weekly Installment Match: " . ($calc['weekly_installment'] == $expectedWeekly ? "✓ PASS" : "✗ FAIL") . "\n";
    
    echo "\nTest 1 Result: " . ($test1Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";
    
    // =================================================================
    // TEST 2: 9 Months — 5M Principal, 4% Rate
    // =================================================================
    
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "TEST 2: Business Boost 9 Months (5M Principal, 4% Rate)\n";
    echo str_repeat("-", 80) . "\n";
    
    $principal2 = 5000000;
    $rate2 = 4.0;
    $months2 = 9;
    $startDate2 = '2026-09-16';
    
    $calc2 = $loanProductModel->calculateBusinessBoost($principal2, $rate2, $months2, $startDate2);
    
    echo "Input:\n";
    echo "  Principal: UGX " . number_format($principal2, 2) . "\n";
    echo "  Monthly Rate: {$rate2}%\n";
    echo "  Term: {$months2} months\n\n";
    
    echo "Calculation Results:\n";
    echo "  Total Interest: UGX " . number_format($calc2['total_interest'], 2) . "\n";
    echo "  Total Payable: UGX " . number_format($calc2['total_payable'], 2) . "\n";
    echo "  Total Weeks: {$calc2['total_weeks']} (contractual)\n";
    echo "  Weekly Installment: UGX " . number_format($calc2['weekly_installment'], 2) . "\n\n";
    
    $expectedWeeks2 = 36;  // 9 × 4
    $expectedInterest2 = 1800000;  // 5M × 4% × 9
    $expectedPayable2 = 6800000;
    $expectedWeekly2 = 188888.89;  // 6,800,000 ÷ 36
    
    echo "Expected Values:\n";
    echo "  Total Weeks: {$expectedWeeks2} (9 × 4)\n";
    echo "  Total Interest: UGX " . number_format($expectedInterest2, 2) . "\n";
    echo "  Weekly Installment: UGX " . number_format($expectedWeekly2, 2) . "\n\n";
    
    $test2Pass = (
        $calc2['total_weeks'] == $expectedWeeks2 &&
        $calc2['total_interest'] == $expectedInterest2 &&
        abs($calc2['weekly_installment'] - $expectedWeekly2) < 0.01
    );
    
    echo "Verification:\n";
    echo "  Weeks Match: " . ($calc2['total_weeks'] == $expectedWeeks2 ? "✓ PASS" : "✗ FAIL") . "\n";
    echo "  Interest Match: " . ($calc2['total_interest'] == $expectedInterest2 ? "✓ PASS" : "✗ FAIL") . "\n";
    echo "  Weekly Installment Match: " . (abs($calc2['weekly_installment'] - $expectedWeekly2) < 0.01 ? "✓ PASS" : "✗ FAIL") . "\n";
    
    echo "\nTest 2 Result: " . ($test2Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";
    
    // =================================================================
    // TEST 3: 12 Months — 5M Principal, 4% Rate
    // =================================================================
    
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "TEST 3: Business Boost 12 Months (5M Principal, 4% Rate)\n";
    echo str_repeat("-", 80) . "\n";
    
    $principal3 = 5000000;
    $rate3 = 4.0;
    $months3 = 12;
    $startDate3 = '2026-09-16';
    
    $calc3 = $loanProductModel->calculateBusinessBoost($principal3, $rate3, $months3, $startDate3);
    
    echo "Input:\n";
    echo "  Principal: UGX " . number_format($principal3, 2) . "\n";
    echo "  Monthly Rate: {$rate3}%\n";
    echo "  Term: {$months3} months\n\n";
    
    echo "Calculation Results:\n";
    echo "  Total Interest: UGX " . number_format($calc3['total_interest'], 2) . "\n";
    echo "  Total Payable: UGX " . number_format($calc3['total_payable'], 2) . "\n";
    echo "  Total Weeks: {$calc3['total_weeks']} (contractual)\n";
    echo "  Weekly Installment: UGX " . number_format($calc3['weekly_installment'], 2) . "\n\n";
    
    $expectedWeeks3 = 48;  // 12 × 4
    $expectedInterest3 = 2400000;  // 5M × 4% × 12
    $expectedPayable3 = 7400000;
    $expectedWeekly3 = 154166.67;  // 7,400,000 ÷ 48
    
    echo "Expected Values:\n";
    echo "  Total Weeks: {$expectedWeeks3} (12 × 4)\n";
    echo "  Total Interest: UGX " . number_format($expectedInterest3, 2) . "\n";
    echo "  Weekly Installment: UGX " . number_format($expectedWeekly3, 2) . "\n\n";
    
    $test3Pass = (
        $calc3['total_weeks'] == $expectedWeeks3 &&
        $calc3['total_interest'] == $expectedInterest3 &&
        abs($calc3['weekly_installment'] - $expectedWeekly3) < 0.01
    );
    
    echo "Verification:\n";
    echo "  Weeks Match: " . ($calc3['total_weeks'] == $expectedWeeks3 ? "✓ PASS" : "✗ FAIL") . "\n";
    echo "  Interest Match: " . ($calc3['total_interest'] == $expectedInterest3 ? "✓ PASS" : "✗ FAIL") . "\n";
    echo "  Weekly Installment Match: " . (abs($calc3['weekly_installment'] - $expectedWeekly3) < 0.01 ? "✓ PASS" : "✗ FAIL") . "\n";
    
    echo "\nTest 3 Result: " . ($test3Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";
    
    // =================================================================
    // TEST 4: Start Date Independence
    // =================================================================
    
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "TEST 4: Start Date Independence\n";
    echo "Same loan with different start dates should produce identical installment amounts\n";
    echo str_repeat("-", 80) . "\n";
    
    $testDates = [
        '2026-09-01',
        '2026-09-15',
        '2026-09-16',
        '2026-09-28',
        '2026-12-31',  // Year boundary
        '2027-02-15',  // Leap year consideration
    ];
    
    $principal4 = 3000000;
    $rate4 = 3.0;
    $months4 = 6;
    
    $weeklyAmounts = [];
    $weekCounts = [];
    
    foreach ($testDates as $date) {
        $calc4 = $loanProductModel->calculateBusinessBoost($principal4, $rate4, $months4, $date);
        $weeklyAmounts[] = $calc4['weekly_installment'];
        $weekCounts[] = $calc4['total_weeks'];
        
        echo "  Start: {$date} → Weeks: {$calc4['total_weeks']}, Weekly: UGX " . number_format($calc4['weekly_installment'], 2) . "\n";
    }
    
    // All should be identical
    $uniqueWeekly = array_unique($weeklyAmounts);
    $uniqueWeeks = array_unique($weekCounts);
    
    $test4Pass = (count($uniqueWeekly) === 1 && count($uniqueWeeks) === 1 && $uniqueWeeks[0] === 24);
    
    echo "\nVerification:\n";
    echo "  All weeks = 24: " . (count($uniqueWeeks) === 1 && $uniqueWeeks[0] === 24 ? "✓ PASS" : "✗ FAIL") . "\n";
    echo "  All weekly amounts identical: " . (count($uniqueWeekly) === 1 ? "✓ PASS" : "✗ FAIL") . "\n";
    
    echo "\nTest 4 Result: " . ($test4Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";
    
    // =================================================================
    // TEST 5: Schedule Generation
    // =================================================================
    
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "TEST 5: Schedule Generation (3M Principal, 3% Rate, 6 Months)\n";
    echo str_repeat("-", 80) . "\n";
    
    // Create a test loan
    $db->prepare("INSERT INTO `loan_products` (`name`, `code`, `status`) VALUES ('Test BB', 'TESTBB', 'active')")->execute();
    $productId = $db->lastInsertId();
    
    $db->prepare("INSERT INTO `members` (`member_no`, `first_name`, `last_name`, `email`, `status`) VALUES ('TEST001', 'Test', 'Member', 'test@test.com', 'active')")->execute();
    $memberId = $db->lastInsertId();
    
    $db->prepare(
        "INSERT INTO `loans` (`member_id`, `loan_product_id`, `principal`, `interest_rate`, `term_months`, 
         `disbursement_date`, `interest_income_account_id`, `interest_receivable_account_id`, 
         `principal_receivable_account_id`, `repayment_type`, `repayment_frequency`, `status`)
         VALUES (?, ?, 3000000, 3.00, 6, '2026-09-16', 1, 1, 1, 'interest_only', 'weekly', 'pending_approval')"
    )->execute([$memberId, $productId]);
    $loanId = $db->lastInsertId();
    
    // Generate schedule
    $loanProductModel->generateBusinessBoostSchedule($loanId, 3000000, 3.0, 6, '2026-09-16');
    
    // Verify schedule
    $stmt = $db->prepare("SELECT COUNT(*) as count, SUM(principal_due) as total_principal, 
                          SUM(interest_due) as total_interest, SUM(amount_due) as total_due,
                          MIN(due_date) as first_date, MAX(due_date) as last_date
                          FROM loan_installments WHERE loan_id = ?");
    $stmt->execute([$loanId]);
    $scheduleData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "Schedule Generated:\n";
    echo "  Total Installments: {$scheduleData['count']}\n";
    echo "  Total Principal: UGX " . number_format($scheduleData['total_principal'], 2) . "\n";
    echo "  Total Interest: UGX " . number_format($scheduleData['total_interest'], 2) . "\n";
    echo "  Total Due: UGX " . number_format($scheduleData['total_due'], 2) . "\n";
    echo "  First Due Date: {$scheduleData['first_date']}\n";
    echo "  Last Due Date: {$scheduleData['last_date']}\n\n";
    
    // Verify loan metadata
    $stmt = $db->prepare("SELECT repayment_method, interest_only_months, principal_recovery_weeks FROM loans WHERE id = ?");
    $stmt->execute([$loanId]);
    $loanMeta = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "Loan Metadata:\n";
    echo "  Repayment Method: {$loanMeta['repayment_method']}\n";
    echo "  Interest Only Months: {$loanMeta['interest_only_months']}\n";
    echo "  Principal Recovery Weeks: {$loanMeta['principal_recovery_weeks']}\n\n";
    
    $test5Pass = (
        $scheduleData['count'] == 24 &&
        abs($scheduleData['total_principal'] - 3000000) < 0.01 &&
        abs($scheduleData['total_interest'] - 540000) < 0.01 &&
        abs($scheduleData['total_due'] - 3540000) < 0.01 &&
        $loanMeta['repayment_method'] === 'business_boost' &&
        $loanMeta['interest_only_months'] == 0 &&
        $loanMeta['principal_recovery_weeks'] == 24
    );
    
    echo "Verification:\n";
    echo "  Installment count = 24: " . ($scheduleData['count'] == 24 ? "✓ PASS" : "✗ FAIL") . "\n";
    echo "  Principal reconciles: " . (abs($scheduleData['total_principal'] - 3000000) < 0.01 ? "✓ PASS" : "✗ FAIL") . "\n";
    echo "  Interest reconciles: " . (abs($scheduleData['total_interest'] - 540000) < 0.01 ? "✓ PASS" : "✗ FAIL") . "\n";
    echo "  Total reconciles: " . (abs($scheduleData['total_due'] - 3540000) < 0.01 ? "✓ PASS" : "✗ FAIL") . "\n";
    echo "  Metadata correct: " . ($loanMeta['principal_recovery_weeks'] == 24 ? "✓ PASS" : "✗ FAIL") . "\n";
    
    echo "\nTest 5 Result: " . ($test5Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";
    
    // =================================================================
    // FINAL SUMMARY
    // =================================================================
    
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "FINAL TEST SUMMARY\n";
    echo str_repeat("=", 80) . "\n";
    
    $allPass = $test1Pass && $test2Pass && $test3Pass && $test4Pass && $test5Pass;
    
    echo "Test 1 (6 months calculation): " . ($test1Pass ? "✓ PASS" : "✗ FAIL") . "\n";
    echo "Test 2 (9 months calculation): " . ($test2Pass ? "✓ PASS" : "✗ FAIL") . "\n";
    echo "Test 3 (12 months calculation): " . ($test3Pass ? "✓ PASS" : "✗ FAIL") . "\n";
    echo "Test 4 (start date independence): " . ($test4Pass ? "✓ PASS" : "✗ FAIL") . "\n";
    echo "Test 5 (schedule generation): " . ($test5Pass ? "✓ PASS" : "✗ FAIL") . "\n";
    
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "OVERALL RESULT: " . ($allPass ? "✓ ALL TESTS PASS" : "✗ SOME TESTS FAILED") . "\n";
    echo str_repeat("=", 80) . "\n\n";
    
    if ($allPass) {
        echo "✓ Business Boost correctly implements the contractual formula:\n";
        echo "  1 month = 30 days = 4 weeks for installment calculation\n";
        echo "  Total Weeks = Months × 4 (NOT calendar-based)\n";
        echo "  Weekly Installment = Total Payable ÷ (Months × 4)\n\n";
    } else {
        echo "✗ Business Boost implementation does NOT match the contractual formula.\n\n";
    }
    
    $db->rollback();
    
} catch (Exception $e) {
    $db->rollback();
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n✓ Test complete (all changes rolled back)\n\n";
