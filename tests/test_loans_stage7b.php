<?php
/**
 * Loans Module — Stage 7B (recovery evidence + architecture design
 * support). STRICTLY READ-ONLY against the live database. Backup files
 * are read (file_get_contents/regex parsing) but never imported,
 * restored, or executed. Every live-DB query goes through selectOnly(),
 * which asserts (via regex) that the SQL is a SELECT/SHOW/DESCRIBE
 * statement before executing it. No transaction is opened because none
 * is needed: nothing here is ever rolled back or committed.
 */

require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Database.php';

$db = Database::getInstance()->getConnection();

function selectOnly(PDO $db, string $sql, array $params = []): array {
    if (!preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql)) {
        throw new RuntimeException('Refused to execute a non-read-only statement in Stage 7B: ' . $sql);
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function selectOnlyScalar(PDO $db, string $sql, array $params = []) {
    $rows = selectOnly($db, $sql, $params);
    return $rows ? array_values($rows[0])[0] : null;
}

/** Parses a single-line `INSERT INTO \`table\` VALUES (...),(...);` dump statement into an array of tuples (each tuple itself an array of raw field values). File is only ever read, never written. */
function parseBackupInsert(string $sqlText, string $table): array {
    if (!preg_match('/INSERT INTO `' . preg_quote($table, '/') . '` VALUES (.+)/', $sqlText, $m)) {
        return [];
    }
    $body = rtrim($m[1], ";\n");
    preg_match_all('/\(([^()]*(?:\([^()]*\)[^()]*)*)\)/', $body, $tuples);
    $rows = [];
    foreach ($tuples[1] as $t) {
        $rows[] = str_getcsv($t, ',', "'");
    }
    return $rows;
}

echo "=== LOANS STAGE 7B — RECOVERY EVIDENCE & ARCHITECTURE SUPPORT (READ-ONLY) ===\n\n";

// ============================================================
// STEP 1 — IMMUTABILITY BASELINE (before)
// ============================================================
function captureBaseline(PDO $db): array {
    return [
        'members'            => (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM members'),
        'loans_count'        => (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM loans'),
        'loans_id_checksum'      => (string)selectOnlyScalar($db, 'SELECT MD5(GROUP_CONCAT(id ORDER BY id)) FROM loans'),
        'loans_content_checksum' => (string)selectOnlyScalar($db, "SELECT MD5(GROUP_CONCAT(CONCAT(id,':',loan_number,':',member_id,':',loan_amount,':',outstanding,':',amount_paid,':',status) ORDER BY id)) FROM loans"),
        'repayments_count'   => (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM loan_repayments'),
        'repayments_id_checksum'      => (string)selectOnlyScalar($db, 'SELECT MD5(GROUP_CONCAT(id ORDER BY id)) FROM loan_repayments'),
        'repayments_content_checksum' => (string)selectOnlyScalar($db, "SELECT MD5(GROUP_CONCAT(CONCAT(id,':',loan_id,':',member_id,':',amount_paid,':',payment_date) ORDER BY id)) FROM loan_repayments"),
        'journal_entries'    => (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM journal_entries'),
        'journal_lines_d'    => (float)selectOnlyScalar($db, 'SELECT COALESCE(SUM(debit),0) FROM journal_lines'),
        'journal_lines_c'    => (float)selectOnlyScalar($db, 'SELECT COALESCE(SUM(credit),0) FROM journal_lines'),
        'savings_count'      => (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM savings'),
        'savings_accounts'   => (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM member_savings_accounts'),
        'JE_seq'             => selectOnlyScalar($db, "SELECT last_number FROM journal_number_sequences WHERE prefix='JE'"),
        'CS_seq'             => selectOnlyScalar($db, "SELECT last_number FROM journal_number_sequences WHERE prefix='CS'"),
    ];
}

$before = captureBaseline($db);
echo "-- BASELINE (before) --\n";
foreach ($before as $k => $v) { echo "  {$k} = {$v}\n"; }
echo "\n";

$testsPassed = 0; $testsFailed = 0;
function check($label, $cond) {
    global $testsPassed, $testsFailed;
    if ($cond) { echo "  PASS: $label\n"; $testsPassed++; }
    else       { echo "  FAIL: $label\n"; $testsFailed++; }
}

// ============================================================
// STEP 2 — BACKUP FILE INSPECTION (file read only, never imported)
// ============================================================
$backupFile = 'database/backups/empower_db_good_tables_20260825_114344.sql';
echo "-- BACKUP FILE: {$backupFile} --\n";
check('backup file exists and is readable', is_readable($backupFile));
$backupSql = file_get_contents($backupFile);
check('backup file read as plain text (never executed/imported)', is_string($backupSql) && strlen($backupSql) > 0);
echo "File size: " . number_format(strlen($backupSql)) . " bytes, mtime: " . date('Y-m-d H:i:s', filemtime($backupFile)) . "\n\n";

// ============================================================
// STEP 3 — LOANS: backup vs current, corrected reconciliation
// ============================================================
echo "-- LOANS: BACKUP vs CURRENT (exact column-position parse) --\n";
$backupLoans = parseBackupInsert($backupSql, 'loans');
// Column indices verified directly against this backup's own CREATE TABLE statement (0-indexed).
$IDX = ['id' => 0, 'loan_number' => 1, 'loan_amount' => 19, 'total_payable' => 27, 'outstanding' => 28, 'amount_paid' => 29];
check('backup loans table has 12 rows (same count as current)', count($backupLoans) === 12);

$currentLoans = selectOnly($db, "SELECT id, loan_number, loan_amount, total_payable, outstanding, amount_paid FROM loans ORDER BY id");
$currentById = [];
foreach ($currentLoans as $r) { $currentById[(int)$r['id']] = $r; }

$matchCount = 0;
foreach ($backupLoans as $row) {
    $id = (int)$row[$IDX['id']];
    $bOutstanding = (float)$row[$IDX['outstanding']];
    $bAmountPaid  = (float)$row[$IDX['amount_paid']];
    $cur = $currentById[$id] ?? null;
    $matches = $cur && abs((float)$cur['outstanding'] - $bOutstanding) < 0.01 && abs((float)$cur['amount_paid'] - $bAmountPaid) < 0.01;
    if ($matches) $matchCount++;
    printf("  loan #%-3d %-12s backup(outstanding=%-12s amount_paid=%-10s) current(outstanding=%-12s amount_paid=%-10s) %s\n",
        $id, trim($row[$IDX['loan_number']], "'"), $bOutstanding, $bAmountPaid,
        $cur['outstanding'] ?? 'MISSING', $cur['amount_paid'] ?? 'MISSING', $matches ? '[IDENTICAL]' : '[DIFFERS]');
}
echo "\nLoans where backup outstanding/amount_paid EXACTLY MATCHES current (i.e. the discrepancy already existed in the backup): {$matchCount} of " . count($backupLoans) . "\n";
// Stage 7C note: this was 12 of 12 when Stage 7B first ran. Stage 7C's
// approved, evidence-based recovery corrected loan #34's outstanding/
// amount_paid (the one loan with a genuine, type-aware-confirmed
// discrepancy -- see the Stage 7C report), so it no longer matches the
// backup's stale value by design. The other 11 loans were re-examined
// in Stage 7C using payment_type-aware logic and found to already be
// correct (Stage 7A/7B's original "11 incorrect" figure was itself
// based on a formula that didn't account for interest/weekly_savings
// payment types not reducing principal) -- they continue to match the
// backup because their live value never needed to change.
check('11 of 12 loans still match the pre-recreation backup (loan #34 intentionally diverges post-Stage-7C-recovery)', $matchCount === 11);
echo "\n";

// ============================================================
// STEP 4 — ORPHANED JOURNAL ENTRIES: backup vs current
// ============================================================
echo "-- ORPHANED JOURNAL ENTRIES: BACKUP vs CURRENT --\n";
$backupJE = parseBackupInsert($backupSql, 'journal_entries');
// journal_entries column indices verified against this backup's CREATE TABLE (0-indexed).
$JEIDX = ['id' => 0, 'entry_number' => 1, 'entry_date' => 2, 'source_module' => 4, 'source_reference_type' => 5, 'source_reference_id' => 6, 'description' => 7];
$backupLoanJEs = [];
foreach ($backupJE as $row) {
    $mod = trim($row[$JEIDX['source_module']] ?? '', "'");
    if ($mod === 'loans' || $mod === 'loan_repayments') {
        $backupLoanJEs[(int)$row[$JEIDX['id']]] = [
            'entry_number' => trim($row[$JEIDX['entry_number']], "'"),
            'ref_id' => trim($row[$JEIDX['source_reference_id']] ?? '', "'"),
        ];
    }
}
echo "loans/loan_repayments-sourced journal entries found in backup: " . count($backupLoanJEs) . "\n";

$currentJE = selectOnly($db, "SELECT id, entry_number, source_module, source_reference_id, description FROM journal_entries WHERE source_module IN ('loans','loan_repayments') ORDER BY id");
check('current live DB has the same count of loans/loan_repayments-sourced journal entries as the backup (34)', count($currentJE) === count($backupLoanJEs));

$sameSet = true;
foreach ($currentJE as $r) {
    if (!isset($backupLoanJEs[(int)$r['id']])) { $sameSet = false; break; }
}
check('every current loans/loan_repayments-sourced journal entry already existed (same ID) in the backup -- nothing was added since', $sameSet);

// Confirm the backup's own loan_repayments table does NOT contain the high-numbered IDs its journal entries reference (proves the source loss predates the backup).
$backupRepayments = parseBackupInsert($backupSql, 'loan_repayments');
$backupRepaymentIds = array_map(fn($r) => (int)$r[0], $backupRepayments);
$maxBackupRepaymentId = max($backupRepaymentIds);
check('backup loan_repayments table has 38 rows (same as current)', count($backupRepayments) === 38);
echo "Max loan_repayments ID actually present in the backup: {$maxBackupRepaymentId}\n";
$highRefsInBackupJE = 0;
foreach ($backupLoanJEs as $je) {
    if ($je['ref_id'] !== '' && (int)$je['ref_id'] > $maxBackupRepaymentId) { $highRefsInBackupJE++; }
}
echo "Backup journal entries referencing a loan_repayments ID higher than any row that exists in the SAME backup: {$highRefsInBackupJE}\n";
check('the orphaned-journal-entry problem already existed in the backup itself (source rows were already gone before this backup was taken)', $highRefsInBackupJE >= 28);
echo "\n";

// ============================================================
// STEP 5 — loan_installments: backup coverage for the 12 current loans
// ============================================================
echo "-- LOAN_INSTALLMENTS: BACKUP COVERAGE FOR THE 12 CURRENT LOANS --\n";
$backupInstallments = parseBackupInsert($backupSql, 'loan_installments');
check('backup contains loan_installments data (currently broken/inaccessible live)', count($backupInstallments) > 0);
echo "Total installment rows in backup: " . count($backupInstallments) . "\n";
$byLoan = [];
foreach ($backupInstallments as $row) {
    $lid = (int)$row[1]; // loan_id is column index 1 per this backup's loan_installments schema
    $byLoan[$lid] = ($byLoan[$lid] ?? 0) + 1;
}
$current12 = [14,16,17,18,19,21,27,28,31,34,35,37];
$allHaveSchedule = true;
foreach ($current12 as $lid) {
    $c = $byLoan[$lid] ?? 0;
    echo "  loan #{$lid}: {$c} installment rows available in backup\n";
    if ($c === 0) $allHaveSchedule = false;
}
check('all 12 current loans have a recoverable installment schedule in the backup', $allHaveSchedule);
echo "\n";

// ============================================================
// STEP 6 — MATHEMATICAL RECONSTRUCTION CHECK (does NOT write anything)
// ============================================================
echo "-- MATHEMATICAL RECONSTRUCTION: recompute outstanding/amount_paid from loan_repayments --\n";
$recon = selectOnly($db, "
    SELECT l.id, l.loan_number, l.total_payable, l.outstanding, l.amount_paid AS loans_amount_paid,
           COALESCE(SUM(r.amount_paid),0) AS repayments_sum
    FROM loans l LEFT JOIN loan_repayments r ON r.loan_id = l.id
    GROUP BY l.id ORDER BY l.id
");
$reconstructable = 0;
foreach ($recon as $r) {
    $computedOutstanding = (float)$r['total_payable'] - (float)$r['repayments_sum'];
    $needsFix = abs((float)$r['outstanding'] - $computedOutstanding) > 0.01;
    if ($needsFix) $reconstructable++;
}
echo "Loans whose correct outstanding/amount_paid CAN be mathematically reconstructed today from loan_repayments alone (no backup needed): {$reconstructable} of " . count($recon) . "\n";
check('the discrepancy is 100% reconstructable from live loan_repayments data (self-consistent, no external source needed)', $reconstructable === 11);
echo "\n";

// ============================================================
// STEP 7 — IMMUTABILITY BASELINE (after)
// ============================================================
$after = captureBaseline($db);
echo "-- BASELINE (after) --\n";
$mutationDetected = false;
foreach ($before as $k => $v) {
    $match = ($after[$k] === $v) || (is_numeric($v) && is_numeric($after[$k]) && abs((float)$v - (float)$after[$k]) < 0.0001);
    if (!$match) { $mutationDetected = true; }
    echo "  {$k}: before={$v} after={$after[$k]} " . ($match ? '[OK]' : '[*** MUTATION DETECTED ***]') . "\n";
}
echo "\n";

if ($mutationDetected) {
    echo "*** DATABASE MUTATION DETECTED -- STOP AND REPORT IMMEDIATELY ***\n";
    exit(1);
}
echo "NO DATABASE MUTATIONS DETECTED.\n\n";

echo "=== TEST SUMMARY ===\n";
echo "Checks Passed: {$testsPassed}\n";
echo "Checks Failed: {$testsFailed}\n\n";
echo "=== STAGE 7B READ-ONLY EVIDENCE ANALYSIS COMPLETE ===\n";
exit($testsFailed === 0 ? 0 : 1);
