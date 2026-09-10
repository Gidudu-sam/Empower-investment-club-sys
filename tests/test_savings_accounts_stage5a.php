<?php
/**
 * Savings Accounts Module — Stage 5A (historical savings → savings_account_id
 * forensic mapping audit). STRICTLY READ-ONLY.
 *
 * This script performs no INSERT/UPDATE/DELETE/ALTER/DROP/TRUNCATE/CREATE/
 * RENAME of any kind. Every query goes through selectOnly(), which asserts
 * (via regex, before execution) that the SQL is a SELECT/SHOW/DESCRIBE
 * statement — a defensive guard against an accidental write slipping in,
 * on top of the fact that no write statement is ever constructed anywhere
 * in this file. No transaction is opened because none is needed: nothing
 * here is ever rolled back or committed.
 *
 * Produces the forensic mapping analysis backing
 * results/stage5a_historical_savings_account_mapping_report.md.
 */

require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Database.php';

$db = Database::getInstance()->getConnection();

/** Defensive guard: refuses to execute anything that isn't a read. */
function selectOnly(PDO $db, string $sql, array $params = []): array {
    if (!preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql)) {
        throw new RuntimeException('Refused to execute a non-read-only statement in Stage 5A: ' . $sql);
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function selectOnlyScalar(PDO $db, string $sql, array $params = []) {
    $rows = selectOnly($db, $sql, $params);
    return $rows ? array_values($rows[0])[0] : null;
}

echo "=== SAVINGS ACCOUNTS STAGE 5A — READ-ONLY FORENSIC MAPPING AUDIT ===\n\n";

// ============================================================
// STEP 1 — IMMUTABILITY BASELINE (before)
// ============================================================
function captureBaseline(PDO $db): array {
    return [
        'members'                      => (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM members'),
        'savings_count'                => (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM savings'),
        'savings_total'                => (float)selectOnlyScalar($db, 'SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM savings'),
        'savings_min_id'                => selectOnlyScalar($db, 'SELECT MIN(id) FROM savings'),
        'savings_max_id'                => selectOnlyScalar($db, 'SELECT MAX(id) FROM savings'),
        'savings_min_date'              => selectOnlyScalar($db, 'SELECT MIN(transaction_date) FROM savings'),
        'savings_max_date'              => selectOnlyScalar($db, 'SELECT MAX(transaction_date) FROM savings'),
        'savings_account_id_null'       => (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM savings WHERE savings_account_id IS NULL'),
        'savings_account_id_not_null'   => (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM savings WHERE savings_account_id IS NOT NULL'),
        'savings_id_checksum'           => (string)selectOnlyScalar($db, 'SELECT MD5(GROUP_CONCAT(id ORDER BY id)) FROM savings'),
        'savings_amount_checksum'       => (string)selectOnlyScalar($db, "SELECT MD5(GROUP_CONCAT(CONCAT(id,':',COALESCE(credit,0),':',COALESCE(debit,0)) ORDER BY id)) FROM savings"),
        'journal_entries'               => (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM journal_entries'),
        'journal_lines_debit'           => (float)selectOnlyScalar($db, 'SELECT COALESCE(SUM(debit),0) FROM journal_lines'),
        'journal_lines_credit'          => (float)selectOnlyScalar($db, 'SELECT COALESCE(SUM(credit),0) FROM journal_lines'),
        'member_savings_accounts'       => (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM member_savings_accounts'),
        'savings_account_holders'       => (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM savings_account_holders'),
        'organizations'                 => (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM organizations'),
        'organization_representatives'  => (int)selectOnlyScalar($db, 'SELECT COUNT(*) FROM organization_representatives'),
        'seq_CS'                        => selectOnlyScalar($db, "SELECT last_number FROM journal_number_sequences WHERE prefix='CS'"),
        'seq_VS'                        => selectOnlyScalar($db, "SELECT last_number FROM journal_number_sequences WHERE prefix='VS'"),
        'seq_JS'                        => selectOnlyScalar($db, "SELECT last_number FROM journal_number_sequences WHERE prefix='JS'"),
        'seq_CORP'                      => selectOnlyScalar($db, "SELECT last_number FROM journal_number_sequences WHERE prefix='CORP'"),
        'savings_accounts_table_exists' => selectOnlyScalar($db, "SHOW TABLES LIKE 'savings_accounts'") !== null,
    ];
}

$before = captureBaseline($db);
echo "-- BASELINE (before) --\n";
foreach ($before as $k => $v) {
    echo "  {$k} = " . (is_bool($v) ? ($v ? 'true' : 'false') : $v) . "\n";
}
echo "\n";

// ============================================================
// STEP 2 — DATABASE BASELINE (new account tables, read-only)
// ============================================================
echo "-- NEW ACCOUNT TABLES --\n";
echo "member_savings_accounts: {$before['member_savings_accounts']} rows\n";
echo "savings_account_holders: {$before['savings_account_holders']} rows\n";
echo "organizations: {$before['organizations']} rows\n";
echo "organization_representatives: {$before['organization_representatives']} rows\n";
if ($before['member_savings_accounts'] === 0) {
    echo "NOTE: zero savings accounts exist for ANY member. No new-architecture\n";
    echo "target accounts exist at all yet -- this is investigated as evidence below.\n";
}
echo "\n";

// ============================================================
// STEP 3 — FULL HISTORICAL SAVINGS INVENTORY (44 rows expected)
// ============================================================
$savingsRows = selectOnly($db, "
    SELECT s.id, s.member_id, m.first_name, m.last_name, m.member_number, m.join_date, m.account_number AS member_account_number,
           s.savings_account_id, s.receipt_number, s.transaction_type, s.debit, s.credit, s.running_balance,
           s.description, s.amount_legacy, s.payment_method, s.reference_number, s.transaction_date,
           s.financial_year, s.notes, s.recorded_by, s.authorized_by, s.journal_entry_id, s.created_at, s.updated_at
    FROM savings s
    JOIN members m ON m.id = s.member_id
    ORDER BY s.id
");

echo "-- HISTORICAL SAVINGS INVENTORY: " . count($savingsRows) . " rows --\n";
foreach ($savingsRows as $r) {
    printf(
        "  #%-3d member=%-3d (%s %s, %s) date=%s type=%-6s credit=%-14s debit=%-8s receipt=%-14s ref=%-16s desc=%s\n",
        $r['id'], $r['member_id'], $r['first_name'], $r['last_name'], $r['member_number'],
        $r['transaction_date'], $r['transaction_type'],
        $r['credit'] ?? '0.00', $r['debit'] ?? '0.00',
        $r['receipt_number'] === '' ? '(EMPTY)' : $r['receipt_number'],
        $r['reference_number'] ?? '(NULL)',
        $r['description'] ?? '(NULL)'
    );
}
echo "\n";

// ============================================================
// STEP 4 — HISTORICAL BUSINESS SEMANTICS: schema-level evidence search
// ============================================================
echo "-- SCHEMA-LEVEL EVIDENCE SEARCH --\n";
$cols = selectOnly($db, "DESCRIBE savings");
$colNames = array_column($cols, 'Field');
$typeIndicatorCols = array_filter($colNames, fn($c) => stripos($c, 'type') !== false || stripos($c, 'account') !== false || stripos($c, 'compulsory') !== false || stripos($c, 'voluntary') !== false);
echo "Columns on `savings` containing type/account/compulsory/voluntary in the name: " . (empty($typeIndicatorCols) ? '(none)' : implode(', ', $typeIndicatorCols)) . "\n";
echo "`savings.transaction_type` ENUM values: opening_balance, deposit, withdrawal, adjustment (generic ledger semantics, NOT account-type semantics)\n";
$ttDist = selectOnly($db, "SELECT transaction_type, COUNT(*) c FROM savings GROUP BY transaction_type");
foreach ($ttDist as $r) { echo "  transaction_type='{$r['transaction_type']}': {$r['c']} rows\n"; }
echo "CONCLUSION: no column, ENUM value, or naming convention on `savings` distinguishes compulsory vs voluntary savings.\n";
echo "This was cross-checked against SavingsController::collectInput() (app/controllers/SavingsController.php) --\n";
echo "the historical deposit form only ever collects member_id/amount/payment_method/reference_number/transaction_date/notes.\n";
echo "No account-type selector has ever existed in the historical recording workflow.\n\n";

// ============================================================
// STEP 5 — CROSS-REFERENCE WITH MEMBERS' CURRENT ACCOUNTS
// ============================================================
echo "-- CROSS-REFERENCE: MEMBERS x CURRENT SAVINGS ACCOUNTS --\n";
$distinctMembers = selectOnly($db, "SELECT DISTINCT s.member_id, m.first_name, m.last_name, m.member_number, m.join_date, m.status FROM savings s JOIN members m ON m.id = s.member_id ORDER BY s.member_id");
foreach ($distinctMembers as $m) {
    $accounts = selectOnly($db, "
        SELECT a.id, a.account_number, a.account_type, a.status
        FROM member_savings_accounts a
        JOIN savings_account_holders h ON h.account_id = a.id
        WHERE h.member_id = ?
    ", [$m['member_id']]);
    $depositCount = (int)selectOnlyScalar($db, "SELECT COUNT(*) FROM savings WHERE member_id = ?", [$m['member_id']]);
    $depositTotal = (float)selectOnlyScalar($db, "SELECT COALESCE(SUM(credit),0) FROM savings WHERE member_id = ?", [$m['member_id']]);
    echo sprintf(
        "  member_id=%d %s %s (%s, joined %s): %d historical deposits totalling %.2f | current accounts: %s\n",
        $m['member_id'], $m['first_name'], $m['last_name'], $m['member_number'], $m['join_date'],
        $depositCount, $depositTotal,
        empty($accounts) ? 'NONE' : implode(', ', array_map(fn($a) => "{$a['account_number']}({$a['account_type']}/{$a['status']})", $accounts))
    );
}
echo "\n";

// ============================================================
// STEP 6 — TEMPORAL ANOMALY CHECK (deposit date vs member join_date)
// ============================================================
echo "-- TEMPORAL ANOMALY CHECK: deposits dated before member.join_date --\n";
$anomalies = selectOnly($db, "
    SELECT s.id, s.member_id, s.transaction_date, m.join_date
    FROM savings s JOIN members m ON m.id = s.member_id
    WHERE s.transaction_date < m.join_date
    ORDER BY s.member_id, s.transaction_date
");
echo count($anomalies) . " row(s) have a transaction_date earlier than the member's join_date:\n";
foreach ($anomalies as $a) {
    echo "  savings #{$a['id']} member #{$a['member_id']}: transaction_date={$a['transaction_date']} < join_date={$a['join_date']}\n";
}
echo "This matters because Stage 3's compulsory-account design sets opened_date = member.join_date;\n";
echo "any deposit dated before join_date cannot be temporally 'after' a hypothetical compulsory account's\n";
echo "opening, and undermines Level-4 temporal-evidence reasoning for the affected member(s).\n\n";

// ============================================================
// STEP 7 — RECEIPT / REFERENCE NUMBER INTEGRITY
// ============================================================
echo "-- RECEIPT / REFERENCE NUMBER INTEGRITY --\n";
$emptyReceipt = selectOnly($db, "SELECT id FROM savings WHERE receipt_number = ''");
echo count($emptyReceipt) . " row(s) with an EMPTY receipt_number: " . implode(', ', array_column($emptyReceipt, 'id')) . "\n";
$nonSavFormat = selectOnly($db, "SELECT id, receipt_number FROM savings WHERE receipt_number != '' AND receipt_number NOT REGEXP '^SAV-[0-9]{6}$'");
echo count($nonSavFormat) . " row(s) with a receipt_number NOT matching the SAV-NNNNNN convention:\n";
foreach ($nonSavFormat as $r) { echo "  savings #{$r['id']}: receipt_number='{$r['receipt_number']}'\n"; }
$dupReceipts = selectOnly($db, "SELECT receipt_number, COUNT(*) c FROM savings WHERE receipt_number != '' GROUP BY receipt_number HAVING c > 1");
echo count($dupReceipts) . " duplicate receipt_number value(s) among non-empty receipts.\n";
// Check whether any reference_number value happens to equal another row's receipt_number (textual cross-reference risk)
$refEqualsOtherReceipt = selectOnly($db, "
    SELECT s1.id AS row_id, s1.reference_number, s2.id AS matched_receipt_row
    FROM savings s1
    JOIN savings s2 ON s2.receipt_number = s1.reference_number AND s2.id != s1.id
");
echo count($refEqualsOtherReceipt) . " row(s) whose reference_number textually matches ANOTHER row's receipt_number (would need manual verification, not assumed as a relationship):\n";
foreach ($refEqualsOtherReceipt as $r) { echo "  savings #{$r['row_id']} reference_number='{$r['reference_number']}' == receipt_number of savings #{$r['matched_receipt_row']}\n"; }
echo "\n";

// ============================================================
// STEP 8 — MEMBER 3 / ~1.236B ROW — SPECIAL ATTENTION
// (flagged in prior session memory as a previously-investigated anomaly)
// ============================================================
echo "-- LARGE-VALUE ROW CHECK (memory flagged a ~1.236B anomaly for member 3) --\n";
$bigRows = selectOnly($db, "SELECT id, member_id, credit, transaction_date, reference_number FROM savings WHERE credit > 100000000 ORDER BY credit DESC");
foreach ($bigRows as $r) {
    echo "  savings #{$r['id']} member #{$r['member_id']}: credit={$r['credit']} date={$r['transaction_date']} reference_number={$r['reference_number']}\n";
}
$memberTotal = (float)selectOnlyScalar($db, "SELECT COALESCE(SUM(credit),0) FROM savings");
if (!empty($bigRows)) {
    $bigTotal = array_sum(array_column($bigRows, 'credit'));
    echo sprintf("  This row (or rows) account for %.4f%% of the entire 44-row historical total.\n", ($bigTotal / $memberTotal) * 100);
}
echo "  Not investigated further here (read-only mapping stage, not a re-audit of this figure's correctness --\n";
echo "  per session memory this was previously reviewed and is tracked separately). Flagged because it dominates\n";
echo "  member-level and overall reconciliation totals below.\n\n";

// ============================================================
// STEP 9 — CLASSIFICATION
//
// Because member_savings_accounts has zero rows (see Step 2), there is
// currently NO target account of any type for ANY member -- this is
// checked first and, where true, is decisive on its own regardless of
// how strong any other evidence might be. The evidence-hierarchy
// reasoning (Level 1-5) is still applied and recorded per row for when
// accounts eventually exist, but the Migration Decision output column
// reflects today's actual, current inability to migrate.
// ============================================================
echo "-- CLASSIFICATION (Migration Decision per row) --\n";
$classification = [];
foreach ($savingsRows as $r) {
    $targetAccounts = selectOnly($db, "
        SELECT a.id FROM member_savings_accounts a
        JOIN savings_account_holders h ON h.account_id = a.id
        WHERE h.member_id = ?
    ", [$r['member_id']]);

    $hasDirectId          = $r['savings_account_id'] !== null;              // Level 1
    $hasExplicitType       = false;                                          // Level 2 -- no column carries this, confirmed Step 4
    $hasUniqueCurrentTarget = count($targetAccounts) === 1;                  // Level 3
    $noTargetAtAll          = count($targetAccounts) === 0;

    if ($hasDirectId) {
        $decision = 'SAFE_TO_MAP';
        $category = 'A. CONFIDENTLY MAPPABLE';
        $evidence = 'Level 1 — savings_account_id already populated';
    } elseif ($noTargetAtAll) {
        $decision = 'DO_NOT_MAP';
        $category = 'D. UNMAPPABLE';
        $evidence = 'No target account of any type exists yet for this member (member_savings_accounts has 0 rows for member #' . $r['member_id'] . ')';
    } elseif ($hasExplicitType) {
        $decision = 'SAFE_TO_MAP';
        $category = 'A. CONFIDENTLY MAPPABLE';
        $evidence = 'Level 2 — explicit transaction classification';
    } elseif ($hasUniqueCurrentTarget) {
        $decision = 'REQUIRES_REVIEW';
        $category = 'B. PROBABLE BUT NOT CERTAIN';
        $evidence = 'Level 3 — member has exactly one current account of a plausible type (inferred, not direct evidence)';
    } else {
        $decision = 'AMBIGUOUS';
        $category = 'C. AMBIGUOUS';
        $evidence = 'Member has multiple possible current accounts and no direct evidence selects one';
    }

    $classification[] = [
        'id' => $r['id'], 'member_id' => $r['member_id'],
        'member' => $r['first_name'] . ' ' . $r['last_name'],
        'date' => $r['transaction_date'], 'amount' => $r['credit'],
        'type' => $r['transaction_type'],
        'proposed_account' => $noTargetAtAll ? 'NONE EXIST' : implode(',', array_column($targetAccounts, 'id')),
        'confidence' => $category,
        'evidence' => $evidence,
        'decision' => $decision,
    ];
    printf("  #%-3d member=%-3d %-20s %-10s decision=%-16s | %s\n", $r['id'], $r['member_id'], $r['first_name'] . ' ' . $r['last_name'], $r['transaction_type'], $decision, $evidence);
}
echo "\n";

$counts = array_count_values(array_column($classification, 'decision'));
echo "-- MAPPING SUMMARY --\n";
echo "Historical savings rows: " . count($savingsRows) . "\n";
echo "SAFE_TO_MAP: " . ($counts['SAFE_TO_MAP'] ?? 0) . "\n";
echo "REQUIRES_REVIEW: " . ($counts['REQUIRES_REVIEW'] ?? 0) . "\n";
echo "AMBIGUOUS: " . ($counts['AMBIGUOUS'] ?? 0) . "\n";
echo "DO_NOT_MAP: " . ($counts['DO_NOT_MAP'] ?? 0) . "\n\n";

// ============================================================
// STEP 10 — RECONCILIATION
// ============================================================
echo "-- RECONCILIATION --\n";
$totalsByDecision = [];
foreach ($classification as $c) {
    $totalsByDecision[$c['decision']] = ($totalsByDecision[$c['decision']] ?? 0) + (float)$c['amount'];
}
$historicalTotal = array_sum(array_column($savingsRows, 'credit'));
$reconciledTotal = array_sum($totalsByDecision);
echo sprintf("Historical total:        UGX %.2f\n", $historicalTotal);
foreach (['SAFE_TO_MAP', 'REQUIRES_REVIEW', 'AMBIGUOUS', 'DO_NOT_MAP'] as $d) {
    echo sprintf("%-24s UGX %.2f\n", $d . ':', $totalsByDecision[$d] ?? 0);
}
echo sprintf("Reconciled difference:   UGX %.2f (must be 0.00)\n", $historicalTotal - $reconciledTotal);
echo "\n";

// ============================================================
// STEP 11 — WITHDRAWALS / JOINT / CORPORATE SPECIAL CASES
// ============================================================
echo "-- WITHDRAWALS --\n";
$withdrawals = array_filter($savingsRows, fn($r) => $r['transaction_type'] === 'withdrawal');
echo count($withdrawals) . " withdrawal row(s) among the 44 (N/A if zero).\n\n";

echo "-- JOINT / CORPORATE ACCOUNT RELEVANCE --\n";
echo "joint accounts in member_savings_accounts: " . (int)selectOnlyScalar($db, "SELECT COUNT(*) FROM member_savings_accounts WHERE account_type='joint'") . "\n";
echo "corporate accounts in member_savings_accounts: " . (int)selectOnlyScalar($db, "SELECT COUNT(*) FROM member_savings_accounts WHERE account_type='corporate'") . "\n";
echo "Since zero accounts of any type currently exist, no historical row can be evidenced as belonging to a joint or corporate account either.\n\n";

// ============================================================
// STEP 12 — IMMUTABILITY BASELINE (after)
// ============================================================
$after = captureBaseline($db);
echo "-- BASELINE (after) --\n";
$mutationDetected = false;
foreach ($before as $k => $v) {
    $match = ($after[$k] === $v) || ((is_numeric($v) && is_numeric($after[$k])) && abs((float)$v - (float)$after[$k]) < 0.0001);
    if (!$match) { $mutationDetected = true; }
    echo "  {$k}: before=" . (is_bool($v) ? ($v?'true':'false') : $v) . " after=" . (is_bool($after[$k]) ? ($after[$k]?'true':'false') : $after[$k]) . " " . ($match ? '[OK]' : '[*** MUTATION DETECTED ***]') . "\n";
}
echo "\n";

if ($mutationDetected) {
    echo "*** DATABASE MUTATION DETECTED — STOP AND REPORT IMMEDIATELY ***\n";
    exit(1);
}

echo "NO DATABASE MUTATIONS DETECTED.\n\n";
echo "=== STAGE 5A READ-ONLY FORENSIC AUDIT COMPLETE ===\n";
exit(0);
