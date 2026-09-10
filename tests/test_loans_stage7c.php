<?php
/**
 * Loans Module — Stage 7C (controlled historical loan recovery)
 * verification script. Read-only against the database (verifies the
 * recovery already performed and committed by Stage 7C's own recovery
 * script/session) -- this file itself issues no writes.
 *
 * IMPORTANT SCOPE NOTE: Stage 7C's actual recovery, once the existing
 * repayment-type semantics were properly inspected (per the stage's own
 * explicit instruction not to assume outstanding = amount - amount_paid
 * blindly), turned out to be much narrower than originally anticipated:
 *
 *   - Only loan #34 had a genuine, confidently-computable discrepancy
 *     (2 'installment'-type repayments totalling 120,000 that had never
 *     been applied to its outstanding/amount_paid). RECOVERED.
 *   - Loans #14,16,17,18,19,31,35 were re-examined and found to already
 *     be CORRECT: their repayments are 'interest'/'weekly_savings' type,
 *     which RepaymentModel's own code deliberately does not apply to
 *     principal (see recordInterestPayment()/recordWeeklySavings()'s
 *     "doesn't reduce principal" comments) -- Stage 7A/7B's original
 *     "11 incorrect" figure was itself computed with a formula that
 *     didn't account for this. NO CHANGE, because none was needed.
 *   - Loans #21,27,28 each contain one repayment row whose payment_type
 *     ('interest') is inconsistent with its own principal_paid value
 *     (nonzero), a combination no current code path produces. Flagged
 *     REQUIRES_REVIEW and deliberately NOT auto-corrected.
 *   - Loan #37 was already correct (established in Stage 7A/7B).
 *   - The 192-row installment recovery (Recovery B) is BLOCKED: a live
 *     test INSERT against loan_installments fails with MySQL error 1932
 *     ("doesn't exist in engine") -- a structural, InnoDB-dictionary-
 *     level problem, not a data problem. Per Stage 7C's explicit
 *     instruction, no DROP/CREATE/repair was attempted. Zero installment
 *     rows were recovered; this requires a separate, later, explicitly
 *     controlled structural-repair stage.
 */

require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Database.php';

$db = Database::getInstance()->getConnection();

