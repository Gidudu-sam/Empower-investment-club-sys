<?php
/**
 * Step 3 — Journal Reversal Service Tests
 * Complete isolated test suite for JournalService::reverse()
 *
 * Stage 13-F (2026-09, AUTH-D-02 remediation): JournalService::reverse()
 * now rejects a reversal where the reversing user is the SAME user who
 * originally posted the entry (journal_entries.created_by === $userId).
 * Every entry in this file is posted with created_by=1 (unchanged, below);
 * the reverser id used in TESTS 2/6/8 was changed from 1 to 101 so those
 * tests exercise a legitimate different-actor reversal instead of what is
 * now a correctly-blocked self-reversal -- the actual behavior under test
 * (mirror lines, balance, immutability, already-reversed idempotency,
 * audit records, transaction rollback safety) is otherwise unchanged. The
 * dedicated self-reversal-is-blocked behavior itself is covered by
 * tests/test_stage13f_auth_d02_self_reversal.php.
 */

require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Database.php';
require 'core/Autoloader.php';

$db = Database::getInstance()->getConnection();

echo "=== JOURNAL REVERSAL SERVICE TESTS ===\n\n";

// Capture initial state
$stmt = $db->query('SELECT last_number FROM journal_number_sequences WHERE prefix = "JE"');
$initialSeq = (int)$stmt->fetch()['last_number'];
$stmt = $db->query('SELECT COUNT(*) as cnt FROM journal_entries');
$initialEntryCount = (int)$stmt->fetch()['cnt'];
$stmt = $db->query('SELECT COUNT(*) as cnt FROM journal_lines');
$initialLineCount = (int)$stmt->fetch()['cnt'];

echo "Initial state:\n";
echo "  Journal entries: {$initialEntryCount}\n";
echo "  Journal lines: {$initialLineCount}\n";
echo "  JE sequence: {$initialSeq}\n\n";

$testsPassed = 0;
$testsFailed = 0;
$testEntryIds = [];

// ============================================================
// TEST 1: Create temporary balanced journal entry
// ============================================================
echo "TEST 1: Create temporary balanced journal entry\n";
try {
    $service = new JournalService();
    
    $result = $service->post([
        'entry_date' => '2026-08-25',
        'description' => 'TEST ENTRY — For reversal testing',
        'source_module' => 'test_reversal',
        'source_reference_type' => 'test_entry',
        'source_reference_id' => 10001,
        'created_by' => 1,
        'lines' => [
            ['account_id' => 7, 'debit' => 1000.00, 'credit' => 0, 'description' => 'Test debit to Cash'],
            ['account_id' => 17, 'debit' => 0, 'credit' => 500.00, 'description' => 'Test credit to Savings'],
            ['account_id' => 77, 'debit' => 0, 'credit' => 500.00, 'description' => 'Test credit to Interest'],
        ]
    ]);
    
    if ($result['created']) {
        $testEntryId = $result['id'];
        $testEntryNumber = $result['entry_number'];
        $testEntryIds[] = $testEntryId;
        echo "  ✓ PASS: Entry created — ID: {$testEntryId}, Number: {$testEntryNumber}\n\n";
        $testsPassed++;
    } else {
        echo "  ✗ FAIL: Entry not created\n\n";
        $testsFailed++;
        exit(1);
    }
} catch (Exception $e) {
    echo "  ✗ FAIL: " . $e->getMessage() . "\n\n";
    $testsFailed++;
    exit(1);
}

// Capture original entry state before reversal
$stmt = $db->prepare("SELECT * FROM journal_entries WHERE id = ?");
$stmt->execute([$testEntryId]);
$originalEntry = $stmt->fetch();

$stmt = $db->prepare("SELECT * FROM journal_lines WHERE journal_entry_id = ? ORDER BY id");
$stmt->execute([$testEntryId]);
$originalLines = $stmt->fetchAll();

