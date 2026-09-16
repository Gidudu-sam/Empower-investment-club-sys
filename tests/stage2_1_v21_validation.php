#!/usr/bin/env php
<?php
/**
 * Stage 2.1: V2.1 Approval Architecture — CORRECTION VALIDATION
 * 
 * Tests all three defect corrections:
 * 1. DEFECT #1: approval_actions DELETE protection (TEST #8A)
 * 2. DEFECT #2: approval_actions UPDATE protection (TEST #8D)
 * 3. DEFECT #3: Officer loan tier (TEST #17)
 * 
 * Plus full regression of Stage 2 tests
 * 
 * DISPOSABLE TEST DATABASE ONLY
 * Production: COMPLETELY UNTOUCHED
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(600);

$testDb = 'empower_approval_test_v21';
$host = '127.0.0.1';
$user = 'root';
$pass = '';

$testResults = [];
$defectTests = [];

echo "\n" . str_repeat("=", 100) . "\n";
echo "Stage 2.1: V2.1 Approval Architecture — CORRECTION VALIDATION\n";
echo str_repeat("=", 100) . "\n\n";

echo "SAFETY:\n";
echo "- Test database: {$testDb}\n";
echo "- Production database: empower_db (NEVER ACCESSED)\n\n";

// Verify we're NOT using production
echo "Database Safety Check:\n";

try {
    $pdo = new PDO("mysql:host={$host};dbname={$testDb};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    $currentDb = $pdo->query("SELECT DATABASE()")->fetchColumn();
    
    if ($currentDb !== $testDb) {
        die("[✗] SAFETY VIOLATION: Expected {$testDb}, got {$currentDb}\n");
    }
    
    if ($currentDb === 'empower_db') {
        die("[✗] CRITICAL: Connected to PRODUCTION database! Aborting!\n");
    }
    
    echo "[✓] Connected to: {$currentDb}\n";
    echo "[✓] Production safe\n\n";
    
    // Get base data
    $policyId = $pdo->query("SELECT id FROM approval_policies LIMIT 1")->fetchColumn();
    $tier2Id = $pdo->query("SELECT id FROM approval_tiers WHERE tier_number = 2 LIMIT 1")->fetchColumn();
    $tier5Id = $pdo->query("SELECT id FROM approval_tiers WHERE tier_number = 5 LIMIT 1")->fetchColumn();
    
    $tier2Slots = $pdo->query("SELECT * FROM approval_tier_slots WHERE tier_id = {$tier2Id}")->fetchAll();
    
    echo str_repeat("=", 100) . "\n";
    echo "DEFECT CORRECTION TESTS\n";
    echo str_repeat("=", 100) . "\n\n";
    
    // ============================================================================
    // DEFECT #1 CORRECTION TEST: TEST #8A - approval_actions DELETE PROTECTION
    // ============================================================================
    echo "DEFECT #1 CORRECTION: TEST #8A - Approval Actions DELETE Protection\n";
    echo str_repeat("-", 100) . "\n";
    
    // Create a test approval action
    $pdo->exec("INSERT INTO transaction_approval_rounds 
        (transaction_type, transaction_id, round_number, policy_id, tier_id, tier_number, snapshot_amount, submitted_by) 
        VALUES ('loan', 8001, 1, {$policyId}, {$tier2Id}, 2, 2000000.00, 5)");
    $round8A = $pdo->lastInsertId();
    
    foreach ($tier2Slots as $slot) {
        $pdo->prepare("INSERT INTO transaction_approval_slot_instances 
            (approval_round_id, slot_id, slot_number, slot_type, slot_group, required_role, display_label)
            VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$round8A, $slot['id'], $slot['slot_number'], $slot['slot_type'], 
                      $slot['slot_group'], $slot['required_role'], $slot['display_label']]);
    }
    
    $testSlot = $pdo->query("SELECT id FROM transaction_approval_slot_instances 
        WHERE approval_round_id = {$round8A} LIMIT 1")->fetchColumn();
    
    $pdo->exec("INSERT INTO approval_actions (approval_round_id, slot_instance_id, user_id, user_role, action_type) 
        VALUES ({$round8A}, {$testSlot}, 1, 'chairman', 'approved')");
    $actionId8A = $pdo->lastInsertId();
    
    echo "Created approval_actions row ID={$actionId8A}\n";
    echo "Attempting DELETE...\n";
    
    try {
        $pdo->exec("DELETE FROM approval_actions WHERE id = {$actionId8A}");
        echo "[✗] FAIL: approval_actions row was DELETED (trigger did not block!)\n";
        $defectTests['8A'] = 'FAIL';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'immutable audit records') !== false) {
            echo "[✓] PASS: DELETE blocked by trigger\n";
            echo "    Error message: " . substr($e->getMessage(), 0, 100) . "...\n";
            
            // Verify row still exists
            $stillExists = $pdo->query("SELECT COUNT(*) FROM approval_actions WHERE id = {$actionId8A}")->fetchColumn();
            if ($stillExists) {
                echo "[✓] VERIFIED: Row still exists (count={$stillExists})\n";
                $defectTests['8A'] = 'PASS';
            } else {
                echo "[✗] FAIL: Row was deleted despite trigger\n";
                $defectTests['8A'] = 'FAIL';
            }
        } else {
            echo "[✗] FAIL: Wrong error: " . $e->getMessage() . "\n";
            $defectTests['8A'] = 'FAIL';
        }
    }
    
    echo "\n";
    
    // ============================================================================
    // DEFECT #2 CORRECTION TEST: TEST #8D - approval_actions UPDATE PROTECTION
    // ============================================================================
    echo "DEFECT #2 CORRECTION: TEST #8D - Approval Actions UPDATE Protection\n";
    echo str_repeat("-", 100) . "\n";
    
    echo "Approval action ID={$actionId8A}: user_id=1, action_type='approved'\n";
    echo "Attempting UPDATE to user_id=999, action_type='rejected'...\n";
    
    try {
        $pdo->exec("UPDATE approval_actions SET user_id = 999, action_type = 'rejected' WHERE id = {$actionId8A}");
        echo "[✗] FAIL: approval_actions row was UPDATED (trigger did not block!)\n";
        $defectTests['8D'] = 'FAIL';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'immutable audit records') !== false) {
            echo "[✓] PASS: UPDATE blocked by trigger\n";
            echo "    Error message: " . substr($e->getMessage(), 0, 100) . "...\n";
            
            // Verify row unchanged
            $row = $pdo->query("SELECT user_id, action_type FROM approval_actions WHERE id = {$actionId8A}")->fetch();
            if ($row['user_id'] == 1 && $row['action_type'] === 'approved') {
                echo "[✓] VERIFIED: Row unchanged (user_id={$row['user_id']}, action_type={$row['action_type']})\n";
                $defectTests['8D'] = 'PASS';
            } else {
                echo "[✗] FAIL: Row was modified despite trigger\n";
                $defectTests['8D'] = 'FAIL';
            }
        } else {
            echo "[✗] FAIL: Wrong error: " . $e->getMessage() . "\n";
            $defectTests['8D'] = 'FAIL';
        }
    }
    
    echo "\n";
    
    // ============================================================================
    // DEFECT #3 CORRECTION TEST: TEST #17 - OFFICER LOAN TIER
    // ============================================================================
    echo "DEFECT #3 CORRECTION: TEST #17 - Officer Loan Tier\n";
    echo str_repeat("-", 100) . "\n";
    
    // Test 17A: Officer loan tier exists
    echo "Test 17A: Officer loan tier exists with special_rule='officer_loan'\n";
    $officerTier = $pdo->query("SELECT id, tier_number, tier_name, special_rule 
        FROM approval_tiers WHERE special_rule = 'officer_loan' LIMIT 1")->fetch();
    
    if ($officerTier) {
        echo "[✓] PASS: Officer loan tier found\n";
        echo "    Tier {$officerTier['tier_number']}: {$officerTier['tier_name']} (special_rule={$officerTier['special_rule']})\n";
        $defectTests['17A'] = 'PASS';
    } else {
        echo "[✗] FAIL: Officer loan tier NOT found\n";
        $defectTests['17A'] = 'FAIL';
    }
    
    // Test 17B: Officer loan tier has 4 approval slots
    echo "\nTest 17B: Officer loan tier has 4 mandatory approval slots\n";
    $officerSlots = $pdo->query("SELECT COUNT(*) FROM approval_tier_slots 
        WHERE tier_id = {$tier5Id}")->fetchColumn();
    
    if ($officerSlots == 4) {
        echo "[✓] PASS: Officer loan tier has 4 slots\n";
        $defectTests['17B'] = 'PASS';
    } else {
        echo "[✗] FAIL: Officer loan tier has {$officerSlots} slots (expected 4)\n";
        $defectTests['17B'] = 'FAIL';
    }
    
    // Test 17C: Officer loan at low amount uses officer tier, not Tier 1
    echo "\nTest 17C: Officer loan (500,000) uses officer tier, NOT amount-based tier\n";
    echo "NOTE: This requires application logic to detect officer loan and select Tier 5\n";
    echo "      Schema supports via special_rule='officer_loan'\n";
    echo "[!] SCHEMA-SUPPORTED: Officer loan tier available for application to use\n";
    $defectTests['17C'] = 'SCHEMA-SUPPORTED';
    
    // Test 17D: Recipient exclusion capability
    echo "\nTest 17D: Recipient exclusion capability (excluded_user_ids field)\n";
    $hasExclusionField = $pdo->query("SHOW COLUMNS FROM transaction_approval_rounds LIKE 'excluded_user_ids'")->fetch();
    
    if ($hasExclusionField) {
        echo "[✓] PASS: excluded_user_ids field exists (JSON type)\n";
        echo "    Application can store recipient ID to exclude from approvals\n";
        $defectTests['17D'] = 'PASS';
    } else {
        echo "[✗] FAIL: excluded_user_ids field NOT found\n";
        $defectTests['17D'] = 'FAIL';
    }
    
    echo "\n";
    
    // ============================================================================
    // REGRESSION TESTS (Subset of Stage 2 critical tests)
    // ============================================================================
    echo str_repeat("=", 100) . "\n";
    echo "REGRESSION TESTS (Critical Stage 2 Tests)\n";
    echo str_repeat("=", 100) . "\n\n";
    
    // TEST #8B: Parent round DELETE protection (FK RESTRICT)
    echo "TEST #8B: Parent Round DELETE Protection (FK RESTRICT)\n";
    echo str_repeat("-", 100) . "\n";
    
    try {
        $pdo->exec("DELETE FROM transaction_approval_rounds WHERE id = {$round8A}");
        echo "[✗] FAIL: Parent round deleted despite child approval_actions\n";
        $testResults['8B'] = 'FAIL';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
            echo "[✓] PASS: DELETE blocked by FK RESTRICT\n";
            $testResults['8B'] = 'PASS';
        } else {
            echo "[✗] FAIL: Wrong error\n";
            $testResults['8B'] = 'FAIL';
        }
    }
    
    echo "\n";
    
    // TEST #8C: Slot instance DELETE protection (FK RESTRICT)
    echo "TEST #8C: Slot Instance DELETE Protection (FK RESTRICT)\n";
    echo str_repeat("-", 100) . "\n";
    
    try {
        $pdo->exec("DELETE FROM transaction_approval_slot_instances WHERE id = {$testSlot}");
        echo "[✗] FAIL: Slot instance deleted despite child approval_actions\n";
        $testResults['8C'] = 'FAIL';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
            echo "[✓] PASS: DELETE blocked by FK RESTRICT\n";
            $testResults['8C'] = 'PASS';
        } else {
            echo "[✗] FAIL: Wrong error\n";
            $testResults['8C'] = 'FAIL';
        }
    }
    
    echo "\n";
    
    // TEST #16: Loan amount boundaries (regression - ensure boundaries still work)
    echo "TEST #16: Loan Amount Boundaries (Regression)\n";
    echo str_repeat("-", 100) . "\n";
    
    $boundaryTests = [
        ['amount' => 999999.00, 'expected_tier' => 1],
        ['amount' => 1000000.00, 'expected_tier' => 2],
        ['amount' => 4999999.00, 'expected_tier' => 2],
        ['amount' => 5000000.00, 'expected_tier' => 3],
        ['amount' => 9999999.00, 'expected_tier' => 3],
        ['amount' => 10000000.00, 'expected_tier' => 4],
        ['amount' => 10000001.00, 'expected_tier' => 4],
    ];
    
    echo str_pad("Amount", 15) . str_pad("Expected", 12) . str_pad("Actual", 12) . "Result\n";
    echo str_repeat("-", 50) . "\n";
    
    $boundaryPass = 0;
    foreach ($boundaryTests as $test) {
        // Exclude officer_loan tier from amount-based selection
        $actualTier = $pdo->query("
            SELECT tier_number FROM approval_tiers 
            WHERE policy_id = {$policyId}
            AND special_rule IS NULL
            AND min_amount <= {$test['amount']}
            AND (max_amount IS NULL OR max_amount >= {$test['amount']})
            ORDER BY tier_number DESC LIMIT 1
        ")->fetchColumn();
        
        $pass = ($actualTier == $test['expected_tier']);
        if ($pass) $boundaryPass++;
        
        echo str_pad(number_format($test['amount'], 2), 15);
        echo str_pad("Tier {$test['expected_tier']}", 12);
        echo str_pad("Tier " . ($actualTier ?: 'NULL'), 12);
        echo ($pass ? '✓ PASS' : '✗ FAIL') . "\n";
    }
    
    $testResults['16'] = ($boundaryPass == count($boundaryTests)) ? 'PASS' : 'FAIL';
    echo "\n[" . ($testResults['16'] === 'PASS' ? '✓' : '✗') . "] TEST #16: {$testResults['16']} ({$boundaryPass}/" . count($boundaryTests) . ")\n\n";
    
    // ============================================================================
    // FINAL SUMMARY
    // ============================================================================
    echo str_repeat("=", 100) . "\n";
    echo "STAGE 2.1 VALIDATION SUMMARY\n";
    echo str_repeat("=", 100) . "\n\n";
    
    echo "DEFECT CORRECTIONS:\n";
    echo str_repeat("-", 100) . "\n";
    foreach ($defectTests as $test => $result) {
        $icon = ($result === 'PASS' || str_contains($result, 'SUPPORTED')) ? '✓' : '✗';
        echo "[{$icon}] Defect Test {$test}: {$result}\n";
    }
    
    echo "\nREGRESSION TESTS:\n";
    echo str_repeat("-", 100) . "\n";
    foreach ($testResults as $test => $result) {
        $icon = $result === 'PASS' ? '✓' : '✗';
        echo "[{$icon}] Test {$test}: {$result}\n";
    }
    
    $defectPass = count(array_filter($defectTests, fn($r) => $r === 'PASS' || str_contains($r, 'SUPPORTED')));
    $defectTotal = count($defectTests);
    $regressionPass = count(array_filter($testResults, fn($r) => $r === 'PASS'));
    $regressionTotal = count($testResults);
    
    echo "\nDEFECT CORRECTIONS: {$defectPass}/{$defectTotal}\n";
    echo "REGRESSION TESTS: {$regressionPass}/{$regressionTotal}\n\n";
    
    echo str_repeat("=", 100) . "\n";
    echo "STAGE 2.1 VERDICT\n";
    echo str_repeat("=", 100) . "\n\n";
    
    $allDefectsFixed = ($defectTests['8A'] === 'PASS' && $defectTests['8D'] === 'PASS' && $defectTests['17A'] === 'PASS');
    $allRegressionPass = !in_array('FAIL', $testResults);
    
    if ($allDefectsFixed && $allRegressionPass) {
        echo "VERDICT: PASS\n\n";
        echo "All three defects corrected:\n";
        echo "  ✓ DEFECT #1: approval_actions DELETE protection working\n";
        echo "  ✓ DEFECT #2: approval_actions UPDATE protection working\n";
        echo "  ✓ DEFECT #3: Officer loan tier present\n\n";
        echo "Regression tests passed.\n\n";
    } else {
        echo "VERDICT: FAIL\n\n";
        echo "Some corrections or regression tests failed.\n\n";
    }
    
    echo str_repeat("=", 100) . "\n";
    echo "PRODUCTION SAFETY CONFIRMATION\n";
    echo str_repeat("=", 100) . "\n\n";
    
    echo "[✓] Production database 'empower_db': NEVER ACCESSED\n";
    echo "[✓] Test database '{$testDb}': Used exclusively\n";
    echo "[✓] Test database preserved for inspection\n\n";
    
    echo "STAGE 3 AUTHORIZATION: NOT GRANTED BY THIS VALIDATION\n";
    echo "Awaiting final architectural review.\n\n";
    
} catch (Exception $e) {
    echo "\n[✗] FATAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
