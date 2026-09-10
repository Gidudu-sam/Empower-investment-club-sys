<?php
/**
 * Loans Module — Stage 7A (forensic + architecture audit). STRICTLY
 * READ-ONLY. No INSERT/UPDATE/DELETE/ALTER/DROP/TRUNCATE/RENAME of any
 * kind is ever constructed in this file. Every query goes through
 * selectOnly(), which asserts (via regex, before execution) that the
 * SQL is a SELECT/SHOW/DESCRIBE statement -- a defensive guard against
 * an accidental write slipping in, on top of the fact that no write
 * statement is ever written anywhere in this file. No transaction is
 * opened because none is needed: nothing here is ever rolled back or
 * committed.
 */

require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Database.php';

$db = Database::getInstance()->getConnection();

function selectOnly(PDO $db, string $sql, array $params = []): array {
    if (!preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql)) {
        throw new RuntimeException('Refused to execute a non-read-only statement in Stage 7A: ' . $sql);
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function selectOnlyScalar(PDO $db, string $sql, array $params = []) {
    $rows = selectOnly($db, $sql, $params);
    return $rows ? array_values($rows[0])[0] : null;
}

echo "=== LOANS STAGE 7A — READ-ONLY FORENSIC & ARCHITECTURE AUDIT ===\n\n";

// ============================================================
// STEP 1 — IMMUTABILITY BASELINE (before)
// ============================================================
function captureBaseline(PDO $db): array {
    return [
        'members'            => (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM members'),
        'loans_count'        => (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM loans'),
        'loans_total_payable'=> (float)selectOnlyScalar($db, 'SELECT COALESCE(SUM(total_payable),0) FROM loans'),
        'loans_outstanding'  => (float)selectOnlyScalar($db, 'SELECT COALESCE(SUM(outstanding),0) FROM loans'),
        'loans_id_checksum'      => (string)selectOnlyScalar($db, 'SELECT MD5(GROUP_CONCAT(id ORDER BY id)) FROM loans'),
        'loans_content_checksum' => (string)selectOnlyScalar($db, "SELECT MD5(GROUP_CONCAT(CONCAT(id,':',loan_number,':',member_id,':',loan_amount,':',outstanding,':',amount_paid,':',status) ORDER BY id)) FROM loans"),
        'repayments_count'   => (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM loan_repayments'),
        'repayments_sum'     => (float)selectOnlyScalar($db, 'SELECT COALESCE(SUM(amount_paid),0) FROM loan_repayments'),
        'repayments_id_checksum'      => (string)selectOnlyScalar($db, 'SELECT MD5(GROUP_CONCAT(id ORDER BY id)) FROM loan_repayments'),
        'repayments_content_checksum' => (string)selectOnlyScalar($db, "SELECT MD5(GROUP_CONCAT(CONCAT(id,':',loan_id,':',member_id,':',amount_paid,':',payment_date) ORDER BY id)) FROM loan_repayments"),
        'journal_entries'    => (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM journal_entries'),
        'journal_lines_d'    => (float)selectOnlyScalar($db, 'SELECT COALESCE(SUM(debit),0) FROM journal_lines'),
        'journal_lines_c'    => (float)selectOnlyScalar($db, 'SELECT COALESCE(SUM(credit),0) FROM journal_lines'),
        'savings_count'      => (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM savings'),
        'JE_seq'             => selectOnlyScalar($db, "SELECT last_number FROM journal_number_sequences WHERE prefix='JE'"),
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
// STEP 2 — SCHEMA / TABLE EXISTENCE AUDIT
// ============================================================
echo "-- TABLE EXISTENCE / ENGINE STATUS --\n";
$loanTables = ['loans','loan_repayments','loan_types','loan_product_rules','loan_product_settings',
    'loan_interest_brackets','loan_penalties','loan_installments','loan_weekly_interest','loan_weekly_savings',
    'business_loan_interest_payments','business_loan_weekly_savings','loan_provisioning_buckets','repayment_installment_allocations'];
$brokenTables = [];
foreach ($loanTables as $t) {
    $status = selectOnly($db, "SHOW TABLE STATUS LIKE " . $db->quote($t));
    $engine = $status[0]['Engine'] ?? null;
    $comment = $status[0]['Comment'] ?? '';
    $broken = ($engine === null);
    if ($broken) $brokenTables[] = $t;
    echo sprintf("  %-38s engine=%-8s rows=%-6s %s\n", $t, $engine ?? 'NULL', $status[0]['Rows'] ?? 'NULL', $broken ? "[BROKEN: {$comment}]" : '');
}
echo "\nBroken (InnoDB dictionary orphaned) tables: " . (empty($brokenTables) ? 'none' : implode(', ', $brokenTables)) . "\n";
$mysql50Count = (int)selectOnlyScalar($db, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '%mysql50%'");
echo "Database-wide '#mysql50#...(2)' ghost table entries (all tables, not Loans-specific): {$mysql50Count}\n\n";

// ============================================================
// STEP 3 — LOAN INVENTORY
// ============================================================
echo "-- LOAN INVENTORY ({$before['loans_count']} rows) --\n";
$loans = selectOnly($db, "SELECT l.id, l.loan_number, l.member_id, m.first_name, m.last_name, l.loan_type_id, lt.name AS type_name,
    l.loan_amount, l.total_payable, l.outstanding, l.amount_paid, l.status, l.issue_date, l.due_date, l.journal_entry_id, l.is_migrated
    FROM loans l JOIN members m ON m.id=l.member_id LEFT JOIN loan_types lt ON lt.id=l.loan_type_id ORDER BY l.id");
foreach ($loans as $l) {
    printf("  #%-3d %-12s member=%-3d %-20s type=%-22s amount=%-12s payable=%-12s outstanding=%-12s paid=%-10s status=%-9s je=%s\n",
        $l['id'], $l['loan_number'], $l['member_id'], $l['first_name'].' '.$l['last_name'], $l['type_name'] ?? '?',
        $l['loan_amount'], $l['total_payable'], $l['outstanding'], $l['amount_paid'], $l['status'], $l['journal_entry_id'] ?? 'NULL');
}
echo "\n";

// ============================================================
// STEP 4 — RECONCILIATION: stored outstanding/amount_paid vs
// computed from loan_repayments
// ============================================================
echo "-- RECONCILIATION: loans.outstanding/amount_paid vs SUM(loan_repayments.amount_paid) --\n";
$recon = selectOnly($db, "
    SELECT l.id, l.loan_number, l.total_payable, l.outstanding, l.amount_paid AS loans_amount_paid,
           COALESCE(SUM(r.amount_paid),0) AS repayments_sum, COUNT(r.id) AS repayment_count
    FROM loans l LEFT JOIN loan_repayments r ON r.loan_id = l.id
    GROUP BY l.id ORDER BY l.id
");
$mismatchCount = 0;
$mismatchTotal = 0.0;
foreach ($recon as $r) {
    $computedOutstanding = (float)$r['total_payable'] - (float)$r['repayments_sum'];
    $diff = (float)$r['outstanding'] - $computedOutstanding;
    if (abs($diff) > 0.01) { $mismatchCount++; $mismatchTotal += $diff; }
    printf("  #%-3d %-12s total_payable=%-12s stored_outstanding=%-12s repayments_sum=%-12s(n=%d) computed_outstanding=%-12s DIFF=%s\n",
        $r['id'], $r['loan_number'], $r['total_payable'], $r['outstanding'], $r['repayments_sum'], $r['repayment_count'], $computedOutstanding, $diff);
}
echo "\nLoans with stored outstanding NOT matching computed (total_payable - repayments_sum): {$mismatchCount} of " . count($recon) . "\n";
echo "Sum of discrepancy across mismatched loans: " . number_format($mismatchTotal, 2) . "\n\n";

// ============================================================
// STEP 5 — ORPHANED JOURNAL ENTRIES (loans + loan_repayments sourced)
// ============================================================
echo "-- ORPHANED JOURNAL ENTRY INVESTIGATION --\n";
$jeRows = selectOnly($db, "SELECT je.id, je.entry_number, je.entry_date, je.source_module, je.source_reference_type, je.source_reference_id, je.description
    FROM journal_entries je WHERE je.source_module IN ('loans','loan_repayments') ORDER BY je.id");
$orphaned = [];
$reversals = [];
foreach ($jeRows as $r) {
    if ($r['source_reference_id'] === null || $r['source_reference_id'] === '') {
        $reversals[] = $r;
        continue;
    }
    $table = ($r['source_module'] === 'loans') ? 'loans' : 'loan_repayments';
    $exists = (int)selectOnlyScalar($db, "SELECT COUNT(*) FROM `{$table}` WHERE id=?", [$r['source_reference_id']]);
    if ($exists === 0) { $orphaned[] = $r; }
}
echo "Total loans/loan_repayments-sourced journal entries: " . count($jeRows) . "\n";
echo "Orphaned (source_reference_id no longer exists): " . count($orphaned) . "\n";
foreach ($orphaned as $r) {
    echo "  JE#{$r['id']} {$r['entry_number']} date={$r['entry_date']} module={$r['source_module']} type={$r['source_reference_type']} ref_id={$r['source_reference_id']} desc={$r['description']}\n";
}
echo "Reversal entries (no source_reference_id by design, not FK-orphaned): " . count($reversals) . "\n";
foreach ($reversals as $r) {
    echo "  JE#{$r['id']} {$r['entry_number']} date={$r['entry_date']} desc={$r['description']}\n";
}
if (!empty($orphaned)) {
    $ids = implode(',', array_column($orphaned, 'id'));
    $sums = selectOnly($db, "SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines WHERE journal_entry_id IN ({$ids})");
    echo "Orphaned entries' journal_lines totals: debit={$sums[0]['d']} credit={$sums[0]['c']}\n";
}
echo "\n";

// ============================================================
// STEP 6 — LOAN TYPES / PRODUCT RULES vs INTENDED BUSINESS SPEC
// ============================================================
echo "-- LOAN TYPES --\n";
foreach (selectOnly($db, "SELECT id, name, repayment_type, is_active FROM loan_types ORDER BY id") as $r) {
    echo "  #{$r['id']} {$r['name']} ({$r['repayment_type']}) active=" . ($r['is_active'] ? 'yes' : 'no') . "\n";
}
echo "\n-- loan_product_settings coverage --\n";
$settingsCount = (int)selectOnlyScalar($db, "SELECT COUNT(*) FROM loan_product_settings");
$typesCount = (int)selectOnlyScalar($db, "SELECT COUNT(*) FROM loan_types");
echo "loan_product_settings rows: {$settingsCount} of {$typesCount} loan_types\n";
$typesWithoutSettings = selectOnly($db, "SELECT lt.id, lt.name FROM loan_types lt LEFT JOIN loan_product_settings lps ON lps.loan_type_id = lt.id WHERE lps.id IS NULL");
foreach ($typesWithoutSettings as $r) { echo "  NO loan_product_settings row: #{$r['id']} {$r['name']}\n"; }
echo "\n-- loan_interest_brackets --\n";
foreach (selectOnly($db, "SELECT lib.loan_type_id, lt.name, lib.bracket_name, lib.min_amount, lib.max_amount, lib.monthly_rate FROM loan_interest_brackets lib JOIN loan_types lt ON lt.id=lib.loan_type_id ORDER BY lib.loan_type_id, lib.sort_order") as $r) {
    echo "  {$r['name']}: {$r['bracket_name']} [{$r['min_amount']}-{$r['max_amount']}] = {$r['monthly_rate']}%\n";
}
echo "\n";

// ============================================================
// STEP 7 — PENALTY ENGINE: exists, callers, dependency status
// ============================================================
echo "-- PENALTY ENGINE --\n";
$penaltyRows = (int)selectOnlyScalar($db, "SELECT COUNT(*) FROM loan_penalties");
echo "loan_penalties rows: {$penaltyRows}\n";
echo "loan_penalties.installment_id FK target (loan_installments) broken: " . (in_array('loan_installments', $brokenTables) ? 'YES -- calculatePenalties() cannot function' : 'no') . "\n\n";

// ============================================================
// STEP 8 — SECURITY: role checks present in Loan/Repayment controllers
// ============================================================
echo "-- SECURITY: role-check presence (grep-verified) --\n";
$loanCtrlSrc = file_get_contents('app/controllers/LoanController.php');
$repayCtrlSrc = file_get_contents('app/controllers/RepaymentController.php');
check('LoanController has NO hasRole()/role gate anywhere (any authenticated user, any role, may create/edit/delete/complete loans)', !str_contains($loanCtrlSrc, 'hasRole('));
preg_match('/public function delete\(\).*?\n    \}/s', $loanCtrlSrc, $deleteBodyMatch);
$deleteBody = $deleteBodyMatch[0] ?? '';
check('LoanController::delete() has no CSRF check (GET-triggered)', $deleteBody !== '' && !str_contains($deleteBody, 'verifyCsrf'));
check('RepaymentController::delete() restricts to admin role', str_contains($repayCtrlSrc, "user_role') !== 'admin'"));
$loanModelSrc = file_get_contents('app/models/LoanModel.php');
check('LoanModel does NOT override delete() (no journal-entry protection like ExpenseModel::delete())', !str_contains($loanModelSrc, 'function delete('));
echo "\n";

// ============================================================
// STEP 9 — SAVINGS ELIGIBILITY DEPENDENCY
// ============================================================
echo "-- SAVINGS ELIGIBILITY DEPENDENCY --\n";
check('LoanController does not reference SavingsModel/member_savings_accounts anywhere', !preg_match('/SavingsModel|member_savings_account/i', $loanCtrlSrc));
check('LoanController::validate() has no min_savings_months/eligibility check', !preg_match('/min_savings_months|requires_income_source|requires_security/', $loanCtrlSrc));
echo "\n";

// ============================================================
// STEP 10 — NUMBERING MECHANISM (vs the row-locked journal_number_sequences pattern)
// ============================================================
echo "-- LOAN/REPAYMENT NUMBERING MECHANISM --\n";
$lnsSeq = selectOnlyScalar($db, "SELECT last_number FROM journal_number_sequences WHERE prefix='LNS'");
$paySeq = selectOnlyScalar($db, "SELECT last_number FROM journal_number_sequences WHERE prefix='PAY'");
echo "journal_number_sequences row for 'LNS': " . ($lnsSeq === false ? 'NONE (uses MAX()+1 pattern instead, not row-locked)' : $lnsSeq) . "\n";
echo "journal_number_sequences row for 'PAY': " . ($paySeq === false ? 'NONE (uses MAX()+1 pattern instead, not row-locked)' : $paySeq) . "\n";
echo "JE sequence counter (journal_number_sequences 'JE'): {$before['JE_seq']} vs actual journal_entries row count: {$before['journal_entries']} -- gap of " . ((int)$before['JE_seq'] - $before['journal_entries']) . " suggests substantially more journal entries existed historically than survive today.\n\n";

// ============================================================
// STEP 11 — IMMUTABILITY BASELINE (after)
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
echo "=== STAGE 7A READ-ONLY FORENSIC AUDIT COMPLETE ===\n";
exit($testsFailed === 0 ? 0 : 1);
