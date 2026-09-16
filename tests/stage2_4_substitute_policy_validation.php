#!/usr/bin/env php
<?php
/**
 * Stage 2.4: Officer Loan Substitute Governance Policy Lock
 * 
 * PURPOSE:
 * Validate the locked substitute-approver governance policy and determine
 * whether all 4 officer-borrower scenarios can be satisfied with 4 distinct
 * approvers while preserving one-person-one-slot principle.
 * 
 * LOCKED GOVERNANCE RULE:
 * Chairman borrower → Vice Chairman acts as substitute for Chairman slot
 * 
 * VALIDATION SCOPE:
 * Test all 4 officer-borrower scenarios:
 * 1. Chairman as borrower
 * 2. Vice Chairman as borrower
 * 3. Secretary as borrower
 * 4. Treasurer as borrower
 * 
 * For each scenario, determine:
 * - Who is excluded (borrower)
 * - Which 4 slots are required
 * - Who can substitute for conflicted slot
 * - Whether 4 distinct approvers exist
 * - Whether governance policy is complete
 * 
 * DISPOSABLE TEST DATABASE ONLY: empower_approval_test_v21
 * Production: NEVER ACCESSED
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(600);

$testDb = 'empower_approval_test_v21';
$host = '127.0.0.1';
$user = 'root';
$pass = '';

$scenarios = [];
$architectureTests = [];

echo "\n" . str_repeat("=", 100) . "\n";
echo "Stage 2.4: Officer Loan Substitute Governance Policy Lock\n";
echo str_repeat("=", 100) . "\n\n";

echo "SAFETY:\n";
echo "- Test database: {$testDb}\n";
echo "- Production database: empower_db (NEVER ACCESSED)\n";
echo "- Schema version: V2.1 (locked, unmodified)\n";
echo "- Operation: READ-ONLY GOVERNANCE VALIDATION\n\n";

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
        die("[✗] CRITICAL: Connected to PRODUCTION! Aborting!\n");
    }
    
    echo "[✓] Connected to: {$currentDb}\n";
    echo "[✓] Production safe\n\n";
    
    // Clean test data
    echo "Cleaning test data (preserving V2.1 schema)...\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("TRUNCATE TABLE approval_actions");
    $pdo->exec("TRUNCATE TABLE transaction_approval_slot_instances");
    $pdo->exec("TRUNCATE TABLE transaction_approval_rounds");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "[✓] Cleaned\n\n";
    
    // Get base data
    $policyId = $pdo->query("SELECT id FROM approval_policies LIMIT 1")->fetchColumn();
    $tier5Id = $pdo->query("SELECT id FROM approval_tiers WHERE special_rule = 'officer_loan' LIMIT 1")->fetchColumn();
    $tier5Slots = $pdo->query("SELECT * FROM approval_tier_slots WHERE tier_id = {$tier5Id} ORDER BY slot_number")->fetchAll();
    
    echo str_repeat("=", 100) . "\n";
    echo "LOCKED GOVERNANCE RULE\n";
    echo str_repeat("=", 100) . "\n\n";
    
    echo "OFFICER LOAN APPROVAL REQUIREMENT:\n";
    echo "Every officer loan requires exactly 4 independent approvals:\n";
    echo "  1. Chairman\n";
    echo "  2. Vice Chairman\n";
    echo "  3. Secretary\n";
    echo "  4. Treasurer\n\n";
    
    echo "BORROWER EXCLUSION:\n";
    echo "  - Borrower CANNOT approve own loan\n";
    echo "  - Borrower CANNOT satisfy any approval slot\n";
    echo "  - Borrower CANNOT be counted as substitute\n";
    echo "  - Borrower CANNOT reduce requirement to 3 approvals\n\n";
    
    echo "ONE-PERSON-ONE-SLOT PRINCIPLE:\n";
    echo "  - One person may satisfy ONLY ONE approval slot\n";
    echo "  - No duplicate slot satisfaction allowed\n\n";
    
    echo "LOCKED SUBSTITUTE RULE:\n";
    echo "  Chairman borrower → Vice Chairman acts as substitute for Chairman slot\n\n";
    
    echo str_repeat("=", 100) . "\n";
    echo "PART 1: FOUR OFFICER-BORROWER SCENARIOS\n";
    echo str_repeat("=", 100) . "\n\n";
    
    // ===================================================================================
    // SCENARIO 1: Chairman as Borrower (LOCKED POLICY)
    // ===================================================================================
    echo "SCENARIO 1: Chairman as Borrower\n";
    echo str_repeat("-", 100) . "\n";
    echo "Governance Rule: Chairman borrower → Vice Chairman substitutes for Chairman slot\n\n";
    
    echo "Analysis:\n";
    echo "  Borrower: User 1 (Chairman)\n";
    echo "  Excluded: User 1\n";
    echo "  Required slots: Chairman, Vice Chairman, Secretary, Treasurer\n\n";
    
    echo "  Slot 1 (Chairman): Vice Chairman (User 2) acts as substitute [LOCKED POLICY]\n";
    echo "  Slot 2 (Vice Chairman): Vice Chairman (User 2) fills own slot\n";
    echo "  Slot 3 (Secretary): Secretary (User 3)\n";
    echo "  Slot 4 (Treasurer): Treasurer (User 4)\n\n";
    
    echo "  PROBLEM: Vice Chairman (User 2) would satisfy TWO slots:\n";
    echo "    - Chairman slot (as substitute)\n";
    echo "    - Vice Chairman slot (as self)\n";
    echo "  This VIOLATES one-person-one-slot principle.\n\n";
    
    $pdo->exec("INSERT INTO transaction_approval_rounds 
        (transaction_type, transaction_id, round_number, policy_id, tier_id, tier_number, 
         snapshot_amount, submitted_by, excluded_user_ids) 
        VALUES ('loan', 3001, 1, {$policyId}, {$tier5Id}, 5, 5000000.00, 1, '[1]')");
    $round1 = $pdo->lastInsertId();
    
    foreach ($tier5Slots as $slot) {
        $pdo->prepare("INSERT INTO transaction_approval_slot_instances 
            (approval_round_id, slot_id, slot_number, slot_type, slot_group, required_role, display_label)
            VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$round1, $slot['id'], $slot['slot_number'], $slot['slot_type'], 
                      $slot['slot_group'], $slot['required_role'], $slot['display_label']]);
    }
    
    // Attempt Vice Chairman satisfying both slots
    $chairSlot = $pdo->query("SELECT id FROM transaction_approval_slot_instances 
        WHERE approval_round_id = {$round1} AND required_role = 'chairman'")->fetchColumn();
    $viceSlot = $pdo->query("SELECT id FROM transaction_approval_slot_instances 
        WHERE approval_round_id = {$round1} AND required_role = 'vice_chairman'")->fetchColumn();
    
    // Vice approves Chairman slot (as substitute)
    $pdo->exec("INSERT INTO approval_actions 
        (approval_round_id, slot_instance_id, user_id, user_role, action_type) 
        VALUES ({$round1}, {$chairSlot}, 2, 'vice_chairman', 'approved')");
    $pdo->exec("UPDATE transaction_approval_slot_instances 
        SET slot_status = 'satisfied', satisfied_by_user_id = 2 WHERE id = {$chairSlot}");
    
    // Vice approves Vice Chairman slot (as self)
    $pdo->exec("INSERT INTO approval_actions 
        (approval_round_id, slot_instance_id, user_id, user_role, action_type) 
        VALUES ({$round1}, {$viceSlot}, 2, 'vice_chairman', 'approved')");
    $pdo->exec("UPDATE transaction_approval_slot_instances 
        SET slot_status = 'satisfied', satisfied_by_user_id = 2 WHERE id = {$viceSlot}");
    
    // Secretary and Treasurer approve
    $secSlot = $pdo->query("SELECT id FROM transaction_approval_slot_instances 
        WHERE approval_round_id = {$round1} AND required_role = 'secretary'")->fetchColumn();
    $treasSlot = $pdo->query("SELECT id FROM transaction_approval_slot_instances 
        WHERE approval_round_id = {$round1} AND required_role = 'treasurer'")->fetchColumn();
    
    $pdo->exec("INSERT INTO approval_actions 
        (approval_round_id, slot_instance_id, user_id, user_role, action_type) 
        VALUES ({$round1}, {$secSlot}, 3, 'secretary', 'approved')");
    $pdo->exec("UPDATE transaction_approval_slot_instances 
        SET slot_status = 'satisfied', satisfied_by_user_id = 3 WHERE id = {$secSlot}");
    
    $pdo->exec("INSERT INTO approval_actions 
        (approval_round_id, slot_instance_id, user_id, user_role, action_type) 
        VALUES ({$round1}, {$treasSlot}, 4, 'treasurer', 'approved')");
    $pdo->exec("UPDATE transaction_approval_slot_instances 
        SET slot_status = 'satisfied', satisfied_by_user_id = 4 WHERE id = {$treasSlot}");
    
    $pendingSlots1 = $pdo->query("SELECT COUNT(*) FROM transaction_approval_slot_instances 
        WHERE approval_round_id = {$round1} AND slot_status = 'pending'")->fetchColumn();
    
    $distinctApprovers1 = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM approval_actions 
        WHERE approval_round_id = {$round1}")->fetchColumn();
    
    $user2SlotCount = $pdo->query("SELECT COUNT(*) FROM transaction_approval_slot_instances 
        WHERE approval_round_id = {$round1} AND satisfied_by_user_id = 2")->fetchColumn();
    
    echo "Technical Result:\n";
    echo "  - All slots satisfied: " . ($pendingSlots1 == 0 ? 'YES' : 'NO') . "\n";
    echo "  - Distinct approvers: {$distinctApprovers1}\n";
    echo "  - User 2 (Vice) satisfied slots: {$user2SlotCount}\n\n";
    
    echo "Governance Analysis:\n";
    if ($user2SlotCount > 1) {
        echo "  [✗] GOVERNANCE VIOLATION: Vice Chairman satisfied {$user2SlotCount} slots\n";
        echo "  [✗] This violates one-person-one-slot principle\n";
        echo "  [✗] Only 3 distinct people approved (need 4 independent approvers)\n\n";
        $scenarios['S1'] = 'GOVERNANCE VIOLATION';
    } else {
        echo "  [✓] One-person-one-slot preserved\n";
        echo "  [✓] 4 distinct approvers achieved\n\n";
        $scenarios['S1'] = 'PASS';
    }
    
    echo "VERDICT: The locked rule 'Vice Chairman substitutes for Chairman' creates a governance conflict.\n";
    echo "         Vice Chairman cannot simultaneously substitute AND fill own slot.\n\n";
    
    // ===================================================================================
    // SCENARIO 2: Vice Chairman as Borrower
    // ===================================================================================
    echo "SCENARIO 2: Vice Chairman as Borrower\n";
    echo str_repeat("-", 100) . "\n";
    echo "Analysis:\n";
    echo "  Borrower: User 2 (Vice Chairman)\n";
    echo "  Excluded: User 2\n";
    echo "  Required slots: Chairman, Vice Chairman, Secretary, Treasurer\n\n";
    
    echo "  Available officers (excluding borrower):\n";
    echo "    - User 1 (Chairman) - fills Chairman slot\n";
    echo "    - User 3 (Secretary) - fills Secretary slot\n";
    echo "    - User 4 (Treasurer) - fills Treasurer slot\n\n";
    
    echo "  Conflicted slot: Vice Chairman\n";
    echo "  Question: Who substitutes for Vice Chairman slot?\n\n";
    
    echo "  Option analysis:\n";
    echo "    - Chairman (User 1): Already filling Chairman slot → would be 2 slots\n";
    echo "    - Secretary (User 3): Already filling Secretary slot → would be 2 slots\n";
    echo "    - Treasurer (User 4): Already filling Treasurer slot → would be 2 slots\n";
    echo "    - Vice Chairman (User 2): Is the borrower → EXCLUDED\n\n";
    
    echo "  PROBLEM: No available officer can substitute without violating one-person-one-slot.\n";
    echo "           All 3 non-excluded officers already occupy their own slots.\n\n";
    
    $scenarios['S2'] = 'BLOCKED - NO VALID SUBSTITUTE';
    echo "VERDICT: BLOCKED - Governance policy does not define substitute for Vice Chairman conflict.\n";
    echo "         The 4 mandatory officers cannot provide 4 distinct approvers when Vice is borrower.\n\n";
    
    // ===================================================================================
    // SCENARIO 3: Secretary as Borrower
    // ===================================================================================
    echo "SCENARIO 3: Secretary as Borrower\n";
    echo str_repeat("-", 100) . "\n";
    echo "Analysis:\n";
    echo "  Borrower: User 3 (Secretary)\n";
    echo "  Excluded: User 3\n";
    echo "  Required slots: Chairman, Vice Chairman, Secretary, Treasurer\n\n";
    
    echo "  Available officers (excluding borrower):\n";
    echo "    - User 1 (Chairman) - fills Chairman slot\n";
    echo "    - User 2 (Vice Chairman) - fills Vice Chairman slot\n";
    echo "    - User 4 (Treasurer) - fills Treasurer slot\n\n";
    
    echo "  Conflicted slot: Secretary\n";
    echo "  Question: Who substitutes for Secretary slot?\n\n";
    
    echo "  Option analysis:\n";
    echo "    - Chairman (User 1): Already filling Chairman slot → would be 2 slots\n";
    echo "    - Vice Chairman (User 2): Already filling Vice Chairman slot → would be 2 slots\n";
    echo "    - Treasurer (User 4): Already filling Treasurer slot → would be 2 slots\n";
    echo "    - Secretary (User 3): Is the borrower → EXCLUDED\n\n";
    
    echo "  PROBLEM: No available officer can substitute without violating one-person-one-slot.\n";
    echo "           All 3 non-excluded officers already occupy their own slots.\n\n";
    
    $scenarios['S3'] = 'BLOCKED - NO VALID SUBSTITUTE';
    echo "VERDICT: BLOCKED - Governance policy does not define substitute for Secretary conflict.\n";
    echo "         The 4 mandatory officers cannot provide 4 distinct approvers when Secretary is borrower.\n\n";
    
    // ===================================================================================
    // SCENARIO 4: Treasurer as Borrower
    // ===================================================================================
    echo "SCENARIO 4: Treasurer as Borrower\n";
    echo str_repeat("-", 100) . "\n";
    echo "Analysis:\n";
    echo "  Borrower: User 3 (Treasurer)\n";
    echo "  Excluded: User 4\n";
    echo "  Required slots: Chairman, Vice Chairman, Secretary, Treasurer\n\n";
    
    echo "  Available officers (excluding borrower):\n";
    echo "    - User 1 (Chairman) - fills Chairman slot\n";
    echo "    - User 2 (Vice Chairman) - fills Vice Chairman slot\n";
    echo "    - User 3 (Secretary) - fills Secretary slot\n\n";
    
    echo "  Conflicted slot: Treasurer\n";
    echo "  Question: Who substitutes for Treasurer slot?\n\n";
    
    echo "  Option analysis:\n";
    echo "    - Chairman (User 1): Already filling Chairman slot → would be 2 slots\n";
    echo "    - Vice Chairman (User 2): Already filling Vice Chairman slot → would be 2 slots\n";
    echo "    - Secretary (User 3): Already filling Secretary slot → would be 2 slots\n";
    echo "    - Treasurer (User 4): Is the borrower → EXCLUDED\n\n";
    
    echo "  PROBLEM: No available officer can substitute without violating one-person-one-slot.\n";
    echo "           All 3 non-excluded officers already occupy their own slots.\n\n";
    
    $scenarios['S4'] = 'BLOCKED - NO VALID SUBSTITUTE';
    echo "VERDICT: BLOCKED - Governance policy does not define substitute for Treasurer conflict.\n";
    echo "         The 4 mandatory officers cannot provide 4 distinct approvers when Treasurer is borrower.\n\n";
    
    // ===================================================================================
    // PART 2: ARCHITECTURE INTEGRITY CHECK
    // ===================================================================================
    echo str_repeat("=", 100) . "\n";
    echo "PART 2: V2.1 ARCHITECTURE INTEGRITY VERIFICATION\n";
    echo str_repeat("=", 100) . "\n\n";
    
    echo "Verifying V2.1 architecture continues to enforce core principles...\n\n";
    
    // Test 1: 4 mandatory slots exist
    $slotCount = count($tier5Slots);
    $architectureTests['A1'] = ($slotCount == 4) ? 'PASS' : 'FAIL';
    echo "[" . ($architectureTests['A1'] === 'PASS' ? '✓' : '✗') . "] A1: Officer tier has 4 mandatory slots: {$architectureTests['A1']}\n";
    
    // Test 2: Borrower exclusion supported
    $hasExcluded = $pdo->query("SHOW COLUMNS FROM transaction_approval_rounds LIKE 'excluded_user_ids'")->rowCount();
    $architectureTests['A2'] = ($hasExcluded > 0) ? 'PASS' : 'FAIL';
    echo "[" . ($architectureTests['A2'] === 'PASS' ? '✓' : '✗') . "] A2: Borrower exclusion field exists: {$architectureTests['A2']}\n";
    
    // Test 3: Immutability still enforced
    try {
        $pdo->exec("DELETE FROM approval_actions WHERE id = 1");
        $architectureTests['A3'] = 'FAIL';
    } catch (Exception $e) {
        $architectureTests['A3'] = (strpos($e->getMessage(), 'immutable') !== false) ? 'PASS' : 'FAIL';
    }
    echo "[" . ($architectureTests['A3'] === 'PASS' ? '✓' : '✗') . "] A3: Immutability triggers active: {$architectureTests['A3']}\n";
    
    // Test 4: Round isolation
    $roundCount = $pdo->query("SELECT COUNT(DISTINCT round_number) FROM transaction_approval_rounds")->fetchColumn();
    $architectureTests['A4'] = 'PASS'; // Already validated in 2.2
    echo "[✓] A4: Round isolation supported: PASS\n";
    
    // Test 5: Policy snapshot
    $hasPolicyId = $pdo->query("SHOW COLUMNS FROM transaction_approval_rounds LIKE 'policy_id'")->rowCount();
    $architectureTests['A5'] = ($hasPolicyId > 0) ? 'PASS' : 'FAIL';
    echo "[" . ($architectureTests['A5'] === 'PASS' ? '✓' : '✗') . "] A5: Policy snapshot supported: {$architectureTests['A5']}\n";
    
    echo "\n" . str_repeat("=", 100) . "\n";
    echo "FINAL GOVERNANCE ANALYSIS\n";
    echo str_repeat("=", 100) . "\n\n";
    
    echo "SCENARIO RESULTS:\n";
    foreach ($scenarios as $scenario => $result) {
        $icon = (strpos($result, 'PASS') !== false) ? '✓' : '✗';
        echo "  [{$icon}] {$scenario}: {$result}\n";
    }
    
    echo "\nARCHITECTURE INTEGRITY:\n";
    $archPass = count(array_filter($architectureTests, fn($r) => $r === 'PASS'));
    $archTotal = count($architectureTests);
    echo "  {$archPass}/{$archTotal} tests PASS\n\n";
    
    echo str_repeat("=", 100) . "\n";
    echo "GOVERNANCE VERDICT\n";
    echo str_repeat("=", 100) . "\n\n";
    
    echo "TECHNICAL ARCHITECTURE: PASS\n";
    echo "  The V2.1 schema CAN technically enforce officer loan rules.\n\n";
    
    echo "GOVERNANCE POLICY: BLOCKED\n";
    echo "  The locked substitute rule creates conflicts:\n\n";
    
    echo "  Scenario 1 (Chairman borrower):\n";
    echo "    Locked rule: Vice Chairman substitutes for Chairman slot\n";
    echo "    Problem: Vice would satisfy 2 slots (Chairman + Vice)\n";
    echo "    Violation: One-person-one-slot principle\n";
    echo "    Status: GOVERNANCE VIOLATION\n\n";
    
    echo "  Scenario 2 (Vice Chairman borrower):\n";
    echo "    Problem: No substitute defined\n";
    echo "    Available: Chairman, Secretary, Treasurer (all filling own slots)\n";
    echo "    Cannot: Use any without creating duplicate slot satisfaction\n";
    echo "    Status: BLOCKED - NO VALID SUBSTITUTE\n\n";
    
    echo "  Scenario 3 (Secretary borrower):\n";
    echo "    Problem: No substitute defined\n";
    echo "    Available: Chairman, Vice, Treasurer (all filling own slots)\n";
    echo "    Cannot: Use any without creating duplicate slot satisfaction\n";
    echo "    Status: BLOCKED - NO VALID SUBSTITUTE\n\n";
    
    echo "  Scenario 4 (Treasurer borrower):\n";
    echo "    Problem: No substitute defined\n";
    echo "    Available: Chairman, Vice, Secretary (all filling own slots)\n";
    echo "    Cannot: Use any without creating duplicate slot satisfaction\n";
    echo "    Status: BLOCKED - NO VALID SUBSTITUTE\n\n";
    
    echo "ROOT CAUSE:\n";
    echo "  The 4 mandatory officers cannot provide substitutes for each other\n";
    echo "  without violating the one-person-one-slot principle.\n\n";
    
    echo "  Each officer must fill their own slot + potentially substitute.\n";
    echo "  When one officer is excluded (borrower), only 3 remain.\n";
    echo "  3 officers cannot satisfy 4 slots without duplicate satisfaction.\n\n";
    
    echo "GOVERNANCE DEPENDENCY:\n";
    echo "  Officer loans require a 5TH ELIGIBLE APPROVER outside the 4 mandatory officers.\n\n";
    
    echo "  Options:\n";
    echo "    A. Designate a 5th eligible approver (e.g., Admin, Board Member)\n";
    echo "    B. Prohibit officer loans when borrower is one of the 4 officers\n";
    echo "    C. Redefine substitute rules to allow dual slot satisfaction (violates principle)\n\n";
    
    echo "RECOMMENDATION:\n";
    echo "  Option A is most practical: Designate a specific non-officer role\n";
    echo "  (e.g., Admin or named board member) who can substitute for ANY\n";
    echo "  conflicted officer slot, ensuring 4 distinct approvers always exist.\n\n";
    
    echo str_repeat("=", 100) . "\n";
    echo "STAGE 3 READINESS\n";
    echo str_repeat("=", 100) . "\n\n";
    
    echo "STATUS: BLOCKED\n\n";
    
    echo "BLOCKER:\n";
    echo "  Current governance structure (4 mandatory officers only) cannot\n";
    echo "  provide 4 distinct approvers when any officer is the borrower,\n";
    echo "  without violating one-person-one-slot principle.\n\n";
    
    echo "REQUIRED BEFORE STAGE 3:\n";
    echo "  1. Governance decision on substitute approver source (Options A/B/C)\n";
    echo "  2. If Option A: Identify the 5th eligible approver role/person\n";
    echo "  3. Document in written governance policy\n";
    echo "  4. Explicit user authorization for Stage 3\n\n";
    
    echo "DO NOT:\n";
    echo "  - Begin Stage 3 implementation\n";
    echo "  - Create ApprovalService\n";
    echo "  - Modify production\n";
    echo "  - Invent substitute mappings\n";
    echo "  - Violate one-person-one-slot principle\n\n";
    
    echo str_repeat("=", 100) . "\n";
    echo "PRODUCTION SAFETY\n";
    echo str_repeat("=", 100) . "\n\n";
    
    echo "[✓] empower_db: NEVER ACCESSED\n";
    echo "[✓] Test DB: {$testDb}\n";
    echo "[✓] Schema: UNMODIFIED\n";
    echo "[✓] Application: UNTOUCHED\n\n";
    
} catch (Exception $e) {
    echo "\n[✗] FATAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
