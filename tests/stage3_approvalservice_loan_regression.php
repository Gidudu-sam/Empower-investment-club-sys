<?php
/**
 * Stage 3A — ApprovalService & Loan Integration Regression Test Suite
 * 
 * CRITICAL: This test runs ONLY against disposable clone database
 * NEVER run against production empower_db
 * 
 * Purpose: Verify V2.1 approval architecture integration with actual loan workflow
 * Environment: Disposable test database (empower_stage3_test)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configuration
define('TEST_DB_NAME', 'empower_stage3_test');
define('PRODUCTION_DB_NAME', 'empower_db');

// Safety check
if (!isset($argv[1]) || $argv[1] !== '--confirmed-disposable') {
    die("ERROR: Must run with --confirmed-disposable flag to confirm test database usage\n" .
        "Usage: php " . basename(__FILE__) . " --confirmed-disposable\n");
}

echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo " Stage 3A — ApprovalService Loan Integration Regression Test Suite\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "Test Database: " . TEST_DB_NAME . "\n";
echo "Production DB: " . PRODUCTION_DB_NAME . " (NEVER TOUCHED)\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// Connect to test database
try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=" . TEST_DB_NAME . ";charset=utf8mb4",
        'root',
        ''
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "[✓] Connected to test database: " . TEST_DB_NAME . "\n\n";
} catch (PDOException $e) {
    die("[✗] FATAL: Cannot connect to test database: " . $e->getMessage() . "\n" .
        "You must create the disposable test database first.\n");
}

// Verify we're NOT connected to production
$currentDb = $pdo->query("SELECT DATABASE()")->fetchColumn();
if ($currentDb === PRODUCTION_DB_NAME) {
    die("[✗] FATAL: Connected to PRODUCTION database. Aborting for safety.\n");
}

echo "[✓] Safety verified: Not connected to production\n\n";

// Test counters
$testResults = [
    'total' => 0,
    'pass' => 0,
    'fail' => 0,
    'blocked' => 0,
];

// Helper function to log test results
function logTest(string $name, bool $passed, string $details = '', &$results = null) {
    global $testResults;
    $testResults['total']++;
    if ($passed) {
        $testResults['pass']++;
        echo "[✓ PASS] {$name}\n";
    } else {
        $testResults['fail']++;
        echo "[✗ FAIL] {$name}\n";
    }
    if ($details) {
        echo "         {$details}\n";
    }
    echo "\n";
    return $passed;
}

// ═══════════════════════════════════════════════════════════════════
// SECTION 1: SCHEMA VERIFICATION
// ═══════════════════════════════════════════════════════════════════

echo "───────────────────────────────────────────────────────────────────\n";
echo "SECTION 1: V2.1 SCHEMA VERIFICATION\n";
echo "───────────────────────────────────────────────────────────────────\n\n";

// Test 1.1: All V2.1 tables exist
$requiredTables = [
    'approval_policies',
    'approval_tiers',
    'approval_tier_slots',
    'transaction_approval_rounds',
    'transaction_approval_slot_instances',
    'approval_actions',
];

$stmt = $pdo->query("SHOW TABLES LIKE 'approval_%'");
$existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

$allTablesExist = true;
foreach ($requiredTables as $table) {
    if (!in_array($table, $existingTables, true)) {
        $allTablesExist = false;
        echo "[✗] Missing table: {$table}\n";
    }
}

logTest(
    "1.1: All V2.1 approval tables exist",
    $allTablesExist,
    $allTablesExist ? "6/6 tables present" : "Some tables missing - run migration first"
);

// Test 1.2: Immutability triggers exist
$stmt = $pdo->query("
    SELECT TRIGGER_NAME 
    FROM information_schema.TRIGGERS 
    WHERE TABLE_SCHEMA = '" . TEST_DB_NAME . "' 
      AND TABLE_NAME = 'approval_actions'
    ORDER BY TRIGGER_NAME
");
$triggers = $stmt->fetchAll(PDO::FETCH_COLUMN);

$hasDeleteTrigger = in_array('prevent_approval_action_delete', $triggers, true);
$hasUpdateTrigger = in_array('prevent_approval_action_update', $triggers, true);

logTest(
    "1.2: Immutability triggers exist",
    $hasDeleteTrigger && $hasUpdateTrigger,
    ($hasDeleteTrigger ? "DELETE trigger: ✓" : "DELETE trigger: ✗") . " | " .
    ($hasUpdateTrigger ? "UPDATE trigger: ✓" : "UPDATE trigger: ✗")
);

// Test 1.3: Policy v1 exists with 5 tiers
$policyCount = $pdo->query("SELECT COUNT(*) FROM approval_policies WHERE transaction_type = 'loan'")->fetchColumn();
$tierCount = $pdo->query("SELECT COUNT(*) FROM approval_tiers WHERE policy_id = 1")->fetchColumn();

logTest(
    "1.3: Policy v1 exists with 5 tiers",
    $policyCount == 1 && $tierCount == 5,
    "Policies: {$policyCount} | Tiers: {$tierCount}"
);

// Test 1.4: Tier boundaries are correct
$tiers = $pdo->query("
    SELECT tier_number, min_amount, max_amount, special_rule
    FROM approval_tiers
    WHERE policy_id = 1
    ORDER BY tier_number
")->fetchAll(PDO::FETCH_ASSOC);

$boundariesCorrect = true;
$expectedBoundaries = [
    1 => ['min' => '0.00', 'max' => '999999.99', 'special' => null],
    2 => ['min' => '1000000.00', 'max' => '4999999.99', 'special' => null],
    3 => ['min' => '5000000.00', 'max' => '9999999.99', 'special' => null],
    4 => ['min' => '10000000.00', 'max' => null, 'special' => null],
    5 => ['min' => '0.00', 'max' => null, 'special' => 'officer_loan'],
];

foreach ($tiers as $tier) {
    $num = $tier['tier_number'];
    if (!isset($expectedBoundaries[$num])) {
        $boundariesCorrect = false;
        break;
    }
    $expected = $expectedBoundaries[$num];
    if ($tier['min_amount'] != $expected['min'] || 
        $tier['max_amount'] != $expected['max'] ||
        $tier['special_rule'] != $expected['special']) {
        $boundariesCorrect = false;
        break;
    }
}

logTest(
    "1.4: Tier boundaries match locked Stage 2.6 governance",
    $boundariesCorrect,
    $boundariesCorrect ? "All 5 tier boundaries correct" : "Boundary mismatch detected"
);

// ═══════════════════════════════════════════════════════════════════
// SECTION 2: IMMUTABILITY TESTS
// ═══════════════════════════════════════════════════════════════════

echo "───────────────────────────────────────────────────────────────────\n";
echo "SECTION 2: IMMUTABILITY ENFORCEMENT TESTS\n";
echo "───────────────────────────────────────────────────────────────────\n\n";

// Create a test approval action for immutability testing
try {
    // Create minimal test data
    $pdo->exec("
        INSERT INTO transaction_approval_rounds 
        (transaction_type, transaction_id, round_number, policy_id, tier_id, tier_number, 
         snapshot_amount, submitted_by, approval_status)
        VALUES ('loan', 999999, 1, 1, 1, 1, 100000, 1, 'pending')
    ");
    $testRoundId = $pdo->lastInsertId();
    
    $pdo->exec("
        INSERT INTO approval_actions
        (approval_round_id, user_id, user_role, action_type)
        VALUES ({$testRoundId}, 1, 'chairman', 'approved')
    ");
    $testActionId = $pdo->lastInsertId();
    
    // Test 2.1: DELETE is prevented
    $deleteBlocked = false;
    try {
        $pdo->exec("DELETE FROM approval_actions WHERE id = {$testActionId}");
    } catch (PDOException $e) {
        $deleteBlocked = ($e->getCode() == '45000');
    }
    
    logTest(
        "2.1: DELETE approval_actions is blocked by trigger",
        $deleteBlocked,
        $deleteBlocked ? "Trigger correctly prevented DELETE" : "WARNING: DELETE was allowed"
    );
    
    // Test 2.2: UPDATE is prevented
    $updateBlocked = false;
    try {
        $pdo->exec("UPDATE approval_actions SET user_id = 999 WHERE id = {$testActionId}");
    } catch (PDOException $e) {
        $updateBlocked = ($e->getCode() == '45000');
    }
    
    logTest(
        "2.2: UPDATE approval_actions is blocked by trigger",
        $updateBlocked,
        $updateBlocked ? "Trigger correctly prevented UPDATE" : "WARNING: UPDATE was allowed"
    );
    
} catch (Exception $e) {
    logTest("2.x: Immutability test setup", false, "Error: " . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════════
// SECTION 3: TIER BOUNDARY TESTS
// ═══════════════════════════════════════════════════════════════════

echo "───────────────────────────────────────────────────────────────────\n";
echo "SECTION 3: TIER BOUNDARY TESTS\n";
echo "───────────────────────────────────────────────────────────────────\n\n";

// Note: These tests would require ApprovalService to be loaded and test members/loans created
// For now, documenting that these tests need actual loan workflow integration

echo "[INFO] Tier boundary tests require full application integration\n";
echo "[INFO] These will be tested via actual loan submission workflow\n\n";

// ═══════════════════════════════════════════════════════════════════
// FINAL SUMMARY
// ═══════════════════════════════════════════════════════════════════

echo "═══════════════════════════════════════════════════════════════════\n";
echo "STAGE 3A TEST SUMMARY\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "Total Tests:   " . $testResults['total'] . "\n";
echo "Passed:        " . $testResults['pass'] . " ✓\n";
echo "Failed:        " . $testResults['fail'] . " ✗\n";
echo "Blocked:       " . $testResults['blocked'] . "\n";
echo "═══════════════════════════════════════════════════════════════════\n";

if ($testResults['fail'] === 0 && $testResults['blocked'] === 0) {
    echo "\n[✓] ALL SCHEMA TESTS PASSED\n";
    echo "Next: Run full application integration tests\n\n";
    exit(0);
} else {
    echo "\n[✗] SOME TESTS FAILED\n";
    echo "Review failures before proceeding\n\n";
    exit(1);
}
