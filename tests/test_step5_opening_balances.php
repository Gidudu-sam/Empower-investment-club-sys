<?php
/**
 * Step 5 — Opening Balance Workflow Tests
 * Complete isolated test suite for OpeningBalanceBatchModel + OpeningBalanceLineModel
 */

require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Database.php';
require 'core/Autoloader.php';

$db = Database::getInstance()->getConnection();

echo "=== STEP 5 OPENING BALANCE WORKFLOW TESTS ===\n\n";

$testsPassed = 0;
$testsFailed = 0;
function check($label, $cond) {
    global $testsPassed, $testsFailed;
    if ($cond) { echo "  PASS: $label\n"; $testsPassed++; }
    else       { echo "  FAIL: $label\n"; $testsFailed++; }
}

// Capture initial state
$beforeEntries = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$beforeLines   = (int)$db->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
$beforeTotals  = $db->query('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines')->fetch();
$beforeJESeq   = (int)$db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='JE'")->fetchColumn();
$beforeOBSeq   = (int)$db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='OB'")->fetchColumn();

echo "Initial state: entries={$beforeEntries} lines={$beforeLines} debit={$beforeTotals['d']} credit={$beforeTotals['c']} JEseq={$beforeJESeq} OBseq={$beforeOBSeq}\n\n";

$model = new OpeningBalanceBatchModel();
$lineModel = new OpeningBalanceLineModel();
$entryModel = new JournalEntryModel();

$createdBatchIds = [];
$tempApproverId = null;

// account 7 = Cash at Hand (asset/debit), account 24 = Shares (equity/credit), account 60 = Garbage (INACTIVE)
$ACC_CASH = 7;
$ACC_SHARES = 24;
$ACC_LAND = 1;              // asset/debit — distinct pair for batchF (avoids conflicting with batchA's account 7/24 once approved)
$ACC_RETAINED_EARNINGS = 25; // equity/credit
$ACC_LOANS = 14;            // asset/debit — distinct pair for batchH
$ACC_SAVINGS = 17;          // liability/credit
$ACC_INACTIVE = 60;
$PERIOD_OPEN = 2; // Q3 2026
$FY_ACTIVE = 2;

// ============================================================
// Create a temporary second user, so approve/self-approval
// tests exercise a genuinely different checker identity
// (only one real login exists in this system today).
// ============================================================
$db->prepare("INSERT INTO users (role_id, full_name, email, password_hash, is_active) VALUES (1, 'TEST Checker', 'test.checker.step5@empower.local', 'x', 1)")->execute();
$tempApproverId = (int)$db->lastInsertId();
echo "Created temporary checker user id={$tempApproverId} (deleted during cleanup)\n\n";