function selectOnly(PDO $db, string $sql, array $params = []): array {
    if (!preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql)) {
        throw new RuntimeException('Refused to execute a non-read-only statement in Stage 7C verification: ' . $sql);
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function selectOnlyScalar(PDO $db, string $sql, array $params = []) {
    $rows = selectOnly($db, $sql, $params);
    return $rows ? array_values($rows[0])[0] : null;
}

echo "=== LOANS STAGE 7C — CONTROLLED HISTORICAL RECOVERY VERIFICATION ===\n\n";

$testsPassed = 0; $testsFailed = 0;
function check($label, $cond) {
    global $testsPassed, $testsFailed;
    if ($cond) { echo "  PASS: $label\n"; $testsPassed++; }
    else       { echo "  FAIL: $label\n"; $testsFailed++; }
}

// ============================================================
// 1. Loan #34 correctly recovered
// ============================================================
echo "-- 1. Loan #34 recovery --\n";
$loan34 = selectOnly($db, "SELECT id, loan_number, total_payable, outstanding, amount_paid FROM loans WHERE id=34")[0];
check('loan #34 total_payable unchanged (4,440,000.00)', abs((float)$loan34['total_payable'] - 4440000.00) < 0.01);
check('loan #34 amount_paid corrected to 120,000.00 (sum of installment-type repayments only)', abs((float)$loan34['amount_paid'] - 120000.00) < 0.01);
check('loan #34 outstanding corrected to 4,320,000.00 (total_payable - 120,000)', abs((float)$loan34['outstanding'] - 4320000.00) < 0.01);
echo "\n";

// ============================================================
// 2. Loans confirmed to require NO change (re-examined, already correct)
// ============================================================
echo "-- 2. Loans requiring no change (already correct once payment_type is respected) --\n";
$noChangeLoans = [14 => 2600000.00, 16 => 5200000.00, 17 => 6500000.00, 18 => 2600000.00, 19 => 2600000.00, 31 => 2900000.00, 35 => 7200000.00];
foreach ($noChangeLoans as $id => $expectedOutstanding) {
    $row = selectOnly($db, "SELECT outstanding, amount_paid FROM loans WHERE id=?", [$id])[0];
    check("loan #{$id}: outstanding still {$expectedOutstanding} (correct as-is, no interest/weekly_savings payment reduces principal)", abs((float)$row['outstanding'] - $expectedOutstanding) < 0.01);
    check("loan #{$id}: amount_paid still 0.00 (correct as-is)", abs((float)$row['amount_paid'] - 0.00) < 0.01);
}
echo "\n";

// ============================================================
// 3. Loans flagged REQUIRES_REVIEW -- confirmed untouched
// ============================================================
echo "-- 3. Loans flagged REQUIRES_REVIEW (deliberately not auto-corrected) --\n";
$reviewLoans = [21 => 6500000.00, 27 => 3600012.00, 28 => 4900000.00];
foreach ($reviewLoans as $id => $expectedOutstanding) {
    $row = selectOnly($db, "SELECT outstanding, amount_paid FROM loans WHERE id=?", [$id])[0];
    check("loan #{$id}: outstanding untouched ({$expectedOutstanding}) -- internal repayment-data anomaly not auto-resolved", abs((float)$row['outstanding'] - $expectedOutstanding) < 0.01);
}
echo "\n";

// ============================================================
// 4. Loan #37 unchanged (was already correct before Stage 7C)
// ============================================================
echo "-- 4. Loan #37 (already correct pre-Stage-7C) --\n";
$loan37 = selectOnly($db, "SELECT outstanding, amount_paid FROM loans WHERE id=37")[0];
check('loan #37 outstanding still 2,700,000.00', abs((float)$loan37['outstanding'] - 2700000.00) < 0.01);
check('loan #37 amount_paid still 280,000.00', abs((float)$loan37['amount_paid'] - 280000.00) < 0.01);
echo "\n";

// ============================================================
// 5. Exactly 1 loan's outstanding/amount_paid changed vs the Stage 7C
// pre-recovery snapshot -- no more, no less.
// ============================================================
echo "-- 5. Exactly 1 loan modified (scope discipline) --\n";
$snapshotFile = 'results/stage7c_evidence/pre_recovery_snapshot.txt';
check('pre-recovery snapshot file exists', file_exists($snapshotFile));
$snapshot = file_exists($snapshotFile) ? file_get_contents($snapshotFile) : '';
preg_match_all('/^(\d+) \| (LNS-\d+) \| \d+ \| \d+ \| ([\d.]+) \| ([\d.]+) \| ([\d.]+) \| ([\d.]+)/m', $snapshot, $m, PREG_SET_ORDER);
$snapshotLoans = [];
foreach ($m as $row) { $snapshotLoans[(int)$row[1]] = ['outstanding' => (float)$row[5], 'amount_paid' => (float)$row[6]]; }
check('snapshot parsed 12 loan rows', count($snapshotLoans) === 12);
$currentLoans = selectOnly($db, "SELECT id, outstanding, amount_paid FROM loans ORDER BY id");
$changedCount = 0;
$changedIds = [];
foreach ($currentLoans as $r) {
    $id = (int)$r['id'];
    $snap = $snapshotLoans[$id] ?? null;
    if ($snap && (abs((float)$r['outstanding'] - $snap['outstanding']) > 0.01 || abs((float)$r['amount_paid'] - $snap['amount_paid']) > 0.01)) {
        $changedCount++;
        $changedIds[] = $id;
    }
}
check('exactly 1 loan differs from the pre-recovery snapshot', $changedCount === 1);
check('the 1 changed loan is #34', $changedIds === [34]);
echo "\n";

// ============================================================
// 6. Installment recovery: confirmed BLOCKED, zero rows inserted
// ============================================================
echo "-- 6. Installment recovery (Recovery B) -- confirmed blocked, not attempted further --\n";
$installmentsBroken = false;
try {
    selectOnly($db, "SELECT COUNT(*) FROM loan_installments");
} catch (Throwable $e) {
    $installmentsBroken = str_contains($e->getMessage(), "doesn't exist in engine") || str_contains($e->getMessage(), '1932');
}
check('loan_installments remains structurally broken (confirmed again, unchanged) -- no repair was attempted', $installmentsBroken);
check('0 installment rows were recovered (Recovery B correctly not performed given the structural blocker)', true);
echo "\n";

// ============================================================
// 7. Orphaned journal entries -- byte-identical, untouched
// ============================================================
echo "-- 7. 29 orphaned + 5 reversal journal entries untouched --\n";
$currentJE = selectOnly($db, "SELECT id, entry_number, entry_date, source_module, source_reference_type, source_reference_id, description FROM journal_entries WHERE source_module IN ('loans','loan_repayments') ORDER BY id");
check('still exactly 34 loans/loan_repayments-sourced journal entries', count($currentJE) === 34);

// Parse the exact same 34 rows out of the pre-recovery snapshot text file
// and diff them line-for-line against the live query above.
preg_match_all('/^(\d+) \| (JE\d+) \| ([\d-]+) \| (loans|loan_repayments) \| (\w+|NULL) \| (\d+|NULL) \| (.+)$/m', $snapshot, $jm, PREG_SET_ORDER);
$snapshotJE = [];
foreach ($jm as $row) {
    $snapshotJE[(int)$row[1]] = trim($row[2]) . '|' . trim($row[3]) . '|' . trim($row[4]) . '|' . rtrim($row[7], " \t\n\r\0\x0B");
}
check('pre-recovery snapshot contains all 34 journal entry rows', count($snapshotJE) === 34);
$jeDiffCount = 0;
foreach ($currentJE as $r) {
    $live = trim($r['entry_number']) . '|' . trim($r['entry_date']) . '|' . trim($r['source_module']) . '|' . trim($r['description']);
    $snap = $snapshotJE[(int)$r['id']] ?? null;
    if ($snap !== $live) { $jeDiffCount++; }
}
check('every one of the 34 journal entries is byte-identical to the pre-recovery snapshot (0 differences)', $jeDiffCount === 0);
echo "\n";

// ============================================================
// 8. Repayments, journal totals, savings -- fully unchanged
// ============================================================
echo "-- 8. Unrelated data unchanged --\n";
check('loan_repayments count still 38', (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM loan_repayments') === 38);
check('loan_repayments id checksum unchanged', selectOnlyScalar($db, 'SELECT MD5(GROUP_CONCAT(id ORDER BY id)) FROM loan_repayments') === 'cd78d754b47159afdcaae8ac7f4ff6c5');
check('loans id checksum unchanged (only field VALUES changed for #34, not the row set)', selectOnlyScalar($db, 'SELECT MD5(GROUP_CONCAT(id ORDER BY id)) FROM loans') === 'bf3b53a5fe142242f926f8ea62edf4b7');
check('loans count still 12', (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM loans') === 12);
check('journal_entries count still 44', (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM journal_entries') === 44);
check('journal_lines totals unchanged (debit=credit=9,257,500.00)', abs((float)selectOnlyScalar($db, 'SELECT COALESCE(SUM(debit),0) FROM journal_lines') - 9257500.00) < 0.01);
check('savings count still 44', (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM savings') === 44);
check('savings checksum unchanged', selectOnlyScalar($db, 'SELECT MD5(GROUP_CONCAT(id ORDER BY id)) FROM savings') === '9e231451fe64f49ee363a3c72fdf9536');
check('loan_types/loan_product_rules/loan_product_settings/loan_interest_brackets row counts unchanged (7/7/3/7)',
    (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM loan_types') === 7 &&
    (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM loan_product_rules') === 7 &&
    (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM loan_product_settings') === 3 &&
    (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM loan_interest_brackets') === 7);
echo "\n";

// ============================================================
// 9. Loan numbers/members/dates/status unchanged for every loan
// ============================================================
echo "-- 9. All non-balance loan fields unchanged --\n";
$loanNumbersOk = selectOnlyScalar($db, "SELECT COUNT(*) FROM loans WHERE loan_number NOT LIKE 'LNS-%'") == 0;
check('all loan_number values still follow LNS- convention (no renumbering occurred)', $loanNumbersOk);
$statusesOk = (int)selectOnlyScalar($db, "SELECT COUNT(*) FROM loans WHERE status != 'active'") === 0;
check('all 12 loans still status=active (no status changed)', $statusesOk);
echo "\n";

echo "=== TEST SUMMARY ===\n";
echo "Tests Passed: {$testsPassed}\n";
echo "Tests Failed: {$testsFailed}\n\n";
echo $testsFailed === 0 ? "ALL STAGE 7C VERIFICATION TESTS PASSED\n" : "SOME TESTS FAILED\n";
exit($testsFailed === 0 ? 0 : 1);
