<?php
/**
 * VERIFICATION SCRIPT - Phase B Findings
 * Validates the orphaned line discovery
 */

require_once __DIR__ . '/app/config/database.php';
$db = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║         PHASE B FINDINGS VERIFICATION                        ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// 1. Orphaned lines affecting GL 3010
$orphaned_3010 = $db->query("
    SELECT 
        jl.id as line_id,
        jl.journal_entry_id,
        jl.debit,
        jl.credit,
        jl.description,
        jl.created_at
    FROM journal_lines jl
    LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
    WHERE je.id IS NULL
      AND jl.account_id = (SELECT id FROM accounts WHERE code = '3010')
    ORDER BY jl.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

echo "1. ORPHANED LINES IN GL 3010\n";
echo "============================\n";
echo "Count: " . count($orphaned_3010) . "\n\n";

if (count($orphaned_3010) > 0) {
    foreach ($orphaned_3010 as $line) {
        echo "Line ID: {$line['line_id']}\n";
        echo "Journal Entry ID: {$line['journal_entry_id']} (DELETED)\n";
        echo "Debit: " . number_format($line['debit'], 2) . "\n";
        echo "Credit: " . number_format($line['credit'], 2) . "\n";
        echo "Net Effect: " . number_format($line['credit'] - $line['debit'], 2) . "\n";
        echo "Description: {$line['description']}\n";
        echo "Created: {$line['created_at']}\n";
        echo "---\n";
    }
}

// 2. Current GL 3010 balance breakdown
$gl_3010_total = $db->query("
    SELECT COALESCE(SUM(credit - debit), 0) as balance
    FROM journal_lines
    WHERE account_id = (SELECT id FROM accounts WHERE code = '3010')
")->fetch(PDO::FETCH_ASSOC);

$gl_3010_valid = $db->query("
    SELECT COUNT(*) as count, COALESCE(SUM(credit - debit), 0) as balance
    FROM journal_lines jl
    INNER JOIN journal_entries je ON je.id = jl.journal_entry_id
    WHERE jl.account_id = (SELECT id FROM accounts WHERE code = '3010')
")->fetch(PDO::FETCH_ASSOC);

$gl_3010_orphaned = $db->query("
    SELECT COUNT(*) as count, COALESCE(SUM(credit - debit), 0) as balance
    FROM journal_lines jl
    LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
    WHERE je.id IS NULL
      AND jl.account_id = (SELECT id FROM accounts WHERE code = '3010')
")->fetch(PDO::FETCH_ASSOC);

echo "\n2. GL 3010 BALANCE BREAKDOWN\n";
echo "============================\n";
echo "Total Balance:           UGX " . number_format($gl_3010_total['balance'], 2) . "\n";
echo "  Valid Lines:           UGX " . number_format($gl_3010_valid['balance'], 2) . " ({$gl_3010_valid['count']} lines)\n";
echo "  Orphaned Lines:        UGX " . number_format($gl_3010_orphaned['balance'], 2) . " ({$gl_3010_orphaned['count']} lines)\n";
echo "  Math Check:            UGX " . number_format($gl_3010_valid['balance'] + $gl_3010_orphaned['balance'], 2) . "\n";

if (abs($gl_3010_total['balance'] - ($gl_3010_valid['balance'] + $gl_3010_orphaned['balance'])) < 0.01) {
    echo "  Status:                ✅ VERIFIED\n";
} else {
    echo "  Status:                ❌ MISMATCH\n";
}

// 3. Total orphaned lines across all accounts
$total_orphaned = $db->query("
    SELECT COUNT(*) as count
    FROM journal_lines jl
    LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
    WHERE je.id IS NULL
")->fetch(PDO::FETCH_ASSOC);

echo "\n3. DATABASE-WIDE ORPHANED LINES\n";
echo "================================\n";
echo "Total Orphaned Lines: {$total_orphaned['count']}\n";

// 4. Affected accounts
$affected_accounts = $db->query("
    SELECT 
        a.code,
        a.name,
        COUNT(*) as line_count,
        SUM(jl.debit) as total_debit,
        SUM(jl.credit) as total_credit,
        SUM(jl.credit - jl.debit) as net_effect
    FROM journal_lines jl
    LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
    LEFT JOIN accounts a ON a.id = jl.account_id
    WHERE je.id IS NULL
    GROUP BY a.code, a.name
    ORDER BY net_effect DESC
")->fetchAll(PDO::FETCH_ASSOC);

echo "\n4. AFFECTED ACCOUNTS\n";
echo "====================\n";
echo str_pad("Account", 12) . str_pad("Name", 30) . str_pad("Lines", 8) . str_pad("Net Effect", 18) . "\n";
echo str_repeat("-", 68) . "\n";

foreach ($affected_accounts as $account) {
    echo str_pad($account['code'], 12);
    echo str_pad(substr($account['name'], 0, 28), 30);
    echo str_pad($account['line_count'], 8);
    echo str_pad(number_format($account['net_effect'], 2), 18) . "\n";
}

// 5. The mystery amount verification
echo "\n5. MYSTERY AMOUNT VERIFICATION\n";
echo "==============================\n";
echo "Expected Mystery Amount:  UGX 51,761,620.00\n";
echo "Actual Orphaned in 3010:  UGX " . number_format($gl_3010_orphaned['balance'], 2) . "\n";

if (abs($gl_3010_orphaned['balance'] - 51761620) < 0.01) {
    echo "Status:                   ✅ CONFIRMED - Mystery solved!\n";
} else {
    echo "Status:                   ❌ MISMATCH - Further investigation needed\n";
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  VERIFICATION COMPLETE                                       ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
