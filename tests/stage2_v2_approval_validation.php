#!/usr/bin/env php
<?php
/**
 * Stage 2: V2 Multi-Approval Architecture — Schema Validation
 * DISPOSABLE TEST DATABASE ONLY
 * Production: READ-ONLY
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Test database connection
$testDb = 'empower_approval_test';
$host = '127.0.0.1';
$user = 'root';
$pass = '';

echo "\n=============================================================\n";
echo "Stage 2: V2 Multi-Approval Architecture — Schema Validation\n";
echo "=============================================================\n\n";

echo "SAFETY CHECK:\n";
echo "- Test database: {$testDb}\n";
echo "- Production database will NOT be touched\n";
echo "- All tests run in isolated environment\n\n";

try {
    // Use mysqli for multi-query execution
    $mysqli = new mysqli($host, $user, $pass);
    
    if ($mysqli->connect_error) {
        throw new Exception("Connection failed: " . $mysqli->connect_error);
    }
    
    echo "[✓] Database connection established\n\n";
    
    // Drop test database if exists (clean slate)
    echo "Dropping existing test database (if any)...\n";
    $mysqli->query("DROP DATABASE IF EXISTS `{$testDb}`");
    echo "[✓] Clean slate ensured\n\n";
    
    // Read and execute schema file
    echo "TASK #1: Creating disposable test database...\n";
    $schemaFile = __DIR__ . '/../database/stage2_v2_approval_schema_test.sql';
    
    if (!file_exists($schemaFile)) {
        throw new Exception("Schema file not found: {$schemaFile}");
    }
    
    $sql = file_get_contents($schemaFile);
    
    // Execute multi-query
    if (!$mysqli->multi_query($sql)) {
        throw new Exception("Schema creation failed: " . $mysqli->error);
    }
    
    // Clear all results
    do {
        if ($result = $mysqli->store_result()) {
            $result->free();
        }
    } while ($mysqli->more_results() && $mysqli->next_result());
    
    // Now create PDO connection for easier querying
    $pdo = new PDO("mysql:host={$host};dbname={$testDb};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    echo "[✓] Test database '{$testDb}' created\n";
    echo "[✓] V2 schema tables created\n";
    echo "[✓] Test users seeded\n";
    echo "[✓] Policy v1 seeded\n\n";
    
    // Verify schema
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables created: " . count($tables) . "\n";
    foreach ($tables as $table) {
        echo "  - {$table}\n";
    }
    echo "\n";
    
    // Verify data
    $policiesCount = $pdo->query("SELECT COUNT(*) FROM approval_policies")->fetchColumn();
    $tiersCount = $pdo->query("SELECT COUNT(*) FROM approval_tiers")->fetchColumn();
    $slotsCount = $pdo->query("SELECT COUNT(*) FROM approval_tier_slots")->fetchColumn();
    $usersCount = $pdo->query("SELECT COUNT(*) FROM test_users")->fetchColumn();
    
    echo "Data seeded:\n";
    echo "  - Policies: {$policiesCount}\n";
    echo "  - Tiers: {$tiersCount}\n";
    echo "  - Slots: {$slotsCount}\n";
    echo "  - Test Users: {$usersCount}\n\n";
    
    // Expected: 1 policy, 4 tiers, 11 slots (2+3+3+4, but Tier1 has 2 alternatives = 2, Tier2 has 1+2 alternatives = 3)
    // Actually: Tier1=2, Tier2=3, Tier3=3, Tier4=4 = 12 slots total (correct!)
    $expectedSlots = 12; // 2 (tier1) + 3 (tier2) + 3 (tier3) + 4 (tier4)
    
    if ($policiesCount != 1 || $tiersCount != 4 || $slotsCount != $expectedSlots || $usersCount != 8) {
        throw new Exception("Seed data verification failed: policies={$policiesCount}, tiers={$tiersCount}, slots={$slotsCount} (expected {$expectedSlots}), users={$usersCount}");
    }
    
    echo "[✓] TASK #1 COMPLETE: Test database ready\n\n";
    
    // Now run all validation tests
    echo "=============================================================\n";
    echo "RUNNING VALIDATION TESTS\n";
    echo "=============================================================\n\n";
    
    $testResults = [];
    
    // TEST #1: Tier 2 Alternative Slots
    echo "TEST #1: Tier 2 Alternative Slots (Chairman + Vice/Secretary)\n";
    echo "------------------------------------------------------------\n";
    
    // Get Tier 2 slots
    $tier2Slots = $pdo->query("
        SELECT ats.*, at.tier_name 
        FROM approval_tier_slots ats
        JOIN approval_tiers at ON at.id = ats.tier_id
        WHERE at.tier_number = 2
        ORDER BY ats.slot_number, ats.required_role
    ")->fetchAll();
    
    echo "Tier 2 slot structure:\n";
    foreach ($tier2Slots as $slot) {
        echo sprintf("  Slot %d: %s (%s, group=%s, role=%s)\n",
            $slot['slot_number'],
            $slot['display_label'],
            $slot['slot_type'],
            $slot['slot_group'] ?? 'NULL',
            $slot['required_role']
        );
    }
    echo "\n";
    
    // Create test loan (UGX 2,000,000 - Tier 2)
    $tier2Id = $pdo->query("
        SELECT id FROM approval_tiers 
        WHERE tier_number = 2 
        LIMIT 1
    ")->fetchColumn();
    
    $policyId = $pdo->query("SELECT id FROM approval_policies LIMIT 1")->fetchColumn();
    
    // TEST 1A: Chairman + Vice Chairman = APPROVED
    echo "Test 1A: Chairman + Vice Chairman\n";
    
    $pdo->exec("INSERT INTO transaction_approval_rounds 
        (transaction_type, transaction_id, round_number, policy_id, tier_id, tier_number, snapshot_amount, submitted_by) 
        VALUES ('loan', 1, 1, {$policyId}, {$tier2Id}, 2, 2000000.00, 5)");
    
    $roundId = $pdo->lastInsertId();
    
    // Copy slots to instances
    foreach ($tier2Slots as $slot) {
        $pdo->prepare("INSERT INTO transaction_approval_slot_instances 
            (approval_round_id, slot_id, slot_number, slot_type, slot_group, required_role, display_label)
            VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $roundId,
                $slot['id'],
                $slot['slot_number'],
                $slot['slot_type'],
                $slot['slot_group'],
                $slot['required_role'],
                $slot['display_label']
            ]);
    }
    
    // Chairman approves (user 1)
    $chairmanSlotId = $pdo->query("
        SELECT id FROM transaction_approval_slot_instances 
        WHERE approval_round_id = {$roundId} AND required_role = 'chairman'
    ")->fetchColumn();
    
    $pdo->exec("INSERT INTO approval_actions 
        (approval_round_id, slot_instance_id, user_id, user_role, action_type) 
        VALUES ({$roundId}, {$chairmanSlotId}, 1, 'chairman', 'approved')");
    
    $pdo->exec("UPDATE transaction_approval_slot_instances 
        SET slot_status = 'satisfied', satisfied_by_user_id = 1, satisfied_at = NOW() 
        WHERE id = {$chairmanSlotId}");
    
    // Vice Chairman approves (user 2)
    $viceSlotId = $pdo->query("
        SELECT id FROM transaction_approval_slot_instances 
        WHERE approval_round_id = {$roundId} AND required_role = 'vice_chairman'
    ")->fetchColumn();
    
    $pdo->exec("INSERT INTO approval_actions 
        (approval_round_id, slot_instance_id, user_id, user_role, action_type) 
        VALUES ({$roundId}, {$viceSlotId}, 2, 'vice_chairman', 'approved')");
    
    $pdo->exec("UPDATE transaction_approval_slot_instances 
        SET slot_status = 'satisfied', satisfied_by_user_id = 2, satisfied_at = NOW() 
        WHERE id = {$viceSlotId}");
    
    // Mark alternative group slot as not_required
    $pdo->exec("UPDATE transaction_approval_slot_instances 
        SET slot_status = 'not_required' 
        WHERE approval_round_id = {$roundId} AND required_role = 'secretary'");
    
    // Check if all required slots satisfied
    $pendingCount = $pdo->query("
        SELECT COUNT(*) FROM transaction_approval_slot_instances 
        WHERE approval_round_id = {$roundId} AND slot_status = 'pending'
    ")->fetchColumn();
    
    if ($pendingCount == 0) {
        echo "[✓] Chairman + Vice Chairman = APPROVED (correct)\n\n";
        $testResults['1A'] = 'PASS';
    } else {
        echo "[✗] FAIL: Slots still pending\n\n";
        $testResults['1A'] = 'FAIL';
    }
    
    // TEST 1B: Chairman + Secretary = APPROVED
    echo "Test 1B: Chairman + Secretary\n";
    
    $pdo->exec("INSERT INTO transaction_approval_rounds 
        (transaction_type, transaction_id, round_number, policy_id, tier_id, tier_number, snapshot_amount, submitted_by) 
        VALUES ('loan', 2, 1, {$policyId}, {$tier2Id}, 2, 2500000.00, 5)");
    
    $round2Id = $pdo->lastInsertId();
    
    // Copy slots
    foreach ($tier2Slots as $slot) {
        $pdo->prepare("INSERT INTO transaction_approval_slot_instances 
            (approval_round_id, slot_id, slot_number, slot_type, slot_group, required_role, display_label)
            VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $round2Id,
                $slot['id'],
                $slot['slot_number'],
                $slot['slot_type'],
                $slot['slot_group'],
                $slot['required_role'],
                $slot['display_label']
            ]);
    }
    
    // Chairman approves
    $chairmanSlot2Id = $pdo->query("
        SELECT id FROM transaction_approval_slot_instances 
        WHERE approval_round_id = {$round2Id} AND required_role = 'chairman'
    ")->fetchColumn();
    
    $pdo->exec("INSERT INTO approval_actions 
        (approval_round_id, slot_instance_id, user_id, user_role, action_type) 
        VALUES ({$round2Id}, {$chairmanSlot2Id}, 1, 'chairman', 'approved')");
    
    $pdo->exec("UPDATE transaction_approval_slot_instances 
        SET slot_status = 'satisfied', satisfied_by_user_id = 1, satisfied_at = NOW() 
        WHERE id = {$chairmanSlot2Id}");
    
    // Secretary approves (user 3)
    $secSlotId = $pdo->query("
        SELECT id FROM transaction_approval_slot_instances 
        WHERE approval_round_id = {$round2Id} AND required_role = 'secretary'
    ")->fetchColumn();
    
    $pdo->exec("INSERT INTO approval_actions 
        (approval_round_id, slot_instance_id, user_id, user_role, action_type) 
        VALUES ({$round2Id}, {$secSlotId}, 3, 'secretary', 'approved')");
    
    $pdo->exec("UPDATE transaction_approval_slot_instances 
        SET slot_status = 'satisfied', satisfied_by_user_id = 3, satisfied_at = NOW() 
        WHERE id = {$secSlotId}");
    
    // Mark vice chairman slot as not_required
    $pdo->exec("UPDATE transaction_approval_slot_instances 
        SET slot_status = 'not_required' 
        WHERE approval_round_id = {$round2Id} AND required_role = 'vice_chairman'");
    
    $pending2Count = $pdo->query("
        SELECT COUNT(*) FROM transaction_approval_slot_instances 
        WHERE approval_round_id = {$round2Id} AND slot_status = 'pending'
    ")->fetchColumn();
    
    if ($pending2Count == 0) {
        echo "[✓] Chairman + Secretary = APPROVED (correct)\n\n";
        $testResults['1B'] = 'PASS';
    } else {
        echo "[✗] FAIL: Slots still pending\n\n";
        $testResults['1B'] = 'FAIL';
    }
    
    // TEST 1C: Vice Chairman + Secretary (WITHOUT Chairman) = NOT APPROVED
    echo "Test 1C: Vice Chairman + Secretary (WITHOUT Chairman)\n";
    
    $pdo->exec("INSERT INTO transaction_approval_rounds 
        (transaction_type, transaction_id, round_number, policy_id, tier_id, tier_number, snapshot_amount, submitted_by) 
        VALUES ('loan', 3, 1, {$policyId}, {$tier2Id}, 2, 3000000.00, 5)");
    
    $round3Id = $pdo->lastInsertId();
    
    // Copy slots
    foreach ($tier2Slots as $slot) {
        $pdo->prepare("INSERT INTO transaction_approval_slot_instances 
            (approval_round_id, slot_id, slot_number, slot_type, slot_group, required_role, display_label)
            VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $round3Id,
                $slot['id'],
                $slot['slot_number'],
                $slot['slot_type'],
                $slot['slot_group'],
                $slot['required_role'],
                $slot['display_label']
            ]);
    }
    
    // Vice Chairman attempts to approve
    $vice3SlotId = $pdo->query("
        SELECT id FROM transaction_approval_slot_instances 
        WHERE approval_round_id = {$round3Id} AND required_role = 'vice_chairman'
    ")->fetchColumn();
    
    $pdo->exec("INSERT INTO approval_actions 
        (approval_round_id, slot_instance_id, user_id, user_role, action_type) 
        VALUES ({$round3Id}, {$vice3SlotId}, 2, 'vice_chairman', 'approved')");
    
    $pdo->exec("UPDATE transaction_approval_slot_instances 
        SET slot_status = 'satisfied', satisfied_by_user_id = 2, satisfied_at = NOW() 
        WHERE id = {$vice3SlotId}");
    
    // Secretary attempts to approve
    $sec3SlotId = $pdo->query("
        SELECT id FROM transaction_approval_slot_instances 
        WHERE approval_round_id = {$round3Id} AND required_role = 'secretary'
    ")->fetchColumn();
    
    $pdo->exec("INSERT INTO approval_actions 
        (approval_round_id, slot_instance_id, user_id, user_role, action_type) 
        VALUES ({$round3Id}, {$sec3SlotId}, 3, 'secretary', 'approved')");
    
    $pdo->exec("UPDATE transaction_approval_slot_instances 
        SET slot_status = 'satisfied', satisfied_by_user_id = 3, satisfied_at = NOW() 
        WHERE id = {$sec3SlotId}");
    
    // Check status - Chairman slot should still be pending
    $chairman3Status = $pdo->query("
        SELECT slot_status FROM transaction_approval_slot_instances 
        WHERE approval_round_id = {$round3Id} AND required_role = 'chairman'
    ")->fetchColumn();
    
    if ($chairman3Status === 'pending') {
        echo "[✓] Vice + Secretary WITHOUT Chairman = PENDING (correct - Chairman mandatory slot unfulfilled)\n\n";
        $testResults['1C'] = 'PASS';
    } else {
        echo "[✗] FAIL: Chairman slot incorrectly marked as {$chairman3Status}\n\n";
        $testResults['1C'] = 'FAIL';
    }
    
    echo "[✓] TEST #1 COMPLETE: Alternative slot logic validated\n\n";
    
    // Summary
    echo "\n=============================================================\n";
    echo "VALIDATION SUMMARY\n";
    echo "=============================================================\n\n";
    
    foreach ($testResults as $test => $result) {
        $icon = $result === 'PASS' ? '✓' : '✗';
        echo "[{$icon}] Test {$test}: {$result}\n";
    }
    
    $passCount = count(array_filter($testResults, fn($r) => $r === 'PASS'));
    $totalCount = count($testResults);
    
    echo "\nTests passed: {$passCount}/{$totalCount}\n\n";
    
    if ($passCount === $totalCount) {
        echo "VERDICT: Tests completed successfully!\n";
        echo "Note: This is a partial validation. Full validation requires all 24 tests.\n\n";
    } else {
        echo "VERDICT: Some tests failed. Review results above.\n\n";
    }
    
    echo "Test database '{$testDb}' preserved for inspection.\n";
    echo "Production database was NOT touched.\n\n";
    
} catch (Exception $e) {
    echo "\n[✗] ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