// ============================================================
// TEST 1: Create draft opening balance
// ============================================================
echo "TEST 1: Create draft opening balance\n";
$batchA = null;
try {
    $batchA = $model->createDraft(
        ['financial_year_id' => $FY_ACTIVE, 'accounting_period_id' => $PERIOD_OPEN, 'as_of_date' => '2026-08-25'],
        [
            ['account_id' => $ACC_CASH, 'debit' => 1000.00, 'credit' => 0, 'description' => 'STEP5 TEST'],
            ['account_id' => $ACC_SHARES, 'debit' => 0, 'credit' => 1000.00, 'description' => 'STEP5 TEST'],
        ],
        1
    );
    $createdBatchIds[] = $batchA;
    $batch = $model->find($batchA);
    check('batch created with status=draft', $batch && $batch['status'] === 'draft');
    check('batch_number matches OB-###### pattern', (bool)preg_match('/^OB-\d{6}$/', $batch['batch_number']));
} catch (Exception $e) {
    check('TEST 1 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 2: Validate account IDs (reject nonexistent account)
// ============================================================
echo "TEST 2: Reject nonexistent account\n";
try {
    $model->createDraft(
        ['financial_year_id' => $FY_ACTIVE, 'accounting_period_id' => $PERIOD_OPEN, 'as_of_date' => '2026-08-25'],
        [
            ['account_id' => 999999, 'debit' => 100, 'credit' => 0],
            ['account_id' => $ACC_SHARES, 'debit' => 0, 'credit' => 100],
        ],
        1
    );
    check('nonexistent account should have thrown', false);
} catch (InvalidArgumentException $e) {
    check('nonexistent account rejected — ' . $e->getMessage(), true);
}
echo "\n";

// ============================================================
// TEST 3: Reject inactive account
// ============================================================
echo "TEST 3: Reject inactive account\n";
try {
    $model->createDraft(
        ['financial_year_id' => $FY_ACTIVE, 'accounting_period_id' => $PERIOD_OPEN, 'as_of_date' => '2026-08-25'],
        [
            ['account_id' => $ACC_INACTIVE, 'debit' => 100, 'credit' => 0],
            ['account_id' => $ACC_SHARES, 'debit' => 0, 'credit' => 100],
        ],
        1
    );
    check('inactive account should have thrown', false);
} catch (InvalidArgumentException $e) {
    check('inactive account rejected — ' . $e->getMessage(), true);
}
echo "\n";

// ============================================================
// TEST 4: Reject unbalanced batch
// ============================================================
echo "TEST 4: Reject unbalanced batch\n";
try {
    $model->createDraft(
        ['financial_year_id' => $FY_ACTIVE, 'accounting_period_id' => $PERIOD_OPEN, 'as_of_date' => '2026-08-25'],
        [
            ['account_id' => $ACC_CASH, 'debit' => 1000, 'credit' => 0],
            ['account_id' => $ACC_SHARES, 'debit' => 0, 'credit' => 500],
        ],
        1
    );
    check('unbalanced batch should have thrown', false);
} catch (InvalidArgumentException $e) {
    check('unbalanced batch rejected — ' . $e->getMessage(), str_contains($e->getMessage(), 'not balanced'));
}
echo "\n";

// ============================================================
// TEST 5: Submit draft
// ============================================================
echo "TEST 5: Submit draft\n";
try {
    $model->submit($batchA, 1);
    $batch = $model->find($batchA);
    check('batch status is now pending_approval', $batch['status'] === 'pending_approval');
} catch (Exception $e) {
    check('TEST 5 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 6: Cannot submit invalid batch (already submitted)
// ============================================================
echo "TEST 6: Cannot re-submit a non-draft batch\n";
try {
    $model->submit($batchA, 1);
    check('re-submit should have thrown', false);
} catch (InvalidArgumentException $e) {
    check('re-submit rejected — ' . $e->getMessage(), true);
}
echo "\n";

// ============================================================
// TEST 8 (run before 7): Maker cannot approve own batch
// ============================================================
echo "TEST 8: Maker cannot approve own batch\n";
try {
    $model->approve($batchA, 1); // 1 is the preparer (entered_by)
    check('self-approval should have thrown', false);
} catch (InvalidArgumentException $e) {
    check('self-approval blocked — ' . $e->getMessage(), str_contains($e->getMessage(), 'own batch'));
    $batch = $model->find($batchA);
    check('batch still pending_approval after blocked self-approval', $batch['status'] === 'pending_approval');
}
echo "\n";

// ============================================================
// TEST 7: Approver (different user) can approve
// ============================================================
echo "TEST 7: Different-user approver can approve\n";
try {
    $model->approve($batchA, $tempApproverId);
    $batch = $model->find($batchA);
    check('batch status is now approved', $batch['status'] === 'approved');
    check('approved_by is the checker, not the preparer', (int)$batch['approved_by'] === $tempApproverId);
} catch (Exception $e) {
    check('TEST 7 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 9: Rejection requires a reason
// ============================================================
echo "TEST 9: Rejection requires a reason\n";
$batchF = null;
try {
    $batchF = $model->createDraft(
        ['financial_year_id' => $FY_ACTIVE, 'accounting_period_id' => $PERIOD_OPEN, 'as_of_date' => '2026-08-25'],
        [
            ['account_id' => $ACC_LAND, 'debit' => 200, 'credit' => 0],
            ['account_id' => $ACC_RETAINED_EARNINGS, 'debit' => 0, 'credit' => 200],
        ],
        1
    );
    $createdBatchIds[] = $batchF;
    $model->submit($batchF, 1);

    try {
        $model->reject($batchF, $tempApproverId, '');
        check('empty-reason rejection should have thrown', false);
    } catch (InvalidArgumentException $e) {
        check('empty-reason rejection blocked — ' . $e->getMessage(), true);
    }

    $model->reject($batchF, $tempApproverId, 'Amounts need correction');
    $batch = $model->find($batchF);
    check('batch status is now rejected', $batch['status'] === 'rejected');
    check('rejection_reason stored', $batch['rejection_reason'] === 'Amounts need correction');
    check('rejected_by is the checker', (int)$batch['rejected_by'] === $tempApproverId);
} catch (Exception $e) {
    check('TEST 9 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 10: Rejected batch can be corrected and resubmitted
// ============================================================
echo "TEST 10: Rejected batch can be corrected and resubmitted\n";
try {
    $model->replaceLines($batchF, [
        ['account_id' => $ACC_LAND, 'debit' => 300, 'credit' => 0, 'description' => 'corrected'],
        ['account_id' => $ACC_RETAINED_EARNINGS, 'debit' => 0, 'credit' => 300, 'description' => 'corrected'],
    ], 1);
    $batch = $model->find($batchF);
    check('batch reverted to draft after correction', $batch['status'] === 'draft');
    check('rejection fields cleared', $batch['rejection_reason'] === null);

    $model->submit($batchF, 1);
    $batch = $model->find($batchF);
    check('corrected batch resubmitted to pending_approval', $batch['status'] === 'pending_approval');
} catch (Exception $e) {
    check('TEST 10 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 11: Approved batch cannot be edited
// ============================================================
echo "TEST 11: Approved batch cannot be edited\n";
try {
    $model->replaceLines($batchA, [
        ['account_id' => $ACC_CASH, 'debit' => 999, 'credit' => 0],
        ['account_id' => $ACC_SHARES, 'debit' => 0, 'credit' => 999],
    ], 1);
    check('editing approved batch should have thrown', false);
} catch (InvalidArgumentException $e) {
    check('editing approved batch blocked — ' . $e->getMessage(), true);
}
echo "\n";

// ============================================================
// TEST 12 & 13: Posting creates balanced journal, correct period
// ============================================================
echo "TEST 12/13: Posting creates a balanced journal entry in the correct period\n";
$postResultA = null;
try {
    $postResultA = $model->post($batchA, 1);
    check('post() returned a journal_entry_id', !empty($postResultA['journal_entry_id']));

    $je = $entryModel->find($postResultA['journal_entry_id']);
    check('journal entry accounting_period_id matches batch period', (int)$je['accounting_period_id'] === $PERIOD_OPEN);

    $sums = $db->prepare('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines WHERE journal_entry_id = ?');
    $sums->execute([$postResultA['journal_entry_id']]);
    $s = $sums->fetch();
    check('journal lines balanced (debit==credit==1000.00)', abs((float)$s['d'] - 1000.00) < 0.01 && abs((float)$s['c'] - 1000.00) < 0.01);

    $batch = $model->find($batchA);
    check('batch status is now posted', $batch['status'] === 'posted');
    check('batch.journal_entry_id set', (int)$batch['journal_entry_id'] === (int)$postResultA['journal_entry_id']);
} catch (Exception $e) {
    check('TEST 12/13 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 14: Posting is idempotent
// ============================================================
echo "TEST 14: Posting is idempotent\n";
try {
    $countBefore = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    $postResultA2 = $model->post($batchA, 1);
    $countAfter = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    check('second post() returns the same journal_entry_id', (int)$postResultA2['journal_entry_id'] === (int)$postResultA['journal_entry_id']);
    check('no new journal entry created on repost', $countBefore === $countAfter);
} catch (Exception $e) {
    check('TEST 14 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 15: Posted batch cannot be edited
// ============================================================
echo "TEST 15: Posted batch cannot be edited\n";
try {
    $model->replaceLines($batchA, [
        ['account_id' => $ACC_CASH, 'debit' => 1, 'credit' => 0],
        ['account_id' => $ACC_SHARES, 'debit' => 0, 'credit' => 1],
    ], 1);
    check('editing posted batch should have thrown', false);
} catch (InvalidArgumentException $e) {
    check('editing posted batch blocked — ' . $e->getMessage(), true);
}
echo "\n";

// ============================================================
// TEST 16: Journal entry is immutable
// ============================================================
echo "TEST 16: Journal entry created from posting is immutable\n";
try {
    $entryModel->update((int)$postResultA['journal_entry_id'], ['description' => 'hacked']);
    check('JournalEntryModel::update should have thrown', false);
} catch (RuntimeException $e) {
    check('JournalEntryModel::update throws RuntimeException', true);
}
try {
    $entryModel->delete((int)$postResultA['journal_entry_id']);
    check('JournalEntryModel::delete should have thrown', false);
} catch (RuntimeException $e) {
    check('JournalEntryModel::delete throws RuntimeException', true);
}
echo "\n";

// ============================================================
// TEST 17: Audit records created for every transition
// ============================================================
echo "TEST 17: Audit records created for every workflow transition\n";
try {
    $stmt = $db->prepare("SELECT action FROM journal_entry_audit WHERE entity_type='opening_balance_batch' AND entity_id=? ORDER BY id");
    $stmt->execute([$batchA]);
    $actionsA = $stmt->fetchAll(PDO::FETCH_COLUMN);
    check('batchA audit trail has created/submitted/approved/posted',
        in_array('created', $actionsA) && in_array('submitted', $actionsA) &&
        in_array('approved', $actionsA) && in_array('posted', $actionsA));

    $stmt->execute([$batchF]);
    $actionsF = $stmt->fetchAll(PDO::FETCH_COLUMN);
    check('batchF audit trail has created/submitted/rejected/corrected',
        in_array('created', $actionsF) && in_array('submitted', $actionsF) &&
        in_array('rejected', $actionsF) && in_array('corrected', $actionsF));
} catch (Exception $e) {
    check('TEST 17 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// TEST 18: Transaction rollback works
// ============================================================
echo "TEST 18: Transaction rollback — post() inside an outer transaction that gets rolled back\n";
$batchH = null;
try {
    $batchH = $model->createDraft(
        ['financial_year_id' => $FY_ACTIVE, 'accounting_period_id' => $PERIOD_OPEN, 'as_of_date' => '2026-08-25'],
        [
            ['account_id' => $ACC_LOANS, 'debit' => 400, 'credit' => 0],
            ['account_id' => $ACC_SAVINGS, 'debit' => 0, 'credit' => 400],
        ],
        1
    );
    $createdBatchIds[] = $batchH;
    $model->submit($batchH, 1);
    $model->approve($batchH, $tempApproverId);

    $entriesBeforeRollback = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();

    $db->beginTransaction();
    $rollbackResult = $model->post($batchH, 1); // nests inside this outer transaction
    $db->rollBack();

    $entriesAfterRollback = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    check('no new journal entry survives the rollback', $entriesBeforeRollback === $entriesAfterRollback);

    $stmt = $db->prepare('SELECT COUNT(*) FROM journal_entries WHERE id = ?');
    $stmt->execute([$rollbackResult['journal_entry_id']]);
    check('the specific rolled-back journal entry id does not exist', (int)$stmt->fetchColumn() === 0);

    $batch = $model->find($batchH);
    check('batch status reverted to approved (not posted) after rollback', $batch['status'] === 'approved');
    check('batch.journal_entry_id still null after rollback', $batch['journal_entry_id'] === null);
} catch (Exception $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    check('TEST 18 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// CLEANUP — must run BEFORE the regression suites below, so
// they see the true pristine baseline rather than this test's
// still-live (correct, just not yet cleaned up) data.
// ============================================================
echo "=== CLEANUP ===\n";
try {
    // Delete the real posted journal entry from batchA (test 12/13/14)
    if (!empty($postResultA['journal_entry_id'])) {
        $jeId = (int)$postResultA['journal_entry_id'];
        $db->exec("DELETE FROM journal_entry_audit WHERE (entity_type='journal_entry' AND entity_id={$jeId}) OR (entity_type='opening_balance_batch' AND entity_id IN (" . implode(',', $createdBatchIds) . "))");
        $db->exec("DELETE FROM journal_lines WHERE journal_entry_id={$jeId}");
        $db->exec("DELETE FROM journal_entries WHERE id={$jeId}");
        echo "Deleted journal entry {$jeId} and its lines/audit\n";
    } else {
        $db->exec("DELETE FROM journal_entry_audit WHERE entity_type='opening_balance_batch' AND entity_id IN (" . implode(',', $createdBatchIds) . ")");
    }

    // Delete batch lines and batches (opening_balances cascades via FK, but be explicit)
    foreach ($createdBatchIds as $bid) {
        $db->exec("DELETE FROM opening_balances WHERE batch_id={$bid}");
        $db->exec("DELETE FROM opening_balance_batches WHERE id={$bid}");
    }
    echo "Deleted " . count($createdBatchIds) . " test batches and their lines\n";

    // Delete the temporary checker user
    if ($tempApproverId) {
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$tempApproverId]);
        echo "Deleted temporary checker user {$tempApproverId}\n";
    }

    // Restore sequences
    $db->prepare("UPDATE journal_number_sequences SET last_number = ? WHERE prefix = 'JE'")->execute([$beforeJESeq]);
    $db->prepare("UPDATE journal_number_sequences SET last_number = ? WHERE prefix = 'OB'")->execute([$beforeOBSeq]);

    echo "Cleanup complete\n\n";
} catch (Exception $e) {
    echo "Cleanup error: " . $e->getMessage() . "\n\n";
}

// ============================================================
// TEST 19: Existing Step 3 reversal tests still pass
// (run against the now-clean, restored database)
// ============================================================
echo "TEST 19: Regression — Step 3 reversal test suite\n";
$revOutput = shell_exec('"' . PHP_BINARY . '" test_journal_reversal.php 2>&1');
$revPassed = str_contains($revOutput ?? '', 'ALL REVERSAL TESTS PASSED');
check('test_journal_reversal.php reports all tests passed', $revPassed);
if (!$revPassed) { echo $revOutput . "\n"; }
echo "\n";

// ============================================================
// TEST 20: Existing Step 4 period tests still pass
// ============================================================
echo "TEST 20: Regression — Step 4 accounting period test suite\n";
$s4Output = shell_exec('"' . PHP_BINARY . '" test_step4_periods.php 2>&1');
$s4Passed = str_contains($s4Output ?? '', 'ALL STEP 4 TESTS PASSED');
check('test_step4_periods.php reports all tests passed', $s4Passed);
if (!$s4Passed) { echo $s4Output . "\n"; }
echo "\n";

// ============================================================
// FINAL VERIFICATION
// ============================================================
echo "=== FINAL VERIFICATION ===\n";
$afterEntries = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$afterLines   = (int)$db->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
$afterTotals  = $db->query('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines')->fetch();
$afterOB      = (int)$db->query('SELECT COUNT(*) FROM opening_balance_batches')->fetchColumn();

echo "Journal entries: {$afterEntries} (expected {$beforeEntries})\n";
echo "Journal lines: {$afterLines} (expected {$beforeLines})\n";
echo "Debits: " . number_format((float)$afterTotals['d'], 2) . " (expected " . number_format((float)$beforeTotals['d'], 2) . ")\n";
echo "Credits: " . number_format((float)$afterTotals['c'], 2) . " (expected " . number_format((float)$beforeTotals['c'], 2) . ")\n";
echo "Opening balance batches remaining: {$afterOB} (expected 0)\n\n";

$restored = ($afterEntries === $beforeEntries && $afterLines === $beforeLines &&
             abs((float)$afterTotals['d'] - (float)$beforeTotals['d']) < 0.01 &&
             abs((float)$afterTotals['c'] - (float)$beforeTotals['c']) < 0.01 &&
             $afterOB === 0);

check('database fully restored to pre-test state', $restored);

// ============================================================
// SUMMARY
// ============================================================
echo "\n=== TEST SUMMARY ===\n";
echo "Tests Passed: {$testsPassed}\n";
echo "Tests Failed: {$testsFailed}\n\n";

if ($testsFailed === 0) {
    echo "ALL STEP 5 TESTS PASSED\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