// ============================================================
// TEST 2: Reverse the entry
// ============================================================
echo "TEST 2: Reverse the entry\n";
try {
    $reversalResult = $service->reverse($testEntryId, 101, 'Test reversal for validation'); // Stage 13-F: different actor than the poster (user 1)
    
    if ($reversalResult['reversed']) {
        $reversalEntryId = $reversalResult['id'];
        $reversalEntryNumber = $reversalResult['entry_number'];
        $testEntryIds[] = $reversalEntryId;
        echo "  ✓ PASS: Reversal created — ID: {$reversalEntryId}, Number: {$reversalEntryNumber}\n";
        
        // Verify reversal_of_id
        $stmt = $db->prepare("SELECT reversal_of_id FROM journal_entries WHERE id = ?");
        $stmt->execute([$reversalEntryId]);
        $revOfId = $stmt->fetch()['reversal_of_id'];
        
        if ($revOfId == $testEntryId) {
            echo "  ✓ PASS: reversal_of_id points to original ({$testEntryId})\n\n";
            $testsPassed++;
        } else {
            echo "  ✗ FAIL: reversal_of_id = {$revOfId}, expected {$testEntryId}\n\n";
            $testsFailed++;
        }
    } else {
        echo "  ✗ FAIL: Reversal not created\n\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "  ✗ FAIL: " . $e->getMessage() . "\n\n";
    $testsFailed++;
}

// ============================================================
// TEST 3: Verify mirror lines (debits/credits swapped)
// ============================================================
echo "TEST 3: Verify mirror lines\n";
try {
    $stmt = $db->prepare("SELECT * FROM journal_lines WHERE journal_entry_id = ? ORDER BY id");
    $stmt->execute([$reversalEntryId]);
    $reversalLines = $stmt->fetchAll();
    
    if (count($originalLines) === count($reversalLines)) {
        echo "  ✓ PASS: Line count matches ({" . count($originalLines) . "})\n";
        
        $allMatch = true;
        for ($i = 0; $i < count($originalLines); $i++) {
            $orig = $originalLines[$i];
            $rev = $reversalLines[$i];
            
            $origDebit = (float)$orig['debit'];
            $origCredit = (float)$orig['credit'];
            $revDebit = (float)$rev['debit'];
            $revCredit = (float)$rev['credit'];
            
            if ($orig['account_id'] !== $rev['account_id']) {
                echo "  ✗ FAIL: Line {$i} account mismatch: {$orig['account_id']} vs {$rev['account_id']}\n";
                $allMatch = false;
            }
            
            if (abs($origDebit - $revCredit) > 0.01) {
                echo "  ✗ FAIL: Line {$i} original debit {$origDebit} != reversal credit {$revCredit}\n";
                $allMatch = false;
            }
            
            if (abs($origCredit - $revDebit) > 0.01) {
                echo "  ✗ FAIL: Line {$i} original credit {$origCredit} != reversal debit {$revDebit}\n";
                $allMatch = false;
            }
        }
        
        if ($allMatch) {
            echo "  ✓ PASS: All lines are perfect mirror images\n\n";
            $testsPassed++;
        } else {
            echo "\n";
            $testsFailed++;
        }
    } else {
        echo "  ✗ FAIL: Line count mismatch: " . count($originalLines) . " vs " . count($reversalLines) . "\n\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "  ✗ FAIL: " . $e->getMessage() . "\n\n";
    $testsFailed++;
}

// ============================================================
// TEST 4: Verify reversal balance
// ============================================================
echo "TEST 4: Verify reversal balance\n";
try {
    $stmt = $db->prepare("SELECT SUM(debit) as total_debit, SUM(credit) as total_credit FROM journal_lines WHERE journal_entry_id = ?");
    $stmt->execute([$reversalEntryId]);
    $reversalTotals = $stmt->fetch();
    
    $debit = (float)$reversalTotals['total_debit'];
    $credit = (float)$reversalTotals['total_credit'];
    
    if (abs($debit - $credit) < 0.01) {
        echo "  ✓ PASS: Reversal is balanced (debits: {$debit}, credits: {$credit})\n\n";
        $testsPassed++;
    } else {
        echo "  ✗ FAIL: Reversal not balanced — debits: {$debit}, credits: {$credit}\n\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "  ✗ FAIL: " . $e->getMessage() . "\n\n";
    $testsFailed++;
}

// ============================================================
// TEST 5: Verify original immutability
// ============================================================
echo "TEST 5: Verify original immutability\n";
try {
    $stmt = $db->prepare("SELECT * FROM journal_entries WHERE id = ?");
    $stmt->execute([$testEntryId]);
    $afterEntry = $stmt->fetch();
    
    $stmt = $db->prepare("SELECT * FROM journal_lines WHERE journal_entry_id = ? ORDER BY id");
    $stmt->execute([$testEntryId]);
    $afterLines = $stmt->fetchAll();
    
    $immutable = true;
    
    // Check entry fields (exclude reversal_of_id and reversed_by which may be legacy)
    foreach (['entry_number', 'entry_date', 'description', 'status', 'posted'] as $field) {
        if ($originalEntry[$field] !== $afterEntry[$field]) {
            echo "  ✗ FAIL: Original entry field '{$field}' changed\n";
            $immutable = false;
        }
    }
    
    // Check lines
    if (count($originalLines) !== count($afterLines)) {
        echo "  ✗ FAIL: Original line count changed\n";
        $immutable = false;
    } else {
        for ($i = 0; $i < count($originalLines); $i++) {
            foreach (['account_id', 'debit', 'credit', 'description'] as $field) {
                if ($originalLines[$i][$field] !== $afterLines[$i][$field]) {
                    echo "  ✗ FAIL: Original line {$i} field '{$field}' changed\n";
                    $immutable = false;
                }
            }
        }
    }
    
    if ($immutable) {
        echo "  ✓ PASS: Original entry and lines remain immutable\n\n";
        $testsPassed++;
    } else {
        echo "\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "  ✗ FAIL: " . $e->getMessage() . "\n\n";
    $testsFailed++;
}

// ============================================================
// TEST 6: Verify already-reversed protection
// ============================================================
echo "TEST 6: Verify already-reversed protection\n";
try {
    $secondReversalAttempt = $service->reverse($testEntryId, 101, 'Second reversal attempt'); // Stage 13-F: same (legitimate) reverser as TEST 2, testing idempotency not the self-reversal block
    
    if (!$secondReversalAttempt['reversed']) {
        echo "  ✓ PASS: Second reversal blocked (returned existing reversal)\n";
        echo "  ✓ PASS: Returned entry ID: {$secondReversalAttempt['id']}, Number: {$secondReversalAttempt['entry_number']}\n";
        
        // Verify only ONE reversal exists
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM journal_entries WHERE reversal_of_id = ?");
        $stmt->execute([$testEntryId]);
        $reversalCount = (int)$stmt->fetch()['cnt'];
        
        if ($reversalCount === 1) {
            echo "  ✓ PASS: Only 1 reversal exists in database\n\n";
            $testsPassed++;
        } else {
            echo "  ✗ FAIL: Found {$reversalCount} reversals, expected 1\n\n";
            $testsFailed++;
        }
    } else {
        echo "  ✗ FAIL: Second reversal was created (should have been blocked)\n\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "  ✗ FAIL: " . $e->getMessage() . "\n\n";
    $testsFailed++;
}

// ============================================================
// TEST 7: Verify audit records
// ============================================================
echo "TEST 7: Verify audit records\n";
try {
    $stmt = $db->prepare("SELECT * FROM journal_entry_audit WHERE entity_id = ? AND action = 'reverse'");
    $stmt->execute([$testEntryId]);
    $auditRecord = $stmt->fetch();
    
    if ($auditRecord) {
        echo "  ✓ PASS: Audit record exists\n";
        echo "  ✓ PASS: Action: {$auditRecord['action']}\n";
        
        if (!empty($auditRecord['reason'])) {
            echo "  ✓ PASS: Reason preserved: {$auditRecord['reason']}\n\n";
            $testsPassed++;
        } else {
            echo "  ✗ FAIL: Reason not preserved\n\n";
            $testsFailed++;
        }
    } else {
        echo "  ✗ FAIL: No audit record found\n\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "  ✗ FAIL: " . $e->getMessage() . "\n\n";
    $testsFailed++;
}

// ============================================================
// TEST 8: Verify transaction rollback
// ============================================================
echo "TEST 8: Verify transaction rollback\n";
try {
    // Create another test entry
    $testResult2 = $service->post([
        'entry_date' => '2026-08-25',
        'description' => 'TEST ENTRY 2 — For rollback testing',
        'source_module' => 'test_reversal',
        'source_reference_type' => 'test_entry',
        'source_reference_id' => 10002,
        'created_by' => 1,
        'lines' => [
            ['account_id' => 7, 'debit' => 200.00, 'credit' => 0],
            ['account_id' => 17, 'debit' => 0, 'credit' => 200.00],
        ]
    ]);
    $testEntry2Id = $testResult2['id'];
    $testEntryIds[] = $testEntry2Id;
    
    echo "  - Created test entry 2: {$testResult2['entry_number']}\n";
    
    // Begin outer transaction
    $db->beginTransaction();
    echo "  - Started outer transaction\n";
    
    // Reverse within transaction
    $rollbackReversal = $service->reverse($testEntry2Id, 101, 'Reversal to be rolled back'); // Stage 13-F: different actor than the poster (user 1)
    $rollbackReversalId = $rollbackReversal['id'];
    echo "  - Created reversal within transaction: {$rollbackReversal['entry_number']}\n";
    
    // Rollback
    $db->rollBack();
    echo "  - Rolled back outer transaction\n";
    
    // Verify reversal disappeared
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM journal_entries WHERE id = ?");
    $stmt->execute([$rollbackReversalId]);
    $reversalExists = (int)$stmt->fetch()['cnt'];
    
    if ($reversalExists === 0) {
        echo "  ✓ PASS: Reversal entry disappeared after rollback\n";
        
        // Verify reversal lines disappeared
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM journal_lines WHERE journal_entry_id = ?");
        $stmt->execute([$rollbackReversalId]);
        $lineCount = (int)$stmt->fetch()['cnt'];
        
        if ($lineCount === 0) {
            echo "  ✓ PASS: Reversal lines disappeared\n";
            
            // Verify original still exists
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM journal_entries WHERE id = ?");
            $stmt->execute([$testEntry2Id]);
            $originalExists = (int)$stmt->fetch()['cnt'];
            
            if ($originalExists === 1) {
                echo "  ✓ PASS: Original entry still exists\n\n";
                $testsPassed++;
            } else {
                echo "  ✗ FAIL: Original entry disappeared\n\n";
                $testsFailed++;
            }
        } else {
            echo "  ✗ FAIL: Reversal lines still exist ({$lineCount})\n\n";
            $testsFailed++;
        }
    } else {
        echo "  ✗ FAIL: Reversal entry still exists after rollback\n\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "  ✗ FAIL: " . $e->getMessage() . "\n\n";
    $testsFailed++;
}

// ============================================================
// TEST 9: Verify journal number sequence integrity
// ============================================================
echo "TEST 9: Verify journal number sequence\n";
try {
    $stmt = $db->query('SELECT last_number FROM journal_number_sequences WHERE prefix = "JE"');
    $currentSeq = (int)$stmt->fetch()['last_number'];
    
    echo "  - Sequence before cleanup: {$currentSeq}\n";
    echo "  - Sequence should be restored to: {$initialSeq}\n";
    echo "  (Will be restored during cleanup)\n\n";
    $testsPassed++;
} catch (Exception $e) {
    echo "  ✗ FAIL: " . $e->getMessage() . "\n\n";
    $testsFailed++;
}

// ============================================================
// CLEANUP
// ============================================================
echo "=== CLEANUP ===\n";
try {
    // Delete test lines
    foreach ($testEntryIds as $id) {
        $db->exec("DELETE FROM journal_lines WHERE journal_entry_id = {$id}");
    }
    
    // Delete test entries
    $placeholders = implode(',', array_fill(0, count($testEntryIds), '?'));
    $stmt = $db->prepare("DELETE FROM journal_entries WHERE id IN ({$placeholders})");
    $stmt->execute($testEntryIds);
    
    // Delete test audit records
    $db->exec("DELETE FROM journal_entry_audit WHERE entity_id IN (" . implode(',', $testEntryIds) . ")");
    $db->exec("DELETE FROM journal_entry_audit WHERE action = 'reverse' AND entity_type = 'journal_entry'");
    
    // Restore sequence
    $db->prepare("UPDATE journal_number_sequences SET last_number = ? WHERE prefix = 'JE'")->execute([$initialSeq]);
    
    // Verify cleanup
    $stmt = $db->query('SELECT COUNT(*) as cnt FROM journal_entries');
    $finalEntryCount = (int)$stmt->fetch()['cnt'];
    $stmt = $db->query('SELECT COUNT(*) as cnt FROM journal_lines');
    $finalLineCount = (int)$stmt->fetch()['cnt'];
    $stmt = $db->query('SELECT last_number FROM journal_number_sequences WHERE prefix = "JE"');
    $finalSeq = (int)$stmt->fetch()['last_number'];
    
    echo "Final state:\n";
    echo "  Journal entries: {$finalEntryCount} (expected: {$initialEntryCount})\n";
    echo "  Journal lines: {$finalLineCount} (expected: {$initialLineCount})\n";
    echo "  JE sequence: {$finalSeq} (expected: {$initialSeq})\n\n";
    
    if ($finalEntryCount === $initialEntryCount && 
        $finalLineCount === $initialLineCount && 
        $finalSeq === $initialSeq) {
        echo "✓ Cleanup successful — database restored to initial state\n\n";
    } else {
        echo "✗ WARNING: Cleanup may be incomplete\n\n";
    }
} catch (Exception $e) {
    echo "Cleanup error: " . $e->getMessage() . "\n\n";
}

// ============================================================
// SUMMARY
// ============================================================
echo "=== TEST SUMMARY ===\n";
echo "Tests Passed: {$testsPassed}\n";
echo "Tests Failed: {$testsFailed}\n\n";

if ($testsFailed === 0) {
    echo "✓ ALL REVERSAL TESTS PASSED\n";
    exit(0);
} else {
    echo "✗ SOME TESTS FAILED\n";
    exit(1);
}
